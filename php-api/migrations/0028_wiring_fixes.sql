-- 0028 — Audit câblage create/update (Offices, Clients, Tournées).
--   ws_offices.delivery_notes    : « Consignes de livraison » du form office —
--                                  le champ existait à l'écran mais n'était
--                                  stocké nulle part.
--   ws_tours.return_to_depot     : toggle « retour dépôt » du form tournée —
--                                  perdu au reload (GET renvoyait true en dur).
--   ws_tour_closures.closure_type: Férié / Congé / Technique — perdu au
--                                  reload (GET renvoyait « Fermeture » en dur).
-- Idempotent MySQL 8 (patron 0020).
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_offices ADD COLUMN delivery_notes VARCHAR(500) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_offices' AND column_name='delivery_notes');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_tours ADD COLUMN return_to_depot TINYINT(1) NOT NULL DEFAULT 1','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_tours' AND column_name='return_to_depot');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_tour_closures ADD COLUMN closure_type VARCHAR(20) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_tour_closures' AND column_name='closure_type');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
