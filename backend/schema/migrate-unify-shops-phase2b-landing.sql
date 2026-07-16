-- ============================================================================
-- migrate-unify-shops-phase2b-landing.sql  —  PHASE 2b : FK côté LANDING
-- Re-pointe lp_shop_hours / lp_shop_services vers `shops`.
-- ⚠️ Contrairement au côté ws (id conservé), le côté lp CHANGE d'id :
--    lp_shop_hours.shop_id = ancien lp_shops.id  →  doit devenir shops.id
--    (mappé via shops.legacy_lp_id). On REMAPPE les valeurs AVANT de swapper la FK.
-- ⚠️ PRÉREQUIS : Phase 1 jouée. Concerne le MODULE LANDING (coordonner avec ce repo).
-- ============================================================================

START TRANSACTION;

-- Sécurité : toute ligne lp_shop_hours/services doit avoir un shops.legacy_lp_id correspondant.
-- Si l'un de ces COUNT est > 0, NE PAS continuer (une vitrine lp n'a pas été migrée).
SET @miss_h := (SELECT COUNT(*) FROM lp_shop_hours    h WHERE NOT EXISTS
                 (SELECT 1 FROM shops s WHERE s.legacy_lp_id = h.shop_id));
SET @miss_s := (SELECT COUNT(*) FROM lp_shop_services x WHERE NOT EXISTS
                 (SELECT 1 FROM shops s WHERE s.legacy_lp_id = x.shop_id));
-- SELECT @miss_h, @miss_s;   -- doivent être 0 avant de poursuivre.

-- 1) DROP les FK actuelles (vers lp_shops) — noms lus dynamiquement.
DELIMITER //
DROP PROCEDURE IF EXISTS _lp_drop_shop_fks//
CREATE PROCEDURE _lp_drop_shop_fks()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_tbl, v_fk VARCHAR(64);
  DECLARE cur CURSOR FOR
    SELECT k.TABLE_NAME, k.CONSTRAINT_NAME
      FROM information_schema.KEY_COLUMN_USAGE k
     WHERE k.CONSTRAINT_SCHEMA = DATABASE()
       AND k.REFERENCED_TABLE_NAME = 'lp_shops';
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
  OPEN cur;
  l: LOOP
    FETCH cur INTO v_tbl, v_fk;
    IF done THEN LEAVE l; END IF;
    SET @s = CONCAT('ALTER TABLE `', v_tbl, '` DROP FOREIGN KEY `', v_fk, '`');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END LOOP;
  CLOSE cur;
END//
DELIMITER ;
CALL _lp_drop_shop_fks();
DROP PROCEDURE IF EXISTS _lp_drop_shop_fks;

-- 2) REMAP des valeurs shop_id : ancien lp_shops.id  →  shops.id (via legacy_lp_id).
--    Idempotent : après remap, shop_id = shops.id, et legacy_lp_id != id (lp-only) ou
--    la ligne est déjà mappée ; un 2e passage ne trouve plus de correspondance -> no-op.
UPDATE lp_shop_hours h
  JOIN shops s ON s.legacy_lp_id = h.shop_id
   SET h.shop_id = s.id
 WHERE h.shop_id <> s.id;

UPDATE lp_shop_services x
  JOIN shops s ON s.legacy_lp_id = x.shop_id
   SET x.shop_id = s.id
 WHERE x.shop_id <> s.id;

-- 3) ADD les nouvelles FK vers shops (idempotent : on ne (re)crée que si absente).
DELIMITER //
DROP PROCEDURE IF EXISTS _lp_add_shop_fk//
CREATE PROCEDURE _lp_add_shop_fk(IN tbl VARCHAR(64))
BEGIN
  IF (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=tbl
          AND COLUMN_NAME='shop_id' AND REFERENCED_TABLE_NAME='shops') = 0 THEN
    SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD CONSTRAINT `fk_shops_', tbl,
                    '_shop_id` FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`) ON DELETE CASCADE');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END//
DELIMITER ;
CALL _lp_add_shop_fk('lp_shop_hours');
CALL _lp_add_shop_fk('lp_shop_services');
DROP PROCEDURE IF EXISTS _lp_add_shop_fk;

COMMIT;

-- Contrôles :
--   SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
--    WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='lp_shops';   -- 0 attendu
--   SELECT h.shop_id, COUNT(*) FROM lp_shop_hours h
--     LEFT JOIN shops s ON s.id=h.shop_id WHERE s.id IS NULL GROUP BY 1;         -- vide attendu
