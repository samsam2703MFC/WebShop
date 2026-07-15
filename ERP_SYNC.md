# ERP ⇄ Webshop — Cartographie, Crons & Liens manquants

> But : organiser la synchro **Franchise Buddy (ERP) ↔ webshop (`ws_`)** — quoi pousser,
> dans quel sens, à quelle fréquence, et ce qui bloque aujourd'hui.
>
> ⚠️ **À confirmer sur ton dump réel** : les noms de tables ERP ci-dessous (`fb_*`)
> viennent du modèle de synchro déjà conçu (récupéré de l'historique git,
> `sync/field-mapping.json`). Remplace-les par les **vrais noms** de `atelierby_db`.

---

## 0. Contexte (important)

- **Une seule base `atelierby_db`** contient **l'ERP** (`fb_*` / Franchise Buddy) **ET** le
  **webshop** (`ws_*`). → La synchro est **INTRA-BASE** (même MySQL), **pas** du HTTP/WooCommerce.
- **Sens de vérité (master) :**
  | Domaine | Master | Réplique |
  |---|---|---|
  | Catalogue, prix/boutique, stock, menus, promos | **ERP** (`fb_*`) | `ws_*` (lecture webshop) |
  | Commandes, clients webshop, comptes B2B, créneaux/tournées webshop | **Webshop** (`ws_*`) | remontée vers l'ERP |
- **Règle d'or (déjà posée dans le design) :** le webshop **n'écrit jamais** dans les tables métier de l'ERP. La seule écriture ERP autorisée = une table **outbox** (voir §2).

---

## 1. Cartographie ERP → `ws_` (catalogue descendant)

Clé de synchro stable = **code/SKU ERP** ↔ **`external_id`** côté `ws_` *(à ajouter — voir §3)*.

| Entité ERP (`fb_*`) | Colonnes ERP | Table(s) `ws_` cible | Remarques vs schéma ACTUEL |
|---|---|---|---|
| **fb_boutiques** | code, enseigne, adresse, couleur, horaires, click_collect | `ws_shops` | id `ws_shops` = INT ; besoin `external_id` = code boutique |
| **fb_familles** | code, libelle, image, ordre | `ws_categories` | idem `external_id` |
| **fb_articles** | sku, famille_code, designation, descriptif, prix_ttc, taux_tva, image, allergenes, portions, promo_croisee, options_menu, retrait_seul, delai_jours | `ws_products` **+ tables liées** | ⚠️ Normalisé maintenant : `taux_tva`→(pas de colonne), `allergenes`→**`ws_product_allergens`**, `delai_jours`→**`ws_product_availability`**, `famille_code`→`ws_products.cat_id` (INT, via résolution) |
| **fb_stock** | sku, boutique_code, prix_boutique, dispo, stock_livraison | `ws_product_prices` **+** `ws_product_shops` **+** `ws_product_stock` | ⚠️ Éclaté : prix/boutique→**`ws_product_prices.price`** ; dispo/no_delivery→**`ws_product_shops`** ; stock jour→**`ws_product_stock`** |
| **fb_promos** | code, libelle, type_promo, valeur, boutique_code, debut, fin | ~~`ws_promotions`~~ → **`ws_pricing_rules`** / **`ws_vouchers`** | ⚠️ `ws_promotions` **n'existe pas** ; remapper vers `ws_pricing_rules` (cross-portion, remises) ou `ws_vouchers` (codes) |

**Menus / options** (produits configurables) : côté `ws_` = `ws_product_options` / `ws_bundles` /
`ws_assortments`. Source ERP = à confirmer (cf. `FRANCHISE_BUDDY_MENUS_API.md`).

## 1bis. Cartographie `ws_` → ERP (remontée montante)

| Entité webshop | Table `ws_` | Vers ERP | Fréquence |
|---|---|---|---|
| **Commandes validées** | `ws_orders` + `ws_order_lines` | table commandes ERP (ou outbox webshop) | voir §2 |
| **Clients / comptes B2B** | `ws_customers`, `ws_offices` | ERP CRM (optionnel) | à définir |

---

