-- 0110 — Supprime 104 tables VIDES et sans clé étrangère entrante.
--
-- CE QUI EST SUPPRIMÉ, ET CE QUI A ÉTÉ ÉCARTÉ SUR PREUVE.
--
-- Point de départ : les 154 tables mesurées vides et non retenues par 0109.
-- Cinquante ont été retirées de la liste, chacune pour une raison mesurée :
--   •  9 portent une date d'écriture récente. Une file d'attente vidée est
--      vide et pourtant vivante — crm_form_score_queue (26/08),
--      mar_campaign_shop (25/08), ceo_shop_budget_line (18/08).
--   • 33 ont été créées en août. Une fonctionnalité en construction est vide
--      parce qu'elle n'a pas encore servi, pas parce qu'elle est morte :
--      sig_*, crm_immersion_*, ceo_campagne_*.
--   •  7 relèvent du webshop ou de la PWA — pwa_reservations,
--      ws_stock_reservations, client_merge_log, b2b_client_department… —
--      adjacentes à des systèmes dont on sait qu'ils tournent.
--   •  1, auth_otp, parce que l'authentification client est en cours de
--      livraison côté ERP et que ce nom est trop proche du chantier.
--
-- POURQUOI CE GESTE EST RÉVERSIBLE. Ces tables sont vides : la seule chose
-- qu'on perd est leur STRUCTURE. Elle est donc reconstituée et imprimée AVANT
-- toute suppression, et le journal de déploiement en garde la trace. Recréer
-- l'une d'elles, c'est copier une ligne de ce journal.
--
-- Ce que la capture contient : colonnes, types, nullabilité, valeurs par
-- défaut, extra (auto_increment) et clé primaire. Ce qu'elle ne contient pas :
-- les index secondaires et les options de moteur. Sur des tables vides et que
-- rien ne référence, c'est la structure qui compte, et il faut le savoir
-- plutôt que le découvrir.
--
-- TROIS GARDES AU MOMENT DE L'EXÉCUTION, pas au moment de la mesure — une
-- table a pu recevoir des lignes ou une contrainte depuis le relevé :
--   1. elle existe encore ;
--   2. COUNT(*) vaut toujours 0 ;
--   3. aucune clé étrangère ne la référence.
-- Une cible qui échoue à l'une des trois n'est pas supprimée, et la sortie la
-- nomme. C'est le contrôle qui manquait à 0102 ce matin (ERROR 1828).
--
-- CE QUE JE NE PEUX PAS VÉRIFIER D'ICI. Le code de Franchise Buddy n'est pas
-- dans ce dépôt. « Vide » prouve qu'aucune donnée n'est perdue ; ça ne prouve
-- pas qu'aucun code n'écrira jamais dedans. C'est exactement pourquoi la
-- structure est capturée avant.
--
-- EFFET DE BORD À CONNAÎTRE : huit des treize clés étrangères qui retiennent
-- `product` viennent de tables de cette liste (promotion_bundle_item,
-- promotion_reward_product, promotion_trigger_product…). Après cette
-- migration, `product` ne sera plus retenue que par cinq. Elle n'est pas
-- supprimée pour autant : ce n'est pas l'objet ici.

SET SESSION group_concat_max_len = 1000000;

SET @cibles := 'cd_candidate_steps,cd_investors,competency_knowledge_resource_connection,competency_operational_procedure_connection,complaint_reason,consultant_area_description_duties,consultant_area_template_duties,consultant_note_comment,course_completion_by_employee,crm_candidate_docs,crm_candidate_mails,crm_push_subs,customer_survey_question_alias,customer_survey_question_answer_alias,customer_survey_question_answer_connection,device_withdrawing_money,domain_functional_category_description_alias,domain_functional_category_name_alias,domain_functional_section_description_alias,domain_functional_section_name_alias,domain_functional_subcategory_description_alias,domain_functional_subcategory_name_alias,employee_position_connection,franchisee_employee_competence_description_alias,franchisee_employee_competence_group_description_alias,franchisee_employee_competence_subgroup_description_alias,franchisee_employee_production_area_description_alias,franchisee_employee_production_role,franchisee_task_type_alias,marketing_campaign_alias,marketing_material_summary_alias,marketing_material_title_alias,material_category_alias,material_order_document,material_order_final_item,material_order_history,material_order_transport,material_supplier_contact,material_supplier_order_config,material_supplier_order_fee,network_countries,network_franchise_types,operational_procedure_attachment,operational_procedure_attachment_label_alias,operational_procedure_category_description_alias,operational_procedure_category_name_alias,operational_procedure_description_alias,operational_procedure_section_content_alias,operational_procedure_title_alias,operational_procedure_todo_link_note_alias,position_level_permission,position_level_task_connection,price_updates,pricelist_state,product_availability_period_description_alias,product_availability_period_name_alias,product_bundle_shop,product_competence_description_alias,product_competence_name_alias,product_package_description_alias,product_package_name_alias,product_positioning_description_alias,product_positioning_name_alias,product_recipe_alias,product_recipe_preparation_step_alias,product_recipe_preparation_type_alias,product_storage_description_alias,product_storage_name_alias,product_subrecipe_category_alias,promotion_bundle,promotion_bundle_item,promotion_buy_x_get_y,promotion_calculation_item,promotion_quantity_category,promotion_quantity_product,promotion_reward_category,promotion_reward_category_excluded_product,promotion_reward_product,promotion_schedule,promotion_scheduled_discount_excluded_product,promotion_scheduled_product_discount,promotion_shop,promotion_trigger_category,promotion_trigger_category_excluded_product,promotion_trigger_product,royalty_webhook_event,secret_share,shop_monthly_pnl,shop_workstation_description_alias,shop_workstation_name_alias,supplier_recipe_step,todo_task_admin_role,todo_task_description_alias,todo_task_employee_role,todo_task_name_alias,todo_task_set_description_alias,todo_task_set_name_alias,todo_task_subtype_alias,training_admin_role_connection,training_employee_role_connection,transaction_correction,transaction_correction_event,transaction_correction_item,withdrawing_money_reason_description_alias';

