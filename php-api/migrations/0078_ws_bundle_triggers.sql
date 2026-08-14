-- 0078 — Les menus ont des déclencheurs EXPLICITES : catégories ET sous-catégories.
--
-- AVANT. Le déclencheur d'un menu était implicite : la catégorie du produit
-- porteur de la formule, armée par ws_categories.menu_default. Une seule
-- catégorie par menu, pas de niveau sous-catégorie, pas de multi-sélection.
--
-- APRÈS. ws_bundle_triggers : une ligne = « ce menu se déclenche pour cette
-- catégorie » ou, plus fin, « pour cette catégorie ET cette sous-catégorie »
-- (sub_cat_id renseigné). Plusieurs lignes = multi-sélection, demandée
-- explicitement le 14/08 (« mettre les sous-catégories avec une possibilité
-- de faire des ET — donc un multi-select de catégories déclencheuses »).
--
-- LA RÉSOLUTION (API) : un produit est « en menu » si menu_override = 'on',
-- ou si — sans surcharge — un déclencheur correspond à lui (sa sous-catégorie
-- d'abord, sa catégorie sinon) ou si sa catégorie garde l'ancien
-- menu_default = 1. L'existant continue donc de marcher tel quel.
--
-- REPRISE : chaque produit porteur d'une formule dont la catégorie était
-- armée (menu_default = 1) reçoit son déclencheur explicite équivalent —
-- comportement identique avant/après, mais désormais visible et éditable.
--
-- IDEMPOTENTE : CREATE IF NOT EXISTS ; reprise INSERT IGNORE (clé unique).

CREATE TABLE IF NOT EXISTS `ws_bundle_triggers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL COMMENT 'produit PORTEUR de la formule (le menu)',
  `cat_id` int(11) NOT NULL COMMENT 'catégorie déclencheuse',
  `sub_cat_id` int(11) DEFAULT NULL COMMENT 'ET sous-catégorie (NULL = toute la catégorie)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trigger` (`product_id`,`cat_id`,`sub_cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_bundles');
SET @s := IF(@t = 0, 'DO 0',
  "INSERT IGNORE INTO ws_bundle_triggers (product_id, cat_id, sub_cat_id)
   SELECT DISTINCT b.product_id, p.cat_id, NULL
     FROM ws_bundles b
     JOIN ws_products p   ON p.id = b.product_id AND p.cat_id IS NOT NULL
     JOIN ws_categories c ON c.id = p.cat_id AND c.menu_default = 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
