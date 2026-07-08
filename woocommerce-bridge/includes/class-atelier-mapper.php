<?php
/**
 * Maps WooCommerce objects to the exact field shapes the React front-end
 * reads (see DATA_SHAPES.md). The front-end is untouched, so these shapes
 * must match the WSXxx contracts byte-for-byte.
 */
if (!defined('ABSPATH')) exit;

class Atelier_Mapper {

    /* WC_Product -> WSCatalog product row.
       vat_rate is derived from the product's tax class (6 reduit / 21 standard). */
    public static function product(\WC_Product $p): array {
        $cats = $p->get_category_ids();
        $catSlug = '';
        if ($cats) {
            $term = get_term($cats[0], 'product_cat');
            $catSlug = $term && !is_wp_error($term) ? $term->slug : '';
        }
        return [
            'id'               => $p->get_id(),
            'cat'              => $catSlug,
            'name'             => $p->get_name(),
            'description'      => wp_strip_all_tags($p->get_short_description() ?: $p->get_description()),
            'price'            => (float) wc_get_price_including_tax($p),
            'vat_rate'         => self::vat_rate($p),
            'img'              => wp_get_attachment_url($p->get_image_id()) ?: null,
            'allergens'        => self::meta_json($p, '_atelier_allergens'),
            'portions'         => (bool) $p->get_meta('_atelier_portions'),
            'crossPortion'     => (bool) $p->get_meta('_atelier_cross_portion'),
            'has_menu_options' => (bool) $p->get_meta('_atelier_menu_options'),
            'no_delivery'      => (bool) $p->get_meta('_atelier_no_delivery'),
            'delivery_stock'   => self::nullable_int($p->get_meta('_atelier_delivery_stock')),
            'lead_time'        => (int) $p->get_meta('_atelier_lead_time'),
        ];
    }

    /* Belgian VAT from WC tax class. Reduced classes map to 6, everything else 21. */
    public static function vat_rate(\WC_Product $p): float {
        $class = $p->get_tax_class(); // '' = standard, 'reduit-6' = reduced
        $rates = \WC_Tax::get_rates($class);
        if ($rates) {
            $rate = reset($rates);
            return round((float) $rate['rate'], 2);
        }
        return $class === '' ? 21.0 : 6.0;
    }

    /* WC term -> WSCatalog category. */
    public static function category(\WP_Term $t): array {
        $img_id = get_term_meta($t->term_id, 'thumbnail_id', true);
        return [
            'id'    => $t->slug,
            'label' => $t->name,
            'img'   => $img_id ? wp_get_attachment_url($img_id) : null,
        ];
    }

    /* WC store -> WSShops. Single-store WooCommerce reports one shop from
       store settings; multi-store setups can extend this to read a CPT. */
    public static function shops(): array {
        return [[
            'id'      => 'chatelain',
            'name'    => get_bloginfo('name'),
            'address' => trim(get_option('woocommerce_store_address', '') . ', ' . get_option('woocommerce_store_city', '')),
            'accent'  => '#8D1D2C',
            'click_collect' => true,
        ]];
    }

    private static function meta_json(\WC_Product $p, string $key) {
        $raw = $p->get_meta($key);
        if (!$raw) return null;
        $decoded = json_decode($raw, true);
        return $decoded ?: null;
    }
    private static function nullable_int($v) {
        return ($v === '' || $v === null) ? null : (int) $v;
    }
}
