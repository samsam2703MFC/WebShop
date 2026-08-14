-- ---------------------------------------------------------------------------
-- jeu_essai.sql — un produit par raison de refus, et le nom le dit.
--
-- CE QU'IL VÉRIFIE. Six conditions décident qu'un produit s'affiche dans une
-- boutique. Une base de test vide les satisfait toutes trivialement : zéro
-- produit d'un côté, zéro de l'autre, le test passe sans rien prouver. Il faut
-- donc au moins un produit PAR condition, sinon la comparaison entre le
-- catalogue servi et le diagnostic ne porte sur rien.
--
-- Les noms sont les verdicts attendus. Quand le test échoue, la ligne fautive
-- se lit sans ouvrir ce fichier — c'est tout l'intérêt de ne pas les appeler
-- « Produit 1 », « Produit 2 ».
--
-- CE N'EST PAS UN SEED. Ces lignes n'existent que dans la base éphémère de
-- l'intégration continue, jamais servies à personne. La règle du dépôt — aucune
-- donnée inventée — vise ce que l'application affiche ; un jeu d'essai qui ne
-- quitte pas le test est ce qui permet de la tenir.
--
-- CONDITION NON COUVERTE, ET IL FAUT LE SAVOIR : « Hors saison à la date
-- demandée » dépend des tables product_availability_period* de l'ERP, absentes
-- du socle. availability_where() se neutralise quand elles manquent : la
-- condition ne se déclenche pas, ni dans le catalogue ni dans le diagnostic —
-- ils restent donc d'accord, mais sur ce point le test ne prouve rien.
-- ---------------------------------------------------------------------------

-- Deux boutiques : la sondée (2) et une AUTRE (3), dont une catégorie porte
-- le shop_id. Sans la ligne 3, le LEFT JOIN qui nomme la boutique propriétaire
-- rendrait NULL et le test passerait sans avoir rien prouvé.
INSERT INTO shops (id, name, city, active) VALUES
  (2, 'Boutique de test', 'Test', 1),
  (3, 'Autre boutique',   'Test', 1);

-- Catégories : une normale, une rattachée à une AUTRE boutique (3).
INSERT INTO ws_categories (id, label, sort_order, img, active, shop_id, slug) VALUES
  (1, 'Categorie de test',      1, 'cat.png',  1, NULL, 'cat-test'),
  (2, 'Categorie boutique 3',   2, 'cat3.png', 1, 3,    'cat-b3');

-- Deux sous-catégories peuplées : le webshop ouvre le second niveau dès une
-- seule, mais deux permettent de vérifier aussi le cas nominal.
INSERT INTO ws_category_subs (id, category_id, slug, label, img, sort_order, active) VALUES
  (1, 1, 'sous-a', 'Sous-categorie A', 'sa.png', 1, 1),
  (2, 1, 'sous-b', 'Sous-categorie B', 'sb.png', 2, 1);

-- ── Les produits. Le nom EST le verdict attendu. ──────────────────────────
INSERT INTO ws_products
  (id, name, cat_id, sub_cat_id, active, brand_whitelist, office_delivery, price, brand_mandatory) VALUES
  -- En ligne : aucune condition ne le refuse.
  (1, 'EN LIGNE',                        1, 1, 1, 1, 1, 5.00, 0),
  (2, 'EN LIGNE bis',                    1, 2, 1, 1, 1, 6.00, 0),
  -- 1. p.active = 0
  (3, 'Brouillon',                       1, 1, 0, 1, 1, 5.00, 0),
  -- 2. brand_whitelist = 0
  (4, 'Retire du reseau par la marque',  1, 1, 1, 0, 1, 5.00, 0),
  -- 3. ws_product_shops.active = 0 (ligne ci-dessous)
  (5, 'Non produit par cette boutique',  1, 1, 1, 1, 1, 5.00, 0),
  -- 4. office_delivery = 0 — ne se déclenche qu'en mode livraison bureau
  (6, 'Non eligible livraison bureau',   1, 1, 1, 1, 0, 5.00, 0),
  -- 6. aucune ligne shop_product → sans prix ERP pour cette boutique
  (7, 'Sans prix ERP',                   1, 1, 1, 1, 1, 5.00, 0),
  -- Navigation : vendu, mais introuvable par une catégorie.
  (8, 'Categorie orpheline',           999, NULL, 1, 1, 1, 5.00, 0),
  (9, 'Categorie autre boutique',        2, NULL, 1, 1, 1, 5.00, 0);

-- BUREAU SEULEMENT — le cas qui etait IMPOSSIBLE avant la migration 0071.
-- `active` portait deux sens : retirer un produit du webshop le mettait en
-- brouillon, donc le retirait AUSSI de la livraison bureau. Ce produit est
-- publie (active=1), ferme au click & collect (webshop=0), ouvert au bureau.
-- Si la colonne disparaissait ou cessait d'etre lue, il reapparaitrait sur le
-- webshop et le troisieme volet du test le dirait.
UPDATE ws_products SET webshop = 0, office_delivery = 1 WHERE id = 2;

-- Le produit 5 est explicitement retiré de l'assortiment de la boutique 2.
INSERT INTO ws_product_shops (product_id, shop_id, active, no_delivery) VALUES (5, 2, 0, 0);

-- LE PRIX VIENT DE L'ERP (shop_product.portion_price), par boutique. Il s'édite
-- dans l'ERP du magasin, pas dans les consoles.
--
-- TOUS SAUF le produit 7, qui doit tomber sur « Sans prix dans l'ERP pour cette
-- boutique ». Le produit 8 en a un aussi : il est bien EN VENTE, c'est sa
-- NAVIGATION qui manque, et les deux notions ne se confondent pas.
INSERT INTO shop_product (id_shop, id_product, portion_price) VALUES
  (2, 1, 5.00), (2, 2, 6.00), (2, 3, 5.00), (2, 4, 5.00),
  (2, 5, 5.00), (2, 6, 5.00), (2, 8, 5.00), (2, 9, 5.00);
