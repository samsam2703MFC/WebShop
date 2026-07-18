-- ============================================================================
-- alter-tour-tracking.sql — Télémétrie des tournées (suivi live).
-- Une ligne par tournée (état courant, upsert). Idempotent.
-- Portée : shop_id dénormalisé → filtrable via bo_user_shops côté API.
-- ============================================================================
CREATE TABLE IF NOT EXISTS ws_tour_tracking (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  tour_id        INT NOT NULL,
  shop_id        INT NULL,                                  -- portée franchisé (dénormalisé)
  driver_name    VARCHAR(120),
  vehicle        VARCHAR(120),
  status         ENUM('idle','en_route','paused','done') NOT NULL DEFAULT 'idle',
  lat            DECIMAL(9,6) NULL,                         -- position courante
  lng            DECIMAL(9,6) NULL,
  next_office_id INT NULL,                                  -- prochain arrêt
  next_label     VARCHAR(200) NULL,                         -- libellé dénormalisé
  next_city      VARCHAR(100) NULL,
  eta            TIME NULL,                                 -- ETA prochain arrêt
  drift_minutes  INT NOT NULL DEFAULT 0,                    -- écart planning (+retard / -avance)
  stops_done     INT NOT NULL DEFAULT 0,
  stops_total    INT NOT NULL DEFAULT 0,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tracking_tour (tour_id),
  KEY idx_tracking_shop (shop_id),
  FOREIGN KEY (tour_id)        REFERENCES ws_tours(id),
  FOREIGN KEY (next_office_id) REFERENCES ws_offices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
