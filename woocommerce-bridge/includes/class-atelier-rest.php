<?php
/**
 * REST routes under /wp-json/atelier/v1/ matching the WSXxx contracts.
 * The React front-end sets api-config.js BASE_URL to this namespace and
 * runs unchanged. Standard commerce is delegated to WooCommerce; the B2B
 * layer is served from the plugin's own tables.
 */
if (!defined('ABSPATH')) exit;

class Atelier_REST {

    const NS = 'atelier/v1';

    public static function register_routes() {
        $public = '__return_true'; // storefront reads are public; writes validated in-handler

        $get = fn($cb) => ['methods' => 'GET', 'callback' => $cb, 'permission_callback' => '__return_true'];
        $post = fn($cb) => ['methods' => 'POST', 'callback' => $cb, 'permission_callback' => '__return_true'];

        register_rest_route(self::NS, '/shops', $get([self::class, 'shops']));
        register_rest_route(self::NS, '/catalog/categories', $get([self::class, 'categories']));
        register_rest_route(self::NS, '/catalog/products', $get([self::class, 'products']));
        register_rest_route(self::NS, '/catalog/assortments', $get(fn() => rest_ensure_response([])));
        register_rest_route(self::NS, '/catalog/bundles', $get(fn() => rest_ensure_response([])));

        register_rest_route(self::NS, '/pricing/quote', $post([self::class, 'quote']));
        register_rest_route(self::NS, '/pricing/payment-methods', $get([self::class, 'payment_methods']));
        register_rest_route(self::NS, '/pricing/promos/cross-portion', $get(fn() =>
            rest_ensure_response(['active' => true, 'buy' => 4, 'free' => 1, 'scope' => 'crossPortion'])));

        register_rest_route(self::NS, '/vouchers/redeem', $post([self::class, 'redeem_voucher']));

        register_rest_route(self::NS, '/delivery-fees/quote', $post([self::class, 'fee_quote']));
        register_rest_route(self::NS, '/delivery-fees/sites', $post([self::class, 'fee_sites']));

        register_rest_route(self::NS, '/tours', $get([self::class, 'tours']));
        register_rest_route(self::NS, '/offices', $get(fn() => rest_ensure_response([])));

        register_rest_route(self::NS, '/orders', $post([self::class, 'place_order']));
        register_rest_route(self::NS, '/orders/(?P<id>[\w-]+)', $get([self::class, 'get_order']));

        // Auth — real WordPress/WooCommerce customer accounts + bearer tokens.
        register_rest_route(self::NS, '/auth/register', $post(['Atelier_Auth', 'register']));
        register_rest_route(self::NS, '/auth/login', $post(['Atelier_Auth', 'login']));
        register_rest_route(self::NS, '/auth/logout', $post(['Atelier_Auth', 'logout']));
        register_rest_route(self::NS, '/auth/me', $get(['Atelier_Auth', 'me']));
        register_rest_route(self::NS, '/auth/me', ['methods' => 'PATCH', 'callback' => ['Atelier_Auth', 'update_me'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/auth/password-reset', $post(['Atelier_Auth', 'password_reset']));

        // Availability — real pickup days/slots from the shop's opening hours.
        register_rest_route(self::NS, '/availability/settings', $get(['Atelier_Availability', 'settings']));
        register_rest_route(self::NS, '/availability/days', $get(['Atelier_Availability', 'days']));
        register_rest_route(self::NS, '/availability/slots', $get(['Atelier_Availability', 'slots']));
        // WSCalendar compatibility (older stub paths).
        register_rest_route(self::NS, '/calendar/days', $get(['Atelier_Availability', 'days']));
        register_rest_route(self::NS, '/calendar/slots', $get(['Atelier_Availability', 'slots']));
        register_rest_route(self::NS, '/calendar/cutoff', $get(['Atelier_Availability', 'cutoff_endpoint']));
    }

    /* ── Catalog ─────────────────────────────────────────────────── */
    public static function shops() {
        // Real shops from Franchise Buddy (ERP); fall back to the WooCommerce
        // store settings if the ERP is unreachable.
        $erp = Atelier_ERP::shops();
        if (is_array($erp) && count($erp)) return rest_ensure_response($erp);
        return rest_ensure_response(Atelier_Mapper::shops());
    }

    public static function categories() {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        $out = array_map([Atelier_Mapper::class, 'category'], is_array($terms) ? $terms : []);
        return rest_ensure_response(array_values($out));
    }

    public static function products(\WP_REST_Request $req) {
        $q = new \WC_Product_Query([
            'status' => 'publish', 'limit' => -1, 'orderby' => 'title', 'order' => 'ASC',
        ]);
        $out = [];
        foreach ($q->get_products() as $p) $out[] = Atelier_Mapper::product($p);
        return rest_ensure_response($out);
    }

    /* ── Pricing ─────────────────────────────────────────────────── */
    public static function quote(\WP_REST_Request $req) {
        try {
            return rest_ensure_response(Atelier_Pricing::quote($req->get_json_params() ?: []));
        } catch (Exception $e) {
            return new \WP_Error('quote_error', $e->getMessage(), ['status' => $e->getCode() ?: 400]);
        }
    }

    public static function payment_methods(\WP_REST_Request $req) {
        $mode = $req->get_param('mode');
        if ($mode === 'delivery' && ($req->get_param('siteId') || $req->get_param('officeClientId'))) {
            $fee = Atelier_Pricing::resolve_delivery_fee([
                'siteId' => $req->get_param('siteId'), 'officeClientId' => $req->get_param('officeClientId'),
                'tourneeId' => $req->get_param('tourneeId'), 'shopId' => $req->get_param('shopId'), 'subtotal' => 0,
            ]);
            if ($fee['payment_type'] === 'deferred') {
                return rest_ensure_response([[
                    'id' => 'deferred', 'label' => 'Paiement différé', 'sub' => 'Facturation mensuelle · paiement sur facture']]);
            }
        }
        $methods = [];
        // Click & Collect → cash paid in store at pickup.
        if ($mode === 'collect') {
            $methods[] = ['id' => 'especes', 'label' => 'Espèces en boutique', 'sub' => 'Paiement au retrait'];
        }
        // Online card / Bancontact via WooCommerce Stripe (when configured).
        $methods[] = ['id' => 'bancontact', 'label' => 'Bancontact', 'sub' => 'Paiement en ligne'];
        $methods[] = ['id' => 'visa', 'label' => 'Carte bancaire', 'sub' => 'Visa · Mastercard · Amex'];
        return rest_ensure_response($methods);
    }

    /* ── Vouchers (WooCommerce coupons) ──────────────────────────── */
    public static function redeem_voucher(\WP_REST_Request $req) {
        $b = $req->get_json_params() ?: [];
        $r = Atelier_Pricing::apply_voucher(strtoupper(trim($b['code'] ?? '')), (float) ($b['subtotal'] ?? 0));
        if (!$r['ok']) return rest_ensure_response(['ok' => false, 'message' => $r['message'] ?: 'Code invalide']);
        return rest_ensure_response(['ok' => true, 'discount' => $r['discount'], 'voucher' => $r['voucher'], 'message' => 'Code appliqué']);
    }

    /* ── Delivery fees (plugin B2B tables) ───────────────────────── */
    public static function fee_quote(\WP_REST_Request $req) {
        $b = $req->get_json_params() ?: [];
        $fee = Atelier_Pricing::resolve_delivery_fee([
            'siteId' => $b['siteId'] ?? null, 'officeClientId' => $b['officeClientId'] ?? null,
            'tourneeId' => $b['tourneeId'] ?? null, 'shopId' => $b['shopId'] ?? null,
            'subtotal' => (float) ($b['subtotal'] ?? 0),
        ]);
        $site = null;
        if (!empty($b['siteId'])) {
            global $wpdb; $t = $wpdb->prefix . 'atelier_delivery_sites';
            $site = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %s", $b['siteId']), ARRAY_A);
        }
        return rest_ensure_response($fee + ['site' => $site]);
    }

    public static function fee_sites(\WP_REST_Request $req) {
        $b = $req->get_json_params() ?: [];
        global $wpdb; $t = $wpdb->prefix . 'atelier_delivery_sites';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE office_client_id = %s AND active = 1", $b['officeClientId'] ?? ''
        ), ARRAY_A);
        return rest_ensure_response($rows ?: []);
    }

    public static function tours() {
        // No demo data — B2B delivery tours come from real configuration (none yet).
        return rest_ensure_response([]);
    }

    /* ── Orders → WooCommerce order + Stripe ─────────────────────── */
    public static function place_order(\WP_REST_Request $req) {
        $b = $req->get_json_params() ?: [];
        try {
            // 1. Server-side price (client totals ignored).
            $officeContext = [];
            if (!empty($b['delivery'])) {
                $d = $b['delivery'];
                $officeContext = [
                    'siteId' => $d['office_delivery_site_id'] ?? null,
                    'officeClientId' => $d['office_client_id'] ?? null,
                    'tourneeId' => $d['tournee_id'] ?? null,
                ];
            }
            $q = Atelier_Pricing::quote([
                'shopId' => $b['shopId'] ?? null, 'mode' => $b['mode'] ?? 'collect',
                'basket' => $b['basket'] ?? [], 'voucherCode' => $b['voucher'] ?? null,
                'officeContext' => $officeContext,
            ]);
        } catch (Exception $e) {
            return new \WP_Error('order_error', $e->getMessage(), ['status' => $e->getCode() ?: 400]);
        }

        $paymentType = ($b['mode'] === 'delivery' && $q['delivery_fee']) ? $q['delivery_fee']['payment_type'] : 'immediate';

        // 2. Create a real WooCommerce order (server prices).
        $order = wc_create_order();
        foreach ($q['lines'] as $l) {
            $order->add_product(wc_get_product($l['productId']), $l['qty']);
        }
        if ($q['delivery_fee'] && $q['delivery_fee']['fee_amount'] > 0) {
            // The quote's fee_amount is TTC (what the customer pays). WooCommerce
            // order-item fees are net, so store the net and let WC re-add 21% VAT
            // to land back on the same TTC — keeps the WC order total == quote total.
            $feeNet = round((float) $q['delivery_fee']['fee_amount'] / 1.21, 2);
            $fee = new \WC_Order_Item_Fee();
            $fee->set_name('Frais de livraison');
            $fee->set_amount((string) $feeNet);
            $fee->set_total((string) $feeNet);
            $fee->set_tax_status('taxable');
            $fee->set_tax_class(''); // standard 21%
            $order->add_item($fee);
        }
        if (!empty($b['customer']['email'])) {
            $order->set_billing_email($b['customer']['email']);
            $order->set_billing_first_name($b['customer']['firstName'] ?? '');
            $order->set_billing_last_name($b['customer']['lastName'] ?? '');
        }
        // Link to the authenticated WooCommerce customer (from bearer token),
        // so the order appears in their account and order history.
        $authUid = Atelier_Auth::user_from_request($req);
        if ($authUid) {
            $order->set_customer_id($authUid);
            $u = get_userdata($authUid);
            if ($u && !$order->get_billing_email()) $order->set_billing_email($u->user_email);
            // Carry the customer's billing/invoice details onto the order so an
            // invoice can be generated (company, address, VAT).
            $cust = new \WC_Customer($authUid);
            if ($cust->get_billing_company())   $order->set_billing_company($cust->get_billing_company());
            if ($cust->get_billing_address_1()) $order->set_billing_address_1($cust->get_billing_address_1());
            if ($cust->get_billing_city())      $order->set_billing_city($cust->get_billing_city());
            if ($cust->get_billing_postcode())  $order->set_billing_postcode($cust->get_billing_postcode());
            if ($cust->get_billing_country())   $order->set_billing_country($cust->get_billing_country());
            $vat = get_user_meta($authUid, '_atelier_vat_number', true);
            if ($vat) { $order->update_meta_data('_atelier_vat_number', $vat); $order->update_meta_data('_vat_number', $vat); }
        }
        if ($q['voucher']) $order->apply_coupon($q['voucher']['code']);

        // B2B metadata (mirrors ws_orders columns).
        $order->update_meta_data('_atelier_mode', $b['mode'] ?? 'collect');
        $order->update_meta_data('_atelier_payment_type', $paymentType);
        if (!empty($b['delivery'])) {
            foreach ([
                'office_client_id', 'office_delivery_site_id', 'office_delivery_site_name',
                'tournee_id', 'tournee_stop_id',
            ] as $k) {
                if (isset($b['delivery'][$k])) $order->update_meta_data('_atelier_' . $k, $b['delivery'][$k]);
            }
            $order->update_meta_data('_atelier_delivery_fee', $q['delivery_fee']['fee_amount'] ?? 0);
        }
        if (!empty($b['slot'])) $order->update_meta_data('_atelier_slot', $b['slot']['label'] ?? '');
        $order->calculate_totals();

        $paymentMethod = $b['payment']['method'] ?? '';

        // 3a. Deferred B2B — no online payment.
        if ($paymentType === 'deferred') {
            $order->update_status('on-hold', 'Paiement différé — facturation mensuelle');
            $order->save();
            return rest_ensure_response([
                'ok' => true, 'orderId' => (string) $order->get_id(),
                'status' => 'deferred_billing', 'total' => $q['total_ttc'], 'payment' => 'deferred',
            ]);
        }

        // 3b. Cash in store (Click & Collect) — paid at pickup, no online payment.
        if (in_array($paymentMethod, ['especes', 'cash', 'cod'], true)) {
            $order->update_meta_data('_atelier_payment_type', 'cash');
            $order->set_payment_method('cod');
            $order->set_payment_method_title('Espèces en boutique');
            $order->update_status('on-hold', 'Paiement en espèces au retrait en boutique');
            $order->save();
            return rest_ensure_response([
                'ok' => true, 'orderId' => (string) $order->get_id(),
                'status' => 'awaiting_pickup_payment', 'total' => $q['total_ttc'], 'payment' => 'especes',
            ]);
        }

        // 3c. Immediate — hand off to WooCommerce Stripe (cards + Bancontact).
        $order->update_status('pending', 'En attente de paiement');
        $order->save();
        $checkoutUrl = self::stripe_checkout_url($order);
        return rest_ensure_response([
            'ok' => true, 'orderId' => (string) $order->get_id(), 'status' => 'pending_payment',
            'total' => $q['total_ttc'], 'checkoutUrl' => $checkoutUrl,
        ]);
    }

    /* Build the payment URL. With the WooCommerce Stripe Gateway active this
       is the order-pay page, which renders Stripe's card + Bancontact fields
       (PaymentIntents, SCA, webhooks all handled by the gateway plugin). */
    private static function stripe_checkout_url(\WC_Order $order): string {
        return $order->get_checkout_payment_url(true);
    }

    public static function get_order(\WP_REST_Request $req) {
        $order = wc_get_order((int) $req['id']);
        if (!$order) return new \WP_Error('not_found', 'Commande introuvable', ['status' => 404]);
        $lines = [];
        foreach ($order->get_items() as $item) {
            $lines[] = ['name' => $item->get_name(), 'qty' => $item->get_quantity(), 'line_ttc' => (float) $item->get_total()];
        }
        return rest_ensure_response([
            'id' => (string) $order->get_id(),
            'status' => self::map_status($order->get_status(), $order->get_meta('_atelier_payment_type')),
            'mode' => $order->get_meta('_atelier_mode'),
            'payment_type' => $order->get_meta('_atelier_payment_type'),
            'total_ttc' => (float) $order->get_total(),
            'total_tva' => (float) $order->get_total_tax(),
            'lines' => $lines,
        ]);
    }

    private static function map_status(string $wc, string $paymentType): string {
        return match ($wc) {
            'processing', 'completed' => 'paid',
            'on-hold' => $paymentType === 'deferred' ? 'deferred_billing' : ($paymentType === 'cash' ? 'awaiting_pickup_payment' : 'pending_payment'),
            'pending' => 'pending_payment',
            'failed' => 'payment_failed',
            'cancelled' => 'canceled',
            default => $wc,
        };
    }
}
