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

-- Les « données de test fournies » (portions actives des produits 6700237 et
-- 6700230 + deux PRIX boutique 2 : 14,90 € et 8,90 €) ont été RETIRÉES de ce
-- fichier à l'audit d'avant go-live : ces tables appartiennent à l'ERP, une
-- migration webshop n'y invente rien. NOTE PRODUCTION : la version précédente
-- de cette migration a pu insérer ces lignes (product_portion id 1-6,
-- shop_product_portion_price id 1-2) — à vérifier/purger côté ERP si elles ne
-- correspondent pas à une configuration réelle.
