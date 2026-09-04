-- 0121 — Intake bureau depuis le landing B2B (formulaire fusionné).
-- Le volet « Livraison bureau » du landing crée un ws_offices en attente
-- (POST /b2b/office-request) et porte trois infos absentes du schéma :
-- le département/service, l'effectif annoncé, et la provenance de la fiche
-- (source='landing', vs les fiches créées en back-office). L'endpoint garde
-- chaque colonne par col_exists : cette migration peut arriver avant ou après
-- le code sans rien casser. Idempotent MySQL 8.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_offices ADD COLUMN department VARCHAR(120) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_offices' AND column_name='department');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_offices ADD COLUMN headcount INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_offices' AND column_name='headcount');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_offices ADD COLUMN source VARCHAR(30) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_offices' AND column_name='source');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
