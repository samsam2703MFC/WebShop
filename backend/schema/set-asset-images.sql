-- ---------------------------------------------------------------------------
-- set-asset-images.sql
-- Pointe la base vers les icônes déposées sous public/assets/ (servies à
-- l'URL /webshop/assets/...). Sans ces UPDATE, ws_categories.img /
-- ws_assortments.img sont vides et le front n'affiche aucune icône.
--
-- PRÉREQUIS : les fichiers PNG doivent aussi être DÉPLOYÉS sur le serveur
--   /var/www/html/webshop/assets/category_icons/*.png
--   /var/www/html/webshop/assets/season_icons/*.png
-- (le chemin en base ne suffit pas si le fichier n'est pas là).
--
-- NB fichiers nommés en ASCII (patisserie.png) alors que le slug en base
-- peut porter un accent (pâtisserie) — d'où le mapping explicite ci-dessous.
-- ---------------------------------------------------------------------------

-- ── Catégories (7 icônes disponibles) ──────────────────────────────────────
UPDATE ws_categories SET img = '/webshop/assets/category_icons/boulangerie.png'  WHERE slug = 'boulangerie';
UPDATE ws_categories SET img = '/webshop/assets/category_icons/patisserie.png'   WHERE slug = 'pâtisserie';
UPDATE ws_categories SET img = '/webshop/assets/category_icons/traiteur.png'     WHERE slug = 'traiteur';
UPDATE ws_categories SET img = '/webshop/assets/category_icons/biscuiterie.png'  WHERE slug = 'biscuiterie';
UPDATE ws_categories SET img = '/webshop/assets/category_icons/viennoiserie.png' WHERE slug = 'viennoiserie';
UPDATE ws_categories SET img = '/webshop/assets/category_icons/quiches.png'      WHERE slug = 'quiches';
UPDATE ws_categories SET img = '/webshop/assets/category_icons/tartes.png'       WHERE slug = 'tartes';
-- Catégories encore sans icône : boissons, épicerie, fêtes-occasions,
-- bundle-promotion, b-2-b  (à créer + ajouter un UPDATE ici).

-- ── Saisons (2 icônes disponibles) ─────────────────────────────────────────
-- Les saisons vivent dans DEUX tables :
--   • ws_seasons  → a un slug (product.season = ws_seasons.slug). Match par slug.
--   • ws_assortments → pas de slug, seulement label. Match par label.
-- NB : ws_seasons n'est pas dans le schéma du repo ; confirme le nom exact
--      (ws_seasons vs ws_season) et la présence d'une colonne img.
--
-- ⚠️ IMPORTANT : le PHP API actuel (php-api/index.php) ne lit PAS ws_seasons et
--    ne renvoie aucun champ season sur les produits. Aujourd'hui les chips de
--    saison du front live proviennent de ws_assortments. Mettre à jour
--    ws_seasons.img ne suffira à afficher les icônes que lorsque l'API joindra
--    ws_seasons (à ajouter côté /catalog).

-- ws_seasons (par slug)
UPDATE ws_seasons    SET img = '/webshop/assets/season_icons/fete-des-meres.png' WHERE slug = 'fete-des-meres';
UPDATE ws_seasons    SET img = '/webshop/assets/season_icons/saint-valentin.png' WHERE slug = 'saint-valentin';

-- ws_assortments (par label — VÉRIFIE les libellés : SELECT id, label FROM ws_assortments;)
UPDATE ws_assortments SET img = '/webshop/assets/season_icons/fete-des-meres.png' WHERE label LIKE '%te des m%res%' OR label LIKE '%Fête des mères%';
UPDATE ws_assortments SET img = '/webshop/assets/season_icons/saint-valentin.png' WHERE label LIKE '%Valentin%';

-- ── Photos produits (10 placées) ───────────────────────────────────────────
-- Le nom de fichier = id du produit, d'où le CONCAT.
UPDATE ws_products
   SET img = CONCAT('/webshop/assets/product_pictures/', id, '.png')
 WHERE id IN (1121001, 1121003, 2110007,
              6700105, 6700107, 6700122, 6700111,
              6700007, 6700027, 6700098);
-- En attente : id 6700099 (collision bowl / sandwich féta) + 3 photos non
-- identifiées, encore dans uploads/.

-- ── Vérification ────────────────────────────────────────────────────────────
-- SELECT slug, img FROM ws_categories WHERE img LIKE '%category_icons%';
-- SELECT slug, img FROM ws_seasons WHERE img LIKE '%season_icons%';
-- SELECT label, img FROM ws_assortments WHERE img LIKE '%season_icons%';
-- SELECT id, img FROM ws_products WHERE img LIKE '%product_pictures%';
