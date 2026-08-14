-- 0075 — Restauration des produits actifs depuis l'instantané du canal.
--
-- L'INCIDENT. Le 14/08 entre 13h48 et 14h18 (heure serveur), la production
-- est passée de 89 produits actifs à 6. Le journal bo_audit n'explique que
-- deux catégories (Biscuiterie, Boissons) : l'extinction de masse — Traiteur
-- 72→0, les catégories boutique 4, Tartes, Pâtisserie — n'y figure PAS. Les
-- trois seules routes d'écriture de l'API sont journalisées, et le serveur
-- n'a ni cron ni script hors dépôt qui touche ws_products (vérifié depuis le
-- serveur) : le canal fautif n'est pas identifié. La 0076 posera une vigie.
--
-- L'INSTANTANÉ QUI SAUVE. La 0071, appliquée à 13h44 — QUATRE MINUTES avant
-- l'incident — a initialisé le canal par « SET webshop = active » (renommé
-- click_and_collect par la 0072). Cette colonne n'a subi depuis que des
-- retouches ponctuelles journalisées. Elle est donc la photographie de
-- l'état d'avant : active = 1 exactement là où click_and_collect = 1.
--
-- CE QUE ÇA RESTAURE, ET RIEN D'AUTRE : les produits actifs ce matin-là et
-- éteints depuis. Un brouillon d'avant l'incident a click_and_collect = 0 —
-- il reste un brouillon. Les produits rallumés à la main depuis (journalisés)
-- sont déjà actifs : le WHERE active = 0 ne les touche pas.
--
-- IDEMPOTENTE ET INERTE APRÈS COUP : une fois la restauration faite, plus
-- aucune ligne ne satisfait le WHERE (tout cc=1 est actif). Sur une base de
-- test reconstruite, le jeu d'essai donne cc = active (0071/0072 rejouées) :
-- aucune ligne non plus. migrate.sh ne rejoue jamais une migration en
-- production.

SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_products');
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_products' AND column_name = 'click_and_collect');

SET @s := IF(@t = 0 OR @c = 0, 'DO 0',
  'UPDATE ws_products SET active = 1 WHERE active = 0 AND click_and_collect = 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Le cache des catégories suit (même règle que l'API : active dès qu'un
-- produit de la catégorie est actif).
SET @s := IF(@t = 0, 'DO 0',
  'UPDATE ws_categories c
      SET c.active = EXISTS(SELECT 1 FROM ws_products p
                             WHERE p.cat_id = c.id AND p.active = 1)');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
