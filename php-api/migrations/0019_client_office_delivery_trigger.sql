-- 0019 — Règle métier : tout bureau B2B (ws_offices) PROVIENT d'un client.
-- Un WS_OFFICE ne doit exister que s'il découle d'un enregistrement `client`
-- marqué is_b2b = 1 ET office_delivery = 1. Le shop (id_shop) du client est
-- déduit du code postal (ou saisi à la main) — voir zip_shop() côté API.
-- Mécanisme : un trigger sur client.office_delivery crée / active le ws_offices
-- correspondant (lien de provenance ws_offices.client_id).
-- Idempotent MySQL 8. migrate.sh applique le fichier via `mysql < fichier`,
-- donc DELIMITER est bien interprété par le client mysql.

-- 1) client.is_b2b (booléen) — créé s'il n'existe pas déjà.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE client ADD COLUMN is_b2b TINYINT(1) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='client' AND column_name='is_b2b');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) client.office_delivery (booléen true/false) — créé s'il n'existe pas déjà.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE client ADD COLUMN office_delivery TINYINT(1) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='client' AND column_name='office_delivery');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3) Provenance : ws_offices.client_id (origine ERP) + unicité (upsert du trigger).
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_offices ADD COLUMN client_id INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_offices' AND column_name='client_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_offices ADD UNIQUE KEY uq_ws_offices_client (client_id)','DO 0')
  FROM information_schema.statistics WHERE table_schema=DATABASE()
   AND table_name='ws_offices' AND index_name='uq_ws_offices_client');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 4) Trigger sur office_delivery : synchronise le ws_offices de provenance.
--    Rejouable : on droppe avant de recréer.
DROP TRIGGER IF EXISTS trg_client_office_delivery_ai;
DROP TRIGGER IF EXISTS trg_client_office_delivery_au;

DELIMITER $$

-- À la création d'un client déjà marqué bureau B2B → crée le ws_offices.
CREATE TRIGGER trg_client_office_delivery_ai AFTER INSERT ON client
FOR EACH ROW
BEGIN
  IF NEW.is_b2b = 1 AND NEW.office_delivery = 1 THEN
    INSERT INTO ws_offices (client_id, name, postal_code, city, email, phone, status, active)
    VALUES (NEW.id,
            COALESCE(NULLIF(TRIM(NEW.name), ''), CONCAT('Client #', NEW.id)),
            NEW.zip, NEW.locality, NEW.email, NEW.phone, 'pending', 1)
    ON DUPLICATE KEY UPDATE active = 1;
  END IF;
END$$

-- Bascule du drapeau office_delivery :
--   0/NULL → 1 (avec is_b2b=1) : crée/active le ws_offices de provenance ;
--   1 → 0 : désactive le ws_offices (il ne « provient » plus d'un bureau actif).
CREATE TRIGGER trg_client_office_delivery_au AFTER UPDATE ON client
FOR EACH ROW
BEGIN
  IF NEW.is_b2b = 1 AND NEW.office_delivery = 1
     AND (OLD.office_delivery IS NULL OR OLD.office_delivery = 0) THEN
    INSERT INTO ws_offices (client_id, name, postal_code, city, email, phone, status, active)
    VALUES (NEW.id,
            COALESCE(NULLIF(TRIM(NEW.name), ''), CONCAT('Client #', NEW.id)),
            NEW.zip, NEW.locality, NEW.email, NEW.phone, 'pending', 1)
    ON DUPLICATE KEY UPDATE active = 1;
  ELSEIF NEW.office_delivery = 0 AND OLD.office_delivery = 1 THEN
    UPDATE ws_offices SET active = 0 WHERE client_id = NEW.id;
  END IF;
END$$

DELIMITER ;
