<?php
/* ============================================================================
 * CATALOGUE ERP — l'assortiment d'une boutique vient de l'ERP.
 *
 *   GET {base}/shops/{id_shop}/products/available?lang_code=nl
 *
 * L'ERP fait autorité sur : QUELS produits cette boutique vend, leur NOM (déjà
 * traduit), leur catégorie, leur TVA, leurs allergènes, leur divisibilité.
 *
 * ⚠️ LE PRIX DE VENTE NE VIENT PAS D'ICI. Il vit dans une table dédiée (prix
 * boutique / prix de portion). Ce payload porte bien des montants —
 * `suggested_sale_price` (conseil marque) et `portion_price*` — mais les deux
 * diffèrent sur 524 des 573 produits observés : les confondre vendrait au
 * mauvais prix. On les expose donc en INFORMATION (`erpSuggested`,
 * `erpPortionPrice`) sans jamais les utiliser comme prix de vente.
 *
 * LA TABLE DE PRIX, elle, est servie par un SEUL endpoint :
 *
 *   GET {base}/shops/{id_shop}/products/{id_product}/portions/prices
 *     → [{ portion_type, label, shop_price, shop_price_gross, shop_price_net,
 *          has_shop_price, is_ready_for_sale, calculated_cost{gross,net},
 *          margin{value,percent}, tax_value_percent }, …]
 *
 * `shop_price_gross` EST le prix facturé, et `has_shop_price` / `is_ready_for_sale`
 * disent s'il est fixé. Observé sur atelierby (shop 2, produit 6700237) : les
 * trois portions existent, `has_shop_price: false` partout — la table est en
 * place, pas encore remplie. C'est exactement le comportement que la règle
 * « pas de prix ⇒ pas vendable » attend : on ne devine pas.
 *
 * DEUX TROUS CÔTÉ ERP, à demander à Franchise Buddy (cf. §fin de fichier) :
 *  - aucun endpoint ne donne le prix de la PIÈCE ENTIÈRE — seules les portions
 *    en ont un. `/shops/{s}/products/{p}/price` répond 404 ;
 *  - aucun endpoint EN LOT : il faut un appel par produit, donc 573 appels pour
 *    une page de catalogue. Inutilisable en liste ; on s'en sert donc uniquement
 *    sur la fiche produit, la liste restant servie par le SQL.
 *
 * RÈGLES DE SÛRETÉ (« aucune donnée inventée, aucun repli ») :
 *  - API non configurée ou source non activée → cette fonction n'est pas
 *    appelée, le catalogue SQL habituel reste en place ;
 *  - appel en échec / JSON illisible → on rend null et l'appelant retombe sur
 *    le catalogue SQL, avec l'incident journalisé. On ne rend JAMAIS une liste
 *    vide qui ferait croire à une boutique sans produits ;
 *  - un produit sans prix dans la table de prix n'est pas vendable : c'est
 *    l'appelant qui applique cette règle, comme aujourd'hui.
 * ========================================================================== */

/* Normalise UN produit ERP vers la forme attendue par le front (mêmes clés que
 * le SELECT de catalog_produits_servis). Rend null si l'entrée est inutilisable. */
