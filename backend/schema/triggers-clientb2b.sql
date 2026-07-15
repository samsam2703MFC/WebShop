-- ============================================================================
-- triggers-clientb2b.sql
-- Keep ws_clientb2b in sync IN REAL TIME: triggers on the ERP `client` table
-- maintain the join row on every INSERT / UPDATE / DELETE — no cron latency.
--
-- A client qualifies as B2B when: is_b2b = 1 AND tax_number is set (non-empty).
-- The triggers only ever write ws_clientb2b (a webshop table) — never `client`.
-- route_id is assigned webshop-side and is NEVER touched here.
--
-- ⚠️  Triggers are lost if the ERP DROPs/recreates the `client` table on a full
--     re-import. Keep alter-clientb2b.sql's bulk upsert as a backfill/safety
--     re-sync (run it once after import, and optionally on a nightly cron).
--
-- Run once in phpMyAdmin (it handles DELIMITER) or: mysql atelierby_db < triggers-clientb2b.sql
-- ============================================================================

DROP TRIGGER IF EXISTS trg_client_b2b_ai;
DROP TRIGGER IF EXISTS trg_client_b2b_au;
DROP TRIGGER IF EXISTS trg_client_b2b_ad;

DELIMITER //

-- INSERT: add the client to the join when it qualifies.
CREATE TRIGGER trg_client_b2b_ai AFTER INSERT ON client
FOR EACH ROW
BEGIN
  IF NEW.is_b2b = 1 AND NEW.tax_number IS NOT NULL AND NEW.tax_number <> '' THEN
    INSERT INTO ws_clientb2b (client_id, shop_id, active)
    VALUES (NEW.id, NEW.id_main_shop, 1)
    ON DUPLICATE KEY UPDATE shop_id = VALUES(shop_id), active = 1;
  END IF;
END//

-- UPDATE: re-evaluate. Still B2B → upsert active; no longer B2B → deactivate
-- (route_id preserved either way).
CREATE TRIGGER trg_client_b2b_au AFTER UPDATE ON client
FOR EACH ROW
BEGIN
  IF NEW.is_b2b = 1 AND NEW.tax_number IS NOT NULL AND NEW.tax_number <> '' THEN
    INSERT INTO ws_clientb2b (client_id, shop_id, active)
    VALUES (NEW.id, NEW.id_main_shop, 1)
    ON DUPLICATE KEY UPDATE shop_id = VALUES(shop_id), active = 1;
  ELSE
    UPDATE ws_clientb2b SET active = 0 WHERE client_id = NEW.id;
  END IF;
END//

-- DELETE: soft-deactivate the join row (keeps route assignment for audit).
CREATE TRIGGER trg_client_b2b_ad AFTER DELETE ON client
FOR EACH ROW
BEGIN
  UPDATE ws_clientb2b SET active = 0 WHERE client_id = OLD.id;
END//

DELIMITER ;
