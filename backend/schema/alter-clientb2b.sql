-- ============================================================================
-- alter-clientb2b.sql
-- Join table linking an ERP B2B client (`client.id`, is_b2b=1) to a webshop
-- delivery ROUTE (`ws_tours.id` = tournée), with an active flag. Plus the
-- idempotent sync query to keep it up to date from the ERP `client` table.
--
-- Design notes:
--   • client_id is a LOGICAL reference to the ERP `client` table (same DB,
--     atelierby_db) — NOT a FK, so the webshop stays decoupled from the ERP
--     schema and a client pointing at a not-yet-seeded shop never breaks sync.
--   • route_id is assigned WEBSHOP-side (the ERP `client` row has no route),
--     so the sync NEVER overwrites it — it only adds/deactivates clients.
--   • shop_id = ERP client.id_main_shop (== ws_shops.id, the Franchise Buddy id).
-- ============================================================================

CREATE TABLE IF NOT EXISTS ws_clientb2b (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  client_id  INT NOT NULL,          -- ERP client.id (is_b2b=1) — logical ref
  route_id   INT,                   -- ws_tours.id (tournée/route) — assigned webshop-side, NULL until set
  shop_id    INT,                   -- ERP client.id_main_shop (main shop)
  active     BOOLEAN DEFAULT TRUE,  -- 1 while the ERP client is still a B2B client
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_clientb2b_client (client_id),
  KEY idx_clientb2b_route (route_id),
  FOREIGN KEY (route_id) REFERENCES ws_tours(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- SYNC — run directly (phpMyAdmin) or on a cron. Idempotent; preserves route_id.
-- ----------------------------------------------------------------------------

-- 1) Upsert every B2B client into the join. New clients arrive with route_id
--    NULL (to be assigned). Existing rows keep their route_id; only shop_id and
--    active are refreshed.
INSERT INTO ws_clientb2b (client_id, shop_id, active)
SELECT c.id, c.id_main_shop, 1
  FROM client c
 WHERE c.is_b2b = 1
ON DUPLICATE KEY UPDATE
  shop_id = VALUES(shop_id),
  active  = 1;

-- 2) Deactivate join rows whose client no longer exists or is no longer B2B.
--    (Soft: keeps the route assignment for audit / reactivation.)
UPDATE ws_clientb2b j
  LEFT JOIN client c ON c.id = j.client_id AND c.is_b2b = 1
   SET j.active = 0
 WHERE c.id IS NULL;

-- ----------------------------------------------------------------------------
-- CRON (VPS) — every 15 min. Put the two statements above in this .sql file.
--   */15 * * * * mysql -u<user> -p<pass> atelierby_db \
--       < /var/www/atelierby/api/cron/sync-clientb2b.sql >> /var/log/ws-sync.log 2>&1
-- ----------------------------------------------------------------------------
