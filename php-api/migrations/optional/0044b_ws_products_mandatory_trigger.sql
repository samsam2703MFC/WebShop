-- 0044b (OPTIONNEL — à jouer À LA MAIN, hors migrate.sh) — GARDE permanente :
-- un produit OBLIGATOIRE ne peut jamais rester fermé sur les deux canaux, même
-- via un import ERP. À toute écriture de ws_products, si brand_mandatory=1 et
-- active=0 et office_delivery=0, on rouvre le webshop (active=1).
-- Corps d'UNE seule instruction (SET) -> pas de DELIMITER nécessaire.
-- Nécessite le privilège TRIGGER sur la base. Idempotent (DROP IF EXISTS).
DROP TRIGGER IF EXISTS trg_ws_products_mandatory_bi;
CREATE TRIGGER trg_ws_products_mandatory_bi
BEFORE INSERT ON ws_products
FOR EACH ROW
  SET NEW.active = IF(NEW.brand_mandatory = 1 AND NEW.active = 0 AND COALESCE(NEW.office_delivery, 1) = 0, 1, NEW.active);

DROP TRIGGER IF EXISTS trg_ws_products_mandatory_bu;
CREATE TRIGGER trg_ws_products_mandatory_bu
BEFORE UPDATE ON ws_products
FOR EACH ROW
  SET NEW.active = IF(NEW.brand_mandatory = 1 AND NEW.active = 0 AND COALESCE(NEW.office_delivery, 1) = 0, 1, NEW.active);
