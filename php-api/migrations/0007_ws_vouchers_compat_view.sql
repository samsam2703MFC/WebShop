-- 0007 — Vue de compatibilité ws_vouchers (Étape 3).
--
-- Renomme la table plate ws_vouchers en ws_vouchers_legacy (filet de sécurité,
-- JAMAIS supprimé), puis crée une VUE ws_vouchers qui reprojette le modèle unifié
-- dans les anciennes colonnes, filtrée sur le canal WS. Objectif : le code webshop
-- actuel continue de tourner EN LECTURE pendant la transition (Étape 4).
--
-- LIMITES (documentées) :
--   • Une vue n'est pas incrémentable en écriture : l'UPDATE ... SET used_count=used_count+1
--     et l'INSERT (POST /franchisor/voucher) du code actuel ÉCHOUERONT sur la vue.
--     L'incrément d'usage doit passer par une redemption ERP (voucher_redemption) et
--     l'upsert par voucher_campaign/voucher_code (cf. plan Étape 4). Tant que le code
--     n'est pas migré, garder ces écritures pointées sur ws_vouchers_legacy si besoin.
--   • La vue n'expose QUE les bons « niveau commande » (promotion_order_discount) sur
--     le canal WS. Les bons à mécanique produit (BUY_X_GET_Y, bundle, …) n'apparaissent
--     pas — c'est voulu : le webshop ne les a jamais compris.
--
-- Idempotent : le rename n'a lieu que si ws_vouchers est encore une BASE TABLE et que
-- ws_vouchers_legacy n'existe pas ; la vue est (re)créée avec CREATE OR REPLACE.

-- ── Rename table plate -> _legacy (garde idempotente) ──
SET @is_base := (SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema=DATABASE() AND table_name='ws_vouchers' AND table_type='BASE TABLE');
SET @legacy_exists := (SELECT COUNT(*) FROM information_schema.tables
                        WHERE table_schema=DATABASE() AND table_name='ws_vouchers_legacy');
SET @s := IF(@is_base=1 AND @legacy_exists=0,
  'RENAME TABLE ws_vouchers TO ws_vouchers_legacy', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── Vue de compat : modèle unifié -> colonnes legacy, canal WS uniquement ──
CREATE OR REPLACE VIEW ws_vouchers AS
SELECT
  vco.id                                   AS id,
  vco.code                                 AS code,
  vc.id_shop                               AS shop_id,     -- NULL = réseau
  vc.id_brand                              AS id_brand,
  CASE pod.discount_kind
    WHEN 'PERCENT'       THEN 'percent'
    WHEN 'FIXED'         THEN 'fixed'
    WHEN 'FREE_DELIVERY' THEN 'free_delivery'
    ELSE pod.discount_kind
  END                                      AS type,
  pod.discount_value                       AS value,
  pod.min_order_amount                     AS min_order,
  vco.usage_limit                          AS max_uses,    -- NULL = illimité
  vco.usage_count                          AS used_count,
  vco.valid_to                             AS expires_at,
  CASE WHEN p.status='ACTIVE' AND vco.status='ACTIVE' THEN 1 ELSE 0 END AS active
FROM voucher_code vco
JOIN voucher_campaign vc            ON vc.id  = vco.id_voucher_campaign
JOIN voucher_campaign_channel vcc   ON vcc.id_voucher_campaign = vc.id AND vcc.channel = 'WS'
JOIN promotion p                    ON p.id   = vc.id_promotion
JOIN promotion_order_discount pod   ON pod.id_promotion = p.id;
