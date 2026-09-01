-- 0108 — INVENTAIRE du reste du dump ERP. Ne supprime RIEN.
--
-- Avant de supprimer « le reste du dump », il faut la LISTE. On ne supprime
-- pas des tables qu'on n'a pas regardées : c'est la règle que 0102 a apprise
-- ce matin à ses dépens.
--
-- MÉTHODE. La liste ci-dessous est celle des tables que ce dépôt CRÉE, LIT,
-- ÉCRIT ou CITE — extraite mécaniquement des migrations et du code PHP. Elle
-- contient volontairement quelques faux positifs (des noms de colonnes
-- attrapés par l'extraction) : un nom en trop rend l'inventaire plus prudent,
-- jamais moins. Tout ce qui n'y figure pas est candidat.
--
-- Pour chaque candidate : son volume, et les clés étrangères qui la retiennent
-- ou qu'elle retient — c'est l'ordre de suppression qui en dépend.

SET SESSION group_concat_max_len = 1000000;
SET @nous := 'absent,accumulated_amount,active,auth_handoff,b2b_client_company_department,bo_audit,bo_pin_session,bo_role,bo_user_shops,bo_users,client,collect_enabled,current_timestamp,delivery_start,display_name,erp_client_id,event_id,franchisee_shop,global,halle,impressions,min_threshold,param_value,partiel,payload,product_portion,product_preparation_batch_group,product_preparation_path,product_preparation_step,promotion,promotion_order_discount,pwa_client_office,pwa_invoices,pwa_offices,pwa_purchase_items,pwa_purchases,pwa_review_items,pwa_reviews,qty,qty_sold,qty_total,qui,remet,restrict,shop_product_portion_price,shops,subject,sur,value,voucher_campaign,voucher_campaign_channel,voucher_code,voucher_redemption,where,ws_assortments,ws_b2b_event_requests,ws_bo_store,ws_bundle_slot_choices,ws_bundle_slots,ws_bundle_triggers,ws_bundles,ws_calendar_rules,ws_categories,ws_category_availability,ws_category_subs,ws_client_erp_map,ws_client_link_requests,ws_client_vouchers,ws_cross_sell_pause,ws_cross_sell_rule,ws_cross_sell_shop,ws_cross_sell_stat,ws_cross_sell_target,ws_cross_sell_trigger,ws_customers,ws_delivery_fee_rules,ws_delivery_zones,ws_email_templates,ws_franchisor_catchment,ws_i18n,ws_incidents,ws_office_delivery_settings,ws_office_delivery_sites,ws_office_emails,ws_office_invites,ws_office_join_requests,ws_offices,ws_order_lines,ws_orders,ws_param,ws_pricing_rules,ws_product_allergens,ws_product_availability,ws_product_prices,ws_product_shops,ws_product_stock,ws_product_stock_defaults,ws_products,ws_promo_campaign,ws_promo_progress,ws_promo_progress_order,ws_rate_limit,ws_review_guidelines,ws_season,ws_shop_availability,ws_shop_device_token,ws_shop_exceptions,ws_shop_hours,ws_shop_payment_options,ws_shops,ws_slot_capacity,ws_slots,ws_stock_reservation,ws_stripe_event,ws_tags,ws_tour_availability,ws_tour_closures,ws_tour_postcodes,ws_tour_tracking,ws_tour_zones,ws_tours,ws_vouchers,ws_vouchers_legacy,ws_zone_requests';

SELECT t.table_name AS `candidate`,
       t.table_rows AS lignes_est,
       ROUND((t.data_length + t.index_length) / 1024) AS ko,
       (SELECT COUNT(*) FROM information_schema.key_column_usage k
         WHERE k.table_schema = DATABASE() AND k.referenced_table_name = t.table_name) AS retenue_par,
       (SELECT COUNT(*) FROM information_schema.key_column_usage k2
         WHERE k2.table_schema = DATABASE() AND k2.table_name = t.table_name
           AND k2.referenced_table_name IS NOT NULL) AS retient
  FROM information_schema.tables t
 WHERE t.table_schema = DATABASE()
   AND t.table_type = 'BASE TABLE'
   AND NOT FIND_IN_SET(t.table_name, @nous)
 ORDER BY retenue_par DESC, t.table_name;

-- Combien au total, et combien de lignes cumulées.
SELECT COUNT(*) AS tables_candidates,
       SUM(t.table_rows) AS lignes_cumulees_est,
       ROUND(SUM(t.data_length + t.index_length) / 1048576, 1) AS mo
  FROM information_schema.tables t
 WHERE t.table_schema = DATABASE() AND t.table_type = 'BASE TABLE'
   AND NOT FIND_IN_SET(t.table_name, @nous);
