-- 0044 — Règle « produit OBLIGATOIRE ⇒ vendable sur au moins un canal ».
-- Constat live : 31 produits brand_mandatory=1 étaient fermés sur les DEUX
-- canaux (active=0 ET office_delivery=0) — invisibles partout alors que
-- « obligatoire ». Réparation idempotente (rejouable sans effet de bord).
-- La GARDE permanente (trigger anti-réouverture par l'ERP) est fournie à part
-- (optional/0044b_ws_products_mandatory_trigger.sql) pour ne pas bloquer le
-- déploiement si le compte MySQL n'a pas le privilège TRIGGER.
UPDATE ws_products
   SET active = 1
 WHERE brand_mandatory = 1 AND active = 0 AND COALESCE(office_delivery, 1) = 0;
