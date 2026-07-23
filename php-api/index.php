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

/* Photos produits présentes sur le disque (id -> URL), servies sous
   /webshop/assets/product_pictures/. Scandir une seule fois par requête : permet
   de résoudre l'image d'un produit PAR CONVENTION ({id}.png|jpg) sans dépendre de
   ws_products.img — toute photo déposée (git ou SFTP) apparaît automatiquement. */
function product_photo_files() {
  static $map = null;
  if ($map !== null) return $map;
  $map = [];
  $dir = __DIR__ . '/../assets/product_pictures';
  if (is_dir($dir)) {
    foreach (scandir($dir) ?: [] as $f) {
      if (preg_match('/^(\d+)\.(png|jpe?g|webp)$/i', $f, $mm)) $map[$mm[1]] = 'assets/product_pictures/' . $f;
    }
  }
  return $map;
}

/* Prix de vente magasin (source de vérité = ERP). La table ERP `shop_product`
 * (même base atelierby_db) porte le prix par boutique : `portion_price` pour un
 * couple (id_shop, id_product). Clés de liaison : id_product = ws_products.id et
 * id_shop = ws_shops.id (= id boutique Franchise Buddy). C'est le prix RÉELLEMENT
 * pratiqué en magasin ; il fait autorité sur le prix répliqué côté ws_
 * (ws_product_prices / ws_products.price).
 *
 * Renvoie une map [id_product => portion_price(float)] pour la boutique donnée,
 * restreinte aux ids demandés. DÉFENSIF : on sonde une fois la présence de
 * shop_product.portion_price ; si la table/colonne est absente (environnement
 * sans l'ERP), ou si la boutique ne correspond à rien, on renvoie [] → l'appelant
 * garde son repli ws_. Aucun prix erroné, jamais de catalogue/commande cassés.
 *
 * shop_product n'a pas d'unicité (id_shop, id_product) → on retient une ligne
 * déterministe (plus petit `id`) par produit. */
