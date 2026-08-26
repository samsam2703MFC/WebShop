<?php
/* sync_season_photos.php — télécharge les PHOTOS DE GAMME depuis l'API ERP
 * (GET {base}/product-availability-periods, champ `photo`) vers
 * assets/season_pictures/{id_periode}.{ext}.
 *
 * POURQUOI RAPATRIER. L'URL rendue par l'ERP est une URL R2 SIGNÉE qui expire
 * en 1200 s (20 min). La servir au navigateur donnerait des vignettes mortes
 * vingt minutes après le chargement de la page — et un catalogue mis en cache
 * les servirait bien plus longtemps. Le fichier est donc copié côté serveur,
 * exactement comme les photos produit.
 *
 * DIFFÉRENCE avec sync_product_photos : un SEUL appel suffit — la collection
 * porte déjà l'URL de chaque photo. Pas d'appel par élément.
 *
 * Ce script réutilise les briques de sync_product_photos.php (manifeste,
 * écriture atomique, contrôle de signature d'image) : une seule
 * implémentation de ces règles, donc une seule à corriger.
 *   - le MANIFESTE (.erp_photos.json) mémorise l'objet source de chaque
 *     fichier écrit ICI ; un fichier hors manifeste est réputé MANUEL et n'est
 *     jamais écrasé — sauf --force ;
 *   - une photo retirée côté ERP supprime le fichier qu'on avait écrit (et lui
 *     seul) : le webshop ne doit pas montrer ce que l'ERP ne montre plus ;
 *   - --dry-run n'écrit rien.
 *
 * Usage :  php sync_season_photos.php [--dry-run] [--force]
 */

define('WS_PHOTOS_AS_LIB', 1);          // rend le point d'entrée CLI de l'autre script inerte
require __DIR__ . '/sync_product_photos.php';

/* L'URL de la photo d'une période, ou '' — la charge peut porter `photo` en
 * objet {url,…} (forme actuelle) ou directement en chaîne. On ne devine pas
 * au-delà : une forme inconnue vaut « pas de photo », pas une URL fabriquée. */
function ssp_photo_url(array $periode) {
  $p = $periode['photo'] ?? null;
  if (is_string($p)) return trim($p);
  if (is_array($p))  return trim((string) ($p['url'] ?? ''));
  return '';
}

/* Synchronise les périodes reçues. cfg : base, token, dir, dry, force, http. */
function ssp_run(array $periodes, $cfg) {
  $dir  = $cfg['dir'];
  $http = $cfg['http'] ?? 'spp_http_get';
  if (!is_dir($dir) && !$cfg['dry'] && !@mkdir($dir, 0755, true)) {
    fwrite(STDERR, "⚠ impossible de créer $dir\n");
    return null;
  }
  // Même politique de cache que les photos produit : no-cache, sinon le
  // navigateur garde l'ancienne image (le nom de fichier ne change pas).
  $ht = "$dir/.htaccess";
  if (!$cfg['dry'] && !is_file($ht)) {
    @file_put_contents($ht,
      "<IfModule mod_headers.c>\n  Header set Cache-Control \"no-cache, must-revalidate\"\n</IfModule>\n"
      . "<FilesMatch \"\\.(json|log)$\">\n  Require all denied\n</FilesMatch>\n");
  }

  $man = spp_manifest_load($dir);
  $c = ['periodes' => count($periodes), 'a_jour' => 0, 'sans_photo' => 0,
        'telecharges' => 0, 'rafraichis' => 0, 'adoptes' => 0, 'supprimes' => 0,
        'echecs_dl' => 0, 'non_image' => 0];

  foreach ($periodes as $per) {
    $id = (int) ($per['id'] ?? 0);
    if (!$id) continue;
    $deja = spp_existing($dir, $id);
    $gere = isset($man[$id]);                       // fichier écrit par ce script ?
    $url  = ssp_photo_url($per);

    if ($url === '') {
      $c['sans_photo']++;
      /* Photo RETIRÉE côté ERP : on efface ce qu'on avait écrit, et rien
         d'autre. Une image posée à la main reste — elle n'est pas à nous. */
      if ($deja && $gere && !$cfg['dry']) {
        @unlink("$dir/$deja"); unset($man[$id]); $c['supprimes']++;
      }
      continue;
    }

    // L'objet R2 sans sa signature : c'est lui qui dit si la photo a changé.
    $src = spp_src_id($url);
    if (!$cfg['force'] && $deja && $gere && ($man[$id]['src'] ?? '') === $src) { $c['a_jour']++; continue; }
    // Fichier présent mais HORS manifeste = posé à la main : on n'y touche pas.
    if (!$cfg['force'] && $deja && !$gere) { $c['a_jour']++; continue; }

    [$code, $bytes] = $http($url, [], 25);
    if ($code !== 200 || $bytes === null || $bytes === '') { $c['echecs_dl']++; continue; }
    /* Le corps doit être une IMAGE. Sans ce contrôle, une page d'erreur R2
       (XML) s'écrirait sous un nom .webp et la vignette serait cassée sans
       que rien ne le signale. */
    $ext = spp_image_ext($bytes);
    if ($ext === null) { $c['non_image']++; continue; }

    if (!$cfg['dry']) {
      // Écriture ATOMIQUE : jamais de fichier à moitié écrit servi par Apache.
      $tmp = "$dir/.$id.tmp";
      if (@file_put_contents($tmp, $bytes) === false) { $c['echecs_dl']++; continue; }
      // L'extension peut changer (png → webp) : l'ancien fichier doit partir,
      // sinon deux fichiers coexistent et c'est l'ancien qui est servi.
      if ($deja && $deja !== "$id.$ext") @unlink("$dir/$deja");
      if (!@rename($tmp, "$dir/$id.$ext")) { @unlink($tmp); $c['echecs_dl']++; continue; }
      @chmod("$dir/$id.$ext", 0644);
      $man[$id] = ['file' => "$id.$ext", 'src' => $src];
    }
    if ($deja && $gere)      $c['rafraichis']++;
    elseif ($deja)           $c['adoptes']++;
    else                     $c['telecharges']++;
  }

  if (!$cfg['dry'] && !spp_manifest_save($dir, $man)) {
    fwrite(STDERR, "⚠ manifeste non écrit — le prochain passage retéléchargera\n");
  }
  return $c;
}

