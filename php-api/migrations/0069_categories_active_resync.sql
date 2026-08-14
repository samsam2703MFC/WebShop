-- 0069 — Remettre à jour le cache ws_categories.active, qui avait dérivé.
--
-- CE QU'EST CETTE COLONNE. Pas une décision de la marque : un CACHE. Une
-- seule requête l'écrit, dans /franchisor/product et /franchisor/category :
--
--     SET active = EXISTS(SELECT 1 FROM ws_products p2
--                          WHERE p2.cat_id = ws_categories.id AND p2.active = 1)
--
-- « au moins un produit actif ». Rien d'autre ne la rafraîchit — ni l'import
-- ERP, ni une migration, ni une écriture directe en base. Un produit activé
-- par un de ces chemins laisse le cache tel qu'il était.
--
-- CE QUI SE PASSAIT. Le cache avait dérivé, et la navigation du webshop en
-- dépendait. Relevé en production : « Biscuiterie » porte 6 produits actifs et
-- son cache vaut 0. Le webshop vendait donc les cookies sans afficher aucune
-- catégorie pour y revenir, ni son illustration — alors que la console marque
-- montrait sa bascule « webshop » sur ACTIF, parce qu'elle lit les produits,
-- c'est-à-dire la vérité. Deux écrans, deux réponses, une seule base.
--
-- LE WEBSHOP N'EN DÉPEND PLUS : /catalog/categories dérive de ses produits
-- servis et ne lit plus ce cache. Mais d'AUTRES lecteurs le lisent encore, et
-- rendent aujourd'hui un verdict faux :
--   · /franchisee/fr-dispo-cats  → affiche « Désactivée » à tort ;
--   · /franchisee/fr-assortiment → champ `navigation` : « Catégorie
--     désactivée », d'où les « 16 produits introuvables » du diagnostic ;
--   · 0058_bundle_slot_category  → ne rattache un créneau qu'à une catégorie
--     active : elle en manquait.
-- On les remet d'accord, une fois, avec la formule de l'API — pas une autre.
--
-- IDEMPOTENTE ET SANS INVENTION. Aucune valeur n'est choisie : chaque ligne
-- reçoit ce que la formule officielle calcule à partir des produits réellement
-- présents. Rejouée, elle ne change rien de plus. Une catégorie sans produit
-- actif retombe à 0, comme l'API l'aurait fait.
--
-- CE QU'ELLE NE FAIT PAS : toucher ws_products. L'accès réseau reste
-- brand_whitelist, décidé par la marque, et il n'est pas concerné.

SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_categories');
SET @p := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_products');
SET @a := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_categories' AND column_name = 'active');

SET @s := IF(@t = 0 OR @p = 0 OR @a = 0, 'DO 0',
  'UPDATE ws_categories c
      SET c.active = EXISTS(SELECT 1 FROM ws_products p
                             WHERE p.cat_id = c.id AND p.active = 1)');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
