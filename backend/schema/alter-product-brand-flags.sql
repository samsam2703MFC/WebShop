-- ============================================================================
-- product_brand_flags.sql — Gouvernance catalogue par la MARQUE (réseau/ERP).
--
--   ws_products.brand_webshop  (défaut 1)
--     = produit ACCEPTÉ PAR LA MARQUE pour la vente webshop (whitelist réseau).
--       Les catégories « liées » en découlent : une catégorie n'apparaît que si
--       elle contient au moins un produit brand_webshop=1 disponible.
--       Défaut 1 = non-régressif (tous les produits actuels restent visibles).
--
--   ws_products.brand_mandatory  (défaut 0)
--     = produit OBLIGATOIRE imposé par la marque : le shop ne peut pas le
--       retirer de son assortiment (ws_product_shops verrouillé côté back-office).
--
-- Flags posés par l'ERP/marque (catalogue réseau). Idempotent (guarded).
-- ============================================================================
SET @db := DATABASE();

-- 1) brand_webshop
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=@db AND table_name='ws_products' AND column_name='brand_webshop');
SET @s := IF(@c=0,
  "ALTER TABLE ws_products
     ADD COLUMN brand_webshop TINYINT(1) NOT NULL DEFAULT 1
     COMMENT 'Accepte par la marque pour le webshop (whitelist reseau)'
     AFTER active",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) brand_mandatory
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=@db AND table_name='ws_products' AND column_name='brand_mandatory');
SET @s := IF(@c=0,
  "ALTER TABLE ws_products
     ADD COLUMN brand_mandatory TINYINT(1) NOT NULL DEFAULT 0
     COMMENT 'Produit obligatoire impose par la marque (non desactivable par le shop)'
     AFTER brand_webshop",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
