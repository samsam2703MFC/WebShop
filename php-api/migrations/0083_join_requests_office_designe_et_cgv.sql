-- 0083 — Rattachement bureau : le bureau DÉSIGNÉ par le jeton revient,
--        et le consentement CGV est enfin tracé.
--
-- 0048 a retiré office_id de ws_office_join_requests en la croyant « jamais
-- renseignée, aucune lecture ailleurs ». C'était faux des deux côtés :
--   • POST /inscription l'écrit (écriture gardée par col_exists — inerte
--     depuis 0048) : c'est le bureau EXACT porté par le lien magique signé ;
--   • la validation franchisé la LIT en priorité (« Bureau DÉSIGNÉ par la
--     demande avant la ressemblance de nom ») — sans elle, la liaison
--     retombe sur un LIKE du nom : dans les bâtiments multi-entreprises,
--     le client peut être rattaché au mauvais bureau.
-- Sémantique : office_id = bureau demandé (jeton) ; resolved_office_id =
-- bureau décidé à la validation. Deux informations, deux colonnes.
--
-- cgv_accepted_at : la case CGV d'inscription.html était exigée à l'écran
-- mais jamais transmise ni stockée — aucun moyen de prouver l'acceptation.
--
-- IDEMPOTENTE : chaque ADD ne s'exécute que si la TABLE existe ET que la colonne
-- manque. La garde de table (comme 0081 et 0085_ticket) laisse le rejeu tourner
-- sur un socle minimal qui ne porte pas ws_office_join_requests : sur la prod la
-- table est là, l'ADD s'exécute donc à l'identique ; sur une base sans elle, il
-- est simplement inerte au lieu d'échouer sur « table doesn't exist ».

SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema=DATABASE() AND table_name='ws_office_join_requests');

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE()
            AND table_name='ws_office_join_requests' AND column_name='office_id');
SET @s := IF(@t=0 OR @c>0, 'DO 0',
  "ALTER TABLE ws_office_join_requests ADD COLUMN office_id int(11) NULL
     COMMENT 'bureau DÉSIGNÉ par le jeton d''invitation (≠ resolved_office_id, décidé à la validation)'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE()
            AND table_name='ws_office_join_requests' AND column_name='cgv_accepted_at');
SET @s := IF(@t=0 OR @c>0, 'DO 0',
  "ALTER TABLE ws_office_join_requests ADD COLUMN cgv_accepted_at timestamp NULL
     COMMENT 'horodatage du consentement CGV coché à l''inscription'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