function erp_cat_map_product(array $p, $lang = '') {
  $id = isset($p['id']) ? (int) $p['id'] : 0;
  if ($id <= 0) return null;
  if (isset($p['is_active']) && !(int) $p['is_active']) return null;

  // `name` est déjà dans la langue demandée ; `base_name` est la source. On
  // garde la source à part : elle sert de repli et de clé de rapprochement.
  $name = trim((string) ($p['name'] ?? ''));
  $base = trim((string) ($p['base_name'] ?? ''));
  if ($name === '') $name = $base;
  if ($name === '') return null;                 // sans nom, rien à afficher

  $catId   = isset($p['id_category']) ? (int) $p['id_category'] : null;
  $catName = trim((string) ($p['category_name'] ?? ($p['base_category_name'] ?? '')));

  /* Allergènes : `grouped_allergens` est une liste séparée par des virgules,
     DÉJÀ traduite par l'ERP (« Glutenhoudende granen, Eieren, … »). On la
     découpe telle quelle. `allergene` et `nutriscore` sont vides sur toutes les
     entrées observées — on ne les invente pas. */
  $allerg = [];
  if (!empty($p['grouped_allergens']) && is_string($p['grouped_allergens'])) {
    foreach (explode(',', $p['grouped_allergens']) as $a) {
      $a = trim($a);
      if ($a !== '') $allerg[] = $a;
    }
  }

  $num = static function ($v) {
    if ($v === null || $v === '') return null;
    return is_numeric($v) ? (float) $v : null;
  };

  return [
    'id'          => $id,
    'name'        => $name,
    'base_name'   => $base !== '' ? $base : null,
    'description' => null,                        // absent du payload ERP
    'img'         => null,                        // idem : les photos restent locales
    'cat_id'      => $catId,
    'cat'         => $catId,
    'sub_cat_id'  => null,
    'subCat'      => null,
    'category'    => $catName !== '' ? $catName : null,
    // TVA : le payload la donne deux fois sous deux noms — on prend la première
    // présente plutôt que d'en privilégier une arbitrairement.
    'vat_rate'    => $num($p['tax_val_perc'] ?? ($p['tax_value_percent'] ?? null)),
    // Divisible = le produit accepte des portions. La LISTE des portions et
    // leurs prix restent à la charge de la table de prix.
    'portions'    => !empty($p['is_divisible']) ? 1 : 0,
    'is_divisible'=> !empty($p['is_divisible']) ? 1 : 0,
    'allergens'   => $allerg,
    'ingredients' => (!empty($p['label_ingredients']) && is_string($p['label_ingredients']))
                       ? trim($p['label_ingredients']) : null,
    'is_vegetarian' => !empty($p['is_vegetarian']) ? 1 : 0,
    /* CANAUX (livraison Franchise Buddy du 23/08) : l'ERP porte désormais les
       trois bascules du webshop. webshop_active naît à 0 — tant que la marque
       ne les a pas posées dans FB, ces valeurs ne pilotent RIEN ici (le
       miroir est derrière ws_param.channels_source, cf. sync_product_photos). */
    'webshop_active'    => isset($p['webshop_active']) ? (int) !!$p['webshop_active'] : null,
    'click_and_collect' => isset($p['click_and_collect']) ? (int) !!$p['click_and_collect'] : null,
    'office_delivery'   => isset($p['office_delivery']) ? (int) !!$p['office_delivery'] : null,
    /* include=portions : portions actives AVEC prix boutique, EN LOT — la
       réponse à « un appel par produit » (ENDPOINTS_WEBSHOP §C.1, partiel).
       Même règle que erp_portion_prices : un montant n'est retenu que fixé
       ET vendable ; sinon la portion est présente mais sans prix. */
    'portionPrices' => (function () use ($p) {
      if (empty($p['portions']) || !is_array($p['portions'])) return null;
      $MAP = ['one_half' => 'demi', 'one_quarter' => 'quart', 'one_eighth' => 'huitieme'];
      $out = [];
      foreach ($p['portions'] as $po) {
        if (!is_array($po) || (isset($po['is_active']) && !(int) $po['is_active'])) continue;
        $v = $MAP[strtolower(trim((string) ($po['portion_type'] ?? '')))] ?? null;
        if (!$v) continue;
        $fixed = !empty($po['has_shop_price']);
        if ($fixed && array_key_exists('is_ready_for_sale', $po)) $fixed = !empty($po['is_ready_for_sale']);
        $raw = $po['shop_price_gross'] ?? ($po['shop_price'] ?? null);
        $out[] = ['v' => $v, 'label' => (string) ($po['label'] ?? $v),
                  'price' => ($fixed && is_numeric($raw) && (float) $raw > 0) ? (float) $raw : null,
                  'pp_id' => (int) ($po['id'] ?? 0)];
      }
      return $out ?: null;
    })(),
    /* include=availability_periods : mêmes clés que la table locale
       product_availability_period (from_md/to_md, is_recurring) — transmises
       telles quelles, l'appelant décide. */
    'availabilityPeriods' => (isset($p['availability_periods']) && is_array($p['availability_periods']))
                               ? $p['availability_periods'] : null,
    // Stock du jour tel que l'ERP le connaît, en information : la réservation
    // reste gérée par le webshop.
    'erpInStock'  => $num($p['in_stock'] ?? null),
    // Montants ERP — INFORMATIFS, jamais le prix de vente (voir en-tête).
    'erpSuggested'     => $num($p['suggested_sale_price'] ?? null),
    'erpPortionPrice'  => $num($p['portion_price_gross'] ?? null),
    'erpMaxPortionPrice' => $num($p['max_portion_price'] ?? null),
    // `price` est délibérément ABSENT : l'appelant le résout depuis la table de
    // prix. Un produit qui en sortirait sans prix ne doit pas être vendable.
  ];
}

