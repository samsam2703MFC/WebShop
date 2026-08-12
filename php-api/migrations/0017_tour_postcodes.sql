-- 0017 — Codes postaux d'une tournée, pris dans la zone de chalandise.
-- Nouveau modèle : une tournée porte DIRECTEMENT ses codes postaux, choisis
-- dans la zone de chalandise attribuée à la boutique (ws_franchisor_catchment).
-- Un même code postal peut alimenter plusieurs tournées. Cette table remplace la
-- notion de « zone secondaire » (ws_delivery_zones / ws_tour_zones), désormais
-- retirée de l'interface.
-- Idempotent MySQL 8 (CREATE TABLE IF NOT EXISTS). Ne peut pas échouer → sûr en
-- déploiement automatique (migrate.sh, set -e). La reprise des données existantes
-- vit dans migrations/optional/0017b_tour_postcodes_backfill.sql (jouée à la main).
CREATE TABLE IF NOT EXISTS ws_tour_postcodes (
  tour_id  INT NOT NULL,
  postcode VARCHAR(10) NOT NULL,
  PRIMARY KEY (tour_id, postcode),
  FOREIGN KEY (tour_id) REFERENCES ws_tours(id) ON DELETE CASCADE
);
