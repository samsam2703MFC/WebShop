<?php
/* Le diagnostic de visibilité dit-il la MÊME chose que le catalogue ?
 *
 *   php php-api/tests/visibilite_test.php
 *
 * POURQUOI CE TEST EXISTE. Six conditions décident qu'un produit s'affiche, et
 * elles vivaient à trois endroits : le WHERE de /catalog/products, un filtre
 * PHP sur le prix ERP appliqué après coup, et deux consoles qui en
 * réimplémentaient chacune une partie. Résultat : la console marque annonçait
 * « 39 produits » là où le site en montrait 1, sans que rien n'explique l'écart.
 *
 * product_visibilite() énumère désormais les six en un seul endroit. Mais une
 * seconde énumération, même juste le jour où on l'écrit, DIVERGE dès qu'on
 * touche au catalogue sans y penser. Ce test est ce qui l'en empêche : il
 * compare, produit par produit, le verdict du diagnostic à la présence réelle
 * dans le catalogue servi. Tout écart est nommé.
 *
 * Il a besoin d'une vraie base (config.php). Sans elle, il s'abstient au lieu
 * d'échouer — un test qui ne peut pas s'exécuter n'est pas un test qui échoue.
 */
$racine = dirname(__DIR__);
if (!is_file("$racine/config.php")) {
  fwrite(STDERR, "config.php absent — test ignoré (il lui faut une base réelle).\n");
  exit(0);
}
require_once "$racine/lib.php";

/* index.php est un ROUTEUR : l'inclure exécuterait une requête. On ne charge
   donc que ses fonctions, extraites à la volée — la même astuce que
   totaux_test.cjs pour le bundle du webshop. */
function charger_fonctions_index($racine) {
  $src = file_get_contents("$racine/index.php");
  // Toutes les fonctions de premier niveau, jusqu'à leur accolade fermante.
  preg_match_all('/^function\s+\w+\s*\([^)]*\)\s*\{/m', $src, $m, PREG_OFFSET_CAPTURE);
  $code = "<?php\n";
  foreach ($m[0] as $f) {
    $d = $f[1]; $prof = 0; $i = strpos($src, '{', $d);
    for ($j = $i; $j < strlen($src); $j++) {
      if ($src[$j] === '{') $prof++;
      elseif ($src[$j] === '}') { $prof--; if ($prof === 0) { $code .= substr($src, $d, $j - $d + 1) . "\n"; break; } }
    }
  }
  $tmp = tempnam(sys_get_temp_dir(), 'vis') . '.php';
  file_put_contents($tmp, $code);
  require $tmp;
  unlink($tmp);
}

charger_fonctions_index($racine);

$shop = (int) ($argv[1] ?? 0);
if (!$shop) {
  $r = row("SELECT id FROM shops WHERE active=1 ORDER BY id LIMIT 1");
  $shop = (int) ($r['id'] ?? 0);
}
if (!$shop) { fwrite(STDERR, "aucune boutique active — test ignoré.\n"); exit(0); }

$tous = rows("SELECT id FROM ws_products LIMIT 2000");
$ids  = array_map(fn ($x) => (int) $x['id'], $tous);
if (!$ids) { fwrite(STDERR, "catalogue vide — test ignoré.\n"); exit(0); }

$vis = product_visibilite($shop, $ids, '', null);
$enLigneDiag = array_keys(array_filter($vis, fn ($v) => $v['enLigne']));

/* Le catalogue RÉELLEMENT servi, obtenu par la même route que le client. On
   appelle la fonction de rendu ? Non : /catalog/products est un bloc du
   routeur. On rejoue donc sa requête, telle qu'elle est écrite, et son filtre
   de prix — c'est précisément ce que le diagnostic doit refléter. */
