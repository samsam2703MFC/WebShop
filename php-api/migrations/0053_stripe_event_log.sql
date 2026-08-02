-- 0053 — Journal des événements Stripe (idempotence du webhook).
--
-- Stripe REJOUE ses webhooks : à chaque échec de livraison, et parfois des
-- jours plus tard. Sans mémoire de ce qui a déjà été traité, le même paiement
-- serait appliqué plusieurs fois — inoffensif pour un simple passage à « payé »,
-- désastreux le jour où l'on branchera un décompte de bon, un stock ou une
-- écriture comptable sur le même événement.
--
-- La table sert donc de garde d'idempotence ET de trace : chaque événement reçu
-- laisse une ligne, avec ce qui en a été fait (`applied`) et sa charge utile
-- tronquée. En cas de litige sur un encaissement, c'est ici qu'on regarde.
--
-- `event_id` est UNIQUE : c'est lui qui bloque le double traitement.
-- `payload` est tronqué à 60 000 caractères par l'API — on garde de quoi
-- comprendre, pas de quoi faire grossir la base indéfiniment.

CREATE TABLE IF NOT EXISTS ws_stripe_event (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  event_id    VARCHAR(80)  NOT NULL,
  type        VARCHAR(80)  NOT NULL DEFAULT '',
  order_id    INT          NULL,
  applied     VARCHAR(32)  NOT NULL DEFAULT '',
  payload     MEDIUMTEXT   NULL,
  received_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_event (event_id),
  KEY idx_order (order_id),
  KEY idx_type_date (type, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
