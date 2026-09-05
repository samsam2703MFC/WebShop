-- 0127 — HEURE DE LA PROCHAINE ACTION. La carte ne portait qu'une date : un
-- agenda de semaine sans heure range tout au même endroit. NULL = pas d'heure
-- fixée, l'action se pose alors en tête de journée et le dit.
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_crm_carte ADD COLUMN prochaine_heure TIME NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_crm_carte' AND column_name='prochaine_heure');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
-- Le TYPE de l'action attendue : visite, appel, envoi… Sans lui, l'agenda ne
-- peut pas colorer ni dire ce qui est à faire.
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_crm_carte ADD COLUMN prochaine_type VARCHAR(16) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_crm_carte' AND column_name='prochaine_type');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
