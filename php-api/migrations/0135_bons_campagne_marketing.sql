-- 0135 — Bons : la CAMPAGNE MARKETING qui les suit.
-- Le module marketing crée des bons pour ses campagnes et en rattache
-- d'existants. Il ne garde aucune table de son côté : le lien vit sur le bon,
-- ici, et il le lit et l'écrit par l'API du webshop (/franchisor/*).
--   mar_campaign_id     : identifiant de la campagne (mar_campaign.id) ; NULL = aucune
--   mar_campaign_origin : 'created' (créé depuis la campagne) | 'attached' (bon existant rattaché)
-- Une colonne, pas une table de liaison : un bon ne suit qu'UNE campagne, et
-- ses commandes ne comptent qu'une fois.
-- Règle réseau : colonnes AJOUTÉES aux tables ERP, jamais retirées. Pas de clé
-- étrangère : mar_campaign peut vivre dans une autre base.
-- Gardée sur l'existence de la table (le socle de test ne l'a pas) ;
-- idempotente (information_schema + PREPARE, même motif que 0009/0041).
SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'voucher_campaign');

SET @s := (SELECT IF(@t = 1 AND COUNT(*) = 0,
  'ALTER TABLE voucher_campaign ADD COLUMN mar_campaign_id INT NULL', 'DO 0')
  FROM information_schema.columns WHERE table_schema = DATABASE()
   AND table_name = 'voucher_campaign' AND column_name = 'mar_campaign_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(@t = 1 AND COUNT(*) = 0,
  'ALTER TABLE voucher_campaign ADD COLUMN mar_campaign_origin VARCHAR(10) NULL', 'DO 0')
  FROM information_schema.columns WHERE table_schema = DATABASE()
   AND table_name = 'voucher_campaign' AND column_name = 'mar_campaign_origin');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(@t = 1 AND COUNT(*) = 0,
  'ALTER TABLE voucher_campaign ADD KEY idx_vc_mar_campaign (mar_campaign_id)', 'DO 0')
  FROM information_schema.statistics WHERE table_schema = DATABASE()
   AND table_name = 'voucher_campaign' AND index_name = 'idx_vc_mar_campaign');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
