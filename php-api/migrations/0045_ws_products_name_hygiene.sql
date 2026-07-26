-- 0045 — Hygiène des noms produits exposés au client.
-- Constat live : de nombreux ws_products.name sont pollués par des tabulations,
-- des doubles espaces et des espaces en fin (ex. « 1/4 - Chocolat\t »,
-- « Entremet 120Ø -  Duo Chocolat », « Bombe Chocolat  »). Ces noms partent tels
-- quels au catalogue webshop (/catalog/products expose p.name brut) -> défaut
-- visible côté client.
-- Normalisation idempotente : toute suite d'espaces blancs (tab/espace/retour)
-- est réduite à un seul espace, puis TRIM. Même idiome que la migration 0030.
-- La GARDE permanente (trigger anti-repollution par l'ERP) est fournie à part
-- (optional/0045b_ws_products_name_hygiene_trigger.sql) pour ne pas bloquer le
-- déploiement si le compte MySQL n'a pas le privilège TRIGGER.
-- On s'adapte à l'ERP : l'import peut pousser des noms sales, on présente propre.
UPDATE ws_products
   SET name = TRIM(REGEXP_REPLACE(name, '[[:space:]]+', ' '))
 WHERE name <> TRIM(REGEXP_REPLACE(name, '[[:space:]]+', ' '));
