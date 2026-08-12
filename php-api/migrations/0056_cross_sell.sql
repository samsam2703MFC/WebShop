-- 0056 — Ventes croisées (« Panier Croisé ») : règles de la marque + mesure.
--
-- Modèle : SI le panier contient un déclencheur, ALORS proposer des produits,
-- pendant une période, à certaines heures, certains jours, sur un périmètre de
-- boutiques et de canaux.
--
-- DÉCISION VALIDÉE — l'heure comparée est celle du CRÉNEAU DE RETRAIT, pas
-- celle de la commande : un client qui commande à 22:00 pour le lendemain midi
-- doit voir les suggestions du midi. La colonne s'appelle donc hour_from /
-- hour_to sans ambiguïté, et l'évaluation reçoit l'heure de retrait.
--
-- Les tables sont ADDITIVES : rien n'existe tant qu'aucune règle n'est créée,
-- et l'absence de ces tables ne casse ni le catalogue ni la commande.

CREATE TABLE IF NOT EXISTS ws_cross_sell_rule (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(120)  NOT NULL,
  date_from        DATE          NULL COMMENT 'NULL = pas de borne de debut',
  date_to          DATE          NULL COMMENT 'NULL = pas de borne de fin',
  hour_from        TIME          NULL COMMENT 'Heure du creneau de RETRAIT, pas de la commande',
  hour_to          TIME          NULL,
  weekdays         VARCHAR(15)   NULL COMMENT '1=lundi .. 7=dimanche, separes par des virgules — NULL = tous',
  match_mode       ENUM('any','all') NOT NULL DEFAULT 'any',
  channel          ENUM('both','collect','delivery') NOT NULL DEFAULT 'both',
  placement        VARCHAR(60)   NOT NULL DEFAULT 'cart,checkout' COMMENT 'cart | checkout | product',
  max_suggestions  INT           NOT NULL DEFAULT 2,
  active           TINYINT(1)    NOT NULL DEFAULT 1,
  created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_xsell_active (active, date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Déclencheurs : un produit OU une catégorie. Les deux colonnes sont nullables ;
-- une ligne renseigne l'une ou l'autre.
CREATE TABLE IF NOT EXISTS ws_cross_sell_trigger (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  rule_id     INT NOT NULL,
  product_id  INT NULL,
  cat_id      INT NULL,
  KEY idx_xsell_trig (rule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Suggestions, dans l'ordre voulu par la marque.
CREATE TABLE IF NOT EXISTS ws_cross_sell_target (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  rule_id     INT NOT NULL,
  product_id  INT NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  KEY idx_xsell_targ (rule_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Périmètre boutique. AUCUNE ligne = toutes les boutiques (règle réseau).
CREATE TABLE IF NOT EXISTS ws_cross_sell_shop (
  rule_id  INT NOT NULL,
  shop_id  INT NOT NULL,
  PRIMARY KEY (rule_id, shop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Suspension par le FRANCHISÉ : la marque garde la main sur le contenu, le
-- franchisé sur la disponibilité (rupture durable d'un produit suggéré).
CREATE TABLE IF NOT EXISTS ws_cross_sell_pause (
  rule_id   INT NOT NULL,
  shop_id   INT NOT NULL,
  paused_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reason    VARCHAR(160) NULL,
  PRIMARY KEY (rule_id, shop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MESURE — agrégat JOURNALIER par règle × produit × boutique. Un compteur par
-- jour plutôt qu'une ligne par événement : on veut savoir quelle règle
-- rapporte, pas rejouer le parcours de chaque client. La table reste petite et
-- la question « combien de fois proposé, combien de fois ajouté » se répond
-- d'un seul SELECT.
CREATE TABLE IF NOT EXISTS ws_cross_sell_stat (
  rule_id     INT  NOT NULL,
  product_id  INT  NOT NULL,
  shop_id     INT  NOT NULL,
  stat_date   DATE NOT NULL,
  impressions INT  NOT NULL DEFAULT 0,
  adds        INT  NOT NULL DEFAULT 0,
  PRIMARY KEY (rule_id, product_id, shop_id, stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
