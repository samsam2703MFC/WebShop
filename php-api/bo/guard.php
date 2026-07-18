<?php
/* ============================================================================
 * bo/guard.php — Isolation des deux back-offices (franchisé ⇄ franchiseur).
 *
 * Deux « guards » indépendants, à la manière des guards Laravel, mais bâtis
 * sur les primitifs réels de cette API :
 *   - jeton de session signé HMAC-SHA256, AVEC UN SECRET DISTINCT PAR BO ;
 *   - cookie HttpOnly/Secure/SameSite au NOM DISTINCT PAR BO ;
 *   - le payload porte `bo:"<role>"` — re-vérifié à chaque requête.
 *
 * Double barrière anti-fuite : un jeton d'un BO présenté à l'autre échoue à la
 * fois sur la signature (secret ≠) ET sur le scope (`bo` ≠). Impossible qu'une
 * session franchisé ouvre le BO franchiseur, et inversement.
 *
 * Dépend des helpers de lib.php : cfg, json_out, req_header, b64u, b64u_dec,
 * db, q, row.
 * ========================================================================== */

/** Rôles BO exposés dans l'URL → rôle stocké en base (bo_users.role). */
function bo_db_role($bo) {
  return $bo === 'franchisor' ? 'siege' : ($bo === 'franchisee' ? 'franchise' : null);
}

/** Config d'un BO (secret, cookie, login_url). 500 si BO inconnu / non configuré. */
function bo_cfg($bo) {
  $all = cfg()['bo'] ?? null;
  if (!$all || empty($all[$bo])) {
    json_out(['error' => "Back-office '$bo' non configuré (section 'bo' de config.php)"], 500);
  }
  return $all[$bo];
}

/** Jeton signé avec le secret DU BO. Le payload contient toujours `bo`. */
function bo_sign($bo, array $payload) {
  $payload['bo'] = $bo;
  $b = b64u(json_encode($payload));
  return $b . '.' . b64u(hash_hmac('sha256', $b, bo_cfg($bo)['secret'], true));
}

/** Vérifie signature (secret du BO) + expiration + scope `bo`. null si invalide. */
function bo_verify($bo, $token) {
  $p = explode('.', (string) $token);
  if (count($p) !== 2) return null;
  [$b, $sig] = $p;
  $expect = b64u(hash_hmac('sha256', $b, bo_cfg($bo)['secret'], true));
  if (!hash_equals($expect, $sig)) return null;                 // secret ≠  → rejet
  $d = json_decode(b64u_dec($b), true);
  if (!is_array($d)) return null;
  if (($d['bo'] ?? null) !== $bo) return null;                  // scope ≠  → rejet
  if (isset($d['exp']) && $d['exp'] < time()) return null;      // expiré   → rejet
  return $d;
}

/** Jeton CSRF (double-submit) dérivé de l'id de session + secret du BO. */
function bo_csrf($bo, $sid) {
  return b64u(hash_hmac('sha256', 'csrf:' . $sid, bo_cfg($bo)['secret'], true));
}

/** Pose le cookie de session du BO (nom distinct, HttpOnly, Secure, SameSite). */
function bo_set_session($bo, array $payload, $ttl = 43200 /* 12h */) {
  $c   = bo_cfg($bo);
  $tok = bo_sign($bo, $payload + ['exp' => time() + $ttl]);
  setcookie($c['cookie'], $tok, [
    'expires'  => time() + $ttl,
    'path'     => '/',
    'domain'   => cfg()['bo']['cookie_domain'] ?? '',
    'secure'   => (bool) (cfg()['bo']['cookie_secure'] ?? true),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  return $tok;
}

/** Efface le cookie du BO (déconnexion — n'affecte JAMAIS l'autre BO). */
function bo_clear_session($bo) {
  $c = bo_cfg($bo);
  setcookie($c['cookie'], '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'domain'   => cfg()['bo']['cookie_domain'] ?? '',
    'secure'   => (bool) (cfg()['bo']['cookie_secure'] ?? true),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

/** Session courante du BO depuis son cookie (ou un Bearer de secours), ou null. */
function bo_current($bo) {
  $c   = bo_cfg($bo);
  $tok = $_COOKIE[$c['cookie']] ?? '';
  if ($tok === '') {                                   // repli Bearer (clients non-cookie)
    $a = req_header('Authorization');
    if (stripos($a, 'bearer ') === 0) $tok = trim(substr($a, 7));
  }
  return $tok !== '' ? bo_verify($bo, $tok) : null;
}

/** Ids de boutiques accessibles : null = réseau (franchiseur) ; liste = franchisé. */
function bo_scope_shop_ids(array $sess) {
  if (($sess['bo'] ?? null) === 'franchisor') return null;      // pas de borne
  $rows = rows("SELECT shop_id FROM bo_user_shops WHERE user_id = ?", [(int) $sess['id']]);
  return array_map(fn($r) => (int) $r['shop_id'], $rows);       // [] possible → aucune boutique
}

/** Refuse l'accès si la boutique demandée n'est pas dans la portée du franchisé. */
function bo_assert_shop_allowed(array $sess, $shopId) {
  $scope = bo_scope_shop_ids($sess);
  if ($scope === null) return;                                  // franchiseur : tout
  if (!in_array((int) $shopId, $scope, true)) {
    json_out(['error' => 'Boutique hors de votre périmètre.'], 403);
  }
}

/**
 * GUARD — à appeler en tête de CHAQUE route protégée du BO.
 * - 401 + login_url du BON back-office si non authentifié ;
 * - CSRF exigé sur toute méthode non-GET (double-submit `X-CSRF-Token`).
 * Retourne la session validée.
 */
function require_bo($bo) {
  $sess = bo_current($bo);
  if (!$sess) {
    json_out([
      'error'     => 'Authentification requise.',
      'bo'        => $bo,
      'login_url' => bo_cfg($bo)['login_url'],
    ], 401);
  }
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if ($method !== 'GET' && $method !== 'HEAD') {
    $given = req_header('X-CSRF-Token');
    if (!hash_equals(bo_csrf($bo, $sess['sid'] ?? ''), (string) $given)) {
      json_out(['error' => 'Jeton CSRF invalide ou manquant.'], 419);
    }
  }
  return $sess;
}

/** Journalise une action sensible dans bo_audit (best-effort). */
function bo_log(array $sess, $action, $entity = null, $entityId = null, $shopId = null, $payload = null) {
  try {
    q("INSERT INTO bo_audit (user_id, action, entity, entity_id, shop_id, payload, ip)
       VALUES (?,?,?,?,?,?,?)", [
      (int) $sess['id'], $action, $entity, $entityId, $shopId,
      $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
      $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
  } catch (Throwable $e) { /* l'audit ne casse jamais la requête */ }
}
