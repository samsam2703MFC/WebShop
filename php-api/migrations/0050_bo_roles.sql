-- 0050 — Profils tablette : la marque définit, la boutique attribue.
--
-- Répartition des responsabilités :
--   • FRANCHISEUR : définit les PROFILS (« Vendeur », « Préparateur », …) et,
--     pour chacun, les sections du back-office auxquelles il donne accès.
--   • FRANCHISÉ  : crée SES comptes tablette (nom + PIN) et leur attribue un
--     profil parmi ceux publiés par la marque. Il ne choisit pas les sections :
--     elles découlent du profil, donc un franchisé ne peut pas s'octroyer un
--     accès que la marque n'a pas prévu.
--
-- Avant, les comptes ET les sections se réglaient dans la console marque : la
-- marque devait connaître le personnel de chaque boutique.
--
-- Additif : bo_users.sections reste en place (comptes déjà créés) et sert de
-- repli tant qu'un compte n'a pas de profil.

CREATE TABLE IF NOT EXISTS bo_role (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  role_key   VARCHAR(32)  NOT NULL,
  label      VARCHAR(64)  NOT NULL,
  sections   TEXT         NULL,
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bo_role_key (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Profil attribué au compte tablette. NULL = compte antérieur au modèle par
-- profils : ses sections propres continuent de faire foi.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE bo_users ADD COLUMN role_id INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='bo_users' AND column_name='role_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE bo_users ADD KEY idx_bo_users_role (role_id)','DO 0')
  FROM information_schema.statistics WHERE table_schema=DATABASE()
   AND table_name='bo_users' AND index_name='idx_bo_users_role');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Aucun profil n'est créé ici : la console marque propose une action explicite
-- « Créer les profils standard ». Rien n'apparaît sans une décision humaine.
