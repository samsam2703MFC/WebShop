-- ============================================================================
-- office_join_request.sql — Prompt 2 : demande de rattachement à un bureau.
--
-- Capte l'intention d'un utilisateur CONNECTÉ qui veut la livraison bureau
-- alors qu'il n'a pas d'office_id (ou bureau hors tournée). Table LÉGÈRE —
-- surtout PAS une ligne ws_offices en 'pending' (polluerait tournées/reporting).
--
-- Mapping au schéma réel (identité consolidée) :
--   spec `office`  -> ws_offices
--   spec `client`  -> client  (table unifiée ; ex-ws_customers fusionnée dedans ;
--                              client.office_id est le rattachement bureau)
--   `resolved_by`  -> INT (réf logique admin, pas de table admin => pas de FK)
--
-- + ws_offices.referrer_client_id : apporteur (client.id), posé UNIQUEMENT à la
--   création d'un bureau depuis une demande. NULL sinon. Lu par le Prompt 3.
-- Idempotent (guarded / IF NOT EXISTS).
-- ============================================================================
SET @db := DATABASE();

-- 1) ws_offices.referrer_client_id (colonne)
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=@db AND table_name='ws_offices' AND column_name='referrer_client_id');
SET @s := IF(@c=0,
  "ALTER TABLE ws_offices
     ADD COLUMN referrer_client_id INT NULL
     COMMENT 'Apporteur (client.id) — pose 1x a la creation depuis une demande, jamais modifie'
     AFTER contract_url",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 1b) FK referrer_client_id -> client (si aucune FK encore sur cette colonne)
SET @c := (SELECT COUNT(*) FROM information_schema.key_column_usage
            WHERE table_schema=@db AND table_name='ws_offices'
              AND column_name='referrer_client_id' AND referenced_table_name IS NOT NULL);
SET @s := IF(@c=0,
  "ALTER TABLE ws_offices
     ADD CONSTRAINT fk_offices_referrer FOREIGN KEY (referrer_client_id) REFERENCES client(id)",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) table des demandes de rattachement
CREATE TABLE IF NOT EXISTS ws_office_join_requests (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  client_id          INT NOT NULL,                 -- client.id — DEPUIS LA SESSION, jamais du formulaire
  shop_id            INT NOT NULL,                 -- routage uniquement (boutique webshop), réaffectable
  office_name_raw    VARCHAR(200) NOT NULL,        -- nom du bureau, tel que saisi
  address_raw        VARCHAR(250) NOT NULL,        -- adresse/ville, tel que saisi
  status             VARCHAR(12) NOT NULL DEFAULT 'pending',  -- pending | linked | created | rejected
  resolved_office_id INT NULL,                     -- FK ws_offices, NULL tant que non traité
  reject_reason      VARCHAR(200) NULL,            -- obligatoire si status=rejected
  resolved_by        INT NULL,                     -- admin (réf logique)
  created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  resolved_at        TIMESTAMP NULL DEFAULT NULL,
  KEY idx_ojr_client_status (client_id, status),   -- plafond « 3 pending / client »
  KEY idx_ojr_shop_status  (shop_id, status),      -- file du franchisé
  FOREIGN KEY (client_id)          REFERENCES client(id),
  FOREIGN KEY (shop_id)            REFERENCES ws_shops(id),
  FOREIGN KEY (resolved_office_id) REFERENCES ws_offices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
