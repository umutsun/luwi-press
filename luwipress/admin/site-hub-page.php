<?php
/**
 * LuwiPress Site Hub (3.5.7+, Content folded in 3.7.3, grouped IA 3.7.3)
 *
 * One submenu ("Site") that houses every site + content tool, organised into
 * a small set of top tabs (groups). Each group renders its tools as
 * collapsible accordion sections. Two render modes keep the one-page-per-
 * request invariant intact:
 *
 *   - co-load : all the group's tool page-files render at once as real
 *               <details> accordions (operator can expand several at once).
 *               Used ONLY for groups whose tools are inert on load (no REST
 *               fired on render, uniquely-prefixed DOM ids).
 *   - lazy    : the open tool renders its page-file; the others are link rows
 *               that navigate (?tool=) to load themselves. Used when a group
 *               contains a tool that auto-fetches on load or is heavy, so they
 *               never co-render and can't cause a REST storm / id clash.
 *   - single  : one tool, included directly (it already ships its own
 *               internal accordions).
 *
 * URL scheme: ?tab=<group> selects the group; ?tool=<tool> selects which tool
 * is open inside a lazy/co-load group; a tool's OWN inner sub-tab (only Health
 * Audit has one) rides ?sub= so it never collides with ?tool=.
 *
 * Legacy deep-links (?tab=<toolkey>, e.g. the pre-grouping ?tab=audit or the
 * compat-redirected old flat slugs) resolve to their owning group automatically.
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

// Theme tab visibility — static bool so PHPStan can prove the method is used.
$theme_companion_active = false;
if ( class_exists( 'LuwiPress' ) ) {
	$theme_companion_active = (bool) LuwiPress::get_instance()->theme_companion_present();
}

// ── Tool registry (the 10 leaf tools) ───────────────────────────────
$lwp_tools = array(
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
	'audit'         => array(
		'label' => __( 'Health Audit', 'luwipress' ),
		'icon'  => 'dashicons-shield',
		'file'  => $plugin_dir . 'admin/content-audit-page.php',
	),
	'schema'        => array(
		'label' => __( 'Schema', 'luwipress' ),
		'icon'  => 'dashicons-screenoptions',
		'files' => array(
			$plugin_dir . 'admin/schema-picker-page.php',
			$plugin_dir . 'admin/schema-preview-page.php',
		),
		'guard' => 'LuwiPress_Schema_Registry',
	),
	'taxonomy'      => array(
		'label' => __( 'Taxonomy', 'luwipress' ),
		'icon'  => 'dashicons-category',
		'file'  => $plugin_dir . 'admin/taxonomy-editor-page.php',
		'guard' => 'LuwiPress_Taxonomy_Editor',
	),
	'alt'           => array(
		'label' => __( 'Image Alt', 'luwipress' ),
		'icon'  => 'dashicons-format-image',
		'file'  => $plugin_dir . 'admin/image-alt-bulk-page.php',
	),
	'scheduler'     => array(
		'label' => __( 'Scheduler', 'luwipress' ),
		'icon'  => 'dashicons-calendar-alt',
		'file'  => $plugin_dir . 'admin/scheduler-page.php',
		'guard' => 'LuwiPress_Content_Scheduler',
	),
);

// ── Group registry (the 5 top tabs). `mode` per load-safety analysis. ─
$lwp_groups = array(
	'content'  => array(
		'label' => __( 'Content', 'luwipress' ),
		'icon'  => 'dashicons-edit',
		'mode'  => 'coload', // audit + alt + scheduler are inert on load
		'tools' => array( 'audit', 'alt', 'scheduler' ),
	),
	'seo'      => array(
		'label' => __( 'SEO & Taxonomy', 'luwipress' ),
		'icon'  => 'dashicons-search',
		'mode'  => 'lazy',   // taxonomy + slug-resolver auto-fetch on load
		'tools' => array( 'schema', 'taxonomy', 'slug-resolver' ),
	),
	'security' => array(
		'label' => __( 'Security', 'luwipress' ),
		'icon'  => 'dashicons-shield-alt',
		'mode'  => 'lazy',   // bot-defense is heavy + auto-fetches
		'tools' => array( 'bot-defense', 'cookies' ),
	),
	'theme'    => array(
		'label' => __( 'Theme', 'luwipress' ),
		'icon'  => 'dashicons-admin-appearance',
		'mode'  => 'single',
		'tools' => array( 'theme' ),
	),
	'vendors'  => array(
		'label' => __( 'Vendors', 'luwipress' ),
		'icon'  => 'dashicons-store',
		'mode'  => 'single',
		'tools' => array( 'vendors' ),
	),
);

// ── Resolve which tools are available (guard / visible_if / file). ───
$lwp_tool_files = array(); // tool key => string[] of page files
foreach ( $lwp_tools as $tk => $tool ) {
	if ( ! empty( $tool['guard'] ) && ! class_exists( $tool['guard'] ) ) {
		continue;
	}
	if ( isset( $tool['visible_if'] ) && ! $tool['visible_if'] ) {
		continue;
	}
	$files = isset( $tool['files'] ) ? (array) $tool['files'] : ( isset( $tool['file'] ) ? array( $tool['file'] ) : array() );
	$files = array_values( array_filter( $files, 'file_exists' ) );
	if ( empty( $files ) ) {
		continue;
	}
	$lwp_tool_files[ $tk ] = $files;
}

// ── Build available groups (drop empty ones, filter tools to available). ─
$available_groups = array();
foreach ( $lwp_groups as $gk => $group ) {
	$tools_in = array();
	foreach ( $group['tools'] as $tk ) {
		if ( isset( $lwp_tool_files[ $tk ] ) ) {
			$tools_in[] = $tk;
		}
	}
	if ( empty( $tools_in ) ) {
		continue;
	}
	$group['tool_keys']   = $tools_in;
	$available_groups[ $gk ] = $group;
}

// ── Resolve the requested group + open tool. ─────────────────────────
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing.
$requested_group = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
$requested_tool  = isset( $_GET['tool'] ) ? sanitize_key( wp_unslash( $_GET['tool'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

// Legacy: ?tab=<toolkey> (pre-grouping deep-link / compat redirect target)
// maps to the tool's owning group, opening that tool.
if ( ! isset( $available_groups[ $requested_group ] ) && isset( $lwp_tool_files[ $requested_group ] ) ) {
	foreach ( $available_groups as $gk => $group ) {
		if ( in_array( $requested_group, $group['tool_keys'], true ) ) {
			$requested_tool  = $requested_group;
			$requested_group = $gk;
			break;
		}
	}
}
if ( ! isset( $available_groups[ $requested_group ] ) ) {
	$keys            = array_keys( $available_groups );
	$requested_group = $keys ? $keys[0] : '';
}

$group     = $available_groups[ $requested_group ] ?? null;
$group_key = $requested_group;
$tool_keys = $group ? $group['tool_keys'] : array();
if ( ! in_array( $requested_tool, $tool_keys, true ) ) {
	$requested_tool = $tool_keys ? $tool_keys[0] : '';
}

if ( ! defined( 'LUWIPRESS_HUB_INCLUDED' ) ) {
	define( 'LUWIPRESS_HUB_INCLUDED', true );
}

/**
 * Include a tool's page file(s).
 *
 * @param array $files string[] of absolute file paths.
 */
