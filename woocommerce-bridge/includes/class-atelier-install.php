<?php
/**
 * Custom B2B tables — the part WooCommerce has no equivalent for.
 * Mirrors ws_office_delivery_sites and ws_delivery_fee_rules from the
 * repo's DATABASE.md, adapted to the WordPress $wpdb prefix.
 */
if (!defined('ABSPATH')) exit;

class Atelier_Install {

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $sites = $wpdb->prefix . 'atelier_delivery_sites';
        $rules = $wpdb->prefix . 'atelier_fee_rules';

        dbDelta("CREATE TABLE $sites (
            id VARCHAR(36) NOT NULL,
            office_client_id VARCHAR(36) NOT NULL,
            name VARCHAR(160) NOT NULL,
            address VARCHAR(250) NOT NULL,
            floor_room VARCHAR(120) NULL,
            contact_name VARCHAR(120) NULL,
            contact_phone VARCHAR(30) NULL,
            tournee_id VARCHAR(36) NULL,
            tournee_stop_id VARCHAR(36) NULL,
            shop_id VARCHAR(36) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_office (office_client_id)
        ) $charset;");

        dbDelta("CREATE TABLE $rules (
            id VARCHAR(36) NOT NULL,
            level VARCHAR(10) NOT NULL,
            site_id VARCHAR(36) NULL,
            office_client_id VARCHAR(36) NULL,
            tour_id VARCHAR(36) NULL,
            shop_id VARCHAR(36) NULL,
            free_delivery TINYINT(1) NOT NULL DEFAULT 0,
            always_charge TINYINT(1) NOT NULL DEFAULT 0,
            fee_amount DECIMAL(8,2) NOT NULL DEFAULT 0,
            free_delivery_minimum DECIMAL(8,2) NOT NULL DEFAULT 0,
            payment_type VARCHAR(10) NOT NULL DEFAULT 'immediate',
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_level (level)
        ) $charset;");

        self::seed($sites, $rules);
    }

    /* Demo seed so the B2B flow is testable out of the box.
       NOTE: explicit $format arrays are required — $wpdb->insert() without
       them infers formats and silently casts VARCHAR keys like
       'site-acme-loi' to 0, which breaks fee resolution. */
    private static function seed($sites, $rules) {
        global $wpdb;
        $site_fmt = ['%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d'];
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM $sites") === 0) {
            $wpdb->insert($sites, [
                'id' => 'site-acme-loi', 'office_client_id' => 'off-acme',
                'name' => 'ACME Avocats — Rue de la Loi', 'address' => 'Rue de la Loi 120, 1040 Bruxelles',
                'floor_room' => '4e étage, salle Themis', 'contact_name' => 'Marie Dubois',
                'contact_phone' => '+32 472 11 22 33', 'tournee_id' => 'tour-bxl-mid',
                'tournee_stop_id' => 'stop-acme-loi', 'shop_id' => 'chatelain', 'active' => 1,
            ], $site_fmt);
            $wpdb->insert($sites, [
                'id' => 'site-acme-arts', 'office_client_id' => 'off-acme',
                'name' => 'ACME Avocats — Place des Arts', 'address' => 'Place des Arts 7, 1210 Saint-Josse',
                'floor_room' => 'Réception', 'contact_name' => 'Pierre Fontaine',
                'contact_phone' => '+32 472 33 44 55', 'tournee_id' => 'tour-bxl-am',
                'tournee_stop_id' => 'stop-acme-arts', 'shop_id' => 'sablon', 'active' => 1,
            ], $site_fmt);
        }
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM $rules") === 0) {
            $rule_fmt = ['%s','%s','%s','%s','%s','%s','%d','%d','%f','%f','%s','%d'];
            $rows = [
                ['rule-site-loi', 'site', 'site-acme-loi', null, null, null, 0, 0, 4.50, 40.00, 'deferred'],
                ['rule-site-arts', 'site', 'site-acme-arts', null, null, null, 1, 0, 0, 0, 'immediate'],
                ['rule-off-acme', 'office', null, 'off-acme', null, null, 0, 0, 5.00, 50.00, 'deferred'],
                ['rule-global', 'global', null, null, null, null, 0, 0, 7.00, 50.00, 'immediate'],
            ];
            foreach ($rows as $r) {
                $wpdb->insert($rules, [
                    'id' => $r[0], 'level' => $r[1], 'site_id' => $r[2], 'office_client_id' => $r[3],
                    'tour_id' => $r[4], 'shop_id' => $r[5], 'free_delivery' => $r[6], 'always_charge' => $r[7],
                    'fee_amount' => $r[8], 'free_delivery_minimum' => $r[9], 'payment_type' => $r[10], 'active' => 1,
                ], $rule_fmt);
            }
        }
    }
}
