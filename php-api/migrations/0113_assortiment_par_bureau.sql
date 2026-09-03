-- 0113 — ASSORTIMENT RÉDUIT et PRIX MASQUÉS, par bureau (personne morale).
--
-- Un bureau peut ne recevoir qu'une partie du catalogue de sa boutique et
-- commander sans voir les prix. Le prix reste résolu et enregistré côté
-- serveur sur chaque ligne : seul son affichage change.
--
--   ws_office_products      : la jointure bureau ↔ produits proposés
--                             (une ligne = un produit coché ; lue seulement
--                             quand le bureau est en assortiment réduit).
--   ws_offices.assortment_mode : 'full' (tout le catalogue) | 'custom'.
--   ws_offices.show_prices     : 1 par défaut ; 0 n'est accepté que si le
--                             bureau est en facturation différée.
--
-- Additive, idempotente. Aucune reprise : tous les bureaux restent en
-- assortiment complet, prix affichés.

CREATE TABLE IF NOT EXISTS ws_office_products (
  office_id  INT NOT NULL,
  product_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (office_id, product_id),
  KEY idx_oap_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @s := (SELECT IF(COUNT(*)=0,
  "ALTER TABLE ws_offices ADD COLUMN assortment_mode ENUM('full','custom') NOT NULL DEFAULT 'full'",'DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_offices' AND column_name='assortment_mode');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_offices ADD COLUMN show_prices TINYINT(1) NOT NULL DEFAULT 1','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_offices' AND column_name='show_prices');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SELECT column_name, column_type, column_default FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='ws_offices'
   AND column_name IN ('assortment_mode','show_prices') ORDER BY column_name;
SELECT COUNT(*) AS lignes_ws_office_products FROM ws_office_products;
