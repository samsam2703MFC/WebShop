-- ============================================================
-- L'Atelier By — Webshop schema (ws_)
-- Version MySQL / MariaDB (compatible phpMyAdmin)
-- Exécution en une seule fois, ordre des dépendances respecté
-- Généré le 2026-07-09
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. SHOPS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ws_shops (
  id        VARCHAR(50)  PRIMARY KEY,
  name      VARCHAR(100) NOT NULL,
  city      VARCHAR(100),
  address   VARCHAR(200),
  phone     VARCHAR(30),
  email     VARCHAR(100),
  accent    VARCHAR(20),   -- couleur hex e.g. #8D1D2C
  tint      VARCHAR(20),   -- fond clair e.g. #fdf6f0
  logo_url  VARCHAR(255),
  active    BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 2. CATEGORIES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ws_categories (
  id          VARCHAR(50)  PRIMARY KEY,
  shop_id     VARCHAR(50),
  label       VARCHAR(100) NOT NULL,
  img         VARCHAR(255),
  sort_order  INT DEFAULT 0,
  active      BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_category_subs (
  id          VARCHAR(50)  PRIMARY KEY,
  category_id VARCHAR(50),
  label       VARCHAR(100) NOT NULL,
  img         VARCHAR(255),
  sort_order  INT DEFAULT 0,
  active      BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (category_id) REFERENCES ws_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 3. PRODUCTS (catalogue global)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ws_products (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  cat              VARCHAR(50),
  sub_cat          VARCHAR(50),
  name             VARCHAR(200) NOT NULL,
  description      TEXT,
  price            DECIMAL(10,2) NOT NULL,  -- prix global par défaut
  img              VARCHAR(255),
  badge            VARCHAR(50),             -- "Du jour", "Nouveau", "4+1", NULL
  portions         BOOLEAN DEFAULT FALSE,   -- quart/demi/entier
  cross_portion    BOOLEAN DEFAULT FALSE,   -- participe au promo 4+1
  has_menu_options BOOLEAN DEFAULT FALSE,   -- carousel bundles/menus
  active           BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (cat)     REFERENCES ws_categories(id),
  FOREIGN KEY (sub_cat) REFERENCES ws_category_subs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_product_allergens (
  product_id  INT,
  allergen    VARCHAR(50) NOT NULL,
  PRIMARY KEY (product_id, allergen),
  FOREIGN KEY (product_id) REFERENCES ws_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Valeurs valides: gluten milk egg fish almond sesame peanut soy
-- shellfish mustard celery lupin molluscs sulphites

CREATE TABLE IF NOT EXISTS ws_product_shops (
  product_id  INT,
  shop_id     VARCHAR(50),
  no_delivery BOOLEAN DEFAULT FALSE,  -- TRUE = collect uniquement
  active      BOOLEAN DEFAULT TRUE,
  PRIMARY KEY (product_id, shop_id),
  FOREIGN KEY (product_id) REFERENCES ws_products(id),
  FOREIGN KEY (shop_id)    REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_product_prices (
  product_id  INT,
  shop_id     VARCHAR(50),
  price       DECIMAL(10,2) NOT NULL,
  active      BOOLEAN DEFAULT TRUE,
  PRIMARY KEY (product_id, shop_id),
  FOREIGN KEY (product_id) REFERENCES ws_products(id),
  FOREIGN KEY (shop_id)    REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 4. OPTIONS PRODUIT
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ws_product_options (
  id          VARCHAR(50)  PRIMARY KEY,
  product_id  INT,
  label       VARCHAR(100) NOT NULL,
  required    BOOLEAN DEFAULT FALSE,
  sort_order  INT DEFAULT 0,
  active      BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (product_id) REFERENCES ws_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_product_option_choices (
  id         VARCHAR(50)   PRIMARY KEY,
  option_id  VARCHAR(50),
  label      VARCHAR(100)  NOT NULL,
  delta      DECIMAL(10,2) DEFAULT 0,  -- ajout de prix (peut être négatif)
  sort_order INT DEFAULT 0,
  active     BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (option_id) REFERENCES ws_product_options(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 5. BUNDLES (menus)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ws_bundles (
  id             VARCHAR(50)   PRIMARY KEY,
  product_id     INT,
  name           VARCHAR(100)  NOT NULL,
  description    TEXT,
  price_modifier DECIMAL(10,2) DEFAULT 0,
  sort_order     INT DEFAULT 0,
  active         BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (product_id) REFERENCES ws_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- "À la carte" est ajouté automatiquement par l'UI — ne pas créer de row.

CREATE TABLE IF NOT EXISTS ws_bundle_slots (
  id         VARCHAR(50)  PRIMARY KEY,
  bundle_id  VARCHAR(50),
  label      VARCHAR(100) NOT NULL,
  required   BOOLEAN DEFAULT FALSE,
  sort_order INT DEFAULT 0,
  active     BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (bundle_id) REFERENCES ws_bundles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_bundle_slot_choices (
  id         VARCHAR(50)   PRIMARY KEY,
  slot_id    VARCHAR(50),
  label      VARCHAR(100)  NOT NULL,
  img        VARCHAR(255),
  delta      DECIMAL(10,2) DEFAULT 0,
  sort_order INT DEFAULT 0,
  active     BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (slot_id) REFERENCES ws_bundle_slots(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 6. ASSORTIMENTS (saisons)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ws_assortments (
  id      VARCHAR(50)  PRIMARY KEY,
  shop_id VARCHAR(50),
  label   VARCHAR(100) NOT NULL,
  img     VARCHAR(255),
  active  BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 7. RÉSEAU LIVRAISON : TOURS → OFFICES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ws_tours (
  id      VARCHAR(50)  PRIMARY KEY,
  shop_id VARCHAR(50),
  name    VARCHAR(100) NOT NULL,
  active  BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_offices (
  id          VARCHAR(50)  PRIMARY KEY,
  tour_id     VARCHAR(50),
  name        VARCHAR(200) NOT NULL,
  address     VARCHAR(200),
  postal_code VARCHAR(20),
  city        VARCHAR(100),
  contact     VARCHAR(100),
  email       VARCHAR(100),
  phone       VARCHAR(30),
  vat         VARCHAR(30),
  status      VARCHAR(20)  DEFAULT 'pending',   -- pending | validated | rejected
  active      BOOLEAN DEFAULT TRUE,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tour_id) REFERENCES ws_tours(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 8. CUSTOMERS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ws_customers (
  id                  VARCHAR(50)  PRIMARY KEY,
  email               VARCHAR(200) UNIQUE NOT NULL,
  password_hash       VARCHAR(255) NOT NULL,       -- bcrypt
  first_name          VARCHAR(100),
  last_name           VARCHAR(100),
  phone               VARCHAR(30),
  office_id           VARCHAR(50),
  preferred_shop_id   VARCHAR(50),
  preferred_lang      VARCHAR(5)   DEFAULT 'fr',   -- fr | nl | en | de
  is_business         BOOLEAN DEFAULT FALSE,
  fidelity_active     BOOLEAN DEFAULT FALSE,
  fidelity_linked_at  TIMESTAMP NULL DEFAULT NULL,
  invoice_country     VARCHAR(5)   DEFAULT 'BE',
  invoice_vat         VARCHAR(30),
  invoice_name        VARCHAR(200),
  invoice_address     VARCHAR(200),
  invoice_postal_code VARCHAR(20),
  invoice_city        VARCHAR(100),
  active              BOOLEAN DEFAULT TRUE,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (office_id)         REFERENCES ws_offices(id),
  FOREIGN KEY (preferred_shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 9. DELIVERY SITES B2B (avant ws_orders qui les référence)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ws_office_delivery_sites (
  id               VARCHAR(50)  PRIMARY KEY,          -- UUID
  office_client_id VARCHAR(50)  NOT NULL,
  name             VARCHAR(120) NOT NULL,             -- "ACME — Rue de la Loi"
  address          VARCHAR(250),
  floor_room       VARCHAR(120),
  contact_name     VARCHAR(120),
  contact_phone    VARCHAR(30),
  tournee_id       VARCHAR(50),
  tournee_stop_id  VARCHAR(50),
  shop_id          VARCHAR(50),
  active           BOOLEAN DEFAULT TRUE,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (office_client_id) REFERENCES ws_offices(id),
  FOREIGN KEY (tournee_id)       REFERENCES ws_tours(id),
  FOREIGN KEY (shop_id)          REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 10. ORDERS (inclut les colonnes delivery fee / site)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ws_orders (
  id               VARCHAR(50)   PRIMARY KEY,
  shop_id          VARCHAR(50),
  customer_id      VARCHAR(50),               -- NULL = guest
  mode             VARCHAR(20)   NOT NULL,    -- collect | delivery
  status           VARCHAR(30)   DEFAULT 'pending',
                   -- pending | confirmed | preparing | ready | delivered | cancelled
  slot_id          VARCHAR(50),
  slot_label       VARCHAR(100),
  delivery_date    DATE,
  subtotal         DECIMAL(10,2),
  promo_amount     DECIMAL(10,2) DEFAULT 0,
  voucher_code     VARCHAR(50),
  voucher_discount DECIMAL(10,2) DEFAULT 0,
  total            DECIMAL(10,2),
  payment_method   VARCHAR(30),               -- bancontact | card | cash
  payment_status   VARCHAR(30)   DEFAULT 'pending',
                   -- pending | paid | failed | refunded
  lang             VARCHAR(5)    DEFAULT 'fr',
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  -- Metadata delivery fee / site B2B
  office_client_id          VARCHAR(50),
  office_delivery_site_id   VARCHAR(50),
  office_delivery_site_name VARCHAR(120),     -- snapshot
  payment_type              VARCHAR(20) DEFAULT 'immediate',  -- immediate | deferred
  delivery_fee_applied      BOOLEAN DEFAULT FALSE,
  delivery_fee_amount       DECIMAL(8,2) DEFAULT 0,
  free_delivery_minimum     DECIMAL(8,2) DEFAULT 0,
  tournee_stop_id           VARCHAR(50),
  delivery_mode             VARCHAR(20) DEFAULT 'collect',    -- office_delivery | collect

  FOREIGN KEY (shop_id)                 REFERENCES ws_shops(id),
  FOREIGN KEY (customer_id)             REFERENCES ws_customers(id),
  FOREIGN KEY (office_client_id)        REFERENCES ws_offices(id),
  FOREIGN KEY (office_delivery_site_id) REFERENCES ws_office_delivery_sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_order_lines (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  order_id     VARCHAR(50),
  product_id   INT,
  product_name VARCHAR(200),               -- snapshot
  qty          INT           NOT NULL,
  unit_price   DECIMAL(10,2),              -- snapshot
  `portion`    VARCHAR(20),               -- quart | demi | entier | NULL (mot réservé → backticks)
  bundle_id    VARCHAR(50),
  options      JSON,                      -- [{ optionId, choiceId, label, delta }]
  bundle_slots JSON,                      -- { slotId: choiceId }
  FOREIGN KEY (order_id)   REFERENCES ws_orders(id),
  FOREIGN KEY (product_id) REFERENCES ws_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 11. VOUCHERS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ws_vouchers (
  code        VARCHAR(50)   PRIMARY KEY,
  type        VARCHAR(20)   NOT NULL,   -- percent | fixed | free_delivery
  value       DECIMAL(10,2),
  min_order   DECIMAL(10,2) DEFAULT 0,
  max_uses    INT,                     -- NULL = illimité
  used_count  INT           DEFAULT 0,
  expires_at  TIMESTAMP NULL DEFAULT NULL,  -- NULL = pas d'expiration
  active      BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 12. CALENDRIER / SLOTS / PRICING RULES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ws_calendar_rules (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  shop_id         VARCHAR(50),
  mode            VARCHAR(20) NOT NULL,  -- collect | delivery
  open_days       JSON,                  -- ISO: [1,2,3,4,5] (1=Lun … 7=Dim)
  cutoff_hour     INT         NOT NULL,  -- 0–23
  cutoff_minutes  INT         DEFAULT 0, -- 0–59
  lead_hours      INT         DEFAULT 0,
  active          BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_slots (
  id         VARCHAR(50)  PRIMARY KEY,
  shop_id    VARCHAR(50),
  mode       VARCHAR(20)  NOT NULL,   -- collect | delivery
  label      VARCHAR(100) NOT NULL,   -- "08:30–10:30"
  sort_order INT DEFAULT 0,
  active     BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_pricing_rules (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  shop_id   VARCHAR(50),
  rule_type VARCHAR(50) NOT NULL,   -- cross_portion
  x         INT,                    -- achète X portions
  y         INT,                    -- Y offertes (les moins chères)
  threshold INT,                    -- minimum de portions pour activer
  label     VARCHAR(200),
  active    BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 13. STOCK
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ws_product_stock (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  product_id   INT,
  shop_id      VARCHAR(50),
  date         DATE        NOT NULL,
  mode         VARCHAR(20),          -- collect | delivery | NULL = les deux
  qty_total    INT         NOT NULL,
  qty_reserved INT         DEFAULT 0,
  qty_sold     INT         DEFAULT 0,
  active       BOOLEAN DEFAULT TRUE,
  UNIQUE KEY uq_stock (product_id, shop_id, date, mode),
  FOREIGN KEY (product_id) REFERENCES ws_products(id),
  FOREIGN KEY (shop_id)    REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_stock_reservations (
  id          VARCHAR(50) PRIMARY KEY,
  product_id  INT,
  shop_id     VARCHAR(50),
  date        DATE        NOT NULL,
  mode        VARCHAR(20) NOT NULL,    -- collect | delivery
  qty         INT         NOT NULL,
  customer_id VARCHAR(50),
  expires_at  TIMESTAMP   NOT NULL,    -- created_at + 15 min
  released    BOOLEAN DEFAULT FALSE,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_stock_res_expiry (released, expires_at),
  FOREIGN KEY (product_id)  REFERENCES ws_products(id),
  FOREIGN KEY (shop_id)     REFERENCES ws_shops(id),
  FOREIGN KEY (customer_id) REFERENCES ws_customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 14. AVAILABILITY ENGINE
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ws_shop_availability (
  shop_id                    VARCHAR(50) PRIMARY KEY,
  collect_enabled            BOOLEAN DEFAULT TRUE,
  delivery_enabled           BOOLEAN DEFAULT TRUE,
  collect_open_days          JSON,     -- [1,2,3,4,5,6] — NULL = Lun–Sam par défaut (backend)
  delivery_open_days         JSON,     -- [1,2,3,4,5]   — NULL = Lun–Ven par défaut (backend)
  collect_hours_start        TIME     DEFAULT '08:00:00',
  collect_hours_end          TIME     DEFAULT '19:00:00',
  delivery_hours_start       TIME     DEFAULT '08:30:00',
  delivery_hours_end         TIME     DEFAULT '13:30:00',
  collect_slot_duration_min  INT      DEFAULT 60,
  delivery_slot_duration_min INT      DEFAULT 120,
  collect_cutoff_hour        SMALLINT DEFAULT 16,
  collect_cutoff_minute      SMALLINT DEFAULT 0,
  collect_lead_hours         SMALLINT DEFAULT 2,
  delivery_cutoff_hour       SMALLINT DEFAULT 11,
  delivery_cutoff_minute     SMALLINT DEFAULT 0,
  delivery_lead_hours        SMALLINT DEFAULT 20,
  collect_capacity_per_slot  INT      DEFAULT 15,
  delivery_capacity_per_slot INT      DEFAULT 30,
  timezone                   VARCHAR(50) DEFAULT 'Europe/Brussels',
  updated_at                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_shop_exceptions (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  shop_id              VARCHAR(50) NOT NULL,
  exception_date       DATE        NOT NULL,
  type                 VARCHAR(20) NOT NULL,   -- closed | modified
  reason               VARCHAR(200),
  collect_hours_start  TIME,
  collect_hours_end    TIME,
  delivery_hours_start TIME,
  delivery_hours_end   TIME,
  collect_enabled      BOOLEAN,      -- NULL = hérite de shop_availability
  delivery_enabled     BOOLEAN,
  created_by           VARCHAR(50),
  created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shop_exception (shop_id, exception_date),
  FOREIGN KEY (shop_id)    REFERENCES ws_shops(id),
  FOREIGN KEY (created_by) REFERENCES ws_customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_product_availability (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  product_id               INT         NOT NULL,
  shop_id                  VARCHAR(50) NOT NULL,
  collect_enabled          BOOLEAN DEFAULT TRUE,
  delivery_enabled         BOOLEAN DEFAULT TRUE,
  collect_lead_time        SMALLINT DEFAULT 0,    -- jours (D+0 = même jour)
  delivery_lead_time       SMALLINT DEFAULT 0,
  collect_cutoff_override  TIME,                  -- NULL = défaut shop
  delivery_cutoff_override TIME,
  max_qty_per_day          INT,                   -- NULL = illimité
  max_qty_per_slot         INT,
  active                   BOOLEAN DEFAULT TRUE,
  UNIQUE KEY uq_prod_avail (product_id, shop_id),
  KEY idx_prod_avail_shop (shop_id),
  FOREIGN KEY (product_id) REFERENCES ws_products(id),
  FOREIGN KEY (shop_id)    REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_category_availability (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  category_id              VARCHAR(50) NOT NULL,
  shop_id                  VARCHAR(50) NOT NULL,
  collect_enabled          BOOLEAN DEFAULT TRUE,
  delivery_enabled         BOOLEAN DEFAULT TRUE,
  collect_lead_time        SMALLINT DEFAULT 0,
  delivery_lead_time       SMALLINT DEFAULT 0,
  collect_cutoff_override  TIME,
  delivery_cutoff_override TIME,
  active                   BOOLEAN DEFAULT TRUE,
  UNIQUE KEY uq_cat_avail (category_id, shop_id),
  FOREIGN KEY (category_id) REFERENCES ws_categories(id),
  FOREIGN KEY (shop_id)     REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_slot_capacity (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  shop_id          VARCHAR(50) NOT NULL,
  mode             VARCHAR(20) NOT NULL,   -- collect | delivery
  slot_date        DATE        NOT NULL,
  slot_start       TIME        NOT NULL,
  slot_end         TIME        NOT NULL,
  max_orders       INT         NOT NULL,
  max_items        INT,
  current_orders   INT         NOT NULL DEFAULT 0,
  current_items    INT         NOT NULL DEFAULT 0,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slot_cap (shop_id, mode, slot_date, slot_start),
  KEY idx_slot_cap_date (shop_id, slot_date, mode),
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_tour_availability (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  tour_id        VARCHAR(50) NOT NULL,
  shop_id        VARCHAR(50) NOT NULL,
  delivery_day   SMALLINT    NOT NULL,   -- ISO 1–7
  delivery_start TIME        NOT NULL,
  delivery_end   TIME        NOT NULL,
  cutoff_time    TIME        NOT NULL,
  max_orders     INT,          -- NULL = illimité
  max_items      INT,
  active         BOOLEAN DEFAULT TRUE,
  UNIQUE KEY uq_tour_avail (tour_id, shop_id, delivery_day),
  FOREIGN KEY (tour_id) REFERENCES ws_tours(id),
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_office_delivery_settings (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  office_id       VARCHAR(50) NOT NULL,
  shop_id         VARCHAR(50) NOT NULL,
  tour_id         VARCHAR(50),
  allowed_days    JSON,               -- [1,2,3,4,5] — NULL = hérite du tour
  delivery_cutoff TIME,               -- NULL = hérite de tour_availability
  delivery_notes  VARCHAR(500),
  active          BOOLEAN DEFAULT TRUE,
  UNIQUE KEY uq_office_delivery (office_id, shop_id),
  FOREIGN KEY (office_id) REFERENCES ws_offices(id),
  FOREIGN KEY (shop_id)   REFERENCES ws_shops(id),
  FOREIGN KEY (tour_id)   REFERENCES ws_tours(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 15. DELIVERY FEE RULES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ws_delivery_fee_rules (
  id                    VARCHAR(50)  PRIMARY KEY,   -- UUID
  level                 VARCHAR(10)  NOT NULL,      -- site | office | tour | shop | global
  site_id               VARCHAR(50),
  office_client_id      VARCHAR(50),
  tour_id               VARCHAR(50),
  shop_id               VARCHAR(50),
  free_delivery         BOOLEAN DEFAULT FALSE,
  always_charge         BOOLEAN DEFAULT FALSE,
  fee_amount            DECIMAL(8,2) DEFAULT 0,
  free_delivery_minimum DECIMAL(8,2) DEFAULT 0,
  payment_type          VARCHAR(20)  DEFAULT 'immediate',  -- immediate | deferred
  active                BOOLEAN DEFAULT TRUE,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fee_level (level, active),
  FOREIGN KEY (site_id)          REFERENCES ws_office_delivery_sites(id),
  FOREIGN KEY (office_client_id) REFERENCES ws_offices(id),
  FOREIGN KEY (tour_id)          REFERENCES ws_tours(id),
  FOREIGN KEY (shop_id)          REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- NB: l'unicité "une seule règle active par cible" doit être
-- vérifiée côté backend (les index partiels n'existent pas en MySQL).

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FIN — 31 tables créées.
-- Cron (toutes les 5 min) — libération des réservations expirées :
--   UPDATE ws_stock_reservations SET released = TRUE
--   WHERE expires_at < NOW() AND released = FALSE;
-- ============================================================
