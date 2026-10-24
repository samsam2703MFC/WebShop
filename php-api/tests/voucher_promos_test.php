<?php
/* Tests unitaires — promotions produit déclenchées par un code (0136).
 *
 * Isolé : aucune base. On teste voucher_promos.php (validation, libellés,
 * chiffrage) et cross_portion_discount (erp_promos.php), la fonction qui dit
 * à l'aperçu ET à la commande quelles pièces sont déjà dans l'offre croisée.
 *
 *   php php-api/tests/voucher_promos_test.php
 */

require __DIR__ . '/../voucher_promos.php';
require __DIR__ . '/../erp_promos.php';

$TESTS = 0; $FAILS = 0;
function check(string $label, bool $ok) {
  global $TESTS, $FAILS; $TESTS++;
  if ($ok) { echo "  ✓ $label\n"; }
  else     { $FAILS++; echo "  ✗ $label\n"; }
}
function eq(float $a, float $b): bool { return abs($a - $b) < 0.005; }

/* Une ligne de panier aux prix serveur. */
function ligne(int $pid, int $qty, float $unit, ?string $portion = null, array $plus = []): array {
  return array_merge(['productId' => $pid, 'id' => $pid, 'qty' => $qty, 'unit' => $unit, 'portion' => $portion,
                      'pack' => false, 'crossLocked' => false, 'cross' => 0, 'cat_id' => 0, 'sub_cat_id' => 0], $plus);
}
/* Une promotion telle que la base la rend (après validation). */
function promo(array $p): array {
  return array_merge(['product_id' => 1, 'mechanic' => 'PERCENT', 'percent' => null, 'price' => null,
                      'buy_qty' => null, 'get_qty' => null, 'max_qty' => null, 'cumulative' => 0,
                      'product_name' => 'Bûche'], $p);
}

echo "Validation\n";
$v = voucher_promos_validate([
  ['productId' => 10, 'mechanic' => 'percent', 'percent' => 15],
  ['productId' => 11, 'mechanic' => 'CROSSED_PRICE', 'price' => 16.9, 'maxQty' => 2],
  ['productId' => 12, 'mechanic' => 'BUY_X_GET_Y', 'buy' => 2, 'get' => 1],
]);
check('trois promotions valides, aucune erreur', $v['errors'] === [] && count($v['clean']) === 3);
check('mécanique normalisée en majuscules', $v['clean'][0]['mechanic'] === 'PERCENT' && eq($v['clean'][0]['percent'], 15));
check('plafond par commande gardé', $v['clean'][1]['max_qty'] === 2);
check('non cumulable par défaut', $v['clean'][2]['cumulative'] === 0);
check('cumulable si demandé', voucher_promos_validate([['productId' => 1, 'mechanic' => 'PERCENT', 'percent' => 5]], true)['clean'][0]['cumulative'] === 1);
check('liste vide refusée', voucher_promos_validate([])['errors'] !== []);
check('objet au lieu de liste refusé', voucher_promos_validate(['a' => 1])['errors'] !== []);
check('« Prix de formule » refusé (pas appliqué au webshop)', voucher_promos_validate([['productId' => 1, 'mechanic' => 'BUNDLE_FIXED', 'price' => 9]])['errors'] !== []);
check('« Livraison offerte » refusée', voucher_promos_validate([['productId' => 1, 'mechanic' => 'FREE_DELIVERY']])['errors'] !== []);
check('pourcentage > 100 refusé', voucher_promos_validate([['productId' => 1, 'mechanic' => 'PERCENT', 'percent' => 120]])['errors'] !== []);
check('pourcentage 0 refusé', voucher_promos_validate([['productId' => 1, 'mechanic' => 'PERCENT', 'percent' => 0]])['errors'] !== []);
check('prix barré 0 refusé', voucher_promos_validate([['productId' => 1, 'mechanic' => 'CROSSED_PRICE', 'price' => 0]])['errors'] !== []);
check('offre liée sans offerte refusée', voucher_promos_validate([['productId' => 1, 'mechanic' => 'BUY_X_GET_Y', 'buy' => 2, 'get' => 0]])['errors'] !== []);
check('produit absent refusé', voucher_promos_validate([['mechanic' => 'PERCENT', 'percent' => 5]])['errors'] !== []);
check('même produit deux fois refusé', voucher_promos_validate([
  ['productId' => 1, 'mechanic' => 'PERCENT', 'percent' => 5],
  ['productId' => 1, 'mechanic' => 'CROSSED_PRICE', 'price' => 3]])['errors'] !== []);
