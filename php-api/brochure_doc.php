<?php
/* brochure_doc.php — LE DOSSIER IMPRIMABLE « Carte & tarifs » (A4).
 *
 * Généré depuis la console franchisé, pour une boutique ou pour UN bureau :
 *   · couverture — logo, boutique, QR du lien d'invitation du bureau
 *     (le mécanisme existant : code court, expiration, révocation), familles ;
 *   · une page par catégorie, découpée en sous-catégories, chaque produit avec
 *     sa photo, son nom, sa description, ses allergènes, ses portions et son
 *     prix TVAC — sans prix si le bureau masque les prix ;
 *   · formules (menus composés), bons en cours, puis jours / cut-off /
 *     livraison / facturation.
 *
 * RIEN N'EST INVENTÉ : une section sans donnée en base est OMISE (aucune
 * formule ⇒ pas de page Formules ; aucun bon ⇒ pas de bloc Bons). Les prix
 * viennent du MÊME résolveur que le webshop et la facturation
 * (catalog_produits_servis) ; l'assortiment d'un bureau est celui que ses
 * collaborateurs verront (office_filtrer).
 *
 * DEUX MOITIÉS, séparées exprès : brochure_donnees() lit la base et rend un
 * tableau plat ; brochure_render() en fait le HTML. La seconde se teste et se
 * dessine SANS base (échantillon, Claude Design) ; la première ne porte
 * aucune mise en page.
 *
 * Polices et logo EMBARQUÉS (data:), comme l'affiche d'invitation : la page
 * s'ouvre depuis un blob:, autre origine que l'API ; une @font-face liée
 * partirait sans CORS et retomberait en Helvetica sans rien dire. */

/* ── Fichiers de la marque, où qu'ils soient déployés ──────────────────── */
function brochure_lire($chemin) {
  foreach ([__DIR__ . '/../' . $chemin, __DIR__ . '/../public/' . $chemin, __DIR__ . '/../dist/' . $chemin,
            __DIR__ . '/../img/brand/' . basename($chemin)] as $c)
    if (is_file($c) && ($b = @file_get_contents($c)) !== false) return $b;
  return null;
}
function brochure_police($fichier, $poids, $famille = 'Gotham') {
  $b = brochure_lire('fonts/' . $fichier);
  if ($b === null) return '';
  $fmt = substr($fichier, -4) === '.ttf' ? 'truetype' : 'opentype';
  $mime = $fmt === 'truetype' ? 'font/ttf' : 'font/otf';
  return "@font-face{font-family:'$famille';src:url(data:$mime;base64," . base64_encode($b)
       . ") format('$fmt');font-weight:$poids;font-style:normal;font-display:swap}";
}
function brochure_eur($v) { return number_format((float) $v, 2, ',', ' ') . "\u{a0}€"; }

