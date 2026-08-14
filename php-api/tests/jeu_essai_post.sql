-- ---------------------------------------------------------------------------
-- jeu_essai_post.sql — ce qui ne peut exister QU'APRÈS les migrations.
--
-- Chargé en dernier : socle → jeu_essai → migrations → CE FICHIER. Tout ce qui
-- s'appuie sur une colonne créée par une migration doit vivre ici, sinon le
-- jeu d'essai échoue sur une colonne inconnue — constaté en écrivant l'étape.
--
-- Et le jeu d'essai principal ne doit PAS venir après les migrations : celles-ci
-- doivent s'appliquer à une base peuplée, comme en production.
-- ---------------------------------------------------------------------------

-- BUREAU SEULEMENT — le cas qui etait IMPOSSIBLE avant la migration 0071.
-- `active` portait deux sens : retirer un produit du webshop le mettait en
-- brouillon, donc le retirait AUSSI de la livraison bureau. Ce produit est
-- publie (active=1), ferme au click & collect (webshop=0), ouvert au bureau.
-- Si la colonne disparaissait ou cessait d'etre lue, il reapparaitrait sur le
-- webshop et le troisieme volet du test le dirait.
UPDATE ws_products SET webshop = 0, office_delivery = 1 WHERE id = 2;
