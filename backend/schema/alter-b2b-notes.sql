-- Comptes entreprise / B2B + notes libres (si la base existe déjà).
-- À importer une seule fois dans phpMyAdmin.

-- Compte entreprise : paiement différé (facturation) + contrat rattaché.
ALTER TABLE ws_offices
  ADD COLUMN deferred_billing_enabled BOOLEAN DEFAULT FALSE AFTER status,
  ADD COLUMN contract_url VARCHAR(255) AFTER deferred_billing_enabled;

-- Plusieurs e-mails rattachés à un même compte entreprise.
CREATE TABLE IF NOT EXISTS ws_office_emails (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  office_id    INT NOT NULL,
  email        VARCHAR(200) NOT NULL,
  contract_url VARCHAR(255),
  active       BOOLEAN DEFAULT TRUE,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_office_email (office_id, email),
  KEY idx_office_email (email),
  FOREIGN KEY (office_id) REFERENCES ws_offices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notes libres (commande + ligne).
ALTER TABLE ws_orders      ADD COLUMN note VARCHAR(500) AFTER lang;
ALTER TABLE ws_order_lines ADD COLUMN note VARCHAR(255) AFTER `portion`;
