<?php
/* Actions de prospection depuis la fiche d'une cible (CRM, migration 0129) :
   le mail, le SMS et l'appel partent d'un MODÈLE dont les variables se
   remplissent avec la carte, la campagne et la boutique. La console montre
   le texte rendu, le franchisé le retouche, et c'est CE texte qui part — le
   serveur n'invente rien après coup.

   Le mail part par mail() natif, comme l'invitation d'un bureau
   (invite_doc.php) : From = mail_from du serveur (SPF), Reply-To = l'adresse
   de la boutique pour que la réponse arrive au franchisé, pièces jointes en
   multipart/mixed. Le SMS passe par sms_envoyer() (SMSAPI) quand un jeton est
   posé ; sans jeton la console ouvre l'application SMS du téléphone et le dit.
   L'appel ne part pas d'ici : la console compose le numéro et consigne
   l'issue. */

/* ── Variables d'un modèle ─────────────────────────────────────────────── */

/* Ce que le modèle peut citer, avec la valeur prise sur la carte. Tout est
   une chaîne ; une valeur absente donne '' — un « {bon} » vide dans un mail
   se voit à l'aperçu, avant l'envoi, jamais chez le client. */
function crm_variables(array $carte, ?array $boutique, $campagneNom = null): array {
  $b = $boutique ?: [];
  $contact = trim((string) ($carte['contact_nom'] ?? ''));
  return [
    'contact'        => $contact !== '' ? $contact : 'Madame, Monsieur',
    'societe'        => (string) ($carte['nom'] ?? ''),
    'bon'            => implode(', ', crm_bons_liste($carte['voucher_code'] ?? '')),
    'campagne'       => (string) ($campagneNom ?: ($carte['campagne_nom'] ?? '')),
    'boutique'       => (string) ($b['name'] ?? ''),
    'tel_boutique'   => (string) ($b['phone'] ?? ''),
    'email_boutique' => (string) ($b['email'] ?? ''),
    'ville_boutique' => (string) ($b['city'] ?? ''),
    'adresse'        => (string) ($carte['adresse'] ?? ''),
  ];
}

/* Liste documentée pour la console (puces sous l'éditeur). */
function crm_variables_doc(): array {
  return [
    ['cle' => 'contact', 'aide' => 'nom du contact (sinon « Madame, Monsieur »)'],
    ['cle' => 'societe', 'aide' => 'nom de la cible'],
    ['cle' => 'bon', 'aide' => 'le ou les bons remis, séparés par des virgules'],
    ['cle' => 'campagne', 'aide' => 'nom de la campagne'],
    ['cle' => 'boutique', 'aide' => 'nom de votre boutique'],
    ['cle' => 'tel_boutique', 'aide' => 'téléphone de la boutique'],
    ['cle' => 'email_boutique', 'aide' => 'e-mail de la boutique'],
    ['cle' => 'adresse', 'aide' => 'adresse de la cible'],
  ];
}

/* ── Les bons d'une cible ──────────────────────────────────────────────
   Une cible peut porter plusieurs bons (0130) : la colonne voucher_code les
   garde séparés par des virgules. Ces deux fonctions sont le seul endroit qui
   connaît ce détail — partout ailleurs c'est un tableau de codes. */
function crm_bons_liste($brut): array {
  $out = [];
  foreach (explode(',', (string) ($brut ?? '')) as $c) {
    $c = mb_strtoupper(trim($c));
    if ($c !== '' && !in_array($c, $out, true)) $out[] = $c;
  }
  return $out;
}

function crm_bons_joindre($liste): ?string {
  $out = [];
  foreach (is_array($liste) ? $liste : [] as $c) {
    $c = mb_substr(mb_strtoupper(trim((string) $c)), 0, 60);
    if ($c !== '' && !in_array($c, $out, true)) $out[] = $c;
  }
  $j = implode(',', $out);
  return mb_substr($j, 0, 255) ?: null;
}

/* « Bonjour {contact}, » → « Bonjour Xavier, ». Accolades simples, à dessein
   distinctes du {{ }} des gabarits HTML (mail_render) : ici c'est du texte. */
function crm_rendre($txt, array $vars): string {
  return (string) preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $vars[$m[1]] ?? $m[0], (string) $txt);
}

/* ── Modèles par défaut ────────────────────────────────────────────────── */

/* Posés pour une boutique à son premier appel, comme les colonnes du tableau :
   l'écran ne s'ouvre jamais vide, et tout se réécrit ensuite. */
