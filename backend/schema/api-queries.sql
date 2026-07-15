-- ============================================================
-- Requêtes API — L'Atelier By (schéma ws_ VERSION INT)
-- À tester dans phpMyAdmin. Shop identifié par son INT id (ex. 2 = Corbais,
-- 4 = Halle). Catégorie = cat_id (INT), plus `cat`.
-- ============================================================

-- 1. SHOPS  →  GET /shops
SELECT id, slug, name, city, email, phone, accent, tint, logo_url,
       TRIM(CONCAT_WS(' ', street, street_num)) AS address
FROM ws_shops WHERE active = 1 ORDER BY name;

-- Thème d'une boutique  →  GET /brand?shopId=2
SELECT id, slug, name, accent, tint, logo_url FROM ws_shops WHERE id = 2;

-- 2. CATÉGORIES  →  GET /catalog/categories?shopId=2
SELECT id, slug, label, img, sort_order
FROM ws_categories
WHERE active = 1 AND (shop_id = 2 OR shop_id IS NULL)
ORDER BY sort_order, label;

-- Sous-catégories
SELECT s.id, s.category_id, s.slug, s.label, s.sort_order
FROM ws_category_subs s
JOIN ws_categories c ON c.id = s.category_id
WHERE s.active = 1 AND (c.shop_id = 2 OR c.shop_id IS NULL)
ORDER BY s.sort_order;

-- 3. PRODUITS  →  GET /catalog/products?shopId=2
--    prix boutique (sinon global) + catégorie + allergènes + retrait-seul
SELECT p.id, p.cat_id, p.sub_cat_id, c.label AS categorie,
       p.name, p.description, p.badge,
       p.portions, p.cross_portion, p.has_menu_options,
       COALESCE(pp.price, p.price) AS price,
       ps.no_delivery,
       (SELECT JSON_ARRAYAGG(allergen)
          FROM ws_product_allergens a WHERE a.product_id = p.id) AS allergens
FROM ws_products p
JOIN ws_product_shops ps
  ON ps.product_id = p.id AND ps.shop_id = 2 AND ps.active = 1
LEFT JOIN ws_product_prices pp
  ON pp.product_id = p.id AND pp.shop_id = 2 AND pp.active = 1
LEFT JOIN ws_categories c ON c.id = p.cat_id
WHERE p.active = 1
ORDER BY c.sort_order, p.name;

-- Un produit  →  GET /catalog/products/:id (?shopId=2)
SELECT p.*, COALESCE(pp.price, p.price) AS shop_price
FROM ws_products p
LEFT JOIN ws_product_prices pp ON pp.product_id = p.id AND pp.shop_id = 2
WHERE p.id = 14;

-- Options d'un produit (+ choix)
SELECT o.id AS option_id, o.label, o.required, o.sort_order,
       ch.id AS choice_id, ch.label AS choice_label, ch.delta
FROM ws_product_options o
LEFT JOIN ws_product_option_choices ch ON ch.option_id = o.id AND ch.active = 1
WHERE o.product_id = 7 AND o.active = 1
ORDER BY o.sort_order, ch.sort_order;

-- 4. BUNDLES / MENUS  →  GET /catalog/bundles?productId=7
SELECT id, name, description, price_modifier, sort_order
FROM ws_bundles WHERE product_id = 7 AND active = 1 ORDER BY sort_order;

SELECT sl.id AS slot_id, sl.label, sl.required,
       ch.id AS choice_id, ch.label AS choice_label, ch.img, ch.delta
FROM ws_bundle_slots sl
LEFT JOIN ws_bundle_slot_choices ch ON ch.slot_id = sl.id AND ch.active = 1
WHERE sl.bundle_id = 1 AND sl.active = 1
ORDER BY sl.sort_order, ch.sort_order;

-- 5. ASSORTIMENTS  →  GET /catalog/assortments?shopId=2
SELECT id, label, img FROM ws_assortments
WHERE active = 1 AND (shop_id = 2 OR shop_id IS NULL);

-- 6. STOCK DU JOUR  →  disponible = total - réservé - vendu
SELECT product_id,
       GREATEST(0, qty_total - qty_reserved - qty_sold) AS available
FROM ws_product_stock
WHERE shop_id = 2 AND date = CURDATE()
  AND (mode = 'collect' OR mode IS NULL) AND active = 1;

-- 7. CALENDRIER / DISPONIBILITÉ
SELECT * FROM ws_shop_availability WHERE shop_id = 2;

SELECT shop_id, mode, open_days, cutoff_hour, cutoff_minutes, lead_hours
FROM ws_calendar_rules WHERE shop_id = 2 AND active = 1;

SELECT id, mode, label, sort_order FROM ws_slots
WHERE shop_id = 2 AND mode = 'collect' AND active = 1 ORDER BY sort_order;

