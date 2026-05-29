<?php
/**
 * LuwiPress Site Hub (3.5.7+)
 *
 * Single submenu that houses every site-infrastructure tool — Slug Resolver,
 * Vendors, Theme bridge, Bot Defense, Cookie Consent. Each tab renders its
 * existing page file with LUWIPRESS_HUB_INCLUDED defined so the included page
 * drops its outer wrap + h1 and emits tab-body only.
 *
 * @package LuwiPress
 * @since   3.5.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
}

$plugin_dir = LUWIPRESS_PLUGIN_DIR;

// Pre-compute the Theme tab's visibility so the gate is a static bool by the
// time PHPStan walks the $tabs array. Calls LuwiPress::theme_companion_present()
// directly (no dynamic dispatch) which lets static analysis prove the method
// is used.
$theme_companion_active = false;
if ( class_exists( 'LuwiPress' ) ) {
	$theme_companion_active = (bool) LuwiPress::get_instance()->theme_companion_present();
}

$tabs = array(
	'slug-resolver' => array(
		'label' => __( 'Slug Resolver', 'luwipress' ),
		'icon'  => 'dashicons-randomize',
		'file'  => $plugin_dir . 'admin/slug-resolver-page.php',
		'guard' => 'LuwiPress_Slug_Resolver',
	),
	'vendors'       => array(
		'label' => __( 'Vendors', 'luwipress' ),
		'icon'  => 'dashicons-store',
		'file'  => $plugin_dir . 'admin/vendors-page.php',
		'guard' => 'LuwiPress_Vendors',
	),
	'theme'         => array(
		'label'      => __( 'Theme', 'luwipress' ),
		'icon'       => 'dashicons-admin-appearance',
		'file'       => $plugin_dir . 'admin/theme-page.php',
		'visible_if' => $theme_companion_active,
	),
	'bot-defense'   => array(
		'label' => __( 'Bot Defense', 'luwipress' ),
		'icon'  => 'dashicons-shield-alt',
		'file'  => $plugin_dir . 'admin/bot-defense-page.php',
	),
	'cookies'       => array(
		'label' => __( 'Cookie Consent', 'luwipress' ),
		'icon'  => 'dashicons-privacy',
		'file'  => $plugin_dir . 'admin/cookies-page.php',
	),
);

$available = array();
foreach ( $tabs as $key => $tab ) {
	if ( ! empty( $tab['guard'] ) && ! class_exists( $tab['guard'] ) ) {
		continue;
	}
	// `visible_if` is a pre-computed bool. When set to false, the tab is
	// suppressed even if its guard class is loaded.
	if ( isset( $tab['visible_if'] ) && ! $tab['visible_if'] ) {
		continue;
	}
	if ( ! file_exists( $tab['file'] ) ) {
		continue;
	}
	$available[ $key ] = $tab;
}

$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
if ( ! isset( $available[ $requested ] ) ) {
	$keys      = array_keys( $available );
	$requested = $keys ? $keys[0] : '';
}

$current = $available[ $requested ] ?? null;
?>
<div class="wrap luwipress-hub luwipress-hub-site">

	<div class="lp-header">
		<div class="lp-header-left">
			<h1 class="lp-title">
				<img class="lp-logo" width="28" height="28"
				     src="<?php echo esc_url( LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo.png' ); ?>"
				     alt="LuwiPress" />
				<?php esc_html_e( 'Site', 'luwipress' ); ?>
			</h1>
		</div>
		<div class="lp-header-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-knowledge-graph' ) ); ?>"
			   class="lp-pill lp-pill--action pill-neutral lp-pill--icon"
			   title="<?php esc_attr_e( 'Knowledge Graph', 'luwipress' ); ?>">
				<span class="dashicons dashicons-networking"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Knowledge Graph', 'luwipress' ); ?></span>
			</a>
			<span class="lp-pill pill-neutral" title="<?php esc_attr_e( 'Plugin version', 'luwipress' ); ?>">
				v<?php echo esc_html( LUWIPRESS_VERSION ); ?>
			</span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-settings' ) ); ?>"
			   class="lp-pill lp-pill--action pill-neutral lp-pill--icon"
			   title="<?php esc_attr_e( 'Settings', 'luwipress' ); ?>">
				<span class="dashicons dashicons-admin-generic"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Settings', 'luwipress' ); ?></span>
			</a>
		</div>
	</div>

	<p class="lp-hub-intro">
		<?php esc_html_e( 'Migration safety, vendor identity, theme bridge, and security tools. Use Slug Resolver before any DNS swap, Vendors for maker / luthier / atelier profiles, Bot Defense + Cookies for compliance.', 'luwipress' ); ?>
	</p>

	<nav class="lp-hub-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Site tools', 'luwipress' ); ?>">
		<?php foreach ( $available as $key => $tab ) :
			$url    = add_query_arg(
				array( 'page' => 'luwipress-site', 'tab' => $key ),
				admin_url( 'admin.php' )
			);
			$is_act = ( $key === $requested );
			$cls    = 'lp-hub-tab' . ( $is_act ? ' lp-hub-tab--active' : '' );
		?>
		<a class="<?php echo esc_attr( $cls ); ?>"
		   href="<?php echo esc_url( $url ); ?>"
		   role="tab"
		   aria-selected="<?php echo $is_act ? 'true' : 'false'; ?>">
			<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
			<span><?php echo esc_html( $tab['label'] ); ?></span>
		</a>
		<?php endforeach; ?>
	</nav>

	<div class="lp-hub-body">
		<?php
		if ( $current && file_exists( $current['file'] ) ) {
			if ( ! defined( 'LUWIPRESS_HUB_INCLUDED' ) ) {
				define( 'LUWIPRESS_HUB_INCLUDED', true );
			}
			include $current['file'];
		} else {
			?>
			<div class="luwipress-card luwipress-card--warning">
				<p><?php esc_html_e( 'No site tools available — required modules are not active.', 'luwipress' ); ?></p>
			</div>
			<?php
		}
		?>
	</div>

</div>
