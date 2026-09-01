-- 0105 — INVENTAIRE du miroir produits. Ne supprime RIEN.
--
-- POURQUOI UNE MIGRATION QUI NE MIGRE PAS. Le catalogue et les prix viennent
-- de l'ERP depuis hier, mais des tables locales en gardent une copie. Avant
-- d'en supprimer une seule il faut savoir ce qui existe VRAIMENT en base, et
-- ce qui la retient.
--
-- Ce matin, 0102 a échoué (ERROR 1828) parce que j'avais vérifié les lectures
-- du code sans regarder les contraintes du schéma. Cette migration-ci est la
-- vérification qui manquait : sa sortie part dans le journal de déploiement,
-- qui est le seul canal de lecture de cette base dont nous disposions sans
-- secret.
--
-- Elle est purement descriptive : aucun ALTER, aucun DROP, aucun INSERT.

SET SESSION group_concat_max_len = 1000000;

-- 1) QUI EXISTE ENCORE, et de quelle taille (estimation du moteur).
SELECT table_name AS `table`, engine, table_rows AS lignes_estimees,
       ROUND((data_length + index_length) / 1024) AS ko
  FROM information_schema.tables
 WHERE table_schema = DATABASE()
   AND table_name IN ('ws_products','ws_categories','ws_category_subs','ws_product_stock',
                      'ws_product_stock_defaults','ws_product_shops','ws_product_prices',
                      'ws_product_allergens','shop_product_portion_price','product_portion',
                      'product_availability_period_connection','ws_i18n','ws_shop_availability',
                      'ws_product_translations','product','ws_season')
 ORDER BY table_name;

-- 2) LE COMPTE EXACT. L'estimation d'InnoDB peut annoncer 0 sur une table
--    pleine : sur une décision de suppression, l'à-peu-près ne suffit pas.
--    Construit dynamiquement pour n'interroger que les tables présentes.
SET @q := (SELECT GROUP_CONCAT(
             CONCAT('SELECT ''', table_name, ''' AS `table`, COUNT(*) AS lignes FROM `', table_name, '`')
             SEPARATOR ' UNION ALL ')
             FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN ('ws_products','ws_categories','ws_category_subs','ws_product_stock',
                                 'ws_product_stock_defaults','ws_product_shops','ws_product_prices',
                                 'ws_product_allergens','shop_product_portion_price','product_portion',
                                 'product_availability_period_connection','ws_i18n','ws_shop_availability',
                                 'ws_product_translations','product','ws_season'));
SET @q := IFNULL(@q, 'SELECT ''aucune'' AS `table`, 0 AS lignes');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

-- 3) CE QUI LES RETIENT. Une table pointée par une clé étrangère ne se
--    supprime pas sans traiter d'abord la contrainte : c'est précisément ce
--    qui a fait échouer 0102.
SELECT referenced_table_name AS retenue, table_name AS depuis,
       column_name AS colonne, constraint_name AS contrainte
  FROM information_schema.key_column_usage
 WHERE table_schema = DATABASE()
   AND referenced_table_name IN ('ws_products','ws_categories','ws_category_subs','ws_product_stock',
                                 'ws_product_stock_defaults','ws_product_shops','ws_product_prices',
                                 'ws_product_allergens','shop_product_portion_price','product_portion',
                                 'product_availability_period_connection','ws_i18n','ws_shop_availability',
                                 'ws_product_translations','product','ws_season')
 ORDER BY referenced_table_name, table_name;
