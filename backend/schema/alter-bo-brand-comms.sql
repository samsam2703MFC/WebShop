-- ============================================================================
-- bo_brand_comms.sql — Ferme les gaps du back-office marque :
--   • Auth : bo_users / bo_user_shops / bo_audit (comptes + rôles + portée + audit)
--   • Marques : ws_brands (+ seed id=1) + FK id_brand
--   • Communications : ws_email_templates
-- Idempotent (CREATE IF NOT EXISTS + guards). Aucune donnée détruite.
-- ============================================================================
SET @db := DATABASE();

-- ─── Auth back-office ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bo_users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(200) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name  VARCHAR(120),
  role          ENUM('siege','franchise') NOT NULL,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at TIMESTAMP NULL DEFAULT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bo_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bo_user_shops (
  user_id INT NOT NULL,
  shop_id INT NOT NULL,
  PRIMARY KEY (user_id, shop_id),
  KEY idx_bus_shop (shop_id),
  FOREIGN KEY (user_id) REFERENCES bo_users(id),
  FOREIGN KEY (shop_id) REFERENCES shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bo_audit (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT,
  action     VARCHAR(60) NOT NULL,
  entity     VARCHAR(40),
  entity_id  INT,
  shop_id    INT NULL,
  payload    JSON,
  ip         VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bo_audit_entity (entity, entity_id, created_at),
  KEY idx_bo_audit_user   (user_id, created_at),
  FOREIGN KEY (user_id) REFERENCES bo_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Marques ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ws_brands (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  slug       VARCHAR(60) NOT NULL,
  logo_media VARCHAR(255),
  accent     VARCHAR(20) DEFAULT '#8D1D2C',
  tint       VARCHAR(20) DEFAULT '#fdf6f0',
  domain     VARCHAR(120),
  active     TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_brand_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO ws_brands (id, name, slug, active) VALUES (1, 'L''Atelier By', 'atelier-by', 1);

-- FK ws_vouchers.id_brand -> ws_brands (si colonne présente et FK absente)
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=@db AND table_name='ws_vouchers' AND column_name='id_brand');
SET @f := (SELECT COUNT(*) FROM information_schema.key_column_usage
            WHERE table_schema=@db AND table_name='ws_vouchers' AND column_name='id_brand' AND referenced_table_name IS NOT NULL);
SET @s := IF(@c=1 AND @f=0,
  "ALTER TABLE ws_vouchers ADD CONSTRAINT fk_vouchers_brand FOREIGN KEY (id_brand) REFERENCES ws_brands(id)",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- FK ws_shops.id_brand -> ws_brands (si colonne présente et FK absente)
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=@db AND table_name='ws_shops' AND column_name='id_brand');
SET @f := (SELECT COUNT(*) FROM information_schema.key_column_usage
            WHERE table_schema=@db AND table_name='ws_shops' AND column_name='id_brand' AND referenced_table_name IS NOT NULL);
SET @s := IF(@c=1 AND @f=0,
  "ALTER TABLE ws_shops ADD CONSTRAINT fk_wsshops_brand FOREIGN KEY (id_brand) REFERENCES ws_brands(id)",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─── Communications ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ws_email_templates (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  tpl_key    VARCHAR(60) NOT NULL,
  lang       VARCHAR(5)  NOT NULL,
  subject    VARCHAR(200) NOT NULL,
  body_html  MEDIUMTEXT NOT NULL,
  id_brand   INT NOT NULL DEFAULT 1,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tpl (tpl_key, lang, id_brand)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
