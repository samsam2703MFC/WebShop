-- 0062 — Lien magique « Créer mon compte » : la table des invitations.
--
-- Le bureau reçoit UN lien et le transfère à tout son personnel. Chaque
-- collaborateur arrive sur la bonne boutique, avec son bureau, son site et son
-- département déjà rattachés : il ne saisit que son identité.
--
-- POURQUOI UNE TABLE, alors que le jeton est signé et se suffirait ?
-- Un jeton signé ne se rappelle pas. Trois besoins l'exigent :
--   • RÉVOQUER — un lien transféré finit par circuler hors de l'entreprise ;
--     sans liste de révocation, il reste valable jusqu'à son expiration ;
--   • COMPTER — la console doit dire combien de personnes s'en sont servies ;
--   • TRACER — qui l'a émis, quand, pour quel bureau.
-- La signature reste l'autorité sur le CONTENU (personne ne peut changer
-- office=17 en office=18) ; la table est l'autorité sur la VALIDITÉ.
--
-- Le jeton est MULTI-USAGE : c'est une invitation d'entreprise, pas un lien
-- personnel. Quarante collaborateurs doivent pouvoir s'en servir.

CREATE TABLE IF NOT EXISTS ws_office_invites (
  jti          VARCHAR(64)  NOT NULL PRIMARY KEY COMMENT 'Identifiant du jeton — porte la revocation',
  shop_id      INT          NOT NULL             COMMENT 'Boutique emettrice (facture et livre)',
  office_id    INT          NULL                 COMMENT 'ws_offices.id — le bureau invite',
  client_code  VARCHAR(64)  NULL                 COMMENT 'Code societe ERP, si connu',
  site_id      INT          NULL                 COMMENT 'ws_office_delivery_sites.id — point de depot',
  domain       VARCHAR(190) NULL                 COMMENT 'Domaine e-mail impose (delhaizegroup.be)',
  depts        VARCHAR(190) NULL                 COMMENT 'Ids de departements proposes au choix, separes par des virgules',
  cp           VARCHAR(12)  NULL                 COMMENT 'Code postal pre-rempli',
  expires_at   DATETIME     NOT NULL             COMMENT 'Fin de validite — 30 jours par defaut',
  revoked_at   DATETIME     NULL                 COMMENT 'Revocation : le lien cesse aussitot de fonctionner',
  created_by   VARCHAR(190) NULL                 COMMENT 'Qui a emis le lien',
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  uses         INT          NOT NULL DEFAULT 0   COMMENT 'Inscriptions effectuees avec ce lien',
  last_use_at  DATETIME     NULL,
  KEY idx_invite_shop (shop_id),
  KEY idx_invite_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Colonnes ajoutées après coup (bases où 0062 est déjà passé) : le jeton n'est
-- PAS stocké, il est refabriqué depuis la ligne quand la console réaffiche le
-- lien. Il faut donc y retrouver TOUTE la charge signée — départements proposés
-- et code postal compris — sinon le lien reconstitué diffère de celui envoyé.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_office_invites ADD COLUMN depts VARCHAR(190) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_invites' AND column_name='depts');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_office_invites ADD COLUMN cp VARCHAR(12) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_office_invites' AND column_name='cp');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Rien n'est pré-rempli : aucune invitation n'existe tant qu'un franchisé n'en
-- a pas émis une depuis l'onboarding d'un bureau.
