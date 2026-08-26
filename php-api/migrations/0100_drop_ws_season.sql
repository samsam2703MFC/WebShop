-- 0100 — Supprimer ws_season : les gammes viennent de l'ERP.
--
-- CE QUI LA REMPLACE (vérifié sur l'API de production le 26/08) :
--   • GET /product-availability-periods                → 15 gammes, nom + période
--   • …?include=availability_periods sur les produits  → 436/578 produits rattachés
--   • champ `photo` sur chaque période                 → 11/15 en portent une,
--     rapatriées par sync_season_photos.php (l'URL ERP est signée, 1200 s)
-- L'ERP fournit donc les trois choses pour lesquelles ws_season existait : le
-- libellé, le lien produit→gamme, et l'image.
--
-- CONDITIONS RÉUNIES AVANT DE JOUER CECI :
--   • webshop_active coché sur les 10 gammes saisonnières (les 5 autres —
--     « test », « Standard », « Bureau », « B.-2-B. », « Automnale » — restent
--     hors vitrine) ;
--   • /catalog/assortments sert 8 gammes avec leurs photos ERP, vérifié en
--     production ;
--   • plus aucune lecture de ws_season dans le code (index.php) : le repli
--     local et les deux jointures ont été retirés dans le même commit.
--
-- GARDE-FOU : la migration ne supprime RIEN tant qu'un produit porte encore
-- season_id, et le dit dans la sortie de migrate.sh. On ne supprime pas une
-- table dont quelque chose dépend encore, même sur une intention.
-- Idempotente : rejouée après coup, elle ne trouve ni colonne ni table et ne
-- fait rien.

-- La colonne existe-t-elle encore ? (Sans ce test, un second passage échoue
-- sur « Unknown column season_id » et bloque migrate.sh, qui est fail-fast.)
SET @colonne := (SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'ws_products'
                    AND column_name = 'season_id');

-- Combien de produits pointent encore vers une gamme locale ? La valeur par
-- défaut est posée en clair ; elle n'est recalculée que si la colonne existe.
-- Seuls SELECT / ALTER / DROP passent par PREPARE — les mêmes constructions
-- que la 0099, jouée sans incident ce matin.
SET @restants := 0;
SET @s := IF(@colonne > 0,
  'SELECT COUNT(*) INTO @restants FROM ws_products WHERE season_id IS NOT NULL',
  'SELECT 0 INTO @restants');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Trace lisible dans la sortie de migrate.sh : on saura POURQUOI rien n'a été
-- supprimé, au lieu de constater plus tard que la table est toujours là.
SELECT IF(@restants = 0,
          'ws_season : aucun produit rattaché — suppression',
          CONCAT('ws_season CONSERVÉE : ', @restants,
                 ' produit(s) portent encore season_id. Rien supprimé.')) AS resultat;

-- La colonne d'abord (elle référence la table).
SET @s := IF(@restants = 0 AND @colonne > 0,
  'ALTER TABLE ws_products DROP COLUMN season_id',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Puis la table.
SET @s := (SELECT IF(@restants = 0 AND COUNT(*) > 0,
  'DROP TABLE ws_season',
  'DO 0')
  FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'ws_season');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
