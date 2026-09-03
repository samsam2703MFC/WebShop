<?php
/* Front controller — même API que le buddy-server Node, en PHP sur ws_.
 * .htaccess renvoie toutes les requêtes ici ; on route sur méthode + chemin. */
require __DIR__ . '/lib.php';
require __DIR__ . '/promo_lib.php';
require __DIR__ . '/tel.php';
require __DIR__ . '/erp_alias.php';
/* erp_catalog.php N'ÉTAIT PAS CHARGÉ — découvert le 31/08 par la sonde de prix.
   Le module était écrit, commenté et complet, mais aucun require ne le tirait :
   erp_catalog_enabled() n'existait donc pas, et TOUTES les gardes
   `function_exists('erp_catalog_enabled') && erp_catalog_enabled()` étaient
   fausses depuis toujours. Le catalogue n'a jamais servi l'intersection ERP.
   Ce qui le laissait croire : le miroir de sync_product_photos.php aligne
   ws_products.active sur webshop_active à chaque déploiement — les produits
   servis coïncidaient donc avec ceux de l'ERP sans qu'aucune intersection ne
   tourne. Une coïncidence de données prise pour un comportement. */
require __DIR__ . '/erp_catalog.php';
require __DIR__ . '/office_logo.php';
require __DIR__ . '/erp_promos.php';
require __DIR__ . '/erp_seasons.php';
require __DIR__ . '/erp_clients.php';
require __DIR__ . '/erp_link.php';
require __DIR__ . '/sms.php';

/* CORS */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = cfg()['cors_origins'];
if ($origin && (in_array($origin, $allowed, true) || in_array('*', $allowed, true))) {
  header("Access-Control-Allow-Origin: $origin");
  header('Access-Control-Allow-Credentials: true');
  // X-Admin-Token / X-Pin-Token : ce sont les en-têtes que les consoles
  // ENVOIENT réellement. Absents d'ici, le navigateur refusait la requête
  // au contrôle préalable dès que l'origine différait — ce qui rendait
  // inutilisable la surcharge « ?api=… » que api-config.js documente pour
  // les tests. En production les deux sont sur la même origine, d'où un
  // défaut invisible jusqu'à ce qu'on essaie de tester autrement.
  header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Token, X-Pin-Token');
  header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
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
/* ── Rafraîchissement AUTOMATIQUE des photos ERP, déclenché par la navigation.
 * Chaque affichage du catalogue relance la synchro photos (sync_product_photos
 * puis sync_product_images) — EN ARRIÈRE-PLAN et AU PLUS une fois par fenêtre
 * de `ws_param.photos_refresh_ttl` secondes (60 par défaut — « à chaque refresh »,
 * une visite par minute au plus déclenche ; 0 = coupé) : une
 * photo ajoutée dans l'ERP apparaît donc sans attendre un déploiement, et un
 * pic de visites ne déclenche qu'UN balayage. Le visiteur ne paie rien :
 * exec() rend la main immédiatement (nohup … &), la réponse part sans délai.
 * Si exec est indisponible sur ce PHP, on ne fait RIEN ici — /erp/probe le
 * dit (photos.auto=false) et la synchro reste celle des déploiements. */
function photos_refresh_due($stamp, $ttl) {
  if ($ttl <= 0) return false;                                  // coupé volontairement
  $age = is_file($stamp) ? time() - (int) filemtime($stamp) : PHP_INT_MAX;
  return $age >= $ttl;
}
function photos_exec_ok() {
  if (!function_exists('exec')) return false;
  $off = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
  return !in_array('exec', $off, true);
}
/* Lance le balayage photos EN ARRIÈRE-PLAN, tout de suite. Le tampon est posé
   AVANT le lancement : c'est lui qui fait l'anti-rafale, y compris entre deux
   requêtes simultanées (la fenêtre restante se compte en millisecondes, et
   les scripts eux-mêmes sont idempotents). */
function photos_refresh_spawn() {
  $dir = __DIR__ . '/../assets/product_pictures';
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
  @touch($dir . '/.last_refresh');
  $log = $dir . '/.refresh.log';
  if (is_file($log) && filesize($log) > 262144) @file_put_contents($log, '');   // journal borné
  $cmd = 'php ' . escapeshellarg(__DIR__ . '/sync_product_photos.php')
       . ' >> ' . escapeshellarg($log) . ' 2>&1'
       . ' && php ' . escapeshellarg(__DIR__ . '/sync_product_images.php')
       . ' >> ' . escapeshellarg($log) . ' 2>&1';
  @exec('nohup sh -c ' . escapeshellarg($cmd) . ' > /dev/null 2>&1 &');
  return true;
}
function photos_refresh_async() {
  try {
    $ttl = (int) ws_param('photos_refresh_ttl', 60);   // 60 s : chaque visite rafraîchit (assortiment, canaux, photos)
    $stamp = __DIR__ . '/../assets/product_pictures/.last_refresh';
    if (!photos_refresh_due($stamp, $ttl) || !photos_exec_ok()) return;
    photos_refresh_spawn();
  } catch (Throwable $e) { /* jamais au détriment de la réponse catalogue */ }
}

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

/* Image produit legacy (ws_products.img) : renvoyée UNIQUEMENT si elle est
   réellement servable. Une URL absolue est conservée telle quelle (on ne peut
   pas la vérifier ici) ; un chemin relatif n'est renvoyé que si le fichier
   existe sous la racine du webshop. Sinon null — le front dessine alors son
   illustration de repli, au lieu d'afficher une image cassée et de consigner
   une erreur à chaque rendu. */
function product_img_or_null($img) {
  $img = trim((string) ($img ?? ''));
  if ($img === '') return null;
  if (preg_match('#^(https?:)?//#i', $img) || str_starts_with($img, 'data:')) return $img;
  $rel = ltrim($img, '/');
  return is_file(__DIR__ . '/../' . $rel) ? $img : null;
}

/* PRIX DE VENTE — ws_products.price, et rien d'autre.
 *
 * UNE SEULE TABLE DÉCIDE DU WEBSHOP : ws_products. Ce qu'un produit vaut, et
 * s'il s'affiche, s'y lit — `price`, `active`, `webshop`, `office_delivery`.
 *
 * shop_product (ERP) N'INTERVIENT PLUS. C'était la table d'une autre
 * application, consultée par boutique, et elle a coûté cher : elle référence
 * des produits absents de ws_products, ce qu'une clé étrangère a révélé en
 * bloquant tous les déploiements. Le webshop n'a pas à connaître le modèle
 * interne de l'ERP ; il lit sa propre table, alimentée par lui.
 *
 * PLUS DE PARAMÈTRE BOUTIQUE, et c'est volontaire : tous les produits sont
 * communs à tous les magasins, donc le prix aussi. Garder un $shopId ignoré
 * aurait laissé croire à une variation par boutique qui n'existe plus.
 *
 * UNE SEULE FONCTION POUR TOUT LE MONDE — catalogue, panier, aperçu de
 * commande, facturation. C'est ce qui interdit d'afficher un prix et d'en
 * encaisser un autre.
 *
 * PRIX <= 0 = « NON FIXÉ », pas « gratuit » : le produit est masqué du
 * catalogue ET refusé à la commande, au lieu d'être facturé 0 €.
 */
function prix_produits(array $ids, $shopId = null) {
  if (!$ids) return [];
  /* SOURCE UNIQUE DU PRIX — affichage ET facturation.
     Quand ws_param.catalog_source vaut 'erp', le prix de vente est
     `portion_price_gross`, servi par shops/{id}/products/available. C'est
     l'information de l'utilisateur du 28/08, et la donnée la corrobore :
     ws_products.price portait 1,00 € sur 45 des 76 produits en vente — un
     remplissage, pas un prix. L'ERP donne 2,80 € pour un Coca 50 cl, 2,90 €
     pour un cookie.
     LA MÊME FONCTION sert le catalogue et POST /orders : c'est ce qui garantit
     que le prix annoncé est le prix débité. Les séparer, c'est afficher 2,80 €
     et facturer 1,00 € — exactement ce que ce fichier interdit deux commentaires
     plus loin.
     Le prix ERP est PAR BOUTIQUE : sans portée, il ne peut pas être résolu. */
  if ($shopId && function_exists('erp_catalog_enabled') && erp_catalog_enabled()) {
    // MÊME appel, MÊME cache que le catalogue : une seule requête ERP par vue.
    $av = erp_get('shops/' . (int) $shopId . '/products/available',
                  max(0, (int) ws_param('catalog_direct_ttl', 0)));
    $lst = is_array($av) ? (array_is_list($av) ? $av : ($av['data'] ?? $av['items'] ?? null)) : null;
    if (is_array($lst)) {
      $veut = array_flip(array_map('intval', $ids));
      $out = [];
      foreach ($lst as $pr) {
        if (!is_array($pr) || empty($pr['id'])) continue;
        $pid = (int) $pr['id'];
        if (!isset($veut[$pid])) continue;
        $px = $pr['portion_price_gross'] ?? ($pr['portion_price'] ?? null);
        /* 0 ou absent = PRIX NON FIXÉ, pas gratuit. Le produit sort de la
           carte : masqué du catalogue, refusé à la commande. Six produits
           sont dans ce cas au 28/08 (Melocake, Abricot, Plateau Mini Wraps…). */
        if (!is_numeric($px) || (float) $px <= 0) continue;
        $out[$pid] = (float) $px;
      }
      return $out;
    }
    /* ERP muet APRÈS cache : on ne retombe pas sur les prix locaux, qui sont
       justement ceux qu'on corrige. Rendre [] fait refuser la commande avec le
       nom du produit — bruyant, et jamais au mauvais prix. */
    if (function_exists('erp_notes')) erp_notes('prix : ERP injoignable, aucun prix résolu (boutique ' . (int) $shopId . ')');
    return [];
  }
  /* Source locale : soit l'ERP n'est pas la source, soit l'appelant n'a pas de
     boutique (écrans d'administration réseau). Dans ce second cas le prix ERP
     est inatteignable par construction — on le journalise plutôt que de le
     taire, parce qu'un écran qui affiche l'ancien prix pendant que la boutique
     en facture un autre est exactement le genre d'écart qu'on ne voit pas. */
  if (!$shopId && function_exists('erp_catalog_enabled') && erp_catalog_enabled()
      && function_exists('erp_notes')) {
    erp_notes('prix : appel sans boutique — prix LOCAL servi alors que la source est l\'ERP');
  }
  $in = implode(',', array_map('intval', $ids));
  $out = [];
  try {
    foreach (rows("SELECT id, price FROM ws_products WHERE id IN ($in)") as $r) {
      $px = (float) $r['price'];
      if ($px <= 0) continue;
      $out[(int) $r['id']] = $px;
    }
  } catch (Throwable $e) {
    error_log('[ws] prix produits indisponible: ' . $e->getMessage());
    return [];
  }
  return $out;
}

/* Options de PORTION, SERVIES PAR L'ENDPOINT
 * (shops/{id}/products/available?include=portions).
 *
 * Remplace une lecture de product_portion × shop_product_portion_price, copies
 * locales des tables de l'ERP. La charge utile est plus riche que la copie :
 * elle porte has_shop_price, is_ready_for_sale et shop_price_gross, là où la
 * table n'avait qu'un prix nu.
 *
 * UNE PORTION N'EST PROPOSABLE QU'AVEC LE PRIX DE SA BOUTIQUE. L'ancien code
 * se rabattait sur le prix d'une AUTRE boutique quand la sienne n'en avait pas
 * (« repli phase de test mono-boutique ») : annoncer un prix qui ne serait pas
 * celui débité est exactement ce que le résolveur unique existe pour empêcher,
 * et ce repli disparaît. L'endpoint sert le prix de la boutique demandée, il
 * n'y a plus de raison de deviner.
 *
 * Prix à 0 ou absent = NON FIXÉ, jamais gratuit : la portion n'est pas
 * proposée, donc elle ne peut pas être facturée 0 €.
 *
 * Renvoie [id_product => [ ['v','label','price','pp_id'], … ]]. */
function erp_portion_options($shopId, array $ids) {
  if (!$ids || !function_exists('erp_available_brut')) return [];
  $rows = erp_available_brut((int) $shopId);
  if (!is_array($rows)) return [];        // ERP muet : aucune portion annoncée

  $MAP = ['one_half' => 'demi', 'half' => 'demi', 'demi' => 'demi', '1/2' => 'demi',
          'one_quarter' => 'quart', 'quarter' => 'quart', 'quart' => 'quart', '1/4' => 'quart',
          'one_eighth' => 'huitieme', 'eighth' => 'huitieme', 'huitieme' => 'huitieme', '1/8' => 'huitieme'];
  $LBL = ['demi' => '1/2', 'quart' => '1/4', 'huitieme' => '1/8'];

  $out = [];
  foreach ($ids as $pid) {
    $pid = (int) $pid;
    $r = $rows[$pid] ?? null;
    if (!$r || empty($r['portions']) || !is_array($r['portions'])) continue;
    $liste = $r['portions'];
    /* display_order fait l'ordre, comme l'ORDER BY d'avant : 1/2 puis 1/4 puis
       1/8, et non l'ordre d'arrivée de la réponse. */
    usort($liste, static fn($a, $b) => ((int) ($a['display_order'] ?? 0)) <=> ((int) ($b['display_order'] ?? 0)));
    foreach ($liste as $q) {
      if (!is_array($q)) continue;
      if (isset($q['is_active']) && !(int) $q['is_active']) continue;
      $v = $MAP[mb_strtolower(trim((string) ($q['portion_type'] ?? '')))] ?? null;
      if (!$v) continue;
      $vendable = !empty($q['has_shop_price']);
      if ($vendable && array_key_exists('is_ready_for_sale', $q)) $vendable = !empty($q['is_ready_for_sale']);
      $price = null;
      if ($vendable) {
        // TVAC d'abord : c'est ce que le client paie. `shop_price` sans suffixe
        // n'est pris qu'à défaut, sa base TVA n'étant pas documentée.
        $raw = $q['shop_price_gross'] ?? ($q['shop_price'] ?? null);
        if (is_numeric($raw) && (float) $raw > 0) $price = (float) $raw;
      }
      $out[$pid][] = ['v' => $v, 'label' => $LBL[$v], 'price' => $price,
                      'pp_id' => (int) ($q['id'] ?? 0)];
    }
  }
  return $out;
}

/* Upsert d'un BON dans le modèle ERP unifié (promotion → voucher_campaign →
   voucher_code, canal WS). Chemin d'écriture UNIQUE partagé marque/franchisé :
   - id_shop NULL  = bon MARQUE (réseau)   · id_shop = X = bon de la boutique X ;
   - owner_guard   : si int, refuse de modifier un code appartenant à une autre
     boutique (ou à la marque) — c'est le verrou d'édition du BO franchisé ;
   - reason_kind / reason_note / created_by : traçabilité (migration 0041).
   Retourne ['ok'=>true] ou ['error'=>…, 'status'=>4xx]. */
function ws_voucher_upsert(array $o) {
  $code = strtoupper(trim((string) ($o['code'] ?? '')));
  if ($code === '') {
    // Code facultatif : génération d'un code LISIBLE et UNIQUE (sans caractères
    // ambigus 0/O/1/I). Préfixe selon l'émetteur : « B<shop>- » pour une
    // boutique, « M- » pour la marque. Vérifié contre voucher_code.
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $prefix = !empty($o['id_shop']) ? ('B' . (int) $o['id_shop'] . '-') : 'M-';
    for ($try = 0; $try < 25 && $code === ''; $try++) {
      $rnd = '';
      for ($i = 0; $i < 6; $i++) $rnd .= $alpha[random_int(0, strlen($alpha) - 1)];
      $cand = $prefix . $rnd;
      if (!row("SELECT 1 x FROM voucher_code WHERE code=? LIMIT 1", [$cand])) $code = $cand;
    }
    if ($code === '') return ['error' => 'génération du code impossible, réessayez', 'status' => 500];
  }
  $type    = in_array($o['type'] ?? 'percent', ['percent','fixed','free_delivery'], true) ? $o['type'] : 'percent';
  $kindMap = ['percent'=>'PERCENT','fixed'=>'FIXED','free_delivery'=>'FREE_DELIVERY'];
  $kind    = $kindMap[$type];
  $value   = $type === 'free_delivery' ? null : (float) ($o['value'] ?? 0);
  $minOrd  = (float) ($o['min_order'] ?? 0);
  $maxUses = isset($o['max_uses']) && $o['max_uses'] !== '' ? (int) $o['max_uses'] : null;
  $perCust = isset($o['per_customer']) && $o['per_customer'] !== '' && $o['per_customer'] !== null ? max(1, (int) $o['per_customer']) : null;
  $exp     = !empty($o['expires_at']) ? $o['expires_at'] : null;
  $active  = isset($o['active']) ? (!empty($o['active']) ? 1 : 0) : 1;
  $status  = $active ? 'ACTIVE' : 'DRAFT';
  $cstatus = $active ? 'ACTIVE' : 'DISABLED';
  $idBrand = (int) ($o['id_brand'] ?? 1);
  $idShop  = isset($o['id_shop']) && $o['id_shop'] !== null && $o['id_shop'] !== '' ? (int) $o['id_shop'] : null;
  $tkind = strtoupper(trim($o['target_kind'] ?? 'NETWORK'));
  if (!in_array($tkind, ['NETWORK','CUSTOMER','OFFICE','GROUP'], true)) $tkind = 'NETWORK';
  $tid   = ($tkind !== 'NETWORK' && isset($o['target_id']) && $o['target_id'] !== '') ? (int) $o['target_id'] : null;
  if ($tkind !== 'NETWORK' && $tid === null) return ['error' => 'target_id requis pour un bon ciblé', 'status' => 400];
  $reqCust = $tkind === 'CUSTOMER' ? 1 : 0;
  $custId  = $tkind === 'CUSTOMER' ? $tid : null;
  $hasReason = col_exists('voucher_campaign', 'reason_kind');
  $hasScope = col_exists('promotion_order_discount', 'scope_id_product');
  $scopePid = isset($o['scope_product_id']) && $o['scope_product_id'] !== '' && $o['scope_product_id'] !== null ? (int) $o['scope_product_id'] : null;
  $scopeQty = isset($o['scope_max_qty']) && $o['scope_max_qty'] !== '' && $o['scope_max_qty'] !== null ? max(1, (int) $o['scope_max_qty']) : null;
  if ($scopePid === null) $scopeQty = null;   // pas de plafond sans périmètre
  $rKind = isset($o['reason_kind']) && $o['reason_kind'] !== '' ? mb_substr(strtoupper(trim((string) $o['reason_kind'])), 0, 24) : null;
  $rNote = isset($o['reason_note']) && $o['reason_note'] !== '' ? mb_substr(trim((string) $o['reason_note']), 0, 255) : null;
  $cBy   = isset($o['created_by']) ? mb_substr(trim((string) $o['created_by']), 0, 64) : null;
  $name  = ($idShop !== null ? 'Boutique ' . $idShop : 'Webshop') . ' — ' . $code;
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $ex = row("SELECT vco.id AS code_id, vc.id AS campaign_id, vc.id_promotion AS promotion_id, vc.id_shop
                 FROM voucher_code vco JOIN voucher_campaign vc ON vc.id = vco.id_voucher_campaign
                WHERE vco.code = ? LIMIT 1", [$code]);
    // Verrou d'édition : une boutique ne modifie que SES bons.
    if ($ex && array_key_exists('owner_guard', $o) && $o['owner_guard'] !== null
           && (int) ($ex['id_shop'] ?? 0) !== (int) $o['owner_guard']) {
      $pdo->rollBack();
      return ['error' => 'code déjà utilisé par ' . ($ex['id_shop'] === null ? 'la marque' : ('la boutique ' . $ex['id_shop'])), 'status' => 403];
    }
    $reasonSetSql = $hasReason ? ", reason_kind=?, reason_note=?, created_by=COALESCE(?, created_by)" : "";
    if ($ex) {
      q("UPDATE promotion SET status=?, valid_to=? WHERE id=?", [$status, $exp, $ex['promotion_id']]);
      $podSet = "discount_kind=?, discount_value=?, min_order_amount=?" . ($hasScope ? ", scope_id_product=?, scope_max_qty=?" : "");
      $podPar = [$kind, $value, $minOrd];
      if ($hasScope) { $podPar[] = $scopePid; $podPar[] = $scopeQty; }
      $podPar[] = $ex['promotion_id'];
      q("UPDATE promotion_order_discount SET $podSet WHERE id_promotion=?", $podPar);
      // NB : id_shop (l'émetteur) n'est JAMAIS modifié à l'update — un bon garde
      // son propriétaire (marque ou boutique) toute sa vie.
      $params = [$exp, $maxUses, $idBrand, $tkind, $tid, $reqCust];
      if ($hasReason) { $params[] = $rKind; $params[] = $rNote; $params[] = $cBy; }
      $params[] = $ex['campaign_id'];
      array_splice($params, 2, 0, [$perCust]);
      q("UPDATE voucher_campaign SET valid_to=?, usage_limit_total=?, usage_limit_per_customer=?, id_brand=?, target_kind=?, target_id=?, requires_customer=?$reasonSetSql WHERE id=?",
        $params);
      q("UPDATE voucher_code SET status=?, valid_to=?, usage_limit=?, id_customer=? WHERE id=?",
        [$cstatus, $exp, $maxUses, $custId, $ex['code_id']]);
      q("INSERT IGNORE INTO voucher_campaign_channel (id_voucher_campaign, channel) VALUES (?, 'WS')", [$ex['campaign_id']]);
    } else {
      q("INSERT INTO promotion (name, description, promotion_type, status, priority, is_exclusive,
             valid_from, valid_to, is_repeatable, shop_scope_type, activation_mode, soft_delete)
         VALUES (?, ?, 'ORDER_DISCOUNT', ?, 0, 0, NULL, ?, 0, 'ALL_SHOPS', 'VOUCHER_ONLY', 0)",
        [$name, $idShop !== null ? 'Bon boutique (franchisé)' : 'Bon marque (franchisor)', $status, $exp]);
      $pid = $pdo->lastInsertId();
      if ($hasScope) {
        q("INSERT INTO promotion_order_discount (id_promotion, discount_kind, discount_value, min_order_amount, scope_id_product, scope_max_qty)
           VALUES (?,?,?,?,?,?)", [$pid, $kind, $value, $minOrd, $scopePid, $scopeQty]);
      } else {
        q("INSERT INTO promotion_order_discount (id_promotion, discount_kind, discount_value, min_order_amount)
           VALUES (?,?,?,?)", [$pid, $kind, $value, $minOrd]);
      }
      $cols = "id_promotion, name, planned_promotion_type, status, code_type,
               valid_from, valid_to, usage_limit_total, usage_limit_per_code, usage_limit_per_customer,
               requires_customer, id_brand, id_shop, target_kind, target_id";
      $vals = "?,?, 'ORDER_DISCOUNT', ?, 'SHARED', NULL, ?, ?, NULL, ?, ?, ?, ?, ?, ?";
      $params = [$pid, $name, $status, $exp, $maxUses, $perCust, $reqCust, $idBrand, $idShop, $tkind, $tid];
      if ($hasReason) { $cols .= ", reason_kind, reason_note, created_by"; $vals .= ", ?, ?, ?"; $params[] = $rKind; $params[] = $rNote; $params[] = $cBy; }
      q("INSERT INTO voucher_campaign ($cols) VALUES ($vals)", $params);
      $cid = $pdo->lastInsertId();
      q("INSERT INTO voucher_code (id_voucher_campaign, code, status, valid_from, valid_to, usage_limit, usage_count, id_customer)
         VALUES (?,?,?, NULL, ?, ?, 0, ?)", [$cid, $code, $cstatus, $exp, $maxUses, $custId]);
      q("INSERT INTO voucher_campaign_channel (id_voucher_campaign, channel) VALUES (?, 'WS')", [$cid]);
    }
    $pdo->commit();
    return ['ok' => true, 'code' => $code];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

/* ─────────────────────────── Routes ─────────────────────────── */
  /* LE CATALOGUE SERVI — SOURCE UNIQUE.
     Extrait de la route /catalog/products pour que la BARRE DE CATÉGORIES en
     dérive au lieu de le redéduire. Les deux appliquaient des règles
     différentes : la barre exigeait ws_categories.active = 1 mais ignorait le
     prix ERP et le canal bureau, la grille l'inverse. Résultat constaté à
     l'écran — des cookies vendus sans catégorie où les retrouver, et des
     catégories menant à une grille vide.

     Le prix ERP est appliqué EN PHP (array_filter sur price > 0), après la
     requête : aucune clause SQL ne peut le répliquer. Partager la fonction est
     donc la seule façon d'empêcher les deux vues de diverger à nouveau. */
  function catalog_produits_servis($s, $mode = '', $date = null, $lang = '', $forcer = null) {
    // Filtre livraison bureau PARTAGÉ (source unique) : en mode 'delivery'/'office',
    // on EXCLUT serveur-side les produits non éligibles au canal bureau
    // (office_delivery=0), pour que TOUT front (webshop online, webshop après
    // handoff PWA, PWA, …) reçoive exactement la même liste — sans dépendre d'un
    // filtrage client ni d'un état résiduel. Sans mode → liste complète (les flags
    // office_delivery/no_delivery restent exposés pour l'UI).
    $mode = strtolower((string) ($mode ?: ''));
    /* DEUX CANAUX, DEUX FILTRES SYMÉTRIQUES (migration 0071).
       `active` ne veut plus dire « vendu sur le webshop » : il veut dire
       « publié au catalogue ». Le canal click & collect a sa colonne, comme le
       bureau a la sienne. Avant, retirer un produit du webshop le retirait
       aussi de la livraison bureau — « bureau seulement » était impossible.
       SANS MODE, aucun filtre de canal : l'appelant veut la liste complète et
       reçoit les deux drapeaux pour décider lui-même. */
    $hasCanal = col_exists('ws_products', 'click_and_collect');
    $deliveryWhere = in_array($mode, ['delivery', 'office', 'apricot'], true)
      ? ' AND COALESCE(p.office_delivery,1) = 1'
      : (($mode === 'collect' && $hasCanal) ? ' AND COALESCE(p.click_and_collect,1) = 1' : '');
    // Gammes saisonnières (product_availability_period) : filtrées sur la DATE
    // DE RETRAIT/LIVRAISON, pas sur aujourd'hui. Un client qui commande le
    // 28 novembre pour le 2 décembre doit voir la gamme de Noël ; c'est la date
    // de remise de la marchandise qui fait foi, pas celle de la commande.
    [$seasonWhere, $seasonArgs] = availability_where('p', $date, $s, $forcer);
    // Déclencheurs de menu explicites (0078) : injectés dans has_menu_options
    // seulement si la table existe (réplica pas encore migré → ancien chemin).
    $trgSql = tbl_exists('ws_bundle_triggers')
      ? " OR EXISTS(SELECT 1 FROM ws_bundle_triggers tg
                     JOIN ws_bundles tb ON tb.product_id = tg.product_id AND tb.active = 1
                     WHERE " . (col_exists('ws_bundle_triggers', 'article_id')
                       ? "(tg.article_id = p.id OR (tg.article_id IS NULL AND tg.cat_id = p.cat_id
                            AND (tg.sub_cat_id IS NULL OR tg.sub_cat_id = p.sub_cat_id)))"
                       : "(tg.cat_id = p.cat_id AND (tg.sub_cat_id IS NULL OR tg.sub_cat_id = p.sub_cat_id))") . ")"
      : '';
    // `badge` (texte) a été migré en FK tag_id -> ws_tags ; on expose le libellé
    // du tag sous la clé `badge` (rétro-compat UI) + couleurs, et la saison.
    $r = rows("SELECT p.id, p.cat_id, p.sub_cat_id,
                      p.cat_id AS cat, p.sub_cat_id AS subCat, c.label AS category,
                      p.name, p.description, p.img,
                      t.tag AS badge, t.slug AS tag_slug, t.bg_color AS tag_bg, t.text_color AS tag_text,
                      NULL AS season, NULL AS season_name, NULL AS season_img,
                      p.portions, p.cross_portion,
                      -- Menu déclenché par la catégorie (menu_default), surchargé par
                      -- le produit (menu_override 'on'/'off'/NULL=hérite). has_menu_options
                      -- est RÉSOLU serveur : le front et /orders reçoivent la valeur finale.
                      -- Armé par : surcharge produit, sinon déclencheur
                      -- EXPLICITE du menu (ws_bundle_triggers 0078 — la
                      -- sous-catégorie du produit compte comme « catégorie ET
                      -- sous-catégorie »), sinon l'ancien menu_default.
                      COALESCE(
                        CASE p.menu_override WHEN 'on' THEN 1 WHEN 'off' THEN 0 END,
                        IF(COALESCE(c.menu_default,0) = 1$trgSql, 1, 0)
                      ) AS has_menu_options,
                      p.price AS price, ps.no_delivery,
                      COALESCE(p.office_delivery,1) AS office_delivery," .
             ($hasCanal ? " COALESCE(p.click_and_collect,1) AS click_and_collect," : " 1 AS click_and_collect,") . "
                      NULL AS allergens   /* renseigné par l'ERP juste après : voir l'intersection ci-dessous */
                 FROM ws_products p
                 LEFT JOIN ws_product_shops ps ON ps.product_id = p.id AND ps.shop_id = ?
                 LEFT JOIN ws_categories c ON c.id = p.cat_id
                 LEFT JOIN ws_tags t ON t.id = p.tag_id
                WHERE p.active = 1$deliveryWhere$seasonWhere
                ORDER BY c.sort_order, p.name", array_merge([$s], $seasonArgs));
    /* ── ASSORTIMENT EN DIRECT DE L'ERP (décision du 24/08 : « pas de fallback
       miroir, seulement l'API direct »). Quand ws_param.catalog_source vaut
       'erp', la liste servie est l'INTERSECTION du SQL local (prix,
       enrichissements) et de la réponse VIVANTE de l'ERP : un produit retiré
       ou fermé dans Franchise Buddy disparaît à la minute (cache 60 s), sans
       attendre le miroir. Les canaux affichés sont ceux de l'ERP à l'instant.
       ERP injoignable après cache → 503 HONNÊTE : le bandeau d'erreur du
       front l'affiche — c'est le choix assumé, pas de rémanence. Un produit
       local jamais créé dans l'ERP n'est plus servi. ── */
    if (function_exists('erp_catalog_enabled') && erp_catalog_enabled()) {
      /* ZÉRO CACHE par défaut (décision du 24/08) : un appel ERP par affichage
         du catalogue — « je change dans l'ERP, c'est là ». Coût assumé :
         ~0,5-1,5 s de latence en plus par vue, et l'ERP reçoit une requête
         par visiteur ; ws_param.catalog_direct_ttl (secondes) permet de
         remonter un cache si un jour la charge ou la latence l'exigent. */
      $av = erp_get('shops/' . (int) $s . '/products/available',
                    max(0, (int) ws_param('catalog_direct_ttl', 0)));
      $lst = is_array($av) ? (array_is_list($av) ? $av : ($av['data'] ?? $av['items'] ?? null)) : null;
      if (!is_array($lst)) {
        json_out(['error' => 'Catalogue ERP injoignable — nouvel essai automatique, revenez dans un instant.'], 503);
      }
      $aval = [];
      foreach ($lst as $pr2) {
        if (is_array($pr2) && !empty($pr2['id']) && !empty($pr2['webshop_active'])) $aval[(int) $pr2['id']] = $pr2;
      }
      $r = array_values(array_filter($r, fn ($x2) => isset($aval[(int) $x2['id']])));
      foreach ($r as &$x) {
        $pr2 = $aval[(int) $x['id']];
        if (isset($pr2['click_and_collect'])) $x['click_and_collect'] = (int) !!$pr2['click_and_collect'];
        if (isset($pr2['office_delivery']))   $x['office_delivery']   = (int) !!$pr2['office_delivery'];
      }
      unset($x);
      // Le filtre de canal du mode se rejoue sur les valeurs VIVANTES (le
      // WHERE SQL a filtré sur les colonnes locales, peut-être en retard).
      if (in_array($mode, ['delivery', 'office', 'apricot'], true)) {
        $r = array_values(array_filter($r, fn ($x2) => (int) $x2['office_delivery'] === 1));
      } elseif ($mode === 'collect') {
        $r = array_values(array_filter($r, fn ($x2) => (int) $x2['click_and_collect'] === 1));
      }
    }
    $photos = product_photo_files();
    foreach ($r as &$x) {
      // Image produit : la photo déposée (assets/product_pictures/{id}.png|jpg) FAIT
      // AUTORITÉ si le fichier existe (c'est la vraie photo produit, uploadée
      // exprès) ; sinon on retombe sur ws_products.img (legacy) ; sinon null (le
      // front affiche l'illustration line-art de repli).
      // Le repli legacy n'était pas vérifié : un chemin mort partait au front,
      // qui affichait une image cassée ET consignait une erreur par produit et
      // par rendu (des centaines en navigation). On ne renvoie une image que si
      // le fichier existe réellement ; sinon null, et le front dessine son
      // illustration de repli — ce que le commentaire promettait déjà.
      $x['img'] = $photos[$x['id']] ?? product_img_or_null($x['img'] ?? null);
      $x['portions'] = (bool) $x['portions'];
      $x['cross_portion'] = (bool) $x['cross_portion'];   // périmètre ERP appliqué plus bas
      $x['has_menu_options'] = (bool) $x['has_menu_options'];
      $x['no_delivery'] = (bool) $x['no_delivery'];
      // Canal livraison bureau (« apricot ») : disponibilité produit dédiée,
      // indépendante de la visibilité webshop. Le front bloque le produit en
      // mode livraison quand c'est faux (cf. no_delivery).
      $x['office_delivery'] = (bool) $x['office_delivery'];
      $x['click_and_collect'] = (bool) $x['click_and_collect'];
      $x['price'] = (float) $x['price'];
      // TROIS états d'allergènes, jamais confondus (sécurité alimentaire) :
      //   liste  = allergènes connus ; []  = recette évaluée, réellement aucun ;
      //   null   = NON RENSEIGNÉ (le front l'affiche comme tel).
      // La valeur part à NULL ici et n'est renseignée que par l'ERP, plus bas :
      // sa réponse fait foi. Un produit que l'ERP ne documente pas reste donc
      // « non renseigné », jamais « aucun allergène » — la nuance est de la
      // sécurité alimentaire, pas du style.
      $x['allergens'] = $x['allergens'] ? json_decode($x['allergens']) : null;
    }
    unset($x);
    // PORTIONS pilotées par l'ERP : candidats (product_portion) × PRIX PAR
    // BOUTIQUE (shop_product_portion_price). AFFICHÉ ⇔ COMMANDABLE : le badge
    // « Disponible en portions » n'existe QUE si l'ERP offre au moins une
    // portion AVEC prix — la commande refuse de toute façon une portion sans
    // prix ERP (409). Le vieux drapeau ws_products.portions ne décide plus
    // rien : constaté sur « Penne all'Arrabbiata » (2110020), il affichait des
    // portions que l'ERP ne déclare pas et que la commande aurait refusées.
    try {
      $popts = erp_portion_options($s, array_map(fn ($x2) => (int) $x2['id'], $r));
      foreach ($r as &$x) {
        $cand = $popts[(int) $x['id']] ?? null;
        $offered = $cand ? array_values(array_filter($cand, fn ($c) => $c['price'] !== null)) : [];
        if (!$offered) { $x['portionOptions'] = null; $x['portions'] = false; continue; }
        $x['portionOptions'] = array_merge(
          [['v' => 'entier', 'label' => 'Entière', 'price' => (float) $x['price']]],
          array_map(fn ($c) => ['v' => $c['v'], 'label' => $c['label'], 'price' => (float) $c['price']], $offered));
        $x['portionTypes'] = array_merge(['entier'], array_map(fn ($c) => $c['v'], $offered));
        $x['portions'] = true;
      }
      unset($x);
    } catch (Throwable $e) {
      // Modèle portions illisible : on n'affirme rien — aucun badge plutôt
      // qu'un badge invérifiable (la commande refuserait la portion).
      foreach ($r as &$x) { $x['portionOptions'] = null; $x['portions'] = false; }
      unset($x);
      error_log('[ws] portions ERP indisponibles: ' . $e->getMessage());
    }
    // Allergènes RÉELS (source de vérité = ERP, même base atelierby_db) : dérivés du
    // modèle recette → matières → allergènes. Clé de liaison : ws_products.id =
    // product.id. SÉMANTIQUE STRICTE (sécurité alimentaire, règle « vraies
    // données ou bug ») — l'ERP ne fait autorité pour dire « aucun allergène »
    // QUE si la recette a réellement été évaluée :
    //   • codes non vides                       → liste d'allergènes ;
    //   • recette liée ET ≥1 ingrédient, 0 code → [] (évaluée : aucun) ;
    //   • pas de recette / recette vide         → null (NON RENSEIGNÉ — l'ancien
    //     comportement affichait ces produits comme « sans allergène »).
    // SOURCE : L'ENDPOINT, plus la copie locale des tables de l'ERP.
    // Le calcul SQL qui vivait ici (product → flattened_recipe_ingredient →
    // allergen) lisait une copie PÉRIMÉE : sur les 81 produits servis, elle
    // documentait 42 produits et en laissait 29 inconnus, là où
    // `grouped_allergens` en documente 49 et n'en laisse que 10. En sécurité
    // alimentaire, la source la plus fraîche et la plus complète gagne.
    // ERP muet ou produit absent de sa réponse : l'état reste celui d'avant
    // (null « non renseigné »), jamais « aucun allergène ».
    if ($r && function_exists('erp_allergenes')) {
      $alg = erp_allergenes($s, $lang ?: 'fr');   /* $lang peut être '' : l'elvis, pas le ?? */
      if (is_array($alg)) {
        foreach ($r as &$x) {
          if (array_key_exists((int) $x['id'], $alg)) $x['allergens'] = $alg[(int) $x['id']];
        }
        unset($x);
      }
    }
    /* PRIX — ws_products.price, lu par la MÊME fonction que le panier et la
       facturation. Un prix <= 0 vaut « non fixé » : le produit est écarté par
       le filtre « price > 0 » plus bas, et le diagnostic le DIT plutôt que de
       le laisser deviner. Plus de 503 « prix magasin indisponibles » : il
       n'existe plus de source externe qui pourrait manquer — la table du
       webshop est là ou la base entière est tombée, ce que le reste signale
       déjà. */
    if ($r) {
      $store = prix_produits(array_map(static fn($p2) => (int) $p2['id'], $r), (int) $s);
      $sansPrix = [];
      foreach ($r as &$x) {
        if (isset($store[(int) $x['id']])) $x['price'] = $store[(int) $x['id']];
        else { $x['price'] = 0.0; $sansPrix[] = (int) $x['id']; }
      }
      unset($x);
      if ($sansPrix) {
        error_log('[ws] catalogue : ' . count($sansPrix)
          . ' produit(s) sans prix (ws_products.price <= 0), masqués (ids: '
          . implode(',', array_slice($sansPrix, 0, 30)) . (count($sansPrix) > 30 ? ',…' : '') . ')');
      }
    }
    // Le prix de la portion ENTIÈRE suit le prix FINAL (magasin ERP inclus) —
    // il était figé AVANT la surcharge : « Entière €1.00 » (prix ws résiduel)
    // alors que la carte affichait 18,90 €.
    foreach ($r as &$x) {
      if (!empty($x['portionOptions']) && is_array($x['portionOptions'])) {
        foreach ($x['portionOptions'] as &$po) {
          if (($po['v'] ?? '') === 'entier') $po['price'] = (float) $x['price'];
        }
        unset($po);
      }
    }
    /* BUNDLES ERP (promotions) : le bundle se SIGNALE sur la vignette et se
       CHOISIT dans la fiche. `bundleOffer` = {id, name, price, regular, saving,
       items[{productId, name, portion, qty, unit}]}, ou null. L'économie est
       calculée avec les prix résolus ICI (les mêmes qu'à la commande), jamais
       reçue du navigateur ; sans bundle ERP complet et tarifé : null. */
    try {
      $idsB = array_map(static fn ($x2) => (int) $x2['id'], $r);
      $nomsB = []; foreach ($r as $x2) $nomsB[(int) $x2['id']] = (string) $x2['name'];
      $prixB = []; foreach ($r as $x2) if ((float) $x2['price'] > 0) $prixB[(int) $x2['id']] = (float) $x2['price'];
      $offres = function_exists('erp_bundle_offres')
        ? erp_bundle_offres((int) $s, $idsB, $prixB, $popts ?? [], $nomsB) : [];
      foreach ($r as &$x) $x['bundleOffer'] = $offres[(int) $x['id']] ?? null;
      unset($x);
    } catch (Throwable $e) {
      error_log('[ws] bundles ERP : ' . $e->getMessage());
      foreach ($r as &$x) $x['bundleOffer'] = null;
      unset($x);
    }
    unset($x);
    // Règle : on n'affiche QUE les produits à prix non nul (> 0). Un produit dont le
    // prix effectif (magasin ERP, ou repli ws_) vaut 0 n'est pas vendable → masqué.
    // Appliqué APRÈS la surcharge du prix magasin pour couvrir les deux sources.
    $r = array_values(array_filter($r, static fn($x) => (float) $x['price'] > 0));

    /* GAMME DU PRODUIT servie par l'ERP. Le filtre de la barre matche
       product.season contre l'id de la vignette : si les vignettes viennent de
       l'ERP et les produits d'une table locale, le filtre ne trouve RIEN. On réécrit
       donc la gamme du produit depuis les périodes de l'ERP — un seul appel,
       celui du catalogue, qui les porte déjà (include=availability_periods).
       Un produit dans plusieurs gammes garde la plus COURTE : « Chandeleur »
       informe le client, « Standard » ne dit rien. */
    if ($r && function_exists('erp_seasons_enabled') && erp_seasons_enabled()) {
      $pub = erp_seasons($lang);
      $periodesPar = [];
      if ($pub) {
        $av = erp_get('shops/' . (int) $s . '/products/available?include=availability_periods', 60);
        $lstAv = is_array($av) ? (array_is_list($av) ? $av : ($av['data'] ?? $av['items'] ?? null)) : null;
        if (is_array($lstAv)) {
          foreach ($lstAv as $pr3) {
            if (is_array($pr3) && !empty($pr3['id']) && !empty($pr3['availability_periods']))
              $periodesPar[(int) $pr3['id']] = $pr3['availability_periods'];
          }
        }
      }
      foreach ($r as &$xs) {
        $g = $pub ? erp_season_principale($periodesPar[(int) $xs['id']] ?? [], $pub) : null;
        $xs['season']      = $g ? $g['id'] : null;
        $xs['season_name'] = $g ? $g['label'] : null;
        /* La photo de la gamme, DE L'ERP et d'elle seule (même règle que la
           barre de gammes). Le repli sur une icône locale a été retiré : il
           faisait cohabiter deux sources pour la même image, donc une vignette
           locale périmée là où l'ERP avait changé la photo.
           Pas de photo côté ERP → null, et le front dessine son emoji. */
        $xs['season_img'] = $g ? $g['img'] : null;
      }
      unset($xs);
    }

    /* PÉRIMÈTRE DE LA PROMO CROISÉE. Quand la règle vient de l'ERP, c'est ELLE
       qui dit quels produits comptent (listes de produits / catégories), pas
       le drapeau local ws_products.cross_portion. On réécrit donc le drapeau
       servi au front avec le MÊME test que la facturation
       (cross_portion_eligible) : sans ça, le panier marquerait des lignes que
       la commande ne remiserait pas — ou l'inverse. */
    if ($r && function_exists('cross_portion_rule')) {
      $cpr = cross_portion_rule($s);
      if ($cpr && ($cpr['source'] ?? '') === 'erp') {
        foreach ($r as &$xc) {
          $xc['cross_portion'] = cross_portion_eligible($cpr, $xc['id'], $xc['cat_id'] ?? 0,
                                                        $xc['sub_cat_id'] ?? 0, $xc['cross_portion']);
        }
        unset($xc);
      } elseif (!$cpr && erp_promos_enabled()) {
        // Source ERP activée mais aucune règle : aucun produit n'est éligible.
        foreach ($r as &$xc) { $xc['cross_portion'] = false; } unset($xc);
      }
    }

    /* Noms traduits : alias servis par l'API ERP (products/aliases), résolus
       ICI — le navigateur ne traduit rien. Sans alias dans cette langue, ou
       API non configurée, le nom SOURCE est conservé : jamais de trou, jamais
       de nom inventé. Un seul appel groupé sert tout le catalogue. */
    $lg = strtolower(substr((string) ($lang ?: ''), 0, 2));
    if ($lg !== '' && function_exists('erp_product_labels')) {
      $al = erp_product_labels($lg);
      if ($al) {
        foreach ($r as &$x2) {
          if (isset($al[(string) $x2['id']])) $x2['name'] = $al[(string) $x2['id']];
        }
        unset($x2);
      }
    }
    return $r;
  }

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
     publique.
     EXCEPTION : /bo/pin-* (connexion tablette par PIN). Ces trois routes sont
     définies plus bas et sont PUBLIQUES par nature — c'est l'écran de connexion
     lui-même. Sans cette exception, elles n'étaient jamais atteintes : le pavé
     PIN recevait « Not found » à chaque saisie, quel que soit le code. ── */
  if (strpos($p, '/bo/') === 0 && strpos($p, '/bo/pin-') !== 0) {
    bo_dispatch($m, $p);
    json_out(['error' => 'Not found', 'path' => $p], 404);
  }

  /* ── Health ── */
  if ($m === 'GET' && $p === '/health') { db()->query('SELECT 1'); json_out(['ok' => true]); }

  /* ── Config front (clés ws_param en liste blanche — jamais la table entière).
     Pilote notamment la visibilité de l'onglet Fidélité (masquable en prod sans
     redéploiement : ws_param.fidelity_tab_enabled = '0') et le délai de demande
     de facture (invoice_request_deadline : 'end_of_month' par défaut, ou un
     nombre de jours). ── */
  if ($m === 'GET' && $p === '/config') {
    json_out([
      // Base URL publique du webshop (ws_param.webshop_base_url). La PWA la lit
      // ICI au lieu de requêter ws_param en direct : source unique, un seul
      // endroit à changer si le domaine bouge. null = non configurée.
      'webshopBaseUrl'         => (($wb = rtrim((string) ws_param('webshop_base_url', ''), '/')) !== '') ? $wb : null,
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
    // La liste des boutiques est la PORTE D'ENTRÉE du webshop : si elle tombe, le
    // client ne voit AUCUNE boutique. Le handler global renverrait « Erreur interne »
    // sans dire pourquoi — on remonte donc ici l'erreur RÉELLE (message, ligne et
    // base connectée), conformément à la règle « soit ça marche avec les vraies
    // données, soit ça renvoie un bug » : jamais de liste inventée en repli.
    try {
      // Langue par boutique (migration 0087) : ajoutée seulement si les colonnes
      // existent — sinon la porte d'entrée /shops casserait sur une base pas
      // encore migrée. NULL = pas paramétré (le front retombe sur le défaut app).
      $langCols = '';
      if (col_exists('shops', 'default_lang')) $langCols .= ', default_lang';
      if (col_exists('shops', 'languages'))    $langCols .= ', languages';
      json_out(rows("SELECT id, slug, name, city, email, phone, accent, tint, logo_url,
                            discount_type AS webshop_discount_type, discount_value AS webshop_discount_value,
                            TRIM(CONCAT_WS(' ', street, street_num)) AS address" . $langCols . "
                       FROM shops WHERE active = 1 AND webshop_enabled = 1 ORDER BY name"));
    } catch (Throwable $e) {
      $base = null;
      try { $base = (row("SELECT DATABASE() d")['d'] ?? null); } catch (Throwable $e2) { $base = 'inconnue'; }
      json_out(['error' => 'liste des boutiques KO',
                'detail' => $e->getMessage(),
                'ligne' => $e->getLine(),
                'base' => $base], 500);
    }
  }
  /* ── Annuaire des boutiques pour la PWA (public). Différent de /shops, qui
     est la PORTE D'ENTRÉE du webshop et ne liste que webshop_enabled = 1 :
     la PWA (fidélité, avis, sélecteur de boutique préférée) a besoin de TOUTES
     les boutiques actives, avec le drapeau webshop et le lien d'avis Google.
     C'est ce que la PWA lisait jusqu'ici en SQL direct sur `shops` ; elle passe
     désormais par cet endpoint — plus aucune table partagée lue en direct.
     Colonnes optionnelles gardées par col_exists : le socle CI ne les porte pas
     toutes, la prod oui. ── */
  if ($m === 'GET' && $p === '/shops/directory') {
    $opt = [];
    foreach (['address_line', 'webshop_url', 'google_review_url', 'sort_order'] as $oc)
      $opt[$oc] = col_exists('shops', $oc);
    $cols = "id, slug, name, city, TRIM(CONCAT_WS(' ', street, street_num)) AS street_address, webshop_enabled";
    foreach ($opt as $oc => $has) if ($has) $cols .= ", $oc";
    $order = ($opt['sort_order'] ? 'sort_order, ' : '') . 'name';
    json_out(array_map(fn ($r) => [
      'id'              => (int) $r['id'],
      'slug'            => $r['slug'] ?? null,
      'name'            => $r['name'],
      'city'            => $r['city'] ?? null,
      // L'adresse « libre » (address_line) prime quand elle existe ; sinon la
      // rue + numéro, comme /shops.
      'address'         => ($opt['address_line'] && !empty($r['address_line'])) ? $r['address_line'] : ($r['street_address'] ?: null),
      'sortOrder'       => $opt['sort_order'] ? (int) ($r['sort_order'] ?? 0) : 0,
      'webshopEnabled'  => (bool) ($r['webshop_enabled'] ?? 0),
      'webshopUrl'      => $opt['webshop_url'] ? ($r['webshop_url'] ?: null) : null,
      'googleReviewUrl' => $opt['google_review_url'] ? ($r['google_review_url'] ?: null) : null,
    ], rows("SELECT $cols FROM shops WHERE active = 1 ORDER BY $order")));
  }

  /* ── « Offre du moment » de la PWA (public) : la diapositive hero marketing
     partagée site ↔ PWA (lp_hero_slides), filtrée sur active_in_pwa et sur son
     calendrier (dates, heures, jours). Logique reprise telle quelle de la PWA
     (repo_promo_featured) pour qu'elle cesse de lire lp_hero_slides en direct.
     Renvoie la première diapositive éligible, ou null — jamais une offre
     inventée. Table absente (hors landing, socle CI) → null aussi. ── */
  if ($m === 'GET' && $p === '/landing/hero') {
    if (!tbl_exists('lp_hero_slides') || !col_exists('lp_hero_slides', 'active_in_pwa')) json_out(null);
    $lang = (qp('locale') === 'nl') ? 'nl' : 'fr';   // lp_hero_slides est fr/nl ; en → fr
    $slides = rows("SELECT position, eyebrow_$lang AS eyebrow, title_$lang AS title, lede_$lang AS lede,
                           cta1_text_$lang AS cta, cta1_url, image_path, pwa_image_path, ws_product_slug,
                           pwa_price_label, pwa_unit_label, pwa_from, pwa_to, pwa_hour_from, pwa_hour_to, pwa_days
                      FROM lp_hero_slides
                     WHERE is_active = 1 AND active_in_pwa = 1
                     ORDER BY position, id");
    $now = time(); $hour = (int) date('G', $now); $dow = (int) date('N', $now);   // 1 = lundi … 7 = dimanche
    foreach ($slides as $r) {
      if (!empty($r['pwa_from']) && $now < strtotime($r['pwa_from'])) continue;
      if (!empty($r['pwa_to'])   && $now > strtotime($r['pwa_to']))   continue;
      if ($r['pwa_hour_from'] !== null && $r['pwa_hour_from'] !== '' && $hour < (int) $r['pwa_hour_from']) continue;
      if ($r['pwa_hour_to']   !== null && $r['pwa_hour_to']   !== '' && $hour > (int) $r['pwa_hour_to'])   continue;
      if (!empty($r['pwa_days'])) {
        $days = array_map('intval', array_filter(explode(',', (string) $r['pwa_days']), 'strlen'));
        if ($days && !in_array($dow, $days, true)) continue;
      }
      json_out([
        'tag'         => $r['eyebrow'],
        'name'        => strip_tags((string) $r['title'], '<br>'),   // retire <span class="script">
        'desc'        => $r['lede'],
        'price'       => $r['pwa_price_label'] ?: null,
        'unit'        => $r['pwa_unit_label'] ?: null,
        'cta'         => $r['cta'] ?: 'Commander →',
        'img'         => $r['image_path'],
        'pwaImage'    => $r['pwa_image_path'] ?: null,
        'productSlug' => $r['ws_product_slug'] ?: null,
        'ctaUrl'      => $r['cta1_url'] ?: null,
      ]);
    }
    json_out(null);
  }
  if ($m === 'GET' && $p === '/brand') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    // Langue par boutique servie ici aussi : un lien profond ?shop=<id> doit
    // ouvrir dans la langue de la boutique (Halle → nl). Colonnes gardées.
    $langCols = '';
    if (col_exists('shops', 'default_lang')) $langCols .= ', default_lang';
    if (col_exists('shops', 'languages'))    $langCols .= ', languages';
    json_out(row("SELECT id, slug, name, accent, tint, logo_url,
                         discount_type AS webshop_discount_type, discount_value AS webshop_discount_value"
                       . $langCols . "
                    FROM shops WHERE id = ? AND webshop_enabled = 1", [$s]) ?: []);
  }

  /* ── Traductions d'interface (source unique : table ws_i18n) ──
   * GET /i18n[?scope=ui] → { scope, strings: { fr:{k:v,…}, nl:{…} } }
   * « Rien en dur » : les libellés ne vivent plus dans le JS, le front les
   * charge ici au boot. Pas de repli inventé — si la table manque ou la requête
   * échoue, on remonte l'erreur réelle (comme /shops), le bandeau la signale. */
  if ($m === 'GET' && $p === '/i18n') {
    $scope = qp('scope') ?: 'ui';
    if (!tbl_exists('ws_i18n')) {
      json_out(['error' => 'table ws_i18n absente',
                'detail' => 'migration 0086 non appliquée sur le serveur'], 500);
    }
    try {
      $rws = rows("SELECT lang, k, value FROM ws_i18n WHERE scope = ? ORDER BY lang, k", [$scope]);
      $out = [];
      foreach ($rws as $r) { $out[$r['lang']][$r['k']] = $r['value']; }
      // `count` : combien de libellés PAR LANGUE. Ouvrir /api/i18n dans un
      // navigateur suffit alors à répondre à « pourquoi le néerlandais ne
      // s'affiche pas ? » — table vide, langue absente, ou front en cause.
      $count = [];
      foreach ($out as $lg => $dict) { $count[$lg] = count($dict); }
      json_out(['scope' => $scope, 'count' => $count, 'strings' => $out]);
    } catch (Throwable $e) {
      json_out(['error' => 'i18n KO', 'detail' => $e->getMessage(), 'ligne' => $e->getLine()], 500);
    }
  }

  /* ── Diagnostic de la liaison ERP (lecture seule, sans secret) ──
   * GET /erp/probe[?lang=nl] → dit si l'API ERP est configurée, joignable, et
   * COMBIEN de libellés traduits elle rend. Sans ça, « les produits ne sont pas
   * traduits » se diagnostique à l'aveugle : on ne sait pas distinguer une
   * adresse absente d'un jeton refusé ou d'une réponse de forme inattendue.
   * Le jeton n'est jamais renvoyé — seulement le fait qu'il soit posé. */
  /* ── ÉTAT DU MIROIR PRODUITS ────────────────────────────────────────────
   * Le catalogue et les prix viennent désormais de l'ERP. Des tables locales
   * en gardent pourtant une copie. Avant d'en supprimer une seule, il faut
   * savoir CE QUI EXISTE VRAIMENT en base : ce matin, une migration a échoué
   * parce que j'avais vérifié les lectures du code sans regarder les
   * contraintes du schéma. Ce diagnostic répond à trois questions par table :
   * existe-t-elle, combien de lignes porte-t-elle, et qui la retient par une
   * clé étrangère.
   *
   * Réservé à l'administrateur : des volumes de tables internes n'ont pas à
   * être publics. */
  if ($m === 'GET' && $p === '/erp/miroir') {
    require_admin();
    $tables = ['ws_products','ws_categories','ws_category_subs','ws_product_stock',
               'ws_product_stock_defaults','ws_product_shops','ws_product_prices',
               'ws_product_allergens','shop_product_portion_price','product_portion',
               'product_availability_period_connection','ws_i18n','ws_shop_availability',
               'ws_product_translations','product','ws_season'];
    $out = [];
    foreach ($tables as $t) {
      if (!tbl_exists($t)) { $out[$t] = ['existe' => false]; continue; }
      $n = null;
      try { $r = row("SELECT COUNT(*) AS n FROM `$t`"); $n = $r ? (int) $r['n'] : null; }
      catch (Throwable $e) { $n = null; }
      /* Qui pointe vers elle : une table retenue par une clé étrangère ne peut
         pas être supprimée sans traiter d'abord la contrainte. */
      $ref = [];
      try {
        foreach (rows("SELECT table_name AS t, constraint_name AS c
                         FROM information_schema.key_column_usage
                        WHERE table_schema = DATABASE() AND referenced_table_name = ?", [$t]) as $k)
          $ref[] = $k['t'] . '.' . $k['c'];
      } catch (Throwable $e) {}
      /* Et ce qu'elle retient elle-même. */
      $sort = [];
      try {
        foreach (rows("SELECT referenced_table_name AS t, constraint_name AS c
                         FROM information_schema.key_column_usage
                        WHERE table_schema = DATABASE() AND table_name = ?
                          AND referenced_table_name IS NOT NULL", [$t]) as $k)
          $sort[] = $k['t'] . '.' . $k['c'];
      } catch (Throwable $e) {}
      $out[$t] = ['existe' => true, 'lignes' => $n,
                  'retenue_par' => $ref ?: null, 'retient' => $sort ?: null];
    }
    json_out(['miroir' => $out, 'catalog_source' => ws_param('catalog_source', '')]);
  }

  if ($m === 'GET' && $p === '/erp/probe') {
    $lg  = strtolower(substr((string) (qp('lang') ?: 'nl'), 0, 2));
    $cfgE = function_exists('erp_cfg') ? erp_cfg() : ['base' => '', 'token' => ''];
    if (!function_exists('erp_enabled') || !erp_enabled()) {
      json_out(['configure' => false,
                'message' => "Adresse ERP absente. Renseignez ws_param.erp_api_base "
                           . "(ex. https://…/api/v1) ; ws_param.erp_api_token si un jeton est exigé.",
                'langue' => $lg]);
    }
    $prod = erp_product_labels($lg);
    $cat  = erp_category_labels($lg);
    json_out([
      'configure'  => true,
      'base'       => $cfgE['base'],
      'jeton_pose' => $cfgE['token'] !== '',
      // 'login-auto' = reconnexion consultant automatique (transitoire) ;
      // 'jeton-statique' = erp_api_token seul — meurt en 30 min si consultant.
      'auth'       => (($cfgE['auth_phone'] ?? '') !== '' && ($cfgE['auth_pass'] ?? '') !== '')
                        ? 'login-auto' : ($cfgE['token'] !== '' ? 'jeton-statique' : 'aucun'),
      'langue'     => $lg,
      'produits_traduits'   => count($prod),
      'categories_traduites' => count($cat),
      'exemples'   => array_slice($prod, 0, 3, true),
      'clients'    => function_exists('erp_clients_etat') ? erp_clients_etat(qp('shopId') ?: 2) : null,
      /* AVANCEMENT DE LA BASCULE (0103) : combien de comptes ont une fiche ERP
         connue, et quelle part des commandes porte déjà sa fiche gelée. Un
         chantier qu'on ne peut pas mesurer est un chantier dont on ignore où
         il en est. */
      'bascule' => function_exists('erp_map_etat') ? erp_map_etat() : null,
      /* Le SMS : configuré ? joignable ? combien de points restants ? Un code
         qui ne part plus faute de crédit doit se voir avant les plaintes. */
      'sms' => function_exists('sms_etat') ? sms_etat() : null,
      /* PRIX — diagnostic d'exécution. Ajouté le 31/08 : le résolveur ERP était
         en place, déployé, et les prix servis restaient les prix locaux ; le
         raisonnement statique ne suffisait plus à dire pourquoi. Ces trois
         valeurs répondent en une requête : la source est-elle l'ERP, la porte
         s'ouvre-t-elle, et que rend le résolveur pour un produit connu. */
      'prix' => (function () {
        $sp = (int) (qp('shopId') ?: 2);
        $pid = (int) (qp('prixPid') ?: 6700092);   // Bouteille Coca-Cola 50cl
        $r = ['shop' => $sp, 'produit' => $pid,
              'catalog_source' => (string) ws_param('catalog_source', ''),
              'erp_enabled' => function_exists('erp_enabled') ? (bool) erp_enabled() : null,
              'catalog_enabled' => function_exists('erp_catalog_enabled') ? (bool) erp_catalog_enabled() : null];
        try { $r['resolu'] = prix_produits([$pid], $sp); } catch (Throwable $e) { $r['resolu'] = 'exception: ' . $e->getMessage(); }
        try { $r['sans_boutique'] = prix_produits([$pid], null); } catch (Throwable $e) { $r['sans_boutique'] = 'exception'; }
        return $r;
      })(),
      'photos'     => (function () {
        $stamp = __DIR__ . '/../assets/product_pictures/.last_refresh';
        return ['auto' => photos_exec_ok(),
                'ttl'  => (int) ws_param('photos_refresh_ttl', 60),
                'dernier_declenchement_s' => is_file($stamp) ? time() - (int) filemtime($stamp) : null];
      })(),
      'incidents'  => erp_notes(),
    ]);
  }

  /* ── SYNCHRO PHOTOS À LA DEMANDE — « j'ai changé la photo, je veux la voir
   * MAINTENANT ». GET /erp/photos-refresh lance le balayage tout de suite,
   * sans attendre la fenêtre de 15 minutes des visites. Garde de 60 s contre
   * le matraquage (le balayage fait ~50 appels ERP) ; le travail part en
   * arrière-plan, la réponse dit quand revenir. ── */
  if ($m === 'GET' && $p === '/erp/photos-refresh') {
    if (!photos_exec_ok())
      json_out(['declenche' => false,
                'motif' => "exec indisponible sur ce PHP — la synchro ne tourne qu'au déploiement"], 503);
    $stamp = __DIR__ . '/../assets/product_pictures/.last_refresh';
    $age = is_file($stamp) ? time() - (int) filemtime($stamp) : PHP_INT_MAX;
    if ($age < 60)
      json_out(['declenche' => false, 'motif' => 'balayage déjà lancé il y a ' . $age . ' s',
                'reessayer_dans_s' => 60 - $age]);
    photos_refresh_spawn();
    json_out(['declenche' => true,
              'info' => 'balayage lancé — comptez ~30 s puis rechargez la page ; état détaillé : /erp/photos-report']);
  }

  /* ── RAPPORT PHOTOS — l'état de CHAQUE produit actif, d'un seul regard. ──
   * GET /erp/photos-report
   * Né d'un vrai besoin : « je ne sais pas vérifier un par un ». Pour chaque
   * produit actif, le rapport dit OÙ il en est et QUOI faire :
   *   photo_erp          — photo en ligne, gérée par la synchro ERP ;
   *   photo_manuelle     — photo en ligne, déposée à la main (jamais écrasée) ;
   *   prete_cote_erp     — photo posée dans Franchise Buddy, PAS ENCORE
   *                        synchronisée : elle arrive au prochain balayage.
   *                        Si elle reste ici d'un rapport à l'autre, la
   *                        synchro a un problème ;
   *   recette_sans_photo — recette liée mais aucune photo dans Franchise
   *                        Buddy : c'est là-bas qu'il faut la poser ;
   *   sans_recette       — pas de recette liée : lier la recette dans
   *                        Franchise Buddy, ou déposer une photo à la main.
   * Le lien produit→recette vient de l'API (available, boutique de référence),
   * comme dans la synchro — le réplica local a déjà menti. Les recettes ne
   * sont interrogées QUE pour les produits sans fichier (cache erp_get). */
  if ($m === 'GET' && $p === '/erp/photos-report') {
    if (!function_exists('erp_enabled') || !erp_enabled())
      json_out(['error' => 'API ERP non configurée (ws_param.erp_api_base)'], 503);
    $prods = rows("SELECT id, name, COALESCE(office_delivery,1) AS od" .
                  (col_exists('ws_products', 'click_and_collect') ? ", COALESCE(click_and_collect,1) AS cc" : ", 1 AS cc") . "
                    FROM ws_products WHERE active = 1 ORDER BY name");
    $fichiers = product_photo_files();
    $manif = [];
    $mf = __DIR__ . '/../assets/product_pictures/.erp_photos.json';
    if (is_file($mf)) { $mj = json_decode((string) @file_get_contents($mf), true); if (is_array($mj)) $manif = $mj; }
    // Lien produit→recette : API d'abord (une requête, cache disque erp_get).
    $refShop = (int) (ws_param('erp_ref_shop', 0) ?: (row("SELECT MIN(id) m FROM shops WHERE webshop_enabled=1")['m'] ?? 0));
    $apiRec = [];
    $av = $refShop > 0 ? erp_get('shops/' . $refShop . '/products/available') : null;
    if (is_array($av)) {
      $lst = array_is_list($av) ? $av : ($av['data'] ?? $av['items'] ?? []);
      foreach ((array) $lst as $pr) if (is_array($pr) && !empty($pr['id']) && !empty($pr['id_recipe'])) $apiRec[(int) $pr['id']] = (int) $pr['id_recipe'];
    }
    $etats = ['photo_erp' => [], 'photo_manuelle' => [], 'prete_cote_erp' => [],
              'recette_sans_photo' => [], 'sans_recette' => []];
    foreach ($prods as $pr) {
      $id = (int) $pr['id'];
      $ligne = ['id' => $id, 'name' => $pr['name'],
                'canaux' => trim(((int) $pr['cc'] ? 'click&collect ' : '') . ((int) $pr['od'] ? 'livraison' : '')) ?: 'aucun'];
      if (isset($fichiers[$id]) || isset($fichiers[(string) $id])) {
        $etats[isset($manif[$id]) || isset($manif[(string) $id]) ? 'photo_erp' : 'photo_manuelle'][] = $ligne;
        continue;
      }
      $rid = $apiRec[$id] ?? 0;
      if ($rid <= 0) { $etats['sans_recette'][] = $ligne; continue; }
      $rec = erp_get('recipes/' . $rid);
      $aPhoto = false;
      if (is_array($rec)) {
        foreach (['shop_photo_path', 'main_photo_path', 'photo_1_path', 'photo_2_path', 'photo_3_path'] as $k) {
          if (!empty($rec[$k])) { $aPhoto = true; break; }
        }
      }
      $ligne['recette'] = $rid;
      $etats[$aPhoto ? 'prete_cote_erp' : 'recette_sans_photo'][] = $ligne;
    }
    json_out([
      'produits_actifs' => count($prods),
      'resume' => array_map('count', $etats),
      'a_faire' => [
        'prete_cote_erp'     => 'rien — la photo arrive au prochain balayage (GET /erp/photos-refresh pour tout de suite). Persistante ? la synchro a un problème.',
        'recette_sans_photo' => 'poser la photo dans Franchise Buddy (shop_photo_path de préférence) — source unique',
        'sans_recette'       => 'lier une recette dans Franchise Buddy — source unique, plus de fichiers manuels',
        'photo_manuelle'     => 'anomalie transitoire : fichier hors ERP, remplacé ou retiré au prochain balayage',
        'sans_prix'          => 'produit créé depuis Franchise Buddy, MASQUÉ tant que son prix n\'est pas posé (console marque) — jamais vendu à 0 €',
      ],
      'etats' => $etats,
      // Produits pilotés par l'ERP mais sans prix local : actifs, INVISIBLES
      // au catalogue par la règle « prix non fixé = pas vendable ».
      'sans_prix' => rows("SELECT id, name FROM ws_products WHERE active = 1 AND price <= 0 ORDER BY name"),
      'incidents' => erp_notes(),
    ]);
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
    /* LA BARRE DÉRIVE DE LA GRILLE, elle ne la redéduit plus.
       Les deux requêtes appliquaient des règles différentes : la barre exigeait
       ws_categories.active = 1 et la bonne boutique, mais IGNORAIT le prix ERP
       et le canal bureau. D'où deux symptômes opposés, tous deux constatés :
         · une catégorie dont aucun produit n'a de prix ERP s'affichait et
           menait à une GRILLE VIDE ;
         · un produit dont la catégorie est désactivée se vendait sans qu'aucune
           catégorie ne permette d'y revenir.
       Le prix ERP étant appliqué en PHP après la requête, aucune clause SQL ne
       pouvait les réconcilier : on part donc des produits SERVIS. Une catégorie
       n'apparaît que si elle contient au moins un produit réellement vendable
       ici, et un produit vendable dont la catégorie manque le SIGNALE. */
    $servis = catalog_produits_servis($s, qp('mode'), qp('date'), qp('lang'));
    $catIds = array_values(array_unique(array_filter(array_map(
      static fn ($x) => $x['cat_id'] !== null ? (int) $x['cat_id'] : null, $servis))));
    $subIds = array_values(array_unique(array_filter(array_map(
      static fn ($x) => $x['sub_cat_id'] !== null ? (int) $x['sub_cat_id'] : null, $servis))));
    if (!$catIds) json_out([]);

    $in  = implode(',', array_map('intval', $catIds));
    /* PLUS DE FILTRE `active` ICI, ET C'EST LE CŒUR DU CORRECTIF.
       J'avais gardé `AND active = 1` en écrivant qu'« une catégorie que la
       marque a retirée ne réapparaît pas dans la navigation ». C'était faux sur
       le fond : ws_categories.active n'est PAS une décision, c'est un CACHE
       CALCULÉ. Une seule requête l'écrit, dans /franchisor/product et
       /franchisor/category :

           SET active = EXISTS(SELECT 1 FROM ws_products p2
                                WHERE p2.cat_id = ws_categories.id AND p2.active = 1)

       « au moins un produit actif » — et rien d'autre ne le rafraîchit. Un
       produit activé par un autre chemin (import ERP, migration, écriture
       directe) laisse le cache tel quel. CONSTATÉ EN PRODUCTION : Biscuiterie
       porte 6 produits actifs et son cache vaut 0. La console marque affiche sa
       bascule « webshop » sur ACTIF — elle lit les produits, donc la vérité —
       pendant que la barre du webshop était vide. Le client voyait les cookies
       en vente sans aucune catégorie pour y revenir.

       Ce filtre n'apportait donc rien de juste : il ne protégeait aucune
       intention, il propageait un cache périmé. Et il était REDONDANT — cette
       liste dérive déjà des produits SERVIS : une catégorie n'y entre que si
       elle contient un produit réellement vendable ici. Retirer ses produits la
       fait disparaître d'elle-même, calculé sur l'instant plutôt que mis en
       cache. Même raisonnement pour les sous-catégories : `sub.active` gardait
       la sous-catégorie « Cookies » hors du menu alors que ses produits se
       vendaient.

       RÈGLE, désormais tenue des deux côtés : ce qui est vendu est joignable. */
    /* PLUS DE FILTRE PAR BOUTIQUE NON PLUS. Il restait ici, dernier vestige du
       modèle où une catégorie pouvait appartenir à une boutique. Le test de
       visibilité l'a attrapé net : les produits d'une catégorie rattachée sont
       désormais VENDUS (tout est commun) pendant que leur catégorie restait
       hors de la barre — vendus et introuvables, l'écart qu'on passe la journée
       à supprimer. La barre dérive des produits servis, un point. */
    $cats = rows("SELECT id, slug, label, img, sort_order FROM ws_categories
                   WHERE id IN ($in)
                   ORDER BY sort_order, label");
    $subs = [];
    if ($subIds) {
      $inS = implode(',', array_map('intval', $subIds));
      $subs = rows("SELECT sub.id, sub.category_id, sub.slug, sub.label, sub.img, sub.sort_order
                      FROM ws_category_subs sub
                     WHERE sub.id IN ($inS)
                     ORDER BY sub.sort_order, sub.label");
    }
    $byCat = [];
    foreach ($subs as $x) { $byCat[$x['category_id']][] = $x; }
    foreach ($cats as &$c) { $c['subs'] = $byCat[$c['id']] ?? []; }
    unset($c);

    /* Libellés traduits : alias servis par l'API ERP (product-categories/aliases
       et product-category-groups/aliases), résolus ICI — le navigateur ne
       décide de rien. Sans alias pour la langue, ou API non configurée, le
       libellé SOURCE est conservé : jamais de trou, jamais de nom inventé. */
    $lang = strtolower(substr((string) (qp('lang') ?: ''), 0, 2));
    if ($lang !== '' && function_exists('erp_category_labels')) {
      $al = erp_category_labels($lang);
      if ($al) {
        foreach ($cats as &$c2) {
          if (isset($al[(string) $c2['id']])) $c2['label'] = $al[(string) $c2['id']];
          foreach ($c2['subs'] as &$sb) {
            if (isset($al[(string) $sb['id']])) $sb['label'] = $al[(string) $sb['id']];
          }
          unset($sb);
        }
        unset($c2);
      }
    }
    json_out($cats);
  }
  /* ASSORTIMENT PAR BUREAU (migration 0113). Un bureau (personne morale) peut
     ne recevoir qu'une partie du catalogue (ws_office_products) et commander
     sans voir les prix (show_prices=0). Le filtre est une RÉDUCTION du
     catalogue déjà servi (canal, saison, stock, prix d'abord) ; il ne joue
     que pour un client rattaché (client.office_id) ou un officeId explicite,
     et la commande le revérifie : le panier n'est jamais cru sur parole. */
  function office_assortiment($officeId) {
    static $cache = [];
    $officeId = (int) $officeId;
    if ($officeId <= 0) return null;
    if (array_key_exists($officeId, $cache)) return $cache[$officeId];
    if (!col_exists('ws_offices', 'assortment_mode')) return $cache[$officeId] = null;
    $o = row("SELECT id, name, assortment_mode, show_prices, deferred_billing_enabled, shop_id
                FROM ws_offices WHERE id=? AND active=1", [$officeId]);
    if (!$o) return $cache[$officeId] = null;
    $ids = [];
    if ($o['assortment_mode'] === 'custom' && tbl_exists('ws_office_products'))
      foreach (rows("SELECT product_id FROM ws_office_products WHERE office_id=?", [$officeId]) as $r) $ids[(int) $r['product_id']] = true;
    return $cache[$officeId] = ['id' => (int) $o['id'], 'name' => (string) $o['name'], 'mode' => (string) $o['assortment_mode'],
      'show' => (bool) $o['show_prices'], 'deferred' => (bool) $o['deferred_billing_enabled'],
      'shopId' => $o['shop_id'] !== null ? (int) $o['shop_id'] : null, 'ids' => $ids];
  }
  function office_du_client($cid) {
    $cid = (int) $cid;
    if ($cid <= 0 || !col_exists('client', 'office_id')) return null;
    $c = row("SELECT office_id FROM client WHERE id=?", [$cid]);
    return ($c && $c['office_id']) ? (int) $c['office_id'] : null;
  }
  function office_filtrer(array $liste, $office) {
    if (!$office || $office['mode'] !== 'custom') return $liste;
    return array_values(array_filter($liste, static fn ($x) => isset($office['ids'][(int) $x['id']])));
  }
  function office_contexte($office) {
    if (!$office) return null;
    return ['id' => $office['id'], 'name' => $office['name'], 'assortment' => $office['mode'],
            'productCount' => $office['mode'] === 'custom' ? count($office['ids']) : null,
            'showPrices' => $office['show'], 'deferredBilling' => $office['deferred']];
  }

  if ($m === 'GET' && $p === '/catalog/products') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    photos_refresh_async();   // relance des photos ERP au fil des visites (throttlée, en arrière-plan)
    $liste = catalog_produits_servis($s, qp('mode'), qp('date'), qp('lang'));
    // Bureau : l'officeId reçu (réduction seulement, donc sans risque) ou le
    // rattachement du client connecté, quand le jeton accompagne l'appel.
    $oidCat = (int) qp('officeId') ?: office_du_client(auth_uid());
    json_out(office_filtrer($liste, office_assortiment($oidCat)));
  }
  /* ── VENTES CROISÉES — évaluation côté panier (migration 0056). ───────────
     Le navigateur envoie ce qu'il a (panier, boutique, date et heure de
     RETRAIT, canal, emplacement) ; le serveur rend les produits à proposer,
     DÉJÀ filtrés. Le filtrage n'est pas laissé au front : suggérer un produit
     hors assortiment, hors saison ou en rupture est pire que ne rien suggérer
     — on ferait cliquer le client sur ce qu'on ne peut pas lui vendre.

     L'heure comparée est celle du CRÉNEAU DE RETRAIT : commander à 22:00 pour
     le lendemain midi doit montrer les suggestions du midi. ── */
  if ($m === 'POST' && $p === '/catalog/cross-sell') {
    if (!xsell_tbl('ws_cross_sell_rule')) json_out([]);
    $b     = body();
    $shopX = (int) ($b['shopId'] ?? 0);
    if (!$shopX) json_out(['error' => 'shopId requis'], 400);
    $inCart = array_values(array_unique(array_filter(array_map('intval', (array) ($b['productIds'] ?? [])))));
    if (!$inCart) json_out([]);
    $dateX = (is_string($b['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $b['date'])) ? $b['date'] : date('Y-m-d');
    $timeX = (is_string($b['time'] ?? null) && preg_match('/^([01]\d|2[0-3]):[0-5]\d/', (string) $b['time']))
             ? substr((string) $b['time'], 0, 5) : null;
    $modeX  = (($b['mode'] ?? '') === 'delivery') ? 'delivery' : 'collect';
    $placeX = preg_replace('/[^a-z]/', '', strtolower((string) ($b['placement'] ?? 'cart'))) ?: 'cart';
    $dowX   = (int) date('N', strtotime($dateX));

    // Règles candidates : actives, dans la fenêtre, le bon jour, le bon canal,
    // le bon emplacement, la bonne boutique, et non suspendues par la boutique.
    $rules = rows(
      "SELECT r.* FROM ws_cross_sell_rule r
        WHERE r.active = 1
          AND (r.date_from IS NULL OR r.date_from <= ?)
          AND (r.date_to   IS NULL OR r.date_to   >= ?)
          AND (r.channel = 'both' OR r.channel = ?)
          AND (r.weekdays IS NULL OR r.weekdays = '' OR FIND_IN_SET(?, r.weekdays))
          AND FIND_IN_SET(?, REPLACE(r.placement, ' ', ''))
          AND (NOT EXISTS (SELECT 1 FROM ws_cross_sell_shop cs WHERE cs.rule_id = r.id)
               OR EXISTS (SELECT 1 FROM ws_cross_sell_shop cs WHERE cs.rule_id = r.id AND cs.shop_id = ?))
          AND NOT EXISTS (SELECT 1 FROM ws_cross_sell_pause cp WHERE cp.rule_id = r.id AND cp.shop_id = ?)
        ORDER BY r.id",
      [$dateX, $dateX, $modeX, $dowX, $placeX, $shopX, $shopX]);
    if (!$rules) json_out([]);

    // Catégories et SOUS-catégories des produits du panier. Depuis le 23/08
    // l'écran marque ajoute des déclencheurs au grain sous-catégorie
    // (« Tartes », pas toute la Pâtisserie) ; les règles anciennes par
    // catégorie entière continuent de se déclencher à l'identique.
    $ph   = implode(',', array_fill(0, count($inCart), '?'));
    $cats = array_map(fn ($x) => (int) $x['cat_id'],
                      rows("SELECT DISTINCT cat_id FROM ws_products WHERE id IN ($ph)", $inCart));
    $hasSubT = col_exists('ws_cross_sell_trigger', 'sub_id');
    $subsC = $hasSubT ? array_map(fn ($x) => (int) $x['sub_cat_id'],
                      rows("SELECT DISTINCT sub_cat_id FROM ws_products WHERE id IN ($ph) AND sub_cat_id IS NOT NULL", $inCart)) : [];
    $out = []; $seen = [];
    foreach ($rules as $rl) {
      $trig = rows("SELECT product_id, cat_id" . ($hasSubT ? ", sub_id" : "") . " FROM ws_cross_sell_trigger WHERE rule_id = ?", [(int) $rl['id']]);
      if (!$trig) continue;
      $hit = 0;
      foreach ($trig as $t) {
        if (($t['product_id'] !== null && in_array((int) $t['product_id'], $inCart, true))
         || ($t['cat_id']     !== null && in_array((int) $t['cat_id'],     $cats,   true))
         || ($hasSubT && ($t['sub_id'] ?? null) !== null && in_array((int) $t['sub_id'], $subsC, true))) $hit++;
      }
      // « au moins un » ou « tous », selon la règle.
      if ($rl['match_mode'] === 'all' ? ($hit < count($trig)) : ($hit === 0)) continue;

      // Plage horaire — sur l'heure de RETRAIT. Sans créneau connu on ne devine
      // pas : la contrainte horaire ne s'applique simplement pas.
      if ($timeX !== null && $rl['hour_from'] && $rl['hour_to']) {
        $hf = substr((string) $rl['hour_from'], 0, 5); $ht = substr((string) $rl['hour_to'], 0, 5);
        $inRange = ($hf <= $ht) ? ($timeX >= $hf && $timeX <= $ht) : ($timeX >= $hf || $timeX <= $ht);
        if (!$inRange) continue;
      }

      $kept = 0; $max = max(1, (int) $rl['max_suggestions']);
      foreach (rows("SELECT product_id FROM ws_cross_sell_target WHERE rule_id = ? ORDER BY sort_order, id",
                    [(int) $rl['id']]) as $tg) {
        if ($kept >= $max) break;
        $tp = (int) $tg['product_id'];
        // Jamais suggérer ce qui est déjà au panier, ni deux fois le même
        // produit quand plusieurs règles se déclenchent.
        if (in_array($tp, $inCart, true) || isset($seen[$tp])) continue;
        $prod = row("SELECT p.id, p.name, p.img, p.price, COALESCE(p.office_delivery,1) AS od
                       FROM ws_products p
                       LEFT JOIN ws_product_shops ps ON ps.product_id = p.id AND ps.shop_id = ?
                      WHERE p.id = ? AND p.active = 1 LIMIT 1",
                    [$shopX, $tp]);
        if (!$prod) continue;
        if ($modeX === 'delivery' && !(int) $prod['od']) continue;
        if (!product_available_on($tp, $dateX, $shopX)) continue;   // gamme saisonnière
        if (!xsell_in_stock($tp, $shopX, $dateX, $modeX)) continue;
        $px    = prix_produits([$tp], $shopX ?: null);
        $price = $px[$tp] ?? (float) $prod['price'];
        if ($price <= 0) continue;                                // sans prix magasin : non vendable
        $seen[$tp] = true; $kept++;
        $out[] = ['ruleId' => (int) $rl['id'], 'ruleName' => $rl['name'],
                  'productId' => $tp, 'name' => $prod['name'],
                  'img' => product_img_or_null($prod['img'] ?? null), 'price' => round($price, 2)];
      }
    }
    json_out($out);
  }

  /* Mesure : combien de fois proposé, combien de fois ajouté. Agrégat
     JOURNALIER (règle × produit × boutique) — on veut savoir quelle règle
     rapporte, pas rejouer le parcours de chaque client. */
  if ($m === 'POST' && $p === '/catalog/cross-sell/stat') {
    if (!xsell_tbl('ws_cross_sell_stat')) json_out(['ok' => true, 'skipped' => 'table absente']);
    $b   = body();
    $ev  = (($b['event'] ?? '') === 'add') ? 'adds' : 'impressions';
    $rid = (int) ($b['ruleId'] ?? 0); $pid5 = (int) ($b['productId'] ?? 0); $sid5 = (int) ($b['shopId'] ?? 0);
    if (!$rid || !$pid5 || !$sid5) json_out(['ok' => false, 'error' => 'ruleId + productId + shopId requis'], 400);
    q("INSERT INTO ws_cross_sell_stat (rule_id, product_id, shop_id, stat_date, impressions, adds)
         VALUES (?,?,?,CURDATE(),?,?)
         ON DUPLICATE KEY UPDATE impressions = impressions + VALUES(impressions), adds = adds + VALUES(adds)",
      [$rid, $pid5, $sid5, $ev === 'impressions' ? 1 : 0, $ev === 'adds' ? 1 : 0]);
    json_out(['ok' => true]);
  }

  // ── DIAGNOSTIC DE VISIBILITÉ (lecture seule, public comme le catalogue) :
  //    /catalog/why?shopId=2&product=Brownies  (ou &productId=1150004)
  //    Évalue CHAQUE maillon de la chaîne pour le(s) produit(s) trouvé(s) et
  //    dit pourquoi il est visible ou non au webshop — fini les devinettes.
  if ($m === 'GET' && $p === '/catalog/why') {
    $s = (int) (qp('shopId') ?: 0); $q = trim((string) (qp('product') ?: '')); $pid = (int) (qp('productId') ?: 0);
    if (!$s || (!$q && !$pid)) json_out(['erreur' => 'shopId + product (nom) ou productId requis'], 400);
    try {
      $prods = $pid ? rows("SELECT * FROM ws_products WHERE id=?", [$pid])
                    : rows("SELECT * FROM ws_products WHERE name LIKE ? ORDER BY active DESC, id LIMIT 10", ['%' . $q . '%']);
      if (!$prods) json_out(['erreur' => 'aucun produit ne correspond', 'recherche' => $q ?: $pid]);
      $out = [];
      foreach ($prods as $p2) {
        $id = (int) $p2['id'];
        $chk = ['produit' => $p2['name'] . ' (id ' . $id . ')'];
        $chk['1_produit_actif'] = ((int) $p2['active'] === 1) ? 'OK'
          : 'ECHEC — ws_products.active=0 : invisible PARTOUT (console franchisor → toggle Webshop)';
        $cat = $p2['cat_id'] !== null ? row("SELECT id, label, active FROM ws_categories WHERE id=?", [(int) $p2['cat_id']]) : null;
        $chk['2_categorie'] = $cat
          ? ($cat['label'] . ' (id ' . $cat['id'] . ') — ' . (((int) $cat['active'] === 1) ? 'active OK'
             : 'ECHEC — catégorie INACTIVE : pas d\'onglet au webshop pour ce produit'))
          : 'ECHEC — cat_id ' . $p2['cat_id'] . ' introuvable dans ws_categories';
        // Sous-catégorie : le front filtre par p.sub_cat_id quand une puce
        // (?sub=…) est sélectionnée — un produit sans rattachement disparaît
        // de cette vue même s'il est visible dans l'onglet catégorie.
        $sub2 = $p2['sub_cat_id'] !== null
          ? row("SELECT id, label, active, category_id FROM ws_category_subs WHERE id=?", [(int) $p2['sub_cat_id']]) : null;
        $chk['2b_sous_categorie'] = $p2['sub_cat_id'] === null
          ? 'AUCUNE — visible dans l\'onglet catégorie et « Tous », mais PAS quand une puce de sous-catégorie est sélectionnée (rattacher via ws_products.sub_cat_id)'
          : ($sub2
             ? ($sub2['label'] . ' (id ' . $sub2['id'] . ') — ' . (((int) $sub2['active'] === 1) ? 'active OK'
                : 'ECHEC — sous-catégorie INACTIVE : puce absente de la nav')
                . (((int) $sub2['category_id'] === (int) $p2['cat_id']) ? ''
                   : ' — ATTENTION : cette sous-catégorie appartient à la catégorie ' . $sub2['category_id']
                     . ' alors que le produit est en catégorie ' . $p2['cat_id'] . ' (incohérence : jamais affiché sous la puce)'))
             : 'ECHEC — sub_cat_id ' . $p2['sub_cat_id'] . ' introuvable dans ws_category_subs');
        $twins = rows("SELECT id, active FROM ws_products WHERE TRIM(name)=TRIM(?) AND id<>?", [$p2['name'], $id]);
        $chk['3_doublons_de_nom'] = $twins
          ? ('ATTENTION — ' . count($twins) . ' jumeau(x) du même nom : ' .
             implode(', ', array_map(fn ($t2) => '#' . $t2['id'] . ((int) $t2['active'] === 1 ? ' (actif)' : ' (inactif)'), $twins)) .
             ' — vérifiez que vous regardez le bon id')
          : 'OK — nom unique';
        $ps2 = row("SELECT active, no_delivery FROM ws_product_shops WHERE product_id=? AND shop_id=?", [$id, $s]);
        $chk['4_assortiment_boutique'] = $ps2 === null ? 'OK — pas de surcharge boutique (inclus par défaut)'
          : (((int) $ps2['active'] === 1) ? 'OK — activé pour la boutique ' . $s
             : 'ECHEC — RETIRÉ de l\'assortiment de la boutique ' . $s . ' (BO franchisé → Assortiment)');
        // Une seule source, comme le catalogue et la facturation. Ce
        // diagnostic empilait deux replis — ws_product_prices puis
        // ws_products.price — et pouvait donc annoncer un prix que le
        // catalogue n'aurait pas servi.
        $erpx = prix_produits([$id], $s ?: null);
        $final = $erpx[$id] ?? 0.0;
        $chk['5_prix_final'] = ($final > 0)
          ? ('OK — ' . number_format($final, 2, ',', ' ') . ' € (ws_products.price)')
          : 'ECHEC — prix non fixé (ws_products.price <= 0) : masqué du catalogue et refusé à la commande';
        $od = (int) ($p2['office_delivery'] ?? 1);
        $chk['6_canal_livraison'] = $od === 1 ? 'OK — visible aussi en mode livraison bureau'
          : 'ATTENTION — office_delivery=0 : masqué en mode LIVRAISON, visible en Click & Collect seulement';
        $popts3 = erp_portion_options($s, [$id]);
        $cand3 = $popts3[$id] ?? [];
        $chk['7_portions_ERP'] = $cand3
          ? implode(' · ', array_map(fn ($c3) => $c3['label'] . ($c3['price'] !== null
              ? (' ' . number_format($c3['price'], 2, ',', ' ') . ' €') : ' (SANS PRIX — non proposée)'), $cand3))
          : ((int) $p2['portions'] === 1 ? 'flag ws portions actif (3 tailles × facteurs)' : 'aucune — produit non portionnable');
        $visible = ((int) $p2['active'] === 1) && ($ps2 === null || (int) $ps2['active'] === 1) && $final > 0;
        $catOk = $cat && (int) $cat['active'] === 1;
        $chk['VERDICT_click_collect'] = ($visible && $catOk) ? 'VISIBLE au webshop (Click & Collect)'
          : ($visible ? 'DANS le catalogue mais SANS ONGLET (catégorie inactive)' : 'INVISIBLE — corriger les ECHEC ci-dessus');
        $chk['VERDICT_livraison'] = ($visible && $catOk && $od === 1) ? 'VISIBLE en mode livraison bureau' : 'INVISIBLE en mode livraison';
        $out[] = $chk;
      }
      json_out($out);
    } catch (Throwable $e) { json_out(['erreur' => 'diagnostic KO', 'detail' => $e->getMessage()], 500); }
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
    $srcPid = bundle_source_pid($pid);
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
        $chPid = col_exists('ws_bundle_slot_choices', 'product_id') ? ', product_id' : ', NULL AS product_id';
        $sl['choices'] = rows("SELECT id, label, img, delta, sort_order$chPid
                                 FROM ws_bundle_slot_choices WHERE slot_id = ? AND active = 1
                                ORDER BY sort_order, id", [$sl['id']]);
        foreach ($sl['choices'] as &$ch) {
          $ch['delta'] = (float) $ch['delta'];
          // Vignette du choix = TOUJOURS l'image de la catégorie du produit
          // correspondant (ws_categories.img) — jamais une image produit ni le
          // repère de couleur du builder ('a'..'d'). Aucune correspondance /
          // catégorie sans image -> null (le front affiche le line-art).
          // Le choix PORTE son produit (0057) : on part de l'identifiant, et le
          // nom ne sert plus qu'en repli. Un choix au libellé générique
          // (« Dessert au choix ») n'avait sinon jamais de vignette.
          $cat = !empty($ch['product_id'])
            ? row("SELECT c.img FROM ws_products p
                     JOIN ws_categories c ON c.id = p.cat_id
                    WHERE p.id = ? AND c.img IS NOT NULL AND c.img <> ''
                    LIMIT 1", [(int) $ch['product_id']])
            : null;
          if (!$cat) $cat = row("SELECT c.img FROM ws_products p
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
    // Une formule SANS contenu configurable (aucune étape, ou aucune étape
    // ayant au moins un choix actif) n'est pas une formule : c'est un brouillon
    // du constructeur resté publié. Live : 2 formules « Nouvelle formule » vides
    // et ACTIVES sur un produit, dont une à +10,00 € — le client la choisissait,
    // n'avait rien à configurer, et payait le supplément pour rien.
    // On ne les sert plus (et on les trace pour que la marque les corrige).
    $vides = [];
    $bundles = array_values(array_filter($bundles, static function ($b2) use (&$vides) {
      $utile = false;
      foreach (($b2['slots'] ?? []) as $sl2) { if (!empty($sl2['choices'])) { $utile = true; break; } }
      if (!$utile) $vides[] = $b2['id'] . ' « ' . $b2['name'] . ' »';
      return $utile;
    }));
    if ($vides) {
      error_log('[ws] formules vides NON proposées (brouillons actifs à corriger dans le constructeur) : '
        . implode(', ', $vides));
    }
    json_out($bundles);
  }
  if ($m === 'GET' && $p === '/catalog/stock') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    $day = qp('date') ?: date('Y-m-d'); $mode = qp('mode') ?: 'collect';
    // CONTRAT StockEntry du front (webshop-catalog-api.jsx) :
    // { productId, qty_total, qty_reserved, qty_sold, qty_available } — en
    // ENTIERS. L'ancienne forme (product_id/available, chaînes) ne matchait
    // jamais le lookup du front : le « Stock épuisé » ne bloquait RIEN.
    $rs = rows("SELECT product_id, qty_total, qty_reserved, qty_sold,
                       GREATEST(0, qty_total - qty_reserved - qty_sold) AS qty_available
                  FROM ws_product_stock
                 WHERE shop_id = ? AND date = ? AND active = 1 AND (mode = ? OR mode IS NULL)",
                [$s, $day, $mode]);
    json_out(array_map(fn ($r) => [
      'productId'     => (int) $r['product_id'],
      'qty_total'     => (int) $r['qty_total'],
      'qty_reserved'  => (int) $r['qty_reserved'],
      'qty_sold'      => (int) $r['qty_sold'],
      'qty_available' => (int) $r['qty_available'],
    ], $rs));
  }
  /* ── RÉSERVATION DE STOCK (maintien) ──────────────────────────────────────
     Le webshop appelle ces deux routes depuis toujours (ajout / retrait au
     panier) ; elles n'existaient PAS : chaque appel partait en 404 et l'échec
     était avalé côté client. Résultat : qty_reserved restait à 0 et deux clients
     pouvaient mettre la même dernière pièce au panier, l'arbitrage n'ayant lieu
     qu'au passage de commande (« Stock insuffisant » après saisie du paiement).
     qty_reserved est un REFLET recalculé depuis ws_stock_reservation : pas de
     compteur incrémenté à l'aveugle qui dérive au premier échec. ── */
  if ($m === 'POST' && ($p === '/catalog/stock/reserve' || $p === '/catalog/stock/release')) {
    if (!row("SELECT 1 AS x FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'ws_stock_reservation' LIMIT 1"))
      json_out(['ok' => false, 'error' => 'Table ws_stock_reservation absente — réservation impossible (migration 0049).'], 501);
    $b = body();
    /* Le client est celui de la SESSION, jamais celui annoncé par le corps de
       la requête. Le repli sur $b['customerId'] laissait n'importe qui réserver
       — ou libérer — du stock au nom d'un autre client, en envoyant simplement
       son identifiant : de quoi geler la disponibilité d'un produit, ou vider
       les réservations d'un panier en cours, sans aucune authentification.
       L'identité vient de l'en-tête Authorization, seule chose que l'appelant
       ne peut pas forger. */
    $cid = auth_uid();
    if (!$cid) json_out(['ok' => false, 'error' => 'Réservation réservée aux clients connectés.'], 401);

    // Purge paresseuse des réservations expirées, puis recalcul de qty_reserved
    // pour les seuls créneaux touchés (jamais un UPDATE global : la valeur des
    // autres créneaux ne nous appartient pas).
    $recount = function (array $slots) {
      $seen = [];
      foreach ($slots as $s) {
        $k = $s['product_id'] . '|' . $s['shop_id'] . '|' . $s['date'] . '|' . $s['mode'];
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $sum = (int) (row("SELECT COALESCE(SUM(qty),0) AS q FROM ws_stock_reservation
                            WHERE product_id=? AND shop_id=? AND date=? AND mode=?
                              AND released_at IS NULL AND expires_at > NOW()",
                          [$s['product_id'], $s['shop_id'], $s['date'], $s['mode']])['q'] ?? 0);
        q("UPDATE ws_product_stock SET qty_reserved=?
            WHERE product_id=? AND shop_id=? AND date=? AND (mode=? OR mode IS NULL)",
          [$sum, $s['product_id'], $s['shop_id'], $s['date'], $s['mode']]);
      }
    };
    $expired = rows("SELECT DISTINCT product_id, shop_id, date, mode FROM ws_stock_reservation
                      WHERE released_at IS NULL AND expires_at <= NOW()");
    if ($expired) {
      q("UPDATE ws_stock_reservation SET released_at=NOW()
          WHERE released_at IS NULL AND expires_at <= NOW()");
      $recount($expired);
    }

    if ($p === '/catalog/stock/release') {
      // Libération du panier (tout le client) ou d'un seul produit — le retrait
      // d'UNE ligne ne doit pas relâcher le reste du panier.
      $pid = isset($b['productId']) ? (int) $b['productId'] : 0;
      $ids = (isset($b['reservationIds']) && is_array($b['reservationIds']))
             ? array_values(array_filter(array_map('intval', $b['reservationIds']))) : [];
      $w = "customer_id=? AND released_at IS NULL"; $a = [$cid];
      if ($ids) { $w .= " AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")"; $a = array_merge($a, $ids); }
      elseif ($pid) { $w .= " AND product_id=?"; $a[] = $pid; }
      $touched = rows("SELECT DISTINCT product_id, shop_id, date, mode FROM ws_stock_reservation WHERE $w", $a);
      if ($touched) { q("UPDATE ws_stock_reservation SET released_at=NOW() WHERE $w", $a); $recount($touched); }
      json_out(['ok' => true, 'released' => count($touched)]);
    }

    // ── Réservation ──
    $pid  = (int) ($b['productId'] ?? 0);
    $shop = (int) ($b['shopId'] ?? 0);
    $day  = trim((string) ($b['date'] ?? ''));
    $mode = (($b['mode'] ?? 'collect') === 'delivery') ? 'delivery' : 'collect';
    $qty  = max(1, (int) ($b['qty'] ?? 1));
    if (!$pid || !$shop) json_out(['ok' => false, 'error' => 'productId et shopId requis.'], 400);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) json_out(['ok' => false, 'error' => 'Date de retrait/livraison requise (AAAA-MM-JJ).'], 400);
    $slot = ['product_id' => $pid, 'shop_id' => $shop, 'date' => $day, 'mode' => $mode];

    // Disponible = total − vendu − réservations des AUTRES clients. Sans le
    // « autres », le client se bloquerait lui-même en ajustant son panier.
    $st = row("SELECT qty_total, qty_sold FROM ws_product_stock
                WHERE product_id=? AND shop_id=? AND date=? AND (mode=? OR mode IS NULL) LIMIT 1",
              [$pid, $shop, $day, $mode]);
    if ($st === null) {
      // Aucune ligne de stock du jour : rien à tenir. Le plafond hebdomadaire
      // (ws_product_stock_defaults) reste appliqué au passage de commande — on
      // ne fabrique pas de ligne de stock ici, une réservation ne doit pas
      // créer d'inventaire. La RAISON est renvoyée : côté client, une réponse
      // « ok » sans réservation était indiscernable d'un échec silencieux.
      json_out(['ok' => true, 'held' => 0,
                'reason' => 'aucun stock du jour déclaré pour ce produit (produit ' . $pid
                            . ', boutique ' . $shop . ', ' . $day . ', ' . $mode . ') — rien à tenir']);
    }
    $others = (int) (row("SELECT COALESCE(SUM(qty),0) AS q FROM ws_stock_reservation
                           WHERE product_id=? AND shop_id=? AND date=? AND mode=?
                             AND released_at IS NULL AND expires_at > NOW()
                             AND (customer_id IS NULL OR customer_id <> ?)",
                         [$pid, $shop, $day, $mode, $cid])['q'] ?? 0);
    $mine = (int) (row("SELECT COALESCE(SUM(qty),0) AS q FROM ws_stock_reservation
                         WHERE product_id=? AND shop_id=? AND date=? AND mode=? AND customer_id=?
                           AND released_at IS NULL AND expires_at > NOW()",
                       [$pid, $shop, $day, $mode, $cid])['q'] ?? 0);
    $avail = max(0, (int) $st['qty_total'] - (int) $st['qty_sold'] - $others);
    if ($qty + $mine > $avail)
      json_out(['ok' => false, 'error' => 'Stock insuffisant', 'available' => max(0, $avail - $mine)], 409);

    $min = (int) ws_param('stock.reservation_minutes', 15);
    if ($min < 1) $min = 15;
    q("INSERT INTO ws_stock_reservation (product_id, shop_id, date, mode, qty, customer_id, expires_at)
       VALUES (?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? MINUTE))",
      [$pid, $shop, $day, $mode, $qty, $cid, $min]);
    $rid = (int) db()->lastInsertId();
    $recount([$slot]);
    json_out(['ok' => true, 'reservationId' => $rid, 'qty' => $qty, 'holdMinutes' => $min]);
  }
  if ($m === 'GET' && $p === '/catalog/assortments') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    // Saisons = périodes de l'ERP (source unique). ws_season et ws_assortments
    // ont été supprimées. chip.id = slug -> matché à product.season côté front,
    // donc le filtre saison fonctionne. On n'expose qu'une saison ayant
    // >=1 produit disponible dans la boutique (même règle que les catégories).
    /* Le JOIN sur ws_product_shops EXIGEAIT une ligne : une saison n'apparaissait
       que pour les produits explicitement inscrits à l'assortiment d'une
       boutique. Or la grille traite l'ABSENCE de ligne comme « vendu » — la
       plupart des produits n'en ont aucune. Les deux requêtes décrivaient la
       même notion et n'en tiraient pas la même conclusion : des produits
       saisonniers en vente, dont la saison ne s'affichait pas dans le filtre.
       Le modèle tranche : tous les produits sont communs, l'assortiment par
       boutique n'existe plus. */
    /* GAMMES DE L'ERP quand la source est basculée : nom traduit, description,
       période — et surtout webshop_active, qui dit ce qui est PUBLIÉ (une
       gamme peut tourner en magasin sans être une vitrine en ligne).
       La PHOTO vient de l'ERP depuis le 26/08 (sync_season_photos.php).
       Le report d'icône locale par slug a donc été retiré : deux sources pour
       la même image, c'est la garantie qu'un jour l'une contredit l'autre —
       une gamme dont la photo change côté ERP aurait gardé le vieux dessin
       local, sans que rien ne le signale. L'ERP décide, point. */
    if (function_exists('erp_seasons_enabled') && erp_seasons_enabled()) {
      $g = erp_seasons(qp('lang'));
      /* MÊME RÈGLE QUE LES CATÉGORIES, et elle vaut aussi pour l'ERP : une
         gamme n'est une vignette que si au moins un produit de la boutique la
         porte. Sans ce filtre, publier dix gammes affichait dix vignettes dont
         neuf ouvraient une grille VIDE — au 26/08, seule « Printanière »
         portait des produits en vente, les neuf autres étaient hors saison.
         L'appel produits est le MÊME que celui du catalogue (même chemin,
         même TTL) : il est déjà en cache quand la page charge sa grille. */
      if ($g) {
        $porte = [];
        $av = function_exists('erp_get')
            ? erp_get('shops/' . (int) $s . '/products/available?include=availability_periods', 60) : null;
        $lstAv = is_array($av) ? (array_is_list($av) ? $av : ($av['data'] ?? $av['items'] ?? null)) : null;
        if (is_array($lstAv)) {
          foreach ($lstAv as $pr4) {
            if (!is_array($pr4) || empty($pr4['availability_periods'])) continue;
            // La gamme RETENUE pour ce produit, pas toutes celles qu'il porte :
            // c'est celle-là que la grille lui donnera, donc la seule sur
            // laquelle le filtre trouvera quelque chose.
            $gp = erp_season_principale($pr4['availability_periods'], $g);
            if ($gp) $porte[$gp['id']] = true;
          }
          $g = array_values(array_filter($g, fn ($x) => isset($porte[$x['id']])));
        }
        /* Liste produits illisible : on ne filtre PAS. Une coupure de l'ERP
           doit dégrader vers « toutes les gammes publiées », jamais vers une
           barre vide qui ferait croire qu'il n'y a plus de gammes. */
        if ($g) json_out($g);
      }
      // Source ERP activée mais aucune gamme publiée (ou aucune ne portant de
      // produit) : barre vide, et c'est exact — cocher webshop_active dans
      // Franchise Buddy, ou rattacher des produits à la gamme.
      json_out([]);
    }
    /* PLUS DE REPLI LOCAL. ws_season a été supprimée (migration 0100) : l'ERP
       est la source unique des gammes, comme il l'est déjà du catalogue.
       Source ERP inactive → aucune gamme, et la barre le montre. Un repli sur
       une table locale aurait servi des gammes que plus personne ne tient à
       jour, ce qui est pire qu'une barre vide : c'est une barre fausse. */
    json_out([]);
  }

  /* ── Promos / Vouchers ── */
  if ($m === 'GET' && $p === '/pricing/promos/cross-portion') {
    // RÉSOLVEUR UNIQUE (erp_promos.php) : la même fonction sert cet affichage
    // ET la facturation dans POST /orders. Deux résolutions séparées
    // finiraient par diverger — le client verrait une économie qu'on ne lui
    // débiterait pas.
    $r = cross_portion_rule(qp('shopId'));
    json_out($r ? ['active' => true, 'buy' => (int) $r['buy'], 'free' => (int) $r['free'],
                   'threshold' => (int) $r['threshold'], 'scope' => 'crossPortion',
                   'label' => $r['label'], 'source' => $r['source']]
                : ['active' => false]);
  }
  // Bons DISPONIBLES pour ce client + cette boutique — outil marketing : le
  // client voit ses codes applicables et les applique en un clic (pas de saisie).
  //   Réseau (id_shop NULL/=shop) + nominatifs (CUSTOMER/OFFICE de CE client).
  //   Filtrés : actifs, non expirés, non épuisés (global + par client), éligibles.
  if ($m === 'GET' && $p === '/vouchers/available') {
    $vLang = (string) (qp('lang') ?: '');
    $s = (int) (qp('shopId') ?: 0); if (!$s) json_out([]);
    // $tblExists n'existe QUE dans le bloc /franchisee/ — ici (racine) on
    // utilise une vérification directe (sinon « null is not callable » → 500).
    $tbl = fn ($t) => (bool) row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?", [$t]);
    if (!$tbl('voucher_code')) json_out([]);
    try {
      // L'identité vient de la SESSION (Authorization) — jamais du customerId
      // de l'URL. Sinon, déclarer l'id d'autrui suffisait à faire RÉVÉLER en
      // clair ses codes nominatifs (CUSTOMER) ou ceux de son bureau (OFFICE).
      $cid = auth_uid() ?: null;
      $sub = (float) (qp('subtotal') ?: 0);
      $officeId = null;
      if ($cid && col_exists('client', 'office_id')) {
        $c = row("SELECT office_id FROM client WHERE id=?", [$cid]); $officeId = $c['office_id'] ?? null;
      }
      $hasScope = col_exists('promotion_order_discount', 'scope_id_product');
      $hasRedem = $tbl('voucher_redemption');
      $rows = rows("SELECT vco.id AS code_id, vco.code, vc.id_shop, vc.target_kind, vc.target_id, vco.id_customer,
                           vc.usage_limit_per_customer, vco.usage_limit, vco.usage_count,
                           pod.discount_kind, pod.discount_value, pod.min_order_amount" .
                           ($hasScope ? ", pod.scope_id_product, pod.scope_max_qty" : ", NULL AS scope_id_product, NULL AS scope_max_qty") . "
                      FROM voucher_code vco
                      JOIN voucher_campaign vc            ON vc.id = vco.id_voucher_campaign
                      JOIN voucher_campaign_channel vcc   ON vcc.id_voucher_campaign = vc.id AND vcc.channel='WS'
                      JOIN promotion pr                   ON pr.id = vc.id_promotion AND pr.status='ACTIVE'
                      JOIN promotion_order_discount pod   ON pod.id_promotion = pr.id
                     WHERE vco.status='ACTIVE'
                       AND (vco.valid_to IS NULL OR vco.valid_to > NOW())
                       AND (vc.id_shop IS NULL OR vc.id_shop = ?)
                       AND (vco.usage_limit IS NULL OR vco.usage_count < vco.usage_limit)
                     ORDER BY vc.id_shop IS NULL, vco.id DESC", [$s]);
      $out = [];
      foreach (($rows ?: []) as $r) {
        $tk = $r['target_kind'] ?: 'NETWORK';
        if ($tk === 'CUSTOMER') { if (!$cid || (int) $r['id_customer'] !== $cid) continue; }
        elseif ($tk === 'OFFICE') { if (!$officeId || (int) $r['target_id'] !== (int) $officeId) continue; }
        elseif ($tk === 'GROUP') { continue; }
        if ($r['usage_limit_per_customer'] !== null) {
          if (!$cid) continue; // nominatif : nécessite un compte
          if ($hasRedem) {
            $used = row("SELECT COUNT(*) n FROM voucher_redemption WHERE id_voucher_code=? AND id_customer=? AND status='CONFIRMED'", [$r['code_id'], $cid]);
            if ((int) ($used['n'] ?? 0) >= (int) $r['usage_limit_per_customer']) continue;
          }
        }
        $kind = $r['discount_kind'];
        $isGift = $kind === 'PERCENT' && (float) $r['discount_value'] >= 100 && !empty($r['scope_id_product']);
        $val = $kind === 'PERCENT' ? '−' . rtrim(rtrim((string) $r['discount_value'], '0'), '.') . ' %'
             : ($kind === 'FIXED' ? '−' . rtrim(rtrim((string) $r['discount_value'], '0'), '.') . ' €'
                                  : ui_t('voucher.freeDelivery', 'Livraison offerte', $vLang));
        if (!empty($r['scope_id_product'])) {
          $pn = row("SELECT name FROM ws_products WHERE id=?", [(int) $r['scope_id_product']]);
          $pname = trim((string) (($pn['name'] ?? null) ?: ui_t('voucher.someProduct', 'un produit', $vLang)));
          $val = $isGift
            ? ui_t('voucher.productFree', '{produit} offert', $vLang, ['produit' => $pname])
            : ui_t('voucher.onProduct', '{remise} sur {qte}{produit}', $vLang, [
                'remise' => $val,
                'qte'    => $r['scope_max_qty'] !== null ? ((int) $r['scope_max_qty'] . ' × ') : '',
                'produit'=> $pname]);
        }
        $minOrd = (float) $r['min_order_amount'];
        $out[] = ['code' => $r['code'], 'label' => $val,
                  'min_order' => $minOrd,
                  'reachable' => $minOrd <= 0 || $sub >= $minOrd,
                  'hint' => $minOrd > 0 ? ui_t('voucher.fromAmount', 'dès {montant} €', $vLang,
                                ['montant' => rtrim(rtrim(number_format($minOrd, 2, ',', ''), '0'), ',')]) : '',
                  'personal' => $tk !== 'NETWORK'];
      }
      json_out($out);
    } catch (Throwable $e) {
      // Diagnostic explicite (endpoint lecture seule) — on voit la vraie cause.
      json_out(['error' => 'bons disponibles KO', 'detail' => $e->getMessage(),
                'ligne' => $e->getLine()], 500);
    }
  }
  if ($m === 'POST' && $p === '/vouchers/redeem') {
    rate_limit('voucher', 15, 600);   // anti brute-force des codes
    $b = body(); $sub = (float) ($b['subtotal'] ?? 0);
    // Verrou boutique (aligné sur le checkout) : bon de boutique -> sa boutique
    // seulement ; shop_id NULL = bon marque réseau.
    $vShop = isset($b['shopId']) && $b['shopId'] !== '' ? (int) $b['shopId'] : 0;
    $v = row("SELECT code, type, value, min_order FROM ws_vouchers
               WHERE code=? AND active=1 AND (expires_at IS NULL OR expires_at>NOW())
                 AND (max_uses IS NULL OR used_count<max_uses)
                 AND (shop_id IS NULL OR shop_id = ?) LIMIT 1",
             [strtoupper(trim($b['code'] ?? '')), $vShop]);
    if (!$v) json_out(['ok' => false, 'message' => 'Code invalide']);
    // Ciblage (0009) — même règle que le checkout : un bon CUSTOMER/OFFICE ne
    // passe que pour la bonne personne. Refusé dès la prévisualisation, pour ne
    // jamais afficher une remise qui sauterait ensuite à la facturation.
    $tg = row("SELECT vc.target_kind, vc.target_id, vco.id_customer
                 FROM voucher_code vco JOIN voucher_campaign vc ON vc.id = vco.id_voucher_campaign
                WHERE vco.code = ? LIMIT 1", [$v['code']]);
    if ($tg && ($tg['target_kind'] ?? 'NETWORK') !== 'NETWORK') {
      $cidV = auth_uid() ?: null;  // identité = session, pas le corps
      $okT = false;
      if ($tg['target_kind'] === 'CUSTOMER') {
        $okT = $cidV !== null && (int) $tg['id_customer'] === $cidV;
      } elseif ($tg['target_kind'] === 'OFFICE') {
        $off = $cidV !== null ? row("SELECT office_id FROM client WHERE id=?", [$cidV]) : null;
        $okT = $off && $off['office_id'] !== null && (int) $off['office_id'] === (int) $tg['target_id'];
      }
      if (!$okT) json_out(['ok' => false, 'message' => $cidV === null
        ? 'Code nominatif — connectez-vous pour l\'utiliser'
        : 'Code réservé à un autre client']);
    }
    if ($sub < (float) $v['min_order']) json_out(['ok' => false, 'message' => "Minimum {$v['min_order']} €"]);
    // Limite PAR CLIENT (voucher_campaign.usage_limit_per_customer) + périmètre
    // produit (0042) — mêmes règles que la facturation, refusées dès l'aperçu.
    $hasScope = col_exists('promotion_order_discount', 'scope_id_product');
    $sc = row("SELECT vco.id AS code_id, vc.usage_limit_per_customer" .
              ($hasScope ? ", pod.scope_id_product, pod.scope_max_qty" : ", NULL AS scope_id_product, NULL AS scope_max_qty") . "
                FROM voucher_code vco
                JOIN voucher_campaign vc ON vc.id = vco.id_voucher_campaign
                JOIN promotion_order_discount pod ON pod.id_promotion = vc.id_promotion
               WHERE vco.code = ? LIMIT 1", [$v['code']]);
    $cidV = auth_uid() ?: null;  // identité = session, pas le corps
    if ($sc && $sc['usage_limit_per_customer'] !== null) {
      if ($cidV === null) json_out(['ok' => false, 'message' => 'Code nominatif — connectez-vous pour l\'utiliser']);
      $used = row("SELECT COUNT(*) n FROM voucher_redemption WHERE id_voucher_code=? AND id_customer=? AND status='CONFIRMED'",
                  [$sc['code_id'], $cidV]);
      if ((int) ($used['n'] ?? 0) >= (int) $sc['usage_limit_per_customer'])
        json_out(['ok' => false, 'message' => 'Vous avez déjà utilisé ce code']);
    }
    $disc = 0; $scopeMsg = '';
    if ($sc && $sc['scope_id_product'] !== null && $v['type'] !== 'free_delivery') {
      // Remise limitée aux pièces de CE produit (les moins chères d'abord).
      // Le prix unitaire vient de l'ERP, PAS du panier envoyé par le client :
      // la facturation le résout ainsi (prix_boutique / erp_portion_options),
      // et un aperçu calculé sur un autre prix aurait annoncé une remise
      // différente de celle réellement appliquée. Sans prix ERP, on ne devine
      // pas : la pièce ne compte pas dans l'assiette.
      $units = [];
      $scPid  = (int) $sc['scope_id_product'];
      $scPx   = $vShop ? prix_produits([$scPid], (int) $vShop) : [];
      $scPort = $vShop ? erp_portion_options($vShop, [$scPid]) : [];
      foreach ((is_array($b['basket'] ?? null) ? $b['basket'] : []) as $l2) {
        if ((int) ($l2['productId'] ?? 0) !== $scPid) continue;
        $q2 = max(1, (int) ($l2['qty'] ?? 1));
        $u2 = $scPx[$scPid] ?? null;
        $po2 = mb_strtolower(trim((string) ($l2['portion'] ?? '')));
        if ($po2 !== '' && $po2 !== 'entier') {
          $u2 = null;
          foreach (($scPort[$scPid] ?? []) as $c3) {
            if ($c3['v'] === $po2 && $c3['price'] !== null) { $u2 = (float) $c3['price']; break; }
          }
        }
        if ($u2 === null) continue;
        for ($k2 = 0; $k2 < $q2; $k2++) $units[] = (float) $u2;
      }
      $pn = row("SELECT name FROM ws_products WHERE id=?", [(int) $sc['scope_id_product']]);
      $pname = $pn['name'] ?? ('produit #' . $sc['scope_id_product']);
      if (!$units) json_out(['ok' => false, 'message' => 'Ajoutez « ' . $pname . ' » au panier pour profiter du code']);
      sort($units);
      $cap = $sc['scope_max_qty'] !== null ? min((int) $sc['scope_max_qty'], count($units)) : count($units);
      $baseScope = array_sum(array_slice($units, 0, $cap));
      $disc = $v['type'] === 'percent' ? round($baseScope * (float) $v['value']) / 100
            : min((float) $v['value'], $baseScope);
      $scopeMsg = ' sur ' . $cap . ' × ' . $pname;
    } else {
      $disc = $v['type'] === 'percent' ? round($sub * (float) $v['value']) / 100
            : ($v['type'] === 'fixed' ? (float) $v['value'] : 0);
    }
    json_out(['ok' => true, 'discount' => round($disc, 2), 'freeDelivery' => ($v['type'] === 'free_delivery'),
              'voucher' => ['code' => $v['code'], 'type' => $v['type'], 'value' => (float) $v['value']],
              'message' => 'Code appliqué' . $scopeMsg]);
  }

  /* ── Campagnes « objectif d'achat cumulé → produit cadeau » ──
   * Le cumul se calcule sur ws_orders (commandes webshop). Le catalogue (produit
   * cadeau) et les boutiques restent maîtres côté ERP : on ne fait que les lire.
   * Identité du client : Bearer (client connecté) sinon email invité. */

  // Liste des campagnes actives pour une boutique (réseau + locales de la boutique).
  if ($m === 'GET' && $p === '/promo/active') {
    $s = qp('shopId');
    $now = promo_now();
    $rowsC = rows(
      "SELECT id, name, id_shop, threshold_amount, currency, starts_at, ends_at,
              reward_product_id, reward_delivery_date, voucher_code_prefix
         FROM ws_promo_campaign
        WHERE is_active = 1 AND starts_at <= ? AND ends_at >= ?
          AND (id_shop IS NULL " . ($s ? "OR id_shop = ?" : "") . ")
        ORDER BY ends_at",
      $s ? [$now, $now, $s] : [$now, $now]
    );
    json_out(array_map(fn ($c) => promo_campaign_public($c), $rowsC));
  }

  // Progression d'un client sur une campagne (lecture seule : calcul live).
  if ($m === 'GET' && ($mm = $match('/promo/:id/progress'))) {
    $camp = promo_campaign((int) $mm['id']);
    if (!$camp) json_out(['error' => 'Campagne introuvable'], 404);

    $uid   = auth_uid();
    $guest = $uid ? null : trim((string) qp('guestEmail', ''));
    $ref   = promo_customer_ref($uid, $guest);
    if (!$ref) json_out(['error' => 'Identité requise (connexion ou guestEmail)'], 400);

    promo_apply_tz(db());
    $acc  = promo_accumulate(db(), $camp, $uid, $guest);
    $prog = row("SELECT unlocked_at, voucher_code, redeemed_at
                   FROM ws_promo_progress WHERE campaign_id = ? AND customer_ref = ?",
                [$camp['id'], $ref]);
    json_out(promo_progress_payload($camp, $acc, $prog));
  }

  // Attribution / verrouillage du voucher (idempotent).
  if ($m === 'POST' && ($mm = $match('/promo/:id/claim'))) {
    $camp = promo_campaign((int) $mm['id']);
    if (!$camp) json_out(['error' => 'Campagne introuvable'], 404);

    $b     = body();
    $uid   = auth_uid();
    $guest = $uid ? null : trim((string) ($b['guestEmail'] ?? qp('guestEmail', '')));
    $ref   = promo_customer_ref($uid, $guest);
    if (!$ref) json_out(['error' => 'Identité requise (connexion ou guestEmail)'], 400);

    promo_apply_tz(db());
    $acc = promo_accumulate(db(), $camp, $uid, $guest);
    $pdo = db();
    try {
      $pdo->beginTransaction();

      // Upsert de la progression (cache du cumul). UNIQUE(campaign_id, customer_ref).
      q("INSERT INTO ws_promo_progress (campaign_id, customer_ref, accumulated_amount)
              VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE accumulated_amount = VALUES(accumulated_amount),
                                 updated_at = CURRENT_TIMESTAMP",
        [$camp['id'], $ref, $acc['amount']]);

      $prog = row("SELECT id, unlocked_at, voucher_code, redeemed_at
                     FROM ws_promo_progress WHERE campaign_id = ? AND customer_ref = ?",
                  [$camp['id'], $ref]);

      // Traçabilité des commandes comptabilisées (idempotent via UNIQUE).
      foreach ($acc['orders'] as $o) {
        q("INSERT IGNORE INTO ws_promo_progress_order (progress_id, order_id, counted_amount)
                VALUES (?, ?, ?)", [$prog['id'], $o['id'], $o['amount']]);
      }

      // Déblocage : seuil atteint ET dans la période ET pas déjà débloqué.
      if (empty($prog['unlocked_at']) && promo_is_unlockable($camp, $acc['amount'], promo_now())) {
        $code = promo_generate_code($camp);
        q("UPDATE ws_promo_progress
              SET unlocked_at = CURRENT_TIMESTAMP, voucher_code = ?
            WHERE id = ? AND unlocked_at IS NULL", [$code, $prog['id']]);
        $prog = row("SELECT unlocked_at, voucher_code, redeemed_at
                       FROM ws_promo_progress WHERE id = ?", [$prog['id']]);
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }

    json_out(promo_progress_payload($camp, $acc, $prog));
  }

  // Pré-validation d'un code cadeau au checkout (le front affiche la ligne 0 €
  // avant de commander). L'attribution réelle (consommation) a lieu à la commande.
  if ($m === 'POST' && $p === '/promo/redeem') {
    $b    = body();
    $code = trim((string) ($b['code'] ?? ''));
    $shop = (int) ($b['shopId'] ?? qp('shopId', 0));
    if ($code === '' || !$shop) json_out(['valid' => false, 'reason' => 'code_and_shop_required']);

    $uid   = auth_uid();
    $guest = $uid ? null : trim((string) ($b['guestEmail'] ?? ''));
    $ref   = promo_customer_ref($uid, $guest);
    if (!$ref) json_out(['valid' => false, 'reason' => 'identity_required']);

    $g = promo_gift_row($code);
    if (!$g) json_out(['valid' => false, 'reason' => 'unknown_code']);
    $chk = promo_gift_redeemable($g, $ref, $shop, promo_now());
    json_out($chk['ok']
      ? ['valid' => true,  'reward' => promo_reward($g['reward_product_id'])]
      : ['valid' => false, 'reason' => $chk['reason']]);
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
    // Livraison au bureau : « Paiement en boutique » n'a pas de sens — le client
    // ne s'y rend jamais. Il était pourtant proposé, le mode n'étant pas pris en
    // compte ici : la marchandise partait au bureau et l'encaissement n'avait
    // jamais lieu. Même famille que la commande acceptée sans moyen de paiement.
    if (qp('mode') === 'delivery') $methods = array_values(array_filter($methods, fn ($x) => $x !== 'shop'));
    // `family` (payment_family) dit au front si le moyen passe par la page de
    // paiement hébergée ('stripe'), se règle sur place ('shop') ou en compte
    // ('deferred'). Le front ne tient PAS sa propre liste de méthodes carte :
    // dupliquer la classification, c'est la voir diverger.
    json_out(array_map(fn ($x) => ['method' => $x, 'label' => payment_label($x, qp('lang')),
                                   'family' => payment_family($x)], $methods));
  }

  /* ── Availability / Calendar ── */
  if ($m === 'GET' && $p === '/availability/settings') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    json_out(row("SELECT * FROM ws_shop_availability WHERE shop_id = ?", [$s]) ?: []);
  }
  // Créneaux — /calendar/slots ET /availability/slots (le front interroge
  // /availability/slots). Deux sources, dans l'ordre, SANS jamais inventer :
  //   1) créneaux EXPLICITES encodés par la boutique (ws_slots) -> priment ;
  //   2) sinon GÉNÉRATION depuis les VRAIES heures + durée de la boutique
  //      (ws_shop_availability.{collect,delivery}_hours_* + *_slot_duration_min).
  //   3) aucune config -> [] (jamais de créneau fictif).
  if ($m === 'GET' && ($p === '/calendar/slots' || $p === '/availability/slots')) {
    $s = (int) qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    $mode = qp('mode') ?: 'collect';
    $explicit = rows("SELECT id, mode, label, sort_order FROM ws_slots
                       WHERE shop_id=? AND mode=? AND active=1 ORDER BY sort_order", [$s, $mode]);
    if ($explicit) json_out($explicit);
    $av = row("SELECT collect_hours_start, collect_hours_end, collect_slot_duration_min, collect_capacity_per_slot,
                      delivery_hours_start, delivery_hours_end, delivery_slot_duration_min, delivery_capacity_per_slot
                 FROM ws_shop_availability WHERE shop_id=?", [$s]);
    if (!$av) json_out([]);
    if ($mode === 'delivery') {
      $start = $av['delivery_hours_start']; $end = $av['delivery_hours_end'];
      $dur = (int) $av['delivery_slot_duration_min']; $cap = (int) $av['delivery_capacity_per_slot'];
    } else {
      $start = $av['collect_hours_start']; $end = $av['collect_hours_end'];
      $dur = (int) $av['collect_slot_duration_min']; $cap = (int) $av['collect_capacity_per_slot'];
    }
    if (!$start || !$end || $dur <= 0) json_out([]);
    $t0 = strtotime($start); $t1 = strtotime($end); $step = $dur * 60;
    $out = []; $i = 0;
    for ($t = $t0; $t + $step <= $t1; $t += $step) {
      $a = date('H:i', $t); $bb = date('H:i', $t + $step);
      $out[] = ['id' => 'gen-' . $mode . '-' . date('Hi', $t), 'mode' => $mode,
                'label' => $a . ' – ' . $bb, 'sort_order' => $i++, 'capacity' => $cap];
    }
    json_out($out);
  }
  if ($m === 'GET' && $p === '/calendar/cutoff') {
    $s = qp('shopId'); if (!$s) json_out(['error' => 'shopId requis'], 400);
    json_out(row("SELECT cutoff_hour, cutoff_minutes, lead_hours, open_days FROM ws_calendar_rules
                   WHERE shop_id=? AND mode=? AND active=1 LIMIT 1", [$s, qp('mode') ?: 'collect']) ?: []);
  }
  // ── Référentiel géo : codes postaux belges (bpost open data, embarqué). ──
  //    ?all=1 → liste compacte [[cp, commune, lat, lng]…] (~100 Ko, à cacher côté client)
  //    ?q=…   → recherche par préfixe de CP ou nom de commune (12 max)
  /* ── POLYGONES des codes postaux (contours réels, pour la carte des zones de
     chalandise). Source = fichier GeoJSON déposé dans data/. AUCUN REPLI : sans
     le fichier, on renvoie une ERREUR explicite (503) — la carte affiche le
     message au lieu de dessiner des pastilles approximatives au centre du CP,
     qui laissaient croire à un contour de territoire.
     Format attendu (FeatureCollection) : chaque feature porte le code postal
     dans properties.postcode | postal_code | zip | cp, et une géométrie
     Polygon/MultiPolygon. Réponse : { "1000": <geometry>, … } filtrable par
     ?cp=1000,1050 pour ne charger que les CP utilisés. ── */
  if ($m === 'GET' && $p === '/geo/postcode-polygons') {
    // Noms de fichier réels acceptés (aucune donnée inventée) — la variante
    // COMPRESSÉE est prioritaire : le GeoJSON des contours pèse plusieurs
    // dizaines de Mo en clair (trop gros pour un dépôt git), alors qu'il
    // compresse d'un facteur ~8. Le serveur le décompresse à la lecture.
    $file = null; $gz = false;
    foreach ([['/data/zipcodes_be_polygons.geojson.gz', true],
              ['/data/zipcodes_be_polygons.json.gz', true],
              ['/data/zipcodes_be_polygons.geojson', false],
              ['/data/zipcodes_be_polygons.json', false]] as [$cand, $isGz]) {
      if (is_file(__DIR__ . $cand)) { $file = __DIR__ . $cand; $gz = $isGz; break; }
    }
    if ($file === null) {
      json_out(['error' => 'polygones des codes postaux non installés',
                'detail' => 'Déposez les contours dans api/data/zipcodes_be_polygons.geojson.gz (recommandé, compressé) ou .geojson.',
                'fichier_attendu' => 'data/zipcodes_be_polygons.geojson.gz'], 503);
    }
    $blob = (string) file_get_contents($file);
    if ($gz) {
      if (!function_exists('gzdecode')) {
        json_out(['error' => 'contours compressés illisibles',
                  'detail' => "L'extension PHP zlib est absente : déposez la version non compressée (.geojson)."], 503);
      }
      $blob = (string) gzdecode($blob);
      if ($blob === '') {
        json_out(['error' => 'contours compressés illisibles',
                  'detail' => 'Décompression impossible — fichier .gz corrompu ?'], 503);
      }
    }
    $raw = json_decode($blob, true);
    if (!is_array($raw) || empty($raw['features'])) {
      json_out(['error' => 'polygones illisibles',
                'detail' => 'FeatureCollection attendue avec une clé "features" non vide.'], 503);
    }
    $want = [];
    if (($cpq = qp('cp')) !== null && $cpq !== '') {
      foreach (preg_split('/[^0-9]+/', (string) $cpq) as $c) { if ($c !== '') $want[$c] = true; }
    }
    $out = [];
    foreach ($raw['features'] as $f) {
      $pr = $f['properties'] ?? [];
      $cp = (string) ($pr['postcode'] ?? $pr['postal_code'] ?? $pr['zip'] ?? $pr['cp'] ?? '');
      $cp = trim($cp);
      if ($cp === '' || empty($f['geometry'])) continue;
      if ($want && !isset($want[$cp])) continue;
      $out[$cp] = $f['geometry'];
    }
    if (!$out) {
      json_out(['error' => 'aucun polygone exploitable',
                'detail' => 'Aucune feature ne porte de code postal reconnaissable (properties.postcode/postal_code/zip/cp).'], 503);
    }
    // Métadonnées du jeu de contours (granularité, source, note) remontées sous
    // « _meta » : l'écran affiche un avertissement quand la granularité n'est
    // pas le code postal lui-même — aucune approximation n'est présentée comme
    // un contour exact. Clé préfixée : les appelants lisent par code postal.
    if (!empty($raw['meta']) && is_array($raw['meta'])) $out['_meta'] = $raw['meta'];
    json_out($out);
  }
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
  /* ── DEMANDE DE RATTACHEMENT À UN BUREAU (webshop, client connecté) ────────
     « Mon bureau n'est pas dans la liste » → le client NE se rattache PAS
     lui-même : la demande part au franchisé (BO › Demandes de rattachement) qui
     décide (POST /franchisee/join-decide). C'est cette décision qui écrit
     client.office_id — la précondition de la livraison au bureau.
     Écriture réelle dans ws_office_join_requests : aucun faux succès, la table
     ou la colonne manquante renvoie une erreur explicite. ── */
  if ($m === 'POST' && $p === '/offices/contact') {
    rate_limit('officejoin', 6, 600);
    $uid = auth_uid();
    if (!$uid) json_out(['ok' => false, 'error' => 'Connectez-vous pour demander un rattachement.'], 401);
    if (!row("SELECT 1 AS x FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'ws_office_join_requests' LIMIT 1"))
      json_out(['ok' => false, 'error' => 'Table ws_office_join_requests absente — demande non enregistrée (migration 0047).'], 501);
    if (!col_exists('ws_office_join_requests', 'client_id'))
      json_out(['ok' => false, 'error' => 'Colonne client_id absente de ws_office_join_requests — impossible de savoir qui rattacher (migration 0047).'], 501);
    $b    = body();
    $name = trim((string) ($b['officeName'] ?? ''));
    if ($name === '') json_out(['ok' => false, 'error' => 'Indiquez le nom du bureau ou de la société.'], 400);
    $addr  = trim((string) ($b['address'] ?? ''));
    $mail  = trim((string) ($b['email'] ?? ''));
    $tel   = trim((string) ($b['phone'] ?? ''));
    if ($addr === '' && $mail === '' && $tel === '')
      json_out(['ok' => false, 'error' => 'Indiquez au moins un moyen de contact (téléphone, e-mail ou adresse).'], 400);
    // Boutique : celle transmise par le formulaire, sinon celle du client.
    $shop = (int) ($b['shopId'] ?? 0);
    if (!$shop) {
      $sc = col_exists('client', 'preferred_shop_id') ? 'COALESCE(preferred_shop_id, id_main_shop)' : 'id_main_shop';
      $shop = (int) (row("SELECT $sc AS s FROM client WHERE id = ?", [$uid])['s'] ?? 0);
    }
    if (!$shop) json_out(['ok' => false, 'error' => 'Aucune boutique rattachée à votre compte — choisissez votre boutique avant de demander un rattachement.'], 409);
    // Anti-doublon : une seule demande en attente par client (on met à jour la
    // demande existante plutôt que d'empiler des lignes dans l'écran franchisé).
    $prev = row("SELECT id FROM ws_office_join_requests
                  WHERE client_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$uid]);
    $set = ['office_name_raw' => $name, 'client_id' => $uid];
    // address_raw est NOT NULL sans défaut sur la table historique : on écrit
    // une chaîne vide quand le client n'a donné que téléphone ou e-mail.
    if (col_exists('ws_office_join_requests', 'address_raw'))   $set['address_raw']   = $addr;
    if (col_exists('ws_office_join_requests', 'contact_email'))  $set['contact_email'] = ($mail ?: null);
    if (col_exists('ws_office_join_requests', 'contact_phone'))  $set['contact_phone'] = ($tel ?: null);
    if (col_exists('ws_office_join_requests', 'shop_id'))        $set['shop_id']       = $shop;
    if ($prev) {
      $sql = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($set)));
      q("UPDATE ws_office_join_requests SET $sql, status = 'pending' WHERE id = ?",
        array_merge(array_values($set), [(int) $prev['id']]));
      $rid = (int) $prev['id'];
    } else {
      $set['status'] = 'pending';
      $cols = array_keys($set);
      q("INSERT INTO ws_office_join_requests (" . implode(', ', $cols) . ")
         VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")", array_values($set));
      $rid = (int) db()->lastInsertId();
    }
    json_out(['ok' => true, 'requestId' => $rid, 'status' => 'pending',
              'message' => 'Demande transmise à votre boutique. Vous serez rattaché après validation.'], 201);
  }

  /* ── LIEN MAGIQUE « Créer mon compte » (public, jeton signé) ───────────────
     Le franchisé crée le bureau, le serveur émet UN lien, le contact du bureau
     le transfère à son personnel. Chaque collaborateur ouvre la page avec sa
     boutique, son bureau et son site DÉJÀ liés : il ne saisit que son identité.

     Ce qui suit sert la page. Deux principes gouvernent les deux endpoints :

     • LE JETON FAIT AUTORITÉ, PAS LE FORMULAIRE. Boutique, bureau et site sont
       relus dans la charge signée à chaque appel ; les mêmes valeurs postées
       par le navigateur sont ignorées. Sinon il suffirait de remplacer
       office=17 par office=18 pour s'inscrire — et commander — chez un autre
       client, en paiement différé.
     • LE LIEN PRÉ-REMPLIT, IL N'OUVRE RIEN. Le compte créé entre en `pending`
       dans ws_office_join_requests, exactement comme une demande faite à la
       main : c'est le franchisé qui écrit client.office_id. ── */
  if ($m === 'GET' && $p === '/inscription') {
    // ?i= jeton signé (e-mail) ou ?c= code court (QR et URL recopiée à la main).
    [$d, $ko, $row] = invite_check((string) (qp('i', '') !== '' ? qp('i', '') : qp('c', '')));
    // 410 Gone : le lien a existé et ne vaut plus. Le corps porte le MOTIF —
    // « expiré » se redemande au responsable, « invalide » signale une URL
    // coupée par un client mail : la page ne dit pas la même chose.
    if (!$d) json_out(['ok' => false, 'error' => $ko], 410);
    $shop = row("SELECT id, name, city FROM shops WHERE id = ?", [(int) ($d['shop'] ?? 0)]);
    $off  = !empty($d['office'])
      ? row("SELECT id, name, address, postal_code, city, active FROM ws_offices WHERE id = ?", [(int) $d['office']]) : null;
    $site = !empty($d['site'])
      ? row("SELECT id, name, address, floor_room FROM ws_office_delivery_sites WHERE id = ? AND active = 1", [(int) $d['site']]) : null;
    // Le bureau est la raison d'être du lien : sans lui, la page créerait un
    // compte rattaché à rien. On refuse plutôt que d'afficher un formulaire.
    if (!$off) json_out(['ok' => false, 'error' => 'Le bureau de ce lien n’existe plus. Demandez un nouveau lien à votre responsable.'], 410);
    $depts = [];
    $dIds  = array_values(array_filter(array_map('intval', (array) ($d['depts'] ?? []))));
    if ($dIds && row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='b2b_client_company_department'"))
      $depts = rows("SELECT id, name FROM b2b_client_company_department
                      WHERE id IN (" . implode(',', $dIds) . ") ORDER BY name");
    /* RIEN N'EST INVENTÉ : une valeur absente est renvoyée à null et la page
       retire la ligne, au lieu d'afficher un texte de remplacement que
       l'employé prendrait pour une donnée de son entreprise. */
    json_out(['ok' => true,
      'office'    => ['id' => (int) $off['id'], 'name' => $off['name'] ?: null,
                      'validated' => (int) $off['active'] === 1],
      'site'      => $site ? ['address' => $site['address'] ?: null,
                              'floor' => $site['floor_room'] ?: null] : null,
      'shop'      => $shop ? ['id' => (int) $shop['id'], 'name' => $shop['name'] ?: null] : null,
      'depts'     => array_map(fn ($x) => ['id' => (int) $x['id'], 'name' => $x['name']], $depts),
      // Exigence de domaine retirée (14/08/2026) : jamais ré-annoncée, même
      // sur un ancien jeton qui en porte un — POST /inscription ne la
      // contrôle plus, l'afficher ferait refuser au formulaire ce que le
      // serveur accepte.
      'domain'    => null,
      'cp'        => $d['cp'] ?: null,
      'expiresAt' => $row['expires_at'] ?? null]);
  }

  if ($m === 'POST' && $p === '/inscription') {
    rate_limit('inscription', 10, 600);
    $b = body();
    [$d, $ko] = invite_check((string) (($b['i'] ?? '') !== '' ? $b['i'] : ($b['c'] ?? '')));
    if (!$d) json_out(['ok' => false, 'error' => $ko], 410);
    $shopI = (int) ($d['shop'] ?? 0);
    $offI  = (int) ($d['office'] ?? 0);
    $off   = $offI ? row("SELECT id, name, address FROM ws_offices WHERE id = ?", [$offI]) : null;
    if (!$off)   json_out(['ok' => false, 'error' => 'Le bureau de ce lien n’existe plus. Demandez un nouveau lien à votre responsable.'], 410);
    if (!$shopI) json_out(['ok' => false, 'error' => 'Ce lien ne désigne aucune boutique livreuse — il ne peut pas créer de compte.'], 409);

    $first = trim((string) ($b['firstName'] ?? ''));
    $last  = trim((string) ($b['lastName'] ?? ''));
    $mail  = strtolower(trim((string) ($b['email'] ?? '')));
    if ($first === '' || $last === '') json_out(['ok' => false, 'error' => 'Prénom et nom requis.'], 400);
    if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) json_out(['ok' => false, 'error' => 'Adresse e-mail invalide.'], 400);
    /* EXIGENCE DE DOMAINE RETIRÉE (14/08/2026, demande explicite) : toute
       adresse e-mail valide passe, y compris sur les anciens jetons qui
       portent encore un domaine en base. Le garde-fou est ailleurs et il
       reste : le compte naît « pending » et la livraison bureau ne s'ouvre
       qu'après validation du franchisé (Demandes de rattachement bureau). */
    [$pfx, $phone, $e164] = norm_phone($b['phonePrefix'] ?? '+32', $b['phone'] ?? '');
    $zip = trim((string) ($b['postalCode'] ?? ($d['cp'] ?? '')));
    if ($zip === '') json_out(['ok' => false, 'error' => 'Code postal requis.'], 400);
    $zip = zip_validate($zip, 'BE');
    if ($zip === null) json_out(['ok' => false, 'error' => 'Code postal invalide.'], 400);
    $loc  = zip_locality($zip, $b['locality'] ?? '');
    /* CODE PIN À 4 CHIFFRES (14/08/2026) — remplace le mot de passe de
       12 caractères : le personnel s'inscrit depuis un téléphone, sur un lien
       transféré, et la règle longue faisait échouer les inscriptions. Même
       stockage que le mot de passe (password_hash) : le compte se connecte
       ensuite par e-mail + PIN sur /auth/login, inchangé et rate-limité.
       10 000 combinaisons, c'est court — les protections restantes sont le
       rate limit par IP (inscription 10/10 min, login 10/5 min) et le compte
       « pending » tant que le franchisé n'a pas validé. La règle est
       appliquée ICI ; le champ du formulaire n'est qu'un confort. */
    $pin = preg_replace('/\D/', '', (string) (($b['pin'] ?? '') !== '' ? $b['pin'] : ($b['password'] ?? '')));
    if (strlen($pin) !== 4)
      json_out(['ok' => false, 'error' => 'Code PIN : exactement 4 chiffres.'], 400);

    // Compte déjà là : on ne fusionne pas et on n'écrase aucun mot de passe —
    // la page propose de se connecter (même contrat que /auth/register).
    if (row("SELECT id FROM client WHERE LOWER(TRIM(email)) = ? LIMIT 1", [$mail]))
      json_out(['ok' => false, 'exists' => true,
                'error' => 'Un compte existe déjà pour cette adresse. Connectez-vous pour commander.'], 409);

    // Département CHOISI, et seulement parmi ceux du jeton : une liste postée
    // par le navigateur laisserait choisir le département d'une autre société.
    $dIds   = array_values(array_filter(array_map('intval', (array) ($d['depts'] ?? []))));
    $deptId = (int) ($b['deptId'] ?? 0);
    if ($deptId && !in_array($deptId, $dIds, true)) $deptId = 0;

    $hasLoc  = col_exists('client', 'locality');
    $hasPref = col_exists('client', 'preferred_shop_id');
    $hasDept = col_exists('client', 'department_id') && $deptId;
    /* office_id N'EST PAS ÉCRIT ICI. C'est la clé de la livraison au bureau :
       la poser à l'inscription reviendrait à laisser le porteur du lien
       commander sur le compte de l'entreprise avant toute validation.
       source_channel : 'webshop' obligatoirement — la colonne est un ENUM en
       prod qui ne connaît pas 'invite' (erreur 1265 → 500 sur tout le flux).
       L'origine « invitation » reste tracée par la demande de rattachement. */
    q("INSERT INTO client (id_main_shop, " . ($hasPref ? "preferred_shop_id, " : "") . "email, phone, phone_prefix, phone_e164,
                           name, surname, zip, " . ($hasLoc ? "locality, " : "") . ($hasDept ? "department_id, " : "") . "password_hash,
                           active, source_channel, webshop_user, preferred_auth_method)
       VALUES (?," . ($hasPref ? "?," : "") . "?,?,?,?,?,?,?," . ($hasLoc ? "?," : "") . ($hasDept ? "?," : "") . "?,1,'webshop',1,'email')",
      array_merge([$shopI], $hasPref ? [$shopI] : [],
        [$mail, ($phone ?: null), ($phone !== '' ? $pfx : null), ($e164 ?: null), $first, $last, $zip],
        $hasLoc ? [$loc] : [], $hasDept ? [$deptId] : [],
        [password_hash($pin, PASSWORD_BCRYPT)]));
    $uid = (int) db()->lastInsertId();

    // Demande de rattachement — le bureau est CONNU (il vient du jeton), donc
    // écrit tel quel : le franchisé n'a plus à le deviner par ressemblance de
    // nom, il valide ou rejette.
    $jrOk = false;
    if (row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ws_office_join_requests'")) {
      $set = ['office_name_raw' => (string) $off['name'], 'status' => 'pending'];
      if (col_exists('ws_office_join_requests', 'client_id'))     $set['client_id']     = $uid;
      if (col_exists('ws_office_join_requests', 'address_raw'))   $set['address_raw']   = (string) ($off['address'] ?? '');
      if (col_exists('ws_office_join_requests', 'contact_email')) $set['contact_email'] = $mail;
      if (col_exists('ws_office_join_requests', 'contact_phone')) $set['contact_phone'] = ($phone ?: null);
      if (col_exists('ws_office_join_requests', 'shop_id'))       $set['shop_id']       = $shopI;
      if (col_exists('ws_office_join_requests', 'office_id'))     $set['office_id']     = $offI;
      // Consentement CGV : la case était exigée à l'écran mais jamais
      // transmise ni stockée — aucun moyen de prouver l'acceptation.
      if (!empty($b['cgv']) && col_exists('ws_office_join_requests', 'cgv_accepted_at')) $set['cgv_accepted_at'] = date('Y-m-d H:i:s');
      try {
        q("INSERT INTO ws_office_join_requests (" . implode(', ', array_keys($set)) . ")
           VALUES (" . implode(', ', array_fill(0, count($set), '?')) . ")", array_values($set));
        $jrOk = true;
      } catch (Throwable $e) { error_log('[ws] inscription join-request KO : ' . $e->getMessage()); }
    }
    try { q("UPDATE ws_office_invites SET uses = uses + 1, last_use_at = NOW() WHERE jti = ?", [(string) $d['jti']]); }
    catch (Throwable $e) { /* le compteur d'usages n'est pas une condition d'inscription */ }

    /* Le compte est créé et connecté, mais NON RATTACHÉ : le webshop
       s'ouvre en click & collect ; la livraison au bureau s'ouvrira à la
       validation. On le dit, plutôt que de laisser l'employé chercher
       pourquoi son bureau n'apparaît pas. */
    json_out(['ok' => true, 'status' => 'pending', 'user' => user_payload($uid),
      'token'  => sign_token(['id' => $uid, 'exp' => time() + 30 * 86400]),
      'office' => $off['name'], 'joinRequest' => $jrOk,
      'message' => $jrOk
        ? 'Compte créé. Votre rattachement à ' . $off['name'] . ' est transmis pour validation ; vous recevrez un e-mail dès qu’il est actif.'
        : 'Compte créé, mais la demande de rattachement n’a pas pu être enregistrée — contactez votre boutique pour être rattaché à ' . $off['name'] . '.'], 201);
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
  /* BUREAUX PROPOSÉS AU CLIENT — des SOCIÉTÉS, pas des points de livraison.
     La requête part de ws_offices, pas de ws_office_delivery_sites. Partir des
     sites renvoyait le nom du POINT : une recherche « lou » sortait trois fois
     « Zoning Nord LLN · Chemin du Cyclotron 3 » — trois entreprises différentes
     du même bâtiment, rigoureusement indiscernables. Le client cherche son
     employeur, pas l'adresse du hall.

     La chaîne exigée est celle du métier : BUREAU → SITE → TOURNÉE. Un bureau
     n'est proposé que s'il est rattaché à un site actif, lui-même desservi par
     une tournée active. Sans ces trois maillons il ne sera pas livré, et le
     proposer revient à faire lier un bureau sans effet.

     GROUP BY sur le bureau : une société ayant plusieurs points n'apparaît
     qu'UNE fois. Le site transmis au serveur est le plus ancien de ses points
     (MIN) — ils partagent la même société, donc la même tournée.

     RECHERCHE SEULEMENT : sans terme, réponse vide. Le carnet d'adresses B2B
     d'une boutique n'a pas à être feuilleté par n'importe quel visiteur. */
  if ($m === 'GET' && $p === '/office-sites') {
    $s = (int) (qp('shopId') ?: 0);
    $q = trim((string) qp('q'));
    if (!$s) json_out([]);
    if (mb_strlen($q) < 2) json_out([]);   // « a » rendrait presque tout le carnet
    if (!col_exists('ws_office_delivery_sites', 'tournee_id')) json_out([]);
    $like = '%' . $q . '%';
    json_out(rows("SELECT MIN(sd.id) AS id,
                          o.id        AS officeId,
                          o.name      AS name,
                          MIN(sd.address) AS address,
                          MIN(t.name)     AS tourName,
                          -- Sous-requête, pas COUNT sur la jointure filtrée : le
                          -- filtre de recherche ne retient qu'une partie des
                          -- points, et le compte aurait varié selon le mot tapé
                          -- (« 1 point » sur une recherche, « 2 » sur une autre,
                          -- pour le même bureau).
                          (SELECT COUNT(*) FROM ws_office_delivery_sites sp
                            WHERE sp.office_client_id = o.id AND sp.active = 1) AS points
                     FROM ws_offices o
                     JOIN ws_office_delivery_sites sd
                       ON sd.office_client_id = o.id AND sd.active = 1
                     JOIN ws_tours t ON t.id = sd.tournee_id AND t.active = 1
                    WHERE o.shop_id = ? AND o.active = 1 AND o.status = 'validated'
                      AND o.name IS NOT NULL AND o.name <> ''
                      AND (o.name LIKE ? OR sd.name LIKE ? OR sd.address LIKE ?)
                    GROUP BY o.id, o.name
                    ORDER BY o.name LIMIT 25", [$s, $like, $like, $like]));
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

  /* Demande d'événement B2B — formulaire de la landing publique /landing/b2b.
     AVANT : le formulaire affichait « demande transmise » sans rien envoyer
     (résidu de mode démo) — chaque prospect réel était perdu. Même armature
     que /zone-request : rate-limit par IP, insertion, mail d'alerte. */
  if ($m === 'POST' && $p === '/b2b/event-request') {
    if (!row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ws_b2b_event_requests'"))
      json_out(['error' => 'Table ws_b2b_event_requests absente (migration 0082).'], 501);
    $b = body();
    $email = trim((string) ($b['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Email professionnel requis.'], 400);
    $nom = trim((string) ($b['contactName'] ?? ''));
    if ($nom === '') json_out(['error' => 'Nom du contact requis.'], 400);
    if (empty($b['consent'])) json_out(['error' => 'Consentement requis.'], 400);
    $ipRaw = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    $ip = trim(explode(',', $ipRaw)[0]);
    $max = (int) ws_param('b2b_event_rate_per_hour', '5');
    $rl = row("SELECT COUNT(*) AS n FROM ws_b2b_event_requests
                WHERE source_ip=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [$ip]);
    if ($rl && (int) $rl['n'] >= $max) json_out(['error' => 'Trop de demandes, réessayez plus tard.'], 429);
    $date = trim((string) ($b['eventDate'] ?? ''));
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    q("INSERT INTO ws_b2b_event_requests
         (event_type, event_date, guests, zone_id, shop_id, postal_code, budget, company, vat,
          contact_name, email, phone, message, consent_at, source_ip)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)",
      [mb_substr(trim((string) ($b['eventType'] ?? '')), 0, 60) ?: null,
       $date,
       is_numeric($b['guests'] ?? null) ? (int) $b['guests'] : null,
       is_numeric($b['zoneId'] ?? null) ? (int) $b['zoneId'] : null,
       is_numeric($b['shopId'] ?? null) ? (int) $b['shopId'] : null,
       mb_substr(trim((string) ($b['postalCode'] ?? '')), 0, 10) ?: null,
       mb_substr(trim((string) ($b['budget'] ?? '')), 0, 60) ?: null,
       mb_substr(trim((string) ($b['company'] ?? '')), 0, 190) ?: null,
       mb_substr(trim((string) ($b['vat'] ?? '')), 0, 40) ?: null,
       mb_substr($nom, 0, 190),
       mb_substr($email, 0, 190),
       mb_substr(trim((string) ($b['phone'] ?? '')), 0, 30) ?: null,
       mb_substr(trim((string) ($b['message'] ?? '')), 0, 5000) ?: null,
       $ip ?: null]);
    $admin = ws_param('zone_request_admin_email', cfg()['mail_from'] ?? '');
    if ($admin && filter_var($admin, FILTER_VALIDATE_EMAIL)) {
      $from = cfg()['mail_from'] ?? 'no-reply@atelierby.be';
      @mail($admin, 'Nouvelle demande événement B2B — landing',
            "Type: " . ($b['eventType'] ?? '—') . "\nDate: " . ($date ?: '—')
            . "\nConvives: " . ($b['guests'] ?? '—') . "\nZone: " . ($b['zoneId'] ?? '—')
            . " (boutique " . ($b['shopId'] ?? '—') . ")\nSociete: " . ($b['company'] ?? '—')
            . "\nTVA: " . ($b['vat'] ?? '—') . "\nContact: $nom\nEmail: $email\nTel: "
            . ($b['phone'] ?? '—') . "\nBudget: " . ($b['budget'] ?? '—') . "\nMessage: "
            . ($b['message'] ?? '—') . "\n",
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
  /* Devis de frais de livraison — CALCULÉ, et dans la forme que le front et
     webshop-delivery-fees-api.jsx documentent (snake_case).

     Cet endpoint renvoyait la règle BRUTE avec des alias camelCase
     (freeDelivery, feeAmount, paymentType). Aucun consommateur ne lit ces
     clés-là : toutes les lectures valaient donc undefined, avec des
     conséquences en cascade —
       • l'affichage retombait sur « Gratuite » quoi qu'il arrive, pendant que
         la commande facturait le vrai forfait (vu en test : 29,90 € annoncés,
         36,90 € enregistrés) ;
       • « Encore X € pour la livraison gratuite » ne s'affichait jamais ;
       • payment_type restant indéfini, un bureau en facturation différée ne se
         voyait jamais proposer le paiement sur compte ;
       • le site résolu étant absent, l'adresse et la tournée du payload
         retombaient sur celles du bureau.

     Le seuil est désormais évalué ICI, avec la formule utilisée à la création
     de commande — c'est la seule façon que l'aperçu et la facture ne divergent
     pas. Le sous-total transmis doit être celui APRÈS remises. */
  if ($m === 'POST' && $p === '/delivery-fees/quote') {
    $b = body();
    $r = row("SELECT id, level, free_delivery, always_charge, fee_amount, free_delivery_minimum, payment_type
                FROM ws_delivery_fee_rules WHERE active=1 AND (
                     (level='site'   AND site_id=?) OR (level='office' AND office_client_id=?) OR
                     (level='tour'   AND tour_id=?) OR (level='shop' AND shop_id=?) OR (level='global'))
               ORDER BY FIELD(level,'site','office','tour','shop','global') LIMIT 1",
             [$b['siteId'] ?? null, $b['officeClientId'] ?? null,
              // Le front envoie `tourneeId` ; on lisait `tourId` — la règle de
              // niveau TOURNÉE ne pouvait donc jamais s'appliquer à l'aperçu,
              // qui retombait silencieusement sur la règle boutique ou globale.
              $b['tourId'] ?? ($b['tourneeId'] ?? null), $b['shopId'] ?? null]);
    if (!$r) json_out(null);
    $sub     = (float) ($b['subtotal'] ?? 0);
    $freeMin = (float) $r['free_delivery_minimum'];
    $isFree  = !$r['always_charge'] && ($r['free_delivery'] || ($freeMin > 0 && $sub >= $freeMin));
    $fee     = $isFree ? 0.0 : (float) $r['fee_amount'];
    $site    = ($r['level'] === 'site' && !empty($b['siteId']))
               ? row("SELECT * FROM ws_office_delivery_sites WHERE id=? AND active=1", [(int) $b['siteId']])
               : null;
    json_out([
      'fee_amount'                => round($fee, 2),
      'free_delivery'             => $isFree,              // décision pour CETTE commande
      'always_charge'             => (bool) $r['always_charge'],
      'free_delivery_minimum'     => $freeMin,
      'amount_remaining_for_free' => ($freeMin > 0 && $sub < $freeMin) ? round($freeMin - $sub, 2) : 0,
      'payment_type'              => $r['payment_type'] ?: 'immediate',
      'resolved_level'            => $r['level'],
      'site'                      => $site ?: null,
    ]);
  }

  /* ── Orders ── (tout est calculé serveur depuis la base : prix, promo 4+1,
       bon de réduction, frais de livraison, paiement différé B2B, liaison bureau) */
  if ($m === 'POST' && $p === '/orders') {
   // TOUT le handler est sous garde : n'importe quel échec (table manquante,
   // colonne inconnue, données inattendues) renvoie un JSON {error, detail}
   // exploitable au lieu d'un 500 muet. json_out() sort par exit → les
   // réponses normales ne passent jamais par le catch.
   try {
    $b = body();
    $shop = $b['shopId'] ?? null; $basket = $b['basket'] ?? [];
    if (!$shop || !is_array($basket) || !count($basket)) json_out(['error' => 'shopId et basket requis'], 400);

    /* ── IDEMPOTENCE ──────────────────────────────────────────────────────────
       Une clé stable par TENTATIVE de commande. Si elle est déjà connue, on
       renvoie la commande existante au lieu d'en créer une seconde.
       Constaté en test : trois commandes identiques pour un seul panier — la
       commande était enregistrée, mais l'appelant recevait une erreur APRÈS
       l'enregistrement, donc il recliquait. Le même scénario survient sans
       aucun bug : coupure réseau sur la réponse, onglet rechargé, double-clic.
       Sans cette garde, chaque tentative recompte le bon et redécrémente le
       stock. ── */
    $reqKey = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($b['requestKey'] ?? ''));
    $reqKey = $reqKey !== '' ? mb_substr($reqKey, 0, 64) : null;
    if ($reqKey !== null && col_exists('ws_orders', 'request_key')) {
      $dejaVu = row("SELECT id, order_ref, total FROM ws_orders WHERE request_key = ? LIMIT 1", [$reqKey]);
      if ($dejaVu) {
        json_out(['ok' => true, 'orderId' => (int) $dejaVu['id'], 'orderRef' => $dejaVu['order_ref'],
                  'total' => (float) $dejaVu['total'], 'deduplique' => true]);
      }
    } else {
      $reqKey = null;   // colonne non migrée : on n'écrit rien
    }
    $mode = $b['mode'] ?? 'collect';
    $dl = is_array($b['delivery'] ?? null) ? $b['delivery'] : [];
    $note = isset($b['note']) ? mb_substr((string) $b['note'], 0, 1000) : null;  // note commande
    // Facturation : « Demander une facture nominative » → invoice:{requested,vat,po}.
    $inv          = is_array($b['invoice'] ?? null) ? $b['invoice'] : null;
    $invRequested = ($inv && !empty($inv['requested'])) ? 1 : 0;
    $poNumber     = ($inv && isset($inv['po']) && $inv['po'] !== '') ? mb_substr((string) $inv['po'], 0, 100) : null;
    $invVat       = ($inv && isset($inv['vat']) && $inv['vat'] !== '') ? mb_substr((string) $inv['vat'], 0, 40) : null;

    // Compatibilité avec le payload du front (imbriqué / snake_case) : on normalise.
    /* L'identité vient de la SESSION (Authorization), JAMAIS du corps — même
       verrou que les réservations de stock : un customerId déclaré permettait
       de commander AU NOM d'un autre client (commande dans ses achats, ses
       bons nominatifs consommés). Sans jeton valide : commande INVITÉ. */
    $b['customerId']   = auth_uid() ?: null;
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
    //    Prix facturé = SOURCE UNIQUE, la même que l'affichage catalogue :
    //    ws_products.price, par prix_produits(). Règle go-live « vraies données
    //    ou bug » : plus AUCUN repli — ni sur une table externe, ni sur les
    //    facteurs de portion inventés (×0.27/0.52/0.15).
    //    Produit sans prix → commande REFUSÉE (409) avec le nom du produit ;
    //    portion sans prix ERP de portion → idem. Le client ne paie jamais un
    //    montant que la boutique n'a pas réellement fixé.
    $subtotal = 0; $lines = [];
    // Assortiment du bureau du client connecté : revérifié ligne par ligne.
    $offCmd = office_assortiment(office_du_client(auth_uid()));
    $storePrices = prix_produits(array_map(static fn($it) => (int) ($it['productId'] ?? 0), $basket), (int) $shop);
    $portPx = erp_portion_options($shop, array_map(static fn ($it2) => (int) ($it2['productId'] ?? 0), $basket));
    foreach ($basket as $it) {
      // cat_id / sub_cat_id : le périmètre d'une promo ERP raisonne par
      // CATÉGORIE (« 4 Quarts + 1 Gratuit » vise « Tartes – Ø 28 cm »), pas
      // par un drapeau produit. Sans eux, aucune ligne ne serait éligible.
      $p2 = row("SELECT p.id, p.name, p.cross_portion, p.cat_id, p.sub_cat_id
                   FROM ws_products p
                  WHERE p.id=? AND p.active=1", [$it['productId'] ?? 0]);
      if (!$p2) continue;
      // Gamme saisonnière : refus à la DATE de retrait/livraison. Masquer au
      // catalogue ne suffit pas — un panier gardé en cache, un onglet resté
      // ouvert ou un appel direct passeraient sans ce contrôle.
      if (!product_available_on($p2['id'], $b['deliveryDate'] ?? null, $shop)) {
        json_out(['error' => '« ' . trim((string) $p2['name']) . ' » n\'est pas disponible à la date choisie'
          . ' — retirez-le du panier ou changez de date.'], 409);
      }
      if ($offCmd && $offCmd['mode'] === 'custom' && !isset($offCmd['ids'][(int) $p2['id']])) {
        json_out(['error' => '« ' . trim((string) $p2['name']) . ' » n\'est pas proposé à votre bureau — retirez-le du panier.'], 409);
      }
      if (!isset($storePrices[(int) $p2['id']])) {
        json_out(['error' => 'Prix indisponible pour « ' . trim((string) $p2['name'])
          . ' » dans cette boutique — retirez-le du panier ou réessayez plus tard.'], 409);
      }
      $unit = (float) $storePrices[(int) $p2['id']];
      $portion2 = mb_strtolower(trim((string) ($it['portion'] ?? '')));
      if ($portion2 !== '' && $portion2 !== 'entier') {
        $unitP = null;
        foreach (($portPx[(int) $p2['id']] ?? []) as $c2) {
          if ($c2['v'] === $portion2 && $c2['price'] !== null) { $unitP = (float) $c2['price']; break; }
        }
        if ($unitP === null) {
          json_out(['error' => 'Prix de portion indisponible pour « ' . trim((string) $p2['name'])
            . ' » — choisissez la pièce entière ou retirez la ligne.'], 409);
        }
        $unit = $unitP;
      }
      $qty = max(1, (int) ($it['qty'] ?? 1));
      /* SUPPLÉMENTS DE FORMULE. Le panier affiche « + 5,00 € » pour un choix
         majoré, mais la commande ne facturait que le prix du produit
         déclencheur : ni le supplément du choix (ws_bundle_slot_choices.delta),
         ni le modificateur de la formule (ws_bundles.price_modifier) n'entraient
         dans le sous-total. Le client voyait un montant et en payait un autre —
         plus bas — et la boutique préparait un produit majoré sans l'encaisser.
         Les deux sont désormais relus EN BASE, jamais reçus du panier. */
      $comp = bundle_compose($p2['id'], $it['bundleId'] ?? null,
                             is_array($it['bundleSlots'] ?? null) ? $it['bundleSlots'] : []);
      $suppl = $comp['modifier'];
      foreach ($comp['choices'] as $c3) $suppl += $c3['delta'];
      $suppl = round($suppl, 2);
      // Le panier a composé un menu mais RIEN n'a survécu à la validation : la
      // commande partirait sans ses composants, sans bruit. On trace — seule
      // façon de voir un écart catalogue/commande sans attendre une réclamation.
      if (!$comp['choices'] && !empty($it['bundleSlots']))
        error_log('[ws] menu non validé — produit ' . (int) $p2['id']
                  . ' formule ' . (int) ($it['bundleId'] ?? 0)
                  . ' choix ' . json_encode($it['bundleSlots']));
      /* BUNDLE ERP choisi dans la fiche : le panier n'envoie qu'un identifiant,
         le bundle est RE-RÉSOLU ici pour cette boutique (mêmes prix que
         l'affichage). La ligne mère porte le NOM du bundle et son PRIX ; les
         autres articles deviennent des lignes filles à 0 (« Inclus dans … »),
         comme les composants d'un menu : la boutique sait quoi préparer, la
         compta lit le prix une seule fois. Un bundle qui n'est plus servi ou
         plus tarifé ⇒ 409, jamais un prix deviné. */
      $nomB = null;
      if (!empty($it['bundleOfferId']) && function_exists('erp_bundle_offre_pour')) {
        $idsB2 = [(int) $p2['id']];
        foreach ((array) erp_bundles_brut($shop) as $bb) if ($bb['id'] === (int) $it['bundleOfferId'])
          foreach ($bb['items'] as $bi) $idsB2[] = $bi['pid'];
        $idsB2 = array_values(array_unique($idsB2));
        $nomsB2 = []; foreach (rows("SELECT id, name FROM ws_products WHERE id IN (" . implode(',', array_fill(0, count($idsB2), '?')) . ")", $idsB2) as $nb) $nomsB2[(int) $nb['id']] = (string) $nb['name'];
        $offB = erp_bundle_offre_pour($shop, (int) $p2['id'], (int) $it['bundleOfferId'],
                                      prix_produits($idsB2, (int) $shop), erp_portion_options($shop, $idsB2), $nomsB2);
        if (!$offB) {
          json_out(['error' => 'Le bundle choisi pour « ' . trim((string) $p2['name'])
            . ' » n\'est plus proposé dans cette boutique — retirez-le du panier et rajoutez le produit.'], 409);
        }
        $unit = (float) $offB['price']; $suppl = 0.0; $nomB = $offB['name'] !== '' ? $offB['name'] : $p2['name'];
        $filles = [];
        foreach ($offB['items'] as $li) {
          $lab = $li['name'] . ($li['portionLabel'] ? ', ' . $li['portionLabel'] : '');
          if ((int) $li['productId'] === (int) $p2['id'] && !$li['portion'] && (int) $li['qty'] === 1) continue; // c'est la mère
          for ($k = 0; $k < (int) $li['qty']; $k++) $filles[] = ['product_id' => (int) $li['productId'], 'label' => $lab, 'delta' => 0];
        }
        $comp = ['modifier' => 0, 'choices' => $filles];
        $it['portion'] = null;   // le bundle fixe ses portions ; la mère est le produit entier
      }
      $subtotal += ($unit + $suppl) * $qty;
      // `options` = composition du menu (formule, choix de chaque emplacement,
      // suppléments). La ligne était reconstruite depuis le produit ERP et
      // perdait cette composition en chemin : c'est ICI qu'elle disparaissait,
      // avant même l'écriture. Le prix, lui, reste celui résolu serveur.
      // `bundleSlots` = les choix du menu, par IDENTIFIANT (emplacement → choix)
      // et non par libellé : c'est le serveur qui résoudra les produits. La
      // ligne était reconstruite depuis le produit ERP et perdait ces choix en
      // chemin — c'est ICI que la composition du menu disparaissait, avant même
      // l'écriture. Le prix, lui, reste celui résolu serveur.
      // `unit` = prix de la ligne mère : le produit + le modificateur de la
      // formule. Les suppléments par choix, eux, restent PORTÉS PAR LEUR LIGNE
      // (voir l'écriture des composants) — c'est là qu'ils sont lisibles.
      $lines[] = ['productId' => $p2['id'], 'name' => $nomB ?? $p2['name'], 'qty' => $qty,
                  'unit' => round($unit + $comp['modifier'], 2),
                  'portion' => $it['portion'] ?? null, 'cross' => (int) $p2['cross_portion'],
                  'id' => (int) $p2['id'], 'cat_id' => (int) ($p2['cat_id'] ?? 0), 'sub_cat_id' => (int) ($p2['sub_cat_id'] ?? 0),
                  'bundleChoices' => $comp['choices'],
                  'note' => isset($it['note']) ? mb_substr((string) $it['note'], 0, 255) : null];
    }
    if (!count($lines)) json_out(['error' => 'aucun produit valide'], 400);
    $subtotal = round($subtotal, 2);

    /* 2. Promo croisée X+Y : les Y les moins chers offerts par tranche de X.
       MÊME résolveur que l'affichage (cross_portion_rule) — c'est ce qui
       garantit que le montant annoncé au panier est celui qu'on débite. Le
       périmètre vient de la règle : drapeau ws_products.cross_portion pour la
       règle locale, listes produits/catégories pour la règle ERP. */
    $promo = 0;
    $rule = cross_portion_rule($shop);
    if ($rule && (int) $rule['buy'] > 0) {
      $units = [];
      foreach ($lines as $l) {
        if (!cross_portion_eligible($rule, $l['id'] ?? 0, $l['cat_id'] ?? 0, $l['sub_cat_id'] ?? 0, $l['cross'])) continue;
        for ($k = 0; $k < $l['qty']; $k++) $units[] = $l['unit'];
      }
      if (count($units) >= (int) $rule['threshold']) {
        sort($units); // les moins chers d'abord
        $freeCount = intdiv(count($units), (int) $rule['buy']) * (int) $rule['free'];
        for ($k = 0; $k < $freeCount && $k < count($units); $k++) $promo += $units[$k];
      }
    }
    $promo = round($promo, 2);

    // 2-bis. Remise webshop paramétrée par boutique — colonnes non garanties
    // par les migrations : absentes → pas de remise (jamais un 500).
    $webshopDisc = 0;
    $sd = (col_exists('shops', 'discount_type') && col_exists('shops', 'discount_value'))
      ? row("SELECT discount_type AS t, discount_value AS v FROM shops WHERE id=?" .
            (col_exists('shops', 'webshop_enabled') ? " AND webshop_enabled=1" : ""), [$shop])
      : null;
    if ($sd && (float) $sd['v'] > 0) {
      $baseW = $subtotal - $promo;
      $webshopDisc = $sd['t'] === 'fixed' ? min($baseW, (float) $sd['v']) : round($baseW * (float) $sd['v']) / 100;
    }
    $webshopDisc = round($webshopDisc, 2);

    // 3. Bon de réduction (lu via la vue ws_vouchers = modèle ERP, canal WS) — validé serveur.
    $voucherCode = null; $voucherDisc = 0; $voucherFreeDelivery = false;
    if (!empty($b['voucher'])) {
      // Verrou boutique : un bon émis par une boutique (shop_id non NULL) ne
      // s'applique QUE chez elle ; shop_id NULL = bon marque, valable partout.
      $v = row("SELECT code, type, value, min_order FROM ws_vouchers
                 WHERE code=? AND active=1 AND (expires_at IS NULL OR expires_at>NOW())
                   AND (max_uses IS NULL OR used_count<max_uses)
                   AND (shop_id IS NULL OR shop_id = ?) LIMIT 1",
               [strtoupper(trim($b['voucher'])), $shop]);
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
      // Limite PAR CLIENT (usage_limit_per_customer) + périmètre produit (0042)
      // — mêmes règles que la prévisualisation, appliquées aux LIGNES re-tarifées
      // serveur ($lines) : le client ne peut pas influencer le montant remisé.
      $vScope = null;
      if ($v && $eligible) {
        $hasScopeC = col_exists('promotion_order_discount', 'scope_id_product');
        $vScope = row("SELECT vco.id AS code_id, vc.usage_limit_per_customer" .
                      ($hasScopeC ? ", pod.scope_id_product, pod.scope_max_qty" : ", NULL AS scope_id_product, NULL AS scope_max_qty") . "
                        FROM voucher_code vco
                        JOIN voucher_campaign vc ON vc.id = vco.id_voucher_campaign
                        JOIN promotion_order_discount pod ON pod.id_promotion = vc.id_promotion
                       WHERE vco.code = ? LIMIT 1", [$v['code']]);
        if ($vScope && $vScope['usage_limit_per_customer'] !== null) {
          $cidC = isset($b['customerId']) && $b['customerId'] !== '' ? (int) $b['customerId'] : null;
          if ($cidC === null) { $eligible = false; }
          else {
            $usedC = row("SELECT COUNT(*) n FROM voucher_redemption WHERE id_voucher_code=? AND id_customer=? AND status='CONFIRMED'",
                         [$vScope['code_id'], $cidC]);
            if ((int) ($usedC['n'] ?? 0) >= (int) $vScope['usage_limit_per_customer']) $eligible = false;
          }
        }
      }
      if ($v && $baseV >= (float) $v['min_order'] && $eligible) {
        $voucherCode = $v['code'];
        if ($vScope && $vScope['scope_id_product'] !== null && $v['type'] !== 'free_delivery') {
          $unitsV = [];
          foreach ($lines as $lv) {
            if ((int) $lv['productId'] !== (int) $vScope['scope_id_product']) continue;
            for ($kv = 0; $kv < $lv['qty']; $kv++) $unitsV[] = (float) $lv['unit'];
          }
          sort($unitsV);
          $capV = $vScope['scope_max_qty'] !== null ? min((int) $vScope['scope_max_qty'], count($unitsV)) : count($unitsV);
          $baseScopeV = array_sum(array_slice($unitsV, 0, $capV));
          // Produit absent du panier -> remise 0 (l'aperçu a déjà prévenu).
          $voucherDisc = $v['type'] === 'percent' ? round($baseScopeV * (float) $v['value']) / 100
                       : min((float) $v['value'], $baseScopeV);
          if ($baseScopeV <= 0) $voucherCode = null;   // rien à remiser -> pas de redemption comptée
        } else {
          $voucherDisc = $v['type'] === 'percent' ? round($baseV * (float) $v['value']) / 100
                       : ($v['type'] === 'fixed' ? (float) $v['value'] : 0);
        }
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
    $family  = payment_family($paymentMethod);
    $allowed = allowed_methods($shop, $profile);
    // Boutique qui n'offre RIEN à ce profil : la commande est refusée. Sans ce
    // test, le front affichait bien « moyens indisponibles » mais la commande
    // partait quand même — le contrôle ci-dessous était sauté dès que le moyen
    // était vide (voir juste en dessous), et une commande sans moyen de paiement
    // se retrouvait en base, encaissable par personne.
    if (!$allowed) {
      json_out(['error' => "Aucun moyen de paiement n'est disponible pour cette boutique",
                'profile' => $profile], 409);
    }
    // Moyen absent ou vide : refus explicite. On ne retombe SURTOUT pas sur un
    // défaut ('cash'), qui transformerait « le client n'a rien choisi » en
    // « paiement en boutique » — une créance inventée par le serveur.
    if ($family === '') {
      json_out(['error' => 'Moyen de paiement requis',
                'profile' => $profile, 'allowed' => $allowed], 400);
    }
    // Livraison : le paiement en boutique est refusé côté SERVEUR, pas seulement
    // masqué dans la liste — une liste filtrée n'empêche personne de poster le
    // moyen écarté.
    if ($mode === 'delivery' && $family === 'shop') {
      json_out(['error' => "Le paiement en boutique n'est pas disponible pour une livraison",
                'profile' => $profile, 'allowed' => array_values(array_diff($allowed, ['shop']))], 400);
    }
    if (!in_array($family, $allowed, true)) {
      json_out(['error' => 'Moyen de paiement non autorisé pour ce profil',
                'profile' => $profile, 'allowed' => $allowed], 400);
    }
    // Contact visiteur (guest) — enregistré seulement si pas de compte.
    $guestEmail = empty($b['customerId']) ? (isset($b['email']) ? mb_substr((string) $b['email'], 0, 190) : null) : null;
    $guestName  = empty($b['customerId']) ? (mb_substr(trim(($b['customer']['firstName'] ?? '') . ' ' . ($b['customer']['lastName'] ?? '')), 0, 190) ?: null) : null;
    $guestPfx = '+32'; $guestPhone = null;
    if (empty($b['customerId'])) {
      [$guestPfx, $guestPhone] = norm_phone($b['customer']['phonePrefix'] ?? ($b['phonePrefix'] ?? '+32'), $b['customer']['phone'] ?? ($b['phone'] ?? ''));
      if ($guestPhone === '') { $guestPhone = null; $guestPfx = null; }
    }

    // 4-quater-avant. Site absent du payload mais BUREAU connu : le front peut
    //   avoir une liste de sites vide au moment du checkout (cache, liaison
    //   bureau↔site posée après la connexion…). On résout alors le site par
    //   défaut du bureau CÔTÉ SERVEUR — sinon la commande s'enregistrait sans
    //   office_delivery_site_id et n'était rattachable à aucune tournée au
    //   back-office (groupe « Hors tournée »).
    if ($mode === 'delivery' && empty($dl['siteId']) && $officeClientId) {
      // Préférence : un site du bureau AVEC tournée ; sinon, le bâtiment situé
      // à l'ADRESSE du bureau qui porte une tournée (cas : la ligne de liaison
      // n'a pas hérité de la tournée du bâtiment) ; en dernier recours un site
      // du bureau sans tournée — le contrôle d'éligibilité expliquera le refus.
      $autoSite = row("SELECT id, name FROM ws_office_delivery_sites
                        WHERE office_client_id=? AND active=1 AND tournee_id IS NOT NULL
                        ORDER BY is_default DESC, id LIMIT 1", [(int) $officeClientId]);
      if (!$autoSite) {
        $offA = row("SELECT address FROM ws_offices WHERE id=?", [(int) $officeClientId]);
        if ($offA && trim((string) $offA['address']) !== '') {
          $nOff = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $offA['address'])));
          $autoSite = row("SELECT id, name FROM ws_office_delivery_sites
                            WHERE active=1 AND tournee_id IS NOT NULL
                              AND LOWER(REGEXP_REPLACE(TRIM(COALESCE(address,'')), '[[:space:]]+', ' '))=?
                            ORDER BY id LIMIT 1", [$nOff]);
        }
      }
      if (!$autoSite) {
        $autoSite = row("SELECT id, name FROM ws_office_delivery_sites
                          WHERE office_client_id=? AND active=1
                          ORDER BY is_default DESC, id LIMIT 1", [(int) $officeClientId]);
      }
      if ($autoSite) {
        $dl['siteId'] = (int) $autoSite['id'];
        if (empty($dl['siteName'])) $dl['siteName'] = $autoSite['name'];
      }
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
      //     NB : $tblExists (closure des sections franchisé) N'EXISTE PAS ici —
      //     l'appeler valait « Value of type null is not callable » (500).
      $hasDefT = (bool) row("SELECT 1 x FROM information_schema.tables
                              WHERE table_schema=DATABASE() AND table_name='ws_product_stock_defaults'");
      $wdStock = (int) date('N', strtotime($stockDate));
      $defQty = function ($pid) use ($hasDefT, $shop, $wdStock, $mode) {
        if (!$hasDefT || !$pid) return null;
        $d = row("SELECT qty FROM ws_product_stock_defaults
                   WHERE shop_id=? AND product_id=? AND weekday=? AND mode=? LIMIT 1",
                 [$shop, (int) $pid, $wdStock, $mode]);
        return $d !== null ? (int) $d['qty'] : null;
      };
      // Le client CONVERTIT son maintien en vente : on relâche d'abord SES
      // réservations pour ce créneau, sinon le contrôle ci-dessous les
      // compterait contre lui (qty_reserved inclut sa propre tenue) et sa
      // commande serait refusée pour un stock qu'il tient lui-même.
      $buyerId = (int) ($b['customerId'] ?? ($b['customer']['id'] ?? 0));
      if ($buyerId && row("SELECT 1 AS x FROM information_schema.tables
                            WHERE table_schema = DATABASE() AND table_name = 'ws_stock_reservation' LIMIT 1")) {
        $mySlots = rows("SELECT DISTINCT product_id, shop_id, date, mode FROM ws_stock_reservation
                          WHERE customer_id=? AND shop_id=? AND date=? AND mode=? AND released_at IS NULL",
                        [$buyerId, $shop, $stockDate, $mode]);
        if ($mySlots) {
          q("UPDATE ws_stock_reservation SET released_at=NOW()
              WHERE customer_id=? AND shop_id=? AND date=? AND mode=? AND released_at IS NULL",
            [$buyerId, $shop, $stockDate, $mode]);
          foreach ($mySlots as $ms) {
            $sum = (int) (row("SELECT COALESCE(SUM(qty),0) AS q FROM ws_stock_reservation
                                WHERE product_id=? AND shop_id=? AND date=? AND mode=?
                                  AND released_at IS NULL AND expires_at > NOW()",
                              [$ms['product_id'], $ms['shop_id'], $ms['date'], $ms['mode']])['q'] ?? 0);
            q("UPDATE ws_product_stock SET qty_reserved=?
                WHERE product_id=? AND shop_id=? AND date=? AND (mode=? OR mode IS NULL)",
              [$sum, $ms['product_id'], $ms['shop_id'], $ms['date'], $ms['mode']]);
          }
        }
      }
      foreach ($lines as $l) {
        $st = row("SELECT GREATEST(0, qty_total - qty_reserved - qty_sold) AS avail
                     FROM ws_product_stock
                    WHERE product_id=? AND shop_id=? AND date=? AND (mode=? OR mode IS NULL)
                    LIMIT 1 FOR UPDATE", [$l['productId'], $shop, $stockDate, $mode]);
        if ($st !== null && $l['qty'] > (int) $st['avail']) {
          $pdo->rollBack();
          json_out(['error' => 'Stock insuffisant', 'product' => $l['name'], 'available' => (int) $st['avail']], 409);
        }
        // Pas de ligne du jour : le MINIMUM hebdomadaire du produit (jour ISO ×
        // canal) fait foi — « 10 par jour » = 10 vendables, pas illimité.
        if ($st === null) {
          $dq = $defQty($l['productId'] ?? null);
          if ($dq !== null && $l['qty'] > $dq) {
            $pdo->rollBack();
            json_out(['error' => 'Stock insuffisant', 'product' => $l['name'], 'available' => $dq], 409);
          }
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
      //     INSERT DYNAMIQUE : seules les colonnes qui EXISTENT sur la table
      //     live sont écrites — une ws_orders au schéma plus ancien ne doit
      //     plus faire échouer le paiement (« Unknown column » → 500). La
      //     migration 0031 aligne le schéma ; ce garde-fou protège l'intervalle.
      // Les colonnes INT ne reçoivent que des valeurs NUMÉRIQUES : le front
      // envoie des ids de créneau symboliques (ex. « s-09 ») → slot_id=NULL
      // (le libellé humain reste dans slot_label). MySQL strict refusait
      // l'INSERT (« Incorrect integer value 's-09' for column slot_id »).
      $intOrNull = fn ($v) => (isset($v) && is_numeric($v)) ? (int) $v : null;
      $ordVals = [
        'order_ref' => $ref, 'shop_id' => $shop, 'customer_id' => $intOrNull($b['customerId'] ?? null),
        /* LA FICHE ERP, GELÉE SUR LA COMMANDE. Une commande est une pièce
           historique : elle doit garder la fiche contre laquelle elle a été
           passée, même si le client est rattaché à une autre plus tard. C'est
           la seule des neuf tables qui justifie une copie plutôt qu'une
           résolution par la correspondance. NULL quand le lien n'est pas encore
           connu — et c'est exact : personne ne sait, à cet instant, à quelle
           fiche ce client correspond. La décision de rattachement reprendra
           ces commandes-là. */
        'customer_erp_id' => (function () use ($b, $intOrNull) {
          $cid = $intOrNull($b['customerId'] ?? null);
          return ($cid && function_exists('erp_map_id')) ? erp_map_id($cid) : null;
        })(),
        'guest_email' => $guestEmail, 'guest_name' => $guestName, 'guest_phone' => $guestPhone, 'guest_phone_prefix' => $guestPfx,
        'mode' => $mode, 'status' => $orderStatus,
        // delivery_date : jour envoyé par le front, sinon JOUR MÊME — plus
        // jamais de NULL (les filtres par jour du BO reposent dessus).
        'slot_id' => $intOrNull($b['slotId'] ?? null), 'slot_label' => $b['slotLabel'] ?? null,
        'delivery_date' => ($b['deliveryDate'] ?? null) ?: date('Y-m-d'),
        'subtotal' => $subtotal, 'promo_amount' => $promo, 'webshop_discount' => $webshopDisc,
        'voucher_code' => $voucherCode, 'voucher_discount' => $voucherDisc, 'total' => $total,
        'payment_method' => $paymentMethod, 'payment_status' => 'pending', 'lang' => $b['lang'] ?? 'fr', 'note' => $note,
        'delivery_mode' => $mode === 'delivery' ? 'office_delivery' : 'collect',
        'office_client_id' => $intOrNull($officeClientId), 'office_delivery_site_id' => $intOrNull($dl['siteId'] ?? null),
        'office_delivery_site_name' => $dl['siteName'] ?? null, 'tournee_stop_id' => $intOrNull($dl['tourneeStopId'] ?? null),
        'payment_type' => $paymentType, 'delivery_fee_applied' => $feeApplied, 'delivery_fee_amount' => $feeAmount,
        'free_delivery_minimum' => $freeMin, 'po_number' => $poNumber, 'invoice_requested' => $invRequested, 'invoice_vat' => $invVat,
        // Source AUTOMATIQUE : toute commande passée ici vient du webshop.
        'source' => 'webshop',
        // Ticket de caisse FISCAL édité à la validation (caisse certifiée) :
        // stocké tel qu'il est fourni — jamais fabriqué par le webshop. Colonnes
        // ignorées si absentes (col_exists ci-dessous). Ordre 0085.
        'fiscal_ticket_no'  => isset($b['fiscalTicketNo'])  && $b['fiscalTicketNo']  !== '' ? mb_substr((string) $b['fiscalTicketNo'], 0, 40) : null,
        'fiscal_ticket_url' => isset($b['fiscalTicketUrl']) && $b['fiscalTicketUrl'] !== '' ? mb_substr((string) $b['fiscalTicketUrl'], 0, 255) : null,
        // Clé d'idempotence de la tentative (voir garde en tête du handler).
        'request_key' => $reqKey,
      ];
      $ordIns = [];
      foreach ($ordVals as $c => $v) if (col_exists('ws_orders', $c)) $ordIns[$c] = $v;
      q("INSERT INTO ws_orders (" . implode(',', array_keys($ordIns)) . ")
           VALUES (" . implode(',', array_fill(0, count($ordIns), '?')) . ")", array_values($ordIns));
      $oid = $pdo->lastInsertId();
      // Composants de menu : écrits seulement si la migration 0055 est passée.
      // Sans la colonne, on ne saurait pas les distinguer d'une ligne vendue et
      // ils fausseraient les compteurs de pièces — mieux vaut ne rien écrire.
      $hasParentCol = col_exists('ws_order_lines', 'parent_line_id');
      foreach ($lines as $l) {
        $pidL = is_numeric($l['productId'] ?? null) ? (int) $l['productId'] : null;
        q("INSERT INTO ws_order_lines (order_id, product_id, product_name, qty, unit_price, `portion`, note) VALUES (?,?,?,?,?,?,?)",
          [$oid, $pidL, $l['name'], $l['qty'], $l['unit'], $l['portion'], $l['note']]);
        /* COMPOSANTS DU MENU — UNE LIGNE PAR PRODUIT. Les choix du menu étaient
           jetés : la commande n'enregistrait que le produit déclencheur, et la
           boutique ne savait pas quoi préparer.
           Chaque choix devient une ligne rattachée à sa mère (parent_line_id,
           migration 0055) ; il porte son produit depuis la migration 0057, avec
           repli par le NOM pour les choix pas encore rattachés.
           PRIX DE LA LIGNE = LE SUPPLÉMENT DU CHOIX, ET LUI SEUL. Un composant
           compris dans la formule reste à 0 — l'y reporter compterait le chiffre
           d'affaires deux fois. Mais un choix majoré (« Cake Nature + 5,00 € »)
           doit porter ses 5 € SUR SA LIGNE : c'est ce produit-là qui les génère,
           et c'est là qu'on les lit, en boutique comme en compta. La somme des
           lignes reste égale au total : le supplément a quitté la ligne mère. */
        $parentId = (int) $pdo->lastInsertId();
        $choices = (array) ($l['bundleChoices'] ?? []);
        if ($choices && $hasParentCol) {
          foreach ($choices as $c4) {
            $lab2 = (string) ($c4['label'] ?? '');
            $cp = !empty($c4['product_id'])
              ? row("SELECT id, name FROM ws_products WHERE id=? LIMIT 1", [(int) $c4['product_id']]) : null;
            if ($cp && $lab2 === '') $lab2 = (string) $cp['name'];
            if ($lab2 === '') continue;
            if (!$cp) $cp = row("SELECT id FROM ws_products WHERE name = ? AND active = 1 ORDER BY id LIMIT 1", [$lab2]);
            $dlt = round((float) ($c4['delta'] ?? 0), 2);
            q("INSERT INTO ws_order_lines (order_id, product_id, product_name, qty, unit_price, `portion`, note, parent_line_id)
                 VALUES (?,?,?,?,?,?,?,?)",
              [$oid, ($cp ? (int) $cp['id'] : null), $lab2, $l['qty'], $dlt, null,
               ($dlt > 0 ? 'Supplément de « ' . $l['name'] . ' »' : 'Inclus dans « ' . $l['name'] . ' »'),
               $parentId]);
          }
        }
        // Décrément du stock du jour : si aucune ligne de stock n'existe encore
        // pour ce produit/jour/mode, on la CRÉE en partant du MINIMUM
        // hebdomadaire (qty_total = défaut du jour, sinon 0) pour que la
        // vente soit tracée ET que le plafond « n par jour » reste appliqué.
        $stq = q("UPDATE ws_product_stock SET qty_sold = qty_sold + ?
            WHERE product_id=? AND shop_id=? AND date=? AND (mode=? OR mode IS NULL)",
          [$l['qty'], $l['productId'], $shop, $stockDate, $mode]);
        if ($stq->rowCount() === 0 && !empty($l['productId'])) {
          // On ne crée la ligne QUE si un minimum hebdomadaire existe : elle
          // porte alors un vrai plafond (« 10 par jour » = 10 vendables).
          //
          // Sans défaut, la créer avec qty_total = 0 rendait le produit
          // INVENDABLE dès sa première vente : la ligne existait désormais, et
          // le contrôle suivant lisait avail = GREATEST(0, 0 − 0 − 1) = 0 →
          // « Stock insuffisant » pour tous les clients suivants, jusqu'au
          // lendemain. Le produit comptait en plus comme une rupture dans
          // l'alerte de seuil bas.
          //
          // « Aucun stock déclaré » veut dire SANS PLAFOND, pas « zéro ». Les
          // deux ne doivent pas s'écrire de la même façon. La vente reste tracée
          // là où elle l'a toujours été — ws_orders et ws_order_lines ; qty_sold
          // ne compte que face à un stock déclaré, sinon il ne mesure rien.
          $dqIns = $defQty($l['productId']);
          if ($dqIns !== null) {
            q("INSERT INTO ws_product_stock (product_id, shop_id, date, mode, qty_total, qty_reserved, qty_sold, active)
                 VALUES (?,?,?,?,?,0,?,1)
                 ON DUPLICATE KEY UPDATE qty_sold = qty_sold + VALUES(qty_sold)",
              [$l['productId'], $shop, $stockDate, $mode, (int) $dqIns, $l['qty']]);
          }
        }
      }
      // 6c-bis. Cadeau « achat cumulé → produit cadeau » : si un code cadeau valide
      // est fourni, on ajoute une LIGNE 0 € (produit cadeau) et on marque la
      // progression consommée (idempotent). Silencieux si invalide (ne bloque pas
      // la commande — le front pré-valide via POST /promo/redeem). Pas de décrément
      // de stock pour le cadeau (freebie promotionnel).
      if (!empty($b['giftCode'])) {
        $giftRef = promo_customer_ref(!empty($b['customerId']) ? (int) $b['customerId'] : null,
                                      empty($b['customerId']) ? $guestEmail : null);
        $g = $giftRef ? promo_gift_row((string) $b['giftCode']) : null;
        if ($g && promo_gift_redeemable($g, $giftRef, (int) $shop, promo_now())['ok']) {
          $rp = row("SELECT id, name FROM ws_products WHERE id = ?", [$g['reward_product_id']]);
          if ($rp) {
            q("INSERT INTO ws_order_lines (order_id, product_id, product_name, qty, unit_price, `portion`, note)
                    VALUES (?,?,?,?,?,?,?)",
              [$oid, $rp['id'], $rp['name'], 1, 0, null, 'Cadeau — campagne ' . (int) $g['campaign_id']]);
            q("UPDATE ws_promo_progress SET redeemed_at = CURRENT_TIMESTAMP
                WHERE id = ? AND redeemed_at IS NULL", [$g['id']]);
          }
        }
      }
      if ($cap) q("UPDATE ws_slot_capacity SET current_orders = current_orders + 1, current_items = current_items + ? WHERE id=?", [$totalQty, $cap['id']]);
      // Usage du bon via le modèle ERP (ws_vouchers est désormais une vue non-inscriptible) :
      // incrément voucher_code.usage_count + redemption tracée (canal WS, idempotence request_key).
      if ($voucherCode) {
        // SUIVI du bon (compteur + redemption) : ENTOURÉ d'un try/catch dédié.
        // Une contrainte sur voucher_redemption (FK/NOT NULL/unique) ne doit
        // JAMAIS annuler la commande — la remise est déjà dans le total et dans
        // ws_orders.voucher_code/voucher_discount. En MySQL, un INSERT en échec
        // n'abandonne que SON instruction, pas la transaction : on log et on
        // poursuit le commit de la commande.
        try {
          $vref = row("SELECT vco.id AS code_id, vco.id_voucher_campaign AS campaign_id, vc.id_promotion AS promotion_id
                         FROM voucher_code vco JOIN voucher_campaign vc ON vc.id = vco.id_voucher_campaign
                        WHERE vco.code = ? LIMIT 1", [$voucherCode]);
          if ($vref) {
            q("UPDATE voucher_code SET usage_count = usage_count + 1 WHERE id = ?", [$vref['code_id']]);
            // id_shop est NOT NULL + FK -> franchisee_shop ; ws_shops.id = franchisee_shop.id.
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
        } catch (Throwable $ev) {
          error_log('[ws] suivi bon (voucher_redemption) échoué, commande conservée : ' . $ev->getMessage());
        }
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      // Plus JAMAIS de 500 muet au paiement : rollback + JSON exploitable
      // (le motif réel — ex. colonne manquante — est journalisé et renvoyé).
      error_log('POST /orders a échoué : ' . $e->getMessage());
      json_out(['error' => 'Commande non enregistrée — erreur serveur',
                'detail' => mb_substr($e->getMessage(), 0, 300)], 500);
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
   } catch (Throwable $e) {
    try { $pdo2 = db(); if ($pdo2->inTransaction()) $pdo2->rollBack(); } catch (Throwable $e2) {}
    error_log('POST /orders (garde globale) : ' . $e->getMessage());
    json_out(['error' => 'Commande non enregistrée — erreur serveur',
              'detail' => mb_substr($e->getMessage(), 0, 300)], 500);
   }
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
    // Canal d'inscription : `channel: 'pwa'` quand l'appel vient de l'API PWA
    // (qui créait le compte elle-même, en SQL direct, avec source_channel='pwa'
    // et pwa_user=1). Le drapeau reste, la table n'est plus écrite que d'ici.
    $canal = (($b['channel'] ?? '') === 'pwa') ? 'pwa' : 'webshop';
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Email invalide'], 400);
    if ($authM === 'email' && $mail === '')  json_out(['error' => 'Email requis'], 400);
    if ($authM === 'phone' && $phone === '') json_out(['error' => 'Téléphone requis'], 400);
    if ($mail === '' && $phone === '')       json_out(['error' => 'Email ou téléphone requis'], 400);
    // Mot de passe OBLIGATOIRE : un compte créé sans hachage restait
    // revendicable par la première personne passant par /auth/set-password
    // avec l'email/le téléphone d'autrui.
    if (strlen((string) ($b['password'] ?? '')) < 8)
      json_out(['error' => 'Mot de passe requis (8 caractères minimum).'], 400);
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
    /* CONNU DE LA BOUTIQUE ? Un client qui achète au comptoir depuis des années
       existe côté ERP — retrouvé par TÉLÉPHONE, la seule clé qui marche
       (8123 fiches sur 8154 en ont un, 575 seulement ont un e-mail). On ne
       bloque PAS l'inscription pour autant : il n'a pas de compte webshop,
       c'est bien un compte qu'il vient créer. On le SIGNALE, pour que l'écran
       puisse dire « nous vous connaissons » et que le franchisé sache que les
       deux fiches désignent la même personne. */
    $dejaErp = null;
    if ($phone !== '' && function_exists('erp_client_par_tel')) {
      try { $dejaErp = erp_client_par_tel($shopI ?? null, $phone); } catch (Throwable $e) { $dejaErp = null; }
    }
    if ($cl) {
      json_out(['error' => 'Ce compte existe déjà. Connectez-vous ou définissez votre mot de passe.', 'exists' => true], 409);
    }
    {
      // La boutique du compte vient de la boutique CONSULTÉE, transmise par le
      // front. Plus de devinette « boutique la plus fréquente en base » : ce
      // repli affectait chaque nouveau client à la boutique la plus peuplée —
      // il n'apparaissait jamais dans la console de SA boutique.
      $ms = is_numeric($b['shopId'] ?? null) ? (int) $b['shopId'] : null;
      if (!$ms || !row("SELECT 1 AS x FROM shops WHERE id=?", [$ms]))
        json_out(['error' => 'Boutique requise pour créer le compte.'], 400);
      // `locality` guardée par col_exists : le code peut être déployé une
      // requête avant que migrate.sh n'ait joué 0015 — pas de 500 pendant la fenêtre.
      $hasLoc = col_exists('client', 'locality');
      // preferred_shop_id posé EXPLICITEMENT, comme partout ailleurs : la
      // console franchisé cloisonne dessus, et un client créé sans lui
      // n'apparaît dans aucune boutique. Un trigger le fait aussi, mais on ne
      // confie pas la visibilité d'un client à un objet de base qui a déjà
      // disparu une fois (cf. trg_client_office_delivery_*).
      $hasPref = col_exists('client', 'preferred_shop_id');
      $hasPwa  = col_exists('client', 'pwa_user');
      q("INSERT INTO client (id_main_shop, " . ($hasPref ? "preferred_shop_id, " : "") . "email, phone, phone_prefix, phone_e164, name, surname, zip, " . ($hasLoc ? "locality, " : "") . "password_hash,
                             active, source_channel, webshop_user, " . ($hasPwa ? "pwa_user, " : "") . "preferred_auth_method)
         VALUES (?," . ($hasPref ? "?," : "") . "?,?,?,?,?,?,?," . ($hasLoc ? "?," : "") . "?,1,?,?," . ($hasPwa ? "?," : "") . "?)",
        array_merge(
          [$ms], $hasPref ? [$ms] : [],
          [($mail ?: null), ($phone ?: null), ($phone !== '' ? $pfx : null), ($e164 ?: null), $first, $last, $zip],
          $hasLoc ? [$locality] : [],
          [$hash, $canal, $canal === 'pwa' ? 0 : 1],
          $hasPwa ? [$canal === 'pwa' ? 1 : 0] : [],
          [$authM]));
      $id = db()->lastInsertId();
      /* MIROIR ERP — la fiche existe côté réseau dès l'inscription, avec son
         ASSIGNATION boutique (la ligne qui manque aux fiches historiques et
         sans laquelle la fiche unitaire de l'ERP répond 404).
         Volontairement APRÈS la création locale et sans test de retour : le
         compte webshop est déjà valide, un ERP en panne ne doit pas empêcher
         quelqu'un de s'inscrire. L'échec est journalisé, rien de plus. */
      /* PAS DE MIROIR SI LA BOUTIQUE CONNAÎT DÉJÀ CE NUMÉRO. Le miroir créait
         une SECONDE fiche pour quelqu'un qui en avait déjà une — un doublon
         dans le fichier client, à chaque inscription d'un habitué du comptoir.
         Deux cas, deux traitements :
           • fiche existante → on ne crée rien ; le rattachement (0099) la
             proposera, et c'est le franchisé qui tranchera. erp_client_id
             reste NULL jusque-là : le lien doit être VALIDÉ, pas supposé ;
           • personne inconnue → on crée, et on mémorise l'id sans arbitrage :
             cette fiche-là, c'est nous qui venons de la faire, il n'y a aucune
             question d'identité à trancher. */
      if (!$dejaErp && function_exists('erp_client_creer')) {
        try {
          /* `name` = PRÉNOM et `surname` = NOM : c'est le sens réel côté ERP,
             vérifié sur les 8156 fiches (name : Amandine, Claude… / surname :
             COLARD, DURAND…). L'inverse paraît plus naturel en anglais et
             c'est exactement l'erreur qui avait été commise dans le mappage
             de lecture. */
          $creee = erp_client_creer($shopI ?? null,
                     ['phone' => $phone, 'name' => $first, 'surname' => $last, 'zip' => $zip]);
          /* L'IDENTIFIANT DE LA FICHE, MÉMORISÉ. Il était jeté : le compte et
             sa fiche ERP existaient tous les deux sans que rien ne dise qu'ils
             désignent la même personne. C'est ce lien que lit la décision de
             rattachement (0099), et c'est lui qu'exigera toute écriture vers
             l'ERP le jour où leur PATCH acceptera autre chose que `status`. */
          if (is_array($creee) && !empty($creee['id'])) {
            if (col_exists('client', 'erp_client_id'))
              q("UPDATE client SET erp_client_id=? WHERE id=?", [(int) $creee['id'], $id]);
            /* La CORRESPONDANCE, qui survivra à la table client (0103). Origine
               « inscription » : cette fiche, c'est nous qui venons de la créer,
               aucun arbitrage n'a été nécessaire. */
            if (function_exists('erp_map_poser')) erp_map_poser($id, (int) $creee['id'], 'inscription');
          }
        } catch (Throwable $e) { error_log('[ws] miroir ERP client: ' . $e->getMessage()); }
      }
    }
    json_out(['user' => user_payload($id), 'token' => sign_token(['id' => (int) $id, 'exp' => time() + 30 * 86400]),
              // Client déjà connu de la boutique (achats au comptoir) : l'écran
              // peut l'accueillir en conséquence. Jamais de donnée personnelle
              // au-delà du prénom — on confirme une reconnaissance, on ne
              // divulgue pas une fiche à qui saisit un numéro.
              'connuEnBoutique' => $dejaErp ? ['depuis' => true, 'prenom' => (string) ($dejaErp['prenom'] ?? '')] : null], 201);
  }

  /* ── RATTACHEMENT d'un compte webshop à une fiche client de la boutique ────
     Le client qui achète au comptoir depuis des années a une fiche ERP ; son
     compte webshop est neuf. Relier les deux lui rend son historique d'achats
     et sa fidélité.

     Ce n'est PAS automatique, et ça ne peut pas l'être : le webshop n'a aucune
     vérification d'identité (ni OTP SMS ni e-mail — cf. l'avertissement sur
     /auth/set-password). Le téléphone est la clé de recherche, pas une preuve
     de possession. Une demande est donc déposée, et le FRANCHISÉ tranche : il
     connaît ses clients, c'est la seule vérification dont on dispose.

     La cible n'est jamais reçue de l'appelant. Le serveur la retrouve à partir
     du téléphone DU COMPTE : un identifiant de fiche transmis par le navigateur
     laisserait choisir la fiche à s'attribuer. ── */

  // Le compte connecté, dans la forme que erp_link_* compare. Renvoie null si
  // le jeton ne désigne aucun client actif.
  $lienCompte = function () {
    $uid = auth_uid();
    if (!$uid) return null;
    $c = row("SELECT id, name, surname, email, phone, phone_e164, zip,
                     " . (col_exists('client', 'preferred_shop_id') ? 'preferred_shop_id' : 'NULL') . " AS pref,
                     id_main_shop,
                     " . (col_exists('client', 'erp_client_id') ? 'erp_client_id' : 'NULL') . " AS erp_client_id
                FROM client WHERE id=? AND active=1", [(int) $uid]);
    if (!$c) return null;
    return [
      'id'     => (int) $c['id'],
      // Même sens qu'à l'inscription : `name` = prénom, `surname` = nom.
      'prenom' => (string) $c['name'],
      'nom'    => (string) $c['surname'],
      'email'  => (string) $c['email'],
      'tel'    => (string) ($c['phone_e164'] ?: $c['phone']),
      'cp'     => (string) $c['zip'],
      'shopId' => (int) ($c['pref'] ?: $c['id_main_shop']),
      'erpId'  => $c['erp_client_id'] !== null ? (int) $c['erp_client_id'] : null,
    ];
  };

  // Demande en cours de ce compte, ou null.
  $lienEnCours = function ($cid) {
    if (!col_exists('ws_client_link_requests', 'id')) return null;
    return row("SELECT * FROM ws_client_link_requests WHERE client_id=? AND status='pending' LIMIT 1", [(int) $cid]);
  };

  /* État du rattachement pour l'écran « mon compte ». Sans effet de bord :
     appelé à chaque affichage, il ne crée jamais de demande. */
  if ($m === 'GET' && $p === '/account/link-request') {
    $me = $lienCompte();
    if (!$me) json_out(['error' => 'Connexion requise.'], 401);
    if ($me['erpId']) json_out(['etat' => 'linked', 'fiche' => $me['erpId']]);
    if (!col_exists('ws_client_link_requests', 'id'))
      json_out(['etat' => 'indisponible', 'raison' => 'Table ws_client_link_requests absente (migration 0099).']);
    if ($d = $lienEnCours($me['id']))
      json_out(['etat' => 'pending', 'depuis' => (string) $d['created_at']]);
    // Rien en cours : y a-t-il seulement quelque chose à proposer ?
    $f = erp_link_candidate($me);
    if (!$f) json_out(['etat' => 'aucune']);
    $cmp = erp_link_comparer($me, $f);
    json_out(['etat' => 'proposable', 'fiche' => erp_link_vue_client($f, $cmp)]);
  }

  /* Dépose la demande. Idempotent : rappelé alors qu'une demande est en cours,
     il rend la demande existante plutôt que d'en empiler une seconde. */
  if ($m === 'POST' && $p === '/account/link-request') {
    $me = $lienCompte();
    if (!$me) json_out(['error' => 'Connexion requise.'], 401);
    if (!col_exists('ws_client_link_requests', 'id'))
      json_out(['ok' => false, 'error' => 'Table ws_client_link_requests absente — demande non enregistrée (migration 0099).'], 501);
    if ($me['erpId']) json_out(['ok' => true, 'etat' => 'linked']);
    if ($lienEnCours($me['id'])) json_out(['ok' => true, 'etat' => 'pending']);
    // Une demande toutes les 10 min par compte : sans cette borne, un compte
    // pourrait sonder l'index client en boucle par la réponse « aucune ».
    rate_limit('lienrattach', 6, 600);

    $f = erp_link_candidate($me);
    if (!$f) json_out(['ok' => false, 'error' => 'Aucune fiche de boutique ne correspond à ce compte.'], 404);

    /* Une fiche déjà rattachée à un AUTRE compte ne se rattache pas deux fois.
       Sans ce contrôle, deux comptes créés avec le même numéro se
       partageraient un historique d'achats. */
    if (col_exists('client', 'erp_client_id')
        && row("SELECT 1 AS x FROM client WHERE erp_client_id=? AND id<>?", [(int) $f['id'], $me['id']]))
      json_out(['ok' => false, 'error' => 'Cette fiche est déjà reliée à un autre compte. Contactez votre boutique.'], 409);

    $cmp = erp_link_comparer($me, $f);
    q("INSERT INTO ws_client_link_requests (client_id, erp_client_id, shop_id, match_json, status)
       VALUES (?,?,?,?, 'pending')",
      [$me['id'], (int) $f['id'], ($me['shopId'] ?: null), json_encode($cmp, JSON_UNESCAPED_UNICODE)]);
    json_out(['ok' => true, 'etat' => 'pending'], 201);
  }

  /* Annulation par le client lui-même. Ne touche qu'une demande PENDANTE :
     un rattachement déjà décidé se défait côté boutique, pas ici. */
  if ($m === 'DELETE' && $p === '/account/link-request') {
    $me = $lienCompte();
    if (!$me) json_out(['error' => 'Connexion requise.'], 401);
    if (!col_exists('ws_client_link_requests', 'id')) json_out(['ok' => true, 'etat' => 'aucune']);
    q("UPDATE ws_client_link_requests SET status='canceled', decided_at=NOW()
        WHERE client_id=? AND status='pending'", [$me['id']]);
    json_out(['ok' => true, 'etat' => 'aucune']);
  }
  /* ── CONNEXION TABLETTE BOUTIQUE par PIN (4 chiffres) ─────────────────────
     Ouvre une session LIMITÉE aux sections du compte (créé dans la console
     franchiseur). Ne donne JAMAIS les droits admin : le jeton ERP reste seul
     habilité pour l'administration.
     Sécurité : 4 chiffres = 10 000 combinaisons, donc
       • PIN haché (password_hash), jamais stocké ni renvoyé en clair ;
       • PIN cherché UNIQUEMENT parmi les comptes actifs de LA boutique ;
       • limitation de tentatives (rate_limit) : 8 essais / 5 min par IP ;
       • session opaque de 12 h (une journée de service), révocable en base.
     ── */
  if ($m === 'POST' && $p === '/bo/pin-login') {
    rate_limit('pinlogin', 8, 300);
    $b = body();
    $shopId3 = (int) ($b['shopId'] ?? 0);
    $pin3    = preg_replace('/\D/', '', (string) ($b['pin'] ?? ''));
    if (!$shopId3 || strlen($pin3) !== 4) json_out(['error' => 'Boutique et PIN à 4 chiffres requis'], 400);
    $hasPin3 = (bool) row("SELECT 1 x FROM information_schema.columns
                            WHERE table_schema=DATABASE() AND table_name='bo_users' AND column_name='pin_hash'");
    if (!$hasPin3) json_out(['error' => 'Comptes tablette non configurés (migration 0046 absente)'], 503);
    // Candidats : comptes ACTIFS rattachés à cette boutique et ayant un PIN.
    // Les SECTIONS viennent du PROFIL attribué par le franchisé parmi ceux
    // publiés par la marque (repli sur les sections propres pour les comptes
    // créés avant le modèle par profils).
    $hasRole3 = col_exists('bo_users', 'role_id');
    $cands = rows("SELECT u.id, u.display_name, u.pin_hash, u.sections" .
                  ($hasRole3 ? ", r.sections AS role_sections" : ", NULL AS role_sections") . "
                     FROM bo_users u
                     JOIN bo_user_shops bus ON bus.user_id = u.id AND bus.shop_id = ?" .
                  ($hasRole3 ? " LEFT JOIN bo_role r ON r.id = u.role_id AND r.active = 1" : "") . "
                    WHERE u.active = 1 AND u.pin_hash IS NOT NULL AND u.pin_hash <> ''", [$shopId3]);
    $me3 = null;
    foreach ($cands as $c3) { if (password_verify($pin3, (string) $c3['pin_hash'])) { $me3 = $c3; break; } }
    if (!$me3) json_out(['error' => 'PIN incorrect'], 401);
    $sections3 = bo_user_sections($me3);
    if (!$sections3) json_out(['error' => 'Aucun profil actif sur ce compte — le gérant doit lui en attribuer un'], 403);
    $tok3 = bin2hex(random_bytes(32));
    q("INSERT INTO bo_pin_session (token, user_id, shop_id, expires_at)
         VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL 12 HOUR))", [$tok3, (int) $me3['id'], $shopId3]);
    q("UPDATE bo_users SET last_login_at = NOW() WHERE id = ?", [(int) $me3['id']]);
    // Purge opportuniste des sessions expirées (pas de tâche planifiée requise).
    q("DELETE FROM bo_pin_session WHERE expires_at < NOW()");
    json_out(['ok' => true, 'token' => $tok3, 'nom' => $me3['display_name'] ?: 'Utilisateur',
              'shopId' => $shopId3, 'sections' => $sections3, 'expireDans' => 12 * 3600]);
  }
  // Contexte d'une session tablette : qui suis-je, et quelles sections ?
  if ($m === 'GET' && $p === '/bo/pin-me') {
    $tok4 = req_header('X-Pin-Token');
    if ($tok4 === '') json_out(['error' => 'session absente'], 401);
    $hasRole4 = col_exists('bo_users', 'role_id');
    $s4 = row("SELECT s.user_id, s.shop_id, u.display_name, u.sections, u.active" .
              ($hasRole4 ? ", r.sections AS role_sections, r.label AS role_label" : ", NULL AS role_sections, NULL AS role_label") . "
                 FROM bo_pin_session s JOIN bo_users u ON u.id = s.user_id" .
              ($hasRole4 ? " LEFT JOIN bo_role r ON r.id = u.role_id AND r.active = 1" : "") . "
                WHERE s.token = ? AND s.expires_at > NOW()", [$tok4]);
    if (!$s4 || !$s4['active']) json_out(['error' => 'session expirée ou compte désactivé'], 401);
    json_out(['ok' => true, 'nom' => $s4['display_name'] ?: 'Utilisateur', 'shopId' => (int) $s4['shop_id'],
              'profil' => $s4['role_label'] ?: null, 'sections' => bo_user_sections($s4)]);
  }
  if ($m === 'POST' && $p === '/bo/pin-logout') {
    $tok5 = req_header('X-Pin-Token');
    if ($tok5 !== '') q("DELETE FROM bo_pin_session WHERE token = ?", [$tok5]);
    json_out(['ok' => true]);
  }

  if ($m === 'POST' && $p === '/auth/login') {
    rate_limit('login', 10, 300);   // anti brute-force mots de passe
    $b = body();
    // Identifiant = email OU téléphone.
    $ident = strtolower(trim($b['identifier'] ?? $b['email'] ?? ''));
    if ($ident === '') json_out(['error' => 'Identifiants incorrects.'], 401);
    // Identifiant téléphone : on le normalise en E.164 + national pour le retrouver.
    [, $identNat, $identE164] = norm_phone($b['phonePrefix'] ?? '+32', $ident);
    // RÉSOLUTION DÉTERMINISTE. L'ancienne requête combinait email et téléphone
    // avec « LIMIT 1 » SANS « ORDER BY » : quand deux comptes partagent un
    // numéro (cas constaté en base), MySQL renvoyait l'un OU l'autre au hasard,
    // et le mot de passe pourtant correct pouvait être refusé.
    // 1) L'EMAIL prime — c'est l'identifiant non ambigu.
    $u = row("SELECT id, password_hash FROM client
               WHERE LOWER(TRIM(email)) = ? AND active = 1
               ORDER BY id LIMIT 1", [$ident]);
    // 2) Sinon téléphone : s'il désigne PLUSIEURS comptes, on refuse
    //    explicitement au lieu d'en choisir un — l'utilisateur est invité à se
    //    connecter par email (« vraies données ou erreur », jamais de devinette).
    if (!$u && $identE164 !== '') {
      $cands2 = rows("SELECT id, password_hash FROM client
                       WHERE (phone_e164 = ? OR phone = ? OR phone = ?) AND active = 1
                       ORDER BY id", [$identE164, $identNat, $ident]);
      if (count($cands2) > 1) {
        json_out(['error' => 'phone_ambigu',
                  'message' => 'Plusieurs comptes utilisent ce numéro de téléphone. Connectez-vous avec votre adresse email.'], 409);
      }
      $u = $cands2[0] ?? null;
    }
    // Compte existant mais sans mot de passe (client importé / créé côté PWA) :
    // on ne renvoie pas "identifiants incorrects" -> on invite à définir un mot de passe.
    if ($u && empty($u['password_hash'])) {
      json_out(['error' => 'no_password', 'message' => 'Ce compte existe mais n’a pas encore de mot de passe.', 'needsPassword' => true], 409);
    }
    if (!$u || !password_verify($b['password'] ?? '', $u['password_hash'])) json_out(['error' => 'Identifiants incorrects.'], 401);
    // Connexion depuis la PWA : le compte est marqué utilisateur PWA (la PWA
    // posait ce drapeau elle-même, en SQL direct, à chaque connexion).
    if (($b['channel'] ?? '') === 'pwa' && col_exists('client', 'pwa_user'))
      q("UPDATE client SET pwa_user = 1 WHERE id = ?", [(int) $u['id']]);
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
    /* DÈS QUE LE SMS EST DISPONIBLE, cette porte se ferme : elle ne vérifie
       aucune identité, et laisser les deux chemins ouverts reviendrait à
       n'avoir posé aucune serrure. Tant que le jeton SMSAPI n'est pas réglé,
       l'ancien comportement subsiste — c'est l'état actuel de la production,
       et le fermer sans remplaçant enfermerait dehors les comptes sans mot de
       passe, c'est-à-dire tout le monde. */
    if (sms_enabled())
      json_out(['error' => "Pour définir votre mot de passe, confirmez votre numéro par SMS.",
                'code' => 'otp_requis'], 409);
    $b = body();
    $mail = strtolower(trim($b['email'] ?? ''));
    [, $phoneNat, $phoneE164] = norm_phone($b['phonePrefix'] ?? '+32', $b['phone'] ?? '');
    $ident = strtolower(trim($b['identifier'] ?? ''));
    [, $identNat, $identE164] = norm_phone($b['phonePrefix'] ?? '+32', $ident);
    $pass = (string) ($b['password'] ?? '');
    if (strlen($pass) < 6) json_out(['error' => 'Mot de passe trop court (min. 6 caractères).'], 400);
    // Un TÉLÉPHONE partagé par plusieurs comptes ne doit pas servir à choisir
    // sur lequel poser le mot de passe : le choix serait arbitraire et pourrait
    // viser le compte d'un autre client. On refuse et on demande l'email.
    if ($mail === '' && ($phoneE164 !== '' || $identE164 !== '')) {
      $e1 = $phoneE164 ?: $identE164; $n1 = $phoneNat ?: $identNat;
      $nb1 = (int) (row("SELECT COUNT(*) n FROM client WHERE phone_e164 = ? OR phone = ? OR phone = ?",
                        [$e1, $n1, $ident])['n'] ?? 0);
      if ($nb1 > 1) {
        json_out(['error' => 'phone_ambigu',
                  'message' => 'Plusieurs comptes utilisent ce numéro. Indiquez votre adresse email pour définir votre mot de passe.'], 409);
      }
    }
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
    // shopId : l'ERP cherche dans les clients de CETTE boutique avant VIES.
    json_out(vies_lookup($mm['vat'], qp('shopId') ?: ($shopId ?? null)));
  }
  // SSO handoff PWA -> webshop. La PWA insère un jeton à usage unique dans
  // auth_handoff (token_hash = sha256 du jeton, + client_id + expires_at) puis
  // redirige vers /webshop?handoff=<jeton>. Ici on le vérifie et on ouvre la session.

  /* ── CODE À USAGE UNIQUE : PROUVER QU'ON EST BIEN LE TITULAIRE DU NUMÉRO ────
   * 8 178 fiches, aucun mot de passe. Sans preuve, `/auth/set-password` laisse
   * réclamer le compte d'autrui en tapant son numéro — et l'emporte pour de
   * bon, puisque la garde « pas d'écrasement » se referme derrière le premier
   * arrivé. Le code SMS est cette preuve.
   *
   * Le compte se résout EXACTEMENT comme à la connexion : l'e-mail prime, un
   * téléphone partagé par plusieurs comptes est refusé au lieu d'en choisir un
   * au hasard. Deux résolutions divergentes seraient deux vérités — le piège
   * qui a déjà coûté cher ici. */
  $otpResoudre = function ($b) {
    $ident = strtolower(trim($b['identifier'] ?? $b['email'] ?? $b['phone'] ?? ''));
    if ($ident === '') return ['err' => 'Indiquez votre e-mail ou votre téléphone.', 'http' => 400];
    [, $nat, $e164] = norm_phone($b['phonePrefix'] ?? '+32', $ident);
    $u = row("SELECT id, email, phone, phone_prefix, phone_e164, password_hash FROM client
               WHERE LOWER(TRIM(email)) = ? AND active = 1 ORDER BY id LIMIT 1", [$ident]);
    if (!$u && $e164 !== '') {
      $c = rows("SELECT id, email, phone, phone_prefix, phone_e164, password_hash FROM client
                  WHERE (phone_e164 = ? OR phone = ? OR phone = ?) AND active = 1 ORDER BY id",
                [$e164, $nat, $ident]);
      if (count($c) > 1) return ['err' => 'Plusieurs comptes utilisent ce numéro. Utilisez votre e-mail.',
                                 'code' => 'phone_ambigu', 'http' => 409];
      $u = $c[0] ?? null;
    }
    if (!$u) return ['err' => "Aucun compte ne correspond.", 'code' => 'inconnu', 'http' => 404];
    /* Le code part au numéro DE LA FICHE, jamais à celui qui vient d'être tapé :
       sinon la preuve ne prouverait rien — on se l'enverrait à soi-même.
       tel_fiche_e164() respecte l'indicatif DE LA FICHE : l'ancien
       norm_phone('+32', …) renvoyait un +48 725… enregistré en +3248725…. */
    $tel = tel_fiche_e164($u);
    if ($tel === '') return ['err' => "Ce compte n'a pas de numéro de téléphone. Contactez la boutique.",
                             'code' => 'sans_tel', 'http' => 409];
    return ['u' => $u, 'tel' => $tel];
  };

  /* Étape 1 : envoyer le code. */
  if ($m === 'POST' && $p === '/auth/otp-request') {
    rate_limit('otpreq', 5, 900);
    if (!sms_enabled()) json_out(['error' => "L'envoi de code est indisponible.", 'code' => 'sms_off'], 503);
    $r = $otpResoudre(body());
    if (isset($r['err'])) json_out(['error' => $r['err'], 'code' => $r['code'] ?? null], $r['http']);
    /* Limite AUSSI par numéro : sans elle, changer d'IP suffirait à faire
       sonner le téléphone de quelqu'un en boucle — et chaque SMS est payant. */
    rate_limit('otpnum:' . substr(sha1($r['tel']), 0, 16), 3, 900);
    if (!sms_otp_envoyer($r['tel'])) {
      /* La CAUSE reste côté serveur. « Plus de points sur le compte SMSAPI »
         est un fait d'exploitation : il regarde la boutique, pas le client, et
         le lui montrer révélerait l'état interne à qui tape un numéro au
         hasard. Il se journalise, et /erp/probe le rend au diagnostic. */
      error_log('[ws] OTP : ' . implode(' | ', sms_notes()));
      json_out(['error' => "Le code n'a pas pu être envoyé. Réessayez ou contactez la boutique.",
                'code' => 'envoi_echoue'], 502);
    }
    json_out(['sent' => true, 'phoneMasked' => sms_masque($r['tel']), 'expiresIn' => 180]);
  }

  /* Étape 2 : code + mot de passe EN UN SEUL APPEL. Un appel unique n'a pas
   * d'état intermédiaire à garder : rien à stocker, rien à expirer, et aucune
   * fenêtre entre « code validé » et « mot de passe posé » où quelqu'un
   * pourrait s'intercaler. */
  if ($m === 'POST' && $p === '/auth/otp-set-password') {
    rate_limit('otpset', 8, 900);
    $b = body();
    $pass = (string) ($b['password'] ?? '');
    if (strlen($pass) < 6) json_out(['error' => 'Mot de passe trop court (min. 6 caractères).'], 400);
    if (!sms_enabled()) json_out(['error' => "L'envoi de code est indisponible.", 'code' => 'sms_off'], 503);
    $r = $otpResoudre($b);
    if (isset($r['err'])) json_out(['error' => $r['err'], 'code' => $r['code'] ?? null], $r['http']);
    $v = sms_otp_verifier($r['tel'], $b['code'] ?? '');
    if ($v === 'perime')        json_out(['error' => 'Code expiré, demandez-en un nouveau.', 'code' => 'perime'], 401);
    if ($v === 'indisponible')  json_out(['error' => "Vérification indisponible.", 'code' => 'sms_off'], 503);
    if ($v !== 'ok')            json_out(['error' => 'Code incorrect.', 'code' => 'faux'], 401);
    $id = (int) $r['u']['id'];
    q("UPDATE client SET password_hash = ? WHERE id = ?", [password_hash($pass, PASSWORD_BCRYPT), $id]);
    // Compte réclamé par SMS depuis la PWA : même drapeau qu'à la connexion.
    if (($b['channel'] ?? '') === 'pwa' && col_exists('client', 'pwa_user'))
      q("UPDATE client SET pwa_user = 1 WHERE id = ?", [$id]);
    json_out(['user' => user_payload($id), 'token' => sign_token(['id' => $id, 'exp' => time() + 30 * 86400])]);
  }

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
  /* ── Émission du jeton SSO PWA → webshop (le pendant de /auth/handoff, qui le
     CONSOMME). Jusqu'ici la PWA écrivait elle-même dans auth_handoff, en SQL
     direct sur une table partagée ; elle appelle désormais cet endpoint avec
     son jeton Bearer. Même contrat qu'avant : jeton aléatoire 256 bits, seul
     son sha256 est stocké, TTL 60 s, usage unique (used_at), empreinte UA+IP.
     public_id est NOT NULL dans auth_handoff : il est posé ici (UUID v4) pour
     les comptes antérieurs à la migration identité. ── */
  if ($m === 'POST' && $p === '/auth/handoff-create') {
    $id = auth_uid(); if (!$id) json_out(['error' => 'Non connecté.'], 401);
    if (!tbl_exists('auth_handoff')) json_out(['error' => 'SSO indisponible (table auth_handoff absente).'], 503);
    $hasPub  = col_exists('client', 'public_id');
    $hasPref = col_exists('client', 'preferred_shop_id');
    $c = row("SELECT id_main_shop" . ($hasPub ? ', public_id' : ", NULL AS public_id")
              . ($hasPref ? ', preferred_shop_id' : ", NULL AS preferred_shop_id")
              . " FROM client WHERE id = ? AND active = 1 LIMIT 1", [$id]);
    if (!$c) json_out(['error' => 'Compte inactif.'], 401);
    $pub = trim((string) ($c['public_id'] ?? ''));
    if ($pub === '') {
      $d = random_bytes(16);
      $d[6] = chr((ord($d[6]) & 0x0f) | 0x40); $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
      $pub = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
      if ($hasPub) q("UPDATE client SET public_id = ? WHERE id = ? AND (public_id IS NULL OR public_id = '')", [$pub, $id]);
    }
    $shop  = $c['preferred_shop_id'] ?? $c['id_main_shop'] ?? null;
    $token = bin2hex(random_bytes(32));                  // jeton brut : jamais persisté
    $fp    = substr(hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 128);
    q("INSERT INTO auth_handoff (token_hash, client_id, public_id, shop_id, fingerprint, expires_at)
       VALUES (?,?,?,?,?, DATE_ADD(NOW(), INTERVAL 60 SECOND))",
      [hash('sha256', $token), $id, $pub, $shop !== null ? (int) $shop : null, $fp]);
    json_out(['token' => $token, 'ttl' => 60]);
  }
  /* Vérifie la TVA via VIES ET lie la société au client (persisté) — miroir du
     PWA handle_billing_verify (modèle « company link ») : la société est une
     LIGNE client dédiée (is_b2b=1, retrouvée par TVA ou créée), la personne y
     est liée via company_client_id et sa copie locale des champs société est
     nettoyée. Badge et données restent ainsi identiques PWA ⇄ WS. */
  if ($m === 'POST' && $p === '/auth/billing-verify') {
    $id = auth_uid(); if (!$id) json_out(['error' => 'Non connecté.'], 401);
    /* Boutique du client : elle sert à l'ERP pour chercher la société parmi
       SES fiches avant d'appeler VIES. Absente → VIES direct, comme avant. */
    $bShop = body()['shopId'] ?? null;
    if (!$bShop) { $cs = row("SELECT id_main_shop FROM client WHERE id=?", [$id]); $bShop = $cs['id_main_shop'] ?? null; }
    $r = vies_lookup((string) (body()['vat'] ?? ''), $bShop);
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
        // La ligne société suit la boutique de la personne qui la crée : sans
        // preferred_shop_id elle n'apparaîtrait dans aucune console.
        $hp = col_exists('client', 'preferred_shop_id');
        q("INSERT INTO client (id_main_shop, " . ($hp ? "preferred_shop_id, " : "") . "is_b2b, company_name, tax_number, invoice_name,
              invoice_address, invoice_postal_code, invoice_city, active, source_channel, verified_at)
           VALUES (?," . ($hp ? "?," : "") . "1,?,?,?,?,?,?,1,'webshop',NOW())",
          array_merge([$ms], $hp ? [$ms] : [],
            [$d['name'], $d['vat'], $d['name'], $d['address'], $d['postalCode'], $d['city']]));
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
    /* DÉLIER = couper TOUTES les sources que le client possède, en une fois.
       user_payload() résout le bureau par une chaîne de replis : client.office_id,
       puis pwa_client_office, puis l'e-mail inscrit dans une entreprise. Cet
       endpoint ne supprimait que le deuxième et PATCH /auth/me que le premier :
       quel que soit le bouton utilisé, l'autre source resservait aussitôt un
       bureau, et le client voyait « un autre bureau » apparaître après avoir
       délié le sien.

       Le rattachement par e-mail (ws_office_emails) n'est PAS touché : c'est une
       appartenance B2B gérée par le franchisé, qu'un client ne doit pas pouvoir
       détruire depuis son profil. S'il la reste, elle rerattache légitimement —
       et le profil doit alors l'expliquer plutôt que de laisser croire à un bug. */
    if ($ref === null || $ref === '') {
      q("DELETE FROM pwa_client_office WHERE client_id = ?", [$id]);
      if (col_exists('client', 'office_id')) q("UPDATE client SET office_id = NULL WHERE id = ?", [$id]);
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
    $hasPep = col_exists('pwa_invoices', 'peppol_status');   // statut Peppol (0085)
    $hasFno = col_exists('ws_orders', 'fiscal_ticket_no');   // ticket fiscal (0085)
    /* Commandes webshop : demande après coup (0111) + demande au tunnel (0011).
       Les deux moments se fondent en UN état « demandée » : le client ne se
       demande pas lequel des deux boutons il a coché. */
    $canReqO = col_exists('ws_orders', 'to_invoice');
    $hasBeO  = col_exists('ws_orders', 'billing_entity_id');
    $hasIrq  = col_exists('ws_orders', 'invoice_requested');
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
                p.purchase_code AS fiscalTicketNo, NULL AS fiscalTicketUrl,
                " . ($hasPep ? "i.peppol_status" : "NULL") . " AS peppolStatus,
                " . ($hasPep ? "i.peppol_at"     : "NULL") . " AS peppolAt,
                'ticket' AS source
           FROM pwa_purchases p LEFT JOIN pwa_invoices i ON i.id = p.invoice_id
          WHERE p.client_id = ? AND COALESCE(p.occurred_at, p.created_at) >= DATE_SUB(NOW(), INTERVAL 12 MONTH)",
        [$id]));
    } catch (Throwable $e) { /* tables PWA absentes */ }
    try {                                                              // commandes webshop
      $items = array_merge($items, rows(
        "SELECT o.order_ref AS ref, s.name AS shop, o.created_at AS at,
                (SELECT COUNT(*) FROM ws_order_lines l WHERE l.order_id = o.id" . oline_own() . ") AS items,
                o.total AS total,
                " . ($canReqO || $hasIrq
                      ? "IF(" . ($canReqO ? "COALESCE(o.to_invoice,0)=1" : "0") . ($hasIrq ? " OR COALESCE(o.invoice_requested,0)=1" : "") . ",1,0)"
                      : "0") . " AS toInvoice,
                " . ($hasBeO ? "o.billing_entity_id" : "NULL") . " AS billingEntityId,
                " . ($hasBeO ? "COALESCE(NULLIF(be.company_name,''), NULLIF(be.invoice_name,''))" : "NULL") . " AS billingEntityName,
                " . ($hasBeO ? "be.tax_number" : "NULL") . " AS billingEntityVat,
                NULL AS frozenAt,
                NULL AS invoiceNo, NULL AS invoiceTotal, NULL AS pdfPath,
                " . ($hasFno ? "o.fiscal_ticket_no"  : "NULL") . " AS fiscalTicketNo,
                " . ($hasFno ? "o.fiscal_ticket_url" : "NULL") . " AS fiscalTicketUrl,
                NULL AS peppolStatus, NULL AS peppolAt, 'order' AS source
           FROM ws_orders o LEFT JOIN shops s ON s.id = o.shop_id AND s.webshop_enabled = 1
           " . ($hasBeO ? "LEFT JOIN client be ON be.id = o.billing_entity_id" : "") . "
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
      // Drapeau « facture PDF disponible » sans exposer le chemin interne.
      $it['hasInvoicePdf'] = !empty($it['pdfPath']);
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
      // Capacité par SOURCE : un ticket de caisse et une commande webshop ne
      // dépendent pas de la même colonne. Le front n'affiche le panneau que
      // pour les lignes que le serveur sait traiter.
      'canRequestInvoice' => $canReq,
      'canRequestInvoiceOrders' => $canReqO,
      // Annoncé AU MOMENT de la demande : la facture n'apparaît qu'au batch
      // mensuel du franchisé — sinon le client la cherche dès le lendemain.
      'invoiceNotice' => 'Votre facture sera émise par la boutique en début de mois prochain.',
    ]);
  }

  /* ── Demande de facture sur un ticket : écrit to_invoice (1/0) + le
     destinataire billing_entity_id. REFUS EN BASE (pas sur l'état affiché) si
     déjà facturé, gelé par le batch ERP, ou délai dépassé. 501 si la colonne
     to_invoice n'est pas encore migrée (voir rapport de schéma). ── */
  /* ── Vérifier une société par son numéro de TVA, SANS la lier au compte.
     /auth/billing-verify fait VIES puis REMPLACE la société de la personne :
     c'est le bon geste pour « ma société », pas pour « facturer ce ticket à une
     autre société ». Ici : VIES, puis la fiche société est retrouvée par TVA ou
     créée (is_b2b, verified_at), et RENDUE — rien n'est écrit sur la personne.
     C'est l'identifiant rendu que la demande de facture recevra. ── */
  if ($m === 'POST' && $p === '/auth/purchases/billing-entity') {
    $id = auth_uid(); if (!$id) json_out(['error' => 'Non connecté.'], 401);
    rate_limit('billent', 20, 900);   // VIES est un service public partagé : pas de rafale
    $cs = row("SELECT id_main_shop FROM client WHERE id=?", [$id]);
    $r = vies_lookup((string) (body()['vat'] ?? ''), $cs['id_main_shop'] ?? null);
    if (empty($r['valid'])) json_out($r, 422);
    $d = $r['data'];
    if (empty($d['name'])) json_out(['valid' => false, 'error' => ['code' => 'no_name',
      'message' => 'VIES reconnaît ce numéro mais ne rend pas de raison sociale : impossible de facturer sans nom.']], 422);
    $c = row("SELECT id FROM client
               WHERE tax_number = ? AND is_b2b = 1 AND (name IS NULL OR name = '')
               ORDER BY (verified_at IS NOT NULL) DESC, id ASC LIMIT 1", [$d['vat']]);
    $eid = (int) ($c['id'] ?? 0);
    if ($eid) {
      q("UPDATE client SET company_name=?, invoice_name=COALESCE(NULLIF(invoice_name,''),?),
            invoice_address=COALESCE(NULLIF(invoice_address,''),?),
            invoice_postal_code=COALESCE(invoice_postal_code,?), invoice_city=COALESCE(invoice_city,?),
            is_b2b=1, verified_at=NOW() WHERE id=?",
        [$d['name'], $d['name'], $d['address'], $d['postalCode'], $d['city'], $eid]);
    } else {
      $ms = (int) (($cs['id_main_shop'] ?? 0) ?: 1);
      $hp = col_exists('client', 'preferred_shop_id');
      q("INSERT INTO client (id_main_shop, " . ($hp ? "preferred_shop_id, " : "") . "is_b2b, company_name, tax_number, invoice_name,
            invoice_address, invoice_postal_code, invoice_city, active, source_channel, verified_at)
         VALUES (?," . ($hp ? "?," : "") . "1,?,?,?,?,?,?,1,'webshop',NOW())",
        array_merge([$ms], $hp ? [$ms] : [], [$d['name'], $d['vat'], $d['name'], $d['address'], $d['postalCode'], $d['city']]));
      $eid = (int) db()->lastInsertId();
    }
    json_out(['ok' => true, 'valid' => true, 'entity' => [
      'id' => $eid, 'name' => $d['name'], 'vat' => $d['vat'],
      'address' => $d['address'], 'postalCode' => $d['postalCode'], 'city' => $d['city'],
    ]]);
  }

  if ($m === 'POST' && $p === '/auth/purchases/request-invoice') {
    $id = auth_uid(); if (!$id) json_out(['error' => 'Non connecté.'], 401);
    $bq = body();
    /* ── COMMANDE WEBSHOP (WS-…) : sa propre table, ses propres règles. Le
       destinataire est, au choix : la personne, sa société liée, ou une
       société VÉRIFIÉE par VIES (is_b2b + tax_number + verified_at) — c'est le
       numéro de TVA saisi qui vaut intention de facturer, pas une saisie libre.
       Le délai est le même que pour les tickets. ── */
    $refQ = (string) ($bq['ref'] ?? '');
    $ord = $refQ !== '' && col_exists('ws_orders', 'to_invoice')
      ? row("SELECT id, created_at FROM ws_orders WHERE order_ref = ? AND customer_id = ? LIMIT 1", [$refQ, $id]) : null;
    if ($ord) {
      $want = !empty($bq['want']) ? 1 : 0;
      if (time() > invoice_deadline((string) $ord['created_at'], ws_param('invoice_request_deadline', 'end_of_month')))
        json_out(['error' => 'Délai dépassé pour cette commande.'], 409);
      $be = null;
      if ($want) {
        $be = (int) ($bq['billingEntityId'] ?? 0);
        if ($be <= 0) $be = (int) $id;
        $ok = ($be === (int) $id)
          || row("SELECT 1 x FROM client WHERE id = ? AND id = (SELECT company_client_id FROM client WHERE id = ?)", [$be, $id])
          || row("SELECT 1 x FROM client WHERE id = ? AND is_b2b = 1 AND tax_number IS NOT NULL AND tax_number <> '' AND verified_at IS NOT NULL", [$be]);
        if (!$ok) json_out(['error' => 'Destinataire non autorisé : société inconnue ou non vérifiée.'], 403);
      }
      $hasBe = col_exists('ws_orders', 'billing_entity_id'); $hasAt = col_exists('ws_orders', 'invoice_requested_at');
      q("UPDATE ws_orders SET to_invoice=?" . ($hasBe ? ", billing_entity_id=?" : "") . ($hasAt ? ", invoice_requested_at=" . ($want ? "NOW()" : "NULL") : "")
        . " WHERE id=?", array_merge([$want], $hasBe ? [$want ? $be : null] : [], [(int) $ord['id']]));
      $lbl = null;
      if ($want && $be !== (int) $id)
        $lbl = row("SELECT COALESCE(NULLIF(company_name,''), NULLIF(invoice_name,'')) AS n, tax_number AS v FROM client WHERE id=?", [$be]);
      json_out(['ok' => true, 'billingEntityId' => $want ? $be : null,
                'billingEntityName' => $lbl['n'] ?? null, 'billingEntityVat' => $lbl['v'] ?? null,
                'notice' => $want ? 'Votre facture sera émise par la boutique en début de mois prochain.' : null]);
    }
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
    // La ligne société suit la boutique de la personne : sans preferred_shop_id
    // elle n'apparaîtrait dans aucune console franchisé.
    if (col_exists('client', 'preferred_shop_id'))
      q("UPDATE client SET preferred_shop_id=? WHERE id=? AND preferred_shop_id IS NULL", [$ms, $co]);
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
    // E-mail (édité depuis le profil PWA, qui l'écrivait en SQL direct) :
    // format validé, normalisé en minuscules ; vide = retiré (NULL).
    if (array_key_exists('email', $b)) {
      $em = strtolower(trim((string) $b['email']));
      if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Email invalide'], 400);
      $sets[] = 'email=?'; $vals[] = ($em !== '' ? $em : null);
    }
    // Téléphone : normalisé en national + E.164 + préfixe.
    if (array_key_exists('phone', $b)) {
      /* Sans phonePrefix explicite, on repart du préfixe DÉJÀ en base (défaut
         +32 seulement s'il n'y en a pas) : le profil n'envoie pas d'indicatif,
         et le défaut aveugle réécrivait phone_prefix/phone_e164 en +32 à
         chaque sauvegarde — corrompant les numéros non belges. */
      $curPfx = $b['phonePrefix'] ?? (row("SELECT phone_prefix FROM client WHERE id=?", [$id])['phone_prefix'] ?? '+32');
      [$pfx, $nat, $e164] = norm_phone($curPfx ?: '+32', $b['phone']);
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
    // Rattachement bureau : le client ne peut se lier qu'à un bureau VALIDÉ
    // (ws_offices.active = 1). Sans ce contrôle, un POST de profil suffisait à
    // se rattacher à n'importe quel bureau — donc à devenir éligible à la
    // livraison bureau ET aux bons nominatifs ciblant ce bureau (cf. la
    // vérification client.office_id = voucher_campaign_target.target_id).
    // Un bureau non listé passe par la demande /offices/contact, validée par le
    // franchisé (POST /franchisee/join-decide).
    if (array_key_exists('officeId', $b)) {
      $ov = ($b['officeId'] !== '' && $b['officeId'] !== null) ? (int) $b['officeId'] : null;
      if ($ov !== null && !row("SELECT 1 AS x FROM ws_offices WHERE id = ? AND active = 1", [$ov]))
        json_out(['error' => 'Bureau inconnu ou non validé — demandez le rattachement à votre boutique.'], 409);
      $sets[] = 'office_id=?';
      $vals[] = $ov;
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
    // 'stripe' et non 'card' : c'est l'identifiant utilisé partout ailleurs
    // (/payment-methods, payment_label, ws_shop_payment_options). 'card' restait
    // compris par payment_family() mais n'avait pas de libellé.
    q("UPDATE ws_orders SET payment_method='stripe', payment_status='pending' WHERE id=?", [$o['id']]);
    json_out(['ok' => true, 'orderId' => (int) $o['id'], 'checkoutUrl' => $sess['url']]);
  }

  /* ── WEBHOOK STRIPE : la seule source de vérité sur un encaissement ────────
     Rien ne faisait jamais passer payment_status de 'pending' à 'paid'. Toutes
     les commandes réglées par carte restaient donc « en attente de paiement » :
     le franchisé ne pouvait distinguer un paiement réussi d'un abandon, ni
     savoir ce qu'il lui restait à encaisser. Une commande pouvait être remise
     au client sans que personne ne sache si elle avait été payée.

     Pourquoi un webhook et pas le retour du navigateur : checkout_success est
     déclenché par le CLIENT. Il peut payer et fermer l'onglet sans revenir, ou
     forger l'URL sans avoir rien payé. Seul Stripe, qui SIGNE son événement,
     fait foi.

     Trois exigences, toutes tenues ici :
       • signature vérifiée (HMAC-SHA256 sur "timestamp.corps brut") — sans
         secret configuré, on refuse tout plutôt que d'ouvrir une porte ;
       • tolérance de 5 min sur l'horodatage, pour qu'un événement capté ne
         puisse pas être rejoué des heures plus tard ;
       • idempotence : Stripe REJOUE ses webhooks (plusieurs fois, parfois des
         jours après). Le même événement ne doit pas être compté deux fois. ── */
  if ($m === 'POST' && $p === '/payments/stripe-webhook') {
    $whsec = cfg()['stripe_webhook_secret'] ?? '';
    $raw   = file_get_contents('php://input');
    $sig   = req_header('Stripe-Signature');
    if ($whsec === '')  json_out(['ok' => false, 'error' => 'Webhook non configuré (stripe_webhook_secret absent)'], 503);
    if ($raw === '' || $sig === '') json_out(['ok' => false, 'error' => 'Requête non signée'], 400);

    // En-tête Stripe : « t=1690000000,v1=<hex>,v1=<hex>… »
    $ts = null; $v1 = [];
    foreach (explode(',', $sig) as $part) {
      $kv = explode('=', trim($part), 2);
      if (count($kv) !== 2) continue;
      if ($kv[0] === 't')  $ts = $kv[1];
      if ($kv[0] === 'v1') $v1[] = $kv[1];
    }
    if ($ts === null || !$v1) json_out(['ok' => false, 'error' => 'Signature illisible'], 400);
    if (abs(time() - (int) $ts) > 300) json_out(['ok' => false, 'error' => 'Signature expirée'], 400);
    $expected = hash_hmac('sha256', $ts . '.' . $raw, $whsec);
    $match = false;
    foreach ($v1 as $cand) if (hash_equals($expected, $cand)) $match = true;   // temps constant
    if (!$match) json_out(['ok' => false, 'error' => 'Signature invalide'], 400);

    $evt  = json_decode($raw, true);
    $type = (string) ($evt['type'] ?? '');
    $obj  = $evt['data']['object'] ?? [];
    $evId = (string) ($evt['id'] ?? '');

    // Idempotence : un événement déjà traité renvoie 200 sans rien refaire —
    // répondre en erreur ferait recommencer Stripe indéfiniment.
    $hasLog = (bool) row("SELECT 1 x FROM information_schema.tables
                           WHERE table_schema=DATABASE() AND table_name='ws_stripe_event' LIMIT 1");
    if ($hasLog && $evId !== '' && row("SELECT 1 x FROM ws_stripe_event WHERE event_id=? LIMIT 1", [$evId]))
      json_out(['ok' => true, 'duplicate' => true]);

    // La commande est retrouvée par les métadonnées posées à la création de la
    // session (stripe_checkout), jamais par un montant ou un e-mail.
    $oid = (int) ($obj['metadata']['order_id'] ?? 0);
    $ref = (string) ($obj['metadata']['order_ref'] ?? '');
    $ord = $oid ? row("SELECT id, total, payment_status FROM ws_orders WHERE id=?", [$oid])
                : ($ref !== '' ? row("SELECT id, total, payment_status FROM ws_orders WHERE order_ref=?", [$ref]) : null);

    $applied = 'ignored';
    if ($ord) {
      if ($type === 'checkout.session.completed' || $type === 'checkout.session.async_payment_succeeded') {
        // « completed » ne suffit pas : une session peut se terminer sans que
        // l'argent soit arrivé (virement, prélèvement en attente). C'est
        // payment_status = 'paid' côté Stripe qui atteste l'encaissement.
        $paid = ($obj['payment_status'] ?? '') === 'paid';
        if ($paid) {
          // Contrôle du MONTANT : un écart signifie que la session ne
          // correspond pas à cette commande (panier modifié entre-temps, session
          // réutilisée). On ne marque pas payé sur la foi du seul identifiant.
          $cents = (int) ($obj['amount_total'] ?? 0);
          $du    = (int) round(((float) $ord['total']) * 100);
          if ($cents > 0 && abs($cents - $du) > 1) {
            $applied = 'montant_different';
          } else {
            q("UPDATE ws_orders SET payment_status='paid'"
              . (col_exists('ws_orders', 'status') ? ", status = CASE WHEN status='pending' THEN 'confirmed' ELSE status END" : "")
              . " WHERE id=?", [(int) $ord['id']]);
            $applied = 'paid';
          }
        }
      } elseif ($type === 'checkout.session.expired' || $type === 'checkout.session.async_payment_failed') {
        // Paiement abandonné ou refusé. La commande n'est PAS annulée d'office :
        // le franchisé décide (le client peut repasser payer). On enregistre le
        // fait, ce qui rend enfin « abandonné » distinguable de « payé ».
        q("UPDATE ws_orders SET payment_status='failed' WHERE id=? AND payment_status <> 'paid'", [(int) $ord['id']]);
        $applied = 'failed';
      }
    }

    if ($hasLog && $evId !== '') {
      q("INSERT INTO ws_stripe_event (event_id, type, order_id, applied, payload)
         VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE event_id = event_id",
        [$evId, $type, $ord ? (int) $ord['id'] : null, $applied, mb_substr($raw, 0, 60000)]);
    }
    // 200 même quand l'événement ne nous concerne pas : sinon Stripe le rejoue.
    json_out(['ok' => true, 'type' => $type, 'applied' => $applied]);
  }

  /* ══════════════════════════════════════════════════════════════════════
     Console marque (franchisor) — lecture réseau. Toutes gardées admin.
     Renvoie exactement les shapes attendues par le back-office franchisor
     (window.BOServer.table(name)) : aucune adaptation côté front.
     ══════════════════════════════════════════════════════════════════════ */
  if (strpos($p, '/franchisor/') === 0) {
    require_admin();

    // Montant ADAPTATIF : l'arrondi systématique en k€ affichait « 0 k€ » pour
    // 343,58 € de CA réel — du chiffre d'affaires existant présenté comme nul.
    // Sous 10 000 € on montre l'euro exact, au-delà le k€ (lisibilité réseau).
    $eurk = function ($n) {
      $n = (float) $n;
      return abs($n) < 10000
        ? number_format($n, 2, ',', ' ') . ' €'
        : number_format(round($n / 1000)) . ' k€';
    };
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
      // Sans mois précédent, aucune évolution n'est calculable : on l'affiche
      // « — » au lieu d'un « ▲ +0 % » qui laisse croire à une stagnation mesurée.
      $hasPrev = $caPrev > 0;
      $pct = $hasPrev ? round(($caMonth - $caPrev) / $caPrev * 100, 1) : 0;
      $up  = $pct >= 0;
      $deltaCa = $hasPrev
        ? (($up ? '▲ +' : '▼ ') . str_replace('.', ',', (string) $pct) . ' %')
        : '— pas de mois précédent';
      // « Boutiques en ligne » : c'est bien ce qui est calculé (actives / total).
      // L'ancien libellé « Adoption whitelist » annonçait une mesure d'adoption
      // du catalogue marque que ce chiffre ne calcule pas.
      $adoption = $totalShops > 0 ? round(100 * $activeShops / $totalShops) : 0;
      json_out([
        ['label' => 'CA réseau (mois)',     'value' => $eurk($caMonth),   'valColor' => 'var(--color-text)',    'delta' => $deltaCa, 'deltaColor' => $hasPrev ? ($up ? '#2d7a3e' : 'var(--color-primary)') : 'var(--color-text-muted)'],
        ['label' => 'CA boutique',          'value' => $eurk($caCollect), 'valColor' => 'var(--color-primary)', 'delta' => 'collecte', 'deltaColor' => 'var(--color-text-muted)'],
        ['label' => 'CA livraison bureau',  'value' => $eurk($caDeliv),   'valColor' => '#C87A3F',              'delta' => 'livraison', 'deltaColor' => 'var(--color-text-muted)'],
        ['label' => 'Boutiques actives',    'value' => $activeShops . ' / ' . $totalShops, 'valColor' => 'var(--color-text)', 'delta' => 'réseau', 'deltaColor' => 'var(--color-text-muted)'],
        ['label' => 'Commandes du jour',    'value' => (string) $ordToday, 'valColor' => 'var(--color-text)',   'delta' => "aujourd'hui", 'deltaColor' => 'var(--color-text-muted)'],
        ['label' => 'Boutiques en ligne',   'value' => $adoption . ' %',   'valColor' => 'var(--color-text)',    'delta' => $activeShops . ' actives sur ' . $totalShops, 'deltaColor' => 'var(--color-text-muted)'],
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
      /* BOUTIQUE DE RÉFÉRENCE — SANS REPLI.
         « En ligne » n'a de sens que rapporté à UNE boutique : chacune a son
         prix ERP et ses exclusions. Faute de ?shop=, cette route prenait « la
         première boutique active par id » et rendait des verdicts calculés sur
         elle — une boutique que personne n'avait demandée, et que la réponse ne
         nommait pas. Le chiffre avait l'air d'une vérité réseau ; c'était celui
         d'une boutique tirée au sort par son id.

         Plus de repli : sans portée, il n'y a pas de verdict. `enLigne` et
         `raison` valent null — ils le pouvaient déjà, la structure ne change
         pas — et l'en-tête X-Boutique-Reference dit ce qui a été pris, ou
         pourquoi rien ne l'a été. Un en-tête parce que cette route rend un
         TABLEAU : y ajouter une racine casserait la console marque. */
      $shopRef = (int) (qp('shop', 0) ?: 0);
      if ($shopRef) {
        $sRef = row("SELECT name FROM $SHOPS WHERE id = ?", [$shopRef]);
        header('X-Boutique-Reference: ' . $shopRef . ($sRef ? ' ' . $sRef['name'] : ''));
      } else {
        header('X-Boutique-Reference: aucune — verdicts « en ligne » non calcules (passer ?shop=<id>)');
      }
      $hasPS = $tblExists('ws_product_shops');
      // Console marque (gestion de l'assortiment) : renvoyer TOUTES les catégories,
      // pas seulement les actives. Une catégorie dont tous les produits sont en
      // brouillon passe active=0 (règle auto) et DISPARAISSAIT de l'assistant — la
      // marque ne pouvait alors ni la voir ni la piloter (« je n'ai que 2 catégories »).
      // Le filtre « avec produits » reste géré plus bas (if $rows2) : les catégories
      // réellement vides restent exclues.
      /* shop_id EST SERVI, et il manquait. Une catégorie peut appartenir à UNE
         boutique (ws_categories.shop_id) ou au réseau (NULL). La console marque
         les affichait toutes sans distinction : « Viennoiserie », « Pains »,
         « Pâtisserie » y côtoyaient les catégories réseau alors qu'elles sont
         propres à une seule boutique. D'où la question posée — « les produits
         du BO marque ne correspondent pas au BO franchisé » : c'est exact, et
         c'était invisible ici. La console franchisé, elle, est cloisonnée.
         Le nom accompagne l'id : « boutique 4 » ne dit rien à personne. */
      $catShopCol = col_exists('ws_categories', 'shop_id');
      $cats = rows("SELECT c.id, c.label, c.img, COALESCE(c.menu_default,0) AS menu_default"
                 . ($catShopCol ? ", c.shop_id, s.name AS shop_nom" : ", NULL AS shop_id, NULL AS shop_nom") . "
                     FROM ws_categories c"
                 . ($catShopCol ? " LEFT JOIN $SHOPS s ON s.id = c.shop_id" : "") . "
                    ORDER BY c.sort_order, c.label");
      /* Sous-catégories : ws_category_subs fait foi. Les déduire des produits
         (ce que faisait le constructeur de menus) ne montrait que celles déjà
         pourvues et masquait les autres — or une étape de formule doit pouvoir
         viser une sous-catégorie encore vide. Seules les ACTIVES remontent :
         proposer une sous-catégorie retirée du webshop reviendrait à composer
         un menu avec ce qui ne se vend plus. Une seule requête, pas une par
         catégorie. */
      $subsByCat = [];
      if ($tblExists('ws_category_subs')) {
        foreach (rows("SELECT sub.id, sub.category_id, sub.label
                         FROM ws_category_subs sub
                         JOIN ws_categories c ON c.id = sub.category_id AND c.active = 1
                        WHERE sub.active = 1
                        ORDER BY sub.sort_order, sub.label") as $sb)
          $subsByCat[(int) $sb['category_id']][] = ['id' => (int) $sb['id'], 'label' => $sb['label']];
      }
      $out = [];
      foreach ($cats as $c) {
        // Le franchisor gère l'assortiment : on renvoie AUSSI les produits inactifs
        // (le toggle « Webshop » = ws_products.active, il faut pouvoir les réactiver).
        /* `wl` (au catalogue réseau) et `bm` (obligatoire) ne sont plus servis :
           leurs colonnes ont disparu (migration 0073). La console marque affiche
           encore deux bascules qui s'en nourrissaient — elles liront `undefined`
           et s'afficheront éteintes. C'est visible et sans danger ; les retirer
           de l'écran reste à faire dans son dépôt. */
        $prods = rows("SELECT p.id, p.name AS nom, p.price AS prix, p.active, p.img,
                              COALESCE(p.office_delivery,1) AS od,
                              " . (col_exists('ws_products', 'click_and_collect')
                                   ? "COALESCE(p.click_and_collect,1)" : "1") . " AS cc,
                              p.sub_cat_id AS sub_id, sub.label AS sub, NULL AS saison
                         FROM ws_products p
                         LEFT JOIN ws_category_subs sub ON sub.id = p.sub_cat_id
                        WHERE p.cat_id = ? ORDER BY sub.sort_order, sub.label, p.name", [$c['id']]);
        $photos = product_photo_files();
        /* EN LIGNE OÙ ? La console marque est réseau : « en ligne » n'a de sens
           que rapporté à une boutique. On diagnostique donc sur une boutique de
           RÉFÉRENCE (?shop=, sinon la première active) et on le DIT — sans quoi
           un chiffre réseau laisserait croire à une vérité unique là où chaque
           boutique a son prix ERP et ses exclusions.
           Avant, la colonne « Webshop » portait ws_products.active seul : un
           produit publié mais sans prix ERP s'y affichait allumé et n'était
           nulle part en vente. C'est l'écart « 39 produits · 1 en ligne ». */
        $rows2 = [];
        $visRef = $shopRef ? product_visibilite($shopRef, array_map(fn ($x2) => (int) $x2['id'], $prods), '', null) : [];
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
            // TROIS DRAPEAUX, ET PLUS QUE TROIS. `bw` valait déjà `active` —
            // le même booléen sous deux noms — et `bm` (obligatoire) n'existe
            // plus. On sert ce que la base porte, sous le nom qu'elle lui donne.
            'active' => (bool) $p2['active'],   // publié au catalogue
            'cc' => (bool) $p2['cc'],           // canal click & collect
            'od' => (bool) $p2['od'],           // canal livraison bureau
            /* `bw` DOIT VALOIR `active`, parce que c'est ce que la bascule qui
               le lit ÉCRIT. La console marque déployée affiche p.bw et poste
               {active: 0/1} (vérifié dans son code, toggleBw). Servi un temps
               = cc : pour un produit en brouillon (active=0, cc=1 par défaut),
               l'interrupteur s'affichait ALLUMÉ — le premier clic « pour le
               mettre en ligne » écrivait alors active=0 sur un produit déjà
               éteint, et rien n'apparaissait côté franchisé. Un affichage et
               une écriture qui ne parlent pas de la même colonne font un
               interrupteur qui ment. À retirer quand la console lira active/cc
               directement. */
            'bw' => (bool) $p2['active'],
            'sub' => $p2['sub'] ?: null, 'sub_id' => $p2['sub_id'] !== null ? (int) $p2['sub_id'] : null,
            'photo' => (!empty($p2['img']) || isset($photos[$p2['id']])), // a une photo produit
            'ad' => $ad, 'saison' => $p2['saison'] ?: null,
            // Verdict RÉEL pour la boutique de référence, et sa raison.
            'enLigne' => isset($visRef[(int) $p2['id']]) ? $visRef[(int) $p2['id']]['enLigne'] : null,
            'raison'  => isset($visRef[(int) $p2['id']]) ? $visRef[(int) $p2['id']]['raison'] : null,
          ];
        }
        if ($rows2) $out[] = ['id' => (int) $c['id'], 'cat' => $c['label'], 'img' => $c['img'] ?: null,
                              // NULL = catégorie du réseau. Renseigné = propre à
                              // cette boutique, donc ABSENTE des autres consoles
                              // franchisé et de leur webshop.
                              'shop_id'  => isset($c['shop_id']) && $c['shop_id'] !== null ? (int) $c['shop_id'] : null,
                              'shop_nom' => $c['shop_nom'] ?? null,
                              'subs' => ($subsByCat[(int) $c['id']] ?? []), 'prods' => $rows2];
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
                      -- p.active n'est plus exigé : un PORTEUR de menu vit hors
                      -- catalogue (active=0) précisément pour ne pas être un
                      -- article — le constructeur doit pourtant le lister.
                      WHERE EXISTS (SELECT 1 FROM ws_bundles b WHERE b.product_id = p.id)
                      ORDER BY p.name");
      $hasTrg = tbl_exists('ws_bundle_triggers');
      foreach ($prods as $p2) {
        $pid = (string) $p2['id'];
        // Déclencheurs EXPLICITES du menu (0078) : catégorie, ou catégorie ET
        // sous-catégorie. Le constructeur les affiche et les édite en
        // multi-sélection ; ids ET libellés servis, pour ne pas re-joindre côté écran.
        $hasArt2 = $hasTrg && col_exists('ws_bundle_triggers', 'article_id');
        $trigs = $hasTrg ? rows("SELECT t.cat_id, t.sub_cat_id, c.label AS cat, sub.label AS sub" .
                                  ($hasArt2 ? ", t.article_id, a.name AS article" : ", NULL AS article_id, NULL AS article") . "
                                   FROM ws_bundle_triggers t
                                   LEFT JOIN ws_categories c ON c.id = t.cat_id
                                   LEFT JOIN ws_category_subs sub ON sub.id = t.sub_cat_id" .
                                  ($hasArt2 ? " LEFT JOIN ws_products a ON a.id = t.article_id" : "") . "
                                  WHERE t.product_id = ? ORDER BY c.label, sub.label", [$p2['id']]) : [];
        $bundles = rows("SELECT id, name, description, price_modifier, sort_order, active
                           FROM ws_bundles WHERE product_id = ? ORDER BY sort_order, id", [$p2['id']]);
        foreach ($bundles as &$b) {
          $b['id'] = (string) $b['id'];
          $b['price_modifier'] = (float) $b['price_modifier'];
          $b['sort_order'] = (int) $b['sort_order'];
          $b['active'] = (bool) $b['active'];
          // cat_id : categorie du catalogue dont proviennent les choix (0058).
          $hasSlotCat = col_exists('ws_bundle_slots', 'cat_id');
          $hasSlotSub = col_exists('ws_bundle_slots', 'sub_cat_id');
          $slots = rows("SELECT id, label, required, COALESCE(kind,'single') AS kind,
                                COALESCE(min_select,1) AS min_select, COALESCE(max_select,1) AS max_select,
                                sort_order, active" . ($hasSlotCat ? ", cat_id" : ", NULL AS cat_id")
                             . ($hasSlotSub ? ", sub_cat_id" : ", NULL AS sub_cat_id") . "
                           FROM ws_bundle_slots WHERE bundle_id = ? ORDER BY sort_order, id", [$b['id']]);
          foreach ($slots as &$sl) {
            $sl['id'] = (string) $sl['id'];
            $sl['required'] = (bool) $sl['required'];
            $sl['min_select'] = (int) $sl['min_select'];
            $sl['max_select'] = (int) $sl['max_select'];
            $sl['sort_order'] = (int) $sl['sort_order'];
            $sl['active'] = (bool) $sl['active'];
            // product_id : donnee portee par le choix (0057). Le nom du produit
            // remonte avec, pour que le constructeur affiche a quoi le choix est
            // rattache sans avoir a recharger le catalogue.
            $hasCP = col_exists('ws_bundle_slot_choices', 'product_id');
            $chs = rows("SELECT c.id, c.label, c.img, c.delta, COALESCE(c.cost,0) AS cost, c.sort_order, c.active"
                        . ($hasCP ? ", c.product_id, pp.name AS product_name" : ", NULL AS product_id, NULL AS product_name") . "
                           FROM ws_bundle_slot_choices c"
                        . ($hasCP ? " LEFT JOIN ws_products pp ON pp.id = c.product_id" : "") . "
                          WHERE c.slot_id = ? ORDER BY c.sort_order, c.id", [$sl['id']]);
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
          // Marqueur « formule vide » : aucune étape ayant un choix ACTIF. Ces
          // formules ne sont PAS proposées au client (cf. /catalog/bundles) —
          // le constructeur doit donc les signaler pour qu'on les complète ou
          // les supprime, au lieu de les croire publiées.
          $utile2 = false;
          foreach ($slots as $sl3) {
            foreach (($sl3['choices'] ?? []) as $ch3) { if (!empty($ch3['active'])) { $utile2 = true; break 2; } }
          }
          $b['vide'] = !$utile2;
        }
        unset($b);
        $db[$pid] = [
          'productName'  => $p2['name'],
          'category'     => $p2['category'] ?: '',
          'menuOverride' => $p2['menu_override'] !== null ? $p2['menu_override'] : null,
          'basePrice'    => (float) $p2['price'],
          'baseCost'     => (float) $p2['base_cost'],
          // Multi-sélection de déclencheurs (0078) : cat seule, ou cat ET sous-cat.
          'triggers'     => array_map(fn ($t) => [
            'cat_id' => (int) $t['cat_id'], 'sub_cat_id' => $t['sub_cat_id'] !== null ? (int) $t['sub_cat_id'] : null,
            'cat' => $t['cat'] ?: '', 'sub' => $t['sub'] ?: null,
            'article_id' => $t['article_id'] !== null ? (int) $t['article_id'] : null,
            'article' => $t['article'] ?: null,
          ], $trigs),
          'bundles'      => $bundles,
        ];
      }
      json_out($db);
    }

    // Bons marque (ws_vouchers, réseau = shop_id NULL).
    if ($m === 'GET' && $p === '/franchisor/vouchers') {
      // Vue unifiée MARQUE : tous les bons WS du réseau — émetteur (marque /
      // boutique), cible, motif (0041) et usages inclus.
      $hasReason = col_exists('voucher_campaign', 'reason_kind');
      $vs = rows("SELECT vco.code, vc.id_shop, sh.name AS shop_name,
                         vc.target_kind, vc.target_id, vco.usage_count, vco.usage_limit, vc.usage_limit_per_customer,
                         vco.valid_to AS expires_at," .
                         (col_exists('promotion_order_discount', 'scope_id_product') ? " pod.scope_id_product, pod.scope_max_qty," : " NULL AS scope_id_product, NULL AS scope_max_qty,") .
                         ($hasReason ? " vc.reason_kind, vc.reason_note," : " NULL AS reason_kind, NULL AS reason_note,") . "
                         CASE pod.discount_kind WHEN 'PERCENT' THEN 'percent'
                              WHEN 'FIXED' THEN 'fixed' WHEN 'FREE_DELIVERY' THEN 'free_delivery'
                              ELSE pod.discount_kind END AS type,
                         pod.discount_value AS value,
                         CASE WHEN pr.status='ACTIVE' AND vco.status='ACTIVE' THEN 1 ELSE 0 END AS active
                    FROM voucher_code vco
                    JOIN voucher_campaign vc          ON vc.id = vco.id_voucher_campaign
                    JOIN voucher_campaign_channel vcc ON vcc.id_voucher_campaign = vc.id AND vcc.channel = 'WS'
                    JOIN promotion pr                 ON pr.id = vc.id_promotion
                    JOIN promotion_order_discount pod ON pod.id_promotion = pr.id
                    LEFT JOIN $SHOPS sh               ON sh.id = vc.id_shop
                   ORDER BY vc.id_shop IS NOT NULL, sh.name, vco.code");
      $REASON = ['RECLAMATION' => 'Réclamation', 'GESTE_CO' => 'Geste commercial',
                 'FIDELITE' => 'Fidélité', 'MARKETING' => 'Marketing',
                 'PARTENARIAT' => 'Partenariat', 'TEST' => 'Test / interne'];
      $out = [];
      foreach ($vs as $v) {
        $val = $v['type'] === 'percent' ? '−' . rtrim(rtrim((string) $v['value'], '0'), '.') . ' %'
             : ($v['type'] === 'fixed' ? '−' . rtrim(rtrim((string) $v['value'], '0'), '.') . ' €' : 'port offert');
        if (!empty($v['scope_id_product'])) {
          $pn2 = row("SELECT name FROM ws_products WHERE id=?", [(int) $v['scope_id_product']]);
          $val .= ' sur ' . ($v['scope_max_qty'] !== null ? ((int) $v['scope_max_qty'] . ' × ') : '')
                . (($pn2['name'] ?? null) ?: ('produit #' . $v['scope_id_product']));
        }
        $cible = 'Réseau';
        if ($v['target_kind'] === 'CUSTOMER' && $v['target_id']) {
          $c2 = row("SELECT CONCAT(COALESCE(name,''),' ',COALESCE(surname,'')) n FROM client WHERE id=?", [(int) $v['target_id']]);
          $cible = 'Client ' . trim(($c2['n'] ?? '') ?: ('#' . $v['target_id']));
        } elseif ($v['target_kind'] === 'OFFICE' && $v['target_id']) {
          $o2 = row("SELECT name FROM ws_offices WHERE id=?", [(int) $v['target_id']]);
          $cible = 'Bureau ' . (($o2['name'] ?? '') ?: ('#' . $v['target_id']));
        } elseif ($v['target_kind'] === 'GROUP' && $v['target_id']) {
          $cible = 'Groupe #' . $v['target_id'];
        }
        $out[] = ['code' => $v['code'], 'valeur' => $val, 'type' => $v['type'],
                  'emetteur' => $v['id_shop'] === null ? 'Marque' : ($v['shop_name'] ?: ('Boutique ' . $v['id_shop'])),
                  'cible'    => $cible,
                  'motif'    => $v['reason_kind'] ? (($REASON[$v['reason_kind']] ?? $v['reason_kind'])
                                . ($v['reason_note'] ? ' — ' . $v['reason_note'] : '')) : '—',
                  'usages'   => (int) $v['usage_count'] . ($v['usage_limit'] !== null ? ' / ' . (int) $v['usage_limit'] : '')
                              . ($v['usage_limit_per_customer'] !== null ? ' · ' . (int) $v['usage_limit_per_customer'] . '/client' : ''),
                  'validite' => $v['expires_at'] ? ('jusqu\'au ' . substr($v['expires_at'], 0, 10)) : 'permanent',
                  'act'      => (bool) $v['active']];
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

    /* ── Avis Google — directives de réponse, par tranche de note (1–5).
       La marque définit ici le TON SOCLE commun (ws_param reviews_tone_base)
       puis, note par note, TON, CONSIGNE et EXEMPLE ; la console franchisé
       les applique pour générer un brouillon (jamais publier — la publication
       reste un geste manuel sur Google). Aucun seed : tout naît sur cet
       écran, et la génération dit quelle tranche manque. ── */
    if ($m === 'GET' && $p === '/franchisor/review-guidelines') {
      if (!$tblExists('ws_review_guidelines'))
        json_out(['error' => 'table ws_review_guidelines absente — migration 0082 non jouée'], 501);
      json_out([
        'socle'      => (string) ws_param('reviews_tone_base', ''),
        'directives' => rows("SELECT id, note_min, note_max, tone, instructions, example_reply,
                            DATE_FORMAT(updated_at,'%d/%m %H:%i') AS maj
                       FROM ws_review_guidelines ORDER BY note_min, note_max, id"),
      ]);
    }
    if ($m === 'POST' && $p === '/franchisor/review-guideline') {
      if (!$tblExists('ws_review_guidelines'))
        json_out(['ok' => false, 'error' => 'table ws_review_guidelines absente — migration 0082 non jouée'], 501);
      $b = body();
      if (!empty($b['delete'])) {
        q("DELETE FROM ws_review_guidelines WHERE id = ?", [(int) ($b['id'] ?? 0)]);
        json_out(['ok' => true]);
      }
      $nMin = (int) ($b['note_min'] ?? 0);
      $nMax = (int) ($b['note_max'] ?? 0);
      if ($nMin < 1 || $nMax > 5 || $nMin > $nMax)
        json_out(['ok' => false, 'error' => 'Tranche invalide — note_min ≤ note_max, entre 1 et 5.'], 400);
      $tone = trim((string) ($b['tone'] ?? ''));
      if ($tone === '')
        json_out(['ok' => false, 'error' => 'Le ton est requis — c’est le sens de la réponse.'], 400);
      $instr = trim((string) ($b['instructions'] ?? ''));
      $ex    = trim((string) ($b['example_reply'] ?? ''));
      $gid = (int) ($b['id'] ?? 0);
      if ($gid) {
        q("UPDATE ws_review_guidelines SET note_min=?, note_max=?, tone=?, instructions=?, example_reply=? WHERE id=?",
          [$nMin, $nMax, $tone, ($instr !== '' ? $instr : null), ($ex !== '' ? $ex : null), $gid]);
      } else {
        q("INSERT INTO ws_review_guidelines (note_min, note_max, tone, instructions, example_reply) VALUES (?,?,?,?,?)",
          [$nMin, $nMax, $tone, ($instr !== '' ? $instr : null), ($ex !== '' ? $ex : null)]);
        $gid = (int) db()->lastInsertId();
      }
      json_out(['ok' => true, 'id' => $gid]);
    }

    /* ── Cartes de fidélité par produit (PWA) — paramétrage MARQUE.
       Tables pwa_loyalty_card / pwa_loyalty_config (base partagée), créées par
       la migration PWA 025 (dépôt latelier-by-pwa, docs/LOYALTY.md §4) ; la PWA
       en dérive les tampons de ses clients (un tampon par unité achetée, sans
       coefficient — les ×2 ne jouent que sur les points). Ici la marque définit
       les cartes (famille, illustration, couleurs, seuil, récompense, mots-clés,
       portée) et la jauge points → bon d'achat. 501 tant que la migration PWA
       n'est pas passée : rien n'est créé d'ici. ── */
    $lcMissing = 'table pwa_loyalty_card absente — migration PWA 025 (migrate.yml du dépôt latelier-by-pwa) non jouée';
    if ($m === 'GET' && $p === '/franchisor/loyalty-cards') {
      if (!$tblExists('pwa_loyalty_card')) json_out(['error' => $lcMissing], 501);
      $cards = rows("SELECT id, card_code, name, img, color_tint, color_fill, color_accent, stamps_required,
                            reward_label, reward_validity_days, match_keywords, cafe_cat_codes, shop_id, active, sort_order
                       FROM pwa_loyalty_card ORDER BY sort_order, id");
      // Usage réseau, lecture seule : tampons en cours et récompenses prêtes par carte.
      $usage = []; $ready = [];
      if ($tblExists('pwa_loyalty_stamp'))
        foreach (rows("SELECT card_id, COUNT(*) n, COUNT(DISTINCT client_id) c FROM pwa_loyalty_stamp WHERE reward_id IS NULL GROUP BY card_id") as $u)
          $usage[(int) $u['card_id']] = ['stamps' => (int) $u['n'], 'clients' => (int) $u['c']];
      if ($tblExists('pwa_loyalty_reward'))
        foreach (rows("SELECT card_id, COUNT(*) n FROM pwa_loyalty_reward WHERE status = 'ready' GROUP BY card_id") as $u)
          $ready[(int) $u['card_id']] = (int) $u['n'];
      foreach ($cards as &$c) {
        $c['id'] = (int) $c['id'];
        $c['stamps_required'] = (int) $c['stamps_required'];
        $c['reward_validity_days'] = (int) $c['reward_validity_days'];
        $c['shop_id'] = $c['shop_id'] !== null ? (int) $c['shop_id'] : null;
        $c['active'] = (int) $c['active'];
        $c['sort_order'] = (int) $c['sort_order'];
        $c['usage'] = $usage[$c['id']] ?? ['stamps' => 0, 'clients' => 0];
        $c['ready'] = $ready[$c['id']] ?? 0;
      }
      unset($c);
      $cfg = null;
      if ($tblExists('pwa_loyalty_config')) {
        $hasV = col_exists('pwa_loyalty_config', 'voucher_points');
        $r = row("SELECT euros_per_point, points_per_step, active" . ($hasV ? ", voucher_points, voucher_value, voucher_label" : "") . " FROM pwa_loyalty_config WHERE id = 1");
        if ($r) $cfg = [
          'euros_per_point' => (float) $r['euros_per_point'],
          'points_per_step' => (int) $r['points_per_step'],
          'active'          => (int) $r['active'],
          'voucher_points'  => $hasV ? (int) $r['voucher_points'] : 100,
          'voucher_value'   => $hasV ? (float) $r['voucher_value'] : 5.0,
          'voucher_label'   => $hasV ? $r['voucher_label'] : null,
          'voucher_editable'=> $hasV,
        ];
      }
      $cats = $tblExists('pwa_cafe_categories') ? rows("SELECT cat_code AS code, label FROM pwa_cafe_categories ORDER BY sort_order, id") : [];
      $shops = [];
      if ($tblExists('shops')) {
        $hasCity = col_exists('shops', 'city');
        $shops = rows("SELECT id, name" . ($hasCity ? ", city" : ", NULL AS city") . " FROM shops WHERE active = 1 ORDER BY name");
        foreach ($shops as &$sh) $sh['id'] = (int) $sh['id'];
        unset($sh);
      }
      json_out(['cards' => $cards, 'config' => $cfg, 'cafeCategories' => $cats, 'shops' => $shops,
                'pwaBase' => rtrim((string) ws_param('pwa_url', ''), '/')]);
    }
    if ($m === 'POST' && $p === '/franchisor/loyalty-card') {
      if (!$tblExists('pwa_loyalty_card')) json_out(['ok' => false, 'error' => $lcMissing], 501);
      $b = body();
      $cid = (int) ($b['id'] ?? 0);
      if (!empty($b['delete'])) {
        if (!$cid) json_out(['ok' => false, 'error' => 'id requis'], 400);
        $n = $tblExists('pwa_loyalty_stamp') ? (int) (row("SELECT COUNT(*) n FROM pwa_loyalty_stamp WHERE card_id = ?", [$cid])['n'] ?? 0) : 0;
        if ($n > 0)
          json_out(['ok' => false, 'error' => "Cette carte porte déjà $n tampon(s) client : désactivez-la plutôt que de la supprimer (les tampons seraient perdus)."], 409);
        q("DELETE FROM pwa_loyalty_card WHERE id = ?", [$cid]);
        json_out(['ok' => true]);
      }
      $name = trim((string) ($b['name'] ?? ''));
      if ($name === '') json_out(['ok' => false, 'error' => 'Le nom de la carte est requis.'], 400);
      $code = strtolower(trim((string) ($b['card_code'] ?? '')));
      if ($code === '') { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name); $code = strtolower($t !== false ? $t : $name); }
      $code = trim(preg_replace('/[^a-z0-9]+/', '-', $code) ?? '', '-');
      if ($code === '') json_out(['ok' => false, 'error' => 'Code de carte invalide (lettres, chiffres, tirets).'], 400);
      $img = trim((string) ($b['img'] ?? ''));
      if ($img === '') json_out(['ok' => false, 'error' => 'L’illustration est requise — chemin d’image côté PWA (ex. img/products/bread-1-240.webp).'], 400);
      $hex = function ($v, $def, $what) {
        $v = trim((string) $v);
        if ($v === '') return $def;
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $v)) json_out(['ok' => false, 'error' => "Couleur « $what » invalide (format #RRGGBB)."], 400);
        return $v;
      };
      $tint   = $hex($b['color_tint'] ?? '',   '#FBEBD2', 'fond');
      $fill   = $hex($b['color_fill'] ?? '',   '#FAC775', 'tampon');
      $accent = $hex($b['color_accent'] ?? '', '#c17a2a', 'accent');
      $req = (int) ($b['stamps_required'] ?? 10);
      if ($req < 1 || $req > 50) json_out(['ok' => false, 'error' => 'Le nombre de tampons requis doit être entre 1 et 50.'], 400);
      $reward = trim((string) ($b['reward_label'] ?? ''));
      if ($reward === '') json_out(['ok' => false, 'error' => 'La récompense est requise (ex. « 1 pain offert »).'], 400);
      $days = max(0, (int) ($b['reward_validity_days'] ?? 30));
      $csv = function ($v) { return implode(',', array_values(array_filter(array_map('trim', explode(',', (string) $v)), 'strlen'))); };
      $kw   = $csv($b['match_keywords'] ?? '');
      $cats = $csv($b['cafe_cat_codes'] ?? '');
      $shop = (isset($b['shop_id']) && $b['shop_id'] !== '' && $b['shop_id'] !== null) ? (int) $b['shop_id'] : null;
      $active = !empty($b['active']) ? 1 : 0;
      $sort = (int) ($b['sort_order'] ?? 0);
      if (row("SELECT id FROM pwa_loyalty_card WHERE card_code = ? AND id <> ?", [$code, $cid]))
        json_out(['ok' => false, 'error' => "Le code « $code » est déjà utilisé par une autre carte."], 409);
      if ($cid) {
        if (!row("SELECT id FROM pwa_loyalty_card WHERE id = ?", [$cid])) json_out(['ok' => false, 'error' => 'Carte introuvable.'], 404);
        q("UPDATE pwa_loyalty_card SET card_code=?, name=?, img=?, color_tint=?, color_fill=?, color_accent=?, stamps_required=?,
              reward_label=?, reward_validity_days=?, match_keywords=?, cafe_cat_codes=?, shop_id=?, active=?, sort_order=? WHERE id=?",
          [$code, $name, $img, $tint, $fill, $accent, $req, $reward, $days, $kw, $cats, $shop, $active, $sort, $cid]);
      } else {
        q("INSERT INTO pwa_loyalty_card (card_code, name, img, color_tint, color_fill, color_accent, stamps_required,
              reward_label, reward_validity_days, match_keywords, cafe_cat_codes, shop_id, active, sort_order)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
          [$code, $name, $img, $tint, $fill, $accent, $req, $reward, $days, $kw, $cats, $shop, $active, $sort]);
        $cid = (int) db()->lastInsertId();
      }
      json_out(['ok' => true, 'id' => $cid, 'card_code' => $code]);
    }
    if ($m === 'POST' && $p === '/franchisor/loyalty-config') {
      if (!$tblExists('pwa_loyalty_config')) json_out(['ok' => false, 'error' => 'table pwa_loyalty_config absente — schéma PWA non installé'], 501);
      if (!col_exists('pwa_loyalty_config', 'voucher_points'))
        json_out(['ok' => false, 'error' => 'colonnes voucher_* absentes — migration PWA 025 non jouée'], 501);
      $b = body();
      $vp  = (int) ($b['voucher_points'] ?? 0);
      $vv  = (float) str_replace(',', '.', (string) ($b['voucher_value'] ?? '0'));
      $epp = (float) str_replace(',', '.', (string) ($b['euros_per_point'] ?? '0'));
      $vl  = trim((string) ($b['voucher_label'] ?? ''));
      if ($vp < 1)   json_out(['ok' => false, 'error' => 'Le seuil de points du bon doit être ≥ 1.'], 400);
      if ($vv <= 0)  json_out(['ok' => false, 'error' => 'La valeur du bon doit être > 0.'], 400);
      if ($epp <= 0) json_out(['ok' => false, 'error' => 'Le montant d’achat par point doit être > 0.'], 400);
      if (row("SELECT id FROM pwa_loyalty_config WHERE id = 1"))
        q("UPDATE pwa_loyalty_config SET euros_per_point=?, voucher_points=?, voucher_value=?, voucher_label=? WHERE id = 1",
          [$epp, $vp, $vv, ($vl !== '' ? $vl : null)]);
      else
        q("INSERT INTO pwa_loyalty_config (id, euros_per_point, points_per_step, active, voucher_points, voucher_value, voucher_label) VALUES (1, ?, 1, 1, ?, ?, ?)",
          [$epp, $vp, $vv, ($vl !== '' ? $vl : null)]);
      json_out(['ok' => true]);
    }

    /* ── Connexion Google Business Profile + clés du circuit avis — état
       sondé EN DIRECT (rien de mémorisé). `cles` dit ce qui est posé en
       ws_param ; `ok` dit si le refresh token s'échange et si accounts.list
       répond — le message d'échec vient de Google, verbatim. ── */
    if ($m === 'GET' && $p === '/franchisor/gbp-status') {
      $cles9 = [
        'places'    => ws_param('google_api_key', '') !== '',
        'anthropic' => ws_param('anthropic_api_key', '') !== '',
        'client'    => ws_param('google_oauth_client_id', '') !== '',
        'secret'    => ws_param('google_oauth_client_secret', '') !== '',
        'refresh'   => ws_param('google_oauth_refresh_token', '') !== '',
      ];
      $nShops9 = (int) (row("SELECT COUNT(*) n FROM ws_param WHERE param_key LIKE 'google_oauth_refresh_token_shop_%'
                                AND param_value <> ''")['n'] ?? 0);
      $why9 = null; $tok9 = gbp_token($why9);
      if (!$tok9) json_out(['cles' => $cles9, 'boutiques' => $nShops9, 'ok' => false, 'why' => $why9]);
      $acc9 = gbp_http('GET', 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts',
                       ['Authorization' => 'Bearer ' . $tok9]);
      if (!is_array($acc9) || isset($acc9['error']))
        json_out(['cles' => $cles9, 'boutiques' => $nShops9, 'ok' => false,
                  'why' => 'Jeton échangé, mais accounts.list échoue : '
                    . (is_array($acc9) ? ((string) ($acc9['error']['message'] ?? 'refus')) : 'API injoignable')
                    . ' — l’accès « Business Profile API » est-il approuvé et activé sur le projet Cloud ?']);
      json_out(['cles' => $cles9, 'boutiques' => $nShops9, 'ok' => true,
                'comptes' => array_map(fn ($a9) => (string) ($a9['accountName'] ?? ($a9['name'] ?? '—')),
                                       (array) ($acc9['accounts'] ?? []))]);
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

    /* SUPPRESSION d'un menu — CÔTÉ SERVEUR. L'écran effaçait sa copie locale
       seulement : au rechargement, le menu revenait de la base (constaté par
       l'utilisateur — « quand j'efface des menus et que je fais un deep
       refresh ils reviennent »). Ce n'était pas un cache : l'écriture
       n'existait pas.
       Ce qui part : formules (choix, étapes, compositions) et déclencheurs.
       Le PRODUIT porteur ne part QUE s'il n'est que ça — la signature des
       porteurs nés à l'écran (cat_id NULL + menu_override='on'). Un vrai
       produit du catalogue qui portait une formule garde sa fiche : on lui
       retire seulement son rôle de menu (menu_override remis à NULL). */
    if ($m === 'POST' && $p === '/franchisor/menu-delete') {
      $b = body(); $pid = (int) ($b['product_id'] ?? 0);
      if (!$pid) json_out(['error' => 'product_id requis'], 400);
      $prod = row("SELECT id, cat_id, menu_override FROM ws_products WHERE id = ?", [$pid]);
      if (!$prod) json_out(['ok' => false, 'error' => 'Menu inconnu en base.'], 400);
      q("DELETE c FROM ws_bundle_slot_choices c JOIN ws_bundle_slots s ON s.id=c.slot_id JOIN ws_bundles bu ON bu.id=s.bundle_id WHERE bu.product_id=?", [$pid]);
      q("DELETE s FROM ws_bundle_slots s JOIN ws_bundles bu ON bu.id=s.bundle_id WHERE bu.product_id=?", [$pid]);
      q("DELETE FROM ws_bundles WHERE product_id=?", [$pid]);
      if (tbl_exists('ws_bundle_triggers')) q("DELETE FROM ws_bundle_triggers WHERE product_id=?", [$pid]);
      /* Porteur = produit SANS catégorie : un vrai article en a toujours une
         (la sonde le vérifie chaque nuit). menu_override ne suffit pas comme
         marqueur — il peut avoir été remis à NULL en route. */
      $porteur = $prod['cat_id'] === null;
      if ($porteur) q("DELETE FROM ws_products WHERE id=?", [$pid]);
      else q("UPDATE ws_products SET menu_override=NULL WHERE id=?", [$pid]);
      $audit('menu.delete', 'ws_bundles', $pid, null, ['porteur_supprime' => $porteur]);
      json_out(['ok' => true, 'porteur_supprime' => $porteur]);
    }

    /* Déclencheurs d'un menu (0078) — REMPLACEMENT COMPLET, comme un
       formulaire : la multi-sélection de l'écran envoie l'état entier, la
       route efface puis réécrit. Chaque entrée : cat_id, et sub_cat_id pour le
       « ET catégorie + sous-catégorie ». Une sous-catégorie est toujours
       validée contre sa catégorie — un couple incohérent est refusé, pas
       corrigé en silence. */
    if ($m === 'POST' && $p === '/franchisor/menu-triggers') {
      if (!tbl_exists('ws_bundle_triggers'))
        json_out(['ok' => false, 'error' => 'Table ws_bundle_triggers absente — migration 0078 non appliquée sur ce serveur.'], 501);
      $b = body(); $pid = (int) ($b['product_id'] ?? 0);
      if (!$pid) json_out(['error' => 'product_id requis'], 400);
      /* L'ORDRE VOULU : déclencheurs D'ABORD, formules ensuite (retour
         utilisateur — l'étape 2 précède l'étape 3 à l'écran aussi). On exige
         donc seulement que le menu EXISTE en produit ; sans formule active,
         ses déclencheurs sont inertes côté client (has_menu_options ne les
         compte qu'avec une formule active) et s'animent dès la première. */
      if (!row("SELECT 1 x FROM ws_products WHERE id = ?", [$pid]))
        json_out(['ok' => false, 'error' => 'Menu inconnu en base — enregistrez-le d’abord (il se crée à sa première sauvegarde).'], 400);
      $in = is_array($b['triggers'] ?? null) ? $b['triggers'] : [];
      $hasArt = col_exists('ws_bundle_triggers', 'article_id');
      $rows2 = [];
      foreach ($in as $t) {
        $cid = (int) ($t['cat_id'] ?? 0);
        $sid = isset($t['sub_cat_id']) && $t['sub_cat_id'] !== null && $t['sub_cat_id'] !== '' ? (int) $t['sub_cat_id'] : null;
        $aid = $hasArt && isset($t['article_id']) && $t['article_id'] !== null && $t['article_id'] !== '' ? (int) $t['article_id'] : null;
        if (!$cid) json_out(['ok' => false, 'error' => 'Déclencheur sans cat_id.'], 400);
        if (!row("SELECT 1 x FROM ws_categories WHERE id = ?", [$cid]))
          json_out(['ok' => false, 'error' => "Catégorie #$cid inconnue."], 400);
        if ($sid !== null && !row("SELECT 1 x FROM ws_category_subs WHERE id = ? AND category_id = ?", [$sid, $cid]))
          json_out(['ok' => false, 'error' => "Sous-catégorie #$sid absente de la catégorie #$cid."], 400);
        // L'ARTICLE (0081) doit appartenir au périmètre qu'il restreint —
        // hors périmètre, refus explicite, pas de correction silencieuse.
        if ($aid !== null && !row("SELECT 1 x FROM ws_products WHERE id = ? AND cat_id = ?" .
              ($sid !== null ? " AND sub_cat_id = ?" : ""),
              $sid !== null ? [$aid, $cid, $sid] : [$aid, $cid]))
          json_out(['ok' => false, 'error' => "Article #$aid hors du périmètre du déclencheur."], 400);
        $rows2[] = [$cid, $sid, $aid];
      }
      q("DELETE FROM ws_bundle_triggers WHERE product_id = ?", [$pid]);
      foreach ($rows2 as [$cid, $sid, $aid])
        q("INSERT IGNORE INTO ws_bundle_triggers (product_id, cat_id, sub_cat_id" . ($hasArt ? ", article_id" : "") . ")
           VALUES (?,?,?" . ($hasArt ? ",?" : "") . ")",
          $hasArt ? [$pid, $cid, $sid, $aid] : [$pid, $cid, $sid]);
      $audit('menu.triggers', 'ws_bundle_triggers', $pid, null, ['n' => count($rows2), 'triggers' => $rows2]);
      json_out(['ok' => true, 'n' => count($rows2)]);
    }


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
    // ── Portions réseau (ERP, lecture seule) — vue FRANCHISOR : quels produits
    //    sont portionnables, quelles portions sont actives, et les prix posés
    //    par boutique (shop_product_portion_price). ──
    if ($m === 'GET' && $p === '/franchisor/erp-portion-rules') {
      /* SOURCE : L'ENDPOINT, un appel par boutique. Remplace une lecture de
         product_portion × shop_product_portion_price. Il n'existe pas
         d'endpoint réseau qui donnerait les prix des quatre boutiques d'un
         coup ; chaque appel est mis en cache, la vue en fait donc quatre au
         plus par requête. */
      if (!function_exists('erp_portions_reseau')) json_out([]);
      $shops = [];
      try { foreach (rows("SELECT id FROM $SHOPS WHERE webshop_enabled = 1 ORDER BY id") as $sr)
              $shops[] = (int) $sr['id']; } catch (Throwable $e) {}
      $donnees = erp_portions_reseau($shops);
      if (!is_array($donnees)) json_out([]);   // ERP muet : rien à montrer, rien d'inventé
      $eur = fn ($v) => number_format((float) $v, 2, ',', ' ') . ' €';
      $out = [];
      foreach ($donnees as $v) {
        $portions = ['Entière'];
        $parts    = ['Entière ' . ($v['prix_piece'] !== null ? $eur($v['prix_piece']) : 'sans prix')];
        foreach ($v['portions'] as $q) {
          $portions[] = $q['label'];
          /* « sans prix » est une information : une portion qu'aucune boutique
             n'a tarifée n'est pas vendable, et l'écran doit le dire. */
          $parts[] = $q['prix']
            ? ($q['label'] . ' ' . implode(' / ', array_map(
                 fn ($m2, $sid2) => $eur($m2) . ' (boutique ' . (int) $sid2 . ')',
                 $q['prix'], array_keys($q['prix']))))
            : ($q['label'] . ' : sans prix');
        }
        $out[] = ['produit' => $v['produit'], 'cat' => $v['cat'],
                  'portions' => $portions, 'prix' => implode(' · ', $parts)];
      }
      json_out($out);
    }

    /* ── VENTES CROISÉES — paramétrage marque (migration 0056). ─────────────
       Lecture, écriture et suppression par l'API : aucune manipulation directe
       en base depuis un back-office. Une règle est écrite d'un bloc — la règle
       et ses listes (déclencheurs, suggestions, boutiques) forment un tout, et
       une écriture partielle produirait une règle qui déclenche sans rien
       proposer, ou l'inverse. D'où la transaction. ── */
    if ($m === 'GET' && $p === '/franchisor/cross-sell') {
      if (!xsell_tbl('ws_cross_sell_rule')) json_out([]);
      $hasSub8 = col_exists('ws_cross_sell_trigger', 'sub_id');
      $out = [];
      foreach (rows("SELECT * FROM ws_cross_sell_rule ORDER BY active DESC, id DESC") as $r) {
        $rid = (int) $r['id'];
        $nm  = fn ($ids) => $ids ? rows("SELECT id, name FROM ws_products WHERE id IN ("
                 . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY name", $ids) : [];
        $tp  = []; $tc = []; $ts = [];
        foreach (rows("SELECT product_id, cat_id" . ($hasSub8 ? ", sub_id" : "") . " FROM ws_cross_sell_trigger WHERE rule_id=?", [$rid]) as $t) {
          if ($t['product_id'] !== null) $tp[] = (int) $t['product_id'];
          if ($t['cat_id']     !== null) $tc[] = (int) $t['cat_id'];
          if ($hasSub8 && ($t['sub_id'] ?? null) !== null) $ts[] = (int) $t['sub_id'];
        }
        $tg = rows("SELECT t.product_id, t.sort_order, p.name
                      FROM ws_cross_sell_target t LEFT JOIN ws_products p ON p.id = t.product_id
                     WHERE t.rule_id=? ORDER BY t.sort_order, t.id", [$rid]);
        // Mesure agrégée : ce que la règle a rapporté depuis sa création.
        $st = xsell_tbl('ws_cross_sell_stat')
          ? row("SELECT COALESCE(SUM(impressions),0) i, COALESCE(SUM(adds),0) a
                   FROM ws_cross_sell_stat WHERE rule_id=?", [$rid]) : null;
        $imp = (int) ($st['i'] ?? 0); $add = (int) ($st['a'] ?? 0);
        $out[] = $r + [
          'triggerProducts'   => $nm($tp),
          'triggerCategories' => $tc ? rows("SELECT id, label AS name FROM ws_categories WHERE id IN ("
                                     . implode(',', array_fill(0, count($tc), '?')) . ")", $tc) : [],
          'triggerSubs'       => $ts ? rows("SELECT id, label AS name FROM ws_category_subs WHERE id IN ("
                                     . implode(',', array_fill(0, count($ts), '?')) . ")", $ts) : [],
          'targets' => array_map(fn ($x) => ['id' => (int) $x['product_id'], 'name' => $x['name']], $tg),
          'shops'   => array_map(fn ($x) => (int) $x['shop_id'],
                                 rows("SELECT shop_id FROM ws_cross_sell_shop WHERE rule_id=?", [$rid])),
          'stats'   => ['impressions' => $imp, 'adds' => $add,
                        'rate' => $imp > 0 ? round($add * 100 / $imp, 1) : null],
        ];
      }
      json_out($out);
    }

    if ($m === 'POST' && $p === '/franchisor/cross-sell') {
      if (!xsell_tbl('ws_cross_sell_rule'))
        json_out(['ok' => false, 'error' => 'Tables des ventes croisées absentes — migration 0056 non passée.'], 501);
      $b    = body();
      $name = trim((string) ($b['name'] ?? ''));
      if ($name === '') json_out(['ok' => false, 'error' => 'Nom de la règle requis.'], 400);
      $trigP = array_values(array_filter(array_map('intval', (array) ($b['triggerProducts']   ?? []))));
      $trigC = array_values(array_filter(array_map('intval', (array) ($b['triggerCategories'] ?? []))));
      $trigS = array_values(array_filter(array_map('intval', (array) ($b['triggerSubs']       ?? []))));
      if ($trigS && !col_exists('ws_cross_sell_trigger', 'sub_id'))
        json_out(['ok' => false, 'error' => 'Déclencheur sous-catégorie indisponible — migration 0085 non passée.'], 501);
      $targs = array_values(array_filter(array_map('intval', (array) ($b['targets']           ?? []))));
      // Une règle sans déclencheur ne se déclenche jamais ; sans suggestion elle
      // ne propose rien. Les deux cas sont des règles mortes : on refuse plutôt
      // que d'enregistrer quelque chose qui ne fera rien sans le dire.
      if (!$trigP && !$trigC && !$trigS) json_out(['ok' => false, 'error' => 'Au moins un produit ou une sous-catégorie déclencheur.'], 400);
      if (!$targs)            json_out(['ok' => false, 'error' => 'Au moins un produit à suggérer.'], 400);
      $dOk = fn ($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
      $hOk = fn ($v) => (is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) ? $v . ':00' : null;
      $wd  = implode(',', array_values(array_filter(array_map('intval', (array) ($b['weekdays'] ?? [])),
                                                    fn ($d) => $d >= 1 && $d <= 7)));
      $vals = [
        'name'            => mb_substr($name, 0, 120),
        'date_from'       => $dOk($b['dateFrom'] ?? null),
        'date_to'         => $dOk($b['dateTo'] ?? null),
        'hour_from'       => $hOk($b['hourFrom'] ?? null),
        'hour_to'         => $hOk($b['hourTo'] ?? null),
        'weekdays'        => $wd !== '' ? $wd : null,
        'match_mode'      => (($b['matchMode'] ?? '') === 'all') ? 'all' : 'any',
        'channel'         => in_array($b['channel'] ?? '', ['collect', 'delivery'], true) ? $b['channel'] : 'both',
        'placement'       => implode(',', array_values(array_intersect(
                               array_map('strval', (array) ($b['placement'] ?? ['cart'])),
                               ['cart', 'checkout', 'product']))) ?: 'cart',
        'max_suggestions' => max(1, min(6, (int) ($b['maxSuggestions'] ?? 2))),
        'active'          => !empty($b['active']) ? 1 : 0,
      ];
      $rid  = (int) ($b['id'] ?? 0);
      $pdoX = db(); $ownX = !$pdoX->inTransaction();
      if ($ownX) $pdoX->beginTransaction();
      try {
        if ($rid) {
          q("UPDATE ws_cross_sell_rule SET " . implode(',', array_map(fn ($c) => "$c=?", array_keys($vals)))
            . " WHERE id=?", array_merge(array_values($vals), [$rid]));
          q("DELETE FROM ws_cross_sell_trigger WHERE rule_id=?", [$rid]);
          q("DELETE FROM ws_cross_sell_target  WHERE rule_id=?", [$rid]);
          q("DELETE FROM ws_cross_sell_shop    WHERE rule_id=?", [$rid]);
        } else {
          q("INSERT INTO ws_cross_sell_rule (" . implode(',', array_keys($vals)) . ") VALUES ("
            . implode(',', array_fill(0, count($vals), '?')) . ")", array_values($vals));
          $rid = (int) $pdoX->lastInsertId();
        }
        foreach ($trigP as $pid6) q("INSERT INTO ws_cross_sell_trigger (rule_id, product_id) VALUES (?,?)", [$rid, $pid6]);
        foreach ($trigC as $cid6) q("INSERT INTO ws_cross_sell_trigger (rule_id, cat_id) VALUES (?,?)", [$rid, $cid6]);
        foreach ($trigS as $sid7) q("INSERT INTO ws_cross_sell_trigger (rule_id, sub_id) VALUES (?,?)", [$rid, $sid7]);
        foreach ($targs as $i6 => $pid6)
          q("INSERT INTO ws_cross_sell_target (rule_id, product_id, sort_order) VALUES (?,?,?)", [$rid, $pid6, $i6]);
        foreach (array_values(array_filter(array_map('intval', (array) ($b['shops'] ?? [])))) as $sid6)
          q("INSERT IGNORE INTO ws_cross_sell_shop (rule_id, shop_id) VALUES (?,?)", [$rid, $sid6]);
        if ($ownX) $pdoX->commit();
      } catch (Throwable $e) {
        if ($ownX && $pdoX->inTransaction()) $pdoX->rollBack();
        json_out(['ok' => false, 'error' => 'Règle non enregistrée (aucune modification appliquée) : ' . $e->getMessage()], 500);
      }
      json_out(['ok' => true, 'id' => $rid]);
    }

    if ($m === 'DELETE' && $p === '/franchisor/cross-sell') {
      if (!xsell_tbl('ws_cross_sell_rule')) json_out(['ok' => false, 'error' => 'tables absentes'], 501);
      $rid = (int) (qp('id') ?: (body()['id'] ?? 0));
      if (!$rid) json_out(['ok' => false, 'error' => 'id requis'], 400);
      foreach (['ws_cross_sell_trigger', 'ws_cross_sell_target', 'ws_cross_sell_shop', 'ws_cross_sell_pause'] as $t6)
        if (xsell_tbl($t6)) q("DELETE FROM $t6 WHERE rule_id=?", [$rid]);
      q("DELETE FROM ws_cross_sell_rule WHERE id=?", [$rid]);
      // Les statistiques SURVIVENT à la suppression de la règle : effacer
      // l'historique de ce qui a été vendu grâce à elle rendrait toute
      // comparaison impossible.
      json_out(['ok' => true]);
    }

    if ($m === 'POST' && $p === '/franchisor/product') {
      $b = body(); $id = (int) ($b['id'] ?? 0);
      if (!$id) json_out(['error' => 'id requis'], 400);
      /* PLUS DE HIÉRARCHIE DE CONTRAINTES, parce qu'il ne reste plus qu'un
         seul niveau. Il y avait ici une « échelle » — au catalogue réseau >
         obligatoire > webshop > livraison bureau — où chaque barreau ne tenait
         que si celui du dessus tenait, avec ses refus et ses complétions
         automatiques. Les deux barreaux du haut ont disparu avec leurs
         colonnes (migration 0073) : brand_whitelist recouvrait les canaux sans
         le dire, brand_mandatory verrouillait un choix que la boutique ne fait
         plus.

         Restent DEUX CANAUX INDÉPENDANTS, sans ordre entre eux, et « publié »
         au-dessus des deux. Fermer les deux canaux est désormais un état
         licite — le produit reste au catalogue, simplement invendu ; c'est un
         état que la marque peut vouloir, et le refuser serait décider à sa
         place.

         Les champs brand_whitelist et brand_mandatory sont IGNORÉS s'ils
         arrivent encore : la console marque les envoie peut-être toujours, et
         un 500 sur une colonne disparue serait une panne là où il n'y a qu'un
         écran à mettre à jour. */
      /* CANAUX GÉRÉS DANS L'ERP (23/08). Quand ws_param.channels_source vaut
         'erp', publié/C&C/livraison sont pilotés par Franchise Buddy et le
         miroir les réécrit à chaque balayage (≤ 60 s) : accepter ces champs
         ici ferait CROIRE à une modification aussitôt écrasée — pire qu'un
         refus. On les IGNORE en le DISANT dans la réponse ; la console peut
         l'afficher. Le prix, le coût et les menus restent gérés ici. */
      $canauxErp = strtolower((string) (ws_param('channels_source', '') ?: '')) === 'erp';
      $sets = []; $vals = []; $ignores = [];
      foreach (['active', 'office_delivery', 'click_and_collect'] as $flag) {
        if (!array_key_exists($flag, $b)) continue;
        if ($canauxErp) { $ignores[] = $flag; continue; }
        if ($flag === 'click_and_collect' && !col_exists('ws_products', 'click_and_collect')) continue;
        $sets[] = "$flag=?"; $vals[] = !empty($b[$flag]) ? 1 : 0;
      }
      if (array_key_exists('price', $b))           { $sets[] = 'price=?';           $vals[] = (float) $b['price']; }
      if (array_key_exists('base_cost', $b))       { $sets[] = 'base_cost=?';       $vals[] = (float) $b['base_cost']; }
      if (array_key_exists('menu_override', $b))   { $sets[] = 'menu_override=?';    $vals[] = in_array($b['menu_override'], ['on','off'], true) ? $b['menu_override'] : null; }
      if (!$sets && $ignores) json_out(['ok' => true, 'ignores' => $ignores,
        'message' => 'Publié / Click & Collect / Livraison se gèrent désormais dans Franchise Buddy — champ(s) ignoré(s) ici.']);
      if (!$sets) json_out(['error' => 'rien à modifier'], 400);
      $vals[] = $id;
      q("UPDATE ws_products SET " . implode(', ', $sets) . " WHERE id=?", $vals);
      // CATÉGORIE AUTOMATIQUE : active dès qu'AU MOINS UN de ses produits est
      // en ligne, désactivée quand plus aucun ne l'est.
      if (!$canauxErp && array_key_exists('active', $b)) {
        $pc = row("SELECT cat_id FROM ws_products WHERE id=?", [$id]);
        if ($pc && $pc['cat_id'] !== null) {
          q("UPDATE ws_categories
                SET active = EXISTS(SELECT 1 FROM ws_products p2 WHERE p2.cat_id = ws_categories.id AND p2.active = 1)
              WHERE id=?", [(int) $pc['cat_id']]);
        }
      }
      $audit('product.update', 'ws_products', $id, null, $b);
      json_out($ignores
        ? ['ok' => true, 'ignores' => $ignores,
           'message' => 'Publié / Click & Collect / Livraison se gèrent désormais dans Franchise Buddy — champ(s) ignoré(s) ici.']
        : ['ok' => true]);
    }

    // Catégorie : menu par défaut (+ cascade optionnelle des flags aux produits).
    if ($m === 'POST' && $p === '/franchisor/category') {
      $b = body(); $id = (int) ($b['id'] ?? 0);
      if (!$id) json_out(['error' => 'id requis'], 400);
      /* Canaux gérés dans l'ERP : les cascades publié/C&C/livraison sont
         IGNORÉES (et dites) quand channels_source='erp' — le miroir les
         écraserait au balayage suivant. menu_default reste géré ici. */
      $canauxErp = strtolower((string) (ws_param('channels_source', '') ?: '')) === 'erp';
      $ignores = $canauxErp ? array_values(array_intersect(['active', 'office_delivery', 'click_and_collect'], array_keys($b))) : [];
      if ($canauxErp) foreach ($ignores as $f) unset($b[$f]);
      if (array_key_exists('menu_default', $b)) q("UPDATE ws_categories SET menu_default=? WHERE id=?", [!empty($b['menu_default']) ? 1 : 0, $id]);
      // Cascades canaux : un produit OBLIGATOIRE garde toujours AU MOINS UN
      // canal ouvert (webshop OU livraison bureau) — la cascade qui fermerait
      // son dernier canal l'épargne.
      if (array_key_exists('active', $b)) {
        if (!empty($b['active'])) q("UPDATE ws_products SET active=1 WHERE cat_id=?", [$id]);
        else q("UPDATE ws_products SET active=0 WHERE cat_id=?", [$id]);
      }
      if (array_key_exists('office_delivery', $b)) {
        if (!empty($b['office_delivery'])) q("UPDATE ws_products SET office_delivery=1 WHERE cat_id=?", [$id]);
        else q("UPDATE ws_products SET office_delivery=0 WHERE cat_id=?", [$id]);
      }
      /* Canal webshop (0071) — la cascade par catégorie, symétrique de celle du
         bureau juste au-dessus. La console marque peut désormais fermer le
         webshop SANS mettre les produits en brouillon : c'est tout l'objet de
         la colonne. Aucun produit obligatoire n'est épargné ici — fermer un
         canal n'est pas le retirer du catalogue, il reste vendu sur l'autre. */
      if (array_key_exists('click_and_collect', $b) && col_exists('ws_products', 'click_and_collect')) {
        q("UPDATE ws_products SET click_and_collect=? WHERE cat_id=?", [!empty($b['click_and_collect']) ? 1 : 0, $id]);
      }
      /* Cascade « au catalogue réseau ». Un produit OBLIGATOIRE en est épargné :
         le retirer du réseau laisserait les boutiques avec un article imposé et
         invendable — même règle qu'au niveau produit, où l'opération est
         refusée. La marque lève l'obligation d'abord. */
      /* Les cascades « au catalogue réseau » et « obligatoire » sont parties
         avec leurs colonnes (0073). Un POST qui envoie encore ces champs est
         sans effet plutôt qu'en erreur : l'écran de la console marque reste à
         mettre à jour, ce n'est pas une raison de lui rendre un 500. */
      if (array_key_exists('active', $b)) {
        q("UPDATE ws_categories
              SET active = EXISTS(SELECT 1 FROM ws_products p2 WHERE p2.cat_id = ws_categories.id AND p2.active = 1)
            WHERE id=?", [$id]);
      }
      $audit('category.update', 'ws_categories', $id, null, $b);
      json_out($ignores
        ? ['ok' => true, 'ignores' => $ignores,
           'message' => 'Publié / Click & Collect / Livraison se gèrent désormais dans Franchise Buddy — cascade(s) ignorée(s) ici.']
        : ['ok' => true]);
    }

    // Sous-catégorie : cascade des flags de pilotage aux produits de la sous-catégorie
    // (ws_products.sub_cat_id). Mêmes flags que la catégorie, portée plus fine.
    if ($m === 'POST' && $p === '/franchisor/subcategory') {
      $b = body(); $id = (int) ($b['id'] ?? ($b['sub_id'] ?? 0));
      if (!$id) json_out(['error' => 'id requis'], 400);
      // Même règle que la catégorie : canaux dans l'ERP ⇒ cascades ignorées et dites.
      $canauxErp = strtolower((string) (ws_param('channels_source', '') ?: '')) === 'erp';
      $ignores = $canauxErp ? array_values(array_intersect(['active', 'office_delivery', 'click_and_collect'], array_keys($b))) : [];
      if ($canauxErp) foreach ($ignores as $f) unset($b[$f]);
      if (array_key_exists('active', $b)) {
        q("UPDATE ws_products SET active=? WHERE sub_cat_id=?", [!empty($b['active']) ? 1 : 0, $id]);
        /* RESYNC DU CACHE, comme les routes produit et catégorie. Oubliée ici,
           elle a laissé Traiteur « INACTIVE » avec 33 produits actifs (14/08) :
           l'écran Stock du jour du franchisé, qui filtrait sur ce cache, les
           cachait pendant que le BO marque et le webshop les montraient. */
        q("UPDATE ws_categories c
              SET c.active = EXISTS(SELECT 1 FROM ws_products p2 WHERE p2.cat_id = c.id AND p2.active = 1)
            WHERE c.id IN (SELECT DISTINCT cat_id FROM ws_products WHERE sub_cat_id=? AND cat_id IS NOT NULL)", [$id]);
      }
      if (array_key_exists('office_delivery', $b)) q("UPDATE ws_products SET office_delivery=? WHERE sub_cat_id=?", [!empty($b['office_delivery']) ? 1 : 0, $id]); // « Bureau »
      if (array_key_exists('click_and_collect', $b) && col_exists('ws_products', 'click_and_collect'))
                                                   q("UPDATE ws_products SET click_and_collect=? WHERE sub_cat_id=?", [!empty($b['click_and_collect']) ? 1 : 0, $id]); // canal C&C, même portée
      if (array_key_exists('menu_override', $b))   q("UPDATE ws_products SET menu_override=? WHERE sub_cat_id=?", [in_array($b['menu_override'], ['on','off'], true) ? $b['menu_override'] : null, $id]);
      $audit('subcategory.update', 'ws_category_subs', $id, null, $b);
      json_out($ignores
        ? ['ok' => true, 'ignores' => $ignores,
           'message' => 'Publié / Click & Collect / Livraison se gèrent désormais dans Franchise Buddy — cascade(s) ignorée(s) ici.']
        : ['ok' => true]);
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
      // Recherche élargie : nom, PRÉNOM, SOCIÉTÉ (« IBA » -> tous ses employés),
      // email, téléphone. La société est renvoyée pour l'afficher dans le picker.
      $hasSoc = col_exists('client', 'company_name');
      $socSel = $hasSoc ? ", company_name AS soc" : ", NULL AS soc";
      $socWhere = $hasSoc ? " OR company_name LIKE ?" : "";
      $params = [$like, $like, $like, $like, $like];
      if ($hasSoc) $params[] = $like;
      json_out(rows("SELECT id, name, surname, email, phone$socSel FROM client
                      WHERE active=1 AND (name LIKE ? OR surname LIKE ? OR email LIKE ? OR phone LIKE ? OR phone_e164 LIKE ?$socWhere)
                      ORDER BY name LIMIT 20", $params));
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

    // Constructeur de tournées — candidats « + Ajouter » : chaque bureau
    // (ws_offices) avec son nombre de sites de livraison (ws_office_delivery_sites)
    // et le coût-temps total (dépôt bureau `drop_minutes` + accès par site
    // `site_access_minutes`). Par défaut on ne liste que les bureaux ayant AU MOINS
    // un site PAS ENCORE rattaché à une tournée (tournee_id NULL) = candidats.
    //   ?all=1    → inclure aussi les sites déjà en tournée
    //   ?shopId=  → borne à une boutique (ws_office_delivery_sites.shop_id)
    //   ?q=       → filtre nom / ville
    // Shape : { id, nom, ville, offices (nb sites), min (minutes totales) } — prêt
    // pour remplacer le mock data.json du back-office franchisé.
    if ($m === 'GET' && $p === '/franchisor/tour-candidates') {
      $all    = qp('all') === '1';
      $shopId = qp('shopId');
      $qq     = trim((string) qp('q'));
      $like   = '%' . $qq . '%';
      // Colonnes temps ajoutées par migration : repli sûr (0) si absentes.
      $hasDrop = (bool) row("SELECT 1 x FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_offices' AND column_name='drop_minutes'");
      $hasAcc  = (bool) row("SELECT 1 x FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_delivery_sites' AND column_name='site_access_minutes'");
      $dropExpr = $hasDrop ? 'COALESCE(o.drop_minutes,0)' : '0';
      $accExpr  = $hasAcc  ? 'COALESCE(SUM(s.site_access_minutes),0)' : '0';
      $notInTour = $all ? '' : ' AND s.tournee_id IS NULL';
      $shopWhere = ($shopId !== null && $shopId !== '') ? ' AND s.shop_id = ?' : '';
      $params = [];
      if ($shopWhere) $params[] = $shopId;
      $params[] = $qq; $params[] = $like; $params[] = $like;
      json_out(rows(
        "SELECT o.id, o.name AS nom, o.city AS ville,
                COUNT(s.id) AS offices,
                ROUND($dropExpr + $accExpr) AS `min`
           FROM ws_offices o
           JOIN ws_office_delivery_sites s
             ON s.office_client_id = o.id AND s.active = 1$notInTour$shopWhere
          WHERE o.active = 1 AND (? = '' OR o.name LIKE ? OR o.city LIKE ?)
          GROUP BY o.id, o.name, o.city
         HAVING COUNT(s.id) > 0
          ORDER BY o.name
          LIMIT 100", $params));
    }

    if ($m === 'POST' && $p === '/franchisor/voucher') {
      $b = body(); $code = strtoupper(trim($b['code'] ?? ''));
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
      // Chemin d'écriture UNIQUE (partagé avec le BO franchisé) : ws_voucher_upsert.
      $r = ws_voucher_upsert([
        'code' => $code, 'type' => $type, 'value' => $b['value'] ?? 0,
        'min_order' => $b['min_order'] ?? 0, 'max_uses' => $b['max_uses'] ?? '',
        'expires_at' => $b['expires_at'] ?? null, 'active' => $active,
        'id_brand' => $idBrand, 'id_shop' => null,           // émetteur = MARQUE
        'target_kind' => $tkind, 'target_id' => $tid,
        'scope_product_id' => $b['scope_product_id'] ?? null, 'scope_max_qty' => $b['scope_max_qty'] ?? null,
        'per_customer' => $b['per_customer'] ?? null,
        'reason_kind' => $b['reason_kind'] ?? 'MARKETING',
        'reason_note' => $b['reason_note'] ?? null,
        'created_by'  => 'franchisor',
      ]);
      if (!empty($r['error'])) json_out(['error' => $r['error']], $r['status'] ?? 400);
      $audit('voucher.upsert', 'voucher_code', null, null, ['code' => $r['code']]);
      json_out(['ok' => true, 'code' => $r['code']]);
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

    /* Référentiel des SECTIONS du back-office franchisé + rôles prédéfinis.
       SOURCE UNIQUE : la console franchiseur affiche ses cases à cocher depuis
       cette liste, le back-office franchisé applique les mêmes clés. Éviter de
       dupliquer les 37 clés d'écran dans deux fronts qui divergeraient. */
    if ($m === 'GET' && $p === '/franchisor/bo-sections') {
      $cat = bo_sections_catalog();
      $groupes = [];
      foreach ($cat as $grp => $items) {
        $groupes[] = ['groupe' => $grp,
                      'sections' => array_map(static fn ($k, $lab) => ['key' => $k, 'label' => $lab],
                                              array_keys($items), array_values($items))];
      }
      $toutes = [];
      foreach ($cat as $items) { foreach ($items as $k => $lab) $toutes[] = $k; }
      json_out(['groupes' => $groupes, 'roles' => bo_role_presets(), 'total' => count($toutes)]);
    }

    /* ══ PROFILS TABLETTE (marque) ═══════════════════════════════════════════
       La MARQUE définit les profils et, pour chacun, les sections du
       back-office qu'ils ouvrent. Elle ne crée PAS les comptes : le personnel
       d'une boutique est connu du franchisé, pas du réseau. Le franchisé crée
       ses comptes (nom + PIN) et leur attribue un de ces profils — il ne peut
       donc jamais s'octroyer un accès que la marque n'a pas prévu. ── */
    if ($m === 'GET' && $p === '/franchisor/bo-roles') {
      if (!$tblExists('bo_role')) json_vide(['bo_role']);
      $rs = rows("SELECT r.id, r.role_key, r.label, r.sections, r.active,
                         (SELECT COUNT(*) FROM bo_users u WHERE u.role_id = r.id) AS nb_comptes
                    FROM bo_role r ORDER BY r.active DESC, r.label");
      json_out(array_map(static function ($r) {
        $sec = $r['sections'] ? (json_decode((string) $r['sections'], true) ?: []) : [];
        return ['id' => (int) $r['id'], 'key' => $r['role_key'], 'label' => $r['label'],
                'sections' => $sec, 'nbSections' => count($sec),
                'active' => (bool) $r['active'], 'nbComptes' => (int) $r['nb_comptes']];
      }, $rs));
    }

    // Création / mise à jour d'un profil. Les sections sont la seule chose qui
    // donne des droits ; le libellé n'est qu'un nom d'usage.
    if ($m === 'POST' && $p === '/franchisor/bo-role') {
      if (!$tblExists('bo_role')) json_out(['ok' => false, 'error' => 'table bo_role absente (migration 0050)'], 501);
      $b = body();
      $id    = (int) ($b['id'] ?? 0);
      $label = trim((string) ($b['label'] ?? ''));
      if ($label === '') json_out(['ok' => false, 'error' => 'libellé du profil requis'], 400);
      $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($b['key'] ?? '')));
      if ($key === '') {
        $key = preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $label) ?: $label));
        $key = trim(preg_replace('/_+/', '_', $key), '_');
      }
      if ($key === '') json_out(['ok' => false, 'error' => 'clé de profil impossible à déduire du libellé'], 400);
      $sections = [];
      foreach ((array) ($b['sections'] ?? []) as $s2) {
        $s2 = preg_replace('/[^A-Za-z0-9_]/', '', (string) $s2);
        if ($s2 !== '' && !in_array($s2, $sections, true)) $sections[] = $s2;
      }
      if (!$sections) json_out(['ok' => false, 'error' => 'un profil sans aucune section ne donnerait accès à rien'], 400);
      $active = array_key_exists('active', $b) ? (!empty($b['active']) ? 1 : 0) : 1;
      $secJson = json_encode(array_values($sections), JSON_UNESCAPED_UNICODE);
      $dup = row("SELECT id FROM bo_role WHERE role_key = ?" . ($id ? " AND id <> " . (int) $id : ""), [$key]);
      if ($dup) json_out(['ok' => false, 'error' => 'un profil porte déjà la clé « ' . $key . ' »'], 409);
      if ($id) {
        q("UPDATE bo_role SET role_key=?, label=?, sections=?, active=? WHERE id=?", [$key, $label, $secJson, $active, $id]);
      } else {
        q("INSERT INTO bo_role (role_key, label, sections, active) VALUES (?,?,?,?)", [$key, $label, $secJson, $active]);
        $id = (int) db()->lastInsertId();
      }
      $audit('bo_role.upsert', 'bo_role', $id, null, ['label' => $label, 'sections' => count($sections)]);
      json_out(['ok' => true, 'id' => $id, 'key' => $key, 'nbSections' => count($sections)]);
    }

    // Retrait d'un profil. Un profil PORTÉ par des comptes n'est pas supprimé :
    // il est désactivé — sinon ces comptes perdraient leurs droits sans trace,
    // et la tablette se fermerait au milieu d'un service.
    if ($m === 'POST' && $p === '/franchisor/bo-role-delete') {
      if (!$tblExists('bo_role')) json_out(['ok' => false, 'error' => 'table bo_role absente'], 501);
      $b = body(); $id = (int) ($b['id'] ?? 0);
      if (!$id) json_out(['ok' => false, 'error' => 'id requis'], 400);
      $n = (int) (row("SELECT COUNT(*) n FROM bo_users WHERE role_id = ?", [$id])['n'] ?? 0);
      if ($n > 0) {
        q("UPDATE bo_role SET active = 0 WHERE id = ?", [$id]);
        $audit('bo_role.disable', 'bo_role', $id, null, ['comptes' => $n]);
        json_out(['ok' => true, 'desactive' => true, 'comptes' => $n,
                  'message' => $n . ' compte(s) utilisent ce profil : il est désactivé (plus attribuable) mais conservé.']);
      }
      q("DELETE FROM bo_role WHERE id = ?", [$id]);
      $audit('bo_role.delete', 'bo_role', $id, null, null);
      json_out(['ok' => true, 'desactive' => false]);
    }

    // Création des profils standard — action EXPLICITE de la marque, jamais un
    // remplissage automatique. N'écrase aucun profil existant (clé unique).
    if ($m === 'POST' && $p === '/franchisor/bo-roles-init') {
      if (!$tblExists('bo_role')) json_out(['ok' => false, 'error' => 'table bo_role absente (migration 0050)'], 501);
      $std = bo_role_presets();
      $n = 0;
      foreach ($std as $r) {
        if (row("SELECT id FROM bo_role WHERE role_key = ?", [$r['key']])) continue;
        q("INSERT INTO bo_role (role_key, label, sections, active) VALUES (?,?,?,1)",
          [$r['key'], $r['label'], json_encode($r['sections'], JSON_UNESCAPED_UNICODE)]);
        $n++;
      }
      $audit('bo_role.init', 'bo_role', null, null, ['crees' => $n]);
      json_out(['ok' => true, 'crees' => $n]);
    }

    // Menu builder — remplace transactionnellement TOUT l'arbre d'un produit
    // (ws_bundles → slots → choices) + champs menu du produit. Évite la désync
    // d'ids : le front édite en local, on réécrit l'arbre en base à chaque save.
    if ($m === 'POST' && $p === '/franchisor/menu') {
      $b = body(); $pid = (int) ($b['productId'] ?? 0);
      $creation = false;
      /* MENU CRÉÉ À L'ÉCRAN : « + Créer un menu » forge un identifiant local
         (menu-xxx) que le cast (int) transformait en 0 → 400. Un tel menu ne
         pouvait DONC JAMAIS être enregistré — ni ses formules ni, depuis la
         0078, ses déclencheurs (constaté par l'utilisateur : « Enregistrez
         d'abord une formule… » en boucle). À la première sauvegarde, on crée
         ici le produit-menu réel et on REND SON IDENTIFIANT : l'écran bascule
         dessus et tout le reste (formules, déclencheurs, commandes) suit. */
      if (!$pid && ($b['productName'] ?? '') !== '' && !is_numeric($b['productId'] ?? null)) {
        // active = 0 : le PORTEUR d'un menu n'est pas un article — il ne doit
        // pas apparaître dans la grille du webshop (constaté : « Menu ! » y
        // figurait en carte à 8,90 €). Le menu n'atteint le client qu'à
        // travers ses déclencheurs, sur les produits qu'ils couvrent.
        q("INSERT INTO ws_products (name, price, base_cost, active, menu_override)
           VALUES (?, ?, ?, 0, 'on')",
          [(string) $b['productName'], (float) ($b['basePrice'] ?? 0), (float) ($b['baseCost'] ?? 0)]);
        $pid = (int) db()->lastInsertId();
        $creation = true;
      }
      if (!$pid) json_out(['error' => 'productId requis'], 400);
      $ov = isset($b['menuOverride']) && in_array($b['menuOverride'], ['on','off'], true) ? $b['menuOverride'] : null;
      $bundles = is_array($b['bundles'] ?? null) ? $b['bundles'] : [];
      $pdo = db();
      $pdo->beginTransaction();
      try {
        // Prix de base, coût et override : chacun n'est écrit QUE s'il est
        // présent dans le body. Sans ça, ouvrir un produit dans le
        // constructeur envoyait baseCost:0 / menuOverride:null (valeurs par
        // défaut du store local) et ÉCRASAIT le coût réel du produit à 0 et
        // sa surcharge menu — pour tout produit simplement consulté.
        $mSets = []; $mVals = [];
        if (array_key_exists('menuOverride', $b)) { $mSets[] = 'menu_override=?'; $mVals[] = $ov; }
        if (array_key_exists('baseCost', $b))     { $mSets[] = 'base_cost=?';     $mVals[] = (float) $b['baseCost']; }
        if (array_key_exists('basePrice', $b))    { $mSets[] = 'price=?';         $mVals[] = (float) $b['basePrice']; }
        if ($mSets) { $mVals[] = $pid; q("UPDATE ws_products SET " . implode(', ', $mSets) . " WHERE id=?", $mVals); }
        q("DELETE c FROM ws_bundle_slot_choices c JOIN ws_bundle_slots s ON s.id=c.slot_id JOIN ws_bundles bu ON bu.id=s.bundle_id WHERE bu.product_id=?", [$pid]);
        q("DELETE s FROM ws_bundle_slots s JOIN ws_bundles bu ON bu.id=s.bundle_id WHERE bu.product_id=?", [$pid]);
        q("DELETE FROM ws_bundles WHERE product_id=?", [$pid]);
        foreach ($bundles as $bi => $bu) {
          q("INSERT INTO ws_bundles (product_id, name, description, price_modifier, sort_order, active) VALUES (?,?,?,?,?,?)",
            [$pid, (string) ($bu['name'] ?? ''), (string) ($bu['description'] ?? ''), (float) ($bu['price_modifier'] ?? 0), $bi, !empty($bu['active']) ? 1 : 0]);
          $bid = (int) $pdo->lastInsertId();
          foreach (is_array($bu['slots'] ?? null) ? $bu['slots'] : [] as $si => $sl) {
            $kind = in_array($sl['kind'] ?? 'single', ['single','multi'], true) ? $sl['kind'] : 'single';
            $slCols = ['bundle_id','label','required','kind','min_select','max_select','sort_order','active'];
            $slVals = [$bid, (string) ($sl['label'] ?? ''), !empty($sl['required']) ? 1 : 0, $kind,
                       (int) ($sl['min_select'] ?? ($kind === 'single' ? 1 : 0)), (int) ($sl['max_select'] ?? 1), $si, !empty($sl['active']) ? 1 : 0];
            if (col_exists('ws_bundle_slots', 'cat_id')) {
              $slCols[] = 'cat_id';
              $slVals[] = is_numeric($sl['cat_id'] ?? null) ? (int) $sl['cat_id'] : null;
            }
            // sub_cat_id AFFINE cat_id (0059) : une etape « Dessert » peut ne
            // vouloir que les parts individuelles, pas toute la patisserie.
            if (col_exists('ws_bundle_slots', 'sub_cat_id')) {
              $slCols[] = 'sub_cat_id';
              $slVals[] = is_numeric($sl['sub_cat_id'] ?? null) ? (int) $sl['sub_cat_id'] : null;
            }
            q("INSERT INTO ws_bundle_slots (" . implode(',', $slCols) . ") VALUES ("
              . implode(',', array_fill(0, count($slCols), '?')) . ")", $slVals);
            $sid = (int) $pdo->lastInsertId();
            foreach (is_array($sl['choices'] ?? null) ? $sl['choices'] : [] as $ci => $ch) {
              /* product_id est la DONNEE PORTEE par le choix (migration 0057) ;
                 le label n'est qu'un affichage. Ecrit seulement si la colonne
                 existe, pour rester deployable avant la migration. */
              $chCols = ['slot_id','label','img','delta','cost','sort_order','active'];
              $chVals = [$sid, (string) ($ch['label'] ?? ''), (string) ($ch['img'] ?? ''),
                         (float) ($ch['delta'] ?? 0), (float) ($ch['cost'] ?? 0), $ci, !empty($ch['active']) ? 1 : 0];
              if (col_exists('ws_bundle_slot_choices', 'product_id')) {
                $chCols[] = 'product_id';
                $chVals[] = is_numeric($ch['product_id'] ?? null) ? (int) $ch['product_id'] : null;
              }
              q("INSERT INTO ws_bundle_slot_choices (" . implode(',', $chCols) . ") VALUES ("
                . implode(',', array_fill(0, count($chCols), '?')) . ")", $chVals);
            }
          }
        }
        $pdo->commit();
      } catch (Throwable $e) { $pdo->rollBack(); json_out(['error' => 'échec sauvegarde menu'], 500); }
      $audit('menu.save', 'ws_bundles', $pid, null, ['bundles' => count($bundles), 'creation' => $creation]);
      // productId TOUJOURS rendu : à la création, c'est lui qui permet à
      // l'écran de remplacer son identifiant local par le vrai.
      json_out(['ok' => true, 'productId' => $pid]);
    }

    /* ══════════════════════════════════════════════════════════════════
       PARCOURS DE PRÉPARATION PRODUIT — configuration RÉSEAU (marque).
       Défini par le franchiseur, partagé par toutes les boutiques : aucune
       portée boutique, pas de seed (tables via migration 0104). Photos =
       objets fichiers indépendants sous assets/preparation/, référencés par
       clé dans l'étape ; la copie duplique les fichiers. Règles (batch, four)
       appliquées ici, côté serveur, jamais seulement en base.
       ══════════════════════════════════════════════════════════════════ */
    $prepDir = __DIR__ . '/../assets/preparation';
    // Sérialisation d'une étape (colonnes → forme d'API stable).
    $prepStepOut = function ($s) {
      $photos = [];
      foreach ([1, 2, 3] as $slot) {
        $k = $s['image_key_' . $slot] ?? null;
        if ($k) $photos[] = ['slot' => $slot, 'key' => $k, 'url' => 'assets/preparation/' . $k];
      }
      return [
        'id'              => (int) $s['id'],
        'sortOrder'       => (int) $s['sort_order'],
        'description'     => (string) ($s['description'] ?? ''),
        'durationSeconds' => (int) $s['duration_seconds'],
        'usesOven'        => (int) $s['uses_oven'] === 1,
        'batchGroupId'    => $s['batch_group_id'] !== null ? (int) $s['batch_group_id'] : null,
        'batchCapacity'   => $s['batch_capacity'] !== null ? (int) $s['batch_capacity'] : null,
        'productsPerTray' => $s['products_per_tray'] !== null ? (int) $s['products_per_tray'] : null,
        'traysPerOven'    => $s['trays_per_oven'] !== null ? (int) $s['trays_per_oven'] : null,
        'photos'          => $photos,
      ];
    };
    // Validation + normalisation d'un corps d'étape. $existing (row) permet le
    // PATCH partiel : un champ absent garde sa valeur actuelle. Termine la
    // requête en 400 si une règle est violée (jamais d'état incohérent en base).
    $prepStepFields = function ($b, $existing = null) {
      $get = function ($k, $def = null) use ($b, $existing) {
        if (array_key_exists($k, $b)) return $b[$k];
        if ($existing !== null && array_key_exists($k, $existing)) return $existing[$k];
        return $def;
      };
      $nOrNull = function ($v) { return ($v === null || $v === '') ? null : (int) $v; };
      $dur = (int) $get('duration_seconds', 0);
      if ($dur < 0) json_out(['error' => 'duration_seconds doit être ≥ 0.'], 400);
      $usesOven = !empty($get('uses_oven', 0)) ? 1 : 0;
      $bg  = $nOrNull($get('batch_group_id'));
      $cap = $nOrNull($get('batch_capacity'));
      $ppt = $nOrNull($get('products_per_tray'));
      $tpo = $nOrNull($get('trays_per_oven'));
      // Règle batchable : batch_group_id ET batch_capacity ensemble, ou aucun.
      if (($bg === null) !== ($cap === null))
        json_out(['error' => 'Étape batchable : batch_group_id ET batch_capacity ; non batchable : ni l’un ni l’autre.'], 400);
      if ($bg !== null && !row("SELECT 1 x FROM product_preparation_batch_group WHERE id=?", [$bg]))
        json_out(['error' => 'batch_group_id inconnu.'], 400);
      if ($cap !== null && $cap <= 0) json_out(['error' => 'batch_capacity doit être > 0.'], 400);
      // Règle four : exige un batch + une capacité, et capacité = plaque × four.
      if ($usesOven) {
        if ($bg === null || $cap === null)
          json_out(['error' => 'Étape four : un batch_group_id et une batch_capacity sont requis.'], 400);
        if ($ppt === null || $tpo === null || $ppt <= 0 || $tpo <= 0)
          json_out(['error' => 'Étape four : products_per_tray et trays_per_oven doivent être > 0.'], 400);
        if ($cap !== $ppt * $tpo)
          json_out(['error' => 'batch_capacity doit égaler products_per_tray × trays_per_oven.'], 400);
      }
      return ['description' => (string) $get('description', ''), 'duration_seconds' => $dur, 'uses_oven' => $usesOven,
              'batch_group_id' => $bg, 'batch_capacity' => $cap, 'products_per_tray' => $ppt, 'trays_per_oven' => $tpo];
    };
    // Écrit une photo (base64 → fichier), renvoie sa clé « <32hex>.<ext> ». Le
    // type est vérifié sur les OCTETS (getimagesizefromstring), jamais sur
    // l'extension annoncée par le client. 10 Mo max.
    $prepSavePhoto = function ($b) use ($prepDir) {
      $data = (string) ($b['photo_base64'] ?? '');
      if ($data === '') json_out(['error' => 'photo_base64 requis.'], 400);
      $data = preg_replace('#^data:[^;]+;base64,#', '', trim($data));
      $bin = base64_decode($data, true);
      if ($bin === false) json_out(['error' => 'base64 invalide.'], 400);
      if (strlen($bin) > 10 * 1024 * 1024) json_out(['error' => 'Image trop lourde (max 10 Mo).'], 400);
      $info = @getimagesizefromstring($bin);
      $extBy = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
      if (!$info || !isset($extBy[$info[2]])) json_out(['error' => 'Image non reconnue (JPEG, PNG ou WebP attendu).'], 400);
      if (!is_dir($prepDir)) @mkdir($prepDir, 0755, true);
      if (!is_dir($prepDir) || !is_writable($prepDir)) json_out(['error' => 'Stockage image indisponible.'], 500);
      $key = bin2hex(random_bytes(16)) . '.' . $extBy[$info[2]];
      if (file_put_contents($prepDir . '/' . $key, $bin) === false) json_out(['error' => 'Écriture image échouée.'], 500);
      return $key;
    };
    // Supprime le fichier d'une clé (jamais hors du dossier : la clé est un nom
    // simple validé, pas un chemin).
    $prepDelPhoto = function ($key) use ($prepDir) {
      if (is_string($key) && preg_match('#^[a-f0-9]{32}\.(jpg|png|webp)$#', $key)) @unlink($prepDir . '/' . $key);
    };
    // id du parcours d'un produit ; le crée à la demande ($create=true).
    $prepPathId = function ($productId, $create = false) {
      $r = row("SELECT id FROM product_preparation_path WHERE product_id=?", [$productId]);
      if ($r) return (int) $r['id'];
      if (!$create) return null;
      q("INSERT INTO product_preparation_path (product_id) VALUES (?)", [$productId]);
      return (int) db()->lastInsertId();
    };
    $prepProductOr404 = function ($productId) {
      if (!$productId || !row("SELECT id FROM ws_products WHERE id=?", [$productId]))
        json_out(['error' => 'Produit inconnu.'], 404);
    };

    /* ── Groupes de batch réseau ─────────────────────────────────────── */
    if ($m === 'GET' && $p === '/franchisor/preparation-batch-groups') {
      json_out(array_map(
        fn ($g) => ['id' => (int) $g['id'], 'name' => $g['name']],
        rows("SELECT id, name FROM product_preparation_batch_group ORDER BY name")));
    }
    if ($m === 'POST' && $p === '/franchisor/preparation-batch-groups') {
      $b = body(); $name = trim((string) ($b['name'] ?? ''));
      if ($name === '') json_out(['error' => 'name requis.'], 400);
      q("INSERT INTO product_preparation_batch_group (name) VALUES (?)", [$name]);
      $gid = (int) db()->lastInsertId();
      $audit('prep.batchgroup.create', 'product_preparation_batch_group', $gid, null, ['name' => $name]);
      json_out(['id' => $gid, 'name' => $name]);
    }

    /* ── IDs des produits ayant un parcours (aperçu : une seule requête) ── */
    if ($m === 'GET' && $p === '/franchisor/preparation-paths/configured-product-ids') {
      json_out(['productIds' => array_map(
        fn ($r) => (int) $r['product_id'],
        rows("SELECT product_id FROM product_preparation_path ORDER BY product_id"))]);
    }

    /* ── Ordre des étapes (AVANT la route :stepId, sinon « order » y tombe) ── */
    if ($m === 'PATCH' && ($mm = $match('/franchisor/products/:pid/preparation-path/steps/order'))) {
      $pid = (int) $mm['pid']; $prepProductOr404($pid);
      $b = body();
      $order = $b['order'] ?? ($b['stepIds'] ?? null);
      if (!is_array($order)) json_out(['error' => 'order (liste d’ids d’étapes) requis.'], 400);
      $pathId = $prepPathId($pid, false);
      if ($pathId === null) json_out(['error' => 'Aucun parcours pour ce produit.'], 404);
      $owned = array_map(fn ($r) => (int) $r['id'], rows("SELECT id FROM product_preparation_step WHERE path_id=?", [$pathId]));
      $wanted = array_map('intval', $order);
      sort($owned); $chk = $wanted; sort($chk);
      if ($owned !== $chk) json_out(['error' => 'La liste doit contenir exactement les étapes du parcours.'], 400);
      db()->beginTransaction();
      try {
        foreach ($wanted as $i => $sid)
          q("UPDATE product_preparation_step SET sort_order=? WHERE id=? AND path_id=?", [$i, $sid, $pathId]);
        db()->commit();
      } catch (Throwable $e) { db()->rollBack(); json_out(['error' => 'Réordonnancement échoué.'], 500); }
      $audit('prep.steps.reorder', 'product_preparation_path', $pathId, null, ['order' => $wanted]);
      json_out(['ok' => true]);
    }

    /* ── Photo d'une étape : POST (upload/replace) / DELETE, slot 1–3 ── */
    if (($m === 'POST' || $m === 'DELETE')
        && ($mm = $match('/franchisor/products/:pid/preparation-path/steps/:sid/photos/:slot'))) {
      $pid = (int) $mm['pid']; $sid = (int) $mm['sid']; $slot = (int) $mm['slot'];
      $prepProductOr404($pid);
      if ($slot < 1 || $slot > 3) json_out(['error' => 'slot doit être 1, 2 ou 3.'], 400);
      $pathId = $prepPathId($pid, false);
      $s = $pathId !== null ? row("SELECT * FROM product_preparation_step WHERE id=? AND path_id=?", [$sid, $pathId]) : null;
      if (!$s) json_out(['error' => 'Étape inconnue pour ce produit.'], 404);
      $col = 'image_key_' . $slot;
      $old = $s[$col];
      if ($m === 'DELETE') {
        $prepDelPhoto($old);
        q("UPDATE product_preparation_step SET $col=NULL WHERE id=?", [$sid]);
        $audit('prep.photo.delete', 'product_preparation_step', $sid, null, ['slot' => $slot]);
        json_out(['ok' => true]);
      }
      $key = $prepSavePhoto(body());          // écrit le nouveau AVANT d'effacer l'ancien
      $prepDelPhoto($old);
      q("UPDATE product_preparation_step SET $col=? WHERE id=?", [$key, $sid]);
      $audit('prep.photo.set', 'product_preparation_step', $sid, null, ['slot' => $slot]);
      json_out(['slot' => $slot, 'key' => $key, 'url' => 'assets/preparation/' . $key]);
    }

    /* ── Une étape : PATCH (update) / DELETE ── */
    if (($m === 'PATCH' || $m === 'DELETE')
        && ($mm = $match('/franchisor/products/:pid/preparation-path/steps/:sid'))) {
      $pid = (int) $mm['pid']; $sid = (int) $mm['sid'];
      $prepProductOr404($pid);
      $pathId = $prepPathId($pid, false);
      $s = $pathId !== null ? row("SELECT * FROM product_preparation_step WHERE id=? AND path_id=?", [$sid, $pathId]) : null;
      if (!$s) json_out(['error' => 'Étape inconnue pour ce produit.'], 404);
      if ($m === 'DELETE') {
        foreach ([1, 2, 3] as $slot) $prepDelPhoto($s['image_key_' . $slot]);
        q("DELETE FROM product_preparation_step WHERE id=?", [$sid]);
        $audit('prep.step.delete', 'product_preparation_step', $sid, null, null);
        json_out(['ok' => true]);
      }
      $f = $prepStepFields(body(), $s);        // PATCH partiel sur base de l'existant
      q("UPDATE product_preparation_step
            SET description=?, duration_seconds=?, uses_oven=?, batch_group_id=?, batch_capacity=?,
                products_per_tray=?, trays_per_oven=? WHERE id=?",
        [$f['description'], $f['duration_seconds'], $f['uses_oven'], $f['batch_group_id'],
         $f['batch_capacity'], $f['products_per_tray'], $f['trays_per_oven'], $sid]);
      $audit('prep.step.update', 'product_preparation_step', $sid, null, $f);
      json_out($prepStepOut(row("SELECT * FROM product_preparation_step WHERE id=?", [$sid])));
    }

    /* ── Créer une étape ── */
    if ($m === 'POST' && ($mm = $match('/franchisor/products/:pid/preparation-path/steps'))) {
      $pid = (int) $mm['pid']; $prepProductOr404($pid);
      $f = $prepStepFields(body(), null);
      $pathId = $prepPathId($pid, true);       // crée le parcours si absent
      $next = (int) (row("SELECT COALESCE(MAX(sort_order)+1,0) n FROM product_preparation_step WHERE path_id=?", [$pathId])['n'] ?? 0);
      q("INSERT INTO product_preparation_step
           (path_id, sort_order, description, duration_seconds, uses_oven, batch_group_id, batch_capacity, products_per_tray, trays_per_oven)
           VALUES (?,?,?,?,?,?,?,?,?)",
        [$pathId, $next, $f['description'], $f['duration_seconds'], $f['uses_oven'],
         $f['batch_group_id'], $f['batch_capacity'], $f['products_per_tray'], $f['trays_per_oven']]);
      $sid = (int) db()->lastInsertId();
      $audit('prep.step.create', 'product_preparation_step', $sid, null, $f);
      json_out($prepStepOut(row("SELECT * FROM product_preparation_step WHERE id=?", [$sid])));
    }

    /* ── Copier le parcours d'un autre produit (remplace la cible) ── */
    if ($m === 'POST' && ($mm = $match('/franchisor/products/:pid/preparation-path/copy-from/:src'))) {
      $pid = (int) $mm['pid']; $src = (int) $mm['src'];
      $prepProductOr404($pid); $prepProductOr404($src);
      if ($pid === $src) json_out(['error' => 'Source et cible identiques.'], 400);
      $srcPath = $prepPathId($src, false);
      if ($srcPath === null) json_out(['error' => 'Le produit source n’a pas de parcours.'], 404);
      $srcSteps = rows("SELECT * FROM product_preparation_step WHERE path_id=? ORDER BY sort_order, id", [$srcPath]);
      db()->beginTransaction();
      try {
        // Efface la cible (fichiers photos compris) puis recrée à l'identique.
        $tgtPath = $prepPathId($pid, true);
        foreach (rows("SELECT * FROM product_preparation_step WHERE path_id=?", [$tgtPath]) as $old)
          foreach ([1, 2, 3] as $slot) $prepDelPhoto($old['image_key_' . $slot]);
        q("DELETE FROM product_preparation_step WHERE path_id=?", [$tgtPath]);
        foreach ($srcSteps as $i => $st) {
          // Photos : objets INDÉPENDANTS — on duplique chaque fichier sous une
          // nouvelle clé (la source et la cible ne partagent aucun fichier).
          $keys = [null, null, null];
          foreach ([1, 2, 3] as $slot) {
            $k = $st['image_key_' . $slot];
            if ($k && preg_match('#^[a-f0-9]{32}\.(jpg|png|webp)$#', $k, $km) && is_file($prepDir . '/' . $k)) {
              $nk = bin2hex(random_bytes(16)) . '.' . $km[1];
              if (@copy($prepDir . '/' . $k, $prepDir . '/' . $nk)) $keys[$slot - 1] = $nk;
            }
          }
          q("INSERT INTO product_preparation_step
               (path_id, sort_order, description, duration_seconds, uses_oven, batch_group_id, batch_capacity,
                products_per_tray, trays_per_oven, image_key_1, image_key_2, image_key_3)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [$tgtPath, $i, $st['description'], $st['duration_seconds'], $st['uses_oven'], $st['batch_group_id'],
             $st['batch_capacity'], $st['products_per_tray'], $st['trays_per_oven'], $keys[0], $keys[1], $keys[2]]);
        }
        db()->commit();
      } catch (Throwable $e) { db()->rollBack(); json_out(['error' => 'Copie échouée.'], 500); }
      $audit('prep.path.copy', 'product_preparation_path', $pid, null, ['from' => $src, 'steps' => count($srcSteps)]);
      json_out(['ok' => true, 'steps' => count($srcSteps)]);
    }

    /* ── Lire / supprimer le parcours d'un produit ── */
    if (($m === 'GET' || $m === 'DELETE') && ($mm = $match('/franchisor/products/:pid/preparation-path'))) {
      $pid = (int) $mm['pid']; $prepProductOr404($pid);
      $pathId = $prepPathId($pid, false);
      if ($m === 'GET') {
        if ($pathId === null) json_out(['configured' => false, 'productId' => $pid, 'steps' => []]);
        $steps = array_map($prepStepOut,
          rows("SELECT * FROM product_preparation_step WHERE path_id=? ORDER BY sort_order, id", [$pathId]));
        json_out(['configured' => true, 'productId' => $pid, 'steps' => $steps]);
      }
      // DELETE : parcours complet + ses photos.
      if ($pathId === null) json_out(['ok' => true]);   // déjà absent → idempotent
      db()->beginTransaction();
      try {
        foreach (rows("SELECT * FROM product_preparation_step WHERE path_id=?", [$pathId]) as $st)
          foreach ([1, 2, 3] as $slot) $prepDelPhoto($st['image_key_' . $slot]);
        q("DELETE FROM product_preparation_step WHERE path_id=?", [$pathId]);
        q("DELETE FROM product_preparation_path WHERE id=?", [$pathId]);
        db()->commit();
      } catch (Throwable $e) { db()->rollBack(); json_out(['error' => 'Suppression échouée.'], 500); }
      $audit('prep.path.delete', 'product_preparation_path', $pathId, null, ['productId' => $pid]);
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
    /* GARDE : jeton admin ERP, OU session tablette (PIN).
       Avant, seul le jeton admin passait : une tablette voyait bien son menu
       filtré, mais CHAQUE écran renvoyait 401 — les comptes PIN ne donnaient
       accès à aucune donnée, la fonctionnalité était décorative.
       Une session PIN est doublement bornée :
         • à SA boutique (le ?shop= de l'URL est ignoré — sinon n'importe quel
           porteur de PIN lisait les données d'une autre boutique) ;
         • à SES sections (bo_endpoint_section) — un endpoint non cartographié
           est refusé, jamais ouvert par défaut. */
    $pinSes = is_admin_request() ? null : bo_pin_session();
    // Troisième identité : le JETON D'APPAREIL de la tablette Kitchen. Il ne
    // remplace pas le jeton admin — sa portée est une liste blanche explicite
    // d'écrans de comptoir. Tout le reste (marges, coûts, réglages réseau,
    // délivrance de jetons) lui est fermé, y compris si un endpoint est ajouté
    // plus tard : ce qui n'est pas listé ici est refusé.
    $devShop = ($pinSes || is_admin_request()) ? null : device_token_shop();
    if (!$pinSes && !$devShop) {
      require_admin();
    } elseif ($devShop) {
      $DEVICE_ENDPOINTS = ['me', 'fr-orders', 'order-status', 'fr-stock-catalog', 'stock-adjust', 'kpis'];
      $epName = substr($p, strlen('/franchisee/'));
      if (!in_array($epName, $DEVICE_ENDPOINTS, true))
        json_out(['error' => 'Écran non autorisé pour une tablette (' . $epName . ').'], 403);
    } else {
      $epName = substr($p, strlen('/franchisee/'));
      // /franchisee/save est l'écriture GÉNÉRIQUE de toutes les tables : la
      // section dépend de la table visée, pas de l'endpoint. Le nom de table du
      // back-office est celui de l'endpoint avec des « _ » (ws_tours ↔ ws-tours).
      if ($epName === 'save') {
        $tbl  = (string) (body()['table'] ?? '');
        $need = $tbl !== '' ? bo_endpoint_section(str_replace('_', '-', $tbl)) : null;
        if ($need === null || $need === '*')
          json_out(['error' => 'Écriture non autorisée pour ce compte (table « ' . ($tbl ?: '?') . ' »).'], 403);
      } else {
        $need = bo_endpoint_section($epName);
      }
      if ($need === null)
        json_out(['error' => 'Section inconnue pour cet écran (' . $epName . ') — accès refusé.'], 403);
      if ($need !== '*' && !in_array($need, $pinSes['sections'], true))
        json_out(['error' => 'Accès refusé : la section « ' . $need . ' » n’est pas autorisée pour ce compte.'], 403);
    }

    $tblExists = function ($t) { return (bool) row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?", [$t]); };
    $SHOPS = 'shops';
    $eur0  = function ($n) { return number_format((float) $n, 0, ',', ' ') . ' €'; };
    // Montant ADAPTATIF (même règle que la console marque) : l'arrondi
    // systématique en k€ affichait « 0 k€ » pour des centaines d'euros de CA
    // réel. Sous 10 000 € → euro exact ; au-delà → k€.
    $eurk  = function ($n) {
      $n = (float) $n;
      return abs($n) < 10000
        ? number_format($n, 2, ',', ' ') . ' €'
        : number_format(round($n / 1000)) . ' k€';
    };
    $today = qp('date', date('Y-m-d'));
    $hasOrders = $tblExists('ws_orders');
    $DAYS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

    /* Portée boutique : ?shop=<id>, et RIEN D'AUTRE. Absente → réseau.
       Le slug était accepté puis résolu en id, ce qui donnait deux écritures
       pour la même portée : selon le chemin emprunté, la valeur mémorisée par
       la console était tantôt « anderlecht », tantôt « 2 ». Une seule forme
       vaut mieux qu'une conversion silencieuse — et un identifiant refusé le
       dit, au lieu de retomber sans bruit sur la vue réseau. */
    $shopParam = qp('shop');
    $shopId = null; $shopKo = null;
    if ($shopParam !== null && $shopParam !== '') {
      if (!ctype_digit((string) $shopParam)) {
        $shopKo = 'Portée boutique invalide : « ' . $shopParam . ' » n’est pas un id.'
                . ' Utilisez ?shop=<id numérique> — la liste est servie par /shops.';
      } else {
        $sr = row("SELECT id FROM $SHOPS WHERE id=?", [(int) $shopParam]);
        if ($sr) $shopId = (int) $sr['id'];
        else $shopKo = 'Boutique id ' . (int) $shopParam . ' introuvable dans la table shops.';
      }
    }
    // Session tablette : la portée est CELLE DE LA SESSION, pas celle de l'URL.
    // Sans ça, changer ?shop= dans la barre d'adresse suffisait à lire les
    // données d'une autre boutique avec un PIN local — et l'absence de ?shop=
    // donnait la vue réseau complète.
    if ($pinSes) $shopId = (int) $pinSes['shop_id'];
    // Même règle pour la tablette Kitchen : le jeton vaut pour UNE boutique, le
    // ?shop= de l'URL ne peut pas l'élargir.
    if ($devShop) $shopId = (int) $devShop;
    // Fragment WHERE de portée pour une colonne shop (réseau → 1=1). $shopId est un int contrôlé.
    $scope = function ($col) use ($shopId) { return $shopId ? "$col = " . (int) $shopId : '1=1'; };

    /* PORTÉE DES ÉCRITURES CLIENT. La LISTE est cloisonnée depuis toujours
       (preferred_shop_id), pas les écritures : elles prenaient l'id reçu et
       écrivaient. L'id d'un client d'une autre boutique suffisait donc à le
       bloquer, le désactiver, changer son code postal ou sa facturation — sans
       jamais l'avoir vu à l'écran.
       La règle est EXACTEMENT celle de la lecture : ce qui n'est pas visible
       n'est pas modifiable. Portée réseau ($shopId nul) = accès complet, comme
       la liste. Schéma sans colonne de rattachement = on ne refuse pas à tort. */
    $clientShopCol = col_exists('client', 'preferred_shop_id') ? 'preferred_shop_id'
                   : (col_exists('client', 'id_main_shop') ? 'id_main_shop' : null);
    $clientGuard = function ($id) use ($shopId, $clientShopCol) {
      if (!$shopId || !$clientShopCol) return;
      if (!row("SELECT 1 x FROM client WHERE id=? AND $clientShopCol=?", [(int) $id, (int) $shopId]))
        json_out(['ok' => false,
          'error' => 'Client #' . (int) $id . ' hors de votre boutique — modification refusée.'], 403);
    };
    // Même règle pour un bureau : il porte shop_id, et un bureau d'une autre
    // boutique ne doit pas pouvoir être rattaché à l'un de vos clients.
    $officeGuard = function ($id) use ($shopId) {
      if (!$shopId || !col_exists('ws_offices', 'shop_id')) return;
      if (!row("SELECT 1 x FROM ws_offices WHERE id=? AND shop_id=?", [(int) $id, (int) $shopId]))
        json_out(['ok' => false,
          'error' => 'Bureau #' . (int) $id . ' hors de votre boutique — rattachement refusé.'], 403);
    };

    // ── Contexte session (admin_token → pas de bo_user ; contexte minimal). ──
    if ($m === 'GET' && $p === '/franchisee/me') {
      // Identité ET position de la BOUTIQUE. L'adresse (address_line + zip) est
      // la source : le seul CP ne donnait qu'un centroïde de commune, à des
      // kilomètres de la boutique, alors que les tournées en partent.
      $shop = $shopId ? row("SELECT * FROM $SHOPS WHERE id=?", [$shopId]) : null;
      $geo = $shop ? shop_geo($shop) : null;
      json_out([
        'shop' => $shop ? [
          'id'      => (int) $shop['id'],
          'name'    => $shop['name'],
          'city'    => $shop['city'],
          'cp'      => $shop['zip'] ?: '',
          'address' => (trim((string) ($shop['address_line'] ?? '')) !== ''
                        ? $shop['address_line']
                        : (trim(trim((string) ($shop['street'] ?? '')) . ' ' . trim((string) ($shop['street_num'] ?? ''))) ?: null)),
          // lat/lng absents = position inconnue. Le back-office le DIT au lieu
          // de placer le pin sur un repli qui aurait l'air d'une vraie adresse.
          'lat'       => $geo ? $geo['lat'] : null,
          'lng'       => $geo ? $geo['lng'] : null,
          'geoSource' => $geo ? $geo['source'] : null,
          // Le MOTIF va jusqu'à l'écran : « position inconnue » sans cause
          // oblige à deviner entre trois pannes qui ne se corrigent pas au
          // même endroit (migration, adresse vide, service injoignable).
          'geoReason' => $geo ? $geo['reason'] : null,
        ] : null,
        // Boutique non résolue : le back-office doit pouvoir le DIRE. Sans ce
        // motif, la console affiche « Ma boutique » et laisse croire à un
        // défaut d'affichage, alors que la portée n'a pas été établie.
        'shopReason' => $shop ? null
          : ($shopKo ?: 'Aucune boutique demandée (?shop= absent) — le jeton admin est réseau,'
                      . ' la portée doit être précisée par ?shop=<id>.'),
        'consoleLabel' => 'Console franchisé' . ($shop ? ' · ' . ($shop['city'] ?: $shop['name']) : ''),
      ]);
    }

    // ── Reprise du géocodage de la boutique, à la demande : après correction de
    //    l'adresse, sans attendre l'expiration du délai anti-boucle. ──
    if ($m === 'POST' && $p === '/franchisee/shop-geocode') {
      if (!$shopId) json_out(['ok' => false, 'error' => 'Boutique non résolue.'], 400);
      $shop = row("SELECT * FROM $SHOPS WHERE id=?", [$shopId]);
      if (!$shop) json_out(['ok' => false, 'error' => 'Boutique introuvable.'], 404);
      if (!col_exists('shops', 'lat'))
        json_out(['ok' => false, 'error' => 'Migration 0060 non passée — colonnes lat/lng absentes.'], 501);
      $geo = shop_geo($shop, true);
      if (!$geo || $geo['lat'] === null) json_out(['ok' => false,
        'error' => $geo['reason'] ?? 'Adresse non résolue — vérifiez address_line et zip de la boutique.',
        'adresse' => trim(trim((string) ($shop['address_line'] ?? '')) . ' · '
                        . trim(((string) ($shop['zip'] ?? '')) . ' ' . ((string) ($shop['city'] ?? ''))), ' ·')], 409);
      json_out(['ok' => true, 'lat' => $geo['lat'], 'lng' => $geo['lng'], 'source' => $geo['source'],
        'reason' => $geo['reason'],
        'exact' => $geo['source'] === 'address' || $geo['source'] === 'manual']);
    }

    // ── Avis clients (PWA) — vue BORNÉE À LA BOUTIQUE DE LA SESSION. Jamais de
    //    portée réseau ici : $shopId est forcé à la session PIN plus haut, donc
    //    un porteur de PIN ne lit que SES avis. Même forme que /admin/reviews
    //    (totals / byProduct / recentNegative), mais un seul shop et sans
    //    sélecteur. `shop` sert au badge de la console franchisée.
    if ($m === 'GET' && $p === '/franchisee/reviews') {
      if (!$shopId) json_out(['error' => 'Boutique de session requise pour les avis.'], 400);
      $sid = (int) $shopId;
      $totals = row(
        "SELECT COUNT(*) total, COALESCE(SUM(liked=1),0) liked, COALESCE(SUM(liked=0),0) disliked,
                COALESCE(SUM(liked IS NULL),0) pending
           FROM pwa_reviews WHERE shop_id=?", [$sid]);
      // Note moyenne par produit (les moins bien notés d'abord).
      $byProduct = rows(
        "SELECT ri.product_name, ROUND(AVG(ri.rating),2) avg_rating, COUNT(ri.rating) n
           FROM pwa_review_items ri JOIN pwa_reviews r ON r.id = ri.review_id
          WHERE ri.rating IS NOT NULL AND r.shop_id=?
          GROUP BY ri.product_name ORDER BY avg_rating ASC, n DESC LIMIT 100", [$sid]);
      // Derniers avis négatifs (liked=0) + leurs notes/commentaires produit.
      $recent = rows(
        "SELECT r.id, r.created_at, r.shop_id, p.store
           FROM pwa_reviews r LEFT JOIN pwa_purchases p ON p.id = r.purchase_id
          WHERE r.liked = 0 AND r.shop_id=?
          ORDER BY r.id DESC LIMIT 40", [$sid]);
      foreach ($recent as &$rv) {
        $rv['items'] = rows(
          "SELECT product_name, rating, note FROM pwa_review_items WHERE review_id=? ORDER BY id",
          [$rv['id']]);
      }
      unset($rv);
      $shop = row("SELECT id, name, city FROM $SHOPS WHERE id=?", [$sid]);
      json_out(['totals' => $totals, 'byProduct' => $byProduct, 'recentNegative' => $recent,
                'shop' => $shop ?: null, 'shops' => $shop ? [$shop] : []]);
    }

    // ── Disponibilité boutique (ws_shop_availability) — SOURCE UNIQUE lue par
    //    le webshop (jours, heures, durée créneau, cut-off, lead). Le BO lit ET
    //    écrit DIRECTEMENT cette table (avant : le BO écrivait dans ws_param,
    //    déconnecté du webshop). Renvoyé en tableau d'une ligne (hydrate BOServer).
    if ($m === 'GET' && $p === '/franchisee/fr-shop-availability') {
      if (!$shopId) json_out([]);
      $r = row("SELECT * FROM ws_shop_availability WHERE shop_id=?", [$shopId]);
      $hm = fn ($t) => $t ? substr((string) $t, 0, 5) : '';
      json_out([[
        'shop_id'                    => $shopId,
        'collect_enabled'            => $r ? (int) $r['collect_enabled'] : 1,
        'delivery_enabled'           => $r ? (int) $r['delivery_enabled'] : 1,
        'collect_open_days'          => $r && $r['collect_open_days'] ? (json_decode($r['collect_open_days'], true) ?: []) : [1,2,3,4,5,6],
        'delivery_open_days'         => $r && $r['delivery_open_days'] ? (json_decode($r['delivery_open_days'], true) ?: []) : [1,2,3,4,5],
        'collect_hours_start'        => $hm($r['collect_hours_start'] ?? '08:00'),
        'collect_hours_end'          => $hm($r['collect_hours_end'] ?? '18:00'),
        'delivery_hours_start'       => $hm($r['delivery_hours_start'] ?? '08:30'),
        'delivery_hours_end'         => $hm($r['delivery_hours_end'] ?? '13:30'),
        'collect_slot_duration_min'  => $r ? (int) $r['collect_slot_duration_min'] : 60,
        'delivery_slot_duration_min' => $r ? (int) $r['delivery_slot_duration_min'] : 120,
        'collect_cutoff_hour'        => $r ? (int) $r['collect_cutoff_hour'] : 16,
        'collect_cutoff_minute'      => $r ? (int) $r['collect_cutoff_minute'] : 0,
        'collect_lead_hours'         => $r ? (int) $r['collect_lead_hours'] : 2,
        'delivery_cutoff_hour'       => $r ? (int) $r['delivery_cutoff_hour'] : 11,
        'delivery_cutoff_minute'     => $r ? (int) $r['delivery_cutoff_minute'] : 0,
        'delivery_lead_hours'        => $r ? (int) $r['delivery_lead_hours'] : 20,
        'collect_capacity_per_slot'  => $r ? (int) $r['collect_capacity_per_slot'] : 15,
        'delivery_capacity_per_slot' => $r ? (int) $r['delivery_capacity_per_slot'] : 30,
      ]]);
    }
    // Écriture : upsert de la ligne ws_shop_availability de CETTE boutique.
    if ($m === 'POST' && $p === '/franchisee/shop-availability') {
      if (!$shopId) json_out(['ok' => false, 'error' => 'boutique requise (?shop=)'], 400);
      if (!$tblExists('ws_shop_availability')) json_out(['ok' => false, 'error' => 'ws_shop_availability absente'], 501);
      $b = body();
      $dow = function ($v) {
        $a = is_array($v) ? $v : [];
        $a = array_values(array_unique(array_filter(array_map('intval', $a), fn ($d) => $d >= 1 && $d <= 7)));
        sort($a); return json_encode($a);
      };
      $hhmm = function ($v, $def) {
        $v = trim((string) $v);
        return preg_match('/^\d{1,2}:\d{2}$/', $v) ? (str_pad(explode(':', $v)[0], 2, '0', STR_PAD_LEFT) . ':' . explode(':', $v)[1] . ':00') : $def;
      };
      $ci = fn ($k, $def, $min, $max) => max($min, min($max, isset($b[$k]) && $b[$k] !== '' ? (int) $b[$k] : $def));
      /* AUDIT GO-LIVE : cet écran complétait tout champ absent avec des
         valeurs métier inventées (08:00–18:00, cut-off 16h, capacité 15/30…)
         — des horaires que la boutique n'avait jamais fixés, écrits en base
         puis appliqués au client. On EXIGE les champs d'horaires d'un canal
         ACTIVÉ plutôt que de décider à sa place : requête refusée avec la
         liste des manquants (la console les envoie tous ; ce garde ne bloque
         qu'un appel partiel/forgé). */
      $miss = [];
      if (!empty($b['collect_enabled'])) foreach (['collect_open_days','collect_hours_start','collect_hours_end'] as $rk)
        if (!isset($b[$rk]) || $b[$rk] === '' || $b[$rk] === []) $miss[] = $rk;
      if (!empty($b['delivery_enabled'])) foreach (['delivery_open_days','delivery_hours_start','delivery_hours_end'] as $rk)
        if (!isset($b[$rk]) || $b[$rk] === '' || $b[$rk] === []) $miss[] = $rk;
      if ($miss) json_out(['ok' => false, 'error' => 'Champs requis manquants (aucune valeur par défaut inventée) : ' . implode(', ', $miss)], 400);
      q("INSERT INTO ws_shop_availability
           (shop_id, collect_enabled, delivery_enabled, collect_open_days, delivery_open_days,
            collect_hours_start, collect_hours_end, delivery_hours_start, delivery_hours_end,
            collect_slot_duration_min, delivery_slot_duration_min,
            collect_cutoff_hour, collect_cutoff_minute, collect_lead_hours,
            delivery_cutoff_hour, delivery_cutoff_minute, delivery_lead_hours,
            collect_capacity_per_slot, delivery_capacity_per_slot)
         VALUES (?,?,?,?,?, ?,?,?,?, ?,?, ?,?,?, ?,?,?, ?,?)
         ON DUPLICATE KEY UPDATE
            collect_enabled=VALUES(collect_enabled), delivery_enabled=VALUES(delivery_enabled),
            collect_open_days=VALUES(collect_open_days), delivery_open_days=VALUES(delivery_open_days),
            collect_hours_start=VALUES(collect_hours_start), collect_hours_end=VALUES(collect_hours_end),
            delivery_hours_start=VALUES(delivery_hours_start), delivery_hours_end=VALUES(delivery_hours_end),
            collect_slot_duration_min=VALUES(collect_slot_duration_min), delivery_slot_duration_min=VALUES(delivery_slot_duration_min),
            collect_cutoff_hour=VALUES(collect_cutoff_hour), collect_cutoff_minute=VALUES(collect_cutoff_minute), collect_lead_hours=VALUES(collect_lead_hours),
            delivery_cutoff_hour=VALUES(delivery_cutoff_hour), delivery_cutoff_minute=VALUES(delivery_cutoff_minute), delivery_lead_hours=VALUES(delivery_lead_hours),
            collect_capacity_per_slot=VALUES(collect_capacity_per_slot), delivery_capacity_per_slot=VALUES(delivery_capacity_per_slot)",
        [$shopId,
         !empty($b['collect_enabled']) ? 1 : 0, !empty($b['delivery_enabled']) ? 1 : 0,
         $dow($b['collect_open_days'] ?? []), $dow($b['delivery_open_days'] ?? []),
         $hhmm($b['collect_hours_start'] ?? '', '08:00:00'), $hhmm($b['collect_hours_end'] ?? '', '18:00:00'),
         $hhmm($b['delivery_hours_start'] ?? '', '08:30:00'), $hhmm($b['delivery_hours_end'] ?? '', '13:30:00'),
         $ci('collect_slot_duration_min', 60, 5, 720), $ci('delivery_slot_duration_min', 120, 5, 720),
         $ci('collect_cutoff_hour', 16, 0, 23), $ci('collect_cutoff_minute', 0, 0, 59), $ci('collect_lead_hours', 2, 0, 336),
         $ci('delivery_cutoff_hour', 11, 0, 23), $ci('delivery_cutoff_minute', 0, 0, 59), $ci('delivery_lead_hours', 20, 0, 336),
         $ci('collect_capacity_per_slot', 15, 1, 9999), $ci('delivery_capacity_per_slot', 30, 1, 9999)]);
      json_out(['ok' => true]);
    }

    /* Identité de la boutique (enseigne, adresse, ville, CP). L'écran
       « Paramètres boutique » l'appelait mais la route N'EXISTAIT PAS : la
       console affichait « POST /franchisee/shop-update absent — rien
       enregistré » (honnête) sans jamais pouvoir écrire. PORTÉE PAR LA
       SESSION : on écrit la boutique du jeton, jamais le shopId du corps.
       Seuls les champs présents dans le corps sont modifiés (UPDATE partiel). */
    if ($m === 'POST' && $p === '/franchisee/shop-update') {
      if (!$shopId) json_out(['ok' => false, 'error' => 'boutique requise (?shop=)'], 400);
      $b = body();
      $map = ['name' => 'name', 'city' => 'city', 'zip' => 'zip'];
      $sets = []; $vals = [];
      foreach ($map as $k => $col) {
        if (array_key_exists($k, $b) && col_exists($SHOPS, $col)) { $sets[] = "$col=?"; $vals[] = mb_substr(trim((string) $b[$k]), 0, 190); }
      }
      // L'adresse : address_line si la colonne existe, sinon street.
      if (array_key_exists('address', $b)) {
        $aCol = col_exists($SHOPS, 'address_line') ? 'address_line' : (col_exists($SHOPS, 'street') ? 'street' : null);
        if ($aCol) { $sets[] = "$aCol=?"; $vals[] = mb_substr(trim((string) $b['address']), 0, 255); }
      }
      /* Langue de la boutique (migration 0087). Elle n'était posée que par
         migration : aucun écran ne pouvait la changer, donc « Halle ouvre en
         néerlandais » n'était pas paramétrable par le franchisé.
         - defaultLang : '' ou absent → NULL (= « non paramétré », l'app décide).
         - languages   : liste offerte au sélecteur, filtrée sur les codes à
           deux lettres. Vide → NULL (= toutes celles que l'app supporte).
         Aucune valeur inventée : on n'écrit que ce que l'utilisateur envoie. */
      if (array_key_exists('defaultLang', $b) && col_exists($SHOPS, 'default_lang')) {
        $dl = strtolower(trim((string) $b['defaultLang']));
        $sets[] = 'default_lang=?';
        $vals[] = preg_match('/^[a-z]{2}$/', $dl) ? $dl : null;
      }
      if (array_key_exists('languages', $b) && col_exists($SHOPS, 'languages')) {
        $raw = is_array($b['languages']) ? $b['languages'] : explode(',', (string) $b['languages']);
        $lgs = [];
        foreach ($raw as $x) {
          $x = strtolower(trim((string) $x));
          if (preg_match('/^[a-z]{2}$/', $x) && !in_array($x, $lgs, true)) $lgs[] = $x;
        }
        $sets[] = 'languages=?';
        $vals[] = $lgs ? implode(',', $lgs) : null;
      }
      if (!$sets) json_out(['ok' => false, 'error' => 'rien à modifier'], 400);
      $vals[] = (int) $shopId;
      q("UPDATE $SHOPS SET " . implode(', ', $sets) . " WHERE id=?", $vals);
      json_out(['ok' => true]);
    }

    // ── KPIs du jour — shape vstat du design (couleurs CSS brutes). ──
    if ($m === 'GET' && $p === '/franchisee/kpis') {
      if (!$hasOrders) json_out([]);
      $sw = $scope('shop_id');
      /* PART LIVRAISON — comptée sur les DEUX colonnes, avec leurs vocabulaires
         respectifs. `delivery_mode='delivery'` ne matchait JAMAIS : la colonne
         vaut 'collect' ou 'office_delivery' (cf. l'INSERT de POST /orders),
         jamais 'delivery' — c'est `mode` qui porte cette valeur-là. Le tableau
         de bord affichait donc « 0 % de livraison » en permanence, et rien ne
         pouvait le signaler : un SUM sur une comparaison fausse rend 0, pas
         une erreur. Même expression qu'au suivi de tournée (~7931), pour que
         les deux écrans ne puissent pas donner deux chiffres différents. */
      $mCol = col_exists('ws_orders', 'mode');
      $dCol = col_exists('ws_orders', 'delivery_mode');
      $livExpr = implode(' OR ', array_filter([
        $mCol ? "mode='delivery'" : null,
        $dCol ? "delivery_mode='office_delivery'" : null,
      ])) ?: '0';
      $d  = row("SELECT COALESCE(SUM(total),0) ca, COUNT(*) n, COALESCE(AVG(total),0) avg_basket,
                        SUM($livExpr) AS deliv,
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
      if (!$tblExists('ws_offices')) json_vide(['ws_offices']);
      $join = ''; $wh = '1=1';
      if ($shopId && $tblExists('ws_tours')) {
        $join = "LEFT JOIN ws_tours t ON t.id = f.tour_id";
        $wh   = "(t.shop_id = " . (int) $shopId . " OR f.tour_id IS NULL)";
      }
      $hasSites = $tblExists('ws_office_delivery_sites');
      // Conditions commerciales B2B servies depuis la fiche client liée
      // (colonnes 0084) quand elles existent — plus de « horeca » / « 30 j »
      // codés en dur. Jointure par ws_offices.client_id si la colonne existe.
      $hasCli   = col_exists('ws_offices', 'client_id');
      $hasB2bC  = $tblExists('client') && col_exists('client', 'b2b_segment');
      $cliSel = ($hasCli && $hasB2bC)
        ? ", c.b2b_segment, c.b2b_payment_terms, c.b2b_credit_ceiling, c.b2b_web_discount, c.b2b_franco"
        : "";
      $cliJoin = ($hasCli && $hasB2bC) ? " LEFT JOIN client c ON c.id = f.client_id" : "";
      $offices = rows("SELECT f.id, f.name, f.vat, f.status, f.deferred_billing_enabled$cliSel
                         FROM ws_offices f $join$cliJoin WHERE $wh ORDER BY f.name LIMIT 200");
      $out = [];
      foreach ($offices as $f) {
        $pts = $hasSites ? rows(
          "SELECT COALESCE(s.name,'—') AS libelle, COALESCE(s.address,'—') AS adresse
             FROM ws_office_delivery_sites s WHERE s.office_client_id=? AND s.active=1 LIMIT 20", [$f['id']]) : [];
        // Paiement : la vraie condition si stockée, sinon dérivée du flag
        // facturation différée ; jamais « 30 j fin de mois » inventé.
        $paie = trim((string) ($f['b2b_payment_terms'] ?? ''));
        if ($paie === '') $paie = $f['deferred_billing_enabled'] ? '—' : 'Comptant';
        $out[] = [
          'raison' => $f['name'], 'code' => 'OF-' . str_pad((string) $f['id'], 4, '0', STR_PAD_LEFT),
          'seg' => trim((string) ($f['b2b_segment'] ?? '')) ?: '—',
          'statut' => $f['status'] === 'validated' ? 'actif' : ($f['status'] ?: 'prospect'),
          'tva' => $f['vat'] ?: '—',
          'paiement' => $paie,
          'plafond' => $f['b2b_credit_ceiling'] !== null && $f['b2b_credit_ceiling'] !== '' ? (float) $f['b2b_credit_ceiling'] : null,
          'encours' => 0,
          'franco' => trim((string) ($f['b2b_franco'] ?? '')) ?: '—',
          'remise' => $f['b2b_web_discount'] !== null && $f['b2b_web_discount'] !== '' ? ((float) $f['b2b_web_discount'] . ' %') : '—',
          'fact' => $f['deferred_billing_enabled'] ? 'Mensuel' : 'Par livraison',
          'points' => array_map(fn ($s2) => ['libelle' => $s2['libelle'], 'adresse' => $s2['adresse'],
            'fenetre' => '—', 'jours' => '—', 'validation' => '—', 'marge' => 0], $pts),
        ];
      }
      json_out($out);
    }

    // ── Incidents (fr_incidents) — ws_incidents, shape fiche du design. ──
    if ($m === 'GET' && $p === '/franchisee/fr-incidents') {
      if (!$tblExists('ws_incidents')) json_vide(['ws_incidents']);
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
      if (!$tblExists('ws_incidents')) json_vide(['ws_incidents']);
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
      if (!$hasOrders || !$tblExists('ws_tours') || !$tblExists('ws_offices')) json_vide(['ws_orders', 'ws_tours', 'ws_offices']);
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
      if (!$tblExists('ws_tour_tracking') || !$tblExists('ws_tours')) json_vide(['ws_tour_tracking', 'ws_tours']);
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
      if (!$tblExists('ws_tours')) json_vide(['ws_tours']);
      $hasTk = $tblExists('ws_tour_tracking');
      $hasZ  = $tblExists('ws_delivery_zones');
      $hasFV = col_exists('ws_tours', 'delivery_fee');
      // `active` REMONTE, et le filtre `t.active=1` est levé : sans cela, une
      // tournée désactivée disparaissait du back-office, donc impossible à
      // réactiver — l'interrupteur aurait été à sens unique. C'est au BO de
      // n'afficher/proposer que ce qui est actif ; le webshop, lui, filtre déjà
      // sur active=1 de son côté (/delivery-zones, /delivery-fees).
      $rs = rows("SELECT t.id, t.name, t.max_items, t.active" . ($hasZ ? ", z.name AS zone" : ", NULL AS zone") .
                 ($hasFV ? ", t.delivery_fee, t.vehicle" : ", NULL AS delivery_fee, NULL AS vehicle") .
                 ($hasTk ? ", tk.driver_name" : ", NULL AS driver_name") . ",
                         (SELECT COUNT(*) FROM ws_orders o
                            LEFT JOIN ws_office_delivery_sites stt ON stt.id = o.office_delivery_site_id
                           WHERE COALESCE(o.tour_id, stt.tournee_id) = t.id AND o.delivery_date=?) AS used
                    FROM ws_tours t" . ($hasZ ? " LEFT JOIN ws_delivery_zones z ON z.id = t.zone_id" : "") .
                 ($hasTk ? " LEFT JOIN ws_tour_tracking tk ON tk.tour_id = t.id" : "") . "
                   WHERE " . $scope('t.shop_id') . " ORDER BY t.name", [$today]);
      // Fenêtre du jour (départ) + jours actifs depuis ws_tour_availability quand dispo.
      $hasAv = $tblExists('ws_tour_availability');
      $svc = (float) ws_param('cost_service_minutes', '15');
      json_out(array_map(function ($t) use ($hasAv, $svc) {
        // Départ et amplitude : NULL tant que ws_tour_availability n'a pas de
        // ligne pour cette tournée — plus de 06h00 / 4h inventés affichés
        // comme des horaires réels. L'écran sait afficher « — » (gardes déjà
        // en place) et « horaires à paramétrer ».
        $start = null; $amp = null;
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
                'max' => ($t['max_items'] !== null && $t['max_items'] !== '') ? (int) $t['max_items'] : null,
                'ret' => (col_exists('ws_tours','return_to_depot') ? ((int) ($t['return_to_depot'] ?? 1) !== 0) : true),
                'forfait' => $t['delivery_fee'] !== null ? (float) $t['delivery_fee'] : null,
                'vehicule' => $t['vehicle'] ?: '', 'days' => $days,
                'amplitude' => $amp, 'decharge' => $hasAv && $amp !== null ? (int) $svc : null, 'trajet' => $hasAv && $amp !== null ? (int) $svc : null,
                'used' => (int) $t['used'], 'zone' => $t['zone'] ?: '—',
                'active' => ((int) ($t['active'] ?? 1)) !== 0];
      }, $rs));
    }

    // ── Zones de livraison (ws_delivery_zones). ──
    if ($m === 'GET' && $p === '/franchisee/ws-delivery-zones') {
      if (!$tblExists('ws_delivery_zones')) json_vide(['ws_delivery_zones']);
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
        // Véhicule / franco / délai ne sont PAS stockés sur ws_delivery_zones :
        // on renvoie null (« non renseigné ») au lieu d'affirmer « Standard » /
        // « J+1 », qui étaient des valeurs inventées affichées comme réelles.
        'vehicule' => null, 'franco' => null, 'delai' => null,
        'service' => (int) (float) ws_param('cost_service_minutes', '15'),
        'catchment' => $z['catchment_name'] ?: ''], $rs));
    }

    // ── Zone de chalandise : codes postaux attribués à la boutique (pool des tournées). ──
    // Alimente le sélecteur de CP du formulaire « Créer une tournée » : le franchisé ne
    // peut cocher que des codes postaux de SA chalandise (ws_franchisor_catchment).
    if ($m === 'GET' && $p === '/franchisee/catchment-postcodes') {
      if (!$tblExists('ws_franchisor_catchment')) json_vide(['ws_franchisor_catchment']);
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
    /* PORTÉE RÉSEAU ASSUMÉE — aiguillage d'une demande B2B vers le franchisé
       qui COUVRE le code postal (zip_shop). C'est tout l'objet de la route : la
       cloisonner l'empêcherait de router hors de sa propre zone. La boutique
       cible est décidée par le CP, jamais par le client de l'appel. */
    /* Création du BUREAU d'un client B2B « livraison bureau ».
       Ce travail était laissé aux triggers trg_client_office_delivery_ai/_au
       (migrations 0019/0021/0023). Ils sont ABSENTS de la base servie : un
       client aiguillé arrivait donc sans bureau, et l'écran affichait « Aucun
       bureau validé ne correspond à … » sur un client pourtant bien créé.
       Les trois autres chemins qui posent office_delivery=1 créent déjà leur
       bureau en PHP ; celui-ci était le dernier à compter sur la base. */
    $ensureOffice = function ($clientId) {
      if (!$clientId || !$tblExists('ws_offices') || !col_exists('ws_offices', 'client_id')) return null;
      $c = row("SELECT * FROM client WHERE id=?", [(int) $clientId]);
      if (!$c) return null;
      if (empty($c['is_b2b']) || empty($c['office_delivery'])) return null;   // pas un bureau : rien à créer
      $ex = row("SELECT id FROM ws_offices WHERE client_id=? ORDER BY id DESC LIMIT 1", [(int) $clientId]);
      if ($ex) { q("UPDATE ws_offices SET active=1 WHERE id=?", [(int) $ex['id']]); return (int) $ex['id']; }
      $nm = trim((string) ($c['company_name'] ?? '')) ?: (trim((string) ($c['name'] ?? '')) ?: ('Client #' . (int) $clientId));
      $cols = ['client_id', 'name', 'status', 'active'];
      $vals = [(int) $clientId, $nm, 'pending', 1];
      foreach ([['shop_id', ((int) ($c['id_main_shop'] ?? 0)) ?: null],
                ['postal_code', $c['zip'] ?? null],
                ['city', $c['locality'] ?? ($c['city'] ?? null)],
                ['email', $c['email'] ?? null],
                ['phone', $c['phone'] ?? null]] as [$cn, $cv]) {
        if (col_exists('ws_offices', $cn)) { $cols[] = $cn; $vals[] = $cv; }
      }
      q("INSERT INTO ws_offices (" . implode(',', $cols) . ") VALUES ("
        . implode(',', array_fill(0, count($cols), '?')) . ")", $vals);
      return (int) db()->lastInsertId();
    };

    /* ── LIEN D'INVITATION D'UN BUREAU (fiche bureau, console franchisé) ─────
       Lire, ré-émettre, révoquer. La portée boutique est reprise du serveur
       ($shopId), jamais de l'id posté : sans cela, un franchisé lirait — et
       révoquerait — le lien d'un bureau d'une autre boutique. ── */
    if ($m === 'GET' && $p === '/franchisee/office-invite') {
      $oid = (int) qp('office', 0);
      if (!$oid) json_out(['ok' => false, 'error' => 'Bureau non précisé.'], 400);
      $oSc = ($shopId && col_exists('ws_offices', 'shop_id')) ? " AND (shop_id IS NULL OR shop_id=" . (int) $shopId . ")" : "";
      if (!row("SELECT 1 x FROM ws_offices WHERE id=?$oSc", [$oid]))
        json_out(['ok' => false, 'error' => 'Bureau inconnu, ou hors de votre boutique.'], 404);
      [$inv, $why] = invite_for_office($oid);
      json_out(['ok' => (bool) $inv, 'invite' => $inv, 'error' => $why]);
    }

    if ($m === 'POST' && $p === '/franchisee/office-invite') {
      $b   = body();
      $oid = (int) ($b['office'] ?? 0);
      if (!$oid) json_out(['ok' => false, 'error' => 'Bureau non précisé.'], 400);
      $oSc = ($shopId && col_exists('ws_offices', 'shop_id')) ? " AND (o.shop_id IS NULL OR o.shop_id=" . (int) $shopId . ")" : "";
      $o = row("SELECT o.id, o.name, o.postal_code, o.email, o.shop_id FROM ws_offices o WHERE o.id=?$oSc", [$oid]);
      if (!$o) json_out(['ok' => false, 'error' => 'Bureau inconnu, ou hors de votre boutique.'], 404);
      /* RÉVOQUER PUIS RÉ-ÉMETTRE, dans cet ordre et dans le même geste : deux
         liens valables en même temps pour un bureau, c'est celui qu'on croyait
         avoir retiré qui continue de servir. */
      try { q("UPDATE ws_office_invites SET revoked_at=NOW() WHERE office_id=? AND revoked_at IS NULL", [$oid]); }
      catch (Throwable $e) { json_out(['ok' => false, 'error' => 'Invitations indisponibles : la table ws_office_invites est absente (migration 0062).'], 501); }
      $site = row("SELECT id FROM ws_office_delivery_sites WHERE office_client_id=? AND active=1 ORDER BY is_default DESC, id LIMIT 1", [$oid]);
      $cli  = col_exists('ws_offices', 'client_id') ? row("SELECT client_id FROM ws_offices WHERE id=?", [$oid]) : null;
      $cid  = (int) ($cli['client_id'] ?? 0);
      $deps = [];
      if ($cid && $tblExists('b2b_client_company_department')) {
        $dk = col_exists('b2b_client_company_department', 'id_client') ? 'id_client'
            : (col_exists('b2b_client_company_department', 'client_id') ? 'client_id' : null);
        if ($dk) $deps = array_map(fn ($x) => (int) $x['id'],
          rows("SELECT id FROM b2b_client_company_department WHERE $dk=? ORDER BY id", [$cid]));
      }
      // Exigence de domaine RETIRÉE (14/08/2026) — lien ouvert à toute
      // adresse ; le compte reste « pending » jusqu'à validation.
      $dom = null;
      $inv = invite_issue(['shop' => (int) ($o['shop_id'] ?: $shopId), 'office' => $oid,
        'client' => $cid ?: null, 'site' => $site ? (int) $site['id'] : null,
        'depts' => $deps, 'domain' => $dom, 'cp' => $o['postal_code'] ?: null,
        'by' => is_admin_request() ? 'admin' : ('pin:' . (string) ($pinSes['id'] ?? '?'))]);
      if (!$inv) json_out(['ok' => false, 'error' => 'Lien non émis : la table ws_office_invites est absente (migration 0062).'], 501);
      json_out(['ok' => true, 'invite' => ['jti' => $inv['jti'], 'url' => invite_link($inv['token']),
                'urlCourt' => invite_link_court($inv['jti']),
                'expiresAt' => $inv['expires_at'], 'uses' => 0]]);
    }

    /* L'AFFICHE EN HTML — celle qu'on imprime, et qu'on exporte en PDF depuis
       le navigateur. C'est la seule façon d'avoir les POLICES et le style de la
       marque : un PDF n'a pas de moteur CSS, et notre écrivain PDF ne sait
       poser que les polices de base. Le navigateur, lui, sait déjà tout faire —
       « Imprimer → Enregistrer en PDF » rend exactement ce qui est à l'écran.
       (La version PDF ci-dessous reste, elle : l'e-mail a besoin d'un fichier
       à joindre, et un serveur PHP ne rend pas du HTML.) */
    if ($m === 'GET' && $p === '/franchisee/office-invite-poster') {
      $oid = (int) qp('office', 0);
      if (!$oid) json_out(['ok' => false, 'error' => 'Bureau non précisé.'], 400);
      $oSc = ($shopId && col_exists('ws_offices', 'shop_id')) ? " AND (shop_id IS NULL OR shop_id=" . (int) $shopId . ")" : "";
      if (!row("SELECT 1 x FROM ws_offices WHERE id=?$oSc", [$oid]))
        json_out(['ok' => false, 'error' => 'Bureau inconnu, ou hors de votre boutique.'], 404);
      [$inv, $why] = invite_for_office($oid);
      if (!$inv) json_out(['ok' => false, 'error' => $why . ' Émettez un lien avant d’imprimer l’affiche.'], 409);
      require_once __DIR__ . '/invite_doc.php';
      $recap = invite_recap($oid);
      if (!$recap) json_out(['ok' => false, 'error' => 'Bureau introuvable.'], 404);
      // Racine du webshop en ABSOLU. La page s'ouvre depuis un blob:, où un
      // chemin relatif ne mène nulle part. Polices et logo sont EMBARQUÉS dans
      // la page ; cette racine ne sert que de dernier recours au logo, si le
      // PNG manquait à côté du script.
      $racine = preg_replace('#/inscription\?.*$#', '', invite_link(''));
      header('Content-Type: text/html; charset=utf-8');
      header('Cache-Control: no-store');
      echo invite_affiche_html($recap, $inv['urlCourt'], date('d/m/Y', strtotime($inv['expiresAt'])), $racine);
      exit;
    }

    /* OPTIONS DU DOSSIER — ce que la modale « Imprimer le dossier » propose :
       les gammes RÉELLEMENT servies pour cette boutique (ou ce bureau, avec son
       assortiment) à la date donnée, avec leur nombre de produits. Lu à
       l'ouverture de la modale, jamais depuis une table en mémoire. */
    if ($m === 'GET' && $p === '/franchisee/office-brochure-options') {
      $oid = (int) qp('office', 0);
      $shopB = $shopId ? (int) $shopId : 0;
      $off = null;
      if ($oid) {
        $oSc = ($shopId && col_exists('ws_offices', 'shop_id')) ? " AND (shop_id IS NULL OR shop_id=" . (int) $shopId . ")" : "";
        $ob = row("SELECT id, shop_id FROM ws_offices WHERE id=? AND active=1$oSc", [$oid]);
        if (!$ob) json_out(['ok' => false, 'error' => 'Bureau inconnu, ou hors de votre boutique.'], 404);
        if (!$shopB && !empty($ob['shop_id'])) $shopB = (int) $ob['shop_id'];
        $off = office_assortiment($oid);
      }
      if (!$shopB) json_out(['ok' => false, 'error' => 'Boutique non résolue : ouvrez la console avec ?shop=<id>.'], 400);
      $dateQ = qp('date', '');
      $dateB = ($dateQ && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateQ)) ? $dateQ : date('Y-m-d');
      $liste = catalog_produits_servis($shopB, 'office', $dateB);
      if ($off) $liste = office_filtrer($liste, $off);
      $ids = array_values(array_unique(array_map(static fn ($x) => (int) ($x['cat_id'] ?? $x['cat'] ?? 0), $liste)));
      $cats = $ids ? rows("SELECT id, slug, label FROM ws_categories WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY sort_order, label", $ids) : [];
      $n = []; foreach ($liste as $x) { $k = (int) ($x['cat_id'] ?? $x['cat'] ?? 0); $n[$k] = ($n[$k] ?? 0) + 1; }
      /* Saisons : TOUTES les gammes saisonnières publiées de l'ERP
         (product-availability-periods), pas seulement celles en période à la
         date. Pour chacune : n = produits de cette gamme dans l'assortiment du
         bureau, dates ignorées ; nDate = ceux servis à la date choisie. La
         modale coche par défaut les gammes en période, et laisse cocher les
         autres pour un dossier imprimé d'avance. */
      $sais = 0; foreach ($liste as $x) if (!empty($x['season'])) $sais++;
      $saisL = [];
      if (function_exists('erp_seasons') && function_exists('erp_seasons_enabled') && erp_seasons_enabled()) {
        $pub = erp_seasons();
        $erpIds = array_map(static fn ($g) => (int) $g['erpId'], $pub);
        $tout = $erpIds ? catalog_produits_servis($shopB, 'office', $dateB, '', $erpIds) : [];
        if ($off) $tout = office_filtrer($tout, $off);
        $nTout = []; foreach ($tout as $x) if (!empty($x['season'])) { $k = (string) $x['season']; $nTout[$k] = ($nTout[$k] ?? 0) + 1; }
        $nDate = []; foreach ($liste as $x) if (!empty($x['season'])) { $k = (string) $x['season']; $nDate[$k] = ($nDate[$k] ?? 0) + 1; }
        foreach ($pub as $g) {
          $k = (string) $g['id'];
          $saisL[] = ['key' => $k, 'nom' => (string) ($g['label'] ?: $k), 'n' => (int) ($nTout[$k] ?? 0), 'nDate' => (int) ($nDate[$k] ?? 0)];
        }
        usort($saisL, static fn ($a, $b) => strcasecmp($a['nom'], $b['nom']));
      }
      json_out(['ok' => true, 'date' => $dateB, 'produits' => count($liste), 'saisonniers' => $sais, 'saisons' => $saisL,
                'cats' => array_map(static fn ($c) => ['key' => (string) ($c['slug'] ?: $c['label']), 'nom' => (string) $c['label'], 'n' => $n[(int) $c['id']] ?? 0], $cats)]);
    }

    /* LE DOSSIER « CARTE & TARIFS » (HTML A4, à imprimer ou enregistrer en PDF)
       — pour la boutique, ou pour UN bureau : son assortiment, ses prix ou
       non, le QR de son lien d'invitation. Même ouverture que l'affiche : la
       console le récupère avec le jeton et l'ouvre dans un onglet. */
    if ($m === 'GET' && $p === '/franchisee/office-brochure') {
      $oid = (int) qp('office', 0);
      $shopB = $shopId ? (int) $shopId : 0;
      if ($oid) {
        $oSc = ($shopId && col_exists('ws_offices', 'shop_id')) ? " AND (shop_id IS NULL OR shop_id=" . (int) $shopId . ")" : "";
        $ob = row("SELECT id, shop_id FROM ws_offices WHERE id=? AND active=1$oSc", [$oid]);
        if (!$ob) json_out(['ok' => false, 'error' => 'Bureau inconnu, ou hors de votre boutique.'], 404);
        if (!$shopB && !empty($ob['shop_id'])) $shopB = (int) $ob['shop_id'];
      }
      if (!$shopB) json_out(['ok' => false, 'error' => 'Boutique non résolue : ouvrez la console avec ?shop=<id>.'], 400);
      require_once __DIR__ . '/qr.php';
      require_once __DIR__ . '/invite_doc.php';
      require_once __DIR__ . '/brochure_doc.php';
      $racine = preg_replace('#/inscription\?.*$#', '', invite_link(''));
      // Le motif d'un échec REMONTE (la sonde et le franchisé le lisent) : un
      // « Erreur interne » muet a caché une colonne inexistante le 03/09.
      try {
        $catsQ = qp('cats', null);
        $catsKeys = ($catsQ !== null && $catsQ !== '') ? array_values(array_filter(array_map('trim', explode(',', (string) $catsQ)), 'strlen')) : null;
        if ($catsQ !== null && !$catsKeys) json_out(['ok' => false, 'error' => 'Choisissez au moins une gamme à imprimer.'], 400);
        // saisons=a,b : seules ces saisons restent (les produits sans saison
        // restent toujours) ; saisons= vide ou sansSaison=1 : aucune saison.
        $saisQ = qp('saisons', null);
        $saisKeys = $saisQ !== null ? array_values(array_filter(array_map('trim', explode(',', (string) $saisQ)), 'strlen')) : null;
        $doc = brochure_donnees($shopB, $oid ?: null, $racine, qp('date', '') ?: null, (bool) qp('sansSaison', 0), $catsKeys, $saisKeys);
        if (!$doc) json_out(['ok' => false, 'error' => 'Boutique ou bureau introuvable.'], 404);
        $qrPng = null;
        if (!empty($doc['qrUrl'])) { $res = qr_matrix($doc['qrUrl'], 'Q'); if ($res) $qrPng = qr_png($res[0], $res[1], 8, 4); }
        $html = brochure_render($doc, $racine, $qrPng);
      } catch (Throwable $e) {
        error_log('[ws] dossier : ' . $e->getMessage());
        json_out(['ok' => false, 'error' => 'Dossier non produit : ' . $e->getMessage()], 500);
      }
      header('Content-Type: text/html; charset=utf-8');
      header('Cache-Control: no-store');
      echo $html;
      exit;
    }

    /* L'AFFICHE (PDF, A4) — la même, écrite par le serveur. Elle sert de PIÈCE
       JOINTE à l'e-mail, où il faut un fichier ; l'écran, lui, passe par la
       version HTML ci-dessus, qui porte les polices de la marque. */
    if ($m === 'GET' && $p === '/franchisee/office-invite-pdf') {
      $oid = (int) qp('office', 0);
      if (!$oid) json_out(['ok' => false, 'error' => 'Bureau non précisé.'], 400);
      $oSc = ($shopId && col_exists('ws_offices', 'shop_id')) ? " AND (shop_id IS NULL OR shop_id=" . (int) $shopId . ")" : "";
      if (!row("SELECT 1 x FROM ws_offices WHERE id=?$oSc", [$oid]))
        json_out(['ok' => false, 'error' => 'Bureau inconnu, ou hors de votre boutique.'], 404);
      [$inv, $why] = invite_for_office($oid);
      if (!$inv) json_out(['ok' => false, 'error' => $why . ' Émettez un lien avant d’imprimer l’affiche.'], 409);
      require_once __DIR__ . '/invite_doc.php';
      $recap = invite_recap($oid);
      $pdf   = $recap ? invite_pdf($recap, $inv['urlCourt'], date('d/m/Y', strtotime($inv['expiresAt']))) : null;
      if (!$pdf) json_out(['ok' => false, 'error' => 'L’affiche n’a pas pu être produite pour ce bureau.'], 500);
      $nom = 'invitation-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($recap['raison'] ?? 'bureau')) . '.pdf';
      header('Content-Type: application/pdf');
      header('Content-Disposition: attachment; filename="' . $nom . '"');
      header('Content-Length: ' . strlen($pdf));
      header('Cache-Control: no-store');
      echo $pdf;
      exit;
    }

    /* ENVOYER (ou renvoyer) l'e-mail d'invitation au contact du bureau. */
    if ($m === 'POST' && $p === '/franchisee/office-invite-send') {
      $b   = body();
      $oid = (int) ($b['office'] ?? 0);
      if (!$oid) json_out(['ok' => false, 'error' => 'Bureau non précisé.'], 400);
      $oSc = ($shopId && col_exists('ws_offices', 'shop_id')) ? " AND (shop_id IS NULL OR shop_id=" . (int) $shopId . ")" : "";
      if (!row("SELECT 1 x FROM ws_offices WHERE id=?$oSc", [$oid]))
        json_out(['ok' => false, 'error' => 'Bureau inconnu, ou hors de votre boutique.'], 404);
      [$inv, $why] = invite_for_office($oid);
      if (!$inv) json_out(['ok' => false, 'error' => $why . ' Émettez un lien avant de l’envoyer.'], 409);
      require_once __DIR__ . '/invite_doc.php';
      $recap = invite_recap($oid);
      if (!$recap) json_out(['ok' => false, 'error' => 'Bureau introuvable.'], 404);
      // Destinataire : celui saisi dans la console, sinon le contact du bureau.
      $dest = trim((string) ($b['email'] ?? ''));
      [$ok, $motif] = invite_mail_envoyer($recap, $inv['url'], $inv['urlCourt'],
        date('d/m/Y', strtotime($inv['expiresAt'])), $dest ?: null);
      json_out(['ok' => $ok, 'error' => $ok ? null : $motif, 'warning' => $ok ? $motif : null,
        'message' => $ok ? ('E-mail envoyé à ' . ($dest ?: $recap['email']) . ' — affiche PDF jointe.') : null]);
    }

    if ($m === 'POST' && $p === '/franchisee/invite-revoke') {
      $b   = body();
      $oid = (int) ($b['office'] ?? 0);
      $jti = trim((string) ($b['jti'] ?? ''));
      if (!$oid && $jti === '') json_out(['ok' => false, 'error' => 'Bureau ou jeton à révoquer non précisé.'], 400);
      $oSc = ($shopId && col_exists('ws_offices', 'shop_id')) ? " AND (shop_id IS NULL OR shop_id=" . (int) $shopId . ")" : "";
      if ($oid && !row("SELECT 1 x FROM ws_offices WHERE id=?$oSc", [$oid]))
        json_out(['ok' => false, 'error' => 'Bureau inconnu, ou hors de votre boutique.'], 404);
      try {
        // Un jti seul reste borné à la boutique : la ligne porte shop_id.
        $n = $oid
          ? q("UPDATE ws_office_invites SET revoked_at=NOW() WHERE office_id=? AND revoked_at IS NULL", [$oid])->rowCount()
          : q("UPDATE ws_office_invites SET revoked_at=NOW() WHERE jti=? AND revoked_at IS NULL"
              . ($shopId ? " AND shop_id=" . (int) $shopId : ""), [$jti])->rowCount();
      } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => 'Invitations indisponibles : la table ws_office_invites est absente (migration 0062).'], 501);
      }
      json_out(['ok' => true, 'revoked' => (int) $n, 'message' => $n
        ? 'Lien révoqué : les liens déjà transférés ne créent plus de compte. Émettez-en un nouveau pour reprendre les inscriptions.'
        : 'Aucun lien actif à révoquer pour ce bureau.']);
    }

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
        // L'aiguillage DÉPLACE le client vers le franchisé qui couvre son CP :
        // les deux rattachements suivent, sinon la console de la boutique cible
        // ne le verrait jamais (elle cloisonne sur preferred_shop_id).
        $sets = ['id_main_shop=' . (int) $target];
        if (col_exists('client', 'preferred_shop_id')) $sets[] = 'preferred_shop_id=' . (int) $target;
        if (col_exists('client', 'is_b2b'))          $sets[] = 'is_b2b=1';
        if (col_exists('client', 'office_delivery')) $sets[] = 'office_delivery=1';
        if (col_exists('client', 'status'))          $sets[] = 'status=1';
        q("UPDATE client SET " . implode(',', $sets) . " WHERE id=?", [(int) $ex['id']]);
        if ($vat !== '' && col_exists('client', 'tax_number')) q("UPDATE client SET tax_number=? WHERE id=?", [$vat, (int) $ex['id']]);
        $offId = $ensureOffice((int) $ex['id']);
        json_out(['routed' => true, 'shop' => $tName, 'client_id' => (int) $ex['id'], 'office_id' => $offId]);
      }
      $cols = ['id_main_shop', 'email', 'name', 'zip', 'city', 'active', 'source_channel', 'webshop_user'];
      $ivals = [(int) $target, $mail ?: null, trim((string) ($b['name'] ?? '')) ?: 'Office', $cp,
                trim((string) ($b['city'] ?? '')), 1, 'webshop', 0];
      if (col_exists('client', 'preferred_shop_id')) { $cols[] = 'preferred_shop_id'; $ivals[] = (int) $target; }
      if (col_exists('client', 'company_name'))    { $cols[] = 'company_name';    $ivals[] = trim((string) ($b['name'] ?? '')) ?: null; }
      if (col_exists('client', 'is_b2b'))          { $cols[] = 'is_b2b';          $ivals[] = 1; }
      if (col_exists('client', 'office_delivery')) { $cols[] = 'office_delivery'; $ivals[] = 1; }
      if (col_exists('client', 'status'))          { $cols[] = 'status';          $ivals[] = 1; }
      if ($vat !== '' && col_exists('client', 'tax_number')) { $cols[] = 'tax_number'; $ivals[] = $vat; }
      q("INSERT INTO client (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")", $ivals);
      $newId = (int) db()->lastInsertId();
      $offId = $ensureOffice($newId);
      json_out(['routed' => true, 'shop' => $tName, 'client_id' => $newId, 'office_id' => $offId]);
    }

    // ── CP déjà affectés à chaque tournée (préremplissage du formulaire Tournée). ──
    if ($m === 'GET' && $p === '/franchisee/ws-tour-postcodes') {
      if (!$tblExists('ws_tour_postcodes')) json_vide(['ws_tour_postcodes']);
      json_out(rows("SELECT tp.tour_id, tp.postcode FROM ws_tour_postcodes tp" .
                    ($shopId ? " JOIN ws_tours t ON t.id = tp.tour_id AND t.shop_id = " . (int) $shopId : "") .
                    " ORDER BY tp.tour_id, tp.postcode"));
    }

    // ── Sites de livraison (ws_office_delivery_sites) — table réelle complète. ──
    if ($m === 'GET' && $p === '/franchisee/ws-office-delivery-sites') {
      if (!$tblExists('ws_office_delivery_sites')) json_vide(['ws_office_delivery_sites']);
      $hasT = $tblExists('ws_tours');
      $hasStop = col_exists('ws_office_delivery_sites', 'tournee_stop_id');
      $rs = rows("SELECT s.id, s.office_client_id, s.client_id, s.name, s.address, s.floor_room,
                         s.contact_name, s.contact_phone, s.tournee_id, s.shop_id,
                         s.site_access_minutes, s.active, f.name AS office_name,
                         f.address AS office_address, f.postal_code AS office_cp, f.city AS office_city" .
                 ($hasStop ? ", s.tournee_stop_id" : ", NULL AS tournee_stop_id") .
                 ($hasT ? ", t.name AS tour_name" : ", NULL AS tour_name") . "
                    FROM ws_office_delivery_sites s
                    LEFT JOIN ws_offices f ON f.id = s.office_client_id" .
                 ($hasT ? " LEFT JOIN ws_tours t ON t.id = s.tournee_id" : "") . "
                   WHERE " . $scope('s.shop_id') . " AND s.active=1
                   ORDER BY " . ($hasStop ? "COALESCE(s.tournee_stop_id, 999), " : "") . "s.name LIMIT 1000");
      json_out(array_map(fn ($s2) => [
        'id' => (int) $s2['id'], 'office_client_id' => $s2['office_client_id'] !== null ? (int) $s2['office_client_id'] : null,
        'client_id' => $s2['client_id'], 'bureau' => $s2['office_name'] ?: ($s2['name'] ?: '—'),
        'office' => $s2['office_name'] ?: '—', 'name' => $s2['name'] ?: '—',
        'adr' => $s2['address'] ?: '—', 'address' => $s2['address'] ?: '—',
        'etage' => $s2['floor_room'] ?: '—', 'floor_room' => $s2['floor_room'] ?: '—',
        'contact_name' => $s2['contact_name'] ?: '—', 'contact_phone' => $s2['contact_phone'] ?: '—',
        'tour' => $s2['tour_name'] ?: '—', 'tournee_id' => $s2['tournee_id'] !== null ? (int) $s2['tournee_id'] : null,
        'stop' => $s2['tournee_stop_id'] !== null ? (int) $s2['tournee_stop_id'] : null,
        'office_address' => $s2['office_address'] ?: '', 'office_cp' => $s2['office_cp'] ?: '', 'office_city' => $s2['office_city'] ?: '',
        // NULL reste NULL : (float) NULL rendait 0, que l'écran lisait comme
        // « zéro minute d'accès » — une mesure, alors que rien n'a été mesuré.
        'acc' => $s2['site_access_minutes'] === null ? null : (float) $s2['site_access_minutes'],
        'site_access_minutes' => $s2['site_access_minutes'] === null ? null : (float) $s2['site_access_minutes'],
        'shop_id' => $s2['shop_id'], 'active' => (bool) $s2['active'],
      ], $rs));
    }

    // ── Offices / bureaux (ws_offices) — table réelle. ──
    if ($m === 'GET' && $p === '/franchisee/ws-offices') {
      if (!$tblExists('ws_offices')) json_vide(['ws_offices']);
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
      $logoSel  = col_exists('ws_offices', 'logo_path') ? ", f.logo_path" : "";
      $rs = rows("SELECT f.id, f.tour_id, $tourSel, f.name, f.address, f.postal_code, f.city, f.contact,
                            f.email, f.phone, f.vat, f.status, f.deferred_billing_enabled$notesSel$siteSel$logoSel
                       FROM ws_offices f $join WHERE $wh AND f.active=1 ORDER BY f.name LIMIT 300");
      // deferred en Oui/Non : valeurs du toggle du formulaire Office.
      json_out(array_map(fn ($f) => ['deferred_billing_enabled' => ((int) $f['deferred_billing_enabled'] ? 'Oui' : 'Non'),
                                     'logo_url' => office_logo_rel($f['logo_path'] ?? null)] + $f, $rs));
    }

    /* ── Contacts e-mail d'un bureau ──────────────────────────────────────────
       DEUX SOURCES, ET ON LE DIT. La fiche du bureau porte un contact déclaré
       (ws_offices.email) ; les contacts ajoutés depuis l'écran vivent dans
       ws_office_emails (migration 0063). On sert l'UNION, chaque ligne marquée
       de son origine : `source` vaut `office` (non supprimable ici — cela se
       corrige sur la fiche) ou `table`.
       Avant 0063, l'écran écrivait dans l'overlay ws_bo_store et relisait la
       seule adresse de la fiche : tout contact ajouté disparaissait au
       rechargement, et aucun e-mail ne partait vers lui. ── */
    if ($m === 'GET' && $p === '/franchisee/ws-office-emails') {
      if (!$tblExists('ws_offices')) json_vide(['ws_offices']);
      // Cloisonné : cette liste servait les bureaux de TOUT le réseau, adresses
      // e-mail comprises. Un franchisé y lisait le carnet d'adresses des autres.
      $sc  = (col_exists('ws_offices', 'shop_id') && $shopId) ? " AND o.shop_id = " . (int) $shopId : "";
      $out = [];
      foreach (rows("SELECT o.id, o.name, o.email FROM ws_offices o
                      WHERE o.email IS NOT NULL AND o.email <> '' AND o.active = 1$sc
                      ORDER BY o.name LIMIT 300") as $f)
        $out[] = ['id' => null, 'officeId' => (int) $f['id'], 'bureau' => $f['name'],
                  'addr' => $f['email'], 'role' => 'Principal', 'source' => 'office'];
      /* La colonne `role` est vérifiée, pas supposée. ws_office_emails
         EXISTAIT avant le système de migrations, avec un autre jeu de colonnes
         (contract_url, pas de role) : la 0063, écrite en CREATE TABLE IF NOT
         EXISTS, n'a donc rien fait sur les installations concernées, et ce
         SELECT partait en erreur SQL — écran mort, HTTP 500, aucun motif. La
         0065 ajoute la colonne ; tant qu'elle n'est pas passée, on DIT lequel
         des deux problèmes on a plutôt que de rendre une liste amputée. */
      if ($tblExists('ws_office_emails') && !col_exists('ws_office_emails', 'role'))
        json_out(['ok' => false, 'error' => 'Table ws_office_emails sans colonne « role » — migration 0065 non appliquée.'], 501);
      if ($tblExists('ws_office_emails')) {
        foreach (rows("SELECT e.id, e.office_id, e.email, e.role, o.name
                         FROM ws_office_emails e
                         JOIN ws_offices o ON o.id = e.office_id
                        WHERE e.active = 1$sc ORDER BY o.name, e.role, e.id LIMIT 500") as $e) {
          // Doublon exact avec la fiche : une seule ligne, celle de la fiche.
          foreach ($out as $x)
            if ($x['officeId'] === (int) $e['office_id']
                && strcasecmp($x['addr'], $e['email']) === 0 && $x['role'] === $e['role']) continue 2;
          $out[] = ['id' => (int) $e['id'], 'officeId' => (int) $e['office_id'], 'bureau' => $e['name'],
                    'addr' => $e['email'], 'role' => $e['role'], 'source' => 'table'];
        }
      }
      json_out($out);
    }

    /* Écriture d'UN contact — jamais le remplacement de la table.
       Même raison que pour les départements : /franchisee/save remplace une
       table entière, ce qui n'a pas de sens pour une ligne rattachée à un
       bureau, et a déjà vidé une table ERP une fois. */
    if ($m === 'POST' && $p === '/franchisee/office-email') {
      if (!$tblExists('ws_office_emails'))
        json_out(['ok' => false, 'error' => 'Table ws_office_emails absente — migration 0063 non appliquée.'], 501);
      // Même garde qu'en lecture : la table peut exister SANS `role`, héritée
      // d'avant les migrations. Écrire un rôle dans une colonne inexistante
      // aurait rendu un 500 au lieu du geste à faire.
      if (!col_exists('ws_office_emails', 'role'))
        json_out(['ok' => false, 'error' => 'Table ws_office_emails sans colonne « role » — migration 0065 non appliquée.'], 501);
      $b   = body();
      $oSc = (col_exists('ws_offices', 'shop_id') && $shopId) ? " AND (shop_id IS NULL OR shop_id = " . (int) $shopId . ")" : "";
      $aMoi = function ($id) use ($oSc) {
        return row("SELECT e.id FROM ws_office_emails e
                      JOIN ws_offices o ON o.id = e.office_id
                     WHERE e.id = ?" . str_replace('shop_id', 'o.shop_id', $oSc), [(int) $id]);
      };

      if (!empty($b['delete'])) {
        $id = (int) ($b['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' =>
          'Ce contact vient de la fiche du bureau — modifiez-le sur la fiche (Clients B2B › Offices), il n’est pas supprimable ici.'], 409);
        if (!$aMoi($id)) json_out(['ok' => false, 'error' => 'Contact inconnu, ou rattaché à un bureau d’une autre boutique.'], 404);
        q("DELETE FROM ws_office_emails WHERE id = ?", [$id]);
        json_out(['ok' => true, 'deleted' => $id]);
      }

      $mail = strtolower(trim((string) ($b['addr'] ?? ($b['email'] ?? ''))));
      if (!filter_var($mail, FILTER_VALIDATE_EMAIL))
        json_out(['ok' => false, 'error' => 'Adresse e-mail invalide.'], 400);
      $role  = trim((string) ($b['role'] ?? 'Principal'));
      $ROLES = ['Principal', 'Facturation', 'Livraison'];
      if (!in_array($role, $ROLES, true))
        json_out(['ok' => false, 'error' => 'Rôle inconnu — attendu : ' . implode(', ', $ROLES) . '.'], 400);

      // Bureau : par id, sinon par nom (c'est ce que l'écran connaît).
      $oid = (int) ($b['officeId'] ?? 0);
      $nom = trim((string) ($b['bureau'] ?? ''));
      if (!$oid && $nom !== '') {
        $o = row("SELECT id FROM ws_offices WHERE name = ?$oSc ORDER BY id LIMIT 1", [$nom]);
        $oid = (int) ($o['id'] ?? 0);
      }
      if (!$oid) json_out(['ok' => false, 'error' => $nom === ''
        ? 'Aucun bureau indiqué — un contact se rattache à un bureau.'
        : 'Aucun bureau « ' . $nom . ' » dans votre boutique.'], 409);
      if (!row("SELECT 1 x FROM ws_offices WHERE id = ?$oSc", [$oid]))
        json_out(['ok' => false, 'error' => 'Ce bureau appartient à une autre boutique.'], 403);

      $id = (int) ($b['id'] ?? 0);
      if ($id) {
        if (!$aMoi($id)) json_out(['ok' => false, 'error' => 'Contact inconnu, ou rattaché à un bureau d’une autre boutique.'], 404);
        q("UPDATE ws_office_emails SET office_id=?, email=?, role=? WHERE id=?", [$oid, $mail, $role, $id]);
        json_out(['ok' => true, 'id' => $id]);
      }
      // Le même contact deux fois pour le même rôle n'ajoute rien : on renvoie
      // la ligne existante plutôt qu'une erreur — le geste a bien abouti.
      $dej = row("SELECT id FROM ws_office_emails WHERE office_id=? AND email=? AND role=?", [$oid, $mail, $role]);
      if ($dej) { q("UPDATE ws_office_emails SET active=1 WHERE id=?", [(int) $dej['id']]);
                  json_out(['ok' => true, 'id' => (int) $dej['id'], 'deja' => true]); }
      q("INSERT INTO ws_office_emails (office_id, email, role) VALUES (?,?,?)", [$oid, $mail, $role]);
      json_out(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }

    // ── Départements B2B (b2b_client_company_department) — table ERP si synchronisée. ──
    if ($m === 'GET' && $p === '/franchisee/b2b-departments') {
      if (!$tblExists('b2b_client_company_department')) json_vide(['b2b_client_company_department']);
      /* Cloisonné par le CLIENT porteur : la table servait ses 500 premières
         lignes, tous réseaux confondus — les départements des clients des
         autres boutiques compris. Le nom de la colonne de rattachement varie
         selon la version du schéma ERP, d'où la résolution défensive ; sans
         aucune d'elles, on ne peut pas cloisonner, donc on ne sert rien plutôt
         que de servir tout. */
      $depCli = col_exists('b2b_client_company_department', 'id_client') ? 'id_client'
              : (col_exists('b2b_client_company_department', 'client_id') ? 'client_id' : null);
      if (!$shopId || !$clientShopCol)
        json_out(rows("SELECT * FROM b2b_client_company_department LIMIT 500"));
      if (!$depCli) json_out([]);
      json_out(rows("SELECT d.* FROM b2b_client_company_department d
                       JOIN client c ON CAST(c.id AS CHAR) = CAST(d.$depCli AS CHAR)
                      WHERE c.$clientShopCol = ? LIMIT 500", [(int) $shopId]));
    }

    /* ── UN département : créer, renommer, supprimer ──────────────────────────
       L'écriture des départements passait par /franchisee/save, qui remplace
       une table ENTIÈRE. Sur une table ERP rattachée par id_client (entier),
       depuis un écran qui ne connaît que la RAISON SOCIALE, c'était intenable :
       la route la refuse (409) — à raison, un DELETE intégral y a déjà vidé la
       table une fois — et l'écran continuait pourtant de la demander. Le
       bandeau annonçait donc un refus à chaque enregistrement. Un bandeau
       permanent n'est plus lu : c'est ainsi qu'une vraie panne passe inaperçue.

       On écrit donc UNE LIGNE à la fois, rattachée au client RÉEL résolu depuis
       sa raison sociale, et bornée à la boutique. Rien d'inventé : sans client
       correspondant, on refuse en le disant, au lieu de fabriquer un code. ── */
    if ($m === 'POST' && $p === '/franchisee/b2b-department') {
      if (!$tblExists('b2b_client_company_department'))
        json_out(['ok' => false, 'error' => 'Table b2b_client_company_department absente de cette base.'], 501);
      $DT   = 'b2b_client_company_department';
      $b    = body();
      $dKey = col_exists($DT, 'id_client') ? 'id_client' : (col_exists($DT, 'client_id') ? 'client_id' : null);
      $dNam = col_exists($DT, 'name') ? 'name' : (col_exists($DT, 'dept') ? 'dept' : null);
      if (!$dKey || !$dNam)
        json_out(['ok' => false, 'error' => 'Schéma inattendu : ni colonne de rattachement client, ni colonne de nom.'], 501);

      // Portée : le département suit son client, et le client suit la boutique.
      $scopeSql  = ($shopId && $clientShopCol) ? " AND c.$clientShopCol = " . (int) $shopId : "";
      $ligneAMoi = function ($id) use ($DT, $dKey, $scopeSql) {
        return row("SELECT d.id FROM $DT d JOIN client c ON CAST(c.id AS CHAR) = CAST(d.$dKey AS CHAR)
                     WHERE d.id = ?$scopeSql", [(int) $id]);
      };

      if (!empty($b['delete'])) {
        $id = (int) ($b['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' => 'Département à supprimer non précisé.'], 400);
        if (!$ligneAMoi($id)) json_out(['ok' => false, 'error' => 'Département inconnu, ou rattaché à un client d’une autre boutique.'], 404);
        q("DELETE FROM $DT WHERE id = ?", [$id]);
        json_out(['ok' => true, 'deleted' => $id]);
      }

      $nom = trim((string) ($b['dept'] ?? ($b['name'] ?? '')));
      if ($nom === '') json_out(['ok' => false, 'error' => 'Nom du département requis.'], 400);

      // Client porteur : par id s'il est fourni, sinon par raison sociale —
      // c'est ce que l'écran connaît. La recherche reste bornée à la boutique.
      $cid = (int) ($b['clientId'] ?? 0);
      $soc = trim((string) ($b['company'] ?? ''));
      if (!$cid && $soc !== '') {
        $c = row("SELECT id FROM client WHERE company_name = ?"
               . (($shopId && $clientShopCol) ? " AND $clientShopCol = " . (int) $shopId : "")
               . " ORDER BY id LIMIT 1", [$soc]);
        $cid = (int) ($c['id'] ?? 0);
      }
      if (!$cid)
        json_out(['ok' => false, 'error' => $soc === ''
          ? 'Aucune société indiquée — un département se rattache à un client B2B.'
          : 'Aucun client B2B « ' . $soc . ' » dans votre boutique — créez la société avant son département.'], 409);
      if ($shopId && $clientShopCol && !row("SELECT 1 x FROM client WHERE id = ? AND $clientShopCol = " . (int) $shopId, [$cid]))
        json_out(['ok' => false, 'error' => 'Ce client appartient à une autre boutique.'], 403);

      // Colonnes annexes : écrites SEULEMENT si elles existent (schéma ERP variable).
      $opt = [];
      foreach (['company' => $soc, 'site' => (string) ($b['site'] ?? ''),
                'office' => (string) ($b['office'] ?? ''),
                'contact' => (string) ($b['contact'] ?? '')] as $c2 => $v2)
        if (col_exists($DT, $c2)) $opt[$c2] = $v2;
      $hasEff = col_exists($DT, 'effectif');

      $id = (int) ($b['id'] ?? 0);
      if ($id) {
        if (!$ligneAMoi($id)) json_out(['ok' => false, 'error' => 'Département inconnu, ou rattaché à un client d’une autre boutique.'], 404);
        $sets = ["$dKey = ?", "$dNam = ?"]; $vals = [$cid, $nom];
        foreach ($opt as $c2 => $v2) { $sets[] = "$c2 = ?"; $vals[] = $v2; }
        if ($hasEff) { $sets[] = "effectif = ?"; $vals[] = (int) ($b['effectif'] ?? 1); }
        $vals[] = $id;
        q("UPDATE $DT SET " . implode(', ', $sets) . " WHERE id = ?", $vals);
        json_out(['ok' => true, 'id' => $id, 'clientId' => $cid]);
      }
      $cols = array_merge([$dKey, $dNam], array_keys($opt), $hasEff ? ['effectif'] : []);
      $vals = array_merge([$cid, $nom], array_values($opt), $hasEff ? [(int) ($b['effectif'] ?? 1)] : []);
      q("INSERT INTO $DT (" . implode(', ', $cols) . ") VALUES ("
        . implode(', ', array_fill(0, count($cols), '?')) . ")", $vals);
      json_out(['ok' => true, 'id' => (int) db()->lastInsertId(), 'clientId' => $cid], 201);
    }

    // ── Menu « Clients » — clients (table ERP client) rattachés aux bureaux. ──
    //    Une ligne par client, avec les signaux de badges : commandes/récurrence
    //    (ws_orders.customer_id), voucher nominatif (ws_vouchers.client_id),
    //    réclamation client (ws_incidents.client_id — migration 0025), achats
    //    magasin (pwa_purchases si présente), bureau/tournée via ws_offices,
    //    différé au niveau bureau. Cloisonné boutique (preferred/id_main_shop).
    if ($m === 'GET' && $p === '/franchisee/b2b-clients') {
      if (!$tblExists('client')) json_vide(['client']);
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
      // Bons nominatifs : modèle UNIFIÉ (cible CUSTOMER) + legacy ws_client_vouchers (0025).
      $vaParts = []; $vuParts = [];
      if ($tblExists('voucher_code')) {
        $vaParts[] = "(SELECT COUNT(*) FROM voucher_code vco JOIN voucher_campaign vc2 ON vc2.id=vco.id_voucher_campaign
                        WHERE vc2.target_kind='CUSTOMER' AND vc2.target_id=c.id AND vco.status='ACTIVE'
                          AND (vco.usage_limit IS NULL OR vco.usage_count < vco.usage_limit))";
        $vuParts[] = "(SELECT COUNT(*) FROM voucher_code vco JOIN voucher_campaign vc2 ON vc2.id=vco.id_voucher_campaign
                        WHERE vc2.target_kind='CUSTOMER' AND vc2.target_id=c.id AND vco.usage_count > 0)";
      }
      if ($tblExists('ws_client_vouchers')) {
        $vaParts[] = "(SELECT COUNT(*) FROM ws_client_vouchers v WHERE v.client_id=c.id AND v.active=1 AND v.used_count < v.max_uses)";
        $vuParts[] = "(SELECT COUNT(*) FROM ws_client_vouchers v WHERE v.client_id=c.id AND v.used_count > 0)";
      }
      $sel .= $vaParts ? ", (" . implode(' + ', $vaParts) . ") AS voucher_active, (" . implode(' + ', $vuParts) . ") AS voucher_used"
                       : ", 0 AS voucher_active, 0 AS voucher_used";
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
      /* Portée BOUTIQUE de la liste clients : la boutique PRÉFÉRÉE du client,
         celle qu'il a choisie. La règle précédente retombait sur id_main_shop
         quand la préférence était nulle — elle faisait donc remonter tous les
         clients que l'ERP rattache administrativement à la boutique, y compris
         ceux qui ne l'ont jamais choisie ni jamais commandé chez elle.
         id_main_shop ne sert plus que de repli de SCHÉMA, quand la colonne de
         préférence n'existe pas. */
      $where = "1=1";
      if ($shopId) {
        $where = $cc('preferred_shop_id')
          ? "c.preferred_shop_id = " . (int) $shopId
          : "c.id_main_shop = " . (int) $shopId;
      }
      // Soft delete : un client « supprimé » (active=0) n'apparaît plus.
      if ($cc('active')) $where .= " AND (c.active = 1 OR c.active IS NULL)";
      // Pas de LIMIT : la liste clients doit être complète.
      json_out(rows("SELECT $sel$offCols$dep FROM client c$joins WHERE $where ORDER BY c.id DESC"));
    }

    // ── Liste clients : rattachement à un office (+ département facultatif). ──
    /* ── Jeton d'appareil de la boutique (tablette Kitchen) ──────────────────
       POST   génère un jeton et RÉVOQUE le précédent (un seul actif par
              boutique) — il n'est renvoyé en clair QU'ICI, comme un mot de
              passe : la base n'en garde que le SHA-256.
       DELETE révoque le jeton actif — les tablettes qui l'utilisent sont
              déconnectées à leur requête suivante.
       GET    état du jeton actif (préfixe, dates), jamais le jeton lui-même.

       Réservé au jeton admin ERP : une tablette ne se délivre pas ses propres
       droits. Le bloc /franchisee/ est déjà sous require_admin(). ── */
    if ($p === '/franchisee/device-token') {
      if (!$tblExists('ws_shop_device_token'))
        json_out(['ok' => false, 'error' => 'Table ws_shop_device_token absente — jeton impossible (migration 0052).'], 501);
      $bd  = ($m === 'GET') ? [] : body();
      $sid = (int) ($bd['shopId'] ?? ($shopId ?: 0));
      if (!$sid) json_out(['ok' => false, 'error' => 'Boutique inconnue — ouvrez le back-office avec ?shop=<id>'], 400);

      if ($m === 'GET') {
        $cur = row("SELECT token_prefix, created_at, last_seen_at FROM ws_shop_device_token
                     WHERE shop_id = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1", [$sid]);
        json_out(['ok' => true, 'active' => (bool) $cur, 'prefix' => $cur['token_prefix'] ?? null,
                  'createdAt' => $cur['created_at'] ?? null, 'lastSeenAt' => $cur['last_seen_at'] ?? null]);
      }
      if ($m === 'DELETE') {
        q("UPDATE ws_shop_device_token SET revoked_at = NOW() WHERE shop_id = ? AND revoked_at IS NULL", [$sid]);
        json_out(['ok' => true, 'revoked' => true]);
      }
      if ($m === 'POST') {
        // 32 octets d'aléa cryptographique : un jeton devinable ouvrirait le
        // comptoir d'une boutique à n'importe qui.
        $tok = bin2hex(random_bytes(32));
        q("UPDATE ws_shop_device_token SET revoked_at = NOW() WHERE shop_id = ? AND revoked_at IS NULL", [$sid]);
        q("INSERT INTO ws_shop_device_token (shop_id, token_hash, token_prefix, label)
           VALUES (?,?,?,?)", [$sid, hash('sha256', $tok), substr($tok, 0, 8), 'Tablette Kitchen']);
        json_out(['ok' => true, 'token' => $tok, 'prefix' => substr($tok, 0, 8)]);
      }
      json_out(['ok' => false, 'error' => 'Méthode non supportée'], 405);
    }

    if ($m === 'POST' && $p === '/franchisee/client-attach') {
      $b = body();
      $id = (int) ($b['id'] ?? 0);
      if (!$id) json_out(['ok' => false, 'error' => 'id manquant'], 400);
      $clientGuard($id);
      $sets = []; $args = [];
      if (array_key_exists('office_id', $b) && col_exists('client', 'office_id')) {
        $ov = ($b['office_id'] === null || $b['office_id'] === '') ? null : (int) $b['office_id'];
        if ($ov !== null && $tblExists('ws_offices') && !row("SELECT id FROM ws_offices WHERE id=?", [$ov]))
          json_out(['ok' => false, 'error' => 'office inconnu'], 400);
        if ($ov !== null) $officeGuard($ov);
        $sets[] = "office_id=?"; $args[] = $ov;
      }
      if (array_key_exists('department_id', $b) && col_exists('client', 'department_id')) {
        $dv = ($b['department_id'] === null || $b['department_id'] === '') ? null : (int) $b['department_id'];
        // Le département doit appartenir à un client de CETTE boutique : sans
        // ce contrôle, on rattachait son propre client au département d'une
        // autre — les deux fiches se retrouvaient liées entre boutiques.
        if ($dv !== null && $tblExists('b2b_client_company_department')) {
          $dCli = col_exists('b2b_client_company_department', 'id_client') ? 'id_client'
                : (col_exists('b2b_client_company_department', 'client_id') ? 'client_id' : null);
          $okDep = ($shopId && $clientShopCol && $dCli)
            ? row("SELECT d.id FROM b2b_client_company_department d
                     JOIN client c ON CAST(c.id AS CHAR) = CAST(d.$dCli AS CHAR)
                    WHERE d.id=? AND c.$clientShopCol=?", [$dv, (int) $shopId])
            : row("SELECT id FROM b2b_client_company_department WHERE id=?", [$dv]);
          if (!$okDep) json_out(['ok' => false, 'error' => 'département inconnu ou hors de votre boutique'], 400);
        }
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
      $clientGuard($id);
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
      $clientGuard($id);
      q("UPDATE client SET zip=? WHERE id=?", [$zip, $id]);
      json_out(['ok' => true, 'zip' => $zip]);
    }

    /* ── Livraison au bureau (client.office_delivery) = VALIDATION MANUELLE du
       franchisé. Activer : valide le client (status=0), crée ou réactive son
       bureau, puis le marque VALIDÉ → livrable. Désactiver : le désactive.
       Tout se fait EN PHP. Le commentaire d'origine renvoyait au trigger
       trg_client_office_delivery_au (migrations 0019/0021/0023) : ce trigger est
       ABSENT de la base servie. Le code s'en passait déjà — par la branche
       « auto-réparation » plus bas — mais la note laissait croire à un mécanisme
       de secours qui n'existe pas. */
    if ($m === 'POST' && $p === '/franchisee/client-office-delivery') {
      $b = body();
      $id = (int) ($b['id'] ?? 0);
      if (!$id || !col_exists('client', 'office_delivery')) json_out(['ok' => false, 'error' => 'id ou colonne office_delivery manquant'], 400);
      $clientGuard($id);
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
          // Chercher le site du bureau SANS filtrer sur active : en le filtrant,
          // un site désactivé (livraison coupée puis reprise) n'était pas
          // retrouvé et un NOUVEAU était inséré. Chaque cycle arrêt/reprise
          // ajoutait ainsi un doublon — même nom, même adresse, même tournée —
          // rendant le choix du site ambigu côté franchisé. L'UPDATE remet
          // active=1, ce qui réactive la ligne existante au lieu d'en créer une.
          $ex = row("SELECT id FROM ws_office_delivery_sites WHERE office_client_id=? ORDER BY active DESC, id LIMIT 1", [(int) $off['id']]);
          if ($ex) q("UPDATE ws_office_delivery_sites SET address=?, name=COALESCE(?, name), tournee_id=COALESCE(?, tournee_id), active=1 WHERE id=?",
                     [$siteAdr, $tpl['name'] ?? null, $tpl['tournee_id'] ?? null, (int) $ex['id']]);
          else q("INSERT INTO ws_office_delivery_sites (office_client_id, name, address, tournee_id, site_access_minutes, active, shop_id)
                    VALUES (?,?,?,?,?,1,?)",
                 [(int) $off['id'], $tpl['name'] ?? null, $siteAdr, $tpl['tournee_id'] ?? null,
                  // Repris du site modèle tel quel : absent chez lui, absent ici.
                  $tpl['site_access_minutes'] ?? null, $shopId ?: ($tpl['shop_id'] ?? null)]);
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
      $clientGuard($id);
      q("UPDATE client SET blocked=? WHERE id=?", [!empty($b['blocked']) ? 1 : 0, $id]);
      json_out(['ok' => true, 'blocked' => !empty($b['blocked'])]);
    }

    // ── Fiche client : facturation personne morale (toggle + TVA VIES obligatoire). ──
    if ($m === 'POST' && $p === '/franchisee/client-billing') {
      $b = body();
      $id = (int) ($b['id'] ?? 0);
      if (!$id) json_out(['ok' => false, 'error' => 'id manquant'], 400);
      $clientGuard($id);
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

    // ── BONS de la boutique (modèle ERP unifié — mêmes tables que la marque). ──
    // Liste : MES bons (éditables) + les bons MARQUE applicables (lecture seule).
    if ($m === 'GET' && $p === '/franchisee/fr-vouchers') {
      if (!$tblExists('voucher_code')) json_vide(['voucher_code']);
      $hasReason = col_exists('voucher_campaign', 'reason_kind');
      $vs = rows("SELECT vco.code, vc.id_shop, vc.target_kind, vc.target_id,
                         vco.usage_count, vco.usage_limit, vc.usage_limit_per_customer, vco.valid_to AS expires_at," .
                         (col_exists('promotion_order_discount', 'scope_id_product') ? " pod.scope_id_product, pod.scope_max_qty," : " NULL AS scope_id_product, NULL AS scope_max_qty,") .
                         ($hasReason ? " vc.reason_kind, vc.reason_note," : " NULL AS reason_kind, NULL AS reason_note,") . "
                         CASE pod.discount_kind WHEN 'PERCENT' THEN 'percent'
                              WHEN 'FIXED' THEN 'fixed' WHEN 'FREE_DELIVERY' THEN 'free_delivery'
                              ELSE pod.discount_kind END AS type,
                         pod.discount_value AS value, pod.min_order_amount AS min_order,
                         CASE WHEN pr.status='ACTIVE' AND vco.status='ACTIVE' THEN 1 ELSE 0 END AS active
                    FROM voucher_code vco
                    JOIN voucher_campaign vc          ON vc.id = vco.id_voucher_campaign
                    JOIN voucher_campaign_channel vcc ON vcc.id_voucher_campaign = vc.id AND vcc.channel = 'WS'
                    JOIN promotion pr                 ON pr.id = vc.id_promotion
                    JOIN promotion_order_discount pod ON pod.id_promotion = pr.id
                   WHERE vc.id_shop IS NULL" . ($shopId ? " OR vc.id_shop = " . (int) $shopId : "") . "
                   ORDER BY vc.id_shop IS NULL, vco.code");
      $REASON = ['RECLAMATION' => 'Réclamation', 'GESTE_CO' => 'Geste commercial',
                 'FIDELITE' => 'Fidélité', 'MARKETING' => 'Marketing',
                 'PARTENARIAT' => 'Partenariat', 'TEST' => 'Test / interne'];
      $out = [];
      foreach ($vs as $v) {
        $effet = $v['type'] === 'percent' ? '−' . rtrim(rtrim((string) $v['value'], '0'), '.') . ' %'
               : ($v['type'] === 'fixed' ? '−' . rtrim(rtrim((string) $v['value'], '0'), '.') . ' €' : 'port offert');
        $scopeName = '';
        if (!empty($v['scope_id_product'])) {
          $pn3 = row("SELECT name FROM ws_products WHERE id=?", [(int) $v['scope_id_product']]);
          $scopeName = ($pn3['name'] ?? null) ?: ('produit #' . $v['scope_id_product']);
          $effet .= ' sur ' . ($v['scope_max_qty'] !== null ? ((int) $v['scope_max_qty'] . ' × ') : '') . $scopeName;
        }
        if ((float) $v['min_order'] > 0) $effet .= ' dès ' . rtrim(rtrim(number_format((float) $v['min_order'], 2, ',', ''), '0'), ',') . ' €';
        $cible = 'Tous les clients';
        if ($v['target_kind'] === 'CUSTOMER' && $v['target_id']) {
          $c2 = $tblExists('client') ? row("SELECT CONCAT(COALESCE(name,''),' ',COALESCE(surname,'')) n FROM client WHERE id=?", [(int) $v['target_id']]) : null;
          $cible = 'Client ' . trim(($c2['n'] ?? '') ?: ('#' . $v['target_id']));
        } elseif ($v['target_kind'] === 'OFFICE' && $v['target_id']) {
          $o2 = $tblExists('ws_offices') ? row("SELECT name FROM ws_offices WHERE id=?", [(int) $v['target_id']]) : null;
          $cible = 'Bureau ' . (($o2['name'] ?? '') ?: ('#' . $v['target_id']));
        } elseif ($v['target_kind'] === 'GROUP' && $v['target_id']) {
          $cible = 'Groupe #' . $v['target_id'];
        }
        $mine = $v['id_shop'] !== null && $shopId && (int) $v['id_shop'] === (int) $shopId;
        $out[] = ['code' => $v['code'], 'effet' => $effet,
                  'origine' => $v['id_shop'] === null ? 'Marque' : 'Ma boutique',
                  'cible' => $cible,
                  'motif' => $v['reason_kind'] ? (($REASON[$v['reason_kind']] ?? $v['reason_kind'])
                              . ($v['reason_note'] ? ' — ' . $v['reason_note'] : '')) : '—',
                  'usages' => (int) $v['usage_count'] . ($v['usage_limit'] !== null ? ' / ' . (int) $v['usage_limit'] : '')
                            . ($v['usage_limit_per_customer'] !== null ? ' · ' . (int) $v['usage_limit_per_customer'] . '/client' : ''),
                  'validite' => $v['expires_at'] ? ('jusqu\'au ' . substr($v['expires_at'], 0, 10)) : 'permanent',
                  'act' => (bool) $v['active'], 'editable' => $mine,
                  // Champs bruts pour le formulaire d'édition du BO.
                  'type' => $v['type'], 'value' => (float) $v['value'], 'min_order' => (float) $v['min_order'],
                  'max_uses' => $v['usage_limit'] !== null ? (int) $v['usage_limit'] : '',
                  'expires_at' => $v['expires_at'] ? substr($v['expires_at'], 0, 10) : '',
                  'target_kind' => $v['target_kind'] ?: 'NETWORK', 'target_id' => $v['target_id'],
                  'per_customer' => $v['usage_limit_per_customer'] !== null ? (int) $v['usage_limit_per_customer'] : '',
                  'scope_product_id' => $v['scope_id_product'] !== null ? (int) $v['scope_id_product'] : '',
                  'scope_product_name' => $scopeName,
                  'scope_max_qty' => $v['scope_max_qty'] !== null ? (int) $v['scope_max_qty'] : '',
                  'reason_kind' => $v['reason_kind'] ?: '', 'reason_note' => $v['reason_note'] ?: ''];
      }
      json_out($out);
    }

    // Cibles possibles pour un bon de boutique : clients + bureaux de MA boutique.
    if ($m === 'GET' && $p === '/franchisee/fr-voucher-targets') {
      $clients = $tblExists('client')
        ? rows("SELECT id, TRIM(CONCAT(COALESCE(name,''),' ',COALESCE(surname,''))) AS nom" .
                (col_exists('client', 'company_name') ? ", company_name AS soc" : ", NULL AS soc") . "
                  FROM client" .
                (col_exists('client', 'id_main_shop') && $shopId ? " WHERE id_main_shop = " . (int) $shopId : "") . "
                 ORDER BY nom LIMIT 500")
        : [];
      $offices = $tblExists('ws_offices')
        ? rows("SELECT o.id, o.name AS nom, o.city AS sub," .
                (col_exists('client', 'office_id') ? " (SELECT COUNT(*) FROM client c2 WHERE c2.office_id = o.id) AS hc" : " NULL AS hc") . "
                  FROM ws_offices o WHERE o.active=1" .
                (col_exists('ws_offices', 'shop_id') && $shopId ? " AND o.shop_id = " . (int) $shopId : "") . "
                 ORDER BY o.name LIMIT 200")
        : [];
      $products = rows("SELECT p.id, p.name AS nom, c.label AS sub, p.price AS price
                          FROM ws_products p
                          LEFT JOIN ws_categories c ON c.id = p.cat_id
                         WHERE p.active=1 ORDER BY p.name LIMIT 500");
      // Prix : ws_products.price, par la fonction partagée. La jointure sur
      // ws_product_prices a disparu avec le repli qu'elle alimentait.
      if ($products) {
        $erpP = prix_produits(array_map(static fn ($x2) => (int) $x2['id'], $products), $shopId ? (int) $shopId : null);
        foreach ($products as &$pp3) { if (isset($erpP[(int) $pp3['id']])) $pp3['price'] = $erpP[(int) $pp3['id']]; $pp3['price'] = (float) $pp3['price']; }
        unset($pp3);
      }
      json_out(['clients' => $clients, 'offices' => $offices, 'products' => $products]);
    }

    // Création / édition d'un bon de MA boutique (jamais un bon marque).
    if ($m === 'POST' && $p === '/franchisee/voucher') {
      if (!$shopId) json_out(['error' => 'boutique requise (?shop=)'], 400);
      $b = body();
      $r = ws_voucher_upsert([
        'code' => $b['code'] ?? '', 'type' => $b['type'] ?? 'percent', 'value' => $b['value'] ?? 0,
        'min_order' => $b['min_order'] ?? 0, 'max_uses' => $b['max_uses'] ?? '',
        'expires_at' => $b['expires_at'] ?? null, 'active' => $b['active'] ?? 1,
        'id_shop' => $shopId, 'owner_guard' => $shopId,       // émetteur = MA boutique, verrou d'édition
        'target_kind' => $b['target_kind'] ?? 'NETWORK', 'target_id' => $b['target_id'] ?? null,
        'scope_product_id' => $b['scope_product_id'] ?? null, 'scope_max_qty' => $b['scope_max_qty'] ?? null,
        'per_customer' => $b['per_customer'] ?? null,
        'reason_kind' => $b['reason_kind'] ?? null, 'reason_note' => $b['reason_note'] ?? null,
        'created_by'  => 'shop:' . $shopId,
      ]);
      if (!empty($r['error'])) json_out(['ok' => false, 'error' => $r['error']], $r['status'] ?? 400);
      json_out(['ok' => true, 'code' => $r['code']]);
    }

    // Activation / désactivation d'un bon de MA boutique.
    if ($m === 'POST' && $p === '/franchisee/voucher-toggle') {
      if (!$shopId) json_out(['error' => 'boutique requise (?shop=)'], 400);
      $b = body(); $code = strtoupper(trim((string) ($b['code'] ?? '')));
      $ex = row("SELECT vco.id AS code_id, vc.id_promotion AS promotion_id, vc.id_shop
                   FROM voucher_code vco JOIN voucher_campaign vc ON vc.id = vco.id_voucher_campaign
                  WHERE vco.code = ? LIMIT 1", [$code]);
      if (!$ex) json_out(['ok' => false, 'error' => 'code inconnu'], 404);
      if ((int) ($ex['id_shop'] ?? 0) !== (int) $shopId)
        json_out(['ok' => false, 'error' => 'ce bon appartient à ' . ($ex['id_shop'] === null ? 'la marque' : 'une autre boutique')], 403);
      $on = !empty($b['active']);
      q("UPDATE voucher_code SET status=? WHERE id=?", [$on ? 'ACTIVE' : 'DISABLED', $ex['code_id']]);
      q("UPDATE promotion SET status=? WHERE id=?", [$on ? 'ACTIVE' : 'DRAFT', $ex['promotion_id']]);
      json_out(['ok' => true]);
    }

    // ── Fiche client : voucher / remboursement nominatif — désormais écrit dans
    //    le MODÈLE UNIFIÉ (voucher_campaign id_shop=MA boutique, cible CUSTOMER).
    //    Avant : table à part ws_client_vouchers (0025), JAMAIS lue au checkout
    //    (codes morts). Les codes RB- émis ici sont maintenant réellement
    //    utilisables au webshop — uniquement par CE client, dans CETTE boutique.
    if ($m === 'POST' && $p === '/franchisee/client-voucher') {
      $b = body();
      $cid = (int) ($b['client_id'] ?? 0);
      $val = (float) ($b['value'] ?? 0);
      if (!$cid || $val <= 0) json_out(['ok' => false, 'error' => 'client_id et value (>0) requis'], 400);
      if (!$shopId) json_out(['ok' => false, 'error' => 'boutique requise (?shop=)'], 400);
      if (!$tblExists('voucher_code')) json_out(['ok' => false, 'error' => 'modèle vouchers ERP absent (migrations 0005+)'], 501);
      $type = in_array(($b['type'] ?? ''), ['percent', 'fixed'], true) ? $b['type'] : 'fixed';
      $code = 'RB-' . $cid . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
      $r = ws_voucher_upsert([
        'code' => $code, 'type' => $type, 'value' => $val, 'max_uses' => 1,
        'id_shop' => $shopId, 'owner_guard' => $shopId,
        'target_kind' => 'CUSTOMER', 'target_id' => $cid,
        'reason_kind' => in_array(($b['reason'] ?? ''), ['RECLAMATION', 'GESTE_CO', 'FIDELITE'], true) ? $b['reason'] : 'GESTE_CO',
        'reason_note' => $b['note'] ?? null,
        'created_by'  => 'shop:' . $shopId,
      ]);
      if (!empty($r['error'])) json_out(['ok' => false, 'error' => $r['error']], $r['status'] ?? 400);
      json_out(['ok' => true, 'code' => $code, 'type' => $type, 'value' => $val]);
    }

    // ── Fiche client : commandes du client (droplist réclamation + note ★1-5). ──
    if ($m === 'GET' && $p === '/franchisee/client-orders') {
      $cid = (int) qp('client_id', 0);
      if (!$tblExists('ws_orders')) json_vide(['ws_orders']);
      if (!$cid) json_out([]);   // pas de client demandé : ce n'est pas une panne
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
        // Ne résoudre que les incidents d'un client DE LA BOUTIQUE (le guard
        // lève 403 si le client est hors périmètre), et borner l'UPDATE à
        // shop_id quand la colonne existe.
        $clientGuard((int) $b['resolve_client_id']);
        $icSc = ($shopId && col_exists('ws_incidents','shop_id')) ? " AND shop_id=".(int)$shopId : "";
        q("UPDATE ws_incidents SET resolved_at=NOW(), status='resolved' WHERE client_id=? AND resolved_at IS NULL$icSc", [(int) $b['resolve_client_id']]);
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
      if (!$tblExists('ws_tour_availability') || !$tblExists('ws_tours')) json_vide(['ws_tour_availability', 'ws_tours']);
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
      if (!$tblExists('ws_tour_closures')) json_vide(['ws_tour_closures']);
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
      if (!$tblExists('ws_calendar_rules')) json_vide(['ws_calendar_rules']);
      $rs = rows("SELECT id, mode, open_days, cutoff_hour, cutoff_minutes, lead_hours FROM ws_calendar_rules
                   WHERE " . $scope('shop_id') . " AND active=1 ORDER BY mode LIMIT 50");
      json_out(array_map(function ($r) use ($DAYS) {
        $days = json_decode((string) $r['open_days'], true);
        $days = is_array($days) ? array_values(array_map('intval', $days)) : [];
        $jours = $days ? implode(' · ', array_map(fn ($d) => $DAYS[(($d + 6) % 7)], $days)) : '—';
        return ['id' => (int) $r['id'],
                'mode' => $r['mode'] === 'delivery' ? 'Livraison' : 'Retrait', 'days' => $jours,
                'cut' => sprintf('%02d:%02d J-1', (int) $r['cutoff_hour'], (int) $r['cutoff_minutes']),
                'lead' => ((int) $r['lead_hours']) . ' h',
                // Brut, pour l'édition : les jours en ISO (1 = lundi), l'heure
                // en HH:MM, le délai en heures. Le texte ci-dessus ne se relit
                // pas — c'est ce qui avait fait du formulaire du texte libre.
                'vJours' => $days,
                'vCut' => sprintf('%02d:%02d', (int) $r['cutoff_hour'], (int) $r['cutoff_minutes']),
                'vLead' => (int) $r['lead_hours']];
      }, $rs));
    }

    /* Écriture d'UNE règle calendrier — elle décide si une commande est
       acceptée (jours ouverts, heure limite, délai minimum). Le formulaire
       écrivait dans l'overlay ws_bo_store : la console montrait le nouveau
       cut-off pendant que le checkout appliquait l'ancien.

       Les jours arrivent en ISO (1 = lundi), comme date('N') que le checkout
       compare ; l'heure en HH:MM ; le délai en heures. Les trois étaient du
       texte : « Lun–Ven », « 17:00 J-1 », « 24 h » — inanalysables. */
    if ($m === 'POST' && $p === '/franchisee/calendar-rule') {
      if (!$tblExists('ws_calendar_rules'))
        json_out(['ok' => false, 'error' => 'Table ws_calendar_rules absente.'], 501);
      $b  = body();
      $sc = $shopId ? (int) $shopId : null;
      $rid = (int) ($b['id'] ?? 0);

      if (!empty($b['delete'])) {
        if (!$rid) json_out(['ok' => false, 'error' => 'Règle non précisée.'], 400);
        $own = $sc ? row("SELECT id FROM ws_calendar_rules WHERE id=? AND (shop_id=? OR shop_id IS NULL)", [$rid, $sc])
                   : row("SELECT id FROM ws_calendar_rules WHERE id=?", [$rid]);
        if (!$own) json_out(['ok' => false, 'error' => 'Règle inconnue, ou hors de votre boutique.'], 404);
        q("UPDATE ws_calendar_rules SET active=0 WHERE id=?", [$rid]);
        json_out(['ok' => true, 'deleted' => true]);
      }

      $mode = ['Livraison' => 'delivery', 'Retrait' => 'pickup'][(string) ($b['mode'] ?? '')] ?? null;
      if (!$mode) json_out(['ok' => false, 'error' => 'Mode attendu : Livraison ou Retrait.'], 400);

      // Jours : une liste d'entiers 1..7. Une liste vide fermerait la boutique
      // tous les jours — on refuse plutôt que d'enregistrer un écran qui coupe
      // les commandes sans que personne ne comprenne pourquoi.
      $jours = $b['jours'] ?? [];
      if (is_string($jours)) $jours = array_filter(explode(',', $jours), 'strlen');
      $jours = array_values(array_unique(array_map('intval', (array) $jours)));
      sort($jours);
      foreach ($jours as $j) if ($j < 1 || $j > 7)
        json_out(['ok' => false, 'error' => "Jour « $j » hors de 1 (lundi) à 7 (dimanche)."], 400);
      if (!$jours) json_out(['ok' => false, 'error' => 'Choisissez au moins un jour ouvert à la commande.'], 400);

      $cut = trim((string) ($b['cut'] ?? ''));
      if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $cut, $mm))
        json_out(['ok' => false, 'error' => 'Heure limite attendue au format HH:MM.'], 400);
      $ch = (int) $mm[1]; $cmn = (int) $mm[2];

      $lead = $b['lead'] ?? 0;
      if (!is_numeric($lead)) json_out(['ok' => false, 'error' => 'Délai : un nombre d’heures est attendu.'], 400);
      $lead = (int) $lead;
      if ($lead < 0 || $lead > 720) json_out(['ok' => false, 'error' => 'Délai hors de 0 à 720 heures.'], 400);

      $od = json_encode($jours);
      if ($rid) {
        $own = $sc ? row("SELECT id FROM ws_calendar_rules WHERE id=? AND (shop_id=? OR shop_id IS NULL)", [$rid, $sc])
                   : row("SELECT id FROM ws_calendar_rules WHERE id=?", [$rid]);
        if (!$own) json_out(['ok' => false, 'error' => 'Règle inconnue, ou hors de votre boutique.'], 404);
        q("UPDATE ws_calendar_rules SET mode=?, open_days=?, cutoff_hour=?, cutoff_minutes=?, lead_hours=?, active=1 WHERE id=?",
          [$mode, $od, $ch, $cmn, $lead, $rid]);
      } else {
        // Un mode n'a qu'une règle par boutique : réactiver celle qui existe
        // évite deux règles contradictoires dont une seule serait appliquée.
        $dej = $sc ? row("SELECT id FROM ws_calendar_rules WHERE mode=? AND shop_id=?", [$mode, $sc])
                   : row("SELECT id FROM ws_calendar_rules WHERE mode=? AND shop_id IS NULL", [$mode]);
        if ($dej) {
          q("UPDATE ws_calendar_rules SET open_days=?, cutoff_hour=?, cutoff_minutes=?, lead_hours=?, active=1 WHERE id=?",
            [$od, $ch, $cmn, $lead, (int) $dej['id']]);
          $rid = (int) $dej['id'];
        } else {
          q("INSERT INTO ws_calendar_rules (shop_id, mode, open_days, cutoff_hour, cutoff_minutes, lead_hours, active)
             VALUES (?,?,?,?,?,?,1)", [$sc, $mode, $od, $ch, $cmn, $lead]);
          $rid = (int) db()->lastInsertId();
        }
      }
      json_out(['ok' => true, 'id' => $rid]);
    }

    // ── Créneaux (ws_slots). ──
    /* ── Créneaux (ws_slots) ────────────────────────────────────────────────
       La table ne porte QUE le libellé et l'ordre d'affichage. L'écran servait
       en plus une « plage horaire » (le libellé recopié) et une « capacité »
       figée à 0 : deux colonnes qui n'existent pas, donc deux champs qu'on
       pouvait remplir sans que rien ne les enregistre. La capacité est un
       autre écran (fr_capacity) ; elle est retirée d'ici plutôt que simulée. */
    if ($m === 'GET' && $p === '/franchisee/ws-slots') {
      if (!$tblExists('ws_slots')) json_vide(['ws_slots']);
      $rs = rows("SELECT id, mode, label, sort_order FROM ws_slots
                   WHERE " . $scope('shop_id') . " AND active=1 ORDER BY sort_order, label LIMIT 100");
      json_out(array_map(fn ($r) => ['id' => (int) $r['id'],
        'mode' => $r['mode'] === 'delivery' ? 'Livraison' : 'Retrait',
        'libelle' => $r['label'], 'ordre' => (int) $r['sort_order']], $rs));
    }

    /* Écriture d'UN créneau. Le formulaire passait par l'overlay ws_bo_store :
       le créneau créé s'affichait dans la console et n'était jamais proposé au
       client, qui lit ws_slots. */
    if ($m === 'POST' && $p === '/franchisee/slot') {
      if (!$tblExists('ws_slots')) json_out(['ok' => false, 'error' => 'Table ws_slots absente.'], 501);
      $b  = body();
      $sc = $shopId ? (int) $shopId : null;
      $rid = (int) ($b['id'] ?? 0);
      $mien = function ($id) use ($sc) {
        return $sc ? row("SELECT id FROM ws_slots WHERE id=? AND (shop_id=? OR shop_id IS NULL)", [$id, $sc])
                   : row("SELECT id FROM ws_slots WHERE id=?", [$id]);
      };
      if (!empty($b['delete'])) {
        if (!$rid || !$mien($rid)) json_out(['ok' => false, 'error' => 'Créneau inconnu, ou hors de votre boutique.'], 404);
        q("UPDATE ws_slots SET active=0 WHERE id=?", [$rid]);
        json_out(['ok' => true, 'deleted' => true]);
      }
      $mode = ['Livraison' => 'delivery', 'Retrait' => 'pickup'][(string) ($b['mode'] ?? '')] ?? null;
      if (!$mode) json_out(['ok' => false, 'error' => 'Mode attendu : Livraison ou Retrait.'], 400);
      $lbl = trim((string) ($b['libelle'] ?? ''));
      if ($lbl === '') json_out(['ok' => false, 'error' => 'Donnez un libellé au créneau — c’est ce que le client lit.'], 400);
      if (mb_strlen($lbl) > 120) json_out(['ok' => false, 'error' => 'Libellé trop long (120 caractères).'], 400);
      $ord = $b['ordre'] ?? 0;
      if (!is_numeric($ord)) json_out(['ok' => false, 'error' => 'Ordre : un nombre est attendu.'], 400);
      $ord = (int) $ord;
      if ($rid) {
        if (!$mien($rid)) json_out(['ok' => false, 'error' => 'Créneau inconnu, ou hors de votre boutique.'], 404);
        q("UPDATE ws_slots SET mode=?, label=?, sort_order=?, active=1 WHERE id=?", [$mode, $lbl, $ord, $rid]);
      } else {
        q("INSERT INTO ws_slots (shop_id, mode, label, sort_order, active) VALUES (?,?,?,?,1)", [$sc, $mode, $lbl, $ord]);
        $rid = (int) db()->lastInsertId();
      }
      json_out(['ok' => true, 'id' => $rid]);
    }

    // ── Bons locaux (ws_vouchers_local) — ws_vouchers boutique + marque. ──
    if ($m === 'GET' && $p === '/franchisee/ws-vouchers-local') {
      if (!$tblExists('ws_vouchers')) json_vide(['ws_vouchers']);
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
      if (!$tblExists('ws_pricing_rules')) json_vide(['ws_pricing_rules']);
      $sw = $shopId ? "(shop_id = " . (int) $shopId . " OR shop_id IS NULL)" : '1=1';
      $rs = rows("SELECT id, rule_type, label, x, y, threshold, shop_id FROM ws_pricing_rules WHERE $sw AND active=1 ORDER BY id LIMIT 200");
      json_out(array_map(function ($r) {
        $effet = $r['rule_type'] === 'cross_portion' ? ((int) $r['x'] . ' achetés → ' . (int) $r['y'] . ' offert(s)') : (string) ($r['threshold'] ?? '—');
        return ['id' => (int) $r['id'], 'nom' => $r['label'] ?: $r['rule_type'], 'cible' => $r['rule_type'], 'effet' => $effet,
                'loc' => $r['shop_id'] !== null,
                // Brut, pour l'édition. `loc` dit surtout si la règle est
                // MODIFIABLE ICI : une règle réseau (shop_id NULL) appartient à
                // la marque, et l'écran doit le montrer plutôt que de proposer
                // une modification que le serveur refusera.
                'vType' => (string) $r['rule_type'], 'vX' => (int) $r['x'], 'vY' => (int) $r['y'],
                'vSeuil' => $r['threshold'] === null ? '' : (float) $r['threshold']];
      }, $rs));
    }

    /* Écriture d'UNE règle de prix locale. Le formulaire écrivait dans
       l'overlay ws_pricing_rules_local — un nom qui n'existe dans aucune base —
       pendant que le webshop applique ws_pricing_rules. Ses trois champs
       (libellé, cible, effet) étaient en outre du texte libre sans rapport
       avec les colonnes réelles : rule_type, x, y, threshold.

       UNE RÈGLE RÉSEAU NE S'ÉDITE PAS ICI. shop_id NULL = règle de la marque ;
       la modifier depuis une boutique la changerait pour tout le réseau. */
    if ($m === 'POST' && $p === '/franchisee/pricing-rule') {
      if (!$tblExists('ws_pricing_rules'))
        json_out(['ok' => false, 'error' => 'Table ws_pricing_rules absente.'], 501);
      if (!$shopId)
        json_out(['ok' => false, 'error' => 'Portée boutique requise pour une règle locale.'], 400);
      $b   = body();
      $sc  = (int) $shopId;
      $rid = (int) ($b['id'] ?? 0);
      $mien = fn ($id) => row("SELECT id FROM ws_pricing_rules WHERE id=? AND shop_id=?", [$id, $sc]);
      if ($rid && !$mien($rid))
        json_out(['ok' => false, 'error' => 'Règle inconnue, ou règle réseau — elle se modifie dans la console marque.'], 403);

      if (!empty($b['delete'])) {
        if (!$rid) json_out(['ok' => false, 'error' => 'Règle non précisée.'], 400);
        q("UPDATE ws_pricing_rules SET active=0 WHERE id=? AND shop_id=?", [$rid, $sc]);
        json_out(['ok' => true, 'deleted' => true]);
      }

      $type = (string) ($b['type'] ?? '');
      if (!in_array($type, ['cross_portion', 'threshold'], true))
        json_out(['ok' => false, 'error' => 'Type attendu : cross_portion (X achetés → Y offerts) ou threshold (seuil).'], 400);
      $lbl = trim((string) ($b['nom'] ?? ''));
      if ($lbl === '') json_out(['ok' => false, 'error' => 'Donnez un libellé à la règle.'], 400);

      $x = 0; $y = 0; $seuil = null;
      if ($type === 'cross_portion') {
        $x = (int) ($b['x'] ?? 0); $y = (int) ($b['y'] ?? 0);
        if ($x < 1) json_out(['ok' => false, 'error' => 'Le nombre de pièces achetées doit valoir au moins 1.'], 400);
        if ($y < 1) json_out(['ok' => false, 'error' => 'Le nombre de pièces offertes doit valoir au moins 1.'], 400);
        if ($y >= $x) json_out(['ok' => false, 'error' => "Offrir $y pièce(s) pour $x achetée(s) revient à tout offrir."], 400);
      } else {
        $s2 = $b['seuil'] ?? '';
        if (!is_numeric($s2)) json_out(['ok' => false, 'error' => 'Seuil : un nombre est attendu.'], 400);
        $seuil = (float) $s2;
        if ($seuil <= 0) json_out(['ok' => false, 'error' => 'Le seuil doit être supérieur à zéro.'], 400);
      }

      if ($rid) {
        q("UPDATE ws_pricing_rules SET rule_type=?, label=?, x=?, y=?, threshold=?, active=1 WHERE id=? AND shop_id=?",
          [$type, $lbl, $x, $y, $seuil, $rid, $sc]);
      } else {
        q("INSERT INTO ws_pricing_rules (shop_id, rule_type, label, x, y, threshold, active) VALUES (?,?,?,?,?,?,1)",
          [$sc, $type, $lbl, $x, $y, $seuil]);
        $rid = (int) db()->lastInsertId();
      }
      json_out(['ok' => true, 'id' => $rid]);
    }

    // ── Jours exceptionnels (ws_shop_exceptions) — table réelle. ──
    if ($m === 'GET' && $p === '/franchisee/ws-shop-exceptions') {
      if (!$tblExists('ws_shop_exceptions')) json_vide(['ws_shop_exceptions']);
      $rs = rows("SELECT id, DATE_FORMAT(exception_date,'%d/%m/%Y') AS date,
                         DATE_FORMAT(exception_date,'%Y-%m-%d') AS iso, type, COALESCE(reason,'—') AS reason
                    FROM ws_shop_exceptions WHERE " . $scope('shop_id') . " ORDER BY exception_date LIMIT 100");
      // `label` et `detail` sortaient tous deux de `reason` : l'écran montrait
      // deux fois la même colonne, et le formulaire proposait deux champs pour
      // elle. Un seul motif, plus la date en ISO pour l'édition.
      json_out(array_map(fn ($r) => ['id' => (int) $r['id'], 'date' => $r['date'],
        'label' => $r['reason'], 'type' => $r['type'] === 'closed' ? 'Fermé' : 'Horaire spécial',
        'detail' => $r['reason'], 'vIso' => $r['iso'], 'vMotif' => $r['reason'] === '—' ? '' : $r['reason']], $rs));
    }

    /* Écriture d'UN jour exceptionnel — ou d'une PLAGE, qui devient une ligne
       par jour (la table porte une date, pas un intervalle).
       Le formulaire écrivait dans l'overlay : une fermeture saisie s'affichait
       dans la console et la boutique restait ouverte au checkout, qui lit
       ws_shop_exceptions. Les dates s'y saisissaient en texte « JJ/MM/AAAA » ;
       elles arrivent maintenant en ISO, imposées par un sélecteur de date. */
    if ($m === 'POST' && $p === '/franchisee/shop-exception') {
      if (!$tblExists('ws_shop_exceptions'))
        json_out(['ok' => false, 'error' => 'Table ws_shop_exceptions absente.'], 501);
      $b  = body();
      $sc = $shopId ? (int) $shopId : null;
      $mien = function ($id) use ($sc) {
        return $sc ? row("SELECT id FROM ws_shop_exceptions WHERE id=? AND (shop_id=? OR shop_id IS NULL)", [$id, $sc])
                   : row("SELECT id FROM ws_shop_exceptions WHERE id=?", [$id]);
      };
      if (!empty($b['delete'])) {
        $rid = (int) ($b['id'] ?? 0);
        if (!$rid || !$mien($rid)) json_out(['ok' => false, 'error' => 'Date inconnue, ou hors de votre boutique.'], 404);
        q("DELETE FROM ws_shop_exceptions WHERE id=?", [$rid]);
        json_out(['ok' => true, 'deleted' => true]);
      }
      $iso = function ($v, $lbl) {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v))
          json_out(['ok' => false, 'error' => "$lbl : date attendue au format AAAA-MM-JJ, reçue « $v »."], 400);
        return $v;
      };
      $d1 = $iso($b['date'] ?? '', 'Date de début');
      if (!$d1) json_out(['ok' => false, 'error' => 'Précisez la date.'], 400);
      $d2 = $iso($b['dateFin'] ?? '', 'Date de fin') ?: $d1;
      if (strtotime($d2) < strtotime($d1))
        json_out(['ok' => false, 'error' => 'La date de fin précède la date de début.'], 400);
      // Une plage se compte en jours : 366 est déjà une année entière fermée.
      $nb = (int) floor((strtotime($d2) - strtotime($d1)) / 86400) + 1;
      if ($nb > 366) json_out(['ok' => false, 'error' => "Plage de $nb jours — 366 au maximum."], 400);

      $type  = ((string) ($b['type'] ?? 'Fermé')) === 'Horaire spécial' ? 'special' : 'closed';
      $motif = trim((string) ($b['motif'] ?? ''));
      if ($motif === '') json_out(['ok' => false, 'error' => 'Donnez un motif — il explique la fermeture à qui relit le calendrier.'], 400);

      $rid = (int) ($b['id'] ?? 0);
      if ($rid) {
        if (!$mien($rid)) json_out(['ok' => false, 'error' => 'Date inconnue, ou hors de votre boutique.'], 404);
        q("UPDATE ws_shop_exceptions SET exception_date=?, type=?, reason=? WHERE id=?", [$d1, $type, $motif, $rid]);
        json_out(['ok' => true, 'id' => $rid, 'n' => 1]);
      }
      // Une plage : une ligne par jour, et une date déjà posée est MISE À JOUR
      // plutôt que doublée — deux exceptions le même jour se contrediraient.
      $n = 0;
      for ($t = strtotime($d1); $t <= strtotime($d2); $t += 86400) {
        $j = date('Y-m-d', $t);
        $dej = $sc ? row("SELECT id FROM ws_shop_exceptions WHERE exception_date=? AND shop_id=?", [$j, $sc])
                   : row("SELECT id FROM ws_shop_exceptions WHERE exception_date=? AND shop_id IS NULL", [$j]);
        if ($dej) q("UPDATE ws_shop_exceptions SET type=?, reason=? WHERE id=?", [$type, $motif, (int) $dej['id']]);
        else      q("INSERT INTO ws_shop_exceptions (shop_id, exception_date, type, reason) VALUES (?,?,?,?)", [$sc, $j, $type, $motif]);
        $n++;
      }
      json_out(['ok' => true, 'n' => $n]);
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

    /* ── Config livraison par bureau ────────────────────────────────────────
       DEUX SOURCES, ET ON DIT LAQUELLE PARLE. Sans ligne dans
       ws_office_delivery_settings, un bureau suit sa TOURNÉE — c'est déjà ce
       que fait le checkout. Avec une ligne, elle DÉROGE.

       Avant, cet écran ne lisait que la tournée et recollait « J-1 » derrière
       l'heure, en dur. La dérogation existait en base et n'était jamais
       montrée ; le cut-off saisi dans la console, lui, partait dans l'overlay
       ws_bo_store et n'atteignait personne. La console affichait donc une
       valeur que le webshop n'appliquait pas.

       `herite` dit à l'écran laquelle des deux il regarde : sans ça, une
       dérogation et un héritage se ressemblent, et on ne sait pas si modifier
       ce champ touchera un bureau ou toute la tournée. */
    if ($m === 'GET' && $p === '/franchisee/ws-office-delivery-settings') {
      if (!$tblExists('ws_offices') || !$tblExists('ws_tours')) json_vide(['ws_offices', 'ws_tours']);
      $hasAv  = $tblExists('ws_tour_availability');
      $hasSet = $tblExists('ws_office_delivery_settings');
      $hasOff = $hasSet && col_exists('ws_office_delivery_settings', 'cutoff_offset');
      $hasAss = col_exists('ws_offices', 'assortment_mode');
      $rs = rows("SELECT f.id, f.name, f.deferred_billing_enabled, f.drop_minutes, f.tour_id, t.name AS tour"
                . ($hasAss ? ", f.assortment_mode, f.show_prices" : "") . "
                    FROM ws_offices f JOIN ws_tours t ON t.id = f.tour_id
                   WHERE " . $scope('t.shop_id') . " AND f.active=1 ORDER BY f.name LIMIT 200");
      // Assortiment (0113) : produits cochés par bureau, et taille du catalogue
      // bureau de la boutique — comptés une fois, pas par carte.
      $assN = [];
      if ($hasAss && $tblExists('ws_office_products'))
        foreach (rows("SELECT office_id, COUNT(*) n FROM ws_office_products GROUP BY office_id") as $an) $assN[(int) $an['office_id']] = (int) $an['n'];
      $catN = null;
      if ($hasAss && $shopId) { try { $catN = count(catalog_produits_servis((int) $shopId, 'office')); } catch (Throwable $e) { $catN = null; } }
      json_out(array_map(function ($f) use ($hasAv, $hasSet, $hasOff, $hasAss, $assN, $catN) {
        $daysArr = []; $heure = null; $decal = 1;
        if ($hasAv) {
          $av = rows("SELECT DISTINCT delivery_day, TIME_FORMAT(MIN(cutoff_time),'%H:%i') AS cut
                        FROM ws_tour_availability WHERE tour_id=? AND active=1 GROUP BY delivery_day", [(int) $f['tour_id']]);
          foreach ($av as $a) { $daysArr[] = (int) $a['delivery_day']; $heure = $a['cut']; }
        }
        // La dérogation du bureau l'emporte, quand elle existe.
        $herite = true;
        if ($hasSet) {
          $d = row("SELECT TIME_FORMAT(delivery_cutoff,'%H:%i') AS cut"
                 . ($hasOff ? ", cutoff_offset" : "")
                 . " FROM ws_office_delivery_settings WHERE office_id=? AND active=1 LIMIT 1", [(int) $f['id']]);
          if ($d && $d['cut'] !== null) {
            $heure = $d['cut']; $herite = false;
            if ($hasOff && $d['cutoff_offset'] !== null) $decal = (int) $d['cutoff_offset'];
          }
        }
        // Pas d'heure connue ⇒ on n'en invente pas : le champ reste vide et
        // l'écran affiche « — », comme partout ailleurs dans cette console.
        return ['officeId' => (int) $f['id'], 'bureau' => $f['name'], 'tour' => $f['tour'],
                'contrat' => $f['deferred_billing_enabled'] ? 'Facturation différée' : 'Comptant',
                'daysArr' => $daysArr,
                'cutHeure' => $heure, 'cutDecal' => $decal, 'cutHerite' => $herite,
                'cut' => $heure === null ? '—' : ($heure . ($decal > 0 ? ' J-' . $decal : ' le jour même')),
                'drop' => (float) $f['drop_minutes'],
                'assortmentMode' => $hasAss ? (string) $f['assortment_mode'] : 'full',
                'assortmentCount' => $hasAss ? ($assN[(int) $f['id']] ?? 0) : null,
                'catalogCount' => $catN,
                'showPrices' => $hasAss ? (bool) $f['show_prices'] : true,
                'deferredBilling' => (bool) $f['deferred_billing_enabled']];
      }, $rs));
    }

    /* ASSORTIMENT D'UN BUREAU (0113) — lecture : le bureau, puis le catalogue
       BUREAU de la boutique (même fonction que le webshop, mode 'office')
       groupé par catégorie avec l'état coché. Le franchisé ne voit que les
       bureaux de sa boutique : 404 sinon, sans dire s'il existe ailleurs. */
    $officeAssortLire = function ($oid) use ($shopId) {
      if (!col_exists('ws_offices', 'assortment_mode')) json_out(['error' => 'Migration 0113 non appliquée.'], 501);
      $oSc = ($shopId && col_exists('ws_offices', 'shop_id')) ? " AND (shop_id IS NULL OR shop_id=" . (int) $shopId . ")" : "";
      $o = row("SELECT id, name, assortment_mode, show_prices, deferred_billing_enabled, shop_id
                  FROM ws_offices WHERE id=? AND active=1$oSc", [(int) $oid]);
      if (!$o) json_out(['error' => 'Bureau inconnu, ou hors de votre boutique.'], 404);
      return $o;
    };
    if ($m === 'GET' && $p === '/franchisee/office-assortment') {
      $oid = (int) qp('officeId'); if (!$oid) json_out(['error' => 'officeId requis'], 400);
      $o = $officeAssortLire($oid);
      $shopCat = $o['shop_id'] ?: $shopId;
      if (!$shopCat) json_out(['error' => 'Bureau sans boutique : impossible de lister le catalogue.'], 409);
      $liste = catalog_produits_servis((int) $shopCat, 'office');
      $ids = [];
      if (tbl_exists('ws_office_products'))
        foreach (rows("SELECT product_id FROM ws_office_products WHERE office_id=?", [$oid]) as $r) $ids[(int) $r['product_id']] = true;
      // Sous-catégories : libellé par identifiant, pour trier et regrouper
      // dans l'éditeur (demande du 03/09). Un produit sans sous-catégorie
      // tombe dans « Autres », en fin de catégorie.
      $subNom = [];
      if (tbl_exists('ws_category_subs'))
        foreach (rows("SELECT id, label FROM ws_category_subs") as $sr) $subNom[(int) $sr['id']] = (string) $sr['label'];
      usort($liste, static function ($a, $b) use ($subNom) {
        $ca = (int) ($a['cat_id'] ?? $a['cat'] ?? 0); $cb = (int) ($b['cat_id'] ?? $b['cat'] ?? 0);
        if ($ca !== $cb) return $ca <=> $cb;
        $sa = $subNom[(int) ($a['sub_cat_id'] ?? 0)] ?? "\u{FFFF}"; $sb = $subNom[(int) ($b['sub_cat_id'] ?? 0)] ?? "\u{FFFF}";
        $c = strcasecmp($sa, $sb); if ($c !== 0) return $c;
        return strcasecmp((string) $a['name'], (string) $b['name']);
      });
      $cats = []; $checked = 0;
      foreach ($liste as $x) {
        $cid = (int) ($x['cat_id'] ?? $x['cat'] ?? 0);
        $cnm = (string) ($x['category'] ?? $x['catName'] ?? $x['cat_name'] ?? '');
        if ($cnm === '') $cnm = $cid ? ('Catégorie ' . $cid) : 'Sans catégorie';
        if (!isset($cats[$cid])) $cats[$cid] = ['id' => $cid, 'name' => $cnm, 'products' => []];
        $on = isset($ids[(int) $x['id']]); if ($on) $checked++;
        $ch = [];
        if (!empty($x['office_delivery'])) $ch[] = 'office_delivery';
        if (!empty($x['click_and_collect'])) $ch[] = 'click_and_collect';
        $scid = (int) ($x['sub_cat_id'] ?? 0);
        $cats[$cid]['products'][] = ['id' => (int) $x['id'], 'name' => (string) $x['name'], 'price' => (float) $x['price'],
                                     'img' => $x['img'] ?? null, 'channels' => $ch, 'checked' => $on,
                                     'subCatId' => $scid ?: null, 'subCatName' => $subNom[$scid] ?? null];
      }
      usort($cats, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
      json_out(['office' => ['id' => (int) $o['id'], 'name' => (string) $o['name'], 'mode' => (string) $o['assortment_mode'],
                             'showPrices' => (bool) $o['show_prices'], 'deferredBilling' => (bool) $o['deferred_billing_enabled']],
                'counts' => ['checked' => $checked, 'total' => count($liste)],
                'categories' => array_values($cats)]);
    }
    /* Écriture : mode + prix + la liste entière des produits cochés, en une
       transaction — le client ne voit jamais un état intermédiaire. */
    if ($m === 'POST' && $p === '/franchisee/office-assortment') {
      $b = body();
      $oid = (int) ($b['officeId'] ?? 0); if (!$oid) json_out(['ok' => false, 'error' => 'Bureau non précisé.'], 400);
      $o = $officeAssortLire($oid);
      if (!tbl_exists('ws_office_products')) json_out(['ok' => false, 'error' => 'Table ws_office_products absente — migration 0113 non appliquée.'], 501);
      $mode = ($b['mode'] ?? 'full') === 'custom' ? 'custom' : 'full';
      $show = array_key_exists('showPrices', $b) ? (bool) $b['showPrices'] : (bool) $o['show_prices'];
      if (!$show && !(bool) $o['deferred_billing_enabled'])
        json_out(['ok' => false, 'error' => 'Prix masqués impossibles sans facturation différée.'], 400);
      $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($b['productIds'] ?? [])), static fn ($i) => $i > 0)));
      if ($mode === 'custom' && !$ids)
        json_out(['ok' => false, 'error' => 'Un assortiment réduit doit proposer au moins un produit.'], 400);
      // Seuls des produits de la boutique : un identifiant étranger est ignoré, pas enregistré.
      if ($ids) {
        $ok = rows("SELECT id FROM ws_products WHERE active=1 AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", $ids);
        $ids = array_map(static fn ($r) => (int) $r['id'], $ok);
      }
      // La connexion vit dans db() (statique de lib.php), pas dans une globale :
      // « global $pdo » rendait null et l'écriture partait en 500 — constaté
      // par la sonde d'endpoints le 03/09, à la première écriture réelle.
      $pdo = db();
      $pdo->beginTransaction();
      try {
        q("UPDATE ws_offices SET assortment_mode=?, show_prices=? WHERE id=?", [$mode, $show ? 1 : 0, $oid]);
        q("DELETE FROM ws_office_products WHERE office_id=?", [$oid]);
        foreach ($ids as $pid) q("INSERT INTO ws_office_products (office_id, product_id) VALUES (?,?)", [$oid, $pid]);
        $pdo->commit();
      } catch (Throwable $e) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Enregistrement refusé : ' . $e->getMessage()], 500); }
      json_out(['ok' => true, 'mode' => $mode, 'count' => count($ids), 'showPrices' => $show]);
    }
    /* Aperçu : le catalogue tel qu'un collaborateur de ce bureau le verra. */
    if ($m === 'GET' && $p === '/franchisee/office-preview') {
      $oid = (int) qp('officeId'); if (!$oid) json_out(['error' => 'officeId requis'], 400);
      $o = $officeAssortLire($oid);
      $shopCat = $o['shop_id'] ?: $shopId;
      if (!$shopCat) json_out(['error' => 'Bureau sans boutique.'], 409);
      $off = office_assortiment($oid);
      json_out(['office' => office_contexte($off), 'showPrices' => (bool) $o['show_prices'],
                'products' => office_filtrer(catalog_produits_servis((int) $shopCat, 'office'), $off)]);
    }

    /* Écriture d'UNE ligne de config bureau — jamais le remplacement de la
       table. /franchisee/save remplace une table entière, ce qui n'a aucun sens
       pour une dérogation rattachée à un bureau, et a déjà vidé une table ERP.

       L'heure arrive en HH:MM et le décalage en NOMBRE de jours : le format
       « 17:00 J-1 » d'avant n'était analysable par personne, et se saisissait
       à la main dans un champ texte. */
    if ($m === 'POST' && $p === '/franchisee/office-delivery-setting') {
      if (!$tblExists('ws_office_delivery_settings'))
        json_out(['ok' => false, 'error' => 'Table ws_office_delivery_settings absente — migration 0066 non appliquée.'], 501);
      if (!col_exists('ws_office_delivery_settings', 'cutoff_offset'))
        json_out(['ok' => false, 'error' => 'Colonne « cutoff_offset » absente — migration 0066 non appliquée.'], 501);
      $b   = body();
      $oid = (int) ($b['officeId'] ?? 0);
      if (!$oid) json_out(['ok' => false, 'error' => 'Bureau non précisé.'], 400);
      // PORTÉE SERVEUR : le bureau doit appartenir à la boutique de la session.
      // Se fier à l'id reçu laisserait un franchisé régler le cut-off d'un
      // bureau qui n'est pas le sien.
      $oSc = ($shopId && col_exists('ws_offices', 'shop_id')) ? " AND (shop_id IS NULL OR shop_id=" . (int) $shopId . ")" : "";
      if (!row("SELECT 1 x FROM ws_offices WHERE id=?$oSc", [$oid]))
        json_out(['ok' => false, 'error' => 'Bureau inconnu, ou hors de votre boutique.'], 404);

      $heure = trim((string) ($b['cutHeure'] ?? ''));
      $decal = (int) ($b['cutDecal'] ?? 1);
      if ($decal < 0 || $decal > 7) json_out(['ok' => false, 'error' => 'Décalage hors de 0 à 7 jours.'], 400);
      // Heure vide = RETOUR À LA TOURNÉE. C'est un geste utile — « ce bureau
      // n'a finalement pas d'horaire à lui » — et il doit être possible sans
      // supprimer la ligne à la main en base.
      if ($heure !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $heure))
        json_out(['ok' => false, 'error' => 'Heure attendue au format HH:MM.'], 400);

      $sc  = $shopId ? (int) $shopId : null;
      $dej = row("SELECT id FROM ws_office_delivery_settings WHERE office_id=? LIMIT 1", [$oid]);
      if ($dej) {
        q("UPDATE ws_office_delivery_settings SET delivery_cutoff=?, cutoff_offset=?, shop_id=COALESCE(shop_id,?), active=1 WHERE id=?",
          [$heure === '' ? null : $heure, $decal, $sc, (int) $dej['id']]);
      } else {
        q("INSERT INTO ws_office_delivery_settings (office_id, shop_id, delivery_cutoff, cutoff_offset, active) VALUES (?,?,?,?,1)",
          [$oid, $sc, $heure === '' ? null : $heure, $decal]);
      }
      // Le temps de dépôt, lui, vit sur la fiche du bureau : c'est une
      // propriété du LIEU (quai, étage, badge), pas de son horaire.
      if (array_key_exists('drop', $b) && col_exists('ws_offices', 'drop_minutes')) {
        $dp = $b['drop'];
        q("UPDATE ws_offices SET drop_minutes=? WHERE id=?",
          [($dp === '' || $dp === null) ? null : (float) $dp, $oid]);
      }
      json_out(['ok' => true, 'herite' => $heure === '']);
    }

    // ── Paramètres (ws_param clé/valeur) — '0'/'1' exposés en bool (toggles UI). ──
    if ($m === 'GET' && $p === '/franchisee/params') {
      if (!$tblExists('ws_param')) json_vide(['ws_param']);
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
      if (!$tblExists('ws_delivery_fee_rules')) json_vide(['ws_delivery_fee_rules']);
      $sw = $shopId ? "(r.shop_id = " . (int) $shopId . " OR r.shop_id IS NULL)" : '1=1';
      /* L'id ET les valeurs BRUTES accompagnent le texte affiché. Sans elles,
         l'écran ne pouvait éditer que « 4,50 € », « Offert » et « — » : des
         chaînes que personne ne sait relire. C'est ce qui avait fait du
         formulaire de frais un formulaire de TEXTE LIBRE sur des montants,
         écrit dans l'overlay au lieu de la table. */
      $rs = rows("SELECT r.id, r.level, r.free_delivery, r.always_charge, r.fee_amount,
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
        return ['id' => (int) $r['id'],
                'niveau' => $lvl[$r['level']] ?? $r['level'], 'cible' => $cible,
                'franco' => ((float) $r['free_delivery_minimum']) > 0
                            ? number_format((float) $r['free_delivery_minimum'], 0, ',', ' ') . ' €' : '—',
                'montant' => $montant,
                'paiement' => $pay[$r['payment_type']] ?? ($r['payment_type'] ?: '—'),
                // Brut, pour le formulaire d'édition.
                'vOffert' => (int) $r['free_delivery'] === 1,
                'vToujours' => (int) $r['always_charge'] === 1,
                'vMontant' => (float) $r['fee_amount'],
                'vFranco' => (float) $r['free_delivery_minimum'],
                'vPaiement' => (string) ($r['payment_type'] ?? '')];
      }, $rs));
    }

    /* Écriture d'UNE règle de frais — l'argent de la livraison.
       Le formulaire écrivait dans l'overlay ws_bo_store : la console affichait
       le nouveau franco pendant que le checkout facturait l'ancien, lu dans
       cette table-ci (/delivery-fees/quote). Personne ne pouvait s'en
       apercevoir sans comparer un écran et une facture.

       La CIBLE est résolue par le serveur, par son nom et DANS LA BOUTIQUE de
       la session : accepter un id reçu laisserait un franchisé fixer les frais
       d'une tournée qui n'est pas la sienne. */
    if ($m === 'POST' && $p === '/franchisee/delivery-fee-rule') {
      if (!$tblExists('ws_delivery_fee_rules'))
        json_out(['ok' => false, 'error' => 'Table ws_delivery_fee_rules absente.'], 501);
      $b  = body();
      $sc = $shopId ? (int) $shopId : null;

      // Suppression = désactivation, et seulement d'une règle de SA boutique.
      if (!empty($b['delete'])) {
        $rid = (int) ($b['id'] ?? 0);
        if (!$rid) json_out(['ok' => false, 'error' => 'Règle non précisée.'], 400);
        $own = $sc ? row("SELECT id FROM ws_delivery_fee_rules WHERE id=? AND (shop_id=? OR shop_id IS NULL)", [$rid, $sc])
                   : row("SELECT id FROM ws_delivery_fee_rules WHERE id=?", [$rid]);
        if (!$own) json_out(['ok' => false, 'error' => 'Règle inconnue, ou hors de votre boutique.'], 404);
        q("UPDATE ws_delivery_fee_rules SET active=0 WHERE id=?", [$rid]);
        json_out(['ok' => true, 'deleted' => true]);
      }

      $mapLvl = ['Site' => 'site', 'Bureau' => 'office', 'Tournée' => 'tour', 'Boutique' => 'shop'];
      $level  = $mapLvl[(string) ($b['niveau'] ?? '')] ?? null;
      if (!$level) json_out(['ok' => false, 'error' => 'Niveau attendu : Site, Bureau, Tournée ou Boutique.'], 400);

      // Les montants sont des NOMBRES. « 6,50 € », « 150 € ou — » et autres
      // saisies libres sont refusées avec leur motif : un franco mal lu se
      // traduit en euros sur chaque facture.
      $nb = function ($v, $lbl) {
        if ($v === null || $v === '') return 0.0;
        if (!is_numeric($v)) json_out(['ok' => false, 'error' => "$lbl : un nombre est attendu (ex. 6.50), reçu « $v »."], 400);
        $f = (float) $v;
        if ($f < 0) json_out(['ok' => false, 'error' => "$lbl ne peut pas être négatif."], 400);
        return $f;
      };
      $montant = $nb($b['montant'] ?? 0, 'Montant des frais');
      $franco  = $nb($b['franco'] ?? 0, 'Franco de port');
      $offert  = !empty($b['offert']);
      $touj    = !empty($b['toujours']);
      if ($offert && $touj)
        json_out(['ok' => false, 'error' => 'Une règle ne peut pas être « offerte » ET « toujours facturée ».'], 400);

      $mapPay = ['Comptant' => 'immediate', 'Différé' => 'deferred', 'Facturé au bureau' => 'office'];
      $pt = $mapPay[(string) ($b['paiement'] ?? '')] ?? null;   // null = selon le bureau

      // Résolution de la cible, bornée à la boutique.
      $cible = trim((string) ($b['cible'] ?? ''));
      $siteId = null; $offId = null; $tourId = null;
      if ($level === 'site' || $level === 'office' || $level === 'tour') {
        if ($cible === '') json_out(['ok' => false, 'error' => 'Précisez la cible de la règle.'], 400);
        if ($level === 'tour') {
          $t = row("SELECT id FROM ws_tours WHERE name=?" . ($sc ? " AND shop_id=$sc" : ""), [$cible]);
          if (!$t) json_out(['ok' => false, 'error' => "Tournée « $cible » inconnue dans votre boutique."], 404);
          $tourId = (int) $t['id'];
        } elseif ($level === 'office') {
          $oSc = ($sc && col_exists('ws_offices', 'shop_id')) ? " AND (shop_id IS NULL OR shop_id=$sc)" : "";
          $o = row("SELECT id FROM ws_offices WHERE name=?$oSc", [$cible]);
          if (!$o) json_out(['ok' => false, 'error' => "Bureau « $cible » inconnu dans votre boutique."], 404);
          $offId = (int) $o['id'];
        } else {
          $s2 = row("SELECT id FROM ws_office_delivery_sites WHERE name=? AND active=1", [$cible]);
          if (!$s2) json_out(['ok' => false, 'error' => "Site « $cible » inconnu."], 404);
          $siteId = (int) $s2['id'];
        }
      }

      $rid = (int) ($b['id'] ?? 0);
      $args = [$level, $siteId, $offId, $tourId, $sc, $offert ? 1 : 0, $touj ? 1 : 0, $montant, $franco, $pt];
      if ($rid) {
        $own = $sc ? row("SELECT id FROM ws_delivery_fee_rules WHERE id=? AND (shop_id=? OR shop_id IS NULL)", [$rid, $sc])
                   : row("SELECT id FROM ws_delivery_fee_rules WHERE id=?", [$rid]);
        if (!$own) json_out(['ok' => false, 'error' => 'Règle inconnue, ou hors de votre boutique.'], 404);
        q("UPDATE ws_delivery_fee_rules SET level=?, site_id=?, office_client_id=?, tour_id=?, shop_id=?,
             free_delivery=?, always_charge=?, fee_amount=?, free_delivery_minimum=?, payment_type=?, active=1
           WHERE id=?", array_merge($args, [$rid]));
      } else {
        q("INSERT INTO ws_delivery_fee_rules
             (level, site_id, office_client_id, tour_id, shop_id, free_delivery, always_charge,
              fee_amount, free_delivery_minimum, payment_type, active)
           VALUES (?,?,?,?,?,?,?,?,?,?,1)", $args);
        $rid = (int) db()->lastInsertId();
      }
      json_out(['ok' => true, 'id' => $rid]);
    }

    // ── Zone de chalandise marque (ws_franchisor_catchment — migration 0012). ──
    //    shop_name / shop_city viennent de la table shops : les tuiles
    //    « Magasins » du BO affichent le vrai nom du franchisé.
    if ($m === 'GET' && $p === '/franchisee/ws-franchisor-catchment') {
      if (!$tblExists('ws_franchisor_catchment')) json_vide(['ws_franchisor_catchment']);
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
      if (!$tblExists('ws_products')) json_vide(['ws_products']);
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
          'rule' => !$r['ps_active'] ? 'Désactivé boutique' : 'Sans livraison',
          // `local` distingue ce que LA BOUTIQUE peut lever de ce que la marque
          // a décidé : une désactivation réseau ne se corrige pas d'ici.
          'local' => true, 'statut' => !$r['ps_active'] ? 'Désactivé en boutique' : 'Sans livraison'];
      }
      // Les lignes réseau sont marquées non modifiables plutôt qu'absentes :
      // savoir qu'un produit est coupé par la marque évite de le chercher.
      foreach ($out as &$o) if (!isset($o['local'])) { $o['local'] = false; $o['statut'] = 'Désactivé (réseau)'; }
      unset($o);
      json_out($out);
    }

    /* Disponibilité d'un produit dans cette boutique — ROUTE FERMÉE.
       Elle a eu deux vies, fausses toutes les deux. L'écran « Exception
       produit » écrivait d'abord dans l'overlay sous le nom
       ws_product_availability, une table qui n'existe nulle part : rien n'était
       enregistré. La route a ensuite écrit pour de bon dans ws_product_shops —
       mais en donnant à la boutique un choix qui n'est pas le sien. */
    if ($m === 'POST' && $p === '/franchisee/product-availability') {
      /* QUI DÉCIDE — RÈGLE MÉTIER, PAS UN DÉTAIL D'ÉCRAN.
         La MARQUE choisit l'assortiment : quels produits sont disponibles et
         vendus, dans chaque boutique. Le FRANCHISÉ ne saisit que les QUANTITÉS
         produites (ws_product_stock, routes /franchisee/stock-*).

         Cette route donnait au franchisé la main sur la disponibilité. Elle est
         fermée, et elle DIT pourquoi plutôt que de rendre 404 : retirer la
         bascule de l'écran sans fermer la route aurait laissé le levier ouvert
         à qui appelle l'API directement — un onglet resté ouvert sur l'ancienne
         version suffisait. */
      json_out(['ok' => false, 'error' =>
        'L’assortiment est décidé par la marque. Une boutique ne choisit pas ce qu’elle vend ; '
        . 'elle saisit les quantités produites (Stock du jour).'], 403);
    }

    /* ── Écrans TDB / prep / suivi / validations / stock / assortiment ──
       (ex-littéraux JSX dé-hardcodés — servis depuis les vraies tables). */

    // Tournées du jour (TDB) — ws_tours + commandes du jour + tracking.
    // Rattachement commande -> tournée : ws_orders.tour_id est une
    // DÉNORMALISATION jamais alimentée par le webshop (toujours NULL). Compter
    // dessus renvoyait zéro colis pour toutes les tournées, qui disparaissaient
    // alors du tableau de bord — « 0 prête · 0 en préparation » avec des
    // commandes bien rattachées à une tournée par leur site.
    // La tournée est donc dérivée du SITE quand la colonne est vide, ce que
    // l'arbre faisait déjà. Une seule source de vérité, aucune donnée à migrer.
    if ($m === 'GET' && $p === '/franchisee/fr-tdb-tournees') {
      if (!$tblExists('ws_tours') || !$hasOrders) json_vide(['ws_tours', 'ws_orders']);
      $hasTk = $tblExists('ws_tour_tracking');
      $hasAv = $tblExists('ws_tour_availability');
      $rs = rows("SELECT t.id, t.name" . ($hasTk ? ", tk.driver_name, tk.vehicle, tk.stops_done" : ", NULL AS driver_name, NULL AS vehicle, 0 AS stops_done") . ",
                         (SELECT COUNT(DISTINCT o.office_client_id) FROM ws_orders o
                            LEFT JOIN ws_office_delivery_sites stt ON stt.id = o.office_delivery_site_id
                           WHERE COALESCE(o.tour_id, stt.tournee_id) = t.id AND o.delivery_date=?) AS pts,
                         (SELECT COUNT(*) FROM ws_orders o
                            LEFT JOIN ws_office_delivery_sites stt ON stt.id = o.office_delivery_site_id
                           WHERE COALESCE(o.tour_id, stt.tournee_id) = t.id AND o.delivery_date=?) AS colis
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
    //
    // HORIZON du tableau de bord : le jour même ET le lendemain. La requête
    // n'avait AUCUNE borne supérieure (« >= aujourd'hui »), si bien qu'une
    // commande du 31 décembre apparaissait dans un écran intitulé « Tournées du
    // jour », et gonflait le compteur « Commandes à préparer ». Le franchisé
    // lisait « 3 à préparer » sans en avoir une seule pour aujourd'hui.
    //
    // Pourquoi J+1 et pas le jour seul : à 15 h on prépare déjà pour le
    // lendemain — le cut-off de saisie à 17 h est fait pour ça. Limiter au jour
    // même viderait le tableau au moment précis où la préparation a lieu.
    // Réglable par ?horizon=N (0 = jour même), plafonné à 7 jours : au-delà,
    // ce n'est plus un écran d'exploitation.
    $tdbHorizon = max(0, min(7, (int) qp('horizon', 1)));
    if ($m === 'GET' && $p === '/franchisee/fr-tdb-tree') {
      if (!$tblExists('ws_tours') || !$tblExists('ws_office_delivery_sites') || !$hasOrders) json_vide(['ws_tours', 'ws_office_delivery_sites', 'ws_orders']);
      $hasTk = $tblExists('ws_tour_tracking');
      $hasZ  = $tblExists('ws_delivery_zones');
      $tours = rows("SELECT t.id, t.name" . ($hasZ ? ", z.name AS zone" : ", NULL AS zone") .
                    ($hasTk ? ", tk.driver_name, tk.stops_done" : ", NULL AS driver_name, 0 AS stops_done") . "
                      FROM ws_tours t" . ($hasZ ? " LEFT JOIN ws_delivery_zones z ON z.id=t.zone_id" : "") .
                    ($hasTk ? " LEFT JOIN ws_tour_tracking tk ON tk.tour_id=t.id" : "") . "
                     WHERE " . $scope('t.shop_id') . " AND t.active=1 ORDER BY t.name", []);
      // La bonne colonne est office_delivery_site_id (delivery_site_id n'existe
      // pas → l'arbre était TOUJOURS vide). Sous chaque site : les COMMANDES
      // réelles (client · n° · pièces), aujourd'hui ET jours à venir ; repli
      // par bureau quand la commande n'a pas d'id de site.
      $hasCliT = $tblExists('client');
      $out = [];
      foreach ($tours as $t) {
        $sites = rows(
          "SELECT s.id AS sid, s.office_client_id AS ocid, s.name AS libelle,
                  COALESCE(s.address,'—') AS ville, s.contact_name, f.name AS office
             FROM ws_office_delivery_sites s LEFT JOIN ws_offices f ON f.id = s.office_client_id
            WHERE s.tournee_id = ? AND s.active = 1 ORDER BY s.name", [(int) $t['id']]);
        $siteOut = [];
        foreach ($sites as $s2) {
          $ords = rows(
            "SELECT o.order_ref, DATE_FORMAT(COALESCE(o.delivery_date, DATE(o.created_at)),'%Y-%m-%d') AS jour,
                    COALESCE(NULLIF(o.guest_name,'')" .
                    ($hasCliT ? ", NULLIF(TRIM(CONCAT(COALESCE(cl.name,''),' ',COALESCE(cl.surname,''))),'')" : "") . ",
                             'Client webshop') AS client,
                    (SELECT COALESCE(SUM(l.qty),0) FROM ws_order_lines l WHERE l.order_id=o.id" . oline_own() . ") AS pieces
               FROM ws_orders o" .
            ($hasCliT ? " LEFT JOIN client cl ON cl.id = o.customer_id" : "") . "
              WHERE " . $scope('o.shop_id') . " AND o.status <> 'cancelled'
                AND COALESCE(o.delivery_date, DATE(o.created_at)) BETWEEN ? AND DATE_ADD(?, INTERVAL " . $tdbHorizon . " DAY)
                AND (o.office_delivery_site_id = ?
                     OR (o.office_delivery_site_id IS NULL AND ? IS NOT NULL AND o.office_client_id = ?))
              ORDER BY jour, o.created_at LIMIT 40",
            [$today, $today, (int) $s2['sid'], $s2['ocid'], $s2['ocid']]);
          if (!$ords) continue;
          $J2 = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
          $siteOut[] = ['libelle' => $s2['libelle'] ?: $s2['ville'], 'ville' => $s2['ville'], 'cutoff' => '—',
            'office' => $s2['office'] ?: '—',
            'commandes' => count($ords),
            'users' => array_map(fn ($o2) => [
              'nom' => $o2['client'] . ' · #' . $o2['order_ref']
                     . ($o2['jour'] > $today ? (' · ' . $J2[(int) date('w', strtotime($o2['jour']))] . ' ' . date('d/m', strtotime($o2['jour']))) : ''),
              'cmd' => (int) $o2['pieces']], $ords)];
        }
        if (!$siteOut) continue;
        $out[] = ['nom' => $t['name'], 'chauffeur' => $t['driver_name'] ?: '— non assigné',
                  'statut' => ((int) $t['stops_done']) > 0 ? 'Prête' : 'En préparation',
                  'zones' => [['nom' => $t['zone'] ?: $t['name'], 'sites' => $siteOut]]];
      }
      // FILET DE SÉCURITÉ : commandes en LIVRAISON qui ne se rattachent à aucune
      // chaîne site actif → tournée active (site sans tournee_id, site inactif,
      // tournée désactivée, ou commande sans id de site NI de bureau). Plutôt
      // qu'un tableau vide inexplicable, on les montre sous « Hors tournée »
      // avec de quoi diagnostiquer le maillon manquant.
      $orphans = rows(
        "SELECT o.order_ref, DATE_FORMAT(COALESCE(o.delivery_date, DATE(o.created_at)),'%Y-%m-%d') AS jour,
                o.office_delivery_site_id AS osid, o.office_client_id AS ooid, f3.name AS bureau,
                COALESCE(NULLIF(o.guest_name,'')" .
                ($hasCliT ? ", NULLIF(TRIM(CONCAT(COALESCE(cl.name,''),' ',COALESCE(cl.surname,''))),'')" : "") . ",
                         'Client webshop') AS client,
                COALESCE(NULLIF(o.office_delivery_site_name,''), f3.name, '— site et bureau non renseignés') AS libelle,
                (SELECT COALESCE(SUM(l.qty),0) FROM ws_order_lines l WHERE l.order_id=o.id" . oline_own() . ") AS pieces
           FROM ws_orders o" .
        ($hasCliT ? " LEFT JOIN client cl ON cl.id = o.customer_id" : "") . "
           LEFT JOIN ws_offices f3 ON f3.id = o.office_client_id
          WHERE " . $scope('o.shop_id') . " AND o.status <> 'cancelled'
            AND COALESCE(o.delivery_date, DATE(o.created_at)) >= ?
            AND (o.mode = 'delivery' OR o.delivery_mode = 'office_delivery')
            AND NOT EXISTS (
              SELECT 1 FROM ws_office_delivery_sites s3
                JOIN ws_tours t3 ON t3.id = s3.tournee_id AND t3.active = 1
               WHERE s3.active = 1
                 AND (s3.id = o.office_delivery_site_id
                      OR (o.office_delivery_site_id IS NULL AND s3.office_client_id = o.office_client_id)))
          ORDER BY jour, o.created_at LIMIT 40", [$today]);
      if ($orphans) {
        $J3 = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
        $bySite = [];
        foreach ($orphans as $o3) {
          // Diagnostic du maillon manquant, affiché tel quel dans l'arbre.
          if ($o3['osid']) {
            $diag = 'site #' . $o3['osid'] . ' : inactif, sans tournée, ou tournée inactive';
          } elseif ($o3['ooid']) {
            $diag = 'bureau #' . $o3['ooid'] . ' : aucun site actif rattaché à une tournée pour ce bureau';
          } else {
            $diag = 'commande sans id de site ni de bureau';
          }
          $off3 = $o3['bureau'] ? ($o3['bureau'] . ' (#' . $o3['ooid'] . ')')
                : ($o3['ooid'] ? ('bureau #' . $o3['ooid']) : '— bureau non renseigné');
          $k3 = $o3['libelle'] . '|' . $diag . '|' . $off3;
          $bySite[$k3]['libelle'] = $o3['libelle'];
          $bySite[$k3]['diag'] = $diag;
          $bySite[$k3]['off'] = $off3;
          $bySite[$k3]['users'][] = [
            'nom' => $o3['client'] . ' · #' . $o3['order_ref']
                   . ($o3['jour'] > $today ? (' · ' . $J3[(int) date('w', strtotime($o3['jour']))] . ' ' . date('d/m', strtotime($o3['jour']))) : ''),
            'cmd' => (int) $o3['pieces']];
        }
        $orphSites = [];
        foreach ($bySite as $g3) {
          $orphSites[] = ['libelle' => $g3['libelle'], 'ville' => $g3['diag'], 'cutoff' => '—', 'office' => $g3['off'],
                          'commandes' => count($g3['users']), 'users' => $g3['users']];
        }
        $out[] = ['nom' => '⚠ Hors tournée (à rattacher)', 'chauffeur' => '—', 'statut' => 'En préparation',
                  'zones' => [['nom' => 'Site inactif, sans tournée, ou commande sans site/bureau', 'sites' => $orphSites]]];
      }
      json_out($out);
    }

    // À PRODUIRE (écran Préparation) — lignes de commande agrégées par
    // catégorie × produit × créneau × tournée, aujourd'hui + 31 jours (le BO
    // filtre par jour via les badges). ws_order_lines × ws_orders réels.
    if ($m === 'GET' && $p === '/franchisee/fr-prep-lines') {
      if (!$hasOrders || !$tblExists('ws_order_lines')) json_vide(['ws_orders', 'ws_order_lines']);
      $hasT = $tblExists('ws_tours') && $tblExists('ws_office_delivery_sites');
      // DATE_FORMAT force le format YYYY-MM-DD quel que soit le type réel de
      // delivery_date (un DATETIME ferait rater le filtre par jour du BO) ;
      // try/catch : une erreur SQL est LOGGÉE au lieu d'un 500 avalé en
      // « Rien à produire » inexplicable.
      try {
        $rs = rows(
          "SELECT DATE_FORMAT(COALESCE(o.delivery_date, DATE(o.created_at)),'%Y-%m-%d') AS jour2,
                  COALESCE(c.label, 'Autres') AS cat,
                  COALESCE(NULLIF(l.product_name,''), pr.name, '—') AS produit,
                  SUM(l.qty) AS qty,
                  COALESCE(NULLIF(o.slot_label,''), '—') AS creneau," .
          ($hasT ? " COALESCE(t.name,
                  (SELECT t3.name FROM ws_office_delivery_sites s3
                     JOIN ws_tours t3 ON t3.id = s3.tournee_id
                    WHERE s3.office_client_id = o.office_client_id
                      AND s3.active = 1 LIMIT 1),
                  IF(o.mode='delivery','⚠ tournée à rattacher','Comptoir'))" : " IF(o.mode='delivery','—','Comptoir')") . " AS tournee
             FROM ws_order_lines l
             JOIN ws_orders o ON o.id = l.order_id
             LEFT JOIN ws_products pr ON pr.id = l.product_id
             LEFT JOIN ws_categories c ON c.id = pr.cat_id" .
          ($hasT ? "
             LEFT JOIN ws_office_delivery_sites s ON s.id = o.office_delivery_site_id
             LEFT JOIN ws_tours t ON t.id = s.tournee_id" : "") . "
            WHERE " . $scope('o.shop_id') . " AND o.status <> 'cancelled'
              AND COALESCE(o.delivery_date, DATE(o.created_at))
                  BETWEEN ? AND DATE_ADD(?, INTERVAL 31 DAY)
            GROUP BY jour2, cat, produit, creneau, tournee
            ORDER BY jour2, cat, produit, creneau LIMIT 500", [$today, $today]);
      } catch (Throwable $e) {
        error_log('[ws] fr-prep-lines KO : ' . $e->getMessage());
        json_out(['error' => 'fr-prep-lines', 'detail' => $e->getMessage()], 500);
      }
      json_out(array_map(fn ($r) => ['date' => $r['jour2'], 'cat' => $r['cat'], 'produit' => $r['produit'],
        'qty' => (int) $r['qty'], 'creneau' => $r['creneau'], 'tournee' => $r['tournee']], $rs));
    }

    // Chauffeurs connus (droplist de la modale d'envoi tablette) : noms
    // DISTINCTS déjà utilisés dans ws_tour_tracking (+ ws_tours si colonne).
    if ($m === 'GET' && $p === '/franchisee/drivers') {
      $names = [];
      try {
        if ($tblExists('ws_tour_tracking')) {
          foreach (rows("SELECT DISTINCT driver_name AS n FROM ws_tour_tracking
                          WHERE driver_name IS NOT NULL AND TRIM(driver_name) <> '' ORDER BY driver_name LIMIT 50") as $r) $names[$r['n']] = 1;
        }
        foreach (['driver_name', 'driver'] as $dc) {
          if ($tblExists('ws_tours') && col_exists('ws_tours', $dc)) {
            foreach (rows("SELECT DISTINCT $dc AS n FROM ws_tours
                            WHERE $dc IS NOT NULL AND TRIM($dc) <> '' AND $dc <> '—' AND " . $scope('shop_id') . " LIMIT 50") as $r) $names[$r['n']] = 1;
            break;
          }
        }
      } catch (Throwable $e) { /* table/colonne absente — liste partielle */ }
      $ns = array_keys($names); sort($ns, SORT_FLAG_CASE | SORT_STRING);
      json_out(array_map(fn ($n) => ['nom' => $n], $ns));
    }

    // Statut d'envoi tablette + validation chauffeur, par tournée.
    if ($m === 'GET' && $p === '/franchisee/tour-dispatch-status') {
      if (!$tblExists('ws_tour_tracking') || !$tblExists('ws_tours')) json_vide(['ws_tour_tracking', 'ws_tours']);
      $hasDis = col_exists('ws_tour_tracking', 'dispatched_at');
      $hasVal = col_exists('ws_tour_tracking', 'driver_validated_at');
      $rs = rows("SELECT t.name AS tour, tk.driver_name,
                         " . ($hasDis ? "DATE_FORMAT(tk.dispatched_at,'%d/%m %H:%i')" : "NULL") . " AS envoye,
                         " . ($hasVal ? "DATE_FORMAT(tk.driver_validated_at,'%d/%m %H:%i')" : "NULL") . " AS valide
                    FROM ws_tour_tracking tk JOIN ws_tours t ON t.id = tk.tour_id
                   WHERE " . $scope('t.shop_id') . " ORDER BY t.name LIMIT 50");
      json_out(array_map(fn ($r) => ['tour' => $r['tour'], 'chauffeur' => $r['driver_name'] ?: '—',
        'envoye' => $r['envoye'], 'valide' => $r['valide']], $rs));
    }

    // ENVOI d'une tournée vers la tablette : upsert ws_tour_tracking —
    // chauffeur + horodatage d'envoi ; chaque envoi REMET la validation
    // chauffeur à zéro (c'est la tablette qui posera driver_validated_at).
    if ($m === 'POST' && $p === '/franchisee/tour-dispatch') {
      if (!$tblExists('ws_tours')) json_out(['error' => 'ws_tours absente'], 500);
      if (!$tblExists('ws_tour_tracking')) json_out(['error' => 'ws_tour_tracking absente — migration 0035 non appliquée'], 500);
      $b2 = body(); $tn = trim((string) ($b2['tour'] ?? '')); $drv = trim((string) ($b2['driver'] ?? ''));
      if ($tn === '') json_out(['error' => 'tournée requise'], 400);
      $t2 = row("SELECT id FROM ws_tours WHERE name=? AND " . $scope('shop_id') . " LIMIT 1", [$tn]);
      if (!$t2) json_out(['error' => 'tournée introuvable', 'tour' => $tn], 404);
      $nStops = 0;
      if ($tblExists('ws_office_delivery_sites')) {
        $c2 = row("SELECT COUNT(DISTINCT LOWER(REGEXP_REPLACE(TRIM(COALESCE(address,'')),'[[:space:]]+',' '))) AS n
                     FROM ws_office_delivery_sites WHERE tournee_id=? AND active=1", [(int) $t2['id']]);
        $nStops = (int) ($c2['n'] ?? 0);
      }
      $hasDis = col_exists('ws_tour_tracking', 'dispatched_at');
      $hasVal = col_exists('ws_tour_tracking', 'driver_validated_at');
      $ex2 = row("SELECT id FROM ws_tour_tracking WHERE tour_id=? LIMIT 1", [(int) $t2['id']]);
      if ($ex2) {
        q("UPDATE ws_tour_tracking SET driver_name=?, stops_total=?" .
          ($hasDis ? ", dispatched_at=NOW()" : "") . ($hasVal ? ", driver_validated_at=NULL" : "") . "
            WHERE id=?", [$drv ?: null, $nStops, (int) $ex2['id']]);
      } else {
        q("INSERT INTO ws_tour_tracking (tour_id, driver_name, stops_done, stops_total" .
          ($hasDis ? ", dispatched_at" : "") . ") VALUES (?,?,0,?" . ($hasDis ? ",NOW()" : "") . ")",
          [(int) $t2['id'], $drv ?: null, $nStops]);
      }
      json_out(['ok' => true, 'tour' => $tn, 'chauffeur' => $drv ?: '—', 'stops' => $nStops]);
    }

    // Bon de chargement (prep) — colis du jour groupés par site.
    if ($m === 'GET' && $p === '/franchisee/fr-prep-points') {
      if (!$tblExists('ws_office_delivery_sites') || !$hasOrders) json_vide(['ws_office_delivery_sites', 'ws_orders']);
      // Bonne colonne (office_delivery_site_id) + repli par bureau : le bon de
      // chargement était vide car delivery_site_id n'existe pas.
      $rs = rows("SELECT COALESCE(NULLIF(TRIM(s.name),''), s.address, f2.name) AS libelle,
                         COALESCE(SUM((SELECT COALESCE(SUM(l.qty),0) FROM ws_order_lines l WHERE l.order_id=o.id" . oline_own() . ")), COUNT(*)) AS colis
                    FROM ws_orders o
                    LEFT JOIN ws_office_delivery_sites s ON s.id = o.office_delivery_site_id
                    LEFT JOIN ws_offices f2 ON f2.id = o.office_client_id
                   WHERE " . $scope('o.shop_id') . " AND o.status <> 'cancelled'
                     AND COALESCE(o.delivery_date, DATE(o.created_at)) = ?
                     AND (s.id IS NOT NULL OR f2.id IS NOT NULL)
                   GROUP BY libelle ORDER BY colis DESC LIMIT 30", [$today]);
      $i = 0;
      json_out(array_map(function ($r) use (&$i) {
        $i++;
        return ['ordre' => $i, 'libelle' => $r['libelle'], 'colisTxt' => ((int) $r['colis']) . ' colis'];
      }, $rs));
    }

    // Suivi live — table chauffeurs (télémétrie réelle ; ETA sans source → «—»).
    if ($m === 'GET' && $p === '/franchisee/fr-live-table') {
      if (!$tblExists('ws_tour_tracking') || !$tblExists('ws_tours')) json_vide(['ws_tour_tracking', 'ws_tours']);
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
      if (!$tblExists('ws_offices')) json_vide(['ws_offices']);
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
                'segment' => '—', 'tva' => $r['vat'] ?: '—',
                'vies' => $r['vat'] ? 'ok' : 'pending', 'date' => $r['d']];
      }, $rs));
    }

    /* ══ COMPTES TABLETTE DE LA BOUTIQUE ═════════════════════════════════════
       Gérés par le FRANCHISÉ : c'est lui qui connaît son personnel. Il choisit
       un PROFIL publié par la marque — les sections en découlent, il ne peut
       donc pas s'octroyer un accès non prévu.
       Réservé au jeton admin ERP : ces routes ne figurent PAS dans
       bo_endpoint_section, donc une session PIN ne peut jamais les atteindre —
       un vendeur ne se crée pas un compte « admin boutique ». ── */
    if ($m === 'GET' && $p === '/franchisee/bo-roles') {
      if (!$tblExists('bo_role')) json_vide(['bo_role']);
      json_out(array_map(static function ($r) {
        $sec = $r['sections'] ? (json_decode((string) $r['sections'], true) ?: []) : [];
        return ['id' => (int) $r['id'], 'label' => $r['label'], 'key' => $r['role_key'],
                'sections' => $sec, 'nbSections' => count($sec)];
      }, rows("SELECT id, role_key, label, sections FROM bo_role WHERE active = 1 ORDER BY label")));
    }

    if ($m === 'GET' && $p === '/franchisee/bo-users') {
      if (!$tblExists('bo_users')) json_vide(['bo_users']);
      if (!$shopId) json_out([]);   // sans portée boutique : liste vide, pas une panne
      $hasRole = col_exists('bo_users', 'role_id');
      $rs = rows("SELECT u.id, u.display_name AS nom, u.active,
                         (u.pin_hash IS NOT NULL AND u.pin_hash <> '') AS pin_pose, u.pin_set_at,
                         u.last_login_at, u.sections" .
                 ($hasRole ? ", u.role_id, r.label AS role_label, r.sections AS role_sections"
                           : ", NULL AS role_id, NULL AS role_label, NULL AS role_sections") . "
                    FROM bo_users u
                    JOIN bo_user_shops bus ON bus.user_id = u.id AND bus.shop_id = ?" .
                 ($hasRole ? " LEFT JOIN bo_role r ON r.id = u.role_id" : "") . "
                   ORDER BY u.display_name, u.id", [$shopId]);
      json_out(array_map(static function ($r) {
        $sec = bo_user_sections($r);
        return ['id' => (int) $r['id'], 'nom' => $r['nom'] ?: '(sans nom)',
                'active' => (bool) $r['active'],
                'roleId' => $r['role_id'] !== null ? (int) $r['role_id'] : null,
                'role' => $r['role_label'] ?: '— aucun profil',
                'nbSections' => count($sec),
                'pinPose' => (bool) $r['pin_pose'], 'pinDepuis' => $r['pin_set_at'],
                'derniereConnexion' => $r['last_login_at'] ?: '—'];
      }, $rs));
    }

    if ($m === 'POST' && $p === '/franchisee/bo-user') {
      if (!$tblExists('bo_users')) json_out(['ok' => false, 'error' => 'table bo_users absente'], 501);
      if (!col_exists('bo_users', 'pin_hash')) json_out(['ok' => false, 'error' => 'migration 0046 non appliquée'], 500);
      if (!col_exists('bo_users', 'role_id')) json_out(['ok' => false, 'error' => 'migration 0050 non appliquée'], 500);
      if (!$shopId) json_out(['ok' => false, 'error' => 'ouvrez le back-office avec ?shop=<id> — un compte appartient à une boutique'], 400);
      $b   = body();
      $id  = (int) ($b['id'] ?? 0);
      $nom = trim((string) ($b['nom'] ?? ''));
      if ($nom === '') json_out(['ok' => false, 'error' => 'nom requis'], 400);
      $roleId = (int) ($b['roleId'] ?? 0);
      if (!$roleId) json_out(['ok' => false, 'error' => 'profil requis — c’est lui qui détermine les accès'], 400);
      $role = row("SELECT id, label, sections FROM bo_role WHERE id = ? AND active = 1", [$roleId]);
      if (!$role) json_out(['ok' => false, 'error' => 'profil inconnu ou désactivé par la marque'], 409);
      $active = array_key_exists('active', $b) ? (!empty($b['active']) ? 1 : 0) : 1;
      // PIN : 4 chiffres. Absent à la modification = PIN inchangé ; obligatoire
      // à la création (un compte sans PIN ne peut ouvrir aucune session).
      $pin = preg_replace('/\D/', '', (string) ($b['pin'] ?? ''));
      if ($pin !== '' && strlen($pin) !== 4) json_out(['ok' => false, 'error' => 'le PIN doit comporter exactement 4 chiffres'], 400);
      if (!$id && $pin === '') json_out(['ok' => false, 'error' => 'PIN à 4 chiffres requis à la création'], 400);
      $pinHash = $pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : null;

      if ($id) {
        // Un franchisé ne modifie que SES comptes.
        if (!row("SELECT 1 x FROM bo_user_shops WHERE user_id = ? AND shop_id = ?", [$id, $shopId]))
          json_out(['ok' => false, 'error' => 'ce compte n’appartient pas à votre boutique'], 403);
        $sets = ['display_name=?', 'active=?', 'role_id=?'];
        $vals = [$nom, $active, $roleId];
        if ($pinHash !== null) { $sets[] = 'pin_hash=?'; $vals[] = $pinHash; $sets[] = 'pin_set_at=NOW()'; }
        $vals[] = $id;
        q("UPDATE bo_users SET " . implode(', ', $sets) . " WHERE id=?", $vals);
        // Compte désactivé ⇒ sessions ouvertes fermées immédiatement.
        if (!$active && $tblExists('bo_pin_session')) q("DELETE FROM bo_pin_session WHERE user_id=?", [$id]);
      } else {
        q("INSERT INTO bo_users (email, password_hash, display_name, role, active, role_id, pin_hash, pin_set_at)
             VALUES (NULL, '', ?, 'franchise', ?, ?, ?, NOW())", [$nom, $active, $roleId, $pinHash]);
        $id = (int) db()->lastInsertId();
        q("INSERT INTO bo_user_shops (user_id, shop_id) VALUES (?,?)", [$id, $shopId]);
      }
      json_out(['ok' => true, 'id' => $id, 'role' => $role['label'], 'pinPose' => $pinHash !== null]);
    }

    if ($m === 'POST' && $p === '/franchisee/bo-user-delete') {
      if (!$tblExists('bo_users')) json_out(['ok' => false, 'error' => 'table bo_users absente'], 501);
      $b = body(); $id = (int) ($b['id'] ?? 0);
      if (!$id || !$shopId) json_out(['ok' => false, 'error' => 'id et boutique requis'], 400);
      if (!row("SELECT 1 x FROM bo_user_shops WHERE user_id = ? AND shop_id = ?", [$id, $shopId]))
        json_out(['ok' => false, 'error' => 'ce compte n’appartient pas à votre boutique'], 403);
      if ($tblExists('bo_pin_session')) q("DELETE FROM bo_pin_session WHERE user_id=?", [$id]);
      q("DELETE FROM bo_user_shops WHERE user_id=?", [$id]);
      q("DELETE FROM bo_users WHERE id=?", [$id]);
      json_out(['ok' => true]);
    }

    // Demandes de rattachement bureau — ws_office_join_requests (pending).
    // On identifie le DEMANDEUR (client.id, nom, e-mail) : sans lui, la
    // décision « Lier » ne sait pas qui rattacher — c'était le trou du flow.
    /* ── RATTACHEMENTS de fiches : la liste que le franchisé arbitre ────────
       Un compte webshop demande à être relié à une fiche client de la
       boutique. Le webshop n'ayant aucune vérification d'identité, c'est le
       franchisé qui tranche — il connaît ses clients.

       On lui montre LES DEUX FICHES EN REGARD, et les concordances telles
       qu'elles étaient AU DÉPÔT de la demande (match_json) : l'index ERP a un
       TTL, la fiche peut avoir changé depuis, et il doit arbitrer sur ce qui a
       été comparé. Les concordances ne sont pas un feu vert — sur ce parc,
       seuls le téléphone (99 %) et le prénom (90 %) sont réellement remplis :
       le code postal ne l'est que sur les 8 % de fiches B2B. ── */
    if ($m === 'GET' && $p === '/franchisee/link-requests') {
      if (!$tblExists('ws_client_link_requests')) json_vide(['ws_client_link_requests']);
      $rs = rows("SELECT r.id, r.client_id, r.erp_client_id, r.match_json, r.created_at,
                         c.name AS c_prenom, c.surname AS c_nom, c.email AS c_mail,
                         COALESCE(c.phone_e164, c.phone) AS c_tel, c.zip AS c_cp
                    FROM ws_client_link_requests r
                    LEFT JOIN client c ON c.id = r.client_id
                   WHERE " . $scope('r.shop_id') . " AND r.status='pending'
                   ORDER BY r.created_at DESC LIMIT 50");
      // L'index ERP est chargé UNE fois pour toute la liste : une lecture par
      // demande relancerait 8156 fiches à chaque ligne.
      $idx = function_exists('erp_clients_index') ? erp_clients_index($shopId) : null;
      json_out(array_map(function ($r) use ($idx) {
        $f = (is_array($idx) && isset($idx[(int) $r['erp_client_id']])) ? $idx[(int) $r['erp_client_id']] : null;
        $cmp = json_decode((string) $r['match_json'], true);
        return [
          'id'      => (int) $r['id'],
          'depuis'  => (string) $r['created_at'],
          // Le compte webshop qui demande.
          'compte'  => [
            'id'     => (int) $r['client_id'],
            'nom'    => trim(((string) $r['c_prenom']) . ' ' . ((string) $r['c_nom'])),
            'email'  => (string) $r['c_mail'],
            'tel'    => (string) $r['c_tel'],
            'cp'     => (string) $r['c_cp'],
          ],
          /* La fiche visée. `null` quand l'index ERP est indisponible ou que la
             fiche a disparu : l'écran doit le DIRE, pas afficher une colonne
             vide qui ressemblerait à une fiche sans données. */
          'fiche'   => $f ? [
            'id'      => (int) $f['id'],
            'nom'     => trim(((string) $f['prenom']) . ' ' . ((string) $f['nom'])),
            'email'   => (string) $f['emailAff'],
            'tel'     => (string) $f['tel'],
            'cp'      => (string) $f['cp'],
            'ville'   => (string) $f['ville'],
            'societe' => (string) $f['societe'],
            'tva'     => (string) $f['tva'],
          ] : null,
          'champs'  => is_array($cmp) ? ($cmp['champs'] ?? []) : [],
        ];
      }, $rs));
    }

    /* Décision. « Rattacher » ÉCRIT client.erp_client_id — c'est cette écriture
       qui donne au compte son historique d'achats et sa fidélité. Elle échoue
       explicitement plutôt que de marquer la demande « linked » sans avoir
       rien rattaché. */
    if ($m === 'POST' && $p === '/franchisee/link-decide') {
      $b = body(); $id = (int) preg_replace('/\D/', '', (string) ($b['id'] ?? ''));
      $act = (string) ($b['action'] ?? '');
      if (!$id || !in_array($act, ['link', 'reject'], true))
        json_out(['ok' => false, 'error' => 'id + action requis'], 400);
      if (!$tblExists('ws_client_link_requests')) json_out(['ok' => false, 'error' => 'table absente (migration 0099)'], 501);
      // Portée : une demande d'une autre boutique n'est pas arbitrable ici.
      $sc = $shopId ? " AND shop_id=" . (int) $shopId : "";
      $r  = row("SELECT * FROM ws_client_link_requests WHERE id=? AND status='pending'$sc", [$id]);
      if (!$r) json_out(['ok' => false, 'error' => 'Demande introuvable, déjà traitée, ou hors de votre boutique.'], 404);
      $par = $pinSes ? (string) ($pinSes['display_name'] ?? '') : '';

      if ($act === 'reject') {
        $why = mb_substr(trim((string) ($b['reason'] ?? '')), 0, 200);
        q("UPDATE ws_client_link_requests SET status='rejected', decided_at=NOW(), decided_by=?, reject_reason=?
            WHERE id=?", [($par ?: null), ($why !== '' ? $why : null), $id]);
        json_out(['ok' => true, 'action' => 'reject']);
      }

      // ── Rattachement ──
      $cid = (int) $r['client_id']; $eid = (int) $r['erp_client_id'];
      if (!col_exists('client', 'erp_client_id'))
        json_out(['ok' => false, 'error' => 'Colonne client.erp_client_id absente — jouez la migration 0099.'], 501);
      // Le client doit être visible depuis cette boutique : même règle que
      // partout ailleurs, ce qui n'est pas visible n'est pas modifiable.
      $clientGuard($cid);
      if (!row("SELECT 1 AS x FROM client WHERE id=? AND active=1", [$cid]))
        json_out(['ok' => false, 'error' => 'Compte #' . $cid . ' introuvable ou inactif.'], 409);
      // Course entre deux demandes visant la même fiche : la seconde échoue ici
      // plutôt que d'écraser silencieusement le rattachement de la première.
      if (row("SELECT 1 AS x FROM client WHERE erp_client_id=? AND id<>?", [$eid, $cid]))
        json_out(['ok' => false, 'error' => 'Fiche #' . $eid . ' déjà reliée à un autre compte.'], 409);

      q("UPDATE client SET erp_client_id=? WHERE id=?", [$eid, $cid]);
      /* Correspondance durable + reprise des commandes DÉJÀ passées par ce
         compte : elles n'avaient pas de fiche ERP au moment où elles ont été
         créées, elles en ont une maintenant. C'est le seul instant où on peut
         le faire sans supposer quoi que ce soit — le franchisé vient de dire
         que les deux fiches sont la même personne. */
      if (function_exists('erp_map_poser')) erp_map_poser($cid, $eid, 'rattachement');
      if (col_exists('ws_orders', 'customer_erp_id')) {
        try { q("UPDATE ws_orders SET customer_erp_id=? WHERE customer_id=? AND customer_erp_id IS NULL",
                [$eid, $cid]); } catch (Throwable $e) { error_log('[ws] reprise commandes: ' . $e->getMessage()); }
      }
      q("UPDATE ws_client_link_requests SET status='linked', decided_at=NOW(), decided_by=? WHERE id=?",
        [($par ?: null), $id]);
      json_out(['ok' => true, 'action' => 'link', 'clientId' => $cid, 'ficheId' => $eid]);
    }

    if ($m === 'GET' && $p === '/franchisee/fr-join-requests') {
      if (!$tblExists('ws_office_join_requests')) json_vide(['ws_office_join_requests']);
      $hasCli  = col_exists('ws_office_join_requests', 'client_id');
      $hasMail = col_exists('ws_office_join_requests', 'contact_email');
      $hasTel  = col_exists('ws_office_join_requests', 'contact_phone');
      $joinCli = ($hasCli && $tblExists('client')) ? " LEFT JOIN client c ON c.id = r.client_id" : "";
      $cliName = $joinCli ? "NULLIF(TRIM(CONCAT_WS(' ', c.name, c.surname)), '')" : "NULL";
      $cliMail = $joinCli ? "NULLIF(TRIM(c.email), '')" : "NULL";
      // Bureau candidat : même boutique que la demande quand ws_offices porte
      // le shop (0022), et seulement des bureaux VALIDÉS (active = 1).
      $oScope = col_exists('ws_offices', 'shop_id') ? " AND (r.shop_id IS NULL OR f.shop_id = r.shop_id)" : "";
      $cand   = "FROM ws_offices f WHERE f.active = 1
                  AND f.name LIKE CONCAT('%', LEFT(r.office_name_raw, 12), '%') $oScope LIMIT 1";
      /* Bureau DÉSIGNÉ par la demande (r.office_id) : une inscription par lien
         magique sait exactement à quel bureau elle se rattache. On le préfère à
         la ressemblance de nom — qui reste le repli des demandes saisies à la
         main. Le bureau doit être validé dans les deux cas : c'est la validation
         qui ouvre la livraison. */
      $hasOff = col_exists('ws_office_join_requests', 'office_id');
      $exact  = $hasOff ? "FROM ws_offices f WHERE f.id = r.office_id AND f.active = 1 $oScope LIMIT 1" : null;
      $eId    = $exact ? "COALESCE((SELECT f.id   $exact), (SELECT f.id   $cand))" : "(SELECT f.id   $cand)";
      $eNom   = $exact ? "COALESCE((SELECT f.name $exact), (SELECT f.name $cand))" : "(SELECT f.name $cand)";
      // Bureau visé mais PAS ENCORE VALIDÉ : le dire, au lieu du « aucun bureau
      // ne correspond » qui enverrait le franchisé en créer un second.
      $attNom = $hasOff ? "(SELECT f.name FROM ws_offices f WHERE f.id = r.office_id LIMIT 1)" : "NULL";
      $rs = rows("SELECT r.id, r.office_name_raw, r.address_raw,
                         " . ($hasCli  ? 'r.client_id'    : 'NULL') . " AS client_id,
                         " . ($hasMail ? 'r.contact_email' : 'NULL') . " AS contact_email,
                         " . ($hasTel  ? 'r.contact_phone' : 'NULL') . " AS contact_phone,
                         $cliName AS cli_name, $cliMail AS cli_mail,
                         $attNom AS vise_nom,
                         $eId AS office_id,
                         $eNom AS proche
                    FROM ws_office_join_requests r$joinCli
                   WHERE " . $scope('r.shop_id') . " AND r.status='pending' ORDER BY r.created_at DESC LIMIT 50");
      json_out(array_map(function ($r) {
        $who = $r['cli_name'] ?: ($r['cli_mail'] ?: ($r['contact_email'] ?: $r['contact_phone']));
        $cid = (int) ($r['client_id'] ?? 0);
        return [
          'id'       => 'jr' . $r['id'],
          'clientId' => $cid ?: null,
          'officeId' => $r['office_id'] ? (int) $r['office_id'] : null,
          'client'   => $who ? ($who . ($cid ? ' · client #' . $cid : '')) : ($cid ? 'Client #' . $cid : 'Demandeur non identifié'),
          'demande'  => '« Rattacher à ' . $r['office_name_raw'] . ' »'
                        . ($r['contact_phone'] ? ' · ' . $r['contact_phone'] : ''),
          'proche'   => $r['office_id']
            ? ($r['proche'] . ($r['address_raw'] ? ' (' . $r['address_raw'] . ')' : ''))
            : ($r['vise_nom']
              ? 'le bureau « ' . $r['vise_nom'] . ' » existe mais n’est pas encore validé — validez-le dans Clients B2B › Bureaux, puis liez'
              : 'aucun bureau validé ne correspond — créez le bureau (Clients B2B › Bureaux) avant de lier'),
          'dup'      => (bool) $r['office_id'],
          // Champs BRUTS de la demande. Ils n'existaient que fondus dans les
          // phrases ci-dessus : pour créer le bureau manquant, le franchisé
          // devait retaper à la main ce que le client avait déjà saisi. Ils
          // pré-remplissent désormais le formulaire Bureau.
          'officeName' => $r['office_name_raw'],
          'address'    => $r['address_raw'] ?: '',
          'email'      => $r['contact_email'] ?: ($r['cli_mail'] ?: ''),
          'phone'      => $r['contact_phone'] ?: '',
          'clientName' => $r['cli_name'] ?: '',
        ];
      }, $rs));
    }

    // ── Commandes du jour — RÉEL (ws_orders, portée boutique). Remplace la
    //    liste de démo codée en dur dans le BO (go-live : plus de mock). ──
    if ($m === 'GET' && $p === '/franchisee/fr-orders') {
      if (!$hasOrders) json_out([]);
      // Toutes les commandes d'AUJOURD'HUI ET DES PROCHAINS JOURS (31 j) :
      // le tableau de bord filtre côté écran (Aujourd'hui / Semaine / Mois).
      // Nom + prénom du client connecté (table client), source (colonne
      // ws_orders.source posée par le webshop, repli moyen de paiement),
      // payé / non payé (payment_status).
      $hasCli2 = $tblExists('client');
      $hasSrc  = col_exists('ws_orders', 'source');
      /* RATTACHEMENT B2B de la commande — bureau (société), site, tournée,
         département — joint quand la base le porte : les étiquettes colis et
         la modale de détail l'affichent. Une commande « privée » (sans site
         ni bureau) rend null partout — rien d'inventé, l'étiquette omet la
         ligne. Le bureau vient de la commande, sinon de son site ; la
         tournée vient du site ; le département vient du compte client. */
      $hasSiteC = col_exists('ws_orders', 'office_delivery_site_id') && $tblExists('ws_office_delivery_sites');
      $hasOffC  = col_exists('ws_orders', 'office_client_id') && $tblExists('ws_offices');
      $hasOffJ3 = $hasSiteC && $tblExists('ws_offices');
      $hasTourJ = $hasSiteC && $tblExists('ws_tours');
      $hasDeptJ = $hasCli2 && col_exists('client', 'department_id') && $tblExists('b2b_client_company_department');
      $selBureau = $hasOffC
        ? ($hasOffJ3 ? "COALESCE(bo2.name, bo3.name)" : "bo2.name")
        : ($hasOffJ3 ? "bo3.name" : "NULL");
      $rs = rows("SELECT o.id, o.order_ref, o.payment_method, o.payment_status, o.payment_type,
                         COALESCE(NULLIF(o.guest_name,'')" .
                         ($hasCli2 ? ", NULLIF(TRIM(CONCAT(COALESCE(cl.name,''),' ',COALESCE(cl.surname,''))),'')" : "") . ",
                                  'Client webshop') AS client,
                         o.mode, o.total, o.status, o.slot_label" .
                 ($hasSrc ? ", o.source" : ", NULL AS source") . ",
                         DATE_FORMAT(COALESCE(o.delivery_date, DATE(o.created_at)),'%Y-%m-%d') AS jour,
                         DATE_FORMAT(o.created_at,'%H:%i') AS heure,
                         (SELECT COALESCE(SUM(l.qty),0) FROM ws_order_lines l WHERE l.order_id=o.id" . oline_own() . ") AS pieces,
                         " . ($hasSiteC ? "ds.name" : "NULL") . " AS site,
                         " . $selBureau . " AS bureau,
                         " . ($hasTourJ ? "tr2.name" : "NULL") . " AS tournee,
                         " . ($hasDeptJ ? "dp2.name" : "NULL") . " AS departement
                    FROM ws_orders o" .
                 ($hasCli2 ? " LEFT JOIN client cl ON cl.id = o.customer_id" : "") .
                 ($hasSiteC ? " LEFT JOIN ws_office_delivery_sites ds ON ds.id = o.office_delivery_site_id" : "") .
                 ($hasOffC ? " LEFT JOIN ws_offices bo2 ON bo2.id = o.office_client_id" : "") .
                 ($hasOffJ3 ? " LEFT JOIN ws_offices bo3 ON bo3.id = ds.office_client_id" : "") .
                 ($hasTourJ ? " LEFT JOIN ws_tours tr2 ON tr2.id = ds.tournee_id" : "") .
                 ($hasDeptJ ? " LEFT JOIN b2b_client_company_department dp2 ON dp2.id = cl.department_id" : "") . "
                   WHERE " . $scope('o.shop_id') . "
                     AND COALESCE(o.delivery_date, DATE(o.created_at))
                         BETWEEN ? AND DATE_ADD(?, INTERVAL 31 DAY)
                   ORDER BY COALESCE(o.delivery_date, DATE(o.created_at)), o.created_at DESC LIMIT 400", [$today, $today]);
      /* LIGNES DE LA COMMANDE — jointes à chaque commande pour la modale de
         détail de la console (clic sur une ligne de « Liste commandes »).
         UNE requête pour tout le lot, groupée en PHP — pas de N+1.
         LES MENUS Y SONT ENTIERS : la ligne mère porte la formule à son prix
         de base, et chaque choix est une ligne fille (parent_line_id,
         unit_price = le SUPPLÉMENT seul, 0 si compris — voir l'écriture de la
         commande). Ne servir que les mères cachait la composition ET faisait
         mentir la somme face au total (le supplément vit sur la fille). Les
         filles partent en `composants` de leur mère, avec `inclus` quand leur
         prix est 0. Le compteur de pièces, lui, reste mères-seules (compte
         commercial). Libellé : celui figé sur la ligne (product_name), sinon
         le nom catalogue actuel — jamais un texte de remplissage. */
      $lignesParCmd = [];
      if ($rs) {
        $ids2 = array_map(fn ($o) => (int) $o['id'], $rs);
        $ph2  = implode(',', array_fill(0, count($ids2), '?'));
        $meres = []; $filles = [];
        foreach (rows("SELECT l.id, l.order_id, " . (col_exists('ws_order_lines', 'parent_line_id') ? "l.parent_line_id" : "NULL AS parent_line_id") . ",
                              COALESCE(NULLIF(l.product_name,''), p.name) AS produit,
                              l.qty, l.unit_price, l.`portion`, l.note
                         FROM ws_order_lines l
                         LEFT JOIN ws_products p ON p.id = l.product_id
                        WHERE l.order_id IN ($ph2)
                        ORDER BY l.order_id, COALESCE(l.parent_line_id, l.id), l.id", $ids2) as $l2) {
          $lbl2 = (string) ($l2['produit'] ?? '');
          if ((string) ($l2['portion'] ?? '') !== '') $lbl2 .= ($lbl2 !== '' ? ' — ' : '') . $l2['portion'];
          $e2 = [
            'produit' => $lbl2 !== '' ? $lbl2 : null,
            'qty'     => (int) $l2['qty'],
            'prix'    => $l2['unit_price'] !== null ? number_format((float) $l2['unit_price'], 2, ',', ' ') . ' €' : null,
            'note'    => (string) ($l2['note'] ?? '') !== '' ? $l2['note'] : null,
          ];
          if (empty($l2['parent_line_id'])) {
            $e2['id'] = (int) $l2['id'];
            $meres[(int) $l2['order_id']][] = $e2;
          } else {
            $e2['inclus'] = ((float) $l2['unit_price']) == 0.0;
            $filles[(int) $l2['parent_line_id']][] = $e2;
          }
        }
        foreach ($meres as $oid2 => $ls2) foreach ($ls2 as $e2) {
          $e2['composants'] = $filles[$e2['id']] ?? []; unset($e2['id']);
          $lignesParCmd[$oid2][] = $e2;
        }
      }
      json_out(array_map(fn ($o) => [
        'ref' => '#' . $o['order_ref'],
        'client' => $o['client'],
        'mode' => ($o['mode'] === 'delivery' ? 'Livraison' : 'Collect'),
        'source' => ($o['source'] === 'pos') ? 'POS'
                  : (($o['source'] === 'webshop') ? 'Webshop'
                  : ((payment_family($o['payment_method'] ?? '') === 'shop') ? 'POS' : 'Webshop')),
        'paye' => in_array(strtolower((string) $o['payment_status']), ['paid', 'captured', 'succeeded'], true) ? 'Payé'
                : (($o['payment_type'] === 'deferred') ? 'Facturé' : 'Non payé'),
        'date' => $o['jour'],
        'montant' => number_format((float) $o['total'], 2, ',', ' ') . ' €',
        'statut' => $o['status'], 'heure' => $o['heure'],
        'creneau' => $o['slot_label'] ?: '—', 'pieces' => (int) $o['pieces'],
        'bureau' => ($o['bureau'] ?? '') !== '' ? $o['bureau'] : null,
        'site' => ($o['site'] ?? '') !== '' ? $o['site'] : null,
        'tournee' => ($o['tournee'] ?? '') !== '' ? $o['tournee'] : null,
        'departement' => ($o['departement'] ?? '') !== '' ? $o['departement'] : null,
        'lines' => $lignesParCmd[(int) $o['id']] ?? [],
      ], $rs));
    }

    // ── Avancement du statut d'une commande (écran Commandes du jour). ──
    if ($m === 'POST' && $p === '/franchisee/order-status') {
      $b = body(); $ref = ltrim(trim((string) ($b['ref'] ?? '')), '#');
      $st = (string) ($b['status'] ?? '');
      // « in_delivery » : la marchandise est PARTIE avec le livreur mais n'est
      // pas encore remise. Sans cet état, une commande sautait de « Prête » à
      // « Livrée » — impossible de savoir ce qui est en tournée à un instant
      // donné, ni de distinguer une commande oubliée en boutique d'une commande
      // en route. La validation finale viendra de la PWA livreur.
      $OK = ['pending', 'confirmed', 'preparing', 'ready', 'in_delivery', 'delivered', 'completed', 'cancelled'];
      if ($ref === '' || !in_array($st, $OK, true)) json_out(['ok' => false, 'error' => 'ref + statut valides requis'], 400);
      if (!$hasOrders) json_out(['ok' => false, 'error' => 'ws_orders absente'], 501);
      // Horodatage de la remise : la colonne delivered_at existait depuis
      // toujours et n'était JAMAIS écrite — la boutique ne pouvait donc pas
      // savoir quand une commande avait été remise ou livrée. C'est ce qui
      // tranche un litige (« je suis passé à 17 h ») et ce qui date le service
      // rendu. Posé au passage en statut terminal, et seulement s'il est encore
      // vide : repasser par le même statut ne réécrit pas l'heure d'origine.
      $stampDone = in_array($st, ['delivered', 'completed'], true) && col_exists('ws_orders', 'delivered_at');
      q("UPDATE ws_orders SET status=?" . ($stampDone ? ", delivered_at = COALESCE(delivered_at, NOW())" : "")
         . " WHERE order_ref=?" . ($shopId ? " AND shop_id=" . (int) $shopId : ""), [$st, $ref]);
      json_out(['ok' => true, 'status' => $st]);
    }

    // ── Stats réseau — agrégats RÉELS 30 jours (toutes boutiques). ──
    /* PORTÉE RÉSEAU ASSUMÉE — écran « Stats réseau consolidées ». Ces quatre
       KPI comparent la boutique au réseau : les cloisonner les viderait de leur
       sens. Seuls des agrégats sortent d'ici, jamais une ligne nominative. */
    /* ── STATS RÉSEAU — CA par boutique, Livraison et Webshop, semaine et mois.
       Une ligne par boutique ACTIVE du réseau. Portée réseau assumée : ce sont
       des agrégats, jamais une ligne nominative — ?shop= ne restreint pas.

       SOURCE : ws_orders, et c'est un choix documenté, pas un défaut.
       L'ERP (GET shops/{id}/transactions) a été sondé le 26/08 sur les quatre
       boutiques : il couvre bien le comptoir, mais il ne peut servir NI l'une
       NI l'autre de ces deux mesures —
         • id_serving_method vaut 2 sur 100 % des lignes (275 le 26/08, 276 le
           25/08, 119 en boutique 3, 108 en boutique 4) : aucune trace du mode
           de service, donc pas de livraison ;
         • order_ref et transaction_uuid sont nuls sur 100 % des lignes : aucun
           marqueur d'origine webshop — normal, rien ne pousse les commandes du
           site vers l'ERP, elles vivent ici.
       La route est en outre JOURNALIÈRE (?date=YYYY-MM-DD ; date_from/from/
       start_date sont ignorés SILENCIEUSEMENT, HTTP 200 et mêmes données) :
       un mois coûterait 30 appels par boutique.

       CE QUE LE CHIFFRE NE COUVRE PAS, ET IL FAUT LE SAVOIR : « livraison »
       ne compte que les livraisons COMMANDÉES SUR LE SITE. Une livraison
       commandée au comptoir n'est nulle part — ni ici, ni récupérable côté
       ERP en l'état. Le chiffre est donc un plancher, pas le CA livraison de
       la boutique. On ne comble pas ce trou par une estimation.

       DEUX MESURES QUI SE RECOUPENT : la livraison est un SOUS-ENSEMBLE du
       webshop. Les additionner compterait deux fois le même euro — c'est
       pourquoi aucune ligne ne porte de total. ── */
    if ($m === 'GET' && $p === '/franchisee/fr-net-stats') {
      if (!$hasOrders) json_vide(['ws_orders']);
      /* `mode` et NON `delivery_mode` : les deux colonnes existent (migration
         0031) mais portent des vocabulaires différents — `mode` vaut
         'collect'/'delivery', `delivery_mode` vaut 'collect'/'office_delivery'
         (cf. l'INSERT de POST /orders). Comparer delivery_mode à 'delivery'
         ne matche jamais et rend 0 sans rien signaler. */
      $modeCol = col_exists('ws_orders', 'mode');
      // Fenêtres GLISSANTES, décidées ici : 7 et 30 jours, comme la mesure
      // qu'elles remplacent. La console ne les recalcule pas et ne les affiche
      // pas — elle ne doit donc jamais avoir à les deviner.
      $fen = function ($jours) use ($modeCol, $today) {
        $liv = $modeCol ? "COALESCE(SUM(CASE WHEN o.mode='delivery' THEN o.total ELSE 0 END),0)" : "NULL";
        return rows("SELECT o.shop_id, COALESCE(SUM(o.total),0) AS ws, $liv AS liv
                       FROM ws_orders o
                      WHERE o.status <> 'cancelled'
                        AND o.created_at >= DATE_SUB(?, INTERVAL $jours DAY)
                      GROUP BY o.shop_id", [$today]);
      };
      $par = ['sem' => [], 'mois' => []];
      foreach (['sem' => 7, 'mois' => 30] as $k => $j)
        foreach ($fen($j) as $r) $par[$k][(int) $r['shop_id']] = $r;

      $out = [];
      foreach (rows("SELECT id, name FROM $SHOPS WHERE active=1 ORDER BY name") as $sh) {
        $sid = (int) $sh['id'];
        $s = $par['sem'][$sid] ?? null; $mo = $par['mois'][$sid] ?? null;
        /* ZÉRO ET NULL NE DISENT PAS LA MÊME CHOSE. Une boutique sans commande
           sur la fenêtre a bien fait 0 € sur le webshop : c'est un chiffre, il
           se calcule, il s'affiche. `null` est réservé à ce qui n'est PAS
           calculable — ici la colonne `mode` absente du schéma, qui empêche de
           distinguer une livraison. La console rend « — » et ne le remplace
           jamais par 0. */
        $out[] = [
          'shop_id'           => $sid,
          'shop_name'         => (string) $sh['name'],
          'livraison_semaine' => ($modeCol && $s)  ? round((float) $s['liv'], 2)  : ($modeCol ? 0.0 : null),
          'webshop_semaine'   => $s  ? round((float) $s['ws'], 2)  : 0.0,
          'livraison_mois'    => ($modeCol && $mo) ? round((float) $mo['liv'], 2) : ($modeCol ? 0.0 : null),
          'webshop_mois'      => $mo ? round((float) $mo['ws'], 2) : 0.0,
        ];
      }
      json_out($out);
    }

    // ── Capacité / calendrier — RÉEL : créneaux (ws_slots) × réservations
    //    (ws_orders.slot_id par delivery_date), 5 prochains jours. ──
    if ($m === 'GET' && $p === '/franchisee/fr-capacity') {
      if (!$tblExists('ws_slots')) json_vide(['ws_slots']);
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
        $vSc = ($shopId && col_exists('ws_offices','shop_id')) ? " AND shop_id=".(int)$shopId : "";
      if ($vSc && !row("SELECT 1 x FROM ws_offices WHERE id=?$vSc", [$id])) json_out(['ok'=>false,'error'=>'Bureau #'.$id.' hors de votre boutique — validation refusée.'],403);
      q("UPDATE ws_offices SET status='validated', active=1 WHERE id=?$vSc", [$id]);
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
                 // Temps d'accès repris du site modèle, ou absent — jamais 6 par défaut.
                 [$id, $tpl2['name'] ?? null, $sa, $tpl2['tournee_id'] ?? null,
                  $tpl2['site_access_minutes'] ?? null]);
          if ($tpl2 && $tpl2['tournee_id'] !== null && col_exists('ws_offices', 'tour_id'))
            q("UPDATE ws_offices SET tour_id=COALESCE(tour_id, ?) WHERE id=?", [(int) $tpl2['tournee_id'], $id]);
        }
      } else {
        q("UPDATE ws_offices SET active=0 WHERE id=? AND status='pending'" . (($shopId && col_exists('ws_offices','shop_id')) ? " AND shop_id=".(int)$shopId : ""), [$id]);
      }
      json_out(['ok' => true, 'action' => $act]);
    }

    /* ── Décision sur une demande de rattachement bureau (fr-join-requests).
       « Lier » ÉCRIT client.office_id — c'est cette écriture qui ouvre la
       livraison au bureau au client. Elle échoue explicitement si le demandeur
       n'est pas identifié ou si aucun bureau validé ne correspond : on ne
       marque JAMAIS la demande « linked » sans avoir rattaché quelqu'un.
       On ne touche ni is_b2b ni office_delivery sur la personne : ces deux
       drapeaux déclenchent le trigger 0019 qui CRÉERAIT un bureau à son nom. ── */
    if ($m === 'POST' && $p === '/franchisee/join-decide') {
      $b = body(); $id = (int) preg_replace('/\D/', '', (string) ($b['id'] ?? ''));
      $act = (string) ($b['action'] ?? '');
      if (!$id || !in_array($act, ['link', 'reject'], true)) json_out(['ok' => false, 'error' => 'id + action requis'], 400);
      if (!$tblExists('ws_office_join_requests')) json_out(['ok' => false, 'error' => 'table absente'], 501);
      $sc = ($shopId && col_exists('ws_office_join_requests', 'shop_id')) ? " AND shop_id=" . (int) $shopId : "";
      $r  = row("SELECT * FROM ws_office_join_requests WHERE id=?$sc", [$id]);
      if (!$r) json_out(['ok' => false, 'error' => 'Demande introuvable (ou hors de votre boutique).'], 404);
      // Colonnes historiques de la table (resolved_*) : c'est elles qui font foi.
      $hasDec = col_exists('ws_office_join_requests', 'resolved_at');
      $hasRes = col_exists('ws_office_join_requests', 'resolved_office_id');

      if ($act === 'reject') {
        $why = mb_substr(trim((string) ($b['reason'] ?? '')), 0, 200);
        q("UPDATE ws_office_join_requests SET status='rejected'"
          . ($hasDec ? ", resolved_at=NOW()" : "")
          . (($why !== '' && col_exists('ws_office_join_requests', 'reject_reason')) ? ", reject_reason=" . db()->quote($why) : "")
          . " WHERE id=?$sc", [$id]);
        json_out(['ok' => true, 'action' => 'reject']);
      }

      // ── Liaison ──
      $cid = (int) ($r['client_id'] ?? 0);
      if (!$cid) json_out(['ok' => false, 'error' => 'Demande sans client identifié (ancienne ligne) — le client doit refaire sa demande depuis le webshop.'], 409);
      if (!col_exists('client', 'office_id')) json_out(['ok' => false, 'error' => 'Colonne client.office_id absente — rattachement impossible.'], 501);
      if (!row("SELECT 1 AS x FROM client WHERE id=? AND active=1", [$cid]))
        json_out(['ok' => false, 'error' => 'Client #' . $cid . ' introuvable ou inactif.'], 409);

      // Bureau : celui choisi par le franchisé, sinon le candidat détecté par nom.
      $oid = (int) ($b['officeId'] ?? 0);
      $oSc = col_exists('ws_offices', 'shop_id') && !empty($r['shop_id'])
             ? " AND (shop_id IS NULL OR shop_id=" . (int) $r['shop_id'] . ")" : "";
      // Bureau DÉSIGNÉ par la demande avant la ressemblance de nom : une
      // inscription venue d'un lien magique porte son bureau exact.
      if (!$oid && !empty($r['office_id'])) $oid = (int) $r['office_id'];
      if (!$oid) {
        $cand = row("SELECT id FROM ws_offices
                      WHERE active=1 AND name LIKE CONCAT('%', ?, '%')$oSc ORDER BY id LIMIT 1",
                    [mb_substr((string) $r['office_name_raw'], 0, 12)]);
        $oid = (int) ($cand['id'] ?? 0);
      }
      if (!$oid) json_out(['ok' => false, 'error' => 'Aucun bureau validé ne correspond à « ' . $r['office_name_raw'] . ' » — créez-le dans Clients B2B › Bureaux, puis liez.'], 409);
      if (!row("SELECT 1 AS x FROM ws_offices WHERE id=? AND active=1$oSc", [$oid])) {
        // Dire LEQUEL des trois cas : « validez le bureau » et « ce bureau
        // n'est pas le vôtre » n'appellent pas le même geste.
        $ex = row("SELECT name, active FROM ws_offices WHERE id=?$oSc", [$oid]);
        json_out(['ok' => false, 'error' => $ex
          ? 'Le bureau « ' . $ex['name'] . ' » n’est pas encore validé — validez-le dans Clients B2B › Bureaux, puis liez.'
          : 'Bureau #' . $oid . ' inconnu, ou hors de votre boutique.'], 409);
      }

      q("UPDATE client SET office_id=? WHERE id=?", [$oid, $cid]);
      q("UPDATE ws_office_join_requests SET status='linked'"
        . ($hasRes ? ", resolved_office_id=" . $oid : "")
        . ($hasDec ? ", resolved_at=NOW()" : "") . " WHERE id=?$sc", [$id]);
      json_out(['ok' => true, 'action' => 'link', 'clientId' => $cid, 'officeId' => $oid]);
    }

    // Stock du jour — catalogue catégories › produits (online/en shop/seuil).
    // ── Stock du jour : lignes de commande du produit (Ruby = Click&Collect,
    //    Apricot = Delivery) — ws_order_lines × ws_orders, jour courant. ──
    if ($m === 'GET' && $p === '/franchisee/stock-product-orders') {
      $pn = trim((string) qp('product', ''));
      if (!$tblExists('ws_orders') || !$tblExists('ws_order_lines')) json_vide(['ws_orders', 'ws_order_lines']);
      if ($pn === '') json_out([]);   // aucun produit demandé : ce n'est pas une panne
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

    /* QR pour les DOCUMENTS D'IMPRESSION de la console (feuille de tournée →
       PWA livraison). Même encodeur autonome que l'affiche du lien magique
       (qr.php) — pas de service tiers : les URL internes ne sortent pas.
       La console le télécharge AVEC le jeton (fetch + en-têtes, un <img>
       n'en porte pas) et l'incruste en data: dans le document. */
    if ($m === 'GET' && $p === '/franchisee/tour-qr') {
      $d3 = trim((string) qp('d', ''));
      if ($d3 === '' || strlen($d3) > 512) json_out(['error' => 'd requis — le contenu du QR, 512 caractères max.'], 400);
      require_once __DIR__ . '/qr.php';
      $mx3 = qr_matrix($d3, 'M');
      if (!$mx3) json_out(['error' => 'QR non générable pour ce contenu.'], 422);
      header('Content-Type: image/png');
      header('Cache-Control: no-store');
      echo qr_png($mx3[0], $mx3[1], 8, 4);
      exit;
    }

    /* Avis Google de LA boutique — LECTURE seule, via l'API Places Details
       LEGACY : c'est la seule variante qui sert les 5 DERNIERS avis
       (reviews_sort=newest). L'API « New » refuse ce paramètre (« Unknown
       name "reviewsSort" ») et ne rend que les « 5 plus pertinents » —
       vérifié depuis ce serveur le 15/08/2026 (sonde). Le Place ID vient de
       la table `shops` (colonne détectée), la clé API de ws_param
       `google_api_key` : la clé ne vit qu'en base, côté serveur — le dépôt
       est public et le navigateur n'a pas à la voir. Répondre aux avis
       exigerait OAuth Business Profile. Au plus 5 avis rendus : limite de
       l'API Places, pas un choix. */
    if ($m === 'GET' && $p === '/franchisee/google-reviews') {
      if (!$shopId) json_out(['error' => 'boutique requise (?shop=)'], 400);
      $gKey = (string) ws_param('google_api_key', '');
      if ($gKey === '')
        json_out(['error' => 'Clé API Google non configurée — poser la clé dans ws_param, clé « google_api_key » (Console marque → Paramètres).'], 501);
      $gCol = null;
      foreach (['google_place_id', 'place_id', 'google_place', 'gplace_id'] as $c9)
        if (col_exists('shops', $c9)) { $gCol = $c9; break; }
      if (!$gCol)
        json_out(['error' => 'Aucune colonne Place ID dans « shops » (cherché : google_place_id, place_id, google_place, gplace_id).'], 501);
      $gPid = trim((string) (row("SELECT `$gCol` v FROM shops WHERE id = ?", [$shopId])['v'] ?? ''));
      if ($gPid === '') json_out(['error' => "Place ID Google vide pour cette boutique (shops.$gCol)."], 404);
      $ctx9 = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
      $raw9 = @file_get_contents('https://maps.googleapis.com/maps/api/place/details/json'
        . '?place_id=' . rawurlencode($gPid)
        . '&fields=' . rawurlencode('name,rating,user_ratings_total,url,reviews')
        . '&reviews_sort=newest&language=fr&key=' . rawurlencode($gKey), false, $ctx9);
      $j9 = ($raw9 !== false) ? json_decode($raw9, true) : null;
      if (!is_array($j9)) json_out(['error' => 'API Places injoignable depuis le serveur.'], 502);
      if (($j9['status'] ?? '') !== 'OK') {
        $why9 = (string) (($j9['status'] ?? '') ?: 'refus');
        if ((string) ($j9['error_message'] ?? '') !== '') $why9 .= ' — ' . $j9['error_message'];
        json_out(['error' => 'Google : ' . $why9, 'status' => (string) ($j9['status'] ?? '')], 502);
      }
      $rs9 = (array) ($j9['result'] ?? []);
      /* L'API rend déjà les 5 derniers ; le tri par time n'est qu'une ceinture. */
      $av9 = array_values((array) ($rs9['reviews'] ?? []));
      usort($av9, fn ($x9, $y9) => ((int) ($y9['time'] ?? 0)) <=> ((int) ($x9['time'] ?? 0)));
      header('Cache-Control: no-store');
      json_out([
        'nom'  => (string) ($rs9['name'] ?? ''),
        'note' => isset($rs9['rating']) ? (float) $rs9['rating'] : null,
        'nb'   => isset($rs9['user_ratings_total']) ? (int) $rs9['user_ratings_total'] : null,
        'url'  => (string) ($rs9['url'] ?? ''),
        'avis' => array_map(fn ($r9) => [
          'auteur' => (string) ($r9['author_name'] ?? '—'),
          'note'   => isset($r9['rating']) ? (int) $r9['rating'] : null,
          'quand'  => (string) ($r9['relative_time_description'] ?? ''),
          'date'   => !empty($r9['time']) ? date('Y-m-d', (int) $r9['time']) : '',
          'texte'  => (string) ($r9['text'] ?? ''),
        ], $av9),
      ]);
    }

    /* Brouillon de réponse à un avis Google — au nom de la boutique, selon la
       directive que la marque a définie pour cette note (ws_review_guidelines,
       console marque). PUBLICATION TOUJOURS MANUELLE : sans OAuth Business
       Profile personne ne peut poster ; le franchisé copie le texte et le
       colle sur Google — c'est le garde-fou, valable pour toutes les notes.
       La clé Anthropic vit en ws_param `anthropic_api_key`, côté serveur
       seulement (dépôt public, navigateur jamais). L'appel suit le motif
       sortant du fichier (contexte HTTP + file_get_contents, comme VIES). */
    if ($m === 'POST' && $p === '/franchisee/google-review-reply') {
      if (!$shopId) json_out(['error' => 'boutique requise (?shop=)'], 400);
      rate_limit('gravis', 20, 300);
      $b9 = body();
      $rNote  = (int) ($b9['note'] ?? 0);
      $rTexte = trim((string) ($b9['texte'] ?? ''));
      $rAut   = trim((string) ($b9['auteur'] ?? ''));
      if ($rNote < 1 || $rNote > 5) json_out(['error' => 'note de l’avis requise (1 à 5)'], 400);
      $aKey = (string) ws_param('anthropic_api_key', '');
      if ($aKey === '')
        json_out(['error' => 'Clé API Anthropic non configurée — poser la clé dans ws_param, clé « anthropic_api_key » (Console marque → Paramètres).'], 501);
      if (!$tblExists('ws_review_guidelines'))
        json_out(['error' => 'Table des directives absente — migration 0082 non jouée.'], 501);
      $gd = row("SELECT note_min, note_max, tone, instructions, example_reply FROM ws_review_guidelines
                  WHERE ? BETWEEN note_min AND note_max ORDER BY (note_max - note_min), id LIMIT 1", [$rNote]);
      if (!$gd)
        json_out(['error' => 'Aucune directive pour la note ' . $rNote . ' — à définir dans la console marque (Avis Google — directives).'], 404);
      $shopN9 = (string) (row("SELECT name FROM shops WHERE id = ?", [$shopId])['name'] ?? '');
      $socle9 = (string) ws_param('reviews_tone_base', '');
      $sys9 = "Tu rédiges la réponse publique d'un établissement à un avis Google, au nom de « " . ($shopN9 !== '' ? $shopN9 : 'la boutique') . " ».\n"
        . "Règles absolues : n'invente aucun fait ; n'admets aucune faute que l'avis n'établit pas ; ne promets aucun dédommagement ni geste commercial ; ne divulgue aucune donnée personnelle ; pour tout litige, invite à contacter directement l'établissement (canal privé).\n"
        . ($socle9 !== '' ? 'Ton socle de la marque (commun à toutes les réponses) : ' . $socle9 . "\n" : '')
        . 'Ton imposé par la marque pour cette note : ' . (string) $gd['tone'] . "\n"
        . ((string) ($gd['instructions'] ?? '') !== '' ? 'Consignes de la marque : ' . $gd['instructions'] . "\n" : '')
        . ((string) ($gd['example_reply'] ?? '') !== '' ? 'Exemple du registre attendu (ne pas recopier) : ' . $gd['example_reply'] . "\n" : '')
        . "Réponds dans la langue de l'avis (français par défaut). Rends UNIQUEMENT le texte de la réponse — sans guillemets, sans préambule, sans signature ajoutée.";
      $usr9 = 'Avis ' . $rNote . '/5' . ($rAut !== '' ? ' de « ' . $rAut . ' »' : '') . " :\n"
        . ($rTexte !== '' ? $rTexte : '(avis sans texte — seulement la note)');
      $req9 = json_encode([
        'model'      => (string) ws_param('reviews_agent_model', 'claude-opus-5'),
        'max_tokens' => 16000,
        'system'     => $sys9,
        'messages'   => [['role' => 'user', 'content' => $usr9]],
      ], JSON_UNESCAPED_UNICODE);
      $ctxA = stream_context_create(['http' => [
        'method'  => 'POST', 'timeout' => 90, 'ignore_errors' => true,
        'header'  => "Content-Type: application/json\r\nx-api-key: " . $aKey . "\r\nanthropic-version: 2023-06-01\r\n",
        'content' => $req9,
      ]]);
      $rawA = @file_get_contents('https://api.anthropic.com/v1/messages', false, $ctxA);
      $jA = ($rawA !== false) ? json_decode($rawA, true) : null;
      if (!is_array($jA)) json_out(['error' => 'API Anthropic injoignable depuis le serveur.'], 502);
      if (($jA['type'] ?? '') === 'error')
        json_out(['error' => 'Anthropic : ' . ((string) ($jA['error']['message'] ?? '') ?: 'refus'),
                  'code' => (string) ($jA['error']['type'] ?? '')], 502);
      if (($jA['stop_reason'] ?? '') === 'refusal')
        json_out(['error' => 'Le modèle a décliné la génération pour cet avis — réponse à rédiger à la main.'], 502);
      $txt9 = '';
      foreach ((array) ($jA['content'] ?? []) as $blk9)
        if (($blk9['type'] ?? '') === 'text') $txt9 .= (string) ($blk9['text'] ?? '');
      $txt9 = trim($txt9);
      if ($txt9 === '') json_out(['error' => 'Réponse vide du modèle.'], 502);
      header('Cache-Control: no-store');
      json_out(['reponse' => $txt9,
                'tronque' => (($jA['stop_reason'] ?? '') === 'max_tokens'),
                'modele'  => (string) ($jA['model'] ?? ''),
                'directive' => ['tranche' => ($gd['note_min'] == $gd['note_max'] ? (string) $gd['note_min'] : $gd['note_min'] . '–' . $gd['note_max']), 'ton' => (string) $gd['tone']]]);
    }

    /* TOUS les avis Business Profile de LA boutique, avec l'état de réponse —
       c'est la source qui permet de RÉPONDRE, contrairement à la lecture
       Places (bornée aux 5 derniers avis, lecture seule). Fiche localisée
       par le Place ID de la boutique à chaque appel. */
    if ($m === 'GET' && $p === '/franchisee/gbp-reviews') {
      if (!$shopId) json_out(['error' => 'boutique requise (?shop=)'], 400);
      $gCol2 = null;
      foreach (['google_place_id', 'place_id', 'google_place', 'gplace_id'] as $c9)
        if (col_exists('shops', $c9)) { $gCol2 = $c9; break; }
      if (!$gCol2) json_out(['error' => 'Aucune colonne Place ID dans « shops ».'], 501);
      $gPid2 = trim((string) (row("SELECT `$gCol2` v FROM shops WHERE id = ?", [$shopId])['v'] ?? ''));
      if ($gPid2 === '') json_out(['error' => "Place ID Google vide pour cette boutique (shops.$gCol2)."], 404);
      $why2 = null; $tok2 = gbp_token($why2, $shopId);
      if (!$tok2) json_out(['error' => $why2], 501);
      $lc2 = gbp_locate($tok2, $gPid2, $why2);
      if (!$lc2) json_out(['error' => $why2], 502);
      $rv2 = gbp_http('GET', 'https://mybusiness.googleapis.com/v4/' . $lc2[0] . '/' . $lc2[1] . '/reviews?pageSize=50',
                      ['Authorization' => 'Bearer ' . $tok2]);
      if (!is_array($rv2)) json_out(['error' => 'API Business Profile (reviews) injoignable.'], 502);
      if (isset($rv2['error'])) json_out(['error' => 'Google (reviews) : ' . ((string) ($rv2['error']['message'] ?? 'refus'))], 502);
      $et2 = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];
      /* Tri par date de l'avis (createTime), plus récents d'abord — l'API rend
         l'ordre updateTime, qui bouge dès qu'une réponse est éditée. */
      $av2 = array_values((array) ($rv2['reviews'] ?? []));
      usort($av2, fn ($x9, $y9) => strcmp((string) ($y9['createTime'] ?? ''), (string) ($x9['createTime'] ?? '')));
      header('Cache-Control: no-store');
      json_out(['fiche' => $lc2[2],
        'nb'   => (int) ($rv2['totalReviewCount'] ?? 0),
        'note' => isset($rv2['averageRating']) ? (float) $rv2['averageRating'] : null,
        'avis' => array_map(fn ($r9) => [
          'id'      => (string) ($r9['reviewId'] ?? ''),
          'auteur'  => (string) ($r9['reviewer']['displayName'] ?? '—'),
          'note'    => $et2[(string) ($r9['starRating'] ?? '')] ?? null,
          'quand'   => substr((string) ($r9['createTime'] ?? ''), 0, 10),
          'texte'   => (string) ($r9['comment'] ?? ''),
          'repondu' => isset($r9['reviewReply']['comment']),
          'reponse' => (string) ($r9['reviewReply']['comment'] ?? ''),
        ], $av2),
      ]);
    }

    /* Le franchisé colle SON refresh token (compte Google de SA fiche) —
       rangé sous la portée serveur (google_oauth_refresh_token_shop_<id>),
       jamais l'id envoyé par le navigateur. Testé aussitôt : l'échange OAuth
       doit réussir, sinon la raison de Google revient et rien ne laisse
       croire que la connexion marche. {delete:true} le retire. */
    if ($m === 'POST' && $p === '/franchisee/gbp-token') {
      if (!$shopId) json_out(['error' => 'boutique requise (?shop=)'], 400);
      rate_limit('gbptok', 10, 300);
      $b9 = body();
      $cle9 = 'google_oauth_refresh_token_shop_' . (int) $shopId;
      if (!empty($b9['delete'])) {
        q("DELETE FROM ws_param WHERE param_key = ?", [$cle9]);
        json_out(['ok' => true, 'retire' => true]);
      }
      $tk9 = trim((string) ($b9['token'] ?? ''));
      if ($tk9 === '' || strlen($tk9) > 512) json_out(['error' => 'refresh token requis (512 caractères max)'], 400);
      q("INSERT INTO ws_param (param_key, param_value) VALUES (?,?)
         ON DUPLICATE KEY UPDATE param_value=VALUES(param_value)", [$cle9, $tk9]);
      $whyT = null; $tokT = gbp_token($whyT, $shopId);
      if (!$tokT) json_out(['ok' => false, 'error' => 'Jeton enregistré mais l’échange OAuth échoue : ' . $whyT], 502);
      json_out(['ok' => true]);
    }

    /* PUBLICATION d'une réponse — LE geste qui part sur Google. Le clic du
       franchisé est la validation humaine : rien ne se publie sans lui,
       quelle que soit la note (l'auto-publication n'existe pas ici). Publier
       sur un avis déjà répondu REMPLACE la réponse (comportement Google). */
    if ($m === 'POST' && $p === '/franchisee/gbp-reply') {
      if (!$shopId) json_out(['error' => 'boutique requise (?shop=)'], 400);
      rate_limit('gbppub', 15, 300);
      $b9 = body();
      $rid9 = trim((string) ($b9['id'] ?? ''));
      $txt9 = trim((string) ($b9['texte'] ?? ''));
      if ($rid9 === '' || $txt9 === '') json_out(['error' => 'id de l’avis et texte requis'], 400);
      if (mb_strlen($txt9) > 4000) json_out(['error' => 'Réponse trop longue — 4 000 caractères maximum sur Google.'], 400);
      $gCol3 = null;
      foreach (['google_place_id', 'place_id', 'google_place', 'gplace_id'] as $c9)
        if (col_exists('shops', $c9)) { $gCol3 = $c9; break; }
      $gPid3 = $gCol3 ? trim((string) (row("SELECT `$gCol3` v FROM shops WHERE id = ?", [$shopId])['v'] ?? '')) : '';
      if ($gPid3 === '') json_out(['error' => 'Place ID Google absent pour cette boutique.'], 404);
      $why3 = null; $tok3 = gbp_token($why3, $shopId);
      if (!$tok3) json_out(['error' => $why3], 501);
      $lc3 = gbp_locate($tok3, $gPid3, $why3);
      if (!$lc3) json_out(['error' => $why3], 502);
      $up3 = gbp_http('PUT', 'https://mybusiness.googleapis.com/v4/' . $lc3[0] . '/' . $lc3[1] . '/reviews/' . rawurlencode($rid9) . '/reply',
                      ['Authorization' => 'Bearer ' . $tok3, 'Content-Type' => 'application/json'],
                      json_encode(['comment' => $txt9], JSON_UNESCAPED_UNICODE));
      if (!is_array($up3)) json_out(['error' => 'API Business Profile (reply) injoignable.'], 502);
      if (isset($up3['error'])) json_out(['error' => 'Google (reply) : ' . ((string) ($up3['error']['message'] ?? 'refus'))], 502);
      json_out(['ok' => true, 'reponse' => (string) ($up3['comment'] ?? $txt9)]);
    }

    // Résolution PRODUIT robuste — utilisée par tous les toggles/saisies :
    // id (productId) prioritaire quand le front le fournit, sinon nom TRIMé ;
    // inclut les produits OBLIGATOIRES même hors webshop (active=0). Avant :
    // WHERE name=? AND active=1 → « produit inconnu » sur simple écart
    // d'espaces, doublon de nom, ou produit désactivé côté marque entre deux
    // rafraîchissements de l'écran.
    $findProduct = function ($src, $cols = 'id') {
      $pid = is_numeric($src['productId'] ?? null) ? (int) $src['productId'] : 0;
      if ($pid) { $p = row("SELECT $cols FROM ws_products WHERE id=?", [$pid]); if ($p) return $p; }
      $n = trim((string) ($src['product'] ?? ''));
      if ($n === '') return null;
      return row("SELECT $cols FROM ws_products
                   WHERE TRIM(name) = ? AND active = 1
                   ORDER BY active DESC, id LIMIT 1", [$n]);
    };
    $prodKo = fn ($src) => json_out(['ok' => false,
      'error' => 'produit inconnu : « ' . trim((string) ($src['product'] ?? ($src['productId'] ?? '?'))) . ' » — rechargez la page (Ctrl+F5), le catalogue a peut-être changé'], 400);

    // ── Stock du jour : SAISIE directe (formulaire modale) — pose les
    //    quantités ABSOLUES du jour par mode (webshop=delivery / shop=collect)
    //    dans ws_product_stock. date = jour courant. ──
    if ($m === 'POST' && $p === '/franchisee/stock-set') {
      $b = body();
      if (!$shopId) json_out(['ok' => false, 'error' => 'boutique requise (?shop=)'], 400);
      if (!$tblExists('ws_product_stock') || !$tblExists('ws_products')) json_out(['ok' => false, 'error' => 'tables stock absentes'], 501);
      $pr = $findProduct($b);
      if (!$pr) $prodKo($b);
      $out2 = [];
      foreach (['delivery' => 'online', 'collect' => 'shop'] as $mode2 => $key2) {
        if (!array_key_exists($key2, $b) || $b[$key2] === '' || $b[$key2] === null) continue;
        $qv = max(0, (int) $b[$key2]);
        q("INSERT INTO ws_product_stock (product_id, shop_id, date, mode, qty_total, qty_reserved, qty_sold, active)
             VALUES (?,?,?,?,?,0,0,1)
             ON DUPLICATE KEY UPDATE qty_total=VALUES(qty_total), active=1",
          [(int) $pr['id'], (int) $shopId, $today, $mode2, $qv]);
        $st2 = row("SELECT qty_total, qty_reserved, qty_sold FROM ws_product_stock
                     WHERE product_id=? AND shop_id=? AND date=? AND mode=?", [(int) $pr['id'], (int) $shopId, $today, $mode2]);
        $out2[$key2] = max(0, (int) ($st2['qty_total'] ?? 0) - (int) ($st2['qty_reserved'] ?? 0) - (int) ($st2['qty_sold'] ?? 0));
      }
      json_out(['ok' => true, 'date' => $today] + $out2);
    }

    // ── Stock du jour : ajustement +/− réel (ws_product_stock, jour courant). ──
    /* Contexte du seuil : ce que l'ecran affiche dans son (i). Renvoie des
       valeurs REELLES — seuil global en vigueur, fenetre de calcul, nombre de
       jours ou la boutique a tourne — plutot qu'un texte generique. Un chiffre
       explique se verifie ; une formule racontee ne se verifie pas. */
    if ($m === 'GET' && $p === '/franchisee/stock-threshold-info') {
      $glob = (int) ws_param('stock.default_min_threshold', '10');
      $du = date('Y-m-d', strtotime($today . ' -42 day'));
      $jours = null;
      if ($hasOrders) {
        $w = "COALESCE(o.delivery_date, DATE(o.created_at))";
        $r = row("SELECT COUNT(DISTINCT $w) AS n FROM ws_orders o
                   WHERE " . $scope('o.shop_id') . " AND o.status <> 'cancelled'
                     AND $w >= ? AND $w < ?", [$du, $today]);
        $jours = (int) ($r['n'] ?? 0);
      }
      $perso = 0;
      if ($shopId && $tblExists('ws_product_shops') && col_exists('ws_product_shops', 'min_threshold')) {
        $perso = (int) (row("SELECT COUNT(*) n FROM ws_product_shops
                              WHERE shop_id=? AND min_threshold IS NOT NULL", [(int) $shopId])['n'] ?? 0);
      }
      json_out(['ok' => true, 'global' => $glob, 'du' => $du, 'au' => $today,
                'jours' => $jours, 'marge' => 20, 'perso' => $perso]);
    }

    /* Seuil d'alerte stock d'un produit, propre a la boutique (migration 0054).
       Vider le champ (min = null) rend la main au parametre global — il faut
       pouvoir revenir en arriere sans deviner quel etait le nombre d'origine. */
    if ($m === 'POST' && $p === '/franchisee/stock-threshold') {
      $b = body();
      if (!$shopId) json_out(['ok' => false, 'error' => 'boutique requise (?shop=)'], 400);
      if (!$tblExists('ws_product_shops') || !col_exists('ws_product_shops', 'min_threshold'))
        json_out(['ok' => false, 'error' => 'Colonne min_threshold absente — seuil par produit indisponible (migration 0054).'], 501);
      $pr = $findProduct($b);
      if (!$pr) $prodKo($b);
      /* Mode AUTO : seuil calcule sur l'historique reel plutot que devine.
         Fenetre : les 6 dernieres semaines revolues (on exclut aujourd'hui, la
         journee n'est pas finie et fausserait la moyenne vers le bas).
         Diviseur : le nombre de JOURS OU LA BOUTIQUE A TOURNE sur la fenetre —
         pas le nombre de jours ou CE produit s'est vendu. Diviser par les seuls
         jours avec ventes gonflerait la moyenne en ignorant les jours a zero,
         et le seuil deviendrait systematiquement trop haut.
         Marge : +20 %, arrondie au superieur. */
      $auto = !empty($b['auto']);
      $raw = $b['min'] ?? null;
      $min = ($raw === null || $raw === '') ? null : max(0, (int) $raw);
      $detail = null;
      if ($auto) {
        if (!$tblExists('ws_order_lines')) json_out(['ok' => false, 'error' => 'ws_order_lines absente — calcul impossible'], 501);
        $win = "COALESCE(o.delivery_date, DATE(o.created_at))";
        $vend = row("SELECT COALESCE(SUM(l.qty),0) AS q
                       FROM ws_order_lines l JOIN ws_orders o ON o.id = l.order_id
                      WHERE l.product_id = ? AND o.shop_id = ? AND o.status <> 'cancelled'
                        AND $win >= DATE_SUB(?, INTERVAL 42 DAY) AND $win < ?",
                    [(int) $pr['id'], (int) $shopId, $today, $today]);
        $jours = row("SELECT COUNT(DISTINCT $win) AS n
                        FROM ws_orders o
                       WHERE o.shop_id = ? AND o.status <> 'cancelled'
                         AND $win >= DATE_SUB(?, INTERVAL 42 DAY) AND $win < ?",
                     [(int) $shopId, $today, $today]);
        $q = (int) ($vend['q'] ?? 0);
        $n = (int) ($jours['n'] ?? 0);
        if ($n < 1) json_out(['ok' => false,
          'error' => "Aucun jour d'activite sur les 6 dernieres semaines — seuil non calculable."], 409);
        $moy = $q / $n;
        $min = (int) ceil($moy * 1.2);
        $detail = ['vendu' => $q, 'jours' => $n, 'moyenne' => round($moy, 2), 'marge' => '20%'];
      }
      // La ligne d'assortiment peut ne pas exister : sans elle, le produit est
      // vendu tel quel (cf. /catalog/products). On la cree en la laissant ACTIVE
      // pour ne pas retirer le produit de la vente en reglant un seuil.
      q("INSERT INTO ws_product_shops (product_id, shop_id, active, min_threshold)
           VALUES (?,?,1,?)
           ON DUPLICATE KEY UPDATE min_threshold = VALUES(min_threshold)",
        [(int) $pr['id'], (int) $shopId, $min]);
      json_out(['ok' => true, 'min' => $min,
                'effectif' => $min ?? (int) ws_param('stock.default_min_threshold', '10'),
                'calcul' => $detail]);
    }

    if ($m === 'POST' && $p === '/franchisee/stock-adjust') {
      $b = body();
      if (!$shopId) json_out(['ok' => false, 'error' => 'boutique requise (?shop=)'], 400);
      if (!$tblExists('ws_product_stock') || !$tblExists('ws_products')) json_out(['ok' => false, 'error' => 'tables stock absentes'], 501);
      $pr = $findProduct($b);
      if (!$pr) $prodKo($b);
      $mode = (($b['mode'] ?? '') === 'delivery') ? 'delivery' : 'collect';

      /* DISPONIBLE ABSOLU — {dispo:n}. Les champs de saisie du BO affichent un
         DISPONIBLE (total − réservé − vendu) et envoyaient un ÉCART calculé
         côté navigateur. Cet écart n'a de sens que si la valeur affichée
         correspond à une ligne réelle : quand elle vient du minimum
         hebdomadaire (aucune ligne du jour pour ce canal), la ligne était
         créée à max(0, écart) — taper 8 sur un affichage à 10 posait 0, taper
         12 posait 2. Aucune erreur, une quantité fausse.

         Le disponible voulu est donc posé ICI, où réservé et vendu sont
         connus : qty_total = voulu + réservé + vendu. L'écriture est absolue
         donc idempotente — une valeur affichée périmée ne fabrique plus de
         dérive, contrairement à un écart. Les boutons ± gardent {delta}. */
      if (array_key_exists('dispo', $b) && $b['dispo'] !== '' && $b['dispo'] !== null) {
        $want = max(0, (int) $b['dispo']);
        $cur = row("SELECT qty_reserved, qty_sold FROM ws_product_stock
                     WHERE product_id=? AND shop_id=? AND date=? AND mode=?",
          [(int) $pr['id'], (int) $shopId, $today, $mode]);
        $tot = $want + (int) ($cur['qty_reserved'] ?? 0) + (int) ($cur['qty_sold'] ?? 0);
        q("INSERT INTO ws_product_stock (product_id, shop_id, date, mode, qty_total, qty_reserved, qty_sold, active)
             VALUES (?,?,?,?,?,0,0,1)
             ON DUPLICATE KEY UPDATE qty_total = VALUES(qty_total), active = 1",
          [(int) $pr['id'], (int) $shopId, $today, $mode, $tot]);
        $st0 = row("SELECT qty_total, qty_reserved, qty_sold FROM ws_product_stock
                     WHERE product_id=? AND shop_id=? AND date=? AND mode=?",
          [(int) $pr['id'], (int) $shopId, $today, $mode]);
        json_out(['ok' => true, 'mode' => $mode,
          'total' => (int) ($st0['qty_total'] ?? 0),
          'dispo' => max(0, (int) ($st0['qty_total'] ?? 0) - (int) ($st0['qty_reserved'] ?? 0) - (int) ($st0['qty_sold'] ?? 0))]);
      }

      $delta = (int) ($b['delta'] ?? 0);
      if (!$delta) json_out(['ok' => false, 'error' => 'delta requis (±n) ou dispo (n)'], 400);
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

    // ── RÈGLES RÉSEAU ERP (lecture seule) : portions par produit. ──
    //    product_portion (même base) : tailles ACTIVES proposées au webshop,
    //    visualisées dans l'écran « Règles de prix » du BO franchisé.
    /* ── ÉDITION PONCTUELLE DEPUIS L'ATELIER. ───────────────────────────────
     * POST /franchisee/atelier-save  { objet:'tour'|'postcodes'|'fee'|'office', … }
     *
     * Pourquoi une route à part plutôt que /franchisee/save : ce dernier a une
     * sémantique de REMPLACEMENT (il reçoit la table entière et désactive ce
     * qui manque). L'atelier n'édite qu'UN objet à la fois — lui faire poster
     * une table partielle désactiverait tout le reste. Ici chaque appel touche
     * exactement ce qu'il nomme, et rien d'autre.
     *
     * Portée : tout objet est vérifié comme appartenant à la boutique de la
     * session avant écriture — une console de boutique ne règle pas les
     * tournées ni les bureaux d'une autre. */
    if ($m === 'POST' && $p === '/franchisee/atelier-save') {
      $b = body();
      $objet = (string) ($b['objet'] ?? '');
      $mienne = function ($tid) use ($shopId) {          // tournée de MA boutique ?
        $t = row("SELECT id, shop_id FROM ws_tours WHERE id=?", [(int) $tid]);
        if (!$t) return null;
        if ($shopId && (int) $t['shop_id'] !== (int) $shopId) return null;
        return (int) $t['id'];
      };
      try {
        /* Identité d'une tournée : nom, capacité. La ZONE n'est pas éditée ici —
           elle vit dans ws_delivery_zones et se choisit dans l'écran Zones ;
           l'atelier la montre, il ne la duplique pas. */
        if ($objet === 'tour') {
          $tid = $mienne($b['id'] ?? 0);
          if (!$tid) json_out(['ok' => false, 'error' => 'tournée inconnue ou hors boutique'], 403);
          $nom = trim((string) ($b['name'] ?? ''));
          if ($nom === '') json_out(['ok' => false, 'error' => 'nom requis'], 400);
          $cap = ($b['maxItems'] ?? '') !== '' ? (int) $b['maxItems'] : null;
          q("UPDATE ws_tours SET name=?, max_items=? WHERE id=?", [$nom, $cap, $tid]);
          json_out(['ok' => true]);
        }
        /* Codes postaux d'UNE tournée. Remplacement de SA liste seulement, et
           borné à la chalandise attribuée à la boutique quand elle est connue :
           servir un code postal hors chalandise promettrait une livraison que
           le réseau n'a pas concédée. */
        if ($objet === 'postcodes') {
          if (!$tblExists('ws_tour_postcodes')) json_out(['ok' => false, 'error' => 'table absente'], 400);
          $tid = $mienne($b['tourId'] ?? 0);
          if (!$tid) json_out(['ok' => false, 'error' => 'tournée inconnue ou hors boutique'], 403);
          $pool = [];
          if ($tblExists('ws_franchisor_catchment')) {
            $hasShopC = col_exists('ws_franchisor_catchment', 'shop_id');
            foreach (rows("SELECT postcodes FROM ws_franchisor_catchment WHERE active=1"
                          . ($hasShopC && $shopId ? " AND (shop_id=" . (int) $shopId . " OR shop_id IS NULL)" : "")) as $pr) {
              foreach (preg_split('/[^0-9]+/', (string) $pr['postcodes'], -1, PREG_SPLIT_NO_EMPTY) as $one) $pool[$one] = true;
            }
          }
          $veut = is_array($b['cps'] ?? null) ? $b['cps'] : preg_split('/[^0-9]+/', (string) ($b['cps'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
          $pdo = db(); $pdo->beginTransaction();
          try {
            q("DELETE FROM ws_tour_postcodes WHERE tour_id=?", [$tid]);
            $n = 0; $refuses = [];
            foreach (array_unique(array_map('trim', (array) $veut)) as $cp1) {
              if (!preg_match('/^[0-9]{4}$/', (string) $cp1)) continue;
              if ($pool && !isset($pool[$cp1])) { $refuses[] = $cp1; continue; }
              q("INSERT IGNORE INTO ws_tour_postcodes (tour_id, postcode) VALUES (?,?)", [$tid, $cp1]);
              $n++;
            }
            $pdo->commit();
            json_out(['ok' => true, 'codes' => $n, 'refuses' => $refuses]);
          } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        }
        /* Frais au niveau TOURNÉE (cascade site > bureau > tournée > boutique).
           On n'écrit que la ligne de CETTE tournée ; les autres niveaux sont
           réglés dans l'écran Frais, qui les montre tous ensemble. */
        if ($objet === 'fee') {
          if (!$tblExists('ws_delivery_fee_rules')) json_out(['ok' => false, 'error' => 'table absente'], 400);
          $tid = $mienne($b['tourId'] ?? 0);
          if (!$tid) json_out(['ok' => false, 'error' => 'tournée inconnue ou hors boutique'], 403);
          $forfait = ($b['forfait'] ?? '') !== '' ? (float) $b['forfait'] : 0;
          $franco  = ($b['franco'] ?? '') !== '' ? (float) $b['franco'] : 0;
          $gratuit = !empty($b['gratuit']) ? 1 : 0;
          $ex = row("SELECT id FROM ws_delivery_fee_rules WHERE level='Tournée' AND tour_id=? LIMIT 1", [$tid]);
          if ($ex) q("UPDATE ws_delivery_fee_rules SET fee_amount=?, free_delivery_minimum=?, free_delivery=?, active=1 WHERE id=?",
                     [$forfait, $franco, $gratuit, (int) $ex['id']]);
          else     q("INSERT INTO ws_delivery_fee_rules (level, tour_id, shop_id, fee_amount, free_delivery_minimum, free_delivery, active)
                      VALUES ('Tournée', ?, ?, ?, ?, ?, 1)", [$tid, $shopId ?: null, $forfait, $franco, $gratuit]);
          json_out(['ok' => true]);
        }
        /* Réglages d'UN bureau : tournée, jours autorisés, cut-off propre.
           Les jours vides = « ceux de la tournée » (pas « aucun ») : une liste
           vide écrite telle quelle fermerait le bureau sans que personne l'ait
           demandé. */
        if ($objet === 'office') {
          if (!$tblExists('ws_offices')) json_out(['ok' => false, 'error' => 'table absente'], 400);
          $oid = (int) ($b['officeId'] ?? 0);
          $off = $oid ? row("SELECT id, shop_id FROM ws_offices WHERE id=?", [$oid]) : null;
          if (!$off) json_out(['ok' => false, 'error' => 'bureau inconnu'], 404);
          if ($shopId && col_exists('ws_offices', 'shop_id') && $off['shop_id'] !== null
              && (int) $off['shop_id'] !== (int) $shopId) json_out(['ok' => false, 'error' => 'bureau hors boutique'], 403);
          $tid = ($b['tourId'] ?? '') !== '' ? $mienne($b['tourId']) : null;
          if (($b['tourId'] ?? '') !== '' && !$tid) json_out(['ok' => false, 'error' => 'tournée inconnue ou hors boutique'], 403);
          if (col_exists('ws_offices', 'tour_id')) q("UPDATE ws_offices SET tour_id=? WHERE id=?", [$tid, $oid]);
          if ($tblExists('ws_office_delivery_settings')) {
            $jours = array_values(array_filter(array_map('intval', (array) ($b['days'] ?? [])), fn ($d) => $d >= 1 && $d <= 7));
            $cut = preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) ($b['cutoff'] ?? '')), $mc)
                   ? sprintf('%02d:%02d:00', $mc[1], $mc[2]) : null;
            $sid = $shopId ?: ($off['shop_id'] ?: null);
            $ex = row("SELECT id FROM ws_office_delivery_settings WHERE office_id=?" . ($sid ? " AND shop_id=" . (int) $sid : "") . " LIMIT 1", [$oid]);
            $jsonJ = $jours ? json_encode($jours) : null;
            if ($ex) q("UPDATE ws_office_delivery_settings SET tour_id=?, allowed_days=?, delivery_cutoff=?, active=1 WHERE id=?",
                       [$tid, $jsonJ, $cut, (int) $ex['id']]);
            elseif ($sid) q("INSERT INTO ws_office_delivery_settings (office_id, shop_id, tour_id, allowed_days, delivery_cutoff, active)
                             VALUES (?,?,?,?,?,1)", [$oid, (int) $sid, $tid, $jsonJ, $cut]);
          }
          json_out(['ok' => true]);
        }
        json_out(['ok' => false, 'error' => 'objet inconnu'], 400);
      } catch (Throwable $e) {
        error_log('[bo] atelier-save: ' . $e->getMessage());
        json_out(['ok' => false, 'error' => 'Enregistrement refusé', 'detail' => mb_substr($e->getMessage(), 0, 200)], 500);
      }
    }

    /* ── FENÊTRES D'UNE TOURNÉE, à l'unité (pour l'atelier de paramétrage). ──
     * GET  /franchisee/tour-windows?tourId=  → lignes BRUTES, une par
     *      (jour × fenêtre). L'endpoint historique ws-tour-availability les
     *      AGRÈGE par tournée (GROUP BY) et perd le détail que l'atelier
     *      édite ; les deux coexistent, l'ancien écran garde le sien.
     * POST /franchisee/tour-windows { tourId, fenetres:[…] } → remplace les
     *      fenêtres de CETTE tournée, en une transaction. Ce qui n'est plus
     *      envoyé est DÉSACTIVÉ (active=0), jamais effacé : des commandes
     *      passées peuvent référencer ces lignes. */
    if ($m === 'GET' && $p === '/franchisee/tour-windows') {
      if (!$tblExists('ws_tour_availability') || !$tblExists('ws_tours')) json_out([]);
      $tid = (int) qp('tourId');
      $w = $tid ? ' AND av.tour_id = ' . $tid : '';
      json_out(rows("SELECT av.id, av.tour_id AS tourId, t.name AS tour, av.delivery_day AS day,
                            LOWER(av.window_label) AS label,
                            TIME_FORMAT(av.delivery_start,'%H:%i') AS start,
                            TIME_FORMAT(av.delivery_end,'%H:%i')   AS end,
                            TIME_FORMAT(av.cutoff_time,'%H:%i')    AS cutoff,
                            av.max_orders AS maxOrders, av.max_items AS maxItems
                       FROM ws_tour_availability av JOIN ws_tours t ON t.id = av.tour_id
                      WHERE " . $scope('av.shop_id') . " AND av.active = 1$w
                      ORDER BY t.name, av.delivery_day, av.delivery_start LIMIT 500"));
    }
    if ($m === 'POST' && $p === '/franchisee/tour-windows') {
      if (!$tblExists('ws_tour_availability')) json_out(['ok' => false, 'error' => 'table absente'], 400);
      $b = body();
      $tid = (int) ($b['tourId'] ?? 0);
      if (!$tid) json_out(['ok' => false, 'error' => 'tourId requis'], 400);
      $tr = row("SELECT id, shop_id FROM ws_tours WHERE id=?", [$tid]);
      if (!$tr) json_out(['ok' => false, 'error' => 'tournée inconnue'], 404);
      $sid = (int) $tr['shop_id'];
      if ($shopId && $sid !== (int) $shopId) json_out(['ok' => false, 'error' => "tournée d'une autre boutique"], 403);
      $t2s = fn ($v) => preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) $v), $m2) ? sprintf('%02d:%02d:00', $m2[1], $m2[2]) : null;
      $pdo = db(); $pdo->beginTransaction();
      try {
        $n = 0; $gardes = [];
        foreach ((array) ($b['fenetres'] ?? []) as $f) {
          if (!is_array($f)) continue;
          $d = (int) ($f['day'] ?? 0);
          $lab = in_array(strtolower((string) ($f['label'] ?? '')), ['afternoon', 'soir', 'evening', 'pm'], true) ? 'afternoon' : 'morning';
          $st = $t2s($f['start'] ?? ''); $en = $t2s($f['end'] ?? ''); $cu = $t2s($f['cutoff'] ?? '');
          if ($d < 1 || $d > 7 || !$st || !$en || !$cu) continue;
          // Incohérences REFUSÉES ici aussi : l'atelier les bloque, mais l'API
          // ne doit pas dépendre de son écran pour rester saine.
          if ($cu >= $st || $en <= $st) continue;
          q("INSERT INTO ws_tour_availability
               (tour_id, shop_id, delivery_day, window_label, delivery_start, delivery_end, cutoff_time, max_orders, max_items, active)
             VALUES (?,?,?,?,?,?,?,?,?,1)
             ON DUPLICATE KEY UPDATE delivery_start=VALUES(delivery_start), delivery_end=VALUES(delivery_end),
               cutoff_time=VALUES(cutoff_time), max_orders=VALUES(max_orders), max_items=VALUES(max_items), active=1",
            [$tid, $sid, $d, $lab, $st, $en, $cu,
             ($f['maxOrders'] ?? '') !== '' ? (int) $f['maxOrders'] : null,
             ($f['maxItems'] ?? '') !== '' ? (int) $f['maxItems'] : null]);
          $gardes[] = $d . '|' . $lab; $n++;
        }
        foreach (rows("SELECT id, delivery_day, LOWER(window_label) AS lab FROM ws_tour_availability
                        WHERE tour_id=? AND shop_id=? AND active=1", [$tid, $sid]) as $a2) {
          $k = ((int) $a2['delivery_day']) . '|' . ($a2['lab'] === 'morning' ? 'morning' : 'afternoon');
          if (!in_array($k, $gardes, true)) q("UPDATE ws_tour_availability SET active=0 WHERE id=?", [(int) $a2['id']]);
        }
        $pdo->commit();
        json_out(['ok' => true, 'fenetres' => $n]);
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[bo] tour-windows: ' . $e->getMessage());
        json_out(['ok' => false, 'error' => 'Enregistrement refusé', 'detail' => mb_substr($e->getMessage(), 0, 200)], 500);
      }
    }

    /* ── SIMULATION DE TOURNÉE — « ce que le client verra ». ─────────────────
     * POST /franchisee/tour-simulate
     *   { jours: 7, tourId?: int, officeId?: int,
     *     fenetres: [ { day:1..7, label:'morning'|'afternoon',
     *                   start:'11:30', end:'13:30', cutoff:'09:30' }, … ],
     *     officeDays?: [1,2,4], officeCutoff?: '09:00' }
     *
     * L'atelier de paramétrage envoie son BROUILLON (rien n'est encore
     * enregistré) et reçoit, jour par jour, ce qu'un client verrait — plus les
     * incohérences de configuration. Les règles appliquées sont EXACTEMENT
     * celles de la commande (fuseau boutique, cut-off dépassé aujourd'hui,
     * fermetures ws_tour_closures, restrictions du bureau) : une simulation qui
     * réimplémenterait sa propre logique finirait par mentir, et un écran de
     * réglage qui ment est pire que pas d'écran du tout.
     *
     * `fenetres` omis → on lit celles ENREGISTRÉES de la tournée : le même
     * endpoint sert donc au diagnostic d'une tournée existante. */
    if ($m === 'POST' && $p === '/franchisee/tour-simulate') {
      $b = body();
      $jours = max(1, min(14, (int) ($b['jours'] ?? 7)));
      $tourId = (int) ($b['tourId'] ?? 0);
      $officeId = (int) ($b['officeId'] ?? 0);
      $LBL = ['morning' => 'Midi', 'afternoon' => 'Soirée', 'soir' => 'Soirée', 'evening' => 'Soirée', 'pm' => 'Soirée'];

      // Bureau : ses restrictions PRIMENT sur celles de la tournée (jours
      // autorisés, cut-off propre) — c'est ce que vit son personnel.
      $offDays = null; $offCut = null;
      if (is_array($b['officeDays'] ?? null)) $offDays = array_map('intval', $b['officeDays']);
      if (!empty($b['officeCutoff'])) $offCut = substr((string) $b['officeCutoff'], 0, 5);
      if ($officeId && $offDays === null) {
        try {
          $os = row("SELECT allowed_days, TIME_FORMAT(delivery_cutoff,'%H:%i') AS cut, tour_id
                       FROM ws_office_delivery_settings WHERE office_id=? LIMIT 1", [$officeId]);
          if ($os) {
            $ad = json_decode((string) ($os['allowed_days'] ?? ''), true);
            if (is_array($ad)) $offDays = array_map('intval', $ad);
            if (!empty($os['cut'])) $offCut = $os['cut'];
            if (!$tourId && !empty($os['tour_id'])) $tourId = (int) $os['tour_id'];
          }
        } catch (Throwable $e) { /* table absente : pas de restriction connue */ }
      }
      if ($officeId && !$tourId) {
        $o = row("SELECT tour_id FROM ws_offices WHERE id=?", [$officeId]);
        if ($o && !empty($o['tour_id'])) $tourId = (int) $o['tour_id'];
      }

      // Fenêtres : celles du brouillon, sinon celles enregistrées.
      $wins = [];
      if (is_array($b['fenetres'] ?? null)) {
        foreach ($b['fenetres'] as $w) {
          if (!is_array($w)) continue;
          $d = (int) ($w['day'] ?? 0);
          if ($d < 1 || $d > 7) continue;
          $wins[] = ['day' => $d,
                     'label' => strtolower((string) ($w['label'] ?? 'morning')),
                     'start' => substr((string) ($w['start'] ?? ''), 0, 5),
                     'end' => substr((string) ($w['end'] ?? ''), 0, 5),
                     'cutoff' => substr((string) ($w['cutoff'] ?? ''), 0, 5)];
        }
      } elseif ($tourId) {
        try {
          foreach (rows("SELECT delivery_day AS day, window_label AS label,
                                TIME_FORMAT(delivery_start,'%H:%i') AS start,
                                TIME_FORMAT(delivery_end,'%H:%i')   AS end,
                                TIME_FORMAT(cutoff_time,'%H:%i')    AS cutoff
                           FROM ws_tour_availability
                          WHERE tour_id=? AND active=1 ORDER BY delivery_day, delivery_start", [$tourId]) as $w) {
            $w['label'] = strtolower((string) $w['label']);
            $wins[] = $w;
          }
        } catch (Throwable $e) { /* table absente */ }
      }

      /* ERREURS DE CONFIGURATION — ce qui rend la tournée invendable, dit avec
         le motif et l'endroit. Bloquantes : la publication les refuse. */
      $err = [];
      foreach ($wins as $w) {
        $j = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'][$w['day'] - 1] ?? ('J' . $w['day']);
        $nom = $LBL[$w['label']] ?? $w['label'];
        if ($w['start'] === '' || $w['cutoff'] === '') {
          $err[] = ['day' => $w['day'], 'label' => $w['label'],
                    'msg' => "$j · $nom — heure de livraison ou cut-off manquant."];
          continue;
        }
        if ($w['cutoff'] >= $w['start']) {
          $err[] = ['day' => $w['day'], 'label' => $w['label'],
                    'msg' => "$j · $nom — le cut-off ({$w['cutoff']}) est après le départ ({$w['start']}) : "
                           . "un client pourrait commander une tournée déjà partie."];
        }
        if ($w['end'] !== '' && $w['end'] <= $w['start']) {
          $err[] = ['day' => $w['day'], 'label' => $w['label'],
                    'msg' => "$j · $nom — la fin de livraison ({$w['end']}) n'est pas après le départ ({$w['start']})."];
        }
      }
      if (!$wins) $err[] = ['msg' => "Aucune fenêtre de livraison : la tournée ne serait proposée aucun jour."];
      if ($offDays !== null && $wins) {
        $joursTournee = array_unique(array_column($wins, 'day'));
        $hors = array_values(array_diff($offDays, $joursTournee));
        if ($hors) $err[] = ['msg' => "Le bureau autorise des jours que la tournée ne roule pas ("
                                    . implode(', ', array_map(fn ($d) => ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'][$d - 1] ?? $d, $hors))
                                    . ") — ces jours ne donneront aucun créneau."];
      }

      // Fuseau de la boutique — même résolution que tour_orderable().
      $tzName = 'Europe/Brussels';
      $shopId = 0;
      if ($tourId) { $t = row("SELECT shop_id FROM ws_tours WHERE id=?", [$tourId]); $shopId = (int) ($t['shop_id'] ?? 0); }
      if ($shopId && col_exists('shops', 'timezone')) {
        $tzr = row("SELECT timezone FROM shops WHERE id=?", [$shopId]);
        if ($tzr && !empty($tzr['timezone'])) $tzName = $tzr['timezone'];
      }
      try { $zone = new DateTimeZone($tzName); } catch (Throwable $e) { $zone = new DateTimeZone('Europe/Brussels'); }
      $now = new DateTime('now', $zone);
      $today = $now->format('Y-m-d');
      $nowHm = $now->format('H:i');

      // Fermetures ponctuelles de la tournée (mêmes lignes que la commande).
      $ferme = [];
      if ($tourId) {
        try {
          foreach (rows("SELECT closure_date FROM ws_tour_closures WHERE tour_id=?", [$tourId]) as $c)
            $ferme[(string) $c['closure_date']] = true;
        } catch (Throwable $e) { /* table absente */ }
      }

      $sortie = []; $commandables = 0;
      $errDays = [];
      foreach ($err as $e2) if (isset($e2['day'])) $errDays[$e2['day'] . '|' . ($e2['label'] ?? '')] = $e2['msg'];
      for ($i = 0; $i < $jours; $i++) {
        $d = (clone $now)->modify("+$i day");
        $date = $d->format('Y-m-d');
        $dow = (int) $d->format('N');
        $creneaux = [];
        foreach ($wins as $w) {
          if ($w['day'] !== $dow) continue;
          $soir = in_array($w['label'], ['afternoon', 'soir', 'evening', 'pm'], true);
          $cle = $w['day'] . '|' . $w['label'];
          $ligne = ['type' => $soir ? 'soir' : 'midi', 'nom' => $LBL[$w['label']] ?? $w['label'],
                    'livraison' => $w['start'], 'cutoff' => $w['cutoff']];
          if (isset($errDays[$cle]))            { $ligne['ok'] = false; $ligne['motif'] = $errDays[$cle]; }
          elseif (isset($ferme[$date]))         { $ligne['ok'] = false; $ligne['motif'] = 'Tournée fermée ce jour (exception).'; }
          elseif ($offDays !== null && !in_array($dow, $offDays, true))
                                                { $ligne['ok'] = false; $ligne['motif'] = 'Jour non autorisé pour ce bureau.'; }
          else {
            $cut = ($offCut !== null && $offCut < $w['cutoff']) ? $offCut : $w['cutoff'];   // la plus stricte gagne
            $ligne['cutoff'] = $cut;
            if ($date === $today && $nowHm >= $cut) { $ligne['ok'] = false; $ligne['motif'] = "Cut-off dépassé (il est $nowHm)."; }
            else { $ligne['ok'] = true; $commandables++; }
          }
          $creneaux[] = $ligne;
        }
        $sortie[] = ['date' => $date,
                     'jour' => ['Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche'][$dow - 1],
                     'creneaux' => $creneaux];
      }

      json_out([
        'jours' => $sortie,
        'erreurs' => array_values(array_map(fn ($e2) => $e2['msg'], $err)),
        'commandables' => $commandables,
        // Publication autorisée : aucune erreur ET au moins un créneau
        // réellement commandable sur la période — une tournée que personne ne
        // peut commander n'est pas une tournée publiée, c'est un piège.
        'publiable' => !$err && $commandables > 0,
        'fuseau' => $tzName,
        'maintenant' => $nowHm,
      ]);
    }

    if ($m === 'GET' && $p === '/franchisee/erp-portion-rules') {
      /* SOURCE : L'ENDPOINT. Remplace une lecture de product_portion jointe à
         ws_products. La liste des produits portionnables VIENT désormais de la
         réponse de la boutique : un produit que l'ERP ne sert plus n'a pas à
         figurer dans cet écran, alors que la jointure locale l'y laissait tant
         que ws_products le gardait actif. */
      if (!$shopId || !function_exists('erp_portions_reseau')) json_out([]);
      $donnees = erp_portions_reseau([(int) $shopId]);
      if (!is_array($donnees)) json_out([]);       // ERP muet : rien d'inventé
      $ids3  = array_keys($donnees);
      $erpPx = prix_produits($ids3, (int) $shopId);
      $eur   = fn ($v) => number_format((float) $v, 2, ',', ' ') . ' €';
      $out = [];
      foreach ($donnees as $pid => $v) {
        /* Le prix de la pièce entière passe par prix_produits() — LA fonction
           qui décide aussi ce qui sera débité. Deux chemins de prix pour le
           même produit, c'est l'écart entre l'annonce et la facture. */
        $base = $erpPx[$pid] ?? $v['prix_piece'];
        $offerts = []; $sans = []; $labels = ['Entière'];
        foreach ($v['portions'] as $q) {
          $px = $q['prix'][(int) $shopId] ?? null;
          if ($px !== null) { $offerts[] = $q['label'] . ' ' . $eur($px); $labels[] = $q['label']; }
          else              { $sans[] = $q['label']; }
        }
        $prix = implode(' · ', array_merge(
          ['Entière ' . ($base !== null ? $eur($base) : 'sans prix')], $offerts));
        if ($sans) $prix .= ' · sans prix boutique : ' . implode(', ', $sans);
        $out[] = ['produit' => $v['produit'], 'cat' => $v['cat'], 'portions' => $labels,
                  'facteurs' => 'prix boutique ERP (endpoint portions)', 'prix' => $prix];
      }
      json_out($out);
    }

    // ── Minimums hebdomadaires : lecture par produit (formulaire BO). ──
    if ($m === 'GET' && $p === '/franchisee/stock-defaults') {
      if (!$shopId) json_out(['ok' => false, 'error' => 'boutique requise (?shop=)'], 400);
      if (!$tblExists('ws_product_stock_defaults') || !$tblExists('ws_products')) json_out(['ok' => true, 'days' => (object) []]);
      $pr = $findProduct(['productId' => qp('productId'), 'product' => qp('product')]);
      if (!$pr) $prodKo(['product' => qp('product')]);
      $days = [];
      foreach (rows("SELECT weekday, mode, qty FROM ws_product_stock_defaults
                      WHERE shop_id=? AND product_id=?", [(int) $shopId, (int) $pr['id']]) as $r) {
        $k = (string) (int) $r['weekday'];
        if (!isset($days[$k])) $days[$k] = ['collect' => null, 'delivery' => null];
        $days[$k][$r['mode'] === 'delivery' ? 'delivery' : 'collect'] = (int) $r['qty'];
      }
      json_out(['ok' => true, 'days' => $days ?: (object) []]);
    }

    // ── Minimums hebdomadaires : écriture — {product, days:{1..7:{collect,delivery}}}.
    //    Valeur numérique = upsert ; vide/null = suppression du couple jour×canal.
    if ($m === 'POST' && $p === '/franchisee/stock-defaults') {
      $b = body();
      if (!$shopId) json_out(['ok' => false, 'error' => 'boutique requise (?shop=)'], 400);
      if (!$tblExists('ws_product_stock_defaults') || !$tblExists('ws_products'))
        json_out(['ok' => false, 'error' => 'ws_product_stock_defaults absente — migration 0036 non appliquée'], 500);
      $pr = $findProduct($b);
      if (!$pr) $prodKo($b);
      $days = is_array($b['days'] ?? null) ? $b['days'] : [];
      $n = 0;
      foreach ($days as $wd => $vals) {
        $wd = (int) $wd; if ($wd < 1 || $wd > 7 || !is_array($vals)) continue;
        foreach (['collect' => 'collect', 'delivery' => 'delivery'] as $key3 => $mode3) {
          if (!array_key_exists($key3, $vals)) continue;
          $v3 = $vals[$key3];
          if ($v3 === '' || $v3 === null) {
            q("DELETE FROM ws_product_stock_defaults WHERE shop_id=? AND product_id=? AND weekday=? AND mode=?",
              [(int) $shopId, (int) $pr['id'], $wd, $mode3]);
          } else {
            q("INSERT INTO ws_product_stock_defaults (shop_id, product_id, weekday, mode, qty)
                 VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE qty=VALUES(qty)",
              [(int) $shopId, (int) $pr['id'], $wd, $mode3, max(0, (int) $v3)]);
            $n++;
          }
        }
      }
      json_out(['ok' => true, 'n' => $n]);
    }

    if ($m === 'GET' && $p === '/franchisee/fr-stock-catalog') {
      // Base du Stock du jour = TOUS les produits actifs (ws_products.active=1)
      // repris dans l'assortiment webshop de la boutique (ws_product_shops
      // actif ou sans surcharge) — les quantités du jour viennent de
      // ws_product_stock quand elles existent, sinon 0.
      if (!$tblExists('ws_products')) json_vide(['ws_products']);
      $hasStock = $tblExists('ws_product_stock');
      $hasPS = $shopId && $tblExists('ws_product_shops');
      // SUM sans ELSE : « aucune ligne du jour pour ce canal » = NULL (et non
      // 0) — on peut alors retomber sur le MINIMUM hebdomadaire du produit.
      $rs = rows("SELECT c.label AS cat, pr.id AS pid, pr.name," .
                 ($hasStock
                   ? " SUM(CASE WHEN st.mode='delivery' THEN st.qty_total - st.qty_reserved - st.qty_sold END) AS online,
                       SUM(CASE WHEN st.id IS NOT NULL AND (st.mode IS NULL OR st.mode<>'delivery') THEN st.qty_total - st.qty_reserved - st.qty_sold END) AS shopq"
                   : " NULL AS online, NULL AS shopq") . "
                    FROM ws_products pr
                    /* LEFT JOIN, et SANS filtre c.active : le cache
                       ws_categories.active est resynchronisé par certaines
                       écritures seulement — la cascade de sous-catégorie l'a
                       oublié un temps, et 33 produits Traiteur actifs sont
                       restés INVISIBLES ici pendant qu'ils étaient au BO
                       marque et sur le webshop (14/08). L'écran Stock du jour
                       liste ce qui est VENDU (pr.active), pas ce que dit un
                       cache — même correction que fr-assortiment avant lui. */
                    LEFT JOIN ws_categories c ON c.id = pr.cat_id" .
                 ($hasPS ? " LEFT JOIN ws_product_shops ps ON ps.product_id = pr.id AND ps.shop_id = " . (int) $shopId : "") .
                 ($hasStock ? " LEFT JOIN ws_product_stock st ON st.product_id = pr.id AND st.date = ? AND st.active = 1" . ($shopId ? " AND st.shop_id = " . (int) $shopId : "") : "") . "
                   WHERE pr.active = 1
                   GROUP BY c.sort_order, c.label, pr.id, pr.name
                   ORDER BY c.sort_order, c.label, pr.name LIMIT 400", $hasStock ? [$today] : []);
      // Minimums hebdomadaires du JOUR (1=lundi … 7=dimanche) par produit.
      $defs = [];
      if ($shopId && $tblExists('ws_product_stock_defaults')) {
        foreach (rows("SELECT product_id, mode, qty FROM ws_product_stock_defaults
                        WHERE shop_id=? AND weekday=?", [(int) $shopId, (int) date('N')]) as $d) {
          $defs[(int) $d['product_id']][$d['mode'] === 'delivery' ? 'delivery' : 'collect'] = (int) $d['qty'];
        }
      }
      // Seuils d'alerte propres a la boutique (migration 0054). NULL/absent =>
      // le parametre global s'applique, comme avant.
      $mins = [];
      if ($shopId && $hasPS && col_exists('ws_product_shops', 'min_threshold')) {
        foreach (rows("SELECT product_id, min_threshold FROM ws_product_shops
                        WHERE shop_id=? AND min_threshold IS NOT NULL", [(int) $shopId]) as $mt) {
          $mins[(int) $mt['product_id']] = (int) $mt['min_threshold'];
        }
      }
      $minGlobal = (int) ws_param('stock.default_min_threshold', '10');
      $cats = [];
      foreach ($rs as $r) {
        $cat = $r['cat'] ?: 'Autres';
        $pid = (int) $r['pid'];
        $cats[$cat]['cat'] = $cat;
        $cats[$cat]['prods'][] = ['id' => $pid, 'nom' => $r['name'],
          'online' => $r['online'] !== null ? max(0, (int) $r['online']) : (int) ($defs[$pid]['delivery'] ?? 0),
          'shop' => $r['shopq'] !== null ? max(0, (int) $r['shopq']) : (int) ($defs[$pid]['collect'] ?? 0),
          // Seuil EFFECTIF : celui de la boutique s'il existe, sinon le global.
          'min' => $mins[$pid] ?? $minGlobal,
          'minOwn' => isset($mins[$pid])];
      }
      json_out(array_values($cats));
    }

    // ── Assortiment par boutique : ROUTE FERMÉE. ──
    //    Elle basculait ws_product_shops.active, par produit ou par catégorie.
    //    La lecture reste ouverte : GET /franchisee/fr-assortiment, juste après.
    if ($m === 'POST' && $p === '/franchisee/assortiment-toggle') {
      /* QUI DÉCIDE — RÈGLE MÉTIER, PAS UN DÉTAIL D'ÉCRAN.
         La MARQUE choisit l'assortiment : quels produits sont disponibles et
         vendus, dans chaque boutique. Le FRANCHISÉ ne saisit que les QUANTITÉS
         produites (ws_product_stock, routes /franchisee/stock-*).

         Cette route donnait au franchisé la main sur la disponibilité. Elle est
         fermée, et elle DIT pourquoi plutôt que de rendre 404 : retirer la
         bascule de l'écran sans fermer la route aurait laissé le levier ouvert
         à qui appelle l'API directement — un onglet resté ouvert sur l'ancienne
         version suffisait. */
      json_out(['ok' => false, 'error' =>
        'L’assortiment est décidé par la marque. Une boutique ne choisit pas ce qu’elle vend ; '
        . 'elle saisit les quantités produites (Stock du jour).'], 403);
    }

    // Assortiment — ws_products × ws_product_shops (actif / sans livraison / verrou marque).
    if ($m === 'GET' && $p === '/franchisee/fr-assortiment') {
      if (!$tblExists('ws_products')) json_vide(['ws_products']);
      $hasPS = $shopId && $tblExists('ws_product_shops');
      // MÊME périmètre que le back-office franchisor : uniquement les
      // catégories ACTIVES (ws_categories.active=1) — une catégorie retirée
      // côté marque (ex. « B2B ») ne doit pas réapparaître ici via ses produits.
      // TRANSPORT de la règle marque : un produit OBLIGATOIRE arrive ici quel
      // que soit son canal (webshop et/ou livraison bureau) et reste VERROUILLÉ
      // — le franchisé ne peut retirer que des produits NON obligatoires
      // (assortiment-toggle refuse les obligatoires côté serveur).
      $hasSub = $tblExists('ws_category_subs');
      /* LEFT JOIN, et non JOIN … AND c.active = 1.
         Avec la jointure stricte, un produit dont la catégorie est désactivée
         ou dont le cat_id ne pointe sur rien DISPARAISSAIT de cet écran —
         pendant qu'il restait EN VENTE sur le webshop, dont la grille ne
         contrôle que le produit. Le franchisé vendait donc des articles qu'il
         ne pouvait ni voir ni retirer. Un écran d'assortiment doit lister tout
         ce qui est réellement en vente, quitte à le signaler. */
      $rs = rows("SELECT pr.id AS pid, pr.name, c.label AS cat,
                         pr.active AS ws_on, COALESCE(pr.office_delivery,1) AS od_on" .
                 ($hasSub ? ", sc2.label AS sub" : ", NULL AS sub") .
                 ($hasPS ? ", ps.active AS ps_active, ps.no_delivery" : ", NULL AS ps_active, NULL AS no_delivery") . "
                    FROM ws_products pr LEFT JOIN ws_categories c ON c.id = pr.cat_id" .
                 ($hasSub ? " LEFT JOIN ws_category_subs sc2 ON sc2.id = pr.sub_cat_id" : "") .
                 ($hasPS ? " LEFT JOIN ws_product_shops ps ON ps.product_id = pr.id AND ps.shop_id = " . (int) $shopId : "") . "
                   WHERE pr.active = 1
                   ORDER BY c.sort_order, c.label, " . ($hasSub ? "COALESCE(sc2.sort_order, 999), sc2.label, " : "") . "pr.name LIMIT 400");
      // NON TARIFÉ = ws_products.price <= 0. Ces produits sont hors vente —
      // masqués du catalogue et refusés à la commande — mais ils
      // DISPARAISSAIENT sans explication de l'écran du franchisé. On expose
      // l'état pour qu'il le voie ; ils redeviennent vendables dès qu'un prix
      // est posé, sans bascule manuelle, donc sans exclusion persistante.
      //
      // L'ERP n'est plus interrogé ici : la table du webshop est ws_products,
      // et elle seule. shop_product appartenait à une autre application, était
      // lue par boutique alors que les produits sont communs, et référence des
      // produits qui n'existent pas côté webshop.
      $tarife = [];
      if ($rs) {
        foreach (prix_produits(array_map(static fn ($r) => (int) $r['pid'], $rs), $shopId ? (int) $shopId : null) as $pid4 => $px4)
          $tarife[$pid4] = true;
      }
      /* LE VERDICT, pas seulement les ingrédients. L'écran montrait déjà
         « non tarifé » et le canal, mais jamais la réponse à la seule question
         qu'on se pose devant lui : ce produit est-il EN LIGNE, et sinon
         pourquoi ? Six conditions décident, et les compter de tête devant un
         tableau de 400 lignes n'est pas un travail. product_visibilite() les
         énumère en un seul endroit ; le test visibilite_test.php vérifie
         qu'elle dit la même chose que le catalogue servi. */
      $vis = $shopId ? product_visibilite($shopId, array_map(fn ($r) => (int) $r['pid'], $rs), '', null) : [];
      json_out(array_map(function ($r) use ($tarife, $shopId, $vis) {
        $pid = (int) $r['pid'];
        $nonTarife = $shopId ? !isset($tarife[$pid]) : false;
        $v = $vis[$pid] ?? null;
        return ['id' => $pid, 'nom' => $r['name'], 'cat' => $r['cat'] ?: '—',
        'sub' => $r['sub'] ?: null,
        'canal' => ((int) $r['ws_on'] && (int) $r['od_on']) ? 'Webshop + Livraison'
                 : ((int) $r['ws_on'] ? 'Webshop' : 'Livraison bureau'),
        // Un obligatoire est TOUJOURS actif chez le franchisé (verrou marque).
        // « Actif » à l'écran de l'assortiment : la colonne est en lecture
        // seule depuis que la marque décide seule, et brand_mandatory n'existe
        // plus. Reste ce que dit ws_product_shops, ou vrai par défaut.
        'defA' => $r['ps_active'] !== null ? (bool) $r['ps_active'] : true,
        'defND' => $r['no_delivery'] !== null ? (bool) $r['no_delivery'] : false,
        'nonTarife' => $nonTarife,
        'etatVente' => $nonTarife ? 'Non tarifé — hors vente' : 'Tarifé',
        // null quand aucune boutique n'est en portée : on ne tranche pas sans
        // savoir de quelle boutique on parle.
        'enLigne' => $v ? $v['enLigne'] : null,
        'raison'  => $v ? $v['raison'] : null,
        // EN VENTE mais INTROUVABLE dans la navigation du site : catégorie
        // désactivée, ou cat_id qui ne pointe sur rien. Deux faits distincts
        // du verdict de vente, et qui se corrigent ailleurs.
        'navigation' => $v ? ($v['navigation'] ?? null) : null];
      }, $rs));
    }

    // Dispo par catégorie — ws_categories (délai/cut-off par défaut ws_param).
    if ($m === 'GET' && $p === '/franchisee/fr-dispo-cats') {
      if (!$tblExists('ws_categories')) json_vide(['ws_categories']);
      $rs = rows("SELECT slug, label, active FROM ws_categories WHERE " . $scope('shop_id') . " OR shop_id IS NULL ORDER BY sort_order, label LIMIT 50");
      $cut = ws_param('order.cutoff_default', '17:00');
      // Le délai par catégorie n'a pas de source en base : on renvoie la valeur
      // de configuration si elle existe, sinon null (« non renseigné »). L'ancien
      // '1' en dur affichait un délai identique inventé pour CHAQUE catégorie.
      $delai = ws_param('order.lead_days_default', null);
      json_out(array_map(fn ($r) => ['key' => $r['slug'] ?: $r['label'], 'nom' => $r['label'],
        'delai' => $delai, 'cut' => $cut, 'def' => (bool) $r['active']], $rs));
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

      /* Remplacement intégral d'une table de config, EN TRANSACTION.
         q() est en autocommit : un DELETE suivi d'INSERT qui échouent laissait
         la table vide. C'est ainsi que b2b_client_company_department a été
         perdue. Le motif est le même ici, donc le garde-fou l'est aussi :
         soit le remplacement aboutit entièrement, soit rien ne bouge. */
      $replaceAll = function (string $table, array $rows3, callable $insert) {
        $pdoR = db();
        $own  = !$pdoR->inTransaction();
        if ($own) $pdoR->beginTransaction();
        try {
          q("DELETE FROM $table");
          foreach ($rows3 as $r3) $insert($r3);
          if ($own) $pdoR->commit();
        } catch (Throwable $e) {
          if ($own && $pdoR->inTransaction()) $pdoR->rollBack();
          json_out(['ok' => false, 'mode' => 'typed', 'error' =>
            "Écriture de $table annulée (aucune donnée perdue) : " . $e->getMessage()], 500);
        }
      };

      // Tables de config à remplacement intégral (petites, non référencées).
      if ($tbl === 'ws_franchisor_catchment' && $tblExists('ws_franchisor_catchment')) {
        $replaceAll('ws_franchisor_catchment', $rows2, function ($r) {
          q("INSERT INTO ws_franchisor_catchment (name, postcodes, exclusive, active) VALUES (?,?,?,1)",
            [(string) ($r['name'] ?? '—'), (string) ($r['cp'] ?? ''), !empty($r['exclusif']) ? 1 : 0]);
        });
        json_out(['ok' => true, 'mode' => 'typed', 'n' => count($rows2)]);
      }
      /* Départements B2B — table ERP, dont le schéma varie (id_client vs
         client_id, colonnes annexes présentes ou non). L'écriture était un
         DELETE INTÉGRAL non scopé suivi d'INSERT en colonnes codées en dur, le
         tout hors transaction : sur un schéma qui ne correspondait pas, le
         DELETE passait, l'INSERT échouait en 500 — et la table ERP se
         retrouvait VIDE. Un écran de back-office ne doit jamais pouvoir
         détruire une table du réseau parce qu'une colonne manque.

         Trois garde-fous : les colonnes écrites sont celles qui existent
         RÉELLEMENT, l'incompatibilité est refusée AVANT le moindre DELETE, et
         l'ensemble tient dans une transaction — un INSERT qui échoue restitue
         l'état d'origine. */
      if ($tbl === 'b2b_client_company_department') {
        json_out(['ok' => false, 'mode' => 'refused', 'error' =>
          'Les départements B2B ne s’écrivent pas depuis l’overlay du back-office : '
          . 'la table ERP est rattachée à un client par id_client (entier), alors que le BO '
          . 'ne dispose que d’un code local inventé. Ils sont écrits par /franchisee/onboard-office, '
          . 'rattachés au client réellement créé.'], 409);
      }
      if ($tbl === 'ws_tour_closures' && $tblExists('ws_tour_closures')) {
        // DELETE scopé BOUTIQUE : ne jamais effacer les fermetures des autres
        // franchisés (les lignes « toutes tournées », tour_id NULL, restent
        // gérées par la boutique courante).
        // Et EN TRANSACTION : un INSERT qui échoue après le DELETE effaçait
        // silencieusement toutes les fermetures de la boutique — un jour de
        // fermeture perdu, c'est une tournée qui roule un jour férié.
        $hasCType = col_exists('ws_tour_closures', 'closure_type');
        $pdoC = db();
        $ownC = !$pdoC->inTransaction();
        if ($ownC) $pdoC->beginTransaction();
        try {
          if ($shopId && $tblExists('ws_tours'))
            q("DELETE cl FROM ws_tour_closures cl LEFT JOIN ws_tours t ON t.id = cl.tour_id
                WHERE t.shop_id = " . (int) $shopId . " OR cl.tour_id IS NULL");
          else q("DELETE FROM ws_tour_closures");
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
          if ($ownC) $pdoC->commit();
        } catch (Throwable $e) {
          if ($ownC && $pdoC->inTransaction()) $pdoC->rollBack();
          json_out(['ok' => false, 'mode' => 'typed', 'error' =>
            'Écriture de ws_tour_closures annulée (aucune fermeture perdue) : ' . $e->getMessage()], 500);
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
            // Activation de la tournée. Une tournée en préparation ou suspendue
            // ne doit plus être proposée nulle part (sites, bureaux, webshop),
            // sans être supprimée : la supprimer perdrait ses codes postaux,
            // ses horaires et son historique.
            if (array_key_exists('active', $r)) { $fvSets[] = 'active=?'; $fvVals[] = !empty($r['active']) ? 1 : 0; }
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
          // Temps d'accès : NULL quand il n'est pas fourni — pas un 10 min
          // inventé. La clé peut arriver présente mais null (« pas saisi ») :
          // array_key_exists + valeur non-null exigés pour écrire un nombre.
          $accRaw = array_key_exists('acc', $r) ? $r['acc'] : ($r['site_access_minutes'] ?? null);
          $acc   = ($accRaw === null || $accRaw === '') ? null : (float) $accRaw;
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
          // Ordre dans la tournée (1, 2, 3…) : tournee_stop_id round-trippé.
          $hasStopCol = col_exists('ws_office_delivery_sites', 'tournee_stop_id');
          $stopVal = (array_key_exists('stop', $r) && is_numeric($r['stop'])) ? (int) $r['stop'] : null;
          $stopClr = array_key_exists('stop', $r) && $r['stop'] === null;
          if ($ex) {
            $tourSql = $tourId !== null ? "tournee_id=" . (int) $tourId : ($tourCleared ? "tournee_id=NULL" : "tournee_id=tournee_id");
            $stopSql = $hasStopCol ? ($stopVal !== null ? ", tournee_stop_id=" . $stopVal : ($stopClr ? ", tournee_stop_id=NULL" : "")) : "";
            q("UPDATE ws_office_delivery_sites SET name=?, address=?, floor_room=?, contact_name=?, contact_phone=?,
                 site_access_minutes=?, $tourSql, office_client_id=COALESCE(?, office_client_id), active=1$stopSql" .
                 ($shopId ? ", shop_id=" . (int) $shopId : "") . " WHERE id=?",
              [$name ?: null, $addr ?: null, $floor ?: null, $cn ?: null, $cp ?: null, $acc, $officeId, (int) $ex['id']]);
            $keptIds[] = (int) $ex['id']; $n++;
          } elseif ($name !== '' || $addr !== '' || $officeId) {
            q("INSERT INTO ws_office_delivery_sites (office_client_id, name, address, floor_room, contact_name, contact_phone, site_access_minutes, tournee_id, shop_id, active" . ($hasStopCol ? ", tournee_stop_id" : "") . ")
                 VALUES (?,?,?,?,?,?,?,?,?,1" . ($hasStopCol ? ",?" : "") . ")",
              array_merge([$officeId, $name ?: null, $addr ?: null, $floor ?: null, $cn ?: null, $cp ?: null, $acc, $tourId, $shopId], $hasStopCol ? [$stopVal] : []));
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
          // PORTÉE BOUTIQUE. Sans ce filtre, une correspondance par TVA ou
          // raison sociale rattrapait le client d'une AUTRE boutique et
          // écrasait son adresse / sa raison — un franchisé écrivait chez un
          // autre. Même règle que $clientGuard : ce qui n'est pas visible
          // n'est pas modifiable (réseau = $shopId nul = pas de filtre).
          $cScope = ($shopId && $clientShopCol) ? " AND $clientShopCol=" . (int) $shopId : "";
          if ($tva !== '' && col_exists('client', 'tax_number'))
            $ex = row("SELECT id FROM client WHERE REPLACE(REPLACE(REPLACE(UPPER(COALESCE(tax_number,'')),'.',''),' ',''),'-','')=?$cScope LIMIT 1", [$tva]);
          if (!$ex && $rais !== '' && col_exists('client', 'company_name'))
            $ex = row("SELECT id FROM client WHERE TRIM(COALESCE(company_name,''))=?$cScope LIMIT 1", [$rais]);
          $sets = []; $uv = [];
          if ($rais !== '' && col_exists('client', 'company_name')) { $sets[] = 'company_name=?'; $uv[] = $rais; }
          if ($tva !== '' && col_exists('client', 'tax_number'))    { $sets[] = 'tax_number=?';   $uv[] = $tva; }
          foreach (['adresse' => 'street', 'cp' => 'zip', 'ville' => 'city'] as $fk => $col) {
            if (!empty($r[$fk]) && col_exists('client', $col)) { $sets[] = "$col=?"; $uv[] = trim((string) $r[$fk]); }
          }
          if (col_exists('client', 'is_b2b')) $sets[] = 'is_b2b=1';
          // Conditions commerciales B2B (colonnes 0084) : chacune écrite si la
          // clé est fournie — plus de perte silencieuse.
          $b2bNum = function ($v) { $v = trim((string) $v); if ($v === '') return null; $v = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', $v)); return is_numeric($v) ? (float) $v : null; };
          foreach ([['seg','b2b_segment','txt'],['paiement','b2b_payment_terms','txt'],
                    ['plafond','b2b_credit_ceiling','num'],['remise','b2b_web_discount','num'],
                    ['remiseWeb','b2b_web_discount','num'],['franco','b2b_franco','txt']] as $mB) {
            if (!array_key_exists($mB[0], $r) || !col_exists('client', $mB[1])) continue;
            $sets[] = $mB[1] . '=?';
            $uv[] = $mB[2] === 'num' ? $b2bNum($r[$mB[0]]) : (trim((string) $r[$mB[0]]) ?: null);
          }
          if ($ex) {
            if ($sets) { $uv[] = (int) $ex['id']; q("UPDATE client SET " . implode(',', $sets) . " WHERE id=?", $uv); }
            $n++;
          } else {
            $cols = ['id_main_shop', 'name', 'zip', 'city', 'street', 'active', 'source_channel', 'webshop_user'];
            $iv = [(int) ($shopId ?: 0), $rais ?: 'Client B2B', trim((string) ($r['cp'] ?? '')),
                   trim((string) ($r['ville'] ?? '')), trim((string) ($r['adresse'] ?? '')), 1, 'webshop', 0];
            if (col_exists('client', 'preferred_shop_id')) { $cols[] = 'preferred_shop_id'; $iv[] = (int) ($shopId ?: 0); }
            if (col_exists('client', 'company_name'))    { $cols[] = 'company_name';    $iv[] = $rais ?: null; }
            if ($tva !== '' && col_exists('client', 'tax_number')) { $cols[] = 'tax_number'; $iv[] = $tva; }
            if (col_exists('client', 'is_b2b'))          { $cols[] = 'is_b2b';          $iv[] = 1; }
            if (col_exists('client', 'office_delivery')) { $cols[] = 'office_delivery'; $iv[] = 1; }
            if (col_exists('client', 'status'))          { $cols[] = 'status';          $iv[] = 1; }
            foreach ([['seg','b2b_segment','txt'],['paiement','b2b_payment_terms','txt'],
                      ['plafond','b2b_credit_ceiling','num'],['remiseWeb','b2b_web_discount','num'],
                      ['remise','b2b_web_discount','num'],['franco','b2b_franco','txt']] as $mB) {
              if (!array_key_exists($mB[0], $r) || !col_exists('client', $mB[1]) || in_array($mB[1], $cols, true)) continue;
              $cols[] = $mB[1];
              $iv[] = $mB[2] === 'num' ? $b2bNum($r[$mB[0]]) : (trim((string) $r[$mB[0]]) ?: null);
            }
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
        // Logo du bureau (0115) : contrôlé AVANT la transaction — un logo
        // refusé ne doit pas laisser une sauvegarde à moitié faite.
        foreach ($rows2 as $r0) { $le = office_logo_check(is_array($r0) ? ($r0['logo_url'] ?? null) : null); if ($le !== null) json_out(['ok' => false, 'error' => $le], 400); }
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
            if (array_key_exists('logo_url', $r)) office_logo_apply($rid, $r['logo_url']);
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
            if (array_key_exists('logo_url', $r)) office_logo_apply($rid, $r['logo_url']);
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
            // La TOURNÉE de la liaison bureau↔site : celle du bureau si posée,
            // SINON celle du BÂTIMENT (une autre ligne active à la même adresse
            // qui en a une — le site créé dans l'écran Sites porte la tournée,
            // la ligne de liaison du bureau doit en HÉRITER, sinon la commande
            // du bureau n'est rattachable à aucune tournée au back-office).
            $bldg = row("SELECT name, tournee_id FROM ws_office_delivery_sites
                          WHERE active=1 AND tournee_id IS NOT NULL AND $normAdrSql=?
                          ORDER BY id LIMIT 1", [$normSite]);
            if ($pairTour === null && $bldg) $pairTour = (int) $bldg['tournee_id'];
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
                  [$rid, ($bldg['name'] ?? null) ?: (($name !== '' ? $name : 'Bureau') . ' @ ' . mb_substr($siteAdr, 0, 80)), $siteAdr, $pairTour, 6]);
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
        // LISTE BLANCHE. ws_param est GLOBAL (pas de colonne boutique) : sans
        // filtre, une session boutique (PIN admin_boutique) pouvait écrire
        // n'importe quelle clé de configuration réseau. On n'accepte que les
        // familles réellement éditées par la console franchisé ; toute autre
        // clé est refusée visiblement plutôt qu'écrite en silence.
        $allowPrefix = ['cost_', 'bo_show_', 'remise.', 'stock.', 'stripe.',
                        'order.', 'pwa_', 'pay.', 'nav.', 'delivery.'];
        $n = 0; $refused = [];
        foreach ($rows2 as $r) {
          $cle = trim((string) ($r['cle'] ?? ''));
          if ($cle === '' || strlen($cle) > 100) continue;
          $ok = false; foreach ($allowPrefix as $pre) if (strncmp($cle, $pre, strlen($pre)) === 0) { $ok = true; break; }
          if (!$ok) { $refused[] = $cle; continue; }
          $val = $r['val'] ?? ($r['def'] ?? '');
          if (is_bool($val)) $val = $val ? '1' : '0';
          q("INSERT INTO ws_param (param_key, param_value) VALUES (?,?)
               ON DUPLICATE KEY UPDATE param_value = VALUES(param_value)", [$cle, (string) $val]);
          $n++;
        }
        if ($refused)
          json_out(['ok' => false, 'error' => 'Paramètre(s) hors périmètre boutique refusé(s) : ' . implode(', ', $refused), 'n' => $n], 403);
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
      $obLogoErr = office_logo_check($b['logo'] ?? null);
      if ($obLogoErr !== null) json_out(['error' => $obLogoErr], 400);
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
      /* Tournée OBLIGATOIRE, et RÉSOLUE. Le nom arrivait du formulaire ; s'il ne
         correspondait à aucune tournée, $tourId restait null et le bureau était
         créé quand même — non livrable, sans que rien ne le signale. Le client
         voyait « En attente de validation » sans cause visible. On refuse
         désormais avant toute écriture. */
      $tourId = null;
      $tourWanted = trim((string) ($b['tour'] ?? ''));
      if ($tourWanted === '')
        json_out(['error' => 'Tournée rattachée requise — un bureau sans tournée n\'est pas livrable.'], 400);
      if ($tblExists('ws_tours')) {
        $tr = row("SELECT id FROM ws_tours WHERE name=? LIMIT 1", [$tourWanted]);
        if ($tr) $tourId = (int) $tr['id'];
      }
      if (!$tourId)
        json_out(['error' => 'Tournée « ' . $tourWanted . ' » introuvable — choisissez une tournée existante.'], 409);
      /* Le bureau naît NON VALIDÉ. Il était créé d'office en status='validated'
         et active=1 : un bureau ouvert à la livraison dès l'enregistrement,
         quels que soient les champs restés vides — et notamment quand il est
         créé à la volée depuis une demande de rattachement client, où l'on ne
         connaît souvent que le nom et un téléphone.
         La validation est la porte qui ouvre la livraison bureau : c'est
         /delivery-fees/sites qui la contrôle. La franchir automatiquement à la
         création la vide de son sens. Elle redevient un geste explicite du
         franchisé, une fois la fiche complète. */
      q("INSERT INTO ws_offices (tour_id, name, address, postal_code, city, contact, email, phone, vat, status, deferred_billing_enabled, drop_minutes, active" . ($obShop ? ", shop_id" : "") . ")
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0" . ($obShop ? "," . (int) $obShop : "") . ")",
        [$tourId, $raison, (string) ($b['adr'] ?? ''), $obZip, $obLoc, (string) ($b['contactNom'] ?? ''),
         (string) ($b['contactEmail'] ?? ''), (string) ($b['contactTel'] ?? ''), (string) ($b['tva'] ?? ''),
         'pending', (stripos((string) ($b['paiement'] ?? ''), 'compt') === false) ? 1 : 0,
         (float) ($b['drop'] ?? 5)]);
      $officeId = (int) db()->lastInsertId();
      if (!empty($b['logo'])) office_logo_apply($officeId, (string) $b['logo']);
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
        $addC('preferred_shop_id', (int) ($obShop ?: 0));
        // Conditions commerciales B2B — enfin persistées (colonnes 0084) :
        // segment, paiement, plafond, remise webshop, franco. NULL quand vide
        // (« non renseigné » est licite ; l'écran affiche « — »).
        $b2bNum = function ($v) { $v = trim((string) $v); if ($v === '') return null; $v = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', $v)); return is_numeric($v) ? (float) $v : null; };
        $addC('b2b_segment',        trim((string) ($b['seg'] ?? '')) ?: null);
        $addC('b2b_payment_terms',  trim((string) ($b['paiement'] ?? '')) ?: null);
        $addC('b2b_credit_ceiling', $b2bNum($b['plafond'] ?? ''));
        $addC('b2b_web_discount',   $b2bNum($b['remiseWeb'] ?? ''));
        $addC('b2b_franco',         trim((string) ($b['franco'] ?? '')) ?: null);
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
      $newSiteId = null;
      if ($tblExists('ws_office_delivery_sites')) {
        q("INSERT INTO ws_office_delivery_sites (office_client_id, name, address, floor_room, tournee_id, site_access_minutes, active" . ($obShop ? ", shop_id" : "") . ")
            VALUES (?,?,?,?,?,?,1" . ($obShop ? "," . (int) $obShop : "") . ")",
          [$officeId, $raison . ' — ' . ((string) ($b['office'] ?? 'Site')), (string) ($b['adr'] ?? ''),
           // Temps d'accès : celui qui a été saisi, sinon RIEN. Les 6 minutes
           // par défaut entraient dans toutes les heures d'arrivée de la
           // tournée sans que personne les ait mesurées.
           (string) ($b['etage'] ?? ''), $tourId,
           (($b['acc'] ?? '') === '' || !is_numeric($b['acc'])) ? null : (float) $b['acc']]);
        $newSiteId = (int) db()->lastInsertId();
      }
      /* Départements B2B. L'INSERT écrivait SEPT colonnes (client_id, company,
         site, office, name, effectif, contact) dans une table ERP qui n'en a
         que trois — id, id_client, name — et posait « OF-<id> » dans id_client,
         qui est un ENTIER. Il ne pouvait donc jamais aboutir. Les colonnes
         écrites sont désormais celles qui existent, et le rattachement se fait
         au client réellement créé, jamais à un code fabriqué.
         Sans client réel, on n'écrit rien : une ligne rattachée à un id
         approximatif est pire qu'une ligne absente. */
      $newDeptIds = [];
      if (!empty($b['departements']) && is_array($b['departements'])
          && $tblExists('b2b_client_company_department') && !empty($newClientId)) {
        $DT   = 'b2b_client_company_department';
        $dKey = col_exists($DT, 'id_client') ? 'id_client' : (col_exists($DT, 'client_id') ? 'client_id' : null);
        $dNam = col_exists($DT, 'name') ? 'name' : (col_exists($DT, 'dept') ? 'dept' : null);
        if ($dKey && $dNam) {
          $dOpt = [];
          foreach (['company' => $raison, 'site' => (string) ($b['adr'] ?? ''),
                    'office' => (string) ($b['office'] ?? ''),
                    'contact' => (string) ($b['contactEmail'] ?? '')] as $c => $v)
            if (col_exists($DT, $c)) $dOpt[$c] = $v;
          $hasEff = col_exists($DT, 'effectif');
          $dCols  = array_merge([$dKey, $dNam], array_keys($dOpt), $hasEff ? ['effectif'] : []);
          $dSql   = "INSERT INTO $DT (" . implode(', ', $dCols) . ") VALUES ("
                  . implode(', ', array_fill(0, count($dCols), '?')) . ")";
          foreach ($b['departements'] as $d) {
            $dVals = array_merge([(int) $newClientId, (string) ($d['dept'] ?? '—')], array_values($dOpt));
            if ($hasEff) $dVals[] = (int) ($d['effectif'] ?? 1);
            try { q($dSql, $dVals); $newDeptIds[] = (int) db()->lastInsertId(); }
            catch (Throwable $e) { /* un département refusé ne doit pas annuler le bureau */ }
          }
        }
      }
      /* LE PERSONNEL DE L'ÉTAPE 6 (noms + e-mails). Il était POSTÉ par la
         console (staff) mais $staff n'était jamais défini côté serveur : la
         liste partait à la poubelle, aucun contact n'était enregistré et les
         invitations « personnel » ne pouvaient pas être envoyées. On lit la
         liste, on enregistre chaque adresse dans ws_office_emails (rôle
         « Personnel »), et on initialise les compteurs renvoyés à la console. */
      $staff = []; $staffOk = 0; $staffInvites = 0; $staffPourquoi = null;
      foreach ((array) ($b['staff'] ?? []) as $sm) {
        $em = strtolower(trim((string) (is_array($sm) ? ($sm['email'] ?? '') : $sm)));
        if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL) || in_array($em, $staff, true)) continue;
        $staff[] = $em;
      }
      if ($staff && $tblExists('ws_office_emails')) {
        $hasRole = col_exists('ws_office_emails', 'role');
        foreach ($staff as $em) {
          try {
            if ($hasRole)
              q("INSERT IGNORE INTO ws_office_emails (office_id, email, role, active) VALUES (?,?, 'Personnel', 1)", [$officeId, $em]);
            else
              q("INSERT IGNORE INTO ws_office_emails (office_id, email, active) VALUES (?,?,1)", [$officeId, $em]);
            $staffOk++;
          } catch (Throwable $e) { if (!$staffPourquoi) $staffPourquoi = 'Enregistrement d’un contact refusé : ' . $e->getMessage(); }
        }
      } elseif ($staff) {
        $staffPourquoi = 'Table ws_office_emails absente (migration 0063) — personnel non enregistré.';
      }

      /* LES VOUCHERS DE L'ONBOARDING — zéro, un ou plusieurs.
         Un bureau peut repartir avec un bon de bienvenue ET la découverte
         d'une gamme ET un geste commercial : n'en accepter qu'un obligeait à
         créer les autres à la main, hors du dossier. La console envoie
         désormais « vouchers » (liste) ; « voucher » (objet) reste lu pour
         les versions qui ne l'envoient pas encore. */
      $voucherList = []; $voucherNoms = [];
      foreach ((array) ($b['vouchers'] ?? []) as $vRow) {
        $c = strtoupper(trim((string) (is_array($vRow) ? ($vRow['code'] ?? '') : $vRow)));
        if ($c === '' || in_array($c, $voucherList, true)) continue;
        $voucherList[] = $c;
        // Le libellé ne sera pas stocké (ws_vouchers ne garde que le code) :
        // il ne sert qu'au document envoyé dans la foulée.
        $voucherNoms[] = ['code' => $c,
                          'libelle' => trim((string) (is_array($vRow) ? ($vRow['libelle'] ?? '') : '')),
                          'valeur'  => is_array($vRow) ? ($vRow['valeur'] ?? null) : null,
                          'validite'=> is_array($vRow) ? trim((string) ($vRow['validite'] ?? '')) : ''];
      }
      if (!$voucherList) {
        $vc1 = strtoupper(trim((string) ($b['voucher']['code'] ?? '')));
        if ($vc1 !== '') { $voucherList[] = $vc1; $voucherNoms[] = ['code' => $vc1, 'libelle' => '']; }
      }
      $vouchersCreated = 0;
      /* Création via le MODÈLE UNIFIÉ (ws_voucher_upsert), ciblée sur le
         bureau créé (target OFFICE). L'ancien INSERT écrivait value=0 dans
         ws_vouchers — devenue une VUE : aucun bon n'était créé, alors que la
         console EXIGEAIT valeur et validité. Le bon porte désormais la valeur
         saisie ; validité (validite) → expires_at si elle est une date. */
      $voucherFailed = [];
      foreach ($voucherNoms as $vn) {
        $val = $vn['valeur'];
        $num = is_numeric(preg_replace('/[^0-9.,]/', '', (string) $val)) ? (float) str_replace(',', '.', preg_replace('/[^0-9.,]/', '', (string) $val)) : null;
        // Type : « % » ou « pourcent » dans la valeur ⇒ percent, sinon montant fixe.
        $vtype = (stripos((string) $val, '%') !== false || stripos((string) $val, 'percent') !== false || stripos((string) $val, 'pour') !== false) ? 'percent' : 'fixed';
        $exp = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $vn['validite']) ? substr((string) $vn['validite'], 0, 10) : null;
        $r = ws_voucher_upsert([
          'code' => $vn['code'], 'type' => $vtype, 'value' => $num ?? 0,
          'expires_at' => $exp, 'active' => 1,
          'id_shop' => $shopId ?: null,                       // émetteur = boutique
          'target_kind' => 'OFFICE', 'target_id' => $officeId,
          'reason_kind' => 'ONBOARDING',
          'reason_note' => $vn['libelle'] ?: null,
          'created_by'  => 'franchisee-onboarding',
        ]);
        if (empty($r['error'])) $vouchersCreated++;
        else $voucherFailed[] = $vn['code'] . ' (' . $r['error'] . ')';
      }
      $voucherCreated = $vouchersCreated > 0;
      /* LIEN MAGIQUE « Créer mon compte ». Le bureau reçoit UN lien et le
         transfère à son personnel : chaque collaborateur arrive avec sa
         boutique, son bureau, son site et ses départements déjà rattachés.
         Le lien est ouvert à TOUTE adresse e-mail (exigence de domaine
         retirée le 14/08/2026) : chaque compte créé reste « pending »
         jusqu'à validation par le franchisé, c'est ce contrôle-là qui
         protège la facturation différée.
         Émettre le lien ne peut pas faire échouer la création du bureau :
         invite_issue() renvoie null (table 0062 absente, par exemple) et la
         console l'annonce au lieu d'afficher un lien qui n'existe pas. */
      // Exigence de domaine RETIRÉE (14/08/2026) : le lien est ouvert à toute
      // adresse — plus de déduction depuis l'e-mail du contact, plus de liste
      // « grand public ». Garde-fou restant : chaque compte naît « pending ».
      $emailDom = null;
      $inv = invite_issue([
        'shop' => (int) ($obShop ?: $shopId), 'office' => $officeId,
        'client' => $newClientId, 'site' => $newSiteId, 'depts' => $newDeptIds,
        'domain' => $emailDom, 'cp' => $obZip,
        'by' => is_admin_request() ? 'admin' : ('pin:' . (string) ($pinSes['id'] ?? '?')),
      ]);
      /* L'E-MAIL au contact du bureau : récapitulatif des conditions, bouton
         « Créer mon compte », et l'affiche à QR code en pièce jointe. Il ne
         part que si le franchisé l'a demandé (case du wizard). Son échec ne
         défait pas le bureau, mais il est DIT — un e-mail qu'on croit parti
         est pire qu'un e-mail non envoyé. */
      $mailFait = false; $mailPourquoi = null;
      if ($inv && !empty($b['sendMail'])) {
        require_once __DIR__ . '/invite_doc.php';
        // La LISTE des codes réellement insérés — pas $vc, qui n'est que la
        // dernière valeur de la boucle et n'existe même pas si ws_vouchers est
        // une vue. Le document annonce tous les bons, ou aucun.
        $recap = invite_recap($officeId, $vouchersCreated ? $voucherNoms : null);
        if (!$recap) $mailPourquoi = 'Bureau introuvable juste après sa création — e-mail non envoyé.';
        else [$mailFait, $mailPourquoi] = invite_mail_envoyer(
          $recap, invite_link($inv['token']), invite_link_court($inv['jti']),
          date('d/m/Y', strtotime($inv['expires_at'])));
      }
      // L'invitation au personnel : le même lien, envoyé à chacun.
      if ($inv && $staff && !empty($b['sendAdhesion'])) {
        require_once __DIR__ . '/invite_doc.php';
        // Même récapitulatif que le contact — les mêmes conditions, la même
        // liste de bons ; le reconstruire autrement enverrait deux documents
        // différents pour un seul bureau.
        $recapS = $recap ?? invite_recap($officeId, $vouchersCreated ? $voucherNoms : null);
        foreach (array_unique($staff) as $sm) {
          if (!$recapS) break;
          [$okS, $whyS] = invite_mail_envoyer($recapS, invite_link($inv['token']),
            invite_link_court($inv['jti']), date('d/m/Y', strtotime($inv['expires_at'])), $sm);
          if ($okS) $staffInvites++; elseif (!$staffPourquoi) $staffPourquoi = $whyS;
        }
      }
      json_out(['ok' => true, 'office_id' => $officeId, 'voucher_created' => $voucherCreated,
                'vouchers_created'  => $vouchersCreated,
                'vouchers_failed'   => $voucherFailed ?? [],
                'staff_saved'       => $staffOk,
                'staff_invited'     => $staffInvites,
                'staff_reason'      => $staffPourquoi,
                'invite_url'        => $inv ? invite_link($inv['token']) : null,
                'invite_short_url'  => $inv ? invite_link_court($inv['jti']) : null,
                'invite_expires_at' => $inv['expires_at'] ?? null,
                'mail_sent'         => $mailFait,
                'mail_reason'       => $mailPourquoi,
                'invite_reason'     => $inv ? null
                  : 'Lien d’invitation non émis : la table ws_office_invites est absente (migration 0062). Le bureau est créé.']);
    }

    json_out(['error' => 'Not found', 'path' => $p], 404);
  }

  /* ── Back-office admin (protégé par admin_token) ── */
  if (strpos($p, '/admin/') === 0) {
    require_admin();

    // Campagnes « objectif d'achat cumulé → produit cadeau » — gestion admin.
    if ($m === 'GET' && $p === '/admin/promo-campaigns') {
      $list = rows("SELECT * FROM ws_promo_campaign ORDER BY is_active DESC, ends_at DESC");
      json_out(array_map(fn ($c) => promo_campaign_public($c) + [
        'isActive'         => (int) $c['is_active'],
        'conditionScope'   => $c['condition_scope'],
        'perCustomerLimit' => (int) $c['per_customer_limit'],
        'voucherCodePrefix'=> $c['voucher_code_prefix'],
      ], $list));
    }
    // Créer (ou modifier si "id" fourni) une campagne.
    if ($m === 'POST' && $p === '/admin/promo-campaign') {
      $b = body();
      $v = promo_campaign_validate($b);
      if ($v['errors']) json_out(['error' => 'validation', 'fields' => $v['errors']], 422);
      $c = $v['clean'];
      // Le produit cadeau doit exister dans le catalogue (réplique ERP).
      if (!row("SELECT 1 AS x FROM ws_products WHERE id = ?", [$c['reward_product_id']]))
        json_out(['error' => 'reward_product_not_found'], 422);

      $cols = [$c['name'], $c['id_shop'], $c['is_active'], $c['starts_at'], $c['ends_at'],
               $c['threshold_amount'], $c['currency'], $c['condition_scope'], $c['reward_product_id'],
               $c['reward_delivery_date'], $c['voucher_code_prefix'], $c['per_customer_limit']];

      if (!empty($b['id'])) {
        q("UPDATE ws_promo_campaign SET name=?, id_shop=?, is_active=?, starts_at=?, ends_at=?,
              threshold_amount=?, currency=?, condition_scope=?, reward_product_id=?,
              reward_delivery_date=?, voucher_code_prefix=?, per_customer_limit=? WHERE id=?",
          array_merge($cols, [(int) $b['id']]));
        json_out(['ok' => true, 'id' => (int) $b['id']]);
      }
      q("INSERT INTO ws_promo_campaign
           (name, id_shop, is_active, starts_at, ends_at, threshold_amount, currency,
            condition_scope, reward_product_id, reward_delivery_date, voucher_code_prefix, per_customer_limit)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", $cols);
      json_out(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }
    // Activer / désactiver une campagne.
    if ($m === 'POST' && ($mm = $match('/admin/promo-campaign/:id/active'))) {
      $b = body();
      q("UPDATE ws_promo_campaign SET is_active = ? WHERE id = ?",
        [!empty($b['isActive']) ? 1 : 0, (int) $mm['id']]);
      json_out(['ok' => true, 'id' => (int) $mm['id'], 'isActive' => !empty($b['isActive']) ? 1 : 0]);
    }

    // Produits (tous) — pour la gestion
    if ($m === 'GET' && $p === '/admin/products') {
      json_out(rows("SELECT p.id, p.cat_id, c.label AS category, p.name, p.price, p.active
                       FROM ws_products p LEFT JOIN ws_categories c ON c.id=p.cat_id ORDER BY p.name"));
    }
    // Créer / modifier un produit
    if ($m === 'POST' && $p === '/admin/products') {
      $b = body();
      // Canaux dans l'ERP : `active` n'est plus écrit ici (le miroir l'écraserait
      // en ≤ 60 s) — nom, prix et catégorie restent gérés.
      $canauxErp = strtolower((string) (ws_param('channels_source', '') ?: '')) === 'erp';
      if (!empty($b['id'])) {
        if ($canauxErp) q("UPDATE ws_products SET name=?, price=?, cat_id=? WHERE id=?",
          [$b['name'], (float) $b['price'], $b['cat_id'] ?? null, $b['id']]);
        else q("UPDATE ws_products SET name=?, price=?, cat_id=?, active=? WHERE id=?",
          [$b['name'], (float) $b['price'], $b['cat_id'] ?? null, !empty($b['active']) ? 1 : 0, $b['id']]);
        json_out(['ok' => true, 'id' => (int) $b['id']]
          + ($canauxErp ? ['message' => 'Publié se gère désormais dans Franchise Buddy.'] : []));
      }
      if (empty($b['name'])) json_out(['error' => 'name requis'], 400);
      q("INSERT INTO ws_products (cat_id, name, price, active) VALUES (?,?,?,1)",
        [$b['cat_id'] ?? null, $b['name'], (float) ($b['price'] ?? 0)]);
      json_out(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }
    // Prix par boutique
    if ($m === 'POST' && $p === '/admin/price') {
      $b = body();
      /* FERMÉ. Cette route écrivait dans ws_product_prices, qui ne fixe plus
         aucun prix depuis que portion_price de l'ERP fait foi (31/08) : on
         enregistrait un montant que personne ne débitait jamais, et l'appelant
         recevait « ok ». Une deuxième source silencieuse, exactement ce que le
         résolveur unique existe pour empêcher. Mieux vaut refuser franchement
         et dire où le prix se règle. */
      json_out(['error' => "Les prix se règlent dans Franchise Buddy (portion_price).",
                'code' => 'prix_erp'], 409);
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
    // ── Avis clients (PWA fidélité) : synthèse + derniers négatifs.
    // Filtre optionnel ?shopId= (pwa_reviews.shop_id, réf logique shops.id).
    if ($m === 'GET' && $p === '/admin/reviews') {
      $s = qp('shopId');
      $sid = $s !== '' ? (int) $s : null;
      $totals = row(
        "SELECT COUNT(*) total, COALESCE(SUM(liked=1),0) liked, COALESCE(SUM(liked=0),0) disliked,
                COALESCE(SUM(liked IS NULL),0) pending
           FROM pwa_reviews" . ($sid !== null ? " WHERE shop_id=?" : ""),
        $sid !== null ? [$sid] : []
      );
      // Note moyenne par produit (les moins bien notés d'abord).
      $byProduct = rows(
        "SELECT ri.product_name, ROUND(AVG(ri.rating),2) avg_rating, COUNT(ri.rating) n
           FROM pwa_review_items ri JOIN pwa_reviews r ON r.id = ri.review_id
          WHERE ri.rating IS NOT NULL" . ($sid !== null ? " AND r.shop_id=?" : "") . "
          GROUP BY ri.product_name ORDER BY avg_rating ASC, n DESC LIMIT 100",
        $sid !== null ? [$sid] : []
      );
      // Derniers avis négatifs (liked=0) + leurs notes/commentaires produit.
      $recent = rows(
        "SELECT r.id, r.created_at, r.shop_id, p.store
           FROM pwa_reviews r LEFT JOIN pwa_purchases p ON p.id = r.purchase_id
          WHERE r.liked = 0" . ($sid !== null ? " AND r.shop_id=?" : "") . "
          ORDER BY r.id DESC LIMIT 40",
        $sid !== null ? [$sid] : []
      );
      foreach ($recent as &$rv) {
        $rv['items'] = rows(
          "SELECT product_name, rating, note FROM pwa_review_items WHERE review_id=? ORDER BY id",
          [$rv['id']]
        );
      }
      unset($rv);
      // Liste des boutiques (table unifiée `shops`) pour le sélecteur franchiseur
      // + le nom de la boutique en mode franchisée. Fiable (n'utilise pas /shops).
      $shops = rows("SELECT id, name, city FROM shops WHERE active = 1 ORDER BY name");
      json_out(['totals' => $totals, 'byProduct' => $byProduct, 'recentNegative' => $recent, 'shops' => $shops]);
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
/* Disponibilité saisonnière d'un produit, SERVIE PAR L'ENDPOINT
   (shops/{id}/products/available?include=availability_periods).
   Ces gammes sont définies dans l'ERP (« 🎄 Noël & Nouvel An », etc.) et
   n'étaient PAS connues du webshop : un cougnou de Noël restait en vente le
   2 août, et rien ne l'activait en décembre non plus.

   Règles :
     • aucune période rattachée      → produit disponible en permanence
       (c'est le cas de l'immense majorité du catalogue : ne rien casser) ;
     • au moins une période ACTIVE couvrant la date → disponible ;
     • périodes récurrentes : comparaison en mois-jour (MMJJ), avec passage
       d'année quand from_md > to_md (1101 → 115 = novembre à janvier) ;
     • ERP muet → aucun filtre. Fermer le catalogue serait pire que de
       laisser passer : on préfère vendre hors saison qu'afficher une boutique
       vide, et c'est de toute façon le comportement d'avant.

   Renvoie [fragment SQL, arguments] à concaténer dans un WHERE. $alias est
   l'alias de la table produit. */
function availability_where($alias, $date = null, $shopId = null, $forcer = null) {
  /* SOURCE : L'ENDPOINT. Le SQL qui vivait ici lisait
     product_availability_period(_connection), copies locales des tables de
     l'ERP. shops/{id}/products/available?include=availability_periods sert la
     même chose, à jour.

     On rend un NOT IN sur l'ensemble EXCLU, pas un IN sur l'ensemble autorisé.
     Deux raisons : l'exclu est bien plus petit (36 produits contre 547 au
     01/09), et surtout un produit que l'ERP ne connaît pas reste vendable —
     c'est exactement la règle d'avant (« aucune période rattachée → disponible
     en permanence »), alors qu'un IN l'aurait silencieusement retiré de la
     vente.

     Les périodes sont définies au niveau de la MARQUE (id_brand dans la
     charge utile) : la boutique ne sert qu'à obtenir la liste, d'où le repli
     sur une boutique de référence quand l'appelant n'en a pas.

     ERP muet → aucun filtre, comme lorsque les tables étaient absentes.
     Fermer le catalogue serait pire que de laisser passer : on préfère vendre
     hors saison qu'afficher une boutique vide. */
  if (!function_exists('erp_produits_de_saison')) return ['', []];
  $sid = (int) $shopId;
  if ($sid <= 0) {
    static $ref = null;
    if ($ref === null) {
      $ref = (int) (function_exists('ws_param') ? ws_param('erp_ref_shop', 0) : 0);
      if ($ref <= 0) {
        try { $r = row("SELECT MIN(id) m FROM shops WHERE webshop_enabled = 1"); $ref = (int) ($r['m'] ?? 0); }
        catch (Throwable $e) { $ref = 0; }
      }
    }
    $sid = $ref;
  }
  if ($sid <= 0) return ['', []];

  $verdict = erp_produits_de_saison($sid, $date, 'fr', $forcer);
  if (!is_array($verdict)) return ['', []];          // ERP muet
  $hors = [];
  foreach ($verdict as $pid => $vendable) if (!$vendable) $hors[] = (int) $pid;
  if (!$hors) return ['', []];
  return [' AND ' . $alias . '.id NOT IN (' . implode(',', $hors) . ')', []];
}

/* Le produit $pid est-il vendable à la date $date ? Même règle que
   availability_where, appliquée à un seul produit — utilisée à la création de
   commande : masquer au catalogue ne suffit pas, un panier gardé en cache ou
   un appel direct doit être refusé côté serveur. */
function product_available_on($pid, $date, $shopId = null) {
  [$sql, $args] = availability_where('p', $date, $shopId);
  if ($sql === '') return true;
  return (bool) row("SELECT 1 AS x FROM ws_products p WHERE p.id = ?$sql LIMIT 1",
                    array_merge([(int) $pid], $args));
}

function payment_family($m) {
  $m = strtolower(trim((string) $m));
  if (in_array($m, ['stripe', 'card', 'carte', 'bancontact', 'visa', 'mastercard', 'maestro'], true)) return 'stripe';
  if (in_array($m, ['shop', 'boutique', 'especes', 'cash', 'cod'], true)) return 'shop';
  if (in_array($m, ['deferred', 'account', 'compte', 'facturation'], true)) return 'deferred';
  return $m;
}
/* Libellé d'un moyen de paiement, DANS LA LANGUE DEMANDÉE.
 * Ces libellés étaient codés en dur en français : sur une boutique
 * néerlandophone, « Carte / Bancontact (en ligne) » s'affichait au milieu
 * d'un écran en néerlandais. Ce ne sont pas des données de configuration
 * (aucune table ne les porte), donc ils rejoignent la table de traduction
 * comme le reste de l'interface : clés pay.<method>.
 * Repli sur le libellé français si la traduction manque — jamais un code
 * technique nu à l'écran. */
/* Libellé d'interface côté serveur, dans la langue demandée (table ws_i18n).
 * Sert aux textes que le SERVEUR compose — libellés de bons, moyens de
 * paiement : le front ne peut pas les traduire, il ne reçoit qu'une phrase
 * déjà faite. Repli sur le texte français fourni si la clé manque : jamais
 * une clé nue ni une chaîne vide à l'écran. */
function ui_t($key, $fr, $lang = '', array $params = []) {
  static $cache = [];
  $lg = strtolower(substr((string) $lang, 0, 2));
  $out = $fr;
  if ($lg !== '' && $lg !== 'fr') {
    if (!array_key_exists($lg, $cache)) {
      $cache[$lg] = [];
      try {
        if (tbl_exists('ws_i18n')) {
          foreach (rows("SELECT k, value FROM ws_i18n WHERE scope='ui' AND lang=?", [$lg]) as $r) {
            $cache[$lg][$r['k']] = $r['value'];
          }
        }
      } catch (Throwable $e) { $cache[$lg] = []; }
    }
    if (!empty($cache[$lg][$key])) $out = $cache[$lg][$key];
  }
  foreach ($params as $k => $v) $out = str_replace('{' . $k . '}', (string) $v, $out);
  return $out;
}

function payment_label($m, $lang = '') {
  static $tr = null;
  $fr = ['stripe' => 'Carte / Bancontact (en ligne)', 'shop' => 'Paiement en boutique',
         'deferred' => 'Sur compte (facturation)'];
  $lg = strtolower(substr((string) $lang, 0, 2));
  if ($lg !== '' && $lg !== 'fr') {
    if ($tr === null) {
      $tr = [];
      try {
        if (tbl_exists('ws_i18n')) {
          foreach (rows("SELECT k, lang, value FROM ws_i18n WHERE scope='ui' AND k LIKE 'pay.%'") as $r) {
            $tr[$r['lang']][substr($r['k'], 4)] = $r['value'];
          }
        }
      } catch (Throwable $e) { $tr = []; }
    }
    if (!empty($tr[$lg][$m])) return $tr[$lg][$m];
  }
  return $fr[$m] ?? $m;
}
/* Moyens de paiement autorisés pour une boutique + profil (config, sinon défaut).
   ws_shop_payment_options n'est créée par aucune migration : si la table est
   absente (cas du serveur live), on retombe sur les défauts au lieu de jeter
   une exception qui faisait échouer TOUTES les commandes en 500. */
/* Moyens de paiement autorisés pour une boutique × un profil.
   Le défaut ne s'applique QUE si la boutique n'a AUCUNE ligne pour ce profil —
   c'est-à-dire si elle n'a jamais été configurée. Auparavant, le défaut
   s'appliquait dès que la liste des lignes ACTIVES était vide : une boutique qui
   désactivait tous ses moyens se les voyait donc rendre par le repli, et il était
   impossible de fermer le paiement en ligne. Une configuration explicite doit
   toujours l'emporter, y compris quand elle ne laisse rien. */
function allowed_methods($shop, $profile) {
  try {
    if (!row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ws_shop_payment_options'"))
      return $profile === 'company' ? ['stripe', 'deferred'] : ['stripe', 'shop'];
    $configuree = row("SELECT 1 x FROM ws_shop_payment_options WHERE shop_id=? AND profile_type=? LIMIT 1", [$shop, $profile]);
    if (!$configuree) return $profile === 'company' ? ['stripe', 'deferred'] : ['stripe', 'shop'];
    $rows = rows("SELECT method FROM ws_shop_payment_options WHERE shop_id=? AND profile_type=? AND active=1 ORDER BY method", [$shop, $profile]);
    return array_column($rows, 'method');   // peut être vide : c'est un CHOIX de la boutique
  } catch (Throwable $e) {
    return $profile === 'company' ? ['stripe', 'deferred'] : ['stripe', 'shop'];
  }
}

/* Shape client d'un customer. */
/* Fragment SQL excluant les COMPOSANTS de menu d'un compteur commercial.
   Ces lignes filles (parent_line_id non nul) sont comprises dans le prix du
   menu : les compter en pieces vendues gonflerait la charge, la capacite des
   creneaux et le panier moyen. Les ecrans de PRODUCTION, eux, les comptent —
   il faut bien les fabriquer.
   Renvoie une chaine vide tant que la migration 0055 n'est pas passee : l'API
   est deployee avant les migrations, la requete doit rester valide entre les
   deux. */
/* Existence d'une table des ventes croisées. Mémorisée : l'évaluation du panier
   est appelée à chaque modification, on ne réinterroge pas le schéma à chaque
   fois. Tables absentes = fonctionnalité inactive, jamais une erreur : le
   panier doit continuer de fonctionner si la migration 0056 n'est pas passée. */
function xsell_tbl($name) {
  static $c = [];
  if (!array_key_exists($name, $c)) {
    try {
      $c[$name] = (bool) row("SELECT 1 x FROM information_schema.tables
                               WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1", [$name]);
    } catch (Throwable $e) { $c[$name] = false; }
  }
  return $c[$name];
}

/* Le produit suggéré est-il RÉELLEMENT disponible ce jour-là ?
   Absence de ligne de stock = pas de plafond (règle du catalogue), donc
   disponible. Sinon on exige un reste strictement positif : proposer un
   produit épuisé fait cliquer le client sur une impasse. */
function xsell_in_stock($pid, $shopId, $date, $mode) {
  try {
    if (!xsell_tbl('ws_product_stock')) return true;
    $st = row("SELECT qty_total, qty_reserved, qty_sold FROM ws_product_stock
                WHERE product_id=? AND shop_id=? AND date=? AND mode=? AND active=1 LIMIT 1",
              [(int) $pid, (int) $shopId, $date, $mode]);
    if (!$st) return true;
    return ((int) $st['qty_total'] - (int) $st['qty_reserved'] - (int) $st['qty_sold']) > 0;
  } catch (Throwable $e) { return true; }
}

function oline_own() {
  static $c = null;
  if ($c === null) $c = col_exists('ws_order_lines', 'parent_line_id') ? ' AND l.parent_line_id IS NULL' : '';
  return $c;
}

/* ── LIEN MAGIQUE « Créer mon compte » ────────────────────────────────────
   Le bureau reçoit UN lien et le transfère à son personnel. Chaque
   collaborateur arrive avec sa boutique, son bureau, son site et ses
   départements DÉJÀ liés : il ne saisit que son identité.

   UN SEUL paramètre dans l'URL, signé. Pas de ?shop=2&office=17 en clair :
   ces valeurs décident qui facture et à quelles conditions ; lisibles, elles
   seraient modifiables — changer office=17 en office=18 suffirait à
   s'inscrire chez un autre client.

   La SIGNATURE fait autorité sur le contenu (sign_token/verify_token, HMAC
   SHA-256, le mécanisme déjà utilisé pour les sessions — pas de second système
   à maintenir). La TABLE ws_office_invites fait autorité sur la validité :
   un jeton signé ne sait pas qu'il a été révoqué, ni combien de fois il a
   servi.

   Le lien PRÉ-REMPLIT, il n'ouvre rien : le compte créé entre en `pending`
   dans ws_office_join_requests et attend la validation du franchisé. Sans
   cela, quiconque détient le lien commanderait sur le compte de l'entreprise,
   en paiement différé. */

/* Charge du jeton — UN SEUL endroit. L'émission et la reconstitution (console,
   quand le franchisé rouvre la fiche du bureau) doivent produire exactement le
   même jeton : la signature porte sur le JSON, donc l'ORDRE des clés en fait
   partie. Deux constructions parallèles finiraient par diverger d'une clé, et
   le lien affiché dans la console ne serait plus celui envoyé par e-mail. */
function invite_payload($jti, $exp, array $d) {
  // Types NORMALISÉS ici, et pas chez l'appelant : la base rend des chaînes là
  // où l'insertion passait des entiers, et « "client":"41" » ne signe pas comme
  // « "client":41 ». Le lien reconstitué serait alors refusé par verify_token.
  $n = fn ($v) => ($v === null || $v === '') ? null : (int) $v;
  $s = fn ($v) => ($v === null || $v === '') ? null : (string) $v;
  return [
    'v' => 1, 'k' => 'invite', 'jti' => (string) $jti, 'exp' => (int) $exp,
    'shop' => (int) ($d['shop'] ?? 0), 'office' => $n($d['office'] ?? null),
    'client' => $s($d['client'] ?? null), 'site' => $n($d['site'] ?? null),
    'depts' => array_values(array_map('intval', (array) ($d['depts'] ?? []))),
    'domain' => $s($d['domain'] ?? null), 'cp' => $s($d['cp'] ?? null),
  ];
}

function invite_issue(array $d, $jours = 30) {
  $jti = 'inv_' . bin2hex(random_bytes(8));
  $exp = time() + $jours * 86400;
  $depts = array_values(array_map('intval', (array) ($d['depts'] ?? [])));
  $tok = sign_token(invite_payload($jti, $exp, $d));
  try {
    q("INSERT INTO ws_office_invites (jti, shop_id, office_id, client_code, site_id, domain, depts, cp, expires_at, created_by)
       VALUES (?,?,?,?,?,?,?,?,FROM_UNIXTIME(?),?)",
      [$jti, (int) ($d['shop'] ?? 0), $d['office'] ?? null, $d['client'] ?? null,
       $d['site'] ?? null, $d['domain'] ?? null, ($depts ? implode(',', $depts) : null),
       $d['cp'] ?? null, $exp, $d['by'] ?? null]);
  } catch (Throwable $e) {
    // Table absente (migration 0062 pas passée) : pas de lien plutôt qu'un lien
    // irrévocable. Un jeton qu'on ne peut pas retirer de la circulation est pire
    // que pas de jeton.
    error_log('[ws] invite_issue KO : ' . $e->getMessage());
    return null;
  }
  return ['jti' => $jti, 'token' => $tok, 'expires_at' => date('c', $exp)];
}

/* Invitation EN COURS d'un bureau, telle qu'affichée dans la console : le lien
   reconstitué, sa date de fin, le nombre d'inscriptions déjà faites.
   Le jeton n'est pas stocké — il est REFABRIQUÉ depuis la ligne. Garder un
   jeton en base n'apporterait rien qu'on n'ait déjà, et ferait de la table un
   trousseau de clés à protéger. Renvoie [info|null, motif]. */
function invite_for_office($officeId) {
  try {
    $r = row("SELECT * FROM ws_office_invites
               WHERE office_id = ? AND revoked_at IS NULL AND expires_at > NOW()
               ORDER BY created_at DESC LIMIT 1", [(int) $officeId]);
  } catch (Throwable $e) {
    return [null, 'Invitations indisponibles : la table ws_office_invites est absente (migration 0062).'];
  }
  if (!$r) {
    // Distinguer « jamais émis » de « révoqué ou expiré » : le franchisé n'a
    // pas le même geste à faire — émettre, ou ré-émettre et prévenir.
    $any = row("SELECT revoked_at, expires_at FROM ws_office_invites
                 WHERE office_id = ? ORDER BY created_at DESC LIMIT 1", [(int) $officeId]);
    if (!$any) return [null, 'Aucun lien d’invitation n’a été émis pour ce bureau.'];
    return [null, !empty($any['revoked_at'])
      ? 'Le dernier lien a été révoqué le ' . substr((string) $any['revoked_at'], 0, 10) . '.'
      : 'Le dernier lien a expiré le ' . substr((string) $any['expires_at'], 0, 10) . '.'];
  }
  $tok = sign_token(invite_payload($r['jti'], strtotime((string) $r['expires_at']), [
    'shop' => $r['shop_id'], 'office' => $r['office_id'] === null ? null : (int) $r['office_id'],
    'client' => $r['client_code'], 'site' => $r['site_id'] === null ? null : (int) $r['site_id'],
    'depts' => ($r['depts'] ?? '') === '' ? [] : explode(',', (string) $r['depts']),
    'domain' => $r['domain'] ?? null, 'cp' => $r['cp'] ?? null,
  ]));
  return [['jti' => $r['jti'], 'url' => invite_link($tok),
           'urlCourt' => invite_link_court($r['jti']), 'expiresAt' => $r['expires_at'],
           'uses' => (int) $r['uses'], 'lastUseAt' => $r['last_use_at'],
           'createdAt' => $r['created_at']], null];
}

/* URL complète de la page « Créer mon compte » pour un jeton donné.
   L'API vit dans <racine webshop>/api : la racine se déduit du chemin du script
   plutôt que d'une constante à tenir à jour. L'URL est ABSOLUE parce qu'elle
   part par e-mail et s'imprime en QR code — un chemin relatif n'y sert à rien. */
function invite_link($tok, $param = 'i') {
  $https = ((($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'));
  $host  = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  $base  = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'))), '/');
  if ($base === '.' || $base === '/') $base = '';
  return ($https ? 'https://' : 'http://') . $host . $base . '/inscription?' . $param . '=' . rawurlencode($tok);
}

/* Le MÊME lien, sous sa forme COURTE (?c=inv_…) : celle qui va sur l'affiche,
   en QR et en toutes lettres dessous. Vingt caractères se recopient à la main
   depuis un mur de cafétéria ; deux cent cinquante, non. */
function invite_link_court($jti) { return invite_link($jti, 'c'); }

/* Vérifie un jeton d'invitation. Renvoie [charge|null, motif, ligne].
   Le motif REMONTE À L'ÉCRAN : « lien expiré », « lien révoqué » et « lien
   invalide » n'appellent pas la même réaction du collaborateur — le premier se
   redemande au responsable, le dernier signale une URL tronquée par un client
   mail. La troisième valeur est la ligne ws_office_invites (date d'expiration,
   usages) pour les appelants qui l'affichent. */
function invite_check($tok) {
  $tok = trim((string) $tok);
  /* DEUX FORMES POUR LE MÊME LIEN.
     • le JETON SIGNÉ (?i=…), ~250 caractères : ce qui part par e-mail, où la
       longueur ne coûte rien puisqu'un bouton la porte ;
     • le CODE COURT (?c=inv_…), 20 caractères : ce qui s'imprime sous un QR au
       mur d'une cafétéria, et qui doit pouvoir se RECOPIER À LA MAIN. Une
       affiche portant 250 caractères ne se recopie pas — c'est une affiche qui
       ne sert qu'aux téléphones dont l'appareil photo marche.
     La sécurité ne baisse pas : les deux formes exigent la ligne
     ws_office_invites, seule autorité sur la validité, et le code fait 64 bits
     de hasard — il ne se devine pas. La signature protège le CONTENU d'un
     jeton auto-porteur ; le code court, lui, ne porte aucun contenu : tout
     vient de la ligne. */
  $court = (bool) preg_match('/^inv_[0-9a-f]{16}$/', $tok);
  $d = null; $jti = '';
  if ($court) { $jti = $tok; }
  else {
    $d = verify_token($tok);
    if (!$d || ($d['k'] ?? '') !== 'invite')
      return [null, 'Ce lien d’invitation est invalide ou incomplet. Vérifiez qu’il a été copié en entier.', null];
    $jti = (string) ($d['jti'] ?? '');
  }
  if ($jti === '') return [null, 'Ce lien d’invitation est invalide.', null];
  try {
    $r = row("SELECT * FROM ws_office_invites WHERE jti = ?", [$jti]);
  } catch (Throwable $e) { return [null, 'Invitations indisponibles — merci de réessayer plus tard.', null]; }
  if ($court && $r) {
    // Charge reconstituée depuis la ligne : le code court n'en porte aucune.
    $d = invite_payload($r['jti'], strtotime((string) $r['expires_at']), [
      'shop' => $r['shop_id'], 'office' => $r['office_id'], 'client' => $r['client_code'],
      'site' => $r['site_id'],
      'depts' => ($r['depts'] ?? '') === '' ? [] : explode(',', (string) $r['depts']),
      'domain' => $r['domain'] ?? null, 'cp' => $r['cp'] ?? null,
    ]);
  }
  // Inconnu en base = émis par une autre installation, ou table réinitialisée.
  if (!$r) return [null, 'Ce lien d’invitation n’est plus reconnu. Demandez-en un nouveau à votre responsable.', null];
  if (!empty($r['revoked_at']))
    return [null, 'Ce lien d’invitation a été révoqué par votre Atelier. Demandez-en un nouveau à votre responsable.', null];
  if (!empty($r['expires_at']) && strtotime((string) $r['expires_at']) < time())
    return [null, 'Ce lien d’invitation a expiré. Demandez-en un nouveau à votre responsable.', null];
  return [$d, null, $r];
}

/* DEUX FONCTIONS ONT DISPARU D'ICI, ET C'EST LE BUT.
 *
 * whitelist_where() filtrait sur brand_whitelist ; categorie_shop_where() sur
 * ws_categories.shop_id. Les deux ont été vidées quand le modèle a été
 * simplifié, puis gardées un temps « pour que la règle reste à un endroit ».
 * Une fonction qui rend systématiquement une chaîne vide n'est plus une règle :
 * c'est un appel que le lecteur doit aller vérifier pour découvrir qu'il ne
 * fait rien. La colonne brand_whitelist n'existe même plus (migration 0073).
 *
 * CE QUI DÉCIDE, DÉSORMAIS, TIENT DANS UNE SEULE TABLE :
 *     ws_products.active            → publié au catalogue
 *     ws_products.click_and_collect → canal click & collect
 *     ws_products.office_delivery   → canal livraison bureau
 *     ws_products.price             → prix (<= 0 = non fixé, hors vente)
 */

/* ── POURQUOI CE PRODUIT N'EST-IL PAS EN LIGNE ? ───────────────────────────
 *
 * Les conditions qui décident qu'un produit s'affiche vivaient éparpillées :
 * le WHERE de /catalog/products, un filtre PHP après coup pour le prix, et deux
 * consoles qui en réimplémentaient chacune une partie. D'où l'écart constaté —
 * la console marque annonçait « 39 produits » là où le site en montrait 1, sans
 * que rien n'explique les 38 autres.
 *
 * IL N'EN RESTE QUE QUATRE, toutes dans ws_products, et c'est le modèle :
 *     active = 0            → brouillon, nulle part
 *     office_delivery = 0   → refusé, mais seulement en mode livraison bureau
 *     click_and_collect = 0 → refusé, mais seulement en mode click & collect
 *     price <= 0            → prix non fixé, hors vente
 * (plus la saison, quand les tables de gammes de l'ERP sont présentes).
 *
 * Le toggle « Webshop » de la console marque ne porte que la première. Les
 * autres sont invisibles à l'écran — d'où cette fonction, SEUL endroit qui les
 * énumère. Elle rend, par produit, le verdict ET la raison du refus : la
 * première rencontrée, dans l'ordre où le catalogue les applique. Les écrans
 * l'affichent au lieu de laisser deviner, et la sonde la compte.
 *
 * Elle ne DÉCIDE pas à la place du catalogue : /catalog/products garde son
 * WHERE (un filtre SQL est plus rapide qu'un diagnostic ligne à ligne). Le
 * test php-api/tests/visibilite_test.php vérifie que les deux disent la même
 * chose — c'est ce test qui empêche la divergence de revenir.
 */
function product_visibilite($shopId, array $ids, $mode = '', $date = null) {
  $ids = array_values(array_unique(array_map('intval', $ids)));
  if (!$ids) return [];
  $in = implode(',', $ids);
  $md = strtolower((string) $mode);
  $horsBureau = in_array($md, ['delivery', 'office', 'apricot'], true);
  $canalWeb   = ($md === 'collect') && col_exists('ws_products', 'click_and_collect');

  /* $shopId ne filtre plus rien, et la signature le garde exprès : tous les
     appelants le passent, et il redeviendra utile si une règle par boutique
     revient. Ce qui a disparu de cette requête : la jointure ws_product_shops
     (plus d'assortiment par boutique) et ws_categories.shop_id (une catégorie
     n'appartient plus à une boutique). Le LEFT JOIN sur ws_categories reste
     pour une seule chose — savoir si cat_id pointe sur une catégorie qui
     existe, ce qui est un défaut de NAVIGATION, pas un refus de vente. */
  [$seasonSql, $seasonArgs] = availability_where('p', $date, $shopId);
  $rs = rows("SELECT p.id,
                     p.active AS actif,
                     COALESCE(p.office_delivery,1) AS od,
                     " . (col_exists('ws_products', 'click_and_collect') ? "COALESCE(p.click_and_collect,1)" : "1") . " AS web,
                     p.cat_id, c.id AS cat_ok
                FROM ws_products p
                LEFT JOIN ws_categories c ON c.id = p.cat_id
               WHERE p.id IN ($in)");

  // Saison : on demande à la MÊME clause que le catalogue quels ids passent,
  // plutôt que de réécrire la règle des périodes récurrentes une seconde fois.
  $okSaison = [];
  if ($seasonSql !== '') {
    foreach (rows("SELECT p.id FROM ws_products p WHERE p.id IN ($in)$seasonSql", $seasonArgs) as $x)
      $okSaison[(int) $x['id']] = true;
  }

  $prix = prix_produits($ids, $shopId ? (int) $shopId : null);

  $out = [];
  foreach ($rs as $r) {
    $pid = (int) $r['id'];
    $raison = null;
    // ORDRE = celui du catalogue. La première condition qui échoue est la
    // raison montrée : en afficher plusieurs ferait chercher laquelle lever.
    if (!(int) $r['actif'])                          $raison = 'Brouillon — non publié au catalogue réseau';
    elseif ($horsBureau && !(int) $r['od'])          $raison = 'Non éligible à la livraison au bureau';
    // Symétrique du précédent depuis la 0071 : le canal click & collect a sa
    // propre colonne. Sans elle, « retiré du webshop » se disait « brouillon »
    // et retirait le produit de la livraison bureau par la même occasion.
    elseif ($canalWeb && !(int) $r['web'])           $raison = 'Non vendu sur le webshop (click & collect)';
    elseif ($seasonSql !== '' && !isset($okSaison[$pid]))
                                                     $raison = 'Hors saison à la date demandée';
    elseif (!isset($prix[$pid]))                     $raison = 'Prix non fixé (ws_products.price)';
    /* JOIGNABLE ≠ EN LIGNE, et les confondre rendrait les deux faux. Un
       produit peut être EN VENTE — il s'affiche sous « Tout » — sans qu'aucune
       catégorie ne permette d'y revenir. On le nomme sans le confondre avec le
       verdict de vente : retirer ces produits de la vente serait une décision
       commerciale, pas une correction technique.

       IL N'EN RESTE QU'UN, et c'est le signe que le reste a été réparé plus
       haut plutôt que décrit ici. « Catégorie désactivée » a disparu : ce
       drapeau est un cache calculé, la barre ne le lit plus, et ce diagnostic
       a continué de l'annoncer un moment — il déclarait injoignables des
       produits parfaitement joignables. « Catégorie d'une autre boutique » a
       disparu aussi : une catégorie n'appartient plus à une boutique, le
       filtre comme la colonne lue ont été retirés du modèle.

       Reste le seul cas où un produit est réellement vendu sans catégorie où
       le retrouver : un cat_id vide, ou qui ne pointe sur rien. */
    $nav = null;
    if (!$r['cat_ok'])                              $nav = 'Catégorie inconnue (cat_id vide ou orphelin) — introuvable dans la navigation';
    $out[$pid] = ['enLigne' => $raison === null, 'raison' => $raison, 'navigation' => $nav];
  }
  return $out;
}

/* Centroïde d'un code postal belge (data/zipcodes_be.json) — le même
   référentiel que celui servi au front par /geo/postcodes. Approximatif par
   nature : c'est le centre de la commune, pas une adresse. */
function zip_centroid($zip) {
  static $idx = null;
  if ($idx === null) {
    $idx = [];
    $f = __DIR__ . '/data/zipcodes_be.json';
    if (is_file($f)) foreach ((json_decode((string) file_get_contents($f), true) ?: []) as $e)
      $idx[(string) $e['zip']] = ['lat' => (float) $e['lat'], 'lng' => (float) $e['lng'], 'city' => $e['city']];
  }
  $z = trim((string) $zip);
  return $z !== '' && isset($idx[$z]) ? $idx[$z] : null;
}

/* POSITION DE LA BOUTIQUE — géocodée une fois depuis son ADRESSE, puis gardée.
   Les cartes du BO franchisé résolvaient le seul code postal : le pin tombait
   au centroïde de la commune, à des kilomètres de la boutique, et sans CP
   résolu sur Bruxelles-centre. Comme tous les tracés de tournée partent de ce
   point et que les ETA en découlent, une position fausse fausse la journée.

   Ordre : position déjà connue > géocodage de address_line + zip (Nominatim,
   l'OpenStreetMap qui sert déjà les tuiles) > centroïde du CP. Chaque niveau
   est ÉTIQUETÉ dans geo_source : le back-office affiche l'avertissement tant
   qu'on n'est pas sur l'adresse exacte. Une saisie 'manual' n'est jamais
   écrasée, et un échec est daté pour ne pas réessayer à chaque affichage. */
function shop_geo($shop, $force = false) {
  $has = fn($c) => col_exists('shops', $c);
  // Un échec renvoie TOUJOURS sa cause. « Position inconnue » sans motif oblige
  // à deviner entre une migration non passée, une adresse vide et un service
  // tiers injoignable — trois pannes qui ne se corrigent pas au même endroit.
  $ko = fn($why) => ['lat' => null, 'lng' => null, 'source' => null, 'reason' => $why];
  if (!$has('lat') || !$has('lng'))
    return $ko('Colonnes shops.lat / shops.lng absentes — migration 0060 non passée sur cette base.');
  $ok = fn($la, $ln, $sr) => ['lat' => (float) $la, 'lng' => (float) $ln, 'source' => $sr, 'reason' => null];
  $src = $has('geo_source') ? ($shop['geo_source'] ?? null) : null;
  if (!$force) {
    if ($shop['lat'] !== null && $shop['lng'] !== null && $src !== 'zip')
      return $ok($shop['lat'], $shop['lng'], $src ?: 'address');
    // Échec récent ou centroïde déjà posé : on ne relance pas un appel externe
    // à chaque ouverture de la console. Une journée suffit à laisser corriger
    // l'adresse, et /franchisee/shop-geocode force la reprise à la demande.
    if ($has('geo_at') && !empty($shop['geo_at']) && strtotime((string) $shop['geo_at']) > time() - 86400) {
      if ($shop['lat'] !== null && $shop['lng'] !== null) return $ok($shop['lat'], $shop['lng'], $src ?: 'zip');
      return $ko('Dernière résolution en échec (' . $shop['geo_at'] . ') — nouvel essai dans 24 h,'
               . ' ou immédiatement via « Recalculer la position ».');
    }
  }
  if ($src === 'manual' && !$force)
    return $shop['lat'] !== null ? $ok($shop['lat'], $shop['lng'], 'manual')
                                 : $ko('Position marquée « saisie manuelle » mais lat/lng vides.');

  // L'adresse se lit d'ABORD dans address_line, sinon dans street + street_num
  // — les deux formes coexistent dans `shops` (l'endpoint public /shops
  // reconstruit d'ailleurs la sienne à partir de street). N'en regarder qu'une
  // aurait déclaré « sans adresse » une boutique dont l'adresse est là.
  $addr = trim((string) ($shop['address_line'] ?? ''));
  if ($addr === '') $addr = trim(trim((string) ($shop['street'] ?? '')) . ' ' . trim((string) ($shop['street_num'] ?? '')));
  $zip  = trim((string) ($shop['zip'] ?? ''));
  $city = trim((string) ($shop['city'] ?? ''));
  if ($addr === '' && $zip === '')
    return $ko('Boutique sans adresse : address_line, street/street_num et zip sont tous vides dans la table shops.');

  $hit = null; $source = null; $why = null;
  $qy = trim($addr . ', ' . trim($zip . ' ' . $city) . ', Belgique', " ,");
  [$hit, $nomWhy] = nominatim_lookup($qy);
  if ($hit) $source = 'address';
  if (!$hit) {                                            // repli ASSUMÉ, étiqueté comme tel
    $c = zip_centroid($zip);
    if ($c) { $hit = $c; $source = 'zip';
      $why = 'Adresse non géocodée (' . $nomWhy . ') — repli sur le centre du code postal ' . $zip . '.'; }
    else $why = $zip === ''
      ? 'Adresse non géocodée (' . $nomWhy . ') et aucun code postal pour se rabattre sur la commune.'
      : 'Adresse non géocodée (' . $nomWhy . ') et code postal « ' . $zip .' » absent du référentiel belge.';
  }
  $cols = ['lat = ?', 'lng = ?'];
  $vals = [$hit ? $hit['lat'] : null, $hit ? $hit['lng'] : null];
  if ($has('geo_source')) { $cols[] = 'geo_source = ?'; $vals[] = $hit ? $source : 'failed'; }
  if ($has('geo_at'))       $cols[] = 'geo_at = NOW()';
  $vals[] = (int) $shop['id'];
  try { q("UPDATE shops SET " . implode(', ', $cols) . " WHERE id = ?", $vals); }
  catch (Throwable $e) { error_log('[ws] shop_geo écriture KO : ' . $e->getMessage()); }
  if (!$hit) return $ko($why);
  return ['lat' => (float) $hit['lat'], 'lng' => (float) $hit['lng'], 'source' => $source, 'reason' => $why];
}

/* Appel Nominatim (OpenStreetMap). Délai court : la console ne doit pas
   attendre un service tiers. Aucune exception ne remonte — sans réponse, on
   retombe sur le centroïde, jamais sur une position inventée.
   Renvoie [position|null, motif] : le motif REMONTE jusqu'à l'écran. Un
   hébergeur qui bloque les appels sortants et une adresse introuvable
   produisent le même pin ; ils ne se corrigent pas du tout au même endroit. */
function nominatim_lookup($query) {
  if (trim((string) $query) === '') return [null, 'requête vide'];
  if (!function_exists('curl_init')) return [null, 'extension cURL absente du PHP du serveur'];
  $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=be&q='
       . rawurlencode($query);
  try {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3,
      // Nominatim exige un agent identifiable ; un appel anonyme est refusé.
      CURLOPT_USERAGENT => 'AtelierBy-BackOffice/1.0 (+' . (cfg()['mail_from'] ?: 'no-reply@atelierby.be') . ')',
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $code === 0) {
      error_log('[ws] nominatim injoignable : ' . $err);
      return [null, 'service de géocodage injoignable depuis le serveur' . ($err ? ' — ' . $err : '')];
    }
    if ($code !== 200) { error_log('[ws] nominatim HTTP ' . $code . ' pour « ' . $query . ' »');
      return [null, 'service de géocodage : HTTP ' . $code]; }
    $j = json_decode((string) $raw, true);
    if (!is_array($j) || !isset($j[0]['lat'], $j[0]['lon'])) {
      error_log('[ws] nominatim sans résultat : « ' . $query . ' »');
      return [null, 'adresse « ' . $query . ' » introuvable']; }
    return [['lat' => (float) $j[0]['lat'], 'lng' => (float) $j[0]['lon']], null];
  } catch (Throwable $e) { error_log('[ws] nominatim KO : ' . $e->getMessage());
    return [null, 'erreur du géocodage : ' . $e->getMessage()]; }
}

/* Produit PORTEUR de la formule d'un produit donné.
     • le produit a sa propre formule active -> lui-même ;
     • sinon, si son menu est armé par la CATÉGORIE (menu_default, non surchargé
       'off'), le produit « modèle » de cette catégorie : tous les produits
       déclencheurs partagent alors LE MÊME menu, l'étape 1 étant le produit
       choisi par le client.
   Une seule définition, parce que deux endroits en dépendent : le catalogue qui
   SERT la formule et la commande qui la VALIDE. Quand ils divergeaient, la
   commande refusait la composition que le catalogue venait d'afficher. */
function bundle_source_pid($pid) {
  $pid = (int) $pid;
  if ($pid <= 0) return 0;
  if (row("SELECT 1 x FROM ws_bundles WHERE product_id = ? AND active = 1 LIMIT 1", [$pid])) return $pid;
  $meta = row("SELECT p.cat_id, p.sub_cat_id, p.menu_override, COALESCE(c.menu_default, 0) AS menu_default
                 FROM ws_products p
                 LEFT JOIN ws_categories c ON c.id = p.cat_id
                WHERE p.id = ? LIMIT 1", [$pid]);
  if (!$meta || $meta['menu_override'] === 'off') return $pid;
  /* DÉCLENCHEURS EXPLICITES D'ABORD (ws_bundle_triggers, 0078) : le menu dont
     un déclencheur correspond au produit — sa SOUS-catégorie prime (le « ET »
     catégorie+sous-catégorie), sa catégorie sinon. Plusieurs menus peuvent se
     disputer un produit : le déclencheur le plus précis gagne, puis le plus
     petit id — déterministe. */
  if ($meta['cat_id'] !== null && tbl_exists('ws_bundle_triggers')) {
    /* Pas de condition tp.active : le porteur vit hors catalogue (active=0),
       c'est voulu — seule sa FORMULE doit être active. L'ARTICLE prime sur la
       sous-catégorie, qui prime sur la catégorie (0081). */
    $hasArt = col_exists('ws_bundle_triggers', 'article_id');
    $tg = row("SELECT t.product_id
                 FROM ws_bundle_triggers t
                 JOIN ws_bundles b ON b.product_id = t.product_id AND b.active = 1
                WHERE " . ($hasArt
                  ? "(t.article_id = ? OR (t.article_id IS NULL AND t.cat_id = ?
                       AND (t.sub_cat_id IS NULL OR t.sub_cat_id = ?)))
                ORDER BY (t.article_id IS NOT NULL) DESC, (t.sub_cat_id IS NOT NULL) DESC, t.product_id"
                  : "t.cat_id = ? AND (t.sub_cat_id IS NULL OR t.sub_cat_id = ?)
                ORDER BY (t.sub_cat_id IS NOT NULL) DESC, t.product_id") . "
                LIMIT 1",
              $hasArt ? [$pid, (int) $meta['cat_id'], $meta['sub_cat_id'] !== null ? (int) $meta['sub_cat_id'] : -1]
                      : [(int) $meta['cat_id'], $meta['sub_cat_id'] !== null ? (int) $meta['sub_cat_id'] : -1]);
    if ($tg) return (int) $tg['product_id'];
  }
  // ANCIEN CHEMIN, conservé : catégorie armée par menu_default → la formule
  // « modèle » de la catégorie (produit explicitement menu d'abord, puis le
  // plus petit id — déterministe).
  $armed = $meta['cat_id'] !== null && (
    $meta['menu_override'] === 'on' ||
    ($meta['menu_override'] === null && (int) $meta['menu_default'] === 1));
  if (!$armed) return $pid;
  $tpl = row("SELECT b.product_id
                FROM ws_bundles b
                JOIN ws_products tp ON tp.id = b.product_id AND tp.active = 1
               WHERE b.active = 1 AND tp.cat_id = ?
               ORDER BY (tp.menu_override = 'on') DESC, b.product_id
               LIMIT 1", [$meta['cat_id']]);
  return $tpl ? (int) $tpl['product_id'] : $pid;
}

/* Composition d'une formule, RÉSOLUE ET VALIDÉE CÔTÉ SERVEUR.
   Le panier envoie des identifiants ; il ne décide ni du prix ni de ce qui
   est composable. On repart donc de la base :
     • la formule doit appartenir au produit PORTEUR (le produit commandé, ou le
       modèle de sa catégorie — exactement ce que le catalogue a servi) ;
     • chaque choix doit appartenir à une étape ACTIVE de CETTE formule —
       sans quoi n'importe quel identifiant de choix ajouterait n'importe quel
       produit à la commande ;
     • le supplément (delta) et le modificateur de formule (price_modifier)
       sont lus en base, jamais reçus du client.
   Renvoie ['modifier' => float, 'choices' => [ [id,label,product_id,delta] ]]. */
function bundle_compose($productId, $bundleId, $rawSlots) {
  $empty = ['modifier' => 0.0, 'choices' => []];
  $ids = [];
  // Une rubrique multi (« 2 choix ») arrive en TABLEAU de choix : on aplatit.
  // Le cast (int) d'un tableau rendait 1 — la composition multi était perdue.
  foreach ((array) $rawSlots as $v) {
    foreach (is_array($v) ? $v : [$v] as $w) { $n = (int) $w; if ($n > 0) $ids[$n] = $n; }
  }
  $ids = array_values($ids);
  $bundleId = is_numeric($bundleId) ? (int) $bundleId : 0;
  if (!$ids && !$bundleId) return $empty;

  // Le porteur de la formule n'est pas toujours le produit commandé : un menu
  // de catégorie (« la quiche choisie devient l'étape 1 ») est porté par le
  // produit modèle. Valider contre le produit commandé rejetait alors TOUTE la
  // composition — celle-là même que le catalogue venait de servir au client.
  $srcPid = bundle_source_pid($productId);
  $hasPid = col_exists('ws_bundle_slot_choices', 'product_id');
  $chosen = [];
  if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $chosen = rows(
      "SELECT c.id, c.label, c.delta, s.bundle_id, s.sort_order AS s_ord, c.sort_order AS c_ord"
      . ($hasPid ? ", c.product_id" : ", NULL AS product_id") . "
         FROM ws_bundle_slot_choices c
         JOIN ws_bundle_slots s  ON s.id  = c.slot_id   AND s.active = 1
         JOIN ws_bundles      bu ON bu.id = s.bundle_id AND bu.active = 1
        WHERE c.active = 1 AND bu.product_id = ? AND c.id IN ($ph)
        ORDER BY s.sort_order, c.sort_order, c.id",
      array_merge([$srcPid], $ids));
  }
  // Une commande porte UNE formule. Celle annoncée par le panier si elle est
  // valable, sinon celle des choix retenus — les choix d'une autre formule
  // sont écartés plutôt que mélangés.
  $bid = 0;
  if ($bundleId) {
    foreach ($chosen as $c) if ((int) $c['bundle_id'] === $bundleId) { $bid = $bundleId; break; }
    if (!$bid && !$chosen
        && row("SELECT 1 x FROM ws_bundles WHERE id=? AND product_id=? AND active=1", [$bundleId, $srcPid]))
      $bid = $bundleId;
  }
  if (!$bid && $chosen) $bid = (int) $chosen[0]['bundle_id'];
  if (!$bid) return $empty;

  $mod = (float) (row("SELECT price_modifier FROM ws_bundles WHERE id=?", [$bid])['price_modifier'] ?? 0);
  $out = [];
  foreach ($chosen as $c) {
    if ((int) $c['bundle_id'] !== $bid) continue;
    $out[] = ['id' => (int) $c['id'], 'label' => trim((string) $c['label']),
              'product_id' => $c['product_id'] !== null ? (int) $c['product_id'] : null,
              'delta' => round((float) $c['delta'], 2)];
  }
  return ['modifier' => round($mod, 2), 'choices' => $out];
}

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
  // Quelle source a fourni le bureau. Sans elle, un bureau « revenu » après un
  // déliement est indiscernable d'un bug : il a fallu trois requêtes SQL pour
  // établir que c'était la liaison PWA qui reprenait la main. L'information
  // coûte une variable et se lit dans /auth/me.
  $officeSource = $officeId ? 'client' : null;
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
      if ($r2 && !empty($r2['oid'])) { $officeId = $r2['oid']; $officeSource = 'pwa'; }
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
      if ($r3 && !empty($r3['oid'])) { $officeId = $r3['oid']; $officeSource = 'email'; }
    } catch (Throwable $e) { /* table absente — repli ignoré */ }
  }
  // Site de livraison lié côté PWA : le « bureau » de la PWA est un site
  // ws_office_delivery_sites, parfois SANS entreprise ws_offices associée
  // (office_client_id NULL). Exposé tel quel pour que le profil webshop affiche
  // la même carte bureau que la PWA, même quand la chaîne vers ws_offices est
  // vide.
  $officeSite = null;
  try {
    // tourId/tourName : le bureau lié est livrable dès que SON SITE porte une
    // tournée active. Sans cette information, la console refusait la livraison
    // à un client pourtant correctement rattaché — la chaîne vers ws_offices
    // étant souvent vide, elle ne pouvait pas conclure.
    $stCols = col_exists('ws_office_delivery_sites', 'tournee_id')
      ? ", s.tournee_id AS tourId, t.name AS tourName" : ", NULL AS tourId, NULL AS tourName";
    $stJoin = col_exists('ws_office_delivery_sites', 'tournee_id')
      ? " LEFT JOIN ws_tours t ON t.id = s.tournee_id AND t.active = 1" : "";
    $officeSite = row("SELECT s.id, s.name, s.address, s.shop_id AS shopId, s.active" . $stCols . "
                         FROM pwa_client_office co
                         JOIN pwa_offices po ON po.id = co.office_id
                         JOIN ws_office_delivery_sites s ON s.id = CAST(po.office_ref AS UNSIGNED) AND s.id > 0"
                         . $stJoin . "
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
    // 'client' = client.office_id · 'pwa' = liaison PWA · 'email' = adresse
    // inscrite dans une entreprise (seule source que « Délier » ne coupe pas).
    'officeSource' => $officeSource,
    'officeSite' => $officeSite,
    // Assortiment et affichage des prix du bureau (0113) : le front sait dès
    // la connexion s'il masque les montants.
    'office' => function_exists('office_contexte') ? office_contexte(office_assortiment($officeId)) : null,
    'preferredShopId' => $u['preferred_shop_id'] ?? null,
    // Champs que la PWA lisait en SQL direct sur `client` (profil, parrainage,
    // adresse de facturation) : exposés ici pour qu'elle passe par /auth/me.
    'mainShopId'   => isset($u['id_main_shop']) ? (int) $u['id_main_shop'] : null,
    'clientCode'   => $u['client_code'] ?? null,
    'memberSince'  => $u['member_since'] ?? null,
    'iban'         => $u['iban'] ?? null,
    'billingLines' => $u['billing_lines'] ?? null,
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
/* ── Google Business Profile (OAuth) — répondre aux avis. La connexion vit en
 * ws_param (client OAuth + refresh token) : l'API exige un accès approuvé par
 * Google et une redirection HTTPS qu'un serveur HTTP nu ne peut pas offrir,
 * donc le refresh token se génère hors ligne (OAuth Playground) et se colle
 * en Console marque → Avis. Chaque requête ré-échange le refresh token contre
 * un access token — aucun cache entre deux requêtes, la vérité est chez
 * Google. ── */
function gbp_http($method, $url, $headers, $body = null) {
  $h = '';
  foreach ($headers as $k => $v) $h .= $k . ': ' . $v . "\r\n";
  $opt = ['method' => $method, 'timeout' => 25, 'ignore_errors' => true, 'header' => $h];
  if ($body !== null) $opt['content'] = $body;
  $raw = @file_get_contents($url, false, stream_context_create(['http' => $opt]));
  return ($raw !== false) ? json_decode($raw, true) : null;
}
function gbp_token(&$why = null, $shopId = null) {
  $cid = (string) ws_param('google_oauth_client_id', '');
  $sec = (string) ws_param('google_oauth_client_secret', '');
  if ($cid === '' || $sec === '') {
    $why = 'Client OAuth non configuré — poser google_oauth_client_id et google_oauth_client_secret (Console marque → Avis).';
    return null;
  }
  /* CHAQUE FRANCHISÉ A SON COMPTE GOOGLE : sa fiche vit sous son compte, donc
     son refresh token est à lui (clé google_oauth_refresh_token_shop_<id>,
     collé depuis SA console). Le jeton réseau reste un repli pour les fiches
     que la marque gère elle-même. */
  $ref = '';
  if ($shopId) $ref = (string) ws_param('google_oauth_refresh_token_shop_' . (int) $shopId, '');
  if ($ref === '') $ref = (string) ws_param('google_oauth_refresh_token', '');
  if ($ref === '') {
    $why = $shopId
      ? 'Aucun refresh token pour cette boutique — collez le vôtre dans la carte Avis Google de cette console (compte Google du franchisé), ou posez un jeton réseau en Console marque → Avis.'
      : 'Aucun refresh token réseau (google_oauth_refresh_token) — chaque boutique peut aussi coller le sien dans sa console.';
    return null;
  }
  $j = gbp_http('POST', 'https://oauth2.googleapis.com/token',
    ['Content-Type' => 'application/x-www-form-urlencoded'],
    http_build_query(['client_id' => $cid, 'client_secret' => $sec,
                      'refresh_token' => $ref, 'grant_type' => 'refresh_token']));
  if (!is_array($j)) { $why = 'oauth2.googleapis.com injoignable depuis le serveur.'; return null; }
  if (empty($j['access_token'])) {
    $why = 'Google a refusé le refresh token : ' . ((string) ($j['error_description'] ?? ($j['error'] ?? 'réponse sans access_token')));
    return null;
  }
  return (string) $j['access_token'];
}
/* Localise la fiche Business Profile d'une boutique par son Place ID :
 * accounts.list puis locations.list (readMask metadata → placeId). Rend
 * [nomCompte, nomFiche, titre] ou null avec la raison exacte dans $why. */
function gbp_locate($tok, $placeId, &$why = null) {
  $acc = gbp_http('GET', 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts',
                  ['Authorization' => 'Bearer ' . $tok]);
  if (!is_array($acc)) { $why = 'API Business Profile (accounts) injoignable.'; return null; }
  if (isset($acc['error'])) { $why = 'Google (accounts) : ' . ((string) ($acc['error']['message'] ?? 'refus')); return null; }
  foreach ((array) ($acc['accounts'] ?? []) as $a) {
    $an = (string) ($a['name'] ?? '');
    if ($an === '') continue;
    $pg = '';
    do {
      $loc = gbp_http('GET', 'https://mybusinessbusinessinformation.googleapis.com/v1/' . $an
        . '/locations?readMask=' . rawurlencode('name,title,metadata') . '&pageSize=100'
        . ($pg !== '' ? '&pageToken=' . rawurlencode($pg) : ''),
        ['Authorization' => 'Bearer ' . $tok]);
      if (!is_array($loc)) { $why = 'API Business Profile (locations) injoignable.'; return null; }
      if (isset($loc['error'])) { $why = 'Google (locations) : ' . ((string) ($loc['error']['message'] ?? 'refus')); return null; }
      foreach ((array) ($loc['locations'] ?? []) as $l)
        if ((string) ($l['metadata']['placeId'] ?? '') === $placeId)
          return [$an, (string) $l['name'], (string) ($l['title'] ?? '')];
      $pg = (string) ($loc['nextPageToken'] ?? '');
    } while ($pg !== '');
  }
  $why = 'Aucune fiche du compte Google connecté ne porte le Place ID de cette boutique (' . $placeId . ').';
  return null;
}

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
function tbl_exists($table) {
  static $cache = [];
  if (!array_key_exists($table, $cache)) {
    try {
      $cache[$table] = (bool) row(
        "SELECT 1 AS ok FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1", [$table]);
    } catch (Throwable $e) { $cache[$table] = false; }
  }
  return $cache[$table];
}

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
/* ── Session tablette (PIN) ───────────────────────────────────────────────────
   Renvoie ['user_id','shop_id','sections'] pour un X-Pin-Token valide, sinon
   null. Le compte est REVÉRIFIÉ à chaque requête (active = 1) : désactiver un
   compte depuis la console marque ferme immédiatement la tablette, sans
   attendre l'expiration des 12 h. */
function bo_pin_session() {
  static $cached = false;
  if ($cached !== false) return $cached;
  $tok = req_header('X-Pin-Token');
  if ($tok === '') return $cached = null;
  try {
    $hasRole = col_exists('bo_users', 'role_id');
    $s = row("SELECT s.user_id, s.shop_id, u.sections, u.active" .
             ($hasRole ? ", r.sections AS role_sections" : ", NULL AS role_sections") . "
                FROM bo_pin_session s JOIN bo_users u ON u.id = s.user_id" .
             ($hasRole ? " LEFT JOIN bo_role r ON r.id = u.role_id AND r.active = 1" : "") . "
               WHERE s.token = ? AND s.expires_at > NOW()", [$tok]);
  } catch (Throwable $e) { return $cached = null; }
  if (!$s || !$s['active']) return $cached = null;
  return $cached = [
    'user_id'  => (int) $s['user_id'],
    'shop_id'  => (int) $s['shop_id'],
    // Sections EFFECTIVES = celles du profil (la marque les fixe) : modifier un
    // profil côté marque change immédiatement les droits des tablettes.
    'sections' => bo_user_sections($s),
  ];
}

/* ── Jeton d'appareil (tablette Kitchen) ─────────────────────────────────────
   Le mode tablette a quitté le back-office franchisé : la tablette de comptoir
   tourne sous Kitchen, à qui l'on donne l'URL du back-office et CE jeton — pas
   le jeton administrateur ERP, qui ouvre les marges, les coûts et les réglages
   réseau et n'a rien à faire sur un comptoir.

   Le jeton n'est jamais stocké en clair : seul son SHA-256 est en base. Il vaut
   pour UNE boutique, et sa portée s'arrête là. Renvoie l'id de boutique, ou
   null si le jeton est absent, inconnu ou révoqué. */
function device_token_shop() {
  static $cached = false;
  if ($cached !== false) return $cached;
  $tok = req_header('X-Device-Token');
  if ($tok === '') return $cached = null;
  try {
    if (!row("SELECT 1 x FROM information_schema.tables
               WHERE table_schema=DATABASE() AND table_name='ws_shop_device_token' LIMIT 1"))
      return $cached = null;
    $r = row("SELECT id, shop_id FROM ws_shop_device_token
               WHERE token_hash = ? AND revoked_at IS NULL LIMIT 1", [hash('sha256', $tok)]);
    if (!$r) return $cached = null;
    // Trace de vie : permet au franchisé de voir qu'une tablette s'en sert
    // encore avant de révoquer. Best effort, jamais bloquant.
    try { q("UPDATE ws_shop_device_token SET last_seen_at = NOW() WHERE id = ?", [(int) $r['id']]); }
    catch (Throwable $e) { /* trace seulement */ }
    return $cached = (int) $r['shop_id'];
  } catch (Throwable $e) { return $cached = null; }
}

/* Catalogue des SECTIONS du back-office franchisé, groupées comme au menu.
   Source unique : la console marque l'affiche pour composer les profils, et
   bo_role_presets() y puise pour les profils standard. */
function bo_sections_catalog() {
  return [
    'Pilotage'   => ['tdb' => 'Tableau de bord', 'statsReseau' => 'Stats réseau consolidées',
                     'rentabilite' => 'Rentabilité', 'params' => 'Coût-temps & coûts',
                     'geoAnalyse' => 'Analyse géographique'],
    'Vente'      => ['commandes' => 'Commandes du jour', 'stockJour' => 'Stock du jour',
                     'assortiment' => 'Assortiment & disponibilité', 'vouchers' => 'Bons / codes',
                     'pricingRules' => 'Règles de prix', 'remiseWebshop' => 'Remise auto webshop',
                     'paiements' => 'Moyens de paiement'],
    'Logistique' => ['prep' => 'Préparation / bon de chargement', 'tournees' => 'Constructeur de tournées',
                     'suivi' => 'Suivi temps réel', 'horaires' => 'Horaires des tournées',
                     'fermetures' => 'Fermetures ponctuelles', 'incidents' => 'Incidents',
                     'frais' => 'Frais de livraison', 'zones' => 'Zone de chalandise'],
    'Clients B2B'=> ['sites' => 'Sites de livraison', 'offices' => 'Offices (bureaux)',
                     'clients' => 'Bureaux (comptes B2B)', 'b2bClients' => 'Clients',
                     'bureauParams' => 'Paramètres livraison par bureau',
                     'emailsBureau' => 'Emails bureau', 'validations' => 'Validations',
                     'demandesBureau' => 'Demandes de rattachement bureau', 'demandesB2B' => 'Demandes B2B'],
    'Disponibilité' => ['creneaux' => 'Créneaux', 'capacite' => 'Capacité (calendrier)',
                     'reglesBoutique' => 'Disponibilité boutique', 'joursExcept' => 'Jours exceptionnels',
                     'calendarRules' => 'Règles calendrier', 'dispoCat' => 'Disponibilité par catégorie',
                     'dispoProd' => 'Disponibilité par produit'],
    'Réglages'   => ['shopParams' => 'Paramètres du shop'],
  ];
}

/* Profils STANDARD proposés à la marque (action explicite « créer les profils
   standard »). Ce ne sont pas des données de démonstration : rien n'est écrit
   sans un clic, et la marque reste libre de les modifier ou d'en créer d'autres. */
function bo_role_presets() {
  $toutes = [];
  foreach (bo_sections_catalog() as $items) { foreach ($items as $k => $lab) $toutes[] = $k; }
  return [
    ['key' => 'vendeur',     'label' => 'Vendeur (comptoir)',
     'sections' => ['tdb', 'commandes', 'stockJour', 'creneaux']],
    ['key' => 'preparateur', 'label' => 'Préparateur',
     'sections' => ['prep', 'commandes', 'stockJour', 'dispoProd', 'assortiment']],
    ['key' => 'chauffeur',   'label' => 'Chauffeur',
     'sections' => ['suivi', 'tournees', 'incidents', 'horaires']],
    ['key' => 'gerant',      'label' => 'Gérant boutique',
     'sections' => array_values(array_diff($toutes, ['rentabilite', 'params', 'statsReseau']))],
    ['key' => 'admin_boutique', 'label' => 'Admin boutique', 'sections' => $toutes],
  ];
}

/* Sections EFFECTIVES d'un compte tablette : celles de son profil (la marque
   les fixe). Repli sur les sections propres du compte pour les comptes créés
   avant le modèle par profils. */
function bo_user_sections($u) {
  if (!empty($u['role_sections'])) {
    $r = json_decode((string) $u['role_sections'], true);
    if (is_array($r) && $r) return $r;
  }
  if (!empty($u['sections'])) {
    $r = json_decode((string) $u['sections'], true);
    if (is_array($r)) return $r;
  }
  return [];
}

/* Section du back-office franchisé à laquelle appartient un endpoint. C'est
   cette table qui rend les droits RÉELS : sans elle, le cloisonnement n'était
   qu'un masquage de menu — la tablette n'avait de toute façon accès à AUCUNE
   donnée, /franchisee/* exigeant le jeton admin.
   Un endpoint absent de la table est REFUSÉ à une session PIN (jamais ouvert
   par défaut) ; l'admin ERP, lui, garde tout. */
function bo_endpoint_section($name) {
  static $MAP = [
    // Pilotage
    'kpis' => 'tdb', 'fr-alertes' => 'tdb', 'fr-tdb-tournees' => 'tdb', 'fr-tdb-tree' => 'tdb',
    'fr-net-stats' => 'statsReseau',
    'fr-rentabilite' => 'rentabilite', 'fr-renta-kpis' => 'rentabilite',
    'fr-cout-params' => 'params',
    'geo-clients' => 'geoAnalyse',
    // Vente
    'fr-orders' => 'commandes', 'order-status' => 'commandes',
    'fr-stock-catalog' => 'stockJour', 'stock-adjust' => 'stockJour', 'stock-set' => 'stockJour',
    'stock-threshold' => 'stockJour', 'stock-threshold-info' => 'stockJour',
    'stock-defaults' => 'stockJour', 'stock-product-orders' => 'stockJour',
    'fr-assortiment' => 'assortiment', 'assortiment-toggle' => 'assortiment', 'erp-portion-rules' => 'assortiment',
    'fr-vouchers' => 'vouchers', 'voucher' => 'vouchers', 'voucher-toggle' => 'vouchers',
    'ws-vouchers-local' => 'vouchers', 'fr-voucher-targets' => 'vouchers', 'client-voucher' => 'vouchers',
    'ws-pricing-rules-local' => 'pricingRules',
    'ws-payment-methods' => 'paiements',
    // Logistique
    'fr-prep-lines' => 'prep', 'fr-prep-points' => 'prep',
    'ws-tours' => 'tournees', 'ws-tour-postcodes' => 'tournees', 'tour-dispatch' => 'tournees',
    'tour-dispatch-status' => 'tournees', 'drivers' => 'tournees',
    'fr-live-table' => 'suivi', 'fr-live-drivers' => 'suivi', 'fr-live-eta' => 'suivi',
    'ws-tour-availability' => 'horaires', 'tour-simulate' => 'horaires', 'tour-windows' => 'horaires', 'atelier-save' => 'horaires',
    'ws-tour-closures' => 'fermetures',
    'fr-incidents' => 'incidents', 'client-complaint' => 'incidents',
    'ws-delivery-fee-rules' => 'frais',
    'ws-delivery-zones' => 'zones', 'ws-franchisor-catchment' => 'zones',
    'catchment-postcodes' => 'zones', 'zone-check' => 'zones',
    // Clients B2B
    'ws-office-delivery-sites' => 'sites',
    'ws-offices' => 'offices', 'onboard-office' => 'offices', 'route-office' => 'offices',
    'office-invite' => 'offices', 'invite-revoke' => 'offices',
    'office-invite-pdf' => 'offices', 'office-invite-send' => 'offices',
    'office-invite-poster' => 'offices',
    'office-delivery-setting' => 'bureauParams',
    'delivery-fee-rule' => 'frais',
    'calendar-rule' => 'calendarRules',
    'slot' => 'creneaux',
    'shop-exception' => 'joursExcept',
    'pricing-rule' => 'pricingRules',
    'product-availability' => 'dispoProd',
    'b2b-clients' => 'b2bClients', 'b2b-departments' => 'b2bClients', 'b2b-department' => 'b2bClients',
    'fr-clients' => 'b2bClients',
    'client-active' => 'b2bClients', 'client-attach' => 'b2bClients', 'client-billing' => 'b2bClients',
    'client-block' => 'b2bClients', 'client-office-delivery' => 'b2bClients', 'client-zip' => 'b2bClients',
    'client-orders' => 'b2bClients', 'vies-check' => 'b2bClients',
    'ws-office-delivery-settings' => 'bureauParams',
    'ws-office-emails' => 'emailsBureau', 'office-email' => 'emailsBureau',
    'fr-validations' => 'validations', 'validation-decide' => 'validations',
    'fr-join-requests' => 'demandesBureau', 'join-decide' => 'demandesBureau',
    'link-requests' => 'demandesBureau', 'link-decide' => 'demandesBureau',
    // Disponibilité
    'ws-slots' => 'creneaux',
    'fr-capacity' => 'capacite',
    'fr-shop-availability' => 'reglesBoutique', 'shop-availability' => 'reglesBoutique',
    'ws-shop-exceptions' => 'joursExcept',
    'ws-calendar-rules' => 'calendarRules',
    'fr-dispo-cats' => 'dispoCat',
    'ws-product-availability' => 'dispoProd',
    // Réglages
    'params' => 'shopParams',
    // 'save' n'est PAS ici : c'est l'écriture générique, sa section dépend de
    // la table visée (traitée à part dans la garde /franchisee/).
  ];
  // Toujours ouverts à une session PIN : identité et état d'interface. Ils ne
  // portent aucune donnée métier propre à une section.
  // 'reviews' : lecture des avis de SA PROPRE boutique (portée déjà bornée à la
  // session PIN dans la garde /franchisee/). Feedback client peu sensible, borné
  // au shop — ouvert à toute session plutôt que gated derrière une section RBAC.
  static $FREE = ['me' => true, 'bo-store' => true, 'reviews' => true];
  if (isset($FREE[$name])) return '*';
  return $MAP[$name] ?? null;
}

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
/* Recherche TVA par l'ERP (POST shops/{id}/clients/vat-lookup, champ
 * `vat_number`). L'ERP interroge D'ABORD sa base clients, PUIS VIES — il rend
 * donc deux choses que notre appel direct à VIES ne pouvait pas donner :
 *   • source:'database' + client_exists → la société est DÉJÀ connue du
 *     réseau : on évite de créer un doublon d'une fiche existante ;
 *   • source:'vies' → même service qu'avant, mais appelé par l'ERP.
 * Rend null si indisponible : l'appelant retombe sur l'appel VIES direct.
 * Ce n'est pas un repli inventé — c'est la même autorité, jointe autrement. */
function erp_vat_lookup($shopId, $vat) {
  if (!function_exists('erp_cfg')) return null;
  $cfg = erp_cfg();
  if ($cfg['base'] === '' || !$shopId) return null;
  $tok = function_exists('erp_token') ? erp_token() : '';
  if ($tok === '') return null;
  $ctx = stream_context_create(['http' => [
    'method' => 'POST', 'timeout' => 12, 'ignore_errors' => true,
    'header' => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer $tok\r\n",
    'content' => json_encode(['vat_number' => $vat]),
  ]]);
  $raw = @file_get_contents($cfg['base'] . '/shops/' . (int) $shopId . '/clients/vat-lookup', false, $ctx);
  $code = 0;
  foreach (($http_response_header ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int) $m[1];
  if ($raw === false || $code >= 400) {
    if (function_exists('erp_notes')) erp_notes('ERP vat-lookup HTTP ' . $code);
    return null;
  }
  $d = json_decode($raw, true);
  if (!is_array($d) || empty($d['found']) || !is_array($d['data'] ?? null)) return null;
  $x = $d['data'];
  // Forme attendue par le front (identique à celle de VIES), enrichie de ce que
  // seul l'ERP sait dire : la société est-elle déjà une fiche du réseau ?
  $adresse = trim(trim((string) ($x['street'] ?? '')) . ' ' . trim((string) ($x['street_number'] ?? '')));
  return [
    'valid' => true,
    'data' => [
      'vat'        => (string) ($x['tax_number'] ?? $vat),
      'country'    => substr((string) ($x['tax_number'] ?? $vat), 0, 2),
      'name'       => (string) ($x['company_name'] ?? ''),
      'address'    => $adresse,
      'postalCode' => (string) ($x['zip'] ?? ''),
      'city'       => (string) ($x['city'] ?? ''),
    ],
    'source'          => (string) ($d['source'] ?? 'erp'),
    'clientExists'    => !empty($d['client_exists']),
    'assignedToShop'  => !empty($d['assigned_to_shop']),
    'erpClientId'     => isset($x['id']) ? (int) $x['id'] : null,
  ];
}

function vies_lookup($rawVat, $shopId = null) {
  $vat = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $rawVat));
  if (strlen($vat) < 4 || !ctype_alpha(substr($vat, 0, 2))) {
    return ['valid' => false, 'error' => ['code' => 'invalid', 'message' => 'N° TVA invalide.']];
  }
  // L'ERP d'abord : il ajoute la déduplication contre les fiches existantes.
  if ($shopId) { $e = erp_vat_lookup($shopId, $vat); if ($e !== null) return $e; }
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

/* norm_phone() vit dans tel.php (partagé avec php-api/tests/tel_fiche_test.php). */

/* Crée une session Stripe Checkout via l'API REST (cURL). null si non configuré. */
/* Session de paiement Stripe.
   ⚠️ Le montant débité DOIT être ws_orders.total, pas la somme des lignes
   produit. La version précédente n'envoyait que les lignes : la REMISE (code
   promo, bon) et les FRAIS DE LIVRAISON étaient ignorés — un client avec un code
   −10 % était débité du prix plein, et les frais de livraison n'étaient jamais
   encaissés.
   On garde le détail des lignes quand il tombe juste (meilleur reçu Stripe) ;
   dès qu'il diffère du total, on envoie UNE ligne au montant exact de la
   commande. Le total fait foi dans tous les cas. */
function stripe_checkout($order, $lines) {
  $secret = cfg()['stripe_secret'];
  if (!$secret) return null;
  $total = round((float) ($order['total'] ?? 0), 2);
  if ($total <= 0) return false;                    // rien à encaisser : anomalie
  /* Le retour atterrit sur la page de l'app (?paid=1 / ?canceled=1, accueillis
     par le front). L'URL configurée est FIXE ; la commande, elle, connaît sa
     boutique : on l'ajoute pour que le retour rouvre le bon magasin au lieu du
     sélecteur. */
  $suc = (string) cfg()['checkout_success'];
  $can = (string) cfg()['checkout_cancel'];
  if (!empty($order['shop_id'])) {
    $suc .= (strpos($suc, '?') !== false ? '&' : '?') . 'shop=' . (int) $order['shop_id'];
    $can .= (strpos($can, '?') !== false ? '&' : '?') . 'shop=' . (int) $order['shop_id'];
  }
  $f = ['mode' => 'payment',
        'success_url' => $suc,
        'cancel_url' => $can,
        'metadata[order_id]' => $order['id'], 'metadata[order_ref]' => $order['order_ref'] ?? ''];
  $somme = 0.0;
  foreach ($lines as $l) $somme += ((float) $l['unit_price']) * ((int) $l['qty']);
  $somme = round($somme, 2);
  if (abs($somme - $total) > 0.01) {
    // Remise et/ou frais : une seule ligne, au montant réellement dû.
    $f['line_items[0][quantity]'] = 1;
    $f['line_items[0][price_data][currency]'] = 'eur';
    $f['line_items[0][price_data][unit_amount]'] = (int) round($total * 100);
    $f['line_items[0][price_data][product_data][name]'] =
      'Commande ' . ($order['order_ref'] ?? ('#' . $order['id']));
    return stripe_post($f, $secret);
  }
  $i = 0;
  foreach ($lines as $l) {
    $f["line_items[$i][quantity]"] = (int) $l['qty'];
    $f["line_items[$i][price_data][currency]"] = 'eur';
    $f["line_items[$i][price_data][unit_amount]"] = (int) round($l['unit_price'] * 100);
    $f["line_items[$i][price_data][product_data][name]"] = $l['product_name'];
    $i++;
  }
  return stripe_post($f, $secret);
}

function stripe_post($f, $secret) {
  $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_USERPWD => $secret . ':',
    CURLOPT_POSTFIELDS => http_build_query($f), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
  $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return ($code >= 200 && $code < 300) ? json_decode($res, true) : false;
}

/* ── Helpers campagnes « achat cumulé → cadeau » (accès DB ; logique pure = promo_lib.php) ── */

// Charge une campagne par id (ou null).
function promo_campaign($id) {
  return row("SELECT id, name, id_shop, is_active, starts_at, ends_at, threshold_amount,
                     currency, condition_scope, reward_product_id, reward_delivery_date,
                     voucher_code_prefix, per_customer_limit
                FROM ws_promo_campaign WHERE id = ?", [(int) $id]);
}

// Ligne progression+campagne par code cadeau (pour valider/consommer au checkout).
function promo_gift_row($code) {
  return row("SELECT pp.id, pp.campaign_id, pp.customer_ref, pp.unlocked_at, pp.redeemed_at,
                     c.reward_product_id, c.reward_delivery_date, c.id_shop
                FROM ws_promo_progress pp
                JOIN ws_promo_campaign c ON c.id = pp.campaign_id
               WHERE pp.voucher_code = ? LIMIT 1", [trim((string) $code)]);
}

// Produit cadeau (réplique du catalogue ERP) : id, nom, image (par convention si absente).
function promo_reward($productId) {
  $p = row("SELECT id, name, img FROM ws_products WHERE id = ?", [(int) $productId]);
  if (!$p) return null;
  $img = $p['img'] ?: (product_photo_files()[$p['id']] ?? null);
  return ['productId' => (int) $p['id'], 'name' => $p['name'], 'img' => $img];
}

// Vue publique d'une campagne (sans champs internes).
function promo_campaign_public($c) {
  return [
    'id'                 => (int) $c['id'],
    'name'               => $c['name'],
    'idShop'             => $c['id_shop'] !== null ? (int) $c['id_shop'] : null,
    'threshold'          => (float) $c['threshold_amount'],
    'currency'           => $c['currency'],
    'startsAt'           => $c['starts_at'],
    'endsAt'             => $c['ends_at'],
    'rewardDeliveryDate' => $c['reward_delivery_date'],
    'reward'             => promo_reward($c['reward_product_id']),
  ];
}

// Réponse de progression (GET progress / POST claim).
function promo_progress_payload($camp, $acc, $prog) {
  $amount = (float) $acc['amount'];
  return [
    'campaign'      => promo_campaign_public($camp),
    'accumulated'   => $amount,
    'remaining'     => promo_remaining($camp, $amount),
    'status'        => promo_status($camp, $amount, promo_now()),
    'unlocked'      => !empty($prog['unlocked_at']),
    'unlockedAt'    => $prog['unlocked_at']  ?? null,
    'voucherCode'   => $prog['voucher_code'] ?? null,
    'redeemedAt'    => $prog['redeemed_at']  ?? null,
    'ordersCounted' => count($acc['orders']),
  ];
}
