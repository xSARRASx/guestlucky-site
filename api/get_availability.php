<?php
/**
 * ============================================================
 * FILE    : get_availability.php
 * DOE     : 2026-05-21
 * DOS     : 2026-05-21
 * AUTHOR  : LumnisTech / Kévin Besnard
 * SUMMARY : Endpoint public GET — retourne les créneaux libres
 *           pour un agent + type de RDV donné.
 *           La plage horaire (WorkDays, WorkStart, WorkEnd) est
 *           définie UNE SEULE FOIS par agent dans Airtable.
 *           Google Calendar FreeBusy fait le vrai filtrage.
 * ============================================================
 *
 * URL    : /api/get_availability.php?agent_id=kevin&type_id=demo&days=45
 * PARAMS :
 *   agent_id  (requis)  — identifiant agent (ex: "kevin")
 *   type_id   (requis)  — identifiant type de RDV (ex: "demo")
 *   days      (optionnel, défaut: 45) — nb jours à scanner
 *
 * RETOUR : { dates: { "2026-06-02": ["09:00","09:30",...], ... } }
 * CACHE  : 10 minutes par agent_id (partagé entre tous les types)
 * ============================================================
 */

// ──────────────────────────────────────────────────────────────
// SECTION 0 — CONFIGURATION
// ──────────────────────────────────────────────────────────────

// Fichier .env spécifique à ce script
$ENV_FILE = '/home/VOTRE_USER/private/glAgenda.env';

define('CACHE_DIR',            __DIR__ . '/cache/availability/');
define('CACHE_TTL',            600);  // 10 minutes
define('SLOT_INTERVAL',        30);   // minutes entre créneaux
define('MAX_DAYS',             60);   // sécurité plafond
define('BUFFER_MINUTES',       120);  // délai minimum avant premier créneau proposé

date_default_timezone_set('Europe/Paris');

header('Content-Type: application/json; charset=utf-8');
// CORS dynamique — accepte https://lumnistech.fr ET https://www.lumnistech.fr
$allowedOrigins = ['https://guestlucky.com', 'https://www.guestlucky.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: public, max-age=300');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET')     { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

// ──────────────────────────────────────────────────────────────
// SECTION 1 — VALIDATION DES PARAMÈTRES
// ──────────────────────────────────────────────────────────────

$agentId = trim($_GET['agent_id'] ?? '');
$typeId  = trim($_GET['type_id']  ?? '');
$days    = min((int)($_GET['days'] ?? 45), MAX_DAYS);

if (!$agentId || !$typeId) {
    http_response_code(422);
    echo json_encode(['error' => 'Paramètres agent_id et type_id requis']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $agentId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $typeId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètres invalides']);
    exit;
}

// ──────────────────────────────────────────────────────────────
// SECTION 2 — FONCTIONS UTILITAIRES
// ──────────────────────────────────────────────────────────────

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

function log_line(string $level, string $message, array $context = []): void {
    $logFile = __DIR__ . '/logs/availability.log';
    @mkdir(dirname($logFile), 0750, true);
    $line = sprintf("[%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        empty($context) ? '' : json_encode($context, JSON_UNESCAPED_UNICODE)
    );
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function http_get(string $url, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($body, true)];
}

function http_post(string $url, array $headers, string $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($resp, true)];
}

// Cache par agent uniquement (pas par type — la plage est globale)
function cache_path(string $agentId): string {
    return CACHE_DIR . "avail_{$agentId}.json";
}

function cache_valid(string $path): bool {
    return file_exists($path) && (time() - filemtime($path)) < CACHE_TTL;
}

function cache_read(string $path): ?array {
    $raw = @file_get_contents($path);
    return $raw ? json_decode($raw, true) : null;
}

function cache_write(string $path, array $data): void {
    @mkdir(dirname($path), 0750, true);
    @file_put_contents($path, json_encode($data), LOCK_EX);
}

/**
 * Génère les créneaux théoriques d'une journée selon la plage horaire
 */
function generate_theoretical_slots(DateTime $day, int $startH, int $endH, int $durationMin): array {
    $slots  = [];
    $cursor = clone $day;
    $cursor->setTime($startH, 0, 0);
    $dayEnd = clone $day;
    $dayEnd->setTime($endH, 0, 0);

    while ($cursor < $dayEnd) {
        $slotEnd = clone $cursor;
        $slotEnd->modify("+{$durationMin} minutes");
        if ($slotEnd <= $dayEnd) {
            $slots[] = clone $cursor;
        }
        $cursor->modify('+' . SLOT_INTERVAL . ' minutes');
    }
    return $slots;
}

