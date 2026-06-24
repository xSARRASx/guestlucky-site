<?php
/**
 * ============================================================
 * FILE    : autosave_contact.php
 * DOE     : 2026-05-21
 * AUTHOR  : LumnisTech / Kévin Besnard
 * SUMMARY : Endpoint POST — enregistre un contact partiel dans
 *           Airtable dès que le téléphone est saisi (avant
 *           confirmation du RDV). Utilisé pour la capture lead.
 * ============================================================
 */

$ENV_FILE = '/home/guestlucky/private/glAgenda.env';

date_default_timezone_set('Europe/Paris');

$allowedOrigins = ['https://guestlucky.com', 'https://www.guestlucky.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); exit; }

// ──────────────────────────────────────────────────────────────
// SECTION 1 — FONCTIONS
// ──────────────────────────────────────────────────────────────

function load_env_file(string $path): array {
    if (!is_readable($path)) return [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $out   = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
    return $out;
}

// ──────────────────────────────────────────────────────────────
// SECTION 2 — CHARGEMENT ENV
// ──────────────────────────────────────────────────────────────

$ENV                  = load_env_file($ENV_FILE);
$AIRTABLE_TOKEN       = $ENV['AIRTABLE_TOKEN']  ?? '';
$AIRTABLE_BASE_ID     = $ENV['AIRTABLE_BASE_ID'] ?? '';
$AIRTABLE_TABLE_CONTACTS = $ENV['TABLE_CONTACTS'] ?? '';

if (!$AIRTABLE_TOKEN || !$AIRTABLE_BASE_ID || !$AIRTABLE_TABLE_CONTACTS) {
    http_response_code(500);
    echo json_encode(['error' => 'Config manquante']);
    exit;
}

// ──────────────────────────────────────────────────────────────
// SECTION 3 — VALIDATION
// ──────────────────────────────────────────────────────────────

$data  = json_decode(file_get_contents('php://input'), true);
$email = trim($data['email'] ?? '');
$phone = trim($data['phone'] ?? '');

if (!$phone) {
    http_response_code(422);
    echo json_encode(['error' => 'Téléphone requis']);
    exit;
}

// ──────────────────────────────────────────────────────────────
// SECTION 4 — UPSERT : PATCH si record_id connu, sinon search + patch/post
// ──────────────────────────────────────────────────────────────

$existingRecordId = trim($data['record_id'] ?? '');

// Si on n'a pas l'ID en mémoire, chercher par téléphone
if (!$existingRecordId) {
    $searchUrl = "https://api.airtable.com/v0/{$AIRTABLE_BASE_ID}/{$AIRTABLE_TABLE_CONTACTS}"
               . "?filterByFormula=" . rawurlencode("{Phone}='{$phone}'")
               . "&maxRecords=1";
    $ch = curl_init($searchUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$AIRTABLE_TOKEN}"],
        CURLOPT_TIMEOUT        => 8,
    ]);
    $searchResp       = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $existingRecordId = $searchResp['records'][0]['id'] ?? null;
}

// ──────────────────────────────────────────────────────────────
// SECTION 5 — CRÉER OU METTRE À JOUR
// ──────────────────────────────────────────────────────────────

// Géolocalisation — envoyée par le JS (IP réelle du visiteur)
$country  = trim($data['country']  ?? '');
$timezone = trim($data['timezone'] ?? 'Europe/Paris');
$ip       = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

$fields = [
    'FirstName'            => trim($data['firstname'] ?? ''),
    'LastName'             => trim($data['lastname']  ?? ''),
    'Email'                => $email,
    'Phone'                => $phone,
    'Company'              => trim($data['company']   ?? ''),
    'Country'              => $country,
    'Timezone'             => $timezone,
    'MeetingTypeId'        => $data['type_id']        ?? '',
    'UtmCampaign'          => $data['utm_campaign']   ?? '',
    'UtmSource'            => $data['utm_source']     ?? '',
    'UtmMedium'            => $data['utm_medium']     ?? '',
    'UtmContent'           => $data['utm_content']    ?? '',
    'UtmTerm'              => $data['utm_term']        ?? '',
    'IpAddress'            => $ip,
    'LastInteractionType'  => 'Autosave',
];

if ($existingRecordId) {
    // PATCH — mise à jour
    $url    = "https://api.airtable.com/v0/{$AIRTABLE_BASE_ID}/{$AIRTABLE_TABLE_CONTACTS}/{$existingRecordId}";
    $method = 'PATCH';
    // Mettre à jour le statut si fourni explicitement
    if (!empty($data['contact_stage']))   $fields['ContactStage']   = $data['contact_stage'];
    if (!empty($data['planning_status'])) $fields['PlanningStatus'] = $data['planning_status'];
} else {
    // POST — création avec statut initial
    $fields['ContactStage']   = $data['contact_stage']   ?? 'New Lead';
    $fields['PlanningStatus'] = $data['planning_status'] ?? 'Potentiel';
    $url    = "https://api.airtable.com/v0/{$AIRTABLE_BASE_ID}/{$AIRTABLE_TABLE_CONTACTS}";
    $method = 'POST';
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_POSTFIELDS     => json_encode(['fields' => $fields]),
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$AIRTABLE_TOKEN}",
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT        => 8,
]);
$resp = json_decode(curl_exec($ch), true);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Log pour diagnostic
$logFile = __DIR__ . '/logs/autosave_contact.log';
@mkdir(dirname($logFile), 0750, true);
@file_put_contents($logFile,
    sprintf("[%s] code=%d action=%s error=%s id=%s\n",
        date('Y-m-d H:i:s'), $code,
        $existingRecordId ? 'patch' : 'post',
        json_encode($resp['error'] ?? null),
        $resp['id'] ?? 'null'
    ), FILE_APPEND | LOCK_EX
);

echo json_encode([
    'success' => ($code === 200 || $code === 201),
    'action'  => $existingRecordId ? 'updated' : 'created',
    'id'      => $resp['id'] ?? null,
]);