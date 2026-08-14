-- 0077 — Tous les produits publiés sont ouverts au click & collect.
--
-- Demandé explicitement le 14/08 (« active tous les produits webshop en
-- click & collect ») : 34 des 42 produits actifs portaient encore
-- click_and_collect = 0 — l'héritage de l'instantané 0071, pris quand ces
-- produits étaient en brouillon. Publiés depuis par la marque, ils restaient
-- invendables sur le canal click & collect sans que rien à l'écran ne le
-- rappelle.
--
-- PORTÉE : les produits PUBLIÉS (active = 1) seulement. Un brouillon garde
-- son canal tel quel — le rouvrir se décidera à sa publication, dans la
-- console, pas ici en masse.
--
-- Après cette migration, le canal se pilote produit par produit (ou par
-- catégorie/sous-catégorie) via la bascule « Webshop » de la console marque,
-- qui lit et écrit précisément cette colonne.
--
-- IDEMPOTENTE : rejouée, ne trouve plus rien à ouvrir. En CI, le jeu d'essai
-- d'après-migrations (jeu_essai_post) repose son cas « bureau seulement »
-- APRÈS ce fichier : l'ordre socle → migrations → post le garantit.

SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_products');
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_products' AND column_name = 'click_and_collect');
SET @s := IF(@t = 0 OR @c = 0, 'DO 0',
  'UPDATE ws_products SET click_and_collect = 1 WHERE active = 1 AND click_and_collect = 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
