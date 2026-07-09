-- =====================================================================
-- 005 — Multi-shop routing
-- =====================================================================
-- Each shop is its own WooCommerce site inside a WordPress Multisite
-- network. ws_shops becomes the shop registry the frontend uses to route
-- cart / checkout / login to the right Woo:
--   * woo_base_url — the site URL of this shop's Woo (routing target)
--   * woo_blog_id  — the Multisite blog id (for targeted sync / ops)
--   * status       — provisioning lifecycle (only 'live' shops are listed)
--   * city         — grouping in the shop picker (was in API.md, not in DDL)
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE ws_shops
  ADD COLUMN city         VARCHAR(120) NULL AFTER name,
  ADD COLUMN woo_base_url VARCHAR(200) NULL AFTER address,   -- https://berlo.atelier.be
  ADD COLUMN woo_blog_id  INT          NULL AFTER woo_base_url,
  ADD COLUMN status       ENUM('provisioning','live','paused')
                          NOT NULL DEFAULT 'provisioning' AFTER active;

-- Any shop already active (e.g. previously synced from the ERP) stays visible.
UPDATE ws_shops SET status = 'live' WHERE active = 1;

-- Current live shop. woo_base_url stays NULL until its Multisite site exists;
-- the catalogue is served by Buddy meanwhile, cart/checkout light up once the
-- URL is set (PATCH /admin/shops/berlo { "woo_base_url": "…", "status":"live" }).
INSERT INTO ws_shops (id, external_id, name, city, address, accent, woo_base_url, status, active)
VALUES ('berlo', NULL, 'Atelier by Berlo', 'Corbais', '', '#8D1D2C', NULL, 'live', 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), city = VALUES(city), status = VALUES(status);
