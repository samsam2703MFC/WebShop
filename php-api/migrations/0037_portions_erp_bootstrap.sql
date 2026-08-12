-- 0037 — Amorçage des tables PORTIONS de l'ERP sur la base live.
-- Les structures viennent des dumps ERP fournis (mêmes noms/colonnes/index) ;
-- l'ERP en reste propriétaire — le webshop ne fait que les LIRE. Sans ces
-- tables sur le serveur, les portions ne s'affichaient nulle part (code
-- défensif). Idempotent : CREATE IF NOT EXISTS + INSERT IGNORE (clés uniques).
-- Pas de FK (les tables parents ERP peuvent arriver plus tard).

CREATE TABLE IF NOT EXISTS product_portion (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id_product INT NOT NULL,
  portion_type VARCHAR(32) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  display_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_portion_product_type (id_product, portion_type),
  KEY idx_product_portion_product_active (id_product, is_active)
);

CREATE TABLE IF NOT EXISTS shop_product_portion_price (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id_shop INT NOT NULL,
  id_product_portion INT NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shop_product_portion_price (id_shop, id_product_portion),
  KEY idx_shop_product_portion_price_portion (id_product_portion)
);

-- Données de test fournies (produits 6700237 et 6700230 ; prix boutique 2).
INSERT IGNORE INTO product_portion (id, id_product, portion_type, is_active, display_order) VALUES
(1, 6700237, 'ONE_HALF', 1, 1),
(2, 6700237, 'ONE_QUARTER', 1, 2),
(3, 6700237, 'ONE_EIGHTH', 1, 3),
(4, 6700230, 'ONE_HALF', 1, 1),
(5, 6700230, 'ONE_QUARTER', 1, 2),
(6, 6700230, 'ONE_EIGHTH', 1, 3);

INSERT IGNORE INTO shop_product_portion_price (id, id_shop, id_product_portion, price) VALUES
(1, 2, 4, 14.90),
(2, 2, 5, 8.90);
