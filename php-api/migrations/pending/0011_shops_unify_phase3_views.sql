-- ============================================================================
-- migrate-unify-shops-phase3.sql  —  PHASE 3 : basculer ws_shops/lp_shops en VUES
-- Renomme les tables d'origine en *_legacy (conservées pour rollback) et crée des
-- VUES de compatibilité ws_shops / lp_shops sur `shops`, pour que le code non encore
-- recâblé (lecture) continue de fonctionner sans changement.
-- ⚠️ PRÉREQUIS : Phase 1 + Phase 2 (+ 2b) jouées — plus AUCUNE FK ne référence
--    ws_shops/lp_shops (elles pointent sur shops). Sinon le RENAME échoue.
-- ⚠️ NON destructif : les données restent dans *_legacy (drop = Phase 4, séparée).
--
-- Écritures : les VUES sont en lecture. La seule écriture legacy (POST /admin/shop-discount)
-- a été recâblée vers shops.webshop_config (JSON). Toute autre écriture directe sur
-- ws_shops/lp_shops doit être recâblée vers `shops` avant la Phase 3.
-- ============================================================================

-- Garde-fou : refuse si une FK référence encore ws_shops ou lp_shops.
SET @fk_left := (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                  WHERE CONSTRAINT_SCHEMA=DATABASE()
                    AND REFERENCED_TABLE_NAME IN ('ws_shops','lp_shops'));
-- SELECT @fk_left;  -- doit être 0. Si >0, (re)jouer Phase 2 / 2b avant.

-- 1) Renommer les tables d'origine (conservées pour rollback).
RENAME TABLE ws_shops TO ws_shops_legacy;
RENAME TABLE lp_shops TO lp_shops_legacy;

-- 2) VUE de compat ws_shops : les boutiques webshop, colonnes à l'identique de l'origine.
--    Les colonnes de remise sont ré-extraites du JSON webshop_config.
CREATE OR REPLACE VIEW ws_shops AS
SELECT id, slug, id_brand, name, legal_name, email, phone, street, street_num,
       zip, city, country_code, vat, opening_time, closing_time, accent, tint, logo_url,
       JSON_UNQUOTE(JSON_EXTRACT(webshop_config,'$.discount_type'))  AS webshop_discount_type,
       CAST(JSON_EXTRACT(webshop_config,'$.discount_value') AS DECIMAL(10,2)) AS webshop_discount_value,
       active
  FROM shops
 WHERE webshop_enabled = 1;

-- 3) VUE de compat lp_shops : les vitrines landing, colonnes à l'identique de lp_shops.
--    id = shops.id (les FK lp_shop_hours/services ont été remappées en Phase 2b).
CREATE OR REPLACE VIEW lp_shops AS
SELECT id,
       CAST(JSON_EXTRACT(landing_config,'$.sort_order') AS UNSIGNED) AS sort_order,
       name, city, zip AS postal_code,
       JSON_UNQUOTE(JSON_EXTRACT(landing_config,'$.kind')) AS kind,
       address_line AS address, phone, email,
       JSON_UNQUOTE(JSON_EXTRACT(landing_config,'$.concept_fr')) AS concept_fr,
       JSON_UNQUOTE(JSON_EXTRACT(landing_config,'$.concept_nl')) AS concept_nl,
       image_path,
       JSON_UNQUOTE(JSON_EXTRACT(landing_config,'$.webshop_url')) AS webshop_url,
       active AS is_active,
       slug AS picker_key,
       JSON_UNQUOTE(JSON_EXTRACT(landing_config,'$.zone')) AS zone,
       CAST(JSON_EXTRACT(landing_config,'$.lat') AS DECIMAL(9,6)) AS lat,
       CAST(JSON_EXTRACT(landing_config,'$.lng') AS DECIMAL(9,6)) AS lng,
       webshop_enabled AS webshop_active,
       updated_at
  FROM shops
 WHERE landing_enabled = 1;

-- Contrôles :
--   SELECT COUNT(*) FROM ws_shops;   -- = nb boutiques webshop (5)
--   SELECT COUNT(*) FROM lp_shops;   -- = nb vitrines landing (10 : 5 webshop + 5 vitrine-only)
--   SELECT id, slug, name, webshop_discount_type, webshop_discount_value FROM ws_shops ORDER BY id;
--   SELECT id, picker_key, name, city, kind FROM lp_shops ORDER BY sort_order;
