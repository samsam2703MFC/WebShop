-- Compact test seed for the INT schema — shop Corbais (id 2).
-- Enough data for every bridge endpoint to return rows.
SET NAMES utf8mb4;

INSERT INTO ws_shops (id, slug, name, city, email, phone, street, street_num, accent, tint) VALUES
  (2, 'corbais', 'Atelier by Berlo - Corbais', 'Corbais', 'corbais@atelierby.be', '010.65.83.83', 'Grand Route', '20', '#8D1D2C', '#fdf6f0');

INSERT INTO ws_categories (id, shop_id, slug, label, sort_order) VALUES
  (1, 2, 'viennoiseries', 'Viennoiseries', 1),
  (2, 2, 'patisserie', 'Pâtisserie', 2);

INSERT INTO ws_products (id, cat_id, name, description, price, portions, cross_portion) VALUES
  (1, 1, 'Croissant', 'Pur beurre', 1.30, 0, 1),
  (2, 2, 'Tarte au riz', 'La classique', 14.00, 1, 1);

INSERT INTO ws_product_shops (product_id, shop_id, no_delivery) VALUES (1, 2, 0), (2, 2, 1);
INSERT INTO ws_product_prices (product_id, shop_id, price) VALUES (1, 2, 1.40);
INSERT INTO ws_product_allergens (product_id, allergen) VALUES (1,'gluten'),(1,'milk'),(2,'gluten'),(2,'milk'),(2,'egg');
INSERT INTO ws_product_stock (product_id, shop_id, date, mode, qty_total, qty_reserved, qty_sold) VALUES
  (1, 2, CURDATE(), 'collect', 120, 5, 15);

INSERT INTO ws_slots (id, shop_id, mode, label, sort_order) VALUES
  (1, 2, 'collect', '08:30–10:30', 1), (2, 2, 'collect', '10:30–12:30', 2);
INSERT INTO ws_calendar_rules (shop_id, mode, open_days, cutoff_hour, cutoff_minutes, lead_hours) VALUES
  (2, 'collect', '[1,2,3,4,5,6]', 16, 0, 2);
INSERT INTO ws_shop_availability (shop_id, collect_open_days, delivery_open_days) VALUES
  (2, '[1,2,3,4,5,6]', '[1,2,3,4,5]');
INSERT INTO ws_shop_exceptions (shop_id, exception_date, type, reason) VALUES
  (2, '2026-07-21', 'closed', 'Fête nationale');

INSERT INTO ws_pricing_rules (shop_id, rule_type, x, y, threshold, label) VALUES
  (2, 'cross_portion', 4, 1, 4, '4 achetés, 1 offert');
INSERT INTO ws_vouchers (code, type, value, min_order) VALUES ('BIENVENUE10', 'percent', 10, 20.00);

INSERT INTO ws_tours (id, shop_id, name) VALUES (1, 2, 'Tournée Corbais');
INSERT INTO ws_offices (id, tour_id, name, address, postal_code, city, contact, status) VALUES
  (1, 1, 'TechnoParc SPRL', 'Av. Mermoz 30', '1435', 'Corbais', 'Julie Renard', 'validated');
INSERT INTO ws_office_delivery_sites (id, office_client_id, name, address, shop_id) VALUES
  (1, 1, 'TechnoParc — Hall', 'Av. Mermoz 30', 2);
INSERT INTO ws_delivery_fee_rules (level, shop_id, fee_amount, free_delivery_minimum, payment_type) VALUES
  ('global', NULL, 7.00, 50.00, 'immediate'), ('shop', 2, 5.00, 40.00, 'immediate');

INSERT INTO ws_customers (id, email, password_hash, first_name, last_name, preferred_shop_id) VALUES
  (1, 'marie@example.be', '$2y$10$mock', 'Marie', 'Dupont', 2);