/* Assortiment d'une boutique, dans la langue demandée.
 * Rend ['products' => [...], 'categories' => [...]] ou null si indisponible. */
function erp_catalog_shop($shopId, $lang = '') {
  static $cache = [];
  $shopId = (int) $shopId;
  if ($shopId <= 0) return null;
  $lg = strtolower(substr((string) $lang, 0, 2));
  $ck = $shopId . '|' . $lg;
  if (array_key_exists($ck, $cache)) return $cache[$ck];
  if (!function_exists('erp_enabled') || !erp_enabled()) return $cache[$ck] = null;

  $path = 'shops/' . $shopId . '/products/available' . ($lg !== '' ? '?lang_code=' . urlencode($lg) : '');
  $data = erp_get($path);
  if (!is_array($data)) return $cache[$ck] = null;      // erp_get a déjà journalisé

  // Liste à la racine, ou sous une clé usuelle : on reconnaît, on ne suppose pas.
  $list = null;
  if (array_is_list($data)) $list = $data;
  else foreach (['data', 'items', 'results', 'products'] as $k) {
    if (isset($data[$k]) && is_array($data[$k]) && array_is_list($data[$k])) { $list = $data[$k]; break; }
  }
  if ($list === null) {
    if (function_exists('erp_notes')) erp_notes('ERP catalogue : forme inattendue sur ' . $path);
    return $cache[$ck] = null;
  }

  $prods = [];
  $cats  = [];
  foreach ($list as $row) {
    if (!is_array($row)) continue;
    $m = erp_cat_map_product($row, $lg);
    if ($m === null) continue;
    $prods[] = $m;
    // Les catégories arrivent imbriquées dans le produit : pas de second appel.
    $cid = $m['cat_id'];
    if ($cid !== null && !isset($cats[$cid])) {
      $c = is_array($row['category'] ?? null) ? $row['category'] : [];
      if (!empty($c['is_active']) || !array_key_exists('is_active', $c)) {
        $cats[$cid] = [
          'id'    => $cid,
          'slug'  => null,
          'label' => $m['category'] ?? trim((string) ($c['name'] ?? ($c['base_name'] ?? ''))),
          'img'   => null,
          'sort_order' => null,
          'subs'  => [],
        ];
      }
    }
  }
  if (!$prods) {
    // Zéro produit exploitable alors que l'API a répondu : c'est un signal, pas
    // un assortiment vide. On rend null pour que l'appelant garde le SQL.
    if (function_exists('erp_notes')) erp_notes('ERP catalogue : aucune ligne exploitable sur ' . $path);
    return $cache[$ck] = null;
  }
  return $cache[$ck] = ['products' => $prods, 'categories' => array_values($cats)];
}

/* PRIX DE PORTION d'UN produit dans UNE boutique, lus par l'API (la table de
 * prix, pas le catalogue).
 *
 *   GET shops/{s}/products/{p}/portions/prices
 *
 * Rend la MÊME forme que erp_portion_options() (php-api/index.php) pour être
 * interchangeable avec elle :
 *   [ ['v'=>'demi'|'quart'|'huitieme', 'label'=>'1/2', 'price'=>float|null,
 *      'pp_id'=>int], … ]
 *
 * Règles, identiques au chemin SQL :
 *  - `has_shop_price` faux, `is_ready_for_sale` faux, ou montant ≤ 0
 *    ⇒ price = null : la portion n'est PAS proposable (elle ne peut donc pas
 *    être facturée 0 €) ;
 *  - un type de portion inconnu est ignoré plutôt que traduit au hasard ;
 *  - appel en échec ⇒ null (et non []), pour que l'appelant garde le SQL.
 *
 * ⚠️ UN APPEL PAR PRODUIT. À réserver à la fiche produit : sur une liste de
 * catalogue, ça ferait un appel par ligne (voir l'en-tête du fichier). */
