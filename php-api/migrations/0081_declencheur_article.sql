-- 0081 — Un déclencheur peut se restreindre à UN article.
--
-- Demandé le 14/08 : une sous-catégorie cochée à l'étape 2 doit permettre de
-- choisir UN de ses articles comme déclencheur — le menu ne s'ouvre alors que
-- sur cet article, pas sur toute la sous-catégorie. Avec plusieurs coches, le
-- choix d'article est impossible (l'écran le grise).
--
-- article_id NULL = le déclencheur couvre son périmètre entier (comportement
-- 0078 inchangé). Renseigné = seulement cet article ; cat_id/sub_cat_id
-- restent posés — ils disent d'où l'article vient et bornent la validation.
-- À la résolution, l'article prime sur la sous-catégorie qui prime sur la
-- catégorie.
--
-- IDEMPOTENTE : garde information_schema + PREPARE.

SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_bundle_triggers');
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_bundle_triggers' AND column_name = 'article_id');
SET @s := IF(@t = 0 OR @c > 0, 'DO 0',
  "ALTER TABLE ws_bundle_triggers
     ADD COLUMN article_id INT NULL
     COMMENT 'NULL = tout le perimetre du declencheur ; renseigne = seulement cet article'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
