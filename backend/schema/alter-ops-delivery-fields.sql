-- ============================================================================
-- ops_delivery_fields.sql — Delta pour l'opérationnel livraison (écran atelier).
-- Maille COMMANDE (1 commande = 1 colis) : aucun table colis. On ajoute juste
-- de quoi piloter préparation/scan/chargement/remise sur ws_orders.
--   delivered_at    : horodatage de remise
--   delivery_proof  : preuve (URL signature/photo)
--   prep_by         : préparateur (réf logique)
--   tour_id         : tournée dénormalisée (perf des vues du jour ; sinon join via site)
--   idx_ops_day     : index (shop_id, delivery_date, status) pour les files du jour
-- Idempotent (guarded).
-- ============================================================================
SET @db := DATABASE();

-- colonnes
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=@db AND table_name='ws_orders' AND column_name='delivered_at');
SET @s := IF(@c=0,
  "ALTER TABLE ws_orders ADD COLUMN delivered_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Horodatage remise' AFTER created_at",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=@db AND table_name='ws_orders' AND column_name='delivery_proof');
SET @s := IF(@c=0,
  "ALTER TABLE ws_orders ADD COLUMN delivery_proof VARCHAR(255) NULL COMMENT 'Preuve remise (URL signature/photo)' AFTER delivered_at",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=@db AND table_name='ws_orders' AND column_name='prep_by');
SET @s := IF(@c=0,
  "ALTER TABLE ws_orders ADD COLUMN prep_by INT NULL COMMENT 'Preparateur (ref logique)' AFTER delivery_proof",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=@db AND table_name='ws_orders' AND column_name='tour_id');
SET @s := IF(@c=0,
  "ALTER TABLE ws_orders ADD COLUMN tour_id INT NULL COMMENT 'Tournee denormalisee (perf) — sinon via site.tournee_id' AFTER tournee_stop_id",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- FK tour_id -> ws_tours (si colonne créée et FK absente)
SET @c := (SELECT COUNT(*) FROM information_schema.key_column_usage
            WHERE table_schema=@db AND table_name='ws_orders'
              AND column_name='tour_id' AND referenced_table_name IS NOT NULL);
SET @s := IF(@c=0,
  "ALTER TABLE ws_orders ADD CONSTRAINT fk_orders_tour FOREIGN KEY (tour_id) REFERENCES ws_tours(id)",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- index des files du jour (shop_id, delivery_date, status)
SET @c := (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema=@db AND table_name='ws_orders' AND index_name='idx_ops_day');
SET @s := IF(@c=0,
  "ALTER TABLE ws_orders ADD INDEX idx_ops_day (shop_id, delivery_date, status)",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
