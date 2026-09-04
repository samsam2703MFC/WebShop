<?php
/* ── CONNEXION CLIENT PAR L'ERP ──────────────────────────────────────────────
 * L'ERP authentifie un client par e-mail ou téléphone E.164 + mot de passe
 * (POST /clients/auth/login), rend un JWT de 30 min et un refresh token à
 * usage unique (POST /clients/auth/refresh), et sait fermer la session
 * (POST /clients/auth/logout). Il n'offre AUCUN moyen de poser ou changer le
 * mot de passe d'un client : les mots de passe définis sur le webshop restent
 * locaux tant que cet endpoint n'existe pas.
 *
 * Doctrine : l'ERP D'ABORD, le mot de passe local EN REPLI.
 *   - 200 ERP → connecté, session ERP mémorisée (ws_erp_client_sessions).
 *   - 403 ERP (inactif / bloqué) → refus ferme : l'ERP fait autorité.
 *   - 401 / 422 / ERP injoignable → mot de passe local, comme avant.
 * Jamais de mot de passe ni de jeton dans les journaux.
 * Kill switch : ws_param.erp_client_auth = 0 (défaut : actif si l'ERP l'est). */

function erp_client_auth_enabled(): bool {
  if (!function_exists('erp_cfg') || erp_cfg()['base'] === '') return false;
  if (function_exists('ws_param')) {
    try { $v = (string) ws_param('erp_client_auth', ''); if ($v === '0' || strtolower($v) === 'off') return false; }
    catch (Throwable $e) { /* table absente : actif */ }
  }
  return true;
}

/* POST JSON public sur l'ERP. Rend [code HTTP, corps décodé|null]. 0 = injoignable. */
function erp_client_post(string $path, array $body): array {
  $cfg = erp_cfg();
  if ($cfg['base'] === '') return [0, null];
  $ctx = stream_context_create(['http' => [
    'method' => 'POST', 'timeout' => max(4, (int) $cfg['timeout']), 'ignore_errors' => true,
    'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
    'content' => json_encode($body),
  ]]);
  $raw = @file_get_contents($cfg['base'] . '/' . ltrim($path, '/'), false, $ctx);
  $code = 0;
  foreach (($http_response_header ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int) $m[1];
  $d = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
  return [$code, is_array($d) ? $d : null];
}

/* Charge utile d'un JWT (sans vérification : il vient de l'ERP par TLS et ne
   sert qu'à lire client_id / preferred_shop_id). */
function erp_jwt_payload(string $jwt): array {
  $p = explode('.', $jwt);
  if (count($p) < 2) return [];
  $d = json_decode(base64_decode(strtr($p[1], '-_', '+/') . str_repeat('=', (4 - strlen($p[1]) % 4) % 4)), true);
  return is_array($d) ? $d : [];
}

/* Tentative de connexion. ['ok'=>true, clientId, preferredShopId, access,
   refresh, expiresIn, sessionId] ou ['ok'=>false, 'code'=>401|403|422|0]. */
function erp_client_login(string $login, string $password): array {
  if ($login === '' || $password === '') return ['ok' => false, 'code' => 422];
  [$code, $d] = erp_client_post('clients/auth/login', ['login' => $login, 'password' => $password]);
  if ($code !== 200 || !$d || empty($d['access_token'])) {
    if (function_exists('erp_notes') && $code === 0) erp_notes('ERP : connexion client injoignable');
    return ['ok' => false, 'code' => $code];
  }
  $j = erp_jwt_payload((string) $d['access_token']);
  return ['ok' => true,
          'clientId'        => (int) ($j['client_id'] ?? $j['sub'] ?? 0),
          'preferredShopId' => (int) ($j['preferred_shop_id'] ?? 0),
          'access'          => (string) $d['access_token'],
          'refresh'         => (string) ($d['refresh_token'] ?? ''),
          'expiresIn'       => (int) ($d['expires_in'] ?? 1800),
          'sessionId'       => (int) ($d['session_id'] ?? 0)];
}

function erp_client_sessions_ok(): bool {
  static $ok = null;
  if ($ok === null) $ok = function_exists('tbl_exists') ? (bool) tbl_exists('ws_erp_client_sessions') : false;
  return $ok;
}

/* Mémorise (ou remplace) la session ERP d'un client local. */
function erp_client_session_store(int $localId, array $r): void {
  if (!erp_client_sessions_ok() || $localId <= 0 || empty($r['ok'])) return;
  $exp = date('Y-m-d H:i:s', time() + max(60, (int) $r['expiresIn']) - 30);
  q("INSERT INTO ws_erp_client_sessions (client_id, erp_session_id, erp_client_id, access_token, refresh_token, expires_at)
     VALUES (?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE erp_session_id=VALUES(erp_session_id), erp_client_id=VALUES(erp_client_id),
       access_token=VALUES(access_token), refresh_token=VALUES(refresh_token), expires_at=VALUES(expires_at)",
    [$localId, $r['sessionId'] ?: null, $r['clientId'] ?: null, $r['access'], $r['refresh'] ?: null, $exp]);
}

/* Jeton d'accès ERP COURANT du client : celui mémorisé s'il est encore valide,
   sinon rafraîchi (le refresh token tourne : le nouveau remplace l'ancien).
   null : pas de session ERP, ou session révoquée (403 / 401 au refresh). */
function erp_client_session_token(int $localId): ?string {
  if (!erp_client_sessions_ok() || $localId <= 0) return null;
  $s = row("SELECT access_token, refresh_token, expires_at FROM ws_erp_client_sessions WHERE client_id=?", [$localId]);
  if (!$s) return null;
  if (!empty($s['access_token']) && !empty($s['expires_at']) && strtotime($s['expires_at']) > time()) return (string) $s['access_token'];
  if (empty($s['refresh_token'])) return null;
  [$code, $d] = erp_client_post('clients/auth/refresh', ['refresh_token' => (string) $s['refresh_token']]);
  if ($code !== 200 || !$d || empty($d['access_token'])) {
    if ($code === 401 || $code === 403) q("DELETE FROM ws_erp_client_sessions WHERE client_id=?", [$localId]);
    return null;
  }
  $exp = date('Y-m-d H:i:s', time() + max(60, (int) ($d['expires_in'] ?? 1800)) - 30);
  q("UPDATE ws_erp_client_sessions SET access_token=?, refresh_token=?, expires_at=? WHERE client_id=?",
    [(string) $d['access_token'], (string) ($d['refresh_token'] ?? $s['refresh_token']), $exp, $localId]);
  return (string) $d['access_token'];
}

/* Déconnexion : ferme la session ERP (idempotent côté ERP) et oublie les jetons. */
function erp_client_logout(int $localId): void {
  if (!erp_client_sessions_ok() || $localId <= 0) return;
  $s = row("SELECT refresh_token FROM ws_erp_client_sessions WHERE client_id=?", [$localId]);
  if ($s && !empty($s['refresh_token'])) erp_client_post('clients/auth/logout', ['refresh_token' => (string) $s['refresh_token']]);
  q("DELETE FROM ws_erp_client_sessions WHERE client_id=?", [$localId]);
}
