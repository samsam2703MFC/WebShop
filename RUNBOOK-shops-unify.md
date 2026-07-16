# Runbook phpMyAdmin — Unification des boutiques (`ws_shops` + `lp_shops` → `shops`)

Base cible : **`atelierby_db`** — sélectionne-la à gauche dans phpMyAdmin AVANT chaque étape.
Chaque étape = un fichier SQL + une requête de contrôle + un **feu vert/rouge**.
**Ne passe à l'étape suivante que si le contrôle est vert.** En cas de rouge → section « Rollback ».

> phpMyAdmin : pour les fichiers contenant `DELIMITER //` (procédures — Phases 2, 2b),
> utilise l'onglet **Importer** (upload du fichier) OU colle dans l'onglet **SQL**
> (phpMyAdmin gère `DELIMITER`). Les autres se collent dans l'onglet **SQL**.

---

## Étape 0 — SAUVEGARDE (obligatoire)

phpMyAdmin → `atelierby_db` → onglet **Exporter** → méthode **Personnalisée** →
format **SQL** → coche **Ajouter DROP TABLE** → **Exécuter** (télécharge le `.sql`).
Garde ce fichier : c'est le filet de sécurité complet.

**Feu vert :** tu as un fichier `atelierby_db.sql` sur ton disque.

---

## Étape 1 — Pré-requis schéma (auth + mise à niveau)

Ces deux scripts sont **idempotents** (re-jouables sans risque). Ils ajoutent les
colonnes que l'app attend (sinon 500) et la colonne `client.preferred_shop_id`
(indispensable pour la redirection PWA).

1. Onglet **SQL** → colle le contenu de **`backend/schema/upgrade-to-current.sql`** → **Exécuter**.
2. Onglet **SQL** → colle le contenu de **`backend/schema/alter-client-webshop-auth.sql`** → **Exécuter**.

**Contrôle :**
```sql
SELECT COUNT(*) AS ok FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='client'
   AND column_name IN ('preferred_shop_id','webshop_user','pwa_user');
```
**Feu vert :** `ok = 3`.

---

## Étape 2 — PHASE 1 : créer + peupler `shops` (NON destructif)

Onglet **SQL** → colle **`backend/schema/migrate-unify-shops.sql`** → **Exécuter**.
(ne touche pas `ws_shops`/`lp_shops`, crée juste la table `shops`)

**Contrôles :**
```sql
-- a) répartition attendue : (1,1)=5  et  (0,1)=5
SELECT webshop_enabled, landing_enabled, COUNT(*) FROM shops GROUP BY 1,2;

-- b) total = 10, aucun slug vide, aucun doublon de slug
SELECT COUNT(*) AS total FROM shops;
SELECT COUNT(*) AS slugs_vides FROM shops WHERE slug IS NULL OR slug='';
SELECT slug, COUNT(*) c FROM shops GROUP BY slug HAVING c>1;

-- c) vue d'ensemble
SELECT id, slug, name, webshop_enabled, landing_enabled, legacy_ws_id, legacy_lp_id
FROM shops ORDER BY id;
```
**Feu vert :** total = **10** · répartition **(1,1)=5 / (0,1)=5** · `slugs_vides=0` · aucun doublon.
Les 5 webshop = `corbais, gosselies, halle, sombreffe, gembloux` (tous `landing_enabled=1`),
les 5 vitrine-only = `Bruxelles, Jette, Liège, Hannut, Brugge`.

> Rouge ici ? `DROP TABLE shops;` puis on ajuste la Phase 1. Rien d'autre n'a bougé.

---

## Étape 3 — PHASE 2 : re-pointer les 21 FK ws_ vers `shops`

Onglet **Importer** (le fichier contient une procédure) →
**`backend/schema/migrate-unify-shops-phase2.sql`** → **Exécuter**.

**Contrôles :**
```sql
-- plus AUCUNE FK ne doit référencer ws_shops :
SELECT COUNT(*) AS fk_vers_ws_shops FROM information_schema.KEY_COLUMN_USAGE
 WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='ws_shops';

-- ~21 FK doivent désormais référencer shops :
SELECT COUNT(*) AS fk_vers_shops FROM information_schema.KEY_COLUMN_USAGE
 WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='shops';
```
**Feu vert :** `fk_vers_ws_shops = 0` · `fk_vers_shops ≈ 21`.

> Rouge ? Voir Rollback Phase 2 (`rollback-unify-shops.sql`).

---

## Étape 4 — PHASE 2b : FK côté landing (`lp_shop_hours` / `lp_shop_services`)

