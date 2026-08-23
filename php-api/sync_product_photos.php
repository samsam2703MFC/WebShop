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
 * RÈGLES (« aucune donnée inventée, aucun repli ») :
 *  - une photo DÉPOSÉE fait autorité : si {id}.* existe déjà, on n'y touche
 *    pas (--force pour la remplacer par celle de l'ERP) ;
 *  - un fichier n'est écrit QUE si le téléchargement aboutit ET que le
 *    contenu est réellement une image (signature JPEG/PNG/WebP) — jamais un
 *    HTML d'erreur enregistré en .jpg ; écriture atomique (tmp + rename) ;
 *  - recette sans photo, produit sans recette : on ne fabrique rien, on
 *    compte et on le dit ;
 *  - API non configurée (ws_param.erp_api_base/erp_api_token absents et pas
 *    de WS_ERP_BASE/WS_ERP_TOKEN en env) → sortie propre, déploiement intact.
 *
 * COÛT : un appel API par recette (aucun endpoint en lot côté ERP —
 * cf. ENDPOINTS_WEBSHOP.md §C.6). Les produits déjà pourvus d'un fichier
 * sont ignorés sans appel ; les recettes vues sans photo sont recontrôlées à
 * chaque exécution (c'est voulu : la photo peut arriver côté ERP).
 *
 * Options : --limit=N  --dry-run  --force  --only=6700106,6700237
 * Env     : WS_ERP_BASE, WS_ERP_TOKEN (priment sur ws_param — utile en test)
 */

/* ── HTTP : curl si présent (honore les proxys d'environnement), sinon flux. ── */
function spp_http_get($url, array $headers = [], $timeout = 20) {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
      CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
      CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) return [0, null, $err];
    return [$code, $body, ''];
  }
  $ctx = stream_context_create(['http' => [
    'method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true,
    'header' => implode("\r\n", $headers),
  ]]);
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

/* ── Fichier photo local existant d'un produit (n'importe quelle extension). ── */
function spp_existing($dir, $id) {
  foreach (['png', 'jpg', 'jpeg', 'webp'] as $e) {
    if (is_file("$dir/$id.$e")) return "$id.$e";
  }
  return null;
}

/* ── Synchronise une liste [ [id_product, id_recipe], … ]. Rend les compteurs. ── */
function spp_run(array $paires, $cfg) {
  $dir = $cfg['dir'];
  if (!is_dir($dir) && !$cfg['dry'] && !@mkdir($dir, 0755, true)) {
    fwrite(STDERR, "⚠ impossible de créer $dir\n");
    return null;
  }
  $c = ['produits' => count($paires), 'existants' => 0, 'sans_photo' => 0,
        'telecharges' => 0, 'remplaces' => 0, 'echecs_api' => 0, 'echecs_dl' => 0, 'non_image' => 0];
  $auth = $cfg['token'] !== '' ? ['Authorization: Bearer ' . $cfg['token']] : [];
  foreach ($paires as [$pid, $rid]) {
    $pid = (int) $pid; $rid = (int) $rid;
    $deja = spp_existing($dir, $pid);
    if ($deja !== null && !$cfg['force']) { $c['existants']++; continue; }

    [$code, $body] = spp_http_get($cfg['base'] . '/recipes/' . $rid, array_merge($auth, ['Accept: application/json']), 15);
    $rec = ($code === 200 && $body !== null) ? json_decode($body, true) : null;
    if (!is_array($rec)) {
      $c['echecs_api']++;
      fwrite(STDERR, "  échec API recette $rid (produit $pid) : HTTP $code\n");
      continue;
    }
    $url = spp_photo_url($rec);
    if ($url === null) { $c['sans_photo']++; continue; }
    if ($cfg['dry']) { echo "  [dry-run] produit $pid ← recette $rid : photo disponible\n"; $c['telecharges']++; continue; }

    [$dcode, $img] = spp_http_get($url, [], 30);
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
    // --force : une seule photo par produit — l'ancienne, si autre extension, part.
    if ($deja !== null && $deja !== "$pid.$ext") { @unlink("$dir/$deja"); $c['remplaces']++; }
    else $c['telecharges']++;
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

  /* Produits ACTIFS du webshop → leur recette. `product` est la table ERP de
     la même base (lecture seule, cf. AUDIT_API_VS_DB.md §3) : c'est elle qui
     porte id_recipe. Table absente → on le dit et on sort proprement. */
  try {
    $sql = "SELECT p.id, erp.id_recipe
              FROM ws_products p
              JOIN product erp ON erp.id = p.id
             WHERE p.active = 1 AND erp.id_recipe IS NOT NULL AND erp.id_recipe > 0";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
  } catch (Throwable $e) {
    fwrite(STDERR, "⚠ sync_product_photos : table `product` (ERP) illisible — " . $e->getMessage() . "\n");
    exit(0);
  }
  if ($opt['only']) $rows = array_values(array_filter($rows, fn ($r) => in_array((int) $r[0], $opt['only'], true)));
  if ($opt['limit'] > 0) $rows = array_slice($rows, 0, $opt['limit']);

  $c = spp_run($rows, ['base' => $base, 'token' => $token, 'dir' => __DIR__ . '/../assets/product_pictures',
                       'dry' => $opt['dry'], 'force' => $opt['force']]);
  if ($c === null) exit(1);
  echo "sync_product_photos : {$c['produits']} produit(s) avec recette ; "
     . "{$c['telecharges']} téléchargé(s), {$c['remplaces']} remplacé(s), {$c['existants']} déjà pourvu(s) (fichier local, prioritaire) ; "
     . "{$c['sans_photo']} recette(s) sans photo ; "
     . "échecs : {$c['echecs_api']} API, {$c['echecs_dl']} téléchargement, {$c['non_image']} contenu non-image.\n";
  echo "→ lancer ensuite sync_product_images.php pour câbler ws_products.img.\n";
}
