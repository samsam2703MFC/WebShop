<?php
/**
 * Real customer accounts — creates/authenticates WordPress users (wp_users)
 * as WooCommerce customers, with bearer-token sessions (the storefront and
 * the API are on different domains, so cookies aren't reliable).
 *
 * Tokens: a random secret is returned to the client on login/register; only
 * its SHA-256 hash is stored in user meta, with an expiry. Subsequent calls
 * send `Authorization: Bearer <token>`.
 */
if (!defined('ABSPATH')) exit;

class Atelier_Auth {

    const TOKEN_META     = '_atelier_auth_token';
    const TOKEN_EXP_META = '_atelier_auth_token_exp';
    const PREF_SHOP_META = '_atelier_preferred_shop';
    const TOKEN_TTL      = 2592000; // 30 days

    /* Issue a fresh token, store only its hash. */
    public static function issue_token(int $user_id): string {
        $token = wp_generate_password(64, false, false);
        update_user_meta($user_id, self::TOKEN_META, hash('sha256', $token));
        update_user_meta($user_id, self::TOKEN_EXP_META, time() + self::TOKEN_TTL);
        return $token;
    }

    /* Resolve the user id from the Authorization bearer token, or null. */
    public static function user_from_request(\WP_REST_Request $req): ?int {
        $auth = $req->get_header('authorization');
        if (!$auth || stripos($auth, 'bearer ') !== 0) return null;
        $token = trim(substr($auth, 7));
        if ($token === '') return null;
        $users = get_users([
            'meta_key' => self::TOKEN_META, 'meta_value' => hash('sha256', $token),
            'number' => 1, 'fields' => 'ID',
        ]);
        if (!$users) return null;
        $uid = (int) $users[0];
        $exp = (int) get_user_meta($uid, self::TOKEN_EXP_META, true);
        if ($exp && $exp < time()) return null;
        return $uid;
    }

    /* Shape a user for the storefront (matches WSAuth's expected fields). */
    public static function user_payload(int $uid): array {
        $u = get_userdata($uid);
        $c = new \WC_Customer($uid);
        return [
            'id'              => (string) $uid,
            'email'           => $u ? $u->user_email : '',
            'firstName'       => $c->get_first_name() ?: get_user_meta($uid, 'first_name', true),
            'lastName'        => $c->get_last_name()  ?: get_user_meta($uid, 'last_name', true),
            'phone'           => $c->get_billing_phone() ?: null,
            'company'         => $c->get_billing_company() ?: null,
            'postalCode'      => $c->get_billing_postcode() ?: null,
            'isBusiness'      => (bool) get_user_meta($uid, '_atelier_is_business', true),
            'officeId'        => get_user_meta($uid, '_atelier_office_id', true) ?: null,
            'preferredShopId' => get_user_meta($uid, self::PREF_SHOP_META, true) ?: null,
        ];
    }

    /* ── endpoints ─────────────────────────────────────────────────── */

    public static function register(\WP_REST_Request $req) {
        $b = $req->get_json_params() ?: [];
        $email = sanitize_email($b['email'] ?? '');
        if (!is_email($email)) return new \WP_Error('email', 'Email invalide.', ['status' => 400]);
        if (strlen((string) ($b['password'] ?? '')) < 6) return new \WP_Error('pwd', 'Mot de passe trop court (min. 6).', ['status' => 400]);
        if (email_exists($email)) return new \WP_Error('exists', 'Un compte existe déjà avec cet email.', ['status' => 409]);

        $uid = wc_create_new_customer($email, '', $b['password'], [
            'first_name' => sanitize_text_field($b['firstName'] ?? ''),
            'last_name'  => sanitize_text_field($b['lastName'] ?? ''),
        ]);
        if (is_wp_error($uid)) return new \WP_Error('reg', $uid->get_error_message(), ['status' => 400]);

        $c = new \WC_Customer($uid);
        $c->set_first_name(sanitize_text_field($b['firstName'] ?? ''));
        $c->set_last_name(sanitize_text_field($b['lastName'] ?? ''));
        $c->save();

        return ['user' => self::user_payload($uid), 'token' => self::issue_token($uid)];
    }

    public static function login(\WP_REST_Request $req) {
        $b = $req->get_json_params() ?: [];
        $user = wp_authenticate(trim($b['email'] ?? ''), (string) ($b['password'] ?? ''));
        if (is_wp_error($user)) return new \WP_Error('auth', 'Identifiants incorrects.', ['status' => 401]);
        return ['user' => self::user_payload($user->ID), 'token' => self::issue_token($user->ID)];
    }

    public static function me(\WP_REST_Request $req) {
        $uid = self::user_from_request($req);
        if (!$uid) return new \WP_Error('unauth', 'Non connecté.', ['status' => 401]);
        return ['user' => self::user_payload($uid)];
    }

    public static function update_me(\WP_REST_Request $req) {
        $uid = self::user_from_request($req);
        if (!$uid) return new \WP_Error('unauth', 'Non connecté.', ['status' => 401]);
        $b = $req->get_json_params() ?: [];
        $c = new \WC_Customer($uid);
        if (isset($b['firstName'])) $c->set_first_name(sanitize_text_field($b['firstName']));
        if (isset($b['lastName']))  $c->set_last_name(sanitize_text_field($b['lastName']));
        if (isset($b['phone']))     $c->set_billing_phone(sanitize_text_field($b['phone']));
        if (isset($b['company']))   $c->set_billing_company(sanitize_text_field($b['company']));
        if (isset($b['postalCode'])) $c->set_billing_postcode(sanitize_text_field($b['postalCode']));
        $c->save();
        if (isset($b['isBusiness']))      update_user_meta($uid, '_atelier_is_business', $b['isBusiness'] ? 1 : 0);
        if (isset($b['preferredShopId'])) update_user_meta($uid, self::PREF_SHOP_META, sanitize_text_field($b['preferredShopId']));
        return ['user' => self::user_payload($uid)];
    }

    public static function logout(\WP_REST_Request $req) {
        $uid = self::user_from_request($req);
        if ($uid) {
            delete_user_meta($uid, self::TOKEN_META);
            delete_user_meta($uid, self::TOKEN_EXP_META);
        }
        return ['ok' => true];
    }

    public static function password_reset(\WP_REST_Request $req) {
        $b = $req->get_json_params() ?: [];
        $user = get_user_by('email', sanitize_email($b['email'] ?? ''));
        if ($user) retrieve_password($user->user_login); // sends the WP reset email
        return ['ok' => true]; // never reveal whether the email exists
    }
}
