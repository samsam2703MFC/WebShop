<?php
/**
 * Franchise Buddy (ERP) client — reads reference data (shops, and later
 * products/stock) from the general system at atelierby.tfbuddy.com.
 *
 * The request runs server-side from WordPress (which can reach the ERP),
 * results are cached briefly, and callers fall back to WooCommerce data if
 * the ERP is unreachable. Field mapping is defensive (tries common key
 * names) so it works across ERP field naming; adjust map_shop() once the
 * exact shape is confirmed.
 *
 * Config (options, set via admin/WP-CLI — never hardcode secrets):
 *   atelier_erp_base_url   default https://atelierby.tfbuddy.com/api/v1
 *   atelier_erp_api_key    optional bearer token if the API requires auth
 */
if (!defined('ABSPATH')) exit;

class Atelier_ERP {

    const BASE_OPT  = 'atelier_erp_base_url';
    const KEY_OPT   = 'atelier_erp_api_key';
    const CACHE_TTL = 300; // 5 min

    public static function base(): string {
        return rtrim((string) get_option(self::BASE_OPT, 'https://atelierby.tfbuddy.com/api/v1'), '/');
    }

    /* GET a path from the ERP, decoded, or null on any failure. */
    public static function get(string $path) {
        $url  = self::base() . '/' . ltrim($path, '/');
        $args = ['timeout' => 12, 'headers' => ['Accept' => 'application/json']];
        $key  = get_option(self::KEY_OPT);
        if ($key) $args['headers']['Authorization'] = 'Bearer ' . $key;

        $resp = wp_remote_get($url, $args);
        if (is_wp_error($resp)) return null;
        if ((int) wp_remote_retrieve_response_code($resp) !== 200) return null;
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        return is_array($body) ? $body : null;
    }

    /* First non-empty value among candidate keys. */
    private static function pick(array $row, array $keys, $default = null) {
        foreach ($keys as $k) {
            if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) return $row[$k];
        }
        return $default;
    }

    /* Normalize the ERP response to a list (handles DRF {results:[...]}). */
    private static function as_list($data): array {
        if (!is_array($data)) return [];
        if (isset($data['results']) && is_array($data['results'])) return $data['results'];
        if (isset($data['data']) && is_array($data['data'])) return $data['data'];
        return array_is_list($data) ? $data : [$data];
    }

    /* Compose the display address from Franchise Buddy's split fields:
       street + street_num, city (e.g. "Rue du Page 33, Ixelles").
       Falls back to a single address-like field when the split ones are absent. */
    private static function shop_address(array $s): string {
        $street = trim((string) self::pick($s, ['street', 'rue'], ''));
        $num    = trim((string) self::pick($s, ['street_num', 'street_number', 'num'], ''));
        $city   = trim((string) self::pick($s, ['city', 'ville', 'commune'], ''));
        $line   = trim($street . ' ' . $num);
        if ($line !== '' || $city !== '') {
            return $line !== '' && $city !== '' ? $line . ', ' . $city : ($line !== '' ? $line : $city);
        }
        return (string) self::pick($s, ['address', 'adresse', 'full_address', 'location'], '');
    }

    /* Map one ERP shop → the storefront shop shape (WSShops). */
    public static function map_shop($s): ?array {
        if (!is_array($s)) return null;
        $id = self::pick($s, ['id', 'code', 'slug', 'shop_id', 'uuid', 'reference']);
        if ($id === null) return null;
        return [
            'id'            => (string) $id,
            'name'          => (string) self::pick($s, ['representative_name', 'name', 'enseigne', 'nom', 'title', 'label'], 'Boutique'),
            'address'       => self::shop_address($s),
            'accent'        => (string) self::pick($s, ['accent', 'color', 'couleur', 'brand_color'], '#8D1D2C'),
            'click_collect' => (bool)   self::pick($s, ['click_collect', 'click_and_collect', 'collect', 'pickup'], true),
        ];
    }

    /* Real shop list from the ERP (cached), or null if unreachable. */
    public static function shops(): ?array {
        $cached = get_transient('atelier_erp_shops');
        if (is_array($cached)) return $cached;

        $data = self::get('shops/');
        if ($data === null) return null; // caller falls back to WooCommerce

        $shops = array_values(array_filter(array_map([self::class, 'map_shop'], self::as_list($data))));
        set_transient('atelier_erp_shops', $shops, self::CACHE_TTL);
        return $shops;
    }

    public static function flush_cache(): void {
        delete_transient('atelier_erp_shops');
    }
}