function erp_portion_prices($shopId, $productId) {
  static $cache = [];
  $shopId = (int) $shopId; $productId = (int) $productId;
  if ($shopId <= 0 || $productId <= 0) return null;
  $ck = $shopId . '|' . $productId;
  if (array_key_exists($ck, $cache)) return $cache[$ck];
  if (!function_exists('erp_enabled') || !erp_enabled()) return $cache[$ck] = null;

  $path = 'shops/' . $shopId . '/products/' . $productId . '/portions/prices';
  $data = erp_get($path);
  if (!is_array($data) || !array_is_list($data)) return $cache[$ck] = null;

  // Mêmes clés reconnues que erp_portion_options() : une seule table de vérité
  // sur le vocabulaire des portions.
  $MAP = ['one_half' => 'demi', 'half' => 'demi', 'demi' => 'demi', '1/2' => 'demi',
          'one_quarter' => 'quart', 'quarter' => 'quart', 'quart' => 'quart', '1/4' => 'quart',
          'one_eighth' => 'huitieme', 'eighth' => 'huitieme', 'huitieme' => 'huitieme', '1/8' => 'huitieme'];
  $LBL = ['demi' => '1/2', 'quart' => '1/4', 'huitieme' => '1/8'];

  $out = [];
  foreach ($data as $r) {
    if (!is_array($r)) continue;
    if (isset($r['is_active']) && !(int) $r['is_active']) continue;
    $v = $MAP[mb_strtolower(trim((string) ($r['portion_type'] ?? '')))] ?? null;
    if (!$v) continue;

    // Un prix fixé ET déclaré vendable. Le second drapeau n'est exigé que s'il
    // est présent : un ERP qui ne le sert pas ne doit pas tout bloquer.
    $price = null;
    $fixed = !empty($r['has_shop_price']);
    if ($fixed && array_key_exists('is_ready_for_sale', $r)) $fixed = !empty($r['is_ready_for_sale']);
    if ($fixed) {
      // TVAC d'abord : c'est ce que le client paie. `shop_price` sans suffixe
      // n'est pris qu'à défaut, sa base TVA n'étant pas documentée.
      $raw = $r['shop_price_gross'] ?? ($r['shop_price'] ?? null);
      if (is_numeric($raw) && (float) $raw > 0) $price = (float) $raw;
    }
    $out[] = ['v' => $v, 'label' => $LBL[$v], 'price' => $price,
              'pp_id' => isset($r['id']) ? (int) $r['id'] : 0];
  }
  if (!$out) return $cache[$ck] = null;   // aucune portion lisible : pas un « zéro portion »
  return $cache[$ck] = $out;
}

/* La source ERP est-elle activée pour le catalogue ?
 * ws_param.catalog_source = 'erp' l'active ; toute autre valeur (ou absence)
 * garde le catalogue SQL. Réglable en base, sans redéploiement. */
function erp_catalog_enabled() {
  if (!function_exists('ws_param') || !function_exists('erp_enabled')) return false;
  if (!erp_enabled()) return false;
  try { return strtolower((string) (ws_param('catalog_source', '') ?: '')) === 'erp'; }
  catch (Throwable $e) { return false; }
}

/* ── LA RÉPONSE BRUTE DE shops/{id}/products/available ────────────────────────
 * Trois besoins tirent de la même réponse : les allergènes, les gammes de
 * disponibilité et les portions. Un seul appel, mis en cache par (boutique,
 * langue, include) : trois appels séparés donneraient trois vérités possibles
 * à quelques secondes d'écart. */
function erp_available_brut($shopId, $include = '', $lang = 'fr') {
  static $cache = [];
  $shopId = (int) $shopId;
  if ($shopId <= 0) return null;
  $lg = strtolower(substr((string) $lang, 0, 2)) ?: 'fr';
  $ck = $shopId . '|' . $lg . '|' . $include;
  if (array_key_exists($ck, $cache)) return $cache[$ck];
  if (!function_exists('erp_enabled') || !erp_enabled()) return $cache[$ck] = null;

  $path = 'shops/' . $shopId . '/products/available?lang_code=' . urlencode($lg)
        . ($include !== '' ? '&include=' . urlencode($include) : '');
  $data = erp_get($path, (int) (function_exists('ws_param') ? ws_param('catalog_direct_ttl', 60) : 60));
  if (!is_array($data)) return $cache[$ck] = null;
  $list = array_is_list($data) ? $data : null;
  if ($list === null) foreach (['data','items','results','products'] as $k)
    if (isset($data[$k]) && is_array($data[$k]) && array_is_list($data[$k])) { $list = $data[$k]; break; }
  if ($list === null) {
    if (function_exists('erp_notes')) erp_notes('ERP available : forme inattendue sur ' . $path);
    return $cache[$ck] = null;
  }
  $out = [];
  foreach ($list as $r) if (is_array($r) && !empty($r['id'])) $out[(int) $r['id']] = $r;
  return $cache[$ck] = $out;
}

