-- 0067 — La table ws_incidents, qui n'avait jamais été créée.
--
-- CE QUI SE PASSAIT. Deux écrans de la console franchisé la lisent — « Alertes »
-- et « Incidents » — et l'API sait y écrire une réclamation client
-- (/franchisee/client-complaint). Mais aucune migration ne l'a jamais créée :
-- les deux écrans étaient vides depuis toujours, et rendaient `[]`.
--
-- Ce `[]` était indiscernable de « aucun incident à signaler ». C'est le
-- constat 1.9 : jusqu'à ce que les routes annoncent les tables manquantes
-- (en-tête X-Tables-Absentes), personne ne pouvait voir la différence entre un
-- écran calme et un écran mort. La sonde l'a signalé à sa PREMIÈRE exécution.
--
-- Le code mentionne « migration 0025 » à propos de ws_incidents.client_id : la
-- 0025 est client_menu.sql, cette référence est fausse. Rien n'existait.
--
-- LES COLONNES SONT CELLES QUE LES REQUÊTES EXIGENT, pas un modèle imaginé :
--   · fr-incidents  : id, order_ref, type, severity, status, title,
--                     description, created_at, shop_id
--   · fr-alertes    : type, severity, title, order_ref, status, created_at
--   · b2b-clients   : client_id, resolved_at  (compteur de réclamations ouvertes)
--   · complaint     : shop_id, order_id, order_ref, type, severity, status,
--                     title, description, client_id
--
-- Le titre est borné à 180 caractères parce que l'API l'y tronque déjà
-- (mb_substr(..., 0, 180)) : la colonne dit la même chose que le code.
--
-- PAS DE CLÉ ÉTRANGÈRE. Les incidents référencent une boutique, une commande et
-- un client, mais ces tables varient d'une installation à l'autre (ws_orders
-- peut être une vue, `client` porte des noms différents selon l'ERP). Une
-- contrainte qui échoue à la création ferait tomber migrate.sh — et un échec de
-- migration bloque TOUS les déploiements suivants, c'est arrivé aujourd'hui.
-- Les index portent la performance ; l'intégrité reste applicative, comme pour
-- les autres tables de ce schéma.

CREATE TABLE IF NOT EXISTS ws_incidents (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  shop_id     INT          NULL          COMMENT 'boutique concernee (shops.id)',
  order_id    INT          NULL          COMMENT 'ws_orders.id si l incident porte sur une commande',
  order_ref   VARCHAR(64)  NULL          COMMENT 'reference lisible de la commande',
  client_id   INT          NULL          COMMENT 'client a l origine, pour une reclamation',
  type        VARCHAR(16)  NOT NULL DEFAULT 'litige'
              COMMENT 'manquant · retard · casse · erreur · litige',
  severity    VARCHAR(16)  NOT NULL DEFAULT 'medium'
              COMMENT 'high remonte en tete des alertes',
  status      VARCHAR(16)  NOT NULL DEFAULT 'open'
              COMMENT 'open · in_progress · resolved',
  title       VARCHAR(180) NOT NULL      COMMENT 'tronque a 180 par l API',
  description TEXT         NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME     NULL          COMMENT 'NULL = encore ouvert',
  -- Les deux écrans trient par (statut ouvert, puis date) dans une boutique.
  KEY idx_incidents_shop_statut (shop_id, status, created_at),
  -- La fiche client compte ses réclamations non résolues.
  KEY idx_incidents_client (client_id, resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Rattrapage, si la table préexistait hors migrations ─────────────────────
-- Même précaution que la 0065 : sur une installation où elle aurait été créée
-- à la main, CREATE TABLE IF NOT EXISTS ne fait rien et les colonnes récentes
-- manqueraient en silence. On ajoute donc celles dont le code dépend.
SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_incidents');

SET @a := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_incidents' AND column_name = 'client_id');
SET @s := IF(@t = 0 OR @a > 0, 'DO 0',
  "ALTER TABLE ws_incidents ADD COLUMN client_id INT NULL COMMENT 'client a l origine, pour une reclamation'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @a := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_incidents' AND column_name = 'resolved_at');
SET @s := IF(@t = 0 OR @a > 0, 'DO 0',
  'ALTER TABLE ws_incidents ADD COLUMN resolved_at DATETIME NULL');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @a := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_incidents' AND column_name = 'order_ref');
SET @s := IF(@t = 0 OR @a > 0, 'DO 0',
  'ALTER TABLE ws_incidents ADD COLUMN order_ref VARCHAR(64) NULL');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- AUCUNE LIGNE N'EST CRÉÉE. Un incident est un fait d'exploitation : en
-- inventer un pour « remplir » l'écran ferait exactement ce que ce dépôt
-- interdit. Les deux écrans restent vides tant qu'il ne se passe rien — la
-- différence est qu'ils le sont désormais parce qu'il n'y a rien à montrer, et
-- non parce que la table manque.
