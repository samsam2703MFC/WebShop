-- 0046 — Comptes tablette boutique : PIN 4 chiffres + droits par SECTION.
--
-- Besoin : un employé se connecte au back-office franchisé depuis une tablette
-- en boutique et n'accède QU'aux sections autorisées. Les comptes et les droits
-- sont créés dans la console franchiseur (structure identique partout) ; le
-- back-office franchisé ne fait que les consommer.
--
-- L'accès ADMIN complet reste le jeton ERP : un PIN ne donne jamais les droits
-- admin, seulement les sections cochées.
--
-- Additif et idempotent (information_schema + PREPARE, comme 0003).
-- bo_users/bo_user_shops préexistent (créées hors migrations) : on n'ajoute que
-- ce qui manque, on ne recrée rien.

-- PIN haché (password_hash) — jamais stocké en clair.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE bo_users ADD COLUMN pin_hash VARCHAR(255) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bo_users' AND column_name='pin_hash');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Date de pose du PIN (traçabilité des réinitialisations).
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE bo_users ADD COLUMN pin_set_at TIMESTAMP NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bo_users' AND column_name='pin_set_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Sections autorisées : tableau JSON des clés d'écran du BO franchisé
-- (ex. ["tdb","commandes","stockJour"]). NULL = aucune section (compte inerte).
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE bo_users ADD COLUMN sections TEXT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bo_users' AND column_name='sections');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Rôle prédéfini ayant servi à pré-cocher les sections (vendeur, preparateur,
-- chauffeur, gerant, admin_boutique). Purement informatif : ce sont les
-- sections qui font foi, car l'admin peut les ajuster case par case.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE bo_users ADD COLUMN role_preset VARCHAR(32) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bo_users' AND column_name='role_preset');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Un employé de comptoir n'a pas forcément d'email : on rend la colonne
-- NULLABLE. L'unicité est conservée pour les emails renseignés (MySQL autorise
-- plusieurs NULL dans un index UNIQUE).
SET @s := (SELECT IF(COUNT(*)=1,
  'ALTER TABLE bo_users MODIFY COLUMN email VARCHAR(200) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bo_users'
    AND column_name='email' AND is_nullable='NO');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Rattachement boutique (portée du compte). La table préexiste normalement ;
-- on la crée si elle manque, pour que la fonctionnalité ne dépende pas d'un
-- script hors migrations.
CREATE TABLE IF NOT EXISTS bo_user_shops (
  user_id INT NOT NULL,
  shop_id INT NOT NULL,
  PRIMARY KEY (user_id, shop_id),
  KEY idx_bus_shop (shop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sessions tablette : un jeton opaque par connexion PIN, expirable et
-- révocable. On ne réutilise PAS le jeton admin (qui, lui, donne tous les
-- droits) : une session tablette ne porte que les sections du compte.
CREATE TABLE IF NOT EXISTS bo_pin_session (
  token       CHAR(64)    NOT NULL,
  user_id     INT         NOT NULL,
  shop_id     INT         NOT NULL,
  created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME    NOT NULL,
  PRIMARY KEY (token),
  KEY idx_bps_user (user_id),
  KEY idx_bps_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
