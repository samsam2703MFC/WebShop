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

    /* ── Orders pull (Woo → Buddy) ─────────────────────────────────── */

    /* GET /sync/orders?since=<ISO8601>&statuses=processing,completed&limit=100
       Lists validated orders modified at/after `since`, oldest-first, so the
       Buddy master can pull them. Snapshots are absolute → re-pulling the
       boundary order is harmless (Buddy upserts idempotently by id). */
    public static function orders(\WP_REST_Request $req) {
        if (!self::token_ok($req)) return self::deny();

        $since  = (string) $req->get_param('since');
        $limit  = min(200, max(1, (int) ($req->get_param('limit') ?: 100)));
        $stParam = (string) ($req->get_param('statuses') ?: 'processing,completed');
        $statuses = array_values(array_filter(array_map('trim', explode(',', $stParam))));

        $args = [
            'limit'   => $limit,
            'status'  => $statuses,
            'orderby' => 'modified',
            'order'   => 'ASC',
        ];
        if ($since !== '' && strtotime($since)) $args['date_modified'] = '>=' . strtotime($since);

        $orders = wc_get_orders($args);
        $out = []; $next = $since;
        foreach ($orders as $o) {
            $out[] = self::order_payload($o);
            $m = $o->get_date_modified();
            if ($m) $next = $m->format('c');
        }
        return rest_ensure_response(['orders' => $out, 'next_since' => $next, 'count' => count($out)]);
    }

    /* Normalize a WooCommerce order to the ws_orders / ws_order_lines shape.
       Money is TTC where the storefront needs it, with the HTVA/TVA split so
       Buddy can store both. */
    private static function order_payload(\WC_Order $o): array {
        $lines = [];
        foreach ($o->get_items() as $item) {
            $product = $item->get_product();
            $qty  = (int) $item->get_quantity();
            $htva = round((float) $item->get_total(), 2);       // net (excl. tax)
            $tva  = round((float) $item->get_total_tax(), 2);
            $ttc  = round($htva + $tva, 2);
            $lines[] = [
                'sku'       => $product ? $product->get_sku() : '',
                'name'      => $item->get_name(),
                'qty'       => $qty,
                'unit_ttc'  => $qty ? round($ttc / $qty, 2) : 0,
                'vat_rate'  => $htva > 0 ? round(($tva / $htva) * 100, 2) : 0,
                'line_htva' => $htva,
                'line_tva'  => $tva,
                'line_ttc'  => $ttc,
            ];
        }
        $subtotalTtc = round(array_sum(array_column($lines, 'line_ttc')), 2);
        $totalTva    = round((float) $o->get_total_tax(), 2);
        $totalTtc    = round((float) $o->get_total(), 2);

        return [
            'id'           => (string) $o->get_id(),
            'number'       => (string) $o->get_order_number(),
            'status'       => $o->get_status(),
            'created'      => $o->get_date_created() ? $o->get_date_created()->format('c') : null,
            'modified'     => $o->get_date_modified() ? $o->get_date_modified()->format('c') : null,
            'paid'         => $o->get_date_paid() ? $o->get_date_paid()->format('c') : null,
            'currency'     => $o->get_currency(),
            'mode'         => $o->get_meta('_atelier_mode') ?: 'collect',
            'payment_type' => $o->get_meta('_atelier_payment_type') ?: 'immediate',
            'customer'     => [
                'id'    => (string) $o->get_customer_id(),
                'email' => $o->get_billing_email(),
                'name'  => trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()),
                'phone' => $o->get_billing_phone() ?: null,
            ],
            'totals'       => [
                'subtotal_ttc' => $subtotalTtc,
                'discount_ttc' => round((float) $o->get_discount_total() + (float) $o->get_discount_tax(), 2),
                'total_ttc'    => $totalTtc,
                'total_tva'    => $totalTva,
                'total_htva'   => round($totalTtc - $totalTva, 2),
            ],
            'stripe'       => [
                'payment_intent' => $o->get_meta('_stripe_intent_id') ?: ($o->get_transaction_id() ?: null),
            ],
            'lines'        => $lines,
        ];
    }
}
