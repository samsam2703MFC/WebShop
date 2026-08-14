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

  /* BONS DISPONIBLES — les vouchers ACTIFS en base pour cette boutique
     (locaux + réseau), non expirés, non épuisés. C'est ce que l'affiche
     annonce en tuiles : la liste du moment, relue à chaque impression, pas
     celle figée à la création du bureau. Huit au plus — une affiche est un
     A4, au-delà les tuiles poussent les conditions hors page ; les huit
     retenus sont ceux qui expirent le plus tôt (l'urgence d'abord). */
  $bonsDispo = [];
  if (row("SELECT 1 x FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ws_vouchers'")) {
    $bonsDispo = rows(
      "SELECT code, type, value, min_order, DATE_FORMAT(expires_at, '%d/%m/%Y') AS fin
         FROM ws_vouchers
        WHERE active = 1
          AND (shop_id IS NULL" . ($shopId ? " OR shop_id = " . (int) $shopId : "") . ")
          AND (expires_at IS NULL OR expires_at > NOW())
          AND (max_uses IS NULL OR used_count < max_uses)
        ORDER BY (expires_at IS NULL), expires_at, code LIMIT 8");
  }

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
    'bonsDispo' => $bonsDispo,
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

  /* Bandeau : le LOGO, pas son nom en lettres. Il est posé en masque 1 bit
     peint en blanc (php-api/logo_mask.php, généré depuis le PNG) — la seule
     forme d'image que l'écrivain PDF sait poser, et celle qui ne dépend
     d'aucune extension du serveur. Si le masque manque, le nom en toutes
     lettres reprend sa place : une affiche sans logo vaut mieux qu'une affiche
     sans en-tête. */
  $c  = pdf_rect(0, $H - 140, $L, 140, $ruby);
  $logo = @include __DIR__ . '/logo_mask.php';
  $imgs = [];
  if (is_array($logo) && !empty($logo['bits'])) {
    // Le logo porte DEUX lignes (le nom et sa signature) : il lui faut sa
    // propre hauteur, sinon le titre vient mordre dessus.
    $lh = 30;                                  // hauteur imprimée, en points
    $lw = $lh * ($logo['w'] / max(1, $logo['h']));
    $c .= pdf_image('Lg0', 48, $H - 38 - $lh, $lw, $lh, [1, 1, 1]);
    $imgs['Lg0'] = ['bits' => $logo['bits'], 'w' => $logo['w'], 'h' => $logo['h']];
  } else {
    $c .= pdf_texte(48, $H - 52, 'F2', 11, 'L\'ATELIER BY', [1, 1, 1]);
  }
  $c .= pdf_texte(48, $H - 98, 'F2', 24, 'Commandez au bureau', [1, 1, 1]);
  $c .= pdf_texte(48, $H - 120, 'F1', 12,
        'Votre entreprise a ouvert un compte — créez le vôtre en une minute.', [1, 1, 1]);

  $y = $H - 174;
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

  $imgs['Im0'] = ['bits' => $bits, 'n' => $n];
  return pdf_document($c, $imgs, $L, $H);
}

/* ── L'AFFICHE EN HTML (imprimée / exportée en PDF par le navigateur) ──────
   POURQUOI DEUX AFFICHES. Celle du dessus est écrite en PDF par le serveur :
   il lui faut un fichier à joindre à l'e-mail, et un serveur PHP ne sait pas
   rendre du HTML. Mais un PDF écrit à la main n'a ni les polices de la marque,
   ni son système de composants — un PDF n'a pas de moteur CSS, et embarquer
   Gotham demanderait de coder l'inclusion d'une police OpenType.
   Le navigateur, lui, sait déjà tout cela. Cette version-ci est donc du HTML
   servi tel quel : Gotham, les couleurs de la marque, la mise en page — et
   « Imprimer → Enregistrer en PDF » produit le fichier, avec le rendu exact.
   Les ressources sont en URL ABSOLUE ou en data: : la page s'ouvre depuis un
   blob (l'endpoint exige le jeton admin), où les chemins relatifs ne résolvent
   plus. */
