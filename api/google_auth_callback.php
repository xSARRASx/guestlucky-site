<?php
/**
 * google_oauth_callback.php
 * - Reçoit ?code=...
 * - Échange contre access_token + refresh_token
 * - Propose un bouton pour écrire le refresh_token dans googleContacts.env (GOOGLE_REFRESH_TOKEN=)
 */

declare(strict_types=1);
date_default_timezone_set('Europe/Paris');
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/* =========================
   CONFIG / ENV
   ========================= */

$ENV_FILE = '/home/VOTRE_USER/private/glAgenda.env'; // <= adapte le chemin ABSOLU

function env_load_file(string $file): array {
    if (!is_file($file) || !is_readable($file)) {
        return [];
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }

    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // KEY=VALUE
        if (!str_contains($line, '=')) {
            continue;
        }

        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);

        // retire les guillemets si présents
        if ($v !== '' && (
            ($v[0] === '"' && substr($v, -1) === '"') ||
            ($v[0] === "'" && substr($v, -1) === "'")
        )) {
            $v = substr($v, 1, -1);
        }

        $env[$k] = $v;
    }

    return $env;
}

$env = env_load_file($ENV_FILE);

$clientId     = $env['GOOGLE_CLIENT_ID']     ?? '';
$clientSecret = $env['GOOGLE_CLIENT_SECRET'] ?? '';
$redirectUri  = $env['GOOGLE_REDIRECT_URI']  ?? '';

if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
    http_response_code(500);
    echo "❌ Config Google manquante dans googleContacts.env. "
       . "Attendu: GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI";
    exit;
}

// (Optionnel mais conseillé) un “secret” simple en plus (ex: ?k=xxxxx)
// $REQUIRE_KEY = true;
// $KEY = 'TON_SECRET_LONG_ET_RANDOM';

/* =========================
   HELPERS
   ========================= */

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function update_env_key(string $file, string $key, string $value): array {
    if (!is_file($file)) {
        return [false, "Fichier introuvable: $file"];
    }
    if (!is_readable($file) || !is_writable($file)) {
        return [false, "Permissions insuffisantes (read/write) sur: $file"];
    }

    $original = file_get_contents($file);
    if ($original === false) {
        return [false, "Impossible de lire le fichier env."];
    }

    $eol = str_contains($original, "\r\n") ? "\r\n" : "\n";
    $lines = preg_split("/\r\n|\n|\r/", $original);

    $found = false;
    $newLines = [];

    foreach ($lines as $line) {
        // Conserve les commentaires / lignes vides
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            $newLines[] = $line;
            continue;
        }

        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*)\s*$/', $line)) {
            $newLines[] = $key . '=' . $value;
            $found = true;
        } else {
            $newLines[] = $line;
        }
    }

    if (!$found) {
        $newLines[] = $key . '=' . $value;
    }

    $newContent = implode($eol, $newLines);

    // Backup
    $backup = $file . '.' . date('Ymd-His') . '.bak';
    if (file_put_contents($backup, $original, LOCK_EX) === false) {
        return [false, "Impossible d'écrire le backup: $backup"];
    }

    // Écriture atomique
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $newContent, LOCK_EX) === false) {
        return [false, "Impossible d'écrire le fichier temporaire: $tmp"];
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        return [false, "Impossible de remplacer le fichier env (rename a échoué)."];
    }

    return [true, "OK. Token mis à jour. Backup créé: $backup"];
}

/* =========================
   CSRF
   ========================= */

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

/* =========================
   STEP 1 : POST => update env
   ========================= */

// ✅ On distingue:
// - POST Google OAuth (code/state/scope) => on DOIT aller à l'échange token
// - POST de ton formulaire (csrf + refresh_token) => update env

$isFormUpdate = ($_SERVER['REQUEST_METHOD'] === 'POST')
    && isset($_POST['csrf'], $_POST['refresh_token']);

