<?php
/**
 * ============================================================
 * FILE    : booking_webhook.php
 * DOE     : 2026-05-19
 * DOS     : 2026-06-10
 * AUTHOR  : LumnisTech / Kévin Besnard
 * SUMMARY : Webhook de réservation agenda LumnisTech
 *           1. Valide la requête POST JSON
 *           2. Crée l'enregistrement dans Airtable (table Bookings)
 *           3. Crée l'événement dans Google Calendar
 *           4. Envoie l'email de confirmation au client via Gmail API
 * ============================================================
 */

// ──────────────────────────────────────────────────────────────
// SECTION 0 — CONFIGURATION
// ──────────────────────────────────────────────────────────────

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
header('Access-Control-Allow-Headers: Content-Type, X-Webhook-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ──────────────────────────────────────────────────────────────
// SECTION 1 — FONCTIONS UTILITAIRES
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

function json_response(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function http_request(string $url, string $method, array $headers, ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    if ($error) return ['error' => $error, 'code' => 0, 'body' => null];
    return ['code' => $httpCode, 'body' => json_decode($response, true), 'raw' => $response];
}

function log_event(string $level, string $message, array $context = []): void {
    $logFile = __DIR__ . '/logs/booking_webhook.log';
    @mkdir(dirname($logFile), 0750, true);
    $line = sprintf("[%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'), strtoupper($level), $message,
        empty($context) ? '' : json_encode($context, JSON_UNESCAPED_UNICODE)
    );
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// ──────────────────────────────────────────────────────────────
// SECTION 2 — FONCTIONS AIRTABLE
// ──────────────────────────────────────────────────────────────

function airtable_create_record(string $tableId, array $fields): ?string {
    $url    = "https://api.airtable.com/v0/" . $GLOBALS['AIRTABLE_BASE_ID'] . "/" . rawurlencode($tableId);
    $result = http_request($url, 'POST', [
        'Authorization: Bearer ' . $GLOBALS['AIRTABLE_TOKEN'],
        'Content-Type: application/json',
    ], json_encode(['fields' => $fields]));

    log_event('debug', 'airtable_create_record', [
        'table'   => $tableId,
        'code'    => $result['code'],
        'error'   => $result['body']['error'] ?? null,
        'id'      => $result['body']['id'] ?? null,
    ]);

    if ($result['code'] !== 200 && $result['code'] !== 201) {
        log_event('error', 'Airtable create failed', ['code' => $result['code'], 'body' => $result['body']]);
        return null;
    }
    return $result['body']['id'] ?? null;
}

function airtable_get_agent(string $agentId): ?array {
    $url = "https://api.airtable.com/v0/" . $GLOBALS['AIRTABLE_BASE_ID']
         . "/" . $GLOBALS['AIRTABLE_TABLE_AGENTS']
         . "?filterByFormula=" . rawurlencode("{AgentId}='" . $agentId . "'")
         . "&maxRecords=1";

    $result = http_request($url, 'GET', [
        'Authorization: Bearer ' . $GLOBALS['AIRTABLE_TOKEN'],
    ]);

    if ($result['code'] !== 200) {
        log_event('error', 'Airtable get agent failed', ['agentId' => $agentId]);
        return null;
    }
    $records = $result['body']['records'] ?? [];
    return empty($records) ? null : $records[0]['fields'];
}

function airtable_patch_record(string $tableId, string $recordId, array $fields): void {
    $url    = "https://api.airtable.com/v0/" . $GLOBALS['AIRTABLE_BASE_ID']
            . "/" . rawurlencode($tableId) . "/" . $recordId;
    $result = http_request($url, 'PATCH', [
        'Authorization: Bearer ' . $GLOBALS['AIRTABLE_TOKEN'],
        'Content-Type: application/json',
    ], json_encode(['fields' => $fields]));

    if ($result['code'] !== 200) {
        log_event('error', 'airtable_patch_record failed', [
            'table'  => $tableId,
            'record' => $recordId,
            'code'   => $result['code'],
            'error'  => $result['body']['error'] ?? null,
        ]);
    }
}

// ──────────────────────────────────────────────────────────────
// SECTION 3 — FONCTIONS GOOGLE OAUTH2
// ──────────────────────────────────────────────────────────────

function google_refresh_access_token(string $refreshToken): ?string {
    $result = http_request(
        'https://oauth2.googleapis.com/token', 'POST',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id'     => $GLOBALS['GOOGLE_CLIENT_ID'],
            'client_secret' => $GLOBALS['GOOGLE_CLIENT_SECRET'],
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ])
    );
    if ($result['code'] !== 200) {
        log_event('error', 'Google token refresh failed', ['code' => $result['code']]);
        return null;
    }
    return $result['body']['access_token'] ?? null;
}

// ──────────────────────────────────────────────────────────────
// SECTION 4 — FONCTIONS GOOGLE CALENDAR
// ──────────────────────────────────────────────────────────────

function gcal_create_event(
    string $accessToken, string $calendarId, string $summary, string $description,
    string $dateStr, string $timeSlot, int $durationMin,
    string $clientEmail, string $agentEmail, string $timezone = 'Europe/Paris'
): ?string {
    $startDt = new DateTime($dateStr . ' ' . $timeSlot, new DateTimeZone($timezone));
    $endDt   = clone $startDt;
    $endDt->modify("+{$durationMin} minutes");

    $event = [
        'summary'     => $summary,
        'description' => $description,
        'start'       => ['dateTime' => $startDt->format('c'), 'timeZone' => $timezone],
        'end'         => ['dateTime' => $endDt->format('c'),   'timeZone' => $timezone],
        'attendees'   => [
            ['email' => $clientEmail],
            ['email' => $agentEmail, 'organizer' => true],
        ],
        'reminders'   => [
            'useDefault' => false,
            'overrides'  => [
                ['method' => 'email', 'minutes' => 1440],
                ['method' => 'popup', 'minutes' => 30],
            ],
        ],
        'conferenceData' => [
            'createRequest' => [
                'requestId'             => 'lumnis_' . time(),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ],
        ],
    ];

    $url    = "https://www.googleapis.com/calendar/v3/calendars/"
            . rawurlencode($calendarId)
            . "/events?conferenceDataVersion=1&sendUpdates=none";
    $result = http_request($url, 'POST', [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ], json_encode($event));

    if ($result['code'] !== 200 && $result['code'] !== 201) {
        log_event('error', 'GCal create event failed', ['code' => $result['code'], 'body' => $result['body']]);
        return null;
    }
    return $result['body']['htmlLink'] ?? null;
}

// ──────────────────────────────────────────────────────────────
// SECTION 5 — FONCTIONS GMAIL
// ──────────────────────────────────────────────────────────────

function gmail_send(string $accessToken, string $from, string $to, string $subject, string $htmlBody, string $replyTo = '', string $cci = ''): bool {
    $boundary = '----=_Part_' . md5(uniqid());
    $raw  = "MIME-Version: 1.0\r\n";
    $raw .= "From: {$from}\r\n";
    $raw .= "To: {$to}\r\n";
    if ($cci)      $raw .= "Cci: {$cci}\r\n";
    if ($replyTo) $raw .= "Reply-To: {$replyTo}\r\n";
    $raw .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $raw .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
    $raw .= "--{$boundary}\r\n";
    $raw .= "Content-Type: text/html; charset=UTF-8\r\n";
    $raw .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $raw .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    $raw .= "--{$boundary}--";

    $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $result  = http_request(
        'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', 'POST',
        ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        json_encode(['raw' => $encoded])
    );

    if ($result['code'] !== 200 && $result['code'] !== 201) {
        log_event('error', 'Gmail send failed', ['code' => $result['code'], 'to' => $to]);
        return false;
    }
    return true;
}

function build_confirmation_email(array $booking, ?string $meetLink, string $timezone = 'Europe/Paris'): string {
    $agentName  = htmlspecialchars($booking['agent_name']);
    $clientName = htmlspecialchars($booking['client_firstname'] . ' ' . $booking['client_lastname']);
    $typeLabel  = htmlspecialchars($booking['type_label']);
    $typeIcon   = $booking['type_icon'];
    $dateLabel  = htmlspecialchars($booking['date_label']);
    $slot       = htmlspecialchars($booking['slot']);
    $duration   = (int)$booking['duration'];
    // Fuseau horaire lisible (ex: "Europe/Paris" → "Paris")
    $tzParts    = explode('/', $timezone);
    $tzDisplay  = end($tzParts);
    $tzLabel    = "heure de {$tzDisplay}";;
    $meetCode    = $meetLink ? basename(parse_url($meetLink, PHP_URL_PATH)) : '';
    $meetSection = $meetLink
        ? "<tr><td style='padding:0 36px 28px;'>
            <a href='{$meetLink}' style='display:block;background:linear-gradient(135deg,#ede9fe,#ddd6fe);border:1px solid #c4b5fd;border-radius:14px;padding:16px 20px;text-decoration:none;text-align:center;'>
              <span style='font-size:20px;margin-right:8px;'>🎥</span>
              <strong style='color:#1a2547;font-size:15px;'>Rejoindre sur Google Meet</strong>
            </a>
            <p style='margin:12px 0 0;text-align:center;font-size:12px;color:#9aa3c0;line-height:1.6;'>
              Problème de connexion ?
              <a href='https://meet.google.com/landing' style='color:#7c3aed;text-decoration:none;'>meet.google.com/landing</a>
              et saisissez le code&nbsp;: <strong style='color:#1a2547;letter-spacing:.05em;font-family:monospace;font-size:13px;'>{$meetCode}</strong>
            </p>
           </td></tr>"
        : '';
    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f0f4ff;font-family:'Inter','Segoe UI',sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4ff;padding:40px 20px;">
    <tr><td align="center">
      <table width="580" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 8px 40px rgba(26,37,71,.12);">

        <!-- HEADER -->
        <tr>
          <td style="background:linear-gradient(135deg,#1a2547 0%,#2d3f7a 100%);padding:28px 36px;">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td>
                  <img src="https://www.guestlucky.com/logo-gl.png"
                       alt="GuestLucky" height="36"
                       style="display:block;height:36px;object-fit:contain;">
                </td>
                <td align="right">
                  <span style="background:rgba(124,58,237,.25);border:1px solid rgba(124,58,237,.4);color:#c4b5fd;font-size:12px;font-weight:600;padding:4px 12px;border-radius:99px;letter-spacing:.04em;">
                    Confirmation RDV
                  </span>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- HERO -->
        <tr>
          <td style="padding:36px 36px 0;">
            <table cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
              <tr>
                <td style="width:56px;height:56px;background:linear-gradient(135deg,#ede9fe,#ddd6fe);border-radius:16px;text-align:center;vertical-align:middle;font-size:26px;line-height:56px;">
                  {$typeIcon}
                </td>
              </tr>
            </table>
            <h1 style="margin:0 0 8px;font-size:26px;font-weight:800;color:#1a2547;letter-spacing:-.03em;line-height:1.2;">
              Votre rendez-vous<br>est confirmé ! 🎉
            </h1>
            <p style="margin:0 0 28px;color:#6b7a9f;font-size:15px;line-height:1.6;">
              Bonjour <strong style="color:#1a2547;">{$clientName}</strong>, voici le récapitulatif de votre échange avec l'équipe GuestLucky.
            </p>
          </td>
        </tr>

        <!-- DÉTAILS -->
        <tr>
          <td style="padding:0 36px 28px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8faff;border:1px solid #dde3f0;border-radius:14px;overflow:hidden;">
              <tr>
                <td style="background:#1a2547;padding:12px 20px;">
                  <p style="margin:0;font-size:11px;font-weight:700;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.08em;">Détails du rendez-vous</p>
                </td>
              </tr>
              <tr>
                <td style="padding:20px 20px 16px;">
                  <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="padding:6px 0;border-bottom:1px solid #eef2ff;">
                        <span style="font-size:13px;color:#6b7a9f;display:inline-block;width:120px;">Type</span>
                        <strong style="font-size:14px;color:#1a2547;">{$typeLabel}</strong>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:6px 0;border-bottom:1px solid #eef2ff;">
                        <span style="font-size:13px;color:#6b7a9f;display:inline-block;width:120px;">Date &amp; heure</span>
                        <strong style="font-size:14px;color:#1a2547;">{$dateLabel} à {$slot}</strong>
                        <span style="font-size:12px;color:#9aa3c0;margin-left:6px;">({$tzLabel})</span>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:6px 0;border-bottom:1px solid #eef2ff;">
                        <span style="font-size:13px;color:#6b7a9f;display:inline-block;width:120px;">Durée</span>
                        <strong style="font-size:14px;color:#1a2547;">{$duration} minutes</strong>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:6px 0;">
                        <span style="font-size:13px;color:#6b7a9f;display:inline-block;width:120px;">Interlocuteur</span>
                        <strong style="font-size:14px;color:#1a2547;">{$agentName}</strong>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- GOOGLE MEET -->
        {$meetSection}

        <!-- CTA -->
        <tr>
          <td style="padding:0 36px 36px;text-align:center;">
            <p style="margin:0 0 20px;color:#6b7a9f;font-size:14px;line-height:1.6;">
              Un événement a été ajouté à votre agenda Google.<br>
              Des questions ? Répondez simplement à cet email.
            </p>
            <a href="mailto:contact@guestlucky.com"
               style="display:inline-block;background:linear-gradient(135deg,#1a2547,#2d3f7a);color:#ffffff;font-weight:700;font-size:14px;padding:14px 32px;border-radius:12px;text-decoration:none;letter-spacing:.01em;">
              Contacter GuestLucky →
            </a>
          </td>
        </tr>

        <!-- FOOTER -->
        <tr>
          <td style="background:#f8faff;border-top:1px solid #dde3f0;padding:20px 36px;text-align:center;">
            <p style="margin:0 0 4px;font-size:13px;font-weight:700;color:#1a2547;">
              Guest<span style="color:#7c3aed;">lucky</span>
            </p>
            <p style="margin:0;font-size:12px;color:#9aa3c0;">
              Le Channel Manager des pros de la location courte durée<br>
              <a href="https://www.guestlucky.com" style="color:#7c3aed;text-decoration:none;">guestlucky.com</a>
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

// ──────────────────────────────────────────────────────────────
// SECTION 6 — POINT D'ENTRÉE PRINCIPAL
// ──────────────────────────────────────────────────────────────

// Étape 1 — Vérifier la méthode
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

// Étape 2 — Charger l'ENV
try {
    $ENV = load_env_file($ENV_FILE);
} catch (RuntimeException $e) {
    json_response(['error' => $e->getMessage()], 500);
}

foreach (['AIRTABLE_TOKEN', 'AIRTABLE_BASE_ID', 'TABLE_BOOKINGS', 'TABLE_AGENTS', 'WEBHOOK_SECRET'] as $k) {
    if (empty($ENV[$k])) {
        json_response(['error' => "Variable manquante dans .env : {$k}"], 500);
    }
}

$WEBHOOK_SECRET               = $ENV['WEBHOOK_SECRET'];
$AIRTABLE_TOKEN               = $ENV['AIRTABLE_TOKEN'];
$AIRTABLE_BASE_ID             = $ENV['AIRTABLE_BASE_ID'];
$AIRTABLE_TABLE_BOOKINGS      = $ENV['TABLE_BOOKINGS'];
$AIRTABLE_TABLE_AGENTS        = $ENV['TABLE_AGENTS'];
$AIRTABLE_TABLE_CONTACTS      = $ENV['TABLE_CONTACTS']  ?? '';
$AIRTABLE_TABLE_QUESTIONS     = $ENV['TABLE_QUESTIONS'] ?? '';
$GOOGLE_CLIENT_ID             = $ENV['GOOGLE_CLIENT_ID']      ?? '';
$GOOGLE_CLIENT_SECRET         = $ENV['GOOGLE_CLIENT_SECRET']  ?? '';
$GOOGLE_REFRESH_TOKEN         = $ENV['GOOGLE_REFRESH_TOKEN']  ?? '';
$REPLY_TO                     = $ENV['REPLY_TO']              ?? '';

// Injection dans $GLOBALS pour les fonctions PHP
$GLOBALS['AIRTABLE_TOKEN']        = $AIRTABLE_TOKEN;
$GLOBALS['AIRTABLE_BASE_ID']      = $AIRTABLE_BASE_ID;
$GLOBALS['AIRTABLE_TABLE_AGENTS'] = $AIRTABLE_TABLE_AGENTS;
$GLOBALS['GOOGLE_CLIENT_ID']      = $GOOGLE_CLIENT_ID;
$GLOBALS['GOOGLE_CLIENT_SECRET']  = $GOOGLE_CLIENT_SECRET;
$GLOBALS['GOOGLE_REFRESH_TOKEN']  = $GOOGLE_REFRESH_TOKEN;

// Étape 3 — Vérifier le secret partagé
$secret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
if (!hash_equals($WEBHOOK_SECRET, $secret)) {
    log_event('warning', 'Unauthorized webhook attempt', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    json_response(['error' => 'Unauthorized'], 401);
}

// Étape 4 — Lire et valider le body JSON
$rawBody = file_get_contents('php://input');
$data    = json_decode($rawBody, true);

$required = ['agent_id','agent_name','agent_email','type_id','type_label','type_icon',
             'type_color','date','date_label','slot','duration',
             'client_firstname','client_lastname','client_email','client_phone'];

foreach ($required as $field) {
    if (empty($data[$field])) {
        json_response(['error' => "Missing field: {$field}"], 422);
    }
}

$booking = $data;

// Étape 5 — Upsert contact dans Airtable (chercher par téléphone d'abord)
$contactId = null;
if ($AIRTABLE_TABLE_CONTACTS) {
    log_event('info', 'Upserting contact', ['phone' => $booking['client_phone']]);

    $answersJson = '';
    if (!empty($booking['answers']) && is_array($booking['answers'])) {
        $answersJson = json_encode($booking['answers'], JSON_UNESCAPED_UNICODE);
    }

    // Géolocalisation — envoyée par le JS (IP réelle du visiteur)
    // Fallback ipapi.co si absent (appel direct API sans front)
    $ip       = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $country  = $booking['country']  ?? '';
    $timezone = $booking['timezone'] ?? '';
    if (!$country && $ip && $ip !== '127.0.0.1') {
        $geoRes = @file_get_contents("https://ipapi.co/{$ip}/json/");
        if ($geoRes) {
            $geo      = json_decode($geoRes, true);
            $country  = $geo['country_name'] ?? '';
            $timezone = $geo['timezone']     ?? 'Europe/Paris';
        }
    }
    if (!$timezone) $timezone = 'Europe/Paris';

    $contactFields = [
        'FirstName'           => $booking['client_firstname'],
        'LastName'            => $booking['client_lastname'],
        'Phone'               => $booking['client_phone'],
        'Email'               => $booking['client_email'],
        'Company'             => $booking['client_company']  ?? '',
        'PlanningStatus'      => ($booking['qualified'] ?? true) ? 'Qualifié' : 'Disqualifié',
        'AssignedCloser'      => $booking['agent_name']      ?? '',
        'Country'             => $country,
        'Timezone'            => $timezone,
        'MeetingTypeId'       => $booking['type_id'],
        'Answers'             => $answersJson,
        'UtmCampaign'         => $booking['utm_campaign']    ?? '',
        'UtmSource'           => $booking['utm_source']      ?? '',
        'UtmMedium'           => $booking['utm_medium']      ?? '',
        'UtmContent'          => $booking['utm_content']     ?? '',
        'UtmTerm'             => $booking['utm_term']        ?? '',
        'IpAddress'           => $ip,
        'LastInteractionType' => 'Booking',
    ];

    // Chercher si le contact existe déjà par téléphone
    $phone     = $booking['client_phone'];
    $searchUrl = "https://api.airtable.com/v0/{$AIRTABLE_BASE_ID}/{$AIRTABLE_TABLE_CONTACTS}"
               . "?filterByFormula=" . rawurlencode("{Phone}='{$phone}'")
               . "&maxRecords=1";
    $searchRes = http_request($searchUrl, 'GET', [
        'Authorization: Bearer ' . $AIRTABLE_TOKEN,
    ]);
    $existingId = $searchRes['body']['records'][0]['id'] ?? null;

    if ($existingId) {
        // PATCH — mettre à jour le contact existant
        airtable_patch_record($AIRTABLE_TABLE_CONTACTS, $existingId, $contactFields);
        $contactId = $existingId;
        log_event('info', 'Contact updated (upsert)', ['id' => $contactId]);
    } else {
        // POST — créer un nouveau contact
        $contactFields['ContactStage'] = 'New Lead';
        $contactId = airtable_create_record($AIRTABLE_TABLE_CONTACTS, $contactFields);
        log_event('info', 'Contact created (upsert)', ['id' => $contactId, 'null_means_error' => $contactId === null]);
    }
}

// Étape 6 — Créer l'enregistrement Booking dans Airtable
log_event('info', 'Creating booking record', ['client' => $booking['client_email']]);

$airtableId = airtable_create_record($AIRTABLE_TABLE_BOOKINGS, [
    'AgentId'       => $booking['agent_id'],
    'AgentName'     => $booking['agent_name'],
    'TypeId'        => $booking['type_id'],
    'TypeLabel'     => $booking['type_label'],
    'Date'          => $booking['date'],
    'Slot'          => $booking['slot'],
    'Duration'      => (int)$booking['duration'],
    'ClientName'    => $booking['client_firstname'] . ' ' . $booking['client_lastname'],
    'ClientEmail'   => $booking['client_email'],
    'ClientPhone'   => $booking['client_phone'],
    'Message'       => $booking['message'] ?? '',
    'Status'        => 'Confirmed',
    'ContactId'     => $contactId ?? '',
]);

if (!$airtableId) {
    json_response(['error' => 'Failed to create Airtable record'], 500);
}

log_event('info', 'Airtable record created', ['id' => $airtableId]);

// Mettre à jour le contact avec le BookingId maintenant qu'on l'a
if ($contactId && $airtableId && $AIRTABLE_TABLE_CONTACTS) {
    $planningStatus = $booking['type_planning_status'] ?? 'Appel de découverte réservé';
    airtable_patch_record($AIRTABLE_TABLE_CONTACTS, $contactId, [
        'BookingId'      => $airtableId,
        'PlanningStatus' => $planningStatus,
        'ContactStage'   => 'Approved',
    ]);
    log_event('info', 'Contact updated with BookingId', [
        'contact'         => $contactId,
        'booking'         => $airtableId,
        'planning_status' => $planningStatus,
        'contact_stage'   => 'Approved',
    ]);
}

// Étape 7 — Récupérer les credentials Google de l'agent
$agentRecord = airtable_get_agent($booking['agent_id']);
$meetLink    = null;
$gcalSuccess = false;
$emailSent   = false;

if (!$agentRecord) {
    log_event('warning', 'Agent not found in Airtable', ['agentId' => $booking['agent_id']]);
} else {
    $refreshToken = $GLOBALS['GOOGLE_REFRESH_TOKEN']; // token central
    $calendarId   = $agentRecord['GoogleCalendarId'] ?? 'primary';
    $agentEmail   = $agentRecord['Email']             ?? $booking['agent_email'];
    $agentTimezone= $agentRecord['Timezone']          ?? 'Europe/Paris';

    if ($refreshToken) {
        // Étape 8 — Rafraîchir l'access token Google
        $accessToken = google_refresh_access_token($refreshToken);

        if ($accessToken) {
            // Étape 9 — Construire le titre et la description de l'événement GCal
            $clientFullName = trim($booking['client_firstname'] . ' ' . $booking['client_lastname']);
            $summary        = "{$booking['type_icon']} {$booking['type_label']} — {$clientFullName}";

            $descLines = [
                "Client : {$clientFullName}",
                "Email  : {$booking['client_email']}",
                "Tél    : " . ($booking['client_phone'] ?? 'N/A'),
                "Société: " . ($booking['client_company'] ?? 'N/A'),
                "Durée  : {$booking['duration']} min",
                "Message: " . ($booking['message'] ?? 'Aucun'),
            ];

            // Ajout des questions/réponses pré-RDV si présentes
            if (!empty($booking['answers']) && is_array($booking['answers'])) {
                $descLines[] = "";
                $descLines[] = "── Questions pré-RDV ──";
                foreach ($booking['answers'] as $qa) {
                    $q = $qa['question'] ?? '';
                    $a = $qa['answer']   ?? '';
                    if (is_array($a)) $a = implode(', ', $a);
                    if ($q) $descLines[] = "• {$q}\n  → {$a}";
                }
            }

            $descLines[] = "";
            $descLines[] = "Réservé via guestlucky.com — Airtable ID : {$airtableId}";
            $description = implode("\n", $descLines);

            // Étape 10 — Créer l'événement Google Calendar
            $meetLink    = gcal_create_event(
                $accessToken, $calendarId ?: 'primary',
                $summary, $description,
                $booking['date'], $booking['slot'], (int)$booking['duration'],
                $booking['client_email'], $agentEmail,
                $booking['timezone'] ?: 'Europe/Paris'
            );

            $gcalSuccess = ($meetLink !== null);
            log_event('info', 'GCal event created', ['meetLink' => $meetLink]);

            // Invalider le cache availability
            $cacheFile = __DIR__ . '/cache/availability/avail_' . $booking['agent_id'] . '.json';
            if (file_exists($cacheFile)) {
                @unlink($cacheFile);
                log_event('info', 'Cache invalidated', ['agent' => $booking['agent_id']]);
            }

            // Mettre à jour Airtable avec le lien Meet
            if ($meetLink) {
                airtable_patch_record($AIRTABLE_TABLE_BOOKINGS, $airtableId, [
                    'MeetLink'        => $meetLink,
                    'GCalEventCreated'=> true,
                ]);
            }

            // Étape 11 — Envoyer l'email de confirmation Gmail
            $htmlEmail = build_confirmation_email($booking, $meetLink, $booking['timezone'] ?: 'Europe/Paris');
            $emailSent = gmail_send(
                $accessToken,
                "GuestLucky <contact@guestlucky.com>",
                $booking['client_email'],
                "✅ Confirmation RDV – {$booking['type_label']} · {$booking['date_label']}",
                $htmlEmail,
                $REPLY_TO,
                $agentEmail  // Cc → l'agent reçoit une copie
            );

            log_event('info', 'Email sent', ['to' => $booking['client_email'], 'success' => $emailSent]);
        } else {
            log_event('error', 'Could not refresh Google token', ['agentId' => $booking['agent_id']]);
        }
    }
}

// Étape 12 — Réponse finale
json_response([
    'success'      => true,
    'airtable_id'  => $airtableId,
    'gcal_created' => $gcalSuccess,
    'meet_link'    => $meetLink,
    'email_sent'   => $emailSent,
]);