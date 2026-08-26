-- 0100 (EN ATTENTE) — Supprimer ws_season, remplacée par les périodes de l'ERP.
--
-- DANS pending/ À DESSEIN : migrate.sh ne joue que migrations/*.sql, pas les
-- sous-dossiers. Cette migration ne partira PAS au prochain déploiement. La
-- déplacer d'un cran vers le haut suffit à l'activer — quand, et seulement
-- quand, les conditions ci-dessous sont réunies.
--
-- CE QUI LA REMPLACE (relevé du 26/08 sur l'API de production) :
--   • GET /product-availability-periods              → 15 gammes, nom + période
--   • …?include=availability_periods sur les produits → 436/578 produits rattachés
--   • champ `photo` sur chaque période               → 11/15 en portent une
-- L'ERP fournit donc désormais les trois choses pour lesquelles ws_season
-- existait : le libellé, le lien produit→gamme, et l'image.
--
-- ⚠ CONDITION BLOQUANTE, non remplie au 26/08 : `webshop_active` vaut 0 sur
-- LES 15 périodes. erp_seasons() ne publie que les périodes actives ET
-- webshop_active — la barre de gammes est donc vide, et le restera après cette
-- suppression. Cochez d'abord webshop_active dans Franchise Buddy sur les
-- gammes à publier, vérifiez que la barre les affiche, PUIS jouez ceci.
-- Supprimer avant, c'est se priver du seul repli sans rien gagner.
--
-- CONDITION TECHNIQUE, vérifiée par la migration elle-même : aucun produit ne
-- doit encore pointer vers une gamme locale. Si ws_products.season_id porte
-- encore des valeurs, la migration NE FAIT RIEN et le dit — on ne supprime pas
-- une table dont quelque chose dépend encore, même sur une intention.

-- Combien de produits pointent encore vers une gamme locale ?
SET @restants := (SELECT COUNT(*) FROM ws_products WHERE season_id IS NOT NULL);

-- Trace lisible dans la sortie de migrate.sh : on saura POURQUOI rien n'a été
-- supprimé, au lieu de constater plus tard que la table est toujours là.
SELECT IF(@restants = 0,
          'ws_season : aucun produit rattaché — suppression',
          CONCAT('ws_season CONSERVÉE : ', @restants,
                 ' produit(s) portent encore season_id. Rien supprimé.')) AS resultat;

-- La colonne d'abord (elle référence la table), et seulement si elle est vide
-- de sens. Idempotent : absente → on ne tente pas.
SET @s := (SELECT IF(@restants = 0 AND COUNT(*) > 0,
  'ALTER TABLE ws_products DROP COLUMN season_id',
  'DO 0')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ws_products'
    AND column_name = 'season_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Puis la table.
SET @s := (SELECT IF(@restants = 0 AND COUNT(*) > 0,
  'DROP TABLE ws_season',
  'DO 0')
  FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'ws_season');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
