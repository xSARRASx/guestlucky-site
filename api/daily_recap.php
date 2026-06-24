<?php
/**
 * ============================================================
 * FILE    : daily_recap.php
 * DOE     : 2026-05-25
 * DOS     : 2026-06-05
 * AUTHOR  : LumnisTech / Kévin Besnard
 * SUMMARY : Rapport quotidien GuestLucky — envoyé chaque matin
 *           à 4h CEST via Gmail API.
 *           - Réservations J (aujourd'hui) et J-1 (hier)
 *           - Contacts créés hier
 *           - Contacts modifiés hier (hors créés hier)
 *           Déclenchement : cron job PlanetHoster
 *           Destinataires  : ALERT_EMAIL du .env
 * ============================================================
 *
 * CRON PlanetHoster (2h UTC = 4h CEST été) :
 *   0 2 * * * /usr/bin/php /home/guestlucky/public_html/api/daily_recap.php
 *
 * Appel manuel de test :
 *   https://www.guestlucky.com/api/daily_recap.php?secret=WEBHOOK_SECRET
 *
 * Appel sur une date précise :
 *   https://www.guestlucky.com/api/daily_recap.php?secret=XXX&date=2026-06-04
 * ============================================================
 */

declare(strict_types=1);
date_default_timezone_set('Europe/Paris');

// ──────────────────────────────────────────────────────────────
// SECTION 0 — CONFIGURATION
// ──────────────────────────────────────────────────────────────

$ENV_FILE = '/home/guestlucky/private/glAgenda.env';
$LOG_FILE = __DIR__ . '/logs/daily_recap.log';

// ──────────────────────────────────────────────────────────────
// SECTION 1 — FONCTIONS UTILITAIRES
// ──────────────────────────────────────────────────────────────

function load_env(string $path): array {
    if (!is_readable($path)) throw new RuntimeException("ENV illisible: $path");
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (!$line || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
    return $out;
}

function log_line(string $level, string $msg, array $ctx = []): void {
    global $LOG_FILE;
    @mkdir(dirname($LOG_FILE), 0750, true);
    $ctx_str = empty($ctx) ? '' : ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE);
    @file_put_contents($LOG_FILE,
        sprintf("[%s] [%s] %s%s\n", date('Y-m-d H:i:s'), strtoupper($level), $msg, $ctx_str),
        FILE_APPEND | LOCK_EX
    );
}

function http_get(string $url, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($raw, true)];
}

function http_post(string $url, array $headers, string $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($raw, true)];
}

/**
 * Fetch tous les records Airtable avec pagination automatique
 */
function airtable_fetch_all(
    string $baseId, string $tableId, string $token,
    string $filter = '', array $fields = [], string $sortField = 'CreatedAt'
): array {
    $records = [];
    $offset  = null;

    do {
        $params = ['pageSize' => 100];
        if ($filter)    $params['filterByFormula']  = $filter;
        if ($fields)    foreach ($fields as $i => $f) $params["fields[$i]"] = $f;
        if ($sortField) { $params['sort[0][field]'] = $sortField; $params['sort[0][direction]'] = 'desc'; }
        if ($offset)    $params['offset'] = $offset;

        $url = "https://api.airtable.com/v0/{$baseId}/{$tableId}?" . http_build_query($params);
        $res = http_get($url, ["Authorization: Bearer $token"]);

        if ($res['code'] !== 200) {
            log_line('error', 'Airtable fetch failed', [
                'table' => $tableId,
                'code'  => $res['code'],
                'body'  => $res['body'],
            ]);
            break;
        }

        $records = array_merge($records, $res['body']['records'] ?? []);
        $offset  = $res['body']['offset'] ?? null;
        if ($offset) sleep(1);

    } while ($offset);

    return $records;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ──────────────────────────────────────────────────────────────
// SECTION 2 — SÉCURITÉ (appel web manuel)
// ──────────────────────────────────────────────────────────────

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $envTmp = load_env($ENV_FILE);
    $secret = $_GET['secret'] ?? '';
    if (empty($envTmp['WEBHOOK_SECRET']) || !hash_equals($envTmp['WEBHOOK_SECRET'], $secret)) {
        http_response_code(403);
        exit("Accès refusé.\n");
    }
}

