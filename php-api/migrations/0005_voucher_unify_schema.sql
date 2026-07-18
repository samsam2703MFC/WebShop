-- 0005 — Unification des bons (vouchers) : dimensions marque/boutique/canal
-- sur le modèle ERP (voucher_campaign / voucher_code / voucher_redemption).
--
-- Contexte : trois systèmes de bons coexistaient dans atelierby_db —
--   (1) ws_vouchers (webshop, table plate),
--   (2) promotion → voucher_campaign → voucher_code → voucher_redemption (ERP, cible),
--   (3) promo_code / promo_code_type / promo_code_product (ERP, parallèle, hors périmètre).
-- Cible imposée : voucher_campaign / voucher_code deviennent la SEULE source de vérité.
--
-- Cette migration (Étape 1, DDL uniquement) :
--   • ajoute id_brand / id_shop à voucher_campaign (portée marque + boutique) ;
--   • crée voucher_campaign_channel (portée par canal WS/POS/OFF/B2B) ;
--   • ajoute voucher_redemption.channel (traçabilité du canal de consommation) ;
--   • crée promotion_order_discount : le moteur `promotion` ERP est PRODUIT-centré
--     (BUY_X_GET_Y / BUNDLE / remise produit planifiée) et ne sait PAS représenter
--     une remise NIVEAU-COMMANDE (% ou € sur le sous-total), le PORT OFFERT, ni un
--     min_order MONÉTAIRE. On ajoute donc un satellite dédié, sur le même patron que
--     promotion_buy_x_get_y (1 ligne par promotion, UNIQUE(id_promotion), CASCADE),
--     avec un nouveau promotion.promotion_type = 'ORDER_DISCOUNT'.
--   • pose les FK id_shop → franchisee_shop et (conditionnelle) id_brand → brand.
--
-- Idempotent & MySQL 8 : gardes information_schema + PREPARE/EXECUTE (DO 0 = no-op).
-- Additif : ne modifie aucune donnée, ne touche ni ws_vouchers ni le code applicatif.
-- Mapping confirmé : ws_shops.id = franchisee_shop.id (identité, ex. Halle=4 des deux
-- côtés). Hypothèse documentée : brand.id = 1 = ws_brands.id = 1 (tout
-- franchisee_shop.id_brand = 1) — la FK id_brand n'est posée QUE si la table brand existe.

-- ── voucher_campaign : + id_brand (NOT NULL DEFAULT 1 = marque réseau par défaut) ──
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE voucher_campaign ADD COLUMN id_brand INT NOT NULL DEFAULT 1','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND column_name='id_brand');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- + id_shop (NULL = réseau / toutes boutiques ; sinon franchisee_shop.id) ──
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE voucher_campaign ADD COLUMN id_shop INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND column_name='id_shop');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Index de portée (idempotents)
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE voucher_campaign ADD KEY idx_vc_brand (id_brand)','DO 0')
  FROM information_schema.statistics WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND index_name='idx_vc_brand');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE voucher_campaign ADD KEY idx_vc_shop (id_shop)','DO 0')
  FROM information_schema.statistics WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND index_name='idx_vc_shop');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── Portée par canal : table de jonction (préférée pour reporting/index) ──
CREATE TABLE IF NOT EXISTS voucher_campaign_channel (
  id_voucher_campaign INT NOT NULL,
  channel VARCHAR(8) NOT NULL,                 -- 'WS' | 'POS' | 'OFF' | 'B2B'
  PRIMARY KEY (id_voucher_campaign, channel),
  KEY idx_vcc_channel (channel),
  CONSTRAINT fk_vcc_campaign FOREIGN KEY (id_voucher_campaign)
    REFERENCES voucher_campaign(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ── Traçabilité du canal de consommation sur la redemption ──
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE voucher_redemption ADD COLUMN channel VARCHAR(8) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='voucher_redemption' AND column_name='channel');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE voucher_redemption ADD KEY idx_vr_channel (channel)','DO 0')
  FROM information_schema.statistics WHERE table_schema=DATABASE()
   AND table_name='voucher_redemption' AND index_name='idx_vr_channel');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── Nouveau satellite : remise NIVEAU-COMMANDE (ce que le moteur produit ne couvre pas) ──
-- discount_kind : 'PERCENT' (discount_value = %), 'FIXED' (discount_value = €),
--                 'FREE_DELIVERY' (discount_value = NULL, port offert).
-- min_order_amount : seuil € sur le panier (= ws_vouchers.min_order). Reprend le
-- vocabulaire ERP existant (promo_code.min_order_value, promo_code_type amount/percent).
CREATE TABLE IF NOT EXISTS promotion_order_discount (
  id               INT NOT NULL AUTO_INCREMENT,
  id_promotion     INT NOT NULL,
  discount_kind    VARCHAR(16)   NOT NULL,            -- 'PERCENT' | 'FIXED' | 'FREE_DELIVERY'
  discount_value   DECIMAL(10,2) DEFAULT NULL,
  min_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_promotion_order_discount_promotion (id_promotion),
  KEY idx_promotion_order_discount_kind (discount_kind),
  CONSTRAINT fk_promotion_order_discount_promotion FOREIGN KEY (id_promotion)
    REFERENCES promotion(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ── FK id_shop → franchisee_shop(id) (mapping identité confirmé) ──
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE voucher_campaign ADD CONSTRAINT fk_vc_shop FOREIGN KEY (id_shop) REFERENCES franchisee_shop(id) ON DELETE RESTRICT ON UPDATE RESTRICT','DO 0')
  FROM information_schema.table_constraints WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND constraint_name='fk_vc_shop');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── FK id_brand → brand(id) : NON posée ici. Le mapping marque n'est pas encore
--    confirmé (table `brand` ERP non fournie ; hypothèse brand.id=1=ws_brands.id=1).
--    Conformément au brief (« FK … une fois le mapping confirmé »), elle est fournie
--    séparément dans migrations/optional/0005b_voucher_brand_fk.sql — à jouer À LA MAIN
--    après avoir vérifié que brand.id=1 existe. id_brand reste indexé sans FK d'ici là.
