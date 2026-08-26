<?php
/* ── Rattacher un compte webshop à une fiche client de la boutique ───────────
   Un client qui achète au comptoir existe côté ERP. En créant un compte
   webshop il se retrouve avec DEUX fiches : la sienne, historique, et le
   compte tout neuf. Les relier lui rend son historique d'achats et sa
   fidélité.

   POURQUOI CE N'EST PAS AUTOMATIQUE. Le webshop n'a aucune vérification
   d'identité — ni OTP SMS ni e-mail (le code le signale déjà pour
   /auth/set-password). Saisir un numéro de téléphone ne prouve pas qu'on le
   possède. Rattacher sur la seule foi du numéro donnerait l'historique d'achats
   de quelqu'un à qui connaît son numéro. Le franchisé arbitre donc chaque
   demande : il connaît ses clients, c'est la seule vérification disponible.

   CE MODULE NE DÉCIDE RIEN. Il compare les deux fiches et rend des
   concordances lisibles. C'est le franchisé qui tranche — et le score n'est
   jamais un feu vert : une concordance parfaite reste une demande à valider. ── */

/* Les champs comparables, dans l'ordre où ils comptent pour reconnaître
   quelqu'un. Le téléphone est la clé de la recherche : il concorde par
   construction, on l'affiche quand même parce que le franchisé doit voir sur
   quoi la reconnaissance repose. */
function erp_link_comparer(array $compte, array $fiche) {
  $norm = function ($s) {
    $s = mb_strtolower(trim((string) $s), 'UTF-8');
    // Accents repliés : « Vanhaever » et « Vanhaëver » sont la même personne.
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    return preg_replace('/[^a-z0-9]/', '', $s);
  };
  $tel = function ($s) {
    $d = preg_replace('/\D/', '', (string) $s);
    return strlen($d) >= 9 ? substr($d, -9) : $d;   // même clé que erp_tel_cle()
  };

  $champs = [
    ['cle' => 'tel',   'label' => 'Téléphone',    'a' => $tel($compte['tel'] ?? ''),   'b' => $tel($fiche['tel'] ?? '')],
    ['cle' => 'cp',    'label' => 'Code postal',  'a' => $norm($compte['cp'] ?? ''),   'b' => $norm($fiche['cp'] ?? '')],
    ['cle' => 'nom',   'label' => 'Nom',          'a' => $norm($compte['nom'] ?? ''),  'b' => $norm($fiche['nom'] ?? '')],
    ['cle' => 'prenom', 'label' => 'Prénom',      'a' => $norm($compte['prenom'] ?? ''), 'b' => $norm($fiche['prenom'] ?? '')],
    ['cle' => 'email', 'label' => 'E-mail',       'a' => $norm($compte['email'] ?? ''), 'b' => $norm($fiche['email'] ?? '')],
  ];

  $out = []; $ok = 0; $ko = 0;
  foreach ($champs as $c) {
    /* Un champ vide d'un côté n'est ni une concordance ni une divergence :
       574 fiches sur 8156 seulement portent un e-mail. Compter l'absence
       comme un désaccord ferait échouer presque toutes les demandes ; la
       compter comme un accord serait mensonger. On l'affiche « absent ». */
    $etat = ($c['a'] === '' || $c['b'] === '') ? 'absent'
          : ($c['a'] === $c['b'] ? 'concorde' : 'diverge');
    if ($etat === 'concorde') $ok++;
    if ($etat === 'diverge')  $ko++;
    $out[] = ['cle' => $c['cle'], 'label' => $c['label'], 'etat' => $etat];
  }
  return ['champs' => $out, 'concordent' => $ok, 'divergent' => $ko];
}

/* La fiche ERP que ce compte pourrait rattacher, ou null.
   Recherche par TÉLÉPHONE uniquement : c'est la seule clé qui couvre le parc
   (8125 fiches sur 8156). Le compte fournit le numéro — jamais l'appelant :
   un id de fiche reçu du navigateur laisserait choisir sa cible. */
function erp_link_candidate(array $compte) {
  if (!function_exists('erp_client_par_tel')) return null;
  $tel = trim((string) ($compte['tel'] ?? ''));
  if ($tel === '') return null;
  try { $f = erp_client_par_tel($compte['shopId'] ?? null, $tel); }
  catch (Throwable $e) { return null; }
  if (!is_array($f) || empty($f['id'])) return null;
  // Fiche bloquée ou désactivée côté ERP : rien à rattacher.
  if (empty($f['actif']) || !empty($f['bloque'])) return null;
  return $f;
}

/* Ce que le DEMANDEUR a le droit de voir de la fiche visée.
   Volontairement pauvre : prénom, et des corroborations qui ne révèlent rien
   à qui ne les connaît pas déjà (« votre code postal concorde »). Renvoyer la
   fiche ferait du formulaire d'inscription un outil de consultation du fichier
   client — il suffirait d'essayer des numéros. */
function erp_link_vue_client(array $fiche, array $cmp) {
  $sur = [];
  foreach ($cmp['champs'] as $c) if ($c['etat'] === 'concorde') $sur[] = $c['label'];
  return [
    'prenom'      => (string) ($fiche['prenom'] ?? ''),
    'concordances' => $sur,
  ];
}
