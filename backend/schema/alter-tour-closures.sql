-- ============================================================================
-- tour_closures.sql — Table des FERMETURES PONCTUELLES de tournée.
-- Une ligne = « la tournée T ne roule pas à la date D » (exception ponctuelle
-- au planning récurrent ws_tour_availability). Lue par tour_orderable().
-- Reflète la structure réelle déjà en base. CREATE ... IF NOT EXISTS =>
-- idempotent, ne détruit rien si déjà présente.
-- ============================================================================
CREATE TABLE IF NOT EXISTS ws_tour_closures (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tour_id       INT NOT NULL,
  closure_date  DATE NOT NULL,
  reason        VARCHAR(120),                 -- motif optionnel (férié, congé chauffeur…)
  UNIQUE KEY uq_tour_closure (tour_id, closure_date),   -- 1 fermeture max par tournée/date
  FOREIGN KEY (tour_id) REFERENCES ws_tours(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
