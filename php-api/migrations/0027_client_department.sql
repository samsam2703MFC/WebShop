-- 0027 — client.department_id : rattachement FACULTATIF d'un client à un
-- département de son office (b2b_client_company_department.id). Posé par le
-- BO franchisé (menu Clients → Rattacher à un office + département).
-- Idempotent MySQL 8 (même patron que 0020).
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE client ADD COLUMN department_id INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='client' AND column_name='department_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
