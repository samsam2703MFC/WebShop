-- ROLLBACK 0006 — supprime UNIQUEMENT les 2 bons migrés (BIENVENUE10, LIVRAISONOFF)
-- et leur chaîne (channel, code, campaign, order_discount, promotion). Transactionnel.
-- Ne touche à aucune autre promotion/campagne. Aucune redemption n'avait été créée.
START TRANSACTION;

-- Résoudre les ids AVANT toute suppression.
SET @vc1 := (SELECT vc.id FROM voucher_campaign vc
             JOIN voucher_code vco ON vco.id_voucher_campaign=vc.id WHERE vco.code='BIENVENUE10' LIMIT 1);
SET @vc2 := (SELECT vc.id FROM voucher_campaign vc
             JOIN voucher_code vco ON vco.id_voucher_campaign=vc.id WHERE vco.code='LIVRAISONOFF' LIMIT 1);
SET @p1  := (SELECT id_promotion FROM voucher_campaign WHERE id=@vc1);
SET @p2  := (SELECT id_promotion FROM voucher_campaign WHERE id=@vc2);

DELETE FROM voucher_campaign_channel WHERE id_voucher_campaign IN (@vc1, @vc2);
DELETE FROM voucher_code             WHERE id_voucher_campaign IN (@vc1, @vc2);
DELETE FROM voucher_campaign         WHERE id IN (@vc1, @vc2);
-- promotion_order_discount part en CASCADE avec promotion, mais on est explicite :
DELETE FROM promotion_order_discount WHERE id_promotion IN (@p1, @p2);
DELETE FROM promotion                WHERE id IN (@p1, @p2);

COMMIT;
