<?php
/* sync_product_photos.php — télécharge les PHOTOS PRODUIT depuis l'API ERP
 * (Franchise Buddy) vers assets/product_pictures/{id_product}.{ext}.
 *
 * POURQUOI UN TÉLÉCHARGEMENT, ET PAS UN LIEN. L'ERP sert la photo d'une
 * recette via GET {base}/recipes/{id} — clés shop_photo_path, main_photo_path,
 * photo_1..3_path — mais l'URL rendue est SIGNÉE (Cloudflare R2,
 * X-Amz-Expires=3600) : elle meurt en une heure. La stocker dans
 * ws_products.img ou la servir au navigateur afficherait des images cassées.
 * On la consomme donc ici, côté serveur, en la déposant là où TOUT le
 * pipeline existant la lit déjà :
 *   assets/product_pictures/{id}.{png|jpg|webp}
 *     → product_photo_files() la sert au catalogue (index.php),
 *     → sync_product_images.php la câble dans ws_products.img.
 * À exécuter AVANT sync_product_images.php.
 *
 * RÈGLES — L'ERP EST LA SOURCE UNIQUE (décision du 23/08 : « effacer tous
 * les manuels, tout doit venir de l'ERP API ») :
 *  - la photo d'un produit est celle de sa recette (shop_photo_path d'abord,
 *    la vitrine, puis main_photo, puis photo_1..3) ;
 *  - un fichier local qui ne correspond plus à l'ERP est REMPLACÉ ; un
 *    fichier local dont l'ERP n'a PAS de photo est SUPPRIMÉ — l'écran montre
 *    alors l'illustration de repli, et /erp/photos-report dit où poser la
 *    photo (dans Franchise Buddy, nulle part ailleurs) ;
 *  - le MANIFESTE (.erp_photos.json) mémorise l'objet source de chaque
 *    fichier (URL sans signature) : tant que l'objet ERP ne change pas, rien
 *    n'est retéléchargé ; un fichier hors manifeste est simplement adopté ;
 *  - --force retélécharge tout, manifeste ignoré ;
 *  - un fichier n'est écrit QUE si le téléchargement aboutit ET que le
 *    contenu est réellement une image (signature JPEG/PNG/WebP) — jamais un
 *    HTML d'erreur enregistré en .jpg ; écriture atomique (tmp + rename) ;
 *  - recette sans photo, produit sans recette : on ne fabrique rien, on
 *    compte et on le dit ;
 *  - API non configurée (ws_param.erp_api_base/erp_api_token absents et pas
 *    de WS_ERP_BASE/WS_ERP_TOKEN en env) → sortie propre, déploiement intact.
 *
 * COÛT : un appel API par recette et par balayage (aucun endpoint en lot
 * côté ERP — cf. ENDPOINTS_WEBSHOP.md §C.6) ; le téléchargement de l'image,
 * lui, n'a lieu que si l'objet source a changé.
 *
 * Options : --limit=N  --dry-run  --force  --only=6700106,6700237
 * Env     : WS_ERP_BASE, WS_ERP_TOKEN (priment sur ws_param — utile en test)
 */

/* ── HTTP : curl si présent (honore les proxys d'environnement), sinon flux. ── */
function spp_http_get($url, array $headers = [], $timeout = 20, $post = null) {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
      CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
      CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($post !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) return [0, null, $err];
    return [$code, $body, ''];
  }
  $opt = ['method' => $post !== null ? 'POST' : 'GET', 'timeout' => $timeout,
          'ignore_errors' => true, 'header' => implode("\r\n", $headers)];
  if ($post !== null) $opt['content'] = $post;
  $ctx = stream_context_create(['http' => $opt]);
  $body = @file_get_contents($url, false, $ctx);
  if ($body === false) return [0, null, 'flux HTTP en échec'];
  $code = 0;
  foreach (($http_response_header ?? []) as $h) {
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int) $m[1];
  }
  return [$code, $body, ''];
}

/* ── La photo d'UNE recette : l'URL à suivre, ou null + motif. ──
 * shop_photo_path d'abord (la photo pensée pour la vitrine, dixit l'ERP),
 * puis main_photo_path, puis photo_1..3 — première non vide. */
