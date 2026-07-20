-- 0011 — Facturation sur la commande webshop.
-- Le tunnel envoie invoice:{requested, vat, po, note} quand « Demander une facture
-- nominative » est coché. On persiste sur ws_orders : le PO client, le drapeau de
-- demande de facture et le N° de TVA facturé. (La remarque va déjà dans ws_orders.note.)
-- Idempotent MySQL 8.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN po_number VARCHAR(100) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='po_number');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN invoice_requested TINYINT(1) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='invoice_requested');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN invoice_vat VARCHAR(40) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='invoice_vat');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