/* ── ALLERGÈNES, PAR L'ENDPOINT ──────────────────────────────────────────────
 * Remplace un calcul SQL local sur product → flattened_recipe_ingredient →
 * allergen. Cette copie locale s'est révélée PÉRIMÉE : sur les 81 produits
 * servis, elle documentait 42 produits et en laissait 29 inconnus, là où
 * l'endpoint en documente 49 et n'en laisse que 10. En sécurité alimentaire,
 * la source la plus complète et la plus fraîche gagne.
 *
 * TROIS ÉTATS, jamais confondus — c'est la règle de ce dépôt et elle compte
 * ici plus qu'ailleurs :
 *   liste  = allergènes connus ;
 *   []     = recette évaluée, réellement aucun ;
 *   null   = NON RENSEIGNÉ.
 * Le second se distingue du troisième par `label_ingredients` : une recette
 * dont les ingrédients sont écrits a été évaluée. Sans ce signal positif, on
 * rend null — annoncer « aucun allergène » sans preuve serait le seul mensonge
 * vraiment dangereux de cet écran.
 *
 * `grouped_allergens` arrive en clair et dans la langue demandée
 * (« Céréales contenant du gluten, Œufs, Lait ») ; le module d'allergènes du
 * front résout ces libellés comme il résout les codes. */
function erp_allergenes($shopId, $lang = 'fr') {
  $rows = erp_available_brut($shopId, 'availability_periods', $lang);
  if (!is_array($rows)) return null;          // ERP muet : on ne conclut rien
  $out = [];
  foreach ($rows as $pid => $r) {
    $g = trim((string) ($r['grouped_allergens'] ?? ''));
    if ($g !== '') {
      $l = array_values(array_filter(array_map('trim', explode(',', $g)), 'strlen'));
      $out[$pid] = $l ?: null;
      continue;
    }
    $evalue = trim((string) ($r['label_ingredients'] ?? '')) !== '';
    $out[$pid] = $evalue ? [] : null;
  }
  return $out;
}

/* ── GAMMES DE DISPONIBILITÉ, PAR L'ENDPOINT ─────────────────────────────────
 * Rend, pour une date, l'ensemble des produits VENDABLES ce jour-là selon
 * leurs périodes. Un produit sans période reste disponible : c'est la règle de
 * l'ancien SQL (« NOT EXISTS … OR EXISTS … ») et la changer retirerait de la
 * vente tout ce que l'ERP ne classe pas.
 *
 * Rend null si l'ERP est muet — l'appelant doit alors NE PAS filtrer, plutôt
 * que masquer tout le catalogue sur une panne réseau. */
function erp_produits_de_saison($shopId, $date = null, $lang = 'fr') {
  $rows = erp_available_brut($shopId, 'availability_periods', $lang);
  if (!is_array($rows)) return null;
  $d  = (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ? $date : date('Y-m-d');
  $md = (int) (substr($d, 5, 2) . substr($d, 8, 2));   // MMJJ, comme l'ancien SQL
  $ok = [];
  foreach ($rows as $pid => $r) {
    $per = $r['availability_periods'] ?? null;
    if (!is_array($per) || !$per) { $ok[$pid] = true; continue; }   // sans période : toujours
    $actives = false; $dedans = false;
    foreach ($per as $p) {
      if (!is_array($p) || (isset($p['is_active']) && !(int) $p['is_active'])) continue;
      $actives = true;
      if (empty($p['is_recurring'])) {
        $s = (string) ($p['start_date'] ?? ''); $e = (string) ($p['end_date'] ?? '');
        if ($s !== '' && $e !== '' && $d >= $s && $d <= $e) { $dedans = true; break; }
      } else {
        $a = (int) ($p['from_md'] ?? 0); $b = (int) ($p['to_md'] ?? 0);
        if ($a === 0 && $b === 0) continue;
        /* Une période à cheval sur le nouvel an (novembre → février) a
           from_md > to_md : le test devient une union, pas un intervalle. */
        if ($a <= $b ? ($md >= $a && $md <= $b) : ($md >= $a || $md <= $b)) { $dedans = true; break; }
      }
    }
    $ok[$pid] = $actives ? $dedans : true;
  }
  return $ok;
}
