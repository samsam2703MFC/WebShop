<?php
/**
 * CORS for the storefront, scoped to the atelier/v1 namespace only.
 *
 * WordPress core echoes *any* Origin back with Allow-Credentials:true, which
 * is too permissive for a public storefront API. This restricts CORS on our
 * routes to a configured allow-list (the GitHub Pages storefront by default)
 * and drops credentials (the storefront carries identity in the payload, not
 * cookies). Other REST consumers (block editor, WC Store API) are untouched.
 *
 * Configure extra origins:
 *   wp option update atelier_storefront_origins "https://a.com,https://b.com"
 * or via the `atelier_cors_allowed_origins` filter.
 */
if (!defined('ABSPATH')) exit;

class Atelier_CORS {

    public static function init() {
        add_filter('rest_pre_serve_request', [self::class, 'send'], 15, 3);
    }

    public static function allowed_origins(): array {
        $stored = (string) get_option('atelier_storefront_origins', 'https://samsam2703mfc.github.io');
        $list = array_filter(array_map('trim', explode(',', $stored)));
        return apply_filters('atelier_cors_allowed_origins', $list);
    }

    public static function send($served, $result, $request) {
        if (!($request instanceof \WP_REST_Request)) return $served;
        if (strpos($request->get_route(), '/' . Atelier_REST::NS) !== 0) return $served;

        // Replace core's permissive CORS with a strict allow-list for our routes.
        header_remove('Access-Control-Allow-Origin');
        header_remove('Access-Control-Allow-Credentials');

        $origin = get_http_origin();
        if ($origin && in_array($origin, self::allowed_origins(), true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            // The storefront fetches with credentials:'include'; the browser then
            // requires an explicit Allow-Credentials + a specific (non-*) origin,
            // else it blocks the response ("Failed to fetch"). Safe here because
            // the origin is restricted to the allow-list above.
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-WP-Nonce');
            header('Vary: Origin', false);
        }
        return $served;
    }
}
