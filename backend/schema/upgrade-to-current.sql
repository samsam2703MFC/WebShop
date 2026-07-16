-- ============================================================================
-- upgrade-to-current.sql  —  IDEMPOTENT
-- Amène un ancien schéma ws_ (import du 14/07) au niveau du code actuel :
-- ajoute UNIQUEMENT les colonnes/tables/index manquants. Sûr à ré-exécuter.
-- Ne touche PAS aux tables ERP (client, client_order, client_order_product).
--
-- Exécuter dans phpMyAdmin (onglet SQL sur atelierby_db) ou :
--   mysql -u sam -p atelierby_db < upgrade-to-current.sql
-- ============================================================================

DELIMITER //
DROP PROCEDURE IF EXISTS _ws_addcol//
CREATE PROCEDURE _ws_addcol(IN tbl VARCHAR(64), IN col VARCHAR(64), IN ddl TEXT)
BEGIN
  IF (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = tbl) = 1
     AND (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = col) = 0 THEN
    SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN ', ddl);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END//
DROP PROCEDURE IF EXISTS _ws_addkey//
CREATE PROCEDURE _ws_addkey(IN tbl VARCHAR(64), IN keyname VARCHAR(64), IN ddl TEXT)
BEGIN
  IF (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = tbl) = 1
     AND (SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = tbl AND index_name = keyname) = 0 THEN
    SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD ', ddl);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END//
DELIMITER ;

-- ── Colonnes (remises, notes, invité, B2B, créneaux, auth) ───────────────────
CALL _ws_addcol('ws_shops','webshop_discount_type',"webshop_discount_type VARCHAR(20) DEFAULT 'percent'");
CALL _ws_addcol('ws_shops','webshop_discount_value','webshop_discount_value DECIMAL(10,2) DEFAULT 0');

CALL _ws_addcol('ws_orders','webshop_discount','webshop_discount DECIMAL(10,2) DEFAULT 0');
CALL _ws_addcol('ws_orders','note','note VARCHAR(500)');
CALL _ws_addcol('ws_orders','guest_email','guest_email VARCHAR(200)');
CALL _ws_addcol('ws_orders','guest_name','guest_name VARCHAR(200)');
CALL _ws_addcol('ws_orders','guest_phone','guest_phone VARCHAR(30)');
CALL _ws_addcol('ws_orders','payment_type',"payment_type VARCHAR(20) DEFAULT 'immediate'");

CALL _ws_addcol('ws_order_lines','note','note VARCHAR(255)');

CALL _ws_addcol('ws_offices','deferred_billing_enabled','deferred_billing_enabled BOOLEAN DEFAULT FALSE');
CALL _ws_addcol('ws_offices','contract_url','contract_url VARCHAR(255)');

CALL _ws_addcol('ws_tours','max_items','max_items INT');

CALL _ws_addcol('ws_tour_availability','window_label',"window_label VARCHAR(16) NOT NULL DEFAULT 'morning'");

CALL _ws_addcol('ws_customers','client_id','client_id INT');
CALL _ws_addkey('ws_customers','idx_customers_phone','KEY idx_customers_phone (phone)');
CALL _ws_addkey('ws_customers','idx_customers_client','KEY idx_customers_client (client_id)');

CALL _ws_addcol('ws_office_delivery_sites','client_id','client_id INT');

-- ── Tables ajoutées plus tard (sûr) ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ws_office_emails (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  office_id    INT NOT NULL,
  email        VARCHAR(200) NOT NULL,
  contract_url VARCHAR(255),
  active       BOOLEAN DEFAULT TRUE,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_office_email (office_id, email),
  KEY idx_office_email (email),
  FOREIGN KEY (office_id) REFERENCES ws_offices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_shop_payment_options (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  shop_id      INT NOT NULL,
  profile_type VARCHAR(20) NOT NULL,   -- guest | registered | company
  method       VARCHAR(20) NOT NULL,   -- stripe | shop | deferred
  active       BOOLEAN DEFAULT TRUE,
  UNIQUE KEY uq_shop_payment (shop_id, profile_type, method),
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP PROCEDURE IF EXISTS _ws_addcol;
DROP PROCEDURE IF EXISTS _ws_addkey;

-- NOTE : pour un 2e créneau de livraison (après-midi) par tournée, il faut aussi
-- élargir la clé unique de ws_tour_availability — voir alter-tour-afternoon-window.sql.
