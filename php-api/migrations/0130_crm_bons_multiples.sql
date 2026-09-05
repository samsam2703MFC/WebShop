-- 0130 — PLUSIEURS BONS PAR CIBLE DE PROSPECTION.
-- Un bureau reçoit rarement un seul bon : bienvenue, découverte d'une gamme,
-- geste commercial. La fiche bureau le permettait déjà (ws_vouchers attribués
-- au bureau) ; la carte de prospection, non — elle ne portait qu'un code de
-- 60 caractères. La colonne s'élargit et accueille la liste des codes,
-- séparés par des virgules ; l'API la sert et la reçoit comme un tableau.
SET @s := (SELECT IF(CHARACTER_MAXIMUM_LENGTH >= 255, 'DO 0',
                     'ALTER TABLE ws_crm_carte MODIFY voucher_code VARCHAR(255) NULL')
           FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='ws_crm_carte' AND column_name='voucher_code');
SET @s := IFNULL(@s, 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
