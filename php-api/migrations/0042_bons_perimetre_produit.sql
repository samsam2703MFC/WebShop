-- 0042 — Bons à PÉRIMÈTRE PRODUIT : la remise d'un bon peut être limitée à un
-- produit et plafonnée à N pièces (les moins chères). Un bon PERCENT 100 +
-- scope_id_product + scope_max_qty=1 = « un produit offert » — sans jamais
-- pouvoir vider tout le panier.
-- Règle réseau : colonnes ADDITIVES uniquement, conventions ERP (id_product).
--   scope_id_product : NULL = remise sur le panier entier (comportement actuel)
--   scope_max_qty    : NULL = toutes les pièces du produit ; sinon plafond
-- Idempotent MySQL 8 (information_schema + PREPARE, même motif que 0009/0041).
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE promotion_order_discount ADD COLUMN scope_id_product INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='promotion_order_discount' AND column_name='scope_id_product');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE promotion_order_discount ADD COLUMN scope_max_qty INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='promotion_order_discount' AND column_name='scope_max_qty');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
