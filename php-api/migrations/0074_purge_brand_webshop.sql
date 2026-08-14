-- 0074 — brand_webshop tombe, si tant est qu'elle existe quelque part.
--
-- Cette colonne n'a JAMAIS existé dans le schéma de ce dépôt : aucune
-- migration ne la crée, le socle de test ne la porte pas, et l'unique code
-- qui la lisait — la route catalog du /bo/franchisor — échouait en SQL à
-- chaque appel, ce qui a été découvert et corrigé pendant la purge (0073).
-- Sa suppression est demandée explicitement, comme brand_whitelist et
-- brand_mandatory avant elle.
--
-- Alors pourquoi une migration ? Parce que « n'existe pas dans le modèle »
-- n'est pas « n'existe pas en production » : une colonne posée à la main il y
-- a longtemps ne se voit dans aucun dépôt. Ce fichier transforme la
-- supposition en fait : après lui, la colonne n'existe NULLE PART, et la
-- sonde quotidienne (dépôt console franchisé) la compte désormais parmi les
-- reliques dont la présence est une régression.
--
-- La 0073 étant déjà appliquée et enregistrée en production, ce DROP ne
-- pouvait pas s'y greffer — migrate.sh ne rejoue jamais une migration.
--
-- IDEMPOTENTE : DO 0 si la table ou la colonne manque — le cas attendu.

SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_products');
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_products' AND column_name = 'brand_webshop');
SET @s := IF(@t = 0 OR @c = 0, 'DO 0', 'ALTER TABLE ws_products DROP COLUMN brand_webshop');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