// ──────────────────────────────────────────────────────────────
// SECTION 3 — CHARGEMENT ENV & PARAMÈTRES
// ──────────────────────────────────────────────────────────────

$ENV = load_env($ENV_FILE);

$AIRTABLE_TOKEN        = $ENV['AIRTABLE_TOKEN']        ?? '';
$AIRTABLE_BASE_ID      = $ENV['AIRTABLE_BASE_ID']      ?? '';
$TABLE_BOOKINGS        = $ENV['TABLE_BOOKINGS']         ?? '';
$TABLE_CONTACTS        = $ENV['TABLE_CONTACTS']         ?? '';
$TABLE_AGENTS          = $ENV['TABLE_AGENTS']           ?? '';
$GOOGLE_CLIENT_ID      = $ENV['GOOGLE_CLIENT_ID']       ?? '';
$GOOGLE_CLIENT_SECRET  = $ENV['GOOGLE_CLIENT_SECRET']   ?? '';
$GOOGLE_REFRESH_TOKEN  = $ENV['GOOGLE_REFRESH_TOKEN']   ?? '';
$RECAP_TO              = $ENV['ALERT_EMAIL'] ?? 'moresebastien@gmail.com';
$RECAP_FROM            = 'contact@guestlucky.com';
$RECAP_FROM_NAME       = 'GuestLucky';
$REPLY_TO              = $ENV['REPLY_TO']               ?? 'support@guestlucky.com';

foreach (['AIRTABLE_TOKEN','AIRTABLE_BASE_ID','TABLE_BOOKINGS','TABLE_CONTACTS'] as $k) {
    if (empty($ENV[$k])) {
        log_line('error', "Variable manquante: $k");
        if (!$isCli) { http_response_code(500); echo "❌ Variable manquante: $k\n"; }
        exit(1);
    }
}

// Date cible : J-1 (ou override via ?date=YYYY-MM-DD pour debug)
if (!$isCli && !empty($_GET['date'])) {
    $yesterday = new DateTime($_GET['date'], new DateTimeZone('Europe/Paris'));
    $today     = (clone $yesterday)->modify('+1 day');
} else {
    $today     = new DateTime('today',     new DateTimeZone('Europe/Paris'));
    $yesterday = new DateTime('yesterday', new DateTimeZone('Europe/Paris'));
}

$todayLabel     = $today->format('d/m/Y');
$yesterdayLabel = $yesterday->format('d/m/Y');

$todayIso     = $today->format('Y-m-d');
$yesterdayIso = $yesterday->format('Y-m-d');

$yStart = $yesterdayIso . 'T00:00:00Z';
$yEnd   = $yesterdayIso . 'T23:59:59Z';
$tStart = $todayIso     . 'T00:00:00Z';
$tEnd   = $todayIso     . 'T23:59:59Z';

log_line('info', '── Début récap quotidien ──', [
    'today'     => $todayIso,
    'yesterday' => $yesterdayIso,
    'mode'      => $isCli ? 'cron' : 'http',
]);

// ──────────────────────────────────────────────────────────────
// SECTION 4 — FETCH AIRTABLE
// ──────────────────────────────────────────────────────────────

// Réservations créées aujourd'hui — CREATED_TIME() = fonction système Airtable
$bookingsToday = airtable_fetch_all(
    $AIRTABLE_BASE_ID, $TABLE_BOOKINGS, $AIRTABLE_TOKEN,
    "AND(IS_AFTER(CREATED_TIME(),'{$tStart}'),IS_BEFORE(CREATED_TIME(),'{$tEnd}'))",
    ['AgentId','AgentName','TypeLabel','Date','Slot','Duration','ClientName','ClientCompany','ClientEmail','ClientPhone','Status','MeetLink'],
    'CreatedAt'
);
log_line('info', 'Bookings today', ['count' => count($bookingsToday)]);

// Réservations créées hier
$bookingsYesterday = airtable_fetch_all(
    $AIRTABLE_BASE_ID, $TABLE_BOOKINGS, $AIRTABLE_TOKEN,
    "AND(IS_AFTER(CREATED_TIME(),'{$yStart}'),IS_BEFORE(CREATED_TIME(),'{$yEnd}'))",
    ['AgentId','AgentName','TypeLabel','Date','Slot','Duration','ClientName','ClientCompany','ClientEmail','ClientPhone','Status','MeetLink'],
    'CreatedAt'
);
log_line('info', 'Bookings yesterday', ['count' => count($bookingsYesterday)]);