/**
 * Retourne true si le créneau ne chevauche aucune période occupée
 */
function is_slot_free(DateTime $slotStart, int $durationMin, array $busyPeriods): bool {
    $slotEnd = clone $slotStart;
    $slotEnd->modify("+{$durationMin} minutes");
    foreach ($busyPeriods as $period) {
        if ($slotStart < $period['end'] && $slotEnd > $period['start']) {
            return false;
        }
    }
    return true;
}

/**
 * Fallback : créneaux théoriques sans consultation Google Calendar
 */
function build_theoretical_availability(
    array $workDays, int $workStart, int $workEnd,
    int $durationMin, int $days
): array {
    $tz     = new DateTimeZone('Europe/Paris');
    $today  = new DateTime('today', $tz);
    $result = [];

    for ($i = 1; $i <= $days; $i++) {
        $day       = (clone $today)->modify("+{$i} days");
        $dayOfWeek = (int)$day->format('N') % 7; // 1=Lun…6=Sam, 0=Dim
        if (!in_array($dayOfWeek, $workDays)) continue;

        $slots = generate_theoretical_slots($day, $workStart, $workEnd, $durationMin);
        if (!empty($slots)) {
            $result[$day->format('Y-m-d')] = array_map(fn($s) => $s->format('H:i'), $slots);
        }
    }
    return $result;
}


// Chargement ENV
$ENV = load_env_file($ENV_FILE);

foreach (['AIRTABLE_TOKEN', 'AIRTABLE_BASE_ID', 'TABLE_AGENTS', 'TABLE_MEETING_TYPES'] as $k) {
    if (empty($ENV[$k])) {
        http_response_code(500);
        echo json_encode(['error' => "Variable manquante dans .env : {$k}"]);
        exit;
    }
}

$AIRTABLE_TOKEN               = $ENV['AIRTABLE_TOKEN'];
$AIRTABLE_BASE_ID             = $ENV['AIRTABLE_BASE_ID'];
$AIRTABLE_TABLE_BOOKINGS      = $ENV['TABLE_BOOKINGS']       ?? '';
$AIRTABLE_TABLE_AGENTS        = $ENV['TABLE_AGENTS'];
$AIRTABLE_TABLE_MEETING_TYPES = $ENV['TABLE_MEETING_TYPES'];
$GOOGLE_CLIENT_ID             = $ENV['GOOGLE_CLIENT_ID']     ?? '';
$GOOGLE_CLIENT_SECRET         = $ENV['GOOGLE_CLIENT_SECRET'] ?? '';


