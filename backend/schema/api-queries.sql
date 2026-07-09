-- ============================================================
-- Requêtes API — L'Atelier By webshop (schéma ws_)
-- À tester dans phpMyAdmin (base test-webshop_db).
-- Les valeurs d'exemple ('berlo', 1, …) sont à remplacer par tes params (les "?").
-- Chaque bloc = une route API. Les SELECT sont sûrs (lecture seule).
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. SHOPS  →  GET /shops
-- ────────────────────────────────────────────────────────────
SELECT id, name, city, address, phone, email, accent, tint, logo_url
FROM ws_shops
WHERE active = 1
ORDER BY name;

-- Thème d'une boutique  →  GET /brand?shopId=berlo
SELECT id, name, accent, tint, logo_url
FROM ws_shops WHERE id = 'berlo';

-- ────────────────────────────────────────────────────────────
-- 2. CATÉGORIES  →  GET /catalog/categories?shopId=berlo
-- ────────────────────────────────────────────────────────────
SELECT id, label, img, sort_order
FROM ws_categories
WHERE active = 1 AND (shop_id = 'berlo' OR shop_id IS NULL)
ORDER BY sort_order, label;

-- Sous-catégories
SELECT s.id, s.category_id, s.label, s.img, s.sort_order
FROM ws_category_subs s
JOIN ws_categories c ON c.id = s.category_id
WHERE s.active = 1 AND (c.shop_id = 'berlo' OR c.shop_id IS NULL)
ORDER BY s.sort_order;

-- ────────────────────────────────────────────────────────────
-- 3. PRODUITS  →  GET /catalog/products?shopId=berlo
--    prix boutique (sinon global) + allergènes + retrait-seul
-- ────────────────────────────────────────────────────────────
SELECT p.id, p.cat, p.sub_cat, p.name, p.description, p.badge,
       p.portions, p.cross_portion, p.has_menu_options,
       COALESCE(pp.price, p.price) AS price,
       ps.no_delivery,
       (SELECT JSON_ARRAYAGG(allergen)
          FROM ws_product_allergens a WHERE a.product_id = p.id) AS allergens
FROM ws_products p
JOIN ws_product_shops ps
  ON ps.product_id = p.id AND ps.shop_id = 'berlo' AND ps.active = 1
LEFT JOIN ws_product_prices pp
  ON pp.product_id = p.id AND pp.shop_id = 'berlo' AND pp.active = 1
WHERE p.active = 1
ORDER BY p.cat, p.name;

-- Un produit  →  GET /catalog/products/:id (?shopId=berlo)
SELECT p.*, COALESCE(pp.price, p.price) AS shop_price
FROM ws_products p
LEFT JOIN ws_product_prices pp
  ON pp.product_id = p.id AND pp.shop_id = 'berlo' AND pp.active = 1
WHERE p.id = 1;

-- Options d'un produit (+ choix)
SELECT o.id AS option_id, o.label, o.required, o.sort_order,
       c.id AS choice_id, c.label AS choice_label, c.delta
FROM ws_product_options o
LEFT JOIN ws_product_option_choices c ON c.option_id = o.id AND c.active = 1
WHERE o.product_id = 1 AND o.active = 1
ORDER BY o.sort_order, c.sort_order;

-- ────────────────────────────────────────────────────────────
-- 4. BUNDLES / MENUS  →  GET /catalog/bundles?productId=1
-- ────────────────────────────────────────────────────────────
SELECT id, name, description, price_modifier, sort_order
FROM ws_bundles
WHERE product_id = 1 AND active = 1
ORDER BY sort_order;

-- Slots + choix d'un bundle
SELECT sl.id AS slot_id, sl.label AS slot_label, sl.required,
       ch.id AS choice_id, ch.label AS choice_label, ch.img, ch.delta
FROM ws_bundle_slots sl
LEFT JOIN ws_bundle_slot_choices ch ON ch.slot_id = sl.id AND ch.active = 1
WHERE sl.bundle_id = 'BUNDLE_ID' AND sl.active = 1
ORDER BY sl.sort_order, ch.sort_order;

-- ────────────────────────────────────────────────────────────
-- 5. ASSORTIMENTS  →  GET /catalog/assortments?shopId=berlo
-- ────────────────────────────────────────────────────────────
SELECT id, label, img
FROM ws_assortments
WHERE active = 1 AND (shop_id = 'berlo' OR shop_id IS NULL);

-- ────────────────────────────────────────────────────────────
-- 6. STOCK DU JOUR (disponibilité)  →  disponible = total - réservé - vendu
-- ────────────────────────────────────────────────────────────
SELECT product_id,
       GREATEST(0, qty_total - qty_reserved - qty_sold) AS available
FROM ws_product_stock
WHERE shop_id = 'berlo' AND date = CURDATE()
  AND (mode = 'collect' OR mode IS NULL) AND active = 1;

