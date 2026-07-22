-- 0024 — Chaque site (building) doit être rattaché à une tournée (point 1 du
-- fil d'ariane) : les sites sans tournee_id héritent de la tournée de leur
-- bureau (ws_offices.tour_id). Les lignes sans bureau ou dont le bureau n'a
-- pas de tournée restent NULL (à régler à la main dans le BO).
-- Idempotent : ne touche que les NULL ; rejouable sans effet.
UPDATE ws_office_delivery_sites s
  JOIN ws_offices o ON o.id = s.office_client_id
   SET s.tournee_id = o.tour_id
 WHERE s.tournee_id IS NULL AND o.tour_id IS NOT NULL;
