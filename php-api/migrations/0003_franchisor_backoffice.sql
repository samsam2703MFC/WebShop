-- 0003 — Console marque (franchisor) back-office : tables & colonnes.
-- Alimente les écrans du back-office franchisor (RBAC, audit, modèles d'email,
-- gouvernance menu) sans toucher au schéma webshop existant.
-- Idempotent & compatible MySQL 8 (pas de "ADD COLUMN IF NOT EXISTS") :
--   colonnes ajoutées via garde information_schema + PREPARE (rejouable sans casser).
-- ⚠ DDL MySQL auto-commit : aucune donnée existante n'est écrasée.

-- ── Helper idempotent : ajoute une colonne seulement si absente (MySQL 8 + MariaDB) ──
-- Gouvernance menu (logique b) : la catégorie arme, le produit surcharge.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_categories ADD COLUMN menu_default TINYINT NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_categories' AND column_name='menu_default');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_products ADD COLUMN menu_override VARCHAR(8) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_products' AND column_name='menu_override');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_products ADD COLUMN base_cost DECIMAL(10,2) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_products' AND column_name='base_cost');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_products ADD COLUMN brand_mandatory TINYINT NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_products' AND column_name='brand_mandatory');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_products ADD COLUMN brand_whitelist TINYINT NOT NULL DEFAULT 1','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_products' AND column_name='brand_whitelist');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Gouvernance boutique (contrat + toggle Webshop par boutique).
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_shops ADD COLUMN contrat VARCHAR(16) NOT NULL DEFAULT ''Franchise''','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_shops' AND column_name='contrat');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_shops ADD COLUMN webshop_enabled TINYINT NOT NULL DEFAULT 1','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_shops' AND column_name='webshop_enabled');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Formules : « sélection jusqu'à N » + food-cost par choix.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_bundle_slots ADD COLUMN kind VARCHAR(8) NOT NULL DEFAULT ''single''','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_bundle_slots' AND column_name='kind');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_bundle_slots ADD COLUMN min_select INT NOT NULL DEFAULT 1','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_bundle_slots' AND column_name='min_select');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_bundle_slots ADD COLUMN max_select INT NOT NULL DEFAULT 1','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_bundle_slots' AND column_name='max_select');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_bundle_slot_choices ADD COLUMN cost DECIMAL(10,2) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_bundle_slot_choices' AND column_name='cost');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── Modèles d'email transactionnels (clé × langue × marque) ──
CREATE TABLE IF NOT EXISTS ws_email_templates (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  cle        VARCHAR(64)  NOT NULL,
  langue     VARCHAR(5)   NOT NULL DEFAULT 'FR',
  sujet      VARCHAR(255) NOT NULL,
  corps      TEXT NULL,
  active     TINYINT NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tpl (cle, langue)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Comptes back-office & portée (RBAC) ──
CREATE TABLE IF NOT EXISTS bo_users (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  nom        VARCHAR(128) NOT NULL,
  email      VARCHAR(190) NOT NULL,
  role       VARCHAR(32)  NOT NULL DEFAULT 'Franchise',
  portee     VARCHAR(255) NOT NULL DEFAULT '',
  active     TINYINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Portée normalisée (RBAC fin) — l'UI affiche bo_users.portee en texte pour l'instant.
CREATE TABLE IF NOT EXISTS bo_user_shops (
  user_id INT NOT NULL,
  shop_id INT NOT NULL,
  PRIMARY KEY (user_id, shop_id),
  CONSTRAINT fk_bus_user FOREIGN KEY (user_id) REFERENCES bo_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Journal d'audit (toute écriture sensible) ──
CREATE TABLE IF NOT EXISTS bo_audit (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  ts       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor    VARCHAR(128) NOT NULL,
  verb     VARCHAR(32)  NOT NULL,
  entity   VARCHAR(255) NOT NULL,
  shop     VARCHAR(128) NOT NULL DEFAULT 'Réseau'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Seeds (démo réseau) — gardés : ne réécrasent jamais des lignes existantes ──
INSERT INTO ws_email_templates (cle, langue, sujet) VALUES
  ('order_confirm',    'FR', 'Votre commande {{commande_ref}} est confirmée'),
  ('order_ready',      'FR', 'Votre commande est prête'),
  ('invoice',          'FR', 'Facture {{commande_ref}}'),
  ('office_onboarding','FR', 'Bienvenue — votre compte {{bureau}}'),
  ('office_reject',    'FR', 'Votre demande de rattachement')
ON DUPLICATE KEY UPDATE cle = cle;

INSERT INTO bo_users (nom, email, role, portee, active) VALUES
  ('Sophie Renard',  'sophie.renard@latelierby.be', 'Siège',     'Réseau complet',      1),
  ('Thomas Legrand', 'thomas.legrand@latelierby.be','Franchise', 'Bruxelles-Centre',    1),
  ('Marek Kowalski', 'm.kowalski@latelierby.be',    'Franchise', 'Anderlecht, Uccle',   1),
  ('Julie Peeters',  'j.peeters@latelierby.be',     'Franchise', 'Louvain',             0)
ON DUPLICATE KEY UPDATE email = email;

-- Audit : seed seulement si la table est vide (pas de clé naturelle).
INSERT INTO bo_audit (ts, actor, verb, entity, shop)
SELECT * FROM (
  SELECT '2026-07-17 14:22:00', 'Sophie Renard',  'Modification', 'ws_products #128 (brand_mandatory)', 'Réseau' UNION ALL
  SELECT '2026-07-17 13:05:00', 'Thomas Legrand', 'Création',     'ws_vouchers BXL10',                 'Bruxelles-Centre' UNION ALL
  SELECT '2026-07-17 11:40:00', 'Sophie Renard',  'Modification', 'ws_param webshop.enabled',          'Réseau' UNION ALL
  SELECT '2026-07-16 18:12:00', 'Marek Kowalski', 'Suppression',  'ws_office_delivery_sites #44',      'Anderlecht' UNION ALL
  SELECT '2026-07-16 09:30:00', 'Sophie Renard',  'Création',     'bo_users j.peeters',                'Louvain'
) seed
WHERE NOT EXISTS (SELECT 1 FROM bo_audit);
