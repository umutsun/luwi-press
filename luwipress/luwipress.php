<?php
/**
 * Plugin Name: LuwiPress
 * Plugin URI: https://luwi.dev/luwipress
 * Description: AI-powered content enrichment, SEO optimization, and translation automation for WooCommerce stores.
 * Version: 2.0.0
 * Author: Luwi Developments LLC
 * Author URI: https://luwi.dev
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: luwipress
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
define('LUWIPRESS_VERSION', '2.0.0');
define('LUWIPRESS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LUWIPRESS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LUWIPRESS_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main LuwiPress Plugin Class
 */
class LuwiPress {
    
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
        add_action('wp_ajax_luwipress_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_luwipress_scan_opportunities', array($this, 'ajax_scan_opportunities'));
        add_action('wp_ajax_luwipress_get_thin_products', array($this, 'ajax_get_thin_products'));
        add_action('wp_ajax_luwipress_emergency_stop', array($this, 'ajax_emergency_stop'));
        add_action('wp_ajax_luwipress_dashboard_data', array($this, 'ajax_dashboard_data'));
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Core infrastructure
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-permission.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-api.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-auth.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-logger.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-token-tracker.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-settings.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-security.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-hmac.php';

        // Environment detection & bridge services
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-plugin-detector.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-site-config.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-email-proxy.php';

        // AI Engine: provider interface, providers, prompts, dispatcher, image handler
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-ai-provider.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/providers/class-luwipress-provider-anthropic.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/providers/class-luwipress-provider-openai.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/providers/class-luwipress-provider-google.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-prompts.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-ai-engine.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-image-handler.php';

        // Content & automation modules
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-ai-content.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-aeo.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-translation.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-content-scheduler.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-hreflang.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-internal-linker.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-review-analytics.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-open-claw.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-crm-bridge.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-knowledge-graph.php';
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $this->set_default_options();

        // Create all database tables
        LuwiPress_Logger::create_table();
        LuwiPress_Token_Tracker::create_table();

        // Generate HMAC secret if not set
        LuwiPress_HMAC::ensure_secret();

        // Schedule daily cleanup
        if ( ! wp_next_scheduled( 'luwipress_daily_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'luwipress_daily_cleanup' );
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        // Log activation
        LuwiPress_Logger::log('Plugin activated', 'info');
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        wp_clear_scheduled_hook( 'luwipress_daily_cleanup' );
        flush_rewrite_rules();
        LuwiPress_Logger::log('Plugin deactivated', 'info');
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        load_plugin_textdomain('luwipress', false, dirname(LUWIPRESS_PLUGIN_BASENAME) . '/languages');

        // Daily cleanup cron
        add_action( 'luwipress_daily_cleanup', array( $this, 'run_daily_cleanup' ) );

        // Core infrastructure
        LuwiPress_API::get_instance();
        LuwiPress_Auth::get_instance();
        LuwiPress_Security::get_instance();

        // Environment detection & bridge services
        LuwiPress_Plugin_Detector::get_instance();
        LuwiPress_Site_Config::get_instance();
        LuwiPress_Email_Proxy::get_instance();

        // Content & automation modules
        LuwiPress_AI_Content::get_instance();
        LuwiPress_AI_Content::init_frontend_hooks();
        LuwiPress_AEO::get_instance();
        LuwiPress_Translation::get_instance();
        LuwiPress_Content_Scheduler::get_instance();
        LuwiPress_Hreflang::get_instance();
        LuwiPress_Internal_Linker::get_instance();
        LuwiPress_Review_Analytics::get_instance();
        LuwiPress_Open_Claw::get_instance();
        LuwiPress_CRM_Bridge::get_instance();
        LuwiPress_Knowledge_Graph::get_instance();
    }
    
    /**
     * Register REST API routes
     *
     * Routes are handled by LuwiPress_API::register_endpoints().
     * This hook is kept for backward compatibility / filter use.
     */
    public function register_rest_routes() {
        // Routes delegated to LuwiPress_API to avoid duplicate registration conflicts.
        do_action( 'luwipress_register_rest_routes' );
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'LuwiPress',
            'LuwiPress',
            'manage_options',
            'luwipress',
            array($this, 'admin_page'),
            'dashicons-networking',
            30
        );
        
        add_submenu_page(
            'luwipress',
            'Settings',
            'Settings',
            'manage_options',
            'luwipress-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'luwipress',
            'Open Claw',
            'Open Claw',
            'manage_options',
            'luwipress-claw',
            array($this, 'open_claw_page')
        );

        add_submenu_page(
            'luwipress',
            __( 'Usage & Logs', 'luwipress' ),
            __( 'Usage & Logs', 'luwipress' ),
            'manage_options',
            'luwipress-usage',
            array($this, 'usage_page')
        );

        add_submenu_page(
            'luwipress',
            __( 'Knowledge Graph', 'luwipress' ),
            __( 'Knowledge Graph', 'luwipress' ),
            'manage_options',
            'luwipress-knowledge-graph',
            array( $this, 'knowledge_graph_page' )
        );

    }
    
