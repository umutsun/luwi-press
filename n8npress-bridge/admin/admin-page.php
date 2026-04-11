<?php
/**
 * n8nPress Dashboard
 *
 * AI-powered WooCommerce automation dashboard.
 * All dynamic data loaded via AJAX for fast page render.
 *
 * @since 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Insufficient permissions.', 'n8npress' ) );
}

$detector    = N8nPress_Plugin_Detector::get_instance();
$environment = $detector->get_environment();
$wc_active   = class_exists( 'WooCommerce' );
$webhook_url = get_option( 'n8npress_seo_webhook_url', '' );
$ai_provider = get_option( 'n8npress_ai_provider', 'openai' );
$ai_model    = get_option( 'n8npress_ai_model', 'gpt-4o-mini' );
$ai_key      = get_option( 'n8npress_openai_api_key', '' )
            ?: get_option( 'n8npress_anthropic_api_key', '' )
            ?: get_option( 'n8npress_google_ai_api_key', '' );

$provider_labels = array( 'openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'google' => 'Google AI' );
$provider_label  = $provider_labels[ $ai_provider ] ?? ucfirst( $ai_provider );
$engine_mode     = ! empty( $webhook_url ) ? 'n8n' : 'built-in';
?>

<div class="wrap n8npress-dashboard">

	<!-- ═══ HEADER ═══ -->
	<div class="n8np-header">
		<div class="n8np-header-left">
			<h1 class="n8np-title">
				<svg class="n8np-logo" width="28" height="28" viewBox="0 0 28 28" fill="none"><circle cx="14" cy="14" r="14" fill="var(--n8n-primary)"/><path d="M8 14l4 4 8-8" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
				n8nPress
			</h1>
			<span class="n8np-version">v<?php echo esc_html( N8NPRESS_VERSION ); ?></span>
		</div>
		<div class="n8np-header-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=n8npress-claw' ) ); ?>" class="button button-primary n8np-btn-glow">
				<span class="dashicons dashicons-superhero-alt"></span> <?php esc_html_e( 'Open Claw AI', 'n8npress' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=n8npress-settings' ) ); ?>" class="button">
				<span class="dashicons dashicons-admin-generic"></span>
			</a>
		</div>
	</div>

	<!-- ═══ STATUS RIBBON ═══ -->
	<div class="n8np-ribbon">
		<?php
		$pills = array();

		// AI Engine
		if ( ! empty( $ai_key ) ) {
			$pills[] = array( 'ok', 'dashicons-admin-generic', sprintf( '%s (%s)', $provider_label, $ai_model ) );
		} else {
			$pills[] = array( 'err', 'dashicons-warning', __( 'No AI key', 'n8npress' ) );
		}

		// Processing
		if ( ! empty( $webhook_url ) ) {
			$pills[] = array( 'ok', 'dashicons-randomize', __( 'n8n Connected', 'n8npress' ) );
		} else {
			$pills[] = array( 'neutral', 'dashicons-laptop', __( 'Built-in Engine', 'n8npress' ) );
		}

		// WooCommerce
		if ( $wc_active ) {
			$pills[] = array( 'ok', 'dashicons-cart', 'WooCommerce ' . WC_VERSION );
		}

		// SEO
		$seo = $environment['seo'];
		if ( 'none' !== $seo['plugin'] ) {
			$pills[] = array( 'ok', 'dashicons-search', ucwords( str_replace( '-', ' ', $seo['plugin'] ) ) );
		}

		// Translation
		$trans = $environment['translation'];
		if ( 'none' !== $trans['plugin'] ) {
			$lang_count = count( $trans['active_languages'] ?? array() );
			$pills[] = array( 'ok', 'dashicons-translation', ucwords( str_replace( '-', ' ', $trans['plugin'] ) ) . ( $lang_count > 1 ? " ({$lang_count})" : '' ) );
		}

		// Email
		$email = $environment['email'];
		if ( 'wp_mail' !== $email['plugin'] && 'none' !== $email['plugin'] ) {
			$pills[] = array( 'ok', 'dashicons-email-alt', ucwords( str_replace( '-', ' ', $email['plugin'] ) ) );
		}

		// Chatwoot
		if ( get_option( 'n8npress_chatwoot_enabled' ) && ! empty( get_option( 'n8npress_chatwoot_url' ) ) ) {
			$pills[] = array( 'ok', 'dashicons-format-chat', 'Chatwoot' );
		}

		// Cache
		$cache = $environment['cache'] ?? array();
		if ( ! empty( $cache['plugin'] ) && 'none' !== $cache['plugin'] ) {
			$pills[] = array( 'ok', 'dashicons-performance', ucwords( str_replace( '-', ' ', $cache['plugin'] ) ) );
		}

		// WebMCP
		if ( get_option( 'n8npress_webmcp_enabled', true ) ) {
			$pills[] = array( 'ok', 'dashicons-rest-api', 'MCP Server' );
		}

		foreach ( $pills as $p ) :
			$cls = 'ok' === $p[0] ? 'pill-ok' : ( 'err' === $p[0] ? 'pill-err' : 'pill-neutral' );
		?>
		<span class="n8np-pill <?php echo esc_attr( $cls ); ?>">
			<span class="dashicons <?php echo esc_attr( $p[1] ); ?>"></span>
			<?php echo esc_html( $p[2] ); ?>
		</span>
		<?php endforeach; ?>
	</div>

	<!-- ═══ HERO STATS (AJAX loaded with animated counters) ═══ -->
	<div class="n8np-hero" id="n8np-hero">
		<div class="n8np-hero-card" data-key="products">
			<div class="n8np-hero-icon" style="background:#6366f1;"><span class="dashicons dashicons-cart"></span></div>
			<div class="n8np-hero-body">
				<span class="n8np-hero-num n8np-skeleton">—</span>
				<span class="n8np-hero-label"><?php esc_html_e( 'Products', 'n8npress' ); ?></span>
			</div>
			<span class="n8np-hero-trend" data-key="products_trend"></span>
		</div>
		<div class="n8np-hero-card" data-key="revenue">
			<div class="n8np-hero-icon" style="background:#16a34a;"><span class="dashicons dashicons-chart-line"></span></div>
			<div class="n8np-hero-body">
				<span class="n8np-hero-num n8np-skeleton">—</span>
				<span class="n8np-hero-label"><?php esc_html_e( 'Revenue (30d)', 'n8npress' ); ?></span>
			</div>
			<span class="n8np-hero-trend" data-key="revenue_trend"></span>
		</div>
		<div class="n8np-hero-card" data-key="ai_calls">
			<div class="n8np-hero-icon" style="background:#f59e0b;"><span class="dashicons dashicons-admin-generic"></span></div>
			<div class="n8np-hero-body">
				<span class="n8np-hero-num n8np-skeleton">—</span>
				<span class="n8np-hero-label"><?php esc_html_e( 'AI Calls (today)', 'n8npress' ); ?></span>
			</div>
			<span class="n8np-hero-trend" data-key="ai_calls_trend"></span>
		</div>
		<div class="n8np-hero-card" data-key="budget">
			<div class="n8np-hero-icon" style="background:#dc2626;"><span class="dashicons dashicons-shield"></span></div>
			<div class="n8np-hero-body">
				<span class="n8np-hero-num n8np-skeleton">—</span>
				<span class="n8np-hero-label"><?php esc_html_e( 'Budget Used', 'n8npress' ); ?></span>
			</div>
			<div class="n8np-budget-bar"><div class="n8np-budget-fill" data-key="budget_pct"></div></div>
		</div>
	</div>

	<!-- ═══ MIDDLE ROW: Chart + Content Health ═══ -->
	<div class="n8np-middle">
		<!-- AI Cost Trend (7 day mini chart) -->
		<div class="n8np-card n8np-chart-card">
			<div class="n8np-card-header">
				<h3><span class="dashicons dashicons-chart-area"></span> <?php esc_html_e( 'AI Cost Trend', 'n8npress' ); ?></h3>
				<span class="n8np-card-badge"><?php esc_html_e( 'Last 7 days', 'n8npress' ); ?></span>
			</div>
			<div class="n8np-chart" id="n8np-cost-chart">
				<div class="n8np-skeleton-chart"></div>
			</div>
			<div class="n8np-chart-footer" id="n8np-cost-footer">
				<span class="n8np-chart-total n8np-skeleton">—</span>
				<span class="n8np-chart-label"><?php esc_html_e( 'Total 7-day spend', 'n8npress' ); ?></span>
			</div>
		</div>

		<!-- Content Health -->
		<div class="n8np-card n8np-health-card">
			<div class="n8np-card-header">
				<h3><span class="dashicons dashicons-heart"></span> <?php esc_html_e( 'Content Health', 'n8npress' ); ?></h3>
				<button type="button" class="button button-small" id="n8np-scan-btn">
					<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Scan', 'n8npress' ); ?>
				</button>
			</div>
			<div class="n8np-health-ring-wrap" id="n8np-health">
				<div class="n8np-skeleton-ring"></div>
			</div>
			<div class="n8np-health-legend" id="n8np-health-legend"></div>
		</div>
	</div>

	<!-- ═══ QUICK ACTIONS ═══ -->
	<div class="n8np-actions">
		<?php
		$actions = array(
			array( 'dashicons-edit-large',       '#6366f1', __( 'Enrich Products', 'n8npress' ),    __( 'AI descriptions, SEO, FAQ', 'n8npress' ),         'n8npress-settings&tab=ai' ),
			array( 'dashicons-translation',      '#2563eb', __( 'Translate', 'n8npress' ),          __( 'SEO-aware multilingual', 'n8npress' ),             'n8npress-translations' ),
			array( 'dashicons-welcome-write-blog','#16a34a', __( 'Schedule Content', 'n8npress' ),  __( 'AI blog posts + images', 'n8npress' ),             'n8npress-scheduler' ),
			array( 'dashicons-rest-api',         '#f59e0b', __( 'MCP Server', 'n8npress' ),         __( 'AI agent tools', 'n8npress' ),                     'n8npress-webmcp' ),
			array( 'dashicons-chart-area',       '#ec4899', __( 'Usage & Logs', 'n8npress' ),       __( 'AI spend, activity', 'n8npress' ),                 'n8npress-usage' ),
		);
		foreach ( $actions as $a ) : ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $a[4] ) ); ?>" class="n8np-action-card" style="--accent:<?php echo esc_attr( $a[1] ); ?>">
			<span class="dashicons <?php echo esc_attr( $a[0] ); ?>"></span>
			<div>
				<strong><?php echo esc_html( $a[2] ); ?></strong>
				<small><?php echo esc_html( $a[3] ); ?></small>
			</div>
		</a>
		<?php endforeach; ?>
	</div>

	<!-- ═══ BOTTOM ROW: Opportunities + Activity ═══ -->
	<div class="n8np-bottom">
		<!-- Content Opportunities -->
		<div class="n8np-card">
			<div class="n8np-card-header">
				<h3><span class="dashicons dashicons-lightbulb"></span> <?php esc_html_e( 'Opportunities', 'n8npress' ); ?></h3>
				<button type="button" class="button button-small button-primary" id="n8np-bulk-enrich">
					<span class="dashicons dashicons-edit-large"></span> <?php esc_html_e( 'Bulk Enrich', 'n8npress' ); ?>
				</button>
			</div>
			<div class="n8np-opps" id="n8np-opps">
				<?php
				$opp_defs = array(
					array( 'missing_translations', 'dashicons-translation',      '#2563eb', __( 'Missing Translations', 'n8npress' ), 'n8npress-translations' ),
					array( 'thin_content',         'dashicons-editor-expand',    '#ea580c', __( 'Thin Content', 'n8npress' ),          '' ),
					array( 'missing_seo',          'dashicons-search',           '#dc2626', __( 'Missing SEO Meta', 'n8npress' ),      '' ),
					array( 'missing_alt',          'dashicons-format-image',     '#7c3aed', __( 'Missing Alt Text', 'n8npress' ),      '' ),
					array( 'stale_content',        'dashicons-calendar-alt',     '#eab308', __( 'Stale Content', 'n8npress' ),         '' ),
				);
				foreach ( $opp_defs as $o ) : ?>
				<div class="n8np-opp-row" data-opp="<?php echo esc_attr( $o[0] ); ?>">
					<span class="dashicons <?php echo esc_attr( $o[1] ); ?>" style="color:<?php echo esc_attr( $o[2] ); ?>"></span>
					<span class="n8np-opp-label"><?php echo esc_html( $o[3] ); ?></span>
					<span class="n8np-opp-count n8np-skeleton">—</span>
					<?php if ( $o[4] ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $o[4] ) ); ?>" class="n8np-opp-link"><?php esc_html_e( 'Fix', 'n8npress' ); ?> &rarr;</a>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- Recent Activity (auto-refresh) -->
		<div class="n8np-card">
			<div class="n8np-card-header">
				<h3><span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'Recent Activity', 'n8npress' ); ?></h3>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=n8npress-usage&tab=logs' ) ); ?>" class="n8np-link-small"><?php esc_html_e( 'All Logs', 'n8npress' ); ?> &rarr;</a>
			</div>
			<div class="n8np-activity" id="n8np-activity">
				<div class="n8np-skeleton-lines">
					<div class="n8np-skeleton-line"></div>
					<div class="n8np-skeleton-line"></div>
					<div class="n8np-skeleton-line"></div>
					<div class="n8np-skeleton-line"></div>
					<div class="n8np-skeleton-line"></div>
				</div>
			</div>
		</div>
	</div>

	<?php
	// Translation coverage — only if WPML/Polylang active
	$trans_plugin = $environment['translation']['plugin'] ?? 'none';
	if ( 'none' !== $trans_plugin ) :
		$target_langs = get_option( 'n8npress_translation_languages', array() );
		if ( ! empty( $target_langs ) ) :
	?>
	<!-- ═══ TRANSLATION COVERAGE ═══ -->
	<div class="n8np-card n8np-trans-card">
		<div class="n8np-card-header">
			<h3><span class="dashicons dashicons-translation"></span> <?php esc_html_e( 'Translation Coverage', 'n8npress' ); ?></h3>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=n8npress-translations' ) ); ?>" class="n8np-link-small"><?php esc_html_e( 'Manage', 'n8npress' ); ?> &rarr;</a>
		</div>
		<div class="n8np-trans-bars" id="n8np-trans-bars">
			<?php foreach ( $target_langs as $lang ) : ?>
			<div class="n8np-trans-row" data-lang="<?php echo esc_attr( $lang ); ?>">
				<span class="n8np-trans-code"><?php echo esc_html( strtoupper( $lang ) ); ?></span>
				<div class="n8np-trans-track">
					<div class="n8np-trans-fill n8np-skeleton" style="width:0%"></div>
				</div>
				<span class="n8np-trans-pct n8np-skeleton">—%</span>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; endif; ?>

	<?php
	// Customer Intelligence — only if WooCommerce active
	if ( $wc_active ) :
		$crm_counts = get_option( 'n8npress_crm_segment_counts', array() );
	?>
	<!-- ═══ CUSTOMER SEGMENTS ═══ -->
	<div class="n8np-card">
		<div class="n8np-card-header">
			<h3><span class="dashicons dashicons-groups"></span> <?php esc_html_e( 'Customer Segments', 'n8npress' ); ?></h3>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=n8npress-claw' ) ); ?>" class="n8np-link-small"><?php esc_html_e( 'Ask AI', 'n8npress' ); ?> &rarr;</a>
		</div>
		<?php if ( ! empty( $crm_counts ) ) : ?>
		<div class="n8np-segments">
			<?php
			$segs = array(
				'vip'     => array( '#6366f1', 'star-filled',  'VIP' ),
				'loyal'   => array( '#16a34a', 'heart',        'Loyal' ),
				'active'  => array( '#0ea5e9', 'businessman',  'Active' ),
				'new'     => array( '#10b981', 'plus-alt',     'New' ),
				'at_risk' => array( '#eab308', 'warning',      'At Risk' ),
				'dormant' => array( '#f97316', 'clock',        'Dormant' ),
				'lost'    => array( '#dc2626', 'dismiss',      'Lost' ),
			);
			foreach ( $segs as $key => $s ) :
				$count = $crm_counts[ $key ] ?? 0;
				if ( 0 === $count && in_array( $key, array( 'dormant', 'lost' ), true ) ) continue;
			?>
			<span class="n8np-seg-pill" style="--seg-color:<?php echo esc_attr( $s[0] ); ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $s[1] ); ?>"></span>
				<strong><?php echo absint( $count ); ?></strong>
				<?php echo esc_html( $s[2] ); ?>
			</span>
			<?php endforeach; ?>
		</div>
		<?php else : ?>
		<p class="n8np-empty"><?php esc_html_e( 'Customer segments will be computed on the next weekly refresh.', 'n8npress' ); ?></p>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- ═══ FOOTER ═══ -->
	<div class="n8np-footer">
		WordPress <?php echo esc_html( get_bloginfo( 'version' ) ); ?>
		&middot; PHP <?php echo esc_html( phpversion() ); ?>
		<?php if ( $wc_active ) : ?>&middot; WooCommerce <?php echo esc_html( WC_VERSION ); ?><?php endif; ?>
		&middot; <?php echo esc_html( $engine_mode === 'n8n' ? 'n8n Processing' : 'Built-in Engine' ); ?>
	</div>

</div>
