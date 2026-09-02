-- 0111 — Demande de facture APRÈS l'achat, sur une commande webshop.
--
-- CE QUI MANQUAIT. « Mes achats » sait demander une facture sur un ticket de
-- caisse (pwa_purchases.to_invoice + billing_entity_id), mais les commandes
-- webshop (WS-…) étaient servies avec toInvoice = 0 en dur : aucun
-- interrupteur n'apparaissait, et la route de demande ne les connaissait pas.
--
-- 0011 avait déjà posé invoice_requested + invoice_vat : c'est la demande
-- faite AU TUNNEL, au moment d'acheter. Ici c'est l'autre moment — après
-- coup, depuis l'historique — et surtout un DESTINATAIRE : la fiche société à
-- facturer, qui peut être la société liée au compte ou une autre société
-- vérifiée par son numéro de TVA (VIES). Les deux moments coexistent ; la
-- liste les fond en un seul état « demandée ».
--
-- Mêmes noms de colonnes que sur pwa_purchases : un seul vocabulaire pour les
-- deux sources, la liste et la console franchisé les lisent de la même façon.
--
-- Additive, idempotente, sans reprise : rien n'a été demandé avant.
--
-- Garde de TABLE (@t), comme 0083/0085/0103 : ws_orders vient de l'ERP et
-- n'est pas dans le socle minimal de la CI, où ce fichier faisait échouer le
-- rejeu (« Table ws_orders doesn't exist ») et bloquait tout déploiement
-- suivant. Présente en prod, chaque ADD s'exécute à l'identique ; absente,
-- il est simplement inerte.

SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema=DATABASE() AND table_name='ws_orders');

SET @s := (SELECT IF(@t=0 OR COUNT(*)>0, 'DO 0',
  'ALTER TABLE ws_orders ADD COLUMN to_invoice TINYINT(1) NOT NULL DEFAULT 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='to_invoice');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(@t=0 OR COUNT(*)>0, 'DO 0',
  'ALTER TABLE ws_orders ADD COLUMN billing_entity_id INT NULL')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='billing_entity_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(@t=0 OR COUNT(*)>0, 'DO 0',
  'ALTER TABLE ws_orders ADD COLUMN invoice_requested_at DATETIME NULL')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='invoice_requested_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- La console franchisé listera « les commandes à facturer » : l'index évite
-- un balayage de la table à chaque ouverture de l'écran.
SET @s := (SELECT IF(@t=0 OR COUNT(*)>0, 'DO 0',
  'ALTER TABLE ws_orders ADD INDEX idx_orders_to_invoice (to_invoice, shop_id)')
  FROM information_schema.statistics WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND index_name='idx_orders_to_invoice');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SELECT column_name, column_type FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='ws_orders'
   AND column_name IN ('to_invoice','billing_entity_id','invoice_requested_at','invoice_requested','invoice_vat')
 ORDER BY column_name;
