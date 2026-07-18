-- ROLLBACK 0007 — supprime la vue ws_vouchers et restaure la table plate.
-- À jouer AVANT le rollback 0006 (données) si on remonte toute la pile.
SET @is_view := (SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema=DATABASE() AND table_name='ws_vouchers' AND table_type='VIEW');
SET @s := IF(@is_view=1, 'DROP VIEW ws_vouchers', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @legacy_exists := (SELECT COUNT(*) FROM information_schema.tables
                        WHERE table_schema=DATABASE() AND table_name='ws_vouchers_legacy' AND table_type='BASE TABLE');
SET @base_exists := (SELECT COUNT(*) FROM information_schema.tables
                      WHERE table_schema=DATABASE() AND table_name='ws_vouchers');
SET @s := IF(@legacy_exists=1 AND @base_exists=0,
  'RENAME TABLE ws_vouchers_legacy TO ws_vouchers', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
