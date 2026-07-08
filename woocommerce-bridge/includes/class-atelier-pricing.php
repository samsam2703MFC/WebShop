<?php
/**
 * Server-side pricing + delivery-fee resolution — the single source of
 * truth for money. Client-sent prices/totals are NEVER trusted (same rule
 * as the Node reference backend). Ports pricing.js to PHP.
 */
if (!defined('ABSPATH')) exit;

class Atelier_Pricing {

    private static function r2(float $n): float { return round($n, 2); }

    /* VAT split of a TTC amount at a given rate. */
    public static function vat_split(float $ttc, float $rate): array {
        $htva = self::r2($ttc / (1 + $rate / 100));
        return ['htva' => $htva, 'tva' => self::r2($ttc - $htva)];
    }

    /* Delivery fee priority: site -> office -> tour -> shop -> global. */
    public static function resolve_delivery_fee(array $ctx): array {
        global $wpdb;
        $t = $wpdb->prefix . 'atelier_fee_rules';
        $levels = [
            ['site',   'site_id',          $ctx['siteId'] ?? null],
            ['office', 'office_client_id', $ctx['officeClientId'] ?? null],
            ['tour',   'tour_id',          $ctx['tourneeId'] ?? null],
            ['shop',   'shop_id',          $ctx['shopId'] ?? null],
        ];
        $subtotal = (float) ($ctx['subtotal'] ?? 0);
        $rule = null; $level = 'global';
        foreach ($levels as [$lvl, $col, $val]) {
            if (!$val) continue;
            $rule = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $t WHERE level = %s AND $col = %s AND active = 1 LIMIT 1", $lvl, $val
            ), ARRAY_A);
            if ($rule) { $level = $lvl; break; }
        }
        if (!$rule) {
            $rule = $wpdb->get_row("SELECT * FROM $t WHERE level = 'global' AND active = 1 LIMIT 1", ARRAY_A)
                ?: ['free_delivery' => 0, 'always_charge' => 0, 'fee_amount' => 0, 'free_delivery_minimum' => 0, 'payment_type' => 'immediate'];
        }
        $fee = 0.0;
        if (!$rule['free_delivery']) {
            if ($rule['always_charge']) $fee = (float) $rule['fee_amount'];
            elseif ($subtotal < (float) $rule['free_delivery_minimum']) $fee = (float) $rule['fee_amount'];
        }
        $min = (float) $rule['free_delivery_minimum'];
        return [
            'fee_amount'                => self::r2($fee),
            'free_delivery'             => (bool) $rule['free_delivery'],
            'always_charge'             => (bool) $rule['always_charge'],
            'free_delivery_minimum'     => $min,
            'amount_remaining_for_free' => ($fee > 0 && $min > 0) ? self::r2(max(0, $min - $subtotal)) : 0,
            'payment_type'              => $rule['payment_type'] ?: 'immediate',
            'resolved_level'            => $level,
        ];
    }

    /* Validate a WooCommerce coupon as a WSVouchers voucher. */
    public static function apply_voucher(?string $code, float $subtotal): array {
        if (!$code) return ['ok' => false, 'message' => null];
        $id = wc_get_coupon_id_by_code($code);
        if (!$id) return ['ok' => false, 'message' => 'Code invalide'];
        $c = new \WC_Coupon($id);
        if ($c->get_date_expires() && $c->get_date_expires()->getTimestamp() < time())
            return ['ok' => false, 'message' => 'Code expiré'];
        if ($c->get_usage_limit() && $c->get_usage_count() >= $c->get_usage_limit())
            return ['ok' => false, 'message' => 'Code épuisé'];
        if ($c->get_minimum_amount() && $subtotal < (float) $c->get_minimum_amount())
            return ['ok' => false, 'message' => 'Minimum de commande €' . number_format((float) $c->get_minimum_amount(), 2)];
        $discount = $c->get_discount_type() === 'percent'
            ? self::r2($subtotal * (float) $c->get_amount() / 100)
            : min(self::r2((float) $c->get_amount()), $subtotal);
        return ['ok' => true, 'discount' => $discount,
                'voucher' => ['code' => $c->get_code(), 'kind' => $c->get_discount_type() === 'percent' ? 'percent' : 'amount', 'value' => (float) $c->get_amount()]];
    }

    /* Full server-side quote. $basket = [['productId'=>, 'qty'=>, ...], ...]. */
    public static function quote(array $args): array {
        $shopId = $args['shopId'] ?? null;
        $mode = $args['mode'] ?? 'collect';
        $basket = $args['basket'] ?? [];
        if (!$basket) throw new Exception('Panier vide', 400);
        if (!in_array($mode, ['collect', 'delivery'], true)) throw new Exception('Mode invalide', 400);

        $lines = []; $subtotal = 0.0;
        foreach ($basket as $item) {
            $qty = max(1, min(99, (int) ($item['qty'] ?? 1)));
            $p = wc_get_product((int) ($item['productId'] ?? 0));
            if (!$p || $p->get_status() !== 'publish') throw new Exception('Produit indisponible', 422);
            if ($mode === 'delivery' && $p->get_meta('_atelier_no_delivery'))
                throw new Exception('« ' . $p->get_name() . ' » est en retrait seulement', 422);
            $stock = $p->get_meta('_atelier_delivery_stock');
            if ($mode === 'delivery' && $stock !== '' && $qty > (int) $stock)
                throw new Exception('Stock livraison insuffisant pour « ' . $p->get_name() . ' »', 422);

            $unit = (float) wc_get_price_including_tax($p);
            $lineTtc = self::r2($unit * $qty);
            $vat = Atelier_Mapper::vat_rate($p);
            $split = self::vat_split($lineTtc, $vat);
            $lines[] = [
                'productId' => $p->get_id(), 'name' => $p->get_name(), 'qty' => $qty,
                'portion' => $item['portion'] ?? null, 'options' => $item['options'] ?? [],
                'unit_price_ttc' => $unit, 'vat_rate' => $vat,
                'line_ttc' => $lineTtc, 'line_htva' => $split['htva'], 'line_tva' => $split['tva'],
            ];
            $subtotal = self::r2($subtotal + $lineTtc);
        }

        $discount = 0.0; $discounts = [];
        $voucher = self::apply_voucher($args['voucherCode'] ?? null, $subtotal);
        if (($args['voucherCode'] ?? null) && !$voucher['ok']) throw new Exception($voucher['message'] ?: 'Code invalide', 422);
        if ($voucher['ok']) {
            $discount = self::r2($discount + $voucher['discount']);
            $discounts[] = ['label' => 'Code ' . $voucher['voucher']['code'], 'amount' => $voucher['discount']];
        }

        $deliveryFee = null; $feeAmount = 0.0;
        if ($mode === 'delivery') {
            $ctx = ($args['officeContext'] ?? []) + ['shopId' => $shopId, 'subtotal' => $subtotal];
            $deliveryFee = self::resolve_delivery_fee($ctx);
            $feeAmount = $deliveryFee['fee_amount'];
        }

        $totalTtc = self::r2(max(0, $subtotal - $discount + $feeAmount));
        $goodsTtc = self::r2(max(0, $subtotal - $discount));
        $ratio = $subtotal > 0 ? $goodsTtc / $subtotal : 0;
        $totalHtva = 0.0; $totalTva = 0.0;
        foreach ($lines as $l) {
            $adj = self::r2($l['line_ttc'] * $ratio);
            $s = self::vat_split($adj, $l['vat_rate']);
            $totalHtva = self::r2($totalHtva + $s['htva']); $totalTva = self::r2($totalTva + $s['tva']);
        }
        if ($feeAmount > 0) {
            $s = self::vat_split($feeAmount, 21);
            $totalHtva = self::r2($totalHtva + $s['htva']); $totalTva = self::r2($totalTva + $s['tva']);
        }

        return [
            'lines' => $lines, 'subtotal_ttc' => $subtotal, 'discounts' => $discounts,
            'discount_ttc' => $discount, 'voucher' => $voucher['ok'] ? $voucher['voucher'] : null,
            'delivery_fee' => $deliveryFee, 'total_ttc' => $totalTtc,
            'total_htva' => $totalHtva, 'total_tva' => $totalTva, 'currency' => 'EUR',
        ];
    }
}