// Contacts créés hier
$contactsCreated = airtable_fetch_all(
    $AIRTABLE_BASE_ID, $TABLE_CONTACTS, $AIRTABLE_TOKEN,
    "AND(IS_AFTER(CREATED_TIME(),'{$yStart}'),IS_BEFORE(CREATED_TIME(),'{$yEnd}'))",
    ['FirstName','LastName','Phone','Email','Company','ContactStage','PlanningStatus','MeetingTypeId','UtmSource','Country','CreatedAt']
);
log_line('info', 'Contacts created', ['count' => count($contactsCreated)]);

// Contacts modifiés hier (pas créés hier)
$contactsModified = airtable_fetch_all(
    $AIRTABLE_BASE_ID, $TABLE_CONTACTS, $AIRTABLE_TOKEN,
    "AND(IS_AFTER(LAST_MODIFIED_TIME(),'{$yStart}'),IS_BEFORE(LAST_MODIFIED_TIME(),'{$yEnd}'),IS_BEFORE(CREATED_TIME(),'{$yStart}'))",
    ['FirstName','LastName','Phone','Email','Company','ContactStage','PlanningStatus','MeetingTypeId','UtmSource','Country','LastModifiedTime']
);
log_line('info', 'Contacts modified', ['count' => count($contactsModified)]);

// ──────────────────────────────────────────────────────────────
// SECTION 5 — ACCESS TOKEN GMAIL
// ──────────────────────────────────────────────────────────────

$tokenRes = http_post(
    'https://oauth2.googleapis.com/token',
    ['Content-Type: application/x-www-form-urlencoded'],
    http_build_query([
        'client_id'     => $GOOGLE_CLIENT_ID,
        'client_secret' => $GOOGLE_CLIENT_SECRET,
        'refresh_token' => $GOOGLE_REFRESH_TOKEN,
        'grant_type'    => 'refresh_token',
    ])
);

$accessToken = $tokenRes['body']['access_token'] ?? null;

if (!$accessToken) {
    log_line('error', 'Token Google invalide — fallback mail()', ['body' => $tokenRes['body']]);

    // Fallback : mail() système pour alerter même si Gmail est KO
    $alertSubject = "🚨 GuestLucky — Token Google expiré, récap non envoyé";
    $alertBody    = "Le refresh token Google est expiré ou révoqué.\n"
                  . "Le récap du {$todayLabel} n'a pas pu être envoyé via Gmail.\n\n"
                  . "Action requise :\n"
                  . "1. Désactiver règle WAF 340162 et 340163 dans PlanetHoster\n"
                  . "2. Ouvrir : https://www.guestlucky.com/api/google_auth_start.php\n"
                  . "3. Suivre le flux OAuth et cliquer 'Mettre à jour'\n"
                  . "4. Réactiver la règle WAF 340162 et 340163\n\n"
                  . "— GuestLucky Système · " . date('d/m/Y H:i');

    foreach (array_filter(array_map('trim', explode(',', $RECAP_TO))) as $r) {
        mail($r, $alertSubject, $alertBody,
            "From: noreply@guestlucky.com\r\nContent-Type: text/plain; charset=UTF-8"
        );
    }

    if (!$isCli) echo "❌ Token Google invalide — alerte mail() envoyée.\n";
    exit(1);
}

// ──────────────────────────────────────────────────────────────
// SECTION 6 — CONSTRUCTION EMAIL HTML
// ──────────────────────────────────────────────────────────────

function stat_box(string $label, string $value, string $color): string {
    return "<td style='padding:0 6px;width:33%;'>
      <div style='background:#f0f4ff;border:1px solid #dde3f0;border-radius:12px;padding:16px;text-align:center;'>
        <div style='font-size:26px;font-weight:800;color:{$color};font-family:monospace;'>" . h($value) . "</div>
        <div style='font-size:11px;color:#6b7a9f;margin-top:4px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;'>" . h($label) . "</div>
      </div>
    </td>";
}

