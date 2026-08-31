-- 0105 — Verdict A de l'audit « zéro table locale » : suppression des vestiges.
--
-- Quatre tables sans lecteur réel ni écrivain (AUDIT_ZERO_TABLE_LOCALE.md §2),
-- code nettoyé au même commit :
--   · ws_customers — l'identité client vit dans la table ERP `client` (fusion
--     ancienne). Retirés avec elle : le repli de l'analyse géo
--     (geo_private_clients) et les deux JOIN du BO (bo/routes.php), re-pointés
--     sur `client` (name/surname). Les lignes restantes datent d'AVANT la
--     fusion — une copie morte, pas la vérité.
--   · ws_product_prices — prix local par boutique : le prix de vente vient de
--     l'ERP (portion_price_gross, prix_produits). Retirés : l'unique écriture
--     (/admin/price, aucun appelant front) et l'unique lecture (repli
--     d'affichage de l'écran portions).
--   · ws_product_availability / ws_category_availability — overlays de dispo
--     par produit/catégorie JAMAIS écrits : la route franchisée qui devait les
--     remplir est fermée (« QUI DÉCIDE » : la marque choisit l'assortiment) et
--     aucun INSERT n'existe nulle part. basket_pa() est réduit à
--     ws_products.no_delivery — le comportement d'overlays vides, c'est-à-dire
--     ce qui a toujours tourné.
--
-- LES CLÉS ÉTRANGÈRES D'ABORD (leçon de la 0102). Une base amorcée depuis un
-- ws_schema.sql ancien peut porter des FK ENTRANTES vers ces tables sous des
-- noms générés (vu dans le schéma : ws_stock_reservations.customer_id,
-- ws_shop_exceptions.created_by → ws_customers). On les lit dans
-- information_schema et on les lève une à une — plusieurs passes ; s'il en
-- restait une de plus, le DROP échouerait et migrate.sh (fail-fast)
-- remonterait l'erreur au lieu de casser en silence.
-- Idempotente : tables absentes → chaque étape devient DO 0.

-- ── FK entrantes vers ws_customers (jusqu'à 3) ──
SET @s := (SELECT CONCAT('ALTER TABLE `', TABLE_NAME, '` DROP FOREIGN KEY `', CONSTRAINT_NAME, '`')
             FROM information_schema.key_column_usage
            WHERE table_schema = DATABASE() AND referenced_table_name = 'ws_customers' LIMIT 1);
SET @s := IFNULL(@s, 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT CONCAT('ALTER TABLE `', TABLE_NAME, '` DROP FOREIGN KEY `', CONSTRAINT_NAME, '`')
             FROM information_schema.key_column_usage
            WHERE table_schema = DATABASE() AND referenced_table_name = 'ws_customers' LIMIT 1);
SET @s := IFNULL(@s, 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT CONCAT('ALTER TABLE `', TABLE_NAME, '` DROP FOREIGN KEY `', CONSTRAINT_NAME, '`')
             FROM information_schema.key_column_usage
            WHERE table_schema = DATABASE() AND referenced_table_name = 'ws_customers' LIMIT 1);
SET @s := IFNULL(@s, 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── FK entrantes vers ws_product_prices (défensif, ×2) ──
SET @s := (SELECT CONCAT('ALTER TABLE `', TABLE_NAME, '` DROP FOREIGN KEY `', CONSTRAINT_NAME, '`')
             FROM information_schema.key_column_usage
            WHERE table_schema = DATABASE() AND referenced_table_name = 'ws_product_prices' LIMIT 1);
SET @s := IFNULL(@s, 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT CONCAT('ALTER TABLE `', TABLE_NAME, '` DROP FOREIGN KEY `', CONSTRAINT_NAME, '`')
             FROM information_schema.key_column_usage
            WHERE table_schema = DATABASE() AND referenced_table_name = 'ws_product_prices' LIMIT 1);
SET @s := IFNULL(@s, 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── FK entrantes vers ws_product_availability (défensif, ×2) ──
SET @s := (SELECT CONCAT('ALTER TABLE `', TABLE_NAME, '` DROP FOREIGN KEY `', CONSTRAINT_NAME, '`')
             FROM information_schema.key_column_usage
            WHERE table_schema = DATABASE() AND referenced_table_name = 'ws_product_availability' LIMIT 1);
SET @s := IFNULL(@s, 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT CONCAT('ALTER TABLE `', TABLE_NAME, '` DROP FOREIGN KEY `', CONSTRAINT_NAME, '`')
             FROM information_schema.key_column_usage
            WHERE table_schema = DATABASE() AND referenced_table_name = 'ws_product_availability' LIMIT 1);
SET @s := IFNULL(@s, 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── FK entrantes vers ws_category_availability (défensif, ×2) ──
SET @s := (SELECT CONCAT('ALTER TABLE `', TABLE_NAME, '` DROP FOREIGN KEY `', CONSTRAINT_NAME, '`')
             FROM information_schema.key_column_usage
            WHERE table_schema = DATABASE() AND referenced_table_name = 'ws_category_availability' LIMIT 1);
SET @s := IFNULL(@s, 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT CONCAT('ALTER TABLE `', TABLE_NAME, '` DROP FOREIGN KEY `', CONSTRAINT_NAME, '`')
             FROM information_schema.key_column_usage
            WHERE table_schema = DATABASE() AND referenced_table_name = 'ws_category_availability' LIMIT 1);
SET @s := IFNULL(@s, 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── Les tables ──
DROP TABLE IF EXISTS ws_category_availability;
DROP TABLE IF EXISTS ws_product_availability;
DROP TABLE IF EXISTS ws_product_prices;
DROP TABLE IF EXISTS ws_customers;

-- Trace : doit rendre 0 partout.
SELECT (SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE()
            AND table_name IN ('ws_customers','ws_product_prices',
                               'ws_product_availability','ws_category_availability')) AS tables_restantes,
       (SELECT COUNT(*) FROM information_schema.key_column_usage
          WHERE table_schema = DATABASE()
            AND referenced_table_name IN ('ws_customers','ws_product_prices',
                                          'ws_product_availability','ws_category_availability')) AS fk_restantes;
