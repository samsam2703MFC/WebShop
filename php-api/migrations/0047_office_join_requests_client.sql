-- 0047 — Demandes de rattachement à un bureau : colonnes manquantes.
--
-- Le flow réel est : le client (webshop, connecté) demande le rattachement à un
-- bureau → le FRANCHISÉ décide (BO › Demandes de rattachement › Lier / Rejeter)
-- → `client.office_id` est écrit. Sans le client demandeur en base, la décision
-- « Lier » ne sait pas QUI lier : la table ne portait que le nom du bureau saisi.
--
-- On ajoute donc : le client demandeur, ses coordonnées de contact (la demande
-- peut arriver avant que la fiche soit complète), le bureau retenu et la date de
-- décision. Additif et idempotent (information_schema + PREPARE, comme 0046).
-- Aucune colonne existante n'est modifiée ni supprimée.

-- Filet : la table est créée hors migrations sur la prod. Si elle manque
-- (nouvel environnement), on la crée avec la définition complète.
CREATE TABLE IF NOT EXISTS ws_office_join_requests (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  shop_id         INT NULL,
  client_id       INT NULL,
  office_name_raw VARCHAR(190) NOT NULL,
  address_raw     VARCHAR(255) NULL,
  contact_email   VARCHAR(190) NULL,
  contact_phone   VARCHAR(40)  NULL,
  office_id       INT NULL,
  status          VARCHAR(16) NOT NULL DEFAULT 'pending',
  decided_at      TIMESTAMP NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_jr_status (status),
  KEY idx_jr_shop (shop_id),
  KEY idx_jr_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Client demandeur (client.id) — indispensable à la décision « Lier ».
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_office_join_requests ADD COLUMN client_id INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_join_requests' AND column_name='client_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Boutique de rattachement (portée du BO franchisé).
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_office_join_requests ADD COLUMN shop_id INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_join_requests' AND column_name='shop_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Coordonnées données AVEC la demande (le franchisé doit pouvoir rappeler).
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_office_join_requests ADD COLUMN contact_email VARCHAR(190) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_join_requests' AND column_name='contact_email');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_office_join_requests ADD COLUMN contact_phone VARCHAR(40) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_join_requests' AND column_name='contact_phone');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Bureau retenu à la validation (ws_offices.id) — trace de la décision.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_office_join_requests ADD COLUMN office_id INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_join_requests' AND column_name='office_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_office_join_requests ADD COLUMN decided_at TIMESTAMP NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_join_requests' AND column_name='decided_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Adresse saisie : présente sur la prod, garantie ici pour les environnements
-- où la table aurait été créée sans (la demande peut n'avoir que le nom).
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_office_join_requests ADD COLUMN address_raw VARCHAR(255) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_join_requests' AND column_name='address_raw');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_office_join_requests ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_join_requests' AND column_name='created_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