try {

log_line('info', '── Requête entrante ──', [
    'agent_id' => $agentId,
    'type_id'  => $typeId,
    'days'     => $days,
    'flush'    => !empty($_GET['flush']) ? '1' : '0',
    'ip'       => $_SERVER['REMOTE_ADDR'] ?? '?',
]);

// ──────────────────────────────────────────────────────────────
// SECTION 3 — CACHE
// ──────────────────────────────────────────────────────────────

$cachePath = cache_path($agentId);

if (!empty($_GET['flush']) && $_GET['flush'] === '1') {
    $deleted = file_exists($cachePath) ? unlink($cachePath) : false;
    log_line('info', 'Cache flush', ['path' => $cachePath, 'deleted' => $deleted ? 'OK' : 'FAILED']);
}

$cachedBusy = null;
if (cache_valid($cachePath)) {
    $cachedBusy = cache_read($cachePath);
    log_line('info', 'Cache HIT', ['agent' => $agentId]);
    header('X-Cache: HIT');
} else {
    header('X-Cache: MISS');
}

// ──────────────────────────────────────────────────────────────
// SECTION 4 — RÉCUPÉRATION AGENT DEPUIS AIRTABLE
// ──────────────────────────────────────────────────────────────

$url = "https://api.airtable.com/v0/" . $AIRTABLE_BASE_ID
     . "/" . $AIRTABLE_TABLE_AGENTS . "?filterByFormula=" . rawurlencode("{AgentId}='" . $agentId . "'")
     . "&maxRecords=1";

$agentRes = http_get($url, ['Authorization: Bearer ' . $AIRTABLE_TOKEN]);

if ($agentRes['code'] !== 200 || empty($agentRes['body']['records'])) {
    http_response_code(404);
    echo json_encode(['error' => 'Agent introuvable']);
    exit;
}

$f = $agentRes['body']['records'][0]['fields'];

if (empty($f['Active'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Agent inactif']);
    exit;
}

// ── Plage horaire globale de l'agent (plus de distinction par type) ──
    // WorkDays peut être :
    //   - string "1,2,3,4,5" (champ texte)
    //   - array d'entiers [1,2,3,4,5]
    //   - array de strings ["Lun","Mar",...] (champ multi-select Airtable)
    $dayLabels    = ['Dim'=>0,'Lun'=>1,'Mar'=>2,'Mer'=>3,'Jeu'=>4,'Ven'=>5,'Sam'=>6,
                     'Sun'=>0,'Mon'=>1,'Tue'=>2,'Wed'=>3,'Thu'=>4,'Fri'=>5,'Sat'=>6];
    $rawWorkDays  = $f['WorkDays'] ?? '1,2,3,4,5';
    if (is_array($rawWorkDays)) {
        $workDays = array_map(function($v) use ($dayLabels) {
            return isset($dayLabels[$v]) ? $dayLabels[$v] : (int)$v;
        }, $rawWorkDays);
    } else {
        $workDays = array_map('intval', explode(',', $rawWorkDays));
    }
    $workStart    = (int)($f['WorkStart'] ?? 9);
    $workEnd      = (int)($f['WorkEnd']   ?? 18);

$refreshToken = $ENV['GOOGLE_REFRESH_TOKEN'] ?? '';
$calendarId   = $f['GoogleCalendarId']   ?? 'primary';

log_line('debug', 'Token check', [
    'refresh_token_length' => strlen($refreshToken),
    'refresh_token_hint'   => $refreshToken ? substr($refreshToken, 0, 10) . '...' : '(vide)',
    'calendar_id'          => $calendarId,
]);

// ──────────────────────────────────────────────────────────────
// SECTION 5 — DURÉE DU TYPE DE RDV DEPUIS AIRTABLE
// ──────────────────────────────────────────────────────────────

$typeUrl     = "https://api.airtable.com/v0/" . $AIRTABLE_BASE_ID
             . "/" . $AIRTABLE_TABLE_MEETING_TYPES . "?filterByFormula=" . rawurlencode("{TypeId}='" . $typeId . "'")
             . "&maxRecords=1";
$typeRes     = http_get($typeUrl, ['Authorization: Bearer ' . $AIRTABLE_TOKEN]);
$typeFields  = $typeRes['body']['records'][0]['fields'] ?? [];
$durationMin = (int)($typeFields['Duration'] ?? 30);

// Vérifier que l'agent est autorisé pour ce type de RDV
$rawAgentIds     = $typeFields['AgentId (from AgentsIds)'] ?? [];
$allowedAgentIds = is_array($rawAgentIds)
    ? array_map('trim', $rawAgentIds)
    : array_map('trim', explode(',', $rawAgentIds));

if (!empty($allowedAgentIds) && !in_array($agentId, $allowedAgentIds)) {
    echo json_encode(['dates' => [], 'source' => 'agent_not_allowed', 'agent_id' => $agentId, 'type_id' => $typeId]);
    exit;
}

// ──────────────────────────────────────────────────────────────
// SECTION 6 — RÉCUPÉRATION DES PÉRIODES OCCUPÉES (si pas en cache)
// ──────────────────────────────────────────────────────────────

$busyPeriods = [];

if ($cachedBusy) {
    // Reconstituer les DateTime depuis le cache JSON
    foreach ($cachedBusy['busy_periods'] as $p) {
        $tz = new DateTimeZone('Europe/Paris');
        $busyPeriods[] = [
            'start' => new DateTime($p['start'], $tz),
            'end'   => new DateTime($p['end'],   $tz),
        ];
    }
} else {
    if (empty($refreshToken)) {
        log_line('warning', 'Refresh token vide → fallback théorique', ['agent' => $agentId]);
        $dates = build_theoretical_availability($workDays, $workStart, $workEnd, $durationMin, $days);
        echo json_encode(['dates' => $dates, 'source' => 'theoretical', 'agent_id' => $agentId, 'type_id' => $typeId]);
        exit;
    }

    // Rafraîchir l'access token Google
    log_line('info', 'Refresh token Google', ['hint' => substr($refreshToken, 0, 10) . '...']);
    $tokenRes = http_post(
        'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id'     => $GOOGLE_CLIENT_ID,
            'client_secret' => $GOOGLE_CLIENT_SECRET,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ])
    );

    if ($tokenRes['code'] !== 200 || empty($tokenRes['body']['access_token'])) {
        log_line('error', 'Token refresh échoué', ['code' => $tokenRes['code'], 'body' => $tokenRes['body']]);
        $dates = build_theoretical_availability($workDays, $workStart, $workEnd, $durationMin, $days);
        echo json_encode(['dates' => $dates, 'source' => 'theoretical_token_error']);
        exit;
    }

    $accessToken = $tokenRes['body']['access_token'];
    log_line('info', 'Access token obtenu');
    $tz          = new DateTimeZone('Europe/Paris');
    $now         = new DateTime('now', $tz);
    $rangeEnd    = (clone $now)->modify("+{$days} days");

    // Appel FreeBusy Google Calendar
    log_line('info', 'FreeBusy request', ['calendar_id' => $calendarId]);
    $fbRes = http_post(
        'https://www.googleapis.com/calendar/v3/freeBusy',
        ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        json_encode([
            'timeMin'  => $now->format('c'),
            'timeMax'  => $rangeEnd->format('c'),
            'timeZone' => 'Europe/Paris',
            'items'    => [['id' => $calendarId]],
        ])
    );

    if ($fbRes['code'] !== 200) {
        log_line('error', 'FreeBusy échoué', ['code' => $fbRes['code'], 'body' => $fbRes['body']]);
        $dates = build_theoretical_availability($workDays, $workStart, $workEnd, $durationMin, $days);
        echo json_encode(['dates' => $dates, 'source' => 'theoretical_freebusy_error']);
        exit;
    }

    $busyRaw     = $fbRes['body']['calendars'][$calendarId]['busy'] ?? [];
    $cacheRaw    = [];

    foreach ($busyRaw as $period) {
        $tz2  = new DateTimeZone('Europe/Paris');
        $start = new DateTime($period['start'], $tz2);
        $end   = new DateTime($period['end'],   $tz2);
        $busyPeriods[] = ['start' => $start, 'end' => $end];
        $cacheRaw[]    = ['start' => $start->format('c'), 'end' => $end->format('c')];
    }

    // Sauvegarder les périodes occupées en cache (partagé entre tous les types)
    cache_write($cachePath, [
        'busy_periods' => $cacheRaw,
        'work'         => ['days' => $workDays, 'start' => $workStart, 'end' => $workEnd],
        'fetched_at'   => (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('c'),
    ]);
}

// ──────────────────────────────────────────────────────────────
// SECTION 7 — CALCUL DES CRÉNEAUX LIBRES
// ──────────────────────────────────────────────────────────────

$tz        = new DateTimeZone('Europe/Paris');
$now       = new DateTime('now', $tz);
$scanStart = (clone $now)->modify('+' . BUFFER_MINUTES . ' minutes');
$today     = new DateTime('today', $tz);

$availableDates = [];

for ($i = 0; $i < $days; $i++) {
    $day       = (clone $today)->modify("+{$i} days");
    // PHP N() : 1=Lun … 7=Dim → on ramène Dim à 0 pour matcher notre convention JS
    $dayOfWeek = (int)$day->format('N') % 7;

    if (!in_array($dayOfWeek, $workDays)) continue;

    $slots     = generate_theoretical_slots($day, $workStart, $workEnd, $durationMin);
    $freeSlots = [];

    foreach ($slots as $slot) {
        if ($slot < $scanStart) continue;
        if (is_slot_free($slot, $durationMin, $busyPeriods)) {
            $freeSlots[] = $slot->format('H:i');
        }
    }

    if (!empty($freeSlots)) {
        $availableDates[$day->format('Y-m-d')] = $freeSlots;
    }
}

// ──────────────────────────────────────────────────────────────
// SECTION 8 — RÉPONSE
// ──────────────────────────────────────────────────────────────

echo json_encode([
    'dates'      => $availableDates,
    'source'     => 'google_calendar',
    'agent_id'   => $agentId,
    'type_id'    => $typeId,
    'duration'   => $durationMin,
    'work_start' => $workStart,
    'work_end'   => $workEnd,
    'fetched_at' => (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('c'),
], JSON_UNESCAPED_UNICODE);

log_line('info', 'Réponse envoyée', ['source' => 'google_calendar', 'days_available' => count($availableDates)]);

} catch (Throwable $e) {
    log_line('error', 'EXCEPTION', ['message' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'file'  => basename($e->getFile()),
        'line'  => $e->getLine(),
    ]);
}