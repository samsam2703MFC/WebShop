<?php
/* Code QR — encodeur autonome (ISO/IEC 18004), mode octet.
 *
 * POURQUOI L'ÉCRIRE PLUTÔT QUE L'INSTALLER. Le serveur n'a ni composer ni
 * bibliothèque QR, et le seul autre chemin serait un service d'images distant :
 * l'URL d'invitation d'un client partirait alors chez un tiers à chaque
 * impression, et la génération dépendrait de sa disponibilité. Un QR est une
 * spécification fermée et testable — c'est le genre de dépendance qu'on porte.
 *
 * CE QUI GARANTIT LA JUSTESSE. Les tables ci-dessous (correction d'erreur par
 * bloc, nombre de blocs, positions des motifs d'alignement) ne se relisent pas :
 * elles se vérifient. php-api/tests/qr_test.php compare la matrice produite,
 * module par module, à celle d'un encodeur de référence (segno), sur des
 * centaines de longueurs et sur les quatre niveaux de correction. Une seule
 * case fausse dans une table fait échouer le test.
 *
 * Portée volontairement étroite : mode OCTET uniquement. Les modes numérique et
 * alphanumérique compressent mieux, mais une URL contient des minuscules et des
 * signes qui les excluent — les coder serait du code jamais emprunté.
 */

/* Correction d'erreur : nombre de codewords de correction PAR BLOC, indexé par
   version (1..40). Ligne par niveau. */
const QR_ECC_PER_BLOCK = [
  'L' => [0,7,10,15,20,26,18,20,24,30,18,20,24,26,30,22,24,28,30,28,28,28,28,30,30,26,28,30,30,30,30,30,30,30,30,30,30,30,30,30,30],
  'M' => [0,10,16,26,18,24,16,18,22,22,26,30,22,22,24,24,28,28,26,26,26,26,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28],
  'Q' => [0,13,22,18,26,18,24,18,22,20,24,28,26,24,20,30,24,28,28,26,30,28,30,30,30,30,28,30,30,30,30,30,30,30,30,30,30,30,30,30,30],
  'H' => [0,17,28,22,16,22,28,26,26,24,28,24,28,22,24,24,30,28,28,26,28,30,24,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30],
];

/* Nombre de blocs de correction d'erreur, même indexation. */
const QR_NUM_BLOCKS = [
  'L' => [0,1,1,1,1,1,2,2,2,2,4,4,4,4,4,6,6,6,6,7,8,8,9,9,10,12,12,12,13,14,15,16,17,18,19,19,20,21,22,24,25],
  'M' => [0,1,1,1,2,2,4,4,4,5,5,5,8,9,9,10,10,11,13,14,16,17,17,18,20,21,23,25,26,28,29,31,33,35,37,38,40,43,45,47,49],
  'Q' => [0,1,1,2,2,4,4,6,6,8,8,8,10,12,16,12,17,16,18,21,20,23,23,25,27,29,34,34,35,38,40,43,45,48,51,53,56,59,62,65,68],
  'H' => [0,1,1,2,4,4,4,5,6,8,8,11,11,16,16,18,16,19,21,25,25,25,34,30,32,35,37,40,42,45,48,51,54,57,60,63,66,70,74,77,81],
];

/* Bits d'indicateur du niveau de correction, tels qu'ils entrent dans le format. */
const QR_ECL_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

/* Modules de données bruts d'une version — la formule de la norme, pas une
   table : les motifs de fonction (finders, alignements, timing, format,
   version) sont retirés du total. */
function qr_raw_data_modules($ver) {
  $r = (16 * $ver + 128) * $ver + 64;
  if ($ver >= 2) {
    $na = intdiv($ver, 7) + 2;
    $r -= (25 * $na - 10) * $na - 55;
    if ($ver >= 7) $r -= 36;
  }
  return $r;
}

/* Codewords de DONNÉES disponibles pour une version et un niveau. */
function qr_data_codewords($ver, $ecl) {
  return intdiv(qr_raw_data_modules($ver), 8)
       - QR_ECC_PER_BLOCK[$ecl][$ver] * QR_NUM_BLOCKS[$ecl][$ver];
}

