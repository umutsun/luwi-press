<?php
/**
 * Plugin Name: LuwiPress
 * Plugin URI: https://luwi.dev/luwipress
 * Description: AI-powered content enrichment, SEO optimization, and translation automation for WooCommerce stores.
 * Version: 3.1.39
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
define('LUWIPRESS_VERSION', '3.1.39');
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
     * Is WooCommerce active and loaded? Single source of truth — every WC-only
     * feature gates on this. Plugin runs without WC; product enrichment, AEO,
     * marketplace, CRM, and KG product nodes silently disable themselves.
     *
     * @return bool
     */
    public static function is_wc_active() {
        return class_exists( 'WooCommerce' );
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
        // Instantiate modules synchronously — each module's constructor
        // registers its own admin_menu / rest_api_init hooks, and we're
        // already on plugins_loaded p10 here (see luwipress_init at the
        // end of this file), which runs BEFORE admin_menu fires.
        $this->load_modules();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Module instantiation runs synchronously at the end of __construct
        // (see load_modules() call below). Each module's constructor is what
        // registers its own admin_menu / rest_api_init hooks, so we MUST
        // instantiate them before WP fires those actions. The bootstrap is
        // already deferred to plugins_loaded p10 by the file footer
        // `add_action('plugins_loaded', 'luwipress_init')`, which runs
        // BEFORE admin_menu (p10 in admin context) — so calling
        // load_modules() inline in the constructor is the simplest correct
        // ordering. Scheduling a NEW plugins_loaded callback from inside an
        // existing plugins_loaded callback at lower priority would silently
        // never fire (WP's hook iteration is already past it).

        add_action('init', array($this, 'init'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_init', array($this, 'handle_cron_notice_dismiss'));
        // Cron + migration notices stay registered globally so they reach the
        // operator on the WP plugins screen and other native pages — but they
        // are SUPPRESSED inside any LuwiPress admin page (see
        // suppress_admin_notices_on_luwipress_pages below) because notices
        // injected into the dashboard hero break our layout. The status
        // ribbon's red WC pill is the in-dashboard signal for missing WC.
        add_action('admin_notices', array($this, 'render_cron_notices'));
        add_action('admin_notices', array($this, 'render_webmcp_moved_notice'));
        add_action('admin_init', array($this, 'handle_webmcp_notice_dismiss'));
        add_action('admin_notices', array($this, 'render_open_claw_moved_notice'));
        add_action('admin_init', array($this, 'handle_open_claw_notice_dismiss'));

        // Strip ALL admin_notices / all_admin_notices output on LuwiPress
        // pages so third-party plugins (BeTheme TGM "recommended plugins",
        // WP core update nags, etc.) don't push the dashboard hero around
        // or overflow the header ribbon. Operator still sees these on their
        // native screens (Plugins list, Dashboard home, etc.).
        add_action('in_admin_header', array($this, 'suppress_admin_notices_on_luwipress_pages'), 1000);
        add_action('wp_footer', array($this, 'render_chat_assets'), 1);
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
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
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-job-queue.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-token-tracker.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-security.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-hmac.php';

        // Environment detection & bridge services
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-plugin-detector.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-site-config.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-email-proxy.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-seo-writer.php';

        // AI Engine: provider interface, providers, prompts, dispatcher, image handler
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-ai-provider.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/providers/class-luwipress-provider-anthropic.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/providers/class-luwipress-provider-openai.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/providers/class-luwipress-provider-google.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/providers/class-luwipress-provider-openai-compatible.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-prompts.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-ai-engine.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-image-handler.php';

        // Content & automation modules
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-ai-content.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-aeo.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-translation.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-content-scheduler.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-internal-linker.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-review-analytics.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-crm-bridge.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-knowledge-graph.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-elementor.php';

        // Marketplace integration
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-adapter.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-amazon.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-ebay.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-trendyol.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-alibaba.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-hepsiburada.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-n11.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-etsy.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-walmart.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-marketplace.php';

        // BM25 search index
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-search-index.php';

        // Customer-facing chat
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-customer-chat.php';

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
        LuwiPress_Customer_Chat::create_table();
        LuwiPress_Search_Index::create_table();
        LuwiPress_Marketplace::create_table();

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
    }

    /**
     * Instantiate all modules. Runs on `plugins_loaded` priority 5 so module
     * constructors (which `add_action('admin_menu', ...)`) execute BEFORE
     * WP fires `admin_menu` on first activation. Previously this ran on
     * `init`, racing same-priority hooks and producing an incomplete menu
     * on the first page load.
     */
    public function load_modules() {
        // Core infrastructure
        LuwiPress_API::get_instance();
        LuwiPress_Auth::get_instance();
        LuwiPress_Security::get_instance();

        // Environment detection & bridge services
        LuwiPress_Plugin_Detector::get_instance();
        LuwiPress_Site_Config::get_instance();
        LuwiPress_Email_Proxy::get_instance();
        LuwiPress_SEO_Writer::get_instance();

        // Content & automation modules
        LuwiPress_AI_Content::get_instance();
        LuwiPress_AI_Content::init_frontend_hooks();
        LuwiPress_AEO::get_instance();
        LuwiPress_Translation::get_instance();
        LuwiPress_Content_Scheduler::get_instance();
        LuwiPress_Internal_Linker::get_instance();
        LuwiPress_Review_Analytics::get_instance();
        LuwiPress_CRM_Bridge::get_instance();
        LuwiPress_Knowledge_Graph::get_instance();
        LuwiPress_Marketplace::get_instance();
        LuwiPress_Search_Index::get_instance();
        LuwiPress_Customer_Chat::get_instance();

        // Elementor integration (only if Elementor is active)
        if ( LuwiPress_Elementor::is_elementor_active() || is_admin() ) {
            LuwiPress_Elementor::get_instance();
        }
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
        // Position 3.13 — decimal under Dashboard (2). Integer 3 was taken
        // by Jetpack on Tapadum (visible bug: Jetpack appeared as a phantom
        // submenu under our parent), and integer collisions overwrite slots
        // unpredictably. WP accepts float positions and uses the decimal as
        // a tiebreak so collision risk drops to near zero. Stays out of the
        // BeTheme 25-55 cluster and any cleanup themes that filter null
        // positions still render us because we pass a real numeric value.
        add_menu_page(
            'LuwiPress',
            'LuwiPress',
            'manage_options',
            'luwipress',
            array($this, 'admin_page'),
            LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo-white.png',
            3.13
        );

        // WP renders menu icons at 20×20 with 7px/2px padding, which squashes our 2000px logo.
        // Force the image to fill the 34px icon cell so the omega motif stays readable.
        add_action( 'admin_head', function () {
            echo '<style>
                #adminmenu #toplevel_page_luwipress .wp-menu-image img {
                    width: 24px; height: 24px; padding: 0; opacity: 1;
                }
                #adminmenu #toplevel_page_luwipress .wp-menu-image {
                    padding: 5px 0 0 4px;
                }
            </style>';
        } );

        // Rename first submenu from parent slug to "Dashboard"
        add_submenu_page(
            'luwipress',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'luwipress',
            array($this, 'admin_page')
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
        if ( method_exists( 'LuwiPress_Customer_Chat', 'cleanup' ) ) {
            $chat_days = absint( get_option( 'luwipress_chat_retention_days', 90 ) );
            LuwiPress_Customer_Chat::cleanup( $chat_days );
        }
        // Orphan translation scan (detect non-EN content registered as EN originals)
        $this->scan_orphan_translations();

        LuwiPress_Logger::log( 'Daily cleanup completed', 'info' );
    }

    /**
     * Scan for orphan translations — posts/products registered as EN originals
     * but containing non-English content. Logs warnings for manual review.
     */
    private function scan_orphan_translations() {
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            return;
        }

        global $wpdb;
        $default_lang = LuwiPress_Translation::get_default_language();

        // Find posts registered as originals (source_language_code IS NULL)
        // in the default language but created in the last 7 days
        $recent_originals = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type, t.language_code
             FROM {$wpdb->posts} p
             JOIN {$wpdb->prefix}icl_translations t ON p.ID = t.element_id
             WHERE t.source_language_code IS NULL
               AND t.language_code = %s
               AND t.element_type LIKE 'post_%%'
               AND p.post_status = 'publish'
               AND p.post_date > DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY p.post_date DESC
             LIMIT 50",
            $default_lang
        ) );

        if ( empty( $recent_originals ) ) {
            return;
        }

        $orphans = array();
        foreach ( $recent_originals as $post ) {
            $title = mb_strtolower( $post->post_title );
            // Detect non-English titles by checking for common non-EN words
            $non_en_indicators = array(
                'professionale', 'argilla', 'elettro', 'strumenti', 'percussione',
                'guitarra', 'eléctric', 'percusión', 'tambor', 'cuerdas', 'madera',
                'professionnel', 'guitare', 'tambour', 'oud turc',
            );
            $matches = 0;
            foreach ( $non_en_indicators as $word ) {
                if ( false !== mb_strpos( $title, $word ) ) {
                    $matches++;
                }
            }
            if ( $matches >= 1 ) {
                $orphans[] = $post;
            }
        }

        if ( ! empty( $orphans ) ) {
            $ids = wp_list_pluck( $orphans, 'ID' );
            LuwiPress_Logger::log(
                sprintf(
                    'Orphan scan: %d suspect post(s) with non-English content registered as %s originals: %s',
                    count( $orphans ),
                    strtoupper( $default_lang ),
                    implode( ', ', array_map( function( $p ) { return '#' . $p->ID . ' "' . $p->post_title . '"'; }, $orphans ) )
                ),
                'warning'
            );
        }
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
            LuwiPress_Customer_Chat::create_table();
            LuwiPress_Search_Index::create_table();
            update_option( 'luwipress_db_version', LUWIPRESS_VERSION );

            // Auto-build BM25 index on first upgrade
            if ( class_exists( 'LuwiPress_Search_Index' ) ) {
                $idx = LuwiPress_Search_Index::get_instance();
                if ( ! $idx->is_indexed() ) {
                    $idx->reindex_all();
                }
            }
        }
    }

    /**
     * Strip every admin_notices / all_admin_notices callback (ours and
     * third-party) inside LuwiPress admin pages. WP injects notices between
     * the `<h1>` and the first `<div>` of page content, which collides with
     * the dashboard hero / Knowledge Graph header / Settings tabs and
     * physically pushes the layout. The status ribbon's red WC pill (and
     * other category pills) replaces any in-page warning the operator
     * needs while inside LuwiPress; native screens (Plugins list, WP
     * Dashboard home) keep getting the notices unchanged.
     */
    public function suppress_admin_notices_on_luwipress_pages() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || strpos( (string) $screen->id, 'luwipress' ) === false ) {
            return;
        }
        remove_all_actions( 'admin_notices' );
        remove_all_actions( 'all_admin_notices' );
        remove_all_actions( 'user_admin_notices' );
        remove_all_actions( 'network_admin_notices' );
    }

    /**
     * Persistent notice: WooCommerce is no longer required (3.1.38+) but most
     * value-add features need it. Show a discreet warning banner so the operator
     * knows what's disabled, with a one-click link to install WC.
     */
    public function render_woocommerce_inactive_notice() {
        if ( self::is_wc_active() ) {
            return;
        }
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        // Only show on LuwiPress admin pages to avoid notice spam across WP admin.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || strpos( (string) $screen->id, 'luwipress' ) === false ) {
            return;
        }

        $install_url = wp_nonce_url(
            self_admin_url( 'update.php?action=install-plugin&plugin=woocommerce' ),
            'install-plugin_woocommerce'
        );
        self::render_pill_notice_styles();
        $tooltip = __( 'Generic features (content scheduler, customer chat, AI provider settings, token tracking, generic SEO/AEO writers) are active. WooCommerce-dependent features are disabled: product enrichment, AEO product schema, marketplace publishing, CRM customer segmentation, product knowledge graph nodes, and review analytics.', 'luwipress' );
        ?>
        <div class="notice notice-warning notice-luwi-pill">
            <span class="luwi-pill" tabindex="0" title="<?php echo esc_attr( $tooltip ); ?>">
                <span class="luwi-pill__dot" aria-hidden="true"></span>
                <span class="luwi-pill__label"><?php esc_html_e( 'WooCommerce inactive — generic features only', 'luwipress' ); ?></span>
                <span class="luwi-pill__tip" role="tooltip" style="display:none;"><?php echo esc_html( $tooltip ); ?></span>
            </span>
            <a href="<?php echo esc_url( $install_url ); ?>" class="button button-small button-primary luwi-pill__action"><?php esc_html_e( 'Install WooCommerce', 'luwipress' ); ?></a>
        </div>
        <?php
    }

    /**
     * Inline styles for the compact pill notices — emit once per page so
     * multiple notices share one stylesheet block. Also prints an empty
     * style tag with id so subsequent calls early-return.
     */
    private static function render_pill_notice_styles() {
        static $printed = false;
        if ( $printed ) {
            return;
        }
        $printed = true;
        ?>
        <style id="luwi-pill-notice-css">
        .notice.notice-luwi-pill {
            padding: 8px 12px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin: 8px 20px 8px 2px;
        }
        .notice.notice-luwi-pill > p { margin: 0; padding: 0; }
        .luwi-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(0,0,0,.04);
            font-size: 12px;
            line-height: 1.4;
            font-weight: 600;
            color: #1d2327;
            position: relative;
            cursor: help;
            white-space: nowrap;
        }
        .luwi-pill:focus { outline: 2px solid #2271b1; outline-offset: 1px; }
        .luwi-pill__dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #dba617;
        }
        .notice-info .luwi-pill__dot { background: #2271b1; }
        .notice-success .luwi-pill__dot { background: #00a32a; }
        .notice-error .luwi-pill__dot { background: #d63638; }
        .luwi-pill__tip {
            display: none !important;
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            z-index: 9999;
            min-width: 280px;
            max-width: 480px;
            padding: 10px 12px;
            border-radius: 6px;
            background: #1d2327;
            color: #fff;
            font-size: 12px;
            font-weight: 400;
            line-height: 1.5;
            white-space: normal;
            box-shadow: 0 4px 14px rgba(0,0,0,.18);
            pointer-events: none;
        }
        .luwi-pill:hover .luwi-pill__tip,
        .luwi-pill:focus .luwi-pill__tip,
        .luwi-pill:focus-within .luwi-pill__tip {
            display: block !important;
        }
        .luwi-pill__action { margin-left: 4px !important; }
        </style>
        <?php
    }

    /**
     * One-time migration notice: Open Claw moved to a companion plugin in 3.1.0.
     */
    public function render_open_claw_moved_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) return;
        if ( class_exists( 'LuwiPress_Open_Claw' ) || defined( 'LUWIPRESS_OPEN_CLAW_VERSION' ) ) return;
        if ( get_option( 'luwipress_open_claw_moved_dismissed', false ) ) return;
        // Migration notice: only show if the site previously had Open Claw
        // enabled (i.e. is upgrading from pre-3.1.0). Fresh installs never
        // had Open Claw so there's nothing to migrate. Without this gate the
        // notice appeared on every clean install (Birikim 3.1.38) as a
        // confusing "what's this?" message for a feature the user never used.
        if ( false === get_option( 'luwipress_open_claw_enabled', false ) ) {
            return;
        }
        // Match the WC notice scoping: only show on LuwiPress admin pages.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || strpos( (string) $screen->id, 'luwipress' ) === false ) {
            return;
        }
        $dismiss_url = wp_nonce_url(
            add_query_arg( 'luwipress_dismiss_open_claw_notice', '1' ),
            'luwipress_dismiss_open_claw_notice'
        );
        self::render_pill_notice_styles();
        $tooltip = __( 'The admin AI assistant now lives in the separate "LuwiPress Open Claw" plugin. Install and activate it to keep the LuwiPress → Open Claw menu.', 'luwipress' );
        ?>
        <div class="notice notice-info notice-luwi-pill">
            <span class="luwi-pill" tabindex="0" title="<?php echo esc_attr( $tooltip ); ?>">
                <span class="luwi-pill__dot" aria-hidden="true"></span>
                <span class="luwi-pill__label"><?php esc_html_e( 'Open Claw moved to companion plugin (3.1+)', 'luwipress' ); ?></span>
                <span class="luwi-pill__tip" role="tooltip" style="display:none;"><?php echo esc_html( $tooltip ); ?></span>
            </span>
            <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-small luwi-pill__action"><?php esc_html_e( 'Dismiss', 'luwipress' ); ?></a>
        </div>
        <?php
    }

    public function handle_open_claw_notice_dismiss() {
        if ( empty( $_GET['luwipress_dismiss_open_claw_notice'] ) ) return;
        if ( ! current_user_can( 'activate_plugins' ) ) return;
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'luwipress_dismiss_open_claw_notice' ) ) return;
        update_option( 'luwipress_open_claw_moved_dismissed', true );
        wp_safe_redirect( remove_query_arg( array( 'luwipress_dismiss_open_claw_notice', '_wpnonce' ) ) );
        exit;
    }

    /**
     * One-time migration notice: WebMCP moved to a companion plugin in 3.0.0.
     * Only shown if the site was previously using WebMCP (enabled option present)
     * and the companion isn't installed yet. Dismissable.
     */
    public function render_webmcp_moved_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        // Only show if the old enabled flag exists (site had WebMCP turned on before 3.0.0)
        if ( false === get_option( 'luwipress_webmcp_enabled', false ) ) {
            return;
        }
        // Hide if companion is active
        if ( class_exists( 'LuwiPress_WebMCP' ) || defined( 'LUWIPRESS_WEBMCP_VERSION' ) ) {
            return;
        }
        // Hide if dismissed
        if ( get_option( 'luwipress_webmcp_moved_dismissed', false ) ) {
            return;
        }
        // Match the WC notice scoping: only show on LuwiPress admin pages.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || strpos( (string) $screen->id, 'luwipress' ) === false ) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            add_query_arg( 'luwipress_dismiss_webmcp_notice', '1' ),
            'luwipress_dismiss_webmcp_notice'
        );
        self::render_pill_notice_styles();
        $tooltip = __( 'Your MCP configuration is preserved, but the WebMCP endpoint and admin page now live in the separate "LuwiPress WebMCP" plugin. Install and activate it to restore AI-agent integration.', 'luwipress' );
        ?>
        <div class="notice notice-info notice-luwi-pill">
            <span class="luwi-pill" tabindex="0" title="<?php echo esc_attr( $tooltip ); ?>">
                <span class="luwi-pill__dot" aria-hidden="true"></span>
                <span class="luwi-pill__label"><?php esc_html_e( 'WebMCP moved to companion plugin (3.0+)', 'luwipress' ); ?></span>
                <span class="luwi-pill__tip" role="tooltip" style="display:none;"><?php echo esc_html( $tooltip ); ?></span>
            </span>
            <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-small luwi-pill__action"><?php esc_html_e( 'Dismiss', 'luwipress' ); ?></a>
        </div>
        <?php
    }

    /**
     * Handle dismissal of the WebMCP migration notice.
     */
    public function handle_webmcp_notice_dismiss() {
        if ( empty( $_GET['luwipress_dismiss_webmcp_notice'] ) ) {
            return;
        }
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'luwipress_dismiss_webmcp_notice' ) ) {
            return;
        }
        update_option( 'luwipress_webmcp_moved_dismissed', true );
        wp_safe_redirect( remove_query_arg( array( 'luwipress_dismiss_webmcp_notice', '_wpnonce' ) ) );
        exit;
    }

    /**
     * Render an admin notice when WP-Cron is disabled.
     *
     * Many LuwiPress features (translation queue, content scheduler, enrichment,
     * CRM refresh) rely on WP-Cron. If DISABLE_WP_CRON is true and no external
     * trigger is confirmed, jobs sit unexecuted.
     */
    public function render_cron_notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
            return;
        }

        if ( get_option( 'luwipress_external_cron_confirmed', false ) ) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            add_query_arg( 'luwipress_dismiss_cron_notice', '1' ),
            'luwipress_dismiss_cron_notice'
        );

        $cron_url = site_url( 'wp-cron.php?doing_wp_cron' );
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'LuwiPress — WP-Cron is disabled.', 'luwipress' ); ?></strong>
                <?php esc_html_e( 'DISABLE_WP_CRON is defined as true in wp-config.php. Background jobs (translation queue, content scheduler, product enrichment, CRM refresh) will not run automatically until an external cron trigger is configured.', 'luwipress' ); ?>
            </p>
            <p>
                <?php esc_html_e( 'Add a server cron entry that hits this URL every 5 minutes:', 'luwipress' ); ?>
                <br><code>*/5 * * * * wget -q -O - <?php echo esc_url( $cron_url ); ?> &gt;/dev/null 2&gt;&amp;1</code>
            </p>
            <p>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Dismiss — external cron is configured', 'luwipress' ); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Handle the cron notice dismiss link.
     *
     * Sets a site-wide option so the notice stays hidden. If WP-Cron is later
     * re-enabled (DISABLE_WP_CRON removed from wp-config.php), the confirmation
     * is cleared automatically so the warning returns if cron gets disabled again.
     */
    public function handle_cron_notice_dismiss() {
        // Auto-clear confirmation when WP-Cron is no longer disabled.
        if ( ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) && get_option( 'luwipress_external_cron_confirmed', false ) ) {
            delete_option( 'luwipress_external_cron_confirmed' );
        }

        if ( empty( $_GET['luwipress_dismiss_cron_notice'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'luwipress_dismiss_cron_notice' ) ) {
            return;
        }

        update_option( 'luwipress_external_cron_confirmed', true );

        wp_safe_redirect( remove_query_arg( array( 'luwipress_dismiss_cron_notice', '_wpnonce' ) ) );
        exit;
    }

    /**
     * Render chat assets directly to the footer.
     *
     * Why not wp_enqueue_*:
     *   Cache plugins (LiteSpeed, WP Rocket, W3TC) frequently combine/defer
     *   or strip enqueued chat assets, leaving the launcher invisible despite
     *   the config being present. We bypass the enqueue pipeline entirely
     *   and echo the CSS inline + JS with data-no-optimize attributes so
     *   optimizers know to leave them alone.
     */
    public function render_chat_assets() {
        if ( is_admin() ) {
            return;
        }
        if ( ! get_option( 'luwipress_chat_enabled', 0 ) ) {
            return;
        }

        $css_file = LUWIPRESS_PLUGIN_DIR . 'assets/css/luwipress-chat.css';
        $js_file  = LUWIPRESS_PLUGIN_DIR . 'assets/js/luwipress-chat.js';
        if ( ! file_exists( $css_file ) || ! file_exists( $js_file ) ) {
            return;
        }

        $config = array(
            'rest_url'           => rest_url( 'luwipress/v1/chat/' ),
            'nonce'              => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
            'store_name'         => get_bloginfo( 'name' ),
            'greeting'           => get_option( 'luwipress_chat_greeting', 'Hi! How can I help you today?' ),
            'primary'            => get_option( 'luwipress_chat_color_primary', '#6366f1' ),
            'text_color'         => get_option( 'luwipress_chat_color_text', '#ffffff' ),
            'position'           => get_option( 'luwipress_chat_position', 'bottom-right' ),
            'escalation_channel' => get_option( 'luwipress_chat_escalation_channel', 'whatsapp' ),
            'whatsapp_number'    => get_option( 'luwipress_whatsapp_number', '' ),
            'telegram_username'  => get_option( 'luwipress_telegram_username', '' ),
            'is_logged_in'       => is_user_logged_in(),
            'customer_name'      => is_user_logged_in() ? wp_get_current_user()->display_name : '',
        );

        $css      = file_get_contents( $css_file );
        $js       = file_get_contents( $js_file );
        $chat_ver = LUWIPRESS_VERSION . '.' . filemtime( $js_file );

        // Optimizer-bypass attributes. Recognized by LiteSpeed, WP Rocket, Autoptimize, Cloudflare.
        $skip_attrs = 'data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-cfasync="false"';

        echo "\n<!-- luwipress-chat (v{$chat_ver}) render_chat_assets() -->\n";
        echo '<style id="luwipress-chat-inline-css" ' . $skip_attrs . '>' . $css . '</style>' . "\n";
        echo '<script id="luwipress-chat-config" ' . $skip_attrs . '>window.lpChat=' . wp_json_encode( $config ) . ';</script>' . "\n";
        echo '<script id="luwipress-chat-inline-js" ' . $skip_attrs . '>' . $js . '</script>' . "\n";
    }
    
    /**
     * Admin enqueue scripts
     */
    public function admin_enqueue_scripts($hook) {
        // Match BOTH the page-load hook ($hook) and the screen ID, because
        // companions (luwipress-webmcp) and themes can register pages whose
        // hook name doesn't start with our parent slug but whose screen ID
        // does. Belt-and-braces: if either matches, enqueue.
        $screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $screen_id = $screen ? (string) $screen->id : '';
        $is_luwipress_page = strpos( (string) $hook, 'luwipress' ) !== false
                          || strpos( $screen_id, 'luwipress' ) !== false;
        if ($is_luwipress_page) {
            // Thickbox: powers the wordpress.org plugin-information modal that
            // the dashboard ribbon's red pills link to. Without this, clicking
            // a "No SMTP" / "No cache plugin" pill would just open a blank
            // iframe popup instead of the proper plugin detail card.
            add_thickbox();

            wp_enqueue_style(
                'luwipress-admin',
                LUWIPRESS_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                LUWIPRESS_VERSION
            );

            $admin_js_path = LUWIPRESS_PLUGIN_DIR . 'assets/js/admin.js';
            $admin_js_ver  = LUWIPRESS_VERSION . '.' . ( file_exists( $admin_js_path ) ? filemtime( $admin_js_path ) : '0' );
            wp_enqueue_script(
                'luwipress-admin',
                LUWIPRESS_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                $admin_js_ver,
                true
            );

            // Shared live-update primitive (polling, countUp, sparkline, barMeter).
            // Lightweight zero-dep module; safe to load on every LuwiPress admin page.
            wp_enqueue_script(
                'luwipress-live',
                LUWIPRESS_PLUGIN_URL . 'assets/js/luwi-live.js',
                array(),
                LUWIPRESS_VERSION,
                true
            );
            wp_localize_script('luwipress-live', 'luwipressLive', array(
                'rest_base'  => esc_url_raw( rest_url( 'luwipress/v1/' ) ),
                'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            ));

            // Usage & Logs — page-specific live glue.
            if ( false !== strpos( $hook, 'luwipress-usage' ) ) {
                wp_enqueue_script(
                    'luwipress-usage-live',
                    LUWIPRESS_PLUGIN_URL . 'assets/js/usage-live.js',
                    array('luwipress-live'),
                    LUWIPRESS_VERSION,
                    true
                );
            }

            $user = wp_get_current_user();
            wp_localize_script('luwipress-admin', 'luwipress', array(
                'ajax_url'     => admin_url('admin-ajax.php'),
                'nonce'        => wp_create_nonce('luwipress_dashboard_nonce'),
                'claw_nonce'   => wp_create_nonce('luwipress_claw_nonce'),
                'user_initial' => mb_strtoupper( mb_substr( $user->display_name, 0, 1 ) ),
                'rest_root'    => esc_url_raw( rest_url() ),
                'rest_base'    => esc_url_raw( rest_url( 'luwipress/v1/' ) ),
                'nonce_rest'   => wp_create_nonce( 'wp_rest' ),
                'i18n'         => array(
                    // Scheduler — confirms
                    'confirm_delete_item'      => __( 'Delete this scheduled item?', 'luwipress' ),
                    'confirm_delete_plan'      => __( 'Delete this recurring plan? Already-queued topics are kept — only the auto-brainstorm stops.', 'luwipress' ),
                    'confirm_run_pending'      => __( 'Run up to 10 pending items through AI generation right now?', 'luwipress' ),
                    'confirm_regen_outline'    => __( 'Discard this outline and regenerate from scratch?', 'luwipress' ),
                    'confirm_bulk_publish'     => __( 'Publish %d selected draft(s)?', 'luwipress' ),
                    'confirm_bulk_retry'       => __( 'Retry %d selected item(s)?', 'luwipress' ),
                    'confirm_bulk_delete'      => __( 'Delete %d selected item(s)? This cannot be undone.', 'luwipress' ),
                    'confirm_enrich_batch'     => __( 'Start bulk AI enrichment for thin content products?', 'luwipress' ),
                    'confirm_enrich_batch50'   => __( 'Send up to 50 thin content products for AI enrichment? This will trigger AI enrichment.', 'luwipress' ),
                    'confirm_refresh_stale'    => __( 'Refresh stale content via AI enrichment?', 'luwipress' ),
                    // Scheduler — errors / validation
                    'err_need_topic'           => __( 'Add at least one topic to continue.', 'luwipress' ),
                    'err_topic_limit'          => __( 'Maximum 50 topics per batch. Please trim the list.', 'luwipress' ),
                    'err_need_date'            => __( 'Pick a start date.', 'luwipress' ),
                    'err_need_theme'           => __( 'Please enter a theme.', 'luwipress' ),
                    'err_need_title'           => __( 'Give the article a title first.', 'luwipress' ),
                    'err_need_section'         => __( 'Outline needs at least one section.', 'luwipress' ),
                    'err_keep_one_section'     => __( 'Keep at least one section.', 'luwipress' ),
                    'err_pick_topic'           => __( 'Pick at least one topic.', 'luwipress' ),
                    'err_plan_required'        => __( 'Name and theme are required.', 'luwipress' ),
                    'err_no_thin'              => __( 'No thin content found to enrich. Run a scan first.', 'luwipress' ),
                    'err_no_thin_products'     => __( 'No thin products found.', 'luwipress' ),
                    'err_fetch_thin'           => __( 'Failed to fetch thin products.', 'luwipress' ),
                    'err_request_failed'       => __( 'Request failed. Please try again.', 'luwipress' ),
                    'err_brainstorm'           => __( 'Brainstorm failed.', 'luwipress' ),
                    'err_brainstorm_empty'     => __( 'No topics returned — try a more specific theme.', 'luwipress' ),
                    'err_save_failed'          => __( 'Save failed.', 'luwipress' ),
                    'err_retry_failed'         => __( 'Retry failed.', 'luwipress' ),
                    'err_bulk_failed'          => __( 'Bulk action failed.', 'luwipress' ),
                    'err_enrich_failed'        => __( 'Enrich failed.', 'luwipress' ),
                    'err_regen_failed'         => __( 'Regenerate failed.', 'luwipress' ),
                    'err_outline_load'         => __( 'Could not load outline.', 'luwipress' ),
                    'err_estimate'             => __( 'Unable to estimate.', 'luwipress' ),
                    'err_generic'              => __( 'Error', 'luwipress' ),
                    // Scheduler — success / status
                    'ok_processed'             => __( 'Processed %d item(s).', 'luwipress' ),
                    'ok_batch_started'         => __( 'Batch enrichment started — %d products queued.', 'luwipress' ),
                    'ok_queued'                => __( 'Queued %d topic(s).', 'luwipress' ),
                    'ok_queued_with_skip'      => __( 'Queued %1$d topic(s) · %2$d skipped.', 'luwipress' ),
                    'ok_refreshing'            => __( 'Refreshing…', 'luwipress' ),
                    // Scheduler — loading / ephemeral
                    'loading_calc'             => __( 'Calculating…', 'luwipress' ),
                    'loading_thinking'         => __( 'Thinking…', 'luwipress' ),
                    'loading_ideas'            => __( 'Generating ideas…', 'luwipress' ),
                    'loading_outline'          => __( 'Loading outline…', 'luwipress' ),
                    'loading_regen'            => __( 'Regenerating…', 'luwipress' ),
                    'loading_saving'           => __( 'Saving…', 'luwipress' ),
                    'loading_queuing'          => __( 'Queuing…', 'luwipress' ),
                    'loading_running'          => __( 'Running…', 'luwipress' ),
                    // Scheduler — labels
                    'lbl_retry'                => __( 'Retry', 'luwipress' ),
                    'lbl_enrich'               => __( 'Enrich', 'luwipress' ),
                    'lbl_run_pending'          => __( 'Run pending now', 'luwipress' ),
                    'lbl_queue_all'            => __( 'Queue all topics', 'luwipress' ),
                    'lbl_regen_outline'        => __( 'Regenerate outline', 'luwipress' ),
                    'lbl_approve_generate'     => __( 'Approve & generate article', 'luwipress' ),
                    'lbl_save_plan'            => __( 'Save plan', 'luwipress' ),
                    'lbl_select_all'           => __( 'Select all (%d)', 'luwipress' ),
                    'lbl_add_picked'           => __( 'Add picked to queue', 'luwipress' ),
                    'lbl_topics_count'         => __( '%1$d / %2$d', 'luwipress' ),
                    'lbl_confirm'              => __( 'Confirm', 'luwipress' ),
                    'lbl_confirm_delete'       => __( 'Delete', 'luwipress' ),
                    'lbl_cancel'               => __( 'Cancel', 'luwipress' ),
                    'lbl_generate_ideas'       => __( 'Generate ideas', 'luwipress' ),
                    'title_confirm_delete'     => __( 'Delete item', 'luwipress' ),
                    'title_confirm_bulk_del'   => __( 'Delete selected items', 'luwipress' ),
                    'title_confirm_regen'      => __( 'Regenerate outline', 'luwipress' ),
                    'title_confirm_run_now'    => __( 'Run pending now', 'luwipress' ),
                    'title_confirm_plan_del'   => __( 'Delete recurring plan', 'luwipress' ),
                    'title_confirm_bulk_pub'   => __( 'Publish selected items', 'luwipress' ),
                    'title_confirm_bulk_retry' => __( 'Retry selected items', 'luwipress' ),
                ),
            ));
        }

        // Knowledge Graph page — separate script + style bundle.
        // 2,200+ lines of D3 code and 1,000+ lines of CSS; no point loading on every admin page.
        if ( false !== strpos( $hook, 'luwipress-knowledge-graph' ) ) {
            $kg_css_path = LUWIPRESS_PLUGIN_DIR . 'assets/css/knowledge-graph.css';
            $kg_css_ver  = LUWIPRESS_VERSION . '.' . ( file_exists( $kg_css_path ) ? filemtime( $kg_css_path ) : '0' );
            wp_enqueue_style(
                'luwipress-knowledge-graph',
                LUWIPRESS_PLUGIN_URL . 'assets/css/knowledge-graph.css',
                array( 'luwipress-admin' ),
                $kg_css_ver
            );
            // Use filemtime as cache buster -- in-place hotfixes that don't bump
            // LUWIPRESS_VERSION (e.g. JS-only chip-render fixes) still get a fresh
            // ?ver=... so browsers + LiteSpeed JS minify cache invalidate immediately.
            $kg_js_path = LUWIPRESS_PLUGIN_DIR . 'assets/js/knowledge-graph.js';
            $kg_js_ver  = LUWIPRESS_VERSION . '.' . ( file_exists( $kg_js_path ) ? filemtime( $kg_js_path ) : '0' );
            wp_enqueue_script(
                'luwipress-knowledge-graph',
                LUWIPRESS_PLUGIN_URL . 'assets/js/knowledge-graph.js',
                array(),
                $kg_js_ver,
                true
            );
            wp_localize_script( 'luwipress-knowledge-graph', 'lpKgConfig', array(
                'apiUrl'   => esc_url_raw( rest_url( 'luwipress/v1/knowledge-graph' ) ),
                'apiToken' => (string) get_option( 'luwipress_seo_api_token', '' ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
                'restBase' => esc_url_raw( rest_url( 'luwipress/v1/' ) ),
            ) );
        }

    }

    /**
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

        // Workflow/model breakdown (last 30 days)
        $workflow_breakdown = array();
        $model_breakdown    = array();
        if ( class_exists( 'LuwiPress_Token_Tracker' ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'luwipress_token_usage';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
                // By workflow
                $wf_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT workflow, COUNT(*) AS calls, SUM(input_tokens) AS input_tok, SUM(output_tokens) AS output_tok, SUM(estimated_cost) AS cost
                     FROM {$table} WHERE created_at >= %s GROUP BY workflow ORDER BY cost DESC",
                    gmdate( 'Y-m-d', strtotime( '-30 days' ) )
                ) );
                foreach ( $wf_rows as $r ) {
                    $workflow_breakdown[] = array(
                        'workflow'      => $r->workflow,
                        'calls'         => intval( $r->calls ),
                        'input_tokens'  => intval( $r->input_tok ),
                        'output_tokens' => intval( $r->output_tok ),
                        'cost'          => round( floatval( $r->cost ), 4 ),
                    );
                }
                // By model
                $m_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT model, provider, COUNT(*) AS calls, SUM(input_tokens) AS input_tok, SUM(output_tokens) AS output_tok, SUM(estimated_cost) AS cost
                     FROM {$table} WHERE created_at >= %s GROUP BY model, provider ORDER BY cost DESC",
                    gmdate( 'Y-m-d', strtotime( '-30 days' ) )
                ) );
                foreach ( $m_rows as $r ) {
                    $model_breakdown[] = array(
                        'model'         => $r->model,
                        'provider'      => $r->provider,
                        'calls'         => intval( $r->calls ),
                        'input_tokens'  => intval( $r->input_tok ),
                        'output_tokens' => intval( $r->output_tok ),
                        'cost'          => round( floatval( $r->cost ), 4 ),
                    );
                }
            }
        }

        wp_send_json_success( array(
            'products'           => $total_products,
            'revenue'            => $revenue_30d,
            'orders'             => $orders_30d,
            'ai_calls'           => $today_calls,
            'ai_cost_today'      => $today_cost,
            'budget_pct'         => $limit_pct,
            'daily_costs'        => $daily_costs,
            'workflow_breakdown' => $workflow_breakdown,
            'model_breakdown'    => $model_breakdown,
            'opportunities'      => $opps,
            'health_pct'         => $optimized_pct,
            'health_thin'        => $opps['thin_content'] ?? 0,
            'health_seo'         => $opps['missing_seo'] ?? 0,
            'logs'               => $logs,
            'trans_coverage'     => $trans_coverage,
            'currency'           => class_exists( 'WooCommerce' ) ? get_woocommerce_currency_symbol() : '$',
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

        // Legacy: external webhook support was removed in v2.0 — processing mode is always 'local'.
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
 * Build the standard _meta block sent with every webhook payload.
 *
 * Workflows read $json.body._meta.* so they never need per-site env variables.
 * Only the user's AI API key stays in provider credentials.
 *
 * @param string $callback_url  Full REST URL webhook should POST results back to.
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