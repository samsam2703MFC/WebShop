<?php
/* sync_product_images.php — renseigne ws_products.img à partir des photos
 * réellement présentes sur le disque (assets/product_pictures/{id}.png|jpg).
 *
 * À exécuter SUR LE SERVEUR (le workflow le lance en SSH après le rsync du front).
 * - Lit les identifiants DB depuis config.php (même source que l'API / migrate.sh).
 * - IDEMPOTENT et NON destructif : ne pose img que si elle est vide/NULL ou pointe
 *   déjà sous assets/product_pictures/ (corrige l'extension). Un chemin d'image
 *   personnalisé n'est jamais écrasé. Aucune image posée si le fichier n'existe pas.
 * - Émet un DIAGNOSTIC clair : combien de fichiers, combien correspondent à un
 *   produit, combien mis à jour / déjà OK / ignorés (img perso) / sans produit.
 */
$cfg = require __DIR__ . '/config.php';
$d = $cfg['db'];
$pdo = new PDO(
  "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4",
  $d['user'], $d['pass'],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$dir = __DIR__ . '/../assets/product_pictures';
$byId = [];                        // id (int) => 'assets/product_pictures/{fichier}'
if (is_dir($dir)) {
  foreach (scandir($dir) ?: [] as $f) {
    if (preg_match('/^(\d+)\.(png|jpe?g|webp)$/i', $f, $m)) $byId[(int) $m[1]] = 'assets/product_pictures/' . $f;
  }
}
$files = count($byId);

$matched = 0; $set = 0; $alreadyOk = 0; $replacedLegacy = 0; $noProduct = $files;
if ($byId) {
  $in = implode(',', array_map('intval', array_keys($byId)));
  $rows = $pdo->query("SELECT id, img FROM ws_products WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
  $matched = count($rows);
  $noProduct = $files - $matched;
  // La photo déposée FAIT AUTORITÉ : si un {id}.png|jpg existe, il DEVIENT
  // ws_products.img (elle a été uploadée exprès comme photo produit). On remplace
  // donc aussi les anciennes valeurs `img` (legacy) pour les produits concernés.
  // On ne touche JAMAIS un produit sans fichier → aucune image cassée.
  $upd = $pdo->prepare("UPDATE ws_products SET img=? WHERE id=?");
  foreach ($rows as $r) {
    $want = $byId[(int) $r['id']];
    $cur = $r['img'];
    if ($cur === $want) { $alreadyOk++; continue; }
    $upd->execute([$want, (int) $r['id']]);
    $set++;
    if ($cur !== null && $cur !== '' && strpos($cur, 'assets/product_pictures/') !== 0) $replacedLegacy++;
  }
}
echo "sync_product_images: $files fichier(s) ; $matched produit(s) correspondant(s) ; "
   . "$set mis à jour (dont $replacedLegacy ancienne(s) img remplacée(s)), $alreadyOk déjà OK ; "
   . "$noProduct fichier(s) SANS produit correspondant.\n";
