-- ---------------------------------------------------------------------------
-- seed-seasons.sql
-- Ajoute les lignes de saison manquantes dans ws_assortments et rattache
-- chaque saison à son icône (public/assets/season_icons/{slug}.png).
--
-- ⚠️ PRÉREQUIS POUR L'AFFICHAGE : le endpoint GET /catalog/assortments N'EST
--    PAS encore implémenté dans php-api/index.php. Tant qu'il manque, le front
--    live retombe sur le seed (img/season-*.png) et ces lignes ne s'affichent
--    PAS. Il faut coder l'endpoint (SELECT id,label,img FROM ws_assortments
--    WHERE active=1 AND (shop_id=? OR shop_id IS NULL)) pour que ça remonte.
--
-- shop_id = NULL  →  saison visible pour toutes les boutiques.
-- ---------------------------------------------------------------------------

-- 1) Saison été (id=1 "Saveurs d'été") : icône TEMPORAIRE réutilisée
--    (season_icons/ete.png = copie de la tarte aux fruits, à remplacer par une
--    vraie icône été plus tard).
UPDATE ws_assortments SET img = '/webshop/assets/season_icons/ete.png' WHERE id = 1;

-- 2) Saisons manquantes correspondant aux 2 icônes déjà déposées.
INSERT INTO ws_assortments (shop_id, label, img, active) VALUES
  (NULL, 'Saint-Valentin', '/webshop/assets/season_icons/saint-valentin.png', 1),
  (NULL, 'Fête des mères',  '/webshop/assets/season_icons/fete-des-meres.png', 1);

-- Vérification
-- SELECT id, shop_id, label, img, active FROM ws_assortments ORDER BY id;
