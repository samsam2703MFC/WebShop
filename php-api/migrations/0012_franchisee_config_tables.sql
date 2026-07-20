-- 0012 — Tables de configuration de la Console franchisé (app DC).
-- Quatre tables lues par le back-office franchisé qui n'existaient pas encore
-- dans le schéma. Tant qu'elles sont absentes, les endpoints /franchisee/*
-- correspondants renvoient [] et le front garde son seed ; après cette
-- migration, la donnée devient pilotée par la base.
-- Idempotent MySQL 8 (CREATE TABLE IF NOT EXISTS + INSERT IGNORE).

-- 1) Barème de frais de livraison en cascade (site → bureau → tournée → boutique).
CREATE TABLE IF NOT EXISTS ws_delivery_fee_rules (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  shop_id     INT NULL,                          -- NULL = toutes boutiques
  level       ENUM('site','office','tour','shop') NOT NULL,
  target      VARCHAR(160) NOT NULL,             -- libellé de la cible (site/bureau/tournée)
  free_from   DECIMAL(10,2) NULL,                -- franco (NULL = aucun)
  amount      DECIMAL(10,2) NOT NULL DEFAULT 0,  -- frais appliqués sous le franco
  payment     VARCHAR(60) NULL,                  -- mode de règlement des frais
  active      BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order  INT NOT NULL DEFAULT 0
);

-- 2) Zone de chalandise définie par le franchiseur (lecture seule côté franchisé).
CREATE TABLE IF NOT EXISTS ws_franchisor_catchment (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(160) NOT NULL,
  postcodes  TEXT NULL,                          -- codes postaux « 1000 · 1020 · … »
  exclusive  BOOLEAN NOT NULL DEFAULT TRUE,
  active     BOOLEAN NOT NULL DEFAULT TRUE
);

-- 3) Règles de disponibilité produit (exceptions écrasant la catégorie).
CREATE TABLE IF NOT EXISTS ws_product_availability_rules (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  shop_id    INT NULL,
  product    VARCHAR(160) NOT NULL,              -- libellé produit (ou lien futur ws_products.id)
  category   VARCHAR(120) NULL,
  rule       VARCHAR(120) NOT NULL,              -- ex. « Sur devis », « Saisonnier », « Délai spécifique »
  active     BOOLEAN NOT NULL DEFAULT TRUE
);

-- 4) Départements ↔ delivery site ↔ office (cible de la synchro ERP clientb2b).
CREATE TABLE IF NOT EXISTS b2b_client_company_department (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  client_id  VARCHAR(40) NOT NULL,               -- code client ERP
  company    VARCHAR(160) NULL,
  site       VARCHAR(200) NULL,
  office     VARCHAR(160) NULL,
  name       VARCHAR(120) NOT NULL,              -- nom du département
  effectif   INT NOT NULL DEFAULT 1,
  contact    VARCHAR(190) NULL,
  KEY idx_dept_client (client_id)
);

-- Valeurs initiales de configuration (reprises de la maquette validée) — à
-- ajuster en production. INSERT IGNORE + ids fixes = rejouable sans doublon.
INSERT IGNORE INTO ws_franchisor_catchment (id, name, postcodes, exclusive) VALUES
  (1, 'Bruxelles Capitale (19 communes)',
      '1000 · 1020 · 1030 · 1040 · 1050 · 1060 · 1070 · 1080 · 1081 · 1082 · 1083 · 1090 · 1120 · 1130 · 1140 · 1150 · 1160 · 1170 · 1180 · 1190 · 1200 · 1210', TRUE),
  (2, 'Brabant flamand — périphérie', '1600 · 1700 · 1800 · 1930 · 1932 · 3000 · 3001 · 3010 · 3020', TRUE),
  (3, 'Brabant wallon nord', '1300 · 1310 · 1320 · 1340 · 1348 · 1400 · 1410 · 1420', FALSE);

INSERT IGNORE INTO ws_delivery_fee_rules (id, level, target, free_from, amount, payment, sort_order) VALUES
  (1, 'shop', 'Toutes livraisons', 80.00, 4.50, 'Comptant', 40),
  (2, 'tour', 'Tournée Centre-ville', 120.00, 5.00, 'Selon bureau', 30);