function invite_affiche_html(array $r, $url, $expire = null, $racine = '') {
  $e = fn ($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
  $res = qr_matrix($url, 'Q');
  $qr  = $res ? ('data:image/png;base64,' . base64_encode(qr_png($res[0], $res[1], 8, 4))) : '';
  /* OÙ SONT LES FICHIERS. Le déploiement ne reproduit pas l'arborescence du
     dépôt : php-api/ part dans <racine>/api/ et dist/ dans <racine>/. Les
     polices et le logo atterrissent donc dans <racine>/fonts/ et
     <racine>/logo-white.png, PAS dans un <racine>/public/ — ce dossier
     n'existe que dans le dépôt.

     Lire uniquement ../public/ marchait en local et échouait en production :
     l'affiche y sortait sans une seule règle @font-face, donc en Helvetica,
     sans que rien ne le signale. On essaie donc les deux dispositions. */
  $lire = function ($chemin) {
    foreach ([__DIR__ . '/../' . $chemin,           // serveur : <racine>/…
              __DIR__ . '/../public/' . $chemin,    // dépôt   : public/…
              __DIR__ . '/../dist/' . $chemin] as $c)
      if (is_file($c) && ($b = @file_get_contents($c)) !== false) return $b;
    return null;
  };
  $logo = $lire('logo-white.png');
  $logoSrc = $logo ? ('data:image/png;base64,' . base64_encode($logo)) : ($racine . '/logo-white.png');

  /* Les conditions sur DEUX colonnes. Empilées, les douze lignes que
     invite_conditions peut produire au maximum débordaient de 45 mm et
     l'affiche sortait sur deux pages — une affiche se punaise, elle ne
     s'agrafe pas. Deux colonnes ramènent la hauteur sous l'A4 avec de la
     marge pour les valeurs qui reviennent à la ligne (une adresse longue). */
  $lignes = '';
  foreach (invite_conditions($r) as [$lbl, $val])
    $lignes .= '<dt>' . $e($lbl) . '</dt><dd>' . $e($val) . '</dd>';

  /* Les TUILES de bons — celles du récap (actifs en base, huit au plus).
     La valeur s'affiche selon le type ; un bon d'onboarding (add_office,
     valeur 0) se présente par son rôle. Rien en base ⇒ pas de section. */
  $tuiles = '';
  foreach ((array) ($r['bonsDispo'] ?? []) as $b) {
    $v = (float) ($b['value'] ?? 0);
    $mnt = null;
    if ($v > 0) $mnt = ($b['type'] === 'percent' || $b['type'] === 'percentage')
      ? ('−' . rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',') . ' %')
      : ('−' . rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',') . ' €');
    elseif (($b['type'] ?? '') === 'add_office') $mnt = 'Bon de bienvenue';
    $min = (float) ($b['min_order'] ?? 0);
    $tuiles .= '<div class="bon"><div class="bon-code">' . $e($b['code']) . '</div>'
      . ($mnt !== null ? '<div class="bon-val">' . $e($mnt) . '</div>' : '')
      . ($min > 0 ? '<div class="bon-min">dès ' . $e(rtrim(rtrim(number_format($min, 2, ',', ' '), '0'), ',')) . ' € d\'achat</div>' : '')
      . (!empty($b['fin']) ? '<div class="bon-min">jusqu\'au ' . $e($b['fin']) . '</div>' : '')
      . '</div>';
  }

  /* Les polices sont EMBARQUÉES, pas liées. La console ouvre cette page depuis
     un blob: — même origine qu'elle, donc AUTRE origine que l'API qui sert les
     .otf — et une requête @font-face part toujours en mode anonyme : sans
     en-tête CORS sur les fichiers de police, Gotham serait silencieusement
     remplacée par Helvetica et l'affiche imprimée ne serait plus à la marque.
     Embarquées, elles survivent aussi à un « enregistrer sous » du franchisé.

     Police introuvable (déploiement partiel) : la règle @font-face est OMISE
     plutôt qu'écrite dans le vide, et la pile de repli Helvetica/Arial prend le
     relais. Le rendu se dégrade, il ne casse pas — mais il le DIT : les
     polices réellement embarquées sont listées dans <meta name="polices">,
     que la sonde check-endpoints lit. Sans ce marqueur, une affiche hors
     charte est indiscernable d'une affiche à la charte tant que personne ne
     l'imprime. */
  $embarquees = [];
  $police = function ($fichier, $poids, $famille = 'Gotham') use ($lire, &$embarquees) {
    $b = $lire('fonts/' . $fichier);
    if ($b === null) return '';
    $embarquees[] = "$famille:$poids";
    $fmt = substr($fichier, -4) === '.ttf' ? 'truetype' : 'opentype';
    $mime = $fmt === 'truetype' ? 'font/ttf' : 'font/otf';
    return "@font-face{font-family:'$famille';src:url(data:$mime;base64," . base64_encode($b)
         . ") format('$fmt');font-weight:$poids;font-display:swap}";
  };
  /* Les mêmes familles et les mêmes graisses que le webshop (index CSS de la
     marque) : Vank pour l'affichage, Gotham 300→700 pour le reste. On n'en
     embarque que ce que cette feuille emploie — chaque fichier pèse ~125 ko. */
  $css = $police('GC_Vank.ttf', 400, 'Vank')
       . $police('Gotham_Book.otf', 400)
       . $police('Gotham_Medium.otf', 600)
    /* Les JETONS DE LA MARQUE, repris tels quels du CSS du webshop
       (--color-primary, --color-secondary, --color-bg, --color-text,
       --color-text-muted, --color-border-secondary). Les valeurs approchées
       que portait cette feuille (#6b6560 au lieu de #666666, une bordure à
       .14 au lieu de .18) faisaient une affiche « presque » à la charte. */
    . ':root{--ruby:#8D1D2C;--abricot:#F2C9A0;--fond:#EAE4DC;--surface:#FFF;'
    . '--txt:#222222;--mut:#666666;--bord:rgba(34,34,34,.18);'
    . '--ui:Gotham,Helvetica,Arial,sans-serif;--display:Vank,Gotham,Helvetica,Arial,sans-serif}'
    . '*{box-sizing:border-box}'
    . 'body{margin:0;background:var(--fond);color:var(--txt);font:400 15px/1.5 var(--ui)}'
    /* La feuille : exactement une A4, marges comprises — ce qui s'affiche à
       l'écran est ce qui sort de l'imprimante. */
    . '.feuille{width:210mm;min-height:297mm;margin:0 auto;background:#fff;'
    . 'box-shadow:0 10px 40px rgba(0,0,0,.12);display:flex;flex-direction:column}'
    . '.bandeau{background:var(--ruby);color:#fff;padding:14mm 16mm 10mm}'
    . '.bandeau img{height:12mm;width:auto;display:block;margin-bottom:5mm}'
    . '.bandeau h1{margin:0 0 3mm;font:400 34px/1.12 var(--display);letter-spacing:.005em}'
    . '.bandeau p{margin:0;font:400 14px/1.5 var(--ui);opacity:.9}'
    . '.corps{padding:10mm 16mm 12mm;flex:1;display:flex;flex-direction:column}'
    . '.raison{font:600 20px var(--ui);margin:0 0 2mm}'
    . '.consigne{font:400 13px var(--ui);color:var(--mut);margin:0 0 6mm}'
    . '.qr{display:block;width:58mm;height:58mm;margin:0 auto 5mm;image-rendering:pixelated}'
    . '.url{text-align:center;margin-bottom:7mm}'
    . '.url .lbl{font:400 12px var(--ui);color:var(--mut);margin-bottom:2mm}'
    . '.url .adr{font:600 13px/1.4 var(--ui);letter-spacing:.01em;word-break:break-all}'
    . '.url .exp{font:400 12px var(--ui);color:var(--mut);margin-top:2mm}'
    . 'h2{font:600 15px var(--ui);margin:0 0 4mm;padding-top:5mm;border-top:.4mm solid var(--bord)}'
    /* Quatre pistes = deux paires « intitulé / valeur » par ligne. L'intitulé
       a une largeur fixe pour que les deux colonnes s'alignent ; la valeur
       prend le reste et peut revenir à la ligne sans pousser sa voisine. */
    . '.cond{display:grid;grid-template-columns:33mm 1fr 33mm 1fr;gap:1.4mm 4mm;'
    . 'margin:0;font:400 12.5px/1.35 var(--ui)}'
    . '.cond dt{color:var(--mut)}'
    . '.cond dd{margin:0;overflow-wrap:anywhere}'
    /* La REMISE en gros et en gras — c'est l'argument de l'affiche, pas une
       ligne parmi les conditions (où elle reste aussi, pour le récapitulatif). */
    . '.remise{background:var(--abricot);border-radius:3mm;text-align:center;'
    . 'padding:4.5mm 5mm;margin:0 0 6mm;font:600 17px/1.3 var(--ui)}'
    . '.remise b{font:700 26px/1.1 var(--ui);color:var(--ruby);letter-spacing:.01em}'
    /* Les BONS en tuiles — quatre par ligne, huit au plus (limite du récap). */
    . '.bons{display:grid;grid-template-columns:repeat(4,1fr);gap:3mm;margin:0 0 2mm}'
    . '.bon{border:.5mm solid var(--ruby);border-radius:2.5mm;padding:3mm 2.5mm;text-align:center;page-break-inside:avoid}'
    . '.bon-code{font:700 13px/1.2 var(--ui);letter-spacing:.04em;word-break:break-all}'
    . '.bon-val{font:700 15px/1.2 var(--ui);color:var(--ruby);margin-top:1mm}'
    . '.bon-min{font:400 10px/1.35 var(--ui);color:var(--mut);margin-top:1mm}'
    . '.pied{margin-top:auto;padding-top:6mm;font:400 11.5px var(--ui);color:var(--mut)}'
    /* La barre d'action ne s'imprime pas : elle n'existe qu'à l'écran. */
    . '.barre{position:fixed;top:0;left:0;right:0;background:var(--ruby);color:#fff;padding:10px 16px;'
    . 'display:flex;align-items:center;gap:14px;font:400 13px var(--ui);z-index:9}'
    . '.barre button{font:600 13px var(--ui);border:none;border-radius:8px;'
    . 'background:#fff;color:var(--ruby);padding:8px 16px;cursor:pointer}'
    . 'body{padding-top:52px}'
    /* À l'impression la feuille GARDE sa hauteur : c'est elle qui pousse le
       pied de page en bas, via le margin-top:auto. 296 et non 297 — un A4 vaut
       297 mm au millimètre près, et arrondir par excès suffisait à faire naître
       une seconde page vide. */
    . '@media print{body{background:#fff;padding:0}.barre{display:none}'
    . '.feuille{width:auto;min-height:296mm;box-shadow:none;margin:0}'
    . '@page{size:A4;margin:0}'
    . '*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}';

  /* Le CSS est assemblé AVANT la page : c'est en le construisant que $police
     remplit $embarquees, et le marqueur ci-dessous doit pouvoir le lire. */
  return '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
    . '<title>Affiche — ' . $e($r['raison'] ?? 'invitation') . '</title>'
    // Ce que la sonde lit pour dire si l'affiche est VRAIMENT à la charte.
    . '<meta name="polices" content="' . $e($embarquees ? implode(' ', $embarquees) : 'aucune') . '">'
    . '<style>' . $css
    . '</style></head><body>'
    . '<div class="barre"><button onclick="window.print()">Imprimer · enregistrer en PDF</button>'
    . '<span>Choisissez « Enregistrer au format PDF » comme destination — la mise en page est déjà au format A4.</span></div>'
    . '<div class="feuille">'
    . '<div class="bandeau"><img src="' . $e($logoSrc) . '" alt="L\'Atelier By">'
    . '<h1>Commandez au bureau</h1>'
    . '<p>Votre entreprise a ouvert un compte — créez le vôtre en une minute.</p></div>'
    . '<div class="corps">'
    . ($r['raison'] ? '<p class="raison">' . $e($r['raison']) . '</p>' : '')
    . '<p class="consigne">Scannez ce code avec l\'appareil photo de votre téléphone.</p>'
    . ($qr ? '<img class="qr" src="' . $qr . '" alt="Code QR vers le formulaire d\'inscription">' : '')
    . '<div class="url"><div class="lbl">Ou tapez cette adresse dans votre navigateur :</div>'
    . '<div class="adr">' . $e($url) . '</div>'
    . ($expire ? '<div class="exp">Valable jusqu\'au ' . $e($expire) . '</div>' : '') . '</div>'
    /* La remise, EN GROS : seulement quand la base en porte une (> 0) —
       jamais un « 0 % » d'affiche. Le format vient du récap (% ou €). */
    . ($r['remise'] ? '<div class="remise">Remise : <b>' . $e($r['remise']) . '</b> sur tous vos achats</div>' : '')
    . ($tuiles ? '<h2>Vos bons de réduction</h2><div class="bons">' . $tuiles . '</div>' : '')
    . ($lignes ? '<h2>Vos conditions</h2><dl class="cond">' . $lignes . '</dl>' : '')
    . '<p class="pied">Votre compte est transmis à votre boutique livreuse pour validation.<br>'
    . 'Besoin d\'aide : aide@latelierby.be</p>'
    . '</div></div></body></html>';
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
