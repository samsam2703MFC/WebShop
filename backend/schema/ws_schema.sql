-- ============================================================
-- L'Atelier By — Webshop schema (ws_) — VERSION FULL INTEGER
-- MySQL / MariaDB — script tout-en-un :
--   1) DROP de toutes les tables ws_
--   2) CREATE de la structure complète (toutes les PK/FK en INT)
--   3) MOCK DATA cohérent pour toutes les tables
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS ws_delivery_fee_rules;
DROP TABLE IF EXISTS ws_office_delivery_settings;
DROP TABLE IF EXISTS ws_tour_availability;
DROP TABLE IF EXISTS ws_slot_capacity;
DROP TABLE IF EXISTS ws_category_availability;
DROP TABLE IF EXISTS ws_product_availability;
DROP TABLE IF EXISTS ws_shop_exceptions;
DROP TABLE IF EXISTS ws_shop_availability;
DROP TABLE IF EXISTS ws_stock_reservations;
DROP TABLE IF EXISTS ws_product_stock;
DROP TABLE IF EXISTS ws_pricing_rules;
DROP TABLE IF EXISTS ws_slots;
DROP TABLE IF EXISTS ws_calendar_rules;
DROP TABLE IF EXISTS ws_vouchers;
DROP TABLE IF EXISTS ws_order_lines;
DROP TABLE IF EXISTS ws_orders;
DROP TABLE IF EXISTS ws_office_delivery_sites;
DROP TABLE IF EXISTS ws_customers;
DROP TABLE IF EXISTS ws_offices;
DROP TABLE IF EXISTS ws_tours;
DROP TABLE IF EXISTS ws_assortments;
DROP TABLE IF EXISTS ws_bundle_slot_choices;
DROP TABLE IF EXISTS ws_bundle_slots;
DROP TABLE IF EXISTS ws_bundles;
DROP TABLE IF EXISTS ws_product_option_choices;
DROP TABLE IF EXISTS ws_product_options;
DROP TABLE IF EXISTS ws_product_prices;
DROP TABLE IF EXISTS ws_product_shops;
DROP TABLE IF EXISTS ws_product_allergens;
DROP TABLE IF EXISTS ws_products;
DROP TABLE IF EXISTS ws_category_subs;
DROP TABLE IF EXISTS ws_categories;
DROP TABLE IF EXISTS ws_shop_payment_options;
DROP TABLE IF EXISTS ws_shops;

