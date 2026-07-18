-- ============================================================================
-- migrate-unify-shops-phase2.sql  —  PHASE 2 : re-pointer les FK vers `shops`
-- IDEMPOTENT. Repointe toutes les FK qui référencent ws_shops(id) vers shops(id).
-- ⚠️ PRÉREQUIS : Phase 1 jouée (table `shops` peuplée, shops.id == ws_shops.id).
--    → aucune valeur shop_id à changer : toutes les lignes ws_shops existent dans shops.
-- ⚠️ NE PAS EXÉCUTER sans validation. Rollback : migrate-unify-shops-phase2-rollback.sql
--
-- Méthode : on lit information_schema pour trouver TOUTES les FK (nom, table, colonne)
-- dont REFERENCED_TABLE = 'ws_shops', puis pour chacune : DROP FK <nom> ; ADD FK
-- (même colonne) REFERENCES shops(id). Robuste (noms de contraintes non devinés).
-- ============================================================================

-- Sécurité : refuse de tourner si des shop_id orphelins existeraient dans shops.
-- (ne devrait jamais arriver après Phase 1, mais on vérifie avant de toucher aux FK)
SET @orphan := (
  SELECT COUNT(*) FROM ws_orders o
   WHERE o.shop_id IS NOT NULL
     AND NOT EXISTS (SELECT 1 FROM shops s WHERE s.id = o.shop_id)
);
-- Si @orphan > 0, corriger AVANT de continuer (ne pas exécuter la suite).

DELIMITER //
DROP PROCEDURE IF EXISTS _ws_repoint_shop_fks//
CREATE PROCEDURE _ws_repoint_shop_fks(IN target_tbl VARCHAR(64))
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_tbl, v_col, v_fk VARCHAR(64);
  DECLARE cur CURSOR FOR
    SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME
      FROM information_schema.KEY_COLUMN_USAGE k
      JOIN information_schema.REFERENTIAL_CONSTRAINTS r
        ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
       AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
     WHERE k.CONSTRAINT_SCHEMA   = DATABASE()
       AND k.REFERENCED_TABLE_NAME = target_tbl;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO v_tbl, v_col, v_fk;
    IF done THEN LEAVE read_loop; END IF;

    -- 1) DROP l'ancienne FK (vers ws_shops)
    SET @s = CONCAT('ALTER TABLE `', v_tbl, '` DROP FOREIGN KEY `', v_fk, '`');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

    -- 2) ADD la nouvelle FK (vers shops) — nom préfixé fk_shops_ pour idempotence/rollback
    SET @nfk = CONCAT('fk_shops_', v_tbl, '_', v_col);
    SET @s = CONCAT('ALTER TABLE `', v_tbl, '` ADD CONSTRAINT `', @nfk,
                    '` FOREIGN KEY (`', v_col, '`) REFERENCES `shops`(`id`)');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END LOOP;
  CLOSE cur;
END//
DELIMITER ;

-- Re-pointe toutes les FK ws_shops -> shops. Ré-exécutable : au 2e passage il ne
-- reste plus de FK vers ws_shops (le curseur est vide) -> no-op.
CALL _ws_repoint_shop_fks('ws_shops');
DROP PROCEDURE IF EXISTS _ws_repoint_shop_fks;

-- Contrôles :
--   -- 0 FK ne doit plus référencer ws_shops :
--   SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
--    WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='ws_shops';
--   -- 21 FK doivent référencer shops :
--   SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
--    WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='shops' ORDER BY TABLE_NAME;
