-- 0015 — Collecte du code postal client, partout.
-- La localité confirmée à la saisie (référentiel /geo/postcodes) est stockée
-- avec le code postal : `client.zip` existe déjà, on ajoute `client.locality`.
-- Le formulaire d'inscription rend le CP obligatoire et la modal de rattrapage
-- post-login remplit les comptes existants — la colonne reste NULL tant que le
-- client n'a pas (re)saisi son code postal.
-- Idempotent MySQL 8.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE client ADD COLUMN locality VARCHAR(120) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='client' AND column_name='locality');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
