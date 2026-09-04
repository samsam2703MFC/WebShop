-- 0123 — POSITION GOOGLE DES BUREAUX : chaque bureau, comme chaque site, porte
-- la position choisie dans la liste Google. Sans elle, deux bureaux d'un même
-- site à des adresses différentes n'avaient qu'un seul trajet et qu'un seul
-- temps d'accès : l'itinéraire ne passait pas par le second.
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_offices ADD COLUMN latitude DECIMAL(10,7) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_offices' AND column_name='latitude');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_offices ADD COLUMN longitude DECIMAL(10,7) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_offices' AND column_name='longitude');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_offices ADD COLUMN google_place_id VARCHAR(160) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_offices' AND column_name='google_place_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_offices ADD COLUMN google_formatted_address VARCHAR(255) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_offices' AND column_name='google_formatted_address');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