/* Positions des centres des motifs d'alignement (axes X = Y). */
function qr_align_positions($ver) {
  if ($ver === 1) return [];
  $na   = intdiv($ver, 7) + 2;
  $step = ($ver === 32) ? 26 : (intdiv($ver * 4 + $na * 2 + 1, $na * 2 - 2) * 2);
  // Le premier centre est TOUJOURS 6 ; les suivants descendent depuis le bord
  // opposé, par pas régulier. (Écraser pos[0] après coup effacerait le dernier
  // centre calculé : les motifs d'alignement se superposeraient au finder et
  // tout symbole de version ≥ 2 deviendrait illisible.)
  $pos  = [6];
  for ($p = $ver * 4 + 10; count($pos) < $na; $p -= $step) array_unshift($pos, $p);
  sort($pos);
  return $pos;
}

/* ── Corps de Galois GF(256) : l'arithmétique de Reed-Solomon ─────────────── */
function qr_gf_mul($a, $b) {
  $z = 0;
  for ($i = 7; $i >= 0; $i--) {
    $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
    $z ^= (($b >> $i) & 1) * $a;
    $z &= 0xFF;
  }
  return $z;
}

/* Polynôme générateur de degré $deg : (x−α⁰)(x−α¹)… */
function qr_rs_generator($deg) {
  $g = array_fill(0, $deg, 0);
  $g[$deg - 1] = 1;
  $root = 1;
  for ($i = 0; $i < $deg; $i++) {
    for ($j = 0; $j < $deg; $j++) {
      $g[$j] = qr_gf_mul($g[$j], $root);
      if ($j + 1 < $deg) $g[$j] ^= $g[$j + 1];
    }
    $root = qr_gf_mul($root, 2);
  }
  return $g;
}

function qr_rs_remainder(array $data, array $gen) {
  $deg = count($gen);
  $res = array_fill(0, $deg, 0);
  foreach ($data as $b) {
    $factor = $b ^ array_shift($res);
    $res[] = 0;
    for ($i = 0; $i < $deg; $i++) $res[$i] ^= qr_gf_mul($gen[$i], $factor);
  }
  return $res;
}

/* ── Encodage ─────────────────────────────────────────────────────────────── */

/* Matrice du QR d'une chaîne. Renvoie [matrice de 0/1, taille] ou null si le
   contenu dépasse la capacité de la version 40 (≈2 900 octets au niveau L). */