## 2. Crons / Push API (plan)

Comme tout est dans **une base**, deux mécanismes possibles — je recommande **A**.

### A. Outbox + cron PHP (recommandé, sans Node)
- **Descendant ERP→ws** : des **triggers** sur `fb_articles/familles/boutiques/stock/promos`
  insèrent 1 ligne dans **`fb_outbox`** (`entity`, `ref_id`, `op`, `created_at`, `processed_at`).
  Un **cron PHP** (`php-api/cron/sync-pull.php`) lit les lignes non traitées, **upsert** dans `ws_*`
  via le mapping du §1, marque `processed_at`. Idempotent, auditable.
- **Montant ws→ERP** : à la validation d'une commande, insérer dans **`ws_outbox`** ; un cron
  pousse vers l'ERP (INSERT dans la table commandes ERP, ou API si l'ERP en expose une).

### B. Cron "full upsert" (plus simple, plus lourd)
- Un cron relit **tout** `fb_*` et réécrit `ws_*` (upsert par `external_id`) toutes les N min.
  Pas de triggers, mais O(catalogue) à chaque passage.

### Planification proposée (crontab)
```cron
# Descendant ERP → webshop (catalogue, prix, stock, promos)
*/5 * * * *  php /var/www/atelierby/api/cron/sync-pull.php      >> /var/log/ws-sync.log 2>&1
# Montant webshop → ERP (commandes)
*/5 * * * *  php /var/www/atelierby/api/cron/sync-push.php      >> /var/log/ws-sync.log 2>&1
# Nettoyage réservations de stock expirées (si tu utilises ws_stock_reservations)
* * * * *    php /var/www/atelierby/api/cron/reservations-gc.php >> /var/log/ws-sync.log 2>&1
```
> ⚠️ L'ancien `crontab.txt` visait **Node + WooCommerce** (supprimés). Ci-dessus = version **PHP**,
> adaptée au serveur VPS (`/var/www/atelierby/api`). Les scripts `cron/*.php` sont **à écrire**.

---

## 3. Liens manquants (les vrais bloquants) 🔴

1. **Aucune clé de synchro `external_id`** dans `ws_` → **rien ne relie** une ligne ERP à sa ligne `ws_`.
   → **À AJOUTER** : `external_id` (le code/SKU ERP) sur `ws_shops`, `ws_categories`, `ws_products`,
   et une résolution boutique pour `ws_product_prices`/`ws_product_shops`/`ws_product_stock`.
2. **`ws_promotions` inexistante** → remapper les promos ERP vers `ws_pricing_rules` / `ws_vouchers`.
3. **Schéma `ws_` normalisé** ≠ mapping d'origine (mono-table) :
   - prix/boutique → `ws_product_prices` ; dispo/no_delivery → `ws_product_shops` ; stock jour → `ws_product_stock`.
   - `vat_rate` : **pas de colonne** dans `ws_products` (à ajouter si l'ERP porte la TVA).
   - `allergenes` → `ws_product_allergens` ; `delai_jours` → `ws_product_availability`.
4. **Résolution d'ID** ERP(code) → ws(INT) : nécessaire pour toutes les FK (`cat_id`, `shop_id`, `product_id`).
5. **Noms de tables ERP réels inconnus** → il me faut le **dump structure** de `atelierby_db`
   (les `fb_*` ci-dessus sont un modèle, pas forcément tes vrais noms).
6. **Menus/options** : source ERP des produits configurables à confirmer.

---

## 4. Ce qu'il me faut de toi pour finaliser

- **`mysqldump --no-data atelierby_db`** (structure complète : ERP + ws_) → pour lire les **vrais noms**
  de tables/colonnes ERP et les mapper précisément.
- Confirmer le **sens de vérité** par domaine (le tableau du §0).
- Confirmer si l'ERP a une **API** ou si tout passe par SQL intra-base.

Une fois ça reçu : je fige le mapping exact, j'écris l'**alter `external_id`**, les **triggers `fb_outbox`**,
et les **scripts `cron/*.php`** (pull ERP→ws, push commandes ws→ERP).
