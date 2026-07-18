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

  /* ── Back-offices Franchise Buddy (sessions isolées franchisé / franchiseur) ──
     Tout « /bo/… » est routé par bo_dispatch() ; chaque route y est protégée par
     son guard require_bo(). Placé en tête pour ne jamais retomber sur une route
     publique. ── */
  if (strpos($p, '/bo/') === 0) { bo_dispatch($m, $p); json_out(['error' => 'Not found', 'path' => $p], 404); }

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
      // Nav catégories : icônes des deux touches de première position (Tout /
      // retour). Ce ne sont PAS des lignes de category — juste des références
      // média (même bibliothèque que les icônes de catégorie), changeables via
      // ws_param sans redéploiement.
      'categoryNavAllIcon'     => ws_param('category_nav_all_icon',  '/webshop/assets/all.png'),
      'categoryNavBackIcon'    => ws_param('category_nav_back_icon', '/webshop/assets/back.png'),
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

  /* ── Catalog ──
     SOURCE DES PRODUITS = ws_products (catalogue actif). ws_product_shops
     n'est PLUS un filtre obligatoire : une ligne d'assortiment sert seulement
     de métadonnée par boutique (no_delivery, ou exclusion EXPLICITE via
     active=0). Sans ligne, le produit est vendu tel quel. */
  if ($m === 'GET' && $p === '/catalog/categories') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    // N'expose une catégorie que si elle a >=1 produit actif du catalogue non
    // explicitement exclu de cette boutique.
    $cats = rows("SELECT c.id, c.slug, c.label, c.img, c.sort_order
                    FROM ws_categories c
                   WHERE c.active = 1 AND (c.shop_id = ? OR c.shop_id IS NULL)
                     AND EXISTS (SELECT 1 FROM ws_products p
                                   LEFT JOIN ws_product_shops ps ON ps.product_id = p.id AND ps.shop_id = ?
                                  WHERE p.cat_id = c.id AND p.active = 1
                                    AND (ps.product_id IS NULL OR ps.active = 1))
                   ORDER BY c.sort_order, c.label", [$s, $s]);
    // Rattache les sous-catégories (ws_category_subs) à chaque catégorie -> c.subs[]
    // (le front lit activeCat.subs pour la ligne de nav). Même règle : on
    // n'expose qu'une sous-catégorie qui a >=1 produit du catalogue ici.
    $subs = rows("SELECT sub.id, sub.category_id, sub.slug, sub.label, sub.img, sub.sort_order
                    FROM ws_category_subs sub
                    JOIN ws_categories c ON c.id = sub.category_id
                   WHERE sub.active = 1 AND (c.shop_id = ? OR c.shop_id IS NULL)
                     AND EXISTS (SELECT 1 FROM ws_products p
                                   LEFT JOIN ws_product_shops ps ON ps.product_id = p.id AND ps.shop_id = ?
                                  WHERE p.sub_cat_id = sub.id AND p.active = 1
                                    AND (ps.product_id IS NULL OR ps.active = 1))
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
                      p.portions, p.cross_portion,
                      -- Menu déclenché par la catégorie (menu_default), surchargé par
                      -- le produit (menu_override 'on'/'off'/NULL=hérite). has_menu_options
                      -- est RÉSOLU serveur : le front et /orders reçoivent la valeur finale.
                      COALESCE(
                        CASE p.menu_override WHEN 'on' THEN 1 WHEN 'off' THEN 0 END,
                        c.menu_default, 0
                      ) AS has_menu_options,
                      COALESCE(pp.price, p.price) AS price, ps.no_delivery,
                      (SELECT JSON_ARRAYAGG(allergen) FROM ws_product_allergens a WHERE a.product_id = p.id) AS allergens
                 FROM ws_products p
                 LEFT JOIN ws_product_shops ps ON ps.product_id = p.id AND ps.shop_id = ?
                 LEFT JOIN ws_product_prices pp ON pp.product_id = p.id AND pp.shop_id = ? AND pp.active = 1
                 LEFT JOIN ws_categories c ON c.id = p.cat_id
                 LEFT JOIN ws_tags t ON t.id = p.tag_id
                 LEFT JOIN ws_season se ON se.id = p.season_id
                WHERE p.active = 1 AND (ps.product_id IS NULL OR ps.active = 1)
                ORDER BY c.sort_order, p.name", [$s, $s]);
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
    // Résolution du produit porteur de la composition :
    //  - si le produit a SA propre formule -> on la sert (menu produit).
    //  - sinon, si son menu est armé par la CATÉGORIE (menu_default, non
    //    surchargé 'off'), on sert la composition « modèle » de la catégorie
    //    = la formule du produit de cette catégorie qui la porte. Ainsi TOUS
    //    les produits d'une catégorie déclencheur partagent LE MÊME menu ;
    //    l'étape 1 étant le produit choisi par le client (implicite).
    $srcPid = $pid;
    $own = row("SELECT 1 AS x FROM ws_bundles WHERE product_id = ? AND active = 1 LIMIT 1", [$pid]);
    if (!$own) {
      $meta = row("SELECT p.cat_id, p.menu_override, COALESCE(c.menu_default, 0) AS menu_default
                     FROM ws_products p
                     LEFT JOIN ws_categories c ON c.id = p.cat_id
                    WHERE p.id = ? LIMIT 1", [$pid]);
      $armed = $meta && $meta['cat_id'] !== null && (
        $meta['menu_override'] === 'on' ||
        ($meta['menu_override'] === null && (int) $meta['menu_default'] === 1)
      );
      if ($armed) {
        // Produit « modèle » de la catégorie : celui qui porte une formule.
        // On privilégie un produit explicitement menu ('on'), puis le plus
        // petit id — déterministe.
        $tpl = row("SELECT b.product_id
                      FROM ws_bundles b
                      JOIN ws_products tp ON tp.id = b.product_id AND tp.active = 1
                     WHERE b.active = 1 AND tp.cat_id = ?
                     ORDER BY (tp.menu_override = 'on') DESC, b.product_id
                     LIMIT 1", [$meta['cat_id']]);
        if ($tpl) $srcPid = $tpl['product_id'];
      }
    }
    $bundles = rows("SELECT id, name, description, price_modifier, sort_order
                       FROM ws_bundles WHERE product_id = ? AND active = 1
                      ORDER BY sort_order, id", [$srcPid]);
    foreach ($bundles as &$b) {
      $b['price_modifier'] = (float) $b['price_modifier'];
      $slots = rows("SELECT id, label, required, min_select, max_select, sort_order
                       FROM ws_bundle_slots WHERE bundle_id = ? AND active = 1
                      ORDER BY sort_order, id", [$b['id']]);
      foreach ($slots as &$sl) {
        $sl['required'] = (bool) $sl['required'];
        // Sélection : min/max (choisir 1 / jusqu'à N / au moins 1). Repli sûr si
        // NULL (anciennes lignes) : single obligatoire/facultatif d'après required.
        $sl['min_select'] = $sl['min_select'] !== null ? (int) $sl['min_select'] : ($sl['required'] ? 1 : 0);
        $sl['max_select'] = $sl['max_select'] !== null ? (int) $sl['max_select'] : 1;
        $sl['choices'] = rows("SELECT id, label, img, delta, sort_order
                                 FROM ws_bundle_slot_choices WHERE slot_id = ? AND active = 1
                                ORDER BY sort_order, id", [$sl['id']]);
        foreach ($sl['choices'] as &$ch) {
          $ch['delta'] = (float) $ch['delta'];
          // Vignette du choix = TOUJOURS l'image de la catégorie du produit
          // correspondant (ws_categories.img) — jamais une image produit ni le
          // repère de couleur du builder ('a'..'d'). Aucune correspondance /
          // catégorie sans image -> null (le front affiche le line-art).
          $cat = row("SELECT c.img FROM ws_products p
                        JOIN ws_categories c ON c.id = p.cat_id
                       WHERE p.name = ? AND c.img IS NOT NULL AND c.img <> ''
                       LIMIT 1", [$ch['label']]);
          $ch['img'] = ($cat && $cat['img']) ? $cat['img'] : null;
        }
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
    json_out(['ok' => true, 'discount' => $disc, 'freeDelivery' => ($v['type'] === 'free_delivery'),
              'voucher' => ['code' => $v['code'], 'type' => $v['type'], 'value' => (float) $v['value']], 'message' => 'Code appliqué']);
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
  // Une tournée par id — utilisé par WSTours.get au checkout (frais/libellés).
  if ($m === 'GET' && ($mm = $match('/tours/:id'))) {
    $t = row("SELECT id, shop_id AS shopId, name, zone_id AS zoneId, zone_secondary AS zoneSecondary, active
                FROM ws_tours WHERE id=?", [$mm['id']]);
    if (!$t) json_out(['error' => 'Tournée introuvable'], 404);
    // Libellé de fenêtre pour la carte bureau (front : `tour.name · tour.window`).
    $w = row("SELECT CONCAT(TIME_FORMAT(MIN(delivery_start),'%Hh%i'),'–',TIME_FORMAT(MAX(delivery_end),'%Hh%i')) AS win
                FROM ws_tour_availability WHERE tour_id=? AND active=1", [$mm['id']]);
    $t['window'] = ($w && !empty($w['win'])) ? $w['win'] : null;
    json_out($t);
  }
  // Bureau validé = active (0/1), source de vérité unique. Le front (compilé)
  // teste `office.status === 'validated'` ; on PROJETTE donc active en status
  // ('validated' si actif, sinon 'pending') — la colonne chaîne status de la
  // table (doublon) n'est plus lue.
  if ($m === 'GET' && $p === '/offices') {
    json_out(rows("SELECT id, tour_id AS tourId, name, address, postal_code AS postalCode, city,
                          contact, email, phone, vat, active,
                          IF(active=1,'validated','pending') AS status
                     FROM ws_offices WHERE active=1"));
  }
  if ($m === 'GET' && ($mm = $match('/offices/:id'))) {
    $o = row("SELECT id, tour_id AS tourId, name, address, postal_code AS postalCode, city,
                     contact, email, phone, vat, active,
                     IF(active=1,'validated','pending') AS status
                FROM ws_offices WHERE id=?", [$mm['id']]);
    if (!$o) json_out(['error' => 'Office introuvable'], 404);
    $o['sites'] = rows("SELECT id, name, address, floor_room AS floorRoom, shop_id AS shopId, is_default AS isDefault
                          FROM ws_office_delivery_sites WHERE office_client_id=? AND active=1", [$mm['id']]);
    $def = row("SELECT id FROM ws_office_delivery_sites
                 WHERE office_client_id=? AND active=1 AND is_default=1 LIMIT 1", [$mm['id']]);
    $o['defaultSiteId'] = $def ? (int) $def['id'] : null;
    json_out($o);
  }
  // Sites de livraison d'un bureau (validé) — alimente WSDeliveryFees.listSites
  // au checkout (le module WSDeliveryFees appelle tout en POST + body JSON).
  // Même 0/1 que l'éligibilité : bureau et site doivent être actifs.
  if ($m === 'POST' && $p === '/delivery-fees/sites') {
    $oc = (int) (body()['officeClientId'] ?? 0);
    if (!$oc || !row("SELECT 1 AS x FROM ws_offices WHERE id=? AND active=1", [$oc])) json_out([]);
    json_out(rows("SELECT id, office_client_id, name, address, floor_room, contact_name, contact_phone,
                          tournee_id, tournee_stop_id, shop_id, is_default, active
                     FROM ws_office_delivery_sites
                    WHERE office_client_id=? AND active=1
                    ORDER BY is_default DESC, name", [$oc]));
  }
  // Un site par id — WSDeliveryFees.getSite (POST /sites/:id, body vide).
  if ($m === 'POST' && ($mm = $match('/delivery-fees/sites/:id'))) {
    $s = row("SELECT id, office_client_id, name, address, floor_room, contact_name, contact_phone,
                     tournee_id, tournee_stop_id, shop_id, is_default, active
                FROM ws_office_delivery_sites WHERE id=? AND active=1", [$mm['id']]);
    json_out($s ?: null);
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

    // 3. Bon de réduction (lu via la vue ws_vouchers = modèle ERP, canal WS) — validé serveur.
    $voucherCode = null; $voucherDisc = 0; $voucherFreeDelivery = false;
    if (!empty($b['voucher'])) {
      $v = row("SELECT code, type, value, min_order FROM ws_vouchers
                 WHERE code=? AND active=1 AND (expires_at IS NULL OR expires_at>NOW())
                   AND (max_uses IS NULL OR used_count<max_uses) LIMIT 1", [strtoupper(trim($b['voucher']))]);
      $baseV = $subtotal - $promo - $webshopDisc;
      if ($v && $baseV >= (float) $v['min_order']) {
        $voucherCode = $v['code'];
        $voucherDisc = $v['type'] === 'percent' ? round($baseV * (float) $v['value']) / 100
                     : ($v['type'] === 'fixed' ? (float) $v['value'] : 0);
        // Bon « port offert » : pas de remise monétaire — on offre les frais de livraison (§4 ci-dessous).
        $voucherFreeDelivery = ($v['type'] === 'free_delivery');
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
        $isFree = !$fr['always_charge'] && ($fr['free_delivery'] || $voucherFreeDelivery || ($freeMin > 0 && $afterDisc >= $freeMin));
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
      // Usage du bon via le modèle ERP (ws_vouchers est désormais une vue non-inscriptible) :
      // incrément voucher_code.usage_count + redemption tracée (canal WS, idempotence request_key).
      if ($voucherCode) {
        $vref = row("SELECT vco.id AS code_id, vco.id_voucher_campaign AS campaign_id, vc.id_promotion AS promotion_id
                       FROM voucher_code vco JOIN voucher_campaign vc ON vc.id = vco.id_voucher_campaign
                      WHERE vco.code = ? LIMIT 1", [$voucherCode]);
        if ($vref) {
          q("UPDATE voucher_code SET usage_count = usage_count + 1 WHERE id = ?", [$vref['code_id']]);
          // id_shop est NOT NULL + FK -> franchisee_shop ; ws_shops.id = franchisee_shop.id.
          // On n'insère la redemption que si le shop existe côté ERP (sinon on ne bloque pas la commande).
          $fsOk = row("SELECT 1 AS x FROM franchisee_shop WHERE id = ? LIMIT 1", [$shop]);
          if ($fsOk) {
            q("INSERT INTO voucher_redemption
                 (id_voucher_code, id_voucher_campaign, id_promotion, id_transaction, id_shop,
                  id_customer, id_employee, discount_value, status, channel, request_key)
               VALUES (?,?,?, NULL, ?, ?, NULL, ?, 'CONFIRMED', 'WS', ?)",
              [$vref['code_id'], $vref['campaign_id'], $vref['promotion_id'], $shop,
               $b['customerId'] ?? null, $voucherDisc, 'WS-ORDER-'.$oid]);
          }
        }
      }
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

  /* ══════════════════════════════════════════════════════════════════════
     Console marque (franchisor) — lecture réseau. Toutes gardées admin.
     Renvoie exactement les shapes attendues par le back-office franchisor
     (window.BOServer.table(name)) : aucune adaptation côté front.
     ══════════════════════════════════════════════════════════════════════ */
  if (strpos($p, '/franchisor/') === 0) {
    require_admin();

    $eurk = function ($n) { return number_format(round($n / 1000)) . ' k€'; };
    $tblExists = function ($t) { return (bool) row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?", [$t]); };
    $hasOrders = $tblExists('ws_orders');
    // Source boutiques : table unifiée `shops` (webshop_enabled/active réels),
    // repli legacy `ws_shops`. $SHOPS est contrôlé (jamais une entrée client).
    $SHOPS = $tblExists('shops') ? 'shops' : 'ws_shops';

    // KPIs réseau — agrégés depuis ws_orders / shops quand dispo.
    if ($m === 'GET' && $p === '/franchisor/kpis') {
      $activeShops = (int) (row("SELECT COUNT(*) n FROM $SHOPS WHERE active=1")['n'] ?? 0);
      $totalShops  = (int) (row("SELECT COUNT(*) n FROM $SHOPS")['n'] ?? 0);
      $caMonth = $caCollect = $caDeliv = 0.0; $ordToday = 0; $caPrev = 0.0;
      if ($hasOrders) {
        $caMonth   = (float) (row("SELECT COALESCE(SUM(total),0) s FROM ws_orders WHERE created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')")['s'] ?? 0);
        $caPrev    = (float) (row("SELECT COALESCE(SUM(total),0) s FROM ws_orders WHERE created_at >= DATE_FORMAT(NOW() - INTERVAL 1 MONTH,'%Y-%m-01') AND created_at < DATE_FORMAT(NOW(),'%Y-%m-01')")['s'] ?? 0);
        $caCollect = (float) (row("SELECT COALESCE(SUM(total),0) s FROM ws_orders WHERE mode='collect'  AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')")['s'] ?? 0);
        $caDeliv   = (float) (row("SELECT COALESCE(SUM(total),0) s FROM ws_orders WHERE mode='delivery' AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')")['s'] ?? 0);
        $ordToday  = (int)   (row("SELECT COUNT(*) n FROM ws_orders WHERE DATE(created_at)=CURDATE()")['n'] ?? 0);
      }
      $pct = $caPrev > 0 ? round(($caMonth - $caPrev) / $caPrev * 100, 1) : 0;
      $up  = $pct >= 0;
      $adoption = $totalShops > 0 ? round(100 * $activeShops / $totalShops) : 0;
      json_out([
        ['label' => 'CA réseau (mois)',     'value' => $eurk($caMonth),   'valColor' => 'var(--color-text)',    'delta' => ($up ? '▲ +' : '▼ ') . str_replace('.', ',', (string) $pct) . ' %', 'deltaColor' => $up ? '#2d7a3e' : 'var(--color-primary)'],
        ['label' => 'CA boutique',          'value' => $eurk($caCollect), 'valColor' => 'var(--color-primary)', 'delta' => 'collecte', 'deltaColor' => 'var(--color-text-muted)'],
        ['label' => 'CA livraison bureau',  'value' => $eurk($caDeliv),   'valColor' => '#C87A3F',              'delta' => 'livraison', 'deltaColor' => 'var(--color-text-muted)'],
        ['label' => 'Boutiques actives',    'value' => $activeShops . ' / ' . $totalShops, 'valColor' => 'var(--color-text)', 'delta' => 'réseau', 'deltaColor' => 'var(--color-text-muted)'],
        ['label' => 'Commandes du jour',    'value' => (string) $ordToday, 'valColor' => 'var(--color-text)',   'delta' => "aujourd'hui", 'deltaColor' => 'var(--color-text-muted)'],
        ['label' => 'Adoption whitelist',   'value' => $adoption . ' %',   'valColor' => 'var(--color-text)',    'delta' => 'boutiques en ligne', 'deltaColor' => 'var(--color-text-muted)'],
      ]);
    }

    // Boutiques du réseau — identité + toggle Webshop RÉELS depuis `shops`.
    if ($m === 'GET' && $p === '/franchisor/shops') {
      // `contrat` (colonne shops ajoutée en 0004) éditable via l'écriture boutique ;
      // '—' tant que non défini. accent/webshop_enabled/active depuis shops.
      $shops = rows("SELECT id, name, city, accent, active, webshop_enabled, contrat FROM $SHOPS ORDER BY name");
      $out = [];
      foreach ($shops as $s) {
        $caShop = $caOffice = 0;
        if ($hasOrders) {
          $caShop   = (float) (row("SELECT COALESCE(SUM(total),0) s FROM ws_orders WHERE shop_id=? AND mode='collect'  AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')", [$s['id']])['s'] ?? 0);
          $caOffice = (float) (row("SELECT COALESCE(SUM(total),0) s FROM ws_orders WHERE shop_id=? AND mode='delivery' AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')", [$s['id']])['s'] ?? 0);
        }
        $out[] = [
          'id' => (string) $s['id'], 'nom' => $s['name'], 'ville' => $s['city'] ?: '—',
          'web' => (bool) $s['webshop_enabled'], 'contrat' => ($s['contrat'] ?? '') !== '' ? $s['contrat'] : '—', 'act' => (bool) $s['active'],
          'caShop' => $caShop, 'caOffice' => $caOffice,
          'adoption' => (bool) $s['webshop_enabled'] ? 100 : 0,
          'accent' => $s['accent'] ?: 'var(--color-primary)',
        ];
      }
      json_out($out);
    }

    // Catalogue — arbre catégories › produits (réel) avec gouvernance marque.
    if ($m === 'GET' && $p === '/franchisor/catalog') {
      $totalShops = (int) (row("SELECT COUNT(*) n FROM $SHOPS WHERE active=1")['n'] ?? 0);
      $hasPS = $tblExists('ws_product_shops');
      $cats = rows("SELECT id, label, img, COALESCE(menu_default,0) AS menu_default FROM ws_categories WHERE active=1 ORDER BY sort_order, label");
      $out = [];
      foreach ($cats as $c) {
        // Le franchisor gère l'assortiment : on renvoie AUSSI les produits inactifs
        // (le toggle « Webshop » = ws_products.active, il faut pouvoir les réactiver).
        $prods = rows("SELECT p.id, p.name AS nom, p.price AS prix, p.active,
                              COALESCE(p.brand_mandatory,0) AS bm, se.name AS saison
                         FROM ws_products p LEFT JOIN ws_season se ON se.id = p.season_id
                        WHERE p.cat_id = ? ORDER BY p.name", [$c['id']]);
        $rows2 = [];
        foreach ($prods as $p2) {
          // Adoption = % boutiques qui ne l'excluent PAS explicitement (ws_product_shops.active=0).
          $ad = 0;
          if ($totalShops > 0) {
            $excl = $hasPS ? (int) (row("SELECT COUNT(*) n FROM ws_product_shops WHERE product_id=? AND active=0", [$p2['id']])['n'] ?? 0) : 0;
            $ad = (int) round(100 * max(0, $totalShops - $excl) / $totalShops);
          }
          $rows2[] = [
            'id' => (string) $p2['id'], 'nom' => $p2['nom'], 'prix' => (float) $p2['prix'],
            'statut' => $p2['active'] ? 'Publié' : 'Brouillon',
            'bw' => (bool) $p2['active'], 'bm' => (bool) $p2['bm'],
            'ad' => $ad, 'saison' => $p2['saison'] ?: null,
          ];
        }
        if ($rows2) $out[] = ['id' => (int) $c['id'], 'cat' => $c['label'], 'img' => $c['img'] ?: null, 'prods' => $rows2];
      }
      json_out($out);
    }

    // Menus & formules — DB du menu builder (ws_products menu + ws_bundles→slots→choices).
    if ($m === 'GET' && $p === '/franchisor/menus') {
      $db = ['_categories' => new stdClass()];
      $cats = rows("SELECT label, COALESCE(menu_default,0) AS menu_default FROM ws_categories WHERE active=1");
      $catObj = [];
      foreach ($cats as $c) $catObj[$c['label']] = ['menu_default' => (int) $c['menu_default']];
      $db['_categories'] = $catObj ?: new stdClass();
      // Un « menu » = un produit qui a RÉELLEMENT une composition (ws_bundles).
      // Ni « catégorie armée » ni « toggle on sans formule » ne créent un menu :
      // sinon la liste se remplit de tous les produits à chaque refresh.
      $prods = rows("SELECT p.id, p.name, p.price, COALESCE(p.base_cost,0) AS base_cost,
                            p.menu_override, c.label AS category
                       FROM ws_products p
                       LEFT JOIN ws_categories c ON c.id = p.cat_id
                      WHERE p.active = 1
                        AND EXISTS (SELECT 1 FROM ws_bundles b WHERE b.product_id = p.id)
                      ORDER BY p.name");
      foreach ($prods as $p2) {
        $pid = (string) $p2['id'];
        $bundles = rows("SELECT id, name, description, price_modifier, sort_order, active
                           FROM ws_bundles WHERE product_id = ? ORDER BY sort_order, id", [$p2['id']]);
        foreach ($bundles as &$b) {
          $b['id'] = (string) $b['id'];
          $b['price_modifier'] = (float) $b['price_modifier'];
          $b['sort_order'] = (int) $b['sort_order'];
          $b['active'] = (bool) $b['active'];
          $slots = rows("SELECT id, label, required, COALESCE(kind,'single') AS kind,
                                COALESCE(min_select,1) AS min_select, COALESCE(max_select,1) AS max_select,
                                sort_order, active
                           FROM ws_bundle_slots WHERE bundle_id = ? ORDER BY sort_order, id", [$b['id']]);
          foreach ($slots as &$sl) {
            $sl['id'] = (string) $sl['id'];
            $sl['required'] = (bool) $sl['required'];
            $sl['min_select'] = (int) $sl['min_select'];
            $sl['max_select'] = (int) $sl['max_select'];
            $sl['sort_order'] = (int) $sl['sort_order'];
            $sl['active'] = (bool) $sl['active'];
            $chs = rows("SELECT id, label, img, delta, COALESCE(cost,0) AS cost, sort_order, active
                           FROM ws_bundle_slot_choices WHERE slot_id = ? ORDER BY sort_order, id", [$sl['id']]);
            foreach ($chs as &$ch) {
              $ch['id'] = (string) $ch['id'];
              $ch['img'] = $ch['img'] ?: '';
              $ch['delta'] = (float) $ch['delta'];
              $ch['cost'] = (float) $ch['cost'];
              $ch['sort_order'] = (int) $ch['sort_order'];
              $ch['active'] = (bool) $ch['active'];
            }
            unset($ch);
            $sl['choices'] = $chs;
          }
          unset($sl);
          $b['slots'] = $slots;
        }
        unset($b);
        $db[$pid] = [
          'productName'  => $p2['name'],
          'category'     => $p2['category'] ?: '',
          'menuOverride' => $p2['menu_override'] !== null ? $p2['menu_override'] : null,
          'basePrice'    => (float) $p2['price'],
          'baseCost'     => (float) $p2['base_cost'],
          'bundles'      => $bundles,
        ];
      }
      json_out($db);
    }

    // Bons marque (ws_vouchers, réseau = shop_id NULL).
    if ($m === 'GET' && $p === '/franchisor/vouchers') {
      $vs = rows("SELECT code, type, value, expires_at FROM ws_vouchers WHERE active=1 ORDER BY code");
      $out = [];
      foreach ($vs as $v) {
        $val = $v['type'] === 'percent' ? '−' . rtrim(rtrim((string) $v['value'], '0'), '.') . ' %'
             : ($v['type'] === 'fixed' ? '−' . rtrim(rtrim((string) $v['value'], '0'), '.') . ' €' : $v['type']);
        $out[] = ['code' => $v['code'], 'valeur' => $val, 'type' => $v['type'],
                  'validite' => $v['expires_at'] ? ('jusqu\'au ' . substr($v['expires_at'], 0, 10)) : 'permanent'];
      }
      json_out($out);
    }

    // Règles de prix réseau (ws_pricing_rules, shop_id NULL).
    if ($m === 'GET' && $p === '/franchisor/pricing-rules') {
      $rs = rows("SELECT rule_type, label, x, y, threshold FROM ws_pricing_rules WHERE active=1 AND shop_id IS NULL ORDER BY id");
      $out = [];
      foreach ($rs as $r) {
        $effet = $r['rule_type'] === 'cross_portion' ? ((int) $r['x'] . ' achetés → ' . (int) $r['y'] . ' offert(s)') : (string) ($r['threshold'] ?? '—');
        $out[] = ['nom' => $r['label'] ?: $r['rule_type'], 'cible' => $r['rule_type'], 'effet' => $effet];
      }
      json_out($out);
    }

    // Paramètres marque (ws_param clé/valeur).
    if ($m === 'GET' && $p === '/franchisor/params') {
      $ps = rows("SELECT param_key, param_value FROM ws_param ORDER BY param_key");
      $out = [];
      foreach ($ps as $x) $out[] = ['cle' => $x['param_key'], 'type' => 'text', 'val' => $x['param_value']];
      json_out($out);
    }

    // Modèles d'email (ws_email_templates : tpl_key/lang/subject, marque=1).
    if ($m === 'GET' && $p === '/franchisor/email-templates') {
      json_out(rows("SELECT tpl_key AS cle, lang AS langue, subject AS sujet
                       FROM ws_email_templates WHERE active=1 AND id_brand=1
                      ORDER BY tpl_key, lang"));
    }

    // Utilisateurs & rôles (bo_users + portée bo_user_shops).
    if ($m === 'GET' && $p === '/franchisor/users') {
      $us = rows("SELECT u.display_name AS nom, u.email, u.role, u.active,
                         CASE WHEN u.role='siege' THEN 'Réseau complet'
                              ELSE COALESCE((SELECT GROUP_CONCAT(sh.name SEPARATOR ', ')
                                               FROM bo_user_shops bus JOIN $SHOPS sh ON sh.id = bus.shop_id
                                              WHERE bus.user_id = u.id), '—') END AS portee
                    FROM bo_users u ORDER BY u.role='franchise', u.display_name");
      foreach ($us as &$u) {
        $u['role'] = $u['role'] === 'siege' ? 'Siège' : 'Franchise';
        $u['act'] = (bool) $u['active']; unset($u['active']);
      }
      unset($u);
      json_out($us);
    }

    // Journal d'audit (bo_audit + acteur bo_users + boutique ws_shops).
    if ($m === 'GET' && $p === '/franchisor/audit') {
      json_out(rows("SELECT DATE_FORMAT(a.created_at,'%d/%m %H:%i') AS ts,
                            COALESCE(u.display_name,'—') AS user,
                            a.action AS verb,
                            TRIM(CONCAT(COALESCE(a.entity,''), IF(a.entity_id IS NOT NULL, CONCAT(' #', a.entity_id), ''))) AS entity,
                            COALESCE(sh.name,'Réseau') AS shop
                       FROM bo_audit a
                       LEFT JOIN bo_users u ON u.id = a.user_id
                       LEFT JOIN $SHOPS sh ON sh.id = a.shop_id
                      ORDER BY a.created_at DESC LIMIT 50"));
    }

    /* ══════════ ÉCRITURES (persistées en DB + tracées dans bo_audit) ══════════
       Auth : admin_token pour l'instant (user_id NULL en audit) ; l'acteur réel
       sera renseigné quand l'auth SSO/bo_users sera branchée. */
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $audit = function ($action, $entity, $entityId = null, $shopId = null, $payload = null) use ($ip) {
      q("INSERT INTO bo_audit (user_id, action, entity, entity_id, shop_id, payload, ip)
         VALUES (NULL, ?, ?, ?, ?, ?, ?)",
        [$action, $entity, $entityId, $shopId, $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null, $ip]);
    };

    // Boutique : contrat / toggle Webshop / actif.
    if ($m === 'POST' && $p === '/franchisor/shop') {
      $b = body(); $id = (int) ($b['id'] ?? 0);
      if (!$id) json_out(['error' => 'id requis'], 400);
      $sets = []; $vals = [];
      if (array_key_exists('contrat', $b))         { $sets[] = 'contrat=?';         $vals[] = (string) $b['contrat']; }
      if (array_key_exists('webshop_enabled', $b)) { $sets[] = 'webshop_enabled=?'; $vals[] = !empty($b['webshop_enabled']) ? 1 : 0; }
      if (array_key_exists('active', $b))          { $sets[] = 'active=?';          $vals[] = !empty($b['active']) ? 1 : 0; }
      if (!$sets) json_out(['error' => 'rien à modifier'], 400);
      $vals[] = $id;
      q("UPDATE $SHOPS SET " . implode(', ', $sets) . " WHERE id=?", $vals);
      $audit('shop.update', 'shops', $id, $id, $b);
      json_out(['ok' => true]);
    }

    // Produit : flags de gouvernance marque + prix réf. + override menu.
    if ($m === 'POST' && $p === '/franchisor/product') {
      $b = body(); $id = (int) ($b['id'] ?? 0);
      if (!$id) json_out(['error' => 'id requis'], 400);
      $sets = []; $vals = [];
      if (array_key_exists('active', $b))          { $sets[] = 'active=?';          $vals[] = !empty($b['active']) ? 1 : 0; }  // « Webshop » = visibilité webshop réelle
      if (array_key_exists('brand_whitelist', $b)) { $sets[] = 'brand_whitelist=?'; $vals[] = !empty($b['brand_whitelist']) ? 1 : 0; }
      if (array_key_exists('brand_mandatory', $b)) { $sets[] = 'brand_mandatory=?'; $vals[] = !empty($b['brand_mandatory']) ? 1 : 0; }
      if (array_key_exists('price', $b))           { $sets[] = 'price=?';           $vals[] = (float) $b['price']; }
      if (array_key_exists('base_cost', $b))       { $sets[] = 'base_cost=?';       $vals[] = (float) $b['base_cost']; }
      if (array_key_exists('menu_override', $b))   { $sets[] = 'menu_override=?';    $vals[] = in_array($b['menu_override'], ['on','off'], true) ? $b['menu_override'] : null; }
      if (!$sets) json_out(['error' => 'rien à modifier'], 400);
      $vals[] = $id;
      q("UPDATE ws_products SET " . implode(', ', $sets) . " WHERE id=?", $vals);
      $audit('product.update', 'ws_products', $id, null, $b);
      json_out(['ok' => true]);
    }

    // Catégorie : menu par défaut (+ cascade optionnelle des flags aux produits).
    if ($m === 'POST' && $p === '/franchisor/category') {
      $b = body(); $id = (int) ($b['id'] ?? 0);
      if (!$id) json_out(['error' => 'id requis'], 400);
      if (array_key_exists('menu_default', $b)) q("UPDATE ws_categories SET menu_default=? WHERE id=?", [!empty($b['menu_default']) ? 1 : 0, $id]);
      if (array_key_exists('active', $b))          q("UPDATE ws_products SET active=? WHERE cat_id=?", [!empty($b['active']) ? 1 : 0, $id]);  // cascade « Webshop » catégorie
      if (array_key_exists('brand_whitelist', $b)) q("UPDATE ws_products SET brand_whitelist=? WHERE cat_id=?", [!empty($b['brand_whitelist']) ? 1 : 0, $id]);
      if (array_key_exists('brand_mandatory', $b)) q("UPDATE ws_products SET brand_mandatory=? WHERE cat_id=?", [!empty($b['brand_mandatory']) ? 1 : 0, $id]);
      $audit('category.update', 'ws_categories', $id, null, $b);
      json_out(['ok' => true]);
    }

    // Paramètre marque (ws_param).
    if ($m === 'POST' && $p === '/franchisor/param') {
      $b = body(); $cle = (string) ($b['cle'] ?? '');
      if ($cle === '') json_out(['error' => 'cle requise'], 400);
      q("INSERT INTO ws_param (param_key, param_value) VALUES (?,?)
         ON DUPLICATE KEY UPDATE param_value=VALUES(param_value)", [$cle, (string) ($b['val'] ?? '')]);
      $audit('param.update', 'ws_param', null, null, $b);
      json_out(['ok' => true]);
    }

    // Modèle d'email (upsert par tpl_key×lang×marque).
    if ($m === 'POST' && $p === '/franchisor/email-template') {
      $b = body();
      $k = (string) ($b['cle'] ?? ''); $lg = (string) ($b['langue'] ?? 'FR');
      if ($k === '') json_out(['error' => 'cle requise'], 400);
      q("INSERT INTO ws_email_templates (tpl_key, lang, subject, body_html, id_brand, active)
         VALUES (?,?,?,?,1,1)
         ON DUPLICATE KEY UPDATE subject=VALUES(subject), body_html=VALUES(body_html), active=1",
        [$k, $lg, (string) ($b['sujet'] ?? ''), (string) ($b['corps'] ?? '')]);
      $audit('email_template.upsert', 'ws_email_templates', null, null, ['cle' => $k, 'langue' => $lg]);
      json_out(['ok' => true]);
    }

    // Bon marque (ws_vouchers).
    if ($m === 'POST' && $p === '/franchisor/voucher') {
      $b = body(); $code = strtoupper(trim($b['code'] ?? ''));
      if ($code === '') json_out(['error' => 'code requis'], 400);
      // Upsert dans le modèle ERP (ws_vouchers est désormais une vue) : bon marque réseau
      // (SHARED, id_shop NULL), remise portée par promotion_order_discount, canal WS.
      $type    = in_array($b['type'] ?? 'percent', ['percent','fixed','free_delivery'], true) ? $b['type'] : 'percent';
      $kindMap = ['percent'=>'PERCENT','fixed'=>'FIXED','free_delivery'=>'FREE_DELIVERY'];
      $kind    = $kindMap[$type];
      $value   = $type === 'free_delivery' ? null : (float) ($b['value'] ?? 0);
      $minOrd  = (float) ($b['min_order'] ?? 0);
      $maxUses = isset($b['max_uses']) && $b['max_uses'] !== '' ? (int) $b['max_uses'] : null;
      $exp     = !empty($b['expires_at']) ? $b['expires_at'] : null;
      $active  = isset($b['active']) ? (!empty($b['active']) ? 1 : 0) : 1;
      $status  = $active ? 'ACTIVE' : 'DRAFT';
      $cstatus = $active ? 'ACTIVE' : 'DISABLED';
      $idBrand = (int) ($b['id_brand'] ?? 1);
      $pdo = db();
      $pdo->beginTransaction();
      try {
        $ex = row("SELECT vco.id AS code_id, vc.id AS campaign_id, vc.id_promotion AS promotion_id
                     FROM voucher_code vco JOIN voucher_campaign vc ON vc.id = vco.id_voucher_campaign
                    WHERE vco.code = ? LIMIT 1", [$code]);
        if ($ex) {
          q("UPDATE promotion SET status=?, valid_to=? WHERE id=?", [$status, $exp, $ex['promotion_id']]);
          q("UPDATE promotion_order_discount SET discount_kind=?, discount_value=?, min_order_amount=? WHERE id_promotion=?",
            [$kind, $value, $minOrd, $ex['promotion_id']]);
          q("UPDATE voucher_campaign SET valid_to=?, usage_limit_total=?, id_brand=? WHERE id=?",
            [$exp, $maxUses, $idBrand, $ex['campaign_id']]);
          q("UPDATE voucher_code SET status=?, valid_to=?, usage_limit=? WHERE id=?",
            [$cstatus, $exp, $maxUses, $ex['code_id']]);
          q("INSERT IGNORE INTO voucher_campaign_channel (id_voucher_campaign, channel) VALUES (?, 'WS')", [$ex['campaign_id']]);
        } else {
          q("INSERT INTO promotion (name, description, promotion_type, status, priority, is_exclusive,
                 valid_from, valid_to, is_repeatable, shop_scope_type, activation_mode, soft_delete)
             VALUES (?, 'Bon marque (franchisor)', 'ORDER_DISCOUNT', ?, 0, 0, NULL, ?, 0, 'ALL_SHOPS', 'VOUCHER_ONLY', 0)",
            ['Webshop — '.$code, $status, $exp]);
          $pid = $pdo->lastInsertId();
          q("INSERT INTO promotion_order_discount (id_promotion, discount_kind, discount_value, min_order_amount)
             VALUES (?,?,?,?)", [$pid, $kind, $value, $minOrd]);
          q("INSERT INTO voucher_campaign (id_promotion, name, planned_promotion_type, status, code_type,
                 valid_from, valid_to, usage_limit_total, usage_limit_per_code, usage_limit_per_customer,
                 requires_customer, id_brand, id_shop)
             VALUES (?,?, 'ORDER_DISCOUNT', ?, 'SHARED', NULL, ?, ?, NULL, NULL, 0, ?, NULL)",
            [$pid, 'Webshop — '.$code, $status, $exp, $maxUses, $idBrand]);
          $cid = $pdo->lastInsertId();
          q("INSERT INTO voucher_code (id_voucher_campaign, code, status, valid_from, valid_to, usage_limit, usage_count)
             VALUES (?,?,?, NULL, ?, ?, 0)", [$cid, $code, $cstatus, $exp, $maxUses]);
          q("INSERT INTO voucher_campaign_channel (id_voucher_campaign, channel) VALUES (?, 'WS')", [$cid]);
        }
        $pdo->commit();
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
      }
      $audit('voucher.upsert', 'voucher_code', null, null, ['code' => $code]);
      json_out(['ok' => true]);
    }

    // Utilisateur back-office — INVITATION (password_hash '' = à définir).
    if ($m === 'POST' && $p === '/franchisor/user') {
      $b = body(); $email = strtolower(trim($b['email'] ?? ''));
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'email invalide'], 400);
      $role = (($b['role'] ?? '') === 'Siège' || ($b['role'] ?? '') === 'siege') ? 'siege' : 'franchise';
      $active = isset($b['active']) ? (!empty($b['active']) ? 1 : 0) : 1;
      q("INSERT INTO bo_users (email, password_hash, display_name, role, active)
         VALUES (?, '', ?, ?, ?)
         ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), role=VALUES(role), active=VALUES(active)",
        [$email, (string) ($b['nom'] ?? ''), $role, $active]);
      $uid = (int) db()->lastInsertId();
      $audit('user.invite', 'bo_users', $uid ?: null, null, ['email' => $email, 'role' => $role]);
      json_out(['ok' => true, 'invite' => true]);
    }

    // Menu builder — remplace transactionnellement TOUT l'arbre d'un produit
    // (ws_bundles → slots → choices) + champs menu du produit. Évite la désync
    // d'ids : le front édite en local, on réécrit l'arbre en base à chaque save.
    if ($m === 'POST' && $p === '/franchisor/menu') {
      $b = body(); $pid = (int) ($b['productId'] ?? 0);
      if (!$pid) json_out(['error' => 'productId requis'], 400);
      $ov = isset($b['menuOverride']) && in_array($b['menuOverride'], ['on','off'], true) ? $b['menuOverride'] : null;
      $bundles = is_array($b['bundles'] ?? null) ? $b['bundles'] : [];
      $pdo = db();
      $pdo->beginTransaction();
      try {
        // Prix de base du menu = ws_products.price (éditable). base_cost + override menu.
        if (array_key_exists('basePrice', $b)) {
          q("UPDATE ws_products SET menu_override=?, base_cost=?, price=? WHERE id=?", [$ov, (float) ($b['baseCost'] ?? 0), (float) $b['basePrice'], $pid]);
        } else {
          q("UPDATE ws_products SET menu_override=?, base_cost=? WHERE id=?", [$ov, (float) ($b['baseCost'] ?? 0), $pid]);
        }
        q("DELETE c FROM ws_bundle_slot_choices c JOIN ws_bundle_slots s ON s.id=c.slot_id JOIN ws_bundles bu ON bu.id=s.bundle_id WHERE bu.product_id=?", [$pid]);
        q("DELETE s FROM ws_bundle_slots s JOIN ws_bundles bu ON bu.id=s.bundle_id WHERE bu.product_id=?", [$pid]);
        q("DELETE FROM ws_bundles WHERE product_id=?", [$pid]);
        foreach ($bundles as $bi => $bu) {
          q("INSERT INTO ws_bundles (product_id, name, description, price_modifier, sort_order, active) VALUES (?,?,?,?,?,?)",
            [$pid, (string) ($bu['name'] ?? ''), (string) ($bu['description'] ?? ''), (float) ($bu['price_modifier'] ?? 0), $bi, !empty($bu['active']) ? 1 : 0]);
          $bid = (int) $pdo->lastInsertId();
          foreach (is_array($bu['slots'] ?? null) ? $bu['slots'] : [] as $si => $sl) {
            $kind = in_array($sl['kind'] ?? 'single', ['single','multi'], true) ? $sl['kind'] : 'single';
            q("INSERT INTO ws_bundle_slots (bundle_id, label, required, kind, min_select, max_select, sort_order, active) VALUES (?,?,?,?,?,?,?,?)",
              [$bid, (string) ($sl['label'] ?? ''), !empty($sl['required']) ? 1 : 0, $kind,
               (int) ($sl['min_select'] ?? ($kind === 'single' ? 1 : 0)), (int) ($sl['max_select'] ?? 1), $si, !empty($sl['active']) ? 1 : 0]);
            $sid = (int) $pdo->lastInsertId();
            foreach (is_array($sl['choices'] ?? null) ? $sl['choices'] : [] as $ci => $ch) {
              q("INSERT INTO ws_bundle_slot_choices (slot_id, label, img, delta, cost, sort_order, active) VALUES (?,?,?,?,?,?,?)",
                [$sid, (string) ($ch['label'] ?? ''), (string) ($ch['img'] ?? ''), (float) ($ch['delta'] ?? 0), (float) ($ch['cost'] ?? 0), $ci, !empty($ch['active']) ? 1 : 0]);
            }
          }
        }
        $pdo->commit();
      } catch (Throwable $e) { $pdo->rollBack(); json_out(['error' => 'échec sauvegarde menu'], 500); }
      $audit('menu.save', 'ws_bundles', $pid, null, ['bundles' => count($bundles)]);
      json_out(['ok' => true]);
    }

    json_out(['error' => 'Not found', 'path' => $p], 404);
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

    /* ═══ MENU BUILDER — déclencheur (b) + formules ═══
       Déclencheur : catégorie menu_default, produit menu_override.
       Contenu : ws_bundles -> ws_bundle_slots -> ws_bundle_slot_choices.
       On NE fait jamais confiance à un id passé : chaque écriture enfant
       re-vérifie en base l'appartenance à son parent. */

    // Déclencheur catégorie : menu_default (0/1)
    if ($m === 'POST' && $p === '/admin/category-menu') {
      $b = body(); $cid = (int) ($b['categoryId'] ?? 0);
      if (!$cid || !row("SELECT 1 AS x FROM ws_categories WHERE id=?", [$cid])) json_out(['error' => 'Catégorie introuvable'], 404);
      q("UPDATE ws_categories SET menu_default=? WHERE id=?", [!empty($b['menuDefault']) ? 1 : 0, $cid]);
      json_out(['ok' => true, 'categoryId' => $cid, 'menuDefault' => !empty($b['menuDefault'])]);
    }
    // Override produit : 'on' | 'off' | null (= hérite)
    if ($m === 'POST' && $p === '/admin/product-menu') {
      $b = body(); $pid = (int) ($b['productId'] ?? 0);
      if (!$pid || !row("SELECT 1 AS x FROM ws_products WHERE id=?", [$pid])) json_out(['error' => 'Produit introuvable'], 404);
      $ov = $b['menuOverride'] ?? null;
      if (!in_array($ov, ['on', 'off', null], true)) json_out(['error' => "menuOverride doit être 'on', 'off' ou null"], 400);
      q("UPDATE ws_products SET menu_override=? WHERE id=?", [$ov, $pid]);
      json_out(['ok' => true, 'productId' => $pid, 'menuOverride' => $ov]);
    }

    // Arbre complet d'un produit (INACTIFS INCLUS — édition)
    if ($m === 'GET' && $p === '/admin/bundles') {
      $pid = (int) (qp('productId') ?: 0);
      if (!$pid) json_out(['error' => 'productId requis'], 400);
      $bundles = rows("SELECT id, name, description, price_modifier, sort_order, active
                         FROM ws_bundles WHERE product_id=? ORDER BY sort_order, id", [$pid]);
      foreach ($bundles as &$bd) {
        $bd['price_modifier'] = (float) $bd['price_modifier'];
        $bd['active'] = (bool) $bd['active'];
        $slots = rows("SELECT id, label, required, min_select, max_select, sort_order, active
                         FROM ws_bundle_slots WHERE bundle_id=? ORDER BY sort_order, id", [$bd['id']]);
        foreach ($slots as &$sl) {
          $sl['required'] = (bool) $sl['required'];
          $sl['min_select'] = $sl['min_select'] !== null ? (int) $sl['min_select'] : ($sl['required'] ? 1 : 0);
          $sl['max_select'] = $sl['max_select'] !== null ? (int) $sl['max_select'] : 1;
          $sl['active'] = (bool) $sl['active'];
          $sl['choices'] = rows("SELECT id, label, img, delta, sort_order, active
                                   FROM ws_bundle_slot_choices WHERE slot_id=? ORDER BY sort_order, id", [$sl['id']]);
          foreach ($sl['choices'] as &$ch) { $ch['delta'] = (float) $ch['delta']; $ch['active'] = (bool) $ch['active']; }
          unset($ch);
        }
        unset($sl);
        $bd['slots'] = $slots;
      }
      unset($bd);
      json_out($bundles);
    }

    // Upsert formule (ws_bundles) — rattachée à un produit vérifié
    if ($m === 'POST' && $p === '/admin/bundles') {
      $b = body(); $pid = (int) ($b['productId'] ?? 0);
      if (!$pid || !row("SELECT 1 AS x FROM ws_products WHERE id=?", [$pid])) json_out(['error' => 'Produit introuvable'], 404);
      $name = trim((string) ($b['name'] ?? ''));
      $pm = (float) ($b['priceModifier'] ?? 0);
      $so = (int) ($b['sortOrder'] ?? 0);
      $act = array_key_exists('active', $b) ? (!empty($b['active']) ? 1 : 0) : 1;
      if (!empty($b['id'])) {
        // Modif : l'id doit appartenir à CE produit (jamais confiance à l'id passé).
        $ex = row("SELECT id FROM ws_bundles WHERE id=? AND product_id=?", [(int) $b['id'], $pid]);
        if (!$ex) json_out(['error' => 'Formule non rattachée à ce produit'], 404);
        q("UPDATE ws_bundles SET name=?, description=?, price_modifier=?, sort_order=?, active=? WHERE id=?",
          [$name, $b['description'] ?? null, $pm, $so, $act, (int) $b['id']]);
        json_out(['ok' => true, 'id' => (int) $b['id']]);
      }
      if ($name === '') json_out(['error' => 'name requis'], 400);
      q("INSERT INTO ws_bundles (product_id, name, description, price_modifier, sort_order, active) VALUES (?,?,?,?,?,?)",
        [$pid, $name, $b['description'] ?? null, $pm, $so, $act]);
      json_out(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }

    // Upsert étape (ws_bundle_slots) — rattachée à une formule vérifiée
    if ($m === 'POST' && $p === '/admin/bundle-slots') {
      $b = body(); $bid = (int) ($b['bundleId'] ?? 0);
      if (!$bid || !row("SELECT 1 AS x FROM ws_bundles WHERE id=?", [$bid])) json_out(['error' => 'Formule introuvable'], 404);
      $label = trim((string) ($b['label'] ?? ''));
      $req = !empty($b['required']) ? 1 : 0;
      // min/max cohérents : max>=1, min>=0, min<=max, required => min>=1.
      $max = max(1, (int) ($b['maxSelect'] ?? 1));
      $min = max(0, (int) ($b['minSelect'] ?? ($req ? 1 : 0)));
      if ($min > $max) $min = $max;
      if ($req && $min < 1) $min = 1;
      $so = (int) ($b['sortOrder'] ?? 0);
      $act = array_key_exists('active', $b) ? (!empty($b['active']) ? 1 : 0) : 1;
      if (!empty($b['id'])) {
        $ex = row("SELECT id FROM ws_bundle_slots WHERE id=? AND bundle_id=?", [(int) $b['id'], $bid]);
        if (!$ex) json_out(['error' => 'Étape non rattachée à cette formule'], 404);
        q("UPDATE ws_bundle_slots SET label=?, required=?, min_select=?, max_select=?, sort_order=?, active=? WHERE id=?",
          [$label, $req, $min, $max, $so, $act, (int) $b['id']]);
        json_out(['ok' => true, 'id' => (int) $b['id']]);
      }
      if ($label === '') json_out(['error' => 'label requis'], 400);
      q("INSERT INTO ws_bundle_slots (bundle_id, label, required, min_select, max_select, sort_order, active) VALUES (?,?,?,?,?,?,?)",
        [$bid, $label, $req, $min, $max, $so, $act]);
      json_out(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }

    // Upsert choix (ws_bundle_slot_choices) — rattaché à une étape vérifiée
    if ($m === 'POST' && $p === '/admin/bundle-choices') {
      $b = body(); $sid = (int) ($b['slotId'] ?? 0);
      if (!$sid || !row("SELECT 1 AS x FROM ws_bundle_slots WHERE id=?", [$sid])) json_out(['error' => 'Étape introuvable'], 404);
      $label = trim((string) ($b['label'] ?? ''));
      $delta = (float) ($b['delta'] ?? 0);
      $so = (int) ($b['sortOrder'] ?? 0);
      $act = array_key_exists('active', $b) ? (!empty($b['active']) ? 1 : 0) : 1;
      if (!empty($b['id'])) {
        $ex = row("SELECT id FROM ws_bundle_slot_choices WHERE id=? AND slot_id=?", [(int) $b['id'], $sid]);
        if (!$ex) json_out(['error' => 'Choix non rattaché à cette étape'], 404);
        q("UPDATE ws_bundle_slot_choices SET label=?, img=?, delta=?, sort_order=?, active=? WHERE id=?",
          [$label, $b['img'] ?? null, $delta, $so, $act, (int) $b['id']]);
        json_out(['ok' => true, 'id' => (int) $b['id']]);
      }
      if ($label === '') json_out(['error' => 'label requis'], 400);
      q("INSERT INTO ws_bundle_slot_choices (slot_id, label, img, delta, sort_order, active) VALUES (?,?,?,?,?,?)",
        [$sid, $label, $b['img'] ?? null, $delta, $so, $act]);
      json_out(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }

    // Réordonnancement générique (batch) : entité + [{id, sortOrder}] — chaque id
    // re-vérifié dans sa table (jamais confiance à l'id passé).
    if ($m === 'POST' && $p === '/admin/bundle-reorder') {
      $b = body();
      $map = ['bundle' => 'ws_bundles', 'slot' => 'ws_bundle_slots', 'choice' => 'ws_bundle_slot_choices'];
      $ent = $b['entity'] ?? '';
      if (!isset($map[$ent])) json_out(['error' => "entity doit être bundle|slot|choice"], 400);
      $tbl = $map[$ent]; $n = 0;
      foreach (($b['order'] ?? []) as $it) {
        $id = (int) ($it['id'] ?? 0); if (!$id) continue;
        q("UPDATE $tbl SET sort_order=? WHERE id=?", [(int) ($it['sortOrder'] ?? 0), $id]); $n++;
      }
      json_out(['ok' => true, 'updated' => $n]);
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
      // Après l'unification, la remise vit sur shops (colonnes à plat discount_type/value
      // dans le schéma prod). Tant que `shops` n'existe pas, on retombe sur ws_shops.
      $hasShops = row("SELECT 1 AS x FROM information_schema.tables
                        WHERE table_schema=DATABASE() AND table_name='shops'");
      if ($hasShops) {
        q("UPDATE shops SET discount_type=?, discount_value=? WHERE id=?",
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
