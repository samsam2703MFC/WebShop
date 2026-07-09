<?php
/* Helpers partagés : config, connexion PDO, réponses JSON, jetons d'auth. */

function cfg() { static $c; if ($c === null) $c = require __DIR__ . '/config.php'; return $c; }

function db() {
  static $pdo;
  if (!$pdo) {
    $c = cfg()['db'];
    $pdo = new PDO(
      "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",
      $c['user'], $c['pass'],
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
  }
  return $pdo;
}

function q($sql, $params = []) { $st = db()->prepare($sql); $st->execute($params); return $st; }
function rows($sql, $p = []) { return q($sql, $p)->fetchAll(); }
function row($sql, $p = []) { $r = q($sql, $p)->fetch(); return $r ?: null; }

function json_out($data, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function body() { $b = json_decode(file_get_contents('php://input'), true); return is_array($b) ? $b : []; }
function qp($key, $default = null) { return $_GET[$key] ?? $default; }

/* ── Jetons de session : HMAC {id, exp} en base64url (sans table de session) ── */
function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec($s) { return base64_decode(strtr($s, '-_', '+/')); }
function sign_token($payload) {
  $b = b64u(json_encode($payload));
  return $b . '.' . b64u(hash_hmac('sha256', $b, cfg()['auth_secret'], true));
}
function verify_token($token) {
  $p = explode('.', (string) $token);
  if (count($p) !== 2) return null;
  [$b, $sig] = $p;
  $expect = b64u(hash_hmac('sha256', $b, cfg()['auth_secret'], true));
  if (!hash_equals($expect, $sig)) return null;
  $d = json_decode(b64u_dec($b), true);
  if (!is_array($d) || (isset($d['exp']) && $d['exp'] < time())) return null;
  return $d;
}
function req_header($name) {
  if (function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) if (strcasecmp($k, $name) === 0) return $v;
  }
  return $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '';
}
function auth_uid() {
  $h = req_header('Authorization');
  if (stripos($h, 'bearer ') === 0) $h = substr($h, 7);
  return verify_token(trim($h))['id'] ?? null;
}
