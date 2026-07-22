-- 0017b — Reprise (sans perte) des CP « zone secondaire » vers ws_tour_postcodes.
-- À jouer À LA MAIN (hors migrate.sh) : recopie les codes postaux des zones
-- actuellement rattachées aux tournées (ws_tour_zones → ws_delivery_zones.postcodes)
-- vers le nouveau modèle ws_tour_postcodes, AVANT de retirer les zones secondaires.
-- Le format des CP est « 1000 · 1020 · 1030 » (séparateurs ·, /, espaces).
-- Sûr à rejouer (INSERT IGNORE). Requiert MySQL 8 (JSON_TABLE).
INSERT IGNORE INTO ws_tour_postcodes (tour_id, postcode)
SELECT tz.tour_id, jt.cp
FROM ws_tour_zones tz
JOIN ws_delivery_zones z ON z.id = tz.zone_id
JOIN JSON_TABLE(
  CONCAT('["',
    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(z.postcodes,' ',''), CHAR(160),''), '·','","'), '/','","'), ',','","'),
  '"]'),
  '$[*]' COLUMNS (cp VARCHAR(10) PATH '$')
) jt
WHERE z.postcodes IS NOT NULL AND z.postcodes <> ''
  AND jt.cp REGEXP '^[0-9]{4}$';
