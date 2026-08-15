-- 0012 — Tables de configuration de la Console franchisé (app DC).
-- Deux tables lues par le back-office franchisé qui n'existaient pas encore
-- dans le schéma. Tant qu'elles sont absentes, les endpoints /franchisee/*
-- correspondants renvoient [] et le front garde son seed ; après cette
-- migration, la donnée devient pilotée par la base.
-- (ws_delivery_fee_rules existe déjà dans ws_schema.sql — l'endpoint lit la
--  table réelle, rien à créer ici.)
-- Idempotent MySQL 8 (CREATE TABLE IF NOT EXISTS + INSERT IGNORE).

-- 1) Zone de chalandise définie par le franchiseur (lecture seule côté franchisé).
CREATE TABLE IF NOT EXISTS ws_franchisor_catchment (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(160) NOT NULL,
  postcodes  TEXT NULL,                          -- codes postaux « 1000 · 1020 · … »
  exclusive  BOOLEAN NOT NULL DEFAULT TRUE,
  active     BOOLEAN NOT NULL DEFAULT TRUE
);

-- (Pas de table de règles produit : la disponibilité produit vit déjà dans
--  ws_products.active + ws_product_shops.active / no_delivery.)

-- 3) Départements ↔ delivery site ↔ office (cible de la synchro ERP clientb2b).
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

-- AUDIT GO-LIVE : les trois zones de chalandise « reprises de la maquette »
-- ont été RETIRÉES — c'étaient des données de démonstration insérées en
-- production par migrate.sh (règle « aucune donnée inventée »). La table est
-- créée vide ; les zones réelles se saisissent dans la console marque
-- (POST /franchisor/catchment).
-- NOTE PRODUCTION : une version précédente a pu poser les lignes id 1-3 —
-- à vérifier/supprimer côté base si elles ne correspondent à aucune zone
-- réellement paramétrée.
