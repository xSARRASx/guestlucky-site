<?php
/**
 * GuestLucky — Webinar registration via WebinarJam One-Click URL
 * Fait l'appel One-Click côté serveur (PHP curl) pour que WebinarJam traite
 * vraiment l'inscription (les iframes sont bloquées par WJ).
 * Aucune clé API nécessaire, juste l'URL One-Click.
 */

const ONE_CLICK_BASE = 'https://event.webinarjam.com/zg53v/register/r2wplcw1/1click';
const SCHEDULE_ID = 1;
const NOTIFY_EMAIL = 'contact@guestlucky.com'; // mettre "" pour désactiver

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$firstname = trim($_POST['firstname'] ?? '');
$lastname  = trim($_POST['lastname']  ?? '');
$email     = trim($_POST['email']     ?? '');
$phone     = trim($_POST['phone']     ?? '');
$consent   = isset($_POST['consent']);

if (!$firstname || !$lastname || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$consent) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Champs manquants ou invalides']);
    exit;
}

$params = [
    'first_name'  => $firstname,
    'last_name'   => $lastname,
    'email'       => $email,
    'timezone'    => 'Europe/Paris',
    'schedule_id' => SCHEDULE_ID,
];
if ($phone) {
    $params['phone_country_code'] = '+33';
    $params['phone_number'] = preg_replace('/\D/', '', $phone);
}

$url = ONE_CLICK_BASE . '?' . http_build_query($params);

// Simule la visite d'un vrai navigateur
$http_code = 0;
$response = '';
$err = '';

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
} else {
    // Fallback sans curl
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n",
            'timeout' => 20,
            'follow_location' => 1,
        ]
    ]);
    $response = @file_get_contents($url, false, $ctx);
    $http_code = $response === false ? 0 : 200;
    $err = $response === false ? 'file_get_contents failed' : '';
}

// Notification email à l'admin
if (NOTIFY_EMAIL) {
    $subject = "[Webinaire GuestLucky] Inscription : $firstname $lastname";
    $body  = "Nouvelle inscription au webinaire :\n\n";
    $body .= "Prénom : $firstname\n";
    $body .= "Nom : $lastname\n";
    $body .= "Email : $email\n";
    if ($phone) $body .= "Téléphone : $phone\n";
    $body .= "Date : " . date('Y-m-d H:i:s') . "\n";
    $body .= "Source : " . ($_POST['source'] ?? 'webinaire.html') . "\n\n";
    $body .= "WebinarJam HTTP $http_code\n";
    if ($err) $body .= "Erreur : $err\n";
    @mail(NOTIFY_EMAIL, $subject, $body, "From: noreply@guestlucky.com\r\n");
}

if ($http_code >= 200 && $http_code < 400) {
    echo json_encode([
        'ok' => true,
        'message' => 'Inscription confirmée',
        'http_code' => $http_code,
    ]);
} else {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => 'Erreur communication WebinarJam',
        'http_code' => $http_code,
        'curl_error' => $err,
    ]);
}
