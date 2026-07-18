-- ROLLBACK 0005 — retire les dimensions ajoutées (schéma). À jouer EN DERNIER,
-- après les rollbacks 0007 puis 0006. Guardé/idempotent.
-- ⚠️ Ne PAS jouer si des données (0006) subsistent : supprimer d'abord les campagnes.

-- FK id_brand (conditionnelle)
SET @s := (SELECT IF(COUNT(*)=1,
  'ALTER TABLE voucher_campaign DROP FOREIGN KEY fk_vc_brand','DO 0')
  FROM information_schema.table_constraints WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND constraint_name='fk_vc_brand');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- FK id_shop
SET @s := (SELECT IF(COUNT(*)=1,
  'ALTER TABLE voucher_campaign DROP FOREIGN KEY fk_vc_shop','DO 0')
  FROM information_schema.table_constraints WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND constraint_name='fk_vc_shop');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

DROP TABLE IF EXISTS promotion_order_discount;
DROP TABLE IF EXISTS voucher_campaign_channel;

-- voucher_redemption.channel
SET @s := (SELECT IF(COUNT(*)=1,
  'ALTER TABLE voucher_redemption DROP COLUMN channel','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='voucher_redemption' AND column_name='channel');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Index de portée
SET @s := (SELECT IF(COUNT(*)=1,
  'ALTER TABLE voucher_campaign DROP KEY idx_vc_brand','DO 0')
  FROM information_schema.statistics WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND index_name='idx_vc_brand');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=1,
  'ALTER TABLE voucher_campaign DROP KEY idx_vc_shop','DO 0')
  FROM information_schema.statistics WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND index_name='idx_vc_shop');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Colonnes
SET @s := (SELECT IF(COUNT(*)=1,
  'ALTER TABLE voucher_campaign DROP COLUMN id_brand','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND column_name='id_brand');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=1,
  'ALTER TABLE voucher_campaign DROP COLUMN id_shop','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND column_name='id_shop');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
