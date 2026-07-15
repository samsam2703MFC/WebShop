-- Recompute ws_clientb2b.active = top 5 clients per shop (by orders in that shop).
-- Source: ERP client_order (id_client, id_shop). Re-run periodically (counts change).
-- Cron (VPS), nightly:
--   0 3 * * * mysql -u<user> -p<pass> atelierby_db < /var/www/atelierby/api/cron/clientb2b-top5-active.sql >> /var/log/ws-sync.log 2>&1

UPDATE ws_clientb2b j
LEFT JOIN (
  SELECT client_id FROM (
    SELECT j.client_id,
           ROW_NUMBER() OVER (
             PARTITION BY j.shop_id
             ORDER BY COALESCE(o.cnt, 0) DESC, j.client_id
           ) AS rn
    FROM ws_clientb2b j
    LEFT JOIN (
      SELECT id_client, id_shop, COUNT(*) AS cnt
      FROM   client_order
      GROUP BY id_client, id_shop
    ) o ON o.id_client = j.client_id AND o.id_shop = j.shop_id
  ) ranked
  WHERE rn <= 5
) top5 ON top5.client_id = j.client_id
SET j.active = IF(top5.client_id IS NULL, 0, 1);
