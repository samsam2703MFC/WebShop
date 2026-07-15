-- ============================================================================
-- apply-clientb2b-server.sql  —  À EXÉCUTER UNE FOIS sur atelierby_db
-- (phpMyAdmin → base atelierby_db → onglet SQL → coller → Exécuter ;
--  ou en SSH :  mysql -usam -p atelierby_db < apply-clientb2b-server.sql)
--
-- Contient TOUT pour ws_clientb2b : la table + les triggers temps-réel + le
-- backfill initial. Après ça, la join se met à jour toute seule à chaque
-- INSERT/UPDATE/DELETE sur `client`. (Le cron n'est qu'un filet de sécurité.)
-- ============================================================================

-- 1) La table join (idempotent) --------------------------------------------
CREATE TABLE IF NOT EXISTS ws_clientb2b (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  client_id  INT NOT NULL,          -- ERP client.id (is_b2b=1 + tax_number)
  route_id   INT,                   -- ws_tours.id — assigné côté webshop, jamais écrasé par la synchro
  shop_id    INT,                   -- client.id_main_shop
  active     BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_clientb2b_client (client_id),
  KEY idx_clientb2b_route (route_id),
  FOREIGN KEY (route_id) REFERENCES ws_tours(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Backfill initial (idempotent) — B2B = is_b2b=1 ET tax_number renseigné --
INSERT INTO ws_clientb2b (client_id, shop_id, active)
SELECT c.id, c.id_main_shop, 1
  FROM client c
 WHERE c.is_b2b = 1
   AND c.tax_number IS NOT NULL AND c.tax_number <> ''
ON DUPLICATE KEY UPDATE shop_id = VALUES(shop_id), active = 1;

UPDATE ws_clientb2b j
  LEFT JOIN client c
    ON c.id = j.client_id AND c.is_b2b = 1
   AND c.tax_number IS NOT NULL AND c.tax_number <> ''
   SET j.active = 0
 WHERE c.id IS NULL;

-- 3) Triggers temps-réel sur `client` (ré-exécutables) ----------------------
DROP TRIGGER IF EXISTS trg_client_b2b_ai;
DROP TRIGGER IF EXISTS trg_client_b2b_au;
DROP TRIGGER IF EXISTS trg_client_b2b_ad;

DELIMITER //
CREATE TRIGGER trg_client_b2b_ai AFTER INSERT ON client
FOR EACH ROW
BEGIN
  IF NEW.is_b2b = 1 AND NEW.tax_number IS NOT NULL AND NEW.tax_number <> '' THEN
    INSERT INTO ws_clientb2b (client_id, shop_id, active)
    VALUES (NEW.id, NEW.id_main_shop, 1)
    ON DUPLICATE KEY UPDATE shop_id = VALUES(shop_id), active = 1;
  END IF;
END//
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
CREATE TRIGGER trg_client_b2b_ad AFTER DELETE ON client
FOR EACH ROW
BEGIN
  UPDATE ws_clientb2b SET active = 0 WHERE client_id = OLD.id;
END//
DELIMITER ;

-- Vérif : SELECT * FROM ws_clientb2b;
