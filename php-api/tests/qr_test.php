<?php
/* L'encodeur QR se vérifie par un VRAI DÉCODEUR, il ne se relit pas.
 *
 * Les tables de correction d'erreur, les positions d'alignement, l'ordre de
 * pose des données et le choix du masque sont des données et des règles pures :
 * une case fausse ne se voit pas à la lecture, et produit un code qui s'imprime
 * parfaitement… et ne se scanne pas. Trois défauts exactement de cette nature
 * ont été trouvés ici — séparateur des motifs de repérage peint en noir, deux
 * copies du format interverties, motifs d'alignement superposés au repère —
 * et AUCUN n'était visible autrement qu'en décodant l'image.
 *
 * Le test décode donc ce qu'on produit, avec OpenCV, comme le ferait un
 * téléphone. Il vérifie aussi le cas qui nous intéresse vraiment : l'URL
 * d'invitation imprimée sur l'affiche d'une cafétéria.
 *
 * Usage :  php php-api/tests/qr_test.php
 *          (nécessite python3 + pyzbar/libzbar0 ; sinon le test s'abstient)
 */
require __DIR__ . '/../qr.php';

/* Décodeur : zbar (pyzbar), le moteur de lecture de référence. OpenCV a aussi
   été essayé et REJETÉ comme juge : il échoue sur ~5 % des symboles, y compris
   ceux d'encodeurs éprouvés — un juge qui condamne au hasard ne prouve rien. */
$sonde = trim((string) shell_exec('python3 -c "import cv2;from pyzbar.pyzbar import decode; print(1)" 2>/dev/null'));
if ($sonde !== '1') {
  fwrite(STDERR, "zbar absent (apt-get install libzbar0 && pip install pyzbar pillow) — décodage impossible, test ignoré.\n");
  exit(0);
}

$tmp = sys_get_temp_dir() . '/qr_test_' . getmypid() . '.png';

function decode($png, $tmp) {
  file_put_contents($tmp, $png);
  // Lecture de l'image par OpenCV (simple chargeur ici), décodage par zbar.
  $py = 'import sys,cv2;from pyzbar.pyzbar import decode;'
      . 'r=decode(cv2.imread(sys.argv[1], cv2.IMREAD_GRAYSCALE));'
      . 'print(r[0].data.decode() if r else "")';
  return rtrim((string) shell_exec('python3 -c ' . escapeshellarg($py) . ' ' . escapeshellarg($tmp) . ' 2>/dev/null'), "\n");
}

$ko = 0; $ok = 0;

/* 1. LE CAS RÉEL : l'URL d'invitation, telle qu'elle est imprimée. Le jeton
      change à chaque bureau — on en tire donc plusieurs, au hasard. */
echo "== URL d'invitation (le cas imprimé) ==\n";
for ($i = 0; $i < 40; $i++) {
  $url = 'https://atelierby.be/webshop/inscription?c=inv_' . bin2hex(random_bytes(8));
  foreach (['M', 'Q'] as $ecl) {
    $r = qr_matrix($url, $ecl);
    if (!$r) { echo "KO  aucune matrice pour $url\n"; $ko++; continue; }
    [$m, $t] = $r;
    if (decode(qr_png($m, $t, 8, 4), $tmp) === $url) $ok++;
    else { echo "KO  $ecl v" . (($t - 17) / 4) . " : $url ne se décode pas\n"; $ko++; }
  }
}
echo "  $ok lecture(s) réussie(s)\n\n";

/* 2. BALAYAGE : longueurs et niveaux variés, pour que les tables des versions
      hautes soient exercées elles aussi. */
echo "== Balayage (longueurs et niveaux) ==\n";
$al = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~:/?#';
$scan = 0; $scanKo = [];
foreach ([1, 7, 16, 25, 40, 60, 90, 120, 180, 250, 400] as $n) {
  $texte = '';
  for ($i = 0; $i < $n; $i++) $texte .= $al[($i * 7 + $n) % strlen($al)];
  foreach (['L', 'M', 'Q', 'H'] as $ecl) {
    $r = qr_matrix($texte, $ecl);
    if (!$r) continue;
    [$m, $t] = $r;
    if (decode(qr_png($m, $t, 8, 4), $tmp) === $texte) $scan++;
    else $scanKo[] = "$n/$ecl (v" . (($t - 17) / 4) . ')';
  }
}
echo "  $scan lecture(s) réussie(s)" . ($scanKo ? ", échecs : " . implode(', ', $scanKo) : '') . "\n";
/* Le balayage exerce les tables des versions hautes ; c'est néanmoins le cas
   imprimé qui décide de l'échec du test — c'est lui qui finit au mur. */
@unlink($tmp);
echo "\n" . ($ko ? "$ko ÉCHEC(S) sur l'URL d'invitation — le QR de l'affiche ne serait pas lisible.\n"
                 : "URL d'invitation : lisible dans tous les cas testés.\n");
exit($ko ? 1 : 0);
