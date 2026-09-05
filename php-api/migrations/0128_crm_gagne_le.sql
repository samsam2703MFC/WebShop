-- 0128 — DATE DE GAIN D'UNE CARTE. Le rapport de campagne mesure ce que le
-- client a commandé DEPUIS qu'il est gagné : sans cette date, on compterait
-- aussi ce qu'il achetait avant la campagne, et la campagne s'attribuerait un
-- chiffre qui ne lui doit rien.
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_crm_carte ADD COLUMN gagne_le DATETIME NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_crm_carte' AND column_name='gagne_le');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
