-- 0059 — Une étape de formule peut viser une SOUS-CATÉGORIE.
--
-- La catégorie (0058) est souvent trop large : « Pâtisserie » englobe les
-- tartes entières et les parts individuelles, et une étape « Dessert » d'un
-- menu du midi ne veut que les secondes.
--
-- sub_cat_id affine donc cat_id, sans le remplacer :
--   • sub_cat_id renseigné → les produits de CETTE sous-catégorie ;
--   • sinon cat_id         → toute la catégorie ;
--   • ni l'un ni l'autre   → tout le catalogue (comportement d'origine).
--
-- Les produits proposés restent, dans tous les cas, les seuls produits ACTIFS
-- du catalogue webshop : une étape ne doit jamais offrir ce qui n'est pas
-- vendable.

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'ws_bundle_slots'
            AND column_name = 'sub_cat_id'),
  'DO 0',
  'ALTER TABLE ws_bundle_slots ADD COLUMN sub_cat_id INT NULL DEFAULT NULL COMMENT ''Sous-categorie affinant cat_id — NULL = toute la categorie'''
));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
