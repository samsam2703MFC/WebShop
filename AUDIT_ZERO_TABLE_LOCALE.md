# Audit — Retirer TOUTES les tables locales (`ws_`/`pwa_`/`bo_` + SQL direct ERP) ?

> Relevé du 31/08/2026, branche `claude/client-orders-post-endpoint-pk5azl`.
> Méthode : inventaire exhaustif des tables citées dans le SQL de `php-api/`
> (grep FROM/JOIN/INTO/UPDATE, comptes réels), lecture des chemins porteurs,
> recoupé avec `AUDIT_API_VS_DB.md` (20/08) et `ERP_GAP.md` (24-25/08).
> Chaque affirmation porteuse cite `fichier:ligne`.

## 0. Réponse courte

**Non — pas d'un bloc, et pas entièrement.** L'inventaire réel : **~50 tables
`ws_`**, **7 `pwa_`**, **5 `bo_`** (+ `shops`, `auth_handoff`), et **17 tables
ERP lues/écrites en SQL direct** (dont `client` : 90 références). Elles se
répartissent en quatre familles, chacune avec SA condition de mort :

| Famille | Peut disparaître ? | Condition |
|---|---|---|
| **A. Vestiges** (~5 tables) | ✅ maintenant | un nettoyage de code, rien d'autre |
| **B. Répliques & coutures ERP** (~30 tables, le gros des références) | ✅ oui | quand l'ERP livre les endpoints listés au §6 |
| **C. Métier webshop sans modèle ERP** (~35 tables) | ⚠️ seulement si l'ERP **absorbe le métier** | bureaux, tournées, créneaux, stock/jour, frais, menus, cross-sell : décision produit, pas endpoint |
| **D. État de service** (~12 tables) | ❌ tant qu'un serveur webshop existe | auth BO, idempotence Stripe, interrupteurs, rate-limit |

**P0 découvert (et corrigé) pendant l'audit** : `erp_catalog.php` n'était
requis nulle part — les bascules « assortiment en direct » (6edd47a) et
« prix de vente ERP » (96a703e) étaient **lettre morte au runtime** ; même
avec `ws_param.catalog_source='erp'` posé, prix et assortiment restaient
locaux. Corrigé par `1db3b57`. **À vérifier en prod : si le paramètre était
posé, les prix servis jusqu'ici étaient les locaux (le « 1,00 € de
remplissage »).**

La cible réaliste à court terme n'est donc pas « zéro table » mais **« zéro
RÉPLIQUE »** : catalogue, clients, bons, commandes vivent côté ERP ; le
métier B2B/logistique reste au webshop tant que Franchise Buddy ne l'a pas
absorbé ; l'état technique reste tant que le serveur existe.

---

## 1. Inventaire chiffré (références SQL dans `php-api/`)

**Tables ERP en SQL direct** : `client` 90 · `voucher_code` 17 ·
`voucher_campaign` 14 · `b2b_client_company_department` 10 ·
`promotion_order_discount` 8 · `promotion` 6 · `voucher_campaign_channel` 5 ·
`voucher_redemption` 4 · `shop_product_portion_price` 3 · `product_portion` 3 ·
`product` 2 · `product_availability_period(_connection)` 2+2 ·
`franchisee_shop` 1 · `flattened_recipe_ingredient` /
`material_allergen_connection` / `allergen` 1 chacune · `client_order` (cron
SQL B2B).

**Locales les plus référencées** : `ws_products` 94 · `ws_offices` 93 ·
`ws_orders` 68 · `ws_office_delivery_sites` 66 · `ws_tours` 62 ·
`ws_categories` 35 · `shops` 31 · `ws_bundles` 22 · `bo_users` 21 ·
`ws_tour_availability` 21 · `ws_product_stock` 20 — le détail par famille est
aux §2-5.

Le front (JSX) ne parle qu'à `php-api/` : toute la question se joue côté
serveur.

---

## 2. Verdict A — peut mourir MAINTENANT (vestiges)