/* ═══ Point d'entrée CLI (inerte quand le fichier est inclus par un test). ═══ */
if (!defined('WS_SEASONS_AS_LIB')) {
  $opt = ['dry' => false, 'force' => false];
  foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $opt['dry'] = true;
    elseif ($a === '--force') $opt['force'] = true;
    else { fwrite(STDERR, "option inconnue : $a\n"); exit(2); }
  }

  $cfgFile = require __DIR__ . '/config.php';
  $d = $cfgFile['db'];
  if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "⚠ sync_season_photos : pdo_mysql absent du PHP CLI — photos de gamme non synchronisées.\n");
    exit(0);
  }
  $pdo = new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4",
                 $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
    echo "sync_season_photos : API ERP non configurée (ws_param.erp_api_base) — rien à faire.\n";
    exit(0);
  }

  $auth = $token !== '' ? ['Authorization: Bearer ' . $token] : [];
  [$code, $body] = spp_http_get($base . '/product-availability-periods',
                                array_merge($auth, ['Accept: application/json']), 25);
  $data = ($code === 200 && $body !== null) ? json_decode($body, true) : null;
  $lst  = is_array($data) ? (array_is_list($data) ? $data : ($data['items'] ?? $data['data'] ?? null)) : null;
  if (!is_array($lst)) {
    // API muette : on ne touche à RIEN. Effacer les photos parce que l'ERP est
    // en panne viderait la barre de gammes pour une coupure réseau.
    fwrite(STDERR, "⚠ sync_season_photos : périodes illisibles (HTTP $code) — aucun fichier modifié.\n");
    exit(0);
  }

  $c = ssp_run($lst, ['base' => $base, 'token' => $token,
                      'dir' => __DIR__ . '/../assets/season_pictures',
                      'dry' => $opt['dry'], 'force' => $opt['force']]);
  if ($c === null) exit(1);
  echo "sync_season_photos : {$c['periodes']} période(s) ; {$c['telecharges']} nouvelle(s), "
     . "{$c['rafraichis']} rafraîchie(s), {$c['adoptes']} fichier(s) hors ERP remplacé(s), "
     . "{$c['supprimes']} supprimé(s) (plus de photo côté ERP), {$c['a_jour']} à jour ; "
     . "{$c['sans_photo']} sans photo ; échecs : {$c['echecs_dl']} téléchargement, "
     . "{$c['non_image']} contenu non-image.\n";
}
