<?php
/* ============================================================================
 * bo/bootstrap.php — Point d'entrée des deux back-offices.
 * Inclus par lib.php ; index.php route toute requête « /bo/… » vers bo_dispatch().
 * ========================================================================== */

require __DIR__ . '/guard.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/routes.php';

/** Back-offices exposés dans l'URL. Tout autre segment → 404 (jamais deviné). */
const BO_ROLES = ['franchisee', 'franchisor'];

/**
 * Routeur des back-offices. Chemins :
 *   /bo/<role>/login|logout|me|password   → bo/auth.php
 *   /bo/<role>/<resource>                 → bo/routes.php   (require_bo obligatoire)
 * Ne renvoie rien : chaque handler termine par json_out(). Si aucune route ne
 * matche, retour à index.php qui renverra 404.
 */
function bo_dispatch($m, $p) {
  if (!preg_match('#^/bo/([a-z]+)/([a-z0-9\-/]+)$#', $p, $mm)) return; // 404
  $bo   = $mm[1];
  $rest = trim($mm[2], '/');
  if (!in_array($bo, BO_ROLES, true)) return;                          // BO inconnu → 404

  // Auth d'abord (login/logout/me/password), sinon routes de données.
  if (in_array($rest, ['login', 'logout', 'me', 'password'], true)) {
    bo_auth_route($m, $bo, $rest);
  } else {
    bo_resource_route($m, $bo, $rest);
  }
  // Si on arrive ici, la méthode ne correspondait à aucun handler de ce chemin.
}
