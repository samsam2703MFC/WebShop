<?php
/* ============================================================================
 * bo/auth.php — Login / logout / me / password, SÉPARÉS par back-office.
 * Provider = table bo_users (role 'siege' = franchiseur, 'franchise' = franchisé),
 * portée franchisé = bo_user_shops. Mots de passe bcrypt (password_hash).
 * Une déconnexion d'un BO n'affecte jamais l'autre (cookies distincts).
 * ========================================================================== */

/** Profil public d'un utilisateur BO. */
function bo_user_payload(array $u, array $scopeShops = null) {
  $out = [
    'id'           => (int) $u['id'],
    'email'        => $u['email'],
    'display_name' => $u['display_name'],
    'role'         => $u['role'],
  ];
  if ($scopeShops !== null) $out['shops'] = $scopeShops;         // franchisé : boutiques autorisées
  return $out;
}

/** Routes d'authentification du BO. Retourne true si la route a été traitée. */
function bo_auth_route($m, $bo, $action) {
  $dbRole = bo_db_role($bo);

  /* ── LOGIN ── (public : pas de guard, pas de CSRF) */
  if ($m === 'POST' && $action === 'login') {
    $b     = body();
    $email = strtolower(trim($b['email'] ?? ''));
    $pass  = (string) ($b['password'] ?? '');
    // On borne la recherche au rôle DU BO : un compte 'siege' ne peut PAS se
    // connecter au BO franchisé, et inversement — même e-mail, autre BO = échec.
    $u = row("SELECT id, email, password_hash, display_name, role, active
                FROM bo_users WHERE LOWER(TRIM(email)) = ? AND role = ? LIMIT 1",
             [$email, $dbRole]);
    if (!$u || !(int) $u['active'] || !password_verify($pass, $u['password_hash'])) {
      json_out(['error' => 'Identifiants incorrects.'], 401);
    }
    q("UPDATE bo_users SET last_login_at = NOW() WHERE id = ?", [(int) $u['id']]);

    $sid  = bin2hex(random_bytes(16));
    $sess = ['id' => (int) $u['id'], 'role' => $u['role'], 'sid' => $sid];
    bo_set_session($bo, $sess);
    bo_log($sess, 'login', 'bo_user', (int) $u['id']);

    $scope = $bo === 'franchisee'
      ? array_map(fn($r) => (int) $r['shop_id'],
                  rows("SELECT shop_id FROM bo_user_shops WHERE user_id = ?", [(int) $u['id']]))
      : null;
    json_out(['user' => bo_user_payload($u, $scope), 'csrf' => bo_csrf($bo, $sid)]);
  }

  /* ── LOGOUT ── (guard + CSRF ; n'efface que le cookie de CE BO) */
  if ($m === 'POST' && $action === 'logout') {
    $sess = require_bo($bo);
    bo_log($sess, 'logout', 'bo_user', (int) $sess['id']);
    bo_clear_session($bo);
    json_out(['ok' => true]);
  }

  /* ── ME ── (identité + portée courante) */
  if ($m === 'GET' && $action === 'me') {
    $sess = require_bo($bo);
    $u = row("SELECT id, email, password_hash, display_name, role, active FROM bo_users WHERE id = ? LIMIT 1",
             [(int) $sess['id']]);
    if (!$u || !(int) $u['active'] || $u['role'] !== $dbRole) {   // révocable : rôle/état revérifiés
      bo_clear_session($bo);
      json_out(['error' => 'Session invalide.'], 401);
    }
    $scope = bo_scope_shop_ids($sess);
    json_out(['user' => bo_user_payload($u, $scope), 'csrf' => bo_csrf($bo, $sess['sid'] ?? '')]);
  }

  /* ── PASSWORD ── (change son propre mot de passe) */
  if ($m === 'POST' && $action === 'password') {
    $sess = require_bo($bo);
    $b   = body();
    $cur = (string) ($b['current'] ?? '');
    $new = (string) ($b['new'] ?? '');
    if (strlen($new) < 8) json_out(['error' => 'Nouveau mot de passe trop court (min. 8).'], 400);
    $u = row("SELECT id, password_hash FROM bo_users WHERE id = ? LIMIT 1", [(int) $sess['id']]);
    if (!$u || !password_verify($cur, $u['password_hash'])) {
      json_out(['error' => 'Mot de passe actuel incorrect.'], 401);
    }
    q("UPDATE bo_users SET password_hash = ? WHERE id = ?",
      [password_hash($new, PASSWORD_BCRYPT), (int) $sess['id']]);
    bo_log($sess, 'password_change', 'bo_user', (int) $sess['id']);
    json_out(['ok' => true]);
  }

  return false; // action d'auth non reconnue
}
