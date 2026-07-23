-- 0036 — Minimums de stock par jour de semaine et par canal.
-- « On saisit 10 → le magasin en produit 10 par jour » : quantité par défaut
-- par produit × jour ISO (1 = lundi … 7 = dimanche) × mode (collect /
-- delivery). Sert de stock du jour quand aucune ligne ws_product_stock
-- n'existe pour la date. Idempotent.
CREATE TABLE IF NOT EXISTS ws_product_stock_defaults (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shop_id INT NOT NULL,
  product_id INT NOT NULL,
  weekday TINYINT NOT NULL,
  mode VARCHAR(10) NOT NULL,
  qty INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_def (shop_id, product_id, weekday, mode)
);
