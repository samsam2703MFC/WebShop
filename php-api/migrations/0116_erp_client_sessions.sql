-- 0116 — CONNEXION CLIENT PAR L'ERP (POST /clients/auth/login).
--
-- Le webshop tente d'abord l'ERP ; en cas de succès il conserve la session
-- ERP du client (access token 30 min, refresh token à usage unique) pour les
-- appels faits en son nom et pour la déconnexion. Une ligne par client local.
-- client.erp_auth_at : dernière connexion réussie par l'ERP — NULL tant que
-- le client ne s'est jamais connecté par ce chemin (mot de passe ERP absent).
CREATE TABLE IF NOT EXISTS ws_erp_client_sessions (
  client_id      INT NOT NULL PRIMARY KEY,
  erp_session_id INT NULL,
  erp_client_id  INT NULL,
  access_token   TEXT NULL,
  refresh_token  VARCHAR(255) NULL,
  expires_at     DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE client ADD COLUMN erp_auth_at DATETIME NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='client' AND column_name='erp_auth_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
