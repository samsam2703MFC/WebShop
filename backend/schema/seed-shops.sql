-- Tes 5 boutiques réelles (Franchise Buddy). Idempotent (ON DUPLICATE KEY).
-- Les id sont ceux de Franchise Buddy (pas d'AUTO_INCREMENT sur ws_shops).
SET NAMES utf8mb4;

INSERT INTO ws_shops
  (id, id_brand, slug, name, legal_name, email, phone,
   street, street_num, zip, city, country_code, vat, opening_time, closing_time, active)
VALUES
  (4,  1, 'halle',     'Atelier by - Halle',                 'Neutralles SRL',
   'halle@atelierby.be',     '0472.24.49.58',
   'Auguste Demaeghtlaan', '227',   '1500', 'Halle',     'BE', 'BE1024074728',  '07:00:00', '19:00:00', TRUE),
  (2,  1, 'corbais',   'Atelier by Berlo - Corbais',         'Berdiff s.a.',
   'corbais@atelierby.be',   '010.65.83.83',
   'Grand Route',          '20',    '1435', 'Corbais',   'BE', 'BE0451.766.810','06:00:00', '18:30:00', TRUE),
  (3,  1, 'gosselies', 'Atelier by Max & Sandra - Gosselies','Max & Sandra De Cnop',
   'gosselies@atelierby.be', '071.218.001',
   'Rue du Pircha',        '202',   '6041', 'Gosselies', 'BE', 'BE0794.645.378','06:00:00', '18:00:00', TRUE),
  (5,  1, 'sombreffe', 'Atelier by Harmonie - Sombreffe',    'Antalramo s.r.l.',
   'sombreffe@atelierby.be', '071.195.862',
   'Chaussée de Nivelles', '136/D', '5140', 'Sombreffe', 'BE', 'BE1011.185.606','07:00:00', '19:00:00', TRUE),
  (10, 1, 'gembloux',  'Atelier by Berlo - Gembloux',        'Pascal Wauters',
   'gembloux@atelierby.be',  '0725038049',
   NULL, NULL, NULL, 'Gembloux', 'BE', NULL, NULL, NULL, FALSE)
ON DUPLICATE KEY UPDATE
  slug = VALUES(slug), name = VALUES(name), legal_name = VALUES(legal_name),
  email = VALUES(email), phone = VALUES(phone), city = VALUES(city), active = VALUES(active);
