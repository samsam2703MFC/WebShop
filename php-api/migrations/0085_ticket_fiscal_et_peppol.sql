-- 0085 — Ticket de caisse fiscal (lié à la commande) + statut Peppol de la facture.
--
-- Demandé le 15/08 : à la validation d'une commande webshop, un TICKET FISCAL
-- est édité (caisse certifiée) et rattaché à la commande. On lui donne sa
-- place sur ws_orders. Côté facture, la transmission Peppol est gérée hors
-- webshop (ERP / point d'accès) ; le webshop se contente d'AFFICHER le statut
-- que l'ERP pousse — d'où deux colonnes de statut sur la facture.
--
-- Toutes NULLABLES : « pas encore émis / pas encore transmis » est un état
-- normal (règle « aucune donnée inventée » — l'écran montre « — » si NULL).
--
-- IDEMPOTENTE : chaque ADD est gardé par information_schema ; la table
-- pwa_invoices n'est modifiée que si elle existe (peut être absente hors ERP).

-- ── ws_orders : ticket fiscal rattaché à la commande ──
SET @ord := (SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema=DATABASE() AND table_name='ws_orders');

SET @s := (SELECT IF(@ord=0 OR COUNT(*)>0, 'DO 0',
  "ALTER TABLE ws_orders ADD COLUMN fiscal_ticket_no VARCHAR(40) NULL COMMENT 'n° du ticket de caisse fiscal (caisse certifiée), édité à la validation'")
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='fiscal_ticket_no');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(@ord=0 OR COUNT(*)>0, 'DO 0',
  "ALTER TABLE ws_orders ADD COLUMN fiscal_ticket_url VARCHAR(255) NULL COMMENT 'PDF du ticket fiscal (poussé par l''ERP)'")
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='fiscal_ticket_url');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── pwa_invoices : statut de transmission Peppol (poussé par l'ERP) ──
SET @tbl := (SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema=DATABASE() AND table_name='pwa_invoices');

SET @s := (SELECT IF(@tbl=0 OR COUNT(*)>0, 'DO 0',
  "ALTER TABLE pwa_invoices ADD COLUMN peppol_status VARCHAR(16) NULL COMMENT 'transmise / en_attente / echec — poussé par l''ERP/point d''accès'")
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='pwa_invoices' AND column_name='peppol_status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(@tbl=0 OR COUNT(*)>0, 'DO 0',
  "ALTER TABLE pwa_invoices ADD COLUMN peppol_at DATETIME NULL COMMENT 'horodatage de transmission Peppol'")
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='pwa_invoices' AND column_name='peppol_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
