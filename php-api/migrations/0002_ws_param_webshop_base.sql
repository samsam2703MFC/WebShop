-- 0002 — Table de paramètres clé/valeur + base URL du webshop.
-- Centralise le domaine du webshop au lieu de le coder en dur dans chaque
-- shops.webshop_url. La PWA (repo_webshop_url) construit {base}?shopId={id}.
-- Idempotent : CREATE IF NOT EXISTS + INSERT sans écrasement.

CREATE TABLE IF NOT EXISTS ws_param (
  param_key   VARCHAR(64) PRIMARY KEY,
  param_value TEXT,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AUDIT GO-LIVE : plus d'IP en dur seedée. La base est un PLACEHOLDER évident
-- (jamais une IP nue en HTTP) ; sur une installation existante, ON DUPLICATE
-- KEY préserve la valeur déjà posée. À DÉFINIR en production :
--   UPDATE ws_param SET param_value='https://VOTRE-DOMAINE/webshop' WHERE param_key='webshop_base_url';
INSERT INTO ws_param (param_key, param_value)
VALUES ('webshop_base_url', 'https://REMPLACER-PAR-VOTRE-DOMAINE/webshop')
ON DUPLICATE KEY UPDATE param_value = param_value;  -- ne pas écraser une valeur déjà définie

-- Vérif : SELECT * FROM ws_param;
