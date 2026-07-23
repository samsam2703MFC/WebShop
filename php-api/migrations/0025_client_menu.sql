-- 0025 — Menu « Clients » du BO franchisé (clients rattachés aux bureaux).
--   client.blocked          : blocage COMMERCIAL d'un client (toggle fiche client),
--                             distinct de client.active (compte/login).
--   ws_client_vouchers      : vouchers / remboursements NOMINATIFS rattachés à
--                             l'ID client (bouton « Créer un voucher » de la
--                             fiche). Table DÉDIÉE : ws_vouchers est une VUE
--                             depuis la migration 0007 (non inscriptible) — le
--                             raccordement au modèle ERP voucher_code viendra
--                             dans une migration ultérieure si nécessaire.
--   ws_incidents.client_id  : réclamation d'un CLIENT mécontent — distincte des
--                             incidents de LIVRAISON, qui restent sans client_id.
-- Idempotent MySQL 8 (même patron que 0020).
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE client ADD COLUMN blocked TINYINT(1) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='client' AND column_name='blocked');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS ws_client_vouchers (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  client_id  INT NOT NULL,
  shop_id    INT NULL,
  code       VARCHAR(50) NOT NULL UNIQUE,
  type       VARCHAR(20) NOT NULL DEFAULT 'fixed',
  value      DECIMAL(10,2) NOT NULL,
  max_uses   INT NOT NULL DEFAULT 1,
  used_count INT NOT NULL DEFAULT 0,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_wcv_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @s := (SELECT IF(COUNT(*)=0 AND EXISTS(
    SELECT 1 FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_name='ws_incidents'),
  'ALTER TABLE ws_incidents ADD COLUMN client_id INT NULL, ADD KEY idx_incidents_client (client_id)','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_incidents' AND column_name='client_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
