<?php
/* PDF — écrivain minimal, sans dépendance.
 *
 * De quoi produire UNE affiche : du texte dans les polices de base, des filets
 * et des aplats de couleur, et une image en noir et blanc (le QR). Rien de
 * plus : ni polices embarquées, ni transparence, ni pages multiples.
 *
 * POURQUOI PAS UNE BIBLIOTHÈQUE. Le serveur n'a pas composer, et le format PDF
 * dont on a besoin ici tient en deux cents lignes lisibles. Embarquer FPDF ou
 * TCPDF ferait entrer des milliers de lignes non lues pour un document d'une
 * page — et il faudrait quand même écrire le QR nous-mêmes.
 *
 * ENCODAGE. Les polices de base sont déclarées en WinAnsiEncoding : le texte
 * est donc converti de UTF-8 vers CP1252 avant d'être écrit. Sans cette
 * conversion, « fenêtre » s'imprime « fenÃªtre » — et personne ne relit un PDF
 * généré automatiquement avant de l'envoyer au client.
 */

/* Échappement d'une chaîne littérale PDF, après passage en CP1252. */
function pdf_txt($s) {
  $s = (string) $s;
  $c = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
  if ($c === false) $c = preg_replace('/[^\x20-\x7E]/', '', $s);   // repli : ASCII
  return strtr($c, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '']);
}

/* Largeur approchée d'un texte, en points — suffisante pour centrer et pour
   couper une URL en deux lignes. Les métriques exactes des polices de base
   demanderaient une table AFM par police ; l'écart ne se voit pas ici. */
function pdf_largeur($texte, $taille, $gras = false, $mono = false) {
  if ($mono) return strlen($texte) * $taille * 0.6;
  return strlen($texte) * $taille * ($gras ? 0.56 : 0.5);
}

/* Opérateurs de dessin — composés par l'appelant dans un flux de contenu. */
function pdf_rect($x, $y, $w, $h, $rgb) {
  return sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n", $rgb[0], $rgb[1], $rgb[2], $x, $y, $w, $h);
}
function pdf_ligne($x1, $y1, $x2, $y2, $rgb, $ep = 0.5) {
  return sprintf("%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S\n", $rgb[0], $rgb[1], $rgb[2], $ep, $x1, $y1, $x2, $y2);
}
function pdf_texte($x, $y, $police, $taille, $texte, $rgb = [0, 0, 0]) {
  return sprintf("%.3f %.3f %.3f rg BT /%s %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n",
                 $rgb[0], $rgb[1], $rgb[2], $police, $taille, $x, $y, pdf_txt($texte));
}
/* Image 1 bit (le QR) : dessinée en masque — les bits à 1 peignent en noir,
   le reste laisse le papier. Pas de fond blanc imposé : la zone de silence est
   du papier, donc parfaitement blanche à l'impression. */
function pdf_image($nom, $x, $y, $w, $h, $rgb = [0, 0, 0]) {
  return sprintf("q %.3f %.3f %.3f rg %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q\n",
                 $rgb[0], $rgb[1], $rgb[2], $w, $h, $x, $y, $nom);
}

/* Assemble le document. $images : ['Im0' => ['bits' => données 1bpp, 'n' => côté]].
   Une seule page, format donné en points (A4 = 595 × 842). */
function pdf_document($contenu, array $images = [], $largeur = 595.28, $hauteur = 841.89) {
  $obj = [];                       // les objets, indexés à partir de 1
  $police = function ($nom) { return "<< /Type /Font /Subtype /Type1 /BaseFont /$nom /Encoding /WinAnsiEncoding >>"; };

  $obj[1] = "<< /Type /Catalog /Pages 2 0 R >>";
  $obj[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
  // 3 = page, 4 = flux de contenu, 5..7 = polices, 8+ = images
  $ressImg = '';
  $n = 8;
  $flux = [];
  foreach ($images as $nom => $img) {
    $ressImg .= "/$nom $n 0 R ";
    $data = gzcompress($img['bits'], 9);
    $obj[$n] = "<< /Type /XObject /Subtype /Image /Width {$img['n']} /Height {$img['n']}"
             . " /ImageMask true /Decode [1 0] /BitsPerComponent 1 /Filter /FlateDecode"
             . " /Length " . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    $n++;
  }
  $obj[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 "
          . sprintf('%.2f %.2f', $largeur, $hauteur) . "] /Contents 4 0 R"
          . " /Resources << /Font << /F1 5 0 R /F2 6 0 R /F3 7 0 R >>"
          . ($ressImg ? " /XObject << $ressImg>>" : '') . " >> >>";
  $flu = gzcompress($contenu, 9);
  $obj[4] = "<< /Length " . strlen($flu) . " /Filter /FlateDecode >>\nstream\n" . $flu . "\nendstream";
  $obj[5] = $police('Helvetica');
  $obj[6] = $police('Helvetica-Bold');
  $obj[7] = $police('Courier');

  ksort($obj);
  $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
  $pos = [];
  foreach ($obj as $i => $corps) {
    $pos[$i] = strlen($out);
    $out .= "$i 0 obj\n$corps\nendobj\n";
  }
  $xref = strlen($out);
  $max  = max(array_keys($obj)) + 1;
  $out .= "xref\n0 $max\n0000000000 65535 f \n";
  for ($i = 1; $i < $max; $i++)
    $out .= sprintf("%010d 00000 n \n", $pos[$i] ?? 0);
  $out .= "trailer\n<< /Size $max /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
  return $out;
}
