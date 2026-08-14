-- 0080 — Les porteurs de menu SANS formule sortent aussi du catalogue.
--
-- La 0079 exigeait une composition (ws_bundles) pour reconnaître un porteur :
-- « un Menu ! », créé à l'écran puis laissé sans formule, est passé au
-- travers et restait en carte à 9,90 € sur la grille du webshop (constaté).
--
-- La signature se resserre sur ce qui ne trompe pas : active = 1, PAS de
-- catégorie (un porteur n'en reçoit jamais ; en production, aucun article
-- réel actif n'est sans catégorie — la sonde le vérifie chaque nuit), et
-- menu_override = 'on' (posé par la création de menu). La formule n'est plus
-- exigée : c'est précisément le porteur pas encore composé qui échappait.
--
-- IDEMPOTENTE : rejouée, ne trouve plus rien.

SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_products');
SET @s := IF(@t = 0, 'DO 0',
  "UPDATE ws_products
      SET active = 0
    WHERE active = 1
      AND cat_id IS NULL
      AND menu_override = 'on'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