CREATE TABLE ws_shops (
  id            INT PRIMARY KEY,               -- = id Franchise Buddy
  id_brand      INT DEFAULT 1,
  slug          VARCHAR(50) UNIQUE NOT NULL,   -- URL: ?shop=halle
  name          VARCHAR(150) NOT NULL,
  legal_name    VARCHAR(150),
  email         VARCHAR(100),
  phone         VARCHAR(30),
  street        VARCHAR(150),
  street_num    VARCHAR(20),
  zip           VARCHAR(20),
  city          VARCHAR(100),
  country_code  VARCHAR(5) DEFAULT 'BE',
  vat           VARCHAR(30),
  opening_time  TIME,
  closing_time  TIME,
  accent        VARCHAR(20) DEFAULT '#8D1D2C',
  tint          VARCHAR(20) DEFAULT '#fdf6f0',
  logo_url      VARCHAR(255),
  webshop_discount_type  VARCHAR(20)   DEFAULT 'percent',  -- percent | fixed
  webshop_discount_value DECIMAL(10,2) DEFAULT 0,          -- remise auto du webshop
  active        BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Moyens de paiement autorisés PAR boutique ET PAR type de profil.
--   profile_type : guest | registered | company
--   method       : stripe | shop | deferred
-- Si aucune ligne pour une boutique → aucune restriction (tout autorisé).
CREATE TABLE ws_shop_payment_options (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  shop_id      INT NOT NULL,
  profile_type VARCHAR(20) NOT NULL,
  method       VARCHAR(20) NOT NULL,
  active       BOOLEAN DEFAULT TRUE,
  UNIQUE KEY uq_shop_payment (shop_id, profile_type, method),
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_categories (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  shop_id     INT,
  slug        VARCHAR(50),
  label       VARCHAR(100) NOT NULL,
  img         VARCHAR(255),
  sort_order  INT DEFAULT 0,
  active      BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_category_subs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT,
  slug        VARCHAR(50),
  label       VARCHAR(100) NOT NULL,
  img         VARCHAR(255),
  sort_order  INT DEFAULT 0,
  active      BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (category_id) REFERENCES ws_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_products (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  cat_id           INT,
  sub_cat_id       INT,
  name             VARCHAR(200) NOT NULL,
  description      TEXT,
  price            DECIMAL(10,2) NOT NULL,
  img              VARCHAR(255),
  badge            VARCHAR(50),
  portions         BOOLEAN DEFAULT FALSE,
  cross_portion    BOOLEAN DEFAULT FALSE,
  has_menu_options BOOLEAN DEFAULT FALSE,
  active           BOOLEAN DEFAULT TRUE,
  brand_webshop    TINYINT(1) NOT NULL DEFAULT 1,  -- accepté par la marque pour le webshop (whitelist réseau)
  brand_mandatory  TINYINT(1) NOT NULL DEFAULT 0,  -- produit obligatoire imposé marque (non désactivable par le shop)
  FOREIGN KEY (cat_id)     REFERENCES ws_categories(id),
  FOREIGN KEY (sub_cat_id) REFERENCES ws_category_subs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_product_allergens (
  product_id  INT,
  allergen    VARCHAR(50) NOT NULL,
  PRIMARY KEY (product_id, allergen),
  FOREIGN KEY (product_id) REFERENCES ws_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_product_shops (
  product_id  INT,
  shop_id     INT,
  no_delivery BOOLEAN DEFAULT FALSE,
  active      BOOLEAN DEFAULT TRUE,
  PRIMARY KEY (product_id, shop_id),
  FOREIGN KEY (product_id) REFERENCES ws_products(id),
  FOREIGN KEY (shop_id)    REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_product_prices (
  product_id  INT,
  shop_id     INT,
  price       DECIMAL(10,2) NOT NULL,
  active      BOOLEAN DEFAULT TRUE,
  PRIMARY KEY (product_id, shop_id),
  FOREIGN KEY (product_id) REFERENCES ws_products(id),
  FOREIGN KEY (shop_id)    REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_product_options (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  product_id  INT,
  label       VARCHAR(100) NOT NULL,
  required    BOOLEAN DEFAULT FALSE,
  sort_order  INT DEFAULT 0,
  active      BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (product_id) REFERENCES ws_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_product_option_choices (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  option_id  INT,
  label      VARCHAR(100)  NOT NULL,
  delta      DECIMAL(10,2) DEFAULT 0,
  sort_order INT DEFAULT 0,
  active     BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (option_id) REFERENCES ws_product_options(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_bundles (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  product_id     INT,
  name           VARCHAR(100)  NOT NULL,
  description    TEXT,
  price_modifier DECIMAL(10,2) DEFAULT 0,
  sort_order     INT DEFAULT 0,
  active         BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (product_id) REFERENCES ws_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_bundle_slots (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  bundle_id  INT,
  label      VARCHAR(100) NOT NULL,
  required   BOOLEAN DEFAULT FALSE,
  sort_order INT DEFAULT 0,
  active     BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (bundle_id) REFERENCES ws_bundles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_bundle_slot_choices (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  slot_id    INT,
  label      VARCHAR(100)  NOT NULL,
  img        VARCHAR(255),
  delta      DECIMAL(10,2) DEFAULT 0,
  sort_order INT DEFAULT 0,
  active     BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (slot_id) REFERENCES ws_bundle_slots(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_assortments (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  shop_id INT,
  label   VARCHAR(100) NOT NULL,
  img     VARCHAR(255),
  active  BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_tours (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  shop_id   INT,
  name      VARCHAR(100) NOT NULL,
  max_items INT,                    -- cap items par route (NULL = illimité). Cap fin par créneau : ws_tour_availability.max_items
  active    BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_offices (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tour_id     INT,
  name        VARCHAR(200) NOT NULL,
  address     VARCHAR(200),
  postal_code VARCHAR(20),
  city        VARCHAR(100),
  contact     VARCHAR(100),
  email       VARCHAR(100),
  phone       VARCHAR(30),
  vat         VARCHAR(30),
  status      VARCHAR(20)  DEFAULT 'pending',
  deferred_billing_enabled BOOLEAN DEFAULT FALSE,  -- commande sur compte (facturation)
  contract_url             VARCHAR(255),           -- contrat rattaché au compte
  referrer_client_id       INT,                     -- apporteur (client.id ERP) — posé 1x à la création depuis une demande ; FK ajoutée en prod
  drop_minutes DECIMAL(5,2) NOT NULL DEFAULT 5.00, -- coût-temps dépôt (min), payé 1x/bureau
  active      BOOLEAN DEFAULT TRUE,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tour_id) REFERENCES ws_tours(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Adresses e-mail rattachées à un compte entreprise (plusieurs par bureau).
CREATE TABLE ws_office_emails (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  office_id    INT NOT NULL,
  email        VARCHAR(200) NOT NULL,
  contract_url VARCHAR(255),
  active       BOOLEAN DEFAULT TRUE,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_office_email (office_id, email),
  KEY idx_office_email (email),
  FOREIGN KEY (office_id) REFERENCES ws_offices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_customers (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  email               VARCHAR(200) UNIQUE NOT NULL,
  password_hash       VARCHAR(255) NOT NULL,
  first_name          VARCHAR(100),
  last_name           VARCHAR(100),
  phone               VARCHAR(30),
  client_id           INT,                        -- lien vers le client ERP (réf logique) — auth commune
  office_id           INT,
  preferred_shop_id   INT,
  preferred_lang      VARCHAR(5)   DEFAULT 'fr',
  is_business         BOOLEAN DEFAULT FALSE,
  fidelity_active     BOOLEAN DEFAULT FALSE,
  fidelity_linked_at  TIMESTAMP NULL DEFAULT NULL,
  invoice_country     VARCHAR(5)   DEFAULT 'BE',
  invoice_vat         VARCHAR(30),
  invoice_name        VARCHAR(200),
  invoice_address     VARCHAR(200),
  invoice_postal_code VARCHAR(20),
  invoice_city        VARCHAR(100),
  active              BOOLEAN DEFAULT TRUE,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_customers_phone (phone),               -- login par téléphone
  KEY idx_customers_client (client_id),
  FOREIGN KEY (office_id)         REFERENCES ws_offices(id),
  FOREIGN KEY (preferred_shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_office_delivery_sites (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  office_client_id INT,                    -- ws_offices.id (site créé côté webshop) — nullable
  client_id        INT,                    -- ERP client.id (config livraison B2B synchronisée) — nullable
  name             VARCHAR(120),           -- nullable (les lignes synchro ERP n'en ont pas)
  address          VARCHAR(250),
  floor_room       VARCHAR(120),
  contact_name     VARCHAR(120),
  contact_phone    VARCHAR(30),
  tournee_id       INT,                    -- route / tournée (ws_tours) = ex "route_id"
  tournee_stop_id  INT,
  shop_id          INT,
  site_access_minutes DECIMAL(5,2) NOT NULL DEFAULT 10.00, -- coût-temps accès site (min), payé 1x/site
  active           BOOLEAN DEFAULT TRUE,   -- pour les lignes ERP : piloté par la règle top-5 (posé explicitement)
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ods_client (client_id),    -- 1 config par client ERP (NULL multiples OK pour les sites webshop)
  KEY idx_ods_shop (shop_id),
  FOREIGN KEY (office_client_id) REFERENCES ws_offices(id),
  FOREIGN KEY (tournee_id)       REFERENCES ws_tours(id)
  -- Pas de FK sur shop_id : c'est une réf logique (l'ERP a des id_main_shop non seedés dans ws_shops).
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_slots (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  shop_id    INT,
  mode       VARCHAR(20)  NOT NULL,
  label      VARCHAR(100) NOT NULL,
  sort_order INT DEFAULT 0,
  active     BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_orders (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  order_ref        VARCHAR(50) UNIQUE,
  shop_id          INT,
  customer_id      INT,                        -- NULL = commande visiteur (guest)
  guest_email      VARCHAR(200),               -- contact visiteur (si pas de compte)
  guest_name       VARCHAR(200),
  guest_phone      VARCHAR(30),
  mode             VARCHAR(20)   NOT NULL,
  status           VARCHAR(30)   DEFAULT 'pending',
  slot_id          INT,
  slot_label       VARCHAR(100),
  delivery_date    DATE,
  subtotal         DECIMAL(10,2),
  promo_amount     DECIMAL(10,2) DEFAULT 0,
  webshop_discount DECIMAL(10,2) DEFAULT 0,   -- remise auto du webshop (config boutique)
  voucher_code     VARCHAR(50),
  voucher_discount DECIMAL(10,2) DEFAULT 0,
  total            DECIMAL(10,2),
  payment_method   VARCHAR(30),
  payment_status   VARCHAR(30)   DEFAULT 'pending',
  lang             VARCHAR(5)    DEFAULT 'fr',
  note             VARCHAR(500),               -- note libre au niveau commande
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  office_client_id          INT,
  office_delivery_site_id   INT,
  office_delivery_site_name VARCHAR(120),
  payment_type              VARCHAR(20) DEFAULT 'immediate',
  delivery_fee_applied      BOOLEAN DEFAULT FALSE,
  delivery_fee_amount       DECIMAL(8,2) DEFAULT 0,
  free_delivery_minimum     DECIMAL(8,2) DEFAULT 0,
  tournee_stop_id           INT,
  delivery_mode             VARCHAR(20) DEFAULT 'collect',
  tour_id                   INT,                     -- tournée dénormalisée (perf ops) — FK ws_tours
  delivered_at              TIMESTAMP NULL DEFAULT NULL, -- horodatage remise
  delivery_proof            VARCHAR(255),            -- preuve remise (URL signature/photo)
  prep_by                   INT,                     -- préparateur (réf logique)
  KEY idx_ops_day (shop_id, delivery_date, status),  -- files opérationnelles du jour
  FOREIGN KEY (shop_id)                 REFERENCES ws_shops(id),
  FOREIGN KEY (customer_id)             REFERENCES ws_customers(id),
  FOREIGN KEY (slot_id)                 REFERENCES ws_slots(id),
  FOREIGN KEY (office_client_id)        REFERENCES ws_offices(id),
  FOREIGN KEY (office_delivery_site_id) REFERENCES ws_office_delivery_sites(id),
  FOREIGN KEY (tour_id)                 REFERENCES ws_tours(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_order_lines (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  order_id     INT,
  product_id   INT,
  product_name VARCHAR(200),
  qty          INT           NOT NULL,
  unit_price   DECIMAL(10,2),
  `portion`    VARCHAR(20),                -- mot réservé MariaDB → backticks
  note         VARCHAR(255),               -- note libre au niveau produit
  bundle_id    INT,
  options      JSON,
  bundle_slots JSON,
  FOREIGN KEY (order_id)   REFERENCES ws_orders(id),
  FOREIGN KEY (product_id) REFERENCES ws_products(id),
  FOREIGN KEY (bundle_id)  REFERENCES ws_bundles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_vouchers (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(50) UNIQUE NOT NULL,
  shop_id     INT NULL,                    -- NULL = bon marque/réseau ; sinon bon local du shop
  id_brand    INT NOT NULL DEFAULT 1,      -- marque cible d'un bon réseau
  type        VARCHAR(20) NOT NULL,        -- percent|fixed|... ; 'add_office' = onboarding bureau
  value       DECIMAL(10,2),
  min_order   DECIMAL(10,2) DEFAULT 0,
  max_uses    INT,
  used_count  INT DEFAULT 0,
  expires_at  TIMESTAMP NULL DEFAULT NULL,
  active      BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_calendar_rules (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  shop_id         INT,
  mode            VARCHAR(20) NOT NULL,
  open_days       JSON,
  cutoff_hour     INT NOT NULL,
  cutoff_minutes  INT DEFAULT 0,
  lead_hours      INT DEFAULT 0,
  active          BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_pricing_rules (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  shop_id   INT,
  rule_type VARCHAR(50) NOT NULL,
  x         INT,
  y         INT,
  threshold INT,
  label     VARCHAR(200),
  active    BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_product_stock (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  product_id   INT,
  shop_id      INT,
  date         DATE NOT NULL,
  mode         VARCHAR(20),
  qty_total    INT NOT NULL,
  qty_reserved INT DEFAULT 0,
  qty_sold     INT DEFAULT 0,
  active       BOOLEAN DEFAULT TRUE,
  UNIQUE KEY uq_stock (product_id, shop_id, date, mode),
  FOREIGN KEY (product_id) REFERENCES ws_products(id),
  FOREIGN KEY (shop_id)    REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_stock_reservations (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  product_id  INT,
  shop_id     INT,
  date        DATE NOT NULL,
  mode        VARCHAR(20) NOT NULL,
  qty         INT NOT NULL,
  customer_id INT,
  expires_at  TIMESTAMP NOT NULL,
  released    BOOLEAN DEFAULT FALSE,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_stock_res_expiry (released, expires_at),
  FOREIGN KEY (product_id)  REFERENCES ws_products(id),
  FOREIGN KEY (shop_id)     REFERENCES ws_shops(id),
  FOREIGN KEY (customer_id) REFERENCES ws_customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_shop_availability (
  shop_id                    INT PRIMARY KEY,
  collect_enabled            BOOLEAN DEFAULT TRUE,
  delivery_enabled           BOOLEAN DEFAULT TRUE,
  collect_open_days          JSON,
  delivery_open_days         JSON,
  collect_hours_start        TIME     DEFAULT '08:00:00',
  collect_hours_end          TIME     DEFAULT '19:00:00',
  delivery_hours_start       TIME     DEFAULT '08:30:00',
  delivery_hours_end         TIME     DEFAULT '13:30:00',
  collect_slot_duration_min  INT      DEFAULT 60,
  delivery_slot_duration_min INT      DEFAULT 120,
  collect_cutoff_hour        SMALLINT DEFAULT 16,
  collect_cutoff_minute      SMALLINT DEFAULT 0,
  collect_lead_hours         SMALLINT DEFAULT 2,
  delivery_cutoff_hour       SMALLINT DEFAULT 11,
  delivery_cutoff_minute     SMALLINT DEFAULT 0,
  delivery_lead_hours        SMALLINT DEFAULT 20,
  collect_capacity_per_slot  INT      DEFAULT 15,
  delivery_capacity_per_slot INT      DEFAULT 30,
  timezone                   VARCHAR(50) DEFAULT 'Europe/Brussels',
  updated_at                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_shop_exceptions (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  shop_id              INT NOT NULL,
  exception_date       DATE NOT NULL,
  type                 VARCHAR(20) NOT NULL,
  reason               VARCHAR(200),
  collect_hours_start  TIME,
  collect_hours_end    TIME,
  delivery_hours_start TIME,
  delivery_hours_end   TIME,
  collect_enabled      BOOLEAN,
  delivery_enabled     BOOLEAN,
  created_by           INT,
  created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shop_exception (shop_id, exception_date),
  FOREIGN KEY (shop_id)    REFERENCES ws_shops(id),
  FOREIGN KEY (created_by) REFERENCES ws_customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_product_availability (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  product_id               INT NOT NULL,
  shop_id                  INT NOT NULL,
  collect_enabled          BOOLEAN DEFAULT TRUE,
  delivery_enabled         BOOLEAN DEFAULT TRUE,
  collect_lead_time        SMALLINT DEFAULT 0,
  delivery_lead_time       SMALLINT DEFAULT 0,
  collect_cutoff_override  TIME,
  delivery_cutoff_override TIME,
  max_qty_per_day          INT,
  max_qty_per_slot         INT,
  active                   BOOLEAN DEFAULT TRUE,
  UNIQUE KEY uq_prod_avail (product_id, shop_id),
  KEY idx_prod_avail_shop (shop_id),
  FOREIGN KEY (product_id) REFERENCES ws_products(id),
  FOREIGN KEY (shop_id)    REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_category_availability (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  category_id              INT NOT NULL,
  shop_id                  INT NOT NULL,
  collect_enabled          BOOLEAN DEFAULT TRUE,
  delivery_enabled         BOOLEAN DEFAULT TRUE,
  collect_lead_time        SMALLINT DEFAULT 0,
  delivery_lead_time       SMALLINT DEFAULT 0,
  collect_cutoff_override  TIME,
  delivery_cutoff_override TIME,
  active                   BOOLEAN DEFAULT TRUE,
  UNIQUE KEY uq_cat_avail (category_id, shop_id),
  FOREIGN KEY (category_id) REFERENCES ws_categories(id),
  FOREIGN KEY (shop_id)     REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_slot_capacity (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  shop_id          INT NOT NULL,
  mode             VARCHAR(20) NOT NULL,
  slot_date        DATE NOT NULL,
  slot_start       TIME NOT NULL,
  slot_end         TIME NOT NULL,
  max_orders       INT NOT NULL,
  max_items        INT,
  current_orders   INT NOT NULL DEFAULT 0,
  current_items    INT NOT NULL DEFAULT 0,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slot_cap (shop_id, mode, slot_date, slot_start),
  KEY idx_slot_cap_date (shop_id, slot_date, mode),
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_tour_availability (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  tour_id        INT NOT NULL,
  shop_id        INT NOT NULL,
  delivery_day   SMALLINT NOT NULL,
  -- Which delivery window on that day. A tour can have several rows per day
  -- (e.g. 'morning' + 'afternoon'), each with its own hours and order cutoff.
  window_label   VARCHAR(16) NOT NULL DEFAULT 'morning',
  delivery_start TIME NOT NULL,
  delivery_end   TIME NOT NULL,
  cutoff_time    TIME NOT NULL,
  max_orders     INT,
  max_items      INT,
  active         BOOLEAN DEFAULT TRUE,
  UNIQUE KEY uq_tour_avail (tour_id, shop_id, delivery_day, window_label),
  FOREIGN KEY (tour_id) REFERENCES ws_tours(id),
  FOREIGN KEY (shop_id) REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fermetures ponctuelles d'une tournée à une date donnée (exception au planning
-- récurrent ws_tour_availability). Lue par tour_orderable() (php-api).
-- (Reflète la structure réelle en base : reason VARCHAR(120), sans autre champ.)
CREATE TABLE ws_tour_closures (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tour_id       INT NOT NULL,
  closure_date  DATE NOT NULL,
  reason        VARCHAR(120),                 -- motif optionnel (férié, congé chauffeur…)
  UNIQUE KEY uq_tour_closure (tour_id, closure_date),
  FOREIGN KEY (tour_id) REFERENCES ws_tours(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- NOTE: the ERP B2B client → route mapping is now merged into
-- ws_office_delivery_sites (columns client_id + tournee_id + shop_id + active).
-- The standalone ws_clientb2bdelivery table has been removed.

CREATE TABLE ws_office_delivery_settings (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  office_id       INT NOT NULL,
  shop_id         INT NOT NULL,
  tour_id         INT,
  allowed_days    JSON,
  delivery_cutoff TIME,
  delivery_notes  VARCHAR(500),
  active          BOOLEAN DEFAULT TRUE,
  UNIQUE KEY uq_office_delivery (office_id, shop_id),
  FOREIGN KEY (office_id) REFERENCES ws_offices(id),
  FOREIGN KEY (shop_id)   REFERENCES ws_shops(id),
  FOREIGN KEY (tour_id)   REFERENCES ws_tours(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Demandes de rattachement à un bureau (Prompt 2) : un utilisateur connecté sans
-- office_id demande la livraison bureau. Table LÉGÈRE (jamais une ligne ws_offices
-- 'pending'). Résolue par le franchisé -> linked / created / rejected.
-- client_id = client.id (ERP, réf logique) ; shop_id = routage seul (réaffectable).
CREATE TABLE ws_office_join_requests (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  client_id          INT NOT NULL,                            -- client.id (ERP) — depuis la session
  shop_id            INT NOT NULL,                            -- routage (boutique webshop), réaffectable
  office_name_raw    VARCHAR(200) NOT NULL,
  address_raw        VARCHAR(250) NOT NULL,
  status             VARCHAR(12) NOT NULL DEFAULT 'pending',  -- pending | linked | created | rejected
  resolved_office_id INT NULL,
  reject_reason      VARCHAR(200) NULL,
  resolved_by        INT NULL,
  created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  resolved_at        TIMESTAMP NULL DEFAULT NULL,
  KEY idx_ojr_client_status (client_id, status),              -- plafond 3 pending / client
  KEY idx_ojr_shop_status  (shop_id, status),                 -- file du franchisé
  FOREIGN KEY (shop_id)            REFERENCES ws_shops(id),
  FOREIGN KEY (resolved_office_id) REFERENCES ws_offices(id)
  -- FK (client_id) -> client(id) ajoutée en prod (table ERP, hors ce schéma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ws_delivery_fee_rules (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  level                 VARCHAR(10) NOT NULL,
  site_id               INT,
  office_client_id      INT,
  tour_id               INT,
  shop_id               INT,
  free_delivery         BOOLEAN DEFAULT FALSE,
  always_charge         BOOLEAN DEFAULT FALSE,
  fee_amount            DECIMAL(8,2) DEFAULT 0,
  free_delivery_minimum DECIMAL(8,2) DEFAULT 0,
  payment_type          VARCHAR(20) DEFAULT 'immediate',
  active                BOOLEAN DEFAULT TRUE,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fee_level (level, active),
  FOREIGN KEY (site_id)          REFERENCES ws_office_delivery_sites(id),
  FOREIGN KEY (office_client_id) REFERENCES ws_offices(id),
  FOREIGN KEY (tour_id)          REFERENCES ws_tours(id),
  FOREIGN KEY (shop_id)          REFERENCES ws_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