function booking_row(array $f): string {
    $firstname = $f['ClientFirstname'] ?? ($f['ClientName'] ?? '');
    $lastname  = $f['ClientLastname']  ?? '';
    $name      = h(trim("$firstname $lastname") ?: '—');
    $email     = h($f['ClientEmail']   ?? '—');
    $phone     = h($f['ClientPhone']   ?? '');
    $type      = h($f['TypeLabel']     ?? '—');
    $agent     = h($f['AgentName']     ?? ($f['AgentId'] ?? '—'));
    $slot      = h($f['Slot']          ?? '—');
    $duration  = (int)($f['Duration']  ?? 0);
    $status    = h($f['PlanningStatus'] ?? ($f['Status'] ?? '—'));
    $meet      = $f['MeetLink'] ?? '';
    $meetBtn   = $meet
        ? "<a href='" . h($meet) . "' style='color:#7c3aed;font-size:12px;text-decoration:none;font-weight:600;'>🎥 Meet</a>"
        : '<span style="color:#c5cfe8;font-size:12px;">—</span>';

    // Date RDV formatée
    $dateRdv = '';
    if (!empty($f['Date'])) {
        try {
            $dateRdv = (new DateTime($f['Date']))->format('d/m/Y');
        } catch (Exception $e) {
            $dateRdv = $f['Date'];
        }
    }

    return "<tr style='border-bottom:1px solid #f0f4ff;'>
      <td style='padding:10px 12px;'>
        <div style='font-weight:700;font-size:13px;color:#1a2547;'>{$name}</div>
        <div style='font-size:11px;color:#6b7a9f;'>{$email}</div>
        " . ($phone ? "<div style='font-size:11px;color:#9aa3c0;'>{$phone}</div>" : '') . "
      </td>
      <td style='padding:10px 12px;font-size:13px;color:#1a2547;'>{$type}</td>
      <td style='padding:10px 12px;font-size:13px;color:#1a2547;'>{$agent}</td>
      <td style='padding:10px 12px;font-size:13px;color:#1a2547;font-family:monospace;'>
        " . ($dateRdv ? "<div style='font-size:12px;color:#6b7a9f;'>{$dateRdv}</div>" : '') . "
        {$slot} <span style='color:#9aa3c0;font-size:11px;'>({$duration}min)</span>
      </td>
      <td style='padding:10px 12px;'>
        <span style='background:rgba(124,58,237,.12);color:#7c3aed;font-size:11px;font-weight:700;padding:3px 8px;border-radius:99px;white-space:nowrap;'>{$status}</span>
      </td>
      <td style='padding:10px 12px;text-align:center;'>{$meetBtn}</td>
    </tr>";
}

function booking_section(array $records, string $title, string $dateLabel): string {
    $count = count($records);
    $badge = "<span style='background:" . ($count > 0 ? '#7c3aed20' : '#eef2ff') . ";color:" . ($count > 0 ? '#7c3aed' : '#9aa3c0') . ";font-size:12px;font-weight:700;padding:2px 10px;border-radius:99px;margin-left:8px;'>{$count}</span>";

    if ($count === 0) {
        return "<div style='margin-bottom:24px;'>
          <h3 style='margin:0 0 10px;font-size:15px;font-weight:700;color:#1a2547;'>{$title} <span style='color:#9aa3c0;font-size:13px;font-weight:400;'>— {$dateLabel}</span>{$badge}</h3>
          <p style='margin:0;color:#9aa3c0;font-size:13px;font-style:italic;padding:12px 16px;background:#f8faff;border-radius:8px;border:1px solid #eef2ff;'>Aucune réservation</p>
        </div>";
    }

    $rows = '';
    foreach ($records as $r) $rows .= booking_row($r['fields'] ?? []);

    return "<div style='margin-bottom:24px;'>
      <h3 style='margin:0 0 10px;font-size:15px;font-weight:700;color:#1a2547;'>{$title} <span style='color:#9aa3c0;font-size:13px;font-weight:400;'>— {$dateLabel}</span>{$badge}</h3>
      <div style='overflow-x:auto;'>
      <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;background:#fff;border:1px solid #dde3f0;border-radius:12px;overflow:hidden;'>
        <thead style='background:#f0f4ff;'>
          <tr>
            <th style='padding:9px 12px;text-align:left;font-size:11px;color:#6b7a9f;text-transform:uppercase;letter-spacing:.05em;font-weight:700;'>Client</th>
            <th style='padding:9px 12px;text-align:left;font-size:11px;color:#6b7a9f;text-transform:uppercase;letter-spacing:.05em;font-weight:700;'>Type</th>
            <th style='padding:9px 12px;text-align:left;font-size:11px;color:#6b7a9f;text-transform:uppercase;letter-spacing:.05em;font-weight:700;'>Agent</th>
            <th style='padding:9px 12px;text-align:left;font-size:11px;color:#6b7a9f;text-transform:uppercase;letter-spacing:.05em;font-weight:700;'>RDV</th>
            <th style='padding:9px 12px;text-align:left;font-size:11px;color:#6b7a9f;text-transform:uppercase;letter-spacing:.05em;font-weight:700;'>Statut</th>
            <th style='padding:9px 12px;text-align:center;font-size:11px;color:#6b7a9f;text-transform:uppercase;letter-spacing:.05em;font-weight:700;'>Meet</th>
          </tr>
        </thead>
        <tbody>{$rows}</tbody>
      </table>
      </div>
    </div>";
}

