-- 0010 — Disponibilité produit par canal : livraison bureau (« apricot » / ws_office).
-- Aujourd'hui un produit n'a qu'une visibilité webshop (ws_products.active, canal
-- « ruby » = click & collect). On ajoute un drapeau DÉDIÉ et INDÉPENDANT pour le
-- canal livraison bureau : office_delivery 1/0. Un produit peut être actif en
-- click & collect sans l'être en livraison bureau, et inversement.
-- DEFAULT 1 : les produits existants restent disponibles en livraison bureau
-- (comportement actuel : aucun filtre canal), le franchisor curationne ensuite.
-- Idempotent MySQL 8.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_products ADD COLUMN office_delivery TINYINT(1) NOT NULL DEFAULT 1','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_products' AND column_name='office_delivery');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
