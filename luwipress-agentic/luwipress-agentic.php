<?php
/**
 * Plugin Name: LuwiPress Agentic
 * Plugin URI: https://luwi.dev/luwipress-agentic
 * Description: Agentic middleware for LuwiPress — uniform admin chat surface, pluggable agent backend, plus the Agentic Commerce hub (Google UCP feed + native checkout and AP2 payment mandates). Ships with Open Claw (oc.luwi.dev) and Hermes (hermes.luwi.dev) runtime adapters; operators pick the active backend and can point either at their own self-hosted endpoint. Requires the core LuwiPress plugin.
 * Version: 1.3.0
 * Author: Luwi Developments LLC
 * Author URI: https://luwi.dev
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: luwipress-agentic
 * Requires at least: 5.6
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: luwipress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LUWIPRESS_AGENTIC_VERSION', '1.3.0' );
define( 'LUWIPRESS_AGENTIC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LUWIPRESS_AGENTIC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LUWIPRESS_AGENTIC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Is the core LuwiPress plugin loaded? Agentic delegates to core's
 * Permission, Token Tracker, Site Config, Plugin Detector, and Job Queue
 * classes. Without those, this companion is a no-op.
 */
function luwipress_agentic_core_active() {
	return class_exists( 'LuwiPress' ) && class_exists( 'LuwiPress_AI_Engine' );
}

/**
 * Reject activation when core LuwiPress is missing.
 */
function luwipress_agentic_activate() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active( 'luwipress/luwipress.php' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			'<h1>' . esc_html__( 'Cannot activate LuwiPress Agentic', 'luwipress-agentic' ) . '</h1>'
			. '<p>' . esc_html__( 'LuwiPress Agentic is a companion plugin and needs the core LuwiPress plugin to be installed and active first.', 'luwipress-agentic' ) . '</p>'
			. '<p><strong>' . esc_html__( 'What to do:', 'luwipress-agentic' ) . '</strong></p>'
			. '<ol>'
			. '<li>' . esc_html__( 'Go to Plugins -> Add New and install LuwiPress (3.1.43 or newer).', 'luwipress-agentic' ) . '</li>'
			. '<li>' . esc_html__( 'Activate LuwiPress.', 'luwipress-agentic' ) . '</li>'
			. '<li>' . esc_html__( 'Come back here and activate LuwiPress Agentic.', 'luwipress-agentic' ) . '</li>'
			. '</ol>'
			. '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">&larr; ' . esc_html__( 'Back to Plugins', 'luwipress-agentic' ) . '</a></p>',
			esc_html__( 'Plugin dependency missing', 'luwipress-agentic' ),
			array( 'response' => 200, 'back_link' => true )
		);
	}
}
register_activation_hook( __FILE__, 'luwipress_agentic_activate' );

/**
 * Soft inline notice on the Plugins screen only — keeps the dashboard clean.
 * The activation gate above prevents accidental enable; this notice handles
 * the rare case where core is deactivated AFTER Agentic is already on.
 */
