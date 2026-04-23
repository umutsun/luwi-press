<?php
/**
 * Security class for LuwiPress plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class LuwiPress_Security {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (get_option('luwipress_security_headers', 1)) {
            add_action('send_headers', array($this, 'add_security_headers'));
        }

        // Always stamp no-store on luwipress/v1 REST responses so upstream caches
        // (LiteSpeed, Varnish, CDN) cannot replay an authenticated body to anon requests.
        add_filter('rest_post_dispatch', array($this, 'stamp_rest_cache_headers'), 10, 3);
    }

    /**
     * Add security headers to every response
     */
    public function add_security_headers() {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
    }

    /**
     * Stamp cache-busting headers on every luwipress/v1 REST response.
     * Without this, LiteSpeed/Varnish/CDN cache authenticated 200 responses
     * by URL and serve them to subsequent unauthenticated requests.
     *
     * @param mixed            $response
     * @param WP_REST_Server   $server
     * @param mixed            $request
     * @return mixed
     */
    public function stamp_rest_cache_headers($response, $server, $request) {
        if (!($response instanceof WP_REST_Response) || !($request instanceof WP_REST_Request)) {
            return $response;
        }

        $route = $request->get_route();
        if (strpos($route, '/luwipress/v1/') !== 0) {
            return $response;
        }

        // Truly public endpoints (no token required, no PII) can stay cacheable.
        $public_routes = array(
            '/luwipress/v1/status',
            '/luwipress/v1/health',
            '/luwipress/v1/chat/config',
        );

        if (in_array($route, $public_routes, true)) {
            return $response;
        }

        $response->header('Cache-Control', 'no-store, private, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');

        // LiteSpeed Cache bypass signals. LS reads these as HTTP response
        // headers — `rest_post_dispatch` runs AFTER PHP has started sending
        // headers for this request, so `header()` is typically a no-op here.
        // The WP_REST_Response->header() API writes into the response's header
        // list which `rest_send_headers()` flushes before LS's caching stage.
        $response->header( 'X-LiteSpeed-Cache-Control', 'no-cache, no-store, private' );
        $response->header( 'X-LiteSpeed-Tag', 'nocache' );
        // Belt-and-braces: also fire the LS plugin hooks so the tag-based
        // plugin cache opts out even when the web-server module misses the
        // headers above.
        if ( did_action( 'init' ) ) {
            do_action( 'litespeed_control_set_nocache', 'luwipress REST authenticated payload' );
            do_action( 'litespeed_control_set_private', 'luwipress REST authenticated payload' );
        }

        // WP Rocket / W3TC / WP Super Cache: DONOTCACHEPAGE constant is the
        // historic opt-out; harmless if the cache plugin isn't installed.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        return $response;
    }

    /**
     * Check if the current request IP is within the whitelist.
     * Returns true (allowed) if no whitelist is configured.
     *
     * @param string $ip  Defaults to REMOTE_ADDR
     * @return bool
     */
    public static function check_ip_whitelist($ip = null) {
        $whitelist_raw = get_option('luwipress_ip_whitelist', '');

        if (empty(trim($whitelist_raw))) {
            return true;
        }

        if (null === $ip) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        }

        $allowed = array_map('trim', explode(',', $whitelist_raw));

        return in_array($ip, $allowed, true);
    }

    /**
     * Check rate limit for the given IP using WordPress transients.
     * Returns true when the request is allowed, false when limit is exceeded.
     *
     * @param string $ip
     * @return bool
     */
    public static function check_rate_limit($ip = null) {
        $limit = (int) get_option('luwipress_rate_limit', 1000);

        if ($limit <= 0) {
            return true;
        }

        if (null === $ip) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
        }

        $transient_key = 'luwipress_rl_' . md5($ip);
        $count         = (int) get_transient($transient_key);

        if ($count >= $limit) {
            LuwiPress_Logger::log(
                'Rate limit exceeded',
                'warning',
                array('ip' => $ip, 'count' => $count, 'limit' => $limit)
            );
            return false;
        }

        if ($count === 0) {
            set_transient($transient_key, 1, HOUR_IN_SECONDS);
        } else {
            set_transient($transient_key, $count + 1, HOUR_IN_SECONDS);
        }

        return true;
    }

    /**
     * Full request validation: IP whitelist + rate limit.
     * Returns WP_Error on failure, true on success.
     *
     * @param string|null $ip
     * @return true|WP_Error
     */
    public static function validate_request($ip = null) {
        if (!self::check_ip_whitelist($ip)) {
            return new WP_Error('ip_blocked', 'Your IP address is not allowed.', array('status' => 403));
        }

        if (!self::check_rate_limit($ip)) {
            return new WP_Error('rate_limit_exceeded', 'Rate limit exceeded. Try again later.', array('status' => 429));
        }

        return true;
    }
}