function qr_matrix($texte, $ecl = 'M', $masqueImpose = null) {
  $data = array_values(unpack('C*', (string) $texte));
  $n    = count($data);
  if (!isset(QR_ECC_PER_BLOCK[$ecl])) $ecl = 'M';

  // Version : la plus petite qui contient les données. En-tête = 4 bits de mode
  // + 8 ou 16 bits de longueur selon la version, d'où le seuil à 10.
  $ver = 0;
  for ($v = 1; $v <= 40; $v++) {
    $cap  = qr_data_codewords($v, $ecl) * 8;
    $need = 4 + ($v <= 9 ? 8 : 16) + $n * 8;
    if ($need <= $cap) { $ver = $v; break; }
  }
  if (!$ver) return null;

  // Flux de bits : mode octet (0100), longueur, données, terminateur, bourrage.
  $bits = '0100' . str_pad(decbin($n), $ver <= 9 ? 8 : 16, '0', STR_PAD_LEFT);
  foreach ($data as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
  $cap   = qr_data_codewords($ver, $ecl) * 8;
  $bits .= str_repeat('0', min(4, $cap - strlen($bits)));
  if (strlen($bits) % 8) $bits .= str_repeat('0', 8 - strlen($bits) % 8);
  $pad = 0;
  while (strlen($bits) < $cap) { $bits .= $pad ? '00010001' : '11101100'; $pad ^= 1; }

  $dw = [];
  for ($i = 0; $i < strlen($bits); $i += 8) $dw[] = bindec(substr($bits, $i, 8));

  // Découpage en blocs, correction par bloc, puis ENTRELACEMENT : c'est lui qui
  // rend le code robuste à une tache — une salissure locale abîme un codeword
  // dans chaque bloc, pas un bloc entier.
  $nb    = QR_NUM_BLOCKS[$ecl][$ver];
  $ecLen = QR_ECC_PER_BLOCK[$ecl][$ver];
  $total = intdiv(qr_raw_data_modules($ver), 8);
  $court = intdiv($total, $nb) - $ecLen;   // codewords de données des blocs courts
  $nCourt = $nb - $total % $nb;            // nombre de blocs courts
  $gen   = qr_rs_generator($ecLen);

  $blocs = []; $ecs = []; $k = 0;
  for ($i = 0; $i < $nb; $i++) {
    $len = $court + ($i < $nCourt ? 0 : 1);
    $b   = array_slice($dw, $k, $len); $k += $len;
    $blocs[] = $b;
    $ecs[]   = qr_rs_remainder($b, $gen);
  }
  $flux = [];
  for ($i = 0; $i <= $court; $i++)
    for ($j = 0; $j < $nb; $j++)
      if ($i < count($blocs[$j])) $flux[] = $blocs[$j][$i];
  for ($i = 0; $i < $ecLen; $i++)
    for ($j = 0; $j < $nb; $j++) $flux[] = $ecs[$j][$i];

  // ── Trame : motifs de fonction d'abord, données ensuite ──
  $size = $ver * 4 + 17;
  $m    = array_fill(0, $size, array_fill(0, $size, 0));   // module noir = 1
  $f    = array_fill(0, $size, array_fill(0, $size, 0));   // 1 = module de fonction

  $poser = function ($x, $y, $noir) use (&$m, &$f, $size) {
    if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) return;
    $m[$y][$x] = $noir ? 1 : 0; $f[$y][$x] = 1;
  };
  // Finders + séparateurs.
  foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$fx, $fy])
    for ($dy = -1; $dy <= 7; $dy++)
      for ($dx = -1; $dx <= 7; $dx++) {
        // Anneau du finder : noir aux distances 0, 1 et 3 ; blanc à 2 (le creux)
        // et à 4 — cette dernière EST le séparateur, la ligne blanche qui isole
        // le motif du reste. La rendre noire brouille les trois repères de
        // cadrage, et le code ne se lit plus du tout.
        $d = max(abs($dx - 3), abs($dy - 3));
        $poser($fx + $dx, $fy + $dy, $d !== 2 && $d !== 4);
      }
  // Alignements — jamais sur un finder.
  $ap = qr_align_positions($ver);
  $na = count($ap);
  for ($i = 0; $i < $na; $i++)
    for ($j = 0; $j < $na; $j++) {
      if (($i === 0 && $j === 0) || ($i === 0 && $j === $na - 1) || ($i === $na - 1 && $j === 0)) continue;
      for ($dy = -2; $dy <= 2; $dy++)
        for ($dx = -2; $dx <= 2; $dx++)
          $poser($ap[$j] + $dx, $ap[$i] + $dy, max(abs($dx), abs($dy)) !== 1);
    }
  // Timing, module noir obligatoire, réserve du format.
  for ($i = 7; $i < $size - 7; $i++) { $poser(6, $i, $i % 2 === 0); $poser($i, 6, $i % 2 === 0); }
  $poser(8, $size - 8, true);
  // Réserve du format — SAUF l'indice 6, qui appartient aux lignes de timing :
  // les écraser ici casserait la grille de repérage que le lecteur suit pour
  // compter les modules.
  for ($i = 0; $i <= 8; $i++) { if ($i === 6) continue; $poser(8, $i, false); $poser($i, 8, false); }
  for ($i = 0; $i < 8; $i++) { $poser($size - 1 - $i, 8, false); $poser(8, $size - 1 - $i, false); }
  // Information de version (≥ 7) : BCH(18,6), générateur 0x1F25.
  if ($ver >= 7) {
    $rem = $ver;
    for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
    $bitsV = ($ver << 12) | $rem;
    for ($i = 0; $i < 18; $i++) {
      $bit = ($bitsV >> $i) & 1;
      $poser($size - 11 + $i % 3, intdiv($i, 3), $bit);
      $poser(intdiv($i, 3), $size - 11 + $i % 3, $bit);
    }
  }

  // Données en zigzag, de bas à droite vers le haut, colonne double.
  $i = 0; $nbits = count($flux) * 8;
  for ($right = $size - 1; $right >= 1; $right -= 2) {
    if ($right === 6) $right = 5;                      // la colonne de timing se saute
    for ($v = 0; $v < $size; $v++) {
      for ($j = 0; $j < 2; $j++) {
        $x  = $right - $j;
        $up = ((($right + 1) & 2) === 0);
        $y  = $up ? $size - 1 - $v : $v;
        if ($f[$y][$x]) continue;
        $m[$y][$x] = ($i < $nbits) ? (($flux[$i >> 3] >> (7 - ($i & 7))) & 1) : 0;
        $i++;
      }
    }
  }

  // Masque : les huit sont essayés, le moins « pénalisé » gagne — c'est ce qui
  // évite les grandes plages uniformes qu'un lecteur confond avec un finder.
  $best = null; $bestPen = PHP_INT_MAX; $bestMask = 0;
  for ($mask = 0; $mask < 8; $mask++) {
    $t = $m;
    for ($y = 0; $y < $size; $y++)
      for ($x = 0; $x < $size; $x++) {
        if ($f[$y][$x]) continue;
        switch ($mask) {
          case 0: $inv = ($x + $y) % 2 === 0; break;
          case 1: $inv = $y % 2 === 0; break;
          case 2: $inv = $x % 3 === 0; break;
          case 3: $inv = ($x + $y) % 3 === 0; break;
          case 4: $inv = (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0; break;
          case 5: $inv = ($x * $y) % 2 + ($x * $y) % 3 === 0; break;
          case 6: $inv = (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0; break;
          default: $inv = ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0; break;
        }
        if ($inv) $t[$y][$x] ^= 1;
      }
    qr_write_format($t, $f, $ecl, $mask, $size);
    $p = ($masqueImpose !== null) ? ($mask === $masqueImpose ? -1 : PHP_INT_MAX) : qr_penalty($t, $size);
    if ($p < $bestPen) { $bestPen = $p; $best = $t; $bestMask = $mask; }
  }
  return [$best, $size];
}

/* Information de format : niveau + masque, BCH(15,5), générateur 0x537,
   masquée par 0x5412 pour qu'elle ne soit jamais toute blanche. */
function qr_write_format(array &$m, array $f, $ecl, $mask, $size) {
  $data = (QR_ECL_BITS[$ecl] << 3) | $mask;
  $rem  = $data;
  for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
  $bits = (($data << 10) | $rem) ^ 0x5412;
  for ($i = 0; $i <= 5; $i++)  $m[$i][8] = ($bits >> $i) & 1;
  $m[7][8] = ($bits >> 6) & 1;
  $m[8][8] = ($bits >> 7) & 1;
  $m[8][7] = ($bits >> 8) & 1;
  for ($i = 9; $i < 15; $i++)  $m[8][14 - $i] = ($bits >> $i) & 1;
  // SECONDE COPIE — les 8 premiers bits sur la LIGNE 8 en partant de la droite,
  // les 7 suivants dans la COLONNE 8 en bas. Les intervertir donne un symbole
  // dont les codewords sont pourtant justes : un lecteur qui ne lit que la
  // première copie le décode, un lecteur qui lit la seconde le rejette. C'est
  // exactement ce qui s'est produit ici, et seul un vrai décodeur l'a vu.
  for ($i = 0; $i < 8; $i++)   $m[8][$size - 1 - $i] = ($bits >> $i) & 1;
  for ($i = 8; $i < 15; $i++)  $m[$size - 15 + $i][8] = ($bits >> $i) & 1;
  $m[$size - 8][8] = 1;
}

/* Pénalité d'un masque — les quatre règles de la norme. */
function qr_penalty(array $m, $size) {
  $p = 0;
  /* Règles 1 et 3, ligne par ligne puis colonne par colonne.
     La règle 3 cherche la séquence 1:1:3:1:1 bordée d'une large plage claire —
     celle qui IMITE un motif de repérage. Ce n'est pas un détail : c'est elle
     qui décide qu'une caméra trouve, ou ne trouve pas, les trois coins du code.
     Un symbole mal masqué se décode module à module en laboratoire et reste
     introuvable au téléphone ; c'est exactement le défaut qui a rendu certains
     codes indétectables ici, jusqu'à ce qu'un vrai décodeur le montre.
     D'où l'historique glissant de sept plages : les bords du symbole comptent
     comme une plage claire de la largeur du symbole (la zone de silence), sans
     quoi les faux repères collés au bord passent inaperçus. */
  $ajoute = function (&$hist, $len) use ($size) {
    if ($hist[0] === 0) $len += $size;      // bord initial : zone de silence
    array_unshift($hist, $len);
    array_pop($hist);
  };
  $compte = function ($hist) {
    $n = $hist[1];
    $coeur = $n > 0 && $hist[2] === $n && $hist[3] === $n * 3 && $hist[4] === $n && $hist[5] === $n;
    return ($coeur && $hist[0] >= $n * 4 && $hist[6] >= $n ? 1 : 0)
         + ($coeur && $hist[6] >= $n * 4 && $hist[0] >= $n ? 1 : 0);
  };
  for ($sens = 0; $sens < 2; $sens++) {
    for ($y = 0; $y < $size; $y++) {
      $hist = array_fill(0, 7, 0);
      $couleur = 0; $run = 0;
      for ($x = 0; $x < $size; $x++) {
        $c = $sens ? $m[$x][$y] : $m[$y][$x];
        if ($c === $couleur) {
          $run++;
          if ($run === 5) $p += 3; elseif ($run > 5) $p++;
        } else {
          $ajoute($hist, $run);
          if (!$couleur) $p += $compte($hist) * 40;
          $couleur = $c; $run = 1;
        }
      }
      if ($couleur) { $ajoute($hist, $run); $run = 0; }
      $ajoute($hist, $run + $size);          // bord final : zone de silence
      $p += $compte($hist) * 40;
    }
  }
  for ($y = 0; $y + 1 < $size; $y++)
    for ($x = 0; $x + 1 < $size; $x++) {
      $c = $m[$y][$x];
      if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) $p += 3;
    }
  /* Règle 4 : l'écart à 50 % de modules noirs, par tranches de 5 points de
     pourcentage. Une image trop claire ou trop sombre se binarise mal sous un
     éclairage médiocre — celui d'une cafétéria, précisément. */
  $noirs = 0;
  foreach ($m as $ligne) $noirs += array_sum($ligne);
  $total = $size * $size;
  $k = intdiv(abs($noirs * 20 - $total * 10) + $total - 1, $total) - 1;
  $p += $k * 10;
  return $p;
}

