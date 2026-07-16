-- ============================================================================
-- alter-client-webshop-auth.sql  —  IDEMPOTENT
-- Colonnes que l'auth unifiée (register/login sur la table ERP `client`) utilise.
-- Sans elles, /auth/register et /auth/login renvoient 500.
--   password_hash          : bcrypt du mot de passe webshop (NULL = passwordless / OTP)
--   active                 : compte actif (login exige active=1) — défaut 1
--   preferred_auth_method  : 'email' | 'phone' (méthode choisie au toggle)
--   source_channel         : 'webshop' | 'pwa' (origine de création)
--   webshop_user / pwa_user: drapeaux d'usage par canal
-- ============================================================================
DELIMITER //
DROP PROCEDURE IF EXISTS _c_addcol//
CREATE PROCEDURE _c_addcol(IN col VARCHAR(64), IN ddl TEXT)
BEGIN
  IF (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'client' AND column_name = col) = 0 THEN
    SET @s = CONCAT('ALTER TABLE `client` ADD COLUMN ', ddl);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END//
DELIMITER ;
CALL _c_addcol('password_hash',         'password_hash VARCHAR(255) NULL');
CALL _c_addcol('active',                'active TINYINT(1) NOT NULL DEFAULT 1');
CALL _c_addcol('preferred_auth_method', "preferred_auth_method VARCHAR(10) NULL");
CALL _c_addcol('source_channel',        'source_channel VARCHAR(20) NULL');
CALL _c_addcol('webshop_user',          'webshop_user TINYINT(1) NOT NULL DEFAULT 0');
CALL _c_addcol('pwa_user',              'pwa_user TINYINT(1) NOT NULL DEFAULT 0');
CALL _c_addcol('office_id',             'office_id INT NULL');
CALL _c_addcol('preferred_shop_id',     'preferred_shop_id INT NULL');
DROP PROCEDURE IF EXISTS _c_addcol;

-- Vérif : SELECT id, name, email, phone, webshop_user, pwa_user, active FROM client LIMIT 5;
