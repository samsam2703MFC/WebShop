-- 0134 — Bons : DATE DE DÉBUT.
-- Un bon créé pour une campagne du module marketing vaut sur les dates de la
-- campagne. Le modèle ERP les porte déjà (voucher_code.valid_from), mais la vue
-- ws_vouchers — celle que lisent l'aperçu du panier et la commande — ne
-- l'exposait pas : un bon créé en avance était utilisable dès sa création.
-- La vue est reprojetée À L'IDENTIQUE, plus une colonne en fin de liste,
-- starts_at (NULL = valable tout de suite, comme avant).
--
-- Gardée : le socle de test n'a pas les tables de bons de l'ERP (elles ne
-- viennent d'aucune migration du webshop). Sans elles, rien à reprojeter — la
-- vue existante reste telle quelle. Idempotent (CREATE OR REPLACE).
SET @ok := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'voucher_code' AND column_name = 'valid_from');
SET @ok := @ok * (SELECT COUNT(*) FROM information_schema.tables
                   WHERE table_schema = DATABASE()
                     AND table_name IN ('voucher_campaign', 'voucher_campaign_channel', 'promotion', 'promotion_order_discount')) ;
SET @s := IF(@ok = 4,
  'CREATE OR REPLACE VIEW ws_vouchers AS
   SELECT
     vco.id                                   AS id,
     vco.code                                 AS code,
     vc.id_shop                               AS shop_id,
     vc.id_brand                              AS id_brand,
     CASE pod.discount_kind
       WHEN ''PERCENT''       THEN ''percent''
       WHEN ''FIXED''         THEN ''fixed''
       WHEN ''FREE_DELIVERY'' THEN ''free_delivery''
       ELSE pod.discount_kind
     END                                      AS type,
     pod.discount_value                       AS value,
     pod.min_order_amount                     AS min_order,
     vco.usage_limit                          AS max_uses,
     vco.usage_count                          AS used_count,
     vco.valid_to                             AS expires_at,
     CASE WHEN p.status=''ACTIVE'' AND vco.status=''ACTIVE'' THEN 1 ELSE 0 END AS active,
     vco.valid_from                           AS starts_at
   FROM voucher_code vco
   JOIN voucher_campaign vc            ON vc.id  = vco.id_voucher_campaign
   JOIN voucher_campaign_channel vcc   ON vcc.id_voucher_campaign = vc.id AND vcc.channel = ''WS''
   JOIN promotion p                    ON p.id   = vc.id_promotion
   JOIN promotion_order_discount pod   ON pod.id_promotion = p.id',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
