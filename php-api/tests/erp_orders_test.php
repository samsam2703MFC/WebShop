<?php
/* Tests unitaires — remontée des commandes vers l'ERP (erp_orders.php).
 *
 * Isolé : ne teste que les fonctions PURES (répartition des remises, date de
 * retrait, construction du corps client-orders). Aucune base, aucun réseau.
 *
 *   php php-api/tests/erp_orders_test.php
 *
 * Couvre : somme des remises = remise commande (au centime, arrondi corrigé),
 * bornes [0, brut] par ligne, remise > total plafonnée, portion transmise en
 * id_product_portion, composants de menu repliés dans le prix de la mère,
 * ligne sans produit ERP exclue et signalée, frais de livraison signalés,
 * comment jamais null, pick_up_datetime composé ou minuit assumé.
 */

require __DIR__ . '/../erp_orders.php';

$TESTS = 0; $FAILS = 0;
function check(string $label, bool $ok) {
  global $TESTS, $FAILS; $TESTS++;
  if ($ok) { echo "  ✓ $label\n"; }
  else     { $FAILS++; echo "  ✗ $label\n"; }
}
function somme(array $x) { return round(array_sum($x), 2); }

/* ── Répartition des remises ─────────────────────────────────────────────── */
echo "Répartition des remises\n";

$r = erp_order_allouer_remises([33.33, 33.33, 33.34], 10.00);
check('pro-rata : la somme fait exactement la remise', somme($r) === 10.00);
check('pro-rata : aucune part négative ni au-delà du brut',
  $r[0] >= 0 && $r[1] >= 0 && $r[2] >= 0 && $r[0] <= 33.33 && $r[1] <= 33.33 && $r[2] <= 33.34);

check('remise nulle : toutes les parts à zéro',
  erp_order_allouer_remises([10.00, 5.00], 0.0) === [0.0, 0.0]);

$r = erp_order_allouer_remises([10.00, 5.00], 15.00);
check('remise = total : chaque ligne entièrement remisée', $r === [10.00, 5.00]);

$r = erp_order_allouer_remises([10.00, 5.00], 99.00);
check('remise > total : plafonnée au total', somme($r) === 15.00);

$r = erp_order_allouer_remises([0.00, 20.00], 7.00);
check('ligne à 0 € (cadeau) : rien à remiser dessus', $r[0] === 0.0 && $r[1] === 7.00);

$r = erp_order_allouer_remises([0.01, 0.01, 0.01], 0.02);
check('centimes : répartition exacte sur très petits montants', somme($r) === 0.02);

check('aucune ligne : aucune part', erp_order_allouer_remises([], 10.0) === []);

/* ── pick_up_datetime ────────────────────────────────────────────────────── */
echo "pick_up_datetime\n";

check('date + créneau « 10:00 – 10:30 » → début du créneau',
  erp_order_retrait_datetime('2026-08-31', '10:00 – 10:30') === '2026-08-31 10:00:00');
check('créneau « 10h30 » (notation h) reconnu',
  erp_order_retrait_datetime('2026-08-31', '10h30') === '2026-08-31 10:30:00');
check('créneau sans heure → minuit assumé',
  erp_order_retrait_datetime('2026-08-31', 'Soirée') === '2026-08-31 00:00:00');
check('date absente → aujourd\'hui, minuit',
  erp_order_retrait_datetime(null, null) === date('Y-m-d') . ' 00:00:00');

/* ── Corps client-orders ─────────────────────────────────────────────────── */
echo "Corps client-orders\n";

$o = [
  'shop_id' => 12, 'customer_id' => 345, 'order_ref' => 'WS-TEST01',
  'mode' => 'delivery', 'slot_label' => '11:30 – 12:00', 'delivery_date' => '2026-09-02',
  'promo_amount' => 0, 'webshop_discount' => 0, 'voucher_discount' => 5.00,
  'voucher_code' => 'B12-ABC123', 'note' => 'Sonner au 2e',
  'payment_method' => 'bancontact', 'delivery_fee_amount' => 3.50,
  'office_delivery_site_name' => 'Tour Sud',
];
$lignes = [
  // tarte en quart, portion ERP 17
  ['id' => 1, 'product_id' => 901, 'product_name' => 'Tarte riz', 'qty' => 2,
   'unit_price' => 12.50, 'portion' => 'quart', 'portion_id' => 17, 'note' => null, 'parent_line_id' => null],
  // menu : mère + 2 composants (un inclus, un majoré +2 €)
  ['id' => 2, 'product_id' => 902, 'product_name' => 'Menu Midi', 'qty' => 1,
   'unit_price' => 15.00, 'portion' => null, 'portion_id' => null, 'note' => 'sans oignons', 'parent_line_id' => null],
  ['id' => 3, 'product_id' => 903, 'product_name' => 'Soupe du jour', 'qty' => 1,
   'unit_price' => 0.00, 'portion' => null, 'portion_id' => null, 'note' => null, 'parent_line_id' => 2],
  ['id' => 4, 'product_id' => 904, 'product_name' => 'Cake nature', 'qty' => 1,
   'unit_price' => 2.00, 'portion' => null, 'portion_id' => null, 'note' => null, 'parent_line_id' => 2],
  // ligne sans produit ERP : doit être EXCLUE (sinon 404 sur toute la commande)
  ['id' => 5, 'product_id' => null, 'product_name' => 'Article inconnu', 'qty' => 1,
   'unit_price' => 4.00, 'portion' => null, 'portion_id' => null, 'note' => null, 'parent_line_id' => null],
];
$c = erp_order_payload($o, $lignes);

