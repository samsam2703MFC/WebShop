-- 0033 — Réparation des liens site↔tournée et commande↔site/bureau.
-- Cause : la ligne de liaison bureau↔site créée par l'étape 3 n'héritait pas
-- de la tournée du bâtiment (ligne de même adresse), et le checkout pouvait
-- enregistrer une commande livraison sans id de site ni de bureau — commandes
-- invisibles dans « Par tournée ». Idempotent (gardes WHERE … IS NULL).

-- 1) Une ligne de liaison sans tournée hérite de la tournée du bâtiment :
--    autre ligne ACTIVE de même adresse normalisée qui en porte une.
UPDATE ws_office_delivery_sites s
JOIN ws_office_delivery_sites b
  ON b.active=1 AND b.id<>s.id AND b.tournee_id IS NOT NULL
 AND LOWER(REGEXP_REPLACE(TRIM(COALESCE(b.address,'')),'[[:space:]]+',' ')) =
     LOWER(REGEXP_REPLACE(TRIM(COALESCE(s.address,'')),'[[:space:]]+',' '))
SET s.tournee_id = b.tournee_id
WHERE s.active=1 AND s.tournee_id IS NULL AND TRIM(COALESCE(s.address,'')) <> '';

-- 2) Commande livraison sans bureau : retrouver le bureau par l'ADRESSE
--    stockée au checkout (office_delivery_site_name = adresse du bureau).
UPDATE ws_orders o
JOIN ws_offices f
  ON f.active=1
 AND LOWER(REGEXP_REPLACE(TRIM(COALESCE(f.address,'')),'[[:space:]]+',' ')) =
     LOWER(REGEXP_REPLACE(TRIM(COALESCE(o.office_delivery_site_name,'')),'[[:space:]]+',' '))
SET o.office_client_id = f.id
WHERE o.office_client_id IS NULL
  AND o.office_delivery_site_name IS NOT NULL AND TRIM(o.office_delivery_site_name) <> ''
  AND (o.mode='delivery' OR o.delivery_mode='office_delivery');

-- 3) Commande livraison avec bureau mais sans id de site : rattacher au site
--    ACTIF du bureau (celui avec tournée en priorité).
UPDATE ws_orders o
JOIN ws_office_delivery_sites s
  ON s.office_client_id = o.office_client_id AND s.active=1
SET o.office_delivery_site_id = s.id
WHERE o.office_delivery_site_id IS NULL AND o.office_client_id IS NOT NULL
  AND s.tournee_id IS NOT NULL
  AND (o.mode='delivery' OR o.delivery_mode='office_delivery');

UPDATE ws_orders o
JOIN ws_office_delivery_sites s
  ON s.office_client_id = o.office_client_id AND s.active=1
SET o.office_delivery_site_id = s.id
WHERE o.office_delivery_site_id IS NULL AND o.office_client_id IS NOT NULL
  AND (o.mode='delivery' OR o.delivery_mode='office_delivery');

-- 4) Toujours sans site : rattacher par l'adresse stockée = adresse du SITE.
UPDATE ws_orders o
JOIN ws_office_delivery_sites s
  ON s.active=1
 AND LOWER(REGEXP_REPLACE(TRIM(COALESCE(s.address,'')),'[[:space:]]+',' ')) =
     LOWER(REGEXP_REPLACE(TRIM(COALESCE(o.office_delivery_site_name,'')),'[[:space:]]+',' '))
SET o.office_delivery_site_id = s.id,
    o.office_client_id = COALESCE(o.office_client_id, s.office_client_id)
WHERE o.office_delivery_site_id IS NULL
  AND o.office_delivery_site_name IS NOT NULL AND TRIM(o.office_delivery_site_name) <> ''
  AND (o.mode='delivery' OR o.delivery_mode='office_delivery');

-- 5) delivery_date manquante (bug front corrigé le 23/07) : la commande vaut
--    pour le jour de sa création.
UPDATE ws_orders SET delivery_date = DATE(created_at)
 WHERE delivery_date IS NULL AND (mode='delivery' OR delivery_mode='office_delivery');
