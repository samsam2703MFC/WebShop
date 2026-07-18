-- PHASE 3 (schéma PROD À PLAT) — `ws_shops` devient une VUE de `shops`.
-- Réf. de l'état final. `lp_shops` (module landing) est SUPPRIMÉ depuis longtemps → aucune
-- bascule landing. Pas de FK sur ws_shops/shops en prod (vérifié) → pas de Phase 2 non plus.
--
-- La vue est un remplaçant FIDÈLE de l'ancienne table : TOUTES les boutiques qui en venaient
-- (legacy_ws_id IS NOT NULL), PAS un filtre webshop_enabled — sinon les résolutions par
-- id/nom (PWA repo_ws_shop_id, /brand, remises, back-office) perdraient les boutiques à
-- webshop_enabled=0 (ex. Gembloux). Les lectures qui ne veulent que les actives appliquent
-- déjà leur propre active=1. Colonnes à plat mappées : discount_type/value ->
-- webshop_discount_type/value ; + webshop_enabled, contrat (présents sur l'ancienne table).
--
-- Idempotent : le RENAME n'a lieu que si ws_shops est encore une BASE TABLE.

SET @is_base := (SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema=DATABASE() AND table_name='ws_shops' AND table_type='BASE TABLE');
SET @legacy_exists := (SELECT COUNT(*) FROM information_schema.tables
                        WHERE table_schema=DATABASE() AND table_name='ws_shops_legacy');
SET @s := IF(@is_base=1 AND @legacy_exists=0, 'RENAME TABLE ws_shops TO ws_shops_legacy', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE OR REPLACE VIEW ws_shops AS
SELECT id, slug, id_brand, name, legal_name, email, phone, street, street_num,
       zip, city, country_code, vat, opening_time, closing_time, accent, tint, logo_url,
       discount_type  AS webshop_discount_type,
       discount_value AS webshop_discount_value,
       webshop_enabled, contrat,
       active
  FROM shops
 WHERE legacy_ws_id IS NOT NULL;

-- Contrôle : SELECT COUNT(*) FROM ws_shops;  -- = nb de boutiques legacy (5), Gembloux inclus.
