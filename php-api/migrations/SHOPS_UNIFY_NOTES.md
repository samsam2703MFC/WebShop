# Unification boutiques — état réel & suite

Périmètre **A** : `ws_shops` + `lp_shops` → **`shops`** ; `franchisee_shop` (master ERP) laissé tel quel.

## État constaté en prod (18/07)
- **`shops` existe déjà et est peuplée** (5 boutiques, ids 2,3,4,5,10 = ids Buddy) — **Phase 1 déjà faite**,
  mais dans un schéma **À PLAT** (pas les JSON `webshop_config`/`landing_config` du doc d'audit) :
  `discount_type, discount_value, kind, concept_fr/nl, webshop_url, zone, lat, lng, sort_order,
  is_franchise, operator, region, since_year, bg_color, img_width` **+ `contrat`**.
- **Aucune FK ne pointe encore sur `shops`** → les 21 FK `ws_*` référencent toujours `ws_shops`.
- `ws_shops` existe encore (5 lignes) → **double source** tant que Phase 2/3 pas faites.

> Conséquence : l'ancien `0008` (créer+peupler `shops` en schéma JSON) est **obsolète et supprimé**.
> Il avait planté en prod (`Unknown column 'webshop_config'`) — sans dégât (INSERT annulé), et
> a été retiré de l'auto-apply. `migrate.sh` est de nouveau vert.

## Correctif code déjà livré
- `POST /admin/shop-discount` écrivait `shops.webshop_config` (JSON, **colonne inexistante** en prod)
  → corrigé en `UPDATE shops SET discount_type=?, discount_value=?` (schéma à plat réel).

## Ce qui reste pour finir l'unification (gaté — exécution manuelle phpMyAdmin recommandée)
Après le plantage, je **ne ré-auto-applique pas** les phases lourdes ; on les joue à la main,
phase par phase, avec contrôle go/no-go (cf. `RUNBOOK-shops-unify.md`).

| Étape | Fichier | Effet |
|---|---|---|
| Phase 2 | `pending/0009_shops_unify_phase2_fk_repoint.sql` | Repointe les **21 FK** `ws_*` → `shops` (agnostique au schéma : lit `information_schema`). Valide tel quel car `shops.id == ws_shops.id`. |
| Phase 2b | `pending/0010_shops_unify_phase2b_landing_fk.sql` | FK landing (`lp_shop_hours/services`) — seulement si `lp_shops` est une table ici (coordonner repo `landing`). |
| Phase 3 | `pending/0011_shops_unify_phase3_views_flat.sql` | Renomme `ws_shops`/`lp_shops` → `_legacy` + **vues** de compat **réécrites pour le schéma à plat**. |
| Phase 4 | `backend/schema/migrate-unify-shops-phase4.sql` | DROP legacy — manuel, destructif, plus tard. |

### ⚠️ La vue ws_shops doit être FIDÈLE (toutes les boutiques, pas seulement webshop_enabled=1)
La vue reprend `WHERE legacy_ws_id IS NOT NULL` (= les 5 boutiques venues de `ws_shops`),
**pas** `WHERE webshop_enabled = 1`. Raison : plusieurs liens résolvent une boutique **par
id ou par nom** sur `ws_shops` et casseraient pour une boutique à `webshop_enabled=0` :
- **PWA** (`latelier-by-pwa/public/api/repo.php` → `repo_ws_shop_id`) : nom → `ws_shops.id`
  pour `wsShopId` + bureaux B2B (`repo_offices`).
- **Webshop** : `/brand?shopId=`, remise commande (`SELECT … FROM ws_shops WHERE id=?`),
  et le back-office (`bo/routes.php` : listes + jointures incidents/commandes).
Les lectures qui ne veulent que les actives appliquent déjà `active=1` (ex. `GET /shops`).
Le reste des liens PWA/webshop utilise déjà `shops` directement (`/webshop-link`,
`repo_shops`, `repo_webshop_url`, validation preferred_shop) → non impactés.

### Prérequis avant Phase 3
- Phase 2 (et 2b) faites (aucune FK sur `ws_shops`/`lp_shops`).
- Les **lectures** webshop de `ws_shops` (`GET /shops`, `/brand`, remise commande, jointures) passeront
  par la **vue** `ws_shops` sans changement de code. L'**écriture** `/admin/shop-discount` vise déjà
  `shops` (corrigé ci-dessus). Aucune autre écriture directe `ws_shops` dans php-api.
- Côté **landing** (autre repo) : recâbler les écritures `lp_shops` vers `shops` AVANT que `lp_shops`
  devienne une vue.

### Contrôles après Phase 2 puis Phase 3
```sql
-- après Phase 2 : 0 FK sur ws_shops, 21 sur shops
SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
 WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='ws_shops';   -- 0
SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
 WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='shops';      -- 21
-- après Phase 3 : ws_shops est une vue qui reflète shops
SELECT id, slug, name, webshop_discount_type, webshop_discount_value FROM ws_shops ORDER BY id;
```
