-- ---------------------------------------------------------------------------
-- jeu_essai.sql — un produit par raison de refus, et le nom le dit.
--
-- CE QU'IL VÉRIFIE. Quelques conditions décident qu'un produit s'affiche, et
-- elles tiennent toutes dans ws_products. Une base de test vide les satisfait
-- toutes trivialement : zéro produit d'un côté, zéro de l'autre, le test passe
-- sans rien prouver. Il faut donc au moins un produit PAR condition, sinon la
-- comparaison entre le catalogue servi et le diagnostic ne porte sur rien.
--
-- Les noms sont les verdicts attendus. Quand le test échoue, la ligne fautive
-- se lit sans ouvrir ce fichier — c'est tout l'intérêt de ne pas les appeler
-- « Produit 1 », « Produit 2 ».
--
-- ET LES NOMS DOIVENT SUIVRE LE MODÈLE. Trois d'entre eux ont menti pendant un
-- temps : « Retiré du réseau par la marque », « Non produit par cette
-- boutique », « Catégorie autre boutique » nommaient des refus qui n'existent
-- plus depuis que le modèle a été simplifié. Le test restait vert — il compare
-- le catalogue au diagnostic, pas au nom — mais la ligne à l'écran racontait
-- l'inverse de ce qu'elle prouvait. Un jeu d'essai qui ment coûte plus cher
-- qu'un jeu d'essai absent : on le croit.
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
-- ORDRE DE CHARGEMENT, ET IL COMPTE : socle → CE FICHIER → migrations →
-- jeu_essai_post.sql. Les migrations doivent s'appliquer à une base PEUPLÉE,
-- comme en production : une migration qui recopie des données ne révèle ses
-- violations de contrainte que s'il y a des données à recopier. Sur une base
-- vide, elle passe — et c'est exactement ce qui a laissé la 0070 arriver en
-- production et bloquer tous les déploiements.
-- Ce fichier n'utilise donc QUE le schéma du socle. Ce qui dépend d'une colonne
-- créée par une migration vit dans jeu_essai_post.sql.
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
  -- L'ANCIEN VERROU, DÉSARMÉ. brand_whitelist = 0 retenait 73 produits actifs
  -- sur 90 ; la migration 0073 fait tomber la colonne. Ce produit la porte
  -- encore ici (le socle est la production AVANT migrations) et doit ressortir
  -- EN LIGNE : si la purge était incomplète — une requête qui lirait encore la
  -- colonne, une migration non rejouée — il resterait caché et le test le dirait.
  (4, 'EN LIGNE malgre ancienne whitelist', 1, 1, 1, 0, 1, 5.00, 0),
  -- L'AUTRE VERROU DÉSARMÉ. La ligne ws_product_shops plus bas retire ce
  -- produit de l'assortiment de la boutique 2. Il n'y a plus d'assortiment par
  -- boutique — tous les produits sont communs — donc cette ligne ne doit plus
  -- rien refuser. Elle reste ici EXPRÈS : c'est ce qui prouve que la table
  -- n'est plus lue, plutôt que de le supposer parce qu'on a retiré la jointure.
  (5, 'EN LIGNE malgre ws_product_shops', 1, 1, 1, 1, 1, 5.00, 0),
  -- 2. office_delivery = 0 — ne se déclenche qu'en mode livraison bureau
  (6, 'Non eligible livraison bureau',   1, 1, 1, 1, 0, 5.00, 0),
  -- 3. prix mis a 0 plus bas → « prix non fixe », donc hors vente
  (7, 'Prix non fixe',                   1, 1, 1, 1, 1, 5.00, 0),
  -- Navigation : vendu, mais introuvable par une catégorie.
  (8, 'Categorie orpheline',           999, NULL, 1, 1, 1, 5.00, 0),
  -- Sa catégorie porte shop_id = 3, une AUTRE boutique. C'était un refus de
  -- vente ; ça n'en est plus un — une catégorie n'appartient plus à personne.
  -- En ligne ET joignable, donc, et le second volet du test le vérifie.
  (9, 'EN LIGNE malgre categorie shop_id', 2, NULL, 1, 1, 1, 5.00, 0);


-- Produit 5 : retiré de l'assortiment de la boutique 2. Ne refuse plus rien
-- (voir plus haut) — la ligne est le témoin, pas la condition.
INSERT INTO ws_product_shops (product_id, shop_id, active, no_delivery) VALUES (5, 2, 0, 0);

-- LE PRIX EST DANS ws_products.price, posé plus haut avec chaque produit —
-- une seule table décide de tout ce qui concerne le webshop. Le produit 7 a
-- son prix mis à zéro ci-dessous : « prix non fixé », donc hors vente.
UPDATE ws_products SET price = 0 WHERE id = 7;

-- L'ANOMALIE DE PRODUCTION, REPRODUITE EXPRÈS : l'ERP référence des produits
-- qui n'existent pas dans ws_products. Ce n'est pas une hypothèse — c'est ce
-- qu'une clé étrangère a révélé en faisant échouer une migration en
-- production, et en bloquant tous les déploiements derrière elle
-- (migrate.sh est fail-fast).
--
-- La ligne ci-dessous vaut avertissement permanent : toute migration qui
-- recopie shop_product vers une table contrainte échouera ICI, en intégration
-- continue, au lieu d'être découverte après le rsync. Ne pas la retirer parce
-- qu'elle « salit » le jeu d'essai : c'est précisément son rôle.
--
-- shop_product n'a pas de clé étrangère (table de l'ERP), l'insertion passe.
INSERT INTO shop_product (id_shop, id_product, portion_price) VALUES
  (2, 424242, 9.99);