function crm_modeles_defaut(): array {
  return [
    ['type' => 'mail', 'nom' => 'Présentation de la campagne',
     'sujet' => '{boutique} — votre sélection {campagne} pour {societe}',
     'corps' => "Bonjour {contact},\n\nNous sommes {boutique}, pâtisserie-boulangerie artisanale à côté de chez vous. Pour {campagne}, nous livrons au bureau : viennoiseries le matin, plateaux pour vos réunions, tartes pour les anniversaires.\n\nVotre bon de découverte : {bon} — à utiliser sur votre première commande.\n\nJe passe volontiers vous présenter la gamme sur place : dites-moi le créneau qui vous arrange.\n\nÀ bientôt,\n{boutique} · {tel_boutique}"],
    ['type' => 'mail', 'nom' => 'Relance après la visite',
     'sujet' => '{societe} — suite à ma visite',
     'corps' => "Bonjour {contact},\n\nMerci pour votre accueil lors de ma visite. Comme convenu, voici notre carte, et le bon {bon} pour votre première commande.\n\nSouhaitez-vous que je prépare un plateau de dégustation pour votre équipe ? Un mot et je le dépose.\n\nBien à vous,\n{boutique} · {tel_boutique}"],
    ['type' => 'sms', 'nom' => 'Annonce de passage',
     'sujet' => null,
     // Sans caractère hors table GSM 7 bits (ç, ê, À, ’…) : un tel caractère
     // fait tomber le SMS de 160 à 70 caractères, et un texte de 120 en coûte deux.
     'corps' => "Bonjour {contact}, {boutique} : je passe vous présenter notre gamme {campagne}. Votre bon {bon} vous attend. A bientôt ! {tel_boutique}"],
    ['type' => 'sms', 'nom' => 'Relance courte',
     'sujet' => null,
     'corps' => "Bonjour {contact}, avez-vous pu déguster ? Le bon {bon} reste valable pour votre première commande chez {boutique}. {tel_boutique}"],
    ['type' => 'tel', 'nom' => 'Script — secrétariat / RH',
     'sujet' => 'Objectif : obtenir un créneau pour déposer un plateau de dégustation',
     'corps' => "1. Bonjour, {boutique}, pâtisserie artisanale à côté de chez vous. Je cherche la personne qui organise les pauses et les réunions chez {societe} — est-ce vous ?\n2. Nous livrons au bureau : viennoiseries le matin, plateaux pour les réunions, tartes pour les anniversaires.\n3. Pour {campagne}, vous avez un bon de découverte : {bon}.\n4. Je passe volontiers déposer un plateau de dégustation — mardi ou jeudi, lequel vous arrange ?\n5. À quelle adresse e-mail puis-je vous envoyer la carte ?"],
  ];
}

/* ── Pièces jointes : la bibliothèque de la boutique ───────────────────── */

function crm_piece_dir($shopId): string {
  return __DIR__ . '/../assets/crm_pieces/' . ($shopId ? (int) $shopId : 'reseau');
}

/* Types admis : ce qu'un bureau ouvre sans se poser de question. */
function crm_piece_types(): array {
  return ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
}

/* Contrôle d'un fichier reçu en data: URL. Rend [erreur] ou [null, mime, bin]. */
function crm_piece_lire($data): array {
  $data = (string) ($data ?? '');
  if (!preg_match('#^data:([a-z]+/[a-z0-9.+-]+);base64,([A-Za-z0-9+/=\s]+)$#i', $data, $m))
    return ['Fichier illisible : PDF, PNG, JPEG ou WebP attendu'];
  $mime = strtolower($m[1]);
  if ($mime === 'image/jpg') $mime = 'image/jpeg';
  if (!isset(crm_piece_types()[$mime])) return ['Type refusé : PDF, PNG, JPEG ou WebP seulement'];
  $bin = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
  if ($bin === false || strlen($bin) < 64) return ['Fichier vide ou illisible'];
  if (strlen($bin) > 5 * 1024 * 1024) return ['Fichier trop lourd (5 Mo maximum) — allégez-le avant de le déposer'];
  if ($mime === 'application/pdf') {
    if (strncmp($bin, '%PDF-', 5) !== 0) return ['Ce fichier n’est pas un PDF'];
  } else {
    $info = @getimagesizefromstring($bin);
    if (!$info || ($info['mime'] ?? '') !== $mime) return ['Image non reconnue'];
  }
  return [null, $mime, $bin];
}

/* Écrit le fichier sous un nom aléatoire (jamais le nom reçu : il vient du
   navigateur) et rend son chemin relatif servi, ou une erreur. */
function crm_piece_ecrire($shopId, $mime, $bin): array {
  $dir = crm_piece_dir($shopId);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  if (!is_dir($dir) || !is_writable($dir)) return [null, 'Dossier assets/crm_pieces non inscriptible sur le serveur'];
  // Un index vide : les noms sont aléatoires, le dossier ne doit pas se lister.
  if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
  $nom = bin2hex(random_bytes(12)) . '.' . crm_piece_types()[$mime];
  if (file_put_contents($dir . '/' . $nom, $bin) === false) return [null, 'Fichier non enregistré (écriture refusée)'];
  return ['assets/crm_pieces/' . ($shopId ? (int) $shopId : 'reseau') . '/' . $nom, null];
}

