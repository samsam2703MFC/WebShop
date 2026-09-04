-- 0120 — ASSISTANT TOURNÉES : prix au km, itinéraire Google, adresse structurée des sites.
--
-- ws_tours.price_per_km : paramètre de la tournée (€/km) ; coût = km × prix.
-- ws_tours.route_km / route_min / route_at : dernier itinéraire calculé par
-- Google (dépôt → arrêts → dépôt), mémorisé pour l'affichage sans rappel.
-- ws_office_delivery_sites.street / street_number / postal_code / city :
-- composantes rendues par Google à la sélection d'une adresse ; latitude,
-- longitude, google_place_id, geocode_status existent déjà (géocodage).
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_tours ADD COLUMN price_per_km DECIMAL(6,2) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_tours' AND column_name='price_per_km');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_tours ADD COLUMN route_km DECIMAL(8,2) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_tours' AND column_name='route_km');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_tours ADD COLUMN route_min INT NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_tours' AND column_name='route_min');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_tours ADD COLUMN route_at DATETIME NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_tours' AND column_name='route_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_office_delivery_sites ADD COLUMN street VARCHAR(160) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_delivery_sites' AND column_name='street');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_office_delivery_sites ADD COLUMN street_number VARCHAR(20) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_delivery_sites' AND column_name='street_number');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_office_delivery_sites ADD COLUMN postal_code VARCHAR(12) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_delivery_sites' AND column_name='postal_code');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_office_delivery_sites ADD COLUMN city VARCHAR(120) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_delivery_sites' AND column_name='city');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_office_delivery_sites ADD COLUMN latitude DECIMAL(10,7) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_delivery_sites' AND column_name='latitude');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_office_delivery_sites ADD COLUMN longitude DECIMAL(10,7) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_delivery_sites' AND column_name='longitude');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_office_delivery_sites ADD COLUMN google_place_id VARCHAR(160) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_delivery_sites' AND column_name='google_place_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_office_delivery_sites ADD COLUMN google_formatted_address VARCHAR(255) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_delivery_sites' AND column_name='google_formatted_address');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_office_delivery_sites ADD COLUMN geocode_status VARCHAR(20) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_delivery_sites' AND column_name='geocode_status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_office_delivery_sites ADD COLUMN geocoded_at DATETIME NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_delivery_sites' AND column_name='geocoded_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