function contact_row(array $f, string $timeField): string {
    $name    = h(trim(($f['Firstname'] ?? '') . ' ' . ($f['Lastname'] ?? '')) ?: '—');
    $email   = h($f['Email']         ?? '—');
    $phone   = h($f['Phone']         ?? '');
    $company = h($f['Company']       ?? '');
    $stage   = $f['ContactStage']    ?? '';
    $status  = h($f['PlanningStatus'] ?? '');
    $source  = h($f['UtmSource']     ?? '');
    $country = h($f['Country']       ?? '');
    $typeId  = h($f['TypeId']        ?? '');
    $time    = isset($f[$timeField])
        ? (new DateTime($f[$timeField]))->setTimezone(new DateTimeZone('Europe/Paris'))->format('H:i')
        : '—';

    $stageColors = [
        'New Lead'  => '#3b82f6', 'Approved'  => '#22c55e',
        'Rejected'  => '#ef4444', 'Nurturing' => '#f59e0b',
    ];
    $stageColor = $stageColors[$stage] ?? '#6b7a9f';
    $stageBadge = $stage
        ? "<span style='background:{$stageColor}20;color:{$stageColor};font-size:11px;font-weight:700;padding:3px 8px;border-radius:99px;'>" . h($stage) . "</span>"
        : '—';

    return "<tr style='border-bottom:1px solid #f0f4ff;'>
      <td style='padding:10px 12px;'>
        <div style='font-weight:700;font-size:13px;color:#1a2547;'>{$name}</div>
        <div style='font-size:11px;color:#9aa3c0;'>{$time}</div>
      </td>
      <td style='padding:10px 12px;font-size:12px;color:#6b7a9f;'>
        {$email}" . ($phone ? "<br>{$phone}" : '') . "
      </td>
      <td style='padding:10px 12px;font-size:13px;color:#1a2547;'>{$company}</td>
      <td style='padding:10px 12px;'>{$stageBadge}</td>
      <td style='padding:10px 12px;font-size:12px;color:#6b7a9f;'>{$status}</td>
      <td style='padding:10px 12px;font-size:12px;color:#6b7a9f;'>{$typeId}</td>
      <td style='padding:10px 12px;font-size:12px;color:#9aa3c0;'>{$source}</td>
      <td style='padding:10px 12px;font-size:12px;color:#9aa3c0;'>{$country}</td>
    </tr>";
}