[$seasonSql, $seasonArgs] = availability_where('p', null);
$wl = whitelist_where('p');
$catShop = categorie_shop_where('c', $shop);
/* ws_product_shops a disparu de cette requête AVEC le modèle : tous les
   produits sont communs à tous les magasins, il n'y a plus d'assortiment par
   boutique. Le test l'a d'ailleurs signalé lui-même — il annonçait un produit
   en ligne que le catalogue, resté sur l'ancienne règle, cachait encore. */
$rs = rows("SELECT p.id FROM ws_products p
              LEFT JOIN ws_categories c ON c.id = p.cat_id
             WHERE p.active = 1$wl$seasonSql$catShop", $seasonArgs);
$prix = prix_produits(array_map(fn ($x) => (int) $x['id'], $rs));
$enLigneCat = array_values(array_filter(array_map(fn ($x) => (int) $x['id'], $rs),
                                        fn ($id) => isset($prix[$id])));

sort($enLigneDiag); sort($enLigneCat);
$manqueDiag = array_diff($enLigneCat, $enLigneDiag);   // le catalogue montre, le diag dit non
$manqueCat  = array_diff($enLigneDiag, $enLigneCat);   // le diag dit oui, le catalogue cache

printf("boutique %d · %d produits au catalogue réseau\n", $shop, count($ids));
printf("  en ligne selon le CATALOGUE   : %d\n", count($enLigneCat));
printf("  en ligne selon le DIAGNOSTIC  : %d\n", count($enLigneDiag));

if ($manqueDiag || $manqueCat) {
  if ($manqueDiag) printf("  ✕ %d produit(s) servis mais annoncés hors ligne : %s\n",
    count($manqueDiag), implode(',', array_slice($manqueDiag, 0, 20)));
  if ($manqueCat) printf("  ✕ %d produit(s) annoncés en ligne mais absents : %s\n",
    count($manqueCat), implode(',', array_slice($manqueCat, 0, 20)));
  fwrite(STDERR, "DIVERGENCE — le diagnostic et le catalogue ne disent pas la même chose.\n");
  exit(1);
}

echo "  ✓ vente : les deux disent la même chose\n";

/* ── SECOND VOLET : LA NAVIGATION ───────────────────────────────────────────
   Le premier volet ne compare que « ce produit est-il VENDU ». Or les deux
   pannes vécues portaient sur « ce produit est-il JOIGNABLE » : des cookies en
   vente sans aucune catégorie où les retrouver, puis une sous-catégorie unique
   masquée. Le champ `navigation` du diagnostic annonce cet écart — encore
   faut-il qu'il dise la vérité, et il a déjà cessé de la dire : il a continué
   d'annoncer « Catégorie désactivée » après que la barre eut cessé de filtrer
   sur ce drapeau.

   On rejoue donc la requête de /catalog/categories et on confronte : tout
   produit VENDU dont la catégorie est absente de la barre doit être signalé
   par `navigation`, et réciproquement. */
$catsBarre = [];
if ($enLigneCat) {
  $inP = implode(',', array_map('intval', $enLigneCat));
  $catIds = array_values(array_filter(array_map(
    fn ($x) => $x['cat_id'] !== null ? (int) $x['cat_id'] : null,
    rows("SELECT DISTINCT cat_id FROM ws_products WHERE id IN ($inP)"))));
  if ($catIds) {
    $inC = implode(',', array_map('intval', $catIds));
    foreach (rows("SELECT id FROM ws_categories WHERE id IN ($inC)") as $c)
      $catsBarre[(int) $c['id']] = true;
  }
}
$catDe = [];
foreach (rows("SELECT id, cat_id FROM ws_products WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")") as $x)
  $catDe[(int) $x['id']] = $x['cat_id'] !== null ? (int) $x['cat_id'] : null;

$navFaux = [];
foreach ($enLigneCat as $pid) {
  $cid        = $catDe[$pid] ?? null;
  $joignable  = $cid !== null && isset($catsBarre[$cid]);
  $annonce    = ($vis[$pid]['navigation'] ?? null) !== null;   // le diag le dit injoignable
  if ($joignable === $annonce)                                  // les deux se contredisent
    $navFaux[] = $pid . ($joignable ? ' (joignable, annoncé introuvable)'
                                    : ' (introuvable, annoncé joignable)');
}
if ($navFaux) {
  printf("  ✕ %d produit(s) dont le verdict de NAVIGATION est faux : %s\n",
    count($navFaux), implode(' · ', array_slice($navFaux, 0, 20)));
  fwrite(STDERR, "DIVERGENCE — le champ `navigation` ne correspond pas à la barre de catégories.\n");
  exit(1);
}
echo "  ✓ navigation : le verdict correspond à la barre de catégories\n";

/* ── TROISIÈME VOLET : LES DEUX CANAUX SONT-ILS INDÉPENDANTS ? ─────────────
   Depuis la 0071, `webshop` et `office_delivery` sont deux interrupteurs
   distincts, et `active` ne veut plus dire que « publié ». Avant, fermer le
   webshop mettait le produit en brouillon et le retirait AUSSI du bureau :
   « bureau seulement » était impossible. Ce volet le vérifie sur les données —
   un produit fermé au click & collect doit rester servi en livraison bureau. */
$webCol = (bool) row("SELECT 1 x FROM information_schema.columns
                       WHERE table_schema=DATABASE() AND table_name='ws_products'
                         AND column_name='webshop'");
if ($webCol) {
  $bureauSeul = rows("SELECT id, name FROM ws_products
                       WHERE active = 1 AND COALESCE(webshop,1) = 0
                         AND COALESCE(office_delivery,1) = 1 LIMIT 20");
  if (!$bureauSeul) {
    echo "  — canaux : aucun produit « bureau seulement » en base, rien à vérifier\n";
  } else {
    $ko = [];
    foreach ($bureauSeul as $b2) {
      $pid = (int) $b2['id'];
      $vCol = product_visibilite($shop, [$pid], 'collect', null);
      $vBur = product_visibilite($shop, [$pid], 'delivery', null);
      // Refusé au webshop, et pour CETTE raison — pas parce qu'il serait brouillon.
      if (!empty($vCol[$pid]['enLigne']))
        $ko[] = $b2['name'] . ' : servi en click & collect alors que webshop=0';
      elseif (strpos((string) $vCol[$pid]['raison'], 'webshop') === false)
        $ko[] = $b2['name'] . ' : refusé au webshop pour la mauvaise raison — ' . $vCol[$pid]['raison'];
      // Et TOUJOURS vendable au bureau : c'est tout l'objet de la colonne.
      if (empty($vBur[$pid]['enLigne']))
        $ko[] = $b2['name'] . ' : perdu aussi en livraison bureau — ' . $vBur[$pid]['raison'];
    }
    if ($ko) {
      foreach ($ko as $k) printf("  ✕ %s\n", $k);
      fwrite(STDERR, "CANAUX LIÉS — fermer le webshop ne doit pas fermer la livraison bureau.\n");
      exit(1);
    }
    printf("  ✓ canaux : %d produit(s) « bureau seulement » servis au bureau, pas au webshop\n", count($bureauSeul));
  }
}

// Le décompte des raisons : ce que la console doit pouvoir afficher.
$parRaison = [];
foreach ($vis as $v) if (!$v['enLigne']) $parRaison[$v['raison']] = ($parRaison[$v['raison']] ?? 0) + 1;
arsort($parRaison);
$parNav = [];
foreach ($vis as $v) if ($v['navigation']) $parNav[$v['navigation']] = ($parNav[$v['navigation']] ?? 0) + 1;
if ($parRaison) {
  echo "  hors ligne, par raison :\n";
  foreach ($parRaison as $r2 => $n) printf("    %4d  %s\n", $n, $r2);
}
if ($parNav) {
  echo "  en vente mais introuvables dans la navigation :\n";
  foreach ($parNav as $r2 => $n) printf("    %4d  %s\n", $n, $r2);
}
exit(0);
