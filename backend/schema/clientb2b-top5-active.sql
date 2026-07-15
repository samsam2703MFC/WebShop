-- ============================================================================
-- clientb2b-top5-active.sql
-- Recompute active for the ERP-client rows of ws_office_delivery_sites: keep
-- ACTIVE only the top 5 clients per shop (by number of orders in that shop),
-- deactivate the other ERP clients. Regular webshop sites (client_id IS NULL)
-- are NOT touched.
--
-- Order counts = ERP client_order (id_client, id_shop).
-- "per shop" = ws_office_delivery_sites.shop_id (= client.id_main_shop).
-- Idempotent; RE-RUN periodically (order counts change) — see cron below.
-- ============================================================================

UPDATE ws_office_delivery_sites j
LEFT JOIN (
  SELECT client_id FROM (
    SELECT j.client_id,
           ROW_NUMBER() OVER (
             PARTITION BY j.shop_id
             ORDER BY COALESCE(o.cnt, 0) DESC, j.client_id
           ) AS rn
    FROM ws_office_delivery_sites j
    LEFT JOIN (
      SELECT id_client, id_shop, COUNT(*) AS cnt
      FROM   client_order
      -- Toutes les commandes. Pour ne compter que les honorées :
      -- WHERE order_status IN ('completed','picked_up')
      GROUP BY id_client, id_shop
    ) o ON o.id_client = j.client_id AND o.id_shop = j.shop_id
    WHERE j.client_id IS NOT NULL              -- lignes ERP uniquement
  ) ranked
  WHERE rn <= 5
) top5 ON top5.client_id = j.client_id
SET j.active = IF(top5.client_id IS NULL, 0, 1)
WHERE j.client_id IS NOT NULL;                 -- ne jamais toucher les sites webshop

-- Vérif : SELECT shop_id, COUNT(*) FROM ws_office_delivery_sites
--         WHERE client_id IS NOT NULL AND active = 1 GROUP BY shop_id;   -- <= 5

-- ----------------------------------------------------------------------------
-- CRON (VPS) — recalcul quotidien :
--   0 3 * * * mysql -u<user> -p<pass> atelierby_db \
--       < /var/www/atelierby/api/cron/clientb2b-top5-active.sql >> /var/log/ws-sync.log 2>&1
-- ----------------------------------------------------------------------------
