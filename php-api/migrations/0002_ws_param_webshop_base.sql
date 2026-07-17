-- 0002 — Table de paramètres clé/valeur + base URL du webshop.
-- Centralise le domaine du webshop au lieu de le coder en dur dans chaque
-- shops.webshop_url. La PWA (repo_webshop_url) construit {base}?shopId={id}.
-- Idempotent : CREATE IF NOT EXISTS + INSERT sans écrasement.

CREATE TABLE IF NOT EXISTS ws_param (
  param_key   VARCHAR(64) PRIMARY KEY,
  param_value TEXT,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Base actuelle (accès par IP). Change-la en une ligne quand le vrai domaine
-- est prêt :  UPDATE ws_param SET param_value='https://ton-domaine/webshop' WHERE param_key='webshop_base_url';
INSERT INTO ws_param (param_key, param_value)
VALUES ('webshop_base_url', 'http://185.180.206.46/webshop')
ON DUPLICATE KEY UPDATE param_value = param_value;  -- ne pas écraser une valeur déjà définie

-- Vérif : SELECT * FROM ws_param;
