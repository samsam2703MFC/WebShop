-- 0018 — Forfait de livraison & véhicule sur ws_tours (éditables depuis la
-- Console franchisé, formulaire Tournée). Le départ et les jours restent dans
-- ws_tour_availability. Idempotent MySQL 8 (garde via information_schema).
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_tours ADD COLUMN delivery_fee DECIMAL(8,2) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_tours' AND column_name='delivery_fee');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_tours ADD COLUMN vehicle VARCHAR(60) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_tours' AND column_name='vehicle');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
