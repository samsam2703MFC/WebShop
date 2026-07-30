-- 0048 — Rattachement bureau : on s'adapte aux colonnes DÉJÀ présentes.
--
-- 0047 a ajouté office_id et decided_at à ws_office_join_requests sans voir que
-- la table portait déjà resolved_office_id, resolved_at, resolved_by et
-- reject_reason : deux colonnes pour la même information, donc deux sources de
-- vérité. On retire les DEUX colonnes ajoutées par 0047 (jamais renseignées,
-- aucune lecture ailleurs) et l'API écrit désormais les colonnes historiques.
--
-- contact_email / contact_phone, elles, n'existaient pas : elles restent.
-- Idempotent : le DROP ne s'exécute que si la colonne est encore là.

SET @s := (SELECT IF(COUNT(*)=1,
  'ALTER TABLE ws_office_join_requests DROP COLUMN office_id','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_office_join_requests' AND column_name='office_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=1,
  'ALTER TABLE ws_office_join_requests DROP COLUMN decided_at','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_office_join_requests' AND column_name='decided_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Filet pour un environnement neuf créé par le CREATE TABLE de 0047 (qui ne
-- posait pas les colonnes historiques) : on les ajoute si elles manquent.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_office_join_requests ADD COLUMN resolved_office_id INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_office_join_requests' AND column_name='resolved_office_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_office_join_requests ADD COLUMN resolved_at TIMESTAMP NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_office_join_requests' AND column_name='resolved_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_office_join_requests ADD COLUMN reject_reason VARCHAR(200) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_office_join_requests' AND column_name='reject_reason');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
