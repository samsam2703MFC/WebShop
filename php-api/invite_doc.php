<?php
/* Le document d'invitation d'un bureau : e-mail + affiche PDF.
 *
 * DEUX SUPPORTS, DEUX GESTES.
 *   • L'E-MAIL va au contact du bureau, qui le TRANSFÈRE à son personnel. Sur
 *     un écran, le geste est le clic : le lien y est donc un BOUTON, et le
 *     jeton signé peut y être long, personne ne le lit.
 *   • L'AFFICHE PDF se punaise au mur d'une cafétéria. Là, le geste est la
 *     photo — ou la recopie. D'où le QR code ET l'URL courte en toutes lettres
 *     dessous : un code de vingt caractères se recopie, un jeton de deux cent
 *     cinquante, non.
 *
 * RÈGLE COMMUNE À TOUT CE FICHIER : rien n'est inventé. Une condition que la
 * base ne porte pas (franco, remise, fenêtre de livraison) DISPARAÎT du
 * document au lieu d'afficher « — » ou une valeur par défaut. Ces documents
 * partent chez un client qui les lit comme un engagement : une ligne fausse y
 * coûte plus cher qu'une ligne absente.
 */
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/pdf.php';

/* Récapitulatif d'un bureau, tel qu'il sera écrit au client. Chaque clé est
   null quand la donnée n'existe pas — l'appelant la retire de son rendu. */
/* $voucherCode : un code, une LISTE de codes, ou rien. Un onboarding peut
   porter plusieurs bons (bienvenue, découverte, geste commercial) ; n'en
   accepter qu'un faisait taire les autres dans le document envoyé au client. */
