<?php
/**
 * Plugin Name: LuwiPress WebMCP
 * Plugin URI: https://luwi.dev/luwipress-webmcp
 * Description: Model Context Protocol (MCP) server for LuwiPress — exposes 140+ REST tools to AI agents (Claude Code, OpenAI, custom clients) via Streamable HTTP transport. Requires the core LuwiPress plugin.
 * Version: 1.0.11
 * Author: Luwi Developments LLC
 * Author URI: https://luwi.dev
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: luwipress-webmcp
 * Requires at least: 5.6
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: luwipress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LUWIPRESS_WEBMCP_VERSION', '1.0.11' );
define( 'LUWIPRESS_WEBMCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LUWIPRESS_WEBMCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LUWIPRESS_WEBMCP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Is the core LuwiPress plugin loaded? The companion piggybacks on core's
 * Permission class, REST route registration, and admin menu parent.
 */
function luwipress_webmcp_core_active() {
	return class_exists( 'LuwiPress' ) && class_exists( 'LuwiPress_Permission' );
}

/**
 * Reject activation when core LuwiPress is missing.
 *
 * `register_activation_hook` fires BEFORE plugins_loaded, so we can't rely
 * on class_exists checks here unless the core plugin happens to be loaded
 * by an earlier activation in the same request. Instead we look for the
 * core plugin file in the standard plugins directory — works even if core
 * is installed-but-deactivated, the operator gets a clear next step.
 */
function luwipress_webmcp_activate() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$core_active = is_plugin_active( 'luwipress/luwipress.php' );
	if ( ! $core_active ) {
		// Bail with a friendly message. WP intercepts wp_die() during
		// activation and surfaces the message in the plugin list.
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			'<h1>' . esc_html__( 'Cannot activate LuwiPress WebMCP', 'luwipress-webmcp' ) . '</h1>'
			. '<p>' . esc_html__( 'LuwiPress WebMCP is a companion plugin and needs the core LuwiPress plugin to be installed and active first.', 'luwipress-webmcp' ) . '</p>'
			. '<p><strong>' . esc_html__( 'What to do:', 'luwipress-webmcp' ) . '</strong></p>'
			. '<ol>'
			. '<li>' . esc_html__( 'Go to Plugins -> Add New and install LuwiPress (3.1.43 or newer).', 'luwipress-webmcp' ) . '</li>'
			. '<li>' . esc_html__( 'Activate LuwiPress.', 'luwipress-webmcp' ) . '</li>'
			. '<li>' . esc_html__( 'Come back here and activate LuwiPress WebMCP.', 'luwipress-webmcp' ) . '</li>'
			. '</ol>'
			. '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">&larr; ' . esc_html__( 'Back to Plugins', 'luwipress-webmcp' ) . '</a></p>',
			esc_html__( 'Plugin dependency missing', 'luwipress-webmcp' ),
			array( 'response' => 200, 'back_link' => true )
		);
	}
}
register_activation_hook( __FILE__, 'luwipress_webmcp_activate' );

/**
 * Soft inline notice on the Plugins screen only — never on the WP dashboard
 * or any LuwiPress admin page where it would be alarmist. The activation
 * gate above already prevents accidental activation; this notice handles
 * the rare case where core is deactivated AFTER WebMCP is already running
 * (e.g. operator deactivates core for maintenance without touching companions).
 */
function luwipress_webmcp_core_missing_notice() {
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
			<strong><?php esc_html_e( 'LuwiPress WebMCP is paused.', 'luwipress-webmcp' ); ?></strong>
			<?php esc_html_e( 'The core LuwiPress plugin is not active, so WebMCP is sitting idle until you reactivate it.', 'luwipress-webmcp' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Bootstrap. Runs on plugins_loaded priority 20 so core (priority 10) is ready.
 */
function luwipress_webmcp_init() {
	if ( ! luwipress_webmcp_core_active() ) {
		add_action( 'admin_notices', 'luwipress_webmcp_core_missing_notice' );
		return;
	}

	require_once LUWIPRESS_WEBMCP_PLUGIN_DIR . 'includes/class-luwipress-webmcp.php';

	if ( LuwiPress_WebMCP::is_enabled() ) {
		LuwiPress_WebMCP::get_instance();
	}
}
add_action( 'plugins_loaded', 'luwipress_webmcp_init', 20 );

/**
 * Admin submenu — attaches to core LuwiPress menu parent.
 */
function luwipress_webmcp_admin_menu() {
	if ( ! luwipress_webmcp_core_active() ) {
		return;
	}
	add_submenu_page(
		'luwipress',
		__( 'WebMCP', 'luwipress-webmcp' ),
		__( 'WebMCP', 'luwipress-webmcp' ),
		'manage_options',
		'luwipress-webmcp',
		'luwipress_webmcp_render_admin_page'
	);
}
add_action( 'admin_menu', 'luwipress_webmcp_admin_menu', 20 );

/**
 * Render the WebMCP admin page from the companion's bundled template.
 */
function luwipress_webmcp_render_admin_page() {
	include LUWIPRESS_WEBMCP_PLUGIN_DIR . 'admin/webmcp-page.php';
}

/**
 * Enqueue the WebMCP client JS on the WebMCP admin page only.
 */
function luwipress_webmcp_admin_enqueue( $hook ) {
	if ( strpos( $hook, 'luwipress-webmcp' ) === false ) {
		return;
	}
	// Inherit core LuwiPress admin CSS (dashboard/card tokens) so layout stays consistent.
	if ( defined( 'LUWIPRESS_PLUGIN_URL' ) && defined( 'LUWIPRESS_VERSION' ) ) {
		wp_enqueue_style(
			'luwipress-admin',
			LUWIPRESS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			LUWIPRESS_VERSION
		);
	}
	wp_enqueue_style(
		'luwipress-webmcp-admin',
		LUWIPRESS_WEBMCP_PLUGIN_URL . 'assets/css/webmcp-admin.css',
		array( 'luwipress-admin' ),
		LUWIPRESS_WEBMCP_VERSION
	);
	wp_enqueue_script(
		'luwipress-webmcp-client',
		LUWIPRESS_WEBMCP_PLUGIN_URL . 'assets/js/webmcp-client.js',
		array(),
		LUWIPRESS_WEBMCP_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'luwipress_webmcp_admin_enqueue' );
