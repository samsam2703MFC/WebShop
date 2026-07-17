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
  // Vrai message dans le log Apache (tail /var/log/apache2/error.log).
  error_log('[ws] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
  // Détail dans la réponse uniquement si 'debug' => true dans config.php.
  $out = ['error' => 'Erreur interne'];
  if (!empty(cfg()['debug'])) $out['detail'] = $e->getMessage();
  json_out($out, 500);
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

  /* ── Config front (clés ws_param en liste blanche — jamais la table entière).
     Pilote notamment la visibilité de l'onglet Fidélité (masquable en prod sans
     redéploiement : ws_param.fidelity_tab_enabled = '0') et le délai de demande
     de facture (invoice_request_deadline : 'end_of_month' par défaut, ou un
     nombre de jours). ── */
  if ($m === 'GET' && $p === '/config') {
    json_out([
      'fidelityTabEnabled'     => ws_param('fidelity_tab_enabled', '1') !== '0',
      'invoiceRequestDeadline' => ws_param('invoice_request_deadline', 'end_of_month'),
    ]);
  }

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

  /* ── Lien webshop du client PWA (footer PWA → boutique préférée) ──
   * GET /webshop-link?clientId=123
   *   → { url, shopId, slug }
   * Résout client.preferred_shop_id → shop, et construit l'URL du webshop mobile :
   *   1) lien absolu shops.landing_config.webshop_url s'il est défini,
   *   2) sinon <webshop_base>?shop=<slug>,
   *   3) sinon (pas connecté / pas de shop préféré / colonne absente) → <webshop_base>.
   * Compatible avant/après unification (shops sinon ws_shops), sans dépendre du
   * script d'auth : si la colonne preferred_shop_id n'existe pas encore → lien générique.
   */
  if ($m === 'GET' && $p === '/webshop-link') {
    $base = cfg()['webshop_base'] ?: 'https://samsam2703mfc.github.io/WebShop/webshop-full.html';
    $cid  = qp('clientId');
    $shop = null;
    $hasCol = row("SELECT 1 AS x FROM information_schema.columns
                     WHERE table_schema=DATABASE() AND table_name='client'
                       AND column_name='preferred_shop_id'");
    if ($cid && $hasCol) {
      $hasShops = row("SELECT 1 AS x FROM information_schema.tables
                         WHERE table_schema=DATABASE() AND table_name='shops'");
      if ($hasShops) {
        $shop = row("SELECT s.id, s.slug, s.webshop_url
                       FROM client c JOIN shops s ON s.id = c.preferred_shop_id
                      WHERE c.id = ?", [$cid]);
      } else {
        $shop = row("SELECT w.id, w.slug, NULL AS webshop_url
                       FROM client c JOIN ws_shops w ON w.id = c.preferred_shop_id
                      WHERE c.id = ?", [$cid]);
      }
    }
    if ($shop && !empty($shop['webshop_url'])) {
      $url = $shop['webshop_url'];                         // 1) lien absolu par boutique
    } elseif ($shop && !empty($shop['slug'])) {
      $sep = (strpos($base, '?') !== false) ? '&' : '?';
      $url = $base . $sep . 'shop=' . rawurlencode($shop['slug']);   // 2) base + slug
    } else {
      $url = $base;                                        // 3) générique
    }
    json_out([
      'url'    => $url,
      'shopId' => $shop['id']   ?? null,
      'slug'   => $shop['slug'] ?? null,
    ]);
  }

  /* ── Catalog ── */
  if ($m === 'GET' && $p === '/catalog/categories') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    // N'expose une catégorie que si elle a >=1 produit DISPONIBLE dans cette
    // boutique (produit actif + présent dans l'assortiment ws_product_shops).
    $cats = rows("SELECT c.id, c.slug, c.label, c.img, c.sort_order
                    FROM ws_categories c
                   WHERE c.active = 1 AND (c.shop_id = ? OR c.shop_id IS NULL)
                     AND EXISTS (SELECT 1 FROM ws_products p
                                   JOIN ws_product_shops ps ON ps.product_id = p.id
                                                           AND ps.shop_id = ? AND ps.active = 1
                                  WHERE p.cat_id = c.id AND p.active = 1)
                   ORDER BY c.sort_order, c.label", [$s, $s]);
    // Rattache les sous-catégories (ws_category_subs) à chaque catégorie -> c.subs[]
    // (le front lit activeCat.subs pour afficher la bande de sous-catégories).
    // Même règle : on n'expose qu'une sous-catégorie qui a >=1 produit dispo ici.
    $subs = rows("SELECT sub.id, sub.category_id, sub.slug, sub.label, sub.img, sub.sort_order
                    FROM ws_category_subs sub
                    JOIN ws_categories c ON c.id = sub.category_id
                   WHERE sub.active = 1 AND (c.shop_id = ? OR c.shop_id IS NULL)
                     AND EXISTS (SELECT 1 FROM ws_products p
                                   JOIN ws_product_shops ps ON ps.product_id = p.id
                                                           AND ps.shop_id = ? AND ps.active = 1
                                  WHERE p.sub_cat_id = sub.id AND p.active = 1)
                   ORDER BY sub.sort_order, sub.label", [$s, $s]);
    $byCat = [];
    foreach ($subs as $x) { $byCat[$x['category_id']][] = $x; }
    foreach ($cats as &$c) { $c['subs'] = $byCat[$c['id']] ?? []; }
    unset($c);
    json_out($cats);
  }
  if ($m === 'GET' && $p === '/catalog/products') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    // `badge` (texte) a été migré en FK tag_id -> ws_tags ; on expose le libellé
    // du tag sous la clé `badge` (rétro-compat UI) + couleurs, et la saison.
    $r = rows("SELECT p.id, p.cat_id, p.sub_cat_id,
                      p.cat_id AS cat, p.sub_cat_id AS subCat, c.label AS category,
                      p.name, p.description,
                      t.tag AS badge, t.slug AS tag_slug, t.bg_color AS tag_bg, t.text_color AS tag_text,
                      se.slug AS season, se.name AS season_name, se.img AS season_img,
                      p.portions, p.cross_portion, p.has_menu_options,
                      COALESCE(pp.price, p.price) AS price, ps.no_delivery,
                      (SELECT JSON_ARRAYAGG(allergen) FROM ws_product_allergens a WHERE a.product_id = p.id) AS allergens
                 FROM ws_products p
                 JOIN ws_product_shops ps ON ps.product_id = p.id AND ps.shop_id = ? AND ps.active = 1
                 LEFT JOIN ws_product_prices pp ON pp.product_id = p.id AND pp.shop_id = ? AND pp.active = 1
                 LEFT JOIN ws_categories c ON c.id = p.cat_id
                 LEFT JOIN ws_tags t ON t.id = p.tag_id
                 LEFT JOIN ws_season se ON se.id = p.season_id
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
  // Menu / bundle d'un produit : ws_bundles -> slots -> choices (imbriqué).
  if ($m === 'GET' && $p === '/catalog/bundles') {
    $pid = qp('productId'); if (!$pid) json_out([]);
    $bundles = rows("SELECT id, name, description, price_modifier, sort_order
                       FROM ws_bundles WHERE product_id = ? AND active = 1
                      ORDER BY sort_order, id", [$pid]);
    foreach ($bundles as &$b) {
      $b['price_modifier'] = (float) $b['price_modifier'];
      $slots = rows("SELECT id, label, required, sort_order
                       FROM ws_bundle_slots WHERE bundle_id = ? AND active = 1
                      ORDER BY sort_order, id", [$b['id']]);
      foreach ($slots as &$sl) {
        $sl['required'] = (bool) $sl['required'];
        $sl['choices'] = rows("SELECT id, label, img, delta, sort_order
                                 FROM ws_bundle_slot_choices WHERE slot_id = ? AND active = 1
                                ORDER BY sort_order, id", [$sl['id']]);
        foreach ($sl['choices'] as &$ch) { $ch['delta'] = (float) $ch['delta']; }
        unset($ch);
      }
      unset($sl);
      $b['slots'] = $slots;
    }
    unset($b);
    json_out($bundles);
  }
  if ($m === 'GET' && $p === '/catalog/stock') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    $day = qp('date') ?: date('Y-m-d'); $mode = qp('mode') ?: 'collect';
    json_out(rows("SELECT product_id, GREATEST(0, qty_total - qty_reserved - qty_sold) AS available
                     FROM ws_product_stock
                    WHERE shop_id = ? AND date = ? AND active = 1 AND (mode = ? OR mode IS NULL)",
                  [$s, $day, $mode]));
  }
  if ($m === 'GET' && $p === '/catalog/assortments') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    // Saisons = ws_season (source unique, basée sur le slug). ws_assortments a
    // été supprimée (doublon). chip.id = slug -> matché à product.season côté
    // front, donc le filtre saison fonctionne. On n'expose qu'une saison ayant
    // >=1 produit disponible dans la boutique (même règle que les catégories).
    json_out(rows("SELECT se.slug AS id, se.name AS label, se.img
                     FROM ws_season se
                    WHERE se.active = 1
                      AND EXISTS (SELECT 1 FROM ws_products p
                                    JOIN ws_product_shops ps ON ps.product_id = p.id
                                                            AND ps.shop_id = ? AND ps.active = 1
                                   WHERE p.season_id = se.id AND p.active = 1)
                    ORDER BY se.sort_order", [$s]));
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
  /* Sites de livraison au bureau d'un shop — MÊME liste que la PWA
     (repo_offices) : ws_office_delivery_sites actifs, filtrés par shop. Sert le
     sélecteur « bureau » du profil webshop. */
  if ($m === 'GET' && $p === '/office-sites') {
    $s = (int) (qp('shopId') ?: 0);
    if (!$s) json_out([]);
    json_out(rows("SELECT id, name, address FROM ws_office_delivery_sites
                    WHERE shop_id = ? AND active = 1 AND name IS NOT NULL AND name <> ''
                    ORDER BY name", [$s]));
  }

  /* ── Zones de livraison bureau (public) — alimente la droplist de la landing :
     tournées ACTIVES groupées par zone principale (option = zone secondaire).
     Une tournée en préparation (active=0) n'apparaît jamais. ── */
  if ($m === 'GET' && $p === '/delivery-zones') {
    json_out(rows("SELECT t.id, t.name AS tour, t.zone_secondary AS zoneSecondary,
                          z.id AS zoneId, z.name AS zonePrincipal, z.sort_order AS zoneSort
                     FROM ws_tours t LEFT JOIN ws_delivery_zones z ON z.id = t.zone_id
                    WHERE t.active = 1
                    ORDER BY (z.sort_order IS NULL), z.sort_order, z.name, t.name"));
  }

  /* ── Demande de zone non servie (public, « Ma zone n'est pas dans la liste »).
     PERSISTÉE (ws_zone_requests) — c'est la carte de la demande non servie — ET
     un mail admin part en PLUS. Rate-limit par IP (formulaire public arrosable). ── */
  if ($m === 'POST' && $p === '/zone-request') {
    $b  = body();
    $cp = trim((string) ($b['postalCode'] ?? ''));
    if ($cp === '') json_out(['error' => 'Code postal requis.'], 400);
    $ipRaw = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    $ip = trim(explode(',', $ipRaw)[0]);
    $max = (int) ws_param('zone_request_rate_per_hour', '5');
    $rl = row("SELECT COUNT(*) AS n FROM ws_zone_requests
                WHERE source_ip=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [$ip]);
    if ($rl && (int) $rl['n'] >= $max) json_out(['error' => 'Trop de demandes, réessayez plus tard.'], 429);
    $city = trim((string) ($b['city'] ?? ''));   $company = trim((string) ($b['company'] ?? ''));
    $head = (int) ($b['headcount'] ?? 0);         $email = trim((string) ($b['email'] ?? ''));
    q("INSERT INTO ws_zone_requests (postal_code, city, company, headcount, email, source_ip)
       VALUES (?,?,?,?,?,?)",
      [$cp, $city ?: null, $company ?: null, $head ?: null, ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null, $ip ?: null]);
    $admin = ws_param('zone_request_admin_email', cfg()['mail_from'] ?? '');
    if ($admin && filter_var($admin, FILTER_VALIDATE_EMAIL)) {
      $from = cfg()['mail_from'] ?? 'no-reply@atelierby.be';
      @mail($admin, 'Nouvelle demande de zone — livraison bureau',
            "Code postal: $cp\nCommune: $city\nSociete: $company\nCollaborateurs: $head\nEmail: $email\n",
            "From: $from\r\nContent-Type: text/plain; charset=utf-8\r\n");
    }
    json_out(['ok' => true]);
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
        $cc = row("SELECT email FROM client WHERE id=?", [$b['customerId']]); $custEmail = $cc['email'] ?? null;
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
    $guestPfx = '+32'; $guestPhone = null;
    if (empty($b['customerId'])) {
      [$guestPfx, $guestPhone] = norm_phone($b['customer']['phonePrefix'] ?? ($b['phonePrefix'] ?? '+32'), $b['customer']['phone'] ?? ($b['phone'] ?? ''));
      if ($guestPhone === '') { $guestPhone = null; $guestPfx = null; }
    }

    // 4-quater. LIVRAISON BUREAU — éligibilité + cut-off vérifiés SERVEUR (jamais
    //   l'état affiché du front) : site actif + rattaché à une tournée + tournée
    //   active + roule ce jour + pas de fermeture + avant cut-off (heure locale
    //   boutique). Un panier ouvert à 15h58 et validé à 16h03 est refusé ici.
    if ($mode === 'delivery' && !empty($dl['siteId'])) {
      $edc = office_delivery_check($dl['siteId'], $b['deliveryDate'] ?? date('Y-m-d'), $b['customerId'] ?? null);
      if (!$edc['ok']) json_out(['error' => $edc['error'], 'code' => 'office_delivery'], 409);
    }

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
           (order_ref, shop_id, customer_id, guest_email, guest_name, guest_phone, guest_phone_prefix, mode, status,
            slot_id, slot_label, delivery_date,
            subtotal, promo_amount, webshop_discount, voucher_code, voucher_discount, total,
            payment_method, payment_status, lang, note, delivery_mode,
            office_client_id, office_delivery_site_id, office_delivery_site_name, tournee_stop_id,
            payment_type, delivery_fee_applied, delivery_fee_amount, free_delivery_minimum)
         VALUES (?,?,?, ?,?,?,?, ?, ?, ?,?,?, ?,?,?,?,?,?, ?, 'pending', ?, ?, ?, ?,?,?,?, ?,?,?,?)",
        [$ref, $shop, $b['customerId'] ?? null, $guestEmail, $guestName, $guestPhone, $guestPfx, $mode, $orderStatus,
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
      $c = row("SELECT email FROM client WHERE id=?", [$b['customerId']]); $to = $c['email'] ?? null;
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
    $b = body();
    // Champs du formulaire : prénom, nom, code postal, téléphone, email.
    // Auth par email OU téléphone (toggle `authMethod`). Mot de passe optionnel
    // (vérification OTP prévue plus tard) ; s'il est fourni il est haché.
    $mail  = strtolower(trim($b['email'] ?? ''));
    [$pfx, $phone, $e164] = norm_phone($b['phonePrefix'] ?? '+32', $b['phone'] ?? '');
    $first = trim($b['firstName'] ?? '');
    $last  = trim($b['lastName'] ?? '');
    $zip   = trim($b['postalCode'] ?? ($b['zip'] ?? ''));
    $authM = (($b['authMethod'] ?? 'email') === 'phone') ? 'phone' : 'email';
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Email invalide'], 400);
    if ($authM === 'email' && $mail === '')  json_out(['error' => 'Email requis'], 400);
    if ($authM === 'phone' && $phone === '') json_out(['error' => 'Téléphone requis'], 400);
    if ($mail === '' && $phone === '')       json_out(['error' => 'Email ou téléphone requis'], 400);
    $pass = (string) ($b['password'] ?? '');
    $hash = ($pass !== '' && strlen($pass) >= 6) ? password_hash($pass, PASSWORD_BCRYPT) : null;
    // Anti-doublon : si un client existe déjà (email OU téléphone E.164), on ne
    // fusionne PAS — on renvoie 409 { exists:true } pour que le front propose de
    // définir/mettre à jour le mot de passe (endpoint /auth/set-password).
    $cl = row("SELECT id FROM client WHERE (? <> '' AND LOWER(TRIM(email))=?) OR (? <> '' AND (phone_e164=? OR phone=?)) LIMIT 1", [$mail, $mail, $phone, $e164, $phone]);
    if ($cl) {
      json_out(['error' => 'Ce compte existe déjà. Connectez-vous ou définissez votre mot de passe.', 'exists' => true], 409);
    }
    {
      // client.id_main_shop is NOT NULL without a default → caller's shop else modal.
      $ms = $b['shopId'] ?? null;
      if (!$ms) { $r = row("SELECT id_main_shop FROM client GROUP BY id_main_shop ORDER BY COUNT(*) DESC LIMIT 1"); $ms = $r['id_main_shop'] ?? 1; }
      q("INSERT INTO client (id_main_shop, email, phone, phone_prefix, phone_e164, name, surname, zip, password_hash,
                             active, source_channel, webshop_user, preferred_auth_method)
         VALUES (?,?,?,?,?,?,?,?,?,1,'webshop',1,?)",
        [$ms, ($mail ?: null), ($phone ?: null), ($phone !== '' ? $pfx : null), ($e164 ?: null), $first, $last, $zip, $hash, $authM]);
      $id = db()->lastInsertId();
    }
    json_out(['user' => user_payload($id), 'token' => sign_token(['id' => (int) $id, 'exp' => time() + 30 * 86400])], 201);
  }
  if ($m === 'POST' && $p === '/auth/login') {
    $b = body();
    // Identifiant = email OU téléphone.
    $ident = strtolower(trim($b['identifier'] ?? $b['email'] ?? ''));
    if ($ident === '') json_out(['error' => 'Identifiants incorrects.'], 401);
    // Identifiant téléphone : on le normalise en E.164 + national pour le retrouver.
    [, $identNat, $identE164] = norm_phone($b['phonePrefix'] ?? '+32', $ident);
    $u = row("SELECT id, password_hash FROM client WHERE (LOWER(TRIM(email))=? OR (? <> '' AND (phone_e164=? OR phone=? OR phone=?))) AND active=1 LIMIT 1", [$ident, $identE164, $identE164, $identNat, $ident]);
    // Compte existant mais sans mot de passe (client importé / créé côté PWA) :
    // on ne renvoie pas "identifiants incorrects" -> on invite à définir un mot de passe.
    if ($u && empty($u['password_hash'])) {
      json_out(['error' => 'no_password', 'message' => 'Ce compte existe mais n’a pas encore de mot de passe.', 'needsPassword' => true], 409);
    }
    if (!$u || !password_verify($b['password'] ?? '', $u['password_hash'])) json_out(['error' => 'Identifiants incorrects.'], 401);
    json_out(['user' => user_payload($u['id']), 'token' => sign_token(['id' => (int) $u['id'], 'exp' => time() + 30 * 86400])]);
  }
  // Définit / met à jour le mot de passe d'un compte existant, puis connecte.
  // ⚠️ SÉCURITÉ : aucune vérification d'identité (pas d'OTP). Choix produit assumé
  // pour le prototype. NE PAS mettre en prod sans OTP/email — sinon vol de compte.
  if ($m === 'POST' && $p === '/auth/set-password') {
    $b = body();
    $mail = strtolower(trim($b['email'] ?? ''));
    [, $phoneNat, $phoneE164] = norm_phone($b['phonePrefix'] ?? '+32', $b['phone'] ?? '');
    $ident = strtolower(trim($b['identifier'] ?? ''));
    [, $identNat, $identE164] = norm_phone($b['phonePrefix'] ?? '+32', $ident);
    $pass = (string) ($b['password'] ?? '');
    if (strlen($pass) < 6) json_out(['error' => 'Mot de passe trop court (min. 6 caractères).'], 400);
    $u = row("SELECT id FROM client
                WHERE (? <> '' AND LOWER(TRIM(email))=?)
                   OR (? <> '' AND (phone_e164=? OR phone=?))
                   OR (? <> '' AND (LOWER(TRIM(email))=? OR phone_e164=? OR phone=?))
                ORDER BY webshop_user DESC, id LIMIT 1",
             [$mail, $mail, $phoneNat, $phoneE164, $phoneNat, $ident, $ident, $identE164, $identNat]);
    if (!$u) json_out(['error' => 'Compte introuvable.'], 404);
    q("UPDATE client SET password_hash=?, webshop_user=1, active=1 WHERE id=?",
      [password_hash($pass, PASSWORD_BCRYPT), $u['id']]);
    json_out(['user' => user_payload($u['id']), 'token' => sign_token(['id' => (int) $u['id'], 'exp' => time() + 30 * 86400]), 'updated' => true]);
  }
  /* ── VIES (validation TVA UE) — public, sans état. Miroir du PWA vies_lookup.
     WSVies.endpoint = <base>/vies/{country}/{vat}. Renvoie
     { valid, data:{ vat, country, name, address, postalCode, city } } pour
     pré-remplir le formulaire de facturation exactement comme la PWA. ── */
  if ($m === 'GET' && ($mm = $match('/vies/:country/:vat'))) {
    json_out(vies_lookup($mm['vat']));
  }
  // SSO handoff PWA -> webshop. La PWA insère un jeton à usage unique dans
  // auth_handoff (token_hash = sha256 du jeton, + client_id + expires_at) puis
  // redirige vers /webshop?handoff=<jeton>. Ici on le vérifie et on ouvre la session.
  if ($m === 'POST' && $p === '/auth/handoff') {
    $token = (string) (body()['token'] ?? '');
    if ($token === '') json_out(['error' => 'Jeton manquant.'], 400);
    $th = hash('sha256', $token);
    $h = row("SELECT client_id, used_at, expires_at FROM auth_handoff WHERE token_hash = ? LIMIT 1", [$th]);
    if (!$h)                                   json_out(['error' => 'Lien invalide.'], 401);
    if ($h['used_at'] !== null)                json_out(['error' => 'Lien déjà utilisé.'], 401);
    if (strtotime($h['expires_at']) < time())  json_out(['error' => 'Lien expiré.'], 401);
    q("UPDATE auth_handoff SET used_at = NOW() WHERE token_hash = ? AND used_at IS NULL", [$th]); // usage unique
    $cid = (int) $h['client_id'];
    if (!row("SELECT id FROM client WHERE id = ? AND active = 1 LIMIT 1", [$cid])) {
      json_out(['error' => 'Compte inactif.'], 401);
    }
    json_out(['user' => user_payload($cid), 'token' => sign_token(['id' => $cid, 'exp' => time() + 30 * 86400])]);
  }
  /* Vérifie la TVA via VIES ET lie la société au client (persisté) — miroir du
     PWA handle_billing_verify (modèle « company link ») : la société est une
     LIGNE client dédiée (is_b2b=1, retrouvée par TVA ou créée), la personne y
     est liée via company_client_id et sa copie locale des champs société est
     nettoyée. Badge et données restent ainsi identiques PWA ⇄ WS. */
  if ($m === 'POST' && $p === '/auth/billing-verify') {
    $id = auth_uid(); if (!$id) json_out(['error' => 'Non connecté.'], 401);
    $r = vies_lookup((string) (body()['vat'] ?? ''));
    if (empty($r['valid'])) json_out($r);
    $d = $r['data'];
    try {
      // 1) Retrouver la ligne société existante (par TVA), sinon la créer.
      $c = row("SELECT id FROM client
                 WHERE tax_number = ? AND is_b2b = 1 AND (name IS NULL OR name = '')
                 ORDER BY (verified_at IS NOT NULL) DESC, id ASC LIMIT 1", [$d['vat']]);
      $companyId = (int) ($c['id'] ?? 0);
      if ($companyId) {
        q("UPDATE client SET company_name=?, invoice_name=COALESCE(NULLIF(invoice_name,''),?),
              invoice_address=COALESCE(NULLIF(invoice_address,''),?),
              invoice_postal_code=COALESCE(invoice_postal_code,?), invoice_city=COALESCE(invoice_city,?),
              is_b2b=1, verified_at=NOW() WHERE id=?",
          [$d['name'], $d['name'], $d['address'], $d['postalCode'], $d['city'], $companyId]);
      } else {
        $ms = (int) ((row("SELECT id_main_shop FROM client WHERE id=?", [$id])['id_main_shop'] ?? 0) ?: 1);
        q("INSERT INTO client (id_main_shop, is_b2b, company_name, tax_number, invoice_name,
              invoice_address, invoice_postal_code, invoice_city, active, source_channel, verified_at)
           VALUES (?,1,?,?,?,?,?,?,1,'webshop',NOW())",
          [$ms, $d['name'], $d['vat'], $d['name'], $d['address'], $d['postalCode'], $d['city']]);
        $companyId = (int) db()->lastInsertId();
      }
      // 2) Lier la personne à la société + retirer sa copie des champs société.
      q("UPDATE client SET company_client_id=?, is_b2b=1,
            company_name=NULL, tax_number=NULL, invoice_name=NULL, invoice_address=NULL
          WHERE id=?", [$companyId, $id]);
      $r['companyClientId'] = $companyId;
    } catch (Throwable $e) {
      // Schéma sans company_client_id (pré-migration) : écriture directe sur la
      // personne, comme l'ancien flux PWA.
      q("UPDATE client
            SET tax_number=?, company_name=?, invoice_name=?, invoice_country=?, invoice_address=?,
                invoice_postal_code=COALESCE(?, invoice_postal_code),
                invoice_city=COALESCE(?, invoice_city),
                is_b2b=1, verified_at=NOW()
          WHERE id=?",
        [$d['vat'], $d['name'], $d['name'], $d['country'], $d['address'], $d['postalCode'], $d['city'], $id]);
    }
    $r['saved'] = true;
    $r['user']  = user_payload($id);
    json_out($r);
  }
  /* Lier / changer / délier le bureau (site de livraison) du client — miroir du
     PWA handle_set_office, MÊME stockage (pwa_offices + pwa_client_office) : la
     modification faite ici est visible dans la PWA et inversement. */
  if ($m === 'POST' && $p === '/auth/office') {
    $id = auth_uid(); if (!$id) json_out(['error' => 'Non connecté.'], 401);
    $ref = body()['siteId'] ?? null;
    if ($ref === null || $ref === '') {
      q("DELETE FROM pwa_client_office WHERE client_id = ?", [$id]);
      json_out(['user' => user_payload($id)]);
    }
    $po = row("SELECT id FROM pwa_offices WHERE office_ref = ? LIMIT 1", [(string) $ref]);
    $poid = (int) ($po['id'] ?? 0);
    if (!$poid) {
      // Auto-création depuis le site choisi (même logique que la PWA après fix).
      $site = row("SELECT id, name FROM ws_office_delivery_sites WHERE id = ? AND active = 1 LIMIT 1", [(int) $ref]);
      if (!$site) json_out(['error' => 'Bureau introuvable.'], 404);
      q("INSERT INTO pwa_offices (office_ref, name, name_norm, status) VALUES (?,?,?,'active')",
        [(string) $ref, $site['name'], mb_strtolower(trim($site['name']))]);
      $poid = (int) db()->lastInsertId();
    }
    q("DELETE FROM pwa_client_office WHERE client_id = ?", [$id]);
    q("INSERT INTO pwa_client_office (client_id, office_id) VALUES (?,?)", [$id, $poid]);
    json_out(['user' => user_payload($id)]);
  }
  /* ── Mes achats : liste UNIFIÉE tickets (pwa_purchases) + commandes webshop
     (ws_orders) de l'utilisateur de session, 12 derniers mois, paginée. Chaque
     ligne porte un état dérivé : open (ticket) | requested (facture demandée) |
     invoiced (facturé, verrouillé) | closed (délai passé). L'appartenance est
     TOUJOURS vérifiée en base (client_id/customer_id = auth_uid). ── */
  if ($m === 'GET' && $p === '/auth/purchases') {
    $id = auth_uid(); if (!$id) json_out(['error' => 'Non connecté.'], 401);
    $filter = qp('filter') ?: 'all';                                   // all | none | requested | invoiced
    $page   = max(1, (int) (qp('page') ?: 1));
    $per    = min(50, max(5, (int) (qp('perPage') ?: 10)));
    $canReq = col_exists('pwa_purchases', 'to_invoice');               // capacité : colonne migrée ?
    $hasBe  = col_exists('pwa_purchases', 'billing_entity_id');
    $hasFrz = col_exists('pwa_purchases', 'frozen_at');
    $hasPdf = col_exists('pwa_invoices', 'pdf_path');
    $items  = [];
    try {                                                              // tickets (ERP/PWA)
      $items = array_merge($items, rows(
        "SELECT p.purchase_code AS ref, p.store AS shop,
                COALESCE(p.occurred_at, p.created_at) AS at,
                (SELECT COUNT(*) FROM pwa_purchase_items it WHERE it.purchase_id = p.id) AS items,
                (SELECT COALESCE(SUM(it.qty * it.unit_price), 0) FROM pwa_purchase_items it WHERE it.purchase_id = p.id)
                  - COALESCE(p.discount, 0) AS total,
                " . ($canReq ? "COALESCE(p.to_invoice,0)" : "0") . " AS toInvoice,
                " . ($hasBe  ? "p.billing_entity_id"       : "NULL") . " AS billingEntityId,
                " . ($hasFrz ? "p.frozen_at"               : "NULL") . " AS frozenAt,
                i.invoice_no AS invoiceNo, i.total_ttc AS invoiceTotal,
                " . ($hasPdf ? "i.pdf_path" : "NULL") . " AS pdfPath,
                'ticket' AS source
           FROM pwa_purchases p LEFT JOIN pwa_invoices i ON i.id = p.invoice_id
          WHERE p.client_id = ? AND COALESCE(p.occurred_at, p.created_at) >= DATE_SUB(NOW(), INTERVAL 12 MONTH)",
        [$id]));
    } catch (Throwable $e) { /* tables PWA absentes */ }
    try {                                                              // commandes webshop
      $items = array_merge($items, rows(
        "SELECT o.order_ref AS ref, s.name AS shop, o.created_at AS at,
                (SELECT COUNT(*) FROM ws_order_lines l WHERE l.order_id = o.id) AS items,
                o.total AS total, 0 AS toInvoice, NULL AS billingEntityId, NULL AS frozenAt,
                NULL AS invoiceNo, NULL AS invoiceTotal, NULL AS pdfPath, 'order' AS source
           FROM ws_orders o LEFT JOIN ws_shops s ON s.id = o.shop_id
          WHERE o.customer_id = ? AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)",
        [$id]));
    } catch (Throwable $e) { /* — */ }
    usort($items, function ($a, $b) { return strcmp((string) $b['at'], (string) $a['at']); });
    $mode = ws_param('invoice_request_deadline', 'end_of_month');
    foreach ($items as &$it) {
      $dl = invoice_deadline((string) $it['at'], $mode);
      $it['state'] = $it['invoiceNo'] ? 'invoiced'
        : (((int) $it['toInvoice']) === 1 ? 'requested'
        : (time() > $dl ? 'closed' : 'open'));
      $it['locked']   = $it['state'] === 'invoiced' || $it['state'] === 'closed' || !empty($it['frozenAt']);
      $it['deadline'] = date('Y-m-d', $dl);
      unset($it['frozenAt'], $it['pdfPath']); // pdf servi via endpoint authentifié uniquement
    }
    unset($it);
    if ($filter !== 'all') {
      $map = ['none' => ['open', 'closed'], 'requested' => ['requested'], 'invoiced' => ['invoiced']];
      $keep = $map[$filter] ?? null;
      if ($keep) $items = array_values(array_filter($items, function ($x) use ($keep) { return in_array($x['state'], $keep, true); }));
    }
    $total = count($items);
    $items = array_slice($items, ($page - 1) * $per, $per);
    json_out([
      'items' => $items, 'total' => $total, 'page' => $page, 'perPage' => $per,
      'canRequestInvoice' => $canReq,
      // Annoncé AU MOMENT de la demande : la facture n'apparaît qu'au batch
      // mensuel du franchisé — sinon le client la cherche dès le lendemain.
      'invoiceNotice' => 'Votre facture sera émise par la boutique en début de mois prochain.',
    ]);
  }

  /* ── Demande de facture sur un ticket : écrit to_invoice (1/0) + le
     destinataire billing_entity_id. REFUS EN BASE (pas sur l'état affiché) si
     déjà facturé, gelé par le batch ERP, ou délai dépassé. 501 si la colonne
     to_invoice n'est pas encore migrée (voir rapport de schéma). ── */
  if ($m === 'POST' && $p === '/auth/purchases/request-invoice') {
    $id = auth_uid(); if (!$id) json_out(['error' => 'Non connecté.'], 401);
    if (!col_exists('pwa_purchases', 'to_invoice')) {
      json_out(['error' => "Fonction indisponible : colonne pwa_purchases.to_invoice absente en base (voir rapport de schéma)."], 501);
    }
    $b    = body();
    $ref  = (string) ($b['ref'] ?? '');
    $want = !empty($b['want']) ? 1 : 0;
    $hasFrz = col_exists('pwa_purchases', 'frozen_at');
    $t = row("SELECT id, invoice_id, COALESCE(occurred_at, created_at) AS at" .
             ($hasFrz ? ", frozen_at" : ", NULL AS frozen_at") .
             " FROM pwa_purchases WHERE purchase_code = ? AND client_id = ? LIMIT 1", [$ref, $id]);
    if (!$t) json_out(['error' => 'Ticket introuvable.'], 404);        // appartenance vérifiée en base
    if (!empty($t['invoice_id'])) json_out(['error' => 'Ticket déjà facturé — modification refusée.'], 409);
    if (!empty($t['frozen_at']))  json_out(['error' => 'Facturation du mois en cours par la boutique — modification refusée.'], 409);
    if (time() > invoice_deadline((string) $t['at'], ws_param('invoice_request_deadline', 'end_of_month'))) {
      json_out(['error' => 'Délai dépassé pour ce ticket.'], 409);
    }
    $sets = 'to_invoice=?'; $vals = [$want];
    if (col_exists('pwa_purchases', 'billing_entity_id')) {
      $be = $b['billingEntityId'] ?? null;
      if ($want && $be !== null && $be !== '') {
        // Le destinataire doit appartenir au compte : sa société liée, ou
        // l'utilisateur lui-même (particulier). Jamais un id arbitraire.
        $mine = ((int) $be === (int) $id)
          || row("SELECT 1 AS ok FROM client WHERE id = ? AND id = (SELECT company_client_id FROM client WHERE id = ?)",
                 [(int) $be, $id]);
        if (!$mine) json_out(['error' => 'Destinataire non autorisé.'], 403);
        $sets .= ', billing_entity_id=?'; $vals[] = (int) $be;
      } elseif (!$want) {
        $sets .= ', billing_entity_id=NULL';
      }
    }
    // Garde anti-course : la clause WHERE re-vérifie l'état AU MOMENT de
    // l'écriture (jamais l'état affiché dans le navigateur).
    $vals[] = (int) $t['id'];
    q("UPDATE pwa_purchases SET $sets WHERE id = ? AND invoice_id IS NULL" .
      ($hasFrz ? " AND frozen_at IS NULL" : ""), $vals);
    $chk = row("SELECT invoice_id" . ($hasFrz ? ", frozen_at" : ", NULL AS frozen_at") .
               " FROM pwa_purchases WHERE id = ?", [(int) $t['id']]);
    if ($chk && (!empty($chk['invoice_id']) || !empty($chk['frozen_at']))) {
      json_out(['error' => 'Ticket verrouillé entre-temps (facturé ou gelé) — modification refusée.'], 409);
    }
    json_out(['ok' => true, 'notice' => 'Votre facture sera émise par la boutique en début de mois prochain.']);
  }

  /* ── Sécurité : changement de mot de passe de la session (auth requise). ── */
  if ($m === 'POST' && $p === '/auth/password') {
    $id = auth_uid(); if (!$id) json_out(['error' => 'Non connecté.'], 401);
    $pass = (string) (body()['password'] ?? '');
    if (strlen($pass) < 6) json_out(['error' => 'Mot de passe trop court (min. 6 caractères).'], 400);
    q("UPDATE client SET password_hash = ? WHERE id = ?", [password_hash($pass, PASSWORD_BCRYPT), $id]);
    json_out(['ok' => true]);
  }

  /* ── Sociétés de facturation : ajout SANS n° TVA (ASBL, association,
     particulier assimilé) — raison sociale + adresse saisies à l'AJOUT
     uniquement, champ TVA vide (entité non assujettie ; la TVA belge reste due,
     seule la case n° TVA de la facture restera vide). NB : schéma actuel
     mono-société (client.company_client_id) — le multi-sociétés + is_default
     figurent au rapport de schéma. ── */
  if ($m === 'POST' && $p === '/auth/billing-company') {
    $id = auth_uid(); if (!$id) json_out(['error' => 'Non connecté.'], 401);
    $b    = body();
    $name = trim((string) ($b['name'] ?? ''));
    if ($name === '') json_out(['error' => 'Raison sociale requise.'], 400);
    $addr = trim((string) ($b['address'] ?? ''));
    $pc   = trim((string) ($b['postalCode'] ?? ''));
    $city = trim((string) ($b['city'] ?? ''));
    $ms   = (int) ((row("SELECT id_main_shop FROM client WHERE id = ?", [$id])['id_main_shop'] ?? 0) ?: 1);
    q("INSERT INTO client (id_main_shop, is_b2b, company_name, invoice_name, invoice_address,
                           invoice_postal_code, invoice_city, active, source_channel)
       VALUES (?,1,?,?,?,?,?,1,'webshop')",
      [$ms, $name, $name, $addr ?: null, $pc ?: null, $city ?: null]);
    $co = (int) db()->lastInsertId();
    q("UPDATE client SET company_client_id = ?, is_b2b = 1 WHERE id = ?", [$co, $id]);
    json_out(['user' => user_payload($id)]);
  }
  /* « Retirer » = ARCHIVAGE : on délie (company_client_id NULL), la ligne
     société n'est JAMAIS supprimée — les factures émises la référencent
     (pwa_invoices.company_client_id, snapshot) et doivent rester lisibles. */
  if ($m === 'POST' && $p === '/auth/billing-company/unlink') {
    $id = auth_uid(); if (!$id) json_out(['error' => 'Non connecté.'], 401);
    q("UPDATE client SET company_client_id = NULL WHERE id = ?", [$id]);
    json_out(['user' => user_payload($id)]);
  }

  if ($m === 'GET' && $p === '/auth/me') {
    $id = auth_uid(); $u = $id ? user_payload($id) : null;
    if (!$u) json_out(['error' => 'Non connecté.'], 401);
    json_out(['user' => $u]);
  }

  /* ── Éligibilité livraison bureau du client connecté pour une date donnée.
     Le FRONT ne montre l'option QUE si eligible=true (confort) ; la vérité est
     re-vérifiée serveur à la commande (office_delivery_check). Renvoie les sites
     rattachés (sur une tournée), leur état commandable et le site par défaut. ── */
  if ($m === 'GET' && $p === '/auth/office-delivery') {
    $id = auth_uid(); if (!$id) json_out(['error' => 'Non connecté.'], 401);
    $date = qp('date') ?: date('Y-m-d');
    $offices = user_office_ids($id);
    $sites = [];
    if ($offices) {
      $ph = implode(',', array_fill(0, count($offices), '?'));
      // Bureau validé = ws_offices.active = 1 (source de vérité 0/1, pas la chaîne
      // status). Un site rattaché à un bureau non validé n'est pas éligible.
      $sites = rows("SELECT s.id, s.name, s.address, s.tournee_id AS tourId, s.is_default AS isDefault
                       FROM ws_office_delivery_sites s
                       JOIN ws_offices o ON o.id = s.office_client_id AND o.active = 1
                      WHERE s.office_client_id IN ($ph) AND s.active = 1 AND s.tournee_id IS NOT NULL
                      ORDER BY s.is_default DESC, s.name", $offices);
    }
    foreach ($sites as &$st) {
      $chk = tour_orderable((int) $st['tourId'], $date);
      $st['id']        = (int) $st['id'];
      $st['tourId']    = (int) $st['tourId'];
      $st['isDefault'] = (bool) $st['isDefault'];
      $st['orderable'] = !empty($chk['ok']);
      $st['reason']    = empty($chk['ok']) ? ($chk['reason'] ?? null) : null;
      $st['cutoffs']   = $chk['cutoffs'] ?? [];
    }
    unset($st);
    $default = null;
    foreach ($sites as $st) if ($st['isDefault']) { $default = $st['id']; break; }
    if ($default === null && count($sites) === 1) $default = $sites[0]['id'];
    json_out(['eligible' => count($sites) > 0, 'sites' => $sites, 'defaultSiteId' => $default, 'date' => $date]);
  }
  if ($m === 'PATCH' && $p === '/auth/me') {
    $id = auth_uid(); if (!$id) json_out(['error' => 'Non connecté.'], 401);
    $b = body(); $map = ['name' => 'firstName', 'surname' => 'lastName'];
    $sets = []; $vals = [];
    foreach ($map as $col => $k) if (array_key_exists($k, $b)) { $sets[] = "$col=?"; $vals[] = $b[$k]; }
    // Téléphone : normalisé en national + E.164 + préfixe.
    if (array_key_exists('phone', $b)) {
      [$pfx, $nat, $e164] = norm_phone($b['phonePrefix'] ?? '+32', $b['phone']);
      $sets[] = 'phone=?';        $vals[] = ($nat ?: null);
      $sets[] = 'phone_prefix=?'; $vals[] = ($nat !== '' ? $pfx : null);
      $sets[] = 'phone_e164=?';   $vals[] = ($e164 ?: null);
    }
    // DONNÉES SOCIÉTÉ NON ÉDITABLES ICI (règle serveur, pas seulement du grisé
    // CSS) : raison sociale, TVA et adresse de facturation atterrissent sur un
    // document fiscal — elles viennent de VIES (/auth/billing-verify) ou d'une
    // saisie encadrée à l'AJOUT (/auth/billing-company), jamais d'une édition
    // libre. Les clés company / invoice / isBusiness envoyées ici sont ignorées.
    if (array_key_exists('postalCode', $b)) { $sets[] = 'zip=?'; $vals[] = ($b['postalCode'] !== '' ? $b['postalCode'] : null); }
    // Préférences éditées dans le profil : persistées pour être visibles depuis
    // la PWA aussi (colonnes partagées). Sans ça, ces choix restaient locaux au
    // navigateur et se perdaient au rechargement.
    if (array_key_exists('preferredShopId', $b)) {
      $sets[] = 'preferred_shop_id=?';
      $vals[] = ($b['preferredShopId'] !== '' && $b['preferredShopId'] !== null) ? (int) $b['preferredShopId'] : null;
    }
    if (array_key_exists('officeId', $b)) {
      $sets[] = 'office_id=?';
      $vals[] = ($b['officeId'] !== '' && $b['officeId'] !== null) ? (int) $b['officeId'] : null;
    }
    if (isset($b['fidelityApp']) && is_array($b['fidelityApp'])) {
      $fa = $b['fidelityApp'];
      if (array_key_exists('active', $fa)) { $sets[] = 'fidelity_active=?'; $vals[] = $fa['active'] ? 1 : 0; }
      if (array_key_exists('linkedAt', $fa)) {
        $lv = $fa['linkedAt'] ?: null;
        if ($lv) { $ts = strtotime((string) $lv); $lv = $ts ? date('Y-m-d H:i:s', $ts) : null; }
        $sets[] = 'fidelity_linked_at=?'; $vals[] = $lv;
      }
    }
    if ($sets) { $vals[] = $id; q("UPDATE client SET " . implode(',', $sets) . " WHERE id=?", $vals); }
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
    // Géocoder un site de livraison (prêt-à-brancher : inactif tant que
    // ws_param('google_geocode_key') est vide — voir geocode_site()).
    if ($m === 'POST' && $p === '/admin/geocode-site') {
      $b = body();
      $sid = (int) ($b['siteId'] ?? 0);
      if (!$sid) json_out(['error' => 'siteId requis'], 400);
      json_out(geocode_site($sid));
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
      // Après l'unification des boutiques la remise vit dans shops.webshop_config (JSON).
      // Tant que `shops` n'existe pas, on retombe sur les colonnes legacy de ws_shops.
      $hasShops = row("SELECT 1 AS x FROM information_schema.tables
                        WHERE table_schema=DATABASE() AND table_name='shops'");
      if ($hasShops) {
        q("UPDATE shops SET webshop_config = JSON_SET(COALESCE(webshop_config, JSON_OBJECT()),
             '$.discount_type', ?, '$.discount_value', ?) WHERE id=?",
          [$type, (float) ($b['value'] ?? 0), $b['shopId'] ?? 0]);
      } else {
        q("UPDATE ws_shops SET webshop_discount_type=?, webshop_discount_value=? WHERE id=?",
          [$type, (float) ($b['value'] ?? 0), $b['shopId'] ?? 0]);
      }
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
/* ── Livraison bureau : éligibilité + cut-off (le SITE fait foi pour la tournée).
   Chaîne : user -> bureau (ws_offices) -> site (ws_office_delivery_sites) ->
   tournée (ws_tours). Toutes ces règles sont vérifiées SERVEUR à la commande. ── */

/* Bureaux (ws_offices.id) rattachés à un client : client.office_id, la liaison
   PWA (pwa_client_office -> site.office_client_id), et l'e-mail rattaché
   (ws_office_emails). Sert au contrôle d'appartenance d'un site. */
function user_office_ids($cid) {
  if (!$cid) return [];
  $ids = [];
  try { $r = row("SELECT office_id, email FROM client WHERE id=?", [$cid]);
        if ($r && $r['office_id']) $ids[] = (int) $r['office_id']; } catch (Throwable $e) { $r = null; }
  try {
    foreach (rows("SELECT s.office_client_id AS oid FROM pwa_client_office co
                     JOIN pwa_offices po ON po.id = co.office_id
                     JOIN ws_office_delivery_sites s ON s.id = CAST(po.office_ref AS UNSIGNED) AND s.id > 0
                    WHERE co.client_id = ?", [$cid]) as $x) if (!empty($x['oid'])) $ids[] = (int) $x['oid'];
  } catch (Throwable $e) {}
  try {
    if (!empty($r['email']))
      foreach (rows("SELECT office_id FROM ws_office_emails WHERE email=? AND active=1",
                    [strtolower(trim($r['email']))]) as $x) $ids[] = (int) $x['office_id'];
  } catch (Throwable $e) {}
  return array_values(array_unique(array_filter($ids)));
}

/* Une tournée est-elle commandable pour une date de livraison donnée ?
   - tournée active
   - pas de fermeture ponctuelle ce jour (ws_tour_closures)
   - roule ce jour de semaine (ws_tour_availability, au moins une fenêtre)
   - avant le cut-off (modèle JOUR DE LIVRAISON MÊME), en HEURE LOCALE boutique
     (ws_shops.timezone) — jamais une heure naïve serveur. */
function tour_orderable($tourId, $deliveryDate) {
  $t = row("SELECT shop_id, active FROM ws_tours WHERE id=?", [$tourId]);
  if (!$t || !$t['active']) return ['ok' => false, 'reason' => 'Tournée indisponible'];
  $shop = (int) $t['shop_id'];
  if (row("SELECT 1 AS x FROM ws_tour_closures WHERE tour_id=? AND closure_date=? LIMIT 1", [$tourId, $deliveryDate]))
    return ['ok' => false, 'reason' => 'Tournée fermée ce jour'];
  // Fuseau boutique : ws_shops.timezone si la colonne existe (capacité), sinon
  // Europe/Brussels — correct pour toutes les boutiques belges du réseau.
  $tzName = 'Europe/Brussels';
  if (col_exists('ws_shops', 'timezone')) {
    $tzr = row("SELECT timezone FROM ws_shops WHERE id=?", [$shop]);
    if ($tzr && !empty($tzr['timezone'])) $tzName = $tzr['timezone'];
  }
  try { $zone = new DateTimeZone($tzName); } catch (Throwable $e) { $zone = new DateTimeZone('Europe/Brussels'); }
  $now = new DateTime('now', $zone);
  $today = $now->format('Y-m-d');
  try { $dow = (int) (new DateTime($deliveryDate, $zone))->format('N'); }
  catch (Throwable $e) { return ['ok' => false, 'reason' => 'Date invalide']; }
  $wins = rows("SELECT TIME_FORMAT(cutoff_time,'%H:%i') AS cutoff FROM ws_tour_availability
                 WHERE tour_id=? AND shop_id=? AND delivery_day=? AND active=1 ORDER BY delivery_start",
               [$tourId, $shop, $dow]);
  if (!$wins) return ['ok' => false, 'reason' => 'Pas de tournée ce jour'];
  if ($deliveryDate < $today) return ['ok' => false, 'reason' => 'Date passée'];
  if ($deliveryDate === $today) {
    $nowHm = $now->format('H:i'); $open = false;
    foreach ($wins as $w) if ($nowHm < $w['cutoff']) { $open = true; break; }
    if (!$open) return ['ok' => false, 'reason' => 'Cut-off dépassé', 'cutoffs' => array_column($wins, 'cutoff')];
  }
  return ['ok' => true, 'cutoffs' => array_column($wins, 'cutoff')];
}

/* Contrôle complet d'un site de livraison pour une commande : existence/actif,
   rattaché à une tournée, appartenance au compte (si connecté), et commandable. */
function office_delivery_check($siteId, $deliveryDate, $cid) {
  if (!$siteId) return ['ok' => false, 'error' => 'Site de livraison requis'];
  $s = row("SELECT id, office_client_id, client_id, tournee_id, active FROM ws_office_delivery_sites WHERE id=?", [$siteId]);
  if (!$s || !$s['active'])      return ['ok' => false, 'error' => 'Site de livraison indisponible'];
  if (empty($s['tournee_id']))   return ['ok' => false, 'error' => 'Site non rattaché à une tournée'];
  // Bureau validé = ws_offices.active = 1 (0/1). Un site rattaché à un bureau
  // non validé n'est pas commandable, même si le site lui-même est actif.
  if ($s['office_client_id'] !== null) {
    $off = row("SELECT active FROM ws_offices WHERE id=?", [$s['office_client_id']]);
    if (!$off || !$off['active']) return ['ok' => false, 'error' => 'Bureau non validé'];
  }
  // Appartenance : un compte connecté ne peut commander sur un site rattaché à
  // un bureau QUE s'il appartient à ce bureau — ou si le site est le sien
  // (client_id). On NE fait jamais confiance à l'id passé : vérif en base à
  // chaque fois. Un compte sans aucun bureau ne « débloque » donc rien.
  if ($cid && $s['office_client_id'] !== null) {
    $ownsSite = ((int) ($s['client_id'] ?? 0) === (int) $cid);
    if (!$ownsSite && !in_array((int) $s['office_client_id'], user_office_ids($cid), true))
      return ['ok' => false, 'error' => 'Site non autorisé pour ce compte'];
  }
  $t = tour_orderable((int) $s['tournee_id'], $deliveryDate);
  return $t['ok'] ? ['ok' => true] : ['ok' => false, 'error' => $t['reason']];
}

/* Géocodage Google d'un site (prêt-à-brancher). INACTIF tant que
   ws_param.google_geocode_key n'est pas posé — un site sans géocodage reste
   livrable (juste sans point sur la carte) : ne bloque jamais une commande.
   À appeler à la CRÉATION/MODIFICATION de l'adresse d'un site (pas à l'affichage :
   l'API est payante et lente). Écrit lat/lng/place_id/adresse formatée + statut. */
function geocode_site($siteId) {
  $key = ws_param('google_geocode_key', '');
  if ($key === '') return ['ok' => false, 'reason' => 'no_key'];   // désactivé
  $s = row("SELECT id, address FROM ws_office_delivery_sites WHERE id=?", [$siteId]);
  if (!$s || empty($s['address'])) return ['ok' => false, 'reason' => 'no_address'];
  $url = 'https://maps.googleapis.com/maps/api/geocode/json?address='
       . urlencode($s['address']) . '&key=' . urlencode($key);
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
  $res = curl_exec($ch); curl_close($ch);
  $d = $res ? json_decode($res, true) : null;
  $status = 'failed'; $lat = null; $lng = null; $pid = null; $fmt = null;
  if (is_array($d) && ($d['status'] ?? '') === 'OK' && !empty($d['results'])) {
    $r0 = $d['results'][0];
    $lat = $r0['geometry']['location']['lat'] ?? null;
    $lng = $r0['geometry']['location']['lng'] ?? null;
    $pid = $r0['place_id'] ?? null;                        // survit aux changements de libellé
    $fmt = $r0['formatted_address'] ?? null;
    $status = (count($d['results']) > 1) ? 'ambiguous' : 'success';
  }
  q("UPDATE ws_office_delivery_sites
        SET latitude=?, longitude=?, google_place_id=?, google_formatted_address=?,
            geocoded_at=NOW(), geocode_status=?
      WHERE id=?", [$lat, $lng, $pid, $fmt, $status, $siteId]);
  return ['ok' => $status !== 'failed', 'status' => $status];
}

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
  // Table `client` unifiée. SELECT * + accès défensif : tolère les variantes de
  // noms de colonnes (name/first_name, locale/preferred_lang, is_b2b/is_business)
  // pendant la transition de schéma.
  $u = row("SELECT * FROM client WHERE id=?", [$id]);
  if (!$u) return null;
  // Bureau lié : client.office_id (canal WS) OU, en repli, la liaison faite
  // depuis la PWA (pwa_client_office → pwa_offices.office_ref = id du site de
  // livraison → ws_office_delivery_sites.office_client_id = l'entreprise). Un
  // bureau associé dans la PWA apparaît ainsi aussi dans le profil du webshop.
  $officeId = $u['office_id'] ?? null;
  if (!$officeId) {
    try {
      // Jointure NUMÉRIQUE (office_ref = id du site en texte) : une comparaison
      // de chaînes CAST(s.id AS CHAR) = office_ref casse selon la collation de
      // la connexion PDO (« illegal mix of collations », avalé par le catch).
      $r2 = row("SELECT s.office_client_id AS oid
                   FROM pwa_client_office co
                   JOIN pwa_offices po ON po.id = co.office_id
                   JOIN ws_office_delivery_sites s ON s.id = CAST(po.office_ref AS UNSIGNED) AND s.id > 0
                  WHERE co.client_id = ? LIMIT 1", [$u['id']]);
      if ($r2 && !empty($r2['oid'])) $officeId = $r2['oid'];
    } catch (Throwable $e) { /* tables legacy PWA absentes — repli ignoré */ }
  }
  if (!$officeId && !empty($u['email'])) {
    // Dernier repli : e-mail rattaché à une entreprise de livraison bureau
    // (ws_office_emails — même source que « commander pour une entreprise »).
    try {
      $r3 = row("SELECT e.office_id AS oid
                   FROM ws_office_emails e JOIN ws_offices o ON o.id = e.office_id
                  WHERE e.email = ? AND e.active = 1 AND o.active = 1
                  ORDER BY (o.status = 'validated') DESC LIMIT 1",
                [strtolower(trim($u['email']))]);
      if ($r3 && !empty($r3['oid'])) $officeId = $r3['oid'];
    } catch (Throwable $e) { /* table absente — repli ignoré */ }
  }
  // Site de livraison lié côté PWA : le « bureau » de la PWA est un site
  // ws_office_delivery_sites, parfois SANS entreprise ws_offices associée
  // (office_client_id NULL). Exposé tel quel pour que le profil webshop affiche
  // la même carte bureau que la PWA, même quand la chaîne vers ws_offices est
  // vide.
  $officeSite = null;
  try {
    $officeSite = row("SELECT s.id, s.name, s.address, s.shop_id AS shopId, s.active
                         FROM pwa_client_office co
                         JOIN pwa_offices po ON po.id = co.office_id
                         JOIN ws_office_delivery_sites s ON s.id = CAST(po.office_ref AS UNSIGNED) AND s.id > 0
                        WHERE co.client_id = ? LIMIT 1", [$u['id']]);
  } catch (Throwable $e) { /* tables legacy absentes */ }
  // Société liée (modèle « company link » de la PWA) : client.company_client_id
  // pointe vers une LIGNE client société (is_b2b=1) qui porte les vraies données
  // de facturation ; les colonnes société de la personne sont alors NULL. On
  // affiche la fiche société quand elle existe — même règle que client_normalize
  // côté PWA — sinon les colonnes de la personne (comptes non migrés).
  $comp = null;
  try {
    if (!empty($u['company_client_id'])) {
      $comp = row("SELECT company_name, tax_number, invoice_name, invoice_country, invoice_address,
                          invoice_postal_code, invoice_city, verified_at, peppol_verified
                     FROM client WHERE id = ? LIMIT 1", [(int) $u['company_client_id']]);
    }
  } catch (Throwable $e) { /* colonne company_client_id absente */ }
  return [
    'id' => (int) $u['id'],
    'email' => $u['email'] ?? null,
    'firstName' => $u['name'] ?? ($u['first_name'] ?? null),
    'lastName' => $u['surname'] ?? ($u['last_name'] ?? null),
    'phone' => $u['phone'] ?? null,
    'phonePrefix' => $u['phone_prefix'] ?? '+32',
    'phoneE164' => $u['phone_e164'] ?? null,
    'postalCode' => $u['zip'] ?? null,
    'authMethod' => $u['preferred_auth_method'] ?? null,
    'webshopUser' => (bool) ($u['webshop_user'] ?? 0),
    'pwaUser' => (bool) ($u['pwa_user'] ?? 0),
    'officeId' => $officeId,
    'officeSite' => $officeSite,
    'preferredShopId' => $u['preferred_shop_id'] ?? null,
    'lang' => $u['locale'] ?? ($u['preferred_lang'] ?? 'fr'),
    'isBusiness' => (bool) ($u['is_b2b'] ?? ($u['is_business'] ?? 0)),
    // Id de la fiche société liée : sert de billing_entity_id lors d'une
    // demande de facture (le serveur re-vérifie l'appartenance à l'écriture).
    'companyClientId' => isset($u['company_client_id']) && $u['company_client_id'] !== null
      ? (int) $u['company_client_id'] : null,
    // Facturation entreprise : fiche société liée (company_client_id) en
    // priorité, sinon les colonnes de la personne. Mêmes valeurs que le profil
    // PWA (repo_client_public / client_normalize).
    'company' => ($comp['company_name'] ?? $comp['invoice_name'] ?? null)
              ?? ($u['company_name'] ?? ($u['invoice_name'] ?? null)),
    'invoice' => [
      'country'    => ($comp['invoice_country'] ?? null) ?? ($u['invoice_country'] ?? 'BE'),
      'vat'        => ($comp['tax_number'] ?? null) ?? ($u['invoice_vat'] ?? ($u['tax_number'] ?? null)),
      'name'       => ($comp['invoice_name'] ?? $comp['company_name'] ?? null) ?? ($u['invoice_name'] ?? null),
      'address'    => ($comp['invoice_address'] ?? null) ?? ($u['invoice_address'] ?? null),
      'postalCode' => ($comp['invoice_postal_code'] ?? null) ?? ($u['invoice_postal_code'] ?? null),
      'city'       => ($comp['invoice_city'] ?? null) ?? ($u['invoice_city'] ?? null),
      // « Vérifié » = société liée & vérifiée (verified_at de la fiche société,
      // sinon celui de la personne) — même règle que la carte VIES de la PWA.
      'viesVerified' => !empty($comp['verified_at']) || !empty($u['verified_at']),
      'peppol'       => (bool) (($comp['peppol_verified'] ?? 0) ?: ($u['peppol_verified'] ?? 0)),
    ],
    'fidelityApp' => [
      'active'     => (bool) ($u['fidelity_active'] ?? 0),
      'linkedAt'   => $u['fidelity_linked_at'] ?? null,
      // Adresse du PWA (QR d'installation quand l'app fidélité n'est pas encore
      // liée). Source unique : ws_param.pwa_url ; repli sur la racine serveur.
      'installUrl' => pwa_url(),
    ],
  ];
}

/* Adresse du PWA (app fidélité). Source unique : la table de config `ws_param`,
 * clé `pwa_url`. La structure de ws_param n'étant pas figée, on tente les formes
 * clé/valeur les plus courantes ET une colonne dédiée, chacune isolée en
 * try/catch. Repli : la RACINE du serveur — le webshop vit sous /webshop/, donc
 * `<scheme>://<host>/` pointe sur le PWA. Toujours surchargeable via ws_param. */
/* Lecture d'un paramètre ws_param (clé/valeur). Repli silencieux sur $default. */
function ws_param($key, $default = null) {
  try {
    $r = row("SELECT param_value AS v FROM ws_param WHERE param_key = ? LIMIT 1", [$key]);
    if ($r && $r['v'] !== null && $r['v'] !== '') return (string) $r['v'];
  } catch (Throwable $e) { /* table absente */ }
  return $default;
}

/* Détection de colonne (information_schema, mémoïsée). Les fonctionnalités qui
 * dépendent d'une colonne non encore migrée (ex. pwa_purchases.to_invoice) se
 * désactivent proprement au lieu d'échouer — et s'activent dès que la colonne
 * est ajoutée en base, sans redéploiement. */
function col_exists($table, $col) {
  static $cache = [];
  $k = "$table.$col";
  if (!array_key_exists($k, $cache)) {
    try {
      $cache[$k] = (bool) row(
        "SELECT 1 AS ok FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
        [$table, $col]);
    } catch (Throwable $e) { $cache[$k] = false; }
  }
  return $cache[$k];
}

/* Date limite de demande de facture pour un ticket : dernier jour du mois du
 * ticket (le franchisé facture en fin de mois), ou N jours si ws_param
 * invoice_request_deadline est numérique. Jamais en dur dans les appels. */
function invoice_deadline($atStr, $mode) {
  $ts = strtotime((string) $atStr) ?: time();
  if (preg_match('/^\d+$/', (string) $mode)) return strtotime('+' . (int) $mode . ' days', $ts);
  return strtotime(date('Y-m-t 23:59:59', $ts)); // 'end_of_month' (défaut)
}

function pwa_url() {
  $tries = [
    "SELECT param_value AS v FROM ws_param WHERE param_key = 'pwa_url' LIMIT 1",
    "SELECT value       AS v FROM ws_param WHERE name      = 'pwa_url' LIMIT 1",
    "SELECT `value`     AS v FROM ws_param WHERE `key`     = 'pwa_url' LIMIT 1",
    "SELECT pwa_url     AS v FROM ws_param LIMIT 1",
  ];
  foreach ($tries as $sql) {
    try { $r = row($sql); if ($r && !empty($r['v'])) return (string) $r['v']; }
    catch (Throwable $e) { /* forme absente -> on tente la suivante */ }
  }
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  return $scheme . '://' . $host . '/';
}

/**
 * Normalise un numéro : préfixe international + numéro national.
 * Retourne [prefix('+32'), national('0470000002'), e164('+32470000002')].
 */
/* Validation TVA via le service VIES (REST UE). Public, sans état. Renvoie la
 * raison sociale + l'adresse découpée (rue / code postal / ville), au format
 * attendu par le formulaire de facturation. Miroir exact du PWA vies_lookup. */
function vies_lookup($rawVat) {
  $vat = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $rawVat));
  if (strlen($vat) < 4 || !ctype_alpha(substr($vat, 0, 2))) {
    return ['valid' => false, 'error' => ['code' => 'invalid', 'message' => 'N° TVA invalide.']];
  }
  $country = substr($vat, 0, 2);
  $number  = substr($vat, 2);
  $url = "https://ec.europa.eu/taxation_customs/vies/rest-api/ms/$country/vat/$number";
  $res = false; $http = 0;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $res  = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
  } else {
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => 'Accept: application/json']]);
    $res  = @file_get_contents($url, false, $ctx);
    $http = $res !== false ? 200 : 0;
  }
  if ($res === false || $http >= 500 || $http === 0) {
    return ['valid' => false, 'error' => ['code' => 'unavailable', 'message' => 'VIES indisponible. Veuillez réessayer.']];
  }
  $d = json_decode($res, true);
  if (!is_array($d)) {
    return ['valid' => false, 'error' => ['code' => 'unavailable', 'message' => 'VIES indisponible.']];
  }
  if (empty($d['valid']) && empty($d['isValid'])) {
    return ['valid' => false, 'error' => ['code' => 'invalid', 'message' => 'Ce numéro de TVA n’a pas été reconnu.']];
  }
  $name = isset($d['name'])    && $d['name']    !== '---' ? trim((string) $d['name'])    : null;
  $addr = isset($d['address']) && $d['address'] !== '---' ? trim((string) $d['address']) : null;
  // Découpe l'adresse multi-lignes en rue / code postal / ville (comme la PWA).
  $lines  = $addr !== null ? array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $addr)))) : [];
  $street = $lines[0] ?? $addr;
  $postal = null; $city = null;
  if (count($lines) >= 2 && preg_match('/^(\d{4,6})\s+(.+)$/', (string) end($lines), $mm)) {
    $postal = $mm[1]; $city = trim($mm[2]);
  }
  return ['valid' => true, 'data' => [
    'vat'     => $vat,   'country'    => $country, 'name' => $name,
    'address' => $street, 'postalCode' => $postal,  'city' => $city,
  ]];
}

function norm_phone($prefix, $raw) {
  $pfx = trim((string) $prefix) !== '' ? trim((string) $prefix) : '+32';
  if ($pfx[0] !== '+') $pfx = '+' . ltrim($pfx, '+');
  $pd = preg_replace('/[^0-9]/', '', $pfx);
  $d  = preg_replace('/[^0-9]/', '', (string) $raw);
  if ($d === '') return [$pfx, '', ''];
  if (strpos($d, '00' . $pd) === 0)                              $d = substr($d, 2 + strlen($pd));
  elseif (strpos($d, $pd) === 0 && strlen($d) > strlen($pd) + 6) $d = substr($d, strlen($pd));
  $d = ltrim($d, '0');
  if ($d === '') return [$pfx, '', ''];
  return [$pfx, '0' . $d, $pfx . $d];
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
