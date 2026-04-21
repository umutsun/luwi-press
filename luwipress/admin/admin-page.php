<?php
/**
 * LuwiPress Dashboard
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
	wp_die( esc_html__( 'Insufficient permissions.', 'luwipress' ) );
}

$detector    = LuwiPress_Plugin_Detector::get_instance();
$environment = $detector->get_environment();
$wc_active   = class_exists( 'WooCommerce' );
$ai_provider = get_option( 'luwipress_ai_provider', 'openai' );
$ai_model    = get_option( 'luwipress_ai_model', 'gpt-4o-mini' );
$ai_key      = get_option( 'luwipress_openai_api_key', '' )
            ?: get_option( 'luwipress_anthropic_api_key', '' )
            ?: get_option( 'luwipress_google_ai_api_key', '' );

$provider_labels = array( 'openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'google' => 'Google AI' );
$provider_label  = $provider_labels[ $ai_provider ] ?? ucfirst( $ai_provider );
?>

<div class="wrap luwipress-dashboard">

	<!-- ═══ HEADER ═══ -->
	<div class="lp-header">
		<div class="lp-header-left">
			<h1 class="lp-title">
				<svg class="lp-logo" width="28" height="28" viewBox="0 0 28 28" fill="none"><circle cx="14" cy="14" r="14" fill="var(--lp-primary)"/><path d="M8 14l4 4 8-8" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
				LuwiPress
			</h1>
			<span class="lp-version">v<?php echo esc_html( LUWIPRESS_VERSION ); ?></span>
		</div>
		<div class="lp-header-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-claw' ) ); ?>" class="button button-primary lp-btn-glow">
				<span class="dashicons dashicons-superhero-alt"></span> <?php esc_html_e( 'Open Claw AI', 'luwipress' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-settings' ) ); ?>" class="button">
				<span class="dashicons dashicons-admin-generic"></span>
			</a>
		</div>
	</div>

	<!-- ═══ STATUS RIBBON ═══ -->
	<div class="lp-ribbon">
		<?php
		$pills = array();

		// AI Engine
		if ( ! empty( $ai_key ) ) {
			$pills[] = array( 'ok', 'dashicons-admin-generic', sprintf( '%s (%s)', $provider_label, $ai_model ) );
		} else {
			$pills[] = array( 'err', 'dashicons-warning', __( 'No AI key', 'luwipress' ) );
		}

		// AI Engine
		$pills[] = array( 'ok', 'dashicons-laptop', __( 'Local AI Engine', 'luwipress' ) );

		// WooCommerce
		if ( $wc_active ) {
			$pills[] = array( 'ok', 'dashicons-cart', 'WooCommerce ' . WC_VERSION );
		}

		// SEO
		$seo = $environment['seo'];
		if ( 'luwipress-native' === $seo['plugin'] ) {
			$pills[] = array( 'ok', 'dashicons-search', __( 'LuwiPress SEO', 'luwipress' ) );
		} elseif ( 'none' !== $seo['plugin'] ) {
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

		// Cache
		$cache = $environment['cache'] ?? array();
		if ( ! empty( $cache['plugin'] ) && 'none' !== $cache['plugin'] ) {
			$pills[] = array( 'ok', 'dashicons-performance', ucwords( str_replace( '-', ' ', $cache['plugin'] ) ) );
		}

		// Analytics (GTM / GA4)
		$analytics = $environment['analytics'] ?? array();
		if ( ! empty( $analytics['plugin'] ) && 'none' !== $analytics['plugin'] ) {
			$pills[] = array( 'ok', 'dashicons-chart-bar', ucwords( str_replace( '-', ' ', $analytics['plugin'] ) ) );
		}

		// Google Ads
		$gads = $environment['google_ads'] ?? array();
		if ( ! empty( $gads['plugin'] ) && 'none' !== $gads['plugin'] ) {
			$pills[] = array( 'ok', 'dashicons-megaphone', ucwords( str_replace( '-', ' ', $gads['plugin'] ) ) );
		}

		// Meta Ads (Facebook/Instagram Pixel)
		$meta = $environment['meta_ads'] ?? array();
		if ( ! empty( $meta['plugin'] ) && 'none' !== $meta['plugin'] ) {
			$pills[] = array( 'ok', 'dashicons-share', ucwords( str_replace( '-', ' ', $meta['plugin'] ) ) );
		}

		// Product Feed
		$feed = $environment['product_feed'] ?? array();
		if ( ! empty( $feed['plugin'] ) && 'none' !== $feed['plugin'] ) {
			$pills[] = array( 'ok', 'dashicons-rss', ucwords( str_replace( '-', ' ', $feed['plugin'] ) ) );
		}

		foreach ( $pills as $p ) :
			$cls = 'ok' === $p[0] ? 'pill-ok' : ( 'err' === $p[0] ? 'pill-err' : 'pill-neutral' );
		?>
		<span class="lp-pill <?php echo esc_attr( $cls ); ?>">
			<span class="dashicons <?php echo esc_attr( $p[1] ); ?>"></span>
			<?php echo esc_html( $p[2] ); ?>
		</span>
		<?php endforeach; ?>
	</div>

	<!-- ═══ HERO STATS (AJAX loaded with animated counters) ═══ -->
	<div class="lp-hero" id="lp-hero">
		<div class="lp-hero-card" data-key="products">
			<div class="lp-hero-icon lp-hero-icon--primary"><span class="dashicons dashicons-cart"></span></div>
			<div class="lp-hero-body">
				<span class="lp-hero-num lp-skeleton">—</span>
				<span class="lp-hero-label"><?php esc_html_e( 'Products', 'luwipress' ); ?></span>
			</div>
			<span class="lp-hero-trend" data-key="products_trend"></span>
		</div>
		<div class="lp-hero-card" data-key="revenue">
			<div class="lp-hero-icon lp-hero-icon--success"><span class="dashicons dashicons-chart-line"></span></div>
			<div class="lp-hero-body">
				<span class="lp-hero-num lp-skeleton">—</span>
				<span class="lp-hero-label"><?php esc_html_e( 'Revenue (30d)', 'luwipress' ); ?></span>
			</div>
			<span class="lp-hero-trend" data-key="revenue_trend"></span>
		</div>
		<div class="lp-hero-card" data-key="ai_calls">
			<div class="lp-hero-icon lp-hero-icon--warning"><span class="dashicons dashicons-admin-generic"></span></div>
			<div class="lp-hero-body">
				<span class="lp-hero-num lp-skeleton">—</span>
				<span class="lp-hero-label"><?php esc_html_e( 'AI Calls (today)', 'luwipress' ); ?></span>
			</div>
			<span class="lp-hero-trend" data-key="ai_calls_trend"></span>
		</div>
		<div class="lp-hero-card" data-key="budget">
			<div class="lp-hero-icon lp-hero-icon--error"><span class="dashicons dashicons-shield"></span></div>
			<div class="lp-hero-body">
				<span class="lp-hero-num lp-skeleton">—</span>
				<span class="lp-hero-label"><?php esc_html_e( 'Budget Used', 'luwipress' ); ?></span>
			</div>
			<div class="lp-budget-bar"><div class="lp-budget-fill" data-key="budget_pct"></div></div>
		</div>
	</div>

	<!-- ═══ MIDDLE ROW: Chart + Content Health ═══ -->
	<div class="lp-middle">
		<!-- AI Cost Trend (7 day mini chart) -->
		<div class="lp-card lp-chart-card">
			<div class="lp-card-header">
				<h3><span class="dashicons dashicons-chart-area"></span> <?php esc_html_e( 'AI Cost Trend', 'luwipress' ); ?></h3>
				<span class="lp-card-badge"><?php esc_html_e( 'Last 7 days', 'luwipress' ); ?></span>
			</div>
			<div class="lp-chart" id="lp-cost-chart">
				<div class="lp-skeleton-chart"></div>
			</div>
			<div class="lp-chart-footer" id="lp-cost-footer">
				<span class="lp-chart-total lp-skeleton">—</span>
				<span class="lp-chart-label"><?php esc_html_e( 'Total 7-day spend', 'luwipress' ); ?></span>
			</div>
		</div>

		<!-- Content Health -->
		<div class="lp-card lp-health-card">
			<div class="lp-card-header">
				<h3><span class="dashicons dashicons-heart"></span> <?php esc_html_e( 'Content Health', 'luwipress' ); ?></h3>
				<button type="button" class="button button-small" id="lp-scan-btn">
					<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Scan', 'luwipress' ); ?>
				</button>
			</div>
			<div class="lp-health-ring-wrap" id="lp-health">
				<div class="lp-skeleton-ring"></div>
			</div>
			<div class="lp-health-legend" id="lp-health-legend"></div>
		</div>
	</div>

	<!-- ═══ AI COST BREAKDOWN ═══ -->
	<div class="lp-middle" id="lp-breakdown-section" style="display:none;">
		<div class="lp-card">
			<div class="lp-card-header">
				<h3><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e( 'Cost by Workflow', 'luwipress' ); ?></h3>
				<span class="lp-card-badge"><?php esc_html_e( 'Last 30 days', 'luwipress' ); ?></span>
			</div>
			<table class="widefat striped" id="lp-workflow-table" style="margin:12px 0;">
				<thead><tr><th><?php esc_html_e( 'Workflow', 'luwipress' ); ?></th><th><?php esc_html_e( 'Calls', 'luwipress' ); ?></th><th><?php esc_html_e( 'Input Tokens', 'luwipress' ); ?></th><th><?php esc_html_e( 'Output Tokens', 'luwipress' ); ?></th><th><?php esc_html_e( 'Cost', 'luwipress' ); ?></th></tr></thead>
				<tbody></tbody>
			</table>
		</div>
		<div class="lp-card">
			<div class="lp-card-header">
				<h3><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'Cost by Model', 'luwipress' ); ?></h3>
				<span class="lp-card-badge"><?php esc_html_e( 'Last 30 days', 'luwipress' ); ?></span>
			</div>
			<table class="widefat striped" id="lp-model-table" style="margin:12px 0;">
				<thead><tr><th><?php esc_html_e( 'Model', 'luwipress' ); ?></th><th><?php esc_html_e( 'Provider', 'luwipress' ); ?></th><th><?php esc_html_e( 'Calls', 'luwipress' ); ?></th><th><?php esc_html_e( 'Tokens', 'luwipress' ); ?></th><th><?php esc_html_e( 'Cost', 'luwipress' ); ?></th></tr></thead>
				<tbody></tbody>
			</table>
		</div>
	</div>

	<!-- ═══ QUICK ACTIONS ═══ -->
	<div class="lp-actions">
		<?php
		$actions = array(
			array( 'dashicons-edit-large',       '#6366f1', __( 'Enrich Products', 'luwipress' ),    __( 'AI descriptions, SEO, FAQ', 'luwipress' ),         'luwipress-settings&tab=ai' ),
			array( 'dashicons-translation',      '#2563eb', __( 'Translate', 'luwipress' ),          __( 'SEO-aware multilingual', 'luwipress' ),             'luwipress-translations' ),
			array( 'dashicons-welcome-write-blog','#16a34a', __( 'Schedule Content', 'luwipress' ),  __( 'AI blog posts + images', 'luwipress' ),             'luwipress-scheduler' ),
			array( 'dashicons-chart-area',       '#ec4899', __( 'Usage & Logs', 'luwipress' ),       __( 'AI spend, activity', 'luwipress' ),                 'luwipress-usage' ),
		);
		foreach ( $actions as $a ) : ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $a[4] ) ); ?>" class="lp-action-card" style="--accent:<?php echo esc_attr( $a[1] ); ?>">
			<span class="dashicons <?php echo esc_attr( $a[0] ); ?>"></span>
			<div>
				<strong><?php echo esc_html( $a[2] ); ?></strong>
				<small><?php echo esc_html( $a[3] ); ?></small>
			</div>
		</a>
		<?php endforeach; ?>
	</div>

	<!-- ═══ BOTTOM ROW: Opportunities + Activity ═══ -->
	<div class="lp-bottom">
		<!-- Content Opportunities -->
		<div class="lp-card">
			<div class="lp-card-header">
				<h3><span class="dashicons dashicons-lightbulb"></span> <?php esc_html_e( 'Opportunities', 'luwipress' ); ?></h3>
				<button type="button" class="button button-small button-primary" id="lp-bulk-enrich">
					<span class="dashicons dashicons-edit-large"></span> <?php esc_html_e( 'Bulk Enrich', 'luwipress' ); ?>
				</button>
			</div>
			<div class="lp-opps" id="lp-opps">
				<?php
				$opp_defs = array(
					array( 'missing_translations', 'dashicons-translation',      '#2563eb', __( 'Missing Translations', 'luwipress' ), 'luwipress-translations' ),
					array( 'thin_content',         'dashicons-editor-expand',    '#ea580c', __( 'Thin Content', 'luwipress' ),          '' ),
					array( 'missing_seo',          'dashicons-search',           '#dc2626', __( 'Missing SEO Meta', 'luwipress' ),      '' ),
					array( 'missing_alt',          'dashicons-format-image',     '#7c3aed', __( 'Missing Alt Text', 'luwipress' ),      '' ),
					array( 'stale_content',        'dashicons-calendar-alt',     '#eab308', __( 'Stale Content', 'luwipress' ),         '' ),
				);
				foreach ( $opp_defs as $o ) : ?>
				<div class="lp-opp-row" data-opp="<?php echo esc_attr( $o[0] ); ?>">
					<span class="dashicons <?php echo esc_attr( $o[1] ); ?>" style="color:<?php echo esc_attr( $o[2] ); ?>"></span>
					<span class="lp-opp-label"><?php echo esc_html( $o[3] ); ?></span>
					<span class="lp-opp-count lp-skeleton">—</span>
					<?php if ( $o[4] ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $o[4] ) ); ?>" class="lp-opp-link"><?php esc_html_e( 'Fix', 'luwipress' ); ?> &rarr;</a>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- Recent Activity (auto-refresh) -->
		<div class="lp-card">
			<div class="lp-card-header">
				<h3><span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'Recent Activity', 'luwipress' ); ?></h3>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-usage&tab=logs' ) ); ?>" class="lp-link-small"><?php esc_html_e( 'All Logs', 'luwipress' ); ?> &rarr;</a>
			</div>
			<div class="lp-activity" id="lp-activity">
				<div class="lp-skeleton-lines">
					<div class="lp-skeleton-line"></div>
					<div class="lp-skeleton-line"></div>
					<div class="lp-skeleton-line"></div>
					<div class="lp-skeleton-line"></div>
					<div class="lp-skeleton-line"></div>
				</div>
			</div>
		</div>
	</div>

	<?php
	// Translation coverage — only if WPML/Polylang active
	$trans_plugin = $environment['translation']['plugin'] ?? 'none';
	if ( 'none' !== $trans_plugin ) :
		$target_langs = get_option( 'luwipress_translation_languages', array() );
		if ( ! empty( $target_langs ) ) :
	?>
	<!-- ═══ TRANSLATION COVERAGE ═══ -->
	<div class="lp-card lp-trans-card">
		<div class="lp-card-header">
			<h3><span class="dashicons dashicons-translation"></span> <?php esc_html_e( 'Translation Coverage', 'luwipress' ); ?></h3>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-translations' ) ); ?>" class="lp-link-small"><?php esc_html_e( 'Manage', 'luwipress' ); ?> &rarr;</a>
		</div>
		<div class="lp-trans-bars" id="lp-trans-bars">
			<?php foreach ( $target_langs as $lang ) : ?>
			<div class="lp-trans-row" data-lang="<?php echo esc_attr( $lang ); ?>">
				<span class="lp-trans-code"><?php echo esc_html( strtoupper( $lang ) ); ?></span>
				<div class="lp-trans-track">
					<div class="lp-trans-fill lp-skeleton" style="width:0%"></div>
				</div>
				<span class="lp-trans-pct lp-skeleton">—%</span>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; endif; ?>

	<?php
	// Customer Intelligence — only if WooCommerce active
	if ( $wc_active ) :
		$crm_counts = get_option( 'luwipress_crm_segment_counts', array() );
	?>
	<!-- ═══ CUSTOMER SEGMENTS ═══ -->
	<div class="lp-card">
		<div class="lp-card-header">
			<h3><span class="dashicons dashicons-groups"></span> <?php esc_html_e( 'Customer Segments', 'luwipress' ); ?></h3>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-claw' ) ); ?>" class="lp-link-small"><?php esc_html_e( 'Ask AI', 'luwipress' ); ?> &rarr;</a>
		</div>
		<?php if ( ! empty( $crm_counts ) ) : ?>
		<div class="lp-segments">
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
			<span class="lp-seg-pill" style="--seg-color:<?php echo esc_attr( $s[0] ); ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $s[1] ); ?>"></span>
				<strong><?php echo absint( $count ); ?></strong>
				<?php echo esc_html( $s[2] ); ?>
			</span>
			<?php endforeach; ?>
		</div>
		<?php else : ?>
		<p class="lp-empty"><?php esc_html_e( 'Customer segments will be computed on the next weekly refresh.', 'luwipress' ); ?></p>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- ═══ FOOTER ═══ -->
	<div class="lp-footer">
		WordPress <?php echo esc_html( get_bloginfo( 'version' ) ); ?>
		&middot; PHP <?php echo esc_html( phpversion() ); ?>
		<?php if ( $wc_active ) : ?>&middot; WooCommerce <?php echo esc_html( WC_VERSION ); ?><?php endif; ?>
		&middot; LuwiPress AI Engine
	</div>

</div>
