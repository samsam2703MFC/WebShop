-- ============================================================================
-- migrate-unify-shops.sql  —  PHASE 1 : créer `shops` + peupler (ws_shops + lp_shops)
-- IDEMPOTENT + TRANSACTIONNEL. NON destructif : ne touche PAS ws_shops / lp_shops.
-- ⚠️ NE PAS EXÉCUTER sans validation (règles de conflits §3 de SHOPS_UNIFY_AUDIT.md).
--
-- Faits (atelierby_db) :
--   • ws_shops.id = id Franchise Buddy (conservé comme PK de `shops` → 21 FK survivent)
--   • lp_shops.id = AUTO_INCREMENT (lignes lp-only → nouvel id > MAX)
--   • Clé de matching : ws_shops.slug  =  lp_shops.picker_key
-- Règles de conflits appliquées : identité←ws ; branding vitrine (image_path/landing)←lp ;
--   email/phone : ws sinon lp (COALESCE) ; webshop_*→webshop_config ; champs vitrine→landing_config.
-- ============================================================================

START TRANSACTION;

-- 0) Table cible
CREATE TABLE IF NOT EXISTS shops (
  id             INT PRIMARY KEY,               -- conserve ws_shops.id ; lp-only → nouvel id
  slug           VARCHAR(50) NOT NULL,
  id_brand       INT DEFAULT 1,
  name           VARCHAR(150) NOT NULL,
  legal_name     VARCHAR(150),
  email          VARCHAR(100),
  phone          VARCHAR(30),
  street         VARCHAR(150),
  street_num     VARCHAR(20),
  address_line   VARCHAR(255),                  -- adresse 1-ligne (lp) / dérivée (ws)
  zip            VARCHAR(20),
  city           VARCHAR(100),
  country_code   VARCHAR(5) DEFAULT 'BE',
  vat            VARCHAR(30),
  opening_time   TIME,
  closing_time   TIME,
  accent         VARCHAR(20) DEFAULT '#8D1D2C',
  tint           VARCHAR(20) DEFAULT '#fdf6f0',
  logo_url       VARCHAR(255),
  image_path     VARCHAR(255),                  -- vitrine (lp)
  webshop_enabled TINYINT(1) NOT NULL DEFAULT 0,
  landing_enabled TINYINT(1) NOT NULL DEFAULT 0,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  webshop_config  JSON,                          -- {discount_type, discount_value}
  landing_config  JSON,                          -- {kind, concept_fr, concept_nl, webshop_url, zone, lat, lng, sort_order}
  legacy_ws_id    INT,
  legacy_lp_id    INT,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shops_slug (slug),
  KEY idx_legacy_ws (legacy_ws_id),
  KEY idx_legacy_lp (legacy_lp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1) ws_shops → shops (id conservé, webshop_enabled=1). Idempotent (upsert par id).
INSERT INTO shops
  (id, slug, id_brand, name, legal_name, email, phone, street, street_num, address_line,
   zip, city, country_code, vat, opening_time, closing_time, accent, tint, logo_url,
   webshop_enabled, active, webshop_config, legacy_ws_id)
SELECT w.id, w.slug, w.id_brand, w.name, w.legal_name, w.email, w.phone, w.street, w.street_num,
       NULLIF(TRIM(CONCAT_WS(' ', w.street, w.street_num)), ''),
       w.zip, w.city, w.country_code, w.vat, w.opening_time, w.closing_time, w.accent, w.tint, w.logo_url,
       1, w.active,
       JSON_OBJECT('discount_type', w.webshop_discount_type, 'discount_value', w.webshop_discount_value),
       w.id
FROM ws_shops w
ON DUPLICATE KEY UPDATE
  slug=VALUES(slug), name=VALUES(name), legal_name=VALUES(legal_name),
  email=VALUES(email), phone=VALUES(phone), street=VALUES(street), street_num=VALUES(street_num),
  address_line=VALUES(address_line), zip=VALUES(zip), city=VALUES(city), vat=VALUES(vat),
  opening_time=VALUES(opening_time), closing_time=VALUES(closing_time),
  accent=VALUES(accent), tint=VALUES(tint), logo_url=VALUES(logo_url),
  webshop_enabled=1, webshop_config=VALUES(webshop_config), legacy_ws_id=VALUES(legacy_ws_id);

-- 2a) lp_shops MATCHÉS (picker_key = un slug ws existant) → merge vitrine, landing_enabled=1.
--     COLLATE : lp_shops = utf8mb4_unicode_ci, ws/shops = utf8mb4_0900_ai_ci (évite #1267).
UPDATE shops s
  JOIN lp_shops l ON l.picker_key = s.slug COLLATE utf8mb4_unicode_ci AND l.picker_key <> ''
SET s.landing_enabled = 1,
    s.legacy_lp_id     = l.id,
    s.image_path       = COALESCE(NULLIF(s.image_path,''), NULLIF(l.image_path,'')),
    s.email            = COALESCE(NULLIF(s.email,''), NULLIF(l.email,'')),      -- ws prioritaire
    s.phone            = COALESCE(NULLIF(s.phone,''), NULLIF(l.phone,'')),
    s.city             = COALESCE(NULLIF(s.city,''), NULLIF(l.city,'')),
    s.zip              = COALESCE(NULLIF(s.zip,''), NULLIF(l.postal_code,'')),
    s.address_line     = COALESCE(NULLIF(s.address_line,''), NULLIF(l.address,'')),
    s.landing_config   = JSON_OBJECT('kind', l.kind, 'concept_fr', l.concept_fr,
                          'concept_nl', l.concept_nl, 'webshop_url', l.webshop_url,
                          'zone', l.zone, 'lat', l.lat, 'lng', l.lng, 'sort_order', l.sort_order);

-- 2b) lp_shops SEULS (picker_key vide OU ne matche aucun slug ws) → nouvelles lignes.
--     Nouvel id = MAX(shops.id) + rang. Slug = picker_key sinon 'lp-<id>'. Idempotent via legacy_lp_id.
INSERT INTO shops
  (id, slug, name, email, phone, address_line, zip, city, image_path,
   landing_enabled, active, landing_config, legacy_lp_id)
