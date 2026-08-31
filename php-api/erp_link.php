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

/* ── LA CORRESPONDANCE client local ↔ fiche ERP ──────────────────────────────
 * Neuf tables portent un identifiant client local (commandes, bureaux, sites,
 * réservations, incidents, bons, handoff, demandes). Le jour où `client`
 * disparaîtra au profit des endpoints, ces identifiants ne désigneront plus
 * rien : il leur faut une clé ERP.
 *
 * Elle vit dans ws_client_erp_map (migration 0103) et NON dans une colonne par
 * table : neuf copies du même fait, ce sont neuf endroits à tenir synchronisés.
 * Et elle ne peut pas vivre dans `client` non plus — c'est justement la table
 * qu'on veut supprimer, elle emporterait la correspondance avec elle.
 *
 * `client.erp_client_id` reste écrit en parallèle tant que la table existe :
 * les deux disent la même chose, mais la map est celle qui survivra. Le jour de
 * la bascule, on lit la map et on ne cherche plus ailleurs. ── */

/* Enregistre (ou met à jour) le lien. `origine` dit COMMENT il a été établi —
 * un lien supposé et un lien arbitré par le franchisé ne se valent pas, et
 * après coup rien ne les distinguerait. */
function erp_map_poser($clientId, $erpId, $origine = 'reprise') {
  $cid = (int) $clientId; $eid = (int) $erpId;
  if ($cid <= 0 || $eid <= 0) return false;
  if (!function_exists('q')) return false;
  try {
    q("INSERT INTO ws_client_erp_map (client_id, erp_client_id, origine)
       VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE erp_client_id = VALUES(erp_client_id),
                               origine = VALUES(origine)",
      [$cid, $eid, substr((string) $origine, 0, 16)]);
    return true;
  } catch (Throwable $e) {
    /* La map est une PRÉPARATION, pas un chemin critique : son échec ne doit
       jamais faire échouer l'inscription ou la décision qui l'a déclenchée.
       Il se journalise, il ne remonte pas. */
    error_log('[ws] map client↔ERP : ' . $e->getMessage());
    return false;
  }
}

/* L'identifiant ERP d'un client local, ou null. Source unique de la résolution :
 * tout appelant passe par ici, personne ne rejoint la table à la main. */
function erp_map_id($clientId) {
  $cid = (int) $clientId;
  if ($cid <= 0 || !function_exists('row')) return null;
  try {
    $r = row("SELECT erp_client_id FROM ws_client_erp_map WHERE client_id = ?", [$cid]);
    return $r ? (int) $r['erp_client_id'] : null;
  } catch (Throwable $e) { return null; }
}

/* État de la préparation, pour /erp/probe : combien de liens connus, et quelle
 * proportion des commandes porte déjà sa fiche gelée. Un chantier de bascule
 * qu'on ne peut pas mesurer est un chantier dont on ignore l'avancement. */
function erp_map_etat() {
  if (!function_exists('row')) return null;
  $n = function ($sql) { try { $r = row($sql); return $r ? (int) array_values($r)[0] : 0; }
                         catch (Throwable $e) { return null; } };
  return [
    'liens'              => $n("SELECT COUNT(*) FROM ws_client_erp_map"),
    'commandes_liees'    => $n("SELECT COUNT(*) FROM ws_orders WHERE customer_erp_id IS NOT NULL"),
    'commandes_a_lier'   => $n("SELECT COUNT(*) FROM ws_orders WHERE customer_id IS NOT NULL AND customer_erp_id IS NULL"),
  ];
}
