<?php
/**
 * LuwiPress Theme page
 *
 * Three tabs:
 *   • Status   — active theme, ecosystem detect, capability matrix
 *   • Tools    — registered maintenance tools (scan/execute/restore + backups)
 *   • Settings — theme_mod proxies declared via `luwipress_theme_settings`
 *
 * The page only renders when the active theme has registered itself with the
 * companion contract; the menu item is hidden otherwise (see luwipress.php
 * `theme_companion_present()`).
 *
 * Design language: matches the LuwiPress Dashboard — `lp-header` + `lp-ribbon`
 * + canonical `.luwipress-stat-card` / `.luwipress-section` / `.lp-pill` classes
 * from `assets/css/admin.css`. No hard-coded hex; everything resolves through
 * `--lp-*` design tokens so dark-mode and theme overrides cascade for free.
 *
 * @package LuwiPress
 * @since   3.1.48
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
}

$bridge   = LuwiPress_Theme_Bridge::get_instance();
$tools    = $bridge->get_tools();
$settings = $bridge->get_settings();
$theme    = wp_get_theme();
$slug     = get_stylesheet();
$detector = class_exists( 'LuwiPress_Plugin_Detector' ) ? LuwiPress_Plugin_Detector::get_instance() : null;
$detect   = $detector ? $detector->detect_theme() : array();
$companion= apply_filters( 'luwipress_theme_companion', array() );
$caps     = isset( $companion[ $slug ] ) && is_array( $companion[ $slug ] ) ? $companion[ $slug ] : array();
$cap_list = isset( $caps['capabilities'] ) && is_array( $caps['capabilities'] ) ? $caps['capabilities'] : array();
$snap     = $bridge->status_snapshot();

// Companion classification used in two spots (header pill + status card).
$is_official_companion = ! empty( $detect['is_official_companion'] );
$is_third_party        = ! $is_official_companion && ! empty( $caps );

// Group settings by their `group` field so the form renders sectioned cards
// without the theme having to register a separate group taxonomy.
$grouped_settings = array();
foreach ( $settings as $s ) {
	$grouped_settings[ $s['group'] ][] = $s;
}

// Sub-tab selection — 3.5.8+ this page can render inside the Site hub
// (`?page=luwipress-site&tab=theme&sub=...`) so we read `sub` first and
// fall back to the standalone `tab` parameter for direct deep links.
// Pre-3.5.8 the strip had Status / Tools / Settings; 3.5.8 merges Status
// + Tools into a single "Overview" tab so the surface stops feeling like
// it has two near-duplicate landing screens. Legacy `tab=status` and
// `tab=tools` both resolve to the new `overview` so deep links + the
// settings_url cross-link from Bot Defense don't 404.
$lwp_theme_sub_raw = '';
if ( isset( $_GET['sub'] ) ) {
	$lwp_theme_sub_raw = sanitize_key( wp_unslash( $_GET['sub'] ) );
} elseif ( isset( $_GET['tab'] ) ) {
	$lwp_theme_sub_raw = sanitize_key( wp_unslash( $_GET['tab'] ) );
}
if ( in_array( $lwp_theme_sub_raw, array( 'status', 'tools' ), true ) ) {
	$lwp_theme_sub_raw = 'overview';
}
$active_tab = in_array( $lwp_theme_sub_raw, array( 'overview', 'settings' ), true )
	? $lwp_theme_sub_raw
	: 'overview';

$nonce = wp_create_nonce( 'luwipress_theme_tools' );

// Helpers for the status cards — the bridge returns raw counts; we map
// each to a semantic accent so the operator's eye lands on red/amber first.
$findings_state    = $snap['findings'] > 0 ? 'stat-warning' : 'stat-success';
$untrans_state     = $snap['untranslated'] > 0 ? 'stat-warning' : 'stat-success';
$kit_pct           = isset( $snap['kit_css']['pct_limit'] ) ? (float) $snap['kit_css']['pct_limit'] : 0;
$kit_state         = $kit_pct > 90 ? 'stat-error' : ( $kit_pct > 70 ? 'stat-warning' : 'stat-success' );
$companion_state   = $is_official_companion ? 'stat-success' : ( $is_third_party ? 'stat-translation' : 'stat-warning' );
?>
<?php $luwipress_hub_mode = defined( 'LUWIPRESS_HUB_INCLUDED' ); ?>
<?php if ( ! $luwipress_hub_mode ) : ?>
<div class="wrap luwipress-admin luwipress-dashboard luwipress-theme-page">
<?php endif; ?>

	<!-- ═══ HEADER ═══ -->
	<?php if ( ! $luwipress_hub_mode ) : ?>
	<!-- Same header chrome as the LuwiPress Dashboard — keeps the brand mark on
	     the left and a tight pill row on the right. The Settings shortcut here
	     points at the THEME settings tab so operators stay in context. -->
	<div class="lp-header">
		<div class="lp-header-left">
			<h1 class="lp-title">
				<img class="lp-logo" width="28" height="28" src="<?php echo esc_url( LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo.png' ); ?>" alt="LuwiPress" />
				<?php esc_html_e( 'Theme', 'luwipress' ); ?>
			</h1>
			<p class="lp-subtitle">
				<?php
				/* translators: %s: theme name */
				printf( esc_html__( 'Maintenance + settings for %s', 'luwipress' ), esc_html( $theme->get( 'Name' ) ) );
				?>
			</p>
		</div>
		<div class="lp-header-actions">
			<?php if ( $is_official_companion ) : ?>
				<span class="lp-pill pill-success" title="<?php esc_attr_e( 'Official LuwiPress companion theme — full ecosystem features active.', 'luwipress' ); ?>">
					✓ <?php echo esc_html( $theme->get( 'Name' ) ); ?> v<?php echo esc_html( $theme->get( 'Version' ) ); ?>
				</span>
			<?php elseif ( $is_third_party ) : ?>
				<span class="lp-pill pill-neutral" title="<?php esc_attr_e( 'Third-party theme has registered with the LuwiPress companion contract.', 'luwipress' ); ?>">
					<?php echo esc_html( $theme->get( 'Name' ) ); ?> v<?php echo esc_html( $theme->get( 'Version' ) ); ?>
				</span>
			<?php else : ?>
				<span class="lp-pill pill-warning" title="<?php esc_attr_e( 'Active theme has not registered with LuwiPress — capabilities cannot be amplified.', 'luwipress' ); ?>">
					<?php echo esc_html( $theme->get( 'Name' ) ); ?> v<?php echo esc_html( $theme->get( 'Version' ) ); ?>
				</span>
			<?php endif; ?>
			<span class="lp-pill pill-neutral" title="<?php esc_attr_e( 'Theme stylesheet slug', 'luwipress' ); ?>">
				<?php echo esc_html( $slug ); ?>
			</span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress' ) ); ?>"
			   class="lp-pill lp-pill--action pill-neutral lp-pill--icon"
			   title="<?php esc_attr_e( 'Back to LuwiPress Dashboard', 'luwipress' ); ?>">
				<span class="dashicons dashicons-admin-home"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Dashboard', 'luwipress' ); ?></span>
			</a>
		</div>
	</div>
	<?php endif; ?>

	<!-- ═══ TAB NAV — hub-aware URLs + Status + Tools merged into Overview ═══ -->
	<?php
	$lwp_theme_hub_mode = defined( 'LUWIPRESS_HUB_INCLUDED' );
	$lwp_theme_tab_url  = function ( $sub ) use ( $lwp_theme_hub_mode ) {
		if ( $lwp_theme_hub_mode ) {
			return add_query_arg(
				array( 'page' => 'luwipress-site', 'tab' => 'theme', 'sub' => $sub ),
				admin_url( 'admin.php' )
			);
		}
		return add_query_arg(
			array( 'page' => 'luwipress-theme', 'tab' => $sub ),
			admin_url( 'admin.php' )
		);
	};
	$lwp_theme_total = (int) count( $tools ) + (int) count( $settings );
	?>
	<nav class="lp-hub-tabs lwp-theme-subtabs" role="tablist" aria-label="<?php esc_attr_e( 'Theme bridge sections', 'luwipress' ); ?>">
		<a href="<?php echo esc_url( $lwp_theme_tab_url( 'overview' ) ); ?>"
		   class="lp-hub-tab <?php echo $active_tab === 'overview' ? 'lp-hub-tab--active' : ''; ?>"
		   role="tab"
		   aria-selected="<?php echo $active_tab === 'overview' ? 'true' : 'false'; ?>">
			<span class="dashicons dashicons-chart-pie"></span>
			<span><?php esc_html_e( 'Overview', 'luwipress' ); ?></span>
			<?php if ( $tools ) : ?><span class="lp-hub-tab-badge"><?php echo (int) count( $tools ); ?></span><?php endif; ?>
		</a>
		<a href="<?php echo esc_url( $lwp_theme_tab_url( 'settings' ) ); ?>"
		   class="lp-hub-tab <?php echo $active_tab === 'settings' ? 'lp-hub-tab--active' : ''; ?>"
		   role="tab"
		   aria-selected="<?php echo $active_tab === 'settings' ? 'true' : 'false'; ?>">
			<span class="dashicons dashicons-admin-generic"></span>
			<span><?php esc_html_e( 'Settings', 'luwipress' ); ?></span>
			<?php if ( $settings ) : ?><span class="lp-hub-tab-badge"><?php echo (int) count( $settings ); ?></span><?php endif; ?>
		</a>
	</nav>

	<?php if ( $active_tab === 'overview' ) : ?>

		<!-- ═══ STAT CARDS ═══ -->
		<!-- Mirrors the Dashboard / KG hero pattern — semantic left-border colour
		     drags the eye to red/amber. Layout grid + accent classes are defined
		     in admin.css; no inline hex needed here. -->
		<div class="luwipress-stats-row">

			<div class="luwipress-stat-card stat-translation">
				<div class="stat-icon"><span class="dashicons dashicons-admin-appearance"></span></div>
				<div class="stat-content">
					<span class="stat-number"><?php echo esc_html( $theme->get( 'Name' ) ); ?></span>
					<span class="stat-label">
						<?php esc_html_e( 'Active theme', 'luwipress' ); ?>
						· v<?php echo esc_html( $theme->get( 'Version' ) ); ?>
					</span>
				</div>
			</div>

			<div class="luwipress-stat-card <?php echo esc_attr( $companion_state ); ?>">
				<div class="stat-icon"><span class="dashicons dashicons-admin-plugins"></span></div>
				<div class="stat-content">
					<span class="stat-number">
						<?php
						if ( $is_official_companion ) {
							esc_html_e( 'Official', 'luwipress' );
						} elseif ( $is_third_party ) {
							esc_html_e( 'Third-party', 'luwipress' );
						} else {
							esc_html_e( 'Generic', 'luwipress' );
						}
						?>
					</span>
					<span class="stat-label"><?php esc_html_e( 'Companion contract', 'luwipress' ); ?></span>
				</div>
			</div>

			<div class="luwipress-stat-card">
				<div class="stat-icon"><span class="dashicons dashicons-admin-tools"></span></div>
				<div class="stat-content">
					<span class="stat-number"><?php echo (int) $snap['tools']; ?></span>
					<span class="stat-label">
						<?php esc_html_e( 'Tools registered', 'luwipress' ); ?>
						· <?php echo (int) $snap['settings']; ?> <?php esc_html_e( 'settings', 'luwipress' ); ?>
					</span>
				</div>
			</div>

			<div class="luwipress-stat-card <?php echo esc_attr( $findings_state ); ?>">
				<div class="stat-icon"><span class="dashicons dashicons-warning"></span></div>
				<div class="stat-content">
					<span class="stat-number"><?php echo (int) $snap['findings']; ?></span>
					<span class="stat-label"><?php esc_html_e( 'Open findings', 'luwipress' ); ?></span>
				</div>
			</div>

			<div class="luwipress-stat-card <?php echo esc_attr( $untrans_state ); ?>">
				<div class="stat-icon"><span class="dashicons dashicons-translation"></span></div>
				<div class="stat-content">
					<span class="stat-number"><?php echo (int) $snap['untranslated']; ?></span>
					<span class="stat-label"><?php esc_html_e( 'Untranslated products (sample)', 'luwipress' ); ?></span>
				</div>
			</div>

			<div class="luwipress-stat-card">
				<div class="stat-icon"><span class="dashicons dashicons-backup"></span></div>
				<div class="stat-content">
					<span class="stat-number"><?php echo (int) $snap['backups']; ?></span>
					<span class="stat-label"><?php esc_html_e( 'Backups stored · last 20 retained', 'luwipress' ); ?></span>
				</div>
			</div>

			<div class="luwipress-stat-card <?php echo esc_attr( $kit_state ); ?>">
				<div class="stat-icon"><span class="dashicons dashicons-editor-code"></span></div>
				<div class="stat-content">
					<span class="stat-number"><?php echo esc_html( number_format_i18n( $kit_pct, 1 ) ); ?>%</span>
					<span class="stat-label">
						<?php esc_html_e( 'Kit CSS headroom', 'luwipress' ); ?>
						· <?php echo esc_html( size_format( $snap['kit_css']['bytes'] ) ); ?>
						/ <?php echo esc_html( size_format( $snap['kit_css']['soft_limit'] ) ); ?>
					</span>
				</div>
			</div>
		</div>

		<?php if ( $cap_list ) : ?>
			<div class="luwipress-section luwipress-capability-section">
				<h2>
					<span><?php esc_html_e( 'Capability matrix', 'luwipress' ); ?></span>
					<span class="luwipress-link-small luwipress-section__count">
						<?php echo (int) count( $cap_list ); ?> <?php esc_html_e( 'capabilities', 'luwipress' ); ?>
					</span>
				</h2>
				<p class="description">
					<?php esc_html_e( 'Storefront features the active theme exposes to LuwiPress and its companion plugins.', 'luwipress' ); ?>
				</p>
				<div class="luwipress-capability-grid">
					<?php foreach ( $cap_list as $cap_id => $cap_value ) : ?>
						<div class="luwipress-capability-row">
							<code class="luwipress-capability-row__id"><?php echo esc_html( $cap_id ); ?></code>
							<?php if ( $cap_value === true ) : ?>
								<span class="lp-pill pill-success"><?php esc_html_e( 'Yes', 'luwipress' ); ?></span>
							<?php elseif ( $cap_value === false ) : ?>
								<span class="lp-pill pill-neutral"><?php esc_html_e( 'No', 'luwipress' ); ?></span>
							<?php else : ?>
								<code class="luwipress-capability-row__val"><?php echo esc_html( is_scalar( $cap_value ) ? (string) $cap_value : wp_json_encode( $cap_value ) ); ?></code>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<!-- ═══ TOOLS — merged into Overview in 3.5.8 ═══ -->
		<h2 class="lp-section-title"><?php esc_html_e( 'Maintenance tools', 'luwipress' ); ?></h2>

		<?php if ( empty( $tools ) ) : ?>
			<div class="luwipress-card luwipress-card--muted">
				<p><?php esc_html_e( 'The active theme has not registered any maintenance tools.', 'luwipress' ); ?></p>
			</div>
		<?php else : ?>
			<div class="luwipress-card luwipress-card--info luwipress-tools-intro">
				<h2><?php esc_html_e( 'Maintenance tools', 'luwipress' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Each tool exposes scan / execute / restore. Execute mutates content and writes a backup; the last 20 backups per site are retained for restore.', 'luwipress' ); ?>
				</p>
				<div class="luwipress-tools-toolbar">
					<button type="button" class="button button-primary js-run-all-audits">
						<span class="dashicons dashicons-search"></span>
						<?php esc_html_e( 'Run all read-only audits', 'luwipress' ); ?>
					</button>
					<span class="luwipress-runall-status" aria-live="polite"></span>
				</div>
				<div class="luwipress-runall-summary" hidden></div>
			</div>

			<div class="luwipress-theme-tools" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php foreach ( $tools as $tool ) :
					$is_destructive = ! empty( $tool['destructive'] );
					$wpml           = ! empty( $tool['wpml_aware'] );
					$has_execute    = in_array( 'execute', array_keys( (array) $tool['callbacks'] ), true );
					$has_restore    = in_array( 'restore', array_keys( (array) $tool['callbacks'] ), true );
					$accent_class   = $is_destructive ? 'luwipress-card--warning' : 'luwipress-card--info';
				?>
					<details class="luwipress-card luwipress-tool <?php echo esc_attr( $accent_class ); ?>"
					         data-tool-id="<?php echo esc_attr( $tool['id'] ); ?>"
					         data-wpml="<?php echo $wpml ? '1' : '0'; ?>"
					         data-destructive="<?php echo $is_destructive ? '1' : '0'; ?>">
						<summary class="luwipress-tool__summary">
							<span class="luwipress-tool__title">
								<span class="luwipress-tool__chevron dashicons dashicons-arrow-right"></span>
								<?php echo esc_html( $tool['label'] ); ?>
							</span>
							<span class="luwipress-tool__badges">
								<span class="lp-pill pill-neutral"><?php echo esc_html( $tool['category'] ); ?></span>
								<?php if ( $is_destructive ) : ?>
									<span class="lp-pill pill-warning"><?php esc_html_e( 'destructive', 'luwipress' ); ?></span>
								<?php endif; ?>
								<?php if ( $wpml ) : ?>
									<span class="lp-pill pill-info"><?php esc_html_e( 'WPML-aware', 'luwipress' ); ?></span>
								<?php endif; ?>
							</span>
						</summary>
						<div class="luwipress-tool__body">
							<?php if ( ! empty( $tool['description'] ) ) : ?>
								<p class="luwipress-tool__description"><?php echo esc_html( $tool['description'] ); ?></p>
							<?php endif; ?>
							<div class="luwipress-tool__actions">
								<button type="button" class="button button-secondary js-tool-scan" data-tool-id="<?php echo esc_attr( $tool['id'] ); ?>">
									<span class="dashicons dashicons-search"></span>
									<?php esc_html_e( 'Scan', 'luwipress' ); ?>
								</button>
								<?php if ( $has_execute ) : ?>
									<button type="button" class="button button-primary js-tool-execute" data-tool-id="<?php echo esc_attr( $tool['id'] ); ?>" disabled>
										<span class="dashicons dashicons-controls-play"></span>
										<?php esc_html_e( 'Execute', 'luwipress' ); ?>
									</button>
								<?php endif; ?>
								<?php if ( $has_restore ) : ?>
									<button type="button" class="button js-tool-toggle-restore" data-tool-id="<?php echo esc_attr( $tool['id'] ); ?>">
										<span class="dashicons dashicons-backup"></span>
										<?php esc_html_e( 'Restore from backup', 'luwipress' ); ?>
									</button>
								<?php endif; ?>
								<span class="luwipress-tool__status" aria-live="polite"></span>
							</div>
							<div class="luwipress-tool__results" hidden></div>
							<div class="luwipress-tool__restore" hidden></div>
						</div>
					</details>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

	<?php elseif ( $active_tab === 'settings' ) : ?>

		<?php if ( empty( $settings ) ) : ?>
			<div class="luwipress-card luwipress-card--muted">
				<p><?php esc_html_e( 'The active theme has not registered any settings.', 'luwipress' ); ?></p>
			</div>
		<?php else : ?>
			<div class="luwipress-card luwipress-card--info luwipress-settings-intro">
				<p class="description">
					<?php esc_html_e( 'These mirror the theme’s Customizer options so you can manage them remotely (REST + WebMCP) without touching wp-admin.', 'luwipress' ); ?>
				</p>
			</div>

			<form class="luwipress-theme-settings-form" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php
				$group_labels = array(
					'general'     => __( 'General', 'luwipress' ),
					'performance' => __( 'Performance', 'luwipress' ),
					'footer'      => __( 'Footer', 'luwipress' ),
					'mega_menu'   => __( 'Mega menu', 'luwipress' ),
					'wpml'        => __( 'WPML & Templates', 'luwipress' ),
					'seo'         => __( 'SEO & Redirects', 'luwipress' ),
					'shop'        => __( 'Shop archive UX', 'luwipress' ),
				);
				$group_accents = array(
					'general'     => 'luwipress-card--primary',
					'performance' => 'luwipress-card--info',
					'footer'      => 'luwipress-card--primary',
					'mega_menu'   => 'luwipress-card--primary',
					'wpml'        => 'luwipress-card--success',
					'seo'         => 'luwipress-card--success',
					'shop'        => 'luwipress-card--primary',
				);
				foreach ( $grouped_settings as $group => $items ) :
					$gl = $group_labels[ $group ] ?? ucfirst( str_replace( '_', ' ', $group ) );
					$ac = $group_accents[ $group ] ?? 'luwipress-card--primary';
				?>
					<div class="luwipress-card <?php echo esc_attr( $ac ); ?> luwipress-settings-section" data-group="<?php echo esc_attr( $group ); ?>">
						<h2 class="luwipress-settings-section__heading">
							<span><?php echo esc_html( $gl ); ?></span>
							<button type="button" class="button button-link js-reset-group" data-group="<?php echo esc_attr( $group ); ?>">
								<?php esc_html_e( 'Reset group', 'luwipress' ); ?>
							</button>
						</h2>
						<table class="form-table">
							<tbody>
								<?php foreach ( $items as $s ) :
									$current  = get_theme_mod( $s['theme_mod'], $s['default'] );
									$field_id = 'lp-set-' . esc_attr( $s['id'] );
								?>
									<tr>
										<th scope="row">
											<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $s['label'] ); ?></label>
										</th>
										<td>
											<?php if ( $s['type'] === 'checkbox' ) : ?>
												<label>
													<input type="checkbox" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $s['id'] ); ?>" value="1" <?php checked( (bool) $current ); ?> />
													<?php esc_html_e( 'Enabled', 'luwipress' ); ?>
												</label>
											<?php elseif ( $s['type'] === 'number' ) : ?>
												<input type="number" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $s['id'] ); ?>" value="<?php echo esc_attr( $current ); ?>"
													<?php if ( isset( $s['min'] ) ) : ?>min="<?php echo esc_attr( $s['min'] ); ?>"<?php endif; ?>
													<?php if ( isset( $s['max'] ) ) : ?>max="<?php echo esc_attr( $s['max'] ); ?>"<?php endif; ?>
													class="small-text" />
											<?php elseif ( $s['type'] === 'select' ) : ?>
												<select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $s['id'] ); ?>">
													<?php foreach ( (array) $s['choices'] as $val => $label ) : ?>
														<option value="<?php echo esc_attr( $val ); ?>" <?php selected( (string) $current, (string) $val ); ?>>
															<?php echo esc_html( $label ); ?>
														</option>
													<?php endforeach; ?>
												</select>
											<?php else : ?>
												<input type="text" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $s['id'] ); ?>" value="<?php echo esc_attr( $current ); ?>" class="regular-text" />
											<?php endif; ?>
											<?php if ( ! empty( $s['description'] ) ) : ?>
												<p class="description"><?php echo esc_html( $s['description'] ); ?></p>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endforeach; ?>

				<div class="luwipress-card luwipress-settings-save">
					<button type="submit" class="button button-primary button-hero">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Save settings', 'luwipress' ); ?>
					</button>
					<span class="luwipress-settings-status" aria-live="polite"></span>
				</div>
			</form>
		<?php endif; ?>

	<?php endif; ?>