/* ── Sorties ──────────────────────────────────────────────────────────────── */

/* PNG en noir et blanc, écrit à la main (1 bit par pixel, deflate + CRC).
   Pas de GD : l'extension peut manquer sur le serveur, et une affiche qui ne
   s'imprime pas parce qu'une extension manque n'est pas une affiche. */
function qr_png($matrice, $taille, $module = 8, $marge = 4) {
  $n   = $taille + 2 * $marge;
  $px  = $n * $module;
  $brut = '';
  for ($y = 0; $y < $px; $y++) {
    $ligne = ''; $my = intdiv($y, $module) - $marge;
    for ($x = 0; $x < $px; $x += 8) {
      $octet = 0;
      for ($b = 0; $b < 8; $b++) {
        $mx = intdiv($x + $b, $module) - $marge;
        $noir = ($my >= 0 && $my < $taille && $mx >= 0 && $mx < $taille) ? $matrice[$my][$mx] : 0;
        if (!$noir) $octet |= 1 << (7 - $b);   // 1 = blanc (niveaux de gris)
      }
      $ligne .= chr($octet);
    }
    $brut .= "\0" . $ligne;                    // filtre 0 : aucune prédiction
  }
  $bloc = function ($type, $data) {
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
  };
  return "\x89PNG\r\n\x1a\n"
       . $bloc('IHDR', pack('NN', $px, $px) . chr(1) . chr(0) . "\0\0\0")
       . $bloc('IDAT', gzcompress($brut, 9))
       . $bloc('IEND', '');
}

/* Données brutes 1 bit/pixel pour l'inclusion dans un PDF (ImageMask). */
function qr_bitmap($matrice, $taille, $marge = 4) {
  $n = $taille + 2 * $marge;
  $out = '';
  for ($y = 0; $y < $n; $y++) {
    $my = $y - $marge; $bits = '';
    for ($x = 0; $x < $n; $x++) {
      $mx = $x - $marge;
      $bits .= ($my >= 0 && $my < $taille && $mx >= 0 && $mx < $taille && $matrice[$my][$mx]) ? '1' : '0';
    }
    $bits = str_pad($bits, (int) (ceil($n / 8) * 8), '0');   // lignes alignées sur l'octet
    for ($i = 0; $i < strlen($bits); $i += 8) $out .= chr(bindec(substr($bits, $i, 8)));
  }
  return [$out, $n];
}
