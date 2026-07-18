-- 0008 — Unification boutiques, PHASE 1 : créer + peupler `shops` (ws_shops + lp_shops).
-- Idempotent + transactionnel + NON destructif (ne touche pas ws_shops / lp_shops).
-- Reprend backend/schema/migrate-unify-shops.sql (règles de conflits validées : identité←ws,
-- branding vitrine←lp, email/phone COALESCE ws-prioritaire, webshop_*→JSON, vitrine→JSON).
--
-- Différence vs le script d'origine : le bloc lp_shops est GARDÉ par l'existence de la
-- table (procédure appelée seulement si lp_shops existe) — sinon `shops` est peuplée avec
-- les seules boutiques webshop, sans jamais casser le déploiement si lp_shops est absente.
--
-- Effet immédiat : le code applique déjà `$SHOPS = table 'shops' existe ? 'shops' : 'ws_shops'`
-- → dès cette phase, le franchisor lit `shops`. ws_shops/lp_shops restent des tables
-- intactes (les autres lectures webshop continuent) : état additif, réversible (DROP TABLE shops).

START TRANSACTION;

-- 0) Table cible (schéma canonique de l'équipe).
CREATE TABLE IF NOT EXISTS shops (
  id             INT PRIMARY KEY,
  slug           VARCHAR(50) NOT NULL,
  id_brand       INT DEFAULT 1,
  name           VARCHAR(150) NOT NULL,
  legal_name     VARCHAR(150),
  email          VARCHAR(100),
  phone          VARCHAR(30),
  street         VARCHAR(150),
  street_num     VARCHAR(20),
  address_line   VARCHAR(255),
  zip            VARCHAR(20),
  city           VARCHAR(100),
  country_code   VARCHAR(5) DEFAULT 'BE',
  vat            VARCHAR(30),
  opening_time   TIME,
  closing_time   TIME,
  accent         VARCHAR(20) DEFAULT '#8D1D2C',
  tint           VARCHAR(20) DEFAULT '#fdf6f0',
  logo_url       VARCHAR(255),
  image_path     VARCHAR(255),
  webshop_enabled TINYINT(1) NOT NULL DEFAULT 0,
  landing_enabled TINYINT(1) NOT NULL DEFAULT 0,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  webshop_config  JSON,
  landing_config  JSON,
  legacy_ws_id    INT,
  legacy_lp_id    INT,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shops_slug (slug),
  KEY idx_legacy_ws (legacy_ws_id),
  KEY idx_legacy_lp (legacy_lp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1) ws_shops → shops (id conservé = id Buddy, webshop_enabled=1). Upsert idempotent.
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

COMMIT;

-- 2) Merge lp_shops — GARDÉ par existence de la table (procédure appelée seulement si lp_shops
--    existe). Une procédure peut référencer lp_shops même si absente (résolution au CALL).
DROP PROCEDURE IF EXISTS _shops_merge_lp;
DELIMITER //
CREATE PROCEDURE _shops_merge_lp()
BEGIN
  -- 2a) lp matchés (picker_key ~ slug ws, normalisé) → merge vitrine, landing_enabled=1.
  UPDATE shops s
    JOIN lp_shops l ON LOWER(TRIM(l.picker_key)) = s.slug COLLATE utf8mb4_unicode_ci
                   AND TRIM(l.picker_key) <> ''
  SET s.landing_enabled = 1, s.legacy_lp_id = l.id,
      s.image_path   = COALESCE(NULLIF(s.image_path,''), NULLIF(l.image_path,'')),
      s.email        = COALESCE(NULLIF(s.email,''), NULLIF(l.email,'')),
      s.phone        = COALESCE(NULLIF(s.phone,''), NULLIF(l.phone,'')),
      s.city         = COALESCE(NULLIF(s.city,''), NULLIF(l.city,'')),
      s.zip          = COALESCE(NULLIF(s.zip,''), NULLIF(l.postal_code,'')),
      s.address_line = COALESCE(NULLIF(s.address_line,''), NULLIF(l.address,'')),
      s.landing_config = JSON_OBJECT('kind', l.kind, 'concept_fr', l.concept_fr,
                          'concept_nl', l.concept_nl, 'webshop_url', l.webshop_url,
                          'zone', l.zone, 'lat', l.lat, 'lng', l.lng, 'sort_order', l.sort_order);

  -- 2a-bis) vitrines riches à picker_key vide dont la VILLE = une boutique webshop → fusion.
  UPDATE shops s
    JOIN lp_shops l ON TRIM(l.picker_key) = '' AND s.webshop_enabled = 1
                   AND LOWER(TRIM(l.city)) = LOWER(TRIM(s.city)) COLLATE utf8mb4_unicode_ci
  SET s.landing_enabled = 1, s.legacy_lp_id = l.id,
      s.image_path   = COALESCE(NULLIF(l.image_path,''), s.image_path),
      s.address_line = COALESCE(NULLIF(l.address,''), s.address_line),
      s.landing_config = JSON_OBJECT('kind', l.kind, 'concept_fr', l.concept_fr,
                          'concept_nl', l.concept_nl, 'webshop_url', l.webshop_url,
                          'zone', l.zone, 'lat', l.lat, 'lng', l.lng, 'sort_order', l.sort_order);

  -- 2b) lp seuls (ville ≠ toute boutique webshop) → nouvelles lignes (id > MAX). Idempotent via legacy_lp_id.
  INSERT INTO shops
    (id, slug, name, email, phone, address_line, zip, city, image_path,
     landing_enabled, active, landing_config, legacy_lp_id)
  SELECT base.maxid + ROW_NUMBER() OVER (ORDER BY l.id),
         COALESCE(NULLIF(LOWER(TRIM(l.picker_key)),''), CONCAT('lp-', l.id)),
         l.name, NULLIF(l.email,''), NULLIF(l.phone,''), NULLIF(l.address,''),
         NULLIF(l.postal_code,''), NULLIF(l.city,''), NULLIF(l.image_path,''),
         1, l.is_active,
         JSON_OBJECT('kind', l.kind, 'concept_fr', l.concept_fr, 'concept_nl', l.concept_nl,
                     'webshop_url', l.webshop_url, 'zone', l.zone, 'lat', l.lat, 'lng', l.lng,
                     'sort_order', l.sort_order),
         l.id
  FROM lp_shops l
  CROSS JOIN (SELECT COALESCE(MAX(id),0) AS maxid FROM shops) base
  WHERE NOT EXISTS (SELECT 1 FROM shops s WHERE s.legacy_lp_id = l.id)
    AND NOT EXISTS (SELECT 1 FROM shops s2 WHERE s2.slug = LOWER(TRIM(l.picker_key)) COLLATE utf8mb4_unicode_ci
                       AND TRIM(l.picker_key) <> '')
    AND NOT EXISTS (SELECT 1 FROM shops s3 WHERE s3.webshop_enabled = 1
                       AND LOWER(TRIM(s3.city)) = LOWER(TRIM(l.city)) COLLATE utf8mb4_unicode_ci)
    AND LOWER(TRIM(l.picker_key)) <> 'wavre'
    AND LOWER(TRIM(l.city))       <> 'wavre';
END//
DELIMITER ;

SET @has_lp := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='lp_shops');
SET @s := IF(@has_lp=1, 'CALL _shops_merge_lp()', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
DROP PROCEDURE IF EXISTS _shops_merge_lp;

-- Contrôles (à lancer après) :
--   SELECT webshop_enabled, landing_enabled, COUNT(*) FROM shops GROUP BY 1,2;   -- répartition
--   SELECT COUNT(*) FROM shops WHERE slug IS NULL OR slug='';                    -- 0 attendu
--   SELECT slug, COUNT(*) c FROM shops GROUP BY slug HAVING c>1;                 -- 0 doublon
--   SELECT id, slug, name, webshop_enabled, landing_enabled, legacy_ws_id, legacy_lp_id FROM shops ORDER BY id;
