<?php
/* Numéro de la fiche pour le SMS (tel_fiche_e164) et normalisation (norm_phone).
   Lancer : php php-api/tests/tel_fiche_test.php — sort en 1 au premier écart. */
require __DIR__ . '/../tel.php';

$cas = [
  [['phone_e164' => '+48725038049', 'phone' => '0725038049', 'phone_prefix' => '+48'], '+48725038049', 'E.164 polonais tel quel (plus de +32 aveugle)'],
  [['phone_e164' => '+32476316972', 'phone' => '0476316972', 'phone_prefix' => '+32'], '+32476316972', 'E.164 belge'],
  [['phone_e164' => '+33 6 12 34 56 78', 'phone' => '0612345678', 'phone_prefix' => '+33'], '+33612345678', 'E.164 avec espaces'],
  [['phone_e164' => null, 'phone' => '0725038049', 'phone_prefix' => '+48'], '+48725038049', 'national + indicatif de la fiche'],
  [['phone_e164' => null, 'phone' => '0476316972', 'phone_prefix' => null], '+32476316972', 'national sans indicatif : +32 par défaut'],
  [['phone_e164' => '', 'phone' => '+33612345678', 'phone_prefix' => null], '+33612345678', 'ancienne fiche : phone déjà international'],
  [['phone_e164' => '', 'phone' => '0033612345678', 'phone_prefix' => null], '+33612345678', 'ancienne fiche : 00 + indicatif'],
  [['phone_e164' => null, 'phone' => '', 'phone_prefix' => '+32'], '', 'fiche sans numéro'],
  [[], '', 'fiche vide'],
];
$n = 0;
foreach ($cas as [$u, $attendu, $quoi]) {
  $r = tel_fiche_e164($u);
  if ($r !== $attendu) { fwrite(STDERR, "ÉCHEC $quoi : attendu '$attendu', obtenu '$r'\n"); exit(1); }
  $n++;
}
$np = [
  ['+48', '725038049',      ['+48', '0725038049', '+48725038049']],
  ['+32', '0476 31 69 72',  ['+32', '0476316972', '+32476316972']],
  ['+32', '+32476316972',   ['+32', '0476316972', '+32476316972']],
  ['+33', '0033612345678',  ['+33', '0612345678', '+33612345678']],
  ['',    '',               ['+32', '', '']],
];
foreach ($np as [$p, $raw, $att]) {
  $r = norm_phone($p, $raw);
  if ($r !== $att) { fwrite(STDERR, "ÉCHEC norm_phone($p, $raw) : " . json_encode($r) . "\n"); exit(1); }
  $n++;
}
echo "OK — $n cas (tel_fiche_e164 / norm_phone)\n";
