-- PHASE 3 (schéma PROD À PLAT) — ws_shops / lp_shops deviennent des VUES de `shops`.
-- ⚠️ RÉÉCRIT pour le vrai schéma prod de `shops` : colonnes à plat (discount_type,
--    discount_value, kind, concept_fr/nl, webshop_url, zone, lat, lng, sort_order…),
--    PAS de webshop_config/landing_config JSON. Remplace pending/… le fichier d'audit.
-- ⚠️ PRÉREQUIS : Phase 2 (repoint des 21 FK ws_* → shops) et, si applicable, Phase 2b
--    (landing) faites — plus AUCUNE FK ne référence ws_shops/lp_shops, sinon le RENAME échoue.
-- ⚠️ À exécuter de préférence via phpMyAdmin (onglet SQL), après contrôles go/no-go.
-- Non destructif : les données restent dans *_legacy (drop = Phase 4).

-- Garde-fou : refuse s'il reste une FK vers ws_shops/lp_shops.
SET @fk_left := (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                  WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IN ('ws_shops','lp_shops'));
-- SELECT @fk_left;  -- doit être 0 avant de continuer.

-- 1) Renommer les tables d'origine (conservées pour rollback). lp_shops seulement si présente.
RENAME TABLE ws_shops TO ws_shops_legacy;
SET @has_lp := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='lp_shops' AND table_type='BASE TABLE');
SET @s := IF(@has_lp=1, 'RENAME TABLE lp_shops TO lp_shops_legacy', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) VUE de compat ws_shops : remplaçant FIDÈLE de l'ancienne table (TOUTES les
--    boutiques qui en venaient = legacy_ws_id IS NOT NULL), PAS un filtre webshop_enabled.
--    Sinon les résolutions par id/nom (PWA repo_ws_shop_id, /brand, back-office,
--    remises) perdraient les boutiques à webshop_enabled=0 (ex. Gembloux/popup).
--    Les lectures qui ne veulent que les actives appliquent déjà leur propre active=1.
--    Mappe discount_type/value (à plat) -> webshop_discount_type/value ; expose aussi
--    webshop_enabled + contrat (colonnes présentes sur l'ancienne table via 0003/0004).
CREATE OR REPLACE VIEW ws_shops AS
SELECT id, slug, id_brand, name, legal_name, email, phone, street, street_num,
       zip, city, country_code, vat, opening_time, closing_time, accent, tint, logo_url,
       discount_type  AS webshop_discount_type,
       discount_value AS webshop_discount_value,
       webshop_enabled, contrat,
       active
  FROM shops
 WHERE legacy_ws_id IS NOT NULL;

-- 3) VUE de compat lp_shops : vitrines landing, colonnes à l'identique de lp_shops (schéma à plat).
SET @s := IF(@has_lp=1,
 'CREATE OR REPLACE VIEW lp_shops AS
   SELECT id, sort_order, name, city, zip AS postal_code, kind, address_line AS address,
          phone, email, concept_fr, concept_nl, image_path, webshop_url,
          active AS is_active, slug AS picker_key, zone, lat, lng,
          webshop_enabled AS webshop_active, updated_at
     FROM shops WHERE landing_enabled = 1',
 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Contrôles :
--   SELECT COUNT(*) FROM ws_shops;   -- = nb boutiques webshop_enabled=1
--   SELECT id, slug, name, webshop_discount_type, webshop_discount_value FROM ws_shops ORDER BY id;
--   SELECT id, picker_key, name, city, kind FROM lp_shops ORDER BY sort_order;   -- si landing
