-- ============================================================================
-- clientb2b-top5-active.sql
-- Recompute ws_clientb2bdelivery.active: keep ACTIVE only the top 5 clients per shop
-- (by number of orders in that shop), deactivate everyone else.
--
-- Source of order counts = ERP `client_order` (id_client, id_shop).
-- "per shop" = ws_clientb2bdelivery.shop_id (the client's main shop = client.id_main_shop).
--
-- Idempotent. Run whenever you want to refresh the selection (see cron below) —
-- order counts change over time, so this must be RE-RUN periodically, not once.
-- ============================================================================

UPDATE ws_clientb2bdelivery j
LEFT JOIN (
  SELECT client_id FROM (
    SELECT j.client_id,
           ROW_NUMBER() OVER (
             PARTITION BY j.shop_id
             ORDER BY COALESCE(o.cnt, 0) DESC, j.client_id
           ) AS rn
    FROM ws_clientb2bdelivery j
    LEFT JOIN (
      SELECT id_client, id_shop, COUNT(*) AS cnt
      FROM   client_order
      -- Compte toutes les commandes. Pour ne compter que les commandes honorées :
      -- WHERE order_status IN ('completed','picked_up')
      GROUP BY id_client, id_shop
    ) o ON o.id_client = j.client_id AND o.id_shop = j.shop_id
  ) ranked
  WHERE rn <= 5
) top5 ON top5.client_id = j.client_id
SET j.active = IF(top5.client_id IS NULL, 0, 1);

-- Vérif : combien d'actifs par magasin (doit être <= 5) —
--   SELECT shop_id, COUNT(*) FROM ws_clientb2bdelivery WHERE active = 1 GROUP BY shop_id;

-- ----------------------------------------------------------------------------
-- CRON (VPS) — recalcul quotidien (les counts de commandes évoluent) :
--   0 3 * * * mysql -u<user> -p<pass> atelierby_db \
--       < /var/www/atelierby/api/cron/clientb2b-top5-active.sql >> /var/log/ws-sync.log 2>&1
--
-- Variante « top 5 par total de commandes du client (tous magasins) » :
--   remplacer le JOIN par  ON o.id_client = j.client_id  et le GROUP BY par  GROUP BY id_client.
-- ----------------------------------------------------------------------------
