-- 0003 — Console marque (franchisor) : colonnes de gouvernance menu/catalogue.
-- Les tables du back-office (bo_users, bo_user_shops, bo_audit, ws_email_templates,
-- ws_brands) et ws_products.brand_mandatory existent DÉJÀ (backend/schema :
-- ws_schema.sql + alter-bo-brand-comms.sql + alter-product-brand-flags.sql) —
-- on ne les recrée pas. Cette migration n'ajoute QUE les colonnes réellement
-- absentes, nécessaires au menu builder et aux toggles franchisor.
-- Idempotent & compatible MySQL 8 : garde information_schema + PREPARE. Additif.

-- Gouvernance menu (logique b) : la catégorie arme, le produit surcharge.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_categories ADD COLUMN menu_default TINYINT NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_categories' AND column_name='menu_default');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_products ADD COLUMN menu_override VARCHAR(8) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_products' AND column_name='menu_override');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_products ADD COLUMN base_cost DECIMAL(10,2) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_products' AND column_name='base_cost');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- brand_whitelist : « Webshop marque » (poussé au réseau). brand_mandatory existe déjà.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_products ADD COLUMN brand_whitelist TINYINT NOT NULL DEFAULT 1','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_products' AND column_name='brand_whitelist');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Gouvernance boutique (contrat + toggle Webshop par boutique) sur ws_shops.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_shops ADD COLUMN contrat VARCHAR(16) NOT NULL DEFAULT ''Franchise''','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_shops' AND column_name='contrat');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_shops ADD COLUMN webshop_enabled TINYINT NOT NULL DEFAULT 1','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_shops' AND column_name='webshop_enabled');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Formules : « sélection jusqu'à N » + food-cost par choix.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_bundle_slots ADD COLUMN kind VARCHAR(8) NOT NULL DEFAULT ''single''','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_bundle_slots' AND column_name='kind');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_bundle_slots ADD COLUMN min_select INT NOT NULL DEFAULT 1','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_bundle_slots' AND column_name='min_select');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_bundle_slots ADD COLUMN max_select INT NOT NULL DEFAULT 1','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_bundle_slots' AND column_name='max_select');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_bundle_slot_choices ADD COLUMN cost DECIMAL(10,2) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_bundle_slot_choices' AND column_name='cost');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