if ($isFormUpdate) {
    $postedCsrf = $_POST['csrf'] ?? '';
    $refreshToken = $_POST['refresh_token'] ?? '';

    if (!hash_equals($csrf, (string)$postedCsrf)) {
        http_response_code(403);
        echo "❌ CSRF invalide.";
        exit;
    }

    if ($refreshToken === '' || strlen($refreshToken) < 20) {
        http_response_code(400);
        echo "❌ refresh_token invalide (trop court / vide).";
        exit;
    }

    [$ok, $msg] = update_env_key($ENV_FILE, 'GOOGLE_REFRESH_TOKEN', $refreshToken);

    echo "<!doctype html><meta charset='utf-8'>";
    echo "<h2>" . ($ok ? "✅ Succès" : "❌ Erreur") . "</h2>";
    echo "<p>" . h($msg) . "</p>";
    echo "<p><a href='" . h($redirectUri) . "'>↩️ Revenir</a></p>";
    exit;
}

/* =========================
   STEP 2 : GET => exchange code
   ========================= */

// ✅ OAuth state (CSRF protection)
$state = $_POST['state'] ?? $_GET['state'] ?? null;
$expectedState = $_SESSION['google_oauth_state'] ?? null;
$stateTs = $_SESSION['google_oauth_state_ts'] ?? 0;

// One-shot: on invalide le state quoi qu'il arrive
unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_state_ts']);

if (!$expectedState || !$state || !hash_equals($expectedState, (string)$state)) {
    http_response_code(400);
    exit("❌ State OAuth invalide (CSRF). Relance l'auth depuis google_auth_start.php");
}

// Optionnel: expire après 10 minutes
if ($stateTs && (time() - (int)$stateTs) > 600) {
    http_response_code(400);
    exit("❌ State OAuth expiré. Relance l'auth.");
}

$code = $_POST['code'] ?? $_GET['code'] ?? null;
if (!$code) {
  die("❌ Pas de code dans l'URL.");
}

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ]),
]);

$resp = curl_exec($ch);
if ($resp === false) {
    echo "Erreur cURL : " . h(curl_error($ch));
    exit;
}
curl_close($ch);

$data = json_decode($resp, true) ?: [];

$refresh = $data['refresh_token'] ?? '';

?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Google OAuth Callback</title>
  <style>
    body { font-family: system-ui, -apple-system, sans-serif; background:#f6f7f9; padding:40px; }
    .box { background:#fff; max-width:900px; margin:auto; padding:24px; border-radius:10px; box-shadow:0 10px 25px rgba(0,0,0,.08); }
    pre { background:#0b1020; color:#dbeafe; padding:14px; border-radius:8px; overflow:auto; }
    button { padding:10px 16px; border-radius:8px; border:none; cursor:pointer; font-weight:700; }
    .primary { background:#16a34a; color:#fff; }
    .warn { color:#b45309; }
  </style>
</head>
<body>
<div class="box">
  <h1>🔁 OAuth Callback</h1>

  <h3>Réponse Google (debug)</h3>
  <pre><?= h(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>

  <?php if ($refresh): ?>
    <h2>🌟 Nouveau refresh_token</h2>
    <pre id="rt"><?= h($refresh) ?></pre>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="refresh_token" value="<?= h($refresh) ?>">

      <button class="primary" type="submit">✅ Mettre à jour GOOGLE_REFRESH_TOKEN dans glAgenda.env</button>
    </form>

    <p style="margin-top:12px" class="warn">
      ⚠️ Cette action modifie un fichier serveur. Assure-toi que cette page est protégée (accès restreint).
    </p>

  <?php else: ?>
    <p class="warn">
      ⚠️ Pas de refresh_token dans la réponse.
      Vérifie que tu utilises bien <code>access_type=offline</code> + <code>prompt=consent</code> dans l’URL d’auth.
    </p>
  <?php endif; ?>
</div>
</body>
</html>