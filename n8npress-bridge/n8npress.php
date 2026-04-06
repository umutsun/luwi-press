<?php
/**
 * Plugin Name: n8nPress
 * Plugin URI: https://github.com/umutsun/n8npress
 * Description: AI-powered content enrichment, SEO optimization, and translation automation for WooCommerce stores via n8n workflows.
 * Version: 1.7.3
 * Author: Luwi Developments LLC
 * Author URI: https://luwi.dev
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: n8npress
 * Domain Path: /languages
 * Requires at least: 5.6
 * Tested up to: 6.9
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('N8NPRESS_VERSION', '1.7.3');
define('N8NPRESS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('N8NPRESS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('N8NPRESS_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main n8nPress Plugin Class
 */
class N8nPress {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('init', array($this, 'init'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('wp_ajax_n8npress_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_n8npress_scan_opportunities', array($this, 'ajax_scan_opportunities'));
        add_action('wp_ajax_n8npress_get_thin_products', array($this, 'ajax_get_thin_products'));
        add_action('wp_ajax_n8npress_emergency_stop', array($this, 'ajax_emergency_stop'));
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Core infrastructure
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-api.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-auth.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-logger.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-token-tracker.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-settings.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-security.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-hmac.php';

        // Environment detection & bridge services
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-plugin-detector.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-site-config.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-email-proxy.php';

        // Content & automation modules
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-ai-content.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-aeo.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-translation.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-content-scheduler.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-hreflang.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-internal-linker.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-review-analytics.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-open-claw.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-crm-bridge.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-chatwoot.php';

        // Workflow management
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-workflow-templates.php';
        require_once N8NPRESS_PLUGIN_DIR . 'includes/class-n8npress-workflow-tracker.php';
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        $this->create_tables();

        // Set default options
        $this->set_default_options();

        // Create workflow tracking table
        N8nPress_Workflow_Tracker::create_table();

        // Create token usage tracking table
        N8nPress_Token_Tracker::create_table();

        // Generate HMAC secret if not set
        N8nPress_HMAC::ensure_secret();

        // Flush rewrite rules
        flush_rewrite_rules();

        // Log activation
        N8nPress_Logger::log('Plugin activated', 'info');
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log deactivation
        N8nPress_Logger::log('Plugin deactivated', 'info');
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('n8npress', false, dirname(N8NPRESS_PLUGIN_BASENAME) . '/languages');
        
        // Core infrastructure
        N8nPress_API::get_instance();
        N8nPress_Auth::get_instance();
        N8nPress_Security::get_instance();

        // Environment detection & bridge services
        N8nPress_Plugin_Detector::get_instance();
        N8nPress_Site_Config::get_instance();
        N8nPress_Email_Proxy::get_instance();

        // Content & automation modules
        N8nPress_AI_Content::get_instance();
        N8nPress_AI_Content::init_frontend_hooks();
        N8nPress_AEO::get_instance();
        N8nPress_Translation::get_instance();
        N8nPress_Content_Scheduler::get_instance();
        N8nPress_Hreflang::get_instance();
        N8nPress_Internal_Linker::get_instance();
        N8nPress_Review_Analytics::get_instance();
        N8nPress_Open_Claw::get_instance();
        N8nPress_CRM_Bridge::get_instance();
        N8nPress_Chatwoot::get_instance();

        // Chatwoot frontend widget
        add_action( 'wp_footer', array( 'N8nPress_Chatwoot', 'maybe_output_widget' ) );

        // Workflow management
        N8nPress_Workflow_Templates::get_instance();
        N8nPress_Workflow_Tracker::get_instance();
    }
    
    /**
     * Register REST API routes
     *
     * Routes are handled by N8nPress_API::register_endpoints().
     * This hook is kept for backward compatibility / filter use.
     */
    public function register_rest_routes() {
        // Routes delegated to N8nPress_API to avoid duplicate registration conflicts.
        do_action( 'n8npress_register_rest_routes' );
    }
    
    /**
     * Handle webhook requests
     */
    public function handle_webhook($request) {
        $start_time = microtime(true);
        
        try {
            // Get request data
            $data = $request->get_json_params();
            if (empty($data)) {
                $data = $request->get_params();
            }
            
            // Log request
            N8nPress_Logger::log('Webhook request received', 'info', array(
                'action' => $data['action'] ?? 'unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ));
            
            // Validate JWT token
            $auth_result = N8nPress_Auth::validate_token($data['auth_token']);
            if (is_wp_error($auth_result)) {
                return new WP_Error('auth_failed', $auth_result->get_error_message(), array('status' => 401));
            }
            
            // Process action
            $result = $this->process_action($data, $auth_result);
            
            // Calculate execution time
            $execution_time = round((microtime(true) - $start_time) * 1000, 2);
            
            // Return success response
            return array(
                'success' => true,
                'data' => $result,
                'timestamp' => current_time('mysql'),
                'execution_time_ms' => $execution_time,
                'request_id' => $data['request_id'] ?? uniqid('req_')
            );
            
        } catch (Exception $e) {
            N8nPress_Logger::log('Webhook error: ' . $e->getMessage(), 'error');
            
            return new WP_Error(
                'webhook_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }
    
    /**
     * Process webhook action
     */
    private function process_action($data, $user) {
        switch ($data['action']) {
            case 'create_post':
                return $this->create_post($data, $user);
            
            case 'update_post':
                return $this->update_post($data, $user);
            
            case 'delete_post':
                return $this->delete_post($data, $user);
            
            case 'get_posts':
                return $this->get_posts($data, $user);
            
            default:
                throw new Exception('Unknown action: ' . $data['action']);
        }
    }
    
    /**
     * Create WordPress post
     */
    private function create_post($data, $user) {
        // Validate required fields
        if (empty($data['title'])) {
            throw new Exception('Title is required');
        }
        
        // Prepare post data
        $post_data = array(
            'post_title' => sanitize_text_field($data['title']),
            'post_content' => wp_kses_post($data['content'] ?? ''),
            'post_excerpt' => sanitize_text_field($data['excerpt'] ?? ''),
            'post_status' => sanitize_text_field($data['status'] ?? 'draft'),
            'post_type' => sanitize_text_field($data['post_type'] ?? 'post'),
            'post_author' => $user->ID,
            'meta_input' => array()
        );
        
        // Add categories
        if (!empty($data['categories'])) {
            $post_data['post_category'] = array_map('intval', (array) $data['categories']);
        }
        
        // Add custom meta
        if (!empty($data['meta']) && is_array($data['meta'])) {
            foreach ($data['meta'] as $key => $value) {
                $post_data['meta_input'][sanitize_key($key)] = sanitize_text_field($value);
            }
        }
        
        // Insert post
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            throw new Exception('Failed to create post: ' . $post_id->get_error_message());
        }
        
        // Add tags
        if (!empty($data['tags'])) {
            wp_set_post_tags($post_id, $data['tags']);
        }
        
        // Set featured image
        if (!empty($data['featured_media'])) {
            set_post_thumbnail($post_id, intval($data['featured_media']));
        }
        
        // Get created post
        $post = get_post($post_id);
        
        return array(
            'id' => $post_id,
            'title' => $post->post_title,
            'url' => get_permalink($post_id),
            'status' => $post->post_status,
            'date' => $post->post_date
        );
    }
    
    /**
     * Update WordPress post
     */
    private function update_post($data, $user) {
        if (empty($data['id'])) {
            throw new Exception('Post ID is required');
        }
        
        $post_id = intval($data['id']);
        
        // Check if post exists
        if (!get_post($post_id)) {
            throw new Exception('Post not found');
        }
        
        // Prepare update data
        $post_data = array(
            'ID' => $post_id
        );
        
        // Update fields if provided
        if (isset($data['title'])) {
            $post_data['post_title'] = sanitize_text_field($data['title']);
        }
        
        if (isset($data['content'])) {
            $post_data['post_content'] = wp_kses_post($data['content']);
        }
        
        if (isset($data['status'])) {
            $post_data['post_status'] = sanitize_text_field($data['status']);
        }
        
        // Update post
        $result = wp_update_post($post_data, true);
        
        if (is_wp_error($result)) {
            throw new Exception('Failed to update post: ' . $result->get_error_message());
        }
        
        return array(
            'id' => $post_id,
            'updated' => true,
            'url' => get_permalink($post_id)
        );
    }
    
    /**
     * Verify webhook permission
     */
    public function verify_webhook_permission($request) {
        // Allow if JWT token is present (will be validated in handler)
        $data = $request->get_json_params();
        if (empty($data)) {
            $data = $request->get_params();
        }
        
        return !empty($data['auth_token']);
    }
    
    /**
     * Get plugin status
     */
    public function get_status() {
        return array(
            'plugin' => 'n8nPress',
            'version' => N8NPRESS_VERSION,
            'status' => 'active',
            'wordpress_version' => get_bloginfo('version'),
            'rest_api_enabled' => true,
            'jwt_enabled' => class_exists('JWT'),
            'timestamp' => current_time('mysql')
        );
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'n8nPress',
            'n8nPress',
            'manage_options',
            'n8npress',
            array($this, 'admin_page'),
            'dashicons-networking',
            30
        );
        
        add_submenu_page(
            'n8npress',
            'Settings',
            'Settings',
            'manage_options',
            'n8npress-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'n8npress',
            'Open Claw',
            'Open Claw',
            'manage_options',
            'n8npress-claw',
            array($this, 'open_claw_page')
        );

        add_submenu_page(
            'n8npress',
            'Logs',
            'Logs',
            'manage_options',
            'n8npress-logs',
            array($this, 'logs_page')
        );

        add_submenu_page(
            'n8npress',
            'AI Token Usage',
            '💰 Token Usage',
            'manage_options',
            'n8npress-token-usage',
            array($this, 'token_usage_page')
        );
    }
    
    /**
     * Admin page
     */
    public function admin_page() {
        include N8NPRESS_PLUGIN_DIR . 'admin/admin-page.php';
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        include N8NPRESS_PLUGIN_DIR . 'admin/settings-page.php';
    }
    
    /**
     * Open Claw page
     */
    public function open_claw_page() {
        include N8NPRESS_PLUGIN_DIR . 'admin/open-claw-page.php';
    }

    /**
     * Logs page
     */
    public function logs_page() {
        include N8NPRESS_PLUGIN_DIR . 'admin/logs-page.php';
    }

    /**
     * Token Usage page
     */
    public function token_usage_page() {
        include N8NPRESS_PLUGIN_DIR . 'admin/token-dashboard.php';
    }

    /**
     * AJAX: Emergency stop — disables all AI enrichment and sets limit to $0.001
     */
    public function ajax_emergency_stop() {
        check_ajax_referer('n8npress_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        update_option('n8npress_auto_enrich_thin', false);
        update_option('n8npress_auto_enrich', false);
        update_option('n8npress_daily_token_limit', 0.001);
        wp_clear_scheduled_hook('n8npress_auto_enrich_thin_cron');

        N8nPress_Logger::log('Emergency stop activated — all AI enrichment disabled', 'warning', array(
            'user' => get_current_user_id(),
        ));

        wp_send_json_success(array('message' => 'Emergency stop activated. All AI enrichment disabled.'));
    }
    
    /**
     * Admin init
     */
    public function admin_init() {
        N8nPress_Settings::get_instance();

        // Auto-upgrade: ensure tables exist on version change
        $db_version = get_option( 'n8npress_db_version', '0' );
        if ( version_compare( $db_version, N8NPRESS_VERSION, '<' ) ) {
            $this->create_tables();
            N8nPress_Workflow_Tracker::create_table();
            N8nPress_Token_Tracker::create_table();
            update_option( 'n8npress_db_version', N8NPRESS_VERSION );
        }
    }
    
    /**
     * Enqueue scripts
     */
    public function enqueue_scripts() {
        // Frontend scripts if needed
    }
    
    /**
     * Admin enqueue scripts
     */
    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'n8npress') !== false) {
            wp_enqueue_style(
                'n8npress-admin',
                N8NPRESS_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                N8NPRESS_VERSION
            );
            
            wp_enqueue_script(
                'n8npress-admin',
                N8NPRESS_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                N8NPRESS_VERSION,
                true
            );

            $user = wp_get_current_user();
            wp_localize_script('n8npress-admin', 'n8npress', array(
                'ajax_url'     => admin_url('admin-ajax.php'),
                'nonce'        => wp_create_nonce('n8npress_dashboard_nonce'),
                'claw_nonce'   => wp_create_nonce('n8npress_claw_nonce'),
                'user_initial' => mb_strtoupper( mb_substr( $user->display_name, 0, 1 ) ),
            ));
        }
    }
    
    /**
     * AJAX: Test n8n webhook connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('n8npress_settings_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'n8npress'));
        }

        $webhook_url = sanitize_url($_POST['webhook_url'] ?? '');
        if (empty($webhook_url)) {
            wp_send_json_error(__('Webhook URL is required', 'n8npress'));
        }

        // Test by calling a known webhook path (product-enrich) with a ping action
        $test_url = trailingslashit( $webhook_url ) . 'product-enrich';
        $response = wp_remote_post($test_url, array(
            'timeout' => 10,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'action' => 'ping',
                'source' => 'n8npress',
                'site'   => get_site_url(),
                'time'   => current_time('mysql'),
            )),
        ));

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 400) {
            wp_send_json_success(array('status_code' => $code));
        } else {
            wp_send_json_error(sprintf(__('HTTP %d response from n8n', 'n8npress'), $code));
        }
    }

    /**
     * AJAX: Scan content opportunities
     */
    public function ajax_scan_opportunities() {
        check_ajax_referer('n8npress_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $config = N8nPress_Site_Config::get_instance();
        $request = new WP_REST_Request('GET', '/n8npress/v1/content/opportunities');
        $request->set_param('limit', 50);
        $response = $config->get_content_opportunities($request);
        $data = $response->get_data();

        wp_send_json_success(array(
            'missing_seo_meta'     => count($data['missing_seo_meta'] ?? array()),
            'missing_translations' => count($data['missing_translations'] ?? array()),
            'stale_content'        => count($data['stale_content'] ?? array()),
            'thin_content'         => count($data['thin_content'] ?? array()),
            'missing_alt_text'     => count($data['missing_alt_text'] ?? array()),
        ));
    }

    /**
     * AJAX: Get thin product IDs for bulk enrichment
     */
    public function ajax_get_thin_products() {
        check_ajax_referer('n8npress_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $threshold = absint(get_option('n8npress_thin_content_threshold', 300));

        global $wpdb;
        $product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_n8npress_enrich_status'
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'
               AND LENGTH(p.post_content) < %d
               AND (pm.meta_value IS NULL OR pm.meta_value NOT IN ('processing', 'queued'))
             ORDER BY LENGTH(p.post_content) ASC
             LIMIT 50",
            $threshold
        ));

        wp_send_json_success(array('product_ids' => array_map('absint', $product_ids)));
    }

    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Logs table
        $table_name = $wpdb->prefix . 'n8npress_logs';
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            context text,
            ip_address varchar(45),
            user_agent text,
            PRIMARY KEY (id),
            KEY timestamp (timestamp),
            KEY level (level)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * Set default options
     */
    private function set_default_options() {
        $defaults = array(
            'n8npress_enable_logging' => 1,
            'n8npress_log_level' => 'info',
            'n8npress_rate_limit' => 1000,
            'n8npress_security_headers' => 1,
            'n8npress_webhook_timeout' => 30,
            'n8npress_ai_provider' => 'openai',
            'n8npress_ai_model' => 'gpt-4o-mini',
            'n8npress_daily_token_limit' => 1.00,
        );
        
        foreach ($defaults as $option => $value) {
            if (get_option($option) === false) {
                add_option($option, $value);
            }
        }
    }
}

// Initialize plugin
function n8npress_init() {
    return N8nPress::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'n8npress_init');

// Global helper functions
function n8npress_log($message, $level = 'info', $context = array()) {
    if (class_exists('N8nPress_Logger')) {
        N8nPress_Logger::log($message, $level, $context);
    }
}

function n8npress_get_option($option, $default = false) {
    return get_option('n8npress_' . $option, $default);
}

/**
 * Build the standard _meta block sent with every WP→n8n webhook payload.
 *
 * Workflows read $json.body._meta.* so they never need per-site env variables.
 * Only the user's AI API key stays in n8n credentials store.
 *
 * @param string $callback_url  Full REST URL n8n should POST results back to.
 * @return array
 */
function n8npress_build_meta_block( $callback_url = '' ) {
    return array(
        'site_url'     => get_site_url(),
        'rest_base'    => rest_url( 'n8npress/v1/' ),
        'callback_url' => $callback_url,
        'api_token'    => get_option( 'n8npress_seo_api_token', '' ),
        'model'        => get_option( 'n8npress_ai_model', 'gpt-4o-mini' ),
        'max_tokens'   => absint( get_option( 'n8npress_max_output_tokens', 1024 ) ),
        'language'     => get_option( 'n8npress_target_language', 'en' ),
        'site_name'    => get_bloginfo( 'name' ),
        'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
    );
}