-- 0011 — Campagne « objectif d'achat cumulé → produit cadeau ».
--
-- Mécanique NOUVELLE, absente de l'ERP : le moteur `promotion` (ERP) ne déclenche
-- que sur une QUANTITÉ de produits dans UNE transaction (BUY_X_GET_Y) ; il ne sait
-- pas suivre un MONTANT € cumulé par client sur PLUSIEURS commandes et une période.
-- Le cumul se calcule donc côté webshop, sur `ws_orders` (commandes webshop, dont
-- le webshop est maître). Les DONNÉES DE BASE restent maîtresses côté ERP : cette
-- feature ne fait que RÉFÉRENCER le catalogue (produit cadeau) et les boutiques par
-- leur id ERP via les répliques webshop (`ws_products.id = product.id`,
-- `ws_shops.id = franchisee_shop.id`). Aucune écriture de donnée de base.
--
-- Idempotent : CREATE TABLE IF NOT EXISTS (rejouable sans casse). MySQL 8 / MariaDB.
-- Aucune valeur métier en dur : objectif, période, produit, code = configuration.

-- ── Campagne : définition (période, seuil, produit cadeau, code) ──
CREATE TABLE IF NOT EXISTS ws_promo_campaign (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  name                 VARCHAR(150)  NOT NULL,
  id_shop              INT NULL,                       -- NULL = réseau ; sinon ws_shops.id (= franchisee_shop.id, maître ERP). Réf logique (schéma boutiques en cours d'unification) → pas de FK.
  is_active            TINYINT(1)    NOT NULL DEFAULT 1,
  starts_at            DATETIME      NOT NULL,          -- borne incluse, Europe/Brussels
  ends_at              DATETIME      NOT NULL,          -- borne incluse, Europe/Brussels
  threshold_amount     DECIMAL(10,2) NOT NULL,          -- objectif € cumulé
  currency             VARCHAR(3)    NOT NULL DEFAULT 'EUR',
  condition_scope      VARCHAR(10)   NOT NULL DEFAULT 'total',  -- 'total' (actif) | 'per_day' (prévu, désactivé)
  reward_product_id    INT           NOT NULL,          -- ws_products.id (= product.id ERP) — catalogue maître ERP
  reward_delivery_date DATE NULL,                       -- date de remise du cadeau
  voucher_code_prefix  VARCHAR(20)   NOT NULL DEFAULT 'GIFT',
  per_customer_limit   INT           NOT NULL DEFAULT 1,
  created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_promo_campaign_active (is_active, starts_at, ends_at),
  KEY idx_promo_campaign_shop (id_shop),
  CONSTRAINT fk_promo_campaign_product FOREIGN KEY (reward_product_id) REFERENCES ws_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Progression par client (cumul + attribution du voucher) ──
-- customer_ref : 'client:{client.id}' (connecté) OU 'guest:{email normalisé}' (invité).
-- accumulated_amount = cache recalculable (source de vérité = agrégat ws_orders).
CREATE TABLE IF NOT EXISTS ws_promo_progress (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  campaign_id        INT NOT NULL,
  customer_ref       VARCHAR(191) NOT NULL,
  accumulated_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  unlocked_at        DATETIME NULL,
  voucher_code       VARCHAR(64) NULL,
  redeemed_at        DATETIME NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_promo_progress (campaign_id, customer_ref),   -- 1 progression / client / campagne → idempotence
  UNIQUE KEY uq_promo_voucher_code (voucher_code),
  CONSTRAINT fk_promo_progress_campaign FOREIGN KEY (campaign_id) REFERENCES ws_promo_campaign(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Traçabilité : quelles commandes ont été comptabilisées (audit + idempotence) ──
-- N'est PAS la source de vérité du cumul (l'agrégat live l'est) ; sert à savoir si
-- un remboursement POSTÉRIEUR au déblocage doit alerter, et à auditer.
CREATE TABLE IF NOT EXISTS ws_promo_progress_order (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  progress_id    INT NOT NULL,
  order_id       INT NOT NULL,
  counted_amount DECIMAL(10,2) NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_promo_progress_order (progress_id, order_id),
  KEY idx_ppo_order (order_id),
  CONSTRAINT fk_ppo_progress FOREIGN KEY (progress_id) REFERENCES ws_promo_progress(id) ON DELETE CASCADE,
  CONSTRAINT fk_ppo_order    FOREIGN KEY (order_id)    REFERENCES ws_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