function invite_recap($officeId, $voucherCode = null) {
  $o = row("SELECT * FROM ws_offices WHERE id = ?", [(int) $officeId]);
  if (!$o) return null;
  $shopId = (int) ($o['shop_id'] ?? 0);
  $shop   = $shopId ? row("SELECT name, city FROM shops WHERE id = ?", [$shopId]) : null;
  $site   = row("SELECT name, address, floor_room, tournee_id FROM ws_office_delivery_sites
                  WHERE office_client_id = ? AND active = 1 ORDER BY id LIMIT 1", [(int) $officeId]);
  $tourId = (int) ($site['tournee_id'] ?? ($o['tour_id'] ?? 0));
  $tour   = $tourId ? row("SELECT name FROM ws_tours WHERE id = ?", [$tourId]) : null;

  // Fenêtre de livraison : jours desservis, plage horaire, heure limite de
  // commande. Sans ligne d'availability, la tournée n'a pas d'horaire publié —
  // et on préfère ne rien annoncer qu'annoncer 08:00 « par défaut ».
  $jours = null; $horaire = null; $cutoff = null;
  if ($tourId && col_exists('ws_tour_availability', 'tour_id')) {
    $J  = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
    $av = row("SELECT GROUP_CONCAT(DISTINCT delivery_day ORDER BY delivery_day) AS days,
                      TIME_FORMAT(MIN(delivery_start), '%H:%i') AS dep,
                      TIME_FORMAT(MAX(delivery_end), '%H:%i')   AS fin,
                      TIME_FORMAT(MIN(cutoff_time), '%H:%i')    AS cut
                 FROM ws_tour_availability WHERE tour_id = ? AND active = 1", [$tourId]);
    if ($av && $av['days'] !== null) {
      $jours   = implode(' · ', array_map(fn ($d) => $J[(((int) $d) + 6) % 7], explode(',', (string) $av['days'])));
      $horaire = ($av['dep'] && $av['fin']) ? ($av['dep'] . '–' . $av['fin']) : null;
      $cutoff  = $av['cut'] ? ($av['cut'] . ' la veille') : null;
    }
  }

  // Franco : le seuil de gratuité qui s'applique à CE bureau, du plus précis au
  // plus général — une règle « site » l'emporte sur une règle « boutique ».
  $franco = null; $frais = null;
  if (row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ws_delivery_fee_rules'")) {
    // Mêmes colonnes et même ordre de priorité que l'aperçu du checkout
    // (/delivery-fees/quote) : le document annonce ce qui sera facturé, sinon
    // il annonce autre chose que la commande.
    $r = row("SELECT free_delivery, always_charge, fee_amount, free_delivery_minimum
                FROM ws_delivery_fee_rules
               WHERE active = 1 AND ((level = 'office' AND office_client_id = ?)
                                  OR (level = 'tour'   AND tour_id = ?)
                                  OR (level = 'shop'   AND shop_id = ?) OR level = 'global')
               ORDER BY FIELD(level,'site','office','tour','shop','global') LIMIT 1",
             [(int) $officeId, $tourId, $shopId]);
    if ($r) {
      $fm     = (float) $r['free_delivery_minimum'];
      $franco = $fm > 0 ? (number_format($fm, 0, ',', ' ') . ' € par livraison') : null;
      $frais  = ((int) $r['free_delivery'] === 1) ? 'Livraison offerte'
              : (((float) $r['fee_amount']) > 0 ? number_format((float) $r['fee_amount'], 2, ',', ' ') . ' €' : null);
    }
  }

  // Remise négociée : celle de la boutique livreuse (colonnes à plat de shops).
  $remise = null;
  if ($shopId && col_exists('shops', 'discount_type') && col_exists('shops', 'discount_value')) {
    $d = row("SELECT discount_type AS t, discount_value AS v FROM shops WHERE id = ?", [$shopId]);
    $v = (float) ($d['v'] ?? 0);
    if ($v > 0) $remise = ($d['t'] === 'percent' || $d['t'] === 'percentage')
      ? (rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',') . ' %')
      : (number_format($v, 2, ',', ' ') . ' €');
  }

  $adresse = trim(implode(', ', array_filter([
    $site['address'] ?? ($o['address'] ?? ''),
    trim(($o['postal_code'] ?? '') . ' ' . ($o['city'] ?? '')),
  ])));

  return [
    'officeId'  => (int) $officeId,
    'raison'    => $o['name'] ?: null,
    'contact'   => $o['contact'] ?: null,
    'tva'       => ($o['vat'] ?? '') !== '' ? $o['vat'] : null,
    'email'     => $o['email'] ?: null,
    'boutique'  => $shop ? ($shop['name'] ?: $shop['city']) : null,
    'site'      => $site ? ($site['name'] ?: null) : null,
    'adresse'   => $adresse !== '' ? $adresse : null,
    'etage'     => ($site['floor_room'] ?? '') !== '' ? $site['floor_room'] : null,
    'tournee'   => $tour['name'] ?? null,
    'jours'     => $jours,
    'horaire'   => $horaire,
    'cutoff'    => $cutoff,
    'franco'    => $franco,
    'frais'     => $frais,
    'remise'    => $remise,
    'paiement'  => ((int) ($o['deferred_billing_enabled'] ?? 0) === 1) ? 'Facturation différée' : 'Paiement à la commande',
    /* Un bon peut arriver NOMMÉ (['code' => …, 'libelle' => …]) ou nu. Son
       libellé n'est pas en base — ws_vouchers n'en garde que le code — donc il
       ne survit qu'au moment de la création, où le franchisé vient de le
       saisir. Un renvoi ultérieur affichera le code seul : c'est exact, là où
       recoller « Bon de bienvenue » sur « Découverte petit-déjeuner » ne le
       serait pas. */
    'vouchers'  => ($vBons = array_values(array_filter(array_map(
                      function ($v) {
                        $c = trim((string) (is_array($v) ? ($v['code'] ?? '') : $v));
                        if ($c === '') return null;
                        return ['code' => $c,
                                'titre' => trim((string) (is_array($v) ? ($v['libelle'] ?? '') : ''))];
                      },
                      is_array($voucherCode) ? $voucherCode : [$voucherCode])))),
    'voucher'   => $vBons ? implode(' · ', array_column($vBons, 'code')) : null,
    'validated' => ((int) ($o['active'] ?? 0) === 1),
  ];
}

/* Les lignes « conditions » réellement remplies, dans l'ordre de lecture. */
function invite_conditions(array $r) {
  $l = [];
  $ajoute = function ($lbl, $val) use (&$l) { if ($val !== null && $val !== '') $l[] = [$lbl, $val]; };
  $ajoute('Livré par',        $r['boutique']);
  $ajoute('Site de livraison', $r['adresse']);
  $ajoute('Étage / local',    $r['etage']);
  $ajoute('Tournée',          $r['tournee']);
  $ajoute('Jours desservis',  $r['jours']);
  $ajoute('Fenêtre',          $r['horaire']);
  $ajoute('Commande jusqu’à', $r['cutoff']);
  $ajoute('Frais de livraison', $r['frais']);
  $ajoute('Franco',           $r['franco']);
  $ajoute('Remise négociée',  $r['remise']);
  $ajoute('Facturation',      $r['paiement']);
  $ajoute(count($r['vouchers'] ?? []) > 1 ? 'Bons de bienvenue' : 'Bon de bienvenue', $r['voucher']);
  return $l;
}

/* ── L'AFFICHE (PDF A4) ───────────────────────────────────────────────────── */

/* $url : le lien COURT (celui qu'on peut recopier). $expire : date lisible. */
function invite_pdf(array $r, $url, $expire = null) {
  $L = 595.28; $H = 841.89;                 // A4 en points
  $ruby = [0.553, 0.114, 0.173];
  $gris = [0.42, 0.40, 0.38];
  $noir = [0.13, 0.13, 0.13];
  $bord = [0.85, 0.83, 0.80];

  $c  = pdf_rect(0, $H - 118, $L, 118, $ruby);
  $c .= pdf_texte(48, $H - 52, 'F2', 11, 'L\'ATELIER BY', [1, 1, 1]);
  $c .= pdf_texte(48, $H - 82, 'F2', 24, 'Commandez au bureau', [1, 1, 1]);
  $c .= pdf_texte(48, $H - 104, 'F1', 12,
        'Votre entreprise a ouvert un compte — créez le vôtre en une minute.', [1, 1, 1]);

  $y = $H - 152;
  if ($r['raison']) { $c .= pdf_texte(48, $y, 'F2', 15, $r['raison'], $noir); $y -= 20; }
  $c .= pdf_texte(48, $y, 'F1', 11, 'Scannez ce code avec l\'appareil photo de votre téléphone.', $gris);
  $y -= 26;

  /* LE QR — dimensionné par sa LARGEUR, pas par son module.
     La consigne initiale (« module de 4 mm minimum ») ne tient pas sur une A4 :
     l'URL courte occupe une version 6, soit 41 modules plus la zone de
     silence — 4 mm chacun feraient 20 cm de large, et il ne resterait rien
     pour les conditions. Or ce n'est pas le module qui décide de la lecture,
     c'est la LARGEUR TOTALE : un lecteur accroche à environ dix fois celle-ci.
     72 mm donnent donc ~70 cm de portée, au-delà des 60 cm demandés, sur une
     affiche qui garde son texte. La correction d'erreur reste au niveau Q :
     l'affiche vit au mur d'une cafétéria, elle sera tachée. */
  $res = qr_matrix($url, 'Q');
  if (!$res) return null;
  [$mat, $taille] = $res;
  [$bits, $n] = qr_bitmap($mat, $taille, 4);
  $mm      = 72 / 25.4;                     // points par millimètre
  $cote    = min(72 * $mm, $L - 2 * 48);
  $qx      = ($L - $cote) / 2;
  $qy      = $y - $cote;
  $c      .= pdf_image('Im0', $qx, $qy, $cote, $cote, $noir);
  $y       = $qy - 30;

  // L'URL en toutes lettres, coupée en deux lignes si nécessaire.
  $c .= pdf_texte(48, $y, 'F1', 10, 'Ou tapez cette adresse dans votre navigateur :', $gris);
  $y -= 18;
  $morceaux = (pdf_largeur($url, 11, false, true) <= $L - 96) ? [$url] : str_split($url, 46);
  foreach ($morceaux as $bout) { $c .= pdf_texte(48, $y, 'F3', 11, $bout, $noir); $y -= 15; }
  if ($expire) { $y -= 6; $c .= pdf_texte(48, $y, 'F1', 10, 'Valable jusqu\'au ' . $expire, $gris); $y -= 18; }

  $y -= 10;
  $c .= pdf_ligne(48, $y, $L - 48, $y, $bord);
  $y -= 26;
  $c .= pdf_texte(48, $y, 'F2', 12, 'Vos conditions', $noir);
  $y -= 20;
  foreach (invite_conditions($r) as [$lbl, $val]) {
    if ($y < 80) break;                     // une page : le reste tient dans l'e-mail
    $c .= pdf_texte(48, $y, 'F1', 10, $lbl, $gris);
    $c .= pdf_texte(190, $y, 'F1', 10.5, $val, $noir);
    $y -= 16;
  }

  $c .= pdf_texte(48, 54, 'F1', 9,
        'Votre compte est transmis à votre boutique livreuse pour validation. Besoin d\'aide : aide@latelierby.be', $gris);

  return pdf_document($c, ['Im0' => ['bits' => $bits, 'n' => $n]], $L, $H);
}

/* ── L'E-MAIL ─────────────────────────────────────────────────────────────── */

/* Le corps HTML — rendu du GABARIT dessiné (mail/bienvenue-bureau.html), pas
   d'un HTML assemblé ici. Un courrier qui part chez un client se relit, se
   corrige et se traduit : il a sa place dans un fichier qu'on ouvre, pas dans
   une concaténation de chaînes.

   Le gabarit ne connaît que trois marqueurs (mail_render dans lib.php), et
   surtout : toute valeur absente y fait DISPARAÎTRE sa ligne. C'est la même
   règle que ce fichier applique depuis le début — une condition que la base ne
   porte pas ne doit pas être remplacée par un tiret, encore moins par une
   valeur par défaut.

   Gabarit introuvable (déploiement partiel) : on ne renvoie pas un courrier
   vide, on le DIT à l'appelant en renvoyant null — il refusera l'envoi plutôt
   que d'expédier une page blanche à un client. */
function invite_mail_html(array $r, $urlLong, $expire = null) {
  $f = __DIR__ . '/mail/bienvenue-bureau.html';
  if (!is_file($f)) return null;
  $vTpl = [];
  // Un bon peut arriver nommé (invite_recap) ou nu (appel direct) : les deux
  // se rendent, aucun ne fait tomber l'envoi.
  foreach (($r['vouchers'] ?? []) as $bon) {
    $code = trim((string) (is_array($bon) ? ($bon['code'] ?? '') : $bon));
    if ($code === '') continue;
    $vTpl[] = ['voucher_titre' => is_array($bon) ? (string) ($bon['titre'] ?? '') : '',
               'voucher_code' => $code, 'voucher_valeur' => '', 'voucher_validite' => ''];
  }
  /* Les commentaires du gabarit sont retirés du message envoyé : ils
     expliquent le fichier à qui le maintient, ils n'ont rien à faire chez le
     client, qui peut afficher la source. Les commentaires CONDITIONNELS
     (<!--[if mso]>) restent : ce sont des instructions pour Outlook, pas des
     explications. */
  $rendu = mail_render(file_get_contents($f), [
    'raison'        => (string) ($r['raison'] ?? ''),
    'contact_nom'   => (string) ($r['contact'] ?? ''),
    // Pas de code client dans le recap : la ligne disparaît du courrier
    // plutôt que d'afficher un identifiant approximatif.
    'code_client'   => '',
    'tva'           => (string) ($r['tva'] ?? ''),
    'site_adresse'  => (string) ($r['adresse'] ?? ''),
    'site_office'   => (string) ($r['site'] ?? ''),
    'site_etage'    => (string) ($r['etage'] ?? ''),
    'tournee'       => (string) ($r['tournee'] ?? ''),
    'jours'         => (string) ($r['jours'] ?? ''),
    'fenetre'       => (string) ($r['horaire'] ?? ''),
    'cutoff'        => (string) ($r['cutoff'] ?? ''),
    'frais'         => (string) ($r['frais'] ?? ''),
    'franco'        => (string) ($r['franco'] ?? ''),
    'remise_web'    => (string) ($r['remise'] ?? ''),
    'facturation'   => (string) ($r['paiement'] ?? ''),
    'boutique_nom'  => (string) ($r['boutique'] ?? ''),
    'url_webshop'   => (string) (cfg()['webshop_base'] ?? ''),
    'url_aide'      => 'mailto:aide@latelierby.be',
    'url_logo'      => (string) (cfg()['mail_logo_url'] ?? ''),
    'invite_url'    => (string) $urlLong,
    'invite_expire_le' => (string) ($expire ?? ''),
    'vouchers'      => $vTpl,
  ]);
  return preg_replace('/<!--(?!\[if|<!\[endif)(?:(?!<!\[endif).)*?-->/s', '', $rendu);
}

function invite_mail_texte(array $r, $urlLong, $expire = null) {
  $t = "Votre compte de livraison au bureau\n\n"
     . "Tout est en place pour " . ($r['raison'] ?: 'votre bureau') . ".\n\n"
     . "Créer un compte : $urlLong\n"
     . ($expire ? "Lien valable jusqu'au $expire\n" : '')
     . "\nTransférez cet e-mail à vos collaborateurs : le lien les amène directement au\n"
     . "formulaire, avec votre bureau et votre département déjà rattachés.\n"
     . "L'affiche jointe porte le même lien en QR code.\n\nVos conditions\n";
  foreach (invite_conditions($r) as [$lbl, $val]) $t .= "  $lbl : $val\n";
  return $t . "\nChaque compte créé nous est transmis pour validation avant sa première commande.\n"
            . "Besoin d'aide : aide@latelierby.be\n";
}

/* Envoi : multipart (texte + HTML) avec l'affiche en pièce jointe.
   Renvoie [bool envoyé, motif]. Le motif REMONTE À LA CONSOLE — « pas d'adresse
   de contact » et « le serveur a refusé l'envoi » n'appellent pas le même
   geste, et un e-mail qu'on croit parti est pire qu'un e-mail non envoyé. */
function invite_mail_envoyer(array $r, $urlLong, $urlCourt, $expire = null, $dest = null) {
  $to = $dest ?: $r['email'];
  if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL))
    return [false, 'Le bureau n’a pas d’adresse e-mail de contact — renseignez-la sur sa fiche pour lui envoyer le lien.'];
  $from = cfg()['mail_from'] ?? '';
  if (!$from) return [false, 'Aucune adresse d’expédition configurée sur le serveur (mail_from).'];
  if (!function_exists('mail')) return [false, 'La fonction d’envoi d’e-mail est désactivée sur ce serveur.'];

  $html = invite_mail_html($r, $urlLong, $expire);
  if ($html === null)
    return [false, 'Gabarit mail/bienvenue-bureau.html introuvable sur le serveur — rien n’a été envoyé.'];
  $pdf   = invite_pdf($r, $urlCourt, $expire);
  $bnd   = 'ab_' . bin2hex(random_bytes(8));
  $bndA  = 'ac_' . bin2hex(random_bytes(8));
  $sujet = '=?UTF-8?B?' . base64_encode('Votre compte L\'Atelier By — ' . ($r['raison'] ?: 'livraison au bureau')) . '?=';

  $corps  = "--$bnd\r\nContent-Type: multipart/alternative; boundary=\"$bndA\"\r\n\r\n";
  $corps .= "--$bndA\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode(invite_mail_texte($r, $urlLong, $expire))) . "\r\n";
  $corps .= "--$bndA\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($html)) . "\r\n";
  $corps .= "--$bndA--\r\n";
  if ($pdf) {
    $nom = 'invitation-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($r['raison'] ?? 'bureau')) . '.pdf';
    $corps .= "--$bnd\r\nContent-Type: application/pdf; name=\"$nom\"\r\n"
            . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$nom\"\r\n\r\n"
            . chunk_split(base64_encode($pdf)) . "\r\n";
  }
  $corps .= "--$bnd--\r\n";

  $entetes = "From: $from\r\nMIME-Version: 1.0\r\n"
           . "Content-Type: multipart/mixed; boundary=\"$bnd\"\r\n";
  $ok = @mail($to, $sujet, $corps, $entetes);
  if (!$ok) return [false, 'Le serveur d’envoi a refusé le message — vérifiez la configuration mail de l’hébergement.'];
  return [true, $pdf ? null : 'E-mail envoyé, mais l’affiche PDF n’a pas pu être produite.'];
}
