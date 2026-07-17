-- 0001 — Unification des saisons.
-- ws_season (14 saisons, slug, icônes) est la source unique ; ws_products.season_id
-- relie les produits. ws_assortments était un doublon (3 lignes) dont l'id numérique
-- ne matchait pas le slug de saison → filtre saison cassé. On la supprime.
-- Idempotent : IF EXISTS.
DROP TABLE IF EXISTS ws_assortments;
