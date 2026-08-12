-- 0035 — Envoi des tournées vers la tablette chauffeur + validation.
-- ws_tour_tracking : créée si absente ; sinon on ajoute les colonnes
-- d'envoi (dispatched_at) et de validation chauffeur (driver_validated_at).
-- Idempotent (patron 0020).
CREATE TABLE IF NOT EXISTS ws_tour_tracking (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tour_id INT NOT NULL,
  driver_name VARCHAR(120) NULL,
  vehicle VARCHAR(80) NULL,
  stops_done INT NOT NULL DEFAULT 0,
  stops_total INT NOT NULL DEFAULT 0,
  dispatched_at DATETIME NULL,
  driver_validated_at DATETIME NULL,
  UNIQUE KEY uniq_tour (tour_id)
);

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_tour_tracking ADD COLUMN dispatched_at DATETIME NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_tour_tracking' AND column_name='dispatched_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_tour_tracking ADD COLUMN driver_validated_at DATETIME NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_tour_tracking' AND column_name='driver_validated_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
