-- Demo seed for the real ws_ schema — Atelier by Berlo (Corbais).
-- Order respects FKs: shops → categories → products → shops/prices/allergens/stock.
SET NAMES utf8mb4;

INSERT INTO ws_shops (id, name, city, address, phone, email, accent, tint, logo_url, active) VALUES
  ('berlo', 'Atelier by Berlo', 'Corbais', 'Rue de Corbais 1', '+32 10 00 00 00',
   'berlo@atelierby.online', '#8D1D2C', '#fdf6f0', NULL, 1);

INSERT INTO ws_categories (id, shop_id, label, img, sort_order, active) VALUES
  ('boul',  'berlo', 'Boulangerie', NULL, 1, 1),
  ('patis', 'berlo', 'Pâtisserie',  NULL, 2, 1);

INSERT INTO ws_products (id, cat, sub_cat, name, description, price, img, badge,
                         portions, cross_portion, has_menu_options, active) VALUES
  (1, 'boul',  NULL, 'Croissant', 'Pur beurre',        1.30, NULL, 'Du jour', 0, 1, 0, 1),
  (2, 'boul',  NULL, 'Pain',      'Pain de campagne',  2.50, NULL, NULL,      0, 0, 0, 1),
  (3, 'patis', NULL, 'Éclair',    'Chocolat',          3.20, NULL, 'Nouveau', 0, 0, 0, 1);

-- Availability per shop (no_delivery = collect only).
INSERT INTO ws_product_shops (product_id, shop_id, no_delivery, active) VALUES
  (1, 'berlo', 0, 1),
  (2, 'berlo', 0, 1),
  (3, 'berlo', 1, 1);   -- Éclair : retrait seulement

-- Per-shop price override (Croissant costs more at Berlo than the global default).
INSERT INTO ws_product_prices (product_id, shop_id, price, active) VALUES
  (1, 'berlo', 1.40, 1);
-- Pain & Éclair : pas d'override → prix global.

INSERT INTO ws_product_allergens (product_id, allergen) VALUES
  (1, 'gluten'), (1, 'milk'),
  (3, 'gluten'), (3, 'milk'), (3, 'egg');

-- Stock du jour (collect), robuste : disponible = qty_total - reserved - sold.
INSERT INTO ws_product_stock (product_id, shop_id, date, mode, qty_total, qty_reserved, qty_sold, active) VALUES
  (1, 'berlo', CURDATE(), 'collect', 120, 5, 15, 1),
  (2, 'berlo', CURDATE(), 'collect',  40, 0,  0, 1);
-- Éclair : pas de ligne stock → illimité.
