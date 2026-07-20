-- 0013 — Journal d'état du back-office franchisé (écritures non typées).
-- Chaque BOServer.save(table) de la Console franchisé est poussé vers l'API :
-- les tables à mapping propre sont écrites dans les vraies tables ; les autres
-- (shapes présentation/agrégées) sont persistées ici en JSON, par boutique
-- (shop_scope=0 ⇒ réseau). hydrate() les réapplique au chargement — l'état du
-- BO n'est plus seulement dans le localStorage du navigateur.
-- Idempotent MySQL 8.
CREATE TABLE IF NOT EXISTS ws_bo_store (
  shop_scope  INT NOT NULL DEFAULT 0,
  tbl         VARCHAR(64) NOT NULL,
  payload     MEDIUMTEXT NOT NULL,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (shop_scope, tbl)
);
