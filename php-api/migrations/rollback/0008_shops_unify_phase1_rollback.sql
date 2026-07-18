-- ROLLBACK 0008 (Phase 1) — non destructif : ws_shops/lp_shops sont intacts.
-- ⚠️ Ne jouer QUE si les phases 2/2b/3 n'ont pas été appliquées (sinon des FK/vues
--    référencent encore shops). Sécurité : refuse s'il reste des FK vers shops.
SET @fk := (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='shops');
SET @s := IF(@fk=0, 'DROP TABLE IF EXISTS shops', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
-- Si @fk>0 : jouer d'abord les rollbacks des phases 2/2b/3 (backend/schema/rollback-unify-shops.sql).