> ✅ **FAIT le 31/08** — migration `0105_drop_vestiges.sql` + code nettoyé
> (repli géo retiré, JOIN BO re-pointés sur `client`, `basket_pa` réduit à
> `no_delivery`, `/admin/price` supprimé, schéma et seed à jour). La version
> actionnable de tout l'audit vit dans `CHECKLIST_ZERO_TABLE.md`.

| Table | Réf. | Constat | Geste |
|---|---|---|---|
| `ws_customers` | 3 | repli legacy « si `client` n'existe pas » (`index.php:12424-12450`) ; `client` existe | retirer le repli, drop |
| `ws_product_prices` | 2 | une lecture d'écran BO (`index.php:9643`) + une écriture `/admin/price` (`index.php:10987`) ; le prix par boutique vit dans l'ERP (`portion_price_gross`, `shop_product_portion_price`) | retirer l'écriture, adapter l'écran, drop |
| `ws_product_availability` | 1 | les contrôles réels lisent l'ERP (`availability_where` `index.php:11473`, `product_available_on` `:11507`) | drop |
| `ws_category_availability` | 1 | idem | drop |
| `ws_vouchers` | 5 | **déjà une vue** non-inscriptible sur le modèle ERP (cf. `index.php:3277+`) — plus une table | rien (déjà fait) |

Précédent de méthode : `ws_season`, supprimée proprement en trois migrations
(0100-0102) — diagnostic, levée de FK, drop. C'est le patron à réutiliser.

---

## 3. Verdict B — meurt quand l'ERP livre un manque PRÉCIS

### B1. Réplica catalogue — `ws_products` 94, `ws_categories` 35, `ws_category_subs` 8, `ws_product_shops` 10, `ws_product_allergens` 1, `ws_tags` 2

Avec `catalog_source='erp'` (effectif depuis `1db3b57`) : assortiment, canaux
et **prix** viennent de l'ERP en direct, 503 honnête si l'ERP est muet
(`index.php:466-496`, `:166-192`). Le SQL local reste le **squelette de la
vue** : catégories/tri, sous-catégories, images, drapeaux menus, allergènes.
Pour qu'il meure, il manque à l'ERP (cf. `ERP_GAP.md` §4-5) :
- **photos** en lot ou à URL stable (aujourd'hui : sync locale, URL R2 1 h) ;
- **`allergens_evaluated`** — la distinction « évaluée sans allergène / non
  renseignée » que le SQL calcule (sécurité alimentaire, à ne pas perdre) ;
- les périodes de disponibilité sont déjà couvertes (SQL direct ERP +
  `include=availability_periods`).

⚠️ **Préalable structurel** : les produits **porteurs de menus** sont créés
LOCALEMENT dans `ws_products`, dans l'espace d'ids de l'ERP
(`AUDIT_API_VS_DB.md` §5, P1 collision). Dropper le réplica exige que ces
porteurs deviennent des produits ERP.

### B2. Clients — table ERP `client` en SQL direct (90 réf., lectures ET écritures)

Le webshop **écrit** la fiche ERP à l'inscription (mot de passe, préférences,
société de facturation — `ERP_GAP.md` §2) et lit par l'index local 15 min
(`erp_clients.php`). Meurt quand : **API clients finaux complète** (CRUD +
rattachement boutique — la création API existe déjà, `erp_client_creer`
`erp_clients.php:201`). Reste à trancher : **où vit l'authentification**
(`password_hash` & co.) — l'ERP ne la veut pas (`ERP_GAP.md` §4) ; « zéro
table » absolu est impossible sans décider ça (une table d'auth locale restera,
ou l'ERP l'assume).

### B3. Bons & promotions — modèle ERP écrit en direct (7 tables, ~55 réf.)

`promotion`, `voucher_campaign(_channel)`, `voucher_code`,
`voucher_redemption` (canal `WS`), garde-fou `franchisee_shop` : upsert
`index.php:273+`, rachat à la commande `index.php:3287+`. Meurt quand : **API
bons/promotions**. À traiter en même temps : `ws_pricing_rules` (8, règle
cross-portion locale vs ERP) et `ws_client_vouchers` (2). Point ouvert :
`voucher_redemption.id_transaction` reste NULL — le lien redemption ↔
`client_order` est à câbler quand l'API commandes rendra une transaction.

