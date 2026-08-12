-- 0020 — client.status : statut de validation du client B2B/bureau.
--   0 = validé (défaut — la majorité des clients existants sont déjà validés)
--   1 = à valider (prospects « livraison au bureau » encodés depuis la landing)
-- lp_office_lead.php (landing) écrit status=1 dès que la colonne existe ;
-- GET /franchisor/prospects l'affiche dans le menu Prospect.
-- Idempotent MySQL 8.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE client ADD COLUMN status TINYINT(1) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='client' AND column_name='status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
