-- 0107 — Supprimer les tables de l'ERP que plus AUCUN code ne lit.
--
-- CE QUI A CHANGÉ AVANT D'EN ARRIVER LÀ. Trois lectures ont été déplacées vers
-- les endpoints, chacune vérifiée contre la donnée réelle :
--   • allergènes      → grouped_allergens (49 produits documentés contre 42) ;
--   • gammes          → availability_periods ;
--   • portions        → portions[] avec has_shop_price / shop_price_gross.
-- Plus aucun fichier PHP du dépôt ne lit product, product_portion,
-- shop_product_portion_price, product_availability_period(_connection),
-- flattened_recipe_ingredient, material_allergen_connection ni allergen.
--
-- POURQUOI CETTE MIGRATION SE GARDE ELLE-MÊME. 0102 a échoué ce matin en
-- ERROR 1828 : j'avais vérifié les lectures du code sans regarder les
-- contraintes du schéma. Ici, chaque suppression est conditionnée à l'absence
-- de clé étrangère POINTANT VERS la table. Une table encore retenue n'est pas
-- supprimée, et la sortie le dit — plutôt qu'un échec de déploiement, ou pire,
-- une contrainte cassée dans une table qui ne nous appartient pas.
--
-- CE QUE ÇA LAISSE DEBOUT, ET C'EST VOULU. `product` est pointée par treize
-- clés étrangères venant de transaction_product, todo_task et onze tables
-- promotion_* ; `product_portion` par transaction_product. Les supprimer
-- exigerait de toucher ces tables-là, ce qui dépasse le miroir produits. Elles
-- restent, inertes, jusqu'à décision explicite.

SET SESSION group_concat_max_len = 1000000;

-- Une table est supprimable si elle EXISTE et que RIEN ne la référence.
SET @cibles := 'product,product_portion,product_availability_period,product_availability_period_connection,shop_product_portion_price';

SET @q := (SELECT GROUP_CONCAT(CONCAT('DROP TABLE IF EXISTS `', t.table_name, '`') SEPARATOR '; ')
             FROM information_schema.tables t
            WHERE t.table_schema = DATABASE()
              AND FIND_IN_SET(t.table_name, @cibles)
              AND NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage k
                               WHERE k.table_schema = DATABASE()
                                 AND k.referenced_table_name = t.table_name));

-- GROUP_CONCAT ne rend qu'UNE instruction préparable à la fois : on boucle
-- avec un curseur serait plus lourd que le gain. On prépare donc table par
-- table, ce que fait la procédure ci-dessous.
DROP PROCEDURE IF EXISTS ws_drop_libres;
DELIMITER //
CREATE PROCEDURE ws_drop_libres()
BEGIN
  DECLARE fini INT DEFAULT 0;
  DECLARE nom VARCHAR(64);
  DECLARE cur CURSOR FOR
    SELECT t.table_name FROM information_schema.tables t
     WHERE t.table_schema = DATABASE()
       AND FIND_IN_SET(t.table_name, @cibles)
       AND NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage k
                        WHERE k.table_schema = DATABASE()
                          AND k.referenced_table_name = t.table_name);
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET fini = 1;
  OPEN cur;
  boucle: LOOP
    FETCH cur INTO nom;
    IF fini = 1 THEN LEAVE boucle; END IF;
    SET @s := CONCAT('DROP TABLE IF EXISTS `', nom, '`');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END LOOP;
  CLOSE cur;
END //
DELIMITER ;
CALL ws_drop_libres();
DROP PROCEDURE ws_drop_libres;

-- Ce qui reste, et ce qui le retient — dit dans le journal de déploiement.
SELECT t.table_name AS `restante`,
       (SELECT COUNT(*) FROM information_schema.key_column_usage k
         WHERE k.table_schema = DATABASE() AND k.referenced_table_name = t.table_name) AS cles_qui_la_retiennent
  FROM information_schema.tables t
 WHERE t.table_schema = DATABASE() AND FIND_IN_SET(t.table_name, @cibles)
 ORDER BY t.table_name;
