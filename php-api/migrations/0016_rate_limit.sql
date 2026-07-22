-- 0016 — Anti brute-force : compteurs de limitation de débit par clé
-- (route + IP) sur fenêtre glissante. Utilisé par /auth/login,
-- /auth/set-password, /vouchers/redeem et /companies. Fail-open côté code :
-- si la table manque ou la DB est indisponible, la requête passe.
-- Idempotent MySQL 8.
CREATE TABLE IF NOT EXISTS ws_rate_limit (
  rl_key       VARCHAR(120) NOT NULL PRIMARY KEY,
  hits         INT NOT NULL DEFAULT 0,
  window_start INT NOT NULL
);
