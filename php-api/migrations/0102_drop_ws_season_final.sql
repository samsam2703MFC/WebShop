-- 0102 — Suppression effective de ws_season.
--
-- La 0100 a refusé (garde-fou : 8 produits portaient encore season_id). La 0101
-- a dit lesquels. Voici le relevé, conservé ICI pour que l'information ne
-- disparaisse pas avec la table :
--
--   id        produit                                     actif  gamme locale
--   3400001   Coca-Cola                                     1    Été
--   6700122   FlipFlap - Poulet Pané                        1    Automne
--   1121001   Tarte Fraises                                 0    Été
--   1121003   Tarte TuttiFrutti                             0    Été
--   1500001   Gâteaux Anniversaire | Chocolat 10 personnes  0    Pâques
--   2110007   Choux farçis & sauce champignons              0    Noël
--   6700105   Assiette - Tagliata de bœuf                   0    Automne
--   6700111   Assiette - Saumon belle-vue                   0    Noël
--
-- POURQUOI CES 8 VALEURS SONT MORTES. Les gammes visées (Été, Automne, Pâques,
-- Noël) sont un jeu local hérité, sans équivalent publié côté ERP. Depuis le
-- commit qui a retiré les trois dernières lectures, AUCUN code ne lit season_id
-- ni ws_season : la gamme d'un produit est réécrite depuis les périodes de
-- l'ERP, et pour ces 8 produits elle vaut NULL. Vérifié en production sur
-- Coca-Cola, en vente et sans gamme affichée. Les supprimer ne change donc rien
-- à ce que voit un client ou un franchisé — c'est du ménage, pas une bascule.
--
-- Idempotente : colonne absente → plus rien à faire.

SET @colonne := (SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'ws_products'
                    AND column_name = 'season_id');

-- Libérer les 8 rattachements. Fait AVANT le DROP COLUMN pour que la vigie
-- d'audit et les éventuelles contraintes voient une table cohérente à chaque
-- étape, et pour que le compte affiché ci-dessous soit vérifiable.
SET @s := IF(@colonne > 0,
  'UPDATE ws_products SET season_id = NULL WHERE season_id IS NOT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- LA CLÉ ÉTRANGÈRE D'ABORD. Premier passage : « ERROR 1828 — Cannot drop
-- column 'season_id': needed in a foreign key constraint
-- fk_ws_products_season ». Les lectures avaient été vérifiées dans le code,
-- pas les contraintes du schéma. migrate.sh étant fail-fast, la migration
-- n'avait pas été marquée appliquée : seul l'UPDATE ci-dessus était passé.
-- Le nom n'est PAS écrit en dur : on le lit dans le schéma. Une base restaurée
-- ailleurs peut porter un nom généré différent, et une migration qui ne
-- retrouve pas SA contrainte échouerait au même endroit.
SET @fk := (SELECT CONSTRAINT_NAME FROM information_schema.key_column_usage
             WHERE table_schema = DATABASE() AND table_name = 'ws_products'
               AND column_name = 'season_id' AND referenced_table_name IS NOT NULL
             LIMIT 1);
SET @s := IF(@fk IS NOT NULL,
  CONCAT('ALTER TABLE ws_products DROP FOREIGN KEY `', @fk, '`'),
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Puis la colonne.
SET @s := IF(@colonne > 0, 'ALTER TABLE ws_products DROP COLUMN season_id', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Puis la table.
SET @s := (SELECT IF(COUNT(*) > 0, 'DROP TABLE ws_season', 'DO 0')
             FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_season');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Trace : ce qui reste (doit être 0 colonne et 0 table).
SELECT (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'ws_products'
            AND column_name = 'season_id')                     AS colonne_restante,
       (SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = 'ws_season') AS table_restante;
