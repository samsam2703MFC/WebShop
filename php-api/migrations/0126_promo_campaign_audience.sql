-- 0126 — CLIENTÈLE CIBLE D'UNE CAMPAGNE. Le CRM de prospection ne travaille
-- que sur les campagnes B2B : sans cette colonne, le tableau proposait aussi
-- les campagnes grand public, qui n'ont rien à faire dans une prospection de
-- bureaux. NULL = cible non précisée (campagnes créées avant cette règle).
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE ws_promo_campaign ADD COLUMN audience VARCHAR(10) NULL','DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ws_promo_campaign' AND column_name='audience');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
