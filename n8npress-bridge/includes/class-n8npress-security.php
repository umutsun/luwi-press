<?php
/**
 * Security class for n8nPress plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class N8nPress_Security {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (get_option('n8npress_security_headers', 1)) {
            add_action('send_headers', array($this, 'add_security_headers'));
        }
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
     * Check if the current request IP is within the whitelist.
     * Returns true (allowed) if no whitelist is configured.
     *
     * @param string $ip  Defaults to REMOTE_ADDR
     * @return bool
     */
    public static function check_ip_whitelist($ip = null) {
        $whitelist_raw = get_option('n8npress_ip_whitelist', '');

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
        $limit = (int) get_option('n8npress_rate_limit', 1000);

        if ($limit <= 0) {
            return true;
        }

        if (null === $ip) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
        }

        $transient_key = 'n8npress_rl_' . md5($ip);
        $count         = (int) get_transient($transient_key);

        if ($count >= $limit) {
            N8nPress_Logger::log(
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
