-- ---------------------------------------------------------------------------
-- rollback-seasons-unify.sql
-- Recrée ws_assortments et ses 3 lignes telles qu'avant migrate-seasons-unify.
-- À utiliser SEULEMENT si on doit revenir en arrière (et redéployer l'ancienne
-- version de l'API qui lisait ws_assortments).
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ws_assortments (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  shop_id INT,
  label   VARCHAR(100) NOT NULL,
  img     VARCHAR(255),
  active  BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO ws_assortments (id, shop_id, label, img, active) VALUES
  (1, 4,    'Saveurs d''été', '/webshop/assets/season_icons/ete.png',            1),
  (2, NULL, 'Saint-Valentin', '/webshop/assets/season_icons/saint-valentin.png', 1),
  (3, NULL, 'Fête des mères', '/webshop/assets/season_icons/fete-des-meres.png', 1);
