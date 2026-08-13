-- 0060 — La boutique porte SA POSITION : shops.lat / shops.lng.
--
-- Les cartes du back-office franchisé plaçaient le dépôt en résolvant le CODE
-- POSTAL dans le référentiel bpost (data/zipcodes_be.json). Ce référentiel ne
-- connaît qu'un centroïde par commune : le pin tombait au milieu du code
-- postal, parfois à plusieurs kilomètres de la boutique. Et sans CP résolu, il
-- tombait sur Bruxelles-centre.
--
-- Ce n'est pas un défaut d'affichage : tous les tracés de tournée partent et
-- reviennent de ce point, et les ETA par arrêt en découlent. Une position
-- fausse fausse toute la planification de la journée.
--
-- La position devient donc une DONNÉE de la boutique, géocodée une fois depuis
-- address_line + zip et conservée. geo_source dit d'où elle vient — on ne
-- présente jamais une approximation comme une adresse exacte :
--   'address'  → géocodage de l'adresse complète (précis)
--   'zip'      → centroïde du code postal (approximatif)
--   'manual'   → saisie à la main (fait foi, jamais écrasée)
--   'failed'   → tentative infructueuse ; geo_at empêche de réessayer en boucle
-- NULL = jamais tenté.

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'shops' AND column_name = 'lat'),
  'DO 0',
  'ALTER TABLE shops ADD COLUMN lat DECIMAL(10,7) NULL DEFAULT NULL COMMENT ''Latitude de la boutique — depart/retour des tournees'''
));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql2 := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'shops' AND column_name = 'lng'),
  'DO 0',
  'ALTER TABLE shops ADD COLUMN lng DECIMAL(10,7) NULL DEFAULT NULL COMMENT ''Longitude de la boutique'''
));
PREPARE st2 FROM @sql2; EXECUTE st2; DEALLOCATE PREPARE st2;

SET @sql3 := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'shops' AND column_name = 'geo_source'),
  'DO 0',
  'ALTER TABLE shops ADD COLUMN geo_source VARCHAR(16) NULL DEFAULT NULL COMMENT ''address | zip | manual | failed — NULL = jamais tente'''
));
PREPARE st3 FROM @sql3; EXECUTE st3; DEALLOCATE PREPARE st3;

SET @sql4 := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'shops' AND column_name = 'geo_at'),
  'DO 0',
  'ALTER TABLE shops ADD COLUMN geo_at DATETIME NULL DEFAULT NULL COMMENT ''Date de la derniere resolution — borne les reessais'''
));
PREPARE st4 FROM @sql4; EXECUTE st4; DEALLOCATE PREPARE st4;

-- Aucune reprise de données ici : rien n'est inventé. Les boutiques sont
-- géocodées à la première lecture de /franchisee/me, depuis leur vraie adresse.