function erp_shop_prices($shopId, array $ids) {
  static $ok = null;
  if (!$ids) return [];
  if ($ok === null) {
    $ok = false;
    try {
      $c = row("SELECT COUNT(*) n FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'shop_product' AND column_name = 'portion_price'");
      $ok = $c && (int) $c['n'] > 0;
    } catch (Throwable $e) {
      $ok = false;
    }
  }
  if (!$ok) return [];
  $in = implode(',', array_map('intval', $ids));
  $out = [];
  try {
    $rows = rows("SELECT id_product AS pid, portion_price
                    FROM shop_product
                   WHERE id_shop = ? AND id_product IN ($in)
                   ORDER BY id_product, id", [$shopId]);
    foreach ($rows as $r) {
      $pid = (int) $r['pid'];
      if (!array_key_exists($pid, $out)) $out[$pid] = (float) $r['portion_price'];
    }
  } catch (Throwable $e) {
    error_log('[ws] prix magasin ERP indisponible: ' . $e->getMessage());
    return [];
  }
  return $out;
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
                          discount_type AS webshop_discount_type, discount_value AS webshop_discount_value,
                          TRIM(CONCAT_WS(' ', street, street_num)) AS address
                     FROM shops WHERE active = 1 AND webshop_enabled = 1 ORDER BY name"));
  }
  if ($m === 'GET' && $p === '/brand') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    json_out(row("SELECT id, slug, name, accent, tint, logo_url,
                         discount_type AS webshop_discount_type, discount_value AS webshop_discount_value
                    FROM shops WHERE id = ? AND webshop_enabled = 1", [$s]) ?: []);
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
                       FROM client c JOIN shops w ON w.id = c.preferred_shop_id AND w.webshop_enabled = 1
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
    // Filtre livraison bureau PARTAGÉ (source unique) : en mode 'delivery'/'office',
    // on EXCLUT serveur-side les produits non éligibles au canal bureau
    // (office_delivery=0), pour que TOUT front (webshop online, webshop après
    // handoff PWA, PWA, …) reçoive exactement la même liste — sans dépendre d'un
    // filtrage client ni d'un état résiduel. Sans mode → liste complète (les flags
    // office_delivery/no_delivery restent exposés pour l'UI).
    $mode = strtolower((string) (qp('mode') ?: ''));
    $deliveryWhere = in_array($mode, ['delivery', 'office', 'apricot'], true)
      ? ' AND COALESCE(p.office_delivery,1) = 1' : '';
    // `badge` (texte) a été migré en FK tag_id -> ws_tags ; on expose le libellé
    // du tag sous la clé `badge` (rétro-compat UI) + couleurs, et la saison.
    $r = rows("SELECT p.id, p.cat_id, p.sub_cat_id,
                      p.cat_id AS cat, p.sub_cat_id AS subCat, c.label AS category,
                      p.name, p.description, p.img,
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
                      COALESCE(p.office_delivery,1) AS office_delivery,
                      (SELECT JSON_ARRAYAGG(allergen) FROM ws_product_allergens a WHERE a.product_id = p.id) AS allergens
                 FROM ws_products p
                 LEFT JOIN ws_product_shops ps ON ps.product_id = p.id AND ps.shop_id = ?
                 LEFT JOIN ws_product_prices pp ON pp.product_id = p.id AND pp.shop_id = ? AND pp.active = 1
                 LEFT JOIN ws_categories c ON c.id = p.cat_id
                 LEFT JOIN ws_tags t ON t.id = p.tag_id
                 LEFT JOIN ws_season se ON se.id = p.season_id
                WHERE p.active = 1 AND (ps.product_id IS NULL OR ps.active = 1)$deliveryWhere
                ORDER BY c.sort_order, p.name", [$s, $s]);
    $photos = product_photo_files();
    foreach ($r as &$x) {
      // Image produit : la photo déposée (assets/product_pictures/{id}.png|jpg) FAIT
      // AUTORITÉ si le fichier existe (c'est la vraie photo produit, uploadée
      // exprès) ; sinon on retombe sur ws_products.img (legacy) ; sinon null (le
      // front affiche l'illustration line-art de repli).
      $x['img'] = $photos[$x['id']] ?? ($x['img'] ?: null);
      $x['portions'] = (bool) $x['portions'];
      $x['cross_portion'] = (bool) $x['cross_portion'];
      $x['has_menu_options'] = (bool) $x['has_menu_options'];
      $x['no_delivery'] = (bool) $x['no_delivery'];
      // Canal livraison bureau (« apricot ») : disponibilité produit dédiée,
      // indépendante de la visibilité webshop. Le front bloque le produit en
      // mode livraison quand c'est faux (cf. no_delivery).
      $x['office_delivery'] = (bool) $x['office_delivery'];
      $x['price'] = (float) $x['price'];
      $x['allergens'] = $x['allergens'] ? json_decode($x['allergens']) : [];
    }
    unset($x);
    // Allergènes RÉELS (source de vérité = ERP, même base atelierby_db) : dérivés du
    // modèle recette → matières → allergènes. Clé de liaison : ws_products.id =
    // product.id (produits semés depuis l'ERP avec le même identifiant). Calcul en
    // direct ici (JOIN live) ; la ligne ws_product_allergens décodée ci-dessus sert
    // de REPLI si le modèle ERP est indisponible → aucun 500 catalogue.
    // LEFT JOIN (comme la requête ERP de référence) : un produit présent dans
    // `product` mais sans allergène renvoie codes=NULL → liste vide FAISANT AUTORITÉ ;
    // un id absent de `product` (pas de correspondance ERP) conserve le repli ws_.
    if ($r) {
      $ids = array_map(static fn($p2) => (int) $p2['id'], $r);
      $in = implode(',', $ids);
      try {
        $erp = rows("SELECT erp.id AS pid, GROUP_CONCAT(DISTINCT al.code) AS codes
                       FROM product erp
                       LEFT JOIN flattened_recipe_ingredient fri ON fri.id_recipe = erp.id_recipe
                       LEFT JOIN material_allergen_connection mac ON mac.id_material = fri.id_material
                       LEFT JOIN allergen al ON al.id = mac.id_allergen
                      WHERE erp.id IN ($in)
                      GROUP BY erp.id");
        $byId = [];
        foreach ($erp as $a) {
          $byId[(int) $a['pid']] = $a['codes'] === null ? []
            : array_values(array_filter(array_map('trim', explode(',', (string) $a['codes'])), 'strlen'));
        }
        foreach ($r as &$x) {
          if (array_key_exists((int) $x['id'], $byId)) $x['allergens'] = $byId[(int) $x['id']];
        }
        unset($x);
      } catch (Throwable $e) {
        // Modèle allergènes ERP indisponible (table absente, id_recipe manquant…) :
        // on garde le repli ws_product_allergens. Le catalogue n'est jamais cassé.
        error_log('[ws] allergènes ERP indisponibles: ' . $e->getMessage());
      }
    }
    // Prix magasin RÉEL (ERP shop_product.portion_price par boutique) : fait
    // autorité sur COALESCE(ws_product_prices, ws_products.price). Le prix facturé
    // par /orders est aligné sur la MÊME source (cf. erp_shop_prices). Repli ws_
    // si la boutique n'a pas de ligne shop_product (ou ERP indisponible).
    if ($r) {
      $store = erp_shop_prices($s, array_map(static fn($p2) => (int) $p2['id'], $r));
      if ($store) {
        foreach ($r as &$x) {
          if (isset($store[(int) $x['id']])) $x['price'] = $store[(int) $x['id']];
        }
        unset($x);
      }
    }
    // Règle : on n'affiche QUE les produits à prix non nul (> 0). Un produit dont le
    // prix effectif (magasin ERP, ou repli ws_) vaut 0 n'est pas vendable → masqué.
    // Appliqué APRÈS la surcharge du prix magasin pour couvrir les deux sources.
    $r = array_values(array_filter($r, static fn($x) => (float) $x['price'] > 0));
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
    rate_limit('voucher', 15, 600);   // anti brute-force des codes
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
  // ── Référentiel géo : codes postaux belges (bpost open data, embarqué). ──
  //    ?all=1 → liste compacte [[cp, commune, lat, lng]…] (~100 Ko, à cacher côté client)
  //    ?q=…   → recherche par préfixe de CP ou nom de commune (12 max)
  if ($m === 'GET' && $p === '/geo/postcodes') {
    $file = __DIR__ . '/data/zipcodes_be.json';
    if (!is_file($file)) json_out([]);
    $all = json_decode((string) file_get_contents($file), true) ?: [];
    if (qp('all') !== null) {
      json_out(array_map(fn ($e) => [$e['zip'], $e['city'], $e['lat'], $e['lng']], $all));
    }
    //    ?groups=1 → arrondissements / régions (plages officielles bpost) avec
    //    le compte de CP du référentiel — pour l'ajout groupé.
    if (qp('groups') !== null) {
      $defs = [
        ['Région de Bruxelles-Capitale', [[1000, 1299]]],
        ['Brabant wallon (arr. Nivelles)', [[1300, 1499]]],
        ['Brabant flamand — Hal-Vilvorde', [[1500, 1999]]],
        ['Brabant flamand — Louvain', [[3000, 3499]]],
        ['Province d\'Anvers', [[2000, 2999]]],
        ['Limbourg', [[3500, 3999]]],
        ['Province de Liège', [[4000, 4999]]],
        ['Province de Namur', [[5000, 5999]]],
        ['Hainaut — Charleroi / Sud', [[6000, 6599]]],
        ['Province de Luxembourg', [[6600, 6999]]],
        ['Hainaut — Mons / Nord', [[7000, 7999]]],
        ['Flandre-Occidentale', [[8000, 8999]]],
        ['Flandre-Orientale', [[9000, 9999]]],
      ];
      $out = [];
      foreach ($defs as [$name, $ranges]) {
        $cps = [];
        foreach ($all as $e) {
          $z = (int) $e['zip'];
          foreach ($ranges as [$a, $b]) if ($z >= $a && $z <= $b) { $cps[] = (string) $e['zip']; break; }
        }
        $cps = array_values(array_unique($cps));
        if ($cps) $out[] = ['name' => $name, 'count' => count($cps), 'cps' => $cps];
      }
      json_out($out);
    }
    $q = mb_strtolower(trim((string) qp('q', '')));
    if (mb_strlen($q) < 2) json_out([]);
    $out = [];
    foreach ($all as $e) {
      if (strpos((string) $e['zip'], $q) === 0 || mb_stripos((string) $e['city'], $q) !== false) {
        $out[] = ['cp' => $e['zip'], 'commune' => $e['city'], 'lat' => $e['lat'], 'lng' => $e['lng']];
        if (count($out) >= 12) break;
      }
    }
    json_out($out);
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
  // Annuaire des bureaux : champs MINIMAUX pour le sélecteur du webshop
  // (nom + adresse). Contact, email, téléphone et TVA ne sont plus exposés
  // publiquement — c'était un annuaire de prospection téléchargeable.
  if ($m === 'GET' && $p === '/offices') {
    json_out(rows("SELECT id, tour_id AS tourId, name, address, postal_code AS postalCode, city, active,
                          IF(active=1,'validated','pending') AS status
                     FROM ws_offices WHERE active=1"));
  }
  if ($m === 'GET' && ($mm = $match('/offices/:id'))) {
    $o = row("SELECT id, tour_id AS tourId, name, address, postal_code AS postalCode, city, active,
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
     tournées ACTIVES avec leurs codes postaux (zone de chalandise).
     Une tournée en préparation (active=0) n'apparaît jamais. ── */
  if ($m === 'GET' && $p === '/delivery-zones') {
    $hasTP = col_exists('ws_tour_postcodes', 'postcode');
    json_out(rows("SELECT t.id, t.name AS tour" .
      ($hasTP ? ", GROUP_CONCAT(DISTINCT tp.postcode ORDER BY tp.postcode SEPARATOR ' · ') AS postcodes" : ", NULL AS postcodes") . "
                     FROM ws_tours t" .
      ($hasTP ? " LEFT JOIN ws_tour_postcodes tp ON tp.tour_id = t.id" : "") . "
                    WHERE t.active = 1
                    GROUP BY t.id, t.name
                    ORDER BY t.name"));
  }

  /* ── Modalités d'une zone/tournée (public) : boutique qui livre + jours,
     horaires, cut-off et créneaux — « pilote tout » de la landing bureau. ── */
  if ($m === 'GET' && $p === '/zone-modalites') {
    $tourId = (int) qp('tour', '0');
    if (!$tourId) json_out((object) []);
    $DAYS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
    $tr = row("SELECT id, name AS tour, shop_id FROM ws_tours WHERE id = ? AND active = 1", [$tourId]);
    if (!$tr) json_out((object) []);
    $shopId = (int) $tr['shop_id'];
    $shop = $shopId ? row("SELECT name, city FROM shops WHERE id = ?", [$shopId]) : null;
    $jours = null; $dep = null; $fin = null; $cut = null;
    if (col_exists('ws_tour_availability', 'tour_id')) {
      $av = row("SELECT GROUP_CONCAT(DISTINCT delivery_day ORDER BY delivery_day) AS days,
                        TIME_FORMAT(MIN(delivery_start), '%H:%i') AS dep,
                        TIME_FORMAT(MAX(delivery_end), '%H:%i')   AS fin,
                        TIME_FORMAT(MIN(cutoff_time), '%H:%i')    AS cut
                   FROM ws_tour_availability WHERE tour_id = ? AND active = 1", [$tourId]);
      if ($av && $av['days'] !== null) {
        $jours = implode(' · ', array_map(fn ($d) => $DAYS[(((int) $d) + 6) % 7], explode(',', (string) $av['days'])));
        $dep = $av['dep']; $fin = $av['fin']; $cut = $av['cut'];
      }
    }
    $creneaux = [];
    if ($shopId && col_exists('ws_slots', 'label')) {
      $creneaux = array_map(fn ($r) => $r['label'],
        rows("SELECT label FROM ws_slots WHERE shop_id = ? AND mode = 'delivery' AND active = 1 ORDER BY sort_order, label LIMIT 40", [$shopId]));
    }
    json_out([
      'shop'     => $shop ? ($shop['name'] ?: $shop['city']) : null,
      'city'     => $shop['city'] ?? null,
      'tour'     => $tr['tour'],
      'jours'    => $jours,
      'horaire'  => ($dep && $fin) ? ($dep . '–' . $fin) : null,
      'cutoff'   => $cut ? ($cut . ' J-1') : null,
      'creneaux' => $creneaux,
    ]);
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
    // Sonde email → sociétés liées : rate-limité (anti-énumération de masse)
    // et réduit aux champs que le checkout consomme (id, nom, TVA pour le
    // pré-remplissage facture, facturation différée) — plus d'adresse exposée.
    rate_limit('companies', 10, 600);
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
    // Facturation : « Demander une facture nominative » → invoice:{requested,vat,po}.
    $inv          = is_array($b['invoice'] ?? null) ? $b['invoice'] : null;
    $invRequested = ($inv && !empty($inv['requested'])) ? 1 : 0;
    $poNumber     = ($inv && isset($inv['po']) && $inv['po'] !== '') ? mb_substr((string) $inv['po'], 0, 100) : null;
    $invVat       = ($inv && isset($inv['vat']) && $inv['vat'] !== '') ? mb_substr((string) $inv['vat'], 0, 40) : null;

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
    //    Prix facturé = MÊME source que l'affichage catalogue : prix magasin ERP
    //    (shop_product.portion_price) s'il existe pour cette boutique, sinon repli
    //    sur COALESCE(ws_product_prices, ws_products.price). Aligné sur /catalog.
    $subtotal = 0; $lines = [];
    $storePrices = erp_shop_prices($shop, array_map(static fn($it) => (int) ($it['productId'] ?? 0), $basket));
    foreach ($basket as $it) {
      $p2 = row("SELECT p.id, p.name, p.cross_portion, COALESCE(pp.price, p.price) AS price
                   FROM ws_products p LEFT JOIN ws_product_prices pp ON pp.product_id=p.id AND pp.shop_id=? AND pp.active=1
                  WHERE p.id=? AND p.active=1", [$shop, $it['productId'] ?? 0]);
      if (!$p2) continue;
      $unit = isset($storePrices[(int) $p2['id']]) ? $storePrices[(int) $p2['id']] : (float) $p2['price'];
      $qty = max(1, (int) ($it['qty'] ?? 1));
      $subtotal += $unit * $qty;
      $lines[] = ['productId' => $p2['id'], 'name' => $p2['name'], 'qty' => $qty,
                  'unit' => $unit, 'portion' => $it['portion'] ?? null, 'cross' => (int) $p2['cross_portion'],
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
    $sd = row("SELECT discount_type AS t, discount_value AS v FROM shops WHERE id=? AND webshop_enabled=1", [$shop]);
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
      // Ciblage (0009) : un bon CUSTOMER/OFFICE/GROUP n'est applicable que si le client de
      // la commande appartient à la cible. NETWORK / bon legacy hors modèle -> pas de restriction.
      $eligible = true;
      if ($v) {
        $tg = row("SELECT vc.target_kind, vc.target_id, vco.id_customer
                     FROM voucher_code vco JOIN voucher_campaign vc ON vc.id = vco.id_voucher_campaign
                    WHERE vco.code = ? LIMIT 1", [strtoupper(trim($b['voucher']))]);
        if ($tg && ($tg['target_kind'] ?? 'NETWORK') !== 'NETWORK') {
          $cid = isset($b['customerId']) && $b['customerId'] !== '' ? (int) $b['customerId'] : null;
          if ($tg['target_kind'] === 'CUSTOMER') {
            $eligible = $cid !== null && (int) $tg['id_customer'] === $cid;
          } elseif ($tg['target_kind'] === 'OFFICE') {
            $off = $cid !== null ? row("SELECT office_id FROM client WHERE id=?", [$cid]) : null;
            $eligible = $off && $off['office_id'] !== null && (int) $off['office_id'] === (int) $tg['target_id'];
          } else { // GROUP : enforcement à câbler quand le lien client<->b2b_client_type sera fourni
            $eligible = false;
          }
        }
      }
      if ($v && $baseV >= (float) $v['min_order'] && $eligible) {
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
            payment_type, delivery_fee_applied, delivery_fee_amount, free_delivery_minimum,
            po_number, invoice_requested, invoice_vat)
         VALUES (?,?,?, ?,?,?,?, ?, ?, ?,?,?, ?,?,?,?,?,?, ?, 'pending', ?, ?, ?, ?,?,?,?, ?,?,?,?, ?,?,?)",
        [$ref, $shop, $b['customerId'] ?? null, $guestEmail, $guestName, $guestPhone, $guestPfx, $mode, $orderStatus,
         $b['slotId'] ?? null, $b['slotLabel'] ?? null, $b['deliveryDate'] ?? null,
         $subtotal, $promo, $webshopDisc, $voucherCode, $voucherDisc, $total,
         $paymentMethod, $b['lang'] ?? 'fr', $note, $mode === 'delivery' ? 'office_delivery' : 'collect',
         $officeClientId, $dl['siteId'] ?? null, $dl['siteName'] ?? null, $dl['tourneeStopId'] ?? null,
         $paymentType, $feeApplied, $feeAmount, $freeMin,
         $poNumber, $invRequested, $invVat]);
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
    // Données personnelles (identité, adresse, contenu) : lecture réservée au
    // PROPRIÉTAIRE connecté ou à l'admin — les ids/refs sont énumérables, un
    // accès public permettrait de lire les commandes de n'importe qui.
    $o = row("SELECT * FROM ws_orders WHERE id=? OR order_ref=? LIMIT 1", [$mm['id'], $mm['id']]);
    if (!$o) json_out(['error' => 'Commande introuvable'], 404);
    $uid = auth_uid();
    $isOwner = $uid && (int) ($o['customer_id'] ?? 0) === (int) $uid;
    if (!$isOwner && !is_admin_request()) json_out(['error' => 'Non autorisé.'], 401);
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
    // Code postal OBLIGATOIRE (exigence réseau : collecte partout) + format
    // validé selon le pays (défaut BE : 4 chiffres). La localité confirmée à
    // la saisie est stockée avec le CP (référentiel /geo/postcodes).
    if ($zip === '') json_out(['error' => 'Code postal requis'], 400);
    $zip = zip_validate($zip, $b['country'] ?? 'BE');
    if ($zip === null) json_out(['error' => 'Code postal invalide'], 400);
    $locality = zip_locality($zip, $b['locality'] ?? '');
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
      // `locality` guardée par col_exists : le code peut être déployé une
      // requête avant que migrate.sh n'ait joué 0015 — pas de 500 pendant la fenêtre.
      $hasLoc = col_exists('client', 'locality');
      q("INSERT INTO client (id_main_shop, email, phone, phone_prefix, phone_e164, name, surname, zip, " . ($hasLoc ? "locality, " : "") . "password_hash,
                             active, source_channel, webshop_user, preferred_auth_method)
         VALUES (?,?,?,?,?,?,?,?," . ($hasLoc ? "?," : "") . "?,1,'webshop',1,?)",
        array_merge(
          [$ms, ($mail ?: null), ($phone ?: null), ($phone !== '' ? $pfx : null), ($e164 ?: null), $first, $last, $zip],
          $hasLoc ? [$locality] : [],
          [$hash, $authM]));
      $id = db()->lastInsertId();
    }
    json_out(['user' => user_payload($id), 'token' => sign_token(['id' => (int) $id, 'exp' => time() + 30 * 86400])], 201);
  }
  if ($m === 'POST' && $p === '/auth/login') {
    rate_limit('login', 10, 300);   // anti brute-force mots de passe
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
    // Durci : rate-limité, et RÉSERVÉ aux comptes qui n'ont PAS encore de mot
    // de passe (clients importés / créés côté PWA — le seul cas du flux front).
    // Un compte déjà protégé ne peut plus être écrasé ici : sans cette garde,
    // connaître l'email de quelqu'un suffisait à voler son compte.
    // TODO produit : ajouter un OTP email/SMS pour couvrir aussi ce cas résiduel.
    rate_limit('setpw', 5, 900);
    $b = body();
    $mail = strtolower(trim($b['email'] ?? ''));
    [, $phoneNat, $phoneE164] = norm_phone($b['phonePrefix'] ?? '+32', $b['phone'] ?? '');
    $ident = strtolower(trim($b['identifier'] ?? ''));
    [, $identNat, $identE164] = norm_phone($b['phonePrefix'] ?? '+32', $ident);
    $pass = (string) ($b['password'] ?? '');
    if (strlen($pass) < 6) json_out(['error' => 'Mot de passe trop court (min. 6 caractères).'], 400);
    $u = row("SELECT id, password_hash FROM client
                WHERE (? <> '' AND LOWER(TRIM(email))=?)
                   OR (? <> '' AND (phone_e164=? OR phone=?))
                   OR (? <> '' AND (LOWER(TRIM(email))=? OR phone_e164=? OR phone=?))
                ORDER BY webshop_user DESC, id LIMIT 1",
             [$mail, $mail, $phoneNat, $phoneE164, $phoneNat, $ident, $ident, $identE164, $identNat]);
    if (!$u) json_out(['error' => 'Compte introuvable.'], 404);
    if (!empty($u['password_hash'])) {
      json_out(['error' => 'Ce compte a déjà un mot de passe. Connectez-vous ou utilisez la réinitialisation.'], 403);
    }
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
           FROM ws_orders o LEFT JOIN shops s ON s.id = o.shop_id AND s.webshop_enabled = 1
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
    // Code postal : format validé (défaut BE) ; la localité confirmée est
    // stockée avec — c'est aussi le canal de la modal de rattrapage post-login.
    if (array_key_exists('postalCode', $b)) {
      $zp = trim((string) $b['postalCode']);
      if ($zp === '') json_out(['error' => 'Code postal requis'], 400); // collecte obligatoire : pas d'effacement
      $zp = zip_validate($zp, $b['country'] ?? 'BE');
      if ($zp === null) json_out(['error' => 'Code postal invalide'], 400);
      $sets[] = 'zip=?'; $vals[] = $zp;
      if (col_exists('client', 'locality')) { $sets[] = 'locality=?'; $vals[] = zip_locality($zp, $b['locality'] ?? ''); }
    }
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
    $SHOPS = 'shops';

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
        $prods = rows("SELECT p.id, p.name AS nom, p.price AS prix, p.active, p.img,
                              COALESCE(p.brand_mandatory,0) AS bm,
                              COALESCE(p.office_delivery,1) AS od,
                              p.sub_cat_id AS sub_id, sub.label AS sub, se.name AS saison
                         FROM ws_products p
                         LEFT JOIN ws_category_subs sub ON sub.id = p.sub_cat_id
                         LEFT JOIN ws_season se ON se.id = p.season_id
                        WHERE p.cat_id = ? ORDER BY sub.sort_order, sub.label, p.name", [$c['id']]);
        $photos = product_photo_files();
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
            'od' => (bool) $p2['od'], // disponible en livraison bureau (« apricot »)
            'sub' => $p2['sub'] ?: null, 'sub_id' => $p2['sub_id'] !== null ? (int) $p2['sub_id'] : null,
            'photo' => (!empty($p2['img']) || isset($photos[$p2['id']])), // a une photo produit
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
      if (array_key_exists('office_delivery', $b)) { $sets[] = 'office_delivery=?'; $vals[] = !empty($b['office_delivery']) ? 1 : 0; }  // canal livraison bureau (« apricot »)
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
      if (array_key_exists('office_delivery', $b)) q("UPDATE ws_products SET office_delivery=? WHERE cat_id=?", [!empty($b['office_delivery']) ? 1 : 0, $id]);  // cascade « livraison bureau » catégorie
      if (array_key_exists('brand_whitelist', $b)) q("UPDATE ws_products SET brand_whitelist=? WHERE cat_id=?", [!empty($b['brand_whitelist']) ? 1 : 0, $id]);
      if (array_key_exists('brand_mandatory', $b)) q("UPDATE ws_products SET brand_mandatory=? WHERE cat_id=?", [!empty($b['brand_mandatory']) ? 1 : 0, $id]);
      $audit('category.update', 'ws_categories', $id, null, $b);
      json_out(['ok' => true]);
    }

    // Sous-catégorie : cascade des flags de pilotage aux produits de la sous-catégorie
    // (ws_products.sub_cat_id). Mêmes flags que la catégorie, portée plus fine.
    if ($m === 'POST' && $p === '/franchisor/subcategory') {
      $b = body(); $id = (int) ($b['id'] ?? ($b['sub_id'] ?? 0));
      if (!$id) json_out(['error' => 'id requis'], 400);
      if (array_key_exists('active', $b))          q("UPDATE ws_products SET active=? WHERE sub_cat_id=?", [!empty($b['active']) ? 1 : 0, $id]);           // « Webshop »
      if (array_key_exists('office_delivery', $b)) q("UPDATE ws_products SET office_delivery=? WHERE sub_cat_id=?", [!empty($b['office_delivery']) ? 1 : 0, $id]); // « Bureau »
      if (array_key_exists('brand_mandatory', $b)) q("UPDATE ws_products SET brand_mandatory=? WHERE sub_cat_id=?", [!empty($b['brand_mandatory']) ? 1 : 0, $id]);
      if (array_key_exists('menu_override', $b))   q("UPDATE ws_products SET menu_override=? WHERE sub_cat_id=?", [in_array($b['menu_override'], ['on','off'], true) ? $b['menu_override'] : null, $id]);
      $audit('subcategory.update', 'ws_category_subs', $id, null, $b);
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
    // Recherche client — bon ciblé PERSONNE (mail / nom / téléphone).
    if ($m === 'GET' && $p === '/franchisor/clients') {
      $qq = trim((string) qp('q'));
      if (mb_strlen($qq) < 2) json_out([]);
      $like = '%'.$qq.'%';
      json_out(rows("SELECT id, name, email, phone FROM client
                      WHERE active=1 AND (name LIKE ? OR email LIKE ? OR phone LIKE ? OR phone_e164 LIKE ?)
                      ORDER BY name LIMIT 20", [$like, $like, $like, $like]));
    }
    // Recherche entreprise / bureau livré — bon ciblé OFFICE (nom / ville).
    if ($m === 'GET' && $p === '/franchisor/offices') {
      $qq = trim((string) qp('q'));
      $like = '%'.$qq.'%';
      json_out(rows("SELECT id, name, city FROM ws_offices
                      WHERE active=1 AND (? = '' OR name LIKE ? OR city LIKE ?)
                      ORDER BY name LIMIT 30", [$qq, $like, $like]));
    }

    // ── Analyse géographique : clients géolocalisables + boutiques + franchisés. ──
    //    Géoloc par code postal (référentiel embarqué côté client). Le front
    //    résout lat/lng et compte les non-localisés. Aucune donnée en dur.
    if ($m === 'GET' && $p === '/franchisor/geo-clients') {
      $out = ['shops' => [], 'clients' => [], 'franchisees' => []];
      $SHOPS2 = 'shops';
      $out['shops'] = rows("SELECT id, name, city, zip AS cp FROM $SHOPS2 WHERE active=1 ORDER BY name");
      // Bureaux (B2B) — jaune. Boutique via la tournée assignée.
      if (row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ws_offices'")) {
        $offs = rows("SELECT f.id, f.name, f.postal_code AS cp, f.city, t.shop_id,
                             (SELECT COALESCE(SUM(o.total),0) FROM ws_orders o WHERE o.office_client_id = f.id) AS ca
                        FROM ws_offices f LEFT JOIN ws_tours t ON t.id = f.tour_id
                       WHERE f.active = 1");
        foreach ($offs as $f) $out['clients'][] = ['id' => 'o' . $f['id'], 'type' => 'office',
          'name' => $f['name'], 'cp' => $f['cp'], 'city' => $f['city'],
          'shop_id' => $f['shop_id'] !== null ? (int) $f['shop_id'] : null, 'ca' => (float) $f['ca']];
      }
      // Particuliers — bleu. Identité unifiée `client` : zip/localité collectés
      // partout (repli facturation), rattachement preferred_shop_id → id_main_shop.
      foreach (geo_private_clients() as $c) $out['clients'][] = $c;
      // Franchisés (RBAC) : bo_users rôle franchise + portée bo_user_shops.
      if (row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='bo_users'")) {
        $frs = rows("SELECT u.id, COALESCE(u.display_name, u.email) AS name FROM bo_users u WHERE u.role='franchise' AND u.active=1 ORDER BY name");
        foreach ($frs as $u) {
          $sids = array_map(fn ($r) => (int) $r['shop_id'], rows("SELECT shop_id FROM bo_user_shops WHERE user_id=?", [(int) $u['id']]));
          $out['franchisees'][] = ['id' => (int) $u['id'], 'name' => $u['name'], 'shops' => $sids];
        }
      }
      json_out($out);
    }

    // ── Prospects B2B non rattachés (id_main_shop = 0) — nouveaux « clients bureau »
    //    encodés depuis la landing dont le code postal n'est couvert par aucun
    //    franchisé. Affichés dans la Console franchiseur, menu « Prospect ». ──
    if ($m === 'GET' && $p === '/franchisor/prospects') {
      if (!row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='client'")) json_out([]);
      $hasB2b = col_exists('client', 'is_b2b');
      $hasOD  = col_exists('client', 'office_delivery');
      $hasSt  = col_exists('client', 'status');
      $hasLoc = col_exists('client', 'locality');
      $hasCr  = col_exists('client', 'created_at');
      $hasCo  = col_exists('client', 'company_name');
      $where = "c.id_main_shop = 0";
      if ($hasB2b) $where .= " AND c.is_b2b = 1";
      json_out(rows("SELECT c.id, " .
                    ($hasCo ? "COALESCE(NULLIF(TRIM(c.company_name),''), c.name) AS name" : "c.name") .
                    ", c.surname, c.email, c.phone, c.zip" .
                    ($hasLoc ? ", c.locality" : ", NULL AS locality") .
                    ($hasSt  ? ", c.status"   : ", NULL AS status") .
                    ($hasOD  ? ", c.office_delivery" : ", 1 AS office_delivery") .
                    ($hasCr  ? ", c.created_at" : ", NULL AS created_at") . "
                       FROM client c WHERE $where ORDER BY c.id DESC LIMIT 500"));
    }

    // ── Zones de chalandise (primaires) — gérées par le franchiseur, par shop. ──
    if ($m === 'GET' && $p === '/franchisor/catchment') {
      if (!row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ws_franchisor_catchment'")) json_out([]);
      $hasShop = (bool) row("SELECT 1 x FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_franchisor_catchment' AND column_name='shop_id'");
      json_out(rows("SELECT c.id, c.name, c.postcodes, c.exclusive, c.active" .
                    ($hasShop ? ", c.shop_id, s.name AS shop_name" : ", NULL AS shop_id, NULL AS shop_name") . "
                      FROM ws_franchisor_catchment c" .
                    ($hasShop ? " LEFT JOIN shops s ON s.id = c.shop_id AND s.webshop_enabled = 1" : "") . " ORDER BY c.name"));
    }
    if ($m === 'POST' && $p === '/franchisor/catchment') {
      $b = body();
      if (!empty($b['delete'])) { q("DELETE FROM ws_franchisor_catchment WHERE id=?", [(int) $b['delete']]); json_out(['ok' => true]); }
      $name = trim((string) ($b['name'] ?? ''));
      if ($name === '') json_out(['error' => 'name requis'], 400);
      $cp = (string) ($b['postcodes'] ?? '');
      $id = (int) ($b['id'] ?? 0);
      // Un CP ne peut appartenir qu'à une seule zone primaire.
      foreach (preg_split('/[^0-9]+/', $cp, -1, PREG_SPLIT_NO_EMPTY) as $one) {
        $hit = row("SELECT name FROM ws_franchisor_catchment WHERE active=1 AND postcodes REGEXP CONCAT('(^|[^0-9])', ?, '($|[^0-9])')" . ($id ? " AND id <> " . $id : ""), [$one]);
        if ($hit) json_out(['error' => "CP $one déjà attribué à la zone de chalandise « {$hit['name']} »", 'cp' => $one], 409);
      }
      $hasShop = (bool) row("SELECT 1 x FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_franchisor_catchment' AND column_name='shop_id'");
      $shop = isset($b['shop_id']) && $b['shop_id'] !== '' ? (int) $b['shop_id'] : null;
      if ($id) {
        q("UPDATE ws_franchisor_catchment SET name=?, postcodes=?, exclusive=?, active=?" . ($hasShop ? ", shop_id=?" : "") . " WHERE id=?",
          $hasShop ? [$name, $cp, !empty($b['exclusive']) ? 1 : 0, isset($b['active']) ? (int) !!$b['active'] : 1, $shop, $id]
                   : [$name, $cp, !empty($b['exclusive']) ? 1 : 0, isset($b['active']) ? (int) !!$b['active'] : 1, $id]);
      } else {
        q("INSERT INTO ws_franchisor_catchment (name, postcodes, exclusive, active" . ($hasShop ? ", shop_id" : "") . ") VALUES (?,?,?,1" . ($hasShop ? ",?" : "") . ")",
          $hasShop ? [$name, $cp, !empty($b['exclusive']) ? 1 : 0, $shop] : [$name, $cp, !empty($b['exclusive']) ? 1 : 0]);
        $id = (int) db()->lastInsertId();
      }
      json_out(['ok' => true, 'id' => $id]);
    }

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
      // Ciblage (0009) : NETWORK (défaut) | CUSTOMER (client.id) | OFFICE (ws_offices.id) | GROUP (b2b_client_type.id).
      // L'appartenance est vérifiée à la redemption (webshop). CUSTOMER pose aussi voucher_code.id_customer.
      $tkind = strtoupper(trim($b['target_kind'] ?? 'NETWORK'));
      if (!in_array($tkind, ['NETWORK','CUSTOMER','OFFICE','GROUP'], true)) $tkind = 'NETWORK';
      $tid   = ($tkind !== 'NETWORK' && isset($b['target_id']) && $b['target_id'] !== '') ? (int) $b['target_id'] : null;
      if ($tkind !== 'NETWORK' && $tid === null) json_out(['error' => 'target_id requis pour un bon ciblé'], 400);
      $reqCust = $tkind === 'CUSTOMER' ? 1 : 0;
      $custId  = $tkind === 'CUSTOMER' ? $tid : null;
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
          q("UPDATE voucher_campaign SET valid_to=?, usage_limit_total=?, id_brand=?, target_kind=?, target_id=?, requires_customer=? WHERE id=?",
            [$exp, $maxUses, $idBrand, $tkind, $tid, $reqCust, $ex['campaign_id']]);
          q("UPDATE voucher_code SET status=?, valid_to=?, usage_limit=?, id_customer=? WHERE id=?",
            [$cstatus, $exp, $maxUses, $custId, $ex['code_id']]);
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
                 requires_customer, id_brand, id_shop, target_kind, target_id)
             VALUES (?,?, 'ORDER_DISCOUNT', ?, 'SHARED', NULL, ?, ?, NULL, NULL, ?, ?, NULL, ?, ?)",
            [$pid, 'Webshop — '.$code, $status, $exp, $maxUses, $reqCust, $idBrand, $tkind, $tid]);
          $cid = $pdo->lastInsertId();
          q("INSERT INTO voucher_code (id_voucher_campaign, code, status, valid_from, valid_to, usage_limit, usage_count, id_customer)
             VALUES (?,?,?, NULL, ?, ?, 0, ?)", [$cid, $code, $cstatus, $exp, $maxUses, $custId]);
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

  /* ══════════════════════════════════════════════════════════════════════
     Console franchisé (franchisee) — lecture, gardée admin (X-Admin-Token).
     Miroir de la Console marque : renvoie EXACTEMENT les shapes attendues par
     le back-office franchisé (app DC — bo_server.js → BOServer.hydrate()).
     Portée boutique optionnelle via ?shop=<slug|id> ; absente → réseau.
     Toute table absente / requête vide ⇒ [] ⇒ le front garde son seed (jamais
     de rendu cassé). Écritures = incrément suivant (comme le franchisor).
     ══════════════════════════════════════════════════════════════════════ */
  if (strpos($p, '/franchisee/') === 0) {
    require_admin();

    $tblExists = function ($t) { return (bool) row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?", [$t]); };
    $SHOPS = 'shops';
    $eur0  = function ($n) { return number_format((float) $n, 0, ',', ' ') . ' €'; };
    $eurk  = function ($n) { return number_format(round($n / 1000)) . ' k€'; };
    $today = qp('date', date('Y-m-d'));
    $hasOrders = $tblExists('ws_orders');
    $DAYS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

    // Portée boutique : ?shop=<slug|id>. Absente → réseau (toutes boutiques).
    $shopParam = qp('shop');
    $shopId = null;
    if ($shopParam !== null && $shopParam !== '') {
      $sr = ctype_digit((string) $shopParam)
        ? row("SELECT id FROM $SHOPS WHERE id=?", [(int) $shopParam])
        : row("SELECT id FROM $SHOPS WHERE slug=?", [$shopParam]);
      if ($sr) $shopId = (int) $sr['id'];
    }
    // Fragment WHERE de portée pour une colonne shop (réseau → 1=1). $shopId est un int contrôlé.
    $scope = function ($col) use ($shopId) { return $shopId ? "$col = " . (int) $shopId : '1=1'; };

    // ── Contexte session (admin_token → pas de bo_user ; contexte minimal). ──
    if ($m === 'GET' && $p === '/franchisee/me') {
      $shop = $shopId ? row("SELECT id, name, city FROM $SHOPS WHERE id=?", [$shopId]) : null;
      json_out([
        'shop'         => $shop ? ['id' => (int) $shop['id'], 'name' => $shop['name'], 'city' => $shop['city']] : null,
        'consoleLabel' => 'Console franchisé' . ($shop ? ' · ' . ($shop['city'] ?: $shop['name']) : ''),
      ]);
    }

    // ── KPIs du jour — shape vstat du design (couleurs CSS brutes). ──
    if ($m === 'GET' && $p === '/franchisee/kpis') {
      if (!$hasOrders) json_out([]);
      $sw = $scope('shop_id');
      $d  = row("SELECT COALESCE(SUM(total),0) ca, COUNT(*) n, COALESCE(AVG(total),0) avg_basket,
                        SUM(delivery_mode='delivery') AS deliv,
                        SUM(status IN ('pending','confirmed','preparing')) AS to_prep
                   FROM ws_orders WHERE $sw AND delivery_date = ?", [$today]);
      $rup = $tblExists('ws_product_stock')
        ? (int) (row("SELECT COUNT(*) n FROM ws_product_stock WHERE $sw AND date=? AND active=1
                        AND (qty_total - qty_reserved - qty_sold) <= 0", [$today])['n'] ?? 0) : 0;
      json_out([
        ['label' => 'CA du jour',        'value' => $eur0($d['ca']),              'valColor' => 'var(--color-text)',    'delta' => "aujourd'hui", 'deltaColor' => '#2d7a3e'],
        ['label' => 'Commandes du jour', 'value' => (string) (int) $d['n'],       'valColor' => 'var(--color-primary)', 'delta' => "aujourd'hui", 'deltaColor' => '#2d7a3e'],
        ['label' => 'Livraisons bureau', 'value' => (string) (int) $d['deliv'],   'valColor' => '#C87A3F',              'delta' => 'livraison',   'deltaColor' => '#2d7a3e'],
        ['label' => 'Panier moyen',      'value' => number_format((float) $d['avg_basket'], 2, ',', ' ') . ' €', 'valColor' => 'var(--color-text)', 'delta' => 'moyenne', 'deltaColor' => '#2d7a3e'],
        ['label' => 'À préparer',        'value' => (string) (int) $d['to_prep'], 'valColor' => 'var(--color-text)',    'delta' => 'en attente',  'deltaColor' => 'var(--color-primary)'],
        ['label' => 'Ruptures stock',    'value' => (string) $rup,                'valColor' => 'var(--color-text)',    'delta' => 'du jour',     'deltaColor' => $rup ? 'var(--color-primary)' : '#2d7a3e'],
      ]);
    }

    // ── Clients B2B (fr_clients) — ws_offices + sites (points de livraison). ──
    if ($m === 'GET' && $p === '/franchisee/fr-clients') {
      if (!$tblExists('ws_offices')) json_out([]);
      $join = ''; $wh = '1=1';
      if ($shopId && $tblExists('ws_tours')) {
        $join = "LEFT JOIN ws_tours t ON t.id = f.tour_id";
        $wh   = "(t.shop_id = " . (int) $shopId . " OR f.tour_id IS NULL)";
      }
      $hasSites = $tblExists('ws_office_delivery_sites');
      $offices = rows("SELECT f.id, f.name, f.vat, f.status, f.deferred_billing_enabled
                         FROM ws_offices f $join WHERE $wh ORDER BY f.name LIMIT 200");
      $out = [];
      foreach ($offices as $f) {
        $pts = $hasSites ? rows(
          "SELECT COALESCE(s.name,'—') AS libelle, COALESCE(s.address,'—') AS adresse
             FROM ws_office_delivery_sites s WHERE s.office_client_id=? AND s.active=1 LIMIT 20", [$f['id']]) : [];
        $out[] = [
          'raison' => $f['name'], 'code' => 'OF-' . str_pad((string) $f['id'], 4, '0', STR_PAD_LEFT),
          'seg' => 'horeca', 'statut' => $f['status'] === 'validated' ? 'actif' : ($f['status'] ?: 'prospect'),
          'tva' => $f['vat'] ?: '—',
          'paiement' => $f['deferred_billing_enabled'] ? '30 j fin de mois' : 'Comptant',
          'plafond' => 0, 'encours' => 0, 'franco' => '—', 'remise' => '—', 'fact' => $f['deferred_billing_enabled'] ? 'Mensuel' : 'Par livraison',
          'points' => array_map(fn ($s2) => ['libelle' => $s2['libelle'], 'adresse' => $s2['adresse'],
            'fenetre' => '—', 'jours' => '—', 'validation' => '—', 'marge' => 0], $pts),
        ];
      }
      json_out($out);
    }

    // ── Incidents (fr_incidents) — ws_incidents, shape fiche du design. ──
    if ($m === 'GET' && $p === '/franchisee/fr-incidents') {
      if (!$tblExists('ws_incidents')) json_out([]);
      $rs = rows("SELECT i.id, i.order_ref, i.type, i.severity, i.status, i.title, i.description,
                         DATE_FORMAT(i.created_at,'%d/%m %H:%i') AS ts, sh.name AS shop
                    FROM ws_incidents i LEFT JOIN $SHOPS sh ON sh.id = i.shop_id
                   WHERE " . $scope('i.shop_id') . " ORDER BY (i.status='open') DESC, i.created_at DESC LIMIT 100");
      $tmap = ['manquant' => 'Colis manquant', 'retard' => 'Retard livraison', 'casse' => 'Colis endommagé',
               'erreur' => 'Erreur de préparation', 'litige' => 'Litige client'];
      $smap = ['open' => 'À traiter', 'in_progress' => 'En cours', 'resolved' => 'Résolu'];
      json_out(array_map(function ($r) use ($tmap, $smap) {
        $open = $r['status'] === 'open'; $done = $r['status'] === 'resolved';
        return [
          'type' => $tmap[$r['type']] ?? 'Incident', 'point' => $r['shop'] ?: '—', 'heure' => $r['ts'],
          'statut' => $smap[$r['status']] ?? 'À traiter',
          'icon' => $open ? '!' : ($done ? '↩' : '?'),
          'iconBg' => $open ? '#fbe9eb' : ($done ? '#eaf5ec' : 'var(--color-background-secondary)'),
          'iconColor' => $open ? 'var(--color-primary)' : ($done ? '#2d7a3e' : 'var(--color-text-muted)'),
          'ref' => 'INC-' . str_pad((string) $r['id'], 4, '0', STR_PAD_LEFT), 'geo' => '—',
          'horodatage' => $r['ts'], 'chauffeur' => '—', 'impact' => '—', 'impactRef' => $r['order_ref'] ? ('cmd #' . $r['order_ref']) : '—',
          'description' => $r['description'] ?: $r['title'],
          'statutColor' => $open ? 'var(--color-primary)' : ($done ? '#2d7a3e' : 'var(--color-text-muted)'),
        ];
      }, $rs));
    }

    // ── Alertes (fr_alertes) — dérivées des incidents ouverts. ──
    if ($m === 'GET' && $p === '/franchisee/fr-alertes') {
      if (!$tblExists('ws_incidents')) json_out([]);
      $rs = rows("SELECT i.type, i.severity, i.title, i.order_ref FROM ws_incidents i
                   WHERE " . $scope('i.shop_id') . " AND i.status='open'
                   ORDER BY (i.severity='high') DESC, i.created_at DESC LIMIT 8");
      json_out(array_map(fn ($r) => [
        'color'  => $r['severity'] === 'high' ? 'var(--color-primary)' : '#c9a24b',
        'titre'  => 'Incident — ' . $r['title'],
        'detail' => ucfirst($r['type']) . ($r['order_ref'] ? (' · cmd #' . $r['order_ref']) : '') . ' · à traiter',
      ], $rs));
    }

    // ── Rentabilité (fr_rentabilite) — arbre tournée › site : CA réel, coûts estimés. ──
    if ($m === 'GET' && $p === '/franchisee/fr-rentabilite') {
      if (!$hasOrders || !$tblExists('ws_tours') || !$tblExists('ws_offices')) json_out([]);
      $from = qp('from', date('Y-m-01'));
      $prep = (float) ws_param('cost_prep_per_order', '0');
      $emb  = (float) ws_param('cost_packaging_unit', '0');
      $tours = rows("SELECT id, name FROM ws_tours WHERE " . $scope('shop_id') . " AND active=1 ORDER BY name");
      $out = [];
      foreach ($tours as $t) {
        $offices = rows(
          "SELECT f.name,
                  (SELECT COALESCE(SUM(o.total),0) FROM ws_orders o
                     WHERE o.office_client_id = f.id AND o.delivery_date >= ?) AS ca,
                  (SELECT COUNT(*) FROM ws_orders o
                     WHERE o.office_client_id = f.id AND o.delivery_date >= ?) AS n
             FROM ws_offices f WHERE f.tour_id = ? AND f.active = 1 ORDER BY f.name", [$from, $from, $t['id']]);
        $sites = array_map(fn ($f) => ['nom' => $f['name'], 'offices' => [[
          'nom' => 'CA net', 'ca' => (float) $f['ca'],
          'couts' => round(((int) $f['n']) * ($prep + $emb), 2),
        ]]], $offices);
        if ($sites) $out[] = ['nom' => $t['name'], 'sites' => $sites];
      }
      json_out($out);
    }

    // ── Chauffeurs live (fr_live_drivers) — télémétrie ws_tour_tracking. ──
    if ($m === 'GET' && $p === '/franchisee/fr-live-drivers') {
      if (!$tblExists('ws_tour_tracking') || !$tblExists('ws_tours')) json_out([]);
      $rs = rows("SELECT tk.driver_name, tk.vehicle, tk.stops_done, tk.stops_total, t.name
                    FROM ws_tour_tracking tk JOIN ws_tours t ON t.id = tk.tour_id
                   WHERE " . $scope('t.shop_id') . " AND tk.driver_name IS NOT NULL ORDER BY t.name LIMIT 20");
      $palette = ['#8D1D2C', '#3B3468', '#2d7a3e', '#C87A3F'];
      $i = 0;
      json_out(array_map(function ($r) use (&$i, $palette) {
        return ['color' => $palette[$i++ % 4], 'nom' => $r['driver_name'],
                'info' => trim(($r['name'] ?: '') . ($r['vehicle'] ? (' · ' . $r['vehicle']) : '')),
                'avancement' => ((int) $r['stops_done']) . '/' . max(1, (int) $r['stops_total'])];
      }, $rs));
    }

    // ── Tournées (ws_tours) — table unique du constructeur. ──
    if ($m === 'GET' && $p === '/franchisee/ws-tours') {
      if (!$tblExists('ws_tours')) json_out([]);
      $hasTk = $tblExists('ws_tour_tracking');
      $hasZ  = $tblExists('ws_delivery_zones');
      $hasFV = col_exists('ws_tours', 'delivery_fee');
      $rs = rows("SELECT t.id, t.name, t.max_items" . ($hasZ ? ", z.name AS zone" : ", NULL AS zone") .
                 ($hasFV ? ", t.delivery_fee, t.vehicle" : ", NULL AS delivery_fee, NULL AS vehicle") .
                 ($hasTk ? ", tk.driver_name" : ", NULL AS driver_name") . ",
                         (SELECT COUNT(*) FROM ws_orders o WHERE o.tour_id=t.id AND o.delivery_date=?) AS used
                    FROM ws_tours t" . ($hasZ ? " LEFT JOIN ws_delivery_zones z ON z.id = t.zone_id" : "") .
                 ($hasTk ? " LEFT JOIN ws_tour_tracking tk ON tk.tour_id = t.id" : "") . "
                   WHERE " . $scope('t.shop_id') . " AND t.active=1 ORDER BY t.name", [$today]);
      // Fenêtre du jour (départ) + jours actifs depuis ws_tour_availability quand dispo.
      $hasAv = $tblExists('ws_tour_availability');
      $svc = (float) ws_param('cost_service_minutes', '15');
      json_out(array_map(function ($t) use ($hasAv, $svc) {
        $start = 360; $amp = 240;
        $inv = [1 => 'L', 2 => 'Ma', 3 => 'Me', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];
        $days = []; foreach ($inv as $kk) $days[$kk] = false;
        if ($hasAv) {
          $av = row("SELECT TIME_TO_SEC(MIN(delivery_start))/60 AS st,
                            TIME_TO_SEC(MAX(delivery_end))/60 - TIME_TO_SEC(MIN(delivery_start))/60 AS amp
                       FROM ws_tour_availability WHERE tour_id=? AND active=1", [(int) $t['id']]);
          if ($av && $av['st'] !== null) { $start = (int) $av['st']; $amp = max(60, (int) $av['amp']); }
          foreach (rows("SELECT DISTINCT delivery_day FROM ws_tour_availability WHERE tour_id=? AND active=1", [(int) $t['id']]) as $rd) {
            $k = $inv[(int) $rd['delivery_day']] ?? null; if ($k) $days[$k] = true;
          }
        }
        $name = $t['name'];
        $short = trim(preg_replace('/^Tourn[ée]e\s+/u', '', $name));
        $short = preg_split('/[\s\/]+/u', $short)[0] ?: $name;
        return ['id' => 'r' . $t['id'], 'name' => $name, 'short' => $short,
                'driver' => $t['driver_name'] ?: '— non assigné', 'start' => $start,
                'max' => (int) ($t['max_items'] ?: 10), 'ret' => (col_exists('ws_tours','return_to_depot') ? ((int) ($t['return_to_depot'] ?? 1) !== 0) : true),
                'forfait' => $t['delivery_fee'] !== null ? (float) $t['delivery_fee'] : 0,
                'vehicule' => $t['vehicle'] ?: '', 'days' => $days,
                'amplitude' => $amp, 'decharge' => (int) $svc, 'trajet' => (int) $svc,
                'used' => (int) $t['used'], 'zone' => $t['zone'] ?: '—'];
      }, $rs));
    }

    // ── Zones de livraison (ws_delivery_zones). ──
    if ($m === 'GET' && $p === '/franchisee/ws-delivery-zones') {
      if (!$tblExists('ws_delivery_zones')) json_out([]);
      $hasZoning = (bool) row("SELECT 1 x FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_delivery_zones' AND column_name='postcodes'");
      $rs = rows("SELECT z.id, z.name, z.sort_order, z.active" .
                 ($hasZoning ? ", z.postcodes, z.zone_type, c.name AS catchment_name" : ", NULL AS postcodes, 'secondary' AS zone_type, NULL AS catchment_name") . "
                    FROM ws_delivery_zones z" .
                 ($hasZoning ? " LEFT JOIN ws_franchisor_catchment c ON c.id = z.catchment_id" : "") . "
                   WHERE " . ($shopId && $hasZoning ? "(z.shop_id = " . (int) $shopId . " OR z.shop_id IS NULL)" : '1=1') . "
                   ORDER BY z.sort_order, z.name");
      json_out(array_map(fn ($z) => ['id' => (int) $z['id'], 'name' => $z['name'],
        'sort_order' => (int) $z['sort_order'], 'active' => (bool) $z['active'],
        'cp' => $z['postcodes'] ?: '—', 'type' => $z['zone_type'] ?: 'secondary',
        'vehicule' => 'Standard', 'franco' => '—', 'delai' => 'J+1',
        'service' => (int) (float) ws_param('cost_service_minutes', '15'),
        'catchment' => $z['catchment_name'] ?: ''], $rs));
    }

    // ── Zone de chalandise : codes postaux attribués à la boutique (pool des tournées). ──
    // Alimente le sélecteur de CP du formulaire « Créer une tournée » : le franchisé ne
    // peut cocher que des codes postaux de SA chalandise (ws_franchisor_catchment).
    if ($m === 'GET' && $p === '/franchisee/catchment-postcodes') {
      if (!$tblExists('ws_franchisor_catchment')) json_out([]);
      $hasShop = (bool) row("SELECT 1 x FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_franchisor_catchment' AND column_name='shop_id'");
      $rs = rows("SELECT name, postcodes FROM ws_franchisor_catchment WHERE active=1" .
                 ($hasShop && $shopId ? " AND (shop_id = " . (int) $shopId . " OR shop_id IS NULL)" : "") .
                 " ORDER BY name");
      $out = []; $seen = [];
      foreach ($rs as $c) {
        foreach (preg_split('/[^0-9]+/', (string) $c['postcodes'], -1, PREG_SPLIT_NO_EMPTY) as $one) {
          if (!preg_match('/^[0-9]{4}$/', $one) || isset($seen[$one])) continue;
          $seen[$one] = true;
          $out[] = ['cp' => $one, 'zone' => $c['name'], 'loc' => implode(' · ', zip_localities($one))];
        }
      }
      json_out($out);
    }

    // ── Vérification TVA via VIES (registre européen) — formulaire Office. ──
    // Renvoie {valid, name, address} ; le BO pré-remplit nom/adresse de l'office
    // (et la fiche client liée est mise à jour à l'enregistrement).
    if ($m === 'GET' && $p === '/franchisee/vies-check') {
      $vat = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) qp('vat', '')));
      if (!preg_match('/^([A-Z]{2})([0-9A-Za-z+*]{2,12})$/', $vat, $mv)) json_out(['valid' => false, 'error' => 'format']);
      $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
      $raw = @file_get_contents('https://ec.europa.eu/taxation_customs/vies/rest-api/ms/' . $mv[1] . '/vat/' . rawurlencode($mv[2]), false, $ctx);
      $j = $raw !== false ? json_decode($raw, true) : null;
      if (!is_array($j) || !array_key_exists('isValid', $j)) json_out(['valid' => null, 'error' => 'vies_unavailable'], 502);
      // Adresse VIES découpée : « Avenue Thomas Edison 111 1402 Nivelles »
      // → street (rue + n°), zip (4 chiffres), city — pour remplir les bons champs.
      $addr1 = trim(preg_replace('/\s+/', ' ', (string) ($j['address'] ?? '')));
      $street = null; $azip = null; $acity = null;
      if (preg_match('/^(.*?)[\s,]*\b(\d{4})\s+(\D.*)$/u', $addr1, $ma)) {
        $street = trim($ma[1], " ,") ?: null; $azip = $ma[2]; $acity = trim($ma[3]) ?: null;
      }
      json_out(['valid' => !empty($j['isValid']),
                'name' => trim((string) ($j['name'] ?? '')) !== '---' ? (trim((string) ($j['name'] ?? '')) ?: null) : null,
                'address' => $addr1 ?: null, 'street' => $street, 'zip' => $azip, 'city' => $acity]);
    }

    // ── Renvoi d'un client office vers le franchisé couvrant son CP (choix 1 du
    //    contrôle TVA vs tournée) : crée/active la fiche client rattachée à la
    //    boutique dont la chalandise couvre le CP → son bureau pending naît chez elle. ──
    if ($m === 'POST' && $p === '/franchisee/route-office') {
      $b = body();
      $cp = preg_replace('/\D+/', '', (string) ($b['cp'] ?? ''));
      if (!preg_match('/^\d{4}$/', $cp)) json_out(['routed' => false, 'error' => 'Code postal requis'], 400);
      $target = zip_shop($cp);
      if (!$target) json_out(['routed' => false, 'error' => 'Aucun franchisé ne couvre ce code postal'], 404);
      $tName = ($r0 = row("SELECT name FROM shops WHERE id=?", [$target])) ? $r0['name'] : ('#' . $target);
      $mail = strtolower(trim((string) ($b['email'] ?? '')));
      $vat  = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($b['vat'] ?? '')));
      $ex = $mail !== '' ? row("SELECT id FROM client WHERE email IS NOT NULL AND LOWER(TRIM(email))=? LIMIT 1", [$mail]) : null;
      if ($ex) {
        $sets = ['id_main_shop=' . (int) $target];
        if (col_exists('client', 'is_b2b'))          $sets[] = 'is_b2b=1';
        if (col_exists('client', 'office_delivery')) $sets[] = 'office_delivery=1';
        if (col_exists('client', 'status'))          $sets[] = 'status=1';
        q("UPDATE client SET " . implode(',', $sets) . " WHERE id=?", [(int) $ex['id']]);
        if ($vat !== '' && col_exists('client', 'tax_number')) q("UPDATE client SET tax_number=? WHERE id=?", [$vat, (int) $ex['id']]);
        json_out(['routed' => true, 'shop' => $tName, 'client_id' => (int) $ex['id']]);
      }
      $cols = ['id_main_shop', 'email', 'name', 'zip', 'city', 'active', 'source_channel', 'webshop_user'];
      $ivals = [(int) $target, $mail ?: null, trim((string) ($b['name'] ?? '')) ?: 'Office', $cp,
                trim((string) ($b['city'] ?? '')), 1, 'webshop', 0];
      if (col_exists('client', 'company_name'))    { $cols[] = 'company_name';    $ivals[] = trim((string) ($b['name'] ?? '')) ?: null; }
      if (col_exists('client', 'is_b2b'))          { $cols[] = 'is_b2b';          $ivals[] = 1; }
      if (col_exists('client', 'office_delivery')) { $cols[] = 'office_delivery'; $ivals[] = 1; }
      if (col_exists('client', 'status'))          { $cols[] = 'status';          $ivals[] = 1; }
      if ($vat !== '' && col_exists('client', 'tax_number')) { $cols[] = 'tax_number'; $ivals[] = $vat; }
      q("INSERT INTO client (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")", $ivals);
      json_out(['routed' => true, 'shop' => $tName, 'client_id' => (int) db()->lastInsertId()]);
    }

    // ── CP déjà affectés à chaque tournée (préremplissage du formulaire Tournée). ──
    if ($m === 'GET' && $p === '/franchisee/ws-tour-postcodes') {
      if (!$tblExists('ws_tour_postcodes')) json_out([]);
      json_out(rows("SELECT tp.tour_id, tp.postcode FROM ws_tour_postcodes tp" .
                    ($shopId ? " JOIN ws_tours t ON t.id = tp.tour_id AND t.shop_id = " . (int) $shopId : "") .
                    " ORDER BY tp.tour_id, tp.postcode"));
    }

    // ── Sites de livraison (ws_office_delivery_sites) — table réelle complète. ──
    if ($m === 'GET' && $p === '/franchisee/ws-office-delivery-sites') {
      if (!$tblExists('ws_office_delivery_sites')) json_out([]);
      $hasT = $tblExists('ws_tours');
      $rs = rows("SELECT s.id, s.office_client_id, s.client_id, s.name, s.address, s.floor_room,
                         s.contact_name, s.contact_phone, s.tournee_id, s.shop_id,
                         s.site_access_minutes, s.active, f.name AS office_name" .
                 ($hasT ? ", t.name AS tour_name" : ", NULL AS tour_name") . "
                    FROM ws_office_delivery_sites s
                    LEFT JOIN ws_offices f ON f.id = s.office_client_id" .
                 ($hasT ? " LEFT JOIN ws_tours t ON t.id = s.tournee_id" : "") . "
                   WHERE " . $scope('s.shop_id') . " AND s.active=1 ORDER BY s.name LIMIT 1000");
      json_out(array_map(fn ($s2) => [
        'id' => (int) $s2['id'], 'office_client_id' => $s2['office_client_id'] !== null ? (int) $s2['office_client_id'] : null,
        'client_id' => $s2['client_id'], 'bureau' => $s2['office_name'] ?: ($s2['name'] ?: '—'),
        'office' => $s2['office_name'] ?: '—', 'name' => $s2['name'] ?: '—',
        'adr' => $s2['address'] ?: '—', 'address' => $s2['address'] ?: '—',
        'etage' => $s2['floor_room'] ?: '—', 'floor_room' => $s2['floor_room'] ?: '—',
        'contact_name' => $s2['contact_name'] ?: '—', 'contact_phone' => $s2['contact_phone'] ?: '—',
        'tour' => $s2['tour_name'] ?: '—', 'tournee_id' => $s2['tournee_id'] !== null ? (int) $s2['tournee_id'] : null,
        'acc' => (float) $s2['site_access_minutes'], 'site_access_minutes' => (float) $s2['site_access_minutes'],
        'shop_id' => $s2['shop_id'], 'active' => (bool) $s2['active'],
      ], $rs));
    }

    // ── Offices / bureaux (ws_offices) — table réelle. ──
    if ($m === 'GET' && $p === '/franchisee/ws-offices') {
      if (!$tblExists('ws_offices')) json_out([]);
      $join = ''; $wh = '1=1'; $tourSel = "NULL AS tour";
      if ($tblExists('ws_tours')) {
        $join = "LEFT JOIN ws_tours t ON t.id = f.tour_id";
        $tourSel = "t.name AS tour";
        if ($shopId) $wh = col_exists('ws_offices', 'shop_id')
          ? "(f.shop_id = " . (int) $shopId . " OR f.shop_id IS NULL)"
          : "(t.shop_id = " . (int) $shopId . " OR f.tour_id IS NULL)";
      }
      $siteSel = $tblExists('ws_office_delivery_sites')
        ? ", (SELECT s.address FROM ws_office_delivery_sites s WHERE s.office_client_id=f.id AND s.active=1 ORDER BY s.id LIMIT 1) AS site"
        : ", NULL AS site";
      $notesSel = col_exists('ws_offices', 'delivery_notes') ? ", f.delivery_notes" : ", NULL AS delivery_notes";
      $rs = rows("SELECT f.id, f.tour_id, $tourSel, f.name, f.address, f.postal_code, f.city, f.contact,
                            f.email, f.phone, f.vat, f.status, f.deferred_billing_enabled$notesSel$siteSel
                       FROM ws_offices f $join WHERE $wh AND f.active=1 ORDER BY f.name LIMIT 300");
      // deferred en Oui/Non : valeurs du toggle du formulaire Office.
      json_out(array_map(fn ($f) => ['deferred_billing_enabled' => ((int) $f['deferred_billing_enabled'] ? 'Oui' : 'Non')] + $f, $rs));
    }

    // ── Emails bureau (ws_office_emails) — dérivés des contacts ws_offices. ──
    if ($m === 'GET' && $p === '/franchisee/ws-office-emails') {
      if (!$tblExists('ws_offices')) json_out([]);
      $rs = rows("SELECT name, email FROM ws_offices WHERE email IS NOT NULL AND email <> '' AND active=1 ORDER BY name LIMIT 300");
      json_out(array_map(fn ($f) => ['bureau' => $f['name'], 'addr' => $f['email'], 'role' => 'Principal'], $rs));
    }

    // ── Départements B2B (b2b_client_company_department) — table ERP si synchronisée. ──
    if ($m === 'GET' && $p === '/franchisee/b2b-departments') {
      if (!$tblExists('b2b_client_company_department')) json_out([]);
      json_out(rows("SELECT * FROM b2b_client_company_department LIMIT 500"));
    }

    // ── Menu « Clients » — clients (table ERP client) rattachés aux bureaux. ──
    //    Une ligne par client, avec les signaux de badges : commandes/récurrence
    //    (ws_orders.customer_id), voucher nominatif (ws_vouchers.client_id),
    //    réclamation client (ws_incidents.client_id — migration 0025), achats
    //    magasin (pwa_purchases si présente), bureau/tournée via ws_offices,
    //    différé au niveau bureau. Cloisonné boutique (preferred/id_main_shop).
    if ($m === 'GET' && $p === '/franchisee/b2b-clients') {
      if (!$tblExists('client')) json_out([]);
      $cc = fn ($c) => col_exists('client', $c);
      $sel = "c.id, c.name, c.surname, c.email, c.phone, c.zip";
      foreach (['company_name','phone_e164','locality','city','is_b2b','office_id','department_id','active','tax_number','office_delivery'] as $col)
        if ($cc($col)) $sel .= ", c.$col";
      $sel .= $cc('status') ? ", c.status" : ", 0 AS status";
      $sel .= $cc('blocked') ? ", c.blocked" : ", 0 AS blocked";
      $sel .= $cc('pwa_user') ? ", c.pwa_user" : ", 0 AS pwa_user";
      $sel .= $cc('webshop_user') ? ", c.webshop_user" : ", 0 AS webshop_user";
      $sel .= $cc('fidelity_active') ? ", c.fidelity_active" : ", 0 AS fidelity_active";
      $sel .= $cc('invoice_vat') ? ", c.invoice_vat" : ", NULL AS invoice_vat";
      $sel .= $cc('created_at') ? ", c.created_at" : ", NULL AS created_at";
      // Commandes webshop : nb, dernière, 90 derniers jours (récurrence), CA.
      if ($tblExists('ws_orders'))
        $sel .= ", (SELECT COUNT(*) FROM ws_orders o WHERE o.customer_id=c.id) AS orders_count,
                  (SELECT MAX(o.created_at) FROM ws_orders o WHERE o.customer_id=c.id) AS last_order,
                  (SELECT COUNT(*) FROM ws_orders o WHERE o.customer_id=c.id AND o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)) AS orders_90d,
                  (SELECT COALESCE(SUM(o.total),0) FROM ws_orders o WHERE o.customer_id=c.id) AS orders_total";
      else $sel .= ", 0 AS orders_count, NULL AS last_order, 0 AS orders_90d, 0 AS orders_total";
      // Vouchers nominatifs (ws_client_vouchers — migration 0025) : actif / consommé.
      if ($tblExists('ws_client_vouchers'))
        $sel .= ", (SELECT COUNT(*) FROM ws_client_vouchers v WHERE v.client_id=c.id AND v.active=1 AND v.used_count < v.max_uses) AS voucher_active,
                  (SELECT COUNT(*) FROM ws_client_vouchers v WHERE v.client_id=c.id AND v.used_count > 0) AS voucher_used";
      else $sel .= ", 0 AS voucher_active, 0 AS voucher_used";
      // Réclamation CLIENT ouverte (≠ incident de livraison, sans client_id).
      if ($tblExists('ws_incidents') && col_exists('ws_incidents', 'client_id'))
        $sel .= ", (SELECT COUNT(*) FROM ws_incidents i WHERE i.client_id=c.id AND i.resolved_at IS NULL) AS complaint_open";
      else $sel .= ", 0 AS complaint_open";
      // Achats en magasin (tickets PWA/ERP — table externe, présence non garantie).
      $sel .= $tblExists('pwa_purchases')
        ? ", (SELECT COUNT(*) FROM pwa_purchases pp WHERE pp.client_id=c.id) AS shop_buys"
        : ", NULL AS shop_buys";
      $joins = ""; $offCols = ", NULL AS office_name, NULL AS tour_name, NULL AS site_name, 0 AS deferred";
      if ($cc('office_id') && $tblExists('ws_offices')) {
        $joins .= " LEFT JOIN ws_offices wo ON wo.id = c.office_id AND wo.active = 1";
        $offCols = ", wo.name AS office_name, wo.deferred_billing_enabled AS deferred";
        $offCols .= (col_exists('ws_offices', 'tour_id') && $tblExists('ws_tours'))
          ? ", (SELECT t.name FROM ws_tours t WHERE t.id = wo.tour_id) AS tour_name" : ", NULL AS tour_name";
        $offCols .= $tblExists('ws_office_delivery_sites')
          ? ", (SELECT COALESCE(NULLIF(TRIM(s.name),''), s.address) FROM ws_office_delivery_sites s
                 WHERE s.office_client_id = wo.id AND s.active=1 ORDER BY s.id LIMIT 1) AS site_name" : ", NULL AS site_name";
      }
      // Département : d'abord le rattachement direct (client.department_id —
      // migration 0027), sinon la liaison au niveau société (id_client/legacy).
      $dep = ", NULL AS department";
      if ($tblExists('b2b_client_company_department')) {
        $byId = $cc('department_id')
          ? "(SELECT d.name FROM b2b_client_company_department d WHERE d.id = c.department_id LIMIT 1)" : "NULL";
        $byCo = "NULL";
        if (col_exists('b2b_client_company_department', 'id_client'))
          $byCo = "(SELECT d.name FROM b2b_client_company_department d WHERE d.id_client = c.id ORDER BY d.id LIMIT 1)";
        elseif (col_exists('b2b_client_company_department', 'client_id'))
          $byCo = "(SELECT d.name FROM b2b_client_company_department d WHERE d.client_id = CAST(c.id AS CHAR) ORDER BY d.id LIMIT 1)";
        $dep = ", COALESCE($byId, $byCo) AS department";
      }
      $where = "1=1";
      if ($shopId) {
        $where = $cc('preferred_shop_id')
          ? "COALESCE(c.preferred_shop_id, c.id_main_shop) = " . (int) $shopId
          : "c.id_main_shop = " . (int) $shopId;
      }
      // Soft delete : un client « supprimé » (active=0) n'apparaît plus.
      if ($cc('active')) $where .= " AND (c.active = 1 OR c.active IS NULL)";
      // Pas de LIMIT : la liste clients doit être complète.
      json_out(rows("SELECT $sel$offCols$dep FROM client c$joins WHERE $where ORDER BY c.id DESC"));
    }

    // ── Liste clients : rattachement à un office (+ département facultatif). ──
    if ($m === 'POST' && $p === '/franchisee/client-attach') {
      $b = body();
      $id = (int) ($b['id'] ?? 0);
      if (!$id) json_out(['ok' => false, 'error' => 'id manquant'], 400);
      $sets = []; $args = [];
      if (array_key_exists('office_id', $b) && col_exists('client', 'office_id')) {
        $ov = ($b['office_id'] === null || $b['office_id'] === '') ? null : (int) $b['office_id'];
        if ($ov !== null && $tblExists('ws_offices') && !row("SELECT id FROM ws_offices WHERE id=?", [$ov]))
          json_out(['ok' => false, 'error' => 'office inconnu'], 400);
        $sets[] = "office_id=?"; $args[] = $ov;
      }
      if (array_key_exists('department_id', $b) && col_exists('client', 'department_id')) {
        $dv = ($b['department_id'] === null || $b['department_id'] === '') ? null : (int) $b['department_id'];
        if ($dv !== null && $tblExists('b2b_client_company_department')
            && !row("SELECT id FROM b2b_client_company_department WHERE id=?", [$dv]))
          json_out(['ok' => false, 'error' => 'département inconnu'], 400);
        $sets[] = "department_id=?"; $args[] = $dv;
      }
      if (!$sets) json_out(['ok' => false, 'error' => 'rien à rattacher (office_id / department_id)'], 400);
      $args[] = $id;
      q("UPDATE client SET " . implode(', ', $sets) . " WHERE id=?", $args);
      json_out(['ok' => true]);
    }

    // ── Liste clients : « suppression » = soft delete (client.active=0). ──
    if ($m === 'POST' && $p === '/franchisee/client-active') {
      $b = body();
      $id = (int) ($b['id'] ?? 0);
      if (!$id || !col_exists('client', 'active')) json_out(['ok' => false, 'error' => 'id ou colonne active manquant'], 400);
      q("UPDATE client SET active=? WHERE id=?", [!empty($b['active']) ? 1 : 0, $id]);
      json_out(['ok' => true, 'active' => !empty($b['active'])]);
    }

    // ── Fiche client : ajout / édition du code postal (client.zip). ──
    if ($m === 'POST' && $p === '/franchisee/client-zip') {
      $b = body();
      $id = (int) ($b['id'] ?? 0);
      $zip = trim((string) ($b['zip'] ?? ''));
      if (!$id) json_out(['ok' => false, 'error' => 'id manquant'], 400);
      if (!preg_match('/^\d{4}$/', $zip)) json_out(['ok' => false, 'error' => 'code postal invalide (4 chiffres)'], 400);
      q("UPDATE client SET zip=? WHERE id=?", [$zip, $id]);
      json_out(['ok' => true, 'zip' => $zip]);
    }

    // ── Livraison au bureau (client.office_delivery) = VALIDATION MANUELLE du
    //    franchisé. Activer : valide le client (status=0), déclenche le trigger
    //    trg_client_office_delivery_au (0023) qui crée/réactive l'office, puis
    //    l'office est marqué VALIDÉ → livrable. Désactiver : le trigger
    //    désactive l'office.
    if ($m === 'POST' && $p === '/franchisee/client-office-delivery') {
      $b = body();
      $id = (int) ($b['id'] ?? 0);
      if (!$id || !col_exists('client', 'office_delivery')) json_out(['ok' => false, 'error' => 'id ou colonne office_delivery manquant'], 400);
      $on = !empty($b['enabled']) ? 1 : 0;
      // Activer force is_b2b=1 (le trigger ne crée l'office QUE si is_b2b=1 —
      // un client « personne morale » via invoice_vat seul serait sinon ignoré)
      // et valide le client (status=0).
      $extra = "";
      if ($on) {
        if (col_exists('client', 'status')) $extra .= ", status=0";
        if (col_exists('client', 'is_b2b')) $extra .= ", is_b2b=1";
      }
      q("UPDATE client SET office_delivery=?$extra WHERE id=?", [$on, $id]);
      $officeName = null; $tourName = null;
      if ($on && $tblExists('ws_offices')) {
        q("UPDATE ws_offices SET status='validated', active=1 WHERE client_id=?", [$id]);
        $off = row("SELECT id, name, tour_id FROM ws_offices WHERE client_id=? AND active=1 ORDER BY id DESC LIMIT 1", [$id]);
        // AUTO-RÉPARATION : client activé sans office (état antérieur aux
        // correctifs — trigger non déclenché) → création directe, sans
        // dépendre d'une transition de colonne.
        if (!$off && col_exists('ws_offices', 'client_id')) {
          $c = row("SELECT * FROM client WHERE id=?", [$id]);
          if ($c) {
            $offName = trim((string) ($c['company_name'] ?? '')) ?: (trim((string) ($c['name'] ?? '')) ?: ('Client #' . $id));
            q("INSERT INTO ws_offices (client_id, shop_id, name, postal_code, city, email, phone, status, active)
                 VALUES (?,?,?,?,?,?,?, 'validated', 1)
                 ON DUPLICATE KEY UPDATE active=1, status='validated'",
              [$id, ((int) ($c['id_main_shop'] ?? 0)) ?: ($shopId ?: null), $offName,
               $c['zip'] ?? null, $c['locality'] ?? ($c['city'] ?? null), $c['email'] ?? null, $c['phone'] ?? null]);
            $off = row("SELECT id, name, tour_id FROM ws_offices WHERE client_id=? AND active=1 ORDER BY id DESC LIMIT 1", [$id]);
          }
        }
        $officeName = $off['name'] ?? null;
        // Lien INVERSE indispensable : le GET b2b-clients projette bureau/site/
        // tournée via client.office_id — sans lui, tout disparaît au reload.
        if ($off && col_exists('client', 'office_id'))
          q("UPDATE client SET office_id=? WHERE id=?", [(int) $off['id'], $id]);
        // SITE choisi dans la modale : le bureau y est rattaché et hérite de la
        // tournée du site (la tournée est déterminée par le site).
        $siteAdr = trim((string) ($b['site_adr'] ?? ''));
        if ($off && $siteAdr !== '' && $tblExists('ws_office_delivery_sites')) {
          $tpl = row("SELECT name, tournee_id, site_access_minutes, shop_id FROM ws_office_delivery_sites
                       WHERE TRIM(COALESCE(address,''))=? AND active=1 ORDER BY id LIMIT 1", [$siteAdr]);
          $ex = row("SELECT id FROM ws_office_delivery_sites WHERE office_client_id=? AND active=1 LIMIT 1", [(int) $off['id']]);
          if ($ex) q("UPDATE ws_office_delivery_sites SET address=?, name=COALESCE(?, name), tournee_id=COALESCE(?, tournee_id), active=1 WHERE id=?",
                     [$siteAdr, $tpl['name'] ?? null, $tpl['tournee_id'] ?? null, (int) $ex['id']]);
          else q("INSERT INTO ws_office_delivery_sites (office_client_id, name, address, tournee_id, site_access_minutes, active, shop_id)
                    VALUES (?,?,?,?,?,1,?)",
                 [(int) $off['id'], $tpl['name'] ?? null, $siteAdr, $tpl['tournee_id'] ?? null,
                  $tpl['site_access_minutes'] ?? 6, $shopId ?: ($tpl['shop_id'] ?? null)]);
          if (($tpl['tournee_id'] ?? null) !== null) {
            if (col_exists('ws_offices', 'tour_id'))
              q("UPDATE ws_offices SET tour_id=? WHERE id=?", [(int) $tpl['tournee_id'], (int) $off['id']]);
            $t = $tblExists('ws_tours') ? row("SELECT name FROM ws_tours WHERE id=?", [(int) $tpl['tournee_id']]) : null;
            $tourName = $t['name'] ?? null;
          }
        }
      }
      json_out(['ok' => true, 'enabled' => (bool) $on, 'office' => $officeName, 'tour' => $tourName]);
    }

    // ── Fiche client : blocage commercial (client.blocked — migration 0025). ──
    if ($m === 'POST' && $p === '/franchisee/client-block') {
      $b = body();
      $id = (int) ($b['id'] ?? 0);
      if (!$id || !col_exists('client', 'blocked')) json_out(['ok' => false, 'error' => 'id ou colonne blocked manquant'], 400);
      q("UPDATE client SET blocked=? WHERE id=?", [!empty($b['blocked']) ? 1 : 0, $id]);
      json_out(['ok' => true, 'blocked' => !empty($b['blocked'])]);
    }

    // ── Fiche client : facturation personne morale (toggle + TVA VIES obligatoire). ──
    if ($m === 'POST' && $p === '/franchisee/client-billing') {
      $b = body();
      $id = (int) ($b['id'] ?? 0);
      if (!$id) json_out(['ok' => false, 'error' => 'id manquant'], 400);
      $corp = !empty($b['corporate']);
      $vat  = strtoupper(preg_replace('/[^A-Za-z0-9+*]/', '', (string) ($b['vat'] ?? '')));
      if ($corp && $vat === '') json_out(['ok' => false, 'error' => 'TVA (VIES) obligatoire pour une personne morale'], 400);
      if ($corp && !preg_match('/^[A-Z]{2}[0-9A-Z+*]{2,12}$/', $vat))
        json_out(['ok' => false, 'error' => 'Format TVA invalide (ex. BE0123456789) — vérifiez via VIES'], 400);
      $sets = []; $args = [];
      if (col_exists('client', 'invoice_vat')) { $sets[] = "invoice_vat=?"; $args[] = $corp ? $vat : null; }
      if (col_exists('client', 'tax_number') && $corp) { $sets[] = "tax_number=?"; $args[] = $vat; }
      if (col_exists('client', 'is_b2b') && $corp) $sets[] = "is_b2b=1";
      // Rétrogradation en PARTICULIER : sans ces resets le badge « Personne
      // morale » (is_b2b/tax_number) resterait pour toujours.
      if (!$corp) {
        if (col_exists('client', 'is_b2b')) $sets[] = "is_b2b=0";
        if (col_exists('client', 'tax_number')) $sets[] = "tax_number=NULL";
      }
      if (!$sets) json_out(['ok' => false, 'error' => 'aucune colonne facturation disponible'], 501);
      $args[] = $id;
      q("UPDATE client SET " . implode(', ', $sets) . " WHERE id=?", $args);
      json_out(['ok' => true, 'corporate' => $corp, 'vat' => $corp ? $vat : null]);
    }

    // ── Fiche client : voucher / remboursement nominatif (ws_client_vouchers). ──
    //    Table dédiée (0025) — ws_vouchers est une VUE (0007), non inscriptible.
    if ($m === 'POST' && $p === '/franchisee/client-voucher') {
      $b = body();
      $cid = (int) ($b['client_id'] ?? 0);
      $val = (float) ($b['value'] ?? 0);
      if (!$cid || $val <= 0) json_out(['ok' => false, 'error' => 'client_id et value (>0) requis'], 400);
      if (!$tblExists('ws_client_vouchers'))
        json_out(['ok' => false, 'error' => 'ws_client_vouchers absente (migration 0025)'], 501);
      $type = in_array(($b['type'] ?? ''), ['percent', 'fixed'], true) ? $b['type'] : 'fixed';
      $code = 'RB-' . $cid . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
      q("INSERT INTO ws_client_vouchers (client_id, shop_id, code, type, value, max_uses, used_count, active)
           VALUES (?,?,?,?,?,1,0,1)", [$cid, $shopId ?: null, $code, $type, $val]);
      json_out(['ok' => true, 'code' => $code, 'type' => $type, 'value' => $val]);
    }

    // ── Fiche client : commandes du client (droplist réclamation + note ★1-5). ──
    if ($m === 'GET' && $p === '/franchisee/client-orders') {
      $cid = (int) qp('client_id', 0);
      if (!$cid || !$tblExists('ws_orders')) json_out([]);
      $hasRating = col_exists('ws_orders', 'rating');
      json_out(rows("SELECT id, order_ref, created_at, total, status" .
                    ($hasRating ? ", rating" : ", NULL AS rating") . "
                      FROM ws_orders WHERE customer_id=" . $cid .
                     ($shopId ? " AND shop_id=" . (int) $shopId : "") . "
                     ORDER BY created_at DESC LIMIT 50"));
    }

    // ── Fiche client : réclamation client mécontent (ws_incidents.client_id). ──
    if ($m === 'POST' && $p === '/franchisee/client-complaint') {
      $b = body();
      if (!$tblExists('ws_incidents') || !col_exists('ws_incidents', 'client_id'))
        json_out(['ok' => false, 'error' => 'ws_incidents.client_id absente (migration 0025)'], 501);
      if (!empty($b['resolve_client_id'])) {
        q("UPDATE ws_incidents SET resolved_at=NOW(), status='resolved' WHERE client_id=? AND resolved_at IS NULL", [(int) $b['resolve_client_id']]);
        json_out(['ok' => true, 'resolved' => true]);
      }
      $cid = (int) ($b['client_id'] ?? 0);
      if (!$cid) json_out(['ok' => false, 'error' => 'client_id requis'], 400);
      if (!$shopId) json_out(['ok' => false, 'error' => 'boutique requise (?shop=)'], 400);
      // Réclamation rattachée à un ACHAT : order_id optionnel, mais s'il est
      // fourni la commande doit appartenir à ce client (order_ref dénormalisée).
      $oid = (int) ($b['order_id'] ?? 0); $oref = null;
      if ($oid) {
        $or = $tblExists('ws_orders') ? row("SELECT id, order_ref FROM ws_orders WHERE id=? AND customer_id=?", [$oid, $cid]) : null;
        if (!$or) json_out(['ok' => false, 'error' => 'commande inconnue pour ce client'], 400);
        $oref = $or['order_ref'];
      }
      q("INSERT INTO ws_incidents (shop_id, order_id, order_ref, type, severity, status, title, description, client_id)
           VALUES (?,?,?,?,?,?,?,?,?)",
        [(int) $shopId, $oid ?: null, $oref, 'litige', 'medium', 'open',
         mb_substr(trim((string) ($b['title'] ?? 'Réclamation client')), 0, 180),
         trim((string) ($b['description'] ?? '')), $cid]);
      json_out(['ok' => true, 'order_ref' => $oref]);
    }

    // ── Horaires tournées (ws_tour_availability) — fenêtres agrégées par tournée. ──
    if ($m === 'GET' && $p === '/franchisee/ws-tour-availability') {
      if (!$tblExists('ws_tour_availability') || !$tblExists('ws_tours')) json_out([]);
      $rs = rows("SELECT t.name AS tour,
                         GROUP_CONCAT(DISTINCT av.delivery_day ORDER BY av.delivery_day) AS days,
                         TIME_FORMAT(MIN(av.delivery_start),'%H:%i') AS dep,
                         TIME_FORMAT(MAX(av.delivery_end),'%H:%i')   AS fin,
                         TIME_FORMAT(MIN(av.cutoff_time),'%H:%i')    AS cut,
                         MAX(av.max_orders) AS cap
                    FROM ws_tour_availability av JOIN ws_tours t ON t.id = av.tour_id
                   WHERE " . $scope('av.shop_id') . " AND av.active=1
                   GROUP BY t.id, t.name ORDER BY t.name LIMIT 100");
      json_out(array_map(function ($r) use ($DAYS) {
        $jours = implode(' · ', array_map(fn ($d) => $DAYS[(((int) $d) + 6) % 7], explode(',', (string) $r['days'])));
        return ['tour' => $r['tour'], 'jour' => $jours ?: '—', 'dep' => $r['dep'], 'fin' => $r['fin'],
                'cut' => $r['cut'] . ' J-1', 'cap' => (string) ((int) $r['cap'] ?: '—')];
      }, $rs));
    }

    // ── Fermetures ponctuelles (ws_tour_closures). ──
    if ($m === 'GET' && $p === '/franchisee/ws-tour-closures') {
      if (!$tblExists('ws_tour_closures')) json_out([]);
      $ctSel = col_exists('ws_tour_closures', 'closure_type') ? "cl.closure_type" : "NULL";
      $rs = rows("SELECT COALESCE(t.name,'Toutes les tournées') AS tour,
                         DATE_FORMAT(cl.closure_date,'%d/%m/%Y') AS date, COALESCE(cl.reason,'—') AS motif,
                         COALESCE($ctSel,'Fermeture') AS ctype
                    FROM ws_tour_closures cl LEFT JOIN ws_tours t ON t.id = cl.tour_id
                   WHERE " . ($shopId ? "(t.shop_id = " . (int) $shopId . " OR cl.tour_id IS NULL)" : '1=1') . "
                   ORDER BY cl.closure_date LIMIT 100");
      json_out(array_map(fn ($r) => ['tour' => $r['tour'], 'date' => $r['date'],
        'type' => $r['ctype'], 'motif' => $r['motif']], $rs));
    }

    // ── Règles calendrier (ws_calendar_rules). ──
    if ($m === 'GET' && $p === '/franchisee/ws-calendar-rules') {
      if (!$tblExists('ws_calendar_rules')) json_out([]);
      $rs = rows("SELECT mode, open_days, cutoff_hour, cutoff_minutes, lead_hours FROM ws_calendar_rules
                   WHERE " . $scope('shop_id') . " AND active=1 ORDER BY mode LIMIT 50");
      json_out(array_map(function ($r) use ($DAYS) {
        $days = json_decode((string) $r['open_days'], true);
        $jours = is_array($days) ? implode(' · ', array_map(fn ($d) => $DAYS[(((int) $d) + 6) % 7], $days)) : '—';
        return ['mode' => $r['mode'] === 'delivery' ? 'Livraison' : 'Retrait', 'days' => $jours,
                'cut' => sprintf('%02d:%02d J-1', (int) $r['cutoff_hour'], (int) $r['cutoff_minutes']),
                'lead' => ((int) $r['lead_hours']) . ' h'];
      }, $rs));
    }

    // ── Créneaux (ws_slots). ──
    if ($m === 'GET' && $p === '/franchisee/ws-slots') {
      if (!$tblExists('ws_slots')) json_out([]);
      $rs = rows("SELECT mode, label FROM ws_slots WHERE " . $scope('shop_id') . " AND active=1 ORDER BY sort_order, label LIMIT 100");
      json_out(array_map(fn ($r) => ['mode' => $r['mode'] === 'delivery' ? 'Livraison' : 'Retrait',
        'libelle' => $r['label'], 'plage' => $r['label'], 'cap' => 0], $rs));
    }

    // ── Bons locaux (ws_vouchers_local) — ws_vouchers boutique + marque. ──
    if ($m === 'GET' && $p === '/franchisee/ws-vouchers-local') {
      if (!$tblExists('ws_vouchers')) json_out([]);
      $sw = $shopId ? "(shop_id = " . (int) $shopId . " OR shop_id IS NULL)" : '1=1';
      $rs = rows("SELECT code, type, value, expires_at, shop_id FROM ws_vouchers WHERE $sw AND active=1 ORDER BY code LIMIT 200");
      json_out(array_map(function ($v) {
        $val = $v['type'] === 'percent' ? '−' . rtrim(rtrim((string) $v['value'], '0'), '.') . ' %'
             : ($v['type'] === 'fixed' ? '−' . rtrim(rtrim((string) $v['value'], '0'), '.') . ' €' : $v['type']);
        return ['code' => $v['code'], 'valeur' => $val, 'type' => $v['type'],
                'validite' => $v['expires_at'] ? ('jusqu\'au ' . substr($v['expires_at'], 0, 10)) : 'permanent',
                'loc' => $v['shop_id'] !== null];
      }, $rs));
    }

    // ── Règles de prix locales (ws_pricing_rules_local) — ws_pricing_rules. ──
    if ($m === 'GET' && $p === '/franchisee/ws-pricing-rules-local') {
      if (!$tblExists('ws_pricing_rules')) json_out([]);
      $sw = $shopId ? "(shop_id = " . (int) $shopId . " OR shop_id IS NULL)" : '1=1';
      $rs = rows("SELECT rule_type, label, x, y, threshold, shop_id FROM ws_pricing_rules WHERE $sw AND active=1 ORDER BY id LIMIT 200");
      json_out(array_map(function ($r) {
        $effet = $r['rule_type'] === 'cross_portion' ? ((int) $r['x'] . ' achetés → ' . (int) $r['y'] . ' offert(s)') : (string) ($r['threshold'] ?? '—');
        return ['nom' => $r['label'] ?: $r['rule_type'], 'cible' => $r['rule_type'], 'effet' => $effet,
                'loc' => $r['shop_id'] !== null];
      }, $rs));
    }

    // ── Jours exceptionnels (ws_shop_exceptions) — table réelle. ──
    if ($m === 'GET' && $p === '/franchisee/ws-shop-exceptions') {
      if (!$tblExists('ws_shop_exceptions')) json_out([]);
      $rs = rows("SELECT DATE_FORMAT(exception_date,'%d/%m/%Y') AS date, type, COALESCE(reason,'—') AS reason
                    FROM ws_shop_exceptions WHERE " . $scope('shop_id') . " ORDER BY exception_date LIMIT 100");
      json_out(array_map(fn ($r) => ['date' => $r['date'],
        'label' => $r['reason'], 'type' => $r['type'] === 'closed' ? 'Fermé' : 'Horaire spécial',
        'detail' => $r['reason']], $rs));
    }

    // ── Moyens de paiement (ws_payment_methods) — par profil (webshop/comptoir/bureau). ──
    if ($m === 'GET' && $p === '/franchisee/ws-payment-methods') {
      if (!$shopId) json_out([]);   // dépend d'une boutique
      $guest = allowed_methods($shopId, 'guest');
      $reg   = allowed_methods($shopId, 'registered');
      $comp  = allowed_methods($shopId, 'company');
      $all = array_values(array_unique(array_merge($guest, $reg, $comp)));
      json_out(array_map(fn ($mm) => ['nom' => payment_label($mm),
        'dw' => in_array($mm, $guest, true), 'dc' => in_array($mm, $reg, true),
        'db' => in_array($mm, $comp, true)], $all));
    }

    // ── Config livraison par bureau (ws_office_delivery_settings) — dérivée. ──
    if ($m === 'GET' && $p === '/franchisee/ws-office-delivery-settings') {
      if (!$tblExists('ws_offices') || !$tblExists('ws_tours')) json_out([]);
      $hasAv = $tblExists('ws_tour_availability');
      $rs = rows("SELECT f.name, f.deferred_billing_enabled, f.drop_minutes, f.tour_id, t.name AS tour
                    FROM ws_offices f JOIN ws_tours t ON t.id = f.tour_id
                   WHERE " . $scope('t.shop_id') . " AND f.active=1 ORDER BY f.name LIMIT 200");
      json_out(array_map(function ($f) use ($hasAv) {
        $daysArr = []; $cut = '—';
        if ($hasAv) {
          $av = rows("SELECT DISTINCT delivery_day, TIME_FORMAT(MIN(cutoff_time),'%H:%i') AS cut
                        FROM ws_tour_availability WHERE tour_id=? AND active=1 GROUP BY delivery_day", [(int) $f['tour_id']]);
          foreach ($av as $a) { $daysArr[] = (int) $a['delivery_day']; $cut = $a['cut'] . ' J-1'; }
        }
        return ['bureau' => $f['name'], 'tour' => $f['tour'],
                'contrat' => $f['deferred_billing_enabled'] ? 'Facturation différée' : 'Comptant',
                'daysArr' => $daysArr, 'cut' => $cut, 'drop' => (float) $f['drop_minutes']];
      }, $rs));
    }

    // ── Paramètres (ws_param clé/valeur) — '0'/'1' exposés en bool (toggles UI). ──
    if ($m === 'GET' && $p === '/franchisee/params') {
      if (!$tblExists('ws_param')) json_out([]);
      $ps = rows("SELECT param_key, param_value FROM ws_param ORDER BY param_key");
      json_out(array_map(function ($x) {
        $v = (string) $x['param_value'];
        if (in_array($v, ['0', '1', 'true', 'false'], true)) {
          return ['cle' => $x['param_key'], 'type' => 'bool', 'val' => ($v === '1' || $v === 'true')];
        }
        return ['cle' => $x['param_key'], 'type' => 'text', 'val' => $v];
      }, $ps));
    }

    // ── Barème de frais en cascade (ws_delivery_fee_rules — table réelle du schéma). ──
    if ($m === 'GET' && $p === '/franchisee/ws-delivery-fee-rules') {
      if (!$tblExists('ws_delivery_fee_rules')) json_out([]);
      $sw = $shopId ? "(r.shop_id = " . (int) $shopId . " OR r.shop_id IS NULL)" : '1=1';
      $rs = rows("SELECT r.level, r.free_delivery, r.always_charge, r.fee_amount,
                         r.free_delivery_minimum, r.payment_type,
                         s.name AS site_name, f.name AS office_name, t.name AS tour_name
                    FROM ws_delivery_fee_rules r
                    LEFT JOIN ws_office_delivery_sites s ON s.id = r.site_id
                    LEFT JOIN ws_offices f ON f.id = r.office_client_id
                    LEFT JOIN ws_tours   t ON t.id = r.tour_id
                   WHERE $sw AND r.active=1
                   ORDER BY FIELD(r.level,'site','office','tour','shop','global'), r.id LIMIT 100");
      $lvl  = ['site' => 'Site', 'office' => 'Bureau', 'tour' => 'Tournée', 'shop' => 'Boutique', 'global' => 'Boutique'];
      $pay  = ['immediate' => 'Comptant', 'deferred' => 'Différé', 'office' => 'Facturé au bureau'];
      json_out(array_map(function ($r) use ($lvl, $pay) {
        $cible = $r['site_name'] ?: ($r['office_name'] ?: ($r['tour_name'] ?: 'Toutes livraisons'));
        $montant = $r['free_delivery'] ? 'Offert'
                 : number_format((float) $r['fee_amount'], 2, ',', ' ') . ' €' . ($r['always_charge'] ? ' (toujours)' : '');
        return ['niveau' => $lvl[$r['level']] ?? $r['level'], 'cible' => $cible,
                'franco' => ((float) $r['free_delivery_minimum']) > 0
                            ? number_format((float) $r['free_delivery_minimum'], 0, ',', ' ') . ' €' : '—',
                'montant' => $montant,
                'paiement' => $pay[$r['payment_type']] ?? ($r['payment_type'] ?: '—')];
      }, $rs));
    }

    // ── Zone de chalandise marque (ws_franchisor_catchment — migration 0012). ──
    //    shop_name / shop_city viennent de la table shops : les tuiles
    //    « Magasins » du BO affichent le vrai nom du franchisé.
    if ($m === 'GET' && $p === '/franchisee/ws-franchisor-catchment') {
      if (!$tblExists('ws_franchisor_catchment')) json_out([]);
      $hasShopC = col_exists('ws_franchisor_catchment', 'shop_id');
      $rs = $hasShopC
        ? rows("SELECT c.id, c.name, c.postcodes, c.exclusive, c.shop_id,
                       s.name AS shop_name, s.city AS shop_city
                  FROM ws_franchisor_catchment c
                  LEFT JOIN shops s ON s.id = c.shop_id
                 WHERE c.active=1 ORDER BY c.id")
        : rows("SELECT id, name, postcodes, exclusive, NULL AS shop_id,
                       NULL AS shop_name, NULL AS shop_city
                  FROM ws_franchisor_catchment WHERE active=1 ORDER BY id");
      json_out(array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'],
        'cp' => $r['postcodes'] ?: '—', 'exclusif' => (bool) $r['exclusive'],
        'shop_id' => $r['shop_id'] !== null ? (int) $r['shop_id'] : null,
        'shop_name' => $r['shop_name'] ?: null, 'shop_city' => $r['shop_city'] ?: null], $rs));
    }

    // ── Dispo produit — exceptions réelles : ws_products.active (réseau) +
    //    ws_product_shops.active / no_delivery (boutique). Pas de table dédiée.
    if ($m === 'GET' && $p === '/franchisee/ws-product-availability') {
      if (!$tblExists('ws_products')) json_out([]);
      $out = [];
      $off = rows("SELECT pr.name, c.label AS cat FROM ws_products pr
                     LEFT JOIN ws_categories c ON c.id = pr.cat_id
                    WHERE pr.active = 0 ORDER BY pr.name LIMIT 200");
      foreach ($off as $r) $out[] = ['produit' => $r['name'], 'cat' => $r['cat'] ?: '—', 'rule' => 'Désactivé (réseau)'];
      if ($shopId && $tblExists('ws_product_shops')) {
        $loc = rows("SELECT pr.name, c.label AS cat, ps.active AS ps_active, ps.no_delivery
                       FROM ws_product_shops ps
                       JOIN ws_products pr ON pr.id = ps.product_id
                       LEFT JOIN ws_categories c ON c.id = pr.cat_id
                      WHERE ps.shop_id = " . (int) $shopId . " AND pr.active = 1
                        AND (ps.active = 0 OR ps.no_delivery = 1)
                      ORDER BY pr.name LIMIT 200");
        foreach ($loc as $r) $out[] = ['produit' => $r['name'], 'cat' => $r['cat'] ?: '—',
          'rule' => !$r['ps_active'] ? 'Désactivé boutique' : 'Sans livraison'];
      }
      json_out($out);
    }

    /* ── Écrans TDB / prep / suivi / validations / stock / assortiment ──
       (ex-littéraux JSX dé-hardcodés — servis depuis les vraies tables). */

    // Tournées du jour (TDB) — ws_tours + commandes du jour + tracking.
    if ($m === 'GET' && $p === '/franchisee/fr-tdb-tournees') {
      if (!$tblExists('ws_tours') || !$hasOrders) json_out([]);
      $hasTk = $tblExists('ws_tour_tracking');
      $hasAv = $tblExists('ws_tour_availability');
      $rs = rows("SELECT t.id, t.name" . ($hasTk ? ", tk.driver_name, tk.vehicle, tk.stops_done" : ", NULL AS driver_name, NULL AS vehicle, 0 AS stops_done") . ",
                         (SELECT COUNT(DISTINCT o.office_client_id) FROM ws_orders o WHERE o.tour_id=t.id AND o.delivery_date=?) AS pts,
                         (SELECT COUNT(*) FROM ws_orders o WHERE o.tour_id=t.id AND o.delivery_date=?) AS colis
                    FROM ws_tours t" . ($hasTk ? " LEFT JOIN ws_tour_tracking tk ON tk.tour_id=t.id" : "") . "
                   WHERE " . $scope('t.shop_id') . " AND t.active=1 ORDER BY t.name", [$today, $today]);
      $out = [];
      foreach ($rs as $t) {
        if (!(int) $t['colis']) continue;   // tournée sans commande aujourd'hui → hors TDB
        $dep = '—';
        if ($hasAv) {
          $av = row("SELECT TIME_FORMAT(MIN(delivery_start),'%H:%i') s FROM ws_tour_availability WHERE tour_id=? AND active=1", [(int) $t['id']]);
          if ($av && $av['s']) $dep = $av['s'];
        }
        $out[] = ['nom' => $t['name'], 'chauffeur' => $t['driver_name'] ?: '— non assigné',
                  'vehicule' => $t['vehicle'] ?: '—', 'nbPoints' => (int) $t['pts'], 'colis' => (int) $t['colis'],
                  'depart' => $dep, 'statut' => ((int) $t['stops_done']) > 0 ? 'Prête' : 'En préparation'];
      }
      json_out($out);
    }

    // Arbre TDB : tournée › zone › sites (+ commandes du jour par site).
    if ($m === 'GET' && $p === '/franchisee/fr-tdb-tree') {
      if (!$tblExists('ws_tours') || !$tblExists('ws_office_delivery_sites') || !$hasOrders) json_out([]);
      $hasTk = $tblExists('ws_tour_tracking');
      $hasZ  = $tblExists('ws_delivery_zones');
      $tours = rows("SELECT t.id, t.name" . ($hasZ ? ", z.name AS zone" : ", NULL AS zone") .
                    ($hasTk ? ", tk.driver_name, tk.stops_done" : ", NULL AS driver_name, 0 AS stops_done") . "
                      FROM ws_tours t" . ($hasZ ? " LEFT JOIN ws_delivery_zones z ON z.id=t.zone_id" : "") .
                    ($hasTk ? " LEFT JOIN ws_tour_tracking tk ON tk.tour_id=t.id" : "") . "
                     WHERE " . $scope('t.shop_id') . " AND t.active=1 ORDER BY t.name", []);
      $out = [];
      foreach ($tours as $t) {
        $sites = rows(
          "SELECT s.name AS libelle, COALESCE(s.address,'—') AS ville, s.contact_name, f.name AS office,
                  (SELECT COUNT(*) FROM ws_orders o WHERE o.delivery_site_id = s.id AND o.delivery_date = ?) AS cmd
             FROM ws_office_delivery_sites s LEFT JOIN ws_offices f ON f.id = s.office_client_id
            WHERE s.tournee_id = ? AND s.active = 1 ORDER BY s.name", [$today, (int) $t['id']]);
        $sites = array_values(array_filter($sites, fn ($s2) => (int) $s2['cmd'] > 0));
        if (!$sites) continue;
        $out[] = ['nom' => $t['name'], 'chauffeur' => $t['driver_name'] ?: '— non assigné',
                  'statut' => ((int) $t['stops_done']) > 0 ? 'Prête' : 'En préparation',
                  'zones' => [['nom' => $t['zone'] ?: $t['name'], 'sites' => array_map(fn ($s2) => [
                    'libelle' => $s2['libelle'], 'ville' => $s2['ville'], 'cutoff' => '—',
                    'office' => $s2['office'] ?: '—',
                    'users' => [['nom' => $s2['contact_name'] ?: ($s2['office'] ?: '—'), 'cmd' => (int) $s2['cmd']]],
                  ], $sites)]]];
      }
      json_out($out);
    }

    // Bon de chargement (prep) — colis du jour groupés par site.
    if ($m === 'GET' && $p === '/franchisee/fr-prep-points') {
      if (!$tblExists('ws_office_delivery_sites') || !$hasOrders) json_out([]);
      $rs = rows("SELECT s.name AS libelle, COUNT(*) AS colis
                    FROM ws_orders o JOIN ws_office_delivery_sites s ON s.id = o.delivery_site_id
                   WHERE " . $scope('o.shop_id') . " AND o.delivery_date = ?
                   GROUP BY s.id, s.name ORDER BY COUNT(*) DESC LIMIT 30", [$today]);
      $i = 0;
      json_out(array_map(function ($r) use (&$i) {
        $i++;
        return ['ordre' => $i, 'libelle' => $r['libelle'], 'colisTxt' => ((int) $r['colis']) . ' colis'];
      }, $rs));
    }

    // Suivi live — table chauffeurs (télémétrie réelle ; ETA sans source → «—»).
    if ($m === 'GET' && $p === '/franchisee/fr-live-table') {
      if (!$tblExists('ws_tour_tracking') || !$tblExists('ws_tours')) json_out([]);
      $rs = rows("SELECT tk.driver_name, tk.vehicle, tk.stops_done, tk.stops_total, t.name
                    FROM ws_tour_tracking tk JOIN ws_tours t ON t.id = tk.tour_id
                   WHERE " . $scope('t.shop_id') . " AND tk.driver_name IS NOT NULL ORDER BY t.name LIMIT 20");
      $palette = ['#8D1D2C', '#3B3468', '#2d7a3e', '#C87A3F'];
      $i = 0;
      json_out(array_map(function ($r) use (&$i, $palette) {
        $short = trim(preg_replace('/^Tourn[ée]e\s+/u', '', (string) $r['name']));
        return ['color' => $palette[$i++ % 4], 'nom' => $r['driver_name'], 'vehicule' => $r['vehicle'] ?: '—',
                'tournee' => preg_split('/[\s\/]+/u', $short)[0] ?: $r['name'],
                'avancement' => ((int) $r['stops_done']) . '/' . max(1, (int) $r['stops_total']),
                'next' => '—', 'nextVille' => '—', 'eta' => '—', 'drift' => '—',
                'statut' => ((int) $r['stops_done']) > 0 ? 'En route' : 'En attente'];
      }, $rs));
    }

    // Comptes Office en attente de validation — ws_offices.status='pending'.
    if ($m === 'GET' && $p === '/franchisee/fr-validations') {
      if (!$tblExists('ws_offices')) json_out([]);
      // Scopé boutique : un bureau « pending » n'apparaît que chez SON franchisé
      // (ws_offices.shop_id, posé par le trigger 0021 = client.id_main_shop).
      // shop_id NULL = bureaux historiques d'avant 0021 → visibles partout.
      $vScope = ($shopId && col_exists('ws_offices', 'shop_id'))
        ? " AND (shop_id = " . (int) $shopId . " OR shop_id IS NULL)" : "";
      $rs = rows("SELECT id, name, email, vat, DATE_FORMAT(created_at,'%d/%m') AS d FROM ws_offices
                   WHERE status='pending' AND active=1$vScope ORDER BY created_at DESC LIMIT 50");
      json_out(array_map(function ($r) {
        $init = strtoupper(mb_substr($r['name'], 0, 1) . (preg_match('/\s(\S)/u', $r['name'], $mm) ? $mm[1] : ''));
        return ['id' => 'p' . $r['id'], 'init' => $init, 'raison' => $r['name'], 'email' => $r['email'] ?: '—',
                'segment' => 'horeca', 'tva' => $r['vat'] ?: '—',
                'vies' => $r['vat'] ? 'ok' : 'pending', 'date' => $r['d']];
      }, $rs));
    }

    // Demandes de rattachement bureau — ws_office_join_requests (pending).
    if ($m === 'GET' && $p === '/franchisee/fr-join-requests') {
      if (!$tblExists('ws_office_join_requests')) json_out([]);
      $rs = rows("SELECT r.id, r.office_name_raw, r.address_raw,
                         (SELECT f.name FROM ws_offices f WHERE f.name LIKE CONCAT('%', LEFT(r.office_name_raw, 12), '%') LIMIT 1) AS proche
                    FROM ws_office_join_requests r
                   WHERE " . $scope('r.shop_id') . " AND r.status='pending' ORDER BY r.created_at DESC LIMIT 50");
      json_out(array_map(fn ($r) => ['id' => 'jr' . $r['id'], 'client' => 'Client #' . $r['id'],
        'demande' => '« Rattacher à ' . $r['office_name_raw'] . ' »',
        'proche' => $r['proche'] ? ($r['proche'] . ' (' . $r['address_raw'] . ')') : ($r['office_name_raw'] . ' (' . $r['address_raw'] . ')'),
        'dup' => (bool) $r['proche']], $rs));
    }

    // ── Commandes du jour — RÉEL (ws_orders, portée boutique). Remplace la
    //    liste de démo codée en dur dans le BO (go-live : plus de mock). ──
    if ($m === 'GET' && $p === '/franchisee/fr-orders') {
      if (!$hasOrders) json_out([]);
      $rs = rows("SELECT o.order_ref, COALESCE(NULLIF(o.guest_name,''),'Client webshop') AS client,
                         o.mode, o.total, o.status, o.slot_label,
                         DATE_FORMAT(o.created_at,'%H:%i') AS heure,
                         (SELECT COALESCE(SUM(l.qty),0) FROM ws_order_lines l WHERE l.order_id=o.id) AS pieces
                    FROM ws_orders o
                   WHERE " . $scope('o.shop_id') . "
                     AND (o.delivery_date = ? OR (o.delivery_date IS NULL AND DATE(o.created_at) = ?))
                   ORDER BY o.created_at DESC LIMIT 200", [$today, $today]);
      json_out(array_map(fn ($o) => [
        'ref' => '#' . $o['order_ref'],
        'client' => $o['client'],
        'mode' => ($o['mode'] === 'delivery' ? 'Livraison' : 'Retrait'),
        'montant' => number_format((float) $o['total'], 2, ',', ' ') . ' €',
        'statut' => $o['status'], 'heure' => $o['heure'],
        'creneau' => $o['slot_label'] ?: '—', 'pieces' => (int) $o['pieces'],
      ], $rs));
    }

    // ── Avancement du statut d'une commande (écran Commandes du jour). ──
    if ($m === 'POST' && $p === '/franchisee/order-status') {
      $b = body(); $ref = ltrim(trim((string) ($b['ref'] ?? '')), '#');
      $st = (string) ($b['status'] ?? '');
      $OK = ['pending', 'confirmed', 'preparing', 'ready', 'delivered', 'completed', 'cancelled'];
      if ($ref === '' || !in_array($st, $OK, true)) json_out(['ok' => false, 'error' => 'ref + statut valides requis'], 400);
      if (!$hasOrders) json_out(['ok' => false, 'error' => 'ws_orders absente'], 501);
      q("UPDATE ws_orders SET status=? WHERE order_ref=?" . ($shopId ? " AND shop_id=" . (int) $shopId : ""), [$st, $ref]);
      json_out(['ok' => true, 'status' => $st]);
    }

    // ── Stats réseau — agrégats RÉELS 30 jours (toutes boutiques). ──
    if ($m === 'GET' && $p === '/franchisee/fr-net-stats') {
      if (!$hasOrders) json_out([]);
      $d = row("SELECT COALESCE(SUM(total),0) ca, COUNT(*) n, COALESCE(AVG(total),0) pm,
                       COALESCE(SUM(mode='delivery'),0) deliv
                  FROM ws_orders WHERE status <> 'cancelled'
                   AND created_at >= DATE_SUB(?, INTERVAL 30 DAY)", [$today]);
      $shopsN = (int) (row("SELECT COUNT(*) n FROM $SHOPS WHERE active=1")['n'] ?? 0);
      $pctLiv = ((int) $d['n']) > 0 ? round(100 * (int) $d['deliv'] / (int) $d['n']) : 0;
      json_out([
        ['k' => 'CA réseau (30 j)',   'v' => $eurk((float) $d['ca']),  'sub' => ((int) $d['n']) . ' commandes'],
        ['k' => 'Boutiques actives',  'v' => (string) $shopsN,         'sub' => 'réseau'],
        ['k' => 'Part livraison',     'v' => $pctLiv . ' %',           'sub' => 'vs retrait'],
        ['k' => 'Panier moyen',       'v' => number_format((float) $d['pm'], 2, ',', ' ') . ' €', 'sub' => '30 jours'],
      ]);
    }

    // ── Capacité / calendrier — RÉEL : créneaux (ws_slots) × réservations
    //    (ws_orders.slot_id par delivery_date), 5 prochains jours. ──
    if ($m === 'GET' && $p === '/franchisee/fr-capacity') {
      if (!$tblExists('ws_slots')) json_out([]);
      $slots = rows("SELECT id, label, mode FROM ws_slots WHERE " . $scope('shop_id') . " AND active=1 ORDER BY sort_order, label LIMIT 20");
      if (!$slots) json_out([]);
      $hasCap = $tblExists('ws_slot_capacity');
      $J = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
      $days = [];
      for ($i = 0; $i < 5; $i++) {
        $dt = date('Y-m-d', strtotime($today . " +$i day"));
        $days[] = ['date' => $dt, 'label' => $J[(int) date('w', strtotime($dt))] . ' ' . (int) date('d', strtotime($dt))];
      }
      $out = [];
      foreach ($slots as $s) {
        $cells = []; $maxDefault = 0;
        foreach ($days as $dy) {
          $n = $hasOrders ? (int) (row("SELECT COUNT(*) n FROM ws_orders
                                         WHERE slot_id=? AND delivery_date=? AND status<>'cancelled'" .
                                        ($shopId ? " AND shop_id=" . (int) $shopId : ""), [(int) $s['id'], $dy['date']])['n'] ?? 0) : 0;
          // Capacité du jour : ws_slot_capacity (par boutique × mode × date) —
          // 0 = pas de plafond défini pour ce créneau/jour.
          $mx = 0;
          if ($hasCap && $shopId) {
            $cp = row("SELECT COALESCE(SUM(max_orders),0) mx, COALESCE(SUM(current_orders),0) cur
                        FROM ws_slot_capacity WHERE shop_id=? AND mode=? AND slot_date=?",
                      [(int) $shopId, $s['mode'], $dy['date']]);
            $mx = (int) ($cp['mx'] ?? 0);
            if ($mx > 0 && !$n) $n = (int) ($cp['cur'] ?? 0);
          }
          if ($mx > $maxDefault) $maxDefault = $mx;
          $cells[] = ['date' => $dy['date'], 'label' => $dy['label'], 'res' => $n, 'max' => $mx];
        }
        $out[] = ['slot' => $s['label'], 'mode' => ($s['mode'] === 'Retrait' || $s['mode'] === 'collect') ? 'Retrait' : 'Livraison',
                  'max' => $maxDefault, 'days' => $cells];
      }
      json_out($out);
    }

    // ── Décision sur une demande d'accès compte Office (fr-validations). ──
    if ($m === 'POST' && $p === '/franchisee/validation-decide') {
      $b = body(); $id = (int) preg_replace('/\D/', '', (string) ($b['id'] ?? ''));
      $act = (string) ($b['action'] ?? '');
      if (!$id || !in_array($act, ['accept', 'reject'], true)) json_out(['ok' => false, 'error' => 'id + action requis'], 400);
      if (!$tblExists('ws_offices')) json_out(['ok' => false, 'error' => 'ws_offices absente'], 501);
      if ($act === 'accept') {
        q("UPDATE ws_offices SET status='validated', active=1 WHERE id=?", [$id]);
        // Tournée choisie dans la modale (nom ou id, résolution tolérante).
        $tv = trim((string) ($b['tour'] ?? ''));
        if ($tv !== '' && col_exists('ws_offices', 'tour_id') && $tblExists('ws_tours')) {
          $scT = $shopId ? " AND (shop_id=" . (int) $shopId . " OR shop_id IS NULL)" : "";
          $tr = ctype_digit($tv) ? row("SELECT id FROM ws_tours WHERE id=?" . $scT, [(int) $tv])
                                 : row("SELECT id FROM ws_tours WHERE name=? AND active=1" . $scT . " ORDER BY id DESC LIMIT 1", [$tv]);
          if ($tr) q("UPDATE ws_offices SET tour_id=? WHERE id=?", [(int) $tr['id'], $id]);
        }
        // Site (building) choisi : ligne de liaison bureau↔bâtiment, tournée héritée.
        $sa = trim((string) ($b['site_adr'] ?? ''));
        if ($sa !== '' && $tblExists('ws_office_delivery_sites')) {
          $nSql = "LOWER(REGEXP_REPLACE(TRIM(COALESCE(address,'')), '[[:space:]]+', ' '))";
          $nSa = mb_strtolower(preg_replace('/\s+/u', ' ', $sa));
          $ex2 = row("SELECT id FROM ws_office_delivery_sites WHERE office_client_id=? AND active=1 LIMIT 1", [$id]);
          $tpl2 = row("SELECT name, tournee_id, site_access_minutes FROM ws_office_delivery_sites WHERE $nSql=? AND active=1 ORDER BY id LIMIT 1", [$nSa]);
          if ($ex2) q("UPDATE ws_office_delivery_sites SET address=?, name=COALESCE(?, name), tournee_id=COALESCE(?, tournee_id), active=1 WHERE id=?",
                      [$sa, $tpl2['name'] ?? null, $tpl2['tournee_id'] ?? null, (int) $ex2['id']]);
          else q("INSERT INTO ws_office_delivery_sites (office_client_id, name, address, tournee_id, site_access_minutes, active" . ($shopId ? ", shop_id" : "") . ")
                    VALUES (?,?,?,?,?,1" . ($shopId ? "," . (int) $shopId : "") . ")",
                 [$id, $tpl2['name'] ?? null, $sa, $tpl2['tournee_id'] ?? null, (float) ($tpl2['site_access_minutes'] ?? 6)]);
          if ($tpl2 && $tpl2['tournee_id'] !== null && col_exists('ws_offices', 'tour_id'))
            q("UPDATE ws_offices SET tour_id=COALESCE(tour_id, ?) WHERE id=?", [(int) $tpl2['tournee_id'], $id]);
        }
      } else {
        q("UPDATE ws_offices SET active=0 WHERE id=? AND status='pending'", [$id]);
      }
      json_out(['ok' => true, 'action' => $act]);
    }

    // ── Décision sur une demande de rattachement bureau (fr-join-requests). ──
    if ($m === 'POST' && $p === '/franchisee/join-decide') {
      $b = body(); $id = (int) preg_replace('/\D/', '', (string) ($b['id'] ?? ''));
      $act = (string) ($b['action'] ?? '');
      if (!$id || !in_array($act, ['link', 'reject'], true)) json_out(['ok' => false, 'error' => 'id + action requis'], 400);
      if (!$tblExists('ws_office_join_requests')) json_out(['ok' => false, 'error' => 'table absente'], 501);
      q("UPDATE ws_office_join_requests SET status=? WHERE id=?" . ($shopId && col_exists('ws_office_join_requests', 'shop_id') ? " AND shop_id=" . (int) $shopId : ""),
        [$act === 'link' ? 'linked' : 'rejected', $id]);
      json_out(['ok' => true, 'action' => $act]);
    }

    // Stock du jour — catalogue catégories › produits (online/en shop/seuil).
    // ── Stock du jour : lignes de commande du produit (Ruby = Click&Collect,
    //    Apricot = Delivery) — ws_order_lines × ws_orders, jour courant. ──
    if ($m === 'GET' && $p === '/franchisee/stock-product-orders') {
      $pn = trim((string) qp('product', ''));
      if ($pn === '' || !$tblExists('ws_orders') || !$tblExists('ws_order_lines')) json_out([]);
      $rs = rows("SELECT o.order_ref, o.mode, o.status, l.qty,
                         DATE_FORMAT(o.created_at,'%H:%i') AS heure,
                         COALESCE(NULLIF(o.guest_name,''), '') AS client
                    FROM ws_order_lines l
                    JOIN ws_orders o ON o.id = l.order_id
                    LEFT JOIN ws_products pr ON pr.id = l.product_id
                   WHERE (l.product_name = ? OR pr.name = ?)" .
                   ($shopId ? " AND o.shop_id = " . (int) $shopId : "") . "
                     AND (o.delivery_date = ? OR (o.delivery_date IS NULL AND DATE(o.created_at) = ?))
                   ORDER BY o.created_at DESC LIMIT 100", [$pn, $pn, $today, $today]);
      json_out(array_map(fn ($r) => ['ref' => $r['order_ref'] ?: '—',
        'mode' => ($r['mode'] === 'delivery' ? 'delivery' : 'collect'),
        'qty' => (int) $r['qty'], 'statut' => $r['status'] ?: '—',
        'heure' => $r['heure'], 'client' => $r['client']], $rs));
    }

    // ── Stock du jour : ajustement +/− réel (ws_product_stock, jour courant). ──
    if ($m === 'POST' && $p === '/franchisee/stock-adjust') {
      $b = body();
      if (!$shopId) json_out(['ok' => false, 'error' => 'boutique requise (?shop=)'], 400);
      if (!$tblExists('ws_product_stock') || !$tblExists('ws_products')) json_out(['ok' => false, 'error' => 'tables stock absentes'], 501);
      $pr = row("SELECT id FROM ws_products WHERE name=? AND active=1 LIMIT 1", [(string) ($b['product'] ?? '')]);
      if (!$pr) json_out(['ok' => false, 'error' => 'produit inconnu'], 400);
      $mode = (($b['mode'] ?? '') === 'delivery') ? 'delivery' : 'collect';
      $delta = (int) ($b['delta'] ?? 0);
      if (!$delta) json_out(['ok' => false, 'error' => 'delta requis (±n)'], 400);
      q("INSERT INTO ws_product_stock (product_id, shop_id, date, mode, qty_total, qty_reserved, qty_sold, active)
           VALUES (?,?,?,?,?,0,0,1)
           ON DUPLICATE KEY UPDATE qty_total = GREATEST(0, qty_total + ?), active=1",
        [(int) $pr['id'], (int) $shopId, $today, $mode, max(0, $delta), $delta]);
      $st = row("SELECT qty_total, qty_reserved, qty_sold FROM ws_product_stock
                  WHERE product_id=? AND shop_id=? AND date=? AND mode=?",
        [(int) $pr['id'], (int) $shopId, $today, $mode]);
      json_out(['ok' => true, 'mode' => $mode,
        'total' => (int) ($st['qty_total'] ?? 0),
        'dispo' => max(0, (int) ($st['qty_total'] ?? 0) - (int) ($st['qty_reserved'] ?? 0) - (int) ($st['qty_sold'] ?? 0))]);
    }

    if ($m === 'GET' && $p === '/franchisee/fr-stock-catalog') {
      // Base du Stock du jour = TOUS les produits actifs (ws_products.active=1)
      // repris dans l'assortiment webshop de la boutique (ws_product_shops
      // actif ou sans surcharge) — les quantités du jour viennent de
      // ws_product_stock quand elles existent, sinon 0.
      if (!$tblExists('ws_products')) json_out([]);
      $hasStock = $tblExists('ws_product_stock');
      $hasPS = $shopId && $tblExists('ws_product_shops');
      $rs = rows("SELECT c.label AS cat, pr.name, pr.brand_mandatory," .
                 ($hasStock
                   ? " SUM(CASE WHEN st.mode='delivery' THEN st.qty_total - st.qty_reserved - st.qty_sold ELSE 0 END) AS online,
                       SUM(CASE WHEN st.mode<>'delivery' OR st.mode IS NULL THEN st.qty_total - st.qty_reserved - st.qty_sold ELSE 0 END) AS shopq"
                   : " 0 AS online, 0 AS shopq") . "
                    FROM ws_products pr
                    LEFT JOIN ws_categories c ON c.id = pr.cat_id" .
                 ($hasPS ? " LEFT JOIN ws_product_shops ps ON ps.product_id = pr.id AND ps.shop_id = " . (int) $shopId : "") .
                 ($hasStock ? " LEFT JOIN ws_product_stock st ON st.product_id = pr.id AND st.date = ? AND st.active = 1" . ($shopId ? " AND st.shop_id = " . (int) $shopId : "") : "") . "
                   WHERE pr.active = 1" . ($hasPS ? " AND (ps.active IS NULL OR ps.active = 1)" : "") . "
                   GROUP BY c.label, pr.id, pr.name, pr.brand_mandatory
                   ORDER BY c.label, pr.name LIMIT 400", $hasStock ? [$today] : []);
      $cats = [];
      foreach ($rs as $r) {
        $cat = $r['cat'] ?: 'Autres';
        $cats[$cat]['cat'] = $cat;
        $cats[$cat]['catMand'] = ($cats[$cat]['catMand'] ?? false) || (bool) $r['brand_mandatory'];
        $cats[$cat]['prods'][] = ['nom' => $r['name'], 'mand' => (bool) $r['brand_mandatory'],
          'online' => max(0, (int) $r['online']), 'shop' => max(0, (int) $r['shopq']),
          'min' => (int) ws_param('stock.default_min_threshold', '10')];
      }
      json_out(array_values($cats));
    }

    // ── Assortiment par boutique : BASCULE RÉELLE ws_product_shops. ──
    //    {product, active} = un produit ; {cat, active} = toute la catégorie.
    //    Les produits « marque obligatoire » ne sont jamais désactivés.
    if ($m === 'POST' && $p === '/franchisee/assortiment-toggle') {
      $b = body();
      if (!$shopId) json_out(['ok' => false, 'error' => 'boutique requise (?shop=)'], 400);
      if (!$tblExists('ws_product_shops') || !$tblExists('ws_products')) json_out(['ok' => false, 'error' => 'tables produits absentes'], 501);
      $active = !empty($b['active']) ? 1 : 0;
      $prods = [];
      if (!empty($b['product'])) {
        $pr = row("SELECT id, brand_mandatory FROM ws_products WHERE name=? AND active=1 LIMIT 1", [(string) $b['product']]);
        if (!$pr) json_out(['ok' => false, 'error' => 'produit inconnu'], 400);
        if ((int) $pr['brand_mandatory'] && !$active) json_out(['ok' => false, 'error' => 'Produit « marque obligatoire » — non désactivable'], 400);
        $prods[] = $pr;
      } elseif (!empty($b['cat'])) {
        $prods = rows("SELECT pr.id, pr.brand_mandatory FROM ws_products pr
                        LEFT JOIN ws_categories c ON c.id = pr.cat_id
                       WHERE pr.active=1 AND c.label=?", [(string) $b['cat']]);
        if (!$prods) json_out(['ok' => false, 'error' => 'catégorie inconnue'], 400);
      } else json_out(['ok' => false, 'error' => 'product ou cat requis'], 400);
      $n = 0;
      foreach ($prods as $pr) {
        if ((int) $pr['brand_mandatory'] && !$active) continue; // verrou marque
        q("INSERT INTO ws_product_shops (product_id, shop_id, active, no_delivery)
             VALUES (?,?,?,0)
             ON DUPLICATE KEY UPDATE active=VALUES(active)", [(int) $pr['id'], (int) $shopId, $active]);
        $n++;
      }
      json_out(['ok' => true, 'n' => $n, 'active' => (bool) $active]);
    }

    // Assortiment — ws_products × ws_product_shops (actif / sans livraison / verrou marque).
    if ($m === 'GET' && $p === '/franchisee/fr-assortiment') {
      if (!$tblExists('ws_products')) json_out([]);
      $hasPS = $shopId && $tblExists('ws_product_shops');
      $rs = rows("SELECT pr.name, c.label AS cat, pr.brand_mandatory" .
                 ($hasPS ? ", ps.active AS ps_active, ps.no_delivery" : ", NULL AS ps_active, NULL AS no_delivery") . "
                    FROM ws_products pr LEFT JOIN ws_categories c ON c.id = pr.cat_id" .
                 ($hasPS ? " LEFT JOIN ws_product_shops ps ON ps.product_id = pr.id AND ps.shop_id = " . (int) $shopId : "") . "
                   WHERE pr.active = 1 ORDER BY c.label, pr.name LIMIT 400");
      json_out(array_map(fn ($r) => ['nom' => $r['name'], 'cat' => $r['cat'] ?: '—',
        'locked' => (bool) $r['brand_mandatory'],
        'defA' => $r['ps_active'] !== null ? (bool) $r['ps_active'] : true,
        'defND' => $r['no_delivery'] !== null ? (bool) $r['no_delivery'] : false], $rs));
    }

    // Dispo par catégorie — ws_categories (délai/cut-off par défaut ws_param).
    if ($m === 'GET' && $p === '/franchisee/fr-dispo-cats') {
      if (!$tblExists('ws_categories')) json_out([]);
      $rs = rows("SELECT slug, label, active FROM ws_categories WHERE " . $scope('shop_id') . " OR shop_id IS NULL ORDER BY sort_order, label LIMIT 50");
      $cut = ws_param('order.cutoff_default', '17:00');
      json_out(array_map(fn ($r) => ['key' => $r['slug'] ?: $r['label'], 'nom' => $r['label'],
        'delai' => '1', 'cut' => $cut, 'def' => (bool) $r['active']], $rs));
    }

    /* Sans source serveur (analytique/telemetrie absentes) → [] ⇒ seed :
       fr-live-eta (ETA par point), fr-renta-kpis (KPIs consolidés analytique),
       fr-cout-params (libellés de config des coûts — valeurs via /params). */
    if ($m === 'GET' && ($p === '/franchisee/fr-live-eta'
                      || $p === '/franchisee/fr-renta-kpis'
                      || $p === '/franchisee/fr-cout-params')) {
      json_out([]);
    }

    // ── Analyse géographique (franchisé) — CLOISONNÉE sur la portée ?shop :
    //    uniquement les clients rattachés à SA boutique. Même contrat que le
    //    module franchiseur (géoloc par CP côté client, non-localisés comptés).
    if ($m === 'GET' && $p === '/franchisee/geo-clients') {
      $out = ['shops' => [], 'clients' => []];
      $out['shops'] = rows("SELECT id, name, city, zip AS cp FROM $SHOPS WHERE active=1 AND " . ($shopId ? "id = " . (int) $shopId : "1=1") . " ORDER BY name");
      if ($tblExists('ws_offices')) {
        $offs = rows("SELECT f.id, f.name, f.postal_code AS cp, f.city, t.shop_id,
                             (SELECT COALESCE(SUM(o.total),0) FROM ws_orders o WHERE o.office_client_id = f.id) AS ca,
                             (SELECT COUNT(*) FROM ws_orders o WHERE o.office_client_id = f.id AND o.status<>'cancelled') AS n
                        FROM ws_offices f LEFT JOIN ws_tours t ON t.id = f.tour_id
                       WHERE f.active = 1" . ($shopId ? " AND t.shop_id = " . (int) $shopId : ""));
        foreach ($offs as $f) $out['clients'][] = ['id' => 'o' . $f['id'], 'type' => 'office',
          'name' => $f['name'], 'cp' => $f['cp'], 'city' => $f['city'],
          'shop_id' => $f['shop_id'] !== null ? (int) $f['shop_id'] : null, 'ca' => (float) $f['ca'], 'n' => (int) $f['n']];
      }
      // Particuliers de MA boutique — identité unifiée `client` (zip/localité
      // collectés partout), rattachement preferred_shop_id → id_main_shop.
      foreach (geo_private_clients($shopId ?: null) as $c) $out['clients'][] = $c;
      json_out($out);
    }

    // ── Cohérence zoning : un CP ne peut appartenir ni à une zone primaire
    //    (chalandise d'un AUTRE point de vente) ni à une autre zone. ──
    if ($m === 'GET' && $p === '/franchisee/zone-check') {
      $cps = preg_split('/[^0-9]+/', (string) qp('cp', ''), -1, PREG_SPLIT_NO_EMPTY);
      $excl = (int) qp('zone', 0);   // zone en cours d'édition (à exclure)
      if (!$cps) json_out(['ok' => true, 'conflicts' => []]);
      $conflicts = [];
      $catch = $tblExists('ws_franchisor_catchment')
        ? rows("SELECT name, postcodes, shop_id FROM ws_franchisor_catchment WHERE active=1") : [];
      $zones = $tblExists('ws_delivery_zones')
        ? rows("SELECT id, name, postcodes FROM ws_delivery_zones WHERE active=1" . ($excl ? " AND id <> " . $excl : "")) : [];
      foreach ($cps as $cp) {
        foreach ($catch as $c) {
          $inMyShop = $shopId && (int) $c['shop_id'] === $shopId;   // sa propre zone primaire = autorisé
          if (!$inMyShop && preg_match('/\\b' . preg_quote($cp, '/') . '\\b/', (string) $c['postcodes'])) {
            $conflicts[] = ['cp' => $cp, 'type' => 'primaire', 'zone' => $c['name']];
            continue 2;
          }
        }
        foreach ($zones as $z) {
          if (preg_match('/\\b' . preg_quote($cp, '/') . '\\b/', (string) $z['postcodes'])) {
            $conflicts[] = ['cp' => $cp, 'type' => 'zone', 'zone' => $z['name']];
            continue 2;
          }
        }
      }
      json_out(['ok' => !$conflicts, 'conflicts' => $conflicts]);
    }

    /* ── Écritures ──────────────────────────────────────────────────────── */

    // État BO persisté (tables sans mapping typé) — lu par hydrate() en overlay.
    if ($m === 'GET' && $p === '/franchisee/bo-store') {
      if (!$tblExists('ws_bo_store')) json_out((object) []);
      $rs = rows("SELECT tbl, payload FROM ws_bo_store WHERE shop_scope = ?", [$shopId ?: 0]);
      $out = [];
      foreach ($rs as $r) { $v = json_decode($r['payload'], true); if (is_array($v)) $out[$r['tbl']] = $v; }
      json_out($out ?: (object) []);
    }

    // BOServer.save(table) → typé quand le mapping est propre, sinon ws_bo_store.
    if ($m === 'POST' && $p === '/franchisee/save') {
      $b = body();
      $tbl  = (string) ($b['table'] ?? '');
      $rows2 = $b['rows'] ?? null;
      if ($tbl === '' || !is_array($rows2)) json_out(['error' => 'table + rows requis'], 400);
      if (strlen(json_encode($rows2)) > 500000) json_out(['error' => 'payload trop grand'], 413);

      // Tables de config à remplacement intégral (petites, non référencées).
      if ($tbl === 'ws_franchisor_catchment' && $tblExists('ws_franchisor_catchment')) {
        q("DELETE FROM ws_franchisor_catchment");
        foreach ($rows2 as $r) q("INSERT INTO ws_franchisor_catchment (name, postcodes, exclusive, active) VALUES (?,?,?,1)",
          [(string) ($r['name'] ?? '—'), (string) ($r['cp'] ?? ''), !empty($r['exclusif']) ? 1 : 0]);
        json_out(['ok' => true, 'mode' => 'typed', 'n' => count($rows2)]);
      }
      if ($tbl === 'b2b_client_company_department' && $tblExists('b2b_client_company_department')) {
        q("DELETE FROM b2b_client_company_department");
        foreach ($rows2 as $r) q("INSERT INTO b2b_client_company_department (client_id, company, site, office, name, effectif, contact) VALUES (?,?,?,?,?,?,?)",
          [(string) ($r['client_id'] ?? ($r['id_client'] ?? '—')), $r['company'] ?? null, $r['site'] ?? null,
           $r['office'] ?? null, (string) ($r['dept'] ?? ($r['name'] ?? '—')), (int) ($r['effectif'] ?? 1), $r['contact'] ?? null]);
        json_out(['ok' => true, 'mode' => 'typed', 'n' => count($rows2)]);
      }
      if ($tbl === 'ws_tour_closures' && $tblExists('ws_tour_closures')) {
        // DELETE scopé BOUTIQUE : ne jamais effacer les fermetures des autres
        // franchisés (les lignes « toutes tournées », tour_id NULL, restent
        // gérées par la boutique courante).
        if ($shopId && $tblExists('ws_tours'))
          q("DELETE cl FROM ws_tour_closures cl LEFT JOIN ws_tours t ON t.id = cl.tour_id
              WHERE t.shop_id = " . (int) $shopId . " OR cl.tour_id IS NULL");
        else q("DELETE FROM ws_tour_closures");
        $hasCType = col_exists('ws_tour_closures', 'closure_type');
        foreach ($rows2 as $r) {
          $d = null;
          if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', (string) ($r['date'] ?? ''), $mm)) $d = "$mm[3]-$mm[2]-$mm[1]";
          if (!$d) continue;
          $tourId = null;
          if (!empty($r['tour']) && !preg_match('/^toutes/i', (string) $r['tour']) && $tblExists('ws_tours')) {
            $tr = row("SELECT id FROM ws_tours WHERE name=? LIMIT 1", [(string) $r['tour']]);
            if ($tr) $tourId = (int) $tr['id'];
          }
          if ($hasCType)
            q("INSERT INTO ws_tour_closures (tour_id, closure_date, reason, closure_type) VALUES (?,?,?,?)",
              [$tourId, $d, (string) ($r['motif'] ?? ''), (string) ($r['type'] ?? 'Fermeture')]);
          else
            q("INSERT INTO ws_tour_closures (tour_id, closure_date, reason) VALUES (?,?,?)",
              [$tourId, $d, (string) ($r['motif'] ?? '')]);
        }
        json_out(['ok' => true, 'mode' => 'typed', 'n' => count($rows2)]);
      }

      // Zones du franchisé → upsert typé ws_delivery_zones (+ validation CP serveur).
      if ($tbl === 'ws_delivery_zones' && $tblExists('ws_delivery_zones')) {
        $hasZoning = (bool) row("SELECT 1 x FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_delivery_zones' AND column_name='postcodes'");
        $n = 0; $rejected = [];
        foreach ($rows2 as $i2 => $r) {
          $name = trim((string) ($r['nom'] ?? ($r['name'] ?? '')));
          if ($name === '') continue;
          $cp = (string) ($r['cp'] ?? '');
          if ($cp === '—') $cp = '';
          $cid = null;
          if (!empty($r['catchment']) && $tblExists('ws_franchisor_catchment')) {
            $cr = ctype_digit((string) $r['catchment'])
              ? row("SELECT id FROM ws_franchisor_catchment WHERE id=?", [(int) $r['catchment']])
              : row("SELECT id FROM ws_franchisor_catchment WHERE name=?", [(string) $r['catchment']]);
            if ($cr) $cid = (int) $cr['id'];
          }
          $ex = row("SELECT id FROM ws_delivery_zones WHERE name=?", [$name]);
          // Validation CP : pas de conflit avec une zone primaire d'un autre shop ni une autre zone.
          $bad = false;
          foreach (preg_split('/[^0-9]+/', $cp, -1, PREG_SPLIT_NO_EMPTY) as $one) {
            $hit = row("SELECT name FROM ws_franchisor_catchment WHERE active=1 AND postcodes REGEXP CONCAT('(^|[^0-9])', ?, '($|[^0-9])')" .
                       ($shopId ? " AND (shop_id IS NULL OR shop_id <> " . (int) $shopId . ")" : ""), [$one]);
            if (!$hit && $hasZoning) $hit = row("SELECT name FROM ws_delivery_zones WHERE active=1 AND postcodes REGEXP CONCAT('(^|[^0-9])', ?, '($|[^0-9])')" . ($ex ? " AND id <> " . (int) $ex['id'] : ""), [$one]);
            if ($hit) { $rejected[] = ['zone' => $name, 'cp' => $one, 'chez' => $hit['name']]; $bad = true; break; }
          }
          if ($bad) continue;
          if ($ex) {
            q("UPDATE ws_delivery_zones SET sort_order=?, active=?" .
              ($hasZoning ? ", postcodes=?, zone_type='secondary', catchment_id=?" . ($shopId ? ", shop_id=" . (int) $shopId : "") : "") . " WHERE id=?",
              $hasZoning ? [(int) ($r['sort_order'] ?? $i2), !empty($r['active']) ? 1 : 1, $cp ?: null, $cid, (int) $ex['id']]
                         : [(int) ($r['sort_order'] ?? $i2), 1, (int) $ex['id']]);
          } else {
            q("INSERT INTO ws_delivery_zones (name, sort_order, active" . ($hasZoning ? ", postcodes, zone_type, catchment_id" . ($shopId ? ", shop_id" : "") : "") . ")
                VALUES (?,?,1" . ($hasZoning ? ",?,'secondary',?" . ($shopId ? "," . (int) $shopId : "") : "") . ")",
              $hasZoning ? [$name, (int) ($r['sort_order'] ?? $i2), $cp ?: null, $cid] : [$name, (int) ($r['sort_order'] ?? $i2)]);
          }
          $n++;
        }
        json_out(['ok' => !$rejected, 'mode' => 'typed', 'n' => $n, 'rejected' => $rejected]);
      }

      // Tournées → codes postaux (ws_tour_postcodes, ⊆ zone de chalandise de la boutique).
      // Une tournée porte directement ses CP ; un même CP peut servir plusieurs tournées.
      if ($tbl === 'ws_tours' && ($tblExists('ws_tour_postcodes') || $tblExists('ws_tour_zones'))) {
        $hasTP = $tblExists('ws_tour_postcodes');
        // Pool autorisé = codes postaux de la chalandise attribuée à la boutique.
        $pool = [];
        if ($hasTP && $tblExists('ws_franchisor_catchment')) {
          $hasShopC = (bool) row("SELECT 1 x FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_franchisor_catchment' AND column_name='shop_id'");
          $poolRows = rows("SELECT postcodes FROM ws_franchisor_catchment WHERE active=1" .
                           ($hasShopC && $shopId ? " AND (shop_id = " . (int) $shopId . " OR shop_id IS NULL)" : ""));
          foreach ($poolRows as $pr) {
            foreach (preg_split('/[^0-9]+/', (string) $pr['postcodes'], -1, PREG_SPLIT_NO_EMPTY) as $one) $pool[$one] = true;
          }
        }
        // Sémantique replace : tids traités collectés → les tournées retirées
        // côté BO passent active=0 (sinon une tournée supprimée ressuscite au
        // reload). Garde-fous : au moins un id r<n> round-trippé + scope shop.
        $n = 0; $keptT = []; $sawT = false;
        foreach ($rows2 as $r) {
          $rid = (string) ($r['id'] ?? '');
          $tid = 0;
          if (preg_match('/^r\d+$/', $rid)) $sawT = true;
          if (preg_match('/^r(\\d+)$/', $rid, $mm)) {                      // tournée réelle existante
            $tid = (int) $mm[1];
            if (!row("SELECT id FROM ws_tours WHERE id=?", [$tid])) continue;
            if (isset($r['name']) && trim((string) $r['name']) !== '')     // édition : nom / capacité
              q("UPDATE ws_tours SET name=?, max_items=? WHERE id=?", [(string) $r['name'], (int) ($r['max'] ?? 10), $tid]);
          } elseif (strpos($rid, 'rn') === 0 && !empty($r['name'])) {      // nouvelle tournée du constructeur → création réelle
            $ex = row("SELECT id FROM ws_tours WHERE name=?", [(string) $r['name']]);
            if ($ex) { $tid = (int) $ex['id']; }
            else {
              q("INSERT INTO ws_tours (name, max_items, active" . ($shopId ? ", shop_id" : "") . ") VALUES (?,?,1" . ($shopId ? "," . (int) $shopId : "") . ")",
                [(string) $r['name'], (int) ($r['max'] ?? 10)]);
              $tid = (int) db()->lastInsertId();
            }
          } else { continue; }
          // Codes postaux de la tournée (nouveau modèle). Remplacement intégral, ⊆ chalandise.
          if ($hasTP && array_key_exists('postcodes', $r)) {
            $wanted = is_array($r['postcodes'])
              ? $r['postcodes']
              : preg_split('/[^0-9]+/', (string) $r['postcodes'], -1, PREG_SPLIT_NO_EMPTY);
            q("DELETE FROM ws_tour_postcodes WHERE tour_id=?", [$tid]);
            foreach (array_unique($wanted) as $cp1) {
              $cp1 = trim((string) $cp1);
              if (!preg_match('/^[0-9]{4}$/', $cp1)) continue;
              if ($pool && !isset($pool[$cp1])) continue;                 // hors chalandise → ignoré
              q("INSERT IGNORE INTO ws_tour_postcodes (tour_id, postcode) VALUES (?,?)", [$tid, $cp1]);
              $n++;
            }
          }
          // Forfait & véhicule (0018) + retour dépôt (0028) → colonnes ws_tours.
          if ($tid) {
            $keptT[] = $tid;
            $fvSets = []; $fvVals = [];
            if (col_exists('ws_tours', 'delivery_fee') && isset($r['forfait'])) { $fvSets[] = 'delivery_fee=?'; $fvVals[] = (float) $r['forfait']; }
            if (col_exists('ws_tours', 'vehicle') && isset($r['vehicule'])) { $fvSets[] = 'vehicle=?'; $fvVals[] = (string) $r['vehicule']; }
            if (col_exists('ws_tours', 'return_to_depot') && array_key_exists('ret', $r)) { $fvSets[] = 'return_to_depot=?'; $fvVals[] = !empty($r['ret']) ? 1 : 0; }
            if ($fvSets) { $fvVals[] = $tid; q("UPDATE ws_tours SET " . implode(',', $fvSets) . " WHERE id=?", $fvVals); }
          }
          // Jours + heure de départ → ws_tour_availability (fenêtre 'morning'), NON destructif :
          // ne touche jamais les fenêtres 'afternoon'/'soir' réglées dans « Horaires & fermetures ».
          if ($tid && $shopId && $tblExists('ws_tour_availability') && !empty($r['days']) && is_array($r['days'])) {
            $dmap = ['L' => 1, 'Ma' => 2, 'Me' => 3, 'J' => 4, 'V' => 5, 'S' => 6, 'D' => 7];
            $sMin = is_numeric($r['start'] ?? null) ? (int) $r['start'] : 360;
            $fmt = fn ($mn) => sprintf('%02d:%02d:00', intdiv((($mn % 1440) + 1440) % 1440, 60), (($mn % 60) + 60) % 60);
            $startT = $fmt($sMin); $endT = $fmt($sMin + 180); $cutT = $fmt(max(0, $sMin - 120));
            foreach ($dmap as $k => $dow) {
              if (!empty($r['days'][$k])) {
                q("INSERT INTO ws_tour_availability (tour_id, shop_id, delivery_day, window_label, delivery_start, delivery_end, cutoff_time, active)
                     VALUES (?,?,?, 'morning', ?, ?, ?, 1)
                     ON DUPLICATE KEY UPDATE delivery_start=VALUES(delivery_start), active=1",
                  [$tid, $shopId, $dow, $startT, $endT, $cutT]);
              } else {
                q("UPDATE ws_tour_availability SET active=0 WHERE tour_id=? AND shop_id=? AND delivery_day=? AND window_label='morning'", [$tid, $shopId, $dow]);
              }
            }
          }
          // Compat héritée : ws_tour_zones/zone_id (zones « secondaires » retirées de l'UI).
          if ($tblExists('ws_tour_zones')) {
            $zs = [];
            foreach (['zone', 'zonePrim', 'zoneSec'] as $k) {
              if (empty($r[$k])) continue;
              $zr = row("SELECT id FROM ws_delivery_zones WHERE name=? OR id=? LIMIT 1", [(string) $r[$k], (string) $r[$k]]);
              if ($zr) $zs[$k] = (int) $zr['id'];
            }
            if (isset($zs['zonePrim']) || isset($zs['zone'])) q("UPDATE ws_tours SET zone_id=? WHERE id=?", [$zs['zonePrim'] ?? $zs['zone'], $tid]);
          }
        }
        // Suppression persistée : tournées du périmètre boutique absentes du
        // payload → active=0 (soft delete, l'historique commandes est gardé).
        if ($sawT && $shopId && col_exists('ws_tours', 'shop_id')) {
          $keptT = array_values(array_filter(array_map('intval', $keptT)));
          $inT = $keptT ? implode(',', $keptT) : '0';
          q("UPDATE ws_tours SET active=0 WHERE active=1 AND shop_id=" . (int) $shopId . " AND id NOT IN ($inT)");
        }
        json_out(['ok' => true, 'mode' => 'typed', 'n' => $n]);
      }

      // Sites de livraison des bureaux → ws_office_delivery_sites (rattachement à
      // une tournée réelle). Relie le « client office » à sa tournée : résout le
      // nom de tournée → ws_tours.id et le nom de bureau → ws_offices.id.
      if ($tbl === 'ws_office_delivery_sites' && $tblExists('ws_office_delivery_sites')) {
        // Le BO envoie la LISTE COMPLÈTE : sémantique « replace ». Les ids
        // traités sont collectés pour désactiver (active=0) les lignes webshop
        // retirées côté BO — sinon elles « réapparaissent » à chaque GET.
        $n = 0; $keptIds = [];
        foreach ($rows2 as $r) {
          // Tournée rattachée (libellé ou id) → ws_tours.id. Scope tolérant :
          // les tournées historiques peuvent avoir shop_id NULL — un scope
          // strict faisait ignorer silencieusement le changement de tournée.
          $tourId = null; $tv = trim((string) ($r['tour'] ?? ($r['tour_name'] ?? '')));
          $tourCleared = array_key_exists('tour', $r) && ($tv === '' || $tv === '—');
          if ($tv !== '' && $tv !== '—') {
            $scT = $shopId ? " AND (shop_id=" . (int) $shopId . " OR shop_id IS NULL)" : "";
            $tr = ctype_digit($tv)
              ? row("SELECT id FROM ws_tours WHERE id=?" . $scT, [(int) $tv])
              : row("SELECT id FROM ws_tours WHERE name=? AND active=1" . $scT . " ORDER BY id DESC LIMIT 1", [$tv]);
            if ($tr) $tourId = (int) $tr['id'];
          }
          // Bureau (compte B2B) → ws_offices.id.
          $officeId = null; $bn = trim((string) ($r['bureau'] ?? ''));
          if ($bn !== '' && $bn !== '—' && $tblExists('ws_offices')) {
            $orow = ctype_digit($bn) ? row("SELECT id FROM ws_offices WHERE id=?", [(int) $bn])
                                     : row("SELECT id FROM ws_offices WHERE name=? AND active=1", [$bn]);
            if ($orow) $officeId = (int) $orow['id'];
          }
          // Le NOM du site prime (champ name du formulaire / de l'API) ; repli sur
          // office (mini-form « + Site »). « — » = placeholder, pas un nom.
          $name  = trim((string) ($r['name'] ?? ($r['office'] ?? '')));
          if ($name === '—') $name = trim((string) ($r['office'] ?? ''));
          if ($name === '—') $name = '';
          $addr  = trim((string) ($r['adr'] ?? ($r['address'] ?? '')));
          if ($addr === '—') $addr = '';
          $floor = trim((string) ($r['etage'] ?? ($r['floor_room'] ?? '')));
          $cn    = trim((string) ($r['contact_name'] ?? ''));
          $cp    = trim((string) ($r['contact_phone'] ?? ''));
          $acc   = isset($r['acc']) ? (float) $r['acc'] : (isset($r['site_access_minutes']) ? (float) $r['site_access_minutes'] : 10);
          $rid   = $r['id'] ?? null;
          $ex    = is_numeric($rid) ? row("SELECT id FROM ws_office_delivery_sites WHERE id=?", [(int) $rid]) : null;
          // Repli anti-doublon : sans id round-trippé, on retrouve la ligne par
          // (office/nom/adresse) plutôt que de ré-insérer à chaque save.
          // Périmètre STRICTEMENT identique au GET (shop_id = boutique) — les
          // lignes shop_id NULL sont invisibles du BO, on n'y touche jamais.
          if (!$ex && ($name !== '' || $addr !== '' || $officeId)) {
            $ex = row("SELECT id FROM ws_office_delivery_sites
                        WHERE active=1 AND (office_client_id <=> ?)
                          AND (name <=> ?) AND (address <=> ?)" .
                        ($shopId ? " AND shop_id=" . (int) $shopId : "") . " LIMIT 1",
              [$officeId, $name ?: null, $addr ?: null]);
          }
          // Seconde chance NORMALISÉE (casse / espaces) : une variante de
          // graphie ne doit pas ré-INSÉRER un doublon du même bâtiment.
          if (!$ex && ($addr !== '' || $name !== '')) {
            $nAdrSql  = "LOWER(REGEXP_REPLACE(TRIM(COALESCE(address,'')), '[[:space:]]+', ' '))";
            $nNameSql = "LOWER(REGEXP_REPLACE(TRIM(COALESCE(name,'')), '[[:space:]]+', ' '))";
            $nA = mb_strtolower(preg_replace('/\s+/u', ' ', $addr));
            $nN = mb_strtolower(preg_replace('/\s+/u', ' ', $name));
            $scS = $shopId ? " AND shop_id=" . (int) $shopId : "";
            if ($officeId && $addr !== '')
              $ex = row("SELECT id FROM ws_office_delivery_sites
                          WHERE active=1 AND office_client_id=? AND $nAdrSql=? $scS LIMIT 1", [$officeId, $nA]);
            if (!$ex && !$officeId && ($addr !== '' || $name !== ''))
              $ex = row("SELECT id FROM ws_office_delivery_sites
                          WHERE active=1 AND office_client_id IS NULL AND $nAdrSql=? AND $nNameSql=? $scS LIMIT 1", [$nA, $nN]);
          }
          if ($ex) {
            $tourSql = $tourId !== null ? "tournee_id=" . (int) $tourId : ($tourCleared ? "tournee_id=NULL" : "tournee_id=tournee_id");
            q("UPDATE ws_office_delivery_sites SET name=?, address=?, floor_room=?, contact_name=?, contact_phone=?,
                 site_access_minutes=?, $tourSql, office_client_id=COALESCE(?, office_client_id), active=1" .
                 ($shopId ? ", shop_id=" . (int) $shopId : "") . " WHERE id=?",
              [$name ?: null, $addr ?: null, $floor ?: null, $cn ?: null, $cp ?: null, $acc, $officeId, (int) $ex['id']]);
            $keptIds[] = (int) $ex['id']; $n++;
          } elseif ($name !== '' || $addr !== '' || $officeId) {
            q("INSERT INTO ws_office_delivery_sites (office_client_id, name, address, floor_room, contact_name, contact_phone, site_access_minutes, tournee_id, shop_id, active)
                 VALUES (?,?,?,?,?,?,?,?,?,1)",
              [$officeId, $name ?: null, $addr ?: null, $floor ?: null, $cn ?: null, $cp ?: null, $acc, $tourId, $shopId]);
            $keptIds[] = (int) db()->lastInsertId(); $n++;
          }
        }
        // Sémantique replace : toute ligne du périmètre boutique absente de la
        // liste envoyée est désactivée — la suppression côté BO fait foi, y
        // compris pour les lignes fusionnées de l'ERP (client_id renseigné) :
        // les épargner faisait « revenir » les sites supprimés à chaque GET.
        // Garde-fous : (1) périmètre STRICTEMENT identique au GET (shop_id =
        // boutique ; jamais les lignes shop_id NULL, invisibles du BO) ;
        // (2) uniquement si au moins un id DB a fait l'aller-retour — un
        // payload sans ids (mode démo/seed, hydratation ratée) reste ADDITIF
        // et ne peut pas désactiver la base en masse.
        $sawDbId = false;
        foreach ($rows2 as $r) if (is_numeric($r['id'] ?? null)) { $sawDbId = true; break; }
        if ($sawDbId && $shopId) {
          $keptIds = array_values(array_filter(array_map('intval', $keptIds)));
          $inList  = $keptIds ? implode(',', $keptIds) : '0';
          q("UPDATE ws_office_delivery_sites SET active=0
              WHERE active=1 AND id NOT IN ($inList)
                AND shop_id=" . (int) $shopId);
        }
        json_out(['ok' => true, 'mode' => 'typed', 'n' => $n]);
      }

      // Client B2B (formulaire « Client B2B (société) ») → vraie table client.
      // Upsert par TVA (normalisée) puis par raison sociale : company_name,
      // tax_number, siège (street/zip/city), is_b2b=1 ; création → rattachée à
      // la boutique courante, office_delivery=1, status=1 (à valider).
      if ($tbl === 'fr_clients' && $tblExists('client')) {
        $n = 0;
        foreach ($rows2 as $r) {
          $tva  = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($r['tva'] ?? '')));
          $rais = trim((string) ($r['raison'] ?? ''));
          if ($tva === '' && $rais === '') continue;
          $ex = null;
          if ($tva !== '' && col_exists('client', 'tax_number'))
            $ex = row("SELECT id FROM client WHERE REPLACE(REPLACE(REPLACE(UPPER(COALESCE(tax_number,'')),'.',''),' ',''),'-','')=? LIMIT 1", [$tva]);
          if (!$ex && $rais !== '' && col_exists('client', 'company_name'))
            $ex = row("SELECT id FROM client WHERE TRIM(COALESCE(company_name,''))=? LIMIT 1", [$rais]);
          $sets = []; $uv = [];
          if ($rais !== '' && col_exists('client', 'company_name')) { $sets[] = 'company_name=?'; $uv[] = $rais; }
          if ($tva !== '' && col_exists('client', 'tax_number'))    { $sets[] = 'tax_number=?';   $uv[] = $tva; }
          foreach (['adresse' => 'street', 'cp' => 'zip', 'ville' => 'city'] as $fk => $col) {
            if (!empty($r[$fk]) && col_exists('client', $col)) { $sets[] = "$col=?"; $uv[] = trim((string) $r[$fk]); }
          }
          if (col_exists('client', 'is_b2b')) $sets[] = 'is_b2b=1';
          if ($ex) {
            if ($sets) { $uv[] = (int) $ex['id']; q("UPDATE client SET " . implode(',', $sets) . " WHERE id=?", $uv); }
            $n++;
          } else {
            $cols = ['id_main_shop', 'name', 'zip', 'city', 'street', 'active', 'source_channel', 'webshop_user'];
            $iv = [(int) ($shopId ?: 0), $rais ?: 'Client B2B', trim((string) ($r['cp'] ?? '')),
                   trim((string) ($r['ville'] ?? '')), trim((string) ($r['adresse'] ?? '')), 1, 'webshop', 0];
            if (col_exists('client', 'company_name'))    { $cols[] = 'company_name';    $iv[] = $rais ?: null; }
            if ($tva !== '' && col_exists('client', 'tax_number')) { $cols[] = 'tax_number'; $iv[] = $tva; }
            if (col_exists('client', 'is_b2b'))          { $cols[] = 'is_b2b';          $iv[] = 1; }
            if (col_exists('client', 'office_delivery')) { $cols[] = 'office_delivery'; $iv[] = 1; }
            if (col_exists('client', 'status'))          { $cols[] = 'status';          $iv[] = 1; }
            q("INSERT INTO client (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")", $iv);
            $n++;
          }
        }
        json_out(['ok' => true, 'mode' => 'typed', 'n' => $n]);
      }

      // Bureau (office) → ws_offices : création + édition complètes — statut
      // (toggle pending/validated), TVA (VIES), facturation différée (toggle),
      // tournée par défaut. La fiche client liée (client_id) reçoit TVA +
      // raison sociale validées par VIES. Le cut-off / jours autorisés ne sont
      // PAS stockés ici : ils sont hérités de la tournée (ws_tour_availability).
      if ($tbl === 'ws_offices' && $tblExists('ws_offices')) {
        $hasTour  = col_exists('ws_offices', 'tour_id');
        $hasShopO = col_exists('ws_offices', 'shop_id');
        $hasCli   = col_exists('ws_offices', 'client_id');
        $hasNotes = col_exists('ws_offices', 'delivery_notes');
        // Sémantique replace (comme les sites) : ids traités collectés pour
        // désactiver les offices retirés côté BO — sinon la suppression d'un
        // office ne persiste jamais (il « ressuscite » au reload).
        $n = 0; $keptOff = [];
        foreach ($rows2 as $r) {
          $name = trim((string) ($r['name'] ?? ''));
          $tourId = null; $tv = trim((string) ($r['tour'] ?? ''));
          if ($hasTour && $tv !== '' && $tv !== '—') {
            $scT = $shopId ? " AND (shop_id=" . (int) $shopId . " OR shop_id IS NULL)" : "";
            $tr = ctype_digit($tv)
              ? row("SELECT id FROM ws_tours WHERE id=?" . $scT, [(int) $tv])
              : row("SELECT id FROM ws_tours WHERE name=? AND active=1" . $scT . " ORDER BY id DESC LIMIT 1", [$tv]);
            if ($tr) $tourId = (int) $tr['id'];
          }
          $status = in_array(($r['status'] ?? ''), ['pending', 'validated'], true) ? $r['status'] : null;
          $defer = null;
          if (array_key_exists('deferred_billing_enabled', $r)) {
            $dv = $r['deferred_billing_enabled'];
            $defer = (int) (is_numeric($dv) ? ((int) $dv !== 0) : ($dv === true || stripos((string) $dv, 'oui') !== false));
          }
          $vat = trim((string) ($r['vat'] ?? ''));
          // id numérique, sinon retrouvé par nom (les lignes créées côté BO n'ont pas d'id DB).
          $rid = is_numeric($r['id'] ?? null) && row("SELECT id FROM ws_offices WHERE id=?", [(int) $r['id']])
            ? (int) $r['id']
            : (($name !== '' && ($ex = row("SELECT id FROM ws_offices WHERE name=? LIMIT 1", [$name]))) ? (int) $ex['id'] : 0);
          if ($rid) {
            $sets = []; $uvals = [];
            foreach (['name' => 'name', 'address' => 'address', 'postal_code' => 'postal_code', 'city' => 'city',
                      'contact' => 'contact', 'email' => 'email', 'phone' => 'phone'] as $fk => $col) {
              if (array_key_exists($fk, $r) && trim((string) $r[$fk]) !== '') { $sets[] = "$col=?"; $uvals[] = trim((string) $r[$fk]); }
            }
            if ($vat !== '')      { $sets[] = 'vat=?';    $uvals[] = $vat; }
            if ($hasNotes && array_key_exists('delivery_notes', $r)) { $sets[] = 'delivery_notes=?'; $uvals[] = trim((string) $r['delivery_notes']) ?: null; }
            if ($status !== null) { $sets[] = 'status=?'; $uvals[] = $status; }
            if ($defer !== null)  { $sets[] = 'deferred_billing_enabled=?'; $uvals[] = $defer; }
            if ($tourId !== null) { $sets[] = 'tour_id=?'; $uvals[] = $tourId; }
            // Estampiller le shop quand il manque : un office round-trippé par
            // ce BO appartient à sa boutique — indispensable pour que la passe
            // de désactivation (suppression) puisse le viser.
            if ($hasShopO && $shopId) $sets[] = 'shop_id=COALESCE(shop_id, ' . (int) $shopId . ')';
            if ($sets) { $uvals[] = $rid; q("UPDATE ws_offices SET " . implode(',', $sets) . " WHERE id=?", $uvals); $n++; }
            $keptOff[] = $rid;
            // Fiche client d'origine : TVA + raison sociale VIES.
            if ($hasCli && ($vat !== '' || !empty($r['vies_name']))) {
              $cl = row("SELECT client_id FROM ws_offices WHERE id=?", [$rid]);
              if ($cl && $cl['client_id']) {
                if ($vat !== '' && col_exists('client', 'tax_number'))
                  q("UPDATE client SET tax_number=? WHERE id=?", [$vat, (int) $cl['client_id']]);
                if (!empty($r['vies_name']) && col_exists('client', 'company_name'))
                  q("UPDATE client SET company_name=? WHERE id=?", [trim((string) $r['vies_name']), (int) $cl['client_id']]);
              }
            }
          } elseif ($name !== '') {
            // Création (« Créer un nouvel office ») — statut par défaut : validé.
            $cols = ['name', 'address', 'postal_code', 'city', 'contact', 'email', 'phone', 'status', 'active'];
            $ivals = [$name, (string) ($r['address'] ?? ''), (string) ($r['postal_code'] ?? ''), (string) ($r['city'] ?? ''),
                      (string) ($r['contact'] ?? ''), (string) ($r['email'] ?? ''), (string) ($r['phone'] ?? ''),
                      $status ?: 'validated', 1];
            if ($vat !== '')      { $cols[] = 'vat'; $ivals[] = $vat; }
            if ($hasNotes && trim((string) ($r['delivery_notes'] ?? '')) !== '') { $cols[] = 'delivery_notes'; $ivals[] = trim((string) $r['delivery_notes']); }
            if ($defer !== null)  { $cols[] = 'deferred_billing_enabled'; $ivals[] = $defer; }
            if ($hasTour && $tourId !== null) { $cols[] = 'tour_id'; $ivals[] = $tourId; }
            if ($hasShopO && $shopId)         { $cols[] = 'shop_id'; $ivals[] = (int) $shopId; }
            q("INSERT INTO ws_offices (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")", $ivals);
            $rid = (int) db()->lastInsertId();
            $keptOff[] = $rid;
            $n++;
          }
          // Sens du paramétrage : le BUREAU choisit son building (site). La ligne
          // de liaison bureau↔site est créée seulement si l'office n'a encore
          // AUCUN site actif (sinon on met à jour l'existant) — un ré-enregis-
          // trement d'office ne doit jamais dupliquer de ligne site.
          $siteAdr = trim((string) ($r['site'] ?? ''));
          if ($siteAdr === '—') $siteAdr = '';
          // GARDE-FOU anti-résurrection : créer/déplacer une ligne site à
          // partir du champ `site` d'un office N'EST PERMIS que si le front a
          // posé le marqueur site_touch (choix EXPLICITE : formulaire office
          // ou étape 3). Sans marqueur (save de liste, round-trip d'une valeur
          // périmée), on se limite au complément de tournée d'une ligne
          // existante — un site supprimé ne peut plus être recréé en douce.
          $siteTouch = !empty($r['site_touch']);
          if ($siteAdr !== '' && $rid && $tblExists('ws_office_delivery_sites')) {
            // Un site DOIT être rattaché à une tournée : repli sur la tournée
            // stockée de l'office si le formulaire n'en a pas résolu une.
            $pairTour = $tourId;
            if ($pairTour === null && $hasTour) {
              $ot = row("SELECT tour_id FROM ws_offices WHERE id=?", [$rid]);
              if ($ot && $ot['tour_id'] !== null) $pairTour = (int) $ot['tour_id'];
            }
            // 1) correspondance EXACTE d'adresse → juste compléter la tournée ;
            // 2) sinon une ligne du bureau SANS adresse → on y met l'adresse ;
            // 3) sinon (adresses différentes non vides) → NE RIEN écraser : le
            //    déplacement d'un bureau se fait à l'étape 3 (drag-drop), pas
            //    par un ré-enregistrement d'office qui renverrait un champ
            //    `site` périmé.
            // Comparaisons d'adresse NORMALISÉES (casse, espaces multiples) —
            // deux graphies de la même adresse ne doivent plus créer deux
            // bâtiments (tags en double dans l'étape 3).
            $normAdrSql = "LOWER(REGEXP_REPLACE(TRIM(COALESCE(address,'')), '[[:space:]]+', ' '))";
            $normSite = mb_strtolower(preg_replace('/\s+/u', ' ', $siteAdr));
            $ps = row("SELECT id, tournee_id FROM ws_office_delivery_sites
                        WHERE office_client_id=? AND active=1 AND $normAdrSql=? LIMIT 1", [$rid, $normSite]);
            if ($ps) {
              if ($ps['tournee_id'] === null && $pairTour !== null)
                q("UPDATE ws_office_delivery_sites SET tournee_id=? WHERE id=?", [$pairTour, (int) $ps['id']]);
            } elseif ($siteTouch) {
              $pn = row("SELECT id FROM ws_office_delivery_sites
                          WHERE office_client_id=? AND active=1 AND TRIM(COALESCE(address,''))='' LIMIT 1", [$rid]);
              $any = $pn ?: row("SELECT id FROM ws_office_delivery_sites WHERE office_client_id=? AND active=1 LIMIT 1", [$rid]);
              if ($pn) {
                q("UPDATE ws_office_delivery_sites SET address=?, tournee_id=COALESCE(?, tournee_id) WHERE id=?",
                  [$siteAdr, $pairTour, (int) $pn['id']]);
              } elseif (!$any) {
                q("INSERT INTO ws_office_delivery_sites (office_client_id, name, address, tournee_id, site_access_minutes, active" . ($shopId ? ", shop_id" : "") . ")
                     VALUES (?,?,?,?,?,1" . ($shopId ? "," . (int) $shopId : "") . ")",
                  [$rid, ($name !== '' ? $name : 'Bureau') . ' @ ' . mb_substr($siteAdr, 0, 80), $siteAdr, $pairTour, 6]);
              } else {
                // 3) l'office a déjà un site à une AUTRE adresse et le form en
                //    choisit une nouvelle : on DÉPLACE la ligne site de l'office
                //    (même sémantique que le drag-drop étape 3) en héritant du
                //    nom/tournée du bâtiment cible s'il existe déjà.
                $tgt = row("SELECT name, tournee_id FROM ws_office_delivery_sites
                             WHERE $normAdrSql=? AND active=1 ORDER BY id LIMIT 1", [$normSite]);
                q("UPDATE ws_office_delivery_sites SET address=?, name=COALESCE(?, name), tournee_id=COALESCE(?, COALESCE(?, tournee_id)) WHERE id=?",
                  [$siteAdr, $tgt['name'] ?? null, $tgt['tournee_id'] ?? null, $pairTour, (int) $any['id']]);
              }
            }
          }
        }
        // Suppression persistée : les offices du périmètre boutique absents de
        // la liste envoyée passent active=0 — mêmes garde-fous que les sites
        // (au moins un id DB round-trippé, jamais sans scope boutique).
        $sawOffId = false;
        foreach ($rows2 as $r) if (is_numeric($r['id'] ?? null)) { $sawOffId = true; break; }
        if ($sawOffId && $shopId && $hasShopO) {
          $keptOff = array_values(array_filter(array_map('intval', $keptOff)));
          $inOff = $keptOff ? implode(',', $keptOff) : '0';
          q("UPDATE ws_offices SET active=0
              WHERE active=1 AND (shop_id=" . (int) $shopId . " OR shop_id IS NULL) AND id NOT IN ($inOff)");
          // Retirer un OFFICE ne supprime pas le CLIENT : on coupe seulement sa
          // livraison au bureau et on détache le lien — le client reste visible
          // dans le menu Clients (sa suppression, elle, se fait là-bas).
          if ($tblExists('client') && col_exists('client', 'office_id')) {
            q("UPDATE client c JOIN ws_offices o ON o.id = c.office_id
                  SET c.office_delivery = 0, c.office_id = NULL
                WHERE o.active = 0 AND (o.shop_id = " . (int) $shopId . " OR o.shop_id IS NULL)");
            if (col_exists('ws_offices', 'client_id'))
              q("UPDATE client c JOIN ws_offices o ON o.client_id = c.id
                    SET c.office_delivery = 0
                  WHERE o.active = 0 AND (o.shop_id = " . (int) $shopId . " OR o.shop_id IS NULL) AND c.office_delivery = 1
                    AND NOT EXISTS (SELECT 1 FROM ws_offices o2 WHERE o2.client_id = c.id AND o2.active = 1)");
          }
        }
        json_out(['ok' => true, 'mode' => 'typed', 'n' => $n]);
      }

      // Horaires des tournées → ws_tour_availability (fenêtre 'morning').
      // COMBLE LE TROU : l'écran « Horaires & fermetures » n'écrivait que le
      // journal bo_store — cut-off / fin / capacité n'atteignaient jamais la
      // vraie table qui pilote la prise de commande du webshop. Upsert par
      // (tournée, jour) ; les jours retirés passent active=0 ; ne touche
      // JAMAIS les fenêtres 'afternoon'.
      if ($tbl === 'ws_tour_availability' && $tblExists('ws_tour_availability') && $tblExists('ws_tours')) {
        if (!$shopId) json_out(['ok' => false, 'error' => 'boutique requise (?shop=)'], 400);
        $dmap = ['lun' => 1, 'mar' => 2, 'mer' => 3, 'jeu' => 4, 'ven' => 5, 'sam' => 6, 'dim' => 7];
        $parseDays = function ($str) use ($dmap) {
          $str = mb_strtolower((string) $str); $out = [];
          if (preg_match('/(lun|mar|mer|jeu|ven|sam|dim)\s*[-–àa]\s*(lun|mar|mer|jeu|ven|sam|dim)/u', $str, $m2)
              && $dmap[$m2[1]] <= $dmap[$m2[2]]) {
            for ($d2 = $dmap[$m2[1]]; $d2 <= $dmap[$m2[2]]; $d2++) $out[$d2] = 1;
            return array_keys($out);
          }
          foreach ($dmap as $ab => $n2) if (strpos($str, $ab) !== false) $out[$n2] = 1;
          return array_keys($out);
        };
        $t2s = function ($v) { return preg_match('/(\d{1,2}):(\d{2})/', (string) $v, $m2) ? sprintf('%02d:%02d:00', $m2[1], $m2[2]) : null; };
        $n = 0; $touched = [];
        foreach ($rows2 as $r) {
          $tr = row("SELECT id FROM ws_tours WHERE name=? AND shop_id=" . (int) $shopId . " LIMIT 1", [(string) ($r['tour'] ?? '')]);
          if (!$tr) continue;
          $tid2 = (int) $tr['id'];
          $start = $t2s($r['dep'] ?? '06:00'); $end = $t2s($r['fin'] ?? '12:00'); $cut = $t2s($r['cut'] ?? '17:00');
          if (!$start || !$end || !$cut) continue;
          $cap = (isset($r['cap']) && $r['cap'] !== '' && $r['cap'] !== '—') ? (int) $r['cap'] : null;
          foreach ($parseDays($r['jour'] ?? '') as $dow) {
            q("INSERT INTO ws_tour_availability
                 (tour_id, shop_id, delivery_day, window_label, delivery_start, delivery_end, cutoff_time, max_orders, active)
               VALUES (?,?,?, 'morning', ?,?,?,?, 1)
               ON DUPLICATE KEY UPDATE delivery_start=VALUES(delivery_start), delivery_end=VALUES(delivery_end),
                 cutoff_time=VALUES(cutoff_time), max_orders=VALUES(max_orders), active=1",
              [$tid2, (int) $shopId, $dow, $start, $end, $cut, $cap]);
            $touched[$tid2][$dow] = 1; $n++;
          }
        }
        foreach ($touched as $tid2 => $dset) {
          $keepD = implode(',', array_map('intval', array_keys($dset)));
          q("UPDATE ws_tour_availability SET active=0
              WHERE tour_id=" . (int) $tid2 . " AND shop_id=" . (int) $shopId . "
                AND window_label='morning' AND delivery_day NOT IN ($keepD)");
        }
        json_out(['ok' => true, 'mode' => 'typed', 'n' => $n]);
      }

      // Paramètres → UPSERT ws_param (config partagée : upsert par clé, jamais de delete).
      if ($tbl === 'params' && $tblExists('ws_param')) {
        $n = 0;
        foreach ($rows2 as $r) {
          $cle = trim((string) ($r['cle'] ?? ''));
          if ($cle === '' || strlen($cle) > 100) continue;
          $val = $r['val'] ?? ($r['def'] ?? '');
          if (is_bool($val)) $val = $val ? '1' : '0';
          q("INSERT INTO ws_param (param_key, param_value) VALUES (?,?)
               ON DUPLICATE KEY UPDATE param_value = VALUES(param_value)", [$cle, (string) $val]);
          $n++;
        }
        json_out(['ok' => true, 'mode' => 'typed', 'n' => $n]);
      }

      // Défaut : journal JSON par table + boutique (repris par hydrate()).
      if (!$tblExists('ws_bo_store')) json_out(['ok' => false, 'error' => 'ws_bo_store absente (migration 0013)'], 501);
      q("INSERT INTO ws_bo_store (shop_scope, tbl, payload) VALUES (?,?,?)
           ON DUPLICATE KEY UPDATE payload = VALUES(payload)",
        [$shopId ?: 0, $tbl, json_encode($rows2, JSON_UNESCAPED_UNICODE)]);
      json_out(['ok' => true, 'mode' => 'store']);
    }

    // Onboarding B2B (wizard 7 étapes) — création réelle bureau + site + départements (+ voucher).
    if ($m === 'POST' && $p === '/franchisee/onboard-office') {
      if (!$tblExists('ws_offices')) json_out(['error' => 'ws_offices absente'], 501);
      $b = body();
      $raison = trim((string) ($b['raison'] ?? ''));
      if ($raison === '') json_out(['error' => 'raison sociale requise'], 400);
      // Code postal OBLIGATOIRE (collecte réseau, formulaire « Nouveau client »
      // du BO franchisé) + localité confirmée, stockés sur ws_offices.
      $obZip = trim((string) ($b['cp'] ?? ($b['postal_code'] ?? '')));
      if ($obZip === '') json_out(['error' => 'Code postal requis'], 400);
      $obZip = zip_validate($obZip, $b['country'] ?? 'BE');
      if ($obZip === null) json_out(['error' => 'Code postal invalide'], 400);
      $obLoc = zip_locality($obZip, $b['localite'] ?? ($b['locality'] ?? ''));
      // id_shop : déduit du code postal (chalandise) ; sinon saisi manuellement
      // ($b['shop']) ; sinon la boutique courante (portée franchisé).
      $obShop = (isset($b['shop']) && $b['shop'] !== '') ? (int) $b['shop'] : (zip_shop($obZip) ?? $shopId);
      $tourId = null;
      if (!empty($b['tour']) && $tblExists('ws_tours')) {
        $tr = row("SELECT id FROM ws_tours WHERE name=? LIMIT 1", [(string) $b['tour']]);
        if ($tr) $tourId = (int) $tr['id'];
      }
      q("INSERT INTO ws_offices (tour_id, name, address, postal_code, city, contact, email, phone, vat, status, deferred_billing_enabled, drop_minutes, active" . ($obShop ? ", shop_id" : "") . ")
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1" . ($obShop ? "," . (int) $obShop : "") . ")",
        [$tourId, $raison, (string) ($b['adr'] ?? ''), $obZip, $obLoc, (string) ($b['contactNom'] ?? ''),
         (string) ($b['contactEmail'] ?? ''), (string) ($b['contactTel'] ?? ''), (string) ($b['tva'] ?? ''),
         'validated', (stripos((string) ($b['paiement'] ?? ''), 'compt') === false) ? 1 : 0,
         (float) ($b['drop'] ?? 5)]);
      $officeId = (int) db()->lastInsertId();
      // Ligne CLIENT (table ERP) — sans elle le nouveau bureau n'apparaît
      // jamais dans le menu Clients (GET b2b-clients lit client). Séquence
      // anti-doublon : insert avec office_delivery=0 (pas de trigger), pose du
      // DOUBLE lien (ws_offices.client_id + client.office_id), puis passage à
      // office_delivery=1 → le trigger AU retombe sur l'office existant
      // (ON DUPLICATE KEY sur client_id) au lieu d'en créer un second.
      $newClientId = null;
      if ($tblExists('client')) {
        $cCols = []; $cVals = [];
        $addC = function ($c, $v) use (&$cCols, &$cVals) { if (col_exists('client', $c)) { $cCols[] = $c; $cVals[] = $v; } };
        $addC('company_name', $raison);
        $addC('name', (string) ($b['contactNom'] ?? $raison));
        $addC('email', (string) ($b['contactEmail'] ?? ''));
        $addC('phone', (string) ($b['contactTel'] ?? ''));
        $addC('zip', $obZip);
        $addC('locality', $obLoc);
        $addC('city', $obLoc);
        $addC('street', (string) ($b['adr'] ?? ''));
        $addC('tax_number', (string) ($b['tva'] ?? ''));
        $addC('is_b2b', 1);
        $addC('office_delivery', 0);
        $addC('status', 0);
        $addC('office_id', $officeId);
        $addC('id_main_shop', (int) ($obShop ?: 0));
        $addC('active', 1);
        if ($cCols) {
          try {
            q("INSERT INTO client (" . implode(',', $cCols) . ") VALUES (" . implode(',', array_fill(0, count($cCols), '?')) . ")", $cVals);
            $newClientId = (int) db()->lastInsertId();
            if (col_exists('ws_offices', 'client_id'))
              q("UPDATE ws_offices SET client_id=? WHERE id=?", [$newClientId, $officeId]);
            if (col_exists('client', 'office_delivery'))
              q("UPDATE client SET office_delivery=1 WHERE id=?", [$newClientId]);
          } catch (Throwable $e) { /* colonne NOT NULL inattendue : l'office reste créé */ }
        }
      }
      if ($tblExists('ws_office_delivery_sites')) {
        q("INSERT INTO ws_office_delivery_sites (office_client_id, name, address, floor_room, tournee_id, site_access_minutes, active" . ($obShop ? ", shop_id" : "") . ")
            VALUES (?,?,?,?,?,?,1" . ($obShop ? "," . (int) $obShop : "") . ")",
          [$officeId, $raison . ' — ' . ((string) ($b['office'] ?? 'Site')), (string) ($b['adr'] ?? ''),
           (string) ($b['etage'] ?? ''), $tourId, (float) ($b['acc'] ?? 6)]);
      }
      if (!empty($b['departements']) && is_array($b['departements']) && $tblExists('b2b_client_company_department')) {
        foreach ($b['departements'] as $d) {
          q("INSERT INTO b2b_client_company_department (client_id, company, site, office, name, effectif, contact) VALUES (?,?,?,?,?,?,?)",
            ['OF-' . $officeId, $raison, (string) ($b['adr'] ?? ''), (string) ($b['office'] ?? ''),
             (string) ($d['dept'] ?? '—'), (int) ($d['effectif'] ?? 1), (string) ($b['contactEmail'] ?? '')]);
        }
      }
      $voucherCreated = false;
      $vc = strtoupper(trim((string) ($b['voucher']['code'] ?? '')));
      if ($vc !== '') {
        // ws_vouchers peut être une VUE (modèle ERP) — n'insérer que si table de base.
        $isBase = row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ws_vouchers' AND table_type='BASE TABLE'");
        if ($isBase) {
          q("INSERT IGNORE INTO ws_vouchers (code, type, value, active" . ($shopId ? ", shop_id" : "") . ") VALUES (?,?,?,1" . ($shopId ? "," . (int) $shopId : "") . ")",
            [$vc, 'add_office', 0]);
          $voucherCreated = true;
        }
      }
      json_out(['ok' => true, 'office_id' => $officeId, 'voucher_created' => $voucherCreated]);
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
      // Après l'unification, la remise vit sur shops (colonnes à plat discount_type/value).
      q("UPDATE shops SET discount_type=?, discount_value=? WHERE id=?",
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
  if (col_exists('shops', 'timezone')) {
    $tzr = row("SELECT timezone FROM shops WHERE id=?", [$shop]);
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
    'locality' => $u['locality'] ?? null,
    // Pilote la modal de rattrapage post-login : true tant que le CP manque.
    'needsPostcode' => (($u['zip'] ?? '') === '' || $u['zip'] === null),
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

/* Particuliers de l'analyse géographique (franchisor + franchisee) — source :
 * l'identité unifiée `client` (zip + localité collectés partout : webshop, PWA,
 * modal de rattrapage), repli sur le CP de facturation quand zip est vide, et
 * repli complet sur la table legacy ws_customers si `client` n'existe pas.
 * Rattachement boutique : preferred_shop_id si défini, sinon id_main_shop —
 * c'est ce COALESCE qui sert aussi de filtre pour la vue cloisonnée franchisé. */
function geo_private_clients($shopId = null) {
  $tbl = function ($t) { return (bool) row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?", [$t]); };
  $out = [];
  if ($tbl('client')) {
    $fn = col_exists('client', 'name') ? 'c.name' : (col_exists('client', 'first_name') ? 'c.first_name' : 'NULL');
    $ln = col_exists('client', 'surname') ? 'c.surname' : (col_exists('client', 'last_name') ? 'c.last_name' : 'NULL');
    $cpExpr = col_exists('client', 'invoice_postal_code') ? "COALESCE(NULLIF(c.zip,''), c.invoice_postal_code)" : "NULLIF(c.zip,'')";
    $cityFallback = col_exists('client', 'invoice_city') ? 'c.invoice_city' : 'NULL';
    $cityExpr = col_exists('client', 'locality') ? "COALESCE(NULLIF(c.locality,''), $cityFallback)" : $cityFallback;
    $shopExpr = col_exists('client', 'preferred_shop_id') ? 'COALESCE(c.preferred_shop_id, c.id_main_shop)' : 'c.id_main_shop';
    // Les fiches société (company link PWA) ne sont pas des particuliers.
    $notB2b = col_exists('client', 'is_b2b') ? 'COALESCE(c.is_b2b,0)=0'
            : (col_exists('client', 'is_business') ? 'COALESCE(c.is_business,0)=0' : '1=1');
    $caExpr = $tbl('ws_orders') ? "(SELECT COALESCE(SUM(o.total),0) FROM ws_orders o WHERE o.customer_id = c.id)" : '0';
    $priv = rows("SELECT c.id, $fn AS first_name, $ln AS last_name, $cpExpr AS cp, $cityExpr AS city,
                         $shopExpr AS shop_id, $caExpr AS ca
                    FROM client c
                   WHERE COALESCE(c.active,1)=1 AND $notB2b" .
                 ($shopId ? " AND $shopExpr = " . (int) $shopId : "") . " LIMIT 3000");
  } elseif ($tbl('ws_customers')) {
    $priv = rows("SELECT c.id, c.first_name, c.last_name, c.invoice_postal_code AS cp, c.invoice_city AS city,
                         c.preferred_shop_id AS shop_id,
                         (SELECT COALESCE(SUM(o.total),0) FROM ws_orders o WHERE o.customer_id = COALESCE(c.client_id, c.id)) AS ca
                    FROM ws_customers c" . ($shopId ? " WHERE c.preferred_shop_id = " . (int) $shopId : "") . " LIMIT 3000");
  } else {
    return [];
  }
  foreach ($priv as $c) $out[] = ['id' => 'p' . $c['id'], 'type' => 'private',
    'name' => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: ('Client #' . $c['id']),
    'cp' => $c['cp'], 'city' => $c['city'],
    'shop_id' => $c['shop_id'] !== null ? (int) $c['shop_id'] : null, 'ca' => (float) $c['ca']];
  return $out;
}

/* Limitation de débit (anti brute-force) — compteur par clé (route|IP) sur une
 * fenêtre glissante simple (table ws_rate_limit, migration 0016). Fail-open :
 * toute erreur DB laisse passer (la disponibilité prime) ; un dépassement
 * renvoie 429 sans révéler le seuil exact. */
function rate_limit($bucket, $max, $windowSec) {
  $blocked = false;
  try {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '?');
    $ip = trim(explode(',', (string) $ip)[0]);
    $key = substr($bucket . '|' . $ip, 0, 120);
    $now = time();
    $r = row("SELECT hits, window_start FROM ws_rate_limit WHERE rl_key=?", [$key]);
    if (!$r || ($now - (int) $r['window_start']) >= $windowSec) {
      q("REPLACE INTO ws_rate_limit (rl_key, hits, window_start) VALUES (?,1,?)", [$key, $now]);
      return;
    }
    if ((int) $r['hits'] >= $max) $blocked = true;   // le 429 part HORS du try
    else q("UPDATE ws_rate_limit SET hits = hits + 1 WHERE rl_key=?", [$key]);
  } catch (Throwable $e) { /* table absente / DB indisponible — fail-open */ }
  if ($blocked) json_out(['error' => 'Trop de tentatives. Réessayez dans quelques minutes.'], 429);
}

/* Le jeton admin est-il présenté sur CETTE requête ? (garde optionnelle pour
 * des lectures sensibles aussi accessibles au propriétaire connecté). */
function is_admin_request() {
  $expected = (string) (cfg()['admin_token'] ?? '');
  if ($expected === '') return false;
  $given = req_header('X-Admin-Token');
  if ($given === '') { $a = req_header('Authorization'); if (stripos($a, 'bearer ') === 0) $given = substr($a, 7); }
  return $given !== '' && hash_equals($expected, trim($given));
}

/* Collecte du code postal client (exigence « partout ») — helpers partagés
 * entre /auth/register, PATCH /auth/me et la modal de rattrapage post-login. */
/* Format du code postal selon le pays (défaut BE). Retourne le CP normalisé
 * (trim) ou null si le format est invalide. */
function zip_validate($zip, $country = 'BE') {
  $zip = trim((string) $zip);
  $formats = [
    'BE' => '/^[1-9][0-9]{3}$/',                  // 4 chiffres, pas de 0 initial
    'NL' => '/^[1-9][0-9]{3}\s?[A-Za-z]{2}$/',
    'FR' => '/^[0-9]{5}$/',
    'LU' => '/^[0-9]{4}$/',
    'DE' => '/^[0-9]{5}$/',
  ];
  $re = $formats[strtoupper((string) $country)] ?? '/^[A-Za-z0-9][A-Za-z0-9 \-]{1,9}$/';
  return preg_match($re, $zip) ? $zip : null;
}
/* Localités du référentiel bpost pour un CP belge (un même code peut couvrir
 * plusieurs localités, ex. 1300 → Limal · Wavre). [] si CP hors référentiel. */
function zip_localities($zip) {
  static $idx = null;
  if ($idx === null) {
    $idx = [];
    $file = __DIR__ . '/data/zipcodes_be.json';
    if (is_file($file)) {
      foreach ((json_decode((string) file_get_contents($file), true) ?: []) as $e) {
        $idx[(string) $e['zip']][] = (string) $e['city'];
      }
    }
  }
  return array_values(array_unique($idx[(string) $zip] ?? []));
}
/* Localité à stocker avec le CP : la localité confirmée par le client si elle
 * appartient bien au référentiel de ce CP, sinon la première du référentiel
 * (CP mono-localité ou saisie libre hors liste). null si CP inconnu. */
function zip_locality($zip, $claimed = '') {
  $loc = zip_localities($zip);
  if (!$loc) return (trim((string) $claimed) !== '') ? trim((string) $claimed) : null;
  $claimed = trim((string) $claimed);
  foreach ($loc as $c) if ($claimed !== '' && mb_strtolower($c) === mb_strtolower($claimed)) return $c;
  return $loc[0];
}
/* Shop (id_shop) déduit du code postal via la zone de chalandise
 * (ws_franchisor_catchment : le CP appartient au territoire d'une boutique).
 * Rend l'id de la boutique qui couvre ce CP, sinon null → saisie manuelle. */
function zip_shop($zip) {
  $zip = preg_replace('/\D+/', '', (string) $zip);
  if ($zip === '') return null;
  if (!row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ws_franchisor_catchment'")) return null;
  if (!col_exists('ws_franchisor_catchment', 'shop_id')) return null;
  $r = row("SELECT shop_id FROM ws_franchisor_catchment
             WHERE active=1 AND shop_id IS NOT NULL
               AND postcodes REGEXP CONCAT('(^|[^0-9])', ?, '($|[^0-9])')
             ORDER BY shop_id LIMIT 1", [$zip]);
  return $r && $r['shop_id'] !== null ? (int) $r['shop_id'] : null;
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
