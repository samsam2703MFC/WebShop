-- 0045b (OPTIONNEL — à jouer À LA MAIN, hors migrate.sh) — GARDE permanente :
-- à toute écriture de ws_products, on normalise le nom (suite d'espaces blancs
-- réduite à un espace, puis TRIM), pour qu'un ré-import ERP ne repollue jamais
-- l'affichage client. Corps d'UNE seule instruction (SET) -> pas de DELIMITER.
-- Indépendant des triggers 0044b (colonne name vs active) : cohabitation OK,
-- l'ordre n'importe pas. Nécessite le privilège TRIGGER. Idempotent (DROP IF EXISTS).
DROP TRIGGER IF EXISTS trg_ws_products_name_hygiene_bi;
CREATE TRIGGER trg_ws_products_name_hygiene_bi
BEFORE INSERT ON ws_products
FOR EACH ROW
  SET NEW.name = TRIM(REGEXP_REPLACE(NEW.name, '[[:space:]]+', ' '));

DROP TRIGGER IF EXISTS trg_ws_products_name_hygiene_bu;
CREATE TRIGGER trg_ws_products_name_hygiene_bu
BEFORE UPDATE ON ws_products
FOR EACH ROW
  SET NEW.name = TRIM(REGEXP_REPLACE(NEW.name, '[[:space:]]+', ' '));