SELECT base.maxid + ROW_NUMBER() OVER (ORDER BY l.id),
       COALESCE(NULLIF(l.picker_key,''), CONCAT('lp-', l.id)),
       l.name, NULLIF(l.email,''), NULLIF(l.phone,''), NULLIF(l.address,''),
       NULLIF(l.postal_code,''), NULLIF(l.city,''), NULLIF(l.image_path,''),
       1, l.is_active,
       JSON_OBJECT('kind', l.kind, 'concept_fr', l.concept_fr, 'concept_nl', l.concept_nl,
                   'webshop_url', l.webshop_url, 'zone', l.zone, 'lat', l.lat, 'lng', l.lng,
                   'sort_order', l.sort_order),
       l.id
FROM lp_shops l
CROSS JOIN (SELECT COALESCE(MAX(id),0) AS maxid FROM shops) base
WHERE NOT EXISTS (SELECT 1 FROM shops s WHERE s.legacy_lp_id = l.id)            -- pas déjà migré
  AND NOT EXISTS (SELECT 1 FROM shops s2
                   WHERE s2.slug = l.picker_key COLLATE utf8mb4_unicode_ci AND l.picker_key <> ''); -- pas un match ws

COMMIT;

-- Contrôles (à lancer après) :
--   SELECT COUNT(*) FROM ws_shops;  SELECT COUNT(*) FROM lp_shops;  SELECT COUNT(*) FROM shops;
--   SELECT COUNT(*) FROM shops WHERE slug IS NULL OR slug='';                       -- 0 attendu
--   SELECT slug, COUNT(*) FROM shops GROUP BY slug HAVING COUNT(*)>1;               -- 0 doublon
--   SELECT webshop_enabled, landing_enabled, COUNT(*) FROM shops GROUP BY 1,2;      -- répartition
--   SELECT id, slug, name, webshop_enabled, landing_enabled, legacy_ws_id, legacy_lp_id FROM shops ORDER BY id;
