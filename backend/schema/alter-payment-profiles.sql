-- Moyens de paiement par boutique × profil + checkout visiteur (si base existante).
-- À importer une seule fois dans phpMyAdmin.

-- Contact du visiteur (commande guest).
ALTER TABLE ws_orders
  ADD COLUMN guest_email VARCHAR(200) AFTER customer_id,
  ADD COLUMN guest_name  VARCHAR(200) AFTER guest_email,
  ADD COLUMN guest_phone VARCHAR(30)  AFTER guest_name;

-- Moyens de paiement autorisés par boutique ET par type de profil.
CREATE TABLE IF NOT EXISTS ws_shop_payment_options (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  shop_id      INT NOT NULL,
  profile_type VARCHAR(20) NOT NULL,   -- guest | registered | company
  method       VARCHAR(20) NOT NULL,   -- stripe | shop | deferred
  active       BOOLEAN DEFAULT TRUE,
  UNIQUE KEY uq_shop_payment (shop_id, profile_type, method),
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Exemple de config (à adapter) : boutique 2, visiteur/enregistré = stripe + shop ;
-- société = stripe + deferred.
-- INSERT INTO ws_shop_payment_options (shop_id, profile_type, method) VALUES
--   (2,'guest','stripe'),(2,'guest','shop'),
--   (2,'registered','stripe'),(2,'registered','shop'),
--   (2,'company','stripe'),(2,'company','deferred');
