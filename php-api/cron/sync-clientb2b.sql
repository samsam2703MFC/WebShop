-- Roster safety-net: keep the ERP-client rows of ws_office_delivery_sites in sync
-- with `client` (B2B = is_b2b=1 AND tax_number set). Real-time is handled by the
-- triggers; this is a periodic backfill (e.g. after an ERP re-import).
-- Copies name/company/contact/address so rows are readable. `active` is owned by
-- the top-5 rule -> inserts active=0. Only ERP rows (client_id NOT NULL) affected.
-- Cron (VPS), e.g. every 15 min:
--   */15 * * * * mysql -u<user> -p<pass> atelierby_db < /var/www/atelierby/api/cron/sync-clientb2b.sql >> /var/log/ws-sync.log 2>&1

-- 1) Upsert qualifying B2B clients (name = company; contact_name = person).
INSERT INTO ws_office_delivery_sites
  (client_id, shop_id, active, name, contact_name, address, contact_phone)
SELECT c.id, c.id_main_shop, 0,
       LEFT(COALESCE(NULLIF(TRIM(c.company_name),''), NULLIF(TRIM(CONCAT_WS(' ', c.name, c.surname)),'')), 120),
       LEFT(NULLIF(TRIM(CONCAT_WS(' ', c.name, c.surname)),''), 120),
       LEFT(NULLIF(TRIM(CONCAT_WS(', ',
              NULLIF(TRIM(CONCAT_WS(' ', c.street, c.street_number)),''),
              NULLIF(TRIM(CONCAT_WS(' ', c.zip, c.city)),''))),''), 250),
       LEFT(NULLIF(TRIM(c.phone),''), 30)
  FROM client c
 WHERE c.is_b2b = 1
   AND c.tax_number IS NOT NULL AND c.tax_number <> ''
ON DUPLICATE KEY UPDATE
  shop_id       = VALUES(shop_id),
  name          = VALUES(name),
  contact_name  = VALUES(contact_name),
  address       = VALUES(address),
  contact_phone = VALUES(contact_phone);

-- 2) Deactivate ERP rows whose client no longer qualifies (not B2B, no VAT, or gone).
UPDATE ws_office_delivery_sites j
  LEFT JOIN client c
    ON c.id = j.client_id AND c.is_b2b = 1
   AND c.tax_number IS NOT NULL AND c.tax_number <> ''
   SET j.active = 0
 WHERE j.client_id IS NOT NULL AND c.id IS NULL;
