-- ---------------------------------------------------------------------------
-- socle_test.sql — le schéma MINIMAL dont les tests ont besoin, et lui seul.
--
-- POURQUOI CE FICHIER EXISTE. ws_products et ws_categories ne sont créées par
-- AUCUNE migration : elles viennent de la base de l'ERP. Les 71 migrations ne
-- font qu'ajouter des colonnes autour. Une intégration continue ne peut donc
-- pas reconstruire le schéma en rejouant les migrations — il lui manque le
-- socle. C'est la raison pour laquelle les cinq fichiers de test du dépôt
-- n'ont jamais tourné ailleurs que sur une base de production.
--
-- CE N'EST PAS UNE COPIE DE LA PRODUCTION, et il ne faut pas le lire comme
-- telle : uniquement les tables et les colonnes que le chemin testé touche.
-- Si le code se met à dépendre d'une colonne absente d'ici, le test échoue en
-- CI avec le nom de la colonne — c'est le comportement voulu. Un socle figé
-- qu'on compléterait en silence donnerait la confiance sans la vérification.
--
-- Aucune donnée n'y figure : le jeu d'essai vit dans jeu_essai.sql, séparé,
-- pour qu'on ne confonde jamais une table avec ce qu'on y a mis.
--
-- IL DÉCRIT LA PRODUCTION **AVANT** LES MIGRATIONS NON ENCORE APPLIQUÉES, et
-- c'est essentiel : la CI rejoue par-dessus toutes les migrations postérieures
-- au numéro inscrit dans socle_niveau.txt. Y ajouter à la main une colonne
-- qu'une migration doit créer ferait passer la CI sans jamais exécuter cette
-- migration — exactement le trou par lequel la 0070 est arrivée en production.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `shops` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(190) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `active` tinyint(4) DEFAULT 1,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `slug` varchar(190) DEFAULT NULL,
  `street` varchar(190) DEFAULT NULL,
  `street_num` varchar(20) DEFAULT NULL,
  `webshop_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `accent` varchar(20) DEFAULT NULL,
  `tint` varchar(20) DEFAULT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `discount_type` varchar(20) DEFAULT NULL,
  `discount_value` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;
CREATE TABLE IF NOT EXISTS `ws_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `label` varchar(120) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `img` varchar(255) DEFAULT NULL,
  `menu_default` tinyint(4) DEFAULT 0,
  `active` tinyint(4) DEFAULT 1,
  `shop_id` int(11) DEFAULT NULL,
  `slug` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;
CREATE TABLE IF NOT EXISTS `ws_category_subs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) DEFAULT NULL,
  `label` varchar(120) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `active` tinyint(4) DEFAULT 1,
  `slug` varchar(60) DEFAULT NULL,
  `img` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;
CREATE TABLE IF NOT EXISTS `ws_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(190) DEFAULT NULL,
  `cat_id` int(11) DEFAULT NULL,
  `active` tinyint(4) DEFAULT 1,
  `brand_whitelist` tinyint(4) DEFAULT 1,
  `office_delivery` tinyint(4) DEFAULT 1,
  `season_id` int(11) DEFAULT NULL,
  `price` decimal(8,2) DEFAULT 0.00,
  `brand_mandatory` tinyint(4) DEFAULT 0,
  `sub_cat_id` int(11) DEFAULT NULL,
  `img` varchar(255) DEFAULT NULL,
  `tag_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `portions` tinyint(4) DEFAULT 0,
  `cross_portion` tinyint(4) DEFAULT 0,
  `menu_override` varchar(8) DEFAULT NULL,
  `base_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;
