<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$LOG_FILE = __DIR__ . '/logs/check_promo.log';
@mkdir(dirname($LOG_FILE), 0750, true);
@file_put_contents($LOG_FILE, "[" . date('Y-m-d H:i:s') . "] START\n", FILE_APPEND);

// ── ENV ──────────────────────────────────────────────────────
$ENV_FILE = '/home/VOTRE_USER/private/glAgenda.env';
$env = [];
if (is_readable($ENV_FILE)) {
    foreach (file($ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (!$line || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
}

$AIRTABLE_TOKEN    = $env['AIRTABLE_TOKEN']    ?? '';
$AIRTABLE_BASE_ID  = $env['AIRTABLE_BASE_ID']  ?? '';
$TABLE_PROMO_CODES = $env['TABLE_PROMO_CODES'] ?? '';

@file_put_contents($LOG_FILE,
    "[" . date('Y-m-d H:i:s') . "] ENV token=" . substr($AIRTABLE_TOKEN,0,10) .
    " base=" . $AIRTABLE_BASE_ID . " table=" . $TABLE_PROMO_CODES . "\n",
    FILE_APPEND
);

if (!$AIRTABLE_TOKEN || !$AIRTABLE_BASE_ID || !$TABLE_PROMO_CODES) {
    echo json_encode(['valid' => false, 'message' => 'Config manquante', 'debug' => [
        'token' => $AIRTABLE_TOKEN ? 'ok' : 'vide',
        'base'  => $AIRTABLE_BASE_ID ?: 'vide',
        'table' => $TABLE_PROMO_CODES ?: 'vide',
    ]]);
    exit;
}

// ── INPUT ────────────────────────────────────────────────────
$data   = json_decode(file_get_contents('php://input'), true) ?? [];
$code   = strtoupper(trim($data['code']    ?? ''));
$typeId = trim($data['type_id'] ?? '');

@file_put_contents($LOG_FILE,
    "[" . date('Y-m-d H:i:s') . "] INPUT code=$code type_id=$typeId\n",
    FILE_APPEND
);

if (!$code) { echo json_encode(['valid' => false, 'message' => 'Code vide']); exit; }

// ── AIRTABLE ─────────────────────────────────────────────────
$formula = "AND({Active}=TRUE(), UPPER({Code})='" . addslashes($code) . "')";
$url     = "https://api.airtable.com/v0/{$AIRTABLE_BASE_ID}/{$TABLE_PROMO_CODES}"
         . "?filterByFormula=" . rawurlencode($formula) . "&maxRecords=1";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$AIRTABLE_TOKEN}"],
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$raw      = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$resp = json_decode($raw, true);

@file_put_contents($LOG_FILE,
    "[" . date('Y-m-d H:i:s') . "] AIRTABLE code=$httpCode records=" .
    count($resp['records'] ?? []) . " preview=" . substr($raw, 0, 200) . "\n",
    FILE_APPEND
);

if ($httpCode !== 200 || empty($resp['records'])) {
    echo json_encode(['valid' => false, 'message' => 'Code invalide']);
    exit;
}

$f     = $resp['records'][0]['fields'];
$recId = $resp['records'][0]['id'];

// ── EXPIRATION ───────────────────────────────────────────────
if (!empty($f['ExpiresAt']) && strtotime($f['ExpiresAt']) < time()) {
    echo json_encode(['valid' => false, 'message' => 'Code expiré']);
    exit;
}

// ── MAX USES ─────────────────────────────────────────────────
$maxUses   = (int)($f['MaxUses']   ?? 0);
$usedCount = (int)($f['UsedCount'] ?? 0);
if ($maxUses > 0 && $usedCount >= $maxUses) {
    echo json_encode(['valid' => false, 'message' => 'Code épuisé']);
    exit;
}

// ── TYPE RDV ─────────────────────────────────────────────────
$rawAllowed   = $f['MeetingTypeId'] ?? [];
$allowedTypes = is_array($rawAllowed)
    ? array_map('strtolower', array_map('trim', $rawAllowed))
    : array_map('strtolower', array_map('trim', explode(',', (string)$rawAllowed)));

@file_put_contents($LOG_FILE,
    "[" . date('Y-m-d H:i:s') . "] TYPES allowed=" . json_encode($allowedTypes) .
    " requested=$typeId\n", FILE_APPEND
);

if (empty($allowedTypes)) {
    echo json_encode(['valid' => false, 'message' => 'Code non configuré pour un type de RDV']);
    exit;
}
if ($typeId && !in_array(strtolower($typeId), $allowedTypes)) {
    echo json_encode(['valid' => false, 'message' => 'Code non valide pour ce type de RDV']);
    exit;
}

// ── INCRÉMENTER ──────────────────────────────────────────────
$patchUrl = "https://api.airtable.com/v0/{$AIRTABLE_BASE_ID}/{$TABLE_PROMO_CODES}/{$recId}";
$ch = curl_init($patchUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PATCH',
    CURLOPT_POSTFIELDS     => json_encode(['fields' => ['UsedCount' => $usedCount + 1]]),
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$AIRTABLE_TOKEN}", 'Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 8,
]);
curl_exec($ch);
curl_close($ch);

@file_put_contents($LOG_FILE, "[" . date('Y-m-d H:i:s') . "] VALID code=$code\n", FILE_APPEND);

echo json_encode(['valid' => true, 'code' => $code, 'description' => $f['Description'] ?? 'Offert']);