    /**
     * Admin page
     */
    public function admin_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/admin-page.php';
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/settings-page.php';
    }
    
    /**
     * Open Claw page
     */
    public function open_claw_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/open-claw-page.php';
    }

    /**
     * Unified Usage & Logs page
     */
    public function usage_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/usage-page.php';
    }

    /**
     * Knowledge Graph page
     */
    public function knowledge_graph_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/knowledge-graph-page.php';
    }

    /**
     * AJAX: Emergency stop — disables all AI enrichment and sets limit to $0.001
     */
    public function ajax_emergency_stop() {
        check_ajax_referer('luwipress_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        update_option('luwipress_auto_enrich_thin', false);
        update_option('luwipress_auto_enrich', false);
        update_option('luwipress_daily_token_limit', 0.001);
        wp_clear_scheduled_hook('luwipress_auto_enrich_thin_cron');

        LuwiPress_Logger::log('Emergency stop activated — all AI enrichment disabled', 'warning', array(
            'user' => get_current_user_id(),
        ));

        wp_send_json_success(array('message' => 'Emergency stop activated. All AI enrichment disabled.'));
    }

    /**
     * Daily cleanup: remove old logs, token records, and expired transients.
     */
    public function run_daily_cleanup() {
        $log_days   = absint( get_option( 'luwipress_log_retention_days', 30 ) );
        $token_days = absint( get_option( 'luwipress_token_retention_days', 90 ) );

        if ( method_exists( 'LuwiPress_Logger', 'cleanup' ) ) {
            LuwiPress_Logger::cleanup( $log_days );
        }
        if ( method_exists( 'LuwiPress_Token_Tracker', 'cleanup' ) ) {
            LuwiPress_Token_Tracker::cleanup( $token_days );
        }
        LuwiPress_Logger::log( 'Daily cleanup completed', 'info' );
    }

    /**
     * Admin init
     */
    public function admin_init() {
        // Auto-upgrade: ensure all tables exist on version change
        $db_version = get_option( 'luwipress_db_version', '0' );
        if ( version_compare( $db_version, LUWIPRESS_VERSION, '<' ) ) {
            LuwiPress_Logger::create_table();
            LuwiPress_Token_Tracker::create_table();
            update_option( 'luwipress_db_version', LUWIPRESS_VERSION );
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
        if (strpos($hook, 'luwipress') !== false) {
            wp_enqueue_style(
                'luwipress-admin',
                LUWIPRESS_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                LUWIPRESS_VERSION
            );
            
            wp_enqueue_script(
                'luwipress-admin',
                LUWIPRESS_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                LUWIPRESS_VERSION,
                true
            );

            $user = wp_get_current_user();
            wp_localize_script('luwipress-admin', 'luwipress', array(
                'ajax_url'     => admin_url('admin-ajax.php'),
                'nonce'        => wp_create_nonce('luwipress_dashboard_nonce'),
                'claw_nonce'   => wp_create_nonce('luwipress_claw_nonce'),
                'user_initial' => mb_strtoupper( mb_substr( $user->display_name, 0, 1 ) ),
            ));
        }

    }
    
    /**
     * AJAX: Test n8n webhook connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('luwipress_settings_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'luwipress'));
        }

        $webhook_url = sanitize_url($_POST['webhook_url'] ?? '');
        if (empty($webhook_url)) {
            wp_send_json_error(__('Webhook URL is required', 'luwipress'));
        }

        // Test by calling a known webhook path (product-enrich) with a ping action
        $test_url = trailingslashit( $webhook_url ) . 'product-enrich';
        $response = wp_remote_post($test_url, array(
            'timeout' => 10,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'action' => 'ping',
                'source' => 'luwipress',
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
            wp_send_json_error(sprintf(__('HTTP %d response from n8n', 'luwipress'), $code));
        }
    }

    /**
     * AJAX: Scan content opportunities
     */
    public function ajax_scan_opportunities() {
        check_ajax_referer('luwipress_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $config = LuwiPress_Site_Config::get_instance();
        $request = new WP_REST_Request('GET', '/luwipress/v1/content/opportunities');
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
        check_ajax_referer('luwipress_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $threshold = absint(get_option('luwipress_thin_content_threshold', 300));

        global $wpdb;
        $product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_luwipress_enrich_status'
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
     * AJAX: Dashboard data — all stats in one call for fast render.
     */
    public function ajax_dashboard_data() {
        check_ajax_referer( 'luwipress_dashboard_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // Hero stats
        $product_counts = wp_count_posts( 'product' );
        $total_products = absint( $product_counts->publish ?? 0 );

        // Revenue (30d)
        $revenue_30d = 0;
        $orders_30d  = 0;
        if ( class_exists( 'WooCommerce' ) ) {
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT COUNT(*) as cnt, COALESCE(SUM(pm.meta_value),0) as total
                 FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
                 WHERE p.post_type IN ('shop_order','shop_order_placehold')
                   AND p.post_status IN ('wc-completed','wc-processing')
                   AND p.post_date >= %s",
                gmdate( 'Y-m-d', strtotime( '-30 days' ) )
            ) );
            if ( $row ) {
                $revenue_30d = floatval( $row->total );
                $orders_30d  = intval( $row->cnt );
            }
        }

        // Token stats
        $token_stats = class_exists( 'LuwiPress_Token_Tracker' ) ? LuwiPress_Token_Tracker::get_stats( 30 ) : null;
        $today_calls = $token_stats ? intval( $token_stats['today']['calls'] ) : 0;
        $today_cost  = $token_stats ? floatval( $token_stats['today']['cost'] ) : 0;
        $limit_pct   = $token_stats ? intval( $token_stats['limit_used'] ) : 0;

        // 7-day cost breakdown
        $daily_costs = array();
        if ( class_exists( 'LuwiPress_Token_Tracker' ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'luwipress_token_usage';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT DATE(created_at) as day, SUM(estimated_cost) as cost
                     FROM {$table}
                     WHERE created_at >= %s
                     GROUP BY DATE(created_at)
                     ORDER BY day ASC",
                    gmdate( 'Y-m-d', strtotime( '-6 days' ) )
                ) );
                $day_map = array();
                foreach ( $rows as $r ) {
                    $day_map[ $r->day ] = floatval( $r->cost );
                }
                for ( $i = 6; $i >= 0; $i-- ) {
                    $d = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
                    $daily_costs[] = array(
                        'day'   => gmdate( 'D', strtotime( $d ) ),
                        'date'  => $d,
                        'cost'  => $day_map[ $d ] ?? 0,
                    );
                }
            }
        }

        // Opportunities (reuse existing scan)
        $opps = array();
        if ( class_exists( 'LuwiPress_Site_Config' ) ) {
            $config  = LuwiPress_Site_Config::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/content/opportunities' );
            $request->set_param( 'limit', 50 );
            $response = $config->get_content_opportunities( $request );
            $data     = $response->get_data();
            $opps = array(
                'missing_translations' => count( $data['missing_translations'] ?? array() ),
                'thin_content'         => count( $data['thin_content'] ?? array() ),
                'missing_seo'          => count( $data['missing_seo_meta'] ?? array() ),
                'missing_alt'          => count( $data['missing_alt_text'] ?? array() ),
                'stale_content'        => count( $data['stale_content'] ?? array() ),
            );
        }

        // Content health percentages
        $total_content   = $total_products > 0 ? $total_products : 1;
        $optimized_count = $total_content - ( $opps['thin_content'] ?? 0 ) - ( $opps['missing_seo'] ?? 0 );
        $optimized_pct   = max( 0, round( ( $optimized_count / $total_content ) * 100 ) );

        // Recent logs
        $logs = array();
        if ( class_exists( 'LuwiPress_Logger' ) ) {
            $raw_logs = LuwiPress_Logger::get_logs( 8 );
            foreach ( $raw_logs as $log ) {
                $logs[] = array(
                    'level'   => $log->level,
                    'message' => $log->message,
                    'time'    => human_time_diff( strtotime( $log->timestamp ), current_time( 'timestamp' ) ),
                );
            }
        }

        // Translation coverage
        $trans_coverage = array();
        $target_langs = get_option( 'luwipress_translation_languages', array() );
        if ( ! empty( $target_langs ) && class_exists( 'LuwiPress_Translation' ) ) {
            global $wpdb;
            $meta_keys = array();
            foreach ( $target_langs as $lang ) {
                $meta_keys[] = '_luwipress_translation_' . $lang . '_status';
            }
            $key_ph = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_key, COUNT(DISTINCT post_id) AS cnt
                 FROM {$wpdb->postmeta}
                 WHERE meta_key IN ({$key_ph}) AND meta_value = 'completed'
                 GROUP BY meta_key",
                $meta_keys
            ) );
            $done_map = array();
            foreach ( $rows as $r ) {
                $done_map[ $r->meta_key ] = intval( $r->cnt );
            }
            foreach ( $target_langs as $lang ) {
                $key  = '_luwipress_translation_' . $lang . '_status';
                $done = $done_map[ $key ] ?? 0;
                $trans_coverage[ $lang ] = $total_products > 0 ? round( ( $done / $total_products ) * 100 ) : 0;
            }
        }

        wp_send_json_success( array(
            'products'      => $total_products,
            'revenue'       => $revenue_30d,
            'orders'        => $orders_30d,
            'ai_calls'      => $today_calls,
            'ai_cost_today' => $today_cost,
            'budget_pct'    => $limit_pct,
            'daily_costs'   => $daily_costs,
            'opportunities' => $opps,
            'health_pct'    => $optimized_pct,
            'health_thin'   => $opps['thin_content'] ?? 0,
            'health_seo'    => $opps['missing_seo'] ?? 0,
            'logs'          => $logs,
            'trans_coverage' => $trans_coverage,
            'currency'      => class_exists( 'WooCommerce' ) ? get_woocommerce_currency_symbol() : '$',
        ) );
    }

    /**
     * Set default options
     */
    private function set_default_options() {
        $defaults = array(
            'luwipress_enable_logging'    => 1,
            'luwipress_log_level'         => 'info',
            'luwipress_rate_limit'        => 1000,
            'luwipress_security_headers'  => 1,
            'luwipress_webhook_timeout'   => 30,
            'luwipress_ai_provider'       => 'openai',
            'luwipress_ai_model'          => 'gpt-4o-mini',
            'luwipress_daily_token_limit' => 1.00,
            // AI Engine defaults (v2.0)
            'luwipress_processing_mode'   => 'local',
            'luwipress_default_provider'  => 'anthropic',
            'luwipress_anthropic_model'   => 'claude-haiku-4-5-20241022',
            'luwipress_openai_model'      => 'gpt-4o-mini',
            'luwipress_google_model'      => 'gemini-2.0-flash',
        );
        
        foreach ($defaults as $option => $value) {
            if (get_option($option) === false) {
                add_option($option, $value);
            }
        }

        // Migration: if webhook URL is already set, keep n8n mode for existing installs.
        $webhook_url = get_option( 'luwipress_seo_webhook_url', '' );
        if ( ! empty( $webhook_url ) && get_option( 'luwipress_processing_mode' ) === 'local' ) {
            // Only override if this is the first time setting defaults (fresh activation with existing config).
            if ( get_option( 'luwipress_ai_engine_migrated' ) === false ) {
                update_option( 'luwipress_processing_mode', 'n8n' );
                add_option( 'luwipress_ai_engine_migrated', '1' );
            }
        }
    }
}

// Initialize plugin
function luwipress_init() {
    return LuwiPress::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'luwipress_init');

// Global helper functions
function luwipress_log($message, $level = 'info', $context = array()) {
    if (class_exists('LuwiPress_Logger')) {
        LuwiPress_Logger::log($message, $level, $context);
    }
}

function luwipress_get_option($option, $default = false) {
    return get_option('luwipress_' . $option, $default);
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
function luwipress_build_meta_block( $callback_url = '' ) {
    return array(
        'site_url'       => get_site_url(),
        'rest_base'      => rest_url( 'luwipress/v1/' ),
        'callback_url'   => $callback_url,
        'api_token'      => get_option( 'luwipress_seo_api_token', '' ),
        'provider'       => get_option( 'luwipress_ai_provider', 'openai' ),
        'model'          => get_option( 'luwipress_ai_model', 'gpt-4o-mini' ),
        'max_tokens'     => absint( get_option( 'luwipress_max_output_tokens', 1024 ) ),
        'image_provider' => get_option( 'luwipress_image_provider', 'dall-e-3' ),
        'language'       => get_option( 'luwipress_target_language', 'en' ),
        'site_name'      => get_bloginfo( 'name' ),
        'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
    );
}