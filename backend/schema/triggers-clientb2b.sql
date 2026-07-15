-- ============================================================================
-- triggers-clientb2b.sql
-- Keep the ws_office_delivery_sites ROSTER in sync with the ERP `client` table in real time
-- (INSERT / UPDATE / DELETE). B2B = is_b2b = 1 AND tax_number set (non-empty).
--
-- IMPORTANT: `active` is OWNED by the top-5 rule (clientb2b-top5-active.sql),
-- NOT by these triggers. So the triggers only:
--   • add a new B2B client to the roster as INACTIVE (the top-5 recompute enables winners),
--   • sync shop_id, WITHOUT ever forcing active back to 1,
--   • deactivate a client that is no longer B2B / was deleted.
-- The triggers only ever write ws_office_delivery_sites — never `client`.
--
-- Run once in phpMyAdmin (handles DELIMITER) or: mysql atelierby_db < triggers-clientb2b.sql
-- ============================================================================

DROP TRIGGER IF EXISTS trg_client_b2b_ai;
DROP TRIGGER IF EXISTS trg_client_b2b_au;
DROP TRIGGER IF EXISTS trg_client_b2b_ad;

DELIMITER //

-- INSERT: new B2B client joins the roster INACTIVE (top-5 recompute may enable it).
CREATE TRIGGER trg_client_b2b_ai AFTER INSERT ON client
FOR EACH ROW
BEGIN
  IF NEW.is_b2b = 1 AND NEW.tax_number IS NOT NULL AND NEW.tax_number <> '' THEN
    INSERT INTO ws_office_delivery_sites (client_id, shop_id, active)
    VALUES (NEW.id, NEW.id_main_shop, 0)
    ON DUPLICATE KEY UPDATE shop_id = VALUES(shop_id);   -- ne touche PAS active
  END IF;
END//

-- UPDATE: still B2B → sync shop_id only (active preserved = top-5); else → deactivate.
CREATE TRIGGER trg_client_b2b_au AFTER UPDATE ON client
FOR EACH ROW
BEGIN
  IF NEW.is_b2b = 1 AND NEW.tax_number IS NOT NULL AND NEW.tax_number <> '' THEN
    INSERT INTO ws_office_delivery_sites (client_id, shop_id, active)
    VALUES (NEW.id, NEW.id_main_shop, 0)
    ON DUPLICATE KEY UPDATE shop_id = VALUES(shop_id);   -- active préservé
  ELSE
    UPDATE ws_office_delivery_sites SET active = 0 WHERE client_id = NEW.id;
  END IF;
END//

-- DELETE: soft-deactivate (keeps route assignment for audit).
CREATE TRIGGER trg_client_b2b_ad AFTER DELETE ON client
FOR EACH ROW
BEGIN
  UPDATE ws_office_delivery_sites SET active = 0 WHERE client_id = OLD.id;
END//

DELIMITER ;
