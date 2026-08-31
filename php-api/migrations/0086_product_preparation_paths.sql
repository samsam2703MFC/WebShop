-- 0086 — Parcours de préparation produit (configuration RÉSEAU).
--
-- Un parcours de préparation est défini par la marque (franchiseur) et partagé
-- par toutes les boutiques : aucune colonne shop_id, aucune colonne id_brand
-- (réseau unique). C'est de la CONFIGURATION — pas un planning de production.
--
-- AUCUN SEED : les parcours naissent exclusivement via l'API
-- /franchisor/preparation-* (jeton admin). Rien n'est pré-rempli ici.
--
-- Idempotent (CREATE TABLE IF NOT EXISTS) — rejouable sans casse, conformément
-- à la convention migrate.sh.
--
-- Note FK : pas de contrainte dure sur ws_products(id) — en prod le schéma
-- « à plat » fait varier moteur/collation entre tables, et une FK inter-tables
-- non appariée ferait ÉCHOUER la migration. Le lien produit est un index
-- UNIQUE ; l'existence du produit est validée par l'API avant toute écriture.
-- Les FK INTERNES à la feature (étape→parcours, étape→groupe) sont sûres : même
-- migration, mêmes moteur/charset.

CREATE TABLE IF NOT EXISTS product_preparation_batch_group (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(150) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_preparation_path (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_prep_path_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_preparation_step (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  path_id           INT NOT NULL,
  sort_order        INT NOT NULL DEFAULT 0,
  description       TEXT,
  duration_seconds  INT NOT NULL DEFAULT 0,
  -- Batch : les DEUX colonnes ou AUCUNE (règle appliquée côté API).
  batch_group_id    INT NULL,
  batch_capacity    INT NULL,
  -- Four : indépendant des autres étapes. batch_capacity = products_per_tray ×
  -- trays_per_oven (règle appliquée côté API).
  uses_oven         TINYINT(1) NOT NULL DEFAULT 0,
  products_per_tray INT NULL,
  trays_per_oven    INT NULL,
  -- Jusqu'à trois photos : la CLÉ de l'objet image (fichier indépendant sous
  -- assets/preparation/<clé>). La copie d'un parcours duplique les fichiers
  -- sous de nouvelles clés (objets indépendants).
  image_key_1       VARCHAR(80) NULL,
  image_key_2       VARCHAR(80) NULL,
  image_key_3       VARCHAR(80) NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_prep_step_path  (path_id, sort_order),
  KEY idx_prep_step_group (batch_group_id),
  CONSTRAINT fk_prep_step_path  FOREIGN KEY (path_id)        REFERENCES product_preparation_path(id)        ON DELETE CASCADE,
  CONSTRAINT fk_prep_step_group FOREIGN KEY (batch_group_id) REFERENCES product_preparation_batch_group(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
