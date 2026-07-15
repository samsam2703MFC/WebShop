-- Roster safety-net: keep the ERP-client rows of ws_office_delivery_sites in sync
-- with `client` (B2B = is_b2b=1 AND tax_number set). Real-time is handled by the
-- triggers; this is a periodic backfill (e.g. after an ERP re-import).
-- `active` is owned by the top-5 rule (clientb2b-top5-active.sql) → inserts active=0.
-- Only ERP rows (client_id IS NOT NULL) are affected; webshop sites untouched.
-- Cron (VPS), e.g. every 15 min:
--   */15 * * * * mysql -u<user> -p<pass> atelierby_db < /var/www/atelierby/api/cron/sync-clientb2b.sql >> /var/log/ws-sync.log 2>&1

-- 1) Add qualifying B2B clients (tournee_id assigned webshop-side; active via top-5).
INSERT INTO ws_office_delivery_sites (client_id, shop_id, active)
SELECT c.id, c.id_main_shop, 0
  FROM client c
 WHERE c.is_b2b = 1
   AND c.tax_number IS NOT NULL AND c.tax_number <> ''
ON DUPLICATE KEY UPDATE shop_id = VALUES(shop_id);

-- 2) Deactivate ERP rows whose client no longer qualifies (not B2B, no VAT, or gone).
UPDATE ws_office_delivery_sites j
  LEFT JOIN client c
    ON c.id = j.client_id AND c.is_b2b = 1
   AND c.tax_number IS NOT NULL AND c.tax_number <> ''
   SET j.active = 0
 WHERE j.client_id IS NOT NULL AND c.id IS NULL;