function luwipress_agentic_core_missing_notice() {
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
			<strong><?php esc_html_e( 'LuwiPress Agentic is paused.', 'luwipress-agentic' ); ?></strong>
			<?php esc_html_e( 'The core LuwiPress plugin is not active, so the agentic assistant is sitting idle until you reactivate it.', 'luwipress-agentic' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Bootstrap. Runs on plugins_loaded priority 20 so core (priority 10) is ready.
 */
function luwipress_agentic_init() {
	if ( ! luwipress_agentic_core_active() ) {
		add_action( 'admin_notices', 'luwipress_agentic_core_missing_notice' );
		return;
	}

	// Agent host + adapter contract (runtime-neutral plumbing).
	require_once LUWIPRESS_AGENTIC_PLUGIN_DIR . 'includes/interface-agent-adapter.php';
	require_once LUWIPRESS_AGENTIC_PLUGIN_DIR . 'includes/class-agent-host.php';
	require_once LUWIPRESS_AGENTIC_PLUGIN_DIR . 'includes/adapters/class-http-adapter.php';

	add_action( 'luwipress_agent_register', 'luwipress_agentic_register_default_adapters', 5 );

	// Boot the host — its constructor fires `luwipress_agent_register`.
	LuwiPress_Agent_Host::get_instance();

	require_once LUWIPRESS_AGENTIC_PLUGIN_DIR . 'includes/class-luwipress-agentic.php';

	LuwiPress_Agentic::get_instance();

	// Agentic Commerce — Google UCP (feed + native checkout) + AP2 mandate
	// audit trail. Moved here from core in core 3.6.2 / agentic 1.3.0 so the
	// core stays lean. Order matters: UCP before checkout (checkout resolves
	// UCP product meta), AP2 last (checkout composes with it via class_exists).
	require_once LUWIPRESS_AGENTIC_PLUGIN_DIR . 'includes/class-luwipress-ucp.php';
	require_once LUWIPRESS_AGENTIC_PLUGIN_DIR . 'includes/class-luwipress-ucp-checkout.php';
	require_once LUWIPRESS_AGENTIC_PLUGIN_DIR . 'includes/class-luwipress-ap2.php';

	LuwiPress_UCP::get_instance();
	LuwiPress_UCP_Checkout::get_instance();
	LuwiPress_AP2::get_instance();

	// Ensure the checkout sessions table exists. Activation only fires on
	// activate (not on a ZIP-replace update), so gate on a stored version and
	// create/upgrade the table whenever the agentic version advances.
	$ucp_db = get_option( 'luwipress_agentic_db_version', '0' );
	if ( version_compare( $ucp_db, LUWIPRESS_AGENTIC_VERSION, '<' ) ) {
		LuwiPress_UCP_Checkout::create_table();
		update_option( 'luwipress_agentic_db_version', LUWIPRESS_AGENTIC_VERSION );
	}
}

function luwipress_agentic_register_default_adapters( $host ) {
	if ( ! ( $host instanceof LuwiPress_Agent_Host ) ) {
		return;
	}
	$host->register( new LuwiPress_Agent_Adapter_HTTP(
		'open-claw',
		__( 'Open Claw', 'luwipress-agentic' ),
		'luwipress_agent_open_claw',
		'https://oc.luwi.dev/agent'
	) );
	$host->register( new LuwiPress_Agent_Adapter_HTTP(
		'hermes',
		__( 'Hermes', 'luwipress-agentic' ),
		'luwipress_agent_hermes',
		'https://hermes.luwi.dev/agent'
	) );
}
add_action( 'plugins_loaded', 'luwipress_agentic_init', 20 );

/**
 * Admin submenu — attaches to the core LuwiPress menu parent.
 */
function luwipress_agentic_admin_menu() {
	if ( ! luwipress_agentic_core_active() ) {
		return;
	}
	add_submenu_page(
		'luwipress',
		__( 'Agentic', 'luwipress-agentic' ),
		__( 'Agentic', 'luwipress-agentic' ),
		'manage_options',
		'luwipress-agentic',
		'luwipress_agentic_render_admin_page'
	);

	// Agentic Commerce hub (Google UCP + AP2) — moved here from core 3.6.2.
	// Register only when the commerce modules actually loaded.
	if ( class_exists( 'LuwiPress_UCP' ) ) {
		add_submenu_page(
			'luwipress',
			__( 'Commerce', 'luwipress-agentic' ),
			__( 'Commerce', 'luwipress-agentic' ),
			'manage_options',
			'luwipress-commerce',
			'luwipress_agentic_render_commerce_page'
		);
	}
	// As of 1.1.1 the settings live as the "Agentic" tab inside the core
	// LuwiPress Settings page. Register the old URL as a HIDDEN route so any
	// existing bookmarks resolve — the admin_init redirect below sends them
	// to the new location.
	add_submenu_page(
		'',
		__( 'Agentic Settings', 'luwipress-agentic' ),
		__( 'Agentic Settings', 'luwipress-agentic' ),
		'manage_options',
		'luwipress-agentic-settings',
		'luwipress_agentic_render_settings_page'
	);
}
add_action( 'admin_menu', 'luwipress_agentic_admin_menu', 20 );

function luwipress_agentic_render_admin_page() {
	include LUWIPRESS_AGENTIC_PLUGIN_DIR . 'admin/agentic-page.php';
}

/**
 * Render the Agentic Commerce hub (UCP + AP2). Enqueues the core LuwiPress
 * admin design system so the page speaks the same lp-header / lp-hub-tabs /
 * luwipress-card language as the rest of the LuwiPress admin.
 */
function luwipress_agentic_render_commerce_page() {
	if ( defined( 'LUWIPRESS_PLUGIN_URL' ) ) {
		$ver = defined( 'LUWIPRESS_VERSION' ) ? LUWIPRESS_VERSION : LUWIPRESS_AGENTIC_VERSION;
		wp_enqueue_style(
			'luwipress-admin',
			LUWIPRESS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			$ver
		);
	}
	include LUWIPRESS_AGENTIC_PLUGIN_DIR . 'admin/agentic-commerce-page.php';
}

function luwipress_agentic_render_settings_page() {
	include LUWIPRESS_AGENTIC_PLUGIN_DIR . 'admin/settings-page.php';
}

/**
 * Register the Agentic tab inside the core LuwiPress Settings page (introduced
 * by the `luwipress_settings_render_tab_nav` / `_content` actions in core 3.2.4).
 */
function luwipress_agentic_settings_tab_nav( $active_tab ) {
	$is_active = ( 'agentic' === $active_tab ) ? 'nav-tab-active' : '';
	?>
	<a href="?page=luwipress-settings&tab=agentic" class="nav-tab <?php echo esc_attr( $is_active ); ?>">
		<span class="dashicons dashicons-superhero-alt"></span> <?php esc_html_e( 'Agentic', 'luwipress-agentic' ); ?>
	</a>
	<?php
}
add_action( 'luwipress_settings_render_tab_nav', 'luwipress_agentic_settings_tab_nav' );

function luwipress_agentic_settings_tab_content( $active_tab ) {
	$classes = 'luwipress-tab-content' . ( 'agentic' === $active_tab ? ' tab-active' : '' );
	?>
	<div class="<?php echo esc_attr( $classes ); ?>" id="tab-agentic">
		<div class="luwipress-card">
			<h2><span class="dashicons dashicons-superhero-alt" style="vertical-align:middle;"></span> <?php esc_html_e( 'Agent runtime', 'luwipress-agentic' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Pick which agent runtime drives the chat surface. The UI stays the same — only the backend changes.', 'luwipress-agentic' ); ?></p>
			<?php include LUWIPRESS_AGENTIC_PLUGIN_DIR . 'admin/settings-fragment.php'; ?>
		</div>
	</div>
	<?php
}
add_action( 'luwipress_settings_render_tab_content', 'luwipress_agentic_settings_tab_content' );

/**
 * Back-compat redirect — the standalone settings URL now bounces to the new
 * tab under core's main Settings page. Keeps any existing bookmarks alive.
 */
function luwipress_agentic_redirect_legacy_settings() {
	if ( ! is_admin() ) {
		return;
	}
	if ( isset( $_GET['page'] ) && $_GET['page'] === 'luwipress-agentic-settings' && empty( $_GET['lwp_keep'] ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=luwipress-settings&tab=agentic' ) );
		exit;
	}
}
add_action( 'admin_init', 'luwipress_agentic_redirect_legacy_settings' );

/**
 * Inherit core LuwiPress admin CSS/JS on the Agentic page so existing
 * admin.js AJAX plumbing (claw_send / claw_history / claw_execute) works
 * as-is without duplicating assets.
 */
function luwipress_agentic_admin_enqueue( $hook ) {
	// Fires on (a) the standalone Agentic chat page, (b) the legacy hidden
	// settings URL, and (c) core's main Settings page (where the Agentic tab
	// now lives — admin.js AJAX nonce is required for the per-card save flow).
	$is_agentic_screen  = strpos( $hook, 'luwipress-agentic' ) !== false;
	$is_core_settings   = ( $hook === 'luwipress_page_luwipress-settings' );
	if ( ! $is_agentic_screen && ! $is_core_settings ) {
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
add_action( 'admin_enqueue_scripts', 'luwipress_agentic_admin_enqueue' );