<?php if ( ! $luwipress_hub_mode ) : ?>
</div>
<?php endif; ?>

<style>
	/* Page-specific chrome only — base card / pill / stat styling lives in admin.css */
	.luwipress-theme-page .lp-subtitle {
		margin: 4px 0 0;
		color: var(--lp-text-secondary);
		font-size: 13px;
	}
	.luwipress-theme-page .luwipress-tabs {
		margin: 18px 0 24px;
	}
	.luwipress-theme-page .luwipress-tabs .nav-tab {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		font-weight: 500;
		border-radius: var(--radius-sm, 6px) var(--radius-sm, 6px) 0 0;
	}
	.luwipress-theme-page .luwipress-tabs .nav-tab .dashicons {
		font-size: 16px; width: 16px; height: 16px;
	}
	.luwipress-tab-count {
		display: inline-block;
		background: var(--lp-border);
		color: var(--lp-text-secondary);
		border-radius: 9999px;
		padding: 1px 8px;
		font-size: 11px;
		font-weight: 600;
		margin-left: 4px;
	}
	.nav-tab-active .luwipress-tab-count {
		background: var(--lp-primary);
		color: #fff;
	}

	/* Force all 7 stat cards onto a single row on standard admin widths.
	   Default admin.css uses minmax(200px, 1fr) which only fits 6 cards on
	   ~1200-1300px content areas → 7th wraps to a new row. Drop min to 145px
	   so 7 × 145 + 6 × 16 = 1086px fits comfortably even on 1366px laptops.
	   Below ~1100px content width, auto-fit gracefully wraps to fewer columns. */
	.luwipress-theme-page .luwipress-stats-row {
		grid-template-columns: repeat(auto-fit, minmax(145px, 1fr));
		gap: 12px;
	}
	/* Stat cards stretch full width of grid columns and let long theme names wrap */
	.luwipress-theme-page .luwipress-stats-row .luwipress-stat-card {
		padding: 14px 12px;
		gap: 10px;
	}
	.luwipress-theme-page .luwipress-stats-row .stat-number {
		font-size: 16px;
		line-height: 1.25;
		word-break: break-word;
	}
	.luwipress-theme-page .luwipress-stats-row .stat-label {
		font-size: 10px;
	}
	.luwipress-theme-page .luwipress-stats-row .stat-icon .dashicons {
		font-size: 22px; width: 22px; height: 22px;
	}
	.luwipress-stat-card.stat-success { border-left-color: var(--lp-success); }
	.luwipress-stat-card.stat-success .stat-icon .dashicons { color: var(--lp-success); }
	.luwipress-stat-card.stat-warning .stat-icon .dashicons { color: var(--lp-warning); }
	.luwipress-stat-card.stat-error   .stat-icon .dashicons { color: var(--lp-error, #dc2626); }
	.luwipress-stat-card.stat-translation .stat-icon .dashicons { color: #0ea5e9; }

	/* Capability matrix as a card grid instead of a striped table */
	.luwipress-section__count {
		font-size: 12px;
		color: var(--lp-text-secondary);
		font-weight: 500;
	}
	.luwipress-capability-grid {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
		gap: 8px;
		margin-top: 12px;
	}
	.luwipress-capability-row {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		padding: 10px 12px;
		border: 1px solid var(--lp-border-light);
		border-radius: var(--radius-sm, 6px);
		background: var(--lp-surface-secondary, #f9fafb);
	}
	.luwipress-capability-row__id {
		font-size: 12px;
		color: var(--lp-text);
		background: transparent;
		padding: 0;
	}
	.luwipress-capability-row__val {
		font-size: 11px;
		background: var(--lp-border-light);
		padding: 2px 6px;
		border-radius: 4px;
	}

	/* Tools tab */
	.luwipress-tools-intro h2 { margin: 0 0 6px; font-size: 16px; }
	.luwipress-tools-toolbar {
		display: flex; gap: 12px; align-items: center;
		margin-top: 14px;
	}
	.luwipress-tools-toolbar .button .dashicons {
		font-size: 16px; width: 16px; height: 16px;
		vertical-align: middle; margin-right: 4px;
	}
	.luwipress-runall-summary {
		background: var(--lp-surface-secondary, #f9fafb);
		border: 1px solid var(--lp-border-light);
		border-radius: var(--radius-sm, 6px);
		padding: 12px;
		margin-top: 12px;
	}
	.luwipress-runall-summary table { width: 100%; border-collapse: collapse; }
	.luwipress-runall-summary th, .luwipress-runall-summary td {
		text-align: left; padding: 6px 8px;
		border-bottom: 1px solid var(--lp-border-light);
		font-size: 13px;
	}

	.luwipress-tool {
		margin-bottom: 12px;
		padding: 0;
		overflow: hidden;
	}
	.luwipress-tool > summary {
		cursor: pointer;
		list-style: none;
		padding: 14px 18px;
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 12px;
		user-select: none;
	}
	.luwipress-tool > summary::-webkit-details-marker { display: none; }
	.luwipress-tool > summary:hover { background: var(--lp-surface-secondary, #f9fafb); }
	.luwipress-tool__title {
		font-weight: 600;
		color: var(--lp-text);
		display: inline-flex;
		align-items: center;
		gap: 8px;
	}
	.luwipress-tool__chevron {
		transition: transform .2s ease;
		color: var(--lp-text-secondary);
		font-size: 18px; width: 18px; height: 18px;
	}
	.luwipress-tool[open] .luwipress-tool__chevron { transform: rotate(90deg); }
	.luwipress-tool__badges { display: flex; gap: 6px; flex-wrap: wrap; }
	.luwipress-tool__body {
		padding: 0 18px 18px;
		border-top: 1px solid var(--lp-border-light);
	}
	.luwipress-tool__description {
		color: var(--lp-text-secondary);
		margin: 14px 0;
		line-height: 1.6;
	}
	.luwipress-tool__actions {
		display: flex; flex-wrap: wrap; gap: 8px;
		align-items: center;
		margin: 12px 0;
	}
	.luwipress-tool__actions .button .dashicons {
		font-size: 16px; width: 16px; height: 16px;
		vertical-align: middle; margin-right: 4px;
	}
	.luwipress-tool__status { color: var(--lp-text-secondary); font-size: 13px; }
	.luwipress-tool__status--ok { color: var(--lp-success); font-weight: 600; }
	.luwipress-tool__status--err { color: var(--lp-error, #dc2626); font-weight: 600; }
	.luwipress-tool__results, .luwipress-tool__restore {
		background: var(--lp-surface-secondary, #f9fafb);
		border: 1px solid var(--lp-border-light);
		border-radius: var(--radius-sm, 6px);
		padding: 12px;
		margin-top: 8px;
	}
	.luwipress-tool__results table { width: 100%; border-collapse: collapse; }
	.luwipress-tool__results th, .luwipress-tool__results td {
		text-align: left; padding: 6px 8px;
		border-bottom: 1px solid var(--lp-border-light);
		font-size: 13px;
	}

	/* Settings tab — sectioned cards by group */
	.luwipress-settings-section { padding: 18px 22px; }
	.luwipress-settings-section__heading {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		margin: 0 0 14px;
		padding-bottom: 12px;
		border-bottom: 1px solid var(--lp-border-light);
		font-size: 15px;
	}
	.luwipress-settings-section__heading .button-link {
		color: var(--lp-text-secondary);
		font-size: 12px;
		text-decoration: none;
	}
	.luwipress-settings-section__heading .button-link:hover { color: var(--lp-primary); }
	.luwipress-settings-section .form-table { margin-top: 0; }
	.luwipress-settings-section .form-table th { padding-left: 0; width: 220px; }

	.luwipress-settings-save {
		display: flex; align-items: center; gap: 12px;
		padding: 16px 22px;
	}
	.luwipress-settings-save .button-hero .dashicons {
		font-size: 18px; width: 18px; height: 18px;
		vertical-align: middle; margin-right: 6px;
	}
	.luwipress-settings-status { color: var(--lp-success); font-weight: 600; }

	/* Pill colour patches — admin.css covers most, ensure pill-info exists */
	.lp-pill.pill-info {
		background: var(--lp-primary-50);
		color: var(--lp-primary-dark);
		border: 1px solid var(--lp-primary-100);
	}

	@media (max-width: 782px) {
		.luwipress-tool > summary { flex-direction: column; align-items: flex-start; }
		.luwipress-settings-section .form-table th { width: auto; padding-bottom: 4px; }
	}
</style>
