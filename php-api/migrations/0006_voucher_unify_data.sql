-- 0006 — Migration des 2 bons ws_vouchers vers le modèle ERP (Étape 2).
--
-- Transactionnel + idempotent : la clé naturelle est voucher_code.code. Si le code
-- existe déjà (bon déjà migré), TOUS les inserts deviennent des no-op (WHERE @exists=0).
-- En cas d'échec en cours de bloc, le COMMIT n'est jamais atteint → ROLLBACK complet
-- (jamais d'état partiel). Aucune ligne voucher_redemption fabriquée : l'historique
-- d'usage n'est pas reconstructible depuis ws_vouchers (simple compteur used_count) ;
-- on reporte used_count dans voucher_code.usage_count, c'est tout.
--
-- Données source (ws_vouchers) :
--   BIENVENUE10  : percent 10, min_order 20, réseau (shop_id NULL), id_brand 1,
--                  max_uses NULL, used_count 1, expire 2026-12-31 23:59:59, canal WS.
--   LIVRAISONOFF : free_delivery, min_order 30, réseau, id_brand 1,
--                  max_uses/usage_limit_total 100, used_count 0, expire 2026-09-30, canal WS.
--
-- Chaîne créée par bon : promotion → promotion_order_discount → voucher_campaign (SHARED)
--                        → voucher_code → voucher_campaign_channel('WS').

START TRANSACTION;

-- ═══════════════════════ BIENVENUE10 ═══════════════════════
SET @exists := (SELECT COUNT(*) FROM voucher_code WHERE code='BIENVENUE10');

INSERT INTO promotion
  (name, description, promotion_type, status, priority, is_exclusive,
   valid_from, valid_to, is_repeatable, shop_scope_type, activation_mode, soft_delete)
SELECT 'Webshop — BIENVENUE10', 'Import ws_vouchers (canal WS)', 'ORDER_DISCOUNT', 'ACTIVE', 0, 0,
       NULL, '2026-12-31 23:59:59', 0, 'ALL_SHOPS', 'VOUCHER_ONLY', 0
FROM DUAL WHERE @exists=0;
SET @pid := IF(@exists=0, LAST_INSERT_ID(), NULL);

INSERT INTO promotion_order_discount (id_promotion, discount_kind, discount_value, min_order_amount)
SELECT @pid, 'PERCENT', 10.00, 20.00 FROM DUAL WHERE @exists=0;

INSERT INTO voucher_campaign
  (id_promotion, name, description, planned_promotion_type, status, code_type,
   valid_from, valid_to, usage_limit_total, usage_limit_per_code, usage_limit_per_customer,
   requires_customer, id_brand, id_shop)
SELECT @pid, 'Webshop — BIENVENUE10', 'Import ws_vouchers (canal WS)', 'ORDER_DISCOUNT', 'ACTIVE', 'SHARED',
       NULL, '2026-12-31 23:59:59', NULL, NULL, NULL,
       0, 1, NULL
FROM DUAL WHERE @exists=0;
SET @cid := IF(@exists=0, LAST_INSERT_ID(), NULL);

INSERT INTO voucher_code
  (id_voucher_campaign, code, status, id_customer, valid_from, valid_to, usage_limit, usage_count)
SELECT @cid, 'BIENVENUE10', 'ACTIVE', NULL, NULL, '2026-12-31 23:59:59', NULL, 1
FROM DUAL WHERE @exists=0;

INSERT INTO voucher_campaign_channel (id_voucher_campaign, channel)
SELECT @cid, 'WS' FROM DUAL WHERE @exists=0;

-- ═══════════════════════ LIVRAISONOFF ═══════════════════════
SET @exists := (SELECT COUNT(*) FROM voucher_code WHERE code='LIVRAISONOFF');

INSERT INTO promotion
  (name, description, promotion_type, status, priority, is_exclusive,
   valid_from, valid_to, is_repeatable, shop_scope_type, activation_mode, soft_delete)
SELECT 'Webshop — LIVRAISONOFF', 'Import ws_vouchers (canal WS)', 'ORDER_DISCOUNT', 'ACTIVE', 0, 0,
       NULL, '2026-09-30 23:59:59', 0, 'ALL_SHOPS', 'VOUCHER_ONLY', 0
FROM DUAL WHERE @exists=0;
SET @pid := IF(@exists=0, LAST_INSERT_ID(), NULL);

INSERT INTO promotion_order_discount (id_promotion, discount_kind, discount_value, min_order_amount)
SELECT @pid, 'FREE_DELIVERY', NULL, 30.00 FROM DUAL WHERE @exists=0;

INSERT INTO voucher_campaign
  (id_promotion, name, description, planned_promotion_type, status, code_type,
   valid_from, valid_to, usage_limit_total, usage_limit_per_code, usage_limit_per_customer,
   requires_customer, id_brand, id_shop)
SELECT @pid, 'Webshop — LIVRAISONOFF', 'Import ws_vouchers (canal WS)', 'ORDER_DISCOUNT', 'ACTIVE', 'SHARED',
       NULL, '2026-09-30 23:59:59', 100, NULL, NULL,
       0, 1, NULL
FROM DUAL WHERE @exists=0;
SET @cid := IF(@exists=0, LAST_INSERT_ID(), NULL);

INSERT INTO voucher_code
  (id_voucher_campaign, code, status, id_customer, valid_from, valid_to, usage_limit, usage_count)
SELECT @cid, 'LIVRAISONOFF', 'ACTIVE', NULL, NULL, '2026-09-30 23:59:59', 100, 0
FROM DUAL WHERE @exists=0;

INSERT INTO voucher_campaign_channel (id_voucher_campaign, channel)
SELECT @cid, 'WS' FROM DUAL WHERE @exists=0;

COMMIT;
