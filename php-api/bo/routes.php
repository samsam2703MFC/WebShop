<?php
/* ============================================================================
 * bo/routes.php — Routes de données protégées, par BO.
 *   - franchiseur (siege)  : portée RÉSEAU (toutes boutiques).
 *   - franchisé  (franchise): portée BORNÉE à bo_user_shops.
 * Chaque route passe par require_bo($bo) ; le franchisé est filtré par scope.
 * Ce sont des exemples représentatifs (lecture) — à étendre selon les besoins,
 * en gardant TOUJOURS require_bo() + bo_assert_shop_allowed() en tête.
 * ========================================================================== */

function bo_resource_route($m, $bo, $rest) {
  $sess  = require_bo($bo);                 // ← guard obligatoire sur CHAQUE route
  $scope = bo_scope_shop_ids($sess);        // null = réseau ; [] / liste = franchisé

  /* Boutiques visibles par l'utilisateur. */
  if ($m === 'GET' && $rest === 'shops') {
    if ($scope === null) {
      json_out(rows("SELECT id, slug, name, city, active FROM ws_shops ORDER BY name"));
    }
    if (!$scope) json_out([]);              // franchisé sans boutique
    $in = implode(',', array_fill(0, count($scope), '?'));
    json_out(rows("SELECT id, slug, name, city, active FROM ws_shops WHERE id IN ($in) ORDER BY name", $scope));
  }

  /* Commandes du jour (ou ?date=YYYY-MM-DD), bornées à la portée. */
  if ($m === 'GET' && $rest === 'orders') {
    $date  = qp('date', date('Y-m-d'));
    $shopQ = qp('shopId');
    if ($shopQ !== null) bo_assert_shop_allowed($sess, $shopQ);   // franchisé : refuse hors périmètre

    $where  = "delivery_date = ?";
    $params = [$date];
    if ($shopQ !== null) { $where .= " AND shop_id = ?"; $params[] = (int) $shopQ; }
    elseif ($scope !== null) {                                    // franchisé sans shopId → toutes SES boutiques
      if (!$scope) json_out([]);
      $in = implode(',', array_fill(0, count($scope), '?'));
      $where .= " AND shop_id IN ($in)";
      $params = array_merge($params, $scope);
    }
    json_out(rows(
      "SELECT id, order_ref, shop_id, mode, status, delivery_date, total, created_at
         FROM ws_orders WHERE $where ORDER BY created_at DESC LIMIT 200", $params));
  }

  /* Identité + portée (utile au front pour router / afficher le périmètre). */
  if ($m === 'GET' && $rest === 'scope') {
    json_out(['bo' => $bo, 'role' => $sess['role'], 'shops' => $scope]);  // null = réseau
  }

  return false;
}
