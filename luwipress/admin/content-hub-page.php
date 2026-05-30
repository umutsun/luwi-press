<?php
/**
 * LuwiPress Content Hub (3.5.7+)
 *
 * Single submenu that houses every content-side tool — Health Audit,
 * Schema Picker, Schema Preview, Taxonomy Editor, Image Alt Bulk, Scheduler.
 * Each tab renders its existing page file with LUWIPRESS_HUB_INCLUDED defined
 * so the included page drops its outer wrap + h1 and emits tab-body only.
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

$tabs = array(
	'audit'     => array(
		'label' => __( 'Health Audit', 'luwipress' ),
		'icon'  => 'dashicons-shield',
		'file'  => $plugin_dir . 'admin/content-audit-page.php',
	),
	// Schema = the editor (Picker) + the live inspector (Preview) stacked in
	// one tab. Both pages suppress their own wrap/h1 under LUWIPRESS_HUB_INCLUDED,
	// so they render as consecutive chrome-less sections under a single tab.
	'schema'    => array(
		'label' => __( 'Schema', 'luwipress' ),
		'icon'  => 'dashicons-screenoptions',
		'files' => array(
			$plugin_dir . 'admin/schema-picker-page.php',
			$plugin_dir . 'admin/schema-preview-page.php',
		),
		'guard' => 'LuwiPress_Schema_Registry',
	),
	'taxonomy'  => array(
		'label' => __( 'Taxonomy', 'luwipress' ),
		'icon'  => 'dashicons-category',
		'file'  => $plugin_dir . 'admin/taxonomy-editor-page.php',
		'guard' => 'LuwiPress_Taxonomy_Editor',
	),
	'alt'       => array(
		'label' => __( 'Image Alt', 'luwipress' ),
		'icon'  => 'dashicons-format-image',
		'file'  => $plugin_dir . 'admin/image-alt-bulk-page.php',
	),
	'scheduler' => array(
		'label' => __( 'Scheduler', 'luwipress' ),
		'icon'  => 'dashicons-calendar-alt',
		'file'  => $plugin_dir . 'admin/scheduler-page.php',
		'guard' => 'LuwiPress_Content_Scheduler',
	),
);

$available = array();        // key => {label, icon} for the tab strip
$lwp_tab_files = array();    // key => string[] of page files to include
foreach ( $tabs as $key => $tab ) {
	if ( ! empty( $tab['guard'] ) && ! class_exists( $tab['guard'] ) ) {
		continue;
	}
	// Normalize to a files[] list — a tab may declare one `file` or several `files`.
	$files = isset( $tab['files'] ) ? (array) $tab['files'] : ( isset( $tab['file'] ) ? array( $tab['file'] ) : array() );
	$files = array_values( array_filter( $files, 'file_exists' ) );
	if ( empty( $files ) ) {
		continue;
	}
	$lwp_tab_files[ $key ] = $files;
	$available[ $key ]     = $tab;
}

$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
if ( ! isset( $available[ $requested ] ) ) {
	$keys      = array_keys( $available );
	$requested = $keys ? $keys[0] : '';
}

$current = $available[ $requested ] ?? null;
?>
<div class="wrap luwipress-hub luwipress-hub-content">

	<div class="lp-header">
		<div class="lp-header-left">
			<h1 class="lp-title">
				<img class="lp-logo" width="28" height="28"
				     src="<?php echo esc_url( LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo.png' ); ?>"
				     alt="LuwiPress" />
				<?php esc_html_e( 'Content', 'luwipress' ); ?>
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
		<?php esc_html_e( 'Content quality, schema, taxonomy, media, and scheduling tools. Drives the Brand Voice + Content Depth + Schema Coverage pillars of your Content Health Score.', 'luwipress' ); ?>
	</p>

	<nav class="lp-hub-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Content tools', 'luwipress' ); ?>">
		<?php foreach ( $available as $key => $tab ) :
			$url     = add_query_arg(
				array( 'page' => 'luwipress-content', 'tab' => $key ),
				admin_url( 'admin.php' )
			);
			$is_act  = ( $key === $requested );
			$cls     = 'lp-hub-tab' . ( $is_act ? ' lp-hub-tab--active' : '' );
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
		$lwp_hub_files = ( '' !== $requested && isset( $lwp_tab_files[ $requested ] ) ) ? $lwp_tab_files[ $requested ] : array();
		if ( ! empty( $lwp_hub_files ) ) {
			if ( ! defined( 'LUWIPRESS_HUB_INCLUDED' ) ) {
				define( 'LUWIPRESS_HUB_INCLUDED', true );
			}
			foreach ( $lwp_hub_files as $lwp_hub_file ) {
				include $lwp_hub_file;
			}
		} else {
			?>
			<div class="luwipress-card luwipress-card--warning">
				<p><?php esc_html_e( 'No content tools available — required modules are not active.', 'luwipress' ); ?></p>
			</div>
			<?php
		}
		?>
	</div>

</div>
