-- 0072 — La colonne du canal prend son vrai nom : click_and_collect.
--
-- Elle s'appelait `webshop` (migration 0071, quelques heures). Dans la table du
-- webshop, une colonne nommée « webshop » ne dit rien : tout y est du webshop.
-- Ce qu'elle porte, c'est UN canal de vente parmi deux, et il a un nom dans le
-- métier — le click & collect, par opposition à la livraison au bureau.
--
--     active            → publié au catalogue (0 = brouillon : nulle part)
--     click_and_collect → vendu en click & collect
--     office_delivery   → vendu en livraison au bureau
--
-- Renommée plutôt que doublée : personne ne la lit encore hors de l'API, la
-- console marque n'a pas commencé à l'écrire. Le coût est nul aujourd'hui, il
-- ne le serait plus demain.
--
-- IDEMPOTENTE : si la colonne porte déjà le nouveau nom, ou si l'ancienne
-- n'existe pas, la migration ne fait rien.

SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_products');
SET @old := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'ws_products' AND column_name = 'webshop');
SET @new := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'ws_products' AND column_name = 'click_and_collect');

SET @s := IF(@t = 0 OR @old = 0 OR @new > 0, 'DO 0',
  "ALTER TABLE ws_products
     CHANGE COLUMN webshop click_and_collect TINYINT(1) NOT NULL DEFAULT 1
     COMMENT 'CANAL : vendu en click and collect. Distinct de active (publie au catalogue) et de office_delivery (livraison bureau).'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Installation qui n'aurait jamais eu la 0071 (colonne absente sous les deux
-- noms) : on la crée directement au bon nom, reprise sur `active` comme le
-- faisait la 0071.
SET @s := IF(@t = 0 OR @old > 0 OR @new > 0, 'DO 0',
  "ALTER TABLE ws_products
     ADD COLUMN click_and_collect TINYINT(1) NOT NULL DEFAULT 1
     COMMENT 'CANAL : vendu en click and collect. Distinct de active (publie au catalogue) et de office_delivery (livraison bureau).'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF(@t = 0 OR @old > 0 OR @new > 0, 'DO 0',
  'UPDATE ws_products SET click_and_collect = active');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
