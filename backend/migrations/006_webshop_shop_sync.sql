-- =====================================================================
-- 006 — Per-shop sync credentials + wire the first live shop to its Woo
-- =====================================================================
-- Buddy (this DB) is the master for price/stock and PUSHES absolute values
-- to each shop's WooCommerce bridge (/wp-json/atelier/v1/sync/*). Each shop
-- authenticates that push with its own shared secret.
--   * sync_token — the secret Buddy sends as X-Atelier-Sync-Token; it must
--     match the `atelier_sync_token` WordPress option on that shop's Woo.
--     Left NULL here (it's a secret): set it out-of-band on both sides.
--     Shops without woo_base_url OR sync_token are skipped by the push.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE ws_shops
  ADD COLUMN sync_token VARCHAR(120) NULL AFTER woo_blog_id;

-- Atelier by Berlo (Corbais) → its live WooCommerce.
UPDATE ws_shops SET woo_base_url = 'https://atelierby.online' WHERE id = 'berlo';
