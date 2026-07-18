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

/* Garde du back-office : jeton admin (X-Admin-Token ou Bearer). */
function require_admin() {
  $expected = cfg()['admin_token'] ?? '';
  if ($expected === '') json_out(['error' => 'Admin non configuré (admin_token manquant)'], 503);
  $given = req_header('X-Admin-Token');
  if ($given === '') { $a = req_header('Authorization'); if (stripos($a, 'bearer ') === 0) $given = substr($a, 7); }
  if (!hash_equals($expected, trim($given))) json_out(['error' => 'Non autorisé'], 401);
}

/* E-mail de confirmation de commande (best-effort ; n'échoue jamais la commande). */
function send_order_email($ref, $lines, $total, $to) {
  $from = cfg()['mail_from'] ?? '';
  if (!$to || !$from || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
  $items = '';
  foreach ($lines as $l) $items .= "- {$l['name']} x{$l['qty']} : " . number_format($l['unit'], 2) . " EUR\n";
  $body = "Bonjour,\n\nMerci pour votre commande $ref.\n\n$items\nTotal : " . number_format($total, 2) . " EUR\n\nL'Atelier By";
  $headers = "From: $from\r\nContent-Type: text/plain; charset=utf-8\r\n";
  @mail($to, "Confirmation de commande $ref", $body, $headers);
}

/* Back-offices Franchise Buddy (franchisé / franchiseur) — sessions isolées.
 * Additif : ne charge que des définitions de fonctions ; n'affecte aucune route
 * existante tant qu'aucune requête « /bo/… » n'arrive (voir index.php). */
require __DIR__ . '/bo/bootstrap.php';
