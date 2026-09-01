-- 0109 — Inventaire EXACT du reste du dump, pour décision externe.
-- Ne supprime rien, ne modifie rien.
--
-- 0108 s'appuyait sur information_schema.table_rows, qui est une ESTIMATION :
-- InnoDB peut annoncer 0 sur une table pleine, et c'est justement sur une
-- table annoncée vide qu'une suppression se déciderait à tort. Ici, COUNT(*)
-- sur chacune.
--
-- On relève aussi create_time et update_time : une table jamais écrite depuis
-- la copie initiale est morte, une table écrite hier est vivante. C'est le
-- meilleur discriminant disponible sans connaître le métier de chaque table,
-- et c'est ce qui permettra à quelqu'un qui le connaît de trancher vite.

SET SESSION group_concat_max_len = 1000000;
SET @nous := 'absent,accumulated_amount,active,auth_handoff,b2b_client_company_department,bo_audit,bo_pin_session,bo_role,bo_user_shops,bo_users,client,collect_enabled,current_timestamp,delivery_start,display_name,erp_client_id,event_id,franchisee_shop,global,halle,impressions,min_threshold,param_value,partiel,payload,product_portion,product_preparation_batch_group,product_preparation_path,product_preparation_step,promotion,promotion_order_discount,pwa_client_office,pwa_invoices,pwa_offices,pwa_purchase_items,pwa_purchases,pwa_review_items,pwa_reviews,qty,qty_sold,qty_total,qui,remet,restrict,shop_product_portion_price,shops,subject,sur,value,voucher_campaign,voucher_campaign_channel,voucher_code,voucher_redemption,where,ws_assortments,ws_b2b_event_requests,ws_bo_store,ws_bundle_slot_choices,ws_bundle_slots,ws_bundle_triggers,ws_bundles,ws_calendar_rules,ws_categories,ws_category_availability,ws_category_subs,ws_client_erp_map,ws_client_link_requests,ws_client_vouchers,ws_cross_sell_pause,ws_cross_sell_rule,ws_cross_sell_shop,ws_cross_sell_stat,ws_cross_sell_target,ws_cross_sell_trigger,ws_customers,ws_delivery_fee_rules,ws_delivery_zones,ws_email_templates,ws_franchisor_catchment,ws_i18n,ws_incidents,ws_office_delivery_settings,ws_office_delivery_sites,ws_office_emails,ws_office_invites,ws_office_join_requests,ws_offices,ws_order_lines,ws_orders,ws_param,ws_pricing_rules,ws_product_allergens,ws_product_availability,ws_product_prices,ws_product_shops,ws_product_stock,ws_product_stock_defaults,ws_products,ws_promo_campaign,ws_promo_progress,ws_promo_progress_order,ws_rate_limit,ws_review_guidelines,ws_season,ws_shop_availability,ws_shop_device_token,ws_shop_exceptions,ws_shop_hours,ws_shop_payment_options,ws_shops,ws_slot_capacity,ws_slots,ws_stock_reservation,ws_stripe_event,ws_tags,ws_tour_availability,ws_tour_closures,ws_tour_postcodes,ws_tour_tracking,ws_tour_zones,ws_tours,ws_vouchers,ws_vouchers_legacy,ws_zone_requests';

DROP TEMPORARY TABLE IF EXISTS zz_inv;
CREATE TEMPORARY TABLE zz_inv (
  nom VARCHAR(64) PRIMARY KEY, lignes BIGINT, ko BIGINT,
  retenue_par INT, retient INT, creee DATETIME NULL, ecrite DATETIME NULL
) ENGINE=MEMORY;

DROP PROCEDURE IF EXISTS ws_inventaire;
DELIMITER //
CREATE PROCEDURE ws_inventaire()
BEGIN
  DECLARE fini INT DEFAULT 0;
  DECLARE nm VARCHAR(64);
  DECLARE cur CURSOR FOR
    SELECT t.table_name FROM information_schema.tables t
     WHERE t.table_schema = DATABASE() AND t.table_type = 'BASE TABLE'
       AND NOT FIND_IN_SET(t.table_name, @nous);
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET fini = 1;
  OPEN cur;
  b: LOOP
    FETCH cur INTO nm;
    IF fini = 1 THEN LEAVE b; END IF;
    SET @s := CONCAT('INSERT INTO zz_inv (nom, lignes, ko, retenue_par, retient, creee, ecrite) ',
      'SELECT ', QUOTE(nm), ', (SELECT COUNT(*) FROM `', nm, '`), ',
      'ROUND((t.data_length + t.index_length)/1024), ',
      '(SELECT COUNT(*) FROM information_schema.key_column_usage k WHERE k.table_schema = DATABASE() AND k.referenced_table_name = ', QUOTE(nm), '), ',
      '(SELECT COUNT(*) FROM information_schema.key_column_usage k2 WHERE k2.table_schema = DATABASE() AND k2.table_name = ', QUOTE(nm), ' AND k2.referenced_table_name IS NOT NULL), ',
      't.create_time, t.update_time ',
      'FROM information_schema.tables t WHERE t.table_schema = DATABASE() AND t.table_name = ', QUOTE(nm));
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END LOOP;
  CLOSE cur;
END //
DELIMITER ;
CALL ws_inventaire();
DROP PROCEDURE ws_inventaire;

SELECT nom, lignes, ko, retenue_par, retient,
       DATE_FORMAT(creee,'%Y-%m-%d') AS creee,
       DATE_FORMAT(ecrite,'%Y-%m-%d %H:%i') AS derniere_ecriture
  FROM zz_inv ORDER BY lignes DESC, nom;

SELECT COUNT(*) AS tables, SUM(lignes) AS lignes_exactes,
       ROUND(SUM(ko)/1024,1) AS mo,
       SUM(lignes = 0) AS vides,
       SUM(retenue_par > 0) AS retenues_par_une_cle
  FROM zz_inv;
