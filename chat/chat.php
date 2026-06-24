<?php
/**
 * Lucky Copilot vitrine - endpoint backend.
 *
 * Recoit une question JSON, appelle Gemini avec le prompt systeme + le
 * manuel, renvoie la reponse au widget.
 *
 * Securite :
 * - Cle Gemini lue dans .env (jamais dans le code).
 * - Limite de 20 questions / IP / heure (anti-spam).
 * - Aucune base de donnees, aucun log de conversation.
 */
declare(strict_types=1);
require_once __DIR__ . '/system_prompt.php';
// ============================================================================
// CORS
// ============================================================================
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
  'https://guestlucky.com',
  'https://www.guestlucky.com',
  'http://localhost',
  'http://localhost:8000',
  'http://127.0.0.1',
];
if (in_array($origin, $allowedOrigins, true)) {
  header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Vary: Origin');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}
header('Content-Type: application/json; charset=utf-8');
// ============================================================================
// Configuration
// ============================================================================
const RATE_LIMIT_PER_HOUR = 20;
const MAX_QUESTION_LENGTH = 4000;
const MAX_HISTORY_MESSAGES = 12;
const GEMINI_MODEL  = 'gemini-2.5-flash';
const GEMINI_TIMEOUT  = 25;
const RATE_LIMIT_FILE  = __DIR__ . '/rate_limit.json';
// ============================================================================
// Charger .env (Gemini API key)
// ============================================================================
$envPath = __DIR__ . '/.env';
if (!is_file($envPath)) {
  respond(500, "Configuration manquante (.env absent).");
}
$envContent = file_get_contents($envPath) ?: '';
$apiKey = '';
foreach (preg_split('/\r\n|\n|\r/', $envContent) as $line) {
  $line = trim($line);
  if ($line === '' || str_starts_with($line, '#')) {
  continue;
  }
  if (preg_match('/^GEMINI_API_KEY\s*=\s*(.+)$/', $line, $m)) {
  $apiKey = trim($m[1], " \t\"'");
  break;
  }
}
if ($apiKey === '' || !preg_match('/^AIza[A-Za-z0-9_\-]{30,}$/', $apiKey)) {
  respond(500, "Cle Gemini invalide ou absente.");
}
// ============================================================================
// Lire la requete
// ============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond(405, "Methode non autorisee.");
}
$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
  respond(400, "JSON invalide.");
}
$question = trim((string) ($data['question'] ?? ''));
$history  = is_array($data['history'] ?? null) ? $data['history'] : [];
if ($question === '') {
  respond(400, "Question vide.");
}
if (mb_strlen($question) > MAX_QUESTION_LENGTH) {
  respond(400, "Question trop longue (max " . MAX_QUESTION_LENGTH . " caracteres).");
}
// ============================================================================
// Rate limiting par IP
// ============================================================================
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ip = explode(',', $ip)[0];
$ip = trim($ip);
if (!rateLimitAllow($ip)) {
  respond(429, "Tu as pose beaucoup de questions, fais une petite pause "
  . "et reviens dans une heure. Si tu veux echanger maintenant, tu peux "
  . "prendre un appel decouverte (gratuit, 15 min) : "
  . "https://www.guestlucky.com/rdv/site");
}
// ============================================================================
// Construire la requete Gemini
// ============================================================================
$systemPrompt = guestlucky_system_prompt();
$contents = [];
// Historique de la conversation (questions / reponses precedentes)
$history = array_slice($history, -MAX_HISTORY_MESSAGES);
foreach ($history as $entry) {
  if (!is_array($entry)) {
  continue;
  }
  $role = ($entry['role'] ?? '') === 'assistant' ? 'model' : 'user';
  $text = trim((string) ($entry['text'] ?? ''));
  if ($text === '') {
  continue;
  }
  $contents[] = [
  'role'  => $role,
  'parts' => [['text' => mb_substr($text, 0, 4000)]],
  ];
}
// Question actuelle
$contents[] = [
  'role'  => 'user',
  'parts' => [['text' => $question]],
];
$payload = [
  'systemInstruction' => [
  'parts' => [['text' => $systemPrompt]],
  ],
  'contents'  => $contents,
  'generationConfig' => [
  'temperature'  => 0.5,
  'topP'  => 0.9,
  'maxOutputTokens'  => 4096,
  'thinkingConfig'  => ['thinkingBudget' => 0],
  ],
  'safetySettings' => [
  ['category' => 'HARM_CATEGORY_HARASSMENT',  'threshold' => 'BLOCK_ONLY_HIGH'],
  ['category' => 'HARM_CATEGORY_HATE_SPEECH',  'threshold' => 'BLOCK_ONLY_HIGH'],
  ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',  'threshold' => 'BLOCK_ONLY_HIGH'],
  ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',  'threshold' => 'BLOCK_ONLY_HIGH'],
  ],
];
// ============================================================================
// Appel Gemini
// ============================================================================
$url = sprintf(
  'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
  GEMINI_MODEL,
  urlencode($apiKey)
);
$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST  => true,
  CURLOPT_HTTPHEADER  => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS  => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT  => GEMINI_TIMEOUT,
  CURLOPT_CONNECTTIMEOUT => 10,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);
