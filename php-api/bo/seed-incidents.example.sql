-- Incidents de démonstration (adapte shop_id à ton parc). Idempotent-ish.
INSERT INTO ws_incidents (shop_id, order_ref, type, severity, status, title, description)
SELECT s.id, 'WS-2041', 'manquant', 'high', 'open',
       'Colis incomplet — 2 articles manquants',
       'Le client signale 2 viennoiseries manquantes sur la commande WS-2041.'
  FROM ws_shops s WHERE s.id = 1
  AND NOT EXISTS (SELECT 1 FROM ws_incidents i WHERE i.order_ref='WS-2041' AND i.type='manquant');

INSERT INTO ws_incidents (shop_id, order_ref, type, severity, status, title, description)
SELECT s.id, 'WS-2043', 'retard', 'medium', 'in_progress',
       'Livraison en retard (> 30 min)',
       'Tournée décalée, client prévenu.'
  FROM ws_shops s WHERE s.id = 1
  AND NOT EXISTS (SELECT 1 FROM ws_incidents i WHERE i.order_ref='WS-2043' AND i.type='retard');
