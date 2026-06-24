<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Paris');
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$ENV_FILE = '/home/guestlucky/private/glAgenda.env';

function env_load_file(string $file): array {
    if (!is_file($file) || !is_readable($file)) return [];
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) return [];
    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if ($v !== '' && (
            ($v[0] === '"' && substr($v, -1) === '"') ||
            ($v[0] === "'" && substr($v, -1) === "'")
        )) { $v = substr($v, 1, -1); }
        $env[$k] = $v;
    }
    return $env;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function update_env_key(string $file, string $key, string $value): array {
    if (!is_file($file)) return [false, "Fichier introuvable: $file"];
    if (!is_readable($file) || !is_writable($file)) return [false, "Permissions insuffisantes sur: $file"];
    $original = file_get_contents($file);
    if ($original === false) return [false, "Impossible de lire le fichier env."];
    $eol = str_contains($original, "\r\n") ? "\r\n" : "\n";
    $lines = preg_split("/\r\n|\n|\r/", $original);
    $found = false;
    $newLines = [];
    foreach ($lines as $line) {
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) { $newLines[] = $line; continue; }
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $line)) {
            $newLines[] = $key . '=' . $value;
            $found = true;
        } else { $newLines[] = $line; }
    }
    if (!$found) $newLines[] = $key . '=' . $value;
    $newContent = implode($eol, $newLines);
    $backup = $file . '.' . date('Ymd-His') . '.bak';
    if (file_put_contents($backup, $original, LOCK_EX) === false) return [false, "Impossible d'écrire le backup."];
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $newContent, LOCK_EX) === false) return [false, "Impossible d'écrire le fichier temporaire."];
    if (!rename($tmp, $file)) { @unlink($tmp); return [false, "rename() a échoué."]; }
    return [true, "Token mis à jour. Backup: $backup"];
}

$env = env_load_file($ENV_FILE);
$clientId     = $env['GOOGLE_CLIENT_ID']     ?? '';
$clientSecret = $env['GOOGLE_CLIENT_SECRET'] ?? '';
$redirectUri  = $env['GOOGLE_REDIRECT_URI']  ?? '';
$webhookSecret = $env['WEBHOOK_SECRET']      ?? '';

if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
    http_response_code(500);
    exit("❌ Config Google manquante (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REDIRECT_URI)");
}

// ── CSRF token pour le formulaire POST de mise à jour ──
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// ── CAS 1 : POST formulaire mise à jour .env ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf'], $_POST['refresh_token'])) {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403); exit("❌ CSRF invalide.");
    }
    $refreshToken = $_POST['refresh_token'] ?? '';
    if ($refreshToken === '' || strlen($refreshToken) < 20) {
        http_response_code(400); exit("❌ refresh_token invalide.");
    }
    [$ok, $msg] = update_env_key($ENV_FILE, 'GOOGLE_REFRESH_TOKEN', $refreshToken);
    echo "<!doctype html><meta charset='utf-8'>";
    echo "<h2>" . ($ok ? "✅ Succès" : "❌ Erreur") . "</h2>";
    echo "<p>" . h($msg) . "</p>";
    exit;
}

// ── CAS 2 : GET callback Google — vérification HMAC ──
$state = $_GET['state'] ?? '';
$parts = explode('.', $state);
if (count($parts) !== 3) {
    http_response_code(400); exit("❌ State malformé.");
}
[$nonce, $ts, $sig] = $parts;
$expected = hash_hmac('sha256', $nonce . '.' . $ts, $webhookSecret);
if (!hash_equals($expected, $sig)) {
    http_response_code(400); exit("❌ State invalide (signature).");
}
if ((time() - (int)$ts) > 600) {
    http_response_code(400); exit("❌ State expiré (> 10 min). Relance depuis google_auth_start.php");
}

// ── Échange du code ──
$code = $_GET['code'] ?? '';
if ($code === '') exit("❌ Pas de code dans l'URL.");

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
if ($resp === false) exit("Erreur cURL : " . h(curl_error($ch)));
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
    body { font-family: system-ui, sans-serif; background:#f6f7f9; padding:40px; }
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
    <pre><?= h($refresh) ?></pre>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="refresh_token" value="<?= h($refresh) ?>">
      <button class="primary" type="submit">✅ Mettre à jour GOOGLE_REFRESH_TOKEN dans glAgenda.env</button>
    </form>
    <p class="warn">⚠️ Cette action modifie un fichier serveur.</p>
  <?php else: ?>
    <p class="warn">⚠️ Pas de refresh_token. Vérifie <code>access_type=offline</code> + <code>prompt=consent</code>.</p>
  <?php endif; ?>
</div>
</body>
</html>