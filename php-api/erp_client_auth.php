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
 * Aucun repli : sans ERP, pas de connexion. */

function erp_client_auth_enabled(): bool {
  return function_exists('erp_cfg') && erp_cfg()['base'] !== '';
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
  return erp_client_session_depuis($d);
}

/* Session ERP à partir d'une réponse de login ou de register. */
function erp_client_session_depuis(array $d): array {
  $j = erp_jwt_payload((string) $d['access_token']);
  return ['ok' => true,
          'clientId'        => (int) ($j['client_id'] ?? $j['sub'] ?? 0),
          'preferredShopId' => (int) ($j['preferred_shop_id'] ?? 0),
          'access'          => (string) $d['access_token'],
          'refresh'         => (string) ($d['refresh_token'] ?? ''),
          'expiresIn'       => (int) ($d['expires_in'] ?? 1800),
          'sessionId'       => (int) ($d['session_id'] ?? 0)];
}

/* ── INSCRIPTION, MOT DE PASSE, RÉINITIALISATION : tout dans l'ERP ─────────
 * POST /clients/auth/register crée la fiche ET la session (201, même forme
 * que le login). POST /clients/auth/password/change (jeton du client) change
 * le mot de passe et révoque toutes les sessions. La réinitialisation passe
 * par e-mail : reset/request (202 quoi qu'il arrive : anti-énumération) puis
 * reset/confirm avec le jeton du lien (une heure, usage unique). */
function erp_client_register(array $f): array {
  [$code, $d] = erp_client_post('clients/auth/register', $f);
  if (($code === 201 || $code === 200) && $d && !empty($d['access_token'])) return erp_client_session_depuis($d);
  return ['ok' => false, 'code' => $code, 'message' => (string) ($d['description'] ?? '')];
}
function erp_client_password_change(int $localId, string $current, string $new): array {
  $tok = erp_client_session_token($localId);
  if ($tok === null) return ['ok' => false, 'code' => 401, 'message' => 'session ERP absente'];
  [$code, $d] = erp_client_request('POST', 'clients/auth/password/change',
    ['current_password' => $current, 'new_password' => $new, 'new_password_confirm' => $new], $tok);
  if ($code === 200) { q("DELETE FROM ws_erp_client_sessions WHERE client_id=?", [$localId]); return ['ok' => true]; }
  return ['ok' => false, 'code' => $code, 'message' => (string) ($d['description'] ?? '')];
}
function erp_client_reset_request(string $email): array {
  [$code, $d] = erp_client_post('clients/auth/password/reset/request', ['email' => $email]);
  return ['code' => $code, 'message' => (string) ($d['description'] ?? '')];
}
function erp_client_reset_confirm(string $token, string $new): array {
  [$code, $d] = erp_client_post('clients/auth/password/reset/confirm', ['token' => $token, 'new_password' => $new, 'new_password_confirm' => $new]);
  return ['code' => $code, 'message' => (string) ($d['description'] ?? '')];
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

/* ── PROFIL : LU ET ÉCRIT DANS L'ERP, AVEC LE JETON DU CLIENT ─────────────────
 * GET /clients/{id} rend la fiche complète (mêmes colonnes que la table client
 * locale) ; PATCH /clients/{id} l'écrit (204). La ligne locale n'est plus
 * qu'un miroir de la fiche ERP, rafraîchi à la connexion et au plus une fois
 * par minute sur /auth/me ; les modifications du profil partent d'abord à
 * l'ERP. L'ERP complète et corrige, il ne vide pas : une valeur nulle ou vide
 * côté ERP ne remplace jamais une valeur locale. */

function erp_client_request(string $method, string $path, ?array $body, string $token): array {
  $cfg = erp_cfg();
  if ($cfg['base'] === '' || $token === '') return [0, null];
  $h = "Accept: application/json\r\nAuthorization: Bearer $token\r\n";
  if ($body !== null) $h .= "Content-Type: application/json\r\n";
  $ctx = stream_context_create(['http' => ['method' => $method, 'header' => $h, 'timeout' => max(4, (int) $cfg['timeout']),
                                            'ignore_errors' => true, 'content' => $body !== null ? json_encode($body) : '']]);
  $raw = @file_get_contents($cfg['base'] . '/' . ltrim($path, '/'), false, $ctx);
  $code = 0;
  foreach (($http_response_header ?? []) as $l) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $l, $m)) $code = (int) $m[1];
  $d = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
  return [$code, is_array($d) ? $d : null];
}

/* Fiche ERP du client, lue avec SON jeton. */
function erp_client_fiche_par_jeton(string $token, int $erpId): ?array {
  if ($erpId <= 0) return null;
  [$code, $d] = erp_client_request('GET', 'clients/' . $erpId, null, $token);
  return ($code === 200 && $d && isset($d['id'])) ? $d : null;
}

