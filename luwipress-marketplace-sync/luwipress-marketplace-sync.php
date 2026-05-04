<?php
/**
 * Plugin Name: LuwiPress Marketplace Sync
 * Plugin URI: https://luwi.dev/luwipress-marketplace-sync
 * Description: Multi-marketplace product publishing for WooCommerce — Amazon, eBay, Trendyol, Hepsiburada, N11, Etsy, Walmart, Alibaba. Companion to the core LuwiPress plugin.
 * Version: 1.0.1
 * Author: Luwi Developments LLC
 * Author URI: https://luwi.dev
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: luwipress-marketplace-sync
 * Requires at least: 5.6
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: luwipress
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'LUWIPRESS_MARKETPLACE_VERSION', '1.0.1' );
define( 'LUWIPRESS_MARKETPLACE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LUWIPRESS_MARKETPLACE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LUWIPRESS_MARKETPLACE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Is the core LuwiPress plugin loaded? The companion piggybacks on core's
 * Permission class, Logger, and admin menu parent.
 */
function luwipress_marketplace_core_loaded() {
    return class_exists( 'LuwiPress_Permission' ) && class_exists( 'LuwiPress_Logger' );
}

/**
 * Soft inline notice — Plugins screen only.
 */
function luwipress_marketplace_core_missing_notice() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || 'plugins' !== $screen->id ) {
        return;
    }
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>'
        . esc_html__( 'LuwiPress Marketplace Sync is paused.', 'luwipress-marketplace-sync' )
        . '</strong> '
        . esc_html__( 'The core LuwiPress plugin is not active, so marketplace publishing is sitting idle until you reactivate it.', 'luwipress-marketplace-sync' )
        . '</p></div>';
}

/**
 * Soft inline notice when WooCommerce is missing — Plugins screen only.
 */
function luwipress_marketplace_woo_missing_notice() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || 'plugins' !== $screen->id ) {
        return;
    }
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    echo '<div class="notice notice-info"><p><strong>'
        . esc_html__( 'LuwiPress Marketplace Sync is waiting for WooCommerce.', 'luwipress-marketplace-sync' )
        . '</strong> '
        . esc_html__( 'You can configure credentials now; publishing will start once WooCommerce is active.', 'luwipress-marketplace-sync' )
        . '</p></div>';
}

/**
 * Bootstrap. Runs on plugins_loaded p20 — after the core plugin (p10).
 */
function luwipress_marketplace_init() {
    if ( ! luwipress_marketplace_core_loaded() ) {
        add_action( 'admin_notices', 'luwipress_marketplace_core_missing_notice' );
        return;
    }

    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'luwipress_marketplace_woo_missing_notice' );
        // Still load REST/admin so operator can configure credentials before
        // activating WooCommerce.
    }

    require_once LUWIPRESS_MARKETPLACE_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-adapter.php';
    require_once LUWIPRESS_MARKETPLACE_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-amazon.php';
    require_once LUWIPRESS_MARKETPLACE_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-ebay.php';
    require_once LUWIPRESS_MARKETPLACE_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-trendyol.php';
    require_once LUWIPRESS_MARKETPLACE_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-alibaba.php';
    require_once LUWIPRESS_MARKETPLACE_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-hepsiburada.php';
    require_once LUWIPRESS_MARKETPLACE_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-n11.php';
    require_once LUWIPRESS_MARKETPLACE_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-etsy.php';
    require_once LUWIPRESS_MARKETPLACE_PLUGIN_DIR . 'includes/marketplace/class-luwipress-marketplace-walmart.php';
    require_once LUWIPRESS_MARKETPLACE_PLUGIN_DIR . 'includes/class-luwipress-marketplace.php';

    // Instantiate manager (registers REST endpoints on rest_api_init).
    LuwiPress_Marketplace::get_instance();

    // Admin settings page (attaches under the LuwiPress parent menu so the
    // operator finds it where they expect — no separate top-level entry).
    if ( is_admin() ) {
        require_once LUWIPRESS_MARKETPLACE_PLUGIN_DIR . 'admin/marketplace-settings-page.php';
        add_action( 'admin_menu', 'luwipress_marketplace_add_admin_menu', 30 );
    }
}
add_action( 'plugins_loaded', 'luwipress_marketplace_init', 20 );

/**
 * Reject activation when core LuwiPress is missing.
 *
 * `register_activation_hook` fires BEFORE plugins_loaded, so we can't rely
 * on class_exists checks here unless the core plugin happens to be loaded
 * by an earlier activation in the same request. Instead we look for the
 * core plugin file in the standard plugins directory — works even if core
 * is installed-but-deactivated, the operator gets a clear next step.
 */
function luwipress_marketplace_activate() {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    if ( ! is_plugin_active( 'luwipress/luwipress.php' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            '<h1>' . esc_html__( 'Cannot activate LuwiPress Marketplace Sync', 'luwipress-marketplace-sync' ) . '</h1>'
            . '<p>' . esc_html__( 'LuwiPress Marketplace Sync is a companion plugin and needs the core LuwiPress plugin to be installed and active first.', 'luwipress-marketplace-sync' ) . '</p>'
            . '<p><strong>' . esc_html__( 'What to do:', 'luwipress-marketplace-sync' ) . '</strong></p>'
            . '<ol>'
            . '<li>' . esc_html__( 'Go to Plugins -> Add New and install LuwiPress (3.1.43 or newer).', 'luwipress-marketplace-sync' ) . '</li>'
            . '<li>' . esc_html__( 'Activate LuwiPress.', 'luwipress-marketplace-sync' ) . '</li>'
            . '<li>' . esc_html__( 'Come back here and activate LuwiPress Marketplace Sync.', 'luwipress-marketplace-sync' ) . '</li>'
            . '</ol>'
            . '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">&larr; ' . esc_html__( 'Back to Plugins', 'luwipress-marketplace-sync' ) . '</a></p>',
            esc_html__( 'Plugin dependency missing', 'luwipress-marketplace-sync' ),
            array( 'response' => 200, 'back_link' => true )
        );
    }

    // Core is present — create the listings table. dbDelta is idempotent so
    // re-running on every reactivation is safe.
    if ( ! class_exists( 'LuwiPress_Marketplace' ) ) {
        require_once LUWIPRESS_MARKETPLACE_PLUGIN_DIR . 'includes/class-luwipress-marketplace.php';
    }
    if ( method_exists( 'LuwiPress_Marketplace', 'create_table' ) ) {
        LuwiPress_Marketplace::create_table();
    }
}
register_activation_hook( __FILE__, 'luwipress_marketplace_activate' );

/**
 * Add the Marketplaces submenu under the LuwiPress parent.
 */
function luwipress_marketplace_add_admin_menu() {
    add_submenu_page(
        'luwipress',
        __( 'Marketplaces', 'luwipress-marketplace-sync' ),
        __( 'Marketplaces', 'luwipress-marketplace-sync' ),
        'manage_options',
        'luwipress-marketplaces',
        'luwipress_marketplace_render_settings_page'
    );
}
