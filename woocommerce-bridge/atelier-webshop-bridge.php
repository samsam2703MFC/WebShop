<?php
/**
 * Plugin Name: Atelier Webshop Bridge
 * Description: Exposes the L'Atelier By storefront API contracts (window.WSXxx from API.md)
 *              on top of WooCommerce. The static React front-end points api-config.js at
 *              /wp-json/atelier/v1/ and runs unchanged — WooCommerce becomes the engine
 *              (products, coupons, tax, orders, Stripe) while this plugin adds the custom
 *              B2B layer (office delivery sites, per-site fees, deferred payment, tournées).
 * Version:     1.0.0
 * Author:      L'Atelier By
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 *
 * Contract source of truth: ../../../API.md and DATA_SHAPES.md at the repo root.
 * Everything here maps WooCommerce <-> those contracts so the front-end sees no difference
 * between the Node reference backend and this WooCommerce backend.
 */

if (!defined('ABSPATH')) exit;

define('ATELIER_BRIDGE_VERSION', '1.0.0');
define('ATELIER_BRIDGE_DIR', plugin_dir_path(__FILE__));

require_once ATELIER_BRIDGE_DIR . 'includes/class-atelier-install.php';
require_once ATELIER_BRIDGE_DIR . 'includes/class-atelier-mapper.php';
require_once ATELIER_BRIDGE_DIR . 'includes/class-atelier-pricing.php';
require_once ATELIER_BRIDGE_DIR . 'includes/class-atelier-erp.php';
require_once ATELIER_BRIDGE_DIR . 'includes/class-atelier-auth.php';
require_once ATELIER_BRIDGE_DIR . 'includes/class-atelier-availability.php';
require_once ATELIER_BRIDGE_DIR . 'includes/class-atelier-rest.php';
require_once ATELIER_BRIDGE_DIR . 'includes/class-atelier-cors.php';

/* Custom B2B tables are created on activation (delivery sites + fee rules).
   These have no WooCommerce equivalent, so the plugin owns them. */
register_activation_hook(__FILE__, ['Atelier_Install', 'activate']);

/* Register all REST routes under the atelier/v1 namespace. */
add_action('rest_api_init', ['Atelier_REST', 'register_routes']);

/* Restrict CORS on our routes to the storefront origin(s). */
Atelier_CORS::init();

/* Make sure WooCommerce is present; if not, warn in admin. */
add_action('admin_init', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>Atelier Webshop Bridge requires WooCommerce to be installed and active.</p></div>';
        });
    }
});
