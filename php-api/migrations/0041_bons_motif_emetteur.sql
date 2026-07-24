-- 0041 — Bons unifiés : MOTIF et TRAÇABILITÉ de l'émetteur sur voucher_campaign.
-- Règle réseau : on peut AJOUTER des colonnes aux tables ERP, jamais en retirer.
--   reason_kind : 'RECLAMATION' | 'GESTE_CO' | 'FIDELITE' | 'MARKETING' (libre, non contraint)
--   reason_note : commentaire libre de l'émetteur (franchisé ou marque)
--   created_by  : qui a émis le bon ('franchisor' / 'shop:2' / email BO…)
-- L'émetteur lui-même reste porté par id_shop (NULL = marque, sinon boutique).
-- Idempotent MySQL 8 (information_schema + PREPARE, même motif que la 0009).
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE voucher_campaign ADD COLUMN reason_kind VARCHAR(24) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND column_name='reason_kind');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE voucher_campaign ADD COLUMN reason_note VARCHAR(255) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND column_name='reason_note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE voucher_campaign ADD COLUMN created_by VARCHAR(64) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='voucher_campaign' AND column_name='created_by');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
