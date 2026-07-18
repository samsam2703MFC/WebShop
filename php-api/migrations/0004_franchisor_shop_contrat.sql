-- 0004 — Console marque (franchisor) : contrat par boutique (donnée franchisor).
-- Ajoute `contrat` sur la table unifiée `shops` (Franchise/Succursale/Master),
-- éditable via l'écriture boutique du back-office. Additif, MySQL 8, idempotent.

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE shops ADD COLUMN contrat VARCHAR(16) NULL DEFAULT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='shops' AND column_name='contrat');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
