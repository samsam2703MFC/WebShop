-- 0039 — Règle affinée : un produit OBLIGATOIRE doit être vendable sur AU
-- MOINS UN canal (webshop `active` OU livraison bureau `office_delivery`),
-- pas forcément le webshop. Réparation : seuls les obligatoires aux DEUX
-- canaux fermés récupèrent le webshop par défaut. (La 0038 avait forcé
-- active=1 sur tous les obligatoires — avec cette règle, le franchisor peut
-- de nouveau couper le webshop d'un obligatoire tant que la livraison bureau
-- reste ouverte.) Idempotent.
UPDATE ws_products
   SET active = 1
 WHERE brand_mandatory = 1 AND active = 0 AND COALESCE(office_delivery, 1) = 0;
