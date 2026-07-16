# Unification `ws_shops` + `lp_shops` → `shops` — AUDIT & schéma cible

> **Statut : AUDIT + SCHÉMA CIBLE pour validation.** Aucune migration écrite/exécutée
> ici (conformément à la contrainte). Migration / rollback / re-câblage seront livrés
> **après** ton accord sur le schéma cible et les règles de résolution de conflits.
>
> **MISE À JOUR :** `lp_shops` trouvé dans le repo **`samsam2703MFC/landing`**
> (`lp_install_shops.php`). **Même base `atelierby_db`** que `ws_shops` → migration
> intra-base. **Clé de matching confirmée : `ws_shops.slug = lp_shops.picker_key`.**
> La **migration Phase 1 est écrite** : `backend/schema/migrate-unify-shops.sql`
> (+ `rollback-unify-shops.sql`) — **NON exécutée**, en attente de ta validation.
> Il me reste à obtenir les **comptages doublons réels** (§1.C, DB live inaccessible d'ici).

---

## 1. AUDIT

### 1.A — `ws_shops` (connu, depuis le repo)

**Schéma** (`backend/schema/ws_schema.sql`) :
| Colonne | Type | Notes |
|---|---|---|
| `id` | INT PK | = **id Franchise Buddy** (pas d'AUTO_INCREMENT — valeur métier) |
| `id_brand` | INT DEFAULT 1 | enseigne |
| `slug` | VARCHAR(50) **UNIQUE NOT NULL** | clé naturelle (URL `?shop=halle`) |
| `name` | VARCHAR(150) NOT NULL | |
| `legal_name` | VARCHAR(150) | |
| `email`, `phone` | VARCHAR | |
| `street`, `street_num`, `zip`, `city`, `country_code` | VARCHAR | adresse |
| `vat` | VARCHAR(30) | |
| `opening_time`, `closing_time` | TIME | |
| `accent`, `tint`, `logo_url` | VARCHAR | branding |
| `webshop_discount_type`, `webshop_discount_value` | VARCHAR/DECIMAL | **spécifique webshop** |
| `active` | BOOLEAN DEFAULT TRUE | |

**Dépendances FK — 21 tables référencent `ws_shops(id)`** (via `shop_id`, sauf
`ws_customers` via `preferred_shop_id`) :
```
ws_assortments, ws_calendar_rules, ws_categories, ws_category_availability,
ws_customers(preferred_shop_id), ws_delivery_fee_rules, ws_office_delivery_settings,
ws_orders, ws_pricing_rules, ws_product_availability, ws_product_prices,
ws_product_shops, ws_product_stock, ws_shop_availability, ws_shop_exceptions,
ws_shop_payment_options, ws_slot_capacity, ws_slots, ws_stock_reservations,
ws_tour_availability, ws_tours
```
→ Toute unification **doit préserver l'espace d'`id`** de `ws_shops` (sinon 21 FK à re-mapper avec risque). Voir §2.

**Consommateurs applicatifs (php-api) :**
| Endpoint | Fichier | Usage |
|---|---|---|
| `GET /shops` | `php-api/index.php:53` | liste (SELECT ws_shops WHERE active=1) |
| `GET /brand?shopId=` | `:59` | thème/branding d'une boutique |
| `POST /orders` | `:352` | lit `webshop_discount_*` |
| `POST /admin/shop-discount` | `:667` | écrit `webshop_discount_*` |

**Consommateurs front :** `window.WSShops` (`webshop-shops-api.jsx`),
`window.WSShopRouter` (`webshop-shop-router.jsx`).

### 1.B — `lp_shops` (CONNU — repo `landing`, base `atelierby_db`)

**Schéma** (`landing/lp_install_shops.php`) :
| Colonne | Type | Notes |
|---|---|---|
| `id` | INT UNSIGNED **AUTO_INCREMENT** PK | ≠ id Buddy |
| `sort_order` | TINYINT | ordre d'affichage vitrine |
| `name` | VARCHAR(100) | |
| `city` | VARCHAR(80) | |
| `postal_code` | VARCHAR(10) | = `ws_shops.zip` |
| `kind` | ENUM('shop','popup') | **spécifique landing** |
| `address` | VARCHAR(255) | **1 seul champ** (vs ws : street/street_num/zip/city) |
| `phone`, `email` | VARCHAR | |
| `concept_fr`, `concept_nl` | TEXT | **spécifique landing** |
| `image_path` | VARCHAR(255) | visuel vitrine |
| `webshop_url` | VARCHAR(255) | lien vers le webshop de cette boutique |
| `is_active` | TINYINT(1) | |
| **`picker_key`** | VARCHAR(40) | **= `ws_shops.slug`** (clé de matching) |
| `zone`, `lat`, `lng` | VARCHAR/DECIMAL | **spécifique landing** (carte) |
| `webshop_active` | TINYINT(1) | flag « présent sur le webshop » |
| `updated_at` | TIMESTAMP | |

**Tables liées landing (FK `shop_id` → `lp_shops.id`)** : `lp_shop_hours` (horaires,
multi-lignes), `lp_shop_services` (services : collect/delivery/phone/b2b/loyalty).
→ À re-pointer vers `shops` en Phase 2 (via `legacy_lp_id`).

**Divergence constatée** : les 2 tables décrivent surtout des boutiques **différentes** —
`ws_shops` = shops Franchise Buddy (Halle, Corbais, Gosselies, Sombreffe, Gembloux) ;
`lp_shops` = vitrines marketing (Châtelain, Sablon, Le Carré, Zuid, Patershol, Grognon).
L'overlap se fait sur `picker_key = slug`.

> *(Optionnel, pour confirmer le prod réel : `SHOW CREATE TABLE lp_shops\G` + FK/vues/triggers.)*
> Requêtes d'introspection restées utiles :
```sql
-- Structure complète
SHOW CREATE TABLE lp_shops\G

-- FK entrantes (tables qui pointent vers lp_shops)
SELECT table_name, column_name, constraint_name, referenced_column_name
FROM information_schema.key_column_usage
WHERE table_schema = DATABASE() AND referenced_table_name = 'lp_shops';

-- Index
SHOW INDEX FROM lp_shops;

-- Vues / triggers qui la référencent
SELECT table_name FROM information_schema.views
 WHERE table_schema=DATABASE() AND view_definition LIKE '%lp_shops%';
SELECT trigger_name, event_object_table FROM information_schema.triggers
 WHERE trigger_schema=DATABASE() AND action_statement LIKE '%lp_shops%';
```
*(Idem pour `ws_shops` si tu veux le réel prod : `SHOW CREATE TABLE ws_shops\G` + les 4 requêtes — pour confirmer que le prod = le schéma repo.)*

### 1.C — Analyse des doublons (À EXÉCUTER — DB live)

**Clé de matching confirmée : `ws_shops.slug = lp_shops.picker_key`.** Lance ces
requêtes sur `atelierby_db` et **colle-moi les résultats** (comptages réels) :
```sql
SELECT (SELECT COUNT(*) FROM ws_shops) AS n_ws, (SELECT COUNT(*) FROM lp_shops) AS n_lp;

-- Matchs (boutiques présentes des 2 côtés)
SELECT COUNT(*) AS matched
FROM ws_shops w JOIN lp_shops l ON l.picker_key = w.slug AND l.picker_key <> '';

-- Orphelins ws (webshop, pas en vitrine)
SELECT w.id, w.slug, w.name FROM ws_shops w
 LEFT JOIN lp_shops l ON l.picker_key = w.slug WHERE l.id IS NULL;

-- Orphelins lp (vitrine, pas webshop)  → picker_key vide OU ne matche aucun slug
SELECT l.id, l.picker_key, l.name, l.city FROM lp_shops l
 LEFT JOIN ws_shops w ON w.slug = l.picker_key WHERE w.id IS NULL;

-- Conflits sur les matchés (nom / email divergents)
SELECT w.slug, w.name AS ws_name, l.name AS lp_name, w.email AS ws_email, l.email AS lp_email
FROM ws_shops w JOIN lp_shops l ON l.picker_key = w.slug AND l.picker_key <> ''
WHERE w.name <> l.name OR IFNULL(w.email,'') <> IFNULL(l.email,'');
```

### 1.D — Mapping colonne par colonne

`ws_shops` rempli ; **colonne `lp_shops` à compléter** une fois 1.B reçu :
| ws_shops | lp_shops (?) | Statut | Cible `shops` |
|---|---|---|---|
| id | ? | clé (préserver l'id ws) | `id` (= legacy ws id) |
| slug | slug ? | **identique** (clé naturelle) | `slug` |
| name | name ? | identique | `name` |
| legal_name | ? | équivalent | `legal_name` |
| email, phone | ? | identique | idem |
| street/street_num/zip/city/country_code | ? | équivalent (peut-être `address` en 1 champ côté lp) | idem |
| vat | ? | identique | `vat` |
| opening_time/closing_time | ? | spécifique/équivalent | idem |
| accent/tint/logo_url | ? (branding) | équivalent | idem |
| webshop_discount_type/value | — | **spécifique webshop** | `webshop_config` (JSON) |
| id_brand | ? | commun | `id_brand` |
| active | ? | commun | `active` |
| — | (champs landing : hero, SEO, etc.) | **spécifique landing** | `landing_config` (JSON) |

---

## 2. SCHÉMA CIBLE (proposé)

```sql
CREATE TABLE shops (
  id             INT PRIMARY KEY,               -- CONSERVE l'id ws_shops (= id Buddy) → les 21 FK restent valides
  slug           VARCHAR(50)  NOT NULL,         -- clé naturelle
  code           VARCHAR(50),                   -- code boutique ERP (si dispo des 2 côtés)
  id_brand       INT DEFAULT 1,
  name           VARCHAR(150) NOT NULL,
  legal_name     VARCHAR(150),
  email          VARCHAR(100),
  phone          VARCHAR(30),
  street         VARCHAR(150),
  street_num     VARCHAR(20),
  zip            VARCHAR(20),
  city           VARCHAR(100),
  country_code   VARCHAR(5) DEFAULT 'BE',
  vat            VARCHAR(30),
  opening_time   TIME,
  closing_time   TIME,
  accent         VARCHAR(20) DEFAULT '#8D1D2C',
  tint           VARCHAR(20) DEFAULT '#fdf6f0',
  logo_url       VARCHAR(255),
  -- Activation PAR MODULE (remplace « présence dans la table »)
  webshop_enabled TINYINT(1) NOT NULL DEFAULT 0,
  landing_enabled TINYINT(1) NOT NULL DEFAULT 0,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  -- Spécifique par module (colonnes peu utilisées → JSON)
  webshop_config  JSON,   -- {discount_type, discount_value, ...}
  landing_config  JSON,   -- {hero, seo, ... depuis lp_shops}
  -- Traçabilité migration
  legacy_ws_id    INT,
  legacy_lp_id    INT,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shops_slug (slug),
  KEY idx_shops_code (code),
  KEY idx_legacy_ws (legacy_ws_id),
  KEY idx_legacy_lp (legacy_lp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Décisions clés (à valider) :**
- **PK stable = l'`id` de `ws_shops`** (= id Franchise Buddy). Les lignes `lp`-only qui
  ne matchent pas reçoivent un **nouvel id > MAX(ws_shops.id)**. → aucune des 21 FK ne casse.
- **Clé naturelle unique = `slug`**. (Si un `code` boutique existe des deux côtés et est
  plus fiable, on matche dessus — à décider en 1.C.)
- **Flags d'activation** `webshop_enabled` / `landing_enabled` au lieu de la présence
  dans une table (une boutique peut être sur les 2, 1, ou 0 module).
- **Colonnes spécifiques peu utilisées → JSON** (`webshop_config`, `landing_config`) ;
  les colonnes communes fortes restent en dur.

---

## 3. RÈGLES DE RÉSOLUTION DE CONFLITS (proposées — à valider AVANT migration)

Pour une boutique présente des deux côtés (match par `slug`) :
| Champ | Source qui fait foi | Raison |
|---|---|---|
| Identité (`name`, `legal_name`, `vat`, adresse) | **ws_shops** | source Franchise Buddy (référentiel ERP) |
| Branding (`accent`, `tint`, `logo_url`) | **lp_shops** | la vitrine porte l'identité visuelle publique |
| `email`, `phone` | **ws_shops** sauf si vide → `lp_shops` | COALESCE, ws prioritaire |
| `webshop_*` | ws_shops → `webshop_config` | spécifique |
| champs landing | lp_shops → `landing_config` | spécifique |
| `active` / flags | `webshop_enabled = (présent dans ws)`, `landing_enabled = (présent dans lp)` | dérivé de la présence |

> **Dis-moi si tu valides / modifies ces priorités.** Elles pilotent le UPSERT de la migration.

---

## 4–5. PLAN Migration / Re-câblage / Validation (esquisse — livré après validation)

1. **Migration (idempotente, transactionnelle)** — *séparée et non exécutée ici* :
   `CREATE TABLE shops` → `INSERT ... SELECT` depuis `ws_shops` (id conservé, `legacy_ws_id`,
   `webshop_enabled=1`) → `INSERT ... ON DUPLICATE KEY UPDATE` (merge) depuis `lp_shops`
   par `slug` (branding lp, `landing_enabled=1`, `legacy_lp_id`, lp-only → nouvel id).
2. **Re-câblage FK** : `ALTER TABLE <21 tables> DROP FK ... ADD FK (shop_id) REFERENCES shops(id)`.
   Comme `shops.id == ws_shops.id`, **aucune valeur `shop_id` à changer**.
3. **Vues de transition** : `CREATE VIEW ws_shops AS SELECT ... FROM shops WHERE webshop_enabled=1`
   et `CREATE VIEW lp_shops AS SELECT ... FROM shops WHERE landing_enabled=1` → le code non
   encore migré continue de lire sans rien casser.
4. **Code applicatif** : php-api (`/shops`, `/brand`, discount) + front `WSShops` pointés sur `shops` ;
   idem module Landing.
5. **Rollback** : `DROP VIEW` + restaurer les FK vers `ws_shops`/`lp_shops` + `DROP TABLE shops`
   (les tables sources ne sont supprimées qu'en **dernière** migration, après validation).
6. **Validation** : `COUNT` avant/après, 0 orphelin, 0 doublon sur `slug`, tests parcours
   (commande webshop, affichage landing, back-office boutique).

---

## Ce qu'il me faut de toi pour continuer
1. **Résultats de 1.B** (`SHOW CREATE TABLE lp_shops` + FK/index/vues/triggers).
2. **Résultats de 1.C** (comptages matchs/orphelins/conflits).
3. **Validation** du §2 (schéma cible) et du §3 (règles de conflits).

→ Avec ça je livre la **migration idempotente + rollback + re-câblage**, en étapes déployables,
sans rien exécuter sans ton feu vert.