check('plafond 0 refusé', voucher_promos_validate([['productId' => 1, 'mechanic' => 'PERCENT', 'percent' => 5, 'maxQty' => 0]])['errors'] !== []);

echo "Libellés\n";
check('pourcentage', voucher_promo_label(promo(['percent' => 15]), 'Bûche Chocolat') === 'Bûche Chocolat : −15 %');
check('pourcentage décimal', voucher_promo_label(promo(['percent' => 12.5]), 'Tarte') === 'Tarte : −12,5 %');
check('prix barré', voucher_promo_label(promo(['mechanic' => 'CROSSED_PRICE', 'price' => 16.9]), 'Bûche') === 'Bûche à 16,90 €');
check('offre liée', voucher_promo_label(promo(['mechanic' => 'BUY_X_GET_Y', 'buy_qty' => 2, 'get_qty' => 1]), 'Bûche') === 'Bûche : 2 achetés → 1 offert');
check('nom absent : identifiant', voucher_promo_label(promo(['product_id' => 7, 'percent' => 5]), '') === 'produit #7 : −5 %');

echo "Pourcentage\n";
$r = voucher_promos_apply([promo(['percent' => 15])], [ligne(1, 2, 19.90)]);
check('−15 % sur 2 × 19,90 = 5,97', eq($r['discount'], 5.97) && $r['details'][0]['pieces'] === 2);
$r = voucher_promos_apply([promo(['percent' => 10])], [ligne(1, 1, 20.0, 'quart'), ligne(1, 1, 40.0)]);
check('un pourcentage vaut aussi pour une portion', eq($r['discount'], 6.0));
$r = voucher_promos_apply([promo(['percent' => 50, 'max_qty' => 1])], [ligne(1, 1, 30.0), ligne(1, 1, 10.0)]);
check('plafond 1 : la pièce la moins chère seulement', eq($r['discount'], 5.0) && $r['details'][0]['pieces'] === 1);
$r = voucher_promos_apply([promo(['percent' => 15])], [ligne(2, 3, 19.90)]);
check('produit absent : aucune remise, il manque 1 pièce', eq($r['discount'], 0) && $r['unmet'][0]['need'] === 1 && $r['unmet'][0]['name'] === 'Bûche');

echo "Prix barré\n";
$cp = promo(['mechanic' => 'CROSSED_PRICE', 'price' => 16.90]);
$r = voucher_promos_apply([$cp], [ligne(1, 2, 19.90)]);
check('2 × (19,90 → 16,90) = 6,00', eq($r['discount'], 6.0));
$r = voucher_promos_apply([$cp], [ligne(1, 2, 5.0, 'quart')]);
check('une portion ne passe pas au prix de la pièce entière', eq($r['discount'], 0));
$r = voucher_promos_apply([$cp], [ligne(1, 1, 15.0)]);
check('boutique déjà moins chère : rien', eq($r['discount'], 0) && count($r['unmet']) === 1);
$r = voucher_promos_apply([promo(['mechanic' => 'CROSSED_PRICE', 'price' => 16.90, 'max_qty' => 1])], [ligne(1, 3, 19.90)]);
check('plafond 1 pièce', eq($r['discount'], 3.0));

