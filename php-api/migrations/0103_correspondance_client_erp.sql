-- 0103 — Préparer la bascule : la correspondance client local ↔ fiche ERP.
--
-- OBJECTIF. Le jour où la table `client` disparaîtra au profit des endpoints,
-- neuf tables se retrouveront avec des identifiants qui ne désignent plus rien :
-- ws_orders (customer_id), ws_offices, ws_office_delivery_sites,
-- ws_stock_reservation, ws_incidents, pwa_client_office, ws_client_vouchers,
-- auth_handoff, ws_office_join_requests. Il leur faut une clé ERP.
--
-- POURQUOI PAS UNE COLONNE DANS CHAQUE TABLE. Ce serait neuf copies du même
-- fait, donc neuf endroits à tenir synchronisés. Ce dépôt a déjà payé ce piège
-- trois fois : deux vocabulaires pour le mode de livraison, deux sources pour
-- l'image d'une gamme, et un prix affiché qui n'était pas le prix débité. Une
-- correspondance vit à UN endroit.
--
-- MAIS ELLE NE PEUT PAS VIVRE DANS `client`. C'est justement la table qu'on
-- veut supprimer : `client.erp_client_id` disparaîtrait avec elle, et toutes
-- les lignes existantes deviendraient orphelines. D'où cette table dédiée, qui
-- lui survit.
--
-- L'EXCEPTION DES COMMANDES. Une commande est une pièce historique : elle doit
-- garder la fiche contre laquelle elle a été passée, même si le client est
-- rattaché à une autre plus tard. Son identifiant ERP est donc GELÉ sur la
-- ligne, pas résolu par la correspondance.
--
-- Additive et idempotente. Aucune colonne existante n'est modifiée.

CREATE TABLE IF NOT EXISTS ws_client_erp_map (
  client_id      INT NOT NULL PRIMARY KEY,   -- client.id, y compris après suppression
  erp_client_id  INT NOT NULL,
  -- Comment le lien a été établi : 'inscription' (fiche créée par le webshop),
  -- 'rattachement' (demande arbitrée par le franchisé), 'reprise' (backfill).
  -- Sans cette trace, un lien douteux est indiscernable d'un lien validé.
  origine        VARCHAR(16) NOT NULL DEFAULT 'reprise',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cem_erp (erp_client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- REPRISE de ce que `client.erp_client_id` sait déjà. Vide aujourd'hui pour
-- l'essentiel : seuls les comptes créés depuis le 26/08 portent le lien. La
-- table se remplira au fil des inscriptions et des rattachements validés.
SET @s := (SELECT IF(COUNT(*) > 0,
  'INSERT INTO ws_client_erp_map (client_id, erp_client_id, origine)
     SELECT id, erp_client_id, ''reprise'' FROM client
      WHERE erp_client_id IS NOT NULL
     ON DUPLICATE KEY UPDATE erp_client_id = VALUES(erp_client_id)',
  'DO 0')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'client'
    AND column_name = 'erp_client_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- LA COMMANDE GARDE SA FICHE. Renseigné à la création (POST /orders) ; NULL sur
-- les commandes antérieures, ce qui est exact — on ne sait pas contre quelle
-- fiche ERP elles ont été passées, et l'inventer serait pire que l'ignorer.
--
-- Garde de TABLE (@ord), comme 0085_ticket : ws_orders est absente du socle
-- minimal (elle vient de l'ERP, pas des migrations), ce qui faisait échouer le
-- rejeu CI. Présente en prod, chaque opération ws_orders s'exécute à
-- l'identique ; absente, elle est simplement inerte.
SET @ord := (SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'ws_orders');

SET @s := (SELECT IF(@ord = 0 OR COUNT(*) > 0, 'DO 0',
  'ALTER TABLE ws_orders ADD COLUMN customer_erp_id INT NULL')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ws_orders'
    AND column_name = 'customer_erp_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(@ord = 0 OR COUNT(*) > 0, 'DO 0',
  'ALTER TABLE ws_orders ADD INDEX idx_orders_cerp (customer_erp_id)')
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ws_orders'
    AND index_name = 'idx_orders_cerp');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Reprise des commandes dont le client porte déjà un lien connu.
SET @s := (SELECT IF(COUNT(*) = 2,
  'UPDATE ws_orders o
      JOIN client c ON c.id = o.customer_id
       SET o.customer_erp_id = c.erp_client_id
     WHERE o.customer_erp_id IS NULL AND c.erp_client_id IS NOT NULL',
  'DO 0')
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND ((table_name = 'ws_orders' AND column_name = 'customer_erp_id')
      OR (table_name = 'client'    AND column_name = 'erp_client_id')));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Où en est la couverture, dit dans la sortie de migrate.sh. Gardé par @ord :
-- sur un socle sans ws_orders on ne rapporte que les liens connus (les deux
-- compteurs de commandes valent alors NULL, ce n'est pas une erreur).
SET @s := IF(@ord = 0,
  'SELECT (SELECT COUNT(*) FROM ws_client_erp_map) AS liens_connus,
          NULL AS commandes_liees, NULL AS commandes_avec_client',
  'SELECT (SELECT COUNT(*) FROM ws_client_erp_map) AS liens_connus,
          (SELECT COUNT(*) FROM ws_orders WHERE customer_erp_id IS NOT NULL) AS commandes_liees,
          (SELECT COUNT(*) FROM ws_orders WHERE customer_id IS NOT NULL) AS commandes_avec_client');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
