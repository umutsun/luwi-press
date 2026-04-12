<?php
/**
 * LuwiPress HMAC Webhook Verification
 *
 * Provides HMAC-SHA256 signing and verification for webhook payloads.
 * Used for both outbound (WP to n8n) and inbound (n8n to WP) webhook security.
 *
 * @package LuwiPress
 * @since 1.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * HMAC Verification Class
 *
 * @since 1.1.0
 */
class LuwiPress_HMAC {

    /**
     * Signature header name
     *
     * @since 1.1.0
     * @var string
     */
    const SIGNATURE_HEADER = 'X-LuwiPress-Signature';

    /**
     * Timestamp header name for replay protection
     *
     * @since 1.1.0
     * @var string
     */
    const TIMESTAMP_HEADER = 'X-LuwiPress-Timestamp';

    /**
     * Maximum age of a signed request in seconds (5 minutes)
     *
     * @since 1.1.0
     * @var int
     */
    const MAX_AGE = 300;

    /**
     * Option key for the shared secret
     *
     * @since 1.1.0
     * @var string
     */
    const SECRET_OPTION = 'luwipress_hmac_secret';

    /**
     * Generate HMAC-SHA256 signature for a payload
     *
     * @since 1.1.0
     * @param string $payload   JSON-encoded payload
     * @param string $secret    Shared secret key
     * @param int    $timestamp Unix timestamp for replay protection
     * @return string Hex-encoded HMAC signature
     */
    public static function sign($payload, $secret, $timestamp = null) {
        if (null === $timestamp) {
            $timestamp = time();
        }

        $message = $timestamp . '.' . $payload;
        return hash_hmac('sha256', $message, $secret);
    }

    /**
     * Verify HMAC-SHA256 signature (timing-safe)
     *
     * @since 1.1.0
     * @param string $payload   JSON-encoded payload
     * @param string $signature Signature to verify
     * @param string $secret    Shared secret key
     * @param int    $timestamp Request timestamp
     * @return bool True if signature is valid
     */
    public static function verify($payload, $signature, $secret, $timestamp = null) {
        if (empty($payload) || empty($signature) || empty($secret)) {
            return false;
        }

        // Check replay protection if timestamp provided
        if (null !== $timestamp) {
            $age = abs(time() - intval($timestamp));
            if ($age > self::MAX_AGE) {
                return false;
            }
        }

        $expected = self::sign($payload, $secret, $timestamp);
        return hash_equals($expected, $signature);
    }

    /**
     * Add HMAC signature headers to an outbound request
     *
     * @since 1.1.0
     * @param array  $headers Existing headers array (passed by reference)
     * @param string $payload JSON-encoded payload body
     * @param string $secret  Shared secret (if empty, reads from options)
     * @return array Modified headers
     */
    public static function add_signature_headers(&$headers, $payload, $secret = '') {
        if (empty($secret)) {
            $secret = get_option(self::SECRET_OPTION, '');
        }

        if (empty($secret)) {
            return $headers;
        }

        $timestamp = time();
        $signature = self::sign($payload, $secret, $timestamp);

        $headers[self::SIGNATURE_HEADER] = $signature;
        $headers[self::TIMESTAMP_HEADER] = (string) $timestamp;

        return $headers;
    }

    /**
     * Verify an inbound request using HMAC headers
     *
     * @since 1.1.0
     * @param string $payload Raw request body
     * @param string $secret  Shared secret (if empty, reads from options)
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function verify_request($payload, $secret = '') {
        if (empty($secret)) {
            $secret = get_option(self::SECRET_OPTION, '');
        }

        // If no secret configured, skip HMAC verification (backward compatible)
        if (empty($secret)) {
            return true;
        }

        $signature = self::get_request_header(self::SIGNATURE_HEADER);
        $timestamp = self::get_request_header(self::TIMESTAMP_HEADER);

        if (empty($signature)) {
            return new WP_Error(
                'hmac_missing_signature',
                'Missing HMAC signature header',
                ['status' => 401]
            );
        }

        $ts = !empty($timestamp) ? intval($timestamp) : null;

        if (!self::verify($payload, $signature, $secret, $ts)) {
            return new WP_Error(
                'hmac_invalid_signature',
                'Invalid HMAC signature',
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * Get a request header value
     *
     * @since 1.1.0
     * @param string $header_name Header name
     * @return string|null Header value or null
     */
    private static function get_request_header($header_name) {
        // Try standard $_SERVER approach
        $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $header_name));
        if (isset($_SERVER[$server_key])) {
            return sanitize_text_field(wp_unslash($_SERVER[$server_key]));
        }

        // Try getallheaders() as fallback
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $value) {
                if (strcasecmp($key, $header_name) === 0) {
                    return sanitize_text_field($value);
                }
            }
        }

        return null;
    }

    /**
     * Generate a random secret key
     *
     * @since 1.1.0
     * @param int $length Length of the secret (default 64)
     * @return string Random secret key
     */
    public static function generate_secret($length = 64) {
        return base64_encode(random_bytes((int) ceil($length / 1.33)));
    }

    /**
     * Auto-generate and store a secret if not already set
     *
     * Should be called on plugin activation.
     *
     * @since 1.1.0
     * @return string The HMAC secret
     */
    public static function ensure_secret($force = false) {
        $secret = get_option(self::SECRET_OPTION, '');
        if (empty($secret) || $force) {
            $secret = self::generate_secret();
            update_option(self::SECRET_OPTION, $secret);
        }
        return $secret;
    }
}
