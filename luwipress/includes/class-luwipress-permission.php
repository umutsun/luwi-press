<?php
/**
 * N8nPress Permission Manager
 *
 * Centralised authentication and authorisation for all REST API endpoints.
 * Replaces the duplicated check_admin_permission(), check_n8n_token(), and
 * check_permission() methods that were copy-pasted across 11+ classes.
 *
 * Three access levels:
 *  1. is_admin()           — logged-in WP admin (cookie + nonce)
 *  2. check_token()        — Bearer token or X-LuwiPress-Token header
 *  3. check_token_or_admin() — either of the above (most REST endpoints)
 *
 * @package    N8nPress
 * @subpackage Permission
 * @since      1.10.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LuwiPress_Permission {

    /**
     * Check if current user is a WordPress administrator.
     *
     * Used for admin-only endpoints (dashboard, settings, diagnostics).
     *
     * @return bool
     */
    public static function is_admin() {
        return current_user_can( 'manage_options' );
    }

    /**
     * Check if current user can manage WooCommerce.
     *
     * Used for CRM and commerce-specific endpoints.
     *
     * @return bool
     */
    public static function is_wc_manager() {
        return current_user_can( 'manage_woocommerce' );
    }

    /**
     * Validate an API token from the request headers.
     *
     * Accepts two header formats:
     *  - Authorization: Bearer <token>
     *  - X-LuwiPress-Token: <token>
     *
     * Token is compared against the stored luwipress_seo_api_token option
     * using hash_equals() to prevent timing attacks.
     *
     * @param  WP_REST_Request $request
     * @return bool True if valid token found, false otherwise.
     */
    public static function check_token( $request ) {
        $stored = get_option( 'luwipress_seo_api_token', '' );
        if ( empty( $stored ) ) {
            return false;
        }

        // Bearer token
        $auth = $request->get_header( 'authorization' );
        if ( ! empty( $auth ) ) {
            $token = str_replace( 'Bearer ', '', $auth );
            if ( hash_equals( $stored, $token ) ) {
                return true;
            }
        }

        // Custom header
        $custom = $request->get_header( 'x-luwipress-token' );
        if ( ! empty( $custom ) && hash_equals( $stored, $custom ) ) {
            return true;
        }

        return false;
    }

    /**
     * Accept either a valid API token OR a logged-in admin.
     *
     * This is the most common permission check — used by endpoints that
     * n8n workflows call (via token) AND admins use from the dashboard
     * (via cookie auth).
     *
     * @param  WP_REST_Request $request
     * @return bool
     */
    public static function check_token_or_admin( $request ) {
        if ( self::check_token( $request ) ) {
            return true;
        }
        return self::is_admin();
    }

    /**
     * Strict token-only check. Returns WP_Error on failure.
     *
     * Used for callback endpoints that should only be called by n8n,
     * never by browser users (e.g., /product/enrich-callback).
     *
     * @param  WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function require_token( $request ) {
        if ( self::check_token( $request ) ) {
            return true;
        }
        return new WP_Error(
            'luwipress_unauthorized',
            'Valid API token required',
            array( 'status' => 401 )
        );
    }
}
