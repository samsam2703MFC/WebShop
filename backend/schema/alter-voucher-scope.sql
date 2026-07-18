-- ============================================================================
-- voucher_scope.sql — Portée des bons : marque (réseau) vs local (franchisé).
--
--   ws_vouchers.shop_id  (NULL par défaut)
--     = NULL  -> bon MARQUE / réseau (valable sur tous les shops, push central)
--     = X     -> bon LOCAL d'un franchisé (ex. promo locale, onboarding office)
--       Les bons existants (tous NULL) restent donc « marque » = comportement
--       global actuel. Non-régressif.
--
--   ws_vouchers.id_brand  (défaut 1)
--     = marque cible d'un bon réseau (aligné sur ws_shops.id_brand).
--
-- Le « voucher pour ajouter un bureau » se modélise via type='add_office'
-- (colonne `type` déjà existante, VARCHAR) + shop_id = franchisé émetteur.
-- Idempotent (guarded).
-- ============================================================================
SET @db := DATABASE();

-- 1) shop_id
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=@db AND table_name='ws_vouchers' AND column_name='shop_id');
SET @s := IF(@c=0,
  "ALTER TABLE ws_vouchers
     ADD COLUMN shop_id INT NULL
     COMMENT 'NULL=bon marque/reseau ; sinon bon local du shop'
     AFTER code",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) id_brand
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=@db AND table_name='ws_vouchers' AND column_name='id_brand');
SET @s := IF(@c=0,
  "ALTER TABLE ws_vouchers
     ADD COLUMN id_brand INT NOT NULL DEFAULT 1
     COMMENT 'Marque cible pour un bon reseau'
     AFTER shop_id",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3) FK shop_id -> ws_shops (si absente)
SET @c := (SELECT COUNT(*) FROM information_schema.key_column_usage
            WHERE table_schema=@db AND table_name='ws_vouchers'
              AND column_name='shop_id' AND referenced_table_name='ws_shops');
SET @s := IF(@c=0,
  "ALTER TABLE ws_vouchers
     ADD CONSTRAINT fk_vouchers_shop FOREIGN KEY (shop_id) REFERENCES ws_shops(id)",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
