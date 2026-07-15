-- ============================================================================
-- apply-clientb2b-server.sql  —  À EXÉCUTER sur atelierby_db
-- (phpMyAdmin → base atelierby_db → onglet SQL → coller → Exécuter)
--
-- PRÉREQUIS : alter-merge-clientb2b-into-sites.sql déjà appliqué
--   (colonne client_id + UNIQUE(client_id), office_client_id/name nullables,
--    FK shop_id supprimée, table ws_clientb2bdelivery supprimée).
--
-- Fait, sur ws_office_delivery_sites, pour les LIGNES ERP (client_id NOT NULL) :
--   roster backfill (avec nom/compagnie/contact)  →  top-5 par magasin  →  triggers.
-- Les sites webshop (client_id NULL) ne sont JAMAIS touchés.
-- Mapping : name = compagnie (company_name) ; contact_name = personne (name surname).
-- ============================================================================

-- 1) Roster backfill — clients B2B, avec nom/compagnie/contact/adresse.
INSERT INTO ws_office_delivery_sites
  (client_id, shop_id, active, name, contact_name, address, contact_phone)
SELECT c.id, c.id_main_shop, 0,
       LEFT(COALESCE(NULLIF(TRIM(c.company_name),''), NULLIF(TRIM(CONCAT_WS(' ', c.name, c.surname)),'')), 120),
       LEFT(NULLIF(TRIM(CONCAT_WS(' ', c.name, c.surname)),''), 120),
       LEFT(NULLIF(TRIM(CONCAT_WS(', ',
              NULLIF(TRIM(CONCAT_WS(' ', c.street, c.street_number)),''),
              NULLIF(TRIM(CONCAT_WS(' ', c.zip, c.city)),''))),''), 250),
       LEFT(NULLIF(TRIM(c.phone),''), 30)
  FROM client c
 WHERE c.is_b2b = 1
   AND c.tax_number IS NOT NULL AND c.tax_number <> ''
ON DUPLICATE KEY UPDATE
  shop_id       = VALUES(shop_id),
  name          = VALUES(name),
  contact_name  = VALUES(contact_name),
  address       = VALUES(address),
  contact_phone = VALUES(contact_phone);

UPDATE ws_office_delivery_sites j
  LEFT JOIN client c
    ON c.id = j.client_id AND c.is_b2b = 1
   AND c.tax_number IS NOT NULL AND c.tax_number <> ''
   SET j.active = 0
 WHERE j.client_id IS NOT NULL AND c.id IS NULL;

-- 2) Sélection TOP-5 par magasin (par nb de commandes dans ce magasin, client_order).
UPDATE ws_office_delivery_sites j
LEFT JOIN (
  SELECT client_id FROM (
    SELECT j.client_id,
           ROW_NUMBER() OVER (
             PARTITION BY j.shop_id
             ORDER BY COALESCE(o.cnt, 0) DESC, j.client_id
           ) AS rn
    FROM ws_office_delivery_sites j
    LEFT JOIN (
      SELECT id_client, id_shop, COUNT(*) AS cnt
      FROM   client_order
      GROUP BY id_client, id_shop
    ) o ON o.id_client = j.client_id AND o.id_shop = j.shop_id
    WHERE j.client_id IS NOT NULL
  ) ranked
  WHERE rn <= 5
) top5 ON top5.client_id = j.client_id
SET j.active = IF(top5.client_id IS NULL, 0, 1)
WHERE j.client_id IS NOT NULL;

-- 3) Triggers temps-réel sur `client` (roster + nom/compagnie ; jamais active=1).
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

-- Vérif : SELECT shop_id, client_id, name, contact_name, active
--         FROM ws_office_delivery_sites WHERE client_id IS NOT NULL ORDER BY shop_id;
