-- ============================================================================
-- delivery_time_fields.sql — Coût-temps de livraison B2B (base de calcul).
--
--   ws_office_delivery_sites.site_access_minutes (~10 min)
--     = coût d'accès au SITE : se garer, trouver la place, sortir le bac,
--       badger à l'entrée, puis revenir au véhicule à la fin.
--       Payé UNE SEULE FOIS par site, quel que soit le nombre de bureaux
--       livrés à cette adresse.
--
--   ws_offices.drop_minutes (~5 min)
--     = coût de CHAQUE dépôt : monter à l'étage, poser, faire signer,
--       ressortir. Payé UNE FOIS PAR BUREAU.
--
-- DECIMAL(5,2) minutes : autorise un réglage fin (ex. 7.50). Idempotent
-- (guarded via information_schema) — réexécutable sans effet de bord.
-- ============================================================================
SET @db := DATABASE();

-- 1) site_access_minutes sur ws_office_delivery_sites
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=@db AND table_name='ws_office_delivery_sites'
              AND column_name='site_access_minutes');
SET @s := IF(@c=0,
  "ALTER TABLE ws_office_delivery_sites
     ADD COLUMN site_access_minutes DECIMAL(5,2) NOT NULL DEFAULT 10.00
     COMMENT 'Coût-temps accès site (min), payé 1x/site quel que soit le nb de bureaux'
     AFTER shop_id",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) drop_minutes sur ws_offices
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=@db AND table_name='ws_offices'
              AND column_name='drop_minutes');
SET @s := IF(@c=0,
  "ALTER TABLE ws_offices
     ADD COLUMN drop_minutes DECIMAL(5,2) NOT NULL DEFAULT 5.00
     COMMENT 'Coût-temps dépôt (min), payé 1x/bureau'
     AFTER contract_url",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
