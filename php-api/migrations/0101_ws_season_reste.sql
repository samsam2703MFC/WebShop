-- 0101 — DIAGNOSTIC seul : quels produits portent encore season_id ?
--
-- La 0100 a refusé de supprimer ws_season : 8 produits référencent encore une
-- gamme locale. Le compte est venu du garde-fou, pas d'une vérification faite
-- avant — la lecture de /catalog/products ne montrait que les 76 produits
-- SERVIS (actifs et publiés côté ERP), pas le contenu de ws_products.
--
-- Cette migration ne modifie RIEN. Elle écrit dans la sortie de migrate.sh
-- quels produits sont concernés, pour qu'on décide sur des faits : ce sont
-- peut-être 8 lignes désactivées de longue date, auquel cas leur season_id est
-- une donnée morte ; ou des produits vivants, auquel cas la gamme locale dit
-- encore quelque chose et la table n'est pas à supprimer.
--
-- Rien à défaire, rien à rejouer : elle est idempotente par nature.

SET @colonne := (SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'ws_products'
                    AND column_name = 'season_id');

SET @s := IF(@colonne > 0,
  'SELECT p.id, p.name AS produit, p.active AS actif, p.season_id,
          (SELECT s.name FROM ws_season s WHERE s.id = p.season_id) AS gamme_locale
     FROM ws_products p
    WHERE p.season_id IS NOT NULL
    ORDER BY p.active DESC, p.id',
  'SELECT ''colonne season_id absente — plus rien à diagnostiquer'' AS resultat');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