/* ── LES DONNÉES (lecture seule) ────────────────────────────────────────── */
function brochure_donnees($shopId, $officeId = null, $racine = '', $date = null, $sansSaison = false, $catsKeys = null) {
  $shopId = (int) $shopId; $officeId = $officeId ? (int) $officeId : null;
  // Disponibilité : le catalogue tel qu'il sera servi À CETTE DATE (saisons,
  // périodes ERP), aujourd'hui par défaut ; « sans saison » écarte en plus les
  // produits saisonniers, pour un dossier qui reste juste toute l'année.
  $date = ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) ? (string) $date : date('Y-m-d');
  // shops n'a pas de colonne address : l'adresse se compose de street + street_num
  // (même expression que la route /shops). webshop_url n'existe pas partout.
  $shop = row("SELECT id, name, city, email, phone, TRIM(CONCAT_WS(' ', street, street_num)) AS address"
            . (col_exists('shops', 'webshop_url') ? ", webshop_url" : ", NULL AS webshop_url") . " FROM shops WHERE id = ?", [$shopId]);
  if (!$shop) return null;

  $office = null; $off = null; $qrUrl = null; $qrCode = null;
  if ($officeId) {
    $office = row("SELECT id, name, address, postal_code, city, deferred_billing_enabled, drop_minutes FROM ws_offices WHERE id = ? AND active = 1", [$officeId]);
    if (!$office) return null;
    $off = function_exists('office_assortiment') ? office_assortiment($officeId) : null;
    if (function_exists('invite_for_office')) {
      [$inv] = invite_for_office($officeId);
      if ($inv && !empty($inv['jti'])) { $qrUrl = invite_link_court($inv['jti']); $qrCode = (string) $inv['jti']; }
    }
  }
  $showPrices = $off ? (bool) $off['show'] : true;

  // Catalogue BUREAU de la boutique (canal livraison au bureau), réduit à
  // l'assortiment du bureau s'il en a un — exactement ce que le client verra.
  $liste = catalog_produits_servis($shopId, 'office', $date);
  if ($off) $liste = office_filtrer($liste, $off);
  if ($sansSaison) $liste = array_values(array_filter($liste, static fn ($x) => empty($x['season'])));

  // Catégories et sous-catégories : celles des produits servis, dans l'ordre
  // du webshop (sort_order, puis libellé).
  $catIds = array_values(array_unique(array_map(static fn ($x) => (int) ($x['cat_id'] ?? $x['cat'] ?? 0), $liste)));
  $subIds = array_values(array_unique(array_filter(array_map(static fn ($x) => (int) ($x['sub_cat_id'] ?? $x['subCat'] ?? 0), $liste))));
  $cats = $catIds ? rows("SELECT id, slug, label, sort_order FROM ws_categories WHERE id IN (" . implode(',', array_fill(0, count($catIds), '?')) . ") ORDER BY sort_order, label", $catIds) : [];
  // Gammes choisies avant impression : clés = slug (ou libellé sans slug), les
  // mêmes que la console lit dans fr-dispo-cats. Sans liste : toutes.
  if (is_array($catsKeys)) {
    $veut = array_flip(array_map(static fn ($k) => mb_strtolower(trim((string) $k)), $catsKeys));
    $cats = array_values(array_filter($cats, static fn ($c) => isset($veut[mb_strtolower((string) ($c['slug'] ?: $c['label']))]) || isset($veut[mb_strtolower((string) $c['label'])])));
  }
  $subs = $subIds ? rows("SELECT id, category_id, label, sort_order FROM ws_category_subs WHERE id IN (" . implode(',', array_fill(0, count($subIds), '?')) . ") ORDER BY sort_order, label", $subIds) : [];
  $subLabel = []; foreach ($subs as $s) $subLabel[(int) $s['id']] = (string) $s['label'];
  $subOrder = []; foreach ($subs as $i => $s) $subOrder[(int) $s['id']] = $i;

  $produitsParCat = [];
  foreach ($liste as $x) $produitsParCat[(int) ($x['cat_id'] ?? $x['cat'] ?? 0)][] = $x;
  $categories = [];
  foreach ($cats as $c) {
    $cid = (int) $c['id']; $prods = $produitsParCat[$cid] ?? [];
    if (!$prods) continue;
    $groupes = [];
    foreach ($prods as $x) {
      $sid = (int) ($x['sub_cat_id'] ?? $x['subCat'] ?? 0);
      $k = isset($subLabel[$sid]) ? $sid : 0;
      if (!isset($groupes[$k])) $groupes[$k] = ['id' => $k, 'label' => $k ? $subLabel[$k] : 'Autres', 'ordre' => $k ? ($subOrder[$k] ?? 999) : 1000, 'products' => []];
      $portions = [];
      foreach ((array) ($x['portionOptions'] ?? []) as $po)
        if (($po['v'] ?? '') !== 'entier' && isset($po['price']) && $po['price'] !== null)
          $portions[] = ['label' => (string) ($po['name'] ?? $po['label'] ?? $po['v']), 'price' => (float) $po['price']];
      $img = (string) ($x['img'] ?? '');
      $groupes[$k]['products'][] = [
        'name' => (string) $x['name'], 'desc' => trim((string) ($x['description'] ?? '')),
        'price' => (float) $x['price'], 'portions' => $portions,
        'allergens' => is_array($x['allergens'] ?? null) ? array_values(array_map('strval', $x['allergens'])) : null,
        'season' => !empty($x['season']) ? (string) ($x['season_name'] ?: $x['season']) : '',
        'img' => ($img !== '' && strpos($img, 'placeholder') === false) ? (preg_match('#^https?://#', $img) ? $img : rtrim($racine, '/') . '/' . ltrim($img, '/')) : '',
      ];
    }
    usort($groupes, static fn ($a, $b) => $a['ordre'] <=> $b['ordre']);
    foreach ($groupes as &$g) usort($g['products'], static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
    unset($g);
    $categories[] = ['label' => (string) $c['label'], 'count' => count($prods), 'groupes' => array_values($groupes)];
  }

  // Saisons servies à cette date : nom, photo de gamme, nombre de produits —
  // les mêmes champs que le webshop (season, season_name, season_img).
  $saisons = [];
  foreach ($liste as $x) {
    if (empty($x['season'])) continue;
    $k = (string) $x['season'];
    if (!isset($saisons[$k])) {
      $si = (string) ($x['season_img'] ?? '');
      $saisons[$k] = ['key' => $k, 'name' => (string) ($x['season_name'] ?: $k), 'n' => 0,
                      'img' => ($si !== '' ? (preg_match('#^https?://#', $si) ? $si : rtrim($racine, '/') . '/' . ltrim($si, '/')) : '')];
    }
    $saisons[$k]['n']++;
  }
  $saisons = array_values($saisons);

  // Formules (menus composés) des produits servis — seulement s'il en existe.
  $formules = [];
  if (tbl_exists('ws_bundles')) {
    foreach ($liste as $x) {
      if (empty($x['has_menu_options'])) continue;
      $srcPid = function_exists('bundle_source_pid') ? bundle_source_pid((int) $x['id']) : (int) $x['id'];
      foreach (rows("SELECT id, name, description, price_modifier FROM ws_bundles WHERE product_id = ? AND active = 1 ORDER BY sort_order, id", [$srcPid]) as $b) {
        $slots = [];
        if (tbl_exists('ws_bundle_slots')) {
          foreach (rows("SELECT id, label FROM ws_bundle_slots WHERE bundle_id = ? AND active = 1 ORDER BY sort_order, id", [(int) $b['id']]) as $sl) {
            $choix = tbl_exists('ws_bundle_slot_choices')
              ? rows("SELECT label, delta FROM ws_bundle_slot_choices WHERE slot_id = ? AND active = 1 ORDER BY sort_order, id", [(int) $sl['id']]) : [];
            $slots[] = ['label' => (string) $sl['label'],
                        'choices' => array_map(static fn ($c) => ['label' => (string) $c['label'], 'delta' => (float) ($c['delta'] ?? 0)], $choix)];
          }
        }
        $formules[] = ['product' => (string) $x['name'], 'name' => (string) $b['name'], 'desc' => trim((string) ($b['description'] ?? '')),
                       'price' => (float) $x['price'] + (float) ($b['price_modifier'] ?? 0), 'img' => '', 'slots' => $slots];
      }
    }
  }

  // Bons en cours : canal webshop, actifs, réseau ou boutique, pour tous ou
  // pour CE bureau. Jamais ceux d'un autre client ni d'un autre bureau.
  $bons = [];
  if (tbl_exists('voucher_code') && tbl_exists('voucher_campaign') && tbl_exists('promotion_order_discount')) {
    try {
      $vs = rows("SELECT vco.code, vc.target_kind, vc.target_id, vco.valid_to AS expires_at, pod.discount_kind, pod.discount_value, pod.min_order_amount
                    FROM voucher_code vco
                    JOIN voucher_campaign vc          ON vc.id = vco.id_voucher_campaign
                    JOIN voucher_campaign_channel vcc ON vcc.id_voucher_campaign = vc.id AND vcc.channel = 'WS'
                    JOIN promotion pr                 ON pr.id = vc.id_promotion
                    JOIN promotion_order_discount pod ON pod.id_promotion = pr.id
                   WHERE pr.status = 'ACTIVE' AND vco.status = 'ACTIVE'
                     AND (vco.valid_to IS NULL OR vco.valid_to >= CURDATE())
                     AND (vc.id_shop IS NULL OR vc.id_shop = ?)
                   ORDER BY vco.code", [$shopId]);
      // TOUS les bons disponibles sur le webshop : réseau et boutique, pour tous
      // les clients ou pour un bureau. Pour le dossier d'UN bureau, seuls les
      // siens ; pour la boutique, ceux de chaque bureau avec le nom du bureau.
      // Jamais un bon nominatif (CUSTOMER) : il n'est disponible qu'à une personne.
      foreach ($vs as $v) {
        $tk = (string) $v['target_kind'];
        if ($tk === 'CUSTOMER') continue;
        if ($tk === 'OFFICE' && $officeId && (int) $v['target_id'] !== $officeId) continue;
        $nomBureau = '';
        if ($tk === 'OFFICE' && !$officeId && $v['target_id']) { $ob2 = row("SELECT name FROM ws_offices WHERE id=?", [(int) $v['target_id']]); $nomBureau = (string) ($ob2['name'] ?? ''); }
        $val = rtrim(rtrim((string) $v['discount_value'], '0'), '.');
        $effet = $v['discount_kind'] === 'PERCENT' ? "−$val %" : ($v['discount_kind'] === 'FIXED' ? "−$val\u{a0}€" : 'Livraison offerte');
        if ((float) $v['min_order_amount'] > 0) $effet .= ' dès ' . brochure_eur($v['min_order_amount']);
        $bons[] = ['code' => (string) $v['code'], 'effet' => $effet,
                   'validite' => $v['expires_at'] ? ('Valable jusqu\'au ' . date('d/m/Y', strtotime($v['expires_at']))) : 'Sans date limite',
                   'cible' => $tk === 'OFFICE' ? ($nomBureau !== '' ? 'Réservé au bureau ' . $nomBureau : 'Réservé à votre bureau') : ($tk === 'GROUP' ? 'Groupe de clients' : 'Tous les clients')];
      }
    } catch (Throwable $e) { $bons = []; }
  }

  // La promo automatique de la boutique (« 4 quarts achetés, 1 offert ») :
  // règle ERP ou locale, appliquée au panier sans code. Un avantage réel,
  // que le dossier doit annoncer comme les bons.
  if (function_exists('cross_portion_rule')) {
    try {
      $regle = cross_portion_rule($shopId);
      if ($regle && (int) $regle['buy'] > 0 && (int) $regle['free'] > 0) {
        $lab = trim((string) ($regle['label'] ?? ''));
        $bons[] = ['code' => $lab !== '' ? $lab : ((int) $regle['buy'] . ' + ' . (int) $regle['free']),
                   'effet' => (int) $regle['buy'] . ' portion' . ((int) $regle['buy'] > 1 ? 's' : '') . ' achetée' . ((int) $regle['buy'] > 1 ? 's' : '') . ', ' . (int) $regle['free'] . ' offerte' . ((int) $regle['free'] > 1 ? 's' : '')
                              . ((int) ($regle['threshold'] ?? 0) > (int) $regle['buy'] ? ' dès ' . (int) $regle['threshold'] . ' portions' : ''),
                   'validite' => 'Automatique au panier, sans code', 'cible' => 'Tous les clients'];
      }
    } catch (Throwable $e) {}
  }

  // Commander : jours, cut-off, dépôt, facturation — depuis la fiche du bureau
  // (invite_recap, la même que l'affiche) quand il y en a un.
  $commande = ['jours' => [], 'cutoff' => null, 'drop' => null, 'facturation' => null, 'webshop' => (string) ($shop['webshop_url'] ?: ''), 'fenetre' => null];
  if ($officeId && function_exists('invite_recap')) {
    $r = invite_recap($officeId);
    if ($r) {
      $commande['jours'] = (array) ($r['joursListe'] ?? []);
      $commande['cutoff'] = $r['cutoff'] ?? null;          // « 10:00 la veille »
      $commande['fenetre'] = $r['horaire'] ?? null;        // « 11:30 – 12:00 »
      $commande['drop'] = $office['drop_minutes'] ?? null;
      $commande['facturation'] = !empty($office['deferred_billing_enabled']) ? 'Facturation mensuelle, selon le contrat du bureau' : 'Paiement à la commande';
    }
  }

  $hero = '';
  foreach ([['Tartes'], []] as $pref) foreach ($categories as $c) { if ($pref && !in_array($c['label'], $pref, true)) continue; foreach ($c['groupes'] as $g) foreach ($g['products'] as $p) if ($p['img'] !== '' && $hero === '') $hero = $p['img']; }
  return ['hero' => $hero, 'shop' => ['name' => (string) $shop['name'], 'city' => (string) $shop['city'], 'address' => (string) ($shop['address'] ?? ''),
                     'phone' => (string) ($shop['phone'] ?? ''), 'email' => (string) ($shop['email'] ?? '')],
          'office' => $office ? ['name' => (string) $office['name'], 'assortment' => $off ? $off['mode'] : 'full'] : null,
          'showPrices' => $showPrices, 'qrUrl' => $qrUrl, 'qrCode' => $qrCode,
          'categories' => $categories, 'saisons' => $saisons, 'formules' => $formules, 'bons' => $bons, 'commande' => $commande,
          'date' => date('d/m/Y'), 'dispo' => date('d/m/Y', strtotime($date)), 'sansSaison' => (bool) $sansSaison, 'mois' => ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][(int) date('n')] . ' ' . date('Y')];
}