CREATE TABLE IF NOT EXISTS `ws_product_shops` (
  `product_id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'FRANCHISE : je produis cet article (0 = je ne le fabrique pas). L acces reseau est ws_products.brand_whitelist, decide par la marque.',
  `no_delivery` tinyint(4) DEFAULT 0,
  PRIMARY KEY (`product_id`,`shop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;
-- ws_product_prices porte en PRODUCTION une CLÉ ÉTRANGÈRE vers ws_products,
-- découverte en la faisant échouer : une migration qui y insérait depuis
-- shop_product s'est arrêtée sur « foreign key constraint fails » — l'ERP
-- référence des produits absents de ws_products. Le socle le reproduit, pour
-- qu'un test ne passe pas ici sur une table plus permissive qu'en vrai.
CREATE TABLE IF NOT EXISTS `ws_product_prices` (
  `product_id` int(11) DEFAULT NULL,
  `shop_id` int(11) DEFAULT NULL,
  `price` decimal(8,2) DEFAULT NULL,
  `active` tinyint(4) DEFAULT 1,
  KEY `idx_wpp_product` (`product_id`),
  CONSTRAINT `ws_product_prices_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `ws_products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;
CREATE TABLE IF NOT EXISTS `ws_product_allergens` (
  `product_id` int(11) DEFAULT NULL,
  `allergen` varchar(60) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;
CREATE TABLE IF NOT EXISTS `ws_tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tag` varchar(60) DEFAULT NULL,
  `slug` varchar(60) DEFAULT NULL,
  `bg_color` varchar(20) DEFAULT NULL,
  `text_color` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;
CREATE TABLE IF NOT EXISTS `ws_season` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(40) DEFAULT NULL,
  `name` varchar(80) DEFAULT NULL,
  `img` varchar(255) DEFAULT NULL,
  -- Production : /catalog/assortments filtre sur active et trie sur
  -- sort_order — le socle sans elles rendait la route 500 en local.
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;
CREATE TABLE IF NOT EXISTS `shop_product` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_shop` int(11) DEFAULT NULL,
  `id_product` int(11) DEFAULT NULL,
  `portion_price` decimal(8,2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;

-- bo_audit : créée en production bien avant le socle (schema initial du
-- back-office). Son absence ici a fait rendre un 500 à POST /franchisor/product
-- pendant un test E2E local — l'UPDATE passait, puis l'INSERT d'audit tombait.
-- Le socle doit porter tout ce que les routes écrivent, sinon un test échoue
-- sur une table que la production a toujours eue.
CREATE TABLE IF NOT EXISTS `bo_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(64) DEFAULT NULL,
  `entity` varchar(64) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `shop_id` int(11) DEFAULT NULL,
  `payload` text DEFAULT NULL,
  `ip` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;

-- Les tables de FORMULES (menus). Créées en production par le schéma initial
-- du back-office (ws_schema.sql, hors migrations) — comme bo_audit, elles
-- doivent exister ici pour que les routes de menus soient testables, sinon un
-- test « passe » en sautant tout l'écran. Colonnes = celles que l'API lit,
-- enrichies par les 0003/0057/0058/0059 (kind, min/max_select, cost,
-- product_id, cat_id, sub_cat_id) — le socle est postérieur à ces migrations.
CREATE TABLE IF NOT EXISTS `ws_bundles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `name` varchar(190) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `price_modifier` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;
CREATE TABLE IF NOT EXISTS `ws_bundle_slots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bundle_id` int(11) NOT NULL,
  `label` varchar(190) DEFAULT NULL,
  `required` tinyint(1) NOT NULL DEFAULT 0,
  `kind` varchar(8) NOT NULL DEFAULT 'single',
  `min_select` int(11) NOT NULL DEFAULT 1,
  `max_select` int(11) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `cat_id` int(11) DEFAULT NULL,
  `sub_cat_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;
CREATE TABLE IF NOT EXISTS `ws_bundle_slot_choices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slot_id` int(11) NOT NULL,
  `label` varchar(190) DEFAULT NULL,
  `img` varchar(255) DEFAULT NULL,
  `delta` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `product_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;

-- Production : /catalog/stock et les réservations lisent ws_product_stock ;
-- les promos croisées lisent ws_pricing_rules. Absentes du socle, ces routes
-- rendaient 500 en local alors qu'elles fonctionnent en ligne.
CREATE TABLE IF NOT EXISTS `ws_product_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `mode` varchar(16) DEFAULT NULL,
  `qty_total` int(11) NOT NULL DEFAULT 0,
  `qty_sold` int(11) NOT NULL DEFAULT 0,
  `qty_reserved` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;
CREATE TABLE IF NOT EXISTS `ws_pricing_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rule_type` varchar(32) NOT NULL,
  `shop_id` int(11) DEFAULT NULL,
  `x` int(11) DEFAULT NULL,
  `y` int(11) DEFAULT NULL,
  `threshold` int(11) DEFAULT NULL,
  `label` varchar(190) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;
