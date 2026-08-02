-- 0051 — Idempotence des commandes : une clé de requête par tentative.
--
-- Constaté en test : trois commandes identiques créées pour un seul panier. La
-- commande était bien enregistrée côté serveur, mais l'appelant recevait une
-- erreur APRÈS l'enregistrement — le client recliquait, et chaque clic créait une
-- nouvelle commande, une nouvelle redemption de bon et un nouveau décrément de
-- stock. Le même scénario se produit sans aucun bug applicatif : coupure réseau
-- sur la réponse, onglet rechargé, double-clic.
--
-- Le front envoie une clé stable pour UNE tentative de commande ; si la clé est
-- déjà connue, le serveur renvoie la commande existante au lieu d'en créer une
-- seconde. L'unicité est garantie par l'index, pas par un test applicatif : deux
-- requêtes simultanées ne peuvent pas passer toutes les deux.

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN request_key VARCHAR(64) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='request_key');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD UNIQUE KEY uq_ws_orders_reqkey (request_key)','DO 0')
  FROM information_schema.statistics WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND index_name='uq_ws_orders_reqkey');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
