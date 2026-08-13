-- 0064 — Le temps d'accès à un site peut être ABSENT, et la base doit pouvoir
-- le dire.
--
-- Ce temps entre dans chaque heure d'arrivée annoncée : heure de départ de la
-- tournée + trajet + temps d'accès de chaque site, cumulés. Tant que la colonne
-- était NOT NULL, tout site sans mesure recevait une valeur inventée — 6, 5 ou
-- 10 minutes selon l'endroit du code qui l'écrivait — et la tournée publiait
-- des heures fausses au lieu de ne rien publier.
--
-- Le moteur d'ETA sait déjà s'arrêter : sans temps d'accès, la tournée n'a pas
-- d'heure d'arrivée du tout (« une heure fausse est pire qu'une heure
-- absente »). Encore fallait-il que l'absence puisse EXISTER en base.
--
-- LES LIGNES DÉJÀ ÉCRITES NE SONT PAS TOUCHÉES. Un 6 déjà enregistré est
-- peut-être une mesure réelle : rien ne permet de le distinguer d'un défaut, et
-- effacer une mesure vraie serait pire que garder un doute. Le contrôle est
-- humain — la sonde check-endpoints liste les sites et leur temps d'accès.

SET @t := (SELECT COLUMN_TYPE FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_office_delivery_sites'
              AND column_name = 'site_access_minutes');

-- Le type est CONSERVÉ tel quel (INT, DECIMAL…) : seule la nullabilité change.
SET @s := IF(@t IS NULL, 'DO 0',
             CONCAT('ALTER TABLE ws_office_delivery_sites MODIFY site_access_minutes ', @t, ' NULL'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