echo "Offre liée (2 achetés → 1 offert)\n";
$bx = promo(['mechanic' => 'BUY_X_GET_Y', 'buy_qty' => 2, 'get_qty' => 1]);
$r = voucher_promos_apply([$bx], [ligne(1, 2, 20.0)]);
check('2 pièces : rien, il en faut 3', eq($r['discount'], 0) && $r['unmet'][0]['need'] === 3 && $r['unmet'][0]['have'] === 2);
$r = voucher_promos_apply([$bx], [ligne(1, 3, 20.0)]);
check('3 pièces : 1 offerte', eq($r['discount'], 20.0) && $r['details'][0]['pieces'] === 1);
$r = voucher_promos_apply([$bx], [ligne(1, 5, 20.0)]);
check('5 pièces : 1 offerte', eq($r['discount'], 20.0));
$r = voucher_promos_apply([$bx], [ligne(1, 6, 20.0)]);
check('6 pièces : 2 offertes', eq($r['discount'], 40.0));
$r = voucher_promos_apply([$bx], [ligne(1, 2, 25.0), ligne(1, 1, 18.0)]);
check('la moins chère est offerte', eq($r['discount'], 18.0));
$r = voucher_promos_apply([$bx], [ligne(1, 3, 6.0, 'demi')]);
check('portions : hors offre liée', eq($r['discount'], 0));
$r = voucher_promos_apply([promo(['mechanic' => 'BUY_X_GET_Y', 'buy_qty' => 2, 'get_qty' => 1, 'max_qty' => 3])], [ligne(1, 6, 20.0)]);
check('plafond 3 pièces : un seul lot', eq($r['discount'], 20.0));

echo "Formules, bundles, offre croisée\n";
$r = voucher_promos_apply([promo(['percent' => 50])], [ligne(1, 2, 20.0, null, ['pack' => true])]);
check('une formule ou un bundle garde son prix', eq($r['discount'], 0));
$r = voucher_promos_apply([promo(['percent' => 50])], [ligne(1, 1, 20.0, null, ['crossLocked' => true]), ligne(1, 1, 30.0)]);
check('non cumulable : la pièce de l\'offre croisée est laissée', eq($r['discount'], 15.0));
$r = voucher_promos_apply([promo(['percent' => 50, 'cumulative' => 1])], [ligne(1, 1, 20.0, null, ['crossLocked' => true]), ligne(1, 1, 30.0)]);
check('cumulable : elle en profite aussi', eq($r['discount'], 25.0));

echo "Plusieurs promotions\n";
$r = voucher_promos_apply([
  promo(['product_id' => 1, 'mechanic' => 'BUY_X_GET_Y', 'buy_qty' => 2, 'get_qty' => 1, 'product_name' => 'Praliné']),
  promo(['product_id' => 2, 'mechanic' => 'CROSSED_PRICE', 'price' => 16.90, 'product_name' => 'Chocolat']),
  promo(['product_id' => 3, 'percent' => 15, 'product_name' => 'Cheesecake']),
], [ligne(1, 3, 19.90), ligne(2, 1, 19.90)]);
check('deux jouent, la troisième manque', eq($r['discount'], 22.90) && count($r['details']) === 2 && count($r['unmet']) === 1);
check('le détail porte le libellé', $r['details'][0]['label'] === 'Praliné : 2 achetés → 1 offert');

echo "Offre croisée (cross_portion_discount)\n";
$regle = ['buy' => 4, 'free' => 1, 'threshold' => 4, 'source' => 'local', 'productIds' => [], 'categoryIds' => [], 'excluded' => []];
$lignes = [ligne(5, 4, 5.0, 'quart', ['cross' => 1]), ligne(1, 1, 20.0)];
$c = cross_portion_discount($regle, $lignes);
check('4 quarts : 1 offert', eq($c['amount'], 5.0));
check('seule la ligne éligible est verrouillée', isset($c['locked'][0]) && !isset($c['locked'][1]));
$c = cross_portion_discount($regle, [ligne(5, 3, 5.0, 'quart', ['cross' => 1])]);
check('sous le seuil : rien, rien de verrouillé', eq($c['amount'], 0) && $c['locked'] === []);
check('sans règle : rien', cross_portion_discount(null, $lignes)['amount'] == 0);

echo "\n$TESTS tests, $FAILS échec(s)\n";
exit($FAILS ? 1 : 0);
