-- ============================================================================
-- rollback-unify-shops.sql
-- Annule la migration d'unification des boutiques, PAR PHASE, en ordre INVERSE.
-- Rejouer la ou les sections correspondant à la dernière phase atteinte.
-- Tant que la Phase 4 (drop legacy) n'a PAS été jouée, tout rollback est complet.
-- ============================================================================

-- ── Rollback PHASE 3 (vues de compat) — si Phase 3 a été jouée ──────────────
-- On supprime les vues et on restaure les tables d'origine.
DROP VIEW IF EXISTS ws_shops;
DROP VIEW IF EXISTS lp_shops;
-- (ne rejouer les RENAME que si Phase 3 avait bien renommé les tables)
--   RENAME TABLE ws_shops_legacy TO ws_shops;
--   RENAME TABLE lp_shops_legacy TO lp_shops;

-- ── Rollback PHASE 2b (FK landing) — si Phase 2b a été jouée ────────────────
-- 1) DROP les FK vers shops ; 2) REMAP shop_id shops.id -> ancien lp_shops.id
--    (via legacy_lp_id) ; 3) recréer les FK vers lp_shops.
START TRANSACTION;
ALTER TABLE lp_shop_hours    DROP FOREIGN KEY fk_shops_lp_shop_hours_shop_id;
ALTER TABLE lp_shop_services DROP FOREIGN KEY fk_shops_lp_shop_services_shop_id;
UPDATE lp_shop_hours h    JOIN shops s ON s.id = h.shop_id AND s.legacy_lp_id IS NOT NULL
   SET h.shop_id = s.legacy_lp_id WHERE h.shop_id <> s.legacy_lp_id;
UPDATE lp_shop_services x JOIN shops s ON s.id = x.shop_id AND s.legacy_lp_id IS NOT NULL
   SET x.shop_id = s.legacy_lp_id WHERE x.shop_id <> s.legacy_lp_id;
-- (recréer les FK vers lp_shops seulement si lp_shops est de nouveau une table — cf. Phase 3 rollback)
--   ALTER TABLE lp_shop_hours    ADD FOREIGN KEY (shop_id) REFERENCES lp_shops(id) ON DELETE CASCADE;
--   ALTER TABLE lp_shop_services ADD FOREIGN KEY (shop_id) REFERENCES lp_shops(id) ON DELETE CASCADE;
COMMIT;

-- ── Rollback PHASE 2 (FK ws) — si Phase 2 a été jouée ──────────────────────
-- Repointe les FK fk_shops_* des 21 tables ws_ de shops vers ws_shops.
-- (les valeurs shop_id sont inchangées car shops.id == ws_shops.id)
DELIMITER //
DROP PROCEDURE IF EXISTS _ws_rollback_shop_fks//
CREATE PROCEDURE _ws_rollback_shop_fks()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_tbl, v_col, v_fk VARCHAR(64);
  DECLARE cur CURSOR FOR
    SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME
      FROM information_schema.KEY_COLUMN_USAGE k
     WHERE k.CONSTRAINT_SCHEMA = DATABASE()
       AND k.REFERENCED_TABLE_NAME = 'shops'
       AND k.TABLE_NAME LIKE 'ws\_%';
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
  OPEN cur;
  l: LOOP
    FETCH cur INTO v_tbl, v_col, v_fk;
    IF done THEN LEAVE l; END IF;
    SET @s = CONCAT('ALTER TABLE `', v_tbl, '` DROP FOREIGN KEY `', v_fk, '`');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    SET @s = CONCAT('ALTER TABLE `', v_tbl, '` ADD FOREIGN KEY (`', v_col,
                    '`) REFERENCES `ws_shops`(`id`)');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END LOOP;
  CLOSE cur;
END//
DELIMITER ;
-- CALL _ws_rollback_shop_fks();   -- décommenter si ws_shops est de nouveau une table
DROP PROCEDURE IF EXISTS _ws_rollback_shop_fks;

-- ── Rollback PHASE 1 (create + populate `shops`) ────────────────────────────
-- Non destructif (ws_shops / lp_shops intacts) : supprimer la table cible.
-- ⚠️ Ne jouer qu'après avoir annulé Phases 2/2b/3 (sinon des FK pointent encore sur shops).
DROP TABLE IF EXISTS shops;

-- NB : les anciennes tables ne sont PHYSIQUEMENT supprimées qu'en Phase 4 (migration
-- séparée). Tant que Phase 4 n'est pas jouée, tout rollback ci-dessus est complet et sûr.
