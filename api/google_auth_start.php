<?php
/**
 * Google OAuth – Génération URL d'autorisation
 * Objectif : obtenir un refresh_token (Contacts + Gmail)
 * Auteur : Kévin BESNARD – LumnisTech
 */

declare(strict_types=1);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/* =========================
   CONFIG / ENV
   ========================= */

$ENV_FILE = '/home/guestlucky/private/glAgenda.env'; // chemin ABSOLU

function env_load_file(string $file): array
{
    if (!is_file($file) || !is_readable($file)) {
        return [];
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // retire guillemets si présents
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
    }

    return $env;
}

$env = env_load_file($ENV_FILE);

$clientId    = $env['GOOGLE_CLIENT_ID']    ?? '';
$redirectUri = $env['GOOGLE_REDIRECT_URI'] ?? '';

if ($clientId === '' || $redirectUri === '') {
    http_response_code(500);
    echo "❌ Configuration Google manquante dans googleContacts.env "
       . "(GOOGLE_CLIENT_ID / GOOGLE_REDIRECT_URI)";
    exit;
}

/* =========================
   OAUTH URL
   ========================= */

$scopes = [
    'https://www.googleapis.com/auth/contacts',
    'https://www.googleapis.com/auth/gmail.send',
    'https://www.googleapis.com/auth/calendar',
];

// ✅ CSRF protection (state)
$nonce = bin2hex(random_bytes(16));
$ts    = time();
$state = $nonce . '.' . $ts . '.' . hash_hmac('sha256', $nonce . '.' . $ts, $env['WEBHOOK_SECRET']);

$params = [
    'response_type' => 'code',
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'scope'         => implode(' ', $scopes),
    'access_type'   => 'offline',
    'prompt'        => 'consent',
    'include_granted_scopes' => 'true',
    'state' => $state,
];

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Google OAuth – Générer URL</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f6f7f9;
            padding: 40px;
        }
        .container {
            background: #fff;
            max-width: 900px;
            margin: auto;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,.08);
        }
        textarea {
            width: 100%;
            height: 120px;
            font-family: monospace;
            font-size: 14px;
            padding: 10px;
            margin: 15px 0;
        }
        button, a.button {
            display: inline-block;
            margin-right: 10px;
            padding: 10px 18px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
        }
        .copy {
            background: #2563eb;
            color: #fff;
        }
        .open {
            background: #16a34a;
            color: #fff;
        }
        .status {
            margin-top: 10px;
            color: #16a34a;
            font-weight: 600;
            display: none;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>🔐 Google OAuth – URL d’autorisation</h1>

    <p>Utilise ce lien pour renouveler manuellement le <strong>refresh_token</strong>.</p>

    <textarea id="oauthUrl" readonly><?= htmlspecialchars($authUrl, ENT_QUOTES) ?></textarea>

    <button class="copy" onclick="copyUrl()">📋 Copier l’URL</button>
    <a class="button open" href="<?= htmlspecialchars($authUrl, ENT_QUOTES) ?>" target="_blank">🚀 Ouvrir Google OAuth</a>

    <div id="status" class="status">✅ URL copiée dans le presse-papiers</div>
</div>

<script>
function copyUrl() {
    const textarea = document.getElementById('oauthUrl');
    textarea.select();
    textarea.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(textarea.value).then(() => {
        const status = document.getElementById('status');
        status.style.display = 'block';
        setTimeout(() => status.style.display = 'none', 2500);
    });
}
</script>

</body>
</html>