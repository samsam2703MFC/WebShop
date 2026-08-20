# Audit — ce qui passe par l'API ERP, ce qui lit la base en direct

> Audit en LECTURE SEULE du dépôt `webshop`, réalisé le 20/08/2026.
> Sujet : pour chaque donnée que sert `php-api/`, d'où vient-elle — l'API
> Franchise Buddy (`{base}/api/v1/…`), une lecture SQL directe des tables ERP
> (même base MySQL `atelierby_db`), ou les tables propres au webshop (`ws_*`,
> `pwa_*`, `bo_*`) ? Chaque affirmation cite `fichier:ligne`. Les observations
> « jeton en main » ont été faites ce jour sur `atelierby.tfbuddy.com`
> (compte consultant, boutique 2).

---

## 1. Vue d'ensemble

| Source | Où | Poids |
|---|---|---|
| **API ERP** (`erp_get`, Bearer) | traductions produits/catégories ; catalogue+prix de portion (écrit mais **non branché**) | 2 usages actifs, 2 en attente |
| **SQL direct sur tables ERP** | allergènes, portions+prix, saisons, bons/promotions, clients, boutiques, départements B2B | 9 familles de tables |
| **Tables propres `ws_*`/`pwa_*`/`bo_*`** | tout le reste : catalogue servi, commandes, i18n, tournées, offices… | ~70 tables |
| **API tierces** | Stripe, Google (Geocode/Places/Business Profile), Nominatim, VIES, Anthropic | 7 intégrations |

Le front (JSX) ne parle **qu'à l'API PHP du webshop** — jamais à l'ERP ni à la
base : la frontière API/DB se joue entièrement dans `php-api/`.

---

## 2. Ce qui fonctionne PAR L'API ERP aujourd'hui

Transport commun : `erp_get()` (`php-api/erp_alias.php:65`) — `Authorization:
Bearer`, cache disque TTL 300 s, échec → `null` + journal `erp_notes()`
(`erp_alias.php:58`). Configuration en base, sans redéploiement :
`ws_param.erp_api_base` / `erp_api_token` (`erp_alias.php:24-45`).

| Donnée | Endpoint ERP | Consommé par | Branché ? |
|---|---|---|---|
| Noms de produits traduits | `GET products/aliases?lang_code=…` | `erp_product_labels` (`erp_alias.php:175`) → surcharge du catalogue (`index.php:493-503`) | ✅ oui |
| Libellés de catégories traduits | `GET product-categories/aliases` + `product-category-groups/aliases` | `erp_category_labels` (`erp_alias.php:191`) → `/catalog/categories` (`index.php:769-782`) | ✅ oui |
| Diagnostic de la liaison | les deux ci-dessus | `GET /erp/probe` (`index.php:620-641`) : configurée ? jointe ? combien de libellés ? | ✅ oui |
| Assortiment d'une boutique | `GET shops/{id}/products/available?lang_code=…` | `erp_catalog_shop` (`erp_catalog.php:117`) | ⚠️ **écrit, testé, NON branché** |
| Prix de portion par boutique | `GET shops/{s}/products/{p}/portions/prices` | `erp_portion_prices` (`erp_catalog.php:192`) | ⚠️ **écrit, testé, NON branché** |

**Non branché veut dire : `erp_catalog.php` n'est même pas `require` par
`index.php`** (`index.php:4-6` ne charge que `lib.php`, `promo_lib.php`,
`erp_alias.php`). C'est voulu — l'activation attend `ws_param.catalog_source =
'erp'` (`erp_catalog.php:239`) et le branchement dans
`catalog_produits_servis` ; observé jeton en main : la table de prix ERP est
encore vide (`has_shop_price: false` sur toutes les portions sondées de la
boutique 2), donc basculer aujourd'hui ne vendrait rien.

Vérifié jeton en main ce jour : `products/aliases` rend 762 lignes (609 alias
NL), `product-categories/aliases` 81 lignes (75 alias NL), forme
`fk_id`/`alias_value` (reconnue depuis `erp_alias.php:120-133`).

---

## 3. Ce qui fonctionne en LECTURE (ou écriture) DB DIRECTE sur les tables ERP

Toutes dans la même base MySQL que les tables `ws_*` (« même base
atelierby_db », `index.php:408`). Classées par existence d'un équivalent API.