SELECT exception_date, type, reason FROM ws_shop_exceptions
WHERE shop_id = 2 AND exception_date >= CURDATE();

-- 8. PRICING  →  cross-portion (4+1) + vouchers
SELECT rule_type, x, y, threshold, label FROM ws_pricing_rules
WHERE shop_id = 2 AND rule_type = 'cross_portion' AND active = 1;

SELECT id, code, type, value, min_order, max_uses, used_count, expires_at
FROM ws_vouchers
WHERE code = 'BIENVENUE10' AND active = 1
  AND (expires_at IS NULL OR expires_at > NOW())
  AND (max_uses IS NULL OR used_count < max_uses);

-- 9. TOURS / OFFICES
SELECT id, shop_id, name FROM ws_tours WHERE active = 1;   -- + AND shop_id = 2

SELECT id, tour_id, name, address, postal_code, city, contact, email, phone, vat, status
FROM ws_offices WHERE status = 'validated' AND active = 1;

SELECT id, name, address, floor_room, contact_name, contact_phone, shop_id
FROM ws_office_delivery_sites WHERE office_client_id = 1 AND active = 1;

-- 10. DELIVERY FEES  →  priorité: site > office > tour > shop > global
SELECT id, level, free_delivery, always_charge, fee_amount,
       free_delivery_minimum, payment_type
FROM ws_delivery_fee_rules
WHERE active = 1 AND (
      (level = 'site'   AND site_id          = 2) OR
      (level = 'office' AND office_client_id = 1) OR
      (level = 'tour'   AND tour_id          = 1) OR
      (level = 'shop'   AND shop_id          = 2) OR
      (level = 'global')
    )
ORDER BY FIELD(level, 'site', 'office', 'tour', 'shop', 'global')
LIMIT 1;

-- 11. AUTH / CUSTOMERS  →  POST /auth/login
SELECT id, email, password_hash, first_name, last_name, phone,
       office_id, preferred_shop_id, preferred_lang, is_business
FROM ws_customers WHERE email = 'marie.dupont@example.be' AND active = 1;

-- 12. ORDERS  →  POST /orders + GET /orders/:id
INSERT INTO ws_orders
  (order_ref, shop_id, customer_id, mode, status, slot_id, slot_label, delivery_date,
   subtotal, promo_amount, voucher_code, voucher_discount, total,
   payment_method, payment_status, lang, delivery_mode)
VALUES
  ('WS-TEST-001', 2, NULL, 'collect', 'pending', NULL, '08:30–09:30', CURDATE(),
   6.70, 0, NULL, 0, 6.70, 'cash', 'pending', 'fr', 'collect');

INSERT INTO ws_order_lines
  (order_id, product_id, product_name, qty, unit_price, `portion`, bundle_id, options, bundle_slots)
VALUES
  (LAST_INSERT_ID(), 14, 'Croissant pur beurre', 3, 1.40, NULL, NULL, NULL, NULL);

SELECT * FROM ws_orders WHERE order_ref = 'WS-TEST-001';

-- Décrément du stock à la validation (par jour)
UPDATE ws_product_stock
SET qty_sold = qty_sold + 3
WHERE product_id = 14 AND shop_id = 2 AND date = CURDATE()
  AND (mode = 'collect' OR mode IS NULL);

-- ============================================================================
-- GET /slots?officeId=&date=  → créneaux de livraison du bureau (via sa tournée)
-- Une ligne par fenêtre (window_label morning/afternoon). 'afternoon' → slot 'soir'.
-- `orderable` calculé côté PHP : date future = ok ; aujourd'hui = NOW() < cutoff_time.
-- ============================================================================
SELECT ta.id, ta.window_label,
       TIME_FORMAT(ta.delivery_start,'%H:%i') AS delivery_time,
       TIME_FORMAT(ta.cutoff_time,'%H:%i')    AS cutoff
  FROM ws_offices o
  JOIN ws_tours t              ON t.id = o.tour_id
  JOIN ws_tour_availability ta ON ta.tour_id = t.id AND ta.shop_id = t.shop_id
 WHERE o.id = :officeId
   AND ta.delivery_day = WEEKDAY(:date) + 1   -- 1=lundi..7=dimanche (ISO)
   AND ta.active = 1
 ORDER BY ta.delivery_start;

-- Donner un créneau soir (livraison 17:00, cutoff 15:00) à UNE tournée :
-- INSERT INTO ws_tour_availability
--   (tour_id, shop_id, delivery_day, window_label, delivery_start, delivery_end, cutoff_time, active)
-- SELECT tour_id, shop_id, delivery_day, 'afternoon', '17:00:00', '18:00:00', '15:00:00', 1
--   FROM ws_tour_availability
--  WHERE tour_id = 2 AND shop_id = 2 AND window_label = 'morning';
