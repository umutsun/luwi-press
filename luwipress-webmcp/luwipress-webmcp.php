<?php
/**
 * Plugin Name: LuwiPress WebMCP
 * Plugin URI: https://luwi.dev/luwipress-webmcp
 * Description: Model Context Protocol (MCP) server for LuwiPress — exposes 130+ REST tools to AI agents (Claude Code, OpenAI, custom clients) via Streamable HTTP transport. Requires the core LuwiPress plugin.
 * Version: 1.0.0
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

define( 'LUWIPRESS_WEBMCP_VERSION', '1.0.0' );
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
 * Show an admin notice when core is missing.
 */
function luwipress_webmcp_core_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'LuwiPress WebMCP requires the core LuwiPress plugin.', 'luwipress-webmcp' ); ?></strong>
			<?php esc_html_e( 'Install and activate LuwiPress (2.0.0 or newer) before enabling WebMCP.', 'luwipress-webmcp' ); ?>
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
	wp_enqueue_script(
		'luwipress-webmcp-client',
		LUWIPRESS_WEBMCP_PLUGIN_URL . 'assets/js/webmcp-client.js',
		array(),
		LUWIPRESS_WEBMCP_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'luwipress_webmcp_admin_enqueue' );