check('id_shop / id_client transmis', $c['id_shop'] === 12 && $c['id_client'] === 345);
check('id_order_status = 1 (new), le seul posé par le webshop', $c['id_order_status'] === 1);
check('pick_up_datetime composé', $c['pick_up_datetime'] === '2026-09-02 11:30:00');
check('2 lignes produits (composants repliés, ligne inconnue exclue)', count($c['products']) === 2);

$p1 = $c['products'][0]; $p2 = $c['products'][1];
check('portion → id_product_portion = 17', ($p1['id_product_portion'] ?? null) === 17);
check('pièce entière : pas de id_product_portion', !array_key_exists('id_product_portion', $p2));
check('menu : supplément replié dans le prix unitaire (15 + 2)', $p2['unit_gross_price'] === 17.00);
$apres = round($p1['total_gross_value_after_discount'] + $p2['total_gross_value_after_discount'], 2);
check('somme après remise = marchandises − bon (25 + 17 − 5)', $apres === 37.00);
check('remises par ligne = remise commande', round($p1['item_discount_value'] + $p2['item_discount_value'], 2) === 5.00);
check('chaque ligne : après remise = brut − remise ligne',
  $p1['total_gross_value_after_discount'] === round(25.00 - $p1['item_discount_value'], 2)
  && $p2['total_gross_value_after_discount'] === round(17.00 - $p2['item_discount_value'], 2));

check('comment jamais vide (NOT NULL côté ERP)', is_string($c['comment']) && $c['comment'] !== '');
check('comment : frais de livraison signalés EN SUS', strpos($c['comment'], 'Frais de livraison 3,50') !== false);
check('comment : bon cité, redemption annoncée déjà comptée', strpos($c['comment'], 'B12-ABC123') !== false);
check('comment : composition du menu', strpos($c['comment'], 'Cake nature (+2,00 €)') !== false);
check('comment : ligne inconnue signalée NON REPRIS', strpos($c['comment'], 'NON REPRIS') !== false
  && strpos($c['comment'], 'Article inconnu') !== false);
check('comment : note client et note de ligne', strpos($c['comment'], 'Sonner au 2e') !== false
  && strpos($c['comment'], 'sans oignons') !== false);
check('pas de voucher_candidate_code (redemption déjà comptée côté webshop)',
  !array_key_exists('voucher_candidate_code', $c));

/* Retrait simple, sans client (invité historique) : id_client absent. */
$c2 = erp_order_payload(['shop_id' => 3, 'customer_id' => null, 'order_ref' => 'WS-T2',
                         'mode' => 'collect', 'slot_label' => '09:00 – 09:30',
                         'delivery_date' => '2026-09-01', 'payment_method' => 'shop'],
                        [['id' => 9, 'product_id' => 55, 'product_name' => 'Pain', 'qty' => 1,
                          'unit_price' => 3.20, 'portion' => null, 'portion_id' => null,
                          'note' => null, 'parent_line_id' => null]]);
check('sans client : id_client absent du corps', !array_key_exists('id_client', $c2));
check('retrait : créneau dans comment', strpos($c2['comment'], 'Retrait 09:00') !== false);
check('comptoir : « à encaisser » signalé', strpos($c2['comment'], 'encaisser en boutique') !== false);

/* ── Famille de paiement (copie CLI alignée sur payment_family) ──────────── */
echo "Famille de paiement\n";
check('bancontact → stripe', erp_order_famille('bancontact') === 'stripe');
check('especes → shop', erp_order_famille('especes') === 'shop');
check('account → deferred', erp_order_famille('account') === 'deferred');

echo ($FAILS === 0 ? "\n✓ $TESTS test(s), aucun échec\n" : "\n✗ $FAILS échec(s) sur $TESTS\n");
exit($FAILS === 0 ? 0 : 1);
