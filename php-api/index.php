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

/* Chemin, en retirant le sous-dossier où vit l'API (ex. /api).
   On ne retire le préfixe que si SCRIPT_NAME pointe bien sur index.php (Apache) ;
   sous le serveur intégré `php -S` (routeur), SCRIPT_NAME = le chemin demandé, donc
   on ne découpe pas (sinon /admin/... serait mal tronqué). */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$base = (substr($script, -4) === '.php') ? rtrim(str_replace('\\', '/', dirname($script)), '/') : '';
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
                          webshop_discount_type, webshop_discount_value,
                          TRIM(CONCAT_WS(' ', street, street_num)) AS address
                     FROM ws_shops WHERE active = 1 ORDER BY name"));
  }
  if ($m === 'GET' && $p === '/brand') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    json_out(row("SELECT id, slug, name, accent, tint, logo_url,
                         webshop_discount_type, webshop_discount_value
                    FROM ws_shops WHERE id = ?", [$s]) ?: []);
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

  /* Moyens de paiement autorisés — par boutique ET par profil (guest/registered/
     company). deferred retiré si la société n'a pas le paiement différé activé. */
  if ($m === 'GET' && $p === '/payment-methods') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    $profile = in_array(qp('profile'), ['guest', 'registered', 'company'], true) ? qp('profile') : 'guest';
    $methods = allowed_methods($s, $profile);
    if ($profile === 'company' && ($cid = qp('companyId'))) {
      $o = row("SELECT deferred_billing_enabled AS d FROM ws_offices WHERE id=?", [$cid]);
      if (!$o || !$o['d']) $methods = array_values(array_filter($methods, fn ($x) => $x !== 'deferred'));
    }
    json_out(array_map(fn ($x) => ['method' => $x, 'label' => payment_label($x)], $methods));
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
    $officeId = qp('officeId'); $siteId = qp('siteId');
    // Fermetures boutique (communes)
    $exc = rows("SELECT DATE_FORMAT(exception_date,'%Y-%m-%d') AS d, type FROM ws_shop_exceptions
                  WHERE shop_id=? AND exception_date BETWEEN ? AND ?", [$s, $from, $to]);
    $closed = []; foreach ($exc as $e) if ($e['type'] === 'closed') $closed[$e['d']] = true;
    // Contrainte du panier (par produit + mode) : lead max, cutoff le plus tôt, dispo.
    $productIds = array_values(array_filter(array_map('intval', explode(',', qp('products') ?: ''))));
    [$leadDays, $prodCutoff, $prodEnabled] = basket_pa($s, $mode, $productIds);
    $todayIso = date('Y-m-d'); $nowT = date('H:i:s');

    // ── B2B : livraison liée à un bureau/site → piloté par la TOURNÉE ──
    if ($mode === 'delivery' && ($officeId || $siteId)) {
      $tourId = null;
      if ($siteId) {
        $site = row("SELECT tournee_id, office_client_id FROM ws_office_delivery_sites WHERE id=? AND active=1", [$siteId]);
        if ($site) { $tourId = $site['tournee_id']; if (!$officeId) $officeId = $site['office_client_id']; }
      }
      $set = $officeId ? row("SELECT tour_id, allowed_days, delivery_cutoff
                                FROM ws_office_delivery_settings WHERE office_id=? AND shop_id=? AND active=1", [$officeId, $s]) : null;
      if (!$tourId && $set) $tourId = $set['tour_id'];
      $ta = $tourId ? rows("SELECT delivery_day, cutoff_time FROM ws_tour_availability
                              WHERE tour_id=? AND shop_id=? AND active=1", [$tourId, $s]) : [];
      $tourDays = []; $cutoffByDay = [];
      foreach ($ta as $r) { $d = (int) $r['delivery_day']; $tourDays[] = $d; $cutoffByDay[$d] = $r['cutoff_time']; }
      $allowed = ($set && $set['allowed_days']) ? json_decode($set['allowed_days'], true) : null; // null = pas de restriction bureau
      $officeCutoff = $set['delivery_cutoff'] ?? null;
      // Date min = aujourd'hui + lead produit (+1 si limite du jour déjà passée).
      $wToday = (int) date('N');
      $cutToday = $prodCutoff ?: ($officeCutoff ?: ($cutoffByDay[$wToday] ?? null));
      $extra = ($cutToday && $nowT >= $cutToday) ? 1 : 0;
      $minDate = date('Y-m-d', strtotime('today') + ($leadDays + $extra) * 86400);

      $days = [];
      for ($t = strtotime($from), $end = strtotime($to); $t <= $end; $t += 86400) {
        $iso = date('Y-m-d', $t); $w = (int) date('N', $t);
        $reason = null;
        if (!in_array($w, $tourDays)) $reason = 'no_tour';                          // pas de tournée ce jour
        elseif ($allowed !== null && !in_array($w, $allowed)) $reason = 'office_closed'; // bureau ne reçoit pas ce jour
        elseif (isset($closed[$iso])) $reason = 'holiday';
        elseif (!$prodEnabled) $reason = 'mode_unavailable';                        // produit non livrable
        elseif ($iso < $minDate) $reason = 'cutoff';                                // trop tôt (lead/limite)
        $days[] = ['date' => $iso, 'available' => $reason === null, 'reason' => $reason];
      }
      json_out($days);
    }

    // ── Retrait ou livraison simple (niveau boutique) ──
    $av = row("SELECT collect_open_days, delivery_open_days FROM ws_shop_availability WHERE shop_id=?", [$s]);
    $col = $mode === 'delivery' ? 'delivery_open_days' : 'collect_open_days';
    $open = $av && $av[$col] ? json_decode($av[$col], true) : ($mode === 'delivery' ? [1,2,3,4,5] : [1,2,3,4,5,6]);
    $cut = row("SELECT cutoff_hour, cutoff_minutes, lead_hours FROM ws_calendar_rules
                 WHERE shop_id=? AND mode=? AND active=1 LIMIT 1", [$s, $mode]);
    // Lead (jours) : max entre le défaut boutique et le panier ; cutoff : le plus tôt
    // entre la limite boutique et l'override produit ; le tout selon le mode.
    $shopCutoff = $cut ? sprintf('%02d:%02d:00', (int) $cut['cutoff_hour'], (int) $cut['cutoff_minutes']) : null;
    $cutoff = $prodCutoff !== null ? ($shopCutoff !== null ? min($prodCutoff, $shopCutoff) : $prodCutoff) : $shopCutoff;
    $lead = max($leadDays, $cut ? (int) ceil((int) $cut['lead_hours'] / 24) : 0);
    $extra = ($cutoff && $nowT >= $cutoff) ? 1 : 0;
    $minDate = date('Y-m-d', strtotime('today') + ($lead + $extra) * 86400);
    $days = [];
    for ($t = strtotime($from), $end = strtotime($to); $t <= $end; $t += 86400) {
      $iso = date('Y-m-d', $t); $isoDay = (int) date('N', $t); // 1=Mon..7=Sun
      $reason = !in_array($isoDay, $open) ? 'closed'
              : (isset($closed[$iso]) ? 'holiday'
              : (!$prodEnabled ? 'mode_unavailable'
              : ($iso < $minDate ? 'cutoff' : null)));
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

  /* Créneaux de livraison d'un bureau = les fenêtres de SA tournée (ws_tour_availability).
     window_label 'afternoon' → slot 'soir' (ex. livraison 17:00, cutoff 15:00). Par tournée :
     seules celles ayant une ligne 'afternoon' renvoient le créneau soir. */
  if ($m === 'GET' && $p === '/slots') {
    json_out(slots_for_office(qp('officeId'), qp('date') ?: date('Y-m-d')));
  }
  if ($m === 'GET' && $p === '/slots/next') {
    $list = slots_for_office(qp('officeId'), qp('date') ?: date('Y-m-d'));
    foreach ($list as $s) if ($s['orderable']) json_out($s);   // 1er créneau encore commandable
    json_out($list[0] ?? null);
  }
  if ($m === 'POST' && $p === '/slots/request-evening') {
    $b = body();
    error_log('[ws] demande créneau soir — office=' . ($b['officeId'] ?? '?'));
    json_out(['ok' => true]);
  }

  /* Comptes entreprise auxquels un e-mail est rattaché (pour commander « pour
     une entreprise »). deferredBilling = paiement sur compte activé. */
  if ($m === 'GET' && $p === '/companies') {
    $email = strtolower(trim(qp('email') ?: ''));
    if ($email === '') json_out([]);
    json_out(rows("SELECT o.id, o.name, o.vat, o.deferred_billing_enabled AS deferredBilling
                     FROM ws_office_emails e JOIN ws_offices o ON o.id = e.office_id
                    WHERE e.email = ? AND e.active = 1 AND o.active = 1 AND o.status = 'validated'
                    ORDER BY o.name", [$email]));
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

  /* ── Orders ── (tout est calculé serveur depuis la base : prix, promo 4+1,
       bon de réduction, frais de livraison, paiement différé B2B, liaison bureau) */
  if ($m === 'POST' && $p === '/orders') {
    $b = body();
    $shop = $b['shopId'] ?? null; $basket = $b['basket'] ?? [];
    if (!$shop || !is_array($basket) || !count($basket)) json_out(['error' => 'shopId et basket requis'], 400);
    $mode = $b['mode'] ?? 'collect';
    $dl = is_array($b['delivery'] ?? null) ? $b['delivery'] : [];
    $note = isset($b['note']) ? mb_substr((string) $b['note'], 0, 500) : null;  // note commande

    // Compatibilité avec le payload du front (imbriqué / snake_case) : on normalise.
    $b['customerId']   = $b['customerId']   ?? ($b['customer']['id']    ?? null);
    $b['email']        = $b['email']        ?? ($b['customer']['email'] ?? null);
    $b['paymentMethod']= $b['paymentMethod']?? ($b['payment']['method'] ?? null);
    $b['slotId']       = $b['slotId']       ?? ($b['slot']['slotId']    ?? null);
    $b['slotLabel']    = $b['slotLabel']    ?? ($b['slot']['label']     ?? null);
    $b['deliveryDate'] = $b['deliveryDate'] ?? ($b['slot']['date']      ?? null);
    $dl['siteId']         = $dl['siteId']         ?? ($dl['office_delivery_site_id']   ?? null);
    $dl['officeClientId'] = $dl['officeClientId'] ?? ($dl['office_client_id']          ?? null);
    $dl['tourId']         = $dl['tourId']         ?? ($dl['tournee_id']                ?? null);
    $dl['siteName']       = $dl['siteName']       ?? ($dl['office_delivery_site_name'] ?? null);
    $dl['tourneeStopId']  = $dl['tourneeStopId']  ?? ($dl['tournee_stop_id']           ?? null);

    // 1. Lignes + sous-total (prix serveur), avec le flag promo croisée + note produit.
    $subtotal = 0; $lines = [];
    foreach ($basket as $it) {
      $p2 = row("SELECT p.id, p.name, p.cross_portion, COALESCE(pp.price, p.price) AS price
                   FROM ws_products p LEFT JOIN ws_product_prices pp ON pp.product_id=p.id AND pp.shop_id=? AND pp.active=1
                  WHERE p.id=? AND p.active=1", [$shop, $it['productId'] ?? 0]);
      if (!$p2) continue;
      $qty = max(1, (int) ($it['qty'] ?? 1));
      $subtotal += (float) $p2['price'] * $qty;
      $lines[] = ['productId' => $p2['id'], 'name' => $p2['name'], 'qty' => $qty,
                  'unit' => (float) $p2['price'], 'portion' => $it['portion'] ?? null, 'cross' => (int) $p2['cross_portion'],
                  'note' => isset($it['note']) ? mb_substr((string) $it['note'], 0, 255) : null];
    }
    if (!count($lines)) json_out(['error' => 'aucun produit valide'], 400);
    $subtotal = round($subtotal, 2);

    // 2. Promo croisée X+Y (ws_pricing_rules) : les Y les moins chers offerts par tranche de X.
    $promo = 0;
    $rule = row("SELECT x, y, threshold FROM ws_pricing_rules
                  WHERE rule_type='cross_portion' AND active=1 AND (shop_id=? OR shop_id IS NULL)
                  ORDER BY shop_id IS NULL LIMIT 1", [$shop]);
    if ($rule && (int) $rule['x'] > 0) {
      $units = [];
      foreach ($lines as $l) if ($l['cross']) for ($k = 0; $k < $l['qty']; $k++) $units[] = $l['unit'];
      if (count($units) >= (int) $rule['threshold']) {
        sort($units); // les moins chers d'abord
        $freeCount = intdiv(count($units), (int) $rule['x']) * (int) $rule['y'];
        for ($k = 0; $k < $freeCount && $k < count($units); $k++) $promo += $units[$k];
      }
    }
    $promo = round($promo, 2);

    // 2-bis. Remise webshop paramétrée par boutique (ws_shops.webshop_discount_*).
    $webshopDisc = 0;
    $sd = row("SELECT webshop_discount_type AS t, webshop_discount_value AS v FROM ws_shops WHERE id=?", [$shop]);
    if ($sd && (float) $sd['v'] > 0) {
      $baseW = $subtotal - $promo;
      $webshopDisc = $sd['t'] === 'fixed' ? min($baseW, (float) $sd['v']) : round($baseW * (float) $sd['v']) / 100;
    }
    $webshopDisc = round($webshopDisc, 2);

    // 3. Bon de réduction (ws_vouchers) — validé serveur.
    $voucherCode = null; $voucherDisc = 0;
    if (!empty($b['voucher'])) {
      $v = row("SELECT code, type, value, min_order FROM ws_vouchers
                 WHERE code=? AND active=1 AND (expires_at IS NULL OR expires_at>NOW())
                   AND (max_uses IS NULL OR used_count<max_uses) LIMIT 1", [strtoupper(trim($b['voucher']))]);
      $baseV = $subtotal - $promo - $webshopDisc;
      if ($v && $baseV >= (float) $v['min_order']) {
        $voucherCode = $v['code'];
        $voucherDisc = $v['type'] === 'percent' ? round($baseV * (float) $v['value']) / 100
                     : ($v['type'] === 'fixed' ? (float) $v['value'] : 0);
      }
    }
    $voucherDisc = round($voucherDisc, 2);

    // 4. Frais de livraison (ws_delivery_fee_rules) — seulement en mode livraison.
    //    La règle la plus spécifique (site>office>tour>shop>global) fixe aussi payment_type.
    $feeApplied = 0; $feeAmount = 0; $freeMin = 0; $paymentType = 'immediate';
    if ($mode === 'delivery') {
      $fr = row("SELECT free_delivery, always_charge, fee_amount, free_delivery_minimum, payment_type
                   FROM ws_delivery_fee_rules WHERE active=1 AND (
                        (level='site'   AND site_id=?) OR (level='office' AND office_client_id=?) OR
                        (level='tour'   AND tour_id=?) OR (level='shop' AND shop_id=?) OR (level='global'))
                  ORDER BY FIELD(level,'site','office','tour','shop','global') LIMIT 1",
                [$dl['siteId'] ?? null, $dl['officeClientId'] ?? null, $dl['tourId'] ?? null, $shop]);
      if ($fr) {
        $paymentType = $fr['payment_type'] ?: 'immediate';
        $freeMin = (float) $fr['free_delivery_minimum'];
        $afterDisc = $subtotal - $promo - $webshopDisc - $voucherDisc;
        $isFree = !$fr['always_charge'] && ($fr['free_delivery'] || ($freeMin > 0 && $afterDisc >= $freeMin));
        if (!$isFree) { $feeAmount = (float) $fr['fee_amount']; $feeApplied = $feeAmount > 0 ? 1 : 0; }
      }
    }

    // 4-bis. Compte entreprise : « commander pour une entreprise ».
    //   - Si le compte a le paiement différé activé ET que le client choisit
    //     « sur compte » → commande facturée (deferred, pas de paiement en ligne).
    //   - Sinon → paiement par carte société (le front affiche « Je paie pour ma société ? »).
    $companyId = $b['companyId'] ?? null; $onAccount = false;
    $paymentMethod = $b['paymentMethod'] ?? 'cash';
    if ($companyId) {
      $custEmail = $b['email'] ?? null;
      if (!$custEmail && !empty($b['customerId'])) {
        $cc = row("SELECT email FROM ws_customers WHERE id=?", [$b['customerId']]); $custEmail = $cc['email'] ?? null;
      }
      $link = $custEmail ? row("SELECT o.deferred_billing_enabled AS deferred
                                  FROM ws_office_emails e JOIN ws_offices o ON o.id = e.office_id
                                 WHERE e.office_id=? AND e.email=? AND e.active=1 AND o.active=1 AND o.status='validated' LIMIT 1",
                               [$companyId, strtolower(trim($custEmail))]) : null;
      if (!$link) json_out(['error' => "Cet e-mail n'est pas rattaché à ce compte entreprise"], 403);
      if ($link['deferred'] && !empty($b['onAccount'])) {
        $onAccount = true; $paymentType = 'deferred'; $paymentMethod = 'account';
      }
    }
    $orderStatus = $onAccount ? 'confirmed' : 'pending';
    $officeClientId = $companyId ?? ($dl['officeClientId'] ?? null);

    // 4-ter. Profil de paiement + validation du moyen selon la config boutique.
    //   profil : company (société) > registered (compte) > guest (visiteur).
    $profile = $companyId ? 'company' : (!empty($b['customerId']) ? 'registered' : 'guest');
    $family = payment_family($paymentMethod);
    if ($family !== '' && !in_array($family, allowed_methods($shop, $profile), true)) {
      json_out(['error' => 'Moyen de paiement non autorisé pour ce profil',
                'profile' => $profile, 'allowed' => allowed_methods($shop, $profile)], 400);
    }
    // Contact visiteur (guest) — enregistré seulement si pas de compte.
    $guestEmail = empty($b['customerId']) ? ($b['email'] ?? null) : null;
    $guestName  = empty($b['customerId']) ? (trim(($b['customer']['firstName'] ?? '') . ' ' . ($b['customer']['lastName'] ?? '')) ?: null) : null;
    $guestPhone = empty($b['customerId']) ? ($b['customer']['phone'] ?? ($b['phone'] ?? null)) : null;

    // 5. Total final.
    $total = max(0, round($subtotal - $promo - $webshopDisc - $voucherDisc + $feeAmount, 2));
    $ref = 'WS-' . time() . rand(10, 99);
    $stockDate = $b['deliveryDate'] ?? date('Y-m-d');   // le stock est par jour
    $slotStart = (!empty($b['slotLabel']) && preg_match('/(\d{1,2}):(\d{2})/', $b['slotLabel'], $tm))
                 ? sprintf('%02d:%02d:00', (int) $tm[1], (int) $tm[2]) : null;
    $totalQty = array_sum(array_map(fn ($l) => $l['qty'], $lines));

    // 6. Transaction : anti-survente + capacité créneau + écriture (tout ou rien).
    $pdo = db();
    $pdo->beginTransaction();
    try {
      // 6a. Anti-survente : stock verrouillé (FOR UPDATE), refus si insuffisant.
      //     Pas de ligne stock pour ce jour = illimité (aucune vérification).
      foreach ($lines as $l) {
        $st = row("SELECT GREATEST(0, qty_total - qty_reserved - qty_sold) AS avail
                     FROM ws_product_stock
                    WHERE product_id=? AND shop_id=? AND date=? AND (mode=? OR mode IS NULL)
                    LIMIT 1 FOR UPDATE", [$l['productId'], $shop, $stockDate, $mode]);
        if ($st !== null && $l['qty'] > (int) $st['avail']) {
          $pdo->rollBack();
          json_out(['error' => 'Stock insuffisant', 'product' => $l['name'], 'available' => (int) $st['avail']], 409);
        }
      }
      // 6b. Capacité du créneau (si défini pour cette date) : refus si complet.
      $cap = null;
      if ($slotStart && !empty($b['deliveryDate'])) {
        $cap = row("SELECT id, max_orders, current_orders FROM ws_slot_capacity
                     WHERE shop_id=? AND mode=? AND slot_date=? AND slot_start=? LIMIT 1 FOR UPDATE",
                   [$shop, $mode, $b['deliveryDate'], $slotStart]);
        if ($cap && (int) $cap['current_orders'] >= (int) $cap['max_orders']) {
          $pdo->rollBack();
          json_out(['error' => 'Créneau complet', 'slot' => $b['slotLabel']], 409);
        }
      }
      // 6b-bis. Capacité de la TOURNÉE B2B (livraison liée à un site) : refus si pleine.
      if ($mode === 'delivery' && !empty($dl['siteId']) && !empty($b['deliveryDate'])) {
        $siteRow = row("SELECT tournee_id FROM ws_office_delivery_sites WHERE id=?", [$dl['siteId']]);
        $tourId = $siteRow['tournee_id'] ?? null;
        if ($tourId) {
          $w = (int) date('N', strtotime($b['deliveryDate']));
          $tcap = row("SELECT max_orders FROM ws_tour_availability
                        WHERE tour_id=? AND shop_id=? AND delivery_day=? AND active=1 LIMIT 1", [$tourId, $shop, $w]);
          if ($tcap && $tcap['max_orders'] !== null) {
            $cnt = row("SELECT COUNT(*) AS n FROM ws_orders o
                          JOIN ws_office_delivery_sites st ON st.id = o.office_delivery_site_id
                         WHERE st.tournee_id=? AND o.delivery_date=? AND o.status<>'cancelled'",
                       [$tourId, $b['deliveryDate']]);
            if ($cnt && (int) $cnt['n'] >= (int) $tcap['max_orders']) {
              $pdo->rollBack();
              json_out(['error' => 'Tournée complète pour cette date', 'date' => $b['deliveryDate']], 409);
            }
          }
        }
      }
      // 6c. Écriture de la commande + lignes + décrément stock (même jour).
      q("INSERT INTO ws_orders
           (order_ref, shop_id, customer_id, guest_email, guest_name, guest_phone, mode, status,
            slot_id, slot_label, delivery_date,
            subtotal, promo_amount, webshop_discount, voucher_code, voucher_discount, total,
            payment_method, payment_status, lang, note, delivery_mode,
            office_client_id, office_delivery_site_id, office_delivery_site_name, tournee_stop_id,
            payment_type, delivery_fee_applied, delivery_fee_amount, free_delivery_minimum)
         VALUES (?,?,?, ?,?,?, ?, ?, ?,?,?, ?,?,?,?,?,?, ?, 'pending', ?, ?, ?, ?,?,?,?, ?,?,?,?)",
        [$ref, $shop, $b['customerId'] ?? null, $guestEmail, $guestName, $guestPhone, $mode, $orderStatus,
         $b['slotId'] ?? null, $b['slotLabel'] ?? null, $b['deliveryDate'] ?? null,
         $subtotal, $promo, $webshopDisc, $voucherCode, $voucherDisc, $total,
         $paymentMethod, $b['lang'] ?? 'fr', $note, $mode === 'delivery' ? 'office_delivery' : 'collect',
         $officeClientId, $dl['siteId'] ?? null, $dl['siteName'] ?? null, $dl['tourneeStopId'] ?? null,
         $paymentType, $feeApplied, $feeAmount, $freeMin]);
      $oid = $pdo->lastInsertId();
      foreach ($lines as $l) {
        q("INSERT INTO ws_order_lines (order_id, product_id, product_name, qty, unit_price, `portion`, note) VALUES (?,?,?,?,?,?,?)",
          [$oid, $l['productId'], $l['name'], $l['qty'], $l['unit'], $l['portion'], $l['note']]);
        q("UPDATE ws_product_stock SET qty_sold = qty_sold + ?
            WHERE product_id=? AND shop_id=? AND date=? AND (mode=? OR mode IS NULL)",
          [$l['qty'], $l['productId'], $shop, $stockDate, $mode]);
      }
      if ($cap) q("UPDATE ws_slot_capacity SET current_orders = current_orders + 1, current_items = current_items + ? WHERE id=?", [$totalQty, $cap['id']]);
      if ($voucherCode) q("UPDATE ws_vouchers SET used_count = used_count + 1 WHERE code=?", [$voucherCode]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }

    // E-mail de confirmation (email fourni, ou celui du client connecté).
    $to = $b['email'] ?? null;
    if (!$to && !empty($b['customerId'])) {
      $c = row("SELECT email FROM ws_customers WHERE id=?", [$b['customerId']]); $to = $c['email'] ?? null;
    }
    send_order_email($ref, $lines, $total, $to);
    json_out(['ok' => true, 'orderId' => (int) $oid, 'orderRef' => $ref,
              'subtotal' => $subtotal, 'promo' => $promo, 'webshopDiscount' => $webshopDisc,
              'voucherDiscount' => $voucherDisc, 'deliveryFee' => $feeAmount,
              'paymentType' => $paymentType, 'onAccount' => $onAccount, 'total' => $total]);
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
    $phone = trim($b['phone'] ?? '');
    $hash = password_hash($b['password'], PASSWORD_BCRYPT);
    // Rattache le compte à un client ERP existant (match email ou téléphone) → client_id. Best-effort.
    $clientId = null;
    try {
      $cl = row("SELECT id FROM client WHERE email=? OR (? <> '' AND phone=?) LIMIT 1", [$mail, $phone, $phone]);
      $clientId = $cl['id'] ?? null;
    } catch (\Throwable $e) { /* table client absente → pas de rattachement */ }
    q("INSERT INTO ws_customers (email, password_hash, first_name, last_name, phone, client_id) VALUES (?,?,?,?,?,?)",
      [$mail, $hash, $b['firstName'] ?? '', $b['lastName'] ?? '', $phone, $clientId]);
    $id = db()->lastInsertId();
    json_out(['user' => user_payload($id), 'token' => sign_token(['id' => (int) $id, 'exp' => time() + 30 * 86400])], 201);
  }
  if ($m === 'POST' && $p === '/auth/login') {
    $b = body();
    // Identifiant = email OU téléphone.
    $ident = strtolower(trim($b['identifier'] ?? $b['email'] ?? ''));
    if ($ident === '') json_out(['error' => 'Identifiants incorrects.'], 401);
    $u = row("SELECT id, password_hash FROM ws_customers WHERE (email=? OR phone=?) AND active=1", [$ident, $ident]);
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

  /* ── Back-office admin (protégé par admin_token) ── */
  if (strpos($p, '/admin/') === 0) {
    require_admin();

    // Produits (tous) — pour la gestion
    if ($m === 'GET' && $p === '/admin/products') {
      json_out(rows("SELECT p.id, p.cat_id, c.label AS category, p.name, p.price, p.active
                       FROM ws_products p LEFT JOIN ws_categories c ON c.id=p.cat_id ORDER BY p.name"));
    }
    // Créer / modifier un produit
    if ($m === 'POST' && $p === '/admin/products') {
      $b = body();
      if (!empty($b['id'])) {
        q("UPDATE ws_products SET name=?, price=?, cat_id=?, active=? WHERE id=?",
          [$b['name'], (float) $b['price'], $b['cat_id'] ?? null, !empty($b['active']) ? 1 : 0, $b['id']]);
        json_out(['ok' => true, 'id' => (int) $b['id']]);
      }
      if (empty($b['name'])) json_out(['error' => 'name requis'], 400);
      q("INSERT INTO ws_products (cat_id, name, price, active) VALUES (?,?,?,1)",
        [$b['cat_id'] ?? null, $b['name'], (float) ($b['price'] ?? 0)]);
      json_out(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }
    // Prix par boutique
    if ($m === 'POST' && $p === '/admin/price') {
      $b = body();
      q("INSERT INTO ws_product_prices (product_id, shop_id, price, active) VALUES (?,?,?,1)
         ON DUPLICATE KEY UPDATE price=VALUES(price), active=1",
        [$b['productId'], $b['shopId'], (float) $b['price']]);
      json_out(['ok' => true]);
    }
    // Stock du jour (ou date donnée)
    if ($m === 'POST' && $p === '/admin/stock') {
      $b = body();
      q("INSERT INTO ws_product_stock (product_id, shop_id, date, mode, qty_total, qty_reserved, qty_sold, active)
         VALUES (?,?,?,?,?,0,0,1)
         ON DUPLICATE KEY UPDATE qty_total=VALUES(qty_total)",
        [$b['productId'], $b['shopId'], $b['date'] ?? date('Y-m-d'), $b['mode'] ?? 'collect', (int) $b['qtyTotal']]);
      json_out(['ok' => true]);
    }
    // Commandes (liste)
    if ($m === 'GET' && $p === '/admin/orders') {
      $s = qp('shopId');
      $sql = "SELECT id, order_ref, shop_id, mode, status, payment_status, total, created_at FROM ws_orders";
      json_out($s ? rows("$sql WHERE shop_id=? ORDER BY id DESC LIMIT 200", [$s])
                  : rows("$sql ORDER BY id DESC LIMIT 200"));
    }
    // Changer le statut d'une commande
    if ($m === 'POST' && ($mm = $match('/admin/orders/:id/status'))) {
      $b = body();
      q("UPDATE ws_orders SET status=? WHERE id=?", [$b['status'] ?? 'confirmed', $mm['id']]);
      json_out(['ok' => true]);
    }
    // Régler la remise webshop d'une boutique
    if ($m === 'POST' && $p === '/admin/shop-discount') {
      $b = body();
      $type = in_array($b['type'] ?? '', ['percent', 'fixed'], true) ? $b['type'] : 'percent';
      q("UPDATE ws_shops SET webshop_discount_type=?, webshop_discount_value=? WHERE id=?",
        [$type, (float) ($b['value'] ?? 0), $b['shopId'] ?? 0]);
      json_out(['ok' => true, 'type' => $type, 'value' => (float) ($b['value'] ?? 0)]);
    }
    // ── Comptes entreprise (B2B) ──
    // Activer/désactiver le paiement différé (sur compte) + contrat.
    if ($m === 'POST' && $p === '/admin/company-billing') {
      $b = body();
      q("UPDATE ws_offices SET deferred_billing_enabled=?, contract_url=? WHERE id=?",
        [!empty($b['deferred']) ? 1 : 0, $b['contractUrl'] ?? null, $b['officeId'] ?? 0]);
      json_out(['ok' => true]);
    }
    // Lister les e-mails rattachés à un compte.
    if ($m === 'GET' && $p === '/admin/company-emails') {
      $oid = qp('officeId'); if (!$oid) json_out(['error' => 'officeId requis'], 400);
      json_out(rows("SELECT id, email, contract_url AS contractUrl, active FROM ws_office_emails WHERE office_id=? ORDER BY email", [$oid]));
    }
    // Ajouter un e-mail à un compte entreprise.
    if ($m === 'POST' && $p === '/admin/company-email') {
      $b = body(); $email = strtolower(trim($b['email'] ?? ''));
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Email invalide'], 400);
      q("INSERT INTO ws_office_emails (office_id, email, contract_url, active) VALUES (?,?,?,1)
         ON DUPLICATE KEY UPDATE active=1, contract_url=VALUES(contract_url)",
        [$b['officeId'] ?? 0, $email, $b['contractUrl'] ?? null]);
      json_out(['ok' => true]);
    }
    // Retirer un e-mail (désactiver).
    if ($m === 'POST' && $p === '/admin/company-email/remove') {
      $b = body();
      q("UPDATE ws_office_emails SET active=0 WHERE office_id=? AND email=?",
        [$b['officeId'] ?? 0, strtolower(trim($b['email'] ?? ''))]);
      json_out(['ok' => true]);
    }
    // ── Moyens de paiement par boutique × profil ──
    if ($m === 'GET' && $p === '/admin/payment-options') {
      $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
      json_out(rows("SELECT profile_type AS profile, method, active FROM ws_shop_payment_options
                      WHERE shop_id=? ORDER BY profile_type, method", [$s]));
    }
    // Activer/désactiver un moyen pour un (boutique, profil).
    if ($m === 'POST' && $p === '/admin/payment-option') {
      $b = body();
      $prof = in_array($b['profile'] ?? '', ['guest', 'registered', 'company'], true) ? $b['profile'] : null;
      $meth = in_array($b['method'] ?? '', ['stripe', 'shop', 'deferred'], true) ? $b['method'] : null;
      if (!$prof || !$meth || empty($b['shopId'])) json_out(['error' => 'shopId, profile, method requis'], 400);
      q("INSERT INTO ws_shop_payment_options (shop_id, profile_type, method, active) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE active=VALUES(active)",
        [$b['shopId'], $prof, $meth, !empty($b['active']) ? 1 : 0]);
      json_out(['ok' => true]);
    }
  }
}

/* Contrainte de date d'un panier, PAR MODE (collect/delivery).
   Hiérarchie par produit : ws_product_availability → ws_category_availability.
   Retourne [leadMax (jours), cutoffMin ('HH:MM:SS' ou null), tousDispo(bool)].
   - lead : le produit le plus long impose son délai (max).
   - cutoff : la limite la plus tôt s'impose (min).
   - dispo : faux si un produit n'est pas activé dans ce mode. */
/* Fenêtres de livraison (créneaux) d'un bureau pour une date, via SA tournée.
   Lit ws_tour_availability (une ligne par fenêtre : window_label morning/afternoon)
   pour le jour ISO de la date. Calcule `orderable` côté serveur d'après cutoff_time. */
function slots_for_office($officeId, $date) {
  if (!$officeId) return [];
  $off = row("SELECT o.tour_id, t.shop_id FROM ws_offices o
                JOIN ws_tours t ON t.id = o.tour_id
               WHERE o.id = ? AND o.active = 1", [$officeId]);
  if (!$off || !$off['tour_id']) return [];
  $dow = (int) date('N', strtotime($date));   // 1=lundi .. 7=dimanche
  $wins = rows("SELECT id, window_label,
                       TIME_FORMAT(delivery_start,'%H:%i') AS start_t,
                       TIME_FORMAT(cutoff_time,'%H:%i')    AS cutoff_t,
                       cutoff_time
                  FROM ws_tour_availability
                 WHERE tour_id = ? AND shop_id = ? AND delivery_day = ? AND active = 1
                 ORDER BY delivery_start", [$off['tour_id'], $off['shop_id'], $dow]);
  $today = date('Y-m-d'); $now = date('H:i:s'); $out = [];
  foreach ($wins as $w) {
    $lbl  = strtolower((string) $w['window_label']);
    $soir = in_array($lbl, ['afternoon', 'soir', 'evening', 'pm'], true);
    if ($date > $today)     $orderable = true;
    elseif ($date < $today) $orderable = false;
    else                    $orderable = ($now < $w['cutoff_time']);   // aujourd'hui : avant le cutoff
    $out[] = [
      'slot_type'     => $soir ? 'soir' : 'midi',
      'route_id'      => 'w' . $w['id'],
      'delivery_time' => $w['start_t'],
      'cutoff'        => $w['cutoff_t'],
      'cutoff_label'  => str_replace(':', 'h', $w['cutoff_t']),
      'orderable'     => $orderable,
      'cta' => [
        'theme' => $soir ? 'evening' : 'lunch',
        'icon'  => $soir ? 'evening' : 'lunch',
        'label' => $soir ? 'Soirée' : 'Midi',
      ],
    ];
  }
  return $out;
}

function basket_pa($shop, $mode, $productIds) {
  if (!$productIds) return [0, null, true];
  $in = implode(',', array_fill(0, count($productIds), '?'));
  $rs = rows("SELECT p.id, p.no_delivery,
                     pa.collect_enabled p_ce, pa.delivery_enabled p_de,
                     pa.collect_lead_time p_cl, pa.delivery_lead_time p_dl,
                     pa.collect_cutoff_override p_cc, pa.delivery_cutoff_override p_dc,
                     ca.collect_enabled c_ce, ca.delivery_enabled c_de,
                     ca.collect_lead_time c_cl, ca.delivery_lead_time c_dl,
                     ca.collect_cutoff_override c_cc, ca.delivery_cutoff_override c_dc
                FROM ws_products p
                LEFT JOIN ws_product_availability pa ON pa.product_id=p.id AND pa.shop_id=? AND pa.active=1
                LEFT JOIN ws_category_availability ca ON ca.category_id=p.cat_id AND ca.shop_id=? AND ca.active=1
               WHERE p.id IN ($in)", array_merge([$shop, $shop], $productIds));
  $lead = 0; $cutoff = null; $enabled = true;
  foreach ($rs as $r) {
    if ($mode === 'delivery') {
      $en = $r['p_de'] ?? $r['c_de'] ?? (int) !$r['no_delivery'];
      $l  = $r['p_dl'] ?? $r['c_dl'] ?? null;
      $c  = $r['p_dc'] ?? $r['c_dc'] ?? null;
    } else {
      $en = $r['p_ce'] ?? $r['c_ce'] ?? 1;
      $l  = $r['p_cl'] ?? $r['c_cl'] ?? null;
      $c  = $r['p_cc'] ?? $r['c_cc'] ?? null;
    }
    if (!$en) $enabled = false;
    if ($l !== null) $lead = max($lead, (int) $l);
    if ($c !== null) $cutoff = ($cutoff === null) ? $c : min($cutoff, $c);
  }
  return [$lead, $cutoff, $enabled];
}

/* Normalise un moyen de paiement vers sa famille canonique. */
function payment_family($m) {
  $m = strtolower(trim((string) $m));
  if (in_array($m, ['stripe', 'card', 'carte', 'bancontact', 'visa', 'mastercard', 'maestro'], true)) return 'stripe';
  if (in_array($m, ['shop', 'boutique', 'especes', 'cash', 'cod'], true)) return 'shop';
  if (in_array($m, ['deferred', 'account', 'compte', 'facturation'], true)) return 'deferred';
  return $m;
}
function payment_label($m) {
  $map = ['stripe' => 'Carte / Bancontact (en ligne)', 'shop' => 'Paiement en boutique', 'deferred' => 'Sur compte (facturation)'];
  return $map[$m] ?? $m;
}
/* Moyens de paiement autorisés pour une boutique + profil (config, sinon défaut). */
function allowed_methods($shop, $profile) {
  $rows = rows("SELECT method FROM ws_shop_payment_options WHERE shop_id=? AND profile_type=? AND active=1 ORDER BY method", [$shop, $profile]);
  if ($rows) return array_column($rows, 'method');
  return $profile === 'company' ? ['stripe', 'deferred'] : ['stripe', 'shop']; // défaut
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
