-- 0115 — LOGO D'ENTREPRISE d'un bureau (ws_offices.logo_path).
--
-- Le franchisé dépose le logo du bureau (personne morale) depuis la fiche
-- bureau ou l'onboarding de la console. Le fichier vit sous
-- assets/office_logos/<id>.<ext> ; la colonne ne porte que son chemin
-- relatif. Il figure sur la couverture du dossier imprimé du bureau, à côté
-- du logo L'Atelier. NULL : pas de logo, la couverture n'en montre pas.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_offices ADD COLUMN logo_path VARCHAR(160) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_offices' AND column_name='logo_path');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