function contact_section(array $records, string $title, string $timeField): string {
    $count = count($records);
    $badge = "<span style='background:" . ($count > 0 ? '#1a254720' : '#eef2ff') . ";color:" . ($count > 0 ? '#1a2547' : '#9aa3c0') . ";font-size:12px;font-weight:700;padding:2px 10px;border-radius:99px;margin-left:8px;'>{$count}</span>";

    if ($count === 0) {
        return "<div style='margin-bottom:24px;'>
          <h3 style='margin:0 0 10px;font-size:15px;font-weight:700;color:#1a2547;'>{$title}{$badge}</h3>
          <p style='margin:0;color:#9aa3c0;font-size:13px;font-style:italic;padding:12px 16px;background:#f8faff;border-radius:8px;border:1px solid #eef2ff;'>Aucun contact</p>
        </div>";
    }

    $rows = '';
    foreach ($records as $r) $rows .= contact_row($r['fields'] ?? [], $timeField);

    return "<div style='margin-bottom:24px;'>
      <h3 style='margin:0 0 10px;font-size:15px;font-weight:700;color:#1a2547;'>{$title}{$badge}</h3>
      <div style='overflow-x:auto;'>
      <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;background:#fff;border:1px solid #dde3f0;border-radius:12px;overflow:hidden;'>
        <thead style='background:#f0f4ff;'>
          <tr>
            <th style='padding:9px 12px;text-align:left;font-size:11px;color:#6b7a9f;text-transform:uppercase;letter-spacing:.05em;font-weight:700;'>Nom</th>
            <th style='padding:9px 12px;text-align:left;font-size:11px;color:#6b7a9f;text-transform:uppercase;letter-spacing:.05em;font-weight:700;'>Contact</th>
            <th style='padding:9px 12px;text-align:left;font-size:11px;color:#6b7a9f;text-transform:uppercase;letter-spacing:.05em;font-weight:700;'>Entreprise</th>
            <th style='padding:9px 12px;text-align:left;font-size:11px;color:#6b7a9f;text-transform:uppercase;letter-spacing:.05em;font-weight:700;'>Stage</th>
            <th style='padding:9px 12px;text-align:left;font-size:11px;color:#6b7a9f;text-transform:uppercase;letter-spacing:.05em;font-weight:700;'>Planning</th>
            <th style='padding:9px 12px;text-align:left;font-size:11px;color:#6b7a9f;text-transform:uppercase;letter-spacing:.05em;font-weight:700;'>Type RDV</th>
            <th style='padding:9px 12px;text-align:left;font-size:11px;color:#6b7a9f;text-transform:uppercase;letter-spacing:.05em;font-weight:700;'>Source</th>
            <th style='padding:9px 12px;text-align:left;font-size:11px;color:#6b7a9f;text-transform:uppercase;letter-spacing:.05em;font-weight:700;'>Pays</th>
          </tr>
        </thead>
        <tbody>{$rows}</tbody>
      </table>
      </div>
    </div>";
}

// ── Totaux ────────────────────────────────────────────────────
$totalBookings = count($bookingsToday) + count($bookingsYesterday);
$totalContacts = count($contactsCreated) + count($contactsModified);
$totalAgents   = count(array_unique(array_filter(array_map(
    fn($r) => $r['fields']['AgentId'] ?? $r['fields']['AgentName'] ?? '',
    array_merge($bookingsToday, $bookingsYesterday)
))));

// ── Sections ──────────────────────────────────────────────────
$sBookingsToday     = booking_section($bookingsToday,     '📅 Réservations du jour',     $todayLabel);
$sBookingsYesterday = booking_section($bookingsYesterday, '📅 Réservations de la veille', $yesterdayLabel);
$sContactsCreated   = contact_section($contactsCreated,  '🆕 Contacts créés hier',       'CreatedAt');
$sContactsModified  = contact_section($contactsModified, '✏️ Contacts modifiés hier',    'LastModifiedTime');

