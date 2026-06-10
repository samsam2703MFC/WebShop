-- =====================================================================
-- 001 — Webshop dedicated database schema
-- =====================================================================
-- Implements DATABASE.md with the audit fixes:
--   * InnoDB + utf8mb4 everywhere
--   * vat_rate on products, HTVA/TVA split on order lines
--   * external_id (ERP key) with UNIQUE index on all synced tables
--   * stripe_* columns on orders
--   * indexes on every lookup field used by the API
--   * sync_log / sync_state for the Phase 2 worker
-- Synced tables (fed from Franchise Buddy): ws_shops, ws_categories,
--   ws_products, ws_product_shops, ws_product_stock, ws_promotions.
-- Webshop-owned tables: users, orders, vouchers, offices, sites, fees,
--   tours, availability, sync bookkeeping.
-- =====================================================================

SET NAMES utf8mb4;

-- ── Synced from general DB ─────────────────────────────────────────--

CREATE TABLE IF NOT EXISTS ws_shops (
  id            VARCHAR(36)   NOT NULL PRIMARY KEY,
  external_id   VARCHAR(64)   NULL,
  name          VARCHAR(120)  NOT NULL,
  address       VARCHAR(250)  NOT NULL DEFAULT '',
  accent        VARCHAR(16)   NOT NULL DEFAULT '#8D1D2C',
  opening_hours JSON          NULL,
  click_collect TINYINT(1)    NOT NULL DEFAULT 1,
  active        TINYINT(1)    NOT NULL DEFAULT 1,
  updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shops_external (external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ws_categories (
  id            VARCHAR(36)   NOT NULL PRIMARY KEY,
  external_id   VARCHAR(64)   NULL,
  label         VARCHAR(120)  NOT NULL,
  img           VARCHAR(250)  NULL,
  sort_order    INT           NOT NULL DEFAULT 0,
  active        TINYINT(1)    NOT NULL DEFAULT 1,
  updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_categories_external (external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ws_products (
  id            INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
  external_id   VARCHAR(64)   NULL,            -- ERP SKU / stable sync key
  cat           VARCHAR(36)   NOT NULL,
  name          VARCHAR(200)  NOT NULL,
  description   TEXT          NULL,
  price         DECIMAL(8,2)  NOT NULL,        -- TTC
  vat_rate      DECIMAL(4,2)  NOT NULL DEFAULT 6.00,  -- BE: 6 food / 21 standard
  img           VARCHAR(250)  NULL,
  allergens     JSON          NULL,
  portions      TINYINT(1)    NOT NULL DEFAULT 0,
  cross_portion TINYINT(1)    NOT NULL DEFAULT 0,
  has_menu_options TINYINT(1) NOT NULL DEFAULT 0,
  no_delivery   TINYINT(1)    NOT NULL DEFAULT 0,
  lead_time     TINYINT       NOT NULL DEFAULT 0,     -- order D+N in advance
  active        TINYINT(1)    NOT NULL DEFAULT 1,
  updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_products_external (external_id),
  KEY idx_products_cat (cat),
  KEY idx_products_active (active),
  CONSTRAINT fk_products_cat FOREIGN KEY (cat) REFERENCES ws_categories(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ws_product_shops (
  product_id    INT           NOT NULL,
  shop_id       VARCHAR(36)   NOT NULL,
  price_override DECIMAL(8,2) NULL,
  available     TINYINT(1)    NOT NULL DEFAULT 1,
  delivery_stock INT          NULL,             -- NULL = unlimited
  updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (product_id, shop_id),
  KEY idx_ps_shop (shop_id),
  CONSTRAINT fk_ps_product FOREIGN KEY (product_id) REFERENCES ws_products(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ps_shop FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ws_promotions (
  id            VARCHAR(36)   NOT NULL PRIMARY KEY,
  external_id   VARCHAR(64)   NULL,
  label         VARCHAR(200)  NOT NULL,
  kind          ENUM('percent','amount') NOT NULL DEFAULT 'percent',
  value         DECIMAL(8,2)  NOT NULL,
  shop_id       VARCHAR(36)   NULL,             -- NULL = all shops
  starts_at     DATE          NULL,
  ends_at       DATE          NULL,
  active        TINYINT(1)    NOT NULL DEFAULT 1,
  updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_promotions_external (external_id),
  KEY idx_promotions_shop (shop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Webshop-owned ──────────────────────────────────────────────────--

CREATE TABLE IF NOT EXISTS ws_users (
  id            VARCHAR(36)   NOT NULL PRIMARY KEY,
  email         VARCHAR(190)  NOT NULL,
  password_hash VARCHAR(120)  NOT NULL,
  first_name    VARCHAR(80)   NOT NULL DEFAULT '',
  last_name     VARCHAR(80)   NOT NULL DEFAULT '',
  phone         VARCHAR(30)   NULL,
  office_id     VARCHAR(36)   NULL,
  preferred_shop_id VARCHAR(36) NULL,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ws_offices (
  id            VARCHAR(36)   NOT NULL PRIMARY KEY,
  name          VARCHAR(160)  NOT NULL,
  contact       VARCHAR(120)  NULL,
  phone         VARCHAR(30)   NULL,
  email         VARCHAR(190)  NULL,
  address       VARCHAR(250)  NULL,
  tour_id       VARCHAR(36)   NULL,
  status        ENUM('pending','validated','rejected') NOT NULL DEFAULT 'pending',
  default_site_id VARCHAR(36) NULL,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ws_tours (
  id            VARCHAR(36)   NOT NULL PRIMARY KEY,
  name          VARCHAR(120)  NOT NULL,
  shop_id       VARCHAR(36)   NOT NULL,
  time_window   VARCHAR(40)   NOT NULL,
  days          VARCHAR(40)   NOT NULL,
  active        TINYINT(1)    NOT NULL DEFAULT 1,
  KEY idx_tours_shop (shop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ws_office_delivery_sites (
  id              VARCHAR(36)  NOT NULL PRIMARY KEY,
  office_client_id VARCHAR(36) NOT NULL,
  name            VARCHAR(160) NOT NULL,
  address         VARCHAR(250) NOT NULL,
  floor_room      VARCHAR(120) NULL,
  contact_name    VARCHAR(120) NULL,
  contact_phone   VARCHAR(30)  NULL,
  tournee_id      VARCHAR(36)  NULL,
  tournee_stop_id VARCHAR(36)  NULL,
  shop_id         VARCHAR(36)  NULL,
  active          TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sites_office (office_client_id),
  CONSTRAINT fk_sites_office FOREIGN KEY (office_client_id) REFERENCES ws_offices(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ws_delivery_fee_rules (
  id              VARCHAR(36)  NOT NULL PRIMARY KEY,
  level           ENUM('site','office','tour','shop','global') NOT NULL,
  site_id         VARCHAR(36)  NULL,
  office_client_id VARCHAR(36) NULL,
  tour_id         VARCHAR(36)  NULL,
  shop_id         VARCHAR(36)  NULL,
  free_delivery   TINYINT(1)   NOT NULL DEFAULT 0,
  always_charge   TINYINT(1)   NOT NULL DEFAULT 0,
  fee_amount      DECIMAL(8,2) NOT NULL DEFAULT 0,
  free_delivery_minimum DECIMAL(8,2) NOT NULL DEFAULT 0,
  payment_type    ENUM('immediate','deferred') NOT NULL DEFAULT 'immediate',
  active          TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fee_level (level),
  KEY idx_fee_site (site_id),
  KEY idx_fee_office (office_client_id),
  KEY idx_fee_tour (tour_id),
  KEY idx_fee_shop (shop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ws_vouchers (
  id            VARCHAR(36)   NOT NULL PRIMARY KEY,
  code          VARCHAR(40)   NOT NULL,
  kind          ENUM('percent','amount') NOT NULL,
  value         DECIMAL(8,2)  NOT NULL,
  min_order     DECIMAL(8,2)  NOT NULL DEFAULT 0,
  shop_id       VARCHAR(36)   NULL,
  max_uses      INT           NULL,
  used_count    INT           NOT NULL DEFAULT 0,
  expires_at    DATE          NULL,
  active        TINYINT(1)    NOT NULL DEFAULT 1,
  UNIQUE KEY uq_vouchers_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ws_orders (
  id            VARCHAR(36)   NOT NULL PRIMARY KEY,
  shop_id       VARCHAR(36)   NOT NULL,
  mode          ENUM('collect','delivery') NOT NULL,
  status        ENUM('pending_payment','paid','deferred_billing','preparing','ready','delivered','canceled','payment_failed') NOT NULL DEFAULT 'pending_payment',
  slot_id       VARCHAR(40)   NULL,
  slot_label    VARCHAR(60)   NULL,
  order_date    DATE          NULL,
  customer_id   VARCHAR(36)   NULL,
  customer_email VARCHAR(190) NULL,
  customer_name VARCHAR(160)  NULL,
  customer_phone VARCHAR(30)  NULL,
  -- delivery site metadata (B2B office delivery)
  office_client_id          VARCHAR(36)  NULL,
  office_delivery_site_id   VARCHAR(36)  NULL,
  office_delivery_site_name VARCHAR(160) NULL,
  delivery_address          VARCHAR(250) NULL,
  tournee_id                VARCHAR(36)  NULL,
  tournee_stop_id           VARCHAR(36)  NULL,
  delivery_mode             VARCHAR(30)  NULL,
  payment_type  ENUM('immediate','deferred') NOT NULL DEFAULT 'immediate',
  delivery_fee_applied  TINYINT(1)   NOT NULL DEFAULT 0,
  delivery_fee_amount   DECIMAL(8,2) NOT NULL DEFAULT 0,
  free_delivery_minimum DECIMAL(8,2) NOT NULL DEFAULT 0,
  -- money (all server-computed; client totals are never trusted)
  subtotal_ttc  DECIMAL(10,2) NOT NULL,
  discount_ttc  DECIMAL(10,2) NOT NULL DEFAULT 0,
  voucher_code  VARCHAR(40)   NULL,
  total_ttc     DECIMAL(10,2) NOT NULL,
  total_htva    DECIMAL(10,2) NOT NULL,
  total_tva     DECIMAL(10,2) NOT NULL,
  currency      CHAR(3)       NOT NULL DEFAULT 'EUR',
  -- stripe (never store card data)
  stripe_payment_intent_id VARCHAR(80) NULL,
  stripe_session_id        VARCHAR(80) NULL,
  paid_at       TIMESTAMP     NULL,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_orders_shop (shop_id),
  KEY idx_orders_customer (customer_id),
  KEY idx_orders_status (status),
  KEY idx_orders_stripe_pi (stripe_payment_intent_id),
  KEY idx_orders_stripe_session (stripe_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ws_order_lines (
  id            INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id      VARCHAR(36)   NOT NULL,
  product_id    INT           NOT NULL,
  name          VARCHAR(200)  NOT NULL,   -- snapshot at order time
  qty           INT           NOT NULL,
  `portion`     VARCHAR(20)   NULL,
  options       JSON          NULL,
  unit_price_ttc DECIMAL(8,2) NOT NULL,   -- snapshot, server-side
  vat_rate      DECIMAL(4,2)  NOT NULL,
  line_ttc      DECIMAL(10,2) NOT NULL,
  line_htva     DECIMAL(10,2) NOT NULL,
  line_tva      DECIMAL(10,2) NOT NULL,
  KEY idx_lines_order (order_id),
  CONSTRAINT fk_lines_order FOREIGN KEY (order_id) REFERENCES ws_orders(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stripe webhook idempotency: every processed event id is recorded;
-- a redelivered event hits the PK and is skipped.
CREATE TABLE IF NOT EXISTS ws_stripe_events (
  event_id      VARCHAR(80)   NOT NULL PRIMARY KEY,
  type          VARCHAR(80)   NOT NULL,
  order_id      VARCHAR(36)   NULL,
  processed_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Sync bookkeeping (Phase 2) ─────────────────────────────────────--

CREATE TABLE IF NOT EXISTS sync_log (
  id            BIGINT        NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_kind      ENUM('event','full','reconcile') NOT NULL,
  entity        VARCHAR(40)   NOT NULL,
  external_id   VARCHAR(64)   NULL,
  action        ENUM('inserted','updated','deactivated','skipped','error') NOT NULL,
  detail        TEXT          NULL,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_synclog_created (created_at),
  KEY idx_synclog_entity (entity, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_state (
  k             VARCHAR(60)   NOT NULL PRIMARY KEY,
  v             VARCHAR(190)  NOT NULL,
  updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
