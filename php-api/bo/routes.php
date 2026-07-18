<?php
/* ============================================================================
 * bo/routes.php — Données des back-offices, 100 % issues de la base (ws_*).
 * Aucune donnée en dur : chaque table de l'UI est servie par une requête SQL.
 *   - franchisé (role franchise) : borné à ses boutiques (bo_user_shops) ;
 *   - franchiseur (role siege)   : portée réseau (marque).
 * Toute route passe par require_bo($bo).
 * ========================================================================== */

/** Fragment WHERE + params restreignant une colonne shop à la portée de session.
 *  franchiseur → ['1=1', []] (réseau) ; franchisé → 'shop_id IN (?,?)' ou '0=1'. */
function bo_shop_where(array $sess, $col = 'shop_id') {
  $scope = bo_scope_shop_ids($sess);
  if ($scope === null) return ['1=1', []];
  if (!$scope) return ['0=1', []];
  return [$col . ' IN (' . implode(',', array_fill(0, count($scope), '?')) . ')', $scope];
}

function bo_resource_route($m, $bo, $rest) {
  $sess = require_bo($bo);                     // guard obligatoire
  if ($m !== 'GET') return;                    // ces routes sont en lecture

  /* ── Commun ─────────────────────────────────────────────────────────── */
  if ($rest === 'scope') {
    json_out(['bo' => $bo, 'role' => $sess['role'], 'shops' => bo_scope_shop_ids($sess)]);
  }
  if ($rest === 'shops') {
    [$w, $p] = bo_shop_where($sess, 'id');
    json_out(rows("SELECT id, slug, name, city, active FROM ws_shops WHERE $w ORDER BY name", $p));
  }

  /* ── FRANCHISÉ — exploitation, borné aux boutiques ──────────────────── */
  if ($bo === 'franchisee') {
    [$w, $p] = bo_shop_where($sess, 'shop_id');

    if ($rest === 'dashboard') {
      $date = qp('date', date('Y-m-d'));
      $k = row("SELECT
          SUM(status IN ('pending','confirmed','preparing','ready')) AS to_prepare,
          COUNT(*)                                                   AS orders_total,
          COUNT(DISTINCT tour_id)                                    AS tours,
          COALESCE(SUM(total),0)                                     AS revenue
        FROM ws_orders WHERE $w AND delivery_date = ?", array_merge($p, [$date]));
      json_out([
        'date'       => $date,
        'toPrepare'  => (int) ($k['to_prepare'] ?? 0),
        'orders'     => (int) ($k['orders_total'] ?? 0),
        'tours'      => (int) ($k['tours'] ?? 0),
        'revenue'    => (float) ($k['revenue'] ?? 0),
      ]);
    }

    if ($rest === 'orders') {
      $date  = qp('date', date('Y-m-d'));
      $mode  = qp('mode');                       // collect | delivery (optionnel)
      $shopQ = qp('shopId');
      $where = "$w AND o.delivery_date = ?"; $params = array_merge($p, [$date]);
      if ($shopQ !== null) { bo_assert_shop_allowed($sess, $shopQ); $where .= " AND o.shop_id = ?"; $params[] = (int) $shopQ; }
      if ($mode !== null)  { $where .= " AND o.mode = ?"; $params[] = $mode; }
      json_out(rows(
        "SELECT o.id, o.order_ref, o.shop_id, o.mode, o.status, o.slot_label,
                o.delivery_date, o.total, o.delivery_mode, o.created_at,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', c.first_name, c.last_name)),''), o.guest_name, o.guest_email, '—') AS client,
                (SELECT COUNT(*) FROM ws_order_lines l WHERE l.order_id = o.id) AS lines_count
           FROM ws_orders o
           LEFT JOIN ws_customers c ON c.id = o.customer_id
          WHERE $where ORDER BY o.created_at DESC LIMIT 300", $params));
    }

    if ($rest === 'tours') {
      $date = qp('date', date('Y-m-d'));
      json_out(rows(
        "SELECT t.id, t.name, t.shop_id, t.max_items,
                (SELECT COUNT(*) FROM ws_orders o WHERE o.tour_id = t.id AND o.delivery_date = ?) AS orders,
                (SELECT COUNT(*) FROM ws_offices f WHERE f.tour_id = t.id AND f.active = 1)        AS offices
           FROM ws_tours t WHERE $w AND t.active = 1 ORDER BY t.name",
        array_merge([$date], $p)));
    }

    if ($rest === 'stock') {
      $date = qp('date', date('Y-m-d'));
      json_out(rows(
        "SELECT s.id, s.product_id, s.shop_id, s.mode, s.qty_total, s.qty_reserved, s.qty_sold,
                pr.name AS product, c.label AS category
           FROM ws_product_stock s
           LEFT JOIN ws_products   pr ON pr.id = s.product_id
           LEFT JOIN ws_categories c  ON c.id = pr.cat_id
          WHERE $w AND s.date = ? AND s.active = 1
          ORDER BY c.label, pr.name", array_merge($p, [$date])));
    }

    if ($rest === 'b2b') {                       // demandes d'office en attente
      json_out(rows(
        "SELECT r.id, r.shop_id, r.office_name_raw AS office, r.address_raw AS address,
                r.status, r.created_at, sh.name AS shop
           FROM ws_office_join_requests r
           LEFT JOIN ws_shops sh ON sh.id = r.shop_id
          WHERE $w AND r.status = 'pending' ORDER BY r.created_at DESC LIMIT 200", $p));
    }
    return; // aucune autre route franchisé
  }

  /* ── FRANCHISEUR — réseau / marque ──────────────────────────────────── */
  if ($bo === 'franchisor') {
    if ($rest === 'dashboard') {
      $date = qp('date', date('Y-m-d'));
      $shops = row("SELECT COUNT(*) n, SUM(active=1) act FROM ws_shops");
      $ord   = row("SELECT COUNT(*) n, COALESCE(SUM(total),0) rev FROM ws_orders WHERE delivery_date = ?", [$date]);
      json_out([
        'date'         => $date,
        'shops'        => (int) ($shops['n'] ?? 0),
        'shopsActive'  => (int) ($shops['act'] ?? 0),
        'ordersToday'  => (int) ($ord['n'] ?? 0),
        'revenueToday' => (float) ($ord['rev'] ?? 0),
      ]);
    }
    if ($rest === 'catalog') {
      json_out([
        'categories' => rows("SELECT id, slug, label, sort_order, active FROM ws_categories ORDER BY sort_order, label"),
        'products'   => rows("SELECT p.id, p.cat_id, c.label AS category, p.name, p.price, p.badge, p.active,
                                     p.brand_webshop, p.brand_mandatory
                                FROM ws_products p LEFT JOIN ws_categories c ON c.id = p.cat_id
                               ORDER BY c.label, p.name"),
      ]);
    }
    if ($rest === 'vouchers') {
      json_out(rows("SELECT id, code, shop_id, type, value, min_order, max_uses, used_count, expires_at, active
                       FROM ws_vouchers ORDER BY active DESC, id DESC LIMIT 500"));
    }
    if ($rest === 'pricing') {
      json_out(rows("SELECT id, shop_id, rule_type, x, y, threshold, label, active
                       FROM ws_pricing_rules ORDER BY active DESC, id DESC LIMIT 500"));
    }
    if ($rest === 'templates') {
      json_out(rows("SELECT id, tpl_key, lang, subject, id_brand, active, updated_at
                       FROM ws_email_templates ORDER BY tpl_key, lang"));
    }
    if ($rest === 'users') {
      json_out(rows("SELECT u.id, u.email, u.display_name, u.role, u.active, u.last_login_at,
                            (SELECT COUNT(*) FROM bo_user_shops s WHERE s.user_id = u.id) AS shops
                       FROM bo_users u ORDER BY u.role, u.email"));
    }
    if ($rest === 'audit') {
      json_out(rows("SELECT a.id, a.user_id, u.email AS user_email, a.action, a.entity, a.entity_id,
                            a.shop_id, a.created_at
                       FROM bo_audit a LEFT JOIN bo_users u ON u.id = a.user_id
                      ORDER BY a.created_at DESC LIMIT 200"));
    }
    return;
  }
}