### B4. Commandes — `ws_orders` 68, `ws_order_lines` 17

Étape 1 **faite** (`e77744b`) : la commande naît côté ERP à la confirmation
(`POST client-orders`, `erp_order_id`, reprise cron). `ws_orders` reste la
**copie opérationnelle** tant que l'ERP n'a pas : **GET des commandes d'un
client**, un statut **« en attente de paiement »/« annulée »** (ou un DELETE),
et des champs **livraison/frais/créneau/paiement** (aujourd'hui dans
`comment`). Sans eux : suivi client (`index.php:3846+`), BO
tournées/préparation, stats, factures et notes lisent `ws_orders`.

### B5. Portions — `product_portion` + `shop_product_portion_price` en SQL direct (6 réf.)

L'équivalent API existe (`portions/prices` un appel/produit ;
`include=portions` en lot). Le SQL direct (`erp_portion_options`
`index.php:224-264`) reste le chemin le plus robuste tant qu'on est même-base ;
il mourra naturellement avec B1.

### B6. `b2b_client_company_department` (10 réf.) — endpoint départements à demander.

### B7. PWA fidélité — `pwa_purchases` 7, `pwa_purchase_items` 2, `pwa_invoices` 1

Tickets de caisse servis dans « Mes achats » (`index.php:3854`). L'ERP annonce
des endpoints fidélité/facturation (`ERP_GAP.md` §2, clé de rapprochement =
téléphone) : ces trois-là meurent à la livraison. `pwa_reviews`/
`pwa_review_items` (avis) et `pwa_client_office`/`pwa_offices` (lien bureau)
relèvent du §4.

---

## 4. Verdict C — métier webshop SANS modèle ERP : absorber ou garder

Ces tables ne « disparaissent » pas contre un endpoint : il faudrait que
Franchise Buddy **absorbe le métier** (nouveaux modèles, nouveaux écrans chez
eux) — ou qu'on assume qu'elles restent. Par domaine, avec le poids réel :

| Domaine | Tables (réf.) |
|---|---|
| **Bureaux B2B** | `ws_offices` (93), `ws_office_delivery_sites` (66), `ws_office_emails` (16), `ws_office_join_requests` (9), `ws_office_delivery_settings` (9), `ws_office_invites` (8), `ws_client_link_requests` (7), `ws_b2b_event_requests` (2), `pwa_client_office`, `pwa_offices` — le lien client↔bureau validé main est « purement webshop » (`ERP_GAP.md` §4) |
| **Tournées & zones** | `ws_tours` (62), `ws_tour_availability` (21), `ws_franchisor_catchment` (17), `ws_tour_tracking` (11), `ws_delivery_zones` (10), `ws_tour_closures` (7), `ws_tour_postcodes` (6), `ws_zone_requests` (2) |
| **Créneaux & calendrier** | `ws_calendar_rules` (13), `ws_shop_exceptions` (11), `ws_slots` (9), `ws_shop_availability` (5), `ws_slot_capacity` (3) |
| **Stock du jour & réservations** | `ws_product_stock` (20), `ws_stock_reservation` (11), `ws_product_stock_defaults` (5) — l'ERP n'a pas de stock jour×canal |
| **Frais de livraison** | `ws_delivery_fee_rules` (14) |
| **Menus/formules** | `ws_bundles` (22), `ws_bundle_slots` (13), `ws_bundle_slot_choices` (10), `ws_bundle_triggers` (6) — cf. B1 (porteurs locaux) |
| **Cross-sell** | 6 tables `ws_cross_sell_*` (~24) |
| **Promo cumulée → cadeau** | `ws_promo_campaign` (7), `ws_promo_progress` (7), `ws_promo_progress_order` (1) |
| **Avis** | `pwa_reviews` (6), `pwa_review_items` (4), `ws_review_guidelines` (5) |
| **Boutique côté webshop** | `shops` (31 — unifiée `ws_shops`+`lp_shops`→`shops`, RUNBOOK) : les colonnes `webshop_enabled`, langues, `landing_config` n'existent que là (`ERP_GAP.md` §6) ; `ws_shop_payment_options` (4) |
| **i18n interface** | `ws_i18n` (3) — alternative crédible : des FICHIERS statiques versionnés, pas l'ERP |
| **Templates d'e-mails** | `ws_email_templates` (3) |