-- ── 1. LA STRUCTURE, AVANT TOUTE SUPPRESSION ────────────────────────────────
-- Reconstituée depuis information_schema plutôt que par SHOW CREATE TABLE :
-- une instruction SHOW préparée n'est pas garantie sur toutes les versions, et
-- un échec ici ferait tomber le déploiement au pire moment — juste avant des
-- suppressions. Cette forme-ci ne peut pas échouer.
SELECT CONCAT('CREATE TABLE `', c.table_name, '` (',
              GROUP_CONCAT(CONCAT('`', c.column_name, '` ', c.column_type,
                                  IF(c.is_nullable = 'NO', ' NOT NULL', ''),
                                  IF(c.column_default IS NULL, '',
                                     CONCAT(' DEFAULT ', c.column_default)),
                                  IF(c.extra = '', '', CONCAT(' ', c.extra)))
                           ORDER BY c.ordinal_position SEPARATOR ', '),
              IFNULL((SELECT CONCAT(', PRIMARY KEY (',
                                    GROUP_CONCAT(CONCAT('`', s.column_name, '`')
                                                 ORDER BY s.seq_in_index SEPARATOR ','), ')')
                        FROM information_schema.statistics s
                       WHERE s.table_schema = DATABASE() AND s.table_name = c.table_name
                         AND s.index_name = 'PRIMARY'), ''),
              ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;') AS structure_avant_suppression
  FROM information_schema.columns c
 WHERE c.table_schema = DATABASE() AND FIND_IN_SET(c.table_name, @cibles)
 GROUP BY c.table_name
 ORDER BY c.table_name;

-- ── 2. LA SUPPRESSION, GARDÉE ───────────────────────────────────────────────
DROP PROCEDURE IF EXISTS ws_drop_vides;
DELIMITER //
CREATE PROCEDURE ws_drop_vides()
BEGIN
  DECLARE fini INT DEFAULT 0;
  DECLARE nm VARCHAR(64);
  DECLARE cur CURSOR FOR
    SELECT t.table_name FROM information_schema.tables t
     WHERE t.table_schema = DATABASE() AND t.table_type = 'BASE TABLE'
       AND FIND_IN_SET(t.table_name, @cibles)
       AND NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage k
                        WHERE k.table_schema = DATABASE()
                          AND k.referenced_table_name = t.table_name)
     ORDER BY t.table_name;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET fini = 1;
  OPEN cur;
  b: LOOP
    FETCH cur INTO nm;
    IF fini = 1 THEN LEAVE b; END IF;
    -- Garde : toujours vide À CET INSTANT, pas au moment de la mesure.
    SET @c := CONCAT('SELECT COUNT(*) INTO @n FROM `', nm, '`');
    PREPARE st FROM @c; EXECUTE st; DEALLOCATE PREPARE st;
    IF @n = 0 THEN
      SET @c := CONCAT('DROP TABLE `', nm, '`');
      PREPARE st FROM @c; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
  END LOOP;
  CLOSE cur;
END //
DELIMITER ;
CALL ws_drop_vides();
DROP PROCEDURE ws_drop_vides;

-- ── 3. CE QUI A SURVÉCU, ET POURQUOI ────────────────────────────────────────
SELECT t.table_name AS `non_supprimee`,
       t.table_rows AS lignes_est,
       (SELECT COUNT(*) FROM information_schema.key_column_usage k
         WHERE k.table_schema = DATABASE() AND k.referenced_table_name = t.table_name) AS retenue_par
  FROM information_schema.tables t
 WHERE t.table_schema = DATABASE() AND FIND_IN_SET(t.table_name, @cibles)
 ORDER BY t.table_name;

SELECT (LENGTH(@cibles) - LENGTH(REPLACE(@cibles, ',', '')) + 1) AS ciblees,
       COUNT(*) AS restantes
  FROM information_schema.tables t
 WHERE t.table_schema = DATABASE() AND FIND_IN_SET(t.table_name, @cibles);
