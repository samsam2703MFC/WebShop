# Unification boutiques — packaging en migrations

Périmètre **A** : `ws_shops` + `lp_shops` → **`shops`**. `franchisee_shop` (master ERP) est
laissé tel quel. Audit complet & décisions : `SHOPS_UNIFY_AUDIT.md` ; pas-à-pas manuel
d'origine : `RUNBOOK-shops-unify.md`.

## Séquence (gatée — comme le runbook)

| Migration | Phase | Effet | Auto-appliqué |
|---|---|---|---|
| `0008_shops_unify_phase1.sql` | 1 | Crée + peuple `shops` (ws_shops + lp_shops), non destructif | ✅ |
| `pending/0009_shops_unify_phase2_fk_repoint.sql` | 2 | Repointe les **21 FK** `ws_*` → `shops` | ⏸️ retenu |
| `pending/0010_shops_unify_phase2b_landing_fk.sql` | 2b | Repointe FK landing (`lp_shop_hours/services`) + remap ids | ⏸️ retenu (coordonner repo `landing`) |
| `pending/0011_shops_unify_phase3_views.sql` | 3 | Renomme `ws_shops`/`lp_shops` → `_legacy` + **vues** de compat | ⏸️ retenu |
| `backend/schema/migrate-unify-shops-phase4.sql` | 4 | **DROP** des tables legacy | ❌ manuel (destructif) |

> Pourquoi gaté : phases 2/2b/3 touchent 21 FK de prod + le module landing (autre repo).
> Le runbook impose une vérif go/no-go entre chaque. On garde ce filet : `0008` part seul,
> les suivantes sont sorties de `pending/` → `migrations/` **une par une** après contrôle.

## Après le déploiement de 0008 — à vérifier
```sql
SELECT webshop_enabled, landing_enabled, COUNT(*) FROM shops GROUP BY 1,2;   -- répartition (attendu (1,1)+(1,0)+(0,1))
SELECT COUNT(*) FROM shops WHERE slug IS NULL OR slug='';                     -- 0
SELECT slug, COUNT(*) c FROM shops GROUP BY slug HAVING c>1;                  -- 0 doublon
SELECT id, slug, name, webshop_enabled, landing_enabled, legacy_ws_id, legacy_lp_id FROM shops ORDER BY id;
-- Cohérence webshop : shops (webshop_enabled=1) doit refléter ws_shops
SELECT (SELECT COUNT(*) FROM ws_shops) AS n_ws, (SELECT COUNT(*) FROM shops WHERE webshop_enabled=1) AS n_shops_ws;
```
Effet immédiat : `$SHOPS = 'shops'` (le code le préfère dès qu'elle existe) → le franchisor
lit `shops`. `ws_shops`/`lp_shops` restent des **tables intactes** ⇒ les autres lectures
webshop et le module landing continuent sans changement (état additif, réversible via
`rollback/0008_...`).

## Prérequis avant d'activer 2/2b/3
- **2 (FK ws)** : phase 1 OK, `shops.id == ws_shops.id` (garanti). Avant activation je
  durcis le garde-fou orphelins (SIGNAL au lieu de commentaire).
- **2b + 3 (landing)** : recâbler les **écritures** landing (`lp_shops`) vers `shops`
  côté repo `landing` AVANT que `lp_shops` devienne une vue (les vues sont en lecture seule).
- **Code webshop** : les lectures directes de `ws_shops` (`GET /shops`, `/brand`,
  `webshop_discount`) passeront par la **vue** `ws_shops` (phase 3) sans changement ;
  l'écriture `POST /admin/shop-discount` doit viser `shops.webshop_config` (à recâbler
  avec la phase 3).
