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

        self::cleanup_demo($sites, $rules);
        self::seed_defaults($rules);
    }

    /* Remove the ACME demo B2B rows shipped by earlier versions. Idempotent. */
    private static function cleanup_demo($sites, $rules) {
        global $wpdb;
        $wpdb->query("DELETE FROM $sites WHERE id IN ('site-acme-loi','site-acme-arts')");
        $wpdb->query("DELETE FROM $rules WHERE id IN ('rule-site-loi','rule-site-arts','rule-off-acme')");
    }

    /* Seed only the GLOBAL fallback delivery-fee rule — a real default, not
       demo data. Real delivery sites/fees are added by the shop later. */
    private static function seed_defaults($rules) {
        global $wpdb;
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM $rules WHERE level = 'global'") === 0) {
            $wpdb->insert($rules, [
                'id' => 'rule-global', 'level' => 'global', 'site_id' => null, 'office_client_id' => null,
                'tour_id' => null, 'shop_id' => null, 'free_delivery' => 0, 'always_charge' => 0,
                'fee_amount' => 7.00, 'free_delivery_minimum' => 50.00, 'payment_type' => 'immediate', 'active' => 1,
            ], ['%s','%s','%s','%s','%s','%s','%d','%d','%f','%f','%s','%d']);
        }
    }
}
