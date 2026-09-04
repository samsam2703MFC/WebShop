-- 0117 — PROFIL CLIENT LU DANS L'ERP (GET /clients/{id} avec le jeton du client).
-- Horodatage de la dernière synchronisation du profil, pour ne pas relire
-- l'ERP à chaque /auth/me (au plus une fois par minute).
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_erp_client_sessions ADD COLUMN profile_synced_at DATETIME NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_erp_client_sessions' AND column_name='profile_synced_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
