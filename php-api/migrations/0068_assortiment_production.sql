-- 0068 — La bascule du franchisé dit « je produis », pas « c'est vendable ».
--
-- QUI DÉCIDE QUOI. L'ACCÈS d'un produit est une décision de la MARQUE, pour
-- tout le réseau : c'est ws_products.brand_whitelist, déjà respectée par le
-- webshop, par le diagnostic et par l'API — qui refuse déjà, en le disant,
-- qu'une boutique rouvre un produit que la marque a retiré.
--
-- La PRODUCTION est une décision du FRANCHISÉ : il fabrique cet article, ou
-- non. C'est ws_product_shops.active, et c'est son seul levier.
--
-- CE QUI SE PASSAIT. L'écran présentait cette bascule comme « Actif ·
-- vendable » et sa catégorie comme « la désactiver retire tous ses produits de
-- la vente » : le franchisé croyait décider de la mise en vente, qui ne lui
-- appartient pas. Les deux notions se ressemblaient assez pour qu'on les
-- confonde, et une seule colonne portait les deux.
--
-- REMISE À ZÉRO ASSUMÉE. Les valeurs actuelles ont été posées sous l'ancienne
-- lecture : elles disent « je ne veux pas le vendre », pas « je ne le produis
-- pas ». Les réinterpréter serait leur prêter une intention qu'elles n'ont
-- jamais eue. On repart donc de « tout est produit », et chaque franchisé
-- redéclare ce qu'il ne fabrique pas.
--
-- CONSÉQUENCE À CONNAÎTRE, ET ELLE EST IMMÉDIATE : tout produit qu'un
-- franchisé avait retiré redevient vendable au premier chargement suivant.
-- C'est le prix d'un changement de sens assumé plutôt que deviné. La marque
-- garde son verrou : brand_whitelist n'est pas touchée, et un produit retiré
-- du catalogue réseau reste invisible partout.
--
-- no_delivery N'EST PAS TOUCHÉE : « je le produis mais je ne le livre pas »
-- reste un choix distinct, et il n'a pas changé de sens.

SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_product_shops');
SET @a := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_product_shops' AND column_name = 'active');

SET @s := IF(@t = 0 OR @a = 0, 'DO 0',
  'UPDATE ws_product_shops SET active = 1 WHERE active = 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Le commentaire de colonne porte la règle là où on la lira : dans le schéma.
SET @s := IF(@t = 0 OR @a = 0, 'DO 0',
  "ALTER TABLE ws_product_shops MODIFY active TINYINT(1) NOT NULL DEFAULT 1
   COMMENT 'FRANCHISE : je produis cet article (0 = je ne le fabrique pas). L acces reseau est ws_products.brand_whitelist, decide par la marque.'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