function crm_piece_supprimer_fichier($chemin): void {
  $chemin = (string) $chemin;
  if ($chemin === '' || strpos($chemin, '..') !== false || strpos($chemin, 'assets/crm_pieces/') !== 0) return;
  $f = __DIR__ . '/../' . $chemin;
  if (is_file($f)) @unlink($f);
}

/* ── Mail ──────────────────────────────────────────────────────────────── */

/* Le texte tapé devient un mail HTML sobre : logo si le serveur en a un,
   paragraphes, rien d'autre. La version texte est le message tel quel. */
function crm_mail_html($texte, ?array $boutique): string {
  $logo = (string) (cfg()['mail_logo_url'] ?? '');
  $nomB = mail_esc($boutique['name'] ?? '');
  $paras = preg_split("/\n{2,}/", trim(str_replace("\r", '', (string) $texte)));
  $corps = '';
  foreach ($paras as $p) {
    $p = trim($p);
    if ($p === '') continue;
    $corps .= '<p style="margin:0 0 14px;font:15px/1.55 Helvetica,Arial,sans-serif;color:#241c1a">' . nl2br(mail_esc($p)) . '</p>';
  }
  $tete = $logo !== ''
    ? '<img src="' . mail_esc($logo) . '" alt="' . $nomB . '" style="max-width:180px;height:auto;margin:0 0 22px;display:block">'
    : '<div style="font:700 18px Helvetica,Arial,sans-serif;color:#8d1d2c;margin:0 0 22px">' . $nomB . '</div>';
  return '<!DOCTYPE html><html><body style="margin:0;background:#f5f1ec;padding:28px 12px">'
       . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;padding:32px 34px">'
       . $tete . $corps
       . '</div></body></html>';
}

/* Envoi : multipart/mixed { multipart/alternative { texte, html }, pièces… }.
   $pieces = [['nom' => 'carte.pdf', 'mime' => 'application/pdf', 'bin' => …], …].
   Rend [ok, motif]. Un refus explique toujours pourquoi. */
function crm_mail_envoyer($to, $sujet, $texte, $html, array $pieces, $replyTo = null, $fromNom = null): array {
  $to = trim((string) $to);
  if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return [false, 'Adresse du destinataire absente ou invalide.'];
  $from = (string) (cfg()['mail_from'] ?? '');
  if ($from === '') return [false, 'Aucune adresse d’expédition configurée sur le serveur (mail_from).'];
  if (!function_exists('mail')) return [false, 'La fonction d’envoi d’e-mail est désactivée sur ce serveur.'];
  $sujet = trim((string) $sujet);
  if ($sujet === '') return [false, 'Objet du mail vide.'];
  if (trim((string) $texte) === '') return [false, 'Message vide.'];

  $bnd  = 'ab_' . bin2hex(random_bytes(8));
  $bndA = 'ac_' . bin2hex(random_bytes(8));
  $corps  = "--$bnd\r\nContent-Type: multipart/alternative; boundary=\"$bndA\"\r\n\r\n";
  $corps .= "--$bndA\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode((string) $texte)) . "\r\n";
  $corps .= "--$bndA\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode((string) $html)) . "\r\n";
  $corps .= "--$bndA--\r\n";
  foreach ($pieces as $pj) {
    $nom = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($pj['nom'] ?? 'piece'));
    $corps .= "--$bnd\r\nContent-Type: " . ($pj['mime'] ?? 'application/octet-stream') . "; name=\"$nom\"\r\n"
            . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$nom\"\r\n\r\n"
            . chunk_split(base64_encode((string) $pj['bin'])) . "\r\n";
  }
  $corps .= "--$bnd--\r\n";

  $fromH = $fromNom ? ('=?UTF-8?B?' . base64_encode((string) $fromNom) . "?= <$from>") : $from;
  $entetes = "From: $fromH\r\n";
  if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $entetes .= "Reply-To: $replyTo\r\n";
  $entetes .= "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$bnd\"\r\n";
  $ok = @mail($to, '=?UTF-8?B?' . base64_encode($sujet) . '?=', $corps, $entetes);
  if (!$ok) return [false, 'Le serveur d’envoi a refusé le message — vérifiez la configuration mail de l’hébergement.'];
  return [true, null];
}

/* ── Téléphone ─────────────────────────────────────────────────────────── */

/* Un numéro de carte tel que tapé (« 0476 31 69 72 », « +32 476… ») → E.164.
   Belge par défaut, comme partout ici. '' si rien d'exploitable. */
function crm_tel_e164($brut): string {
  $t = trim((string) $brut);
  if ($t === '') return '';
  if ($t[0] === '+') $t = preg_replace('/\(0\)/', '', $t);   // +32 (0)476… → +32 476…
  $d = preg_replace('/\D+/', '', $t);
  if ($t[0] === '+') return strlen($d) >= 8 ? '+' . $d : '';
  if (preg_match('/^00\d{8,}$/', $d)) return '+' . substr($d, 2);
  [, , $e164] = norm_phone('+32', $t);
  return (string) $e164;
}
