-- ============================================================================
-- alter-incidents.sql — Incidents & litiges (back-office franchisé).
-- Idempotent (CREATE IF NOT EXISTS). Aucune donnée détruite.
-- Portée : par boutique (shop_id) → filtrable via bo_user_shops côté API.
-- ============================================================================
SET @db := DATABASE();

CREATE TABLE IF NOT EXISTS ws_incidents (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  shop_id     INT NOT NULL,                                   -- boutique concernée (portée franchisé)
  order_id    INT NULL,                                       -- commande liée (optionnel)
  order_ref   VARCHAR(50) NULL,                               -- réf dénormalisée (affichage)
  type        ENUM('retard','manquant','casse','erreur','litige','autre') NOT NULL DEFAULT 'autre',
  severity    ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  status      ENUM('open','in_progress','resolved') NOT NULL DEFAULT 'open',
  title       VARCHAR(200) NOT NULL,
  description TEXT NULL,
  created_by  INT NULL,                                       -- bo_users.id (auteur)
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL DEFAULT NULL,
  KEY idx_incidents_shop   (shop_id, status, created_at),
  KEY idx_incidents_order  (order_id),
  FOREIGN KEY (shop_id)  REFERENCES ws_shops(id),
  FOREIGN KEY (order_id) REFERENCES ws_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
