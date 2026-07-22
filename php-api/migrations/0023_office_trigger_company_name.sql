-- 0023 — Le bureau créé par le trigger porte la RAISON SOCIALE.
-- La table client possède une colonne dédiée company_name : le nom du bureau
-- (ws_offices.name) la prend en priorité, puis name (contact), puis un repli.
-- (Avant : name seul → un client existant donnait « Verheyden » au lieu de la
-- société saisie dans le formulaire.)
-- Idempotent : DROP + CREATE.
DROP TRIGGER IF EXISTS trg_client_office_delivery_ai;
DROP TRIGGER IF EXISTS trg_client_office_delivery_au;

DELIMITER $$

CREATE TRIGGER trg_client_office_delivery_ai AFTER INSERT ON client
FOR EACH ROW
BEGIN
  IF NEW.is_b2b = 1 AND NEW.office_delivery = 1 AND NEW.id_main_shop > 0 THEN
    INSERT INTO ws_offices (client_id, shop_id, name, postal_code, city, email, phone, status, active)
    VALUES (NEW.id, NEW.id_main_shop,
            COALESCE(NULLIF(TRIM(NEW.company_name), ''), NULLIF(TRIM(NEW.name), ''), CONCAT('Client #', NEW.id)),
            NEW.zip, NEW.locality, NEW.email, NEW.phone, 'pending', 1)
    ON DUPLICATE KEY UPDATE active = 1, shop_id = NEW.id_main_shop,
      name = COALESCE(NULLIF(TRIM(NEW.company_name), ''), ws_offices.name);
  END IF;
END$$

CREATE TRIGGER trg_client_office_delivery_au AFTER UPDATE ON client
FOR EACH ROW
BEGIN
  IF NEW.is_b2b = 1 AND NEW.office_delivery = 1 AND NEW.id_main_shop > 0
     AND (   OLD.office_delivery IS NULL OR OLD.office_delivery = 0
          OR OLD.id_main_shop IS NULL OR OLD.id_main_shop = 0
          OR OLD.id_main_shop <> NEW.id_main_shop) THEN
    INSERT INTO ws_offices (client_id, shop_id, name, postal_code, city, email, phone, status, active)
    VALUES (NEW.id, NEW.id_main_shop,
            COALESCE(NULLIF(TRIM(NEW.company_name), ''), NULLIF(TRIM(NEW.name), ''), CONCAT('Client #', NEW.id)),
            NEW.zip, NEW.locality, NEW.email, NEW.phone, 'pending', 1)
    ON DUPLICATE KEY UPDATE active = 1, shop_id = NEW.id_main_shop,
      name = COALESCE(NULLIF(TRIM(NEW.company_name), ''), ws_offices.name);
  ELSEIF NEW.office_delivery = 0 AND OLD.office_delivery = 1 THEN
    UPDATE ws_offices SET active = 0 WHERE client_id = NEW.id;
  END IF;
END$$

DELIMITER ;
