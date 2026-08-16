-- 0087 — Langue par boutique (demandé : « néerlandais par défaut pour Halle,
-- à paramétrer dans shops »).
--
--   default_lang : langue d'ouverture de la boutique. NULL = on laisse le défaut
--                  de l'app décider (choix explicite du client > profil >
--                  navigateur > app). Renseignée => la boutique l'emporte sur la
--                  détection navigateur (une boutique connaît sa clientèle).
--   languages    : langues offertes au sélecteur, CSV (« fr,nl »). NULL = toutes
--                  celles que l'app supporte.
--
-- Toutes NULLABLES : « non paramétré » est un état licite (règle « aucune valeur
-- inventée » — rien n'est forcé sauf ce que l'utilisateur demande : Halle=nl).
--
-- IDEMPOTENTE : chaque ADD est gardé par information_schema ; l'UPDATE Halle ne
-- s'exécute que si la table et la colonne existent, et ne touche que Halle.

SET @tbl := (SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema=DATABASE() AND table_name='shops');

SET @s := (SELECT IF(@tbl=0 OR COUNT(*)>0, 'DO 0',
  "ALTER TABLE shops ADD COLUMN default_lang VARCHAR(5) NULL COMMENT 'langue par défaut de la boutique (NULL = défaut app)'")
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='shops' AND column_name='default_lang');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(@tbl=0 OR COUNT(*)>0, 'DO 0',
  "ALTER TABLE shops ADD COLUMN languages VARCHAR(40) NULL COMMENT 'langues offertes, CSV ex. fr,nl (NULL = toutes celles de l''app)'")
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='shops' AND column_name='languages');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Halle → ouvre en néerlandais, langues fr+nl. Best-effort : si aucune boutique
-- Halle dans cette base, 0 ligne touchée (le réglage se fera dans l'admin).
SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE()
              AND table_name='shops' AND column_name='default_lang');
SET @s := IF(@has=0, 'DO 0',
  "UPDATE shops SET default_lang='nl', languages='fr,nl'
     WHERE (city LIKE 'Halle' OR name LIKE '%Halle%') AND default_lang IS NULL");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