$lwp_render_tool = function ( $files ) {
	foreach ( $files as $f ) {
		include $f;
	}
};
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
		<?php esc_html_e( 'Site infrastructure and content tools, grouped — pick a section above; its tools open as collapsible panels below.', 'luwipress' ); ?>
	</p>

	<nav class="lp-hub-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Site sections', 'luwipress' ); ?>">
		<?php foreach ( $available_groups as $gk => $g ) :
			$url    = add_query_arg(
				array( 'page' => 'luwipress-site', 'tab' => $gk ),
				admin_url( 'admin.php' )
			);
			$is_act = ( $gk === $group_key );
			$cls    = 'lp-hub-tab' . ( $is_act ? ' lp-hub-tab--active' : '' );
		?>
		<a class="<?php echo esc_attr( $cls ); ?>"
		   href="<?php echo esc_url( $url ); ?>"
		   role="tab"
		   aria-selected="<?php echo $is_act ? 'true' : 'false'; ?>">
			<span class="dashicons <?php echo esc_attr( $g['icon'] ); ?>"></span>
			<span><?php echo esc_html( $g['label'] ); ?></span>
		</a>
		<?php endforeach; ?>
	</nav>

	<div class="lp-hub-body">
		<?php
		if ( ! $group || empty( $tool_keys ) ) {
			?>
			<div class="luwipress-card luwipress-card--warning">
				<p><?php esc_html_e( 'No tools available — required modules are not active.', 'luwipress' ); ?></p>
			</div>
			<?php
		} elseif ( 'single' === $group['mode'] ) {
			// One tool, no accordion wrapper — it ships its own internal sections.
			$lwp_render_tool( $lwp_tool_files[ $tool_keys[0] ] );
		} else {
			// co-load or lazy: render an accordion stack.
			$is_lazy = ( 'lazy' === $group['mode'] );
			echo '<div class="lwp-collapse-stack">';
			foreach ( $tool_keys as $tk ) {
				$tool    = $lwp_tools[ $tk ];
				$is_open = ( $tk === $requested_tool );
				if ( $is_lazy && ! $is_open ) {
					// Collapsed sibling = a link that loads this tool on click.
					$turl = add_query_arg(
						array( 'page' => 'luwipress-site', 'tab' => $group_key, 'tool' => $tk ),
						admin_url( 'admin.php' )
					);
					?>
					<a class="lp-collapse lp-collapse-link" href="<?php echo esc_url( $turl ); ?>">
						<span class="lp-collapse-link-summary">
							<span class="dashicons <?php echo esc_attr( $tool['icon'] ); ?>"></span>
							<span><?php echo esc_html( $tool['label'] ); ?></span>
						</span>
					</a>
					<?php
				} else {
					// co-load: every tool renders (open one expanded). lazy: only
					// the open tool reaches here (its file IS included).
					?>
					<details class="lp-collapse"<?php echo $is_open ? ' open' : ''; ?>>
					<summary><span class="dashicons <?php echo esc_attr( $tool['icon'] ); ?>"></span> <span><?php echo esc_html( $tool['label'] ); ?></span></summary>
					<div class="lp-collapse-body">
						<?php $lwp_render_tool( $lwp_tool_files[ $tk ] ); ?>
					</div><!-- .lp-collapse-body -->
					</details>
					<?php
				}
			}
			echo '</div><!-- .lwp-collapse-stack -->';
		}
		?>
	</div>

</div>
