<?php
/* Tests unitaires — rendu des gabarits d'e-mail (mail_render).
 *
 *   php php-api/tests/mail_render_test.php
 *
 * Le danger de ce courrier n'est pas qu'il soit laid : c'est qu'il affiche un
 * chiffre que personne n'a saisi. Un franco, un plafond, un code de bon lus
 * par le client sont des engagements de la boutique. Les tests portent donc
 * d'abord sur ce qui DOIT disparaître.
 *
 * Isolé : aucune base, aucun envoi. cfg() est bouchonné, seules les fonctions
 * de rendu de lib.php sont chargées.
 */

function cfg() { return ['mail_from' => 'no-reply@example.be', 'webshop_base' => 'https://exemple.be/ws']; }

$src = file_get_contents(__DIR__ . '/../lib.php');
$a = strpos($src, 'function mail_esc');
$b = strpos($src, '/* Back-offices Franchise Buddy');
if ($a === false || $b === false) { fwrite(STDERR, "lib.php : bloc de rendu introuvable\n"); exit(2); }
eval(substr($src, $a, $b - $a));

$T = 0; $F = 0;
function check(string $label, bool $ok) {
  global $T, $F; $T++;
  echo ($ok ? "  ✓ " : "  ✗ ") . $label . "\n";
  if (!$ok) $F++;
}

echo "\n── substitution ──\n";
check('la variable est remplacée',
  mail_render('Bonjour {{ nom }}.', ['nom' => 'Marie']) === 'Bonjour Marie.');
check('les espaces autour du nom sont tolérés',
  mail_render('{{nom}}/{{  nom  }}', ['nom' => 'x']) === 'x/x');
check('une variable inconnue s’efface, elle ne s’affiche pas',
  mail_render('a{{ inexistante }}b', []) === 'ab');

echo "\n── échappement ──\n";
check('le HTML d’une raison sociale est neutralisé',
  mail_render('{{ r }}', ['r' => 'Dupont & Fils <b>']) === 'Dupont &amp; Fils &lt;b&gt;');
check('les guillemets aussi — la valeur passe dans des attributs',
  strpos(mail_render('<a href="{{ u }}">', ['u' => '" onclick="x']), '&quot;') !== false);

echo "\n── conditions ──\n";
$tpl = 'A<!-- IF v -->B{{ v }}<!-- ENDIF -->C';
check('bloc gardé quand la valeur existe', mail_render($tpl, ['v' => '5 %']) === 'AB5 %C');
check('bloc retiré quand la valeur est vide', mail_render($tpl, ['v' => '']) === 'AC');
check('bloc retiré quand la valeur est absente', mail_render($tpl, []) === 'AC');
check('« 0 » n’est pas vide — un franco à 0 € reste un engagement',
  mail_render($tpl, ['v' => '0']) === 'AB0C');
check('une liste vide referme son bloc',
  mail_render('X<!-- IF l -->Y<!-- ENDIF -->Z', ['l' => []]) === 'XZ');
check('deux conditions voisines ne se mélangent pas',
  mail_render('<!-- IF a -->A<!-- ENDIF --><!-- IF b -->B<!-- ENDIF -->',
              ['a' => '', 'b' => 'oui']) === 'B');

echo "\n── boucles ──\n";
$loop = '<!-- FOR vouchers -->[{{ voucher_code }}]<!-- ENDFOR -->';
check('une carte par élément',
  mail_render($loop, ['vouchers' => [['voucher_code' => 'A1'], ['voucher_code' => 'B2']]]) === '[A1][B2]');
check('liste vide ⇒ rien', mail_render($loop, ['vouchers' => []]) === '');
check('liste absente ⇒ rien', mail_render($loop, []) === '');
check('une condition à l’intérieur de la boucle suit chaque élément',
  mail_render('<!-- FOR v -->{{ c }}<!-- IF d -->({{ d }})<!-- ENDIF -->;<!-- ENDFOR -->',
              ['v' => [['c' => 'A', 'd' => '60 j'], ['c' => 'B', 'd' => '']]]) === 'A(60 j);B;');
check('l’élément voit aussi les variables globales',
  mail_render('<!-- FOR v -->{{ c }}@{{ g }}<!-- ENDFOR -->',
              ['g' => 'G', 'v' => [['c' => 'A']]]) === 'A@G');

