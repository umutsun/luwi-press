<?php
/**
 * Plugin Name: LuwiPress Open Claw
 * Plugin URI: https://luwi.dev/luwipress-open-claw
 * Description: Admin-side AI chat assistant for LuwiPress. Natural language and slash commands to manage WooCommerce content, SEO, translations, enrichment, CRM, and more — routes through the LuwiPress AI Engine. Requires the core LuwiPress plugin.
 * Version: 1.0.1
 * Author: Luwi Developments LLC
 * Author URI: https://luwi.dev
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: luwipress-open-claw
 * Requires at least: 5.6
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: luwipress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LUWIPRESS_OPEN_CLAW_VERSION', '1.0.1' );
define( 'LUWIPRESS_OPEN_CLAW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LUWIPRESS_OPEN_CLAW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LUWIPRESS_OPEN_CLAW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Is the core LuwiPress plugin loaded? Open Claw delegates to core's
 * AI Engine, Permission, Token Tracker, Site Config, Plugin Detector, and
 * Job Queue classes. Without those, this companion is a no-op.
 */
function luwipress_open_claw_core_active() {
	return class_exists( 'LuwiPress' ) && class_exists( 'LuwiPress_AI_Engine' );
}

/**
 * Reject activation when core LuwiPress is missing.
 *
 * `register_activation_hook` fires BEFORE plugins_loaded; we check the
 * plugin file directly via is_plugin_active so the operator gets a clear
 * "install core first" path instead of a silent activation that does nothing.
 */
function luwipress_open_claw_activate() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active( 'luwipress/luwipress.php' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			'<h1>' . esc_html__( 'Cannot activate LuwiPress Open Claw', 'luwipress-open-claw' ) . '</h1>'
			. '<p>' . esc_html__( 'LuwiPress Open Claw is a companion plugin and needs the core LuwiPress plugin to be installed and active first.', 'luwipress-open-claw' ) . '</p>'
			. '<p><strong>' . esc_html__( 'What to do:', 'luwipress-open-claw' ) . '</strong></p>'
			. '<ol>'
			. '<li>' . esc_html__( 'Go to Plugins -> Add New and install LuwiPress (3.1.43 or newer).', 'luwipress-open-claw' ) . '</li>'
			. '<li>' . esc_html__( 'Activate LuwiPress.', 'luwipress-open-claw' ) . '</li>'
			. '<li>' . esc_html__( 'Come back here and activate LuwiPress Open Claw.', 'luwipress-open-claw' ) . '</li>'
			. '</ol>'
			. '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">&larr; ' . esc_html__( 'Back to Plugins', 'luwipress-open-claw' ) . '</a></p>',
			esc_html__( 'Plugin dependency missing', 'luwipress-open-claw' ),
			array( 'response' => 200, 'back_link' => true )
		);
	}
}
register_activation_hook( __FILE__, 'luwipress_open_claw_activate' );

/**
 * Soft inline notice on the Plugins screen only — keeps the dashboard clean.
 * The activation gate above prevents accidental enable; this notice handles
 * the rare case where core is deactivated AFTER Open Claw is already on.
 */
function luwipress_open_claw_core_missing_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'plugins' !== $screen->id ) {
		return;
	}
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-warning">
		<p>
			<strong><?php esc_html_e( 'LuwiPress Open Claw is paused.', 'luwipress-open-claw' ); ?></strong>
			<?php esc_html_e( 'The core LuwiPress plugin is not active, so the AI chat assistant is sitting idle until you reactivate it.', 'luwipress-open-claw' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Bootstrap. Runs on plugins_loaded priority 20 so core (priority 10) is ready.
 */
function luwipress_open_claw_init() {
	if ( ! luwipress_open_claw_core_active() ) {
		add_action( 'admin_notices', 'luwipress_open_claw_core_missing_notice' );
		return;
	}

	require_once LUWIPRESS_OPEN_CLAW_PLUGIN_DIR . 'includes/class-luwipress-open-claw.php';

	LuwiPress_Open_Claw::get_instance();
}
add_action( 'plugins_loaded', 'luwipress_open_claw_init', 20 );

/**
 * Admin submenu — attaches to the core LuwiPress menu parent.
 */
function luwipress_open_claw_admin_menu() {
	if ( ! luwipress_open_claw_core_active() ) {
		return;
	}
	add_submenu_page(
		'luwipress',
		__( 'Open Claw', 'luwipress-open-claw' ),
		__( 'Open Claw', 'luwipress-open-claw' ),
		'manage_options',
		'luwipress-claw',
		'luwipress_open_claw_render_admin_page'
	);
}
add_action( 'admin_menu', 'luwipress_open_claw_admin_menu', 20 );

function luwipress_open_claw_render_admin_page() {
	include LUWIPRESS_OPEN_CLAW_PLUGIN_DIR . 'admin/open-claw-page.php';
}

/**
 * Inherit core LuwiPress admin CSS/JS on the Open Claw page so existing
 * admin.js AJAX plumbing (claw_send / claw_history / claw_execute) works
 * as-is without duplicating assets.
 */
function luwipress_open_claw_admin_enqueue( $hook ) {
	if ( strpos( $hook, 'luwipress-claw' ) === false ) {
		return;
	}
	if ( defined( 'LUWIPRESS_PLUGIN_URL' ) && defined( 'LUWIPRESS_VERSION' ) ) {
		wp_enqueue_style(
			'luwipress-admin',
			LUWIPRESS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			LUWIPRESS_VERSION
		);
		wp_enqueue_script(
			'luwipress-admin',
			LUWIPRESS_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			LUWIPRESS_VERSION,
			true
		);
		$user = wp_get_current_user();
		wp_localize_script( 'luwipress-admin', 'luwipress', array(
			'ajax_url'     => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'luwipress_dashboard_nonce' ),
			'claw_nonce'   => wp_create_nonce( 'luwipress_claw_nonce' ),
			'user_initial' => mb_strtoupper( mb_substr( $user->display_name, 0, 1 ) ),
			'rest_root'    => esc_url_raw( rest_url() ),
			'rest_base'    => esc_url_raw( rest_url( 'luwipress/v1/' ) ),
		) );
	}
}
add_action( 'admin_enqueue_scripts', 'luwipress_open_claw_admin_enqueue' );
