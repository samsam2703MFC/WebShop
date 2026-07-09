<?php
/* Front controller — même API que le buddy-server Node, en PHP sur ws_.
 * .htaccess renvoie toutes les requêtes ici ; on route sur méthode + chemin. */
require __DIR__ . '/lib.php';

/* CORS */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = cfg()['cors_origins'];
if ($origin && (in_array($origin, $allowed, true) || in_array('*', $allowed, true))) {
  header("Access-Control-Allow-Origin: $origin");
  header('Access-Control-Allow-Credentials: true');
  header('Access-Control-Allow-Headers: Content-Type, Authorization');
  header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

/* Chemin, en retirant le sous-dossier où vit l'API (ex. /api) */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($base !== '' && $base !== '/' && strpos($path, $base) === 0) $path = substr($path, strlen($base));
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
  dispatch($method, $path);
  json_out(['error' => 'Not found', 'path' => $path], 404);
} catch (Throwable $e) {
  json_out(['error' => 'Erreur interne'], 500);
}

/* ─────────────────────────── Routes ─────────────────────────── */
function dispatch($m, $p) {
  // helper de matching avec :param
  $match = function ($pat) use ($p) {
    $rx = '#^' . preg_replace('#:([\w]+)#', '(?<$1>[^/]+)', $pat) . '$#';
    if (preg_match($rx, $p, $mm)) return $mm;
    return null;
  };

  /* ── Health ── */
  if ($m === 'GET' && $p === '/health') { db()->query('SELECT 1'); json_out(['ok' => true]); }

  /* ── Shops / Brand ── */
  if ($m === 'GET' && $p === '/shops') {
    json_out(rows("SELECT id, slug, name, city, email, phone, accent, tint, logo_url,
                          TRIM(CONCAT_WS(' ', street, street_num)) AS address
                     FROM ws_shops WHERE active = 1 ORDER BY name"));
  }
  if ($m === 'GET' && $p === '/brand') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    json_out(row("SELECT id, slug, name, accent, tint, logo_url FROM ws_shops WHERE id = ?", [$s]) ?: []);
  }

  /* ── Catalog ── */
  if ($m === 'GET' && $p === '/catalog/categories') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    json_out(rows("SELECT id, slug, label, img, sort_order FROM ws_categories
                    WHERE active = 1 AND (shop_id = ? OR shop_id IS NULL)
                    ORDER BY sort_order, label", [$s]));
  }
  if ($m === 'GET' && $p === '/catalog/products') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    $r = rows("SELECT p.id, p.cat_id, p.sub_cat_id, c.label AS category,
                      p.name, p.description, p.badge,
                      p.portions, p.cross_portion, p.has_menu_options,
                      COALESCE(pp.price, p.price) AS price, ps.no_delivery,
                      (SELECT JSON_ARRAYAGG(allergen) FROM ws_product_allergens a WHERE a.product_id = p.id) AS allergens
                 FROM ws_products p
                 JOIN ws_product_shops ps ON ps.product_id = p.id AND ps.shop_id = ? AND ps.active = 1
                 LEFT JOIN ws_product_prices pp ON pp.product_id = p.id AND pp.shop_id = ? AND pp.active = 1
                 LEFT JOIN ws_categories c ON c.id = p.cat_id
                WHERE p.active = 1 ORDER BY c.sort_order, p.name", [$s, $s]);
    foreach ($r as &$x) {
      $x['portions'] = (bool) $x['portions'];
      $x['cross_portion'] = (bool) $x['cross_portion'];
      $x['has_menu_options'] = (bool) $x['has_menu_options'];
      $x['no_delivery'] = (bool) $x['no_delivery'];
      $x['price'] = (float) $x['price'];
      $x['allergens'] = $x['allergens'] ? json_decode($x['allergens']) : [];
    }
    json_out($r);
  }
  if ($m === 'GET' && $p === '/catalog/stock') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    $day = qp('date') ?: date('Y-m-d'); $mode = qp('mode') ?: 'collect';
    json_out(rows("SELECT product_id, GREATEST(0, qty_total - qty_reserved - qty_sold) AS available
                     FROM ws_product_stock
                    WHERE shop_id = ? AND date = ? AND active = 1 AND (mode = ? OR mode IS NULL)",
                  [$s, $day, $mode]));
  }

  /* ── Promos / Vouchers ── */
  if ($m === 'GET' && $p === '/pricing/promos/cross-portion') {
    $s = qp('shopId');
    $r = row("SELECT x AS buy, y AS free, threshold, label FROM ws_pricing_rules
               WHERE rule_type='cross_portion' AND active=1 AND (shop_id=? OR shop_id IS NULL)
               ORDER BY shop_id IS NULL LIMIT 1", [$s]);
    json_out($r ? ['active' => true, 'buy' => (int) $r['buy'], 'free' => (int) $r['free'],
                   'threshold' => (int) $r['threshold'], 'scope' => 'crossPortion', 'label' => $r['label']]
                : ['active' => false]);
  }
  if ($m === 'POST' && $p === '/vouchers/redeem') {
    $b = body(); $sub = (float) ($b['subtotal'] ?? 0);
    $v = row("SELECT code, type, value, min_order FROM ws_vouchers
               WHERE code=? AND active=1 AND (expires_at IS NULL OR expires_at>NOW())
                 AND (max_uses IS NULL OR used_count<max_uses) LIMIT 1",
             [strtoupper(trim($b['code'] ?? ''))]);
    if (!$v) json_out(['ok' => false, 'message' => 'Code invalide']);
    if ($sub < (float) $v['min_order']) json_out(['ok' => false, 'message' => "Minimum {$v['min_order']} €"]);
    $disc = $v['type'] === 'percent' ? round($sub * (float) $v['value']) / 100
          : ($v['type'] === 'fixed' ? (float) $v['value'] : 0);
    json_out(['ok' => true, 'discount' => $disc, 'voucher' => ['code' => $v['code'], 'type' => $v['type'], 'value' => (float) $v['value']], 'message' => 'Code appliqué']);
  }

  /* ── Availability / Calendar ── */
  if ($m === 'GET' && $p === '/availability/settings') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    json_out(row("SELECT * FROM ws_shop_availability WHERE shop_id = ?", [$s]) ?: []);
  }
  if ($m === 'GET' && $p === '/calendar/slots') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    json_out(rows("SELECT id, mode, label, sort_order FROM ws_slots
                    WHERE shop_id=? AND mode=? AND active=1 ORDER BY sort_order", [$s, qp('mode') ?: 'collect']));
  }
  if ($m === 'GET' && $p === '/calendar/cutoff') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    json_out(row("SELECT cutoff_hour, cutoff_minutes, lead_hours, open_days FROM ws_calendar_rules
                   WHERE shop_id=? AND mode=? AND active=1 LIMIT 1", [$s, qp('mode') ?: 'collect']) ?: []);
  }
  if ($m === 'GET' && $p === '/calendar/exceptions') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    json_out(rows("SELECT DATE_FORMAT(exception_date,'%Y-%m-%d') AS exception_date, type, reason
                     FROM ws_shop_exceptions WHERE shop_id=? AND exception_date>=CURDATE()
                    ORDER BY exception_date", [$s]));
  }
  if ($m === 'GET' && $p === '/availability/days') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    $mode = qp('mode') ?: 'collect';
    $from = qp('from') ?: date('Y-m-d');
    $to = qp('to') ?: date('Y-m-d', time() + 30 * 86400);
    $av = row("SELECT collect_open_days, delivery_open_days FROM ws_shop_availability WHERE shop_id=?", [$s]);
    $col = $mode === 'delivery' ? 'delivery_open_days' : 'collect_open_days';
    $open = $av && $av[$col] ? json_decode($av[$col], true) : ($mode === 'delivery' ? [1,2,3,4,5] : [1,2,3,4,5,6]);
    $exc = rows("SELECT DATE_FORMAT(exception_date,'%Y-%m-%d') AS d, type FROM ws_shop_exceptions
                  WHERE shop_id=? AND exception_date BETWEEN ? AND ?", [$s, $from, $to]);
    $closed = []; foreach ($exc as $e) if ($e['type'] === 'closed') $closed[$e['d']] = true;
    $days = [];
    for ($t = strtotime($from), $end = strtotime($to); $t <= $end; $t += 86400) {
      $iso = date('Y-m-d', $t); $isoDay = (int) date('N', $t); // 1=Mon..7=Sun
      $reason = !in_array($isoDay, $open) ? 'closed' : (isset($closed[$iso]) ? 'holiday' : null);
      $days[] = ['date' => $iso, 'available' => $reason === null, 'reason' => $reason];
    }
    json_out($days);
  }

  /* ── Network : tours / offices / delivery-fees ── */
  if ($m === 'GET' && $p === '/tours') {
    $s = qp('shopId');
    json_out($s ? rows("SELECT id, shop_id AS shopId, name FROM ws_tours WHERE active=1 AND shop_id=?", [$s])
                : rows("SELECT id, shop_id AS shopId, name FROM ws_tours WHERE active=1"));
  }
  if ($m === 'GET' && $p === '/offices') {
    json_out(rows("SELECT id, tour_id AS tourId, name, address, postal_code AS postalCode, city,
                          contact, email, phone, vat, status
                     FROM ws_offices WHERE status='validated' AND active=1"));
  }
  if ($m === 'GET' && ($mm = $match('/offices/:id'))) {
    $o = row("SELECT * FROM ws_offices WHERE id=?", [$mm['id']]);
    if (!$o) json_out(['error' => 'Office introuvable'], 404);
    $o['sites'] = rows("SELECT id, name, address, floor_room AS floorRoom, shop_id AS shopId
                          FROM ws_office_delivery_sites WHERE office_client_id=? AND active=1", [$mm['id']]);
    json_out($o);
  }
  if ($m === 'POST' && $p === '/delivery-fees/quote') {
    $b = body();
    $r = row("SELECT id, level, free_delivery AS freeDelivery, always_charge AS alwaysCharge,
                     fee_amount AS feeAmount, free_delivery_minimum AS freeDeliveryMinimum, payment_type AS paymentType
                FROM ws_delivery_fee_rules WHERE active=1 AND (
                     (level='site'   AND site_id=?) OR (level='office' AND office_client_id=?) OR
                     (level='tour'   AND tour_id=?) OR (level='shop' AND shop_id=?) OR (level='global'))
               ORDER BY FIELD(level,'site','office','tour','shop','global') LIMIT 1",
             [$b['siteId'] ?? null, $b['officeClientId'] ?? null, $b['tourId'] ?? null, $b['shopId'] ?? null]);
    json_out($r ?: null);
  }

  /* ── Orders ── */
  if ($m === 'POST' && $p === '/orders') {
    $b = body();
    $shop = $b['shopId'] ?? null; $basket = $b['basket'] ?? [];
    if (!$shop || !is_array($basket) || !count($basket)) json_out(['error' => 'shopId et basket requis'], 400);
    $mode = $b['mode'] ?? 'collect';
    $subtotal = 0; $lines = [];
    foreach ($basket as $it) {
      $p2 = row("SELECT p.id, p.name, COALESCE(pp.price, p.price) AS price
                   FROM ws_products p LEFT JOIN ws_product_prices pp ON pp.product_id=p.id AND pp.shop_id=? AND pp.active=1
                  WHERE p.id=? AND p.active=1", [$shop, $it['productId'] ?? 0]);
      if (!$p2) continue;
      $qty = max(1, (int) ($it['qty'] ?? 1));
      $subtotal += (float) $p2['price'] * $qty;
      $lines[] = ['productId' => $p2['id'], 'name' => $p2['name'], 'qty' => $qty, 'unit' => (float) $p2['price'], 'portion' => $it['portion'] ?? null];
    }
    if (!count($lines)) json_out(['error' => 'aucun produit valide'], 400);
    $total = round($subtotal, 2); $ref = 'WS-' . time() . rand(10, 99);
    q("INSERT INTO ws_orders (order_ref, shop_id, customer_id, mode, status, slot_id, slot_label, delivery_date,
         subtotal, total, payment_method, payment_status, lang, delivery_mode)
       VALUES (?,?,?,?, 'pending', ?,?,?, ?,?, ?, 'pending', ?, ?)",
      [$ref, $shop, $b['customerId'] ?? null, $mode, $b['slotId'] ?? null, $b['slotLabel'] ?? null, $b['deliveryDate'] ?? null,
       $total, $total, $b['paymentMethod'] ?? 'cash', $b['lang'] ?? 'fr', $mode === 'delivery' ? 'office_delivery' : 'collect']);
    $oid = db()->lastInsertId();
    foreach ($lines as $l) {
      q("INSERT INTO ws_order_lines (order_id, product_id, product_name, qty, unit_price, `portion`) VALUES (?,?,?,?,?,?)",
        [$oid, $l['productId'], $l['name'], $l['qty'], $l['unit'], $l['portion']]);
      q("UPDATE ws_product_stock SET qty_sold = qty_sold + ?
          WHERE product_id=? AND shop_id=? AND date=CURDATE() AND (mode=? OR mode IS NULL)",
        [$l['qty'], $l['productId'], $shop, $mode]);
    }
    json_out(['ok' => true, 'orderId' => (int) $oid, 'orderRef' => $ref, 'total' => $total]);
  }
  if ($m === 'GET' && ($mm = $match('/orders/:id'))) {
    $o = row("SELECT * FROM ws_orders WHERE id=? OR order_ref=? LIMIT 1", [$mm['id'], $mm['id']]);
    if (!$o) json_out(['error' => 'Commande introuvable'], 404);
    $o['lines'] = rows("SELECT * FROM ws_order_lines WHERE order_id=?", [$o['id']]);
    json_out($o);
  }

  /* ── Auth (bcrypt natif PHP + jeton HMAC) ── */
  if ($m === 'POST' && $p === '/auth/register') {
    $b = body(); $mail = strtolower(trim($b['email'] ?? ''));
    if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Email invalide'], 400);
    if (strlen($b['password'] ?? '') < 6) json_out(['error' => 'Mot de passe trop court (min. 6)'], 400);
    if (row("SELECT id FROM ws_customers WHERE email=?", [$mail])) json_out(['error' => 'Un compte existe déjà avec cet email.'], 409);
    $hash = password_hash($b['password'], PASSWORD_BCRYPT);
    q("INSERT INTO ws_customers (email, password_hash, first_name, last_name) VALUES (?,?,?,?)",
      [$mail, $hash, $b['firstName'] ?? '', $b['lastName'] ?? '']);
    $id = db()->lastInsertId();
    json_out(['user' => user_payload($id), 'token' => sign_token(['id' => (int) $id, 'exp' => time() + 30 * 86400])], 201);
  }
  if ($m === 'POST' && $p === '/auth/login') {
    $b = body();
    $u = row("SELECT id, password_hash FROM ws_customers WHERE email=? AND active=1", [strtolower(trim($b['email'] ?? ''))]);
    if (!$u || !password_verify($b['password'] ?? '', $u['password_hash'])) json_out(['error' => 'Identifiants incorrects.'], 401);
    json_out(['user' => user_payload($u['id']), 'token' => sign_token(['id' => (int) $u['id'], 'exp' => time() + 30 * 86400])]);
  }
  if ($m === 'GET' && $p === '/auth/me') {
    $id = auth_uid(); $u = $id ? user_payload($id) : null;
    if (!$u) json_out(['error' => 'Non connecté.'], 401);
    json_out(['user' => $u]);
  }
  if ($m === 'PATCH' && $p === '/auth/me') {
    $id = auth_uid(); if (!$id) json_out(['error' => 'Non connecté.'], 401);
    $b = body(); $map = ['first_name' => 'firstName', 'last_name' => 'lastName', 'phone' => 'phone', 'preferred_shop_id' => 'preferredShopId'];
    $sets = []; $vals = [];
    foreach ($map as $col => $k) if (array_key_exists($k, $b)) { $sets[] = "$col=?"; $vals[] = $b[$k]; }
    if ($sets) { $vals[] = $id; q("UPDATE ws_customers SET " . implode(',', $sets) . " WHERE id=?", $vals); }
    json_out(['user' => user_payload($id)]);
  }

  /* ── Payment (Stripe via cURL, sans SDK) ── */
  if ($m === 'POST' && $p === '/payments/checkout') {
    $b = body();
    $o = row("SELECT * FROM ws_orders WHERE id=? OR order_ref=? LIMIT 1", [$b['orderId'] ?? 0, $b['orderId'] ?? '']);
    if (!$o) json_out(['error' => 'Commande introuvable'], 404);
    $lines = rows("SELECT product_name, qty, unit_price FROM ws_order_lines WHERE order_id=?", [$o['id']]);
    $sess = stripe_checkout($o, $lines);
    if ($sess === null) json_out(['error' => 'Paiement indisponible (Stripe non configuré)', 'orderId' => (int) $o['id'], 'status' => $o['status']], 503);
    if ($sess === false) json_out(['error' => 'Échec Stripe'], 502);
    q("UPDATE ws_orders SET payment_method='card', payment_status='pending' WHERE id=?", [$o['id']]);
    json_out(['ok' => true, 'orderId' => (int) $o['id'], 'checkoutUrl' => $sess['url']]);
  }
}

/* Shape client d'un customer. */
function user_payload($id) {
  $u = row("SELECT id, email, first_name, last_name, phone, office_id, preferred_shop_id,
                   preferred_lang, is_business, fidelity_active FROM ws_customers WHERE id=?", [$id]);
  if (!$u) return null;
  return [
    'id' => (int) $u['id'], 'email' => $u['email'], 'firstName' => $u['first_name'], 'lastName' => $u['last_name'],
    'phone' => $u['phone'], 'officeId' => $u['office_id'], 'preferredShopId' => $u['preferred_shop_id'],
    'lang' => $u['preferred_lang'], 'isBusiness' => (bool) $u['is_business'],
    'fidelityApp' => ['active' => (bool) $u['fidelity_active']],
  ];
}

/* Crée une session Stripe Checkout via l'API REST (cURL). null si non configuré. */
function stripe_checkout($order, $lines) {
  $secret = cfg()['stripe_secret'];
  if (!$secret) return null;
  $f = ['mode' => 'payment',
        'success_url' => cfg()['checkout_success'],
        'cancel_url' => cfg()['checkout_cancel'],
        'metadata[order_id]' => $order['id'], 'metadata[order_ref]' => $order['order_ref'] ?? ''];
  $i = 0;
  foreach ($lines as $l) {
    $f["line_items[$i][quantity]"] = (int) $l['qty'];
    $f["line_items[$i][price_data][currency]"] = 'eur';
    $f["line_items[$i][price_data][unit_amount]"] = (int) round($l['unit_price'] * 100);
    $f["line_items[$i][price_data][product_data][name]"] = $l['product_name'];
    $i++;
  }
  $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_USERPWD => $secret . ':',
    CURLOPT_POSTFIELDS => http_build_query($f), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
  $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return ($code >= 200 && $code < 300) ? json_decode($res, true) : false;
}
