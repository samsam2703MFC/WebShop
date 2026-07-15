-- ============================================================================
-- apply-clientb2b-server.sql  —  À EXÉCUTER UNE FOIS sur atelierby_db
-- (phpMyAdmin → base atelierby_db → onglet SQL → coller → Exécuter ;
--  ou en SSH :  mysql -usam -p atelierby_db < apply-clientb2b-server.sql)
--
-- Contient TOUT pour ws_clientb2b : table + roster backfill + sélection top-5
-- (5 clients par magasin avec le plus de commandes) + triggers temps-réel.
--
-- Modèle de `active` : piloté par la règle TOP-5 (pas par les triggers).
--   • triggers = tiennent le roster (ajout/retrait) sans jamais forcer active=1
--   • top-5    = seul à mettre active=1 pour les gagnants ; à RE-JOUER régulièrement
--                (cron) car le nombre de commandes évolue.
-- ============================================================================

-- 1) La table join (idempotent) --------------------------------------------
CREATE TABLE IF NOT EXISTS ws_clientb2b (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  client_id  INT NOT NULL,          -- ERP client.id (is_b2b=1 + tax_number)
  route_id   INT,                   -- ws_tours.id — assigné côté webshop, jamais écrasé
  shop_id    INT,                   -- client.id_main_shop
  active     BOOLEAN DEFAULT FALSE, -- piloté par la règle top-5
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_clientb2b_client (client_id),
  KEY idx_clientb2b_route (route_id),
  FOREIGN KEY (route_id) REFERENCES ws_tours(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Roster backfill — chaque client B2B (is_b2b=1 ET tax_number) entre dans la join.
--    (active laissé à la charge du top-5 juste après ; route_id préservé.)
INSERT INTO ws_clientb2b (client_id, shop_id, active)
SELECT c.id, c.id_main_shop, 0
  FROM client c
 WHERE c.is_b2b = 1
   AND c.tax_number IS NOT NULL AND c.tax_number <> ''
ON DUPLICATE KEY UPDATE shop_id = VALUES(shop_id);

-- Retirer du "actif" ceux qui ne sont plus B2B (roster garde la ligne, active=0).
UPDATE ws_clientb2b j
  LEFT JOIN client c
    ON c.id = j.client_id AND c.is_b2b = 1
   AND c.tax_number IS NOT NULL AND c.tax_number <> ''
   SET j.active = 0
 WHERE c.id IS NULL;

-- 3) Sélection TOP-5 par magasin (par nb de commandes dans ce magasin) -------
--    Actif = uniquement les 5 clients avec le plus de commandes par shop_id.
UPDATE ws_clientb2b j
LEFT JOIN (
  SELECT client_id FROM (
    SELECT j.client_id,
           ROW_NUMBER() OVER (
             PARTITION BY j.shop_id
             ORDER BY COALESCE(o.cnt, 0) DESC, j.client_id
           ) AS rn
    FROM ws_clientb2b j
    LEFT JOIN (
      SELECT id_client, id_shop, COUNT(*) AS cnt
      FROM   client_order
      GROUP BY id_client, id_shop
    ) o ON o.id_client = j.client_id AND o.id_shop = j.shop_id
  ) ranked
  WHERE rn <= 5
) top5 ON top5.client_id = j.client_id
SET j.active = IF(top5.client_id IS NULL, 0, 1);

-- 4) Triggers temps-réel (roster only — ne forcent JAMAIS active=1) ----------
DROP TRIGGER IF EXISTS trg_client_b2b_ai;
DROP TRIGGER IF EXISTS trg_client_b2b_au;
DROP TRIGGER IF EXISTS trg_client_b2b_ad;

DELIMITER //
CREATE TRIGGER trg_client_b2b_ai AFTER INSERT ON client
FOR EACH ROW
BEGIN
  IF NEW.is_b2b = 1 AND NEW.tax_number IS NOT NULL AND NEW.tax_number <> '' THEN
    INSERT INTO ws_clientb2b (client_id, shop_id, active)
    VALUES (NEW.id, NEW.id_main_shop, 0)
    ON DUPLICATE KEY UPDATE shop_id = VALUES(shop_id);
  END IF;
END//
CREATE TRIGGER trg_client_b2b_au AFTER UPDATE ON client
FOR EACH ROW
BEGIN
  IF NEW.is_b2b = 1 AND NEW.tax_number IS NOT NULL AND NEW.tax_number <> '' THEN
    INSERT INTO ws_clientb2b (client_id, shop_id, active)
    VALUES (NEW.id, NEW.id_main_shop, 0)
    ON DUPLICATE KEY UPDATE shop_id = VALUES(shop_id);
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

-- Vérif : SELECT shop_id, COUNT(*) FROM ws_clientb2b WHERE active=1 GROUP BY shop_id;  -- <= 5 par magasin
