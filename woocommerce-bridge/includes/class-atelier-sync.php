<?php
/**
 * Write-side sync — receives ABSOLUTE price / stock values from the Franchise
 * Buddy master (the webshop backend) and applies them to WooCommerce products,
 * keyed by SKU (external_id). Values are absolute (set, not delta) → the calls
 * are idempotent and safe to replay; a lost or duplicated request never drifts
 * the stock, and the next full push self-heals any divergence.
 *
 * Auth: a shared secret in the `atelier_sync_token` option (set via WP-CLI /
 * admin, never hardcoded). Buddy sends it as `X-Atelier-Sync-Token`.
 *
 *   wp option update atelier_sync_token "<long-random-secret>"
 *
 * Price note: values are TTC. This assumes the store is configured with prices
 * entered inclusive of tax (the Belgian food-retail default). Promotions are
 * handled separately (WooCommerce sale price / coupons) and are NOT touched.
 */
if (!defined('ABSPATH')) exit;

class Atelier_Sync {

    const TOKEN_OPT = 'atelier_sync_token';

    /* Constant-time check of the X-Atelier-Sync-Token header. */
    public static function token_ok(\WP_REST_Request $req): bool {
        $expected = (string) get_option(self::TOKEN_OPT, '');
        if ($expected === '') return false; // not configured → deny by default
        $given = (string) $req->get_header('x-atelier-sync-token');
        return $given !== '' && hash_equals($expected, $given);
    }

    private static function deny() {
        return new \WP_Error('sync_unauthorized', 'Jeton de synchronisation invalide.', ['status' => 401]);
    }

    /* Normalize the body to a list of rows. Accepts a bare array or {items:[...]}. */
    private static function rows(\WP_REST_Request $req): array {
        $b = $req->get_json_params();
        if (is_array($b) && isset($b['items']) && is_array($b['items'])) $b = $b['items'];
        return is_array($b) ? $b : [];
    }

    private static function product_for(string $sku) {
        if ($sku === '') return null;
        $pid = wc_get_product_id_by_sku($sku);
        return $pid ? wc_get_product($pid) : null;
    }

    private static function apply_stock(\WC_Product $product, $value): void {
        $qty = max(0, (int) $value);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($qty);
        $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
    }

    private static function apply_price(\WC_Product $product, $value): void {
        $price = (string) wc_format_decimal($value, wc_get_price_decimals());
        $product->set_regular_price($price);
    }

    /* POST /sync/stock — body: [ {sku, value}, ... ]  value = absolute qty. */
    public static function stock(\WP_REST_Request $req) {
        if (!self::token_ok($req)) return self::deny();
        $updated = 0; $missing = [];
        foreach (self::rows($req) as $row) {
            $sku = isset($row['sku']) ? (string) $row['sku'] : '';
            if ($sku === '' || !array_key_exists('value', $row)) continue;
            $product = self::product_for($sku);
            if (!$product) { $missing[] = $sku; continue; }
            self::apply_stock($product, $row['value']);
            $product->save();
            $updated++;
        }
        return rest_ensure_response(['ok' => true, 'updated' => $updated, 'missing' => $missing]);
    }

    /* POST /sync/price — body: [ {sku, value}, ... ]  value = absolute TTC price. */
    public static function price(\WP_REST_Request $req) {
        if (!self::token_ok($req)) return self::deny();
        $updated = 0; $missing = [];
        foreach (self::rows($req) as $row) {
            $sku = isset($row['sku']) ? (string) $row['sku'] : '';
            if ($sku === '' || !array_key_exists('value', $row)) continue;
            $product = self::product_for($sku);
            if (!$product) { $missing[] = $sku; continue; }
            self::apply_price($product, $row['value']);
            $product->save();
            $updated++;
        }
        return rest_ensure_response(['ok' => true, 'updated' => $updated, 'missing' => $missing]);
    }

    /* POST /sync/products — combined price + stock in one call.
       body: [ {sku, price?, stock?}, ... ] — only the keys present are applied. */
    public static function products(\WP_REST_Request $req) {
        if (!self::token_ok($req)) return self::deny();
        $updated = 0; $missing = [];
        foreach (self::rows($req) as $row) {
            $sku = isset($row['sku']) ? (string) $row['sku'] : '';
            if ($sku === '') continue;
            $product = self::product_for($sku);
            if (!$product) { $missing[] = $sku; continue; }
            $touched = false;
            if (array_key_exists('price', $row) && $row['price'] !== null) {
                self::apply_price($product, $row['price']); $touched = true;
            }
            if (array_key_exists('stock', $row) && $row['stock'] !== null) {
                self::apply_stock($product, $row['stock']); $touched = true;
            }
            if ($touched) { $product->save(); $updated++; }
        }
        return rest_ensure_response(['ok' => true, 'updated' => $updated, 'missing' => $missing]);
    }
}
