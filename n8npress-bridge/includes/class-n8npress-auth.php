<?php
/**
 * n8nPress Authentication Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class N8nPress_Auth {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
        add_filter('jwt_auth_token_before_dispatch', array($this, 'add_custom_claims'), 10, 2);
    }
    
    public function init() {
        // Register JWT auth endpoints if not already registered
        if (!$this->is_jwt_plugin_active()) {
            add_action('rest_api_init', array($this, 'register_jwt_endpoints'));
        }
    }
    
    private function is_jwt_plugin_active() {
        return function_exists('firebase\jwt\JWT::decode') || class_exists('Firebase\JWT\JWT');
    }
    
    public function register_jwt_endpoints() {
        // Token generation endpoint
        register_rest_route('jwt-auth/v1', '/token', array(
            'methods' => 'POST',
            'callback' => array($this, 'generate_token'),
            'permission_callback' => '__return_true',
            'args' => array(
                'username' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_user'
                ),
                'password' => array(
                    'required' => true,
                    'type' => 'string'
                )
            )
        ));
        
        // Token validation endpoint
        register_rest_route('jwt-auth/v1', '/token/validate', array(
            'methods' => 'POST',
            'callback' => array($this, 'validate_token_endpoint'),
            'permission_callback' => '__return_true'
        ));
        
        // Token refresh endpoint
        register_rest_route('jwt-auth/v1', '/token/refresh', array(
            'methods' => 'POST',
            'callback' => array($this, 'refresh_token'),
            'permission_callback' => '__return_true'
        ));
    }
    
    public function generate_token($request) {
        $username = $request->get_param('username');
        $password = $request->get_param('password');
        
        // Authenticate user
        $user = wp_authenticate($username, $password);
        
        if (is_wp_error($user)) {
            return new WP_Error(
                'invalid_credentials',
                'Invalid username or password',
                array('status' => 401)
            );
        }
        
        // Check if user has required capabilities
        if (!user_can($user, 'publish_posts')) {
            return new WP_Error(
                'insufficient_permissions',
                'User does not have sufficient permissions',
                array('status' => 403)
            );
        }
        
        // Generate JWT token
        $token_data = $this->create_jwt_token($user);
        
        // Log token generation
        N8nPress_Logger::log('JWT token generated', 'info', array(
            'user_id' => $user->ID,
            'username' => $user->user_login,
            'expires' => $token_data['expires']
        ));
        
        return array(
            'token' => $token_data['token'],
            'user_id' => $user->ID,
            'user_login' => $user->user_login,
            'user_nicename' => $user->user_nicename,
            'user_email' => $user->user_email,
            'user_display_name' => $user->display_name,
            'expires' => $token_data['expires'],
            'expires_in' => $token_data['expires_in']
        );
    }
    
    public function validate_token_endpoint($request) {
        $auth_header = $request->get_header('authorization');
        
        if (empty($auth_header)) {
            return new WP_Error(
                'missing_token',
                'Authorization token is required',
                array('status' => 401)
            );
        }
        
        // Extract token from header
        $token = str_replace('Bearer ', '', $auth_header);
        
        $user = $this->validate_token($token);
        
        if (is_wp_error($user)) {
            return $user;
        }
        
        return array(
            'code' => 'jwt_auth_valid_token',
            'data' => array(
                'status' => 200,
                'user_id' => $user->ID,
                'user_login' => $user->user_login,
                'user_email' => $user->user_email
            )
        );
    }
    
    public function refresh_token($request) {
        $auth_header = $request->get_header('authorization');
        
        if (empty($auth_header)) {
            return new WP_Error(
                'missing_token',
                'Authorization token is required',
                array('status' => 401)
            );
        }
        
        $token = str_replace('Bearer ', '', $auth_header);
        
        // Validate current token
        $user = $this->validate_token($token, false); // Don't check expiration for refresh
        
        if (is_wp_error($user)) {
            return $user;
        }
        
        // Generate new token
        $token_data = $this->create_jwt_token($user);
        
        N8nPress_Logger::log('JWT token refreshed', 'info', array(
            'user_id' => $user->ID,
            'expires' => $token_data['expires']
        ));
        
        return array(
            'token' => $token_data['token'],
            'expires' => $token_data['expires'],
            'expires_in' => $token_data['expires_in']
        );
    }
    
    public static function validate_token($token, $check_expiration = true) {
        if (empty($token)) {
            return new WP_Error(
                'missing_token',
                'JWT token is required',
                array('status' => 401)
            );
        }
        
        try {
            $secret_key = self::get_secret_key();
            
            if (!$secret_key) {
                return new WP_Error(
                    'jwt_secret_missing',
                    'JWT secret key is not configured',
                    array('status' => 500)
                );
            }
            
            // Decode token
            if (class_exists('Firebase\JWT\JWT')) {
                $decoded = Firebase\JWT\JWT::decode($token, new Firebase\JWT\Key($secret_key, 'HS256'));
            } elseif (function_exists('firebase\jwt\JWT::decode')) {
                $decoded = firebase\jwt\JWT::decode($token, $secret_key, array('HS256'));
            } else {
                return new WP_Error(
                    'jwt_library_missing',
                    'JWT library is not available',
                    array('status' => 500)
                );
            }
            
            // Check token expiration
            if ($check_expiration && isset($decoded->exp) && $decoded->exp < time()) {
                return new WP_Error(
                    'token_expired',
                    'JWT token has expired',
                    array('status' => 401)
                );
            }
            
            // Check if user still exists
            $user = get_user_by('id', $decoded->data->user->id);
            
            if (!$user) {
                return new WP_Error(
                    'user_not_found',
                    'User not found',
                    array('status' => 401)
                );
            }
            
            // Check if token has been revoked
            $jti = $decoded->jti ?? '';
            if ( $jti && self::is_token_revoked( $jti ) ) {
                return new WP_Error( 'token_revoked', 'Token has been revoked', array( 'status' => 401 ) );
            }

            // Check if user is still active
            if (!user_can($user, 'publish_posts')) {
                return new WP_Error(
                    'insufficient_permissions',
                    'User no longer has sufficient permissions',
                    array('status' => 403)
                );
            }
            
            return $user;
            
        } catch (Exception $e) {
            N8nPress_Logger::log('JWT validation failed: ' . $e->getMessage(), 'debug');
            
            return new WP_Error(
                'token_invalid',
                'Invalid or expired JWT token.',
                array('status' => 401)
            );
        }
    }
    
    private function create_jwt_token($user) {
        $secret_key = self::get_secret_key();
        $issued_at = time();
        $expires_in = apply_filters('n8npress_jwt_expires_in', WEEK_IN_SECONDS);
        $expires_at = $issued_at + $expires_in;
        
        $payload = array(
            'iss' => get_bloginfo('url'),
            'aud' => get_bloginfo('url'),
            'iat' => $issued_at,
            'nbf' => $issued_at,
            'exp' => $expires_at,
            'jti' => bin2hex(random_bytes(16)),
            'data' => array(
                'user' => array(
                    'id' => $user->ID,
                    'login' => $user->user_login,
                    'email' => $user->user_email,
                    'nicename' => $user->user_nicename,
                    'display_name' => $user->display_name
                ),
                'capabilities' => array_keys($user->allcaps),
                'roles' => $user->roles
            )
        );
        
        // Add custom claims
        $payload = apply_filters('n8npress_jwt_payload', $payload, $user);
        
        // Generate token
        if (class_exists('Firebase\JWT\JWT')) {
            $token = Firebase\JWT\JWT::encode($payload, $secret_key, 'HS256');
        } elseif (function_exists('firebase\jwt\JWT::encode')) {
            $token = firebase\jwt\JWT::encode($payload, $secret_key);
        } else {
            throw new Exception('JWT library is not available');
        }
        
        return array(
            'token' => $token,
            'expires' => $expires_at,
            'expires_in' => $expires_in
        );
    }
    
    public static function get_secret_key() {
        $secret = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : false;
        
        if (!$secret) {
            $secret = get_option('n8npress_jwt_secret');
        }
        
        if (!$secret) {
            // Generate a secret if none exists
            $secret = base64_encode(random_bytes(32));
            update_option('n8npress_jwt_secret', $secret);
        }
        
        return $secret;
    }
    
    public function add_custom_claims($token, $user) {
        // Add n8nPress specific claims
        $token['n8npress_version'] = N8NPRESS_VERSION;
        $token['issued_by'] = 'n8npress';
        
        return $token;
    }
    
    public static function create_application_password($user_id, $app_name = 'n8nPress') {
        if (!function_exists('wp_generate_application_password')) {
            return new WP_Error(
                'app_passwords_not_supported',
                'Application passwords are not supported',
                array('status' => 500)
            );
        }
        
        $password_data = wp_generate_application_password($user_id, $app_name);
        
        if (is_wp_error($password_data)) {
            return $password_data;
        }
        
        return array(
            'password' => $password_data[0],
            'uuid' => $password_data[1]['uuid'],
            'app_id' => $password_data[1]['app_id'],
            'name' => $password_data[1]['name'],
            'created' => $password_data[1]['created']
        );
    }
    
    public static function revoke_application_password($user_id, $uuid) {
        if (!function_exists('wp_delete_application_password')) {
            return new WP_Error(
                'app_passwords_not_supported',
                'Application passwords are not supported',
                array('status' => 500)
            );
        }
        
        return wp_delete_application_password($user_id, $uuid);
    }
    
    public static function list_application_passwords($user_id) {
        if (!function_exists('wp_get_application_passwords')) {
            return new WP_Error(
                'app_passwords_not_supported',
                'Application passwords are not supported',
                array('status' => 500)
            );
        }
        
        return wp_get_application_passwords($user_id);
    }
    
    public static function hash_application_password($raw_password) {
        return wp_hash_password($raw_password);
    }
    
    public static function verify_application_password($user, $username, $password) {
        if (!function_exists('wp_authenticate_application_password')) {
            return false;
        }
        return wp_authenticate_application_password($user, $username, $password);
    }

    /**
     * Revoke a JWT token by its jti claim.
     */
    public static function revoke_token( $jti ) {
        $revoked = get_option( 'n8npress_revoked_tokens', array() );
        $revoked[ $jti ] = time();
        // Clean expired entries (older than 7 days — max token lifetime)
        $cutoff = time() - WEEK_IN_SECONDS;
        $revoked = array_filter( $revoked, function ( $ts ) use ( $cutoff ) {
            return $ts > $cutoff;
        } );
        update_option( 'n8npress_revoked_tokens', $revoked );
    }

    /**
     * Check if a token jti is revoked.
     */
    public static function is_token_revoked( $jti ) {
        $revoked = get_option( 'n8npress_revoked_tokens', array() );
        return isset( $revoked[ $jti ] );
    }
}