⚠️ Uniquement si ces tables existent dans `atelierby_db`. Vérifie d'abord :
```sql
SELECT table_name FROM information_schema.tables
 WHERE table_schema=DATABASE() AND table_name IN ('lp_shop_hours','lp_shop_services');
```
Si elles n'existent pas → **saute cette étape**.

Sinon, **contrôle de sécurité AVANT** (toute ligne doit avoir un shop correspondant) :
```sql
SELECT (SELECT COUNT(*) FROM lp_shop_hours h
         WHERE NOT EXISTS (SELECT 1 FROM shops s WHERE s.legacy_lp_id=h.shop_id)) AS miss_hours,
       (SELECT COUNT(*) FROM lp_shop_services x
         WHERE NOT EXISTS (SELECT 1 FROM shops s WHERE s.legacy_lp_id=x.shop_id)) AS miss_services;
```
**Doit renvoyer `0 / 0`.** Si > 0 : une vitrine lp n'a pas été migrée → STOP, on regarde.

Puis onglet **Importer** → **`backend/schema/migrate-unify-shops-phase2b-landing.sql`** → **Exécuter**.

**Contrôle :**
```sql
SELECT COUNT(*) AS fk_vers_lp_shops FROM information_schema.KEY_COLUMN_USAGE
 WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='lp_shops';
```
**Feu vert :** `fk_vers_lp_shops = 0`.

---

## Étape 5 — PHASE 3 : basculer `ws_shops`/`lp_shops` en VUES de compat

Garde-fou d'abord (doit être 0, sinon rejouer Phase 2/2b) :
```sql
SELECT COUNT(*) AS fk_restantes FROM information_schema.KEY_COLUMN_USAGE
 WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IN ('ws_shops','lp_shops');
```
Si `fk_restantes = 0` → onglet **SQL** → colle **`backend/schema/migrate-unify-shops-phase3.sql`** → **Exécuter**.

**Contrôles :**
```sql
SELECT COUNT(*) AS webshop_shops FROM ws_shops;   -- 5 (via la vue)
SELECT COUNT(*) AS vitrines      FROM lp_shops;   -- 10 (via la vue)
SELECT id, slug, webshop_discount_type, webshop_discount_value FROM ws_shops ORDER BY id;
SELECT id, picker_key, name, city, kind FROM lp_shops ORDER BY sort_order;
```
**Feu vert :** `ws_shops` renvoie 5 lignes, `lp_shops` en renvoie 10, la remise s'affiche.
Les tables d'origine sont conservées en `ws_shops_legacy` / `lp_shops_legacy`.

---

## Étape 6 — Déployer le php-api recâblé

Après Phase 3, déploie le php-api (commits en attente) : `/admin/shop-discount` écrit
la remise dans `shops.webshop_config`, et `GET /webshop-link` est disponible.

**Test :**
```bash
curl "https://<domaine>/api/shops"
curl "https://<domaine>/api/webshop-link?clientId=<un_client_avec_preferred_shop_id>"
```
Le 2e doit renvoyer `{ "url": "...?shop=<slug>", "shopId": ..., "slug": ... }`.

---

## Étape 7 — PHASE 4 : suppression des tables legacy (PLUS TARD, destructif)

**Ne joue ceci qu'après plusieurs jours de stabilisation** et une nouvelle sauvegarde.
Onglet **SQL** → **`backend/schema/migrate-unify-shops-phase4.sql`** → **Exécuter**.
Après ça, plus de rollback vers `ws_shops`/`lp_shops` d'origine.

---

## Rollback (tant que Phase 4 n'a PAS été jouée)

Le fichier **`backend/schema/rollback-unify-shops.sql`** annule par phase, en ordre inverse
(vues → FK landing → FK ws → drop `shops`). Décommente les `RENAME TABLE` / `CALL` selon la
dernière phase atteinte. En dernier recours : réimporter la sauvegarde de l'Étape 0.

---

## Récap ordre d'exécution

| # | Fichier | Onglet | Contrôle vert |
|---|---|---|---|
| 0 | (export) | Exporter | fichier `.sql` téléchargé |
| 1 | `upgrade-to-current.sql` + `alter-client-webshop-auth.sql` | SQL | `ok=3` |
| 2 | `migrate-unify-shops.sql` | SQL | 10 shops, (1,1)=5 / (0,1)=5 |
| 3 | `migrate-unify-shops-phase2.sql` | Importer | 0 FK→ws_shops, ~21→shops |
| 4 | `migrate-unify-shops-phase2b-landing.sql` | Importer | 0 FK→lp_shops |
| 5 | `migrate-unify-shops-phase3.sql` | SQL | ws_shops=5, lp_shops=10 |
| 6 | déploiement php-api | — | `/webshop-link` répond |
| 7 | `migrate-unify-shops-phase4.sql` | SQL | (plus tard) |
