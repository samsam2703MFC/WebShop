-- ---------------------------------------------------------------------------
-- migrate-seasons-unify.sql
-- Unifie les saisons sur ws_season (source unique, basée sur le slug) et
-- supprime ws_assortments (table doublon, 3 lignes).
--
-- ws_season : 14 saisons (id, name, slug, img, sort_order, active), globales.
-- ws_products.season_id → FK vers ws_season. Le front filtre par slug de saison
-- (product.season === chip.id), donc chip.id doit être le slug (fait côté API).
--
-- ORDRE DE DÉPLOIEMENT :
--   1) déployer d'abord l'API rewired (/catalog/assortments lit ws_season)
--   2) PUIS exécuter ce script (drop). Sinon l'API encore en place lirait une
--      table supprimée → 500.
-- Rollback : voir rollback-seasons-unify.sql
-- ---------------------------------------------------------------------------

-- (Optionnel) tracer ce qu'on supprime, pour archive :
-- SELECT * FROM ws_assortments;

DROP TABLE IF EXISTS ws_assortments;

-- Vérifications post-migration :
-- SELECT slug, name, img FROM ws_season WHERE active=1 ORDER BY sort_order;
-- SELECT COUNT(*) AS produits_avec_saison FROM ws_products WHERE season_id IS NOT NULL;
--   (⚠️ si 0 → aucune chip saison ne s'affichera : il faut rattacher des
--    produits à une saison via ws_products.season_id.)
