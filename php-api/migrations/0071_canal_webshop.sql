-- 0071 — Deux canaux enfin symétriques : webshop et livraison bureau.
--
-- CE QUI N'ALLAIT PAS. office_delivery est un vrai interrupteur de canal.
-- `active` n'en était pas un : il portait DEUX sens à la fois — « publié au
-- catalogue » et « vendu sur le webshop ». Et la requête du canal bureau
-- exigeait `p.active = 1`. Conséquence, vérifiable dans le code avant cette
-- migration :
--
--     un produit ne pouvait pas être « bureau seulement ».
--     Le retirer du webshop le retirait AUSSI de la livraison bureau.
--
-- Ça devient concret maintenant que les 73 produits Traiteur sont publiés sur
-- le webshop grand public : les réserver au bureau était impossible.
--
-- APRÈS, TROIS NOTIONS DISTINCTES, chacune sa colonne :
--     active          → publié au catalogue réseau (0 = brouillon : nulle part)
--     webshop         → vendu en click & collect
--     office_delivery → vendu en livraison bureau
--
-- RIEN NE BOUGE À L'ÉCRAN. webshop reçoit la valeur de active pour tout
-- l'existant : un produit aujourd'hui vendu sur le webshop l'est encore, un
-- brouillon reste un brouillon. La migration n'ouvre ni ne ferme aucun canal ;
-- elle rend seulement possible de les distinguer.
--
-- DEFAULT 1, comme office_delivery : un produit créé ensuite est vendu sur les
-- deux canaux sauf décision contraire. C'est le comportement actuel — l'absence
-- de colonne était traitée par COALESCE(..., 1).
--
-- À FAIRE DANS LA CONSOLE MARQUE (autre dépôt) : sa bascule « Webshop » écrit
-- encore ws_products.active. Tant qu'elle n'écrit pas `webshop`, l'éteindre
-- continuera de mettre le produit en brouillon — donc de le retirer des deux
-- canaux. La colonne existe et l'API l'accepte ; la vue doit s'en saisir.

SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_products');
-- @c compte la colonne SOUS SES DEUX NOMS. La 0072 renomme `webshop` en
-- `click_and_collect` ; sans ce second test, cette migration rejouée après elle
-- ne trouverait plus `webshop` et la RECRÉERAIT — deux colonnes pour un seul
-- canal, dont une que plus personne ne lit. Constaté au rejeu double de la CI :
-- une migration idempotente SEULE ne l'est pas forcément dans sa séquence.
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_products'
              AND column_name IN ('webshop', 'click_and_collect'));

SET @s := IF(@t = 0 OR @c > 0, 'DO 0',
  "ALTER TABLE ws_products
     ADD COLUMN webshop TINYINT(1) NOT NULL DEFAULT 1
     COMMENT 'CANAL : vendu en click and collect. Distinct de active (publie au catalogue) et de office_delivery (livraison bureau).'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Reprise de l'existant : uniquement à la CRÉATION de la colonne (@c = 0 lu
-- avant l'ALTER). Rejouée, la migration ne réécrit pas des choix faits depuis.
SET @s := IF(@t = 0 OR @c > 0, 'DO 0',
  'UPDATE ws_products SET webshop = active');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
