<?php
/**
 * VIES VAT validation — calls the official EU VIES REST API server-side
 * (the browser can't, due to CORS) and returns the company's validated
 * name + address so the checkout invoice form can auto-fill.
 *
 * Response shape matches the WSVies stub contract:
 *   { valid:true, data:{ vat, country, name, address, postalCode, city } }
 *   { valid:false }  |  { valid:false, error:{ code:'unavailable' } }
 */
if (!defined('ABSPATH')) exit;

class Atelier_Vies {

    const API = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/';

    public static function check(\WP_REST_Request $req) {
        $country = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $req['country']));
        $vat     = preg_replace('/[^0-9A-Za-z]/', '', (string) $req['vat']);
        // VAT may carry the country prefix (BE0123…) — strip it for the call.
        if (strlen($vat) > 2 && strtoupper(substr($vat, 0, 2)) === $country) $vat = substr($vat, 2);
        if ($country === '' || $vat === '') {
            return rest_ensure_response(['valid' => false, 'error' => ['code' => 'invalid']]);
        }

        $url  = self::API . $country . '/vat/' . rawurlencode($vat);
        $resp = wp_remote_get($url, ['timeout' => 12, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            return rest_ensure_response(['valid' => false, 'error' => ['code' => 'unavailable']]);
        }
        $j = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($j)) {
            return rest_ensure_response(['valid' => false, 'error' => ['code' => 'unavailable']]);
        }

        $valid = !empty($j['valid']) || !empty($j['isValid']);
        if (!$valid) return rest_ensure_response(['valid' => false]);

        $addr = self::parse_address((string) ($j['address'] ?? ''));
        return rest_ensure_response([
            'valid' => true,
            'data'  => [
                'vat'        => $country . $vat,
                'country'    => $country,
                'name'       => self::clean((string) ($j['name'] ?? '')),
                'address'    => $addr['address'],
                'postalCode' => $addr['postalCode'],
                'city'       => $addr['city'],
            ],
        ]);
    }

    private static function clean(string $v): string {
        $v = trim($v);
        return ($v === '---' || $v === '') ? '' : $v; // VIES uses '---' when hidden
    }

    /* Split a VIES address string into street / postcode / city.
       Handles multi-line ("STREET 1\n1050 CITY") and comma forms. */
    private static function parse_address(string $raw): array {
        $out = ['address' => '', 'postalCode' => '', 'city' => ''];
        $raw = trim($raw);
        if ($raw === '' || $raw === '---') return $out;

        $flat = trim(preg_replace('/\s*[\r\n]+\s*/', ', ', $raw)); // newlines → ", "
        // Postcode (4–6 digits) followed by a city name.
        if (preg_match('/(?:^|,\s*)([0-9]{4,6})\s+([^,]+?)(?:,|$)/u', $flat, $m, PREG_OFFSET_CAPTURE)) {
            $out['postalCode'] = $m[1][0];
            $out['city']       = trim($m[2][0]);
            $out['address']    = trim(substr($flat, 0, $m[0][1]), " ,");
        } else {
            $out['address'] = $flat;
        }
        return $out;
    }
}
