-- =====================================================================
-- 002 — DEMO Franchise Buddy (general/ERP DB) schema + seed
-- =====================================================================
-- ⚠️  DEV/DEMO ONLY. In production the general DB already exists and
-- is owned by the ERP — this file just recreates a representative
-- subset locally so the sync pipeline can be developed and tested.
-- Table/column names mimic the ERP side (French) on purpose so the
-- field-mapping config is exercised for real.
-- EXCLUDED on purpose (per spec): costing, suppliers, royalties, HR,
-- accounting — the webshop must never see those.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS fb_boutiques (
  code          VARCHAR(36)  NOT NULL PRIMARY KEY,
  enseigne      VARCHAR(120) NOT NULL,
  adresse       VARCHAR(250) NOT NULL,
  couleur       VARCHAR(16)  NOT NULL DEFAULT '#8D1D2C',
  horaires      JSON         NULL,
  click_collect TINYINT(1)   NOT NULL DEFAULT 1,
  actif         TINYINT(1)   NOT NULL DEFAULT 1,
  maj_le        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fb_familles (
  code          VARCHAR(36)  NOT NULL PRIMARY KEY,
  libelle       VARCHAR(120) NOT NULL,
  image         VARCHAR(250) NULL,
  ordre         INT          NOT NULL DEFAULT 0,
  actif         TINYINT(1)   NOT NULL DEFAULT 1,
  maj_le        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fb_articles (
  sku           VARCHAR(64)  NOT NULL PRIMARY KEY,
  famille_code  VARCHAR(36)  NOT NULL,
  designation   VARCHAR(200) NOT NULL,
  descriptif    TEXT         NULL,
  prix_ttc      DECIMAL(8,2) NOT NULL,
  taux_tva      DECIMAL(4,2) NOT NULL DEFAULT 6.00,
  image         VARCHAR(250) NULL,
  allergenes    JSON         NULL,
  portions      TINYINT(1)   NOT NULL DEFAULT 0,
  promo_croisee TINYINT(1)   NOT NULL DEFAULT 0,
  options_menu  TINYINT(1)   NOT NULL DEFAULT 0,
  retrait_seul  TINYINT(1)   NOT NULL DEFAULT 0,   -- = no_delivery
  delai_jours   TINYINT      NOT NULL DEFAULT 0,   -- = lead_time
  actif         TINYINT(1)   NOT NULL DEFAULT 1,
  maj_le        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_articles_famille (famille_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fb_stock (
  sku           VARCHAR(64)  NOT NULL,
  boutique_code VARCHAR(36)  NOT NULL,
  prix_boutique DECIMAL(8,2) NULL,
  dispo         TINYINT(1)   NOT NULL DEFAULT 1,
  stock_livraison INT        NULL,
  maj_le        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (sku, boutique_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fb_promos (
  code          VARCHAR(36)  NOT NULL PRIMARY KEY,
  libelle       VARCHAR(200) NOT NULL,
  type_promo    ENUM('pourcent','montant') NOT NULL DEFAULT 'pourcent',
  valeur        DECIMAL(8,2) NOT NULL,
  boutique_code VARCHAR(36)  NULL,
  debut         DATE         NULL,
  fin           DATE         NULL,
  actif         TINYINT(1)   NOT NULL DEFAULT 1,
  maj_le        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed (mirrors the frontend demo catalog) ────────────────────────

INSERT INTO fb_boutiques (code, enseigne, adresse, couleur) VALUES
  ('chatelain', 'L''Atelier By — Châtelain', 'Rue du Page 33, 1050 Ixelles',   '#8D1D2C'),
  ('sablon',    'L''Atelier By — Sablon',    'Rue de la Régence 12, 1000 Bxl', '#1D5C8D'),
  ('carre',     'L''Atelier By — Carré',     'En Féronstrée 84, 4000 Liège',   '#8D6A1D')
ON DUPLICATE KEY UPDATE enseigne = VALUES(enseigne);

INSERT INTO fb_familles (code, libelle, image, ordre) VALUES
  ('viennoiseries', 'Viennoiseries',   'img/cat-viennoiseries.png', 1),
  ('breads',        'Pains',           'img/cat-breads.png',        2),
  ('sweet',         'Douceurs',        'img/cat-sweet.png',         3),
  ('savory',        'Salé',            'img/cat-savory.png',        4),
  ('drinks',        'Boissons',        'img/cat-drinks.png',        5)
ON DUPLICATE KEY UPDATE libelle = VALUES(libelle);

INSERT INTO fb_articles (sku, famille_code, designation, prix_ttc, taux_tva, portions, promo_croisee, retrait_seul, delai_jours) VALUES
  ('SKU-CROIS-001', 'viennoiseries', 'Croissant pur beurre',          1.40, 6.00, 0, 0, 0, 0),
  ('SKU-PCHOC-002', 'viennoiseries', 'Pain au chocolat',              1.60, 6.00, 0, 0, 0, 0),
  ('SKU-CAMP-008',  'breads',        'Pain de campagne au levain',    4.20, 6.00, 0, 0, 0, 1),
  ('SKU-CERE-010',  'breads',        'Pain aux céréales',             4.50, 6.00, 0, 0, 0, 1),
  ('SKU-TARTE-014', 'sweet',         'Tarte citron meringuée',       18.50, 6.00, 1, 1, 0, 0),
  ('SKU-MACAR-017', 'sweet',         'Macarons (×8)',                14.00, 6.00, 0, 0, 0, 2),
  ('SKU-QUICHE-021','savory',        'Quiche lorraine',               6.80, 6.00, 0, 0, 1, 0),
  ('SKU-SANDW-022', 'savory',        'Sandwich jambon-beurre',        5.50, 6.00, 0, 0, 0, 0),
  ('SKU-JUS-030',   'drinks',        'Jus d''orange pressé 33cl',     3.80, 6.00, 0, 0, 0, 0),
  ('SKU-VIN-031',   'drinks',        'Vin blanc — Côtes de Gascogne',12.90,21.00, 0, 0, 0, 0)
ON DUPLICATE KEY UPDATE designation = VALUES(designation);

INSERT IGNORE INTO fb_stock (sku, boutique_code, dispo, stock_livraison)
SELECT a.sku, b.code, 1, NULL FROM fb_articles a CROSS JOIN fb_boutiques b;

UPDATE fb_stock SET stock_livraison = 6 WHERE sku = 'SKU-TARTE-014';

INSERT INTO fb_promos (code, libelle, type_promo, valeur) VALUES
  ('PROMO-WEB5', 'Réduction Webshop collecte', 'pourcent', 5.00)
ON DUPLICATE KEY UPDATE libelle = VALUES(libelle);