/* Colonnes recopiées de la fiche ERP vers la ligne locale. Les rattachements
   (boutique, bureau, département) ne sont pris que s'ils sont posés dans l'ERP :
   la console franchisé les gère aussi, on ne les efface pas depuis ici. */
const ERP_CLIENT_LIENS = ['preferred_shop_id', 'office_id', 'department_id'];
const ERP_CLIENT_COLS = ['name', 'surname', 'company_name', 'tax_number', 'street', 'street_number', 'city', 'zip', 'locality',
  'phone', 'phone_prefix', 'phone_e164', 'email', 'is_b2b', 'can_deferral', 'payment_terms', 'personal_discount_percent',
  'peppol_identifier', 'peppol_verified', 'locale', 'invoice_country', 'invoice_name', 'invoice_address', 'invoice_postal_code',
  'invoice_city', 'client_code', 'member_since', 'iban', 'billing_lines', 'fidelity_active', 'fidelity_linked_at',
  'office_delivery', 'status', 'blocked', 'b2b_segment', 'b2b_payment_terms', 'b2b_credit_ceiling', 'b2b_web_discount', 'b2b_franco',
  'id_main_shop', 'preferred_shop_id', 'office_id', 'department_id'];

function erp_client_profil_sync(int $localId, array $c): int {
  if ($localId <= 0) return 0;
  $sets = []; $vals = [];
  foreach (ERP_CLIENT_COLS as $col) {
    if (!array_key_exists($col, $c) || !col_exists('client', $col)) continue;
    $v = $c[$col];
    if (($v === null || $v === '') && in_array($col, ERP_CLIENT_LIENS, true)) continue;   // rattachement géré ici
    if ($v === '') $v = null;
    if (is_array($v)) $v = json_encode($v);
    $sets[] = "$col=?"; $vals[] = $v;
  }
  if (!empty($c['id']) && col_exists('client', 'erp_client_id')) { $sets[] = 'erp_client_id=COALESCE(erp_client_id, ?)'; $vals[] = (int) $c['id']; }
  if ($sets) { $vals[] = $localId; q("UPDATE client SET " . implode(',', $sets) . " WHERE id=?", $vals); }
  if (erp_client_sessions_ok() && col_exists('ws_erp_client_sessions', 'profile_synced_at'))
    q("UPDATE ws_erp_client_sessions SET profile_synced_at=NOW() WHERE client_id=?", [$localId]);
  return count($sets);
}

/* Relit la fiche ERP si la dernière lecture date de plus de $maxAge secondes.
   Rend true si la ligne locale a été rafraîchie. */
function erp_client_profil_refresh(int $localId, int $maxAge = 60): bool {
  if (!erp_client_sessions_ok() || $localId <= 0) return false;
  $s = row("SELECT erp_client_id" . (col_exists('ws_erp_client_sessions', 'profile_synced_at') ? ", profile_synced_at" : ", NULL AS profile_synced_at") .
           " FROM ws_erp_client_sessions WHERE client_id=?", [$localId]);
  if (!$s) return false;
  if (!empty($s['profile_synced_at']) && strtotime($s['profile_synced_at']) > time() - $maxAge) return false;
  $tok = erp_client_session_token($localId);
  if ($tok === null) return false;
  $erpId = (int) ($s['erp_client_id'] ?? 0);
  if ($erpId <= 0 && col_exists('client', 'erp_client_id')) $erpId = (int) (row("SELECT erp_client_id FROM client WHERE id=?", [$localId])['erp_client_id'] ?? 0);
  $c = erp_client_fiche_par_jeton($tok, $erpId);
  if (!$c) return false;
  erp_client_profil_sync($localId, $c);
  return true;
}

/* Écrit des champs de la fiche dans l'ERP (PATCH /clients/{id}). Rend true si
   l'ERP a accepté (2xx), false sinon (pas de session ERP, refus, panne). */
function erp_client_patch(int $localId, array $fields): bool {
  if (!erp_client_sessions_ok() || $localId <= 0 || !$fields) return false;
  $tok = erp_client_session_token($localId);
  if ($tok === null) return false;
  $s = row("SELECT erp_client_id FROM ws_erp_client_sessions WHERE client_id=?", [$localId]);
  $erpId = (int) ($s['erp_client_id'] ?? 0);
  if ($erpId <= 0 && col_exists('client', 'erp_client_id')) $erpId = (int) (row("SELECT erp_client_id FROM client WHERE id=?", [$localId])['erp_client_id'] ?? 0);
  if ($erpId <= 0) return false;
  [$code] = erp_client_request('PATCH', 'clients/' . $erpId, $fields, $tok);
  return $code >= 200 && $code < 300;
}
