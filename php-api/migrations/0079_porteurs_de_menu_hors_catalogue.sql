-- 0079 — Les porteurs de menu créés à l'écran sortent du catalogue.
--
-- Constat utilisateur (14/08) : « Menu ! » et « un Menu ! », créés par
-- « + Créer un menu » de la console marque, apparaissaient en CARTES sur la
-- grille du webshop, à côté des vrais articles. Un porteur de menu n'est pas
-- un article : le menu n'atteint le client qu'à travers ses déclencheurs, sur
-- les produits qu'ils couvrent. La route de création écrit désormais
-- active = 0 ; cette migration ramène les porteurs DÉJÀ créés au même état.
--
-- PORTÉE VOLONTAIREMENT ÉTROITE : seulement la signature exacte des porteurs
-- nés du bouton de création — une composition (ws_bundles), PAS de catégorie
-- (cat_id IS NULL : ils n'en reçoivent jamais), et menu_override = 'on' (posé
-- par la création). Un VRAI produit vendu qui porte sa propre formule a une
-- catégorie : il n'est pas touché.
--
-- IDEMPOTENTE : l'UPDATE re-exécuté ne trouve plus rien à changer.

SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_bundles');
SET @s := IF(@t = 0, 'DO 0',
  "UPDATE ws_products p
      SET p.active = 0
    WHERE p.active = 1
      AND p.cat_id IS NULL
      AND p.menu_override = 'on'
      AND EXISTS (SELECT 1 FROM ws_bundles b WHERE b.product_id = p.id)");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
