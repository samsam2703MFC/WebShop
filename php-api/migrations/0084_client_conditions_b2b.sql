-- 0084 — Conditions commerciales B2B sur la fiche client.
--
-- Constat d'audit avant go-live : l'onboarding bureau et l'édition d'un
-- client B2B collectent segment, conditions de paiement, plafond d'encours,
-- remise webshop et franco de port — puis les JETAIENT en silence (aucune
-- colonne), pendant que la lecture servait « horeca » / « 30 j fin de mois »
-- codés en dur. Ces cinq colonnes leur donnent enfin une place réelle sur la
-- fiche `client` (qui porte déjà company_name, tax_number, is_b2b).
--
-- Toutes NULLABLES : « non renseigné » est un état licite (règle « aucune
-- valeur inventée » — l'écran affiche « — » quand c'est NULL).
--
-- IDEMPOTENTE : chaque ADD ne s'exécute que si la colonne manque.

SET @add := '';
SET @add := (SELECT IF(COUNT(*)=0,
  "ALTER TABLE client ADD COLUMN b2b_segment VARCHAR(32) NULL COMMENT 'segment commercial B2B'", 'DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='client' AND column_name='b2b_segment');
PREPARE st FROM @add; EXECUTE st; DEALLOCATE PREPARE st;

SET @add := (SELECT IF(COUNT(*)=0,
  "ALTER TABLE client ADD COLUMN b2b_payment_terms VARCHAR(64) NULL COMMENT 'conditions de paiement B2B'", 'DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='client' AND column_name='b2b_payment_terms');
PREPARE st FROM @add; EXECUTE st; DEALLOCATE PREPARE st;

SET @add := (SELECT IF(COUNT(*)=0,
  "ALTER TABLE client ADD COLUMN b2b_credit_ceiling DECIMAL(12,2) NULL COMMENT 'plafond d''encours B2B (€)'", 'DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='client' AND column_name='b2b_credit_ceiling');
PREPARE st FROM @add; EXECUTE st; DEALLOCATE PREPARE st;

SET @add := (SELECT IF(COUNT(*)=0,
  "ALTER TABLE client ADD COLUMN b2b_web_discount DECIMAL(5,2) NULL COMMENT 'remise webshop B2B (%)'", 'DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='client' AND column_name='b2b_web_discount');
PREPARE st FROM @add; EXECUTE st; DEALLOCATE PREPARE st;

SET @add := (SELECT IF(COUNT(*)=0,
  "ALTER TABLE client ADD COLUMN b2b_franco VARCHAR(40) NULL COMMENT 'franco de port B2B (texte libre, ex. « 250 € »)'", 'DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='client' AND column_name='b2b_franco');
PREPARE st FROM @add; EXECUTE st; DEALLOCATE PREPARE st;
