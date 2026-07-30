-- 0049 — Réservation de stock (maintien 15 min) : la table manquait.
--
-- ws_product_stock.qty_reserved existait et était bien soustrait du disponible
-- (catalogue ET contrôle de commande), mais RIEN ne l'alimentait : les endpoints
-- /catalog/stock/reserve et /catalog/stock/release n'existaient pas. Le webshop
-- les appelait déjà à chaque ajout au panier — en 404, silencieusement. Donc
-- qty_reserved restait à 0 et deux clients pouvaient remplir leur panier avec la
-- dernière pièce ; seul le passage de commande arbitrait.
--
-- Cette table porte les réservations ; qty_reserved en devient le REFLET,
-- recalculé par l'API à chaque mouvement (pas un compteur qui dérive). Une
-- réservation expirée n'est pas supprimée : released_at est daté, la trace reste.
--
-- Durée du maintien : ws_param('stock.reservation_minutes'), 15 par défaut côté
-- API — aucune écriture de paramètre ici.
--
-- ⚠️ Aucune remise à zéro de qty_reserved : la valeur actuelle n'a pas été
-- écrite par le webshop, donc elle ne nous appartient pas. Le recalcul ne touche
-- que les créneaux (produit × boutique × jour × canal) effectivement réservés.

CREATE TABLE IF NOT EXISTS ws_stock_reservation (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  product_id  INT NOT NULL,
  shop_id     INT NOT NULL,
  date        DATE NOT NULL,
  mode        VARCHAR(16) NOT NULL DEFAULT 'collect',
  qty         INT NOT NULL DEFAULT 1,
  customer_id INT NULL,
  expires_at  DATETIME NOT NULL,
  released_at DATETIME NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_res_slot (product_id, shop_id, date, mode, released_at),
  KEY idx_res_cust (customer_id, released_at),
  KEY idx_res_exp (released_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
