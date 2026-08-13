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

/* Réponse JSON — JAMAIS mise en cache.
   Sans Cache-Control, le navigateur applique sa « fraîcheur heuristique » et
   réutilise une réponse sans la redemander. Sur cette API, c'est faux par
   nature : le catalogue, les prix, le stock et les créneaux changent d'une
   minute à l'autre. Symptôme observé — un produit désactivé côté marque
   (ws_products.active=0) restait en vente sur le webshop : la requête n'était
   plus émise, le client relisait l'ancienne liste.
   La configuration du service worker affirmait déjà que « l'API n'est jamais
   mise en cache » ; c'était vrai du SW, pas du cache HTTP du navigateur. Ces
   en-têtes rendent la phrase exacte.
   no-store plutôt que no-cache : il n'y a rien à revalider, une donnée d'ERP
   périmée n'a aucune valeur — mieux vaut la redemander. */
function json_out($data, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');   // proxys et navigateurs anciens
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
/* Table ABSENTE : le corps reste `[]`, et un en-tête DIT laquelle manque.
 *
 * POURQUOI PAS UN 501. « Table absente » et « aucune donnée » rendaient tous
 * deux `[]` : une migration oubliée ressemblait exactement à une base vide, et
 * plus rien ne les distinguait à l'écran. Mais répondre 501 casserait les
 * écrans dont la table est LÉGITIMEMENT optionnelle sur une installation
 * donnée — un back-office qui refuse de s'afficher parce qu'une table annexe
 * manque est pire que le doute qu'on veut lever.
 *
 * L'en-tête est donc ADDITIF : tout consommateur existant continue de recevoir
 * `[]` et fonctionne, pendant que la console lit `X-Tables-Absentes` et
 * l'annonce dans son bandeau d'erreur — là où quelqu'un le verra.
 */
function json_vide($tables) {
  $t = is_array($tables) ? $tables : [$tables];
  header('X-Tables-Absentes: ' . implode(',', $t));
  json_out([]);
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

/* =====================================================================
   Gabarits d'e-mail — rendu minimal, volontairement
   =====================================================================
   Trois marqueurs, et rien d'autre :
     {{ var }}                              substitution, ÉCHAPPÉE en HTML
     <!-- IF var -->…<!-- ENDIF -->         gardé si la valeur est non vide
     <!-- FOR liste -->…<!-- ENDFOR -->     répété par élément de la liste

   Pas de moteur de gabarit : ces courriers portent des conditions
   commerciales, pas de la logique. Toute valeur absente fait DISPARAÎTRE son
   bloc — jamais de « — », jamais de montant par défaut. Un chiffre affiché
   dans ce document engage la boutique.
   ===================================================================== */
function mail_esc($v) {
  return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function mail_render($tpl, array $vars) {
  // Les boucles d'abord : leur corps peut contenir des IF et des variables.
  $tpl = preg_replace_callback('/<!-- FOR ([a-z_]+) -->(.*?)<!-- ENDFOR -->/s',
    function ($m) use ($vars) {
      $liste = $vars[$m[1]] ?? [];
      if (!is_array($liste) || !$liste) return '';
      $out = '';
      foreach ($liste as $item)
        $out .= mail_render($m[2], is_array($item) ? array_merge($vars, $item) : $vars);
      return $out;
    }, $tpl);
  // Puis les conditions, du bloc le plus interne vers l'extérieur.
  $avant = null;
  while ($avant !== $tpl) {
    $avant = $tpl;
    $tpl = preg_replace_callback('/<!-- IF ([a-z_]+) -->((?:(?!<!-- IF ).)*?)<!-- ENDIF -->/s',
      function ($m) use ($vars) {
        $v = $vars[$m[1]] ?? '';
        $plein = is_array($v) ? count($v) > 0 : (trim((string) $v) !== '');
        return $plein ? $m[2] : '';
      }, $tpl);
  }
  // Enfin les variables. Une variable inconnue s'efface : mieux vaut un vide
  // qu'un « {{ plafond }} » lu par le client.
  return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/',
    function ($m) use ($vars) {
      $v = $vars[$m[1]] ?? '';
      return is_array($v) ? '' : mail_esc($v);
    }, $tpl);
}

/* Envoi d'un courrier HTML avec sa version texte (multipart/alternative).
   Best-effort, comme les commandes : un envoi qui échoue ne doit jamais
   défaire ce qui vient d'être écrit en base. Renvoie false si rien n'est
   parti, pour que l'appelant puisse le DIRE plutôt que de le supposer. */
function send_html_mail($to, $subject, $html, $text = '') {
  $from = cfg()['mail_from'] ?? '';
  if (!$to || !$from || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
  if ($text === '') {
    $text = trim(html_entity_decode(strip_tags(preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/is', '', $html)),
                                    ENT_QUOTES, 'UTF-8'));
    $text = preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text));
  }
  $b = 'b' . bin2hex(random_bytes(8));
  $headers = "From: $from\r\nMIME-Version: 1.0\r\n"
           . "Content-Type: multipart/alternative; boundary=\"$b\"\r\n";
  $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n$text\r\n"
        . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n$html\r\n--$b--\r\n";
  // Sujet encodé : « Bienvenue chez L'Atelier By » contient des accents, et un
  // en-tête brut les rend en mojibake dans la moitié des clients.
  $sub = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  return @mail($to, $sub, $body, $headers);
}

/* Back-offices Franchise Buddy (franchisé / franchiseur) — sessions isolées.
 * Additif : ne charge que des définitions de fonctions ; n'affecte aucune route
 * existante tant qu'aucune requête « /bo/… » n'arrive (voir index.php). */
require __DIR__ . '/bo/bootstrap.php';
