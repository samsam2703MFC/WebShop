-- Sync ERP `client` (is_b2b=1) → ws_clientb2b. Idempotent; preserves route_id.
-- Cron (VPS), every 15 min:
--   */15 * * * * mysql -u<user> -p<pass> atelierby_db < /var/www/atelierby/api/cron/sync-clientb2b.sql >> /var/log/ws-sync.log 2>&1

-- B2B = is_b2b = 1 AND a tax_number (VAT) is present.

-- 1) Upsert every qualifying B2B client (new rows get route_id NULL; existing keep theirs).
INSERT INTO ws_clientb2b (client_id, shop_id, active)
SELECT c.id, c.id_main_shop, 1
  FROM client c
 WHERE c.is_b2b = 1
   AND c.tax_number IS NOT NULL AND c.tax_number <> ''
ON DUPLICATE KEY UPDATE
  shop_id = VALUES(shop_id),
  active  = 1;

-- 2) Deactivate rows whose client no longer qualifies (not B2B, or no VAT, or gone).
UPDATE ws_clientb2b j
  LEFT JOIN client c
    ON c.id = j.client_id AND c.is_b2b = 1
   AND c.tax_number IS NOT NULL AND c.tax_number <> ''
   SET j.active = 0
 WHERE c.id IS NULL;
