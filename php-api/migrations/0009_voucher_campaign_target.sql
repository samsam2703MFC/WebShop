-- 0009 — Ciblage des bons : à qui s'adresse une campagne.
-- target_kind : 'NETWORK' (défaut, tout le réseau), 'CUSTOMER' (une personne),
--               'OFFICE' (une entreprise / bureau livré), 'GROUP' (b2b_client_type).
-- target_id   : id de la cible selon le kind (client.id / ws_offices.id / b2b_client_type.id) ; NULL si NETWORK.
-- L'appartenance (client.office_id == target_id, etc.) est vérifiée à la redemption (webshop).
-- Pas de FK (target_id référence des tables différentes selon le kind). Idempotent MySQL 8.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE voucher_campaign ADD COLUMN target_kind VARCHAR(16) NOT NULL DEFAULT ''NETWORK''','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND column_name='target_kind');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE voucher_campaign ADD COLUMN target_id INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND column_name='target_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE voucher_campaign ADD KEY idx_vc_target (target_kind, target_id)','DO 0')
  FROM information_schema.statistics WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND index_name='idx_vc_target');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
