-- 0038 — Règle métier : un produit OBLIGATOIRE (brand_mandatory) est
-- forcément visible au webshop et dans l'assortiment de chaque boutique.
-- Répare les états incohérents existants (ex. Brownies : obligatoire mais
-- active=0 → invisible partout). Idempotent.
UPDATE ws_products SET active = 1 WHERE brand_mandatory = 1 AND active = 0;

UPDATE ws_product_shops ps
JOIN ws_products p ON p.id = ps.product_id
SET ps.active = 1
WHERE p.brand_mandatory = 1 AND ps.active = 0;
