-- =====================================================================
-- Franchise Buddy — Menus / configurable products (MySQL migration)
-- =====================================================================
-- Run against the Franchise Buddy database. The tables reference your
-- existing products table via product_id — adjust the REFERENCES clause
-- to your real products table name if it isn't `products`.
-- InnoDB · utf8mb4 · foreign keys.  See FRANCHISE_BUDDY_MENUS_API.md for
-- the JSON the API must return, and DATABASE_MENUS.md for the model.
-- =====================================================================

SET NAMES utf8mb4;

-- 1) Options that modify the base product (bread, sauce)
CREATE TABLE IF NOT EXISTS ws_product_options (
  id          VARCHAR(36)  NOT NULL PRIMARY KEY,
  product_id  INT          NOT NULL,
  code        VARCHAR(40)  NOT NULL,
  label       VARCHAR(120) NOT NULL,
  kind        ENUM('single','multi') NOT NULL DEFAULT 'single',
  required    TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order  INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_option (product_id, code),
  KEY idx_option_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ws_product_option_choices (
  id          VARCHAR(36)  NOT NULL PRIMARY KEY,
  option_id   VARCHAR(36)  NOT NULL,
  code        VARCHAR(40)  NOT NULL,
  label       VARCHAR(120) NOT NULL,
  price_delta DECIMAL(8,2) NOT NULL DEFAULT 0,
  sort_order  INT          NOT NULL DEFAULT 0,
  KEY idx_choice_option (option_id),
  CONSTRAINT fk_choice_option FOREIGN KEY (option_id)
    REFERENCES ws_product_options(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Menus / bundles (Menu, Full Menu)
CREATE TABLE IF NOT EXISTS ws_menus (
  id             VARCHAR(36)  NOT NULL PRIMARY KEY,
  product_id     INT          NOT NULL,
  code           VARCHAR(40)  NOT NULL,
  name           VARCHAR(120) NOT NULL,
  description    VARCHAR(250) NULL,
  price_modifier DECIMAL(8,2) NOT NULL DEFAULT 0,
  recommended    TINYINT(1)   NOT NULL DEFAULT 0,
  advantages     JSON         NULL,
  sort_order     INT          NOT NULL DEFAULT 0,
  active         TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_menu (product_id, code),
  KEY idx_menu_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ws_menu_slots (
  id          VARCHAR(36)  NOT NULL PRIMARY KEY,
  menu_id     VARCHAR(36)  NOT NULL,
  code        VARCHAR(40)  NOT NULL,
  label       VARCHAR(120) NOT NULL,
  required    TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_slot (menu_id, code),
  KEY idx_slot_menu (menu_id),
  CONSTRAINT fk_slot_menu FOREIGN KEY (menu_id)
    REFERENCES ws_menus(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ws_menu_slot_choices (
  id          VARCHAR(36)  NOT NULL PRIMARY KEY,
  slot_id     VARCHAR(36)  NOT NULL,
  code        VARCHAR(40)  NOT NULL,
  label       VARCHAR(120) NOT NULL,
  image       VARCHAR(250) NULL,
  price_delta DECIMAL(8,2) NOT NULL DEFAULT 0,
  sort_order  INT          NOT NULL DEFAULT 0,
  KEY idx_slotchoice_slot (slot_id),
  CONSTRAINT fk_slotchoice_slot FOREIGN KEY (slot_id)
    REFERENCES ws_menu_slots(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Upsells (optional add-ons)
CREATE TABLE IF NOT EXISTS ws_product_upsells (
  id          VARCHAR(36)  NOT NULL PRIMARY KEY,
  product_id  INT          NOT NULL,
  code        VARCHAR(40)  NOT NULL,
  label       VARCHAR(120) NOT NULL,
  image       VARCHAR(250) NULL,
  price_delta DECIMAL(8,2) NOT NULL DEFAULT 0,
  sort_order  INT          NOT NULL DEFAULT 0,
  KEY idx_upsell_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
