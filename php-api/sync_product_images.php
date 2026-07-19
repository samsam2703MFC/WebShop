<?php
/* sync_product_images.php — renseigne ws_products.img à partir des photos
 * réellement présentes sur le disque (assets/product_pictures/{id}.png|jpg).
 *
 * À exécuter SUR LE SERVEUR (le workflow le lance en SSH après le rsync du front).
 * - Lit les identifiants DB depuis config.php (même source que l'API / migrate.sh).
 * - Pour chaque fichier {id}.{png|jpg|jpeg|webp}, pose img = 'assets/product_pictures/{fichier}'.
 * - IDEMPOTENT et NON destructif : ne touche un produit que si son img est vide,
 *   NULL, ou pointe déjà sous assets/product_pictures/ (corrige alors l'extension).
 *   Un chemin d'image personnalisé n'est jamais écrasé.
 * - Ne pose jamais d'image pour un produit dont le fichier n'existe pas → aucune
 *   image cassée.
 */
$cfg = require __DIR__ . '/config.php';
$d = $cfg['db'];
$pdo = new PDO(
  "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4",
  $d['user'], $d['pass'],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$dir = __DIR__ . '/../assets/product_pictures';
$updated = 0; $files = 0;
if (is_dir($dir)) {
  $upd = $pdo->prepare(
    "UPDATE ws_products SET img=? WHERE id=? AND (img IS NULL OR img='' OR img LIKE 'assets/product_pictures/%')"
  );
  foreach (scandir($dir) ?: [] as $f) {
    if (preg_match('/^(\d+)\.(png|jpe?g|webp)$/i', $f, $m)) {
      $files++;
      $upd->execute(['assets/product_pictures/' . $f, (int) $m[1]]);
      $updated += $upd->rowCount();
    }
  }
}
echo "sync_product_images: $files photo(s) sur le disque, $updated produit(s) mis à jour.\n";
