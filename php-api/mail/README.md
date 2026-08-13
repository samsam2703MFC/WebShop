# Courriers de l'onboarding bureau

## `bienvenue-bureau.html`

Le courrier envoyé au **contact du bureau** dès que le franchisé a validé
l'onboarding. C'est le document de référence du bureau : conditions
commerciales, engagement de livraison, départements, vouchers — et le
**bouton d'invitation** que le contact transfère à tout son personnel.

HTML d'e-mail : tableaux imbriqués, styles inline, 600 px, aucun script ni
police externe. Testé pour Outlook (Word), Gmail, Apple Mail.

### Le bouton

```html
<a href="{{ invite_url }}">Créer mon compte</a>
```

`invite_url` vient de la réponse de `POST /franchisee/onboard-office`
(`invite_link()`), et pointe sur `<racine>/inscription?i=<jeton>`.

**Un seul paramètre, signé.** Ne remettez jamais `shop`, `office`, `client`
en clair dans cette URL : lisibles, ils sont modifiables, et ils décident de
qui facture. Le lien est **multi-usage** — c'est une invitation d'entreprise,
la secrétaire la transfère à quarante personnes — et il **n'ouvre rien** :
chaque compte créé entre en `pending` dans `ws_office_join_requests`.

### Variables

| Groupe | Variables |
| --- | --- |
| Société | `raison` `code_client` `tva` `segment` `paiement` `plafond` `franco` `remise_web` `facturation` |
| Livraison | `site_adresse` `site_cp` `site_localite` `site_office` `site_etage` `jours` `fenetre` `validation` `tournee` |
| Invitation | `invite_url` `invite_expire_le` `invite_domaine` |
| Boucles | `vouchers[]` (`voucher_titre` `voucher_code` `voucher_valeur` `voucher_validite`) · `departements[]` (`dept` `effectif`) · `staff[]` (`nom` `email`) |
| Boutique | `boutique_nom` `boutique_adresse` `boutique_tel` `url_webshop` `url_aide` `url_desabonnement` `contact_nom` |

Les blocs `<!-- IF x -->…<!-- ENDIF -->` et `<!-- FOR x -->…<!-- ENDFOR -->`
sont des marqueurs pour le moteur de rendu, pas une syntaxe existante.

**Règle** : une variable non fournie n'affiche rien et son bloc disparaît.
Aucun montant, aucun horaire, aucun code par défaut — un chiffre affiché dans
ce courrier est un engagement de la boutique.

### État

Le gabarit est prêt ; **aucun envoyeur ne l'utilise encore**. `POST
/franchisee/onboard-office` crée le bureau et émet le lien, mais n'envoie pas
de courrier. Reste à écrire : le rendu (IF/FOR + substitution) et l'envoi au
contact, avec la version PDF (bouton → QR code de `invite_url`).

Note : les vouchers sont insérés dans `ws_vouchers` avec leur **code** seul —
leur libellé et leur valeur voyagent dans le corps de l'onboarding et doivent
être passés au gabarit par l'envoyeur, pas relus en base.
