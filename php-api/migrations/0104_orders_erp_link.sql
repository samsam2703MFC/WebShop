-- 0104 — Commandes : liaison vers l'ERP (client_order).
--
-- Cap acté : les tables ws_ sont appelées à disparaître — la commande doit
-- NAÎTRE côté ERP. Première étape : dès qu'une commande est DÉFINITIVE
-- (comptoir/sur-compte à l'enregistrement, Stripe à l'encaissement), le
-- webshop la POSTe sur /api/v1/client-orders et garde l'inserted_id rendu.
--
--   erp_order_id   : id de la ligne client_order créée — NULL = jamais poussée.
--                    L'API ERP n'a PAS de clé d'idempotence : cette colonne
--                    EST la garde anti-doublon (on ne pousse que si NULL, sous
--                    verrou GET_LOCK — voir erp_orders.php).
--   erp_push_error : motif du DERNIER échec (HTTP + message ERP), remis à NULL
--                    au succès. C'est le signal du cron de reprise
--                    (cron/erp-orders-push.php) et le diagnostic des écrans.
--
-- Idempotent (patron 0020).

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'ws_orders'
            AND column_name = 'erp_order_id'),
  'DO 0',
  'ALTER TABLE ws_orders ADD COLUMN erp_order_id INT NULL DEFAULT NULL COMMENT ''client_order.id cree par la remontee ERP — NULL = jamais poussee'''
));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql2 := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'ws_orders'
            AND column_name = 'erp_push_error'),
  'DO 0',
  'ALTER TABLE ws_orders ADD COLUMN erp_push_error VARCHAR(400) NULL DEFAULT NULL COMMENT ''Motif du dernier echec de remontee ERP — NULL = poussee ou jamais tentee'''
));
PREPARE st2 FROM @sql2; EXECUTE st2; DEALLOCATE PREPARE st2;
