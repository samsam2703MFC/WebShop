-- 0058 — Une étape de formule porte SA CATÉGORIE : ws_bundle_slots.cat_id.
--
-- Une étape s'appelait « Boissons » ou « Dessert » en texte libre, et chaque
-- choix devait ensuite être cherché dans TOUT le catalogue — au risque de
-- proposer de la biscuiterie dans une étape « Boissons ».
--
-- L'étape désigne désormais une catégorie du catalogue (ws_categories). Les
-- produits proposés à ses choix en découlent : les produits ACTIFS de cette
-- catégorie, et eux seuls. Le libellé reste modifiable pour l'affichage client
-- (« Votre boisson »), mais il ne décide plus de rien.
--
-- NULL = étape non rattachée (l'existant). Le back-office la signale, et la
-- sélection retombe alors sur le catalogue entier — comme avant, sans casse.

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'ws_bundle_slots'
            AND column_name = 'cat_id'),
  'DO 0',
  'ALTER TABLE ws_bundle_slots ADD COLUMN cat_id INT NULL DEFAULT NULL COMMENT ''Categorie du catalogue dont proviennent les choix de cette etape'''
));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Reprise : une étape dont le libellé correspond EXACTEMENT à une catégorie
-- active, et à une seule, est rattachée. Aucun rapprochement approximatif.
UPDATE ws_bundle_slots s
   SET s.cat_id = (SELECT c.id FROM ws_categories c WHERE c.label = s.label AND c.active = 1 LIMIT 1)
 WHERE s.cat_id IS NULL
   AND (SELECT COUNT(*) FROM ws_categories c2 WHERE c2.label = s.label AND c2.active = 1) = 1;
