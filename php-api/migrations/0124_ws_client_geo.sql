-- 0124 — POSITION GOOGLE DES CLIENTS B2B : table à part (la table `client`
-- est celle de l'ERP, on n'y ajoute pas de colonne). Règle d'hygiène : tout
-- bureau, site et client porte sa position Google, sinon aucun itinéraire ne
-- peut passer par lui.
CREATE TABLE IF NOT EXISTS ws_client_geo (
  client_id INT NOT NULL PRIMARY KEY,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  google_place_id VARCHAR(160) NULL,
  google_formatted_address VARCHAR(255) NULL,
  source VARCHAR(16) NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
