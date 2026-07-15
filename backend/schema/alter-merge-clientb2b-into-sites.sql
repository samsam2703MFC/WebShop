-- ============================================================================
-- alter-merge-clientb2b-into-sites.sql  —  RUN ONCE on atelierby_db
-- Merge the ERP-client→route mapping INTO ws_office_delivery_sites, then drop
-- the standalone ws_clientb2bdelivery table.
--
-- ws_office_delivery_sites already had the route (tournee_id), shop_id, active.
-- The only missing field was client_id (the ERP client.id) → added here, plus
-- office_client_id / name relaxed to NULL so ERP-synced rows are valid.
-- UNIQUE(client_id): one delivery config per ERP client (NULLs allowed → regular
-- webshop sites are unaffected).
-- ============================================================================

ALTER TABLE ws_office_delivery_sites
  ADD COLUMN client_id INT AFTER office_client_id,   -- ERP client.id
  MODIFY office_client_id INT NULL,                  -- was NOT NULL
  MODIFY name VARCHAR(120) NULL,                     -- was NOT NULL
  ADD UNIQUE KEY uq_ods_client (client_id);

-- Drop the shop_id FK: la synchro y met client.id_main_shop, et l'ERP a des
-- shops non seedés dans ws_shops → shop_id devient une réf LOGIQUE (comme client_id).
-- Le nom du FK est auto-généré ; d'après ton erreur c'est ws_office_delivery_sites_ibfk_3.
-- (Vérifier au besoin : SHOW CREATE TABLE ws_office_delivery_sites;)
ALTER TABLE ws_office_delivery_sites DROP FOREIGN KEY ws_office_delivery_sites_ibfk_3;
ALTER TABLE ws_office_delivery_sites ADD KEY idx_ods_shop (shop_id);

-- OPTIONNEL — si tu avais déjà créé/rempli ws_clientb2bdelivery, migre ses lignes
-- (route_id → tournee_id) AVANT le DROP. Décommente si besoin :
-- INSERT INTO ws_office_delivery_sites (client_id, tournee_id, shop_id, active)
-- SELECT client_id, route_id, shop_id, active FROM ws_clientb2bdelivery
-- ON DUPLICATE KEY UPDATE tournee_id = VALUES(tournee_id),
--                         shop_id    = VALUES(shop_id),
--                         active     = VALUES(active);

DROP TABLE IF EXISTS ws_clientb2bdelivery;
