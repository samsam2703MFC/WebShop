-- 0030 — Dédoublonnage de ws_office_delivery_sites (« Zoning Nord LLN »
-- recréé, tags « Corbais » / « LLN » en double dans l'étape 3).
-- Deux causes historiques :
--   1. variantes de graphie de la même adresse (casse, espaces) → plusieurs
--      lignes actives pour le même couple bureau × bâtiment ;
--   2. lignes « placeholder » sans bureau (office_client_id NULL) restées
--      actives alors qu'une ligne du même bâtiment avec bureau existe.
-- On garde la ligne la plus ancienne (id min) de chaque groupe, on désactive
-- le reste. Idempotent (rejouable sans effet la 2e fois).

-- 1. Doublons bureau × adresse normalisée : garder id min, désactiver le reste.
UPDATE ws_office_delivery_sites s
  JOIN (
    SELECT office_client_id,
           LOWER(REGEXP_REPLACE(TRIM(COALESCE(address,'')), '[[:space:]]+', ' ')) AS nadr,
           MIN(id) AS keep_id
      FROM ws_office_delivery_sites
     WHERE active = 1 AND office_client_id IS NOT NULL
       AND TRIM(COALESCE(address,'')) <> ''
     GROUP BY office_client_id, nadr
    HAVING COUNT(*) > 1
  ) d ON d.office_client_id = s.office_client_id
     AND LOWER(REGEXP_REPLACE(TRIM(COALESCE(s.address,'')), '[[:space:]]+', ' ')) = d.nadr
   SET s.active = 0
 WHERE s.active = 1 AND s.id <> d.keep_id;

-- 2. Doublons SANS bureau (nom + adresse normalisés identiques) : idem.
UPDATE ws_office_delivery_sites s
  JOIN (
    SELECT LOWER(REGEXP_REPLACE(TRIM(COALESCE(name,'')), '[[:space:]]+', ' '))    AS nnom,
           LOWER(REGEXP_REPLACE(TRIM(COALESCE(address,'')), '[[:space:]]+', ' ')) AS nadr,
           MIN(id) AS keep_id
      FROM ws_office_delivery_sites
     WHERE active = 1 AND office_client_id IS NULL
     GROUP BY nnom, nadr
    HAVING COUNT(*) > 1
  ) d ON LOWER(REGEXP_REPLACE(TRIM(COALESCE(s.name,'')), '[[:space:]]+', ' ')) = d.nnom
     AND LOWER(REGEXP_REPLACE(TRIM(COALESCE(s.address,'')), '[[:space:]]+', ' ')) = d.nadr
   SET s.active = 0
 WHERE s.active = 1 AND s.office_client_id IS NULL AND s.id <> d.keep_id;

-- 3. Placeholders sans bureau devenus inutiles : une ligne du même bâtiment
--    (adresse normalisée) AVEC bureau existe → le bâtiment est déjà représenté.
UPDATE ws_office_delivery_sites s
  JOIN (
    SELECT DISTINCT LOWER(REGEXP_REPLACE(TRIM(COALESCE(address,'')), '[[:space:]]+', ' ')) AS nadr
      FROM ws_office_delivery_sites
     WHERE active = 1 AND office_client_id IS NOT NULL
       AND TRIM(COALESCE(address,'')) <> ''
  ) o ON LOWER(REGEXP_REPLACE(TRIM(COALESCE(s.address,'')), '[[:space:]]+', ' ')) = o.nadr
   SET s.active = 0
 WHERE s.active = 1 AND s.office_client_id IS NULL;
