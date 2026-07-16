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

-- ── Saisons (ws_assortments) ────────────────────────────────────────────────
-- Une SEULE table saison existe : ws_assortments (id, shop_id, label, img,
-- active). Pas de ws_seasons, pas de colonne season sur ws_products.
--
-- État réel en base : UNE seule ligne  →  id=1  "Saveurs d'été".
-- Les 2 icônes disponibles (saint-valentin, fete-des-meres) ne correspondent
-- PAS à cette ligne : il manque une icône « été ». Donc rien à mettre à jour
-- ici pour l'instant. Dès qu'une icône été existe :
--   UPDATE ws_assortments SET img = '/webshop/assets/season_icons/ete.png' WHERE id = 1;
--
-- (Les icônes saint-valentin / fete-des-meres n'auront de sens que si tu ajoutes
--  les lignes de saison correspondantes dans ws_assortments.)

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
-- SELECT label, img FROM ws_assortments WHERE img LIKE '%season_icons%';
-- SELECT id, img FROM ws_products WHERE img LIKE '%product_pictures%';