if ($response === false) {
  respond(502, "Le service de reponse est momentanement indisponible. "
  . "Reessaye dans quelques secondes. (" . htmlspecialchars($curlErr, ENT_QUOTES) . ")");
}
$body = json_decode($response, true);
if ($httpCode === 429) {
  respond(503, "Trop de questions en meme temps cote serveur. "
  . "Reessaye dans une minute.");
}
if ($httpCode !== 200) {
  $errMsg = is_array($body) && isset($body['error']['message'])
  ? (string) $body['error']['message']
  : 'erreur inconnue';
  respond(502, "Le service de reponse a rencontre un probleme. Reessaye "
  . "dans quelques instants.");
}
// ============================================================================
// Extraire la reponse
// ============================================================================
$candidates = $body['candidates'] ?? [];
$text = '';
if (!empty($candidates[0]['content']['parts'])) {
  foreach ($candidates[0]['content']['parts'] as $part) {
  if (isset($part['text'])) {
  $text .= $part['text'];
  }
  }
}
$text = trim($text);
if ($text === '') {
  respond(502, "Je n'ai pas reussi a formuler une reponse. Reformule la "
  . "question, ou prends un appel decouverte : "
  . "https://www.guestlucky.com/rdv/site");
}
echo json_encode([
  'ok'  => true,
  'answer' => $text,
], JSON_UNESCAPED_UNICODE);
exit;
// ============================================================================
// Helpers
// ============================================================================
function respond(int $code, string $message): void
{
  http_response_code($code);
  echo json_encode([
  'ok'  => $code >= 200 && $code < 300,
  'error' => $message,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
function rateLimitAllow(string $ip): bool
{
  $now  = time();
  $window = 3600;
  $file  = RATE_LIMIT_FILE;
  $data = [];
  if (is_file($file)) {
  $content = file_get_contents($file);
  if ($content !== false) {
  $decoded = json_decode($content, true);
  if (is_array($decoded)) {
  $data = $decoded;
  }
  }
  }
  // Nettoyage : on enleve toutes les IPs dont l'entree la plus recente
  // est plus vieille que la fenetre.
  foreach ($data as $key => $timestamps) {
  if (!is_array($timestamps)) {
  unset($data[$key]);
  continue;
  }
  $data[$key] = array_values(array_filter(
  $timestamps,
  fn ($t) => is_int($t) && ($now - $t) < $window
  ));
  if (empty($data[$key])) {
  unset($data[$key]);
  }
  }
  $ipKey = hash('sha256', $ip);
  $timestamps = $data[$ipKey] ?? [];
  if (count($timestamps) >= RATE_LIMIT_PER_HOUR) {
  // Sauvegarde quand meme pour garder le nettoyage
  @file_put_contents($file, json_encode($data));
  return false;
  }
  $timestamps[] = $now;
  $data[$ipKey] = $timestamps;
  @file_put_contents($file, json_encode($data), LOCK_EX);
  return true;
}
