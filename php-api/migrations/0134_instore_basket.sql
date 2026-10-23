-- 0134 — Panier caisse (« scan & go ») : le client compose son panier dans le
-- webshop, en boutique, et le fait scanner à la caisse, qui passe directement
-- en auto-paiement (voir php-api/instore.php et SCAN_CAISSE.md).
--
-- Un panier caisse n'est PAS une commande : rien n'est préparé, rien n'est
-- réservé, rien n'est encaissé par le webshop. C'est une liste figée aux prix
-- de la boutique, identifiée par un code court (« PC-7K3Q9X ») que la caisse
-- lit dans le QR. La caisse fait foi sur le montant et édite le ticket fiscal ;
-- elle renvoie ici le montant encaissé et le numéro du ticket.
--
-- Statuts : open (affiché sur le téléphone) → at_till (pris par une caisse,
-- auto-paiement en cours) → paid. Sorties : cancelled (le client renonce),
-- expired (pas scanné à temps, ws_param.instore_basket_ttl_min, 120 min).
--
-- owner_token : secret du téléphone qui a créé le panier (suivi du statut,
-- annulation). Jamais renvoyé à la caisse.
-- client_id : le client connecté, s'il l'est — la caisse reçoit son
-- client_code et l'attache au ticket : points et tampons de fidélité suivent.
-- IDEMPOTENTE : CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS ws_instore_basket (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  code             VARCHAR(16)   NOT NULL,
  owner_token      CHAR(32)      NOT NULL,
  shop_id          INT           NOT NULL,
  client_id        INT           NULL,
  status           VARCHAR(12)   NOT NULL DEFAULT 'open',
  subtotal         DECIMAL(10,2) NOT NULL DEFAULT 0,
  promo_amount     DECIMAL(10,2) NOT NULL DEFAULT 0,
  total            DECIMAL(10,2) NOT NULL DEFAULT 0,
  items_count      INT           NOT NULL DEFAULT 0,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at       DATETIME      NOT NULL,
  claimed_at       DATETIME      NULL,
  claimed_by       VARCHAR(64)   NULL,
  paid_at          DATETIME      NULL,
  paid_amount      DECIMAL(10,2) NULL,
  payment_method   VARCHAR(32)   NULL,
  fiscal_ticket_no VARCHAR(40)   NULL,
  pos_ref          VARCHAR(64)   NULL,
  UNIQUE KEY uq_instore_code (code),
  KEY idx_instore_shop_status (shop_id, status),
  KEY idx_instore_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Une ligne par article ; les composants d'un menu / d'un bundle sont des
-- lignes filles (parent_line_id), comme ws_order_lines.
CREATE TABLE IF NOT EXISTS ws_instore_basket_line (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  basket_id      INT           NOT NULL,
  parent_line_id INT           NULL,
  product_id     INT           NULL,
  external_id    VARCHAR(64)   NULL,
  name           VARCHAR(255)  NOT NULL,
  `portion`      VARCHAR(20)   NULL,
  qty            INT           NOT NULL DEFAULT 1,
  unit_price     DECIMAL(10,2) NOT NULL DEFAULT 0,
  KEY idx_instore_line_basket (basket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