/* ── LE HTML (aucune lecture de base) ───────────────────────────────────── */
function brochure_render(array $d, $racine = '', $qrPng = null) {
  $e = static fn ($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
  $prix = $d['showPrices'];
  $logo = brochure_lire('logo.png') ?: brochure_lire('logo-white.png');
  $logoSrc = $logo ? ('data:image/png;base64,' . base64_encode($logo)) : '';
  $qrSrc = $qrPng ? ('data:image/png;base64,' . base64_encode($qrPng)) : '';
  $police = brochure_police('GC_Vank.ttf', 400, 'Vank') . brochure_police('Gotham_Light.otf', 300)
          . brochure_police('Gotham_Book.otf', 400) . brochure_police('Gotham_Medium.otf', 500);
  $shop = $d['shop']; $titreShop = $shop['name'] . ($shop['city'] && stripos($shop['name'], $shop['city']) === false ? ' — ' . $shop['city'] : '');
  $pages = [];

  /* Pagination des catégories : ~14 lignes par page (une sous-catégorie
     pèse 0,6 ligne). Une catégorie longue continue sur la page suivante,
     avec son titre repris et « (suite) ». */
  $CAP = 14.0;
  $pagesCat = [];
  foreach ($d['categories'] as $c) {
    $items = [];
    foreach ($c['groupes'] as $g) { $items[] = ['t' => 'sub', 'g' => $g, 'w' => 0.6]; foreach ($g['products'] as $p) $items[] = ['t' => 'p', 'p' => $p, 'w' => 1.0]; }
    $chunks = []; $cur = []; $poids = 0.0;
    foreach ($items as $it) {
      if ($poids + $it['w'] > $CAP && $cur) { $chunks[] = $cur; $cur = []; $poids = 0.0; }
      if ($it['t'] === 'sub' && $poids + 1.6 > $CAP && $cur) { $chunks[] = $cur; $cur = []; $poids = 0.0; }
      $cur[] = $it; $poids += $it['w'];
    }
    if ($cur) $chunks[] = $cur;
    foreach ($chunks as $i => $ch) $pagesCat[] = ['cat' => $c, 'items' => $ch, 'suite' => $i > 0, 'derniere' => $i === count($chunks) - 1];
  }
  $saisons = $d['saisons'] ?? [];
  $total = 1 + count($pagesCat) + (($saisons || $d['formules'] || $d['bons'] || $d['commande']['jours'] || $d['commande']['webshop']) ? 1 : 0);

  $entete = static fn ($titre, $sous = '') => '<header class="en-tete"><div><div class="sur-titre">' . $e($titreShop) . ' · Carte &amp; tarifs</div><h2 class="titre">' . $e($titre) . '</h2></div></header>';
  $pied = function ($n) use ($e, $logoSrc, $shop, $total, $prix) {
    return '<footer class="pied">' . ($logoSrc ? '<img class="pied__logo" src="' . $logoSrc . '" alt="L\'Atelier">' : '')
         . '<span>' . ($prix ? 'Prix TVAC · ' : '') . 'sous réserve de disponibilité et de saison · ' . $e($shop['name']) . ($shop['phone'] ? ' · ' . $e($shop['phone']) : '') . '</span><span class="pied__n">' . $n . ' / ' . $total . '</span></footer>';
  };

  // 1 · Couverture
  $familles = implode('', array_map(static fn ($c) => '<span class="chip">' . $e($c['label']) . '</span>', $d['categories']));
  $cover = '<section class="page page--couv">' . (!empty($d['hero']) ? '<div class="couv__photo"><img src="' . $e($d['hero']) . '" alt=""></div>' : '');
  $cover .= '<div class="couv__haut">' . ($logoSrc ? '<img class="couv__logo" src="' . $logoSrc . '" alt="L\'Atelier, sucré, salé, à emporter">' : '<div class="sur-titre">L\'Atelier · Sucré · Salé · À emporter</div>');
  $cover .= '<h1 class="couv__titre">Carte &amp; tarifs</h1><div class="couv__sous">Livraison au bureau · Click &amp; Collect · ' . $e($d['mois']) . '</div></div>';
  $cover .= '<hr class="filet">';
  $cover .= '<div class="couv__grille"><div class="bloc"><div class="etiquette">Votre boutique</div><div class="bloc__nom">' . $e($titreShop) . '</div>'
          . ($shop['address'] ? '<div class="bloc__l">' . $e($shop['address']) . ($shop['city'] ? ' · ' . $e($shop['city']) : '') . '</div>' : '')
          . (($shop['phone'] || $shop['email']) ? '<div class="bloc__l">' . $e(trim($shop['phone'] . ($shop['phone'] && $shop['email'] ? ' · ' : '') . $shop['email'])) . '</div>' : '') . '</div>';
  if ($d['office'] && $qrSrc) {
    $cover .= '<div class="couv__qr"><img class="qr" src="' . $qrSrc . '" alt="QR : créer mon compte rattaché à mon bureau"><div class="bloc"><div class="etiquette">Votre compte, rattaché à votre bureau</div>'
            . '<div class="bloc__l">Scannez : votre compte se crée déjà rattaché à <strong>' . $e($d['office']['name']) . '</strong>' . ($d['office']['assortment'] === 'custom' ? ', avec votre assortiment' : '') . ($prix ? '' : ', sans montant à régler') . '.</div>'
            . '<div class="bloc__l bloc__l--petit">Ou ouvrez : <strong class="code">' . $e(preg_replace('#^https?://#', '', (string) $d['qrUrl'])) . '</strong></div></div></div>';
  } elseif ($d['office']) {
    $cover .= '<div class="bloc"><div class="etiquette">Votre compte, rattaché à votre bureau</div><div class="bloc__l">Demandez le lien d\'invitation de <strong>' . $e($d['office']['name']) . '</strong> à votre boutique : votre compte se crée déjà rattaché.</div></div>';
  } else {
    $cover .= '<div class="bloc"><div class="etiquette">Commander</div><div class="bloc__l">Sur le webshop' . ($d['commande']['webshop'] ? ' : <strong>' . $e($d['commande']['webshop']) . '</strong>' : '') . '.</div></div>';
  }
  $cover .= '</div><div class="couv__bas"><div class="chips">' . $familles . '</div><div class="colophon">Édité depuis la console franchisé<br>le ' . $e($d['date']) . ($prix ? ' · prix TVAC' : ' · sans prix') . ((!empty($d['dispo']) && $d['dispo'] !== $d['date']) ? '<br>Disponibilités au ' . $e($d['dispo']) : '') . (!empty($d['sansSaison']) ? '<br>Sans les produits saisonniers' : '') . '</div></div></section>';
  $pages[] = $cover;

  // 2… · Catégories
  $n = 2;
  foreach ($pagesCat as $pc) {
    $c = $pc['cat'];
    $h = '<section class="page">' . $entete($c['label'] . ($pc['suite'] ? ' (suite)' : ''), $c['count'] . ' produit' . ($c['count'] > 1 ? 's' : '') . ' · ' . count($c['groupes']) . ' famille' . (count($c['groupes']) > 1 ? 's' : '') . '<br>Livraison au bureau');
    foreach ($pc['items'] as $it) {
      if ($it['t'] === 'sub') { $g = $it['g']; $h .= '<div class="sous"><span class="sous__t">' . $e($g['label']) . '</span></div>'; continue; }
      $p = $it['p'];
      $h .= '<div class="ligne"><div class="vignette">' . ($p['img'] ? '<img src="' . $e($p['img']) . '" alt="">' : '') . '</div><div class="ligne__c"><div class="ligne__nom">' . $e($p['name']) . (!empty($p['season']) ? ' <span class="saison">' . $e($p['season']) . '</span>' : '') . '</div>';
      $det = [];
      if ($p['desc'] !== '') $det[] = $e($p['desc']);
      if ($p['allergens']) $det[] = 'Allergènes : ' . $e(implode(', ', $p['allergens']));
      if ($prix && $p['portions']) $det[] = 'Portions : ' . implode(' · ', array_map(static fn ($po) => $e($po['label']) . ' ' . brochure_eur($po['price']), $p['portions']));
      if ($det) $h .= '<div class="ligne__d">' . implode(' — ', $det) . '</div>';
      $h .= '</div>' . ($prix ? '<div class="ligne__px">' . brochure_eur($p['price']) . '</div>' : '<div class="ligne__px"></div>') . '</div>';
    }
    $h .= $pied($n) . '</section>'; $pages[] = $h; $n++;
  }

  // Dernière · Formules, bons, commande
  if ($total > 1 + count($pagesCat)) {
    $h = '<section class="page">' . $entete($saisons ? 'Saisons, formules & avantages' : 'Formules & avantages');
    if ($saisons) {
      $h .= '<div class="rubrique">Gammes de saison servies' . (!empty($d['dispo']) ? ' au ' . $e($d['dispo']) : '') . '</div><div class="saisons">';
      foreach ($saisons as $sa) $h .= '<div class="saison-c">' . ($sa['img'] ? '<img src="' . $e($sa['img']) . '" alt="">' : '<div class="saison-c__vide"></div>') . '<div><div class="saison-c__nom">' . $e($sa['name']) . '</div><div class="saison-c__n">' . (int) $sa['n'] . ' produit' . ($sa['n'] > 1 ? 's' : '') . ' dans ce dossier</div></div></div>';
      $h .= '</div>';
    }
    if ($d['formules']) {
      $h .= '<div class="rubrique">Formules · menus composés</div>';
      foreach ($d['formules'] as $f) {
        $comp = implode(' · ', array_map(static fn ($s) => '<b>' . $e($s['label']) . '</b> ' . implode(', ', array_map(static fn ($c) => $e($c['label']) . ($c['delta'] > 0 ? ' (+' . brochure_eur($c['delta']) . ')' : ''), $s['choices'])), $f['slots']));
        $h .= '<div class="menu"><div class="menu__c"><div class="menu__nom">' . $e($f['name']) . ' <span class="menu__prod">· ' . $e($f['product']) . '</span></div>' . ($f['desc'] !== '' ? '<div class="menu__d">' . $e($f['desc']) . '</div>' : '') . ($comp ? '<div class="menu__d">' . $comp . '</div>' : '') . '</div>' . ($prix ? '<div class="ligne__px">' . brochure_eur($f['price']) . '</div>' : '') . '</div>';
      }
    }
    if ($d['bons']) {
      $h .= '<div class="rubrique">Bons &amp; avantages en cours</div><div class="bons">';
      foreach ($d['bons'] as $b) $h .= '<div class="bon"><span class="bon__code">' . $e($b['code']) . '</span><span class="bon__l">' . $e($b['effet']) . '</span><span class="bon__c">' . $e($b['validite']) . ' · ' . $e($b['cible']) . '</span></div>';
      $h .= '</div>';
    }
    $cm = $d['commande'];
    if ($cm['jours'] || $cm['cutoff'] || $cm['facturation'] || $cm['webshop']) {
      $h .= '<div class="rubrique">Commander &amp; se faire livrer</div><div class="cmd">';
      if ($cm['jours'] || $cm['cutoff']) $h .= '<div class="bloc"><div class="etiquette">Jours &amp; cut-off</div><div class="bloc__l">' . ($cm['jours'] ? $e(implode(', ', $cm['jours'])) : '') . ($cm['cutoff'] ? '<br>Commande avant ' . $e($cm['cutoff']) : '') . '</div></div>';
      if ($cm['fenetre'] || $cm['drop']) $h .= '<div class="bloc"><div class="etiquette">Livraison</div><div class="bloc__l">' . ($cm['fenetre'] ? $e($cm['fenetre']) : 'Au bureau') . ($cm['drop'] ? '<br>Dépôt à l\'accueil, ' . (int) $cm['drop'] . ' min' : '') . '</div></div>';
      if ($cm['facturation']) $h .= '<div class="bloc"><div class="etiquette">Facturation</div><div class="bloc__l">' . $e($cm['facturation']) . '</div></div>';
      if ($cm['webshop']) $h .= '<div class="bloc"><div class="etiquette">Webshop</div><div class="bloc__l">' . $e($cm['webshop']) . '</div></div>';
      $h .= '</div>';
    }
    $h .= $pied($n) . '</section>'; $pages[] = $h;
  }

  $titre = 'Carte & tarifs — ' . $shop['name'] . ($d['office'] ? ' — ' . $d['office']['name'] : '');
  $css = <<<CSS
:root{--color-primary:#8D1D2C;--color-secondary:#F2C9A0;--color-bg:#EAE4DC;--color-background-secondary:#F4EFE8;--color-surface:#FFFFFF;--color-text:#222222;--color-text-muted:#666666;--color-border-secondary:rgba(34,34,34,.18);--color-border-tertiary:rgba(34,34,34,.10);--font-ui:'Gotham',Helvetica,Arial,sans-serif;--font-display:'Vank',Georgia,serif;--tracking-admin:.08em;--weight-regular:400;--weight-medium:500}
*{box-sizing:border-box}html,body{margin:0}body{background:var(--color-bg);font-family:var(--font-ui);font-weight:var(--weight-regular);color:var(--color-text);-webkit-font-smoothing:antialiased;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.barre{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 18px;background:var(--color-surface);border-bottom:.5px solid var(--color-border-tertiary);font-size:13px}
.barre button{background:var(--color-primary);color:#fff;border:none;border-radius:8px;padding:10px 16px;font:500 14px var(--font-ui);cursor:pointer}
.doc{display:flex;flex-direction:column;align-items:center;gap:18px;padding:22px 12px 40px}
.page{width:210mm;min-height:297mm;background:var(--color-surface);padding:14mm 15mm 12mm;display:flex;flex-direction:column;gap:3.2mm;box-shadow:0 2px 14px rgba(0,0,0,.10);position:relative}
.page--couv{background:var(--color-background-secondary);padding:0;gap:0}
.sur-titre{font-size:11px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--color-primary)}
.etiquette{font-size:11px;font-weight:500;letter-spacing:var(--tracking-admin);text-transform:uppercase;color:var(--color-text-muted);margin-bottom:3px}
.titre{font-family:var(--font-display);font-weight:400;font-size:32px;line-height:1.05;margin:4px 0 0}
.en-tete{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;padding-bottom:2mm}
.en-tete__d{font-size:11px;color:var(--color-text-muted);text-align:right;line-height:1.5}
.filet{border:none;border-top:1px solid var(--color-border-tertiary);margin:0}
.sous{display:flex;align-items:baseline;gap:10px;padding-top:2.6mm;border-top:1px solid var(--color-border-tertiary)}
.sous__t{font-size:12px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:var(--color-primary)}.sous__n{font-size:11px;color:var(--color-text-muted)}
.ligne{display:grid;grid-template-columns:17mm 1fr auto;gap:4mm;align-items:center;padding:2mm 0;border-bottom:1px dotted var(--color-border-secondary);break-inside:avoid}
.vignette{width:17mm;height:12.5mm;border-radius:2mm;overflow:hidden;background:var(--color-background-secondary)}.vignette img{width:100%;height:100%;object-fit:cover;display:block}
.ligne__nom{font-size:13.5px;font-weight:500}.ligne__d{font-size:11.5px;color:var(--color-text-muted);margin-top:1px;line-height:1.45}
.ligne__px{font-size:14px;font-weight:500;font-variant-numeric:tabular-nums;white-space:nowrap;min-width:16mm;text-align:right}
.pied{margin-top:auto;display:flex;align-items:center;gap:14px;padding-top:2.5mm;border-top:1px solid var(--color-border-tertiary);font-size:10.5px;color:#8a8079}
.pied__logo{height:14px;width:auto;opacity:.85}.pied__n{margin-left:auto}
.rubrique{font-size:12px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:var(--color-primary);padding-top:3mm;border-top:1px solid var(--color-border-tertiary)}
.menu{display:grid;grid-template-columns:1fr auto;gap:4mm;align-items:start;padding:2.5mm 0;border-bottom:1px dotted var(--color-border-secondary)}
.menu__nom{font-size:14px;font-weight:500}.menu__prod{font-weight:400;color:var(--color-text-muted)}.menu__d{font-size:11.5px;color:#444;margin-top:1mm;line-height:1.5}.menu__d b{font-weight:500;color:var(--color-text)}
.saison{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:999px;background:var(--color-secondary);color:#6b4420;font-size:10px;font-weight:500;letter-spacing:.04em;vertical-align:middle}
.saisons{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:3mm}.saison-c{display:flex;gap:3mm;align-items:center;border:1px solid var(--color-border-secondary);border-radius:3mm;padding:2.5mm 3mm;background:var(--color-background-secondary)}
.saison-c img,.saison-c__vide{width:18mm;height:13mm;border-radius:2mm;object-fit:cover;flex:none;background:#fff}.saison-c__nom{font-size:13px;font-weight:500}.saison-c__n{font-size:11px;color:var(--color-text-muted)}
.bons{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:3mm}
.bon{border:1px solid var(--color-border-secondary);border-radius:3mm;padding:3mm 3.5mm;display:flex;flex-direction:column;gap:1mm;background:var(--color-background-secondary)}
.bon__code{font-family:Consolas,'SFMono-Regular',monospace;font-size:15px;font-weight:700;letter-spacing:.06em;color:var(--color-primary)}.bon__l{font-size:12.5px;font-weight:500}.bon__c{font-size:11px;color:var(--color-text-muted)}
.cmd{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:3mm}.bloc__nom{font-size:18px;font-weight:500}.bloc__l{font-size:13px;color:#444;line-height:1.5}.bloc__l--petit{font-size:12px}
.couv__photo{height:148mm;overflow:hidden;position:relative;flex:none}.couv__photo img{width:100%;height:100%;object-fit:cover;display:block}.couv__photo::after{content:'';position:absolute;left:0;right:0;bottom:0;height:42mm;background:linear-gradient(180deg,rgba(244,239,232,0),#F4EFE8)}
.couv__haut{padding:4mm 16mm 0;display:flex;flex-direction:column;gap:2mm}.couv__logo{width:62mm;height:auto;display:block;margin-bottom:2mm}
.couv__titre{font-family:var(--font-display);font-weight:400;font-size:52px;line-height:1.02;margin:0}.couv__sous{font-size:16px;color:var(--color-text-muted)}
.page--couv .filet{margin:6mm 16mm 0}.couv__grille{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6mm;padding:6mm 16mm 0}
.couv__qr{display:flex;gap:4mm;align-items:flex-start}.qr{width:28mm;height:28mm;flex:none;border-radius:2mm;border:1px solid var(--color-border-tertiary);display:block;background:#fff}
.code{font-family:Consolas,'SFMono-Regular',monospace;letter-spacing:.08em;color:var(--color-primary)}
.couv__bas{margin-top:auto;display:flex;justify-content:space-between;align-items:flex-end;gap:6mm;padding:0 16mm 14mm}.chips{display:flex;gap:2mm;flex-wrap:wrap}
.chip{font-size:11px;font-weight:500;padding:1.4mm 2.8mm;border-radius:999px;background:var(--color-primary);color:#fff}.colophon{font-size:11px;color:#8a8079;text-align:right;white-space:nowrap;flex:none}
@page{size:A4;margin:0}
@media print{body{background:#fff}.barre{display:none}.doc{padding:0;gap:0}.page{box-shadow:none;width:210mm;height:297mm;page-break-after:always;break-after:page}.page:last-child{page-break-after:auto;break-after:auto}}
CSS;
  return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($titre) . '</title><style>' . $police . $css . '</style></head><body>'
       . '<div class="barre"><span>' . $e($titre) . ' · ' . $total . ' page' . ($total > 1 ? 's' : '') . ' A4</span><button onclick="window.print()">Imprimer / enregistrer en PDF</button></div>'
       . '<main class="doc">' . implode('', $pages) . '</main></body></html>';
}