function spp_photo_url(array $recipe) {
  foreach (['shop_photo_path', 'main_photo_path', 'photo_1_path', 'photo_2_path', 'photo_3_path'] as $k) {
    $v = trim((string) ($recipe[$k] ?? ''));
    if ($v !== '') return $v;
  }
  return null;
}

/* ── Signature du contenu : extension sûre, ou null si ce n'est PAS une image. ── */
function spp_image_ext($bytes) {
  if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) return 'jpg';
  if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) return 'png';
  if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') return 'webp';
  return null;
}

/* ── Identité STABLE d'une photo ERP : l'URL sans sa signature (la partie
 * X-Amz-* change à chaque appel, l'objet R2, lui, ne change que si la photo
 * change réellement). C'est elle qu'on compare pour décider d'un
 * rafraîchissement. ── */
function spp_src_id($url) { return explode('?', (string) $url, 2)[0]; }

/* ── Manifeste des fichiers écrits par CE script : [id => ['file','src']].
 * Illisible ou absent → tableau vide, donc tout fichier présent est réputé
 * MANUEL : une corruption ne peut jamais autoriser un écrasement. ── */
function spp_manifest_load($dir) {
  $f = "$dir/.erp_photos.json";
  if (!is_file($f)) return [];
  $m = json_decode((string) @file_get_contents($f), true);
  return is_array($m) ? $m : [];
}
function spp_manifest_save($dir, array $m) {
  $tmp = "$dir/.erp_photos.json.tmp";
  ksort($m);
  if (@file_put_contents($tmp, json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) return false;
  return @rename($tmp, "$dir/.erp_photos.json");
}

/* ── Fichier photo local existant d'un produit (n'importe quelle extension). ── */
function spp_existing($dir, $id) {
  foreach (['png', 'jpg', 'jpeg', 'webp'] as $e) {
    if (is_file("$dir/$id.$e")) return "$id.$e";
  }
  return null;
}

/* ── Synchronise une liste [ [id_product, id_recipe], … ]. Rend les compteurs.
 * cfg: base, token, dir, dry, force — et http (injectable, tests hors réseau). ── */
function spp_run(array $paires, $cfg) {
  $dir = $cfg['dir'];
  $http = $cfg['http'] ?? 'spp_http_get';
  if (!is_dir($dir) && !$cfg['dry'] && !@mkdir($dir, 0755, true)) {
    fwrite(STDERR, "⚠ impossible de créer $dir\n");
    return null;
  }
  /* Politique de cache DU DOSSIER, posée une fois pour toutes (relevé d'audit :
     les photos partaient sans Cache-Control — le navigateur appliquait sa
     fraîcheur heuristique et gardait l'ANCIENNE image après un
     rafraîchissement, le nom de fichier ne changeant pas). no-cache = l'image
     est revalidée à chaque affichage : avec l'ETag d'Apache ça coûte un 304,
     pas un retéléchargement. Le manifeste et le journal, eux, n'ont rien à
     faire en HTTP public. Fichier existant jamais réécrit : la boutique peut
     l'ajuster à la main. */
  $ht = "$dir/.htaccess";
  if (!$cfg['dry'] && !is_file($ht)) {
    @file_put_contents($ht,
      "<IfModule mod_headers.c>\n  Header set Cache-Control \"no-cache, must-revalidate\"\n</IfModule>\n"
      . "<FilesMatch \"\\.(json|log)$\">\n  Require all denied\n</FilesMatch>\n");
  }
  $man = spp_manifest_load($dir);
  $c = ['produits' => count($paires), 'a_jour' => 0, 'sans_photo' => 0,
        'telecharges' => 0, 'rafraichis' => 0, 'adoptes' => 0, 'supprimes' => 0,
        'echecs_api' => 0, 'echecs_dl' => 0, 'non_image' => 0];
  $auth = $cfg['token'] !== '' ? ['Authorization: Bearer ' . $cfg['token']] : [];
  foreach ($paires as [$pid, $rid]) {
    $pid = (int) $pid; $rid = (int) $rid;
    $deja = spp_existing($dir, $pid);
    $gere = isset($man[$pid]);                        // écrit par ce script ?

    [$code, $body] = $http($cfg['base'] . '/recipes/' . $rid, array_merge($auth, ['Accept: application/json']), 15);
    $rec = ($code === 200 && $body !== null) ? json_decode($body, true) : null;
    if (!is_array($rec)) {
      $c['echecs_api']++;
      fwrite(STDERR, "  échec API recette $rid (produit $pid) : HTTP $code\n");
      continue;
    }
    $url = spp_photo_url($rec);
    if ($url === null) {
      // L'ERP n'a pas de photo pour ce produit : il n'y en a pas, point —
      // un fichier local qui traîne (ancien manuel, photo retirée de l'ERP)
      // est SUPPRIMÉ, l'illustration de repli prend la place.
      if ($deja !== null) {
        if (!$cfg['dry']) { @unlink("$dir/$deja"); unset($man[$pid]); }
        $c['supprimes']++;
        fwrite(STDERR, "  produit $pid : aucune photo côté ERP — fichier local retiré ($deja)\n");
      } else $c['sans_photo']++;
      continue;
    }
    $src = spp_src_id($url);
    if (!$cfg['force'] && $deja !== null && $gere && ($man[$pid]['src'] ?? '') === $src) { $c['a_jour']++; continue; }

    if ($cfg['dry']) {
      echo '  [dry-run] produit ' . $pid . ' : '
         . ($deja === null ? 'nouvelle photo' : (!$gere ? 'fichier hors ERP à remplacer' : 'rafraîchissement')) . "\n";
      $deja === null ? $c['telecharges']++ : (!$gere ? $c['adoptes']++ : $c['rafraichis']++);
      continue;
    }
    [$dcode, $img] = $http($url, [], 30);
    if ($dcode !== 200 || $img === null || $img === '') {
      $c['echecs_dl']++;
      fwrite(STDERR, "  échec téléchargement produit $pid : HTTP $dcode\n");
      continue;
    }
    $ext = spp_image_ext($img);
    if ($ext === null) {                     // du HTML d'erreur en .jpg = image cassée partout
      $c['non_image']++;
      fwrite(STDERR, "  contenu non-image pour le produit $pid — rien d'écrit\n");
      continue;
    }
    $tmp = "$dir/.$pid.$ext.tmp";
    if (@file_put_contents($tmp, $img) !== strlen($img) || !@rename($tmp, "$dir/$pid.$ext")) {
      @unlink($tmp);
      $c['echecs_dl']++;
      fwrite(STDERR, "  écriture impossible pour le produit $pid\n");
      continue;
    }
    if ($deja !== null && $deja !== "$pid.$ext") @unlink("$dir/$deja");   // une seule photo par produit
    if ($deja === null) $c['telecharges']++;
    elseif (!$gere) $c['adoptes']++;              // fichier hors manifeste remplacé par l'ERP
    else $c['rafraichis']++;
    $man[$pid] = ['file' => "$pid.$ext", 'src' => $src];
  }
  if (!$cfg['dry'] && !spp_manifest_save($dir, $man)) {
    // Sans manifeste sauvé, les fichiers écrits passeraient pour MANUELS au
    // prochain passage : plus aucun rafraîchissement. C'est un échec à voir.
    fwrite(STDERR, "⚠ manifeste non écrit ($dir/.erp_photos.json) — les rafraîchissements futurs sont compromis\n");
  }
  return $c;
}

/* ═══ Point d'entrée CLI (inerte quand le fichier est inclus par un test). ═══ */
if (!defined('WS_PHOTOS_AS_LIB')) {
  $opt = ['limit' => 0, 'dry' => false, 'force' => false, 'only' => []];
  foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) $opt['limit'] = (int) $m[1];
    elseif ($a === '--dry-run') $opt['dry'] = true;
    elseif ($a === '--force') $opt['force'] = true;
    elseif (preg_match('/^--only=([\d,]+)$/', $a, $m)) $opt['only'] = array_map('intval', explode(',', $m[1]));
    else { fwrite(STDERR, "option inconnue : $a\n"); exit(2); }
  }

  $cfgFile = require __DIR__ . '/config.php';
  $d = $cfgFile['db'];
  if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "⚠ sync_product_photos : pdo_mysql absent du PHP CLI — photos non synchronisées, le reste est intact.\n");
    exit(0);
  }
  $pdo = new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4",
                 $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

  /* Adresse + jeton ERP : env d'abord (test), sinon ws_param — le même
     paramétrage que erp_alias.php, réglable en base sans redéploiement. */
  $param = function ($k) use ($pdo) {
    try {
      $s = $pdo->prepare("SELECT param_value FROM ws_param WHERE param_key = ? LIMIT 1");
      $s->execute([$k]);
      return (string) ($s->fetchColumn() ?: '');
    } catch (Throwable $e) { return ''; }
  };
  $base  = rtrim((string) (getenv('WS_ERP_BASE') ?: $param('erp_api_base')), '/');
  $token = (string) (getenv('WS_ERP_TOKEN') ?: $param('erp_api_token'));
  if ($base === '') {
    echo "sync_product_photos : API ERP non configurée (ws_param.erp_api_base) — rien à faire.\n";
    exit(0);
  }
  /* Reconnexion automatique (mêmes clés que erp_alias.php) : le jeton
     consultant expire en 30 min, un jeton statique meurt donc vite. Si les
     identifiants sont posés, un jeton FRAIS est pris pour ce balayage — le
     statique reste le repli si la connexion échoue. */
  $aph = (string) ($param('erp_auth_phone') ?: '');
  $apw = (string) ($param('erp_auth_password') ?: '');
  if ($aph !== '' && $apw !== '') {
    [$lc, $lb] = spp_http_get($base . '/consultant/auth/login', ['Content-Type: application/json', 'Accept: application/json'], 15,
                              json_encode(['phone' => $aph, 'password' => $apw]));
    $ld = ($lc === 200 && $lb !== null) ? json_decode($lb, true) : null;
    $fresh = is_array($ld) ? (string) ($ld['access_token'] ?? '') : '';
    if ($fresh !== '') $token = $fresh;
    else fwrite(STDERR, "  ⚠ reconnexion ERP refusée — jeton statique utilisé en repli\n");
  }

  /* Produits ACTIFS du webshop → leur recette. Le lien produit→recette vient
     de L'API D'ABORD (shops/{ref}/products/available, UN appel) : la table
     locale `product` est un réplica, et il a déjà menti — constaté en
     production sur « Salade Chèvre » (2130006) : recette liée dans tfbuddy à
     11 h, réplica local encore à NULL, produit invisible pour la synchro
     alors que sa photo attendait. Le réplica ne sert plus que de REPLI pour
     les produits absents de la réponse API (ou si l'appel échoue). */
  try {
    $sql = "SELECT p.id, erp.id_recipe
              FROM ws_products p
              LEFT JOIN product erp ON erp.id = p.id
             WHERE p.active = 1";
    $rows0 = $pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
  } catch (Throwable $e) {
    fwrite(STDERR, "⚠ sync_product_photos : lecture ws_products/product impossible — " . $e->getMessage() . "\n");
    exit(0);
  }
  $apiRec = [];
  $apiRows = [];   // lignes complètes de available — servent aussi au miroir des canaux
  // Boutique de référence pour l'appel available (le lien recette est commun
  // à toutes) : erp_ref_shop si posé, sinon la première boutique webshop.
  $refShop = (int) ($param('erp_ref_shop') ?: 0);
  if ($refShop <= 0) {
    try { $refShop = (int) $pdo->query("SELECT MIN(id) FROM shops WHERE webshop_enabled = 1")->fetchColumn(); }
    catch (Throwable $e) { $refShop = 0; }
  }
  if ($refShop > 0) {
    [$ac, $ab] = spp_http_get($base . '/shops/' . $refShop . '/products/available',
                              array_merge($token !== '' ? ['Authorization: Bearer ' . $token] : [], ['Accept: application/json']), 30);
    $al = ($ac === 200 && $ab !== null) ? json_decode($ab, true) : null;
    if (is_array($al) && !array_is_list($al)) {
      foreach (['data', 'items', 'results', 'products'] as $k) {
        if (isset($al[$k]) && is_array($al[$k])) { $al = $al[$k]; break; }
      }
    }
    if (is_array($al) && array_is_list($al)) {
      foreach ($al as $pr) {
        if (!is_array($pr) || empty($pr['id'])) continue;
        $apiRows[(int) $pr['id']] = $pr;
        if (!empty($pr['id_recipe'])) $apiRec[(int) $pr['id']] = (int) $pr['id_recipe'];
      }
    }
    if (!$apiRec) fwrite(STDERR, "  ⚠ available (boutique $refShop) illisible — liens recette pris du réplica local seul\n");
  }
  $rows = [];
  $dirPhotos = __DIR__ . '/../assets/product_pictures';
  foreach ($rows0 as [$pid, $ridLocal]) {
    $rid = $apiRec[(int) $pid] ?? (int) $ridLocal;   // l'API prime, le réplica complète
    if ($rid > 0) { $rows[] = [(int) $pid, $rid]; continue; }
    /* Produit actif SANS recette : le balayage ne le traite jamais, donc un
       vieux fichier manuel y survivait indéfiniment (constaté : 6700098,
       « Thon provençal »). Source unique oblige : on le retire ici — hors
       --only/--limit, qui ne restreignent que les téléchargements. */
    if (!$opt['dry'] && !$opt['only'] && ($f = spp_existing($dirPhotos, (int) $pid)) !== null) {
      @unlink("$dirPhotos/$f");
      fwrite(STDERR, "  produit $pid : sans recette ERP — fichier manuel retiré ($f)\n");
    }
  }
  if ($opt['only']) $rows = array_values(array_filter($rows, fn ($r) => in_array((int) $r[0], $opt['only'], true)));
  if ($opt['limit'] > 0) $rows = array_slice($rows, 0, $opt['limit']);

  /* ── MIROIR DES CANAUX (livraison Franchise Buddy du 23/08). L'ERP porte
     désormais webshop_active / click_and_collect / office_delivery : quand
     ws_param.channels_source vaut 'erp', ces trois bascules PILOTENT
     ws_products (active, click_and_collect, office_delivery) — la console
     marque cesse d'être l'écriture de référence sur ces colonnes.
     GARDE-FOU : webshop_active naît à 0 côté ERP. Si le miroir devait
     désactiver TOUT le catalogue (aucun webshop_active=1 alors que des
     produits sont actifs ici), c'est que les bascules n'ont pas encore été
     posées dans Franchise Buddy — on REFUSE et on le dit, plutôt que de
     vider la boutique. Inerte tant que le paramètre n'est pas posé. ── */
  if (!$opt['dry'] && !$opt['only'] && strtolower((string) ($param('channels_source') ?: '')) === 'erp' && $apiRows) {
    $erpOn = 0;
    foreach ($apiRows as $pr) if (!empty($pr['webshop_active'])) $erpOn++;
    $wsOn = (int) $pdo->query("SELECT COUNT(*) FROM ws_products WHERE active = 1")->fetchColumn();
    if ($erpOn === 0 && $wsOn > 0) {
      fwrite(STDERR, "  ⚠ canaux ERP : webshop_active=0 PARTOUT alors que $wsOn produit(s) sont actifs ici — miroir refusé (poser les bascules dans Franchise Buddy d'abord)\n");
    } else {
      /* Lignes EXISTANTES : canaux + nom source + taxonomie suivis. Le mapping
         est celui relevé en production : id_category ERP = SOUS-catégorie
         webshop (32100 Cookies…), category.groups[0].id = catégorie (26
         Traiteur…) — mêmes espaces d'identifiants. */
      $upd = $pdo->prepare("UPDATE ws_products
                               SET active = ?, click_and_collect = ?, office_delivery = ?,
                                   name = ?, cat_id = COALESCE(?, cat_id), sub_cat_id = COALESCE(?, sub_cat_id)
                             WHERE id = ? AND (active <> ? OR COALESCE(click_and_collect,1) <> ? OR COALESCE(office_delivery,1) <> ?
                                               OR name <> ? OR cat_id <> COALESCE(?, cat_id) OR COALESCE(sub_cat_id,0) <> COALESCE(?, sub_cat_id, 0))");
      /* Produit FB ABSENT d'ici : CRÉÉ — c'est ce qui fait de l'ERP le
         commandant de l'assortiment. price=0 volontaire : « prix non fixé »
         masque le produit du catalogue ET le refuse à la commande (règle
         prix_produits) — il apparaîtra tout seul dès qu'un prix sera posé.
         /erp/photos-report les liste sous sans_prix. */
      $ins = $pdo->prepare("INSERT INTO ws_products (id, name, price, active, click_and_collect, office_delivery, cat_id, sub_cat_id)
                            VALUES (?, ?, 0, ?, ?, ?, ?, ?)");
      $existe = [];
      foreach ($pdo->query("SELECT id FROM ws_products")->fetchAll(PDO::FETCH_COLUMN) as $x) $existe[(int) $x] = true;
      $chg = 0; $crees = 0;
      foreach ($apiRows as $pid => $pr) {
        if (!isset($pr['webshop_active'], $pr['click_and_collect'], $pr['office_delivery'])) continue;
        $a = (int) !!$pr['webshop_active']; $cc = (int) !!$pr['click_and_collect']; $od = (int) !!$pr['office_delivery'];
        $nom = trim((string) ($pr['base_name'] ?? ($pr['name'] ?? '')));
        $sub = !empty($pr['id_category']) ? (int) $pr['id_category'] : null;
        $cat = (isset($pr['category']['groups'][0]['id'])) ? (int) $pr['category']['groups'][0]['id'] : null;
        if (isset($existe[(int) $pid])) {
          if ($nom === '') continue;
          $upd->execute([$a, $cc, $od, $nom, $cat, $sub, (int) $pid, $a, $cc, $od, $nom, $cat, $sub]);
          $chg += $upd->rowCount();
        } elseif ($a && $nom !== '') {
          try { $ins->execute([(int) $pid, $nom, $a, $cc, $od, $cat, $sub]); $crees++; }
          catch (Throwable $e) { fwrite(STDERR, "  ⚠ création $pid impossible : " . $e->getMessage() . "\n"); }
        }
      }
      echo "assortiment ERP → ws_products : $erpOn webshop_active côté ERP, $chg mise(s) à jour, $crees créé(s) (masqués tant que sans prix).\n";
      /* Actifs ICI absents de l'ERP : on ne les coupe PAS (porteurs de menus,
         produits du jour locaux) mais on les NOMME — l'écart doit se voir. */
      $orphelins = [];
      foreach ($pdo->query("SELECT id, name FROM ws_products WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($apiRows[(int) $r['id']])) $orphelins[] = $r['id'] . ' ' . $r['name'];
      }
      if ($orphelins) echo "  actifs hors ERP (conservés, à créer dans Franchise Buddy si voulus là-bas) : " . implode(' · ', array_slice($orphelins, 0, 10)) . "\n";
    }
  }

  $c = spp_run($rows, ['base' => $base, 'token' => $token, 'dir' => __DIR__ . '/../assets/product_pictures',
                       'dry' => $opt['dry'], 'force' => $opt['force']]);
  if ($c === null) exit(1);
  echo "sync_product_photos (source unique : ERP) : {$c['produits']} produit(s) avec recette ; "
     . "{$c['telecharges']} nouvelle(s), {$c['rafraichis']} rafraîchie(s), {$c['adoptes']} fichier(s) hors ERP remplacé(s), "
     . "{$c['supprimes']} supprimé(s) (plus de photo côté ERP), {$c['a_jour']} à jour ; {$c['sans_photo']} recette(s) sans photo ; "
     . "échecs : {$c['echecs_api']} API, {$c['echecs_dl']} téléchargement, {$c['non_image']} contenu non-image.\n";
  echo "→ lancer ensuite sync_product_images.php pour câbler ws_products.img.\n";
}