---

## 5. Verdict D — état de service (existe parce que le serveur existe)

`bo_users` (21), `bo_role` (14), `bo_user_shops` (11), `bo_pin_session` (7),
`bo_audit` (4) — l'auth des back-offices ; `ws_param` (14) — les
interrupteurs (`catalog_source`, `orders_push`, jetons ERP…) ;
`ws_stripe_event` — **l'idempotence du webhook Stripe, critique** ;
`ws_rate_limit`, `ws_incidents`, `ws_shop_device_token` (push),
`ws_bo_store`, `auth_handoff`. Supprimer ça, ce n'est pas migrer des données :
c'est déplacer des FONCTIONS (comptes BO chez l'ERP ? push ? webhooks ?). Tant
qu'un `php-api/` tourne, il garde son état technique.

---

## 6. La liste consolidée à demander à l'ERP (ordonnée par ce qu'elle libère)

1. **Commandes** : GET par client + statut « pending »/« cancelled » (ou
   DELETE) + champs livraison/frais/créneau/paiement → mort de `ws_orders` +
   `ws_order_lines` (85 réf.).
2. **Clients finaux** : CRUD complet + décision sur l'auth → mort des 90 réf.
   SQL sur `client`.
3. **Bons/promotions** : API lecture + rachat → mort de 7 tables de couture
   (~55 réf.) ; brancher `id_transaction` sur la commande ERP.
4. **Catalogue** : photos en lot/URL stable + `allergens_evaluated` → mort du
   réplica (~150 réf.), après migration des porteurs de menus en produits ERP.
5. **Départements B2B** (10 réf.), **fidélité** (10 réf.).
6. **Colonnes boutique webshop** dans `GET /shops` (`ERP_GAP.md` §6).
7. **Absorptions MÉTIER** (décision produit, pas endpoint) : stock/jour,
   créneaux, tournées, bureaux, frais, menus, cross-sell — sinon ces ~35
   tables restent, et c'est un choix défendable.

---

## 7. Séquence recommandée

- **Vague 0 — fait** : `require erp_catalog.php` (`1db3b57`), naissance des
  commandes côté ERP (`e77744b`), fin du parcours invité.
- **Vague 1 — activer & observer** : poser `catalog_source='erp'` et
  `orders_push='erp'` en prod ; surveiller `erp_notes`, `erp_push_error`, et
  vérifier le point P0 (« quels prix ont été servis jusqu'ici ? »).
- **Vague 2 — nettoyages A** : `ws_customers`, `ws_product_prices`,
  `ws_(product|category)_availability` (patron `ws_season`).
- **Vague 3 — coutures écrites → API** dès livraison ERP : clients (2), bons
  (3).
- **Vague 4 — lectures commandes → ERP**, puis drop `ws_orders` (1).
- **Vague 5 — réplica catalogue → pass-through**, puis drop (4).
- **Vague 6 — trancher le métier C et l'état D** : absorption ERP, ou
  périmètre assumé du webshop.

---

## 8. Risques nommés

- **Même base = couplage silencieux** : chaque migration de schéma ERP peut
  casser une couture SQL sans préavis (précédent `shop_product`) — c'est
  l'argument central POUR cette bascule.
- **Résilience** : la doctrine du 24/08 est « ERP muet → 503 honnête, pas de
  rémanence ». Zéro table locale = **zéro vente pendant une panne ERP**. À
  assumer explicitement : c'est LE prix de la cible.
- **Collision d'ids** : produits locaux (`AUTO_INCREMENT`) dans l'espace d'ids
  ERP — toujours ouverte tant que les porteurs de menus sont locaux.
- **Annulation** : une commande poussée en `new` ne peut pas être retirée de
  l'ERP (statuts confirmés le 31/08 : new/completed/picked_up/unpicked) — la
  question est chez Szymon.
- **Où vit l'auth client** : la seule donnée que ni l'ERP ni « zéro table »
  ne veulent porter — à trancher avant la vague 3.
