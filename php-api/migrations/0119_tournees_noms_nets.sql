-- 0119 — NOMS DE TOURNÉE SANS ESPACES PARASITES.
--
-- Une tournée créée « Gembloux » (espace final) depuis le constructeur ne se
-- retrouvait plus quand un site lui était rattaché : le nom envoyé est nettoyé
-- (trim) alors que la colonne gardait l'espace, et la collation ne pardonne
-- pas — le rattachement était perdu sans erreur. Les lectures comparent
-- désormais TRIM(name) ; on nettoie aussi les noms en base.
UPDATE ws_tours SET name = TRIM(name) WHERE name <> TRIM(name);
