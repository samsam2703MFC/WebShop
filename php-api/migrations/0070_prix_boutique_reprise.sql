-- 0070 — Le prix passe sous la main du franchisé, SANS qu'aucun prix ne bouge.
--
-- CE QUI CHANGE. Le prix de vente d'un produit dans une boutique venait
-- exclusivement de l'ERP (shop_product.portion_price). Il s'édite désormais
-- dans la console du franchisé, et ws_product_prices devient la SOURCE UNIQUE
-- — lue par le catalogue ET par la facturation, ensemble : afficher un prix et
-- en encaisser un autre serait pire que le problème qu'on résout.
--
-- POURQUOI CETTE MIGRATION EXISTE. Sans elle, la bascule viderait les quatre
-- boutiques : ws_product_prices est quasi vide, et un produit sans prix n'est
-- pas vendable. On RECOPIE donc les prix ERP en vigueur, une fois. Aucun prix
-- n'est inventé, aucun n'est modifié : la boutique affiche après exactement ce
-- qu'elle affichait avant, et le franchisé part de là.
--
-- LA RÈGLE DU PRIX NON FIXÉ EST CONSERVÉE À L'IDENTIQUE. Un portion_price à 0
-- ou négatif signifie « produit listé, prix NON FIXÉ » — pas « gratuit ». Il
-- n'est donc pas repris : le produit reste non tarifé, donc hors vente, comme
-- aujourd'hui. C'est la même condition que celle du code (px <= 0 → continue).
--
-- DOUBLONS. shop_product peut porter plusieurs lignes par (boutique, produit) ;
-- le code retenait la PREMIÈRE par id (ORDER BY id_product, id, puis
-- array_key_exists). MIN(id) reproduit ce choix — pas MIN(prix), qui
-- retiendrait un autre montant que celui affiché jusqu'ici.
--
-- NE PAS ÉCRASER L'EXISTANT : les lignes ws_product_prices déjà présentes sont
-- la décision de quelqu'un. NOT EXISTS les préserve.
--
-- PAS DE « ON DUPLICATE KEY UPDATE » : ws_product_prices n'a AUCUN index, cette
-- clause n'aurait donc rien à quoi se raccrocher et AJOUTERAIT une ligne à
-- chaque exécution — deux prix pour un même produit, dont c'est le dernier rendu
-- par la base qui serait facturé. Constaté en écrivant la route de saisie.
-- NOT EXISTS ne dépend d'aucune clé et rend la migration réellement idempotente.

SET @t1 := (SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'shop_product');
SET @t2 := (SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'ws_product_prices');
SET @c  := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'shop_product' AND column_name = 'portion_price');

SET @s := IF(@t1 = 0 OR @t2 = 0 OR @c = 0, 'DO 0',
  "INSERT INTO ws_product_prices (product_id, shop_id, price, active)
   SELECT sp.id_product, sp.id_shop, sp.portion_price, 1
     FROM shop_product sp
     JOIN (SELECT id_shop, id_product, MIN(id) AS premier
             FROM shop_product
            WHERE portion_price > 0
            GROUP BY id_shop, id_product) pr
       ON pr.premier = sp.id
    WHERE NOT EXISTS (SELECT 1 FROM ws_product_prices w
                       WHERE w.product_id = sp.id_product AND w.shop_id = sp.id_shop)");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
