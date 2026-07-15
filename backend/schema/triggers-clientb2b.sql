-- ============================================================================
-- triggers-clientb2b.sql
-- Real-time roster of the ERP-client rows in ws_office_delivery_sites, from the
-- ERP `client` table (INSERT / UPDATE / DELETE). B2B = is_b2b=1 AND tax_number set.
-- Copies name (=company), contact_name (=person), address, phone.
--
-- `active` is OWNED by the top-5 rule (clientb2b-top5-active.sql), NOT by these
-- triggers: new rows are inserted INACTIVE and updates never force active=1.
-- The triggers only ever write ws_office_delivery_sites — never `client`.
--
-- Run once in phpMyAdmin (handles DELIMITER) or: mysql atelierby_db < triggers-clientb2b.sql
-- ============================================================================

DROP TRIGGER IF EXISTS trg_client_b2b_ai;
DROP TRIGGER IF EXISTS trg_client_b2b_au;
DROP TRIGGER IF EXISTS trg_client_b2b_ad;

DELIMITER //

CREATE TRIGGER trg_client_b2b_ai AFTER INSERT ON client
FOR EACH ROW
BEGIN
  IF NEW.is_b2b = 1 AND NEW.tax_number IS NOT NULL AND NEW.tax_number <> '' THEN
    INSERT INTO ws_office_delivery_sites
      (client_id, shop_id, active, name, contact_name, address, contact_phone)
    VALUES (NEW.id, NEW.id_main_shop, 0,
      LEFT(COALESCE(NULLIF(TRIM(NEW.company_name),''), NULLIF(TRIM(CONCAT_WS(' ', NEW.name, NEW.surname)),'')), 120),
      LEFT(NULLIF(TRIM(CONCAT_WS(' ', NEW.name, NEW.surname)),''), 120),
      LEFT(NULLIF(TRIM(CONCAT_WS(', ',
            NULLIF(TRIM(CONCAT_WS(' ', NEW.street, NEW.street_number)),''),
            NULLIF(TRIM(CONCAT_WS(' ', NEW.zip, NEW.city)),''))),''), 250),
      LEFT(NULLIF(TRIM(NEW.phone),''), 30))
    ON DUPLICATE KEY UPDATE
      shop_id = VALUES(shop_id), name = VALUES(name), contact_name = VALUES(contact_name),
      address = VALUES(address), contact_phone = VALUES(contact_phone);
  END IF;
END//

CREATE TRIGGER trg_client_b2b_au AFTER UPDATE ON client
FOR EACH ROW
BEGIN
  IF NEW.is_b2b = 1 AND NEW.tax_number IS NOT NULL AND NEW.tax_number <> '' THEN
    INSERT INTO ws_office_delivery_sites
      (client_id, shop_id, active, name, contact_name, address, contact_phone)
    VALUES (NEW.id, NEW.id_main_shop, 0,
      LEFT(COALESCE(NULLIF(TRIM(NEW.company_name),''), NULLIF(TRIM(CONCAT_WS(' ', NEW.name, NEW.surname)),'')), 120),
      LEFT(NULLIF(TRIM(CONCAT_WS(' ', NEW.name, NEW.surname)),''), 120),
      LEFT(NULLIF(TRIM(CONCAT_WS(', ',
            NULLIF(TRIM(CONCAT_WS(' ', NEW.street, NEW.street_number)),''),
            NULLIF(TRIM(CONCAT_WS(' ', NEW.zip, NEW.city)),''))),''), 250),
      LEFT(NULLIF(TRIM(NEW.phone),''), 30))
    ON DUPLICATE KEY UPDATE
      shop_id = VALUES(shop_id), name = VALUES(name), contact_name = VALUES(contact_name),
      address = VALUES(address), contact_phone = VALUES(contact_phone);
  ELSE
    UPDATE ws_office_delivery_sites SET active = 0 WHERE client_id = NEW.id;
  END IF;
END//

CREATE TRIGGER trg_client_b2b_ad AFTER DELETE ON client
FOR EACH ROW
BEGIN
  UPDATE ws_office_delivery_sites SET active = 0 WHERE client_id = OLD.id;
END//

DELIMITER ;
