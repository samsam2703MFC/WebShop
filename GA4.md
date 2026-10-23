# Google Analytics 4 et consentement sur le webshop

## Ce qui est en place

- **Consentement (Consent Mode v2).** `index.html` déclare tout refusé **avant** le conteneur Google Tag
  Manager (`GTM-53D3FLG5`). Un bandeau (`webshop-consent.jsx`) propose *Tout refuser*, *Choisir*
  (mesure d'audience, publicité) et *Tout accepter* — trois boutons de même poids. Le choix est gardé
  dans le navigateur (`ws.consent`) et rejoué au chargement suivant ; il se rouvre par **Cookies**
  dans le menu des langues, ou par `?cookies=1`. Textes FR/NL : migration `0133_i18n_consentement.sql`.
- **Google Analytics 4.** Chargé par le webshop lui-même, **seulement après un oui à la mesure
  d'audience**, et seulement si `ws_param.ga4_measurement_id` porte un identifiant `G-…` (servi par
  `GET /config` sous `ga4MeasurementId`). Sans identifiant, rien ne change.
- **Événements e-commerce** (vocabulaire GA4, monnaie EUR) :

  | Événement | Quand | Contenu |
  |---|---|---|
  | `view_item` | ouverture d'une fiche produit | l'article |
  | `add_to_cart` | ajout au panier (simple ou configuré) | la ligne ajoutée |
  | `begin_checkout` | « Commander » | les lignes du panier, le total |
  | `purchase` | commande enregistrée (paiement en boutique, sur compte, différé) ou retour Stripe payé | n° de commande, total, boutique (`affiliation`), bon (`coupon`), articles |

  Chaque événement part dans `dataLayer` pour le conteneur — `purchase` garde ses champs à plat
  (`transaction_id`, `value`, `currency`, `payment`) que la conversion Google Ads lit déjà — et, si
  GA4 est chargé, vers la propriété GA4 seulement (`send_to`).

## Créer la propriété (5 minutes)

1. <https://analytics.google.com> → **Administration** → **Créer** → **Propriété** :
   nom « L'Atelier By — Webshop », fuseau **Belgique (Bruxelles)**, devise **euro**.
2. **Flux de données** → **Web** : adresse du webshop (aujourd'hui
   `http://185.180.206.46/webshop`), nom « Webshop ». Laisser les *mesures améliorées* actives.
3. Copier l'**ID de mesure** (`G-XXXXXXXXXX`).
4. Le poser dans le paramètre de marque **`ga4_measurement_id`** (back-office franchiseur →
   paramètres, route `POST /franchisor/param`). Pas de redéploiement : le webshop le lit au
   chargement suivant.
5. Dans GA4 : **Administration → Associations de produits → Google Ads** → associer le compte
   `AW-18385863943` (conversions et audiences partagées), et marquer `purchase` comme événement clé.

## À dire à l'agence

- **Ne pas ajouter de balise GA4 dans le conteneur GTM** : le webshop la charge déjà, une seconde
  balise compterait tout en double.
- La conversion Google Ads (`AW-18385863943`) reste dans le conteneur et lit les mêmes champs.
  Elle respecte maintenant le consentement : sans oui à la publicité, Google ne dépose plus de
  cookie et **modélise** les conversions au lieu de les observer. Le nombre de conversions
  observées baissera ; c'est l'effet attendu du consentement, pas une panne.

## Ensuite

Quand la connexion Google du module Social sera complète (`docs/GOOGLE.md` du dépôt social), le
Marketing pourra lire dans GA4 les commandes et le chiffre d'affaires du webshop par
`utm_campaign` — les liens de chaque campagne portent déjà ce code — et les montrer dans
« Récap & résultats » de l'assistant de campagne.
