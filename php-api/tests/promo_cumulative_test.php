<?php
/* Tests unitaires — logique « objectif d'achat cumulé → produit cadeau ».
 *
 * Isolé : PDO SQLite EN MÉMOIRE (aucune vraie base requise). On ne teste que la
 * logique pure de promo_lib.php (SQL portable), pas les routes MySQL.
 *
 *   php php-api/tests/promo_cumulative_test.php
 *
 * Couvre : franchissement du seuil, remboursement/annulation qui repasse sous le
 * seuil, hors période, portée boutique (réseau vs locale), invité vs client,
 * bornes TZ Europe/Brussels, idempotence de la clé d'identité.
 */

require __DIR__ . '/../promo_lib.php';

$TESTS = 0; $FAILS = 0;
function check(string $label, bool $ok) {
  global $TESTS, $FAILS; $TESTS++;
  if ($ok) { echo "  ✓ $label\n"; }
  else     { $FAILS++; echo "  ✗ $label\n"; }
}

/* Mini-schéma ws_orders (colonnes utilisées par promo_accumulate). */
function make_db(): PDO {
  $pdo = new PDO('sqlite::memory:');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("CREATE TABLE ws_orders (
    id INTEGER PRIMARY KEY,
    shop_id INTEGER,
    customer_id INTEGER,
    guest_email TEXT,
    status TEXT,
    payment_status TEXT,
    total REAL,
    created_at TEXT
  )");
  return $pdo;
}
function add_order(PDO $pdo, array $o): void {
  $pdo->prepare("INSERT INTO ws_orders (id, shop_id, customer_id, guest_email, status, payment_status, total, created_at)
                 VALUES (:id,:shop,:cid,:email,:status,:pay,:total,:created)")
      ->execute([
        ':id' => $o['id'], ':shop' => $o['shop'] ?? 2, ':cid' => $o['cid'] ?? null,
        ':email' => $o['email'] ?? null, ':status' => $o['status'] ?? 'confirmed',
        ':pay' => $o['pay'] ?? 'paid', ':total' => $o['total'], ':created' => $o['created'],
      ]);
}

// Campagne de référence : réseau, seuil 100 €, juillet 2026.
function campaign(array $over = []): array {
  return array_merge([
    'id' => 1, 'name' => 'Été', 'id_shop' => null, 'is_active' => 1,
    'starts_at' => '2026-07-01 00:00:00', 'ends_at' => '2026-07-31 23:59:59',
    'threshold_amount' => 100.00, 'currency' => 'EUR', 'condition_scope' => 'total',
    'reward_product_id' => 42, 'voucher_code_prefix' => 'GIFT',
  ], $over);
}

echo "── Cumul & seuil ──\n";
$db = make_db();
add_order($db, ['id' => 1, 'cid' => 7, 'total' => 40, 'created' => '2026-07-05 10:00:00']);
add_order($db, ['id' => 2, 'cid' => 7, 'total' => 35, 'created' => '2026-07-10 10:00:00']);
$c = campaign();
$acc = promo_accumulate($db, $c, 7, null);
check('cumul de 2 commandes payées = 75', abs($acc['amount'] - 75.0) < 0.001);
check('sous le seuil → in_progress', promo_status($c, $acc['amount'], '2026-07-15 12:00:00') === 'in_progress');
check('reste à dépenser = 25', abs(promo_remaining($c, $acc['amount']) - 25.0) < 0.001);

add_order($db, ['id' => 3, 'cid' => 7, 'total' => 30, 'created' => '2026-07-20 10:00:00']);
$acc = promo_accumulate($db, $c, 7, null);
check('franchissement du seuil : 105 >= 100', $acc['amount'] >= 100);
check('dans la période + seuil → unlocked', promo_is_unlockable($c, $acc['amount'], '2026-07-21 12:00:00'));
check('3 commandes comptées', count($acc['orders']) === 3);

echo "── Remboursement / annulation repasse sous le seuil ──\n";
$db2 = make_db();
add_order($db2, ['id' => 1, 'cid' => 7, 'total' => 60, 'created' => '2026-07-05 10:00:00']);
add_order($db2, ['id' => 2, 'cid' => 7, 'total' => 50, 'pay' => 'refunded', 'created' => '2026-07-06 10:00:00']);
add_order($db2, ['id' => 3, 'cid' => 7, 'total' => 50, 'status' => 'cancelled', 'created' => '2026-07-07 10:00:00']);
$acc = promo_accumulate($db2, campaign(), 7, null);
check('remboursée (refunded) exclue', $acc['amount'] == 60.0);
check('annulée (cancelled) exclue', count($acc['orders']) === 1);
check('repasse sous le seuil → in_progress',
      promo_status(campaign(), $acc['amount'], '2026-07-15 12:00:00') === 'in_progress');

echo "── Hors période ──\n";
$db3 = make_db();
add_order($db3, ['id' => 1, 'cid' => 7, 'total' => 200, 'created' => '2026-06-30 23:59:59']); // avant
add_order($db3, ['id' => 2, 'cid' => 7, 'total' => 200, 'created' => '2026-08-01 00:00:00']); // après
$acc = promo_accumulate($db3, campaign(), 7, null);
check('commandes hors [starts,ends] exclues', $acc['amount'] == 0.0);
add_order($db3, ['id' => 3, 'cid' => 7, 'total' => 120, 'created' => '2026-07-01 00:00:00']); // borne incluse
$acc = promo_accumulate($db3, campaign(), 7, null);
check('borne de début incluse', $acc['amount'] == 120.0);
check('après ends_at → status ended', promo_status(campaign(), 999, '2026-08-02 09:00:00') === 'ended');
check('avant starts_at → status not_started', promo_status(campaign(), 0, '2026-06-15 09:00:00') === 'not_started');
check('campagne désactivée → inactive',
      promo_status(campaign(['is_active' => 0]), 999, '2026-07-15 09:00:00') === 'inactive');

echo "── Portée boutique (réseau vs locale) ──\n";
$db4 = make_db();
add_order($db4, ['id' => 1, 'cid' => 7, 'shop' => 2, 'total' => 60, 'created' => '2026-07-05 10:00:00']);
add_order($db4, ['id' => 2, 'cid' => 7, 'shop' => 3, 'total' => 60, 'created' => '2026-07-06 10:00:00']);
check('réseau (id_shop NULL) = toutes boutiques → 120',
      promo_accumulate($db4, campaign(), 7, null)['amount'] == 120.0);
check('campagne boutique 2 → 60 seulement',
      promo_accumulate($db4, campaign(['id_shop' => 2]), 7, null)['amount'] == 60.0);

echo "── Invité vs client (isolation) ──\n";
$db5 = make_db();
add_order($db5, ['id' => 1, 'cid' => 7, 'total' => 80, 'created' => '2026-07-05 10:00:00']);
add_order($db5, ['id' => 2, 'cid' => null, 'email' => 'Guest@Mail.be', 'total' => 90, 'created' => '2026-07-05 10:00:00']);
check('client 7 ne voit que ses commandes', promo_accumulate($db5, campaign(), 7, null)['amount'] == 80.0);
check('invité (email, insensible casse) = 90', promo_accumulate($db5, campaign(), null, 'guest@mail.be')['amount'] == 90.0);
check('invité n\'agrège pas les commandes client', promo_accumulate($db5, campaign(), null, 'guest@mail.be')['amount'] != 170.0);

echo "── Clé d'identité ──\n";
check('connecté → client:{id}', promo_customer_ref(7, null) === 'client:7');
check('invité → guest:{email normalisé}', promo_customer_ref(null, ' Guest@Mail.be ') === 'guest:guest@mail.be');
check('email invalide → null', promo_customer_ref(null, 'pas-un-email') === null);
check('rien → null', promo_customer_ref(null, null) === null);

echo "── Bornes TZ Europe/Brussels ──\n";
// ends_at = 31/07 23:59:59 Brussels ; une commande à 00:30 le 01/08 heure Brussels
// (= 22:30 UTC le 31/07) ne doit PAS compter — on compare en heure locale Brussels.
$nowJuly = promo_now(new DateTimeImmutable('2026-07-15 12:00:00', new DateTimeZone('Europe/Brussels')));
check('promo_now formate en Y-m-d H:i:s', (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $nowJuly));
check('01/08 00:30 Brussels > ends_at → ended', promo_status(campaign(), 999, '2026-08-01 00:30:00') === 'ended');

echo "── Génération du code ──\n";
$code = promo_generate_code(campaign(), 'ABCD1234');
check('format {PREFIX}-{id}-{suffixe}', $code === 'GIFT-1-ABCD1234');
check('préfixe assaini', promo_generate_code(campaign(['voucher_code_prefix' => 'gi ft!']), 'X') === 'GIFT-1-X');

echo "── Consommation du cadeau au checkout ──\n";
// $g = ligne jointe progression+campagne.
$base = ['unlocked_at' => '2026-07-21 10:00:00', 'redeemed_at' => null,
         'customer_ref' => 'client:7', 'reward_delivery_date' => null, 'id_shop' => null];
check('cadeau débloqué, bon client, réseau → ok',
      promo_gift_redeemable($base, 'client:7', 2, '2026-07-25 10:00:00')['ok'] === true);
check('pas encore débloqué → not_unlocked',
      promo_gift_redeemable(['unlocked_at' => null] + $base, 'client:7', 2, '2026-07-25 10:00:00')['reason'] === 'not_unlocked');
check('déjà consommé → already_redeemed',
      promo_gift_redeemable(['redeemed_at' => '2026-07-26 09:00:00'] + $base, 'client:7', 2, '2026-07-25 10:00:00')['reason'] === 'already_redeemed');
check('autre client → not_owner',
      promo_gift_redeemable($base, 'client:99', 2, '2026-07-25 10:00:00')['reason'] === 'not_owner');
check('avant date de remise → before_delivery_date',
      promo_gift_redeemable(['reward_delivery_date' => '2026-08-01'] + $base, 'client:7', 2, '2026-07-25 10:00:00')['reason'] === 'before_delivery_date');
check('à/après date de remise → ok',
      promo_gift_redeemable(['reward_delivery_date' => '2026-08-01'] + $base, 'client:7', 2, '2026-08-01 08:00:00')['ok'] === true);
check('campagne boutique 2, commande boutique 3 → wrong_shop',
      promo_gift_redeemable(['id_shop' => 2] + $base, 'client:7', 3, '2026-07-25 10:00:00')['reason'] === 'wrong_shop');
check('campagne boutique 2, commande boutique 2 → ok',
      promo_gift_redeemable(['id_shop' => 2] + $base, 'client:7', 2, '2026-07-25 10:00:00')['ok'] === true);

echo "── Validation admin d'une campagne ──\n";
$ok = promo_campaign_validate([
  'name' => 'Été', 'startsAt' => '2026-07-01', 'endsAt' => '2026-07-31',
  'thresholdAmount' => 100, 'rewardProductId' => 42, 'rewardDeliveryDate' => '2026-08-05',
  'voucherCodePrefix' => 'gi ft!', 'idShop' => 2,
]);
check('entrée valide → aucune erreur', $ok['errors'] === []);
check('date seule → 00:00:00 / 23:59:59',
      $ok['clean']['starts_at'] === '2026-07-01 00:00:00' && $ok['clean']['ends_at'] === '2026-07-31 23:59:59');
check('préfixe assaini + upper', $ok['clean']['voucher_code_prefix'] === 'GIFT');
check('idShop typé int', $ok['clean']['id_shop'] === 2);
check('reward_delivery_date conservée', $ok['clean']['reward_delivery_date'] === '2026-08-05');

$bad = promo_campaign_validate(['name' => '', 'startsAt' => '2026-07-31', 'endsAt' => '2026-07-01',
                                'thresholdAmount' => 0, 'rewardProductId' => 0]);
check('name vide → name_required', in_array('name_required', $bad['errors'], true));
check('période inversée → period_inverted', in_array('period_inverted', $bad['errors'], true));
check('seuil 0 → threshold_invalid', in_array('threshold_invalid', $bad['errors'], true));
check('produit manquant → reward_product_required', in_array('reward_product_required', $bad['errors'], true));

check('idShop absent → réseau (null)',
      promo_campaign_validate(['name' => 'X', 'startsAt' => '2026-07-01', 'endsAt' => '2026-07-31',
                               'thresholdAmount' => 50, 'rewardProductId' => 1])['clean']['id_shop'] === null);
check('scope invalide → condition_scope_invalid',
      in_array('condition_scope_invalid', promo_campaign_validate(['name' => 'X', 'startsAt' => '2026-07-01',
        'endsAt' => '2026-07-31', 'thresholdAmount' => 50, 'rewardProductId' => 1, 'conditionScope' => 'weekly'])['errors'], true));

echo "\n";
if ($FAILS === 0) { echo "✅ $TESTS tests OK\n"; exit(0); }
echo "❌ $FAILS / $TESTS échec(s)\n"; exit(1);
