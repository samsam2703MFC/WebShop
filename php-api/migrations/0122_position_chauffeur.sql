-- 0122 — POSITION DU CHAUFFEUR (suivi live) : dernière position GPS envoyée
-- par la tablette pour une tournée (POST /franchisee/driver-position).
-- Sans envoi, les colonnes restent NULL et la console n'affiche rien.
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_tour_tracking ADD COLUMN lat DECIMAL(10,7) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_tour_tracking' AND column_name='lat');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_tour_tracking ADD COLUMN lng DECIMAL(10,7) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_tour_tracking' AND column_name='lng');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_tour_tracking ADD COLUMN position_at DATETIME NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_tour_tracking' AND column_name='position_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
