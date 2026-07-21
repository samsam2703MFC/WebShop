-- 0014 — Zones de chalandise (primaires, franchiseur) & zoning franchisé.
-- • ws_franchisor_catchment.shop_id : la zone primaire appartient à un point
--   de vente (ex. shop 2) ; NULL = territoire marque non affecté.
-- • ws_delivery_zones : + shop_id (zone du franchisé), postcodes (codes
--   postaux « 1000 · 1020 »), zone_type (primary|secondary — les zones
--   franchisé sont secondaires), catchment_id (zone primaire parente).
-- • ws_tour_zones : n zones secondaires par tournée (zone_id de ws_tours
--   reste la zone principale historique).
-- Idempotent MySQL 8.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_franchisor_catchment ADD COLUMN shop_id INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_franchisor_catchment' AND column_name='shop_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_delivery_zones ADD COLUMN shop_id INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_delivery_zones' AND column_name='shop_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_delivery_zones ADD COLUMN postcodes TEXT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_delivery_zones' AND column_name='postcodes');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_delivery_zones ADD COLUMN zone_type VARCHAR(10) NOT NULL DEFAULT ''secondary''','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_delivery_zones' AND column_name='zone_type');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_delivery_zones ADD COLUMN catchment_id INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_delivery_zones' AND column_name='catchment_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS ws_tour_zones (
  tour_id INT NOT NULL,
  zone_id INT NOT NULL,
  PRIMARY KEY (tour_id, zone_id),
  FOREIGN KEY (tour_id) REFERENCES ws_tours(id),
  FOREIGN KEY (zone_id) REFERENCES ws_delivery_zones(id)
);
