-- ---------------------------------------------------------------------------
-- set-subcat-icons.sql
-- Les sous-catégories (ws_category_subs) ont img = NULL → aucune icône.
-- Option (a) : chaque sous-catégorie hérite de l'icône de sa catégorie parente
-- (ws_categories.img via category_id). Ne touche que les img encore vides.
-- ---------------------------------------------------------------------------

UPDATE ws_category_subs sub
  JOIN ws_categories c ON c.id = sub.category_id
   SET sub.img = c.img
 WHERE (sub.img IS NULL OR sub.img = '')
   AND c.img IS NOT NULL AND c.img <> '';

-- Sous-catégories orphelines (category_id NULL) : pas de parent → restent sans
-- icône. À rattacher à une catégorie si tu veux leur donner une icône.
--   SELECT id, slug FROM ws_category_subs WHERE category_id IS NULL;

-- Vérification
-- SELECT sub.slug, sub.img FROM ws_category_subs sub WHERE sub.img IS NOT NULL LIMIT 30;
-- SELECT COUNT(*) AS sans_img FROM ws_category_subs WHERE img IS NULL OR img='';