echo "\n── le gabarit réel ──\n";
$f = __DIR__ . '/../mail/bienvenue-bureau.html';
if (!is_file($f)) { check('gabarit présent', false); }
else {
  $tplReel = file_get_contents($f);
  // Le pire cas : un onboarding minimal. Rien ne doit s'inventer.
  $out = mail_render($tplReel, ['raison' => 'Sopra Steria', 'contact_nom' => 'Marie']);
  check('aucun marqueur {{ }} ne survit', !preg_match('/\{\{/', $out));
  check('aucun IF/FOR non résolu', strpos($out, '<!-- IF ') === false && strpos($out, '<!-- FOR ') === false);
  check('aucun voucher inventé', stripos($out, 'voucher de bienvenue') === false);
  check('aucun bouton d’invitation sans lien', strpos($out, 'href=""') === false);
  check('la raison sociale est là', strpos($out, 'Sopra Steria') !== false);
  // Ce que le cas maigre ne doit PAS laisser traîner : des séparateurs, des
  // phrases à trou, un cadre d'image cassé. Un courrier bancal fait douter du
  // reste — y compris des conditions, qui, elles, sont exactes.
  check('pas de tiret orphelin après la raison sociale',
    strpos($out, 'Sopra Steria &mdash;') === false && strpos($out, 'Sopra Steria —') === false);
  check('pas de phrase de franco à trou', strpos($out, 'franco de port ,') === false);
  check('pas de point suspendu au pied',
    strpos($out, 'compte de livraison .') === false);
  check('aucune image sans source', strpos($out, 'src=""') === false);

  $plein = mail_render($tplReel, [
    'raison' => 'Sopra Steria', 'contact_nom' => 'Marie', 'code_client' => 'CL-1', 'franco' => '250 €',
    'invite_url' => 'https://exemple.be/inscription?i=JETON', 'invite_expire_le' => '30/09/2026',
    'invite_domaine' => 'soprasteria.be',
    'vouchers' => [['voucher_titre' => 'Bienvenue', 'voucher_code' => 'BW1', 'voucher_valeur' => '−10 %', 'voucher_validite' => '60 j'],
                   ['voucher_titre' => 'Petit-déj', 'voucher_code' => 'PDJ', 'voucher_valeur' => 'Une viennoiserie', 'voucher_validite' => '']],
    'departements' => [['dept' => 'Finance', 'effectif' => '11']],
    'staff' => [['nom' => 'Marie Dupont', 'email' => 'marie@soprasteria.be']],
  ]);
  check('le bouton porte le lien signé',
    strpos($plein, 'href="https://exemple.be/inscription?i=JETON"') !== false);
  check('les deux vouchers sont rendus',
    strpos($plein, 'BW1') !== false && strpos($plein, 'PDJ') !== false);
  check('un voucher sans validité n’affiche pas de ligne vide',
    substr_count($plein, '60 j') === 1);
  check('l’échéance du lien est dite', strpos($plein, '30/09/2026') !== false);
  check('le domaine imposé est dit', strpos($plein, 'soprasteria.be') !== false);
  check('le département est rendu', strpos($plein, 'Finance') !== false);
  check('aucun marqueur ne survit non plus en cas plein', !preg_match('/\{\{|<!-- (IF|FOR) /', $plein));
}

echo "\n── le courrier tel que le serveur le construit ──\n";
/* invite_mail_html() est le seul chemin d'envoi : ce qu'il rend est ce que le
   client lit. On le teste avec un récapitulatif comme invite_recap() en
   produit, valeurs manquantes comprises. */
require_once __DIR__ . '/../invite_doc.php';
$recapMinimal = ['raison' => 'Asima sp z oo', 'contact' => 'Jan', 'email' => 'jan@asima.be',
                 'boutique' => "L'Atelier By Anderlecht", 'vouchers' => []];
$h = invite_mail_html($recapMinimal, 'https://exemple.be/inscription?i=JET', '30/09/2026');
check('le gabarit est trouvé et rendu', is_string($h) && $h !== '');
check('aucun marqueur ne survit', is_string($h) && !preg_match('/\{\{|<!-- (IF|FOR) /', $h));
check('le bouton porte le lien', is_string($h) && strpos($h, 'https://exemple.be/inscription?i=JET') !== false);
check('aucune condition inventée', is_string($h) && stripos($h, 'franco de port ,') === false);
// La carte d'un bon a un FOND abricot (l'abricot sert aussi à numéroter les
// étapes : c'est bien « background-color » qu'il faut chercher).
check('aucune carte de bon quand la liste est vide',
  is_string($h) && strpos($h, 'background-color:#C87A3F') === false);
// Les commentaires du gabarit expliquent le fichier, pas le message : ils ne
// doivent pas partir chez le client, qui peut afficher la source.
check('aucun commentaire de gabarit dans le message',
  is_string($h) && !preg_match('/<!--(?!\[if)/', $h));
check('les commentaires conditionnels Outlook survivent',
  is_string($h) && strpos($h, '<!--[if') !== false && strpos($h, '<![endif]') !== false);

$recapPlein = $recapMinimal + ['tva' => 'BE 0418.467.437', 'adresse' => 'Zoning Sud, 1300 Wavre',
  'etage' => '2e', 'tournee' => 'Wavre & LLN SUD', 'jours' => 'Mar · Jeu', 'horaire' => '09:00–10:30',
  'cutoff' => '17:00 la veille', 'frais' => '6 €', 'franco' => '150 €', 'remise' => '8 %',
  'paiement' => 'Facturation différée'];
// Bons NOMMÉS, comme invite_recap les produit à la création.
$recapPlein['vouchers'] = [['code' => 'BW1', 'titre' => 'Voucher de bienvenue'],
                           ['code' => 'PDJ2026', 'titre' => 'Découverte petit-déjeuner']];
$h2 = invite_mail_html($recapPlein, 'https://exemple.be/inscription?i=JET', '30/09/2026');
check('les deux bons sont des cartes distinctes',
  substr_count($h2, 'BW1') === 1 && substr_count($h2, 'PDJ2026') === 1);
check('l’heure limite de commande n’est pas perdue', strpos($h2, '17:00 la veille') !== false);
check('les frais de livraison non plus', strpos($h2, '6 €') !== false);
check('la fenêtre de livraison est là', strpos($h2, '09:00–10:30') !== false);
check('aucun marqueur ne survit non plus en cas plein', !preg_match('/\{\{|<!-- (IF|FOR) /', $h2));
check('le libellé saisi est le titre de la carte',
  strpos($h2, 'Découverte petit-déjeuner') !== false);
// Un renvoi ultérieur ne dispose que des codes (ws_vouchers ne stocke pas les
// libellés) : la carte se rend quand même, sans titre inventé.
$h3 = invite_mail_html(array_merge($recapPlein, ['vouchers' => ['BW1']]), 'https://exemple.be/i', null);
check('un bon sans libellé se rend sans titre inventé',
  strpos($h3, 'BW1') !== false && stripos($h3, 'Bon de bienvenue') === false);

echo "\n" . ($F ? "$F échec(s) sur $T" : "tout est vert ($T contrôles)") . "\n";
exit($F ? 1 : 0);
