<?php
/* Fidélité dans le webshop (loyalty.php) et panier caisse (instore.php) :
   les règles pures, celles qu'un écart rendrait fausses sans bruit.
     • un tampon va à la carte du mot-clé LE PLUS LONG (« pain au chocolat »
       → Viennoiseries, pas Pain), accents et casse ignorés, mots entiers ;
     • un coefficient (Happy Hour, week-end ×2) n'est actif que dans son créneau ;
     • le code caisse se lit tel que la douchette ou la caissière le tape.
   Lancer : php php-api/tests/fidelite_caisse_test.php — sort en 1 au premier écart. */
require __DIR__ . '/../loyalty.php';
require __DIR__ . '/../instore.php';

$n = 0;
function ok($cond, $quoi) { global $n; if (!$cond) { fwrite(STDERR, "ÉCHEC : $quoi\n"); exit(1); } $n++; }

// ── Attribution d'une ligne d'achat à une carte à tampons ──
$cards = [
  ['id' => 1, 'card_code' => 'pain', 'keywords' => array_map('loy_norm', ['pain', 'baguette']), 'cafe_cats' => []],
  ['id' => 2, 'card_code' => 'vien', 'keywords' => array_map('loy_norm', ['croissant', 'pain au chocolat']), 'cafe_cats' => []],
  ['id' => 3, 'card_code' => 'cafe', 'keywords' => array_map('loy_norm', ['café']), 'cafe_cats' => ['hot']],
];
$carte = fn($label, $cat = null) => (loy_card_for($cards, $label, $cat) ?? ['card_code' => null])['card_code'];
ok($carte('Baguette tradition') === 'pain', 'baguette → Pain');
ok($carte('PAIN AU CHOCOLAT x2') === 'vien', 'le mot-clé le plus long l\'emporte (pain au chocolat → Viennoiseries)');
ok($carte('Pain gris') === 'pain', 'pain seul → Pain');
ok($carte('Croissant au beurre') === 'vien', 'croissant → Viennoiseries');
ok($carte('Crème caramel') === null, 'aucun mot-clé → pas de carte');
ok($carte('Painlevé') === null, 'mot entier seulement (« Painlevé » n\'est pas « pain »)');
ok($carte('Cafe latte') === 'cafe', 'accents ignorés (café = cafe)');
ok($carte('Latte macchiato', 'hot') === 'cafe', 'commande café : la catégorie passe avant les mots-clés');

// ── Créneaux des coefficients de points ──
$samedi10h = strtotime('2026-10-24 10:00:00');   // samedi
$lundi16h  = strtotime('2026-10-26 16:00:00');   // lundi
$weekend = ['date_from' => null, 'date_to' => null, 'hour_from' => null, 'hour_to' => null, 'days' => '6,7', 'kind' => 'multiply', 'value' => '2.00'];
$happy   = ['date_from' => null, 'date_to' => null, 'hour_from' => 15, 'hour_to' => 17, 'days' => '1,2,3,4,5', 'kind' => 'add', 'value' => '1.00'];
ok(loy_rule_active($weekend, $samedi10h), 'week-end ×2 actif le samedi');
ok(!loy_rule_active($weekend, $lundi16h), 'week-end ×2 inactif le lundi');
ok(loy_rule_active($happy, $lundi16h), 'Happy Hour actif lundi 16h');
ok(!loy_rule_active($happy, $samedi10h), 'Happy Hour inactif samedi 10h');
ok(loy_rule_label($weekend) === '×2' && loy_rule_label($happy) === '+1', 'libellés ×2 / +1');
ok(loy_sched_hint($happy) === 'lun–ven 15h–17h', 'créneau lisible lun–ven 15h–17h');
ok(loy_sched_hint($weekend) === 'sam · dim', 'créneau lisible sam · dim');

// ── Code du panier caisse ──
ok(instore_norm_code('PC-7K3Q9X') === 'PC-7K3Q9X', 'code tel quel');
ok(instore_norm_code('pc7k3q9x') === 'PC-7K3Q9X', 'code tapé sans tiret, en minuscules');
ok(instore_norm_code(' pc 7k3q 9x ') === 'PC-7K3Q9X', 'espaces ignorés');
ok(instore_norm_code('7K3Q9X') === 'PC-7K3Q9X', 'préfixe ajouté s\'il manque');
ok(instore_norm_code('') === '', 'vide reste vide');

echo "OK — $n contrôles (fidélité, panier caisse)\n";