-- ────────────────────────────────────────────────────────────
-- 7. CALENDRIER / DISPONIBILITÉ  →  GET /availability, /calendar/*
-- ────────────────────────────────────────────────────────────
-- Réglages d'ouverture d'une boutique (heures, cutoff, capacité)
SELECT * FROM ws_shop_availability WHERE shop_id = 'berlo';

-- Règles calendrier (jours ouverts, cutoff) par mode
SELECT shop_id, mode, open_days, cutoff_hour, cutoff_minutes, lead_hours
FROM ws_calendar_rules
WHERE shop_id = 'berlo' AND active = 1;

-- Créneaux d'un mode
SELECT id, mode, label, sort_order
FROM ws_slots
WHERE shop_id = 'berlo' AND mode = 'collect' AND active = 1
ORDER BY sort_order;

-- Exceptions (fermetures / horaires modifiés à venir)
SELECT exception_date, type, reason
FROM ws_shop_exceptions
WHERE shop_id = 'berlo' AND exception_date >= CURDATE();

-- ────────────────────────────────────────────────────────────
-- 8. PRICING  →  cross-portion (4+1) + vouchers
-- ────────────────────────────────────────────────────────────
-- Règle 4+1  →  GET /pricing/promos/cross-portion?shopId=berlo
SELECT rule_type, x, y, threshold, label
FROM ws_pricing_rules
WHERE shop_id = 'berlo' AND rule_type = 'cross_portion' AND active = 1;

-- Valider un code promo  →  POST /vouchers/redeem
SELECT code, type, value, min_order, max_uses, used_count, expires_at
FROM ws_vouchers
WHERE code = 'BIENVENUE10' AND active = 1
  AND (expires_at IS NULL OR expires_at > NOW())
  AND (max_uses IS NULL OR used_count < max_uses);

-- ────────────────────────────────────────────────────────────
-- 9. TOURS / OFFICES  →  GET /tours, /offices
-- ────────────────────────────────────────────────────────────
SELECT id, shop_id, name FROM ws_tours WHERE active = 1;        -- + AND shop_id='berlo'

SELECT id, tour_id, name, address, postal_code, city, contact, email, phone, vat, status
FROM ws_offices
WHERE status = 'validated' AND active = 1;

-- Sites de livraison d'un office
SELECT id, name, address, floor_room, contact_name, contact_phone, shop_id
FROM ws_office_delivery_sites
WHERE office_client_id = 'OFFICE_ID' AND active = 1;

-- ────────────────────────────────────────────────────────────
-- 10. DELIVERY FEES  →  règle la plus spécifique
--     priorité: site > office > tour > shop > global
-- ────────────────────────────────────────────────────────────
SELECT id, level, free_delivery, always_charge, fee_amount,
       free_delivery_minimum, payment_type
FROM ws_delivery_fee_rules
WHERE active = 1 AND (
      (level = 'site'   AND site_id          = 'SITE_ID')  OR
      (level = 'office' AND office_client_id = 'OFFICE_ID') OR
      (level = 'tour'   AND tour_id          = 'TOUR_ID')  OR
      (level = 'shop'   AND shop_id          = 'berlo')    OR
      (level = 'global')
    )
ORDER BY FIELD(level, 'site', 'office', 'tour', 'shop', 'global')
LIMIT 1;

-- ────────────────────────────────────────────────────────────
-- 11. AUTH / CUSTOMERS  →  POST /auth/login, GET /auth/me
--     (le backend compare le password_hash bcrypt — jamais en SQL)
-- ────────────────────────────────────────────────────────────
SELECT id, email, password_hash, first_name, last_name, phone,
       office_id, preferred_shop_id, preferred_lang, is_business
FROM ws_customers
WHERE email = 'client@ex.be' AND active = 1;

-- ────────────────────────────────────────────────────────────
-- 12. ORDERS  →  POST /orders (création) + GET /orders/:id
-- ────────────────────────────────────────────────────────────
-- Créer une commande (exemple Click & Collect)
INSERT INTO ws_orders
  (id, shop_id, customer_id, mode, status, slot_id, slot_label, delivery_date,
   subtotal, promo_amount, voucher_code, voucher_discount, total,
   payment_method, payment_status, lang, delivery_mode)
VALUES
  ('ord-TEST-001', 'berlo', NULL, 'collect', 'pending', 'slot-1', '08:30–09:30', CURDATE(),
   6.70, 0, NULL, 0, 6.70, 'cash', 'pending', 'fr', 'collect');

-- Lignes de la commande (portion = mot réservé → backticks)
INSERT INTO ws_order_lines
  (order_id, product_id, product_name, qty, unit_price, `portion`, bundle_id, options, bundle_slots)
VALUES
  ('ord-TEST-001', 1, 'Croissant', 3, 1.40, NULL, NULL, NULL, NULL),
  ('ord-TEST-001', 2, 'Pain',      1, 2.50, NULL, NULL, NULL, NULL);

-- Lire une commande + ses lignes  →  GET /orders/:id
SELECT * FROM ws_orders      WHERE id       = 'ord-TEST-001';
SELECT * FROM ws_order_lines WHERE order_id = 'ord-TEST-001';

-- Décrément du stock à la validation (robuste, absolu par jour)
UPDATE ws_product_stock
SET qty_sold = qty_sold + 3
WHERE product_id = 1 AND shop_id = 'berlo' AND date = CURDATE()
  AND (mode = 'collect' OR mode IS NULL);