### 3.a Un équivalent API EXISTE — migrable si on le décide

| Donnée | Tables lues | Code | Équivalent API (vérifié) |
|---|---|---|---|
| Portions candidates + prix par boutique | `product_portion` × `shop_product_portion_price` | `erp_portion_options` (`index.php:126-166`), injecté au catalogue (`index.php:393-407`) | `products/{id}/portions` (200, 3 lignes) + `shops/{s}/products/{p}/portions/prices` (200) — mais **un appel par produit**, donc seul le SQL tient en liste |
| Vue « règles de portions » des BO | idem | franchisor `index.php:4314-4335` ; franchisé `index.php:8524-8553` | idem — écran de consultation, la volumétrie d'un appel par produit y est acceptable |
| Allergènes réels (recette → matières → allergènes) | `product`, `flattened_recipe_ingredient`, `material_allergen_connection`, `allergen` | `index.php:419-448` | `shops/{id}/products/available` porte `grouped_allergens` déjà traduit — mais PAS la distinction « évaluée-sans-allergène / non renseignée » que le SQL calcule (`index.php:409-417`), sémantique de sécurité alimentaire à ne pas perdre |
| Boutiques | `shops` | `/shops` `index.php:565`, `/shops/{id}` `index.php:585`, +6 lectures ponctuelles | `GET /shops` ERP existe (ids identiques, vérifié) — mais les colonnes webshop (`webshop_enabled`, `default_lang`, `languages`, `landing_config`) n'existent QUE dans la base : l'API ERP ne peut pas remplacer ces lectures |

### 3.b AUCUN équivalent API connu — le SQL direct est le seul chemin

| Donnée | Tables | Code | Remarque |
|---|---|---|---|
| Gammes saisonnières | `product_availability_period(_connection)` | `availability_where` (`index.php:10357-10385`) | filtre sur la date de retrait ; rien côté API |
| Comptes clients | `client` | lectures `index.php:665-669, 1828` ; **écritures** `index.php:1973, 3032, 3240` | le webshop CRÉE des clients directement dans la table ERP ; seul `consultant/auth/login` existe côté API — rien pour les clients finaux |
| Bons & promotions (modèle unifié) | `promotion`, `voucher_campaign(_channel)`, `voucher_code`, `voucher_redemption` | upsert `index.php:223-271` ; lecture `/vouchers/available` `index.php:1215+` ; rachat à la commande `index.php:2918-2932` | écritures directes dans le modèle ERP, canal `'WS'` ; aucune API bons connue |
| Garde-fou FK du rachat | `franchisee_shop` | `index.php:2924` | simple test d'existence avant `voucher_redemption` |
| Départements B2B | `b2b_client_company_department` | `index.php:6016+` | « table ERP si synchronisée » — servie vide sinon |
| Stats B2B (cron) | `client_order` | `php-api/cron/clientb2b-top5-active.sql:18` | SQL de cron, hors API PHP |

### 3.c Le réplica `ws_` : DB par construction

Le catalogue servi au client ne lit NI l'API NI les tables ERP : il lit le
réplica du webshop — `ws_products` + `ws_categories` + co.
(`catalog_produits_servis`, `index.php:295-379`), prix unique
`ws_products.price` (`prix_produits`, `index.php:79-116`). Les ids sont ceux
de l'ERP (« ws_products.id = product.id », `index.php:409-410` ; nom de
catégorie « vient de l'ERP », `index.php:4522-4523`), ce qui rend les
surcharges d'alias (§2) et les jointures (§3.a) cohérentes. **L'alimentation
du réplica est extérieure à ce dépôt** — aucun code d'import ici ; la console
marque n'écrit que des bascules/prix (`/franchisor/product`,
`index.php:4468-4517`).

Tout le reste — commandes (`ws_orders`/`ws_order_lines`, `index.php:2815`),
i18n (`ws_i18n`, `/i18n` `index.php:593`), offices/tournées/stock/creneaux/
cross-sell (~70 tables `ws_*`, `pwa_*`, `bo_*`) — est propriété du webshop :
la question API/DB ne s'y pose pas, il n'existe pas d'autre détenteur.

---

## 4. Autres API externes (pour un inventaire complet)

