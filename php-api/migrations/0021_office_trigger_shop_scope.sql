-- 0021 — Affine la règle « WS_OFFICE provient d'un client » (0019) :
--   • un bureau n'est créé QUE si le client est rattaché à un franchisé
--     (id_main_shop > 0). Un prospect (id_main_shop = 0) reste côté
--     franchiseur (menu Prospect) et ne doit apparaître chez AUCUN franchisé ;
--   • le bureau créé porte shop_id = id_main_shop du client → l'écran
--     « Nouveaux bureaux / Validations » du BO franchisé peut être scopé ;
--   • si un prospect est ensuite rattaché (id_main_shop 0 → n), le bureau se
--     crée à ce moment-là.
-- Idempotent MySQL 8 (DROP TRIGGER IF EXISTS + garde information_schema).

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_offices ADD COLUMN shop_id INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_offices' AND column_name='shop_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

DROP TRIGGER IF EXISTS trg_client_office_delivery_ai;
DROP TRIGGER IF EXISTS trg_client_office_delivery_au;

DELIMITER $$

CREATE TRIGGER trg_client_office_delivery_ai AFTER INSERT ON client
FOR EACH ROW
BEGIN
  IF NEW.is_b2b = 1 AND NEW.office_delivery = 1 AND NEW.id_main_shop > 0 THEN
    INSERT INTO ws_offices (client_id, shop_id, name, postal_code, city, email, phone, status, active)
    VALUES (NEW.id, NEW.id_main_shop,
            COALESCE(NULLIF(TRIM(NEW.name), ''), CONCAT('Client #', NEW.id)),
            NEW.zip, NEW.locality, NEW.email, NEW.phone, 'pending', 1)
    ON DUPLICATE KEY UPDATE active = 1, shop_id = NEW.id_main_shop;
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
            COALESCE(NULLIF(TRIM(NEW.name), ''), CONCAT('Client #', NEW.id)),
            NEW.zip, NEW.locality, NEW.email, NEW.phone, 'pending', 1)
    ON DUPLICATE KEY UPDATE active = 1, shop_id = NEW.id_main_shop;
  ELSEIF NEW.office_delivery = 0 AND OLD.office_delivery = 1 THEN
    UPDATE ws_offices SET active = 0 WHERE client_id = NEW.id;
  END IF;
END$$

DELIMITER ;

-- Nettoyage : désactive les bureaux créés par 0019 pour des prospects non
-- rattachés (ils ne doivent vivre que dans le menu Prospect du franchiseur).
UPDATE ws_offices o JOIN client c ON c.id = o.client_id
   SET o.active = 0
 WHERE c.id_main_shop = 0 AND o.status = 'pending';
