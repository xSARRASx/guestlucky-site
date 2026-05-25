<?php
/**
 * ============================================================
 * FILE    : get_agenda_config.php
 * DOE     : 2026-05-21
 * DOS     : 2026-05-21
 * AUTHOR  : LumnisTech / Kévin Besnard
 * SUMMARY : Endpoint public GET — retourne agents actifs +
 *           types de RDV actifs depuis Airtable
 * ============================================================
 */

// ──────────────────────────────────────────────────────────────
// SECTION 0 — CHARGEMENT ENV
// ──────────────────────────────────────────────────────────────

define('CACHE_FILE', __DIR__ . '/cache/agenda_config.json');
define('LOG_FILE',   __DIR__ . '/logs/agenda_config.log');
define('CACHE_TTL',  300);

date_default_timezone_set('Europe/Paris');

function load_env_file(string $path): array {
    if (!is_readable($path)) {
        throw new RuntimeException("Fichier .env illisible: {$path}");
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $out   = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v]       = explode('=', $line, 2);
        $out[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
    return $out;
}

try {
    $ENV = load_env_file('/home/VOTRE_USER/private/glAgenda.env');
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$AIRTABLE_TOKEN        = $ENV['AIRTABLE_TOKEN']        ?? '';
$AIRTABLE_BASE_ID      = $ENV['AIRTABLE_BASE_ID']      ?? '';
$TABLE_AGENTS          = $ENV['TABLE_AGENTS']          ?? '';
$TABLE_MEETING_TYPES   = $ENV['TABLE_MEETING_TYPES']   ?? '';

// Vérification des variables obligatoires
foreach (['AIRTABLE_TOKEN' => $AIRTABLE_TOKEN, 'AIRTABLE_BASE_ID' => $AIRTABLE_BASE_ID,
          'TABLE_AGENTS' => $TABLE_AGENTS, 'TABLE_MEETING_TYPES' => $TABLE_MEETING_TYPES] as $k => $v) {
    if (empty($v)) {
        http_response_code(500);
        echo json_encode(['error' => "Variable manquante dans .env : {$k}"]);
        exit;
    }
}

// ──────────────────────────────────────────────────────────────
// SECTION 1 — HEADERS CORS + Content-Type
// ──────────────────────────────────────────────────────────────

$allowedOrigins = ['https://guestlucky.com', 'https://www.guestlucky.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: public, max-age=300');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET')     { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

// ──────────────────────────────────────────────────────────────
// SECTION 2 — LOGS
// ──────────────────────────────────────────────────────────────

function log_line(string $level, string $message, array $context = []): void {
    @mkdir(dirname(LOG_FILE), 0750, true);
    $ctx  = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $line = sprintf("[%s] [%s] %s%s\n", date('Y-m-d H:i:s'), strtoupper($level), $message, $ctx);
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

log_line('info', '───── Requête entrante ─────', [
    'method'      => $_SERVER['REQUEST_METHOD'],
    'origin'      => $origin ?: '(none)',
    'ip'          => $_SERVER['REMOTE_ADDR'] ?? '?',
    'base_id'     => $AIRTABLE_BASE_ID,
    'tbl_agents'  => $TABLE_AGENTS,
    'tbl_types'   => $TABLE_MEETING_TYPES,
    'token_hint'  => substr($AIRTABLE_TOKEN, 0, 14) . '...',
]);

// ──────────────────────────────────────────────────────────────
// SECTION 3 — FONCTIONS UTILITAIRES
// ──────────────────────────────────────────────────────────────

function airtable_get(string $tableId, string $baseId, string $token): array {
    $url = "https://api.airtable.com/v0/{$baseId}/{$tableId}";

    log_line('info', 'Airtable GET', ['url' => $url]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        log_line('error', 'cURL error', ['error' => $curlErr]);
        return [];
    }

    $decoded = json_decode($response, true);
    $records = $decoded['records'] ?? [];

    log_line('info', 'Airtable réponse', [
        'http_code'   => $httpCode,
        'records'     => count($records),
        'error'       => $decoded['error'] ?? null,
        'raw_preview' => substr($response, 0, 400),
    ]);

    return ($httpCode === 200) ? $records : [];
}

function cache_valid(): bool {
    return file_exists(CACHE_FILE)
        && (time() - filemtime(CACHE_FILE)) < CACHE_TTL;
}

function cache_read(): ?array {
    $raw = @file_get_contents(CACHE_FILE);
    return $raw ? json_decode($raw, true) : null;
}

function cache_write(array $data): void {
    @mkdir(dirname(CACHE_FILE), 0750, true);
    @file_put_contents(CACHE_FILE, json_encode($data), LOCK_EX);
}

// ──────────────────────────────────────────────────────────────
// SECTION 4 — CACHE
// ──────────────────────────────────────────────────────────────

if (!empty($_GET['flush']) && $_GET['flush'] === '1') {
    @unlink(CACHE_FILE);
    log_line('info', 'Cache vidé manuellement');
}

if (cache_valid()) {
    $cached = cache_read();
    if ($cached) {
        log_line('info', 'Cache HIT');
        header('X-Cache: HIT');
        echo json_encode($cached);
        exit;
    }
}

log_line('info', 'Cache MISS — appel Airtable');
header('X-Cache: MISS');

// ──────────────────────────────────────────────────────────────
// SECTION 5 — FETCH AGENTS
// ──────────────────────────────────────────────────────────────

try {

$agentRecords = airtable_get($TABLE_AGENTS, $AIRTABLE_BASE_ID, $AIRTABLE_TOKEN);
$agentsBefore = count($agentRecords);

if ($agentsBefore > 0) {
    log_line('debug', 'Champs premier agent brut', [
        'fields' => $agentRecords[0]['fields'] ?? [],
    ]);
}

$agentRecords = array_filter($agentRecords, fn($r) => !empty($r['fields']['Active']));
$agentsAfter  = count($agentRecords);
log_line('info', 'Agents filtrés', ['brut' => $agentsBefore, 'actifs' => $agentsAfter]);

$agents = [];
foreach ($agentRecords as $record) {
    $f = $record['fields'];

    // WorkDays peut être string "1,2,3,4,5", array d'entiers, ou array de labels Airtable
    $dayLabels   = ['Dim'=>0,'Lun'=>1,'Mar'=>2,'Mer'=>3,'Jeu'=>4,'Ven'=>5,'Sam'=>6,
                    'Sun'=>0,'Mon'=>1,'Tue'=>2,'Wed'=>3,'Thu'=>4,'Fri'=>5,'Sat'=>6];
    $rawWorkDays = $f['WorkDays'] ?? '1,2,3,4,5';
    if (is_array($rawWorkDays)) {
        $workDays = array_map(function($v) use ($dayLabels) {
            return isset($dayLabels[$v]) ? $dayLabels[$v] : (int)$v;
        }, $rawWorkDays);
    } else {
        $workDays = array_map('intval', explode(',', $rawWorkDays));
    }

    $agents[] = [
        'id'        => is_array($f['AgentId'] ?? '') ? ($f['AgentId'][0] ?? $record['id']) : ($f['AgentId'] ?? $record['id']),
        'name'      => $f['Name']     ?? '',
        'role'      => $f['Role']     ?? '',
        'email'     => $f['Email']    ?? '',
        'color'     => $f['Color']    ?? '#1E66F5',
        'initials'  => $f['Initials'] ?? strtoupper(mb_substr($f['Name'] ?? '?', 0, 2)),
        'workDays'  => array_values($workDays),
        'workStart' => (int)($f['WorkStart'] ?? 9),
        'workEnd'   => (int)($f['WorkEnd']   ?? 18),
    ];
}

// ──────────────────────────────────────────────────────────────
// SECTION 6 — FETCH MEETING TYPES
// ──────────────────────────────────────────────────────────────

$typeRecords = airtable_get($TABLE_MEETING_TYPES, $AIRTABLE_BASE_ID, $AIRTABLE_TOKEN);
$typesBefore = count($typeRecords);

if ($typesBefore > 0) {
    log_line('debug', 'Champs premier type brut', [
        'fields' => $typeRecords[0]['fields'] ?? [],
    ]);
}

$typeRecords = array_filter($typeRecords, fn($r) => !empty($r['fields']['Active']));
$typesAfter  = count($typeRecords);
log_line('info', 'Types filtrés', ['brut' => $typesBefore, 'actifs' => $typesAfter]);

$meetingTypes = [];
foreach ($typeRecords as $record) {
    $f              = $record['fields'];
    $rawAgentIds     = $f['AgentId (from AgentsIds)'] ?? [];
    $agentIds        = is_array($rawAgentIds) ? $rawAgentIds : explode(',', $rawAgentIds);

    $meetingTypes[] = [
        'id'             => is_array($f['TypeId'] ?? '') ? ($f['TypeId'][0] ?? $record['id']) : ($f['TypeId'] ?? $record['id']),
        'label'          => $f['Label']         ?? '',
        'icon'           => $f['Icon']          ?? '📅',
        'duration'       => (int)($f['Duration'] ?? 30),
        'color'          => $f['Color']         ?? '#1E66F5',
        'description'    => $f['Description']   ?? '',
        'agentIds'       => array_values(array_map('trim', $agentIds)),
        'planningStatus'   => $f['PlanningStatus']   ?? '',
        'order'            => (int)($f['Order']       ?? 99),
        'paymentRequired'  => !empty($f['PaymentRequired']),
        'paymentQuestion'  => $f['PaymentQuestion']  ?? '',
        'stripeLinks'      => !empty($f['StripeLinks'])
                              ? (json_decode($f['StripeLinks'], true) ?? [])
                              : [],
    ];
}

// ──────────────────────────────────────────────────────────────
// SECTION 7 — FETCH QUESTIONS depuis Airtable
// ──────────────────────────────────────────────────────────────

$TABLE_QUESTIONS = $ENV['TABLE_QUESTIONS'] ?? '';
$questions       = [];

if ($TABLE_QUESTIONS) {
    $qRecords = airtable_get($TABLE_QUESTIONS, $AIRTABLE_BASE_ID, $AIRTABLE_TOKEN);
    log_line('info', 'Questions fetch', ['count' => count($qRecords)]);

    // Trier par TypeId puis Order
    usort($qRecords, function($a, $b) {
        $typeA = is_array($a['fields']['TypeId'] ?? '') ? ($a['fields']['TypeId'][0] ?? '') : ($a['fields']['TypeId'] ?? '');
        $typeB = is_array($b['fields']['TypeId'] ?? '') ? ($b['fields']['TypeId'][0] ?? '') : ($b['fields']['TypeId'] ?? '');
        $typeCompare = strcmp($typeA, $typeB);
        if ($typeCompare !== 0) return $typeCompare;
        return (int)($a['fields']['Order'] ?? 0) <=> (int)($b['fields']['Order'] ?? 0);
    });

    foreach ($qRecords as $record) {
        $f = $record['fields'];

        // Parser les options (string séparée par virgule)
        $rawOptions = $f['Options'] ?? '';
        $options    = $rawOptions
            ? array_map('trim', explode(',', $rawOptions))
            : [];

        // Parser les mots-clés de disqualification
        $rawDisqualify = $f['DisqualifyKeywords'] ?? '';
        $disqualify    = $rawDisqualify
            ? array_map('trim', explode(',', $rawDisqualify))
            : [];

        $questions[] = [
            'id'          => $record['id'],
            'typeId'      => is_array($f['TypeId'] ?? '') ? ($f['TypeId'][0] ?? '') : ($f['TypeId'] ?? ''),
            'question'    => $f['Question']  ?? '',
            'fieldType'   => $f['FieldType'] ?? 'text', // single_choice | multi_choice | text
            'options'     => $options,
            'disqualify'  => $disqualify,
            'order'       => (int)($f['Order']    ?? 0),
            'required'    => !empty($f['Required']),
        ];
    }
}

// ──────────────────────────────────────────────────────────────
// SECTION 8 — RÉPONSE + CACHE
// ──────────────────────────────────────────────────────────────

// Trier par Order
usort($meetingTypes, fn($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));

$payload = [
    'agents'       => array_values($agents),
    'meetingTypes' => array_values($meetingTypes),
    'questions'    => $questions,
    'fetchedAt'    => date('c'),
];

log_line('info', 'Réponse finale', [
    'agents'       => count($agents),
    'meetingTypes' => count($meetingTypes),
]);

cache_write($payload);
echo json_encode($payload, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    log_line('error', 'EXCEPTION FATALE', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        'trace'   => substr($e->getTraceAsString(), 0, 500),
    ]);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
}