// ── Template HTML ─────────────────────────────────────────────
$htmlBody = "<!doctype html>
<html lang='fr'>
<head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='margin:0;padding:0;background:#f0f4ff;font-family:Inter,Segoe UI,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f0f4ff;padding:32px 16px;'>
<tr><td align='center'>
<table width='700' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 8px 40px rgba(26,37,71,.10);'>

  <!-- HEADER -->
  <tr>
    <td style='background:linear-gradient(135deg,#1a2547 0%,#2d3f7a 100%);padding:24px 32px;'>
      <table width='100%' cellpadding='0' cellspacing='0'>
        <tr>
          <td>
            <img src='https://www.guestlucky.com/logo-gl.png' alt='GuestLucky' height='32'
                 style='display:block;height:32px;'>
          </td>
          <td align='right'>
            <span style='background:rgba(124,58,237,.3);color:#c4b5fd;font-size:11px;font-weight:700;padding:4px 12px;border-radius:99px;letter-spacing:.04em;'>
              📊 Récap quotidien
            </span>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- TITRE + STATS -->
  <tr>
    <td style='padding:28px 32px 20px;'>
      <h1 style='margin:0 0 4px;font-size:22px;font-weight:800;color:#1a2547;letter-spacing:-.02em;'>
        Bonjour Sébastien 👋
      </h1>
      <p style='margin:0 0 20px;color:#6b7a9f;font-size:14px;'>
        Récap du <strong style='color:#1a2547;'>{$todayLabel}</strong> — activité des dernières 48h
      </p>
      <table width='100%' cellpadding='0' cellspacing='0'>
        <tr>
          " . stat_box('Réservations', (string)$totalBookings, '#7c3aed') . "
          " . stat_box('Contacts', (string)$totalContacts, '#1a2547') . "
          " . stat_box('Agents actifs', (string)($totalAgents ?: '—'), '#2d3f7a') . "
        </tr>
      </table>
    </td>
  </tr>

  <!-- SÉPARATEUR -->
  <tr><td style='padding:0 32px;'><hr style='border:none;border-top:1px solid #eef2ff;margin:4px 0 20px;'></td></tr>

  <!-- CONTENU -->
  <tr>
    <td style='padding:0 32px 32px;'>
      {$sBookingsToday}
      {$sBookingsYesterday}
      {$sContactsCreated}
      {$sContactsModified}
    </td>
  </tr>

  <!-- FOOTER -->
  <tr>
    <td style='background:#f8faff;border-top:1px solid #dde3f0;padding:16px 32px;text-align:center;'>
      <p style='margin:0 0 6px;font-size:12px;color:#9aa3c0;'>
        <strong style='color:#1a2547;'>Guest<span style='color:#7c3aed;'>Lucky</span></strong>
        — Rapport automatique généré à 4h00 ·
        <a href='https://www.guestlucky.com' style='color:#7c3aed;text-decoration:none;'>guestlucky.com</a>
      </p>
      <p style='margin:0;font-size:11px;color:#c5cfe8;'>
        Généré le " . date('d/m/Y à H:i') . "
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>";

// ──────────────────────────────────────────────────────────────
// SECTION 7 — ENVOI VIA GMAIL API
// ──────────────────────────────────────────────────────────────

$subject  = "📊 GuestLucky — Récap {$todayLabel} ({$totalBookings} RDV · {$totalContacts} contacts)";
$boundary = '----=_Part_' . md5(uniqid('', true));

$raw  = "MIME-Version: 1.0\r\n";
$raw .= "From: {$RECAP_FROM_NAME} <{$RECAP_FROM}>\r\n";
$raw .= "To: {$RECAP_TO}\r\n";
$raw .= "Reply-To: {$REPLY_TO}\r\n";
$raw .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
$raw .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
$raw .= "--{$boundary}\r\n";
$raw .= "Content-Type: text/html; charset=UTF-8\r\n";
$raw .= "Content-Transfer-Encoding: base64\r\n\r\n";
$raw .= chunk_split(base64_encode($htmlBody)) . "\r\n";
$raw .= "--{$boundary}--";

$encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

$sendRes = http_post(
    'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
    [
        "Authorization: Bearer {$accessToken}",
        'Content-Type: application/json',
    ],
    json_encode(['raw' => $encoded])
);

if (in_array($sendRes['code'], [200, 201])) {
    log_line('info', '✅ Récap envoyé', [
        'to'        => $RECAP_TO,
        'date'      => $todayLabel,
        'bookings'  => $totalBookings,
        'contacts'  => $totalContacts,
        'gmail_id'  => $sendRes['body']['id'] ?? '?',
    ]);
    if (!$isCli) {
        echo "✅ Récap du {$todayLabel} envoyé.\n";
        echo "   Réservations : {$totalBookings}\n";
        echo "   Contacts     : {$totalContacts}\n";
    }
} else {
    log_line('error', '❌ Envoi Gmail échoué', [
        'code' => $sendRes['code'],
        'body' => $sendRes['body'],
    ]);
    if (!$isCli) {
        http_response_code(500);
        echo "❌ Erreur envoi Gmail : " . json_encode($sendRes['body']) . "\n";
    }
    exit(1);
}