| Service | Usage | Code |
|---|---|---|
| Stripe | checkout (`stripe_checkout`, `index.php:11733`) + webhook seule source de vérité d'encaissement (`index.php:3620`) | cURL, sans SDK |
| Google Geocode | géocodage des sites à la saisie | `index.php:10242-10262` |
| Google Places | fiche/avis boutique | `index.php:8155-8160` |
| Google Business Profile | avis (console marque) | `gbp_http`, `index.php:11198-11205` |
| Nominatim (OSM) | repli de géocodage | `index.php:10902-10925` |
| VIES | validation TVA intracommunautaire | `index.php:5605-5606` |
| Anthropic | brouillons de réponse aux avis | `index.php:8229-8240` |

---

## 5. Écarts et risques

**P0 — `/shops/{id}/products/available` est public en production.** Vérifié
sans jeton : 200, 567 produits, avec `recipe_cost_gross`, `recipe_cost_net`,
`expected_margin`. C'est le seul endpoint dont dépend la future bascule
catalogue (`erp_catalog.php:117`) — et il fuit les coûts de revient et marges
du réseau à quiconque devine l'URL. Conséquence nommée : un concurrent ou un
franchisé lit la structure de marge de toutes les boutiques. À signaler à
Franchise Buddy — côté webshop, rien à corriger, nous n'exposons pas ces
champs (`erp_cat_map_product` ne les mappe pas, `erp_catalog.php:49-113`).

**P1 — identifiants locaux dans l'espace d'ids ERP.** `INSERT INTO
ws_products` sans id (`index.php:4938`, `index.php:9864`) laisse
`AUTO_INCREMENT` choisir — dans une table dont les ids sont par ailleurs ceux
de l'ERP (`index.php:409-410`). Un futur produit ERP peut retomber sur un id
déjà pris par un menu créé à l'écran : l'import écraserait ou échouerait.
Conséquence nommée : collision silencieuse entre un produit réseau et un
porteur de menu local.

**P1 — pas de lecture de prix en LOT côté ERP.** `portions/prices` prend un
produit à la fois et `/price` (pièce entière) répond 404 (vérifié ce jour).
Tant que `GET /shops/{id}/products/prices` n'existe pas, la bascule
`catalog_source='erp'` ne peut couvrir que l'assortiment et les noms — le prix
restera `ws_products.price` (`index.php:101`). Documenté dans
`MENU_BUILDER_API.md` §7.

**P2 — deux écritures webshop dans des tables ERP sans contrat.** Clients
(`index.php:1973`) et bons (`index.php:251-271`) sont écrits directement dans
le modèle ERP. Ça marche parce que même base ; le jour où l'ERP migre son
schéma (il vient de le faire pour `shop_product`, cf. `index.php:84-89`), ces
écritures cassent sans préavis. À défaut d'API, tenir la liste de ces points
de couture — c'est l'objet de ce rapport.

**P2 — `is_divisible` ne prédit pas les portions.** Vérifié : `6700237` est
`is_divisible: 0` avec 3 portions actives ; les 10 produits `is_divisible: 1`
de la boutique 2 n'en ont aucune. Le code ne s'y fie pas (ni
`erp_portion_options` `index.php:126`, ni `erp_portion_prices`
`erp_catalog.php:192` — tous deux lisent les lignes de portions), mais
`erp_cat_map_product` expose encore le drapeau (`erp_catalog.php:97-98`) : ne
jamais l'utiliser pour décider d'un affichage.

---

## 6. Ce qu'il faudrait à l'ERP pour déplacer le curseur vers l'API

Par ordre d'utilité, si l'objectif est de réduire les lectures SQL directes :

1. `GET /shops/{id}/products/prices` — prix de vente EN LOT, pièce entière et
   portions (débloque §3.a lignes 1-2 et la bascule catalogue) ;
2. une API clients finaux (création + lecture) — supprime les écritures
   directes `client` (§3.b) ;
3. une API bons/promotions — supprime les écritures directes du modèle
   voucher (§3.b) ;
4. les périodes de disponibilité dans `products/available` — supprime
   `availability_where` (§3.b) ;
5. la distinction « recette évaluée sans allergène / non renseignée » dans
   `products/available` — permet d'abandonner la jointure allergènes sans
   perdre la sémantique de sécurité alimentaire (§3.a).
