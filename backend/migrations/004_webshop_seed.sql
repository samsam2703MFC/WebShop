-- =====================================================================
-- 004 — Webshop-owned seed data (offices, sites, fee rules, tours,
-- vouchers). These entities are NOT synced from the ERP — they are
-- managed in the webshop admin. Mirrors the frontend demo data.
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO ws_tours (id, name, shop_id, time_window, days) VALUES
  ('tour-bxl-mid', 'Bruxelles Midi',  'chatelain', '11:30–13:30', 'lun-ven'),
  ('tour-bxl-am',  'Bruxelles Matin', 'sablon',    '08:30–10:30', 'lun-ven'),
  ('tour-lg',      'Liège Centre',    'carre',     '11:00–13:00', 'mar-ven')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO ws_offices (id, name, contact, phone, email, address, tour_id, status, default_site_id) VALUES
  ('off-acme', 'ACME Avocats', 'Marie Dubois', '+32 472 11 22 33', 'marie@acme.be',
   'Rue de la Loi 120, 1040 Bxl', 'tour-bxl-mid', 'validated', 'site-acme-loi')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO ws_office_delivery_sites
  (id, office_client_id, name, address, floor_room, contact_name, contact_phone, tournee_id, tournee_stop_id, shop_id) VALUES
  ('site-acme-loi',  'off-acme', 'ACME Avocats — Rue de la Loi',  'Rue de la Loi 120, 1040 Bruxelles',
   '4e étage, salle Themis', 'Marie Dubois', '+32 472 11 22 33', 'tour-bxl-mid', 'stop-acme-loi', 'chatelain'),
  ('site-acme-arts', 'off-acme', 'ACME Avocats — Place des Arts', 'Place des Arts 7, 1210 Saint-Josse',
   'Réception', 'Pierre Fontaine', '+32 472 33 44 55', 'tour-bxl-am', 'stop-acme-arts', 'sablon')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO ws_delivery_fee_rules
  (id, level, site_id, office_client_id, tour_id, shop_id, free_delivery, always_charge, fee_amount, free_delivery_minimum, payment_type) VALUES
  ('rule-site-loi',  'site',   'site-acme-loi',  NULL, NULL, NULL, 0, 0, 4.50, 40.00, 'deferred'),
  ('rule-site-arts', 'site',   'site-acme-arts', NULL, NULL, NULL, 1, 0, 0,    0,     'immediate'),
  ('rule-off-acme',  'office', NULL, 'off-acme', NULL, NULL,       0, 0, 5.00, 50.00, 'deferred'),
  ('rule-global',    'global', NULL, NULL, NULL, NULL,             0, 0, 7.00, 50.00, 'immediate')
ON DUPLICATE KEY UPDATE fee_amount = VALUES(fee_amount);

INSERT INTO ws_vouchers (id, code, kind, value, min_order, max_uses) VALUES
  ('v-bienvenue10', 'BIENVENUE10', 'percent', 10.00, 20.00, NULL),
  ('v-flat5',       'FLAT5',       'amount',   5.00, 25.00, 100)
ON DUPLICATE KEY UPDATE value = VALUES(value);
