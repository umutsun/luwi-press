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
            ?: get_option( 'luwipress_google_ai_api_key', '' )
            ?: get_option( 'luwipress_openai_compatible_api_key', '' );

$provider_labels = array(
	'openai'             => 'OpenAI',
	'anthropic'          => 'Anthropic',
	'google'             => 'Google AI',
	'openai_compatible'  => 'OpenAI-Compatible',
);
$provider_label  = $provider_labels[ $ai_provider ] ?? ucfirst( $ai_provider );
?>

<div class="wrap luwipress-dashboard">

	<!-- ═══ HEADER ═══ -->
	<!-- Header keeps the brand mark + a tight pill row of nav/version actions.
	     All right-side actions render in the same pill style as the status
	     ribbon below so the visual language stays consistent at a glance. -->
	<div class="lp-header">
		<div class="lp-header-left">
			<h1 class="lp-title">
				<img class="lp-logo" width="28" height="28" src="<?php echo esc_url( LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo.png' ); ?>" alt="LuwiPress" />
				LuwiPress
			</h1>
		</div>
		<div class="lp-header-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-knowledge-graph' ) ); ?>"
			   class="lp-pill lp-pill--action pill-neutral lp-pill--icon"
			   title="<?php esc_attr_e( 'Knowledge Graph — interactive store intelligence (D3.js).', 'luwipress' ); ?>">
				<span class="dashicons dashicons-networking"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Knowledge Graph', 'luwipress' ); ?></span>
			</a>
			<span class="lp-pill pill-neutral" title="<?php esc_attr_e( 'Plugin version', 'luwipress' ); ?>">
				v<?php echo esc_html( LUWIPRESS_VERSION ); ?>
			</span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-settings' ) ); ?>"
			   class="lp-pill lp-pill--action pill-neutral lp-pill--icon"
			   title="<?php esc_attr_e( 'Settings — API keys, providers, budget, integrations.', 'luwipress' ); ?>">
				<span class="dashicons dashicons-admin-generic"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Settings', 'luwipress' ); ?></span>
			</a>
		</div>
	</div>

	<!-- ═══ STATUS RIBBON ═══ -->
	<!-- Every soft-dep category is rendered unconditionally — green dot when a
	     friendly plugin is detected, red dot when the slot is empty. As the
	     operator installs WC / Rank Math / WPML / etc, dots flip green so the
	     WC-less starting state is visually obvious. Hover any pill for the
	     "what does this slot enable, what's recommended" tooltip. -->
	<div class="lp-ribbon">
		<?php
		// Pill rows are now actionable: each red pill links to the WP plugin
		// installer for the recommended .org slug; each green pill links to
		// that plugin's settings/admin page when we know one. Operators don't
		// have to leave the dashboard to fix a missing dependency.
		$pills = array();

		$slug_label = static function ( $slug ) {
			return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
		};

		// Build a "plugin info" thickbox URL for a wordpress.org plugin slug.
		// Opens the standard WP plugin-information popup (screenshots,
		// description, ratings, "Install Now" button) instead of triggering
		// the install immediately. Operators expect to review before installing.
		$install_url = static function ( $org_slug ) {
			return self_admin_url(
				'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( $org_slug ) .
				'&TB_iframe=true&width=772&height=577'
			);
		};

		// Map detected plugin slug → its admin/settings page (best-effort).
		// When the plugin's settings page isn't known, fall back to the WP
		// plugins list so the operator at least lands on the management screen.
		$manage_url = static function ( $detected_slug ) {
			$map = array(
				// SEO
				'rank-math'       => admin_url( 'admin.php?page=rank-math' ),
				'wordpress-seo'   => admin_url( 'admin.php?page=wpseo_dashboard' ),
				'all-in-one-seo'  => admin_url( 'admin.php?page=aioseo' ),
				'aioseo'          => admin_url( 'admin.php?page=aioseo' ),
				'seopress'        => admin_url( 'admin.php?page=seopress-option' ),
				// Translation
				'wpml'            => admin_url( 'admin.php?page=sitepress-multilingual-cms/menu/languages.php' ),
				'polylang'        => admin_url( 'admin.php?page=mlang' ),
				'translatepress'  => admin_url( 'admin.php?page=trp_settings' ),
				// SMTP
				'wp-mail-smtp'    => admin_url( 'admin.php?page=wp-mail-smtp' ),
				'fluent-smtp'     => admin_url( 'admin.php?page=fluent-mail' ),
				'post-smtp'       => admin_url( 'admin.php?page=postman' ),
				// Cache
				'litespeed-cache' => admin_url( 'admin.php?page=litespeed' ),
				'wp-rocket'       => admin_url( 'options-general.php?page=wprocket' ),
				'w3-total-cache'  => admin_url( 'admin.php?page=w3tc_dashboard' ),
				// Page builder
				'elementor'       => admin_url( 'admin.php?page=elementor' ),
				'divi'            => admin_url( 'admin.php?page=et_divi_options' ),
				// Analytics
				'google-site-kit' => admin_url( 'admin.php?page=googlesitekit-dashboard' ),
				'gtm4wp'          => admin_url( 'options-general.php?page=gtm4wp-options' ),
				'monsterinsights' => admin_url( 'admin.php?page=monsterinsights_reports' ),
				// Google Ads / Merchant Center
				'google-listings-and-ads' => admin_url( 'admin.php?page=wc-admin&path=%2Fgoogle%2Fdashboard' ),
				// Meta
				'facebook-for-woocommerce' => admin_url( 'admin.php?page=wc-facebook' ),
			);
			return $map[ $detected_slug ] ?? admin_url( 'plugins.php' );
		};

		// AI Engine — config slot, not a plugin. Click → Settings (API keys tab).
		if ( ! empty( $ai_key ) ) {
			$pills[] = array( 'ok', 'dashicons-admin-generic', sprintf( '%s (%s)', $provider_label, $ai_model ),
				sprintf( __( 'AI provider configured: %1$s · model %2$s. Click to manage in Settings.', 'luwipress' ), $provider_label, $ai_model ),
				admin_url( 'admin.php?page=luwipress-settings' ) );
		} else {
			$pills[] = array( 'err', 'dashicons-admin-generic', __( 'No AI key', 'luwipress' ),
				__( 'No AI provider key set. Click to open Settings → API Keys (OpenAI, Anthropic, Google, or any OpenAI-compatible endpoint).', 'luwipress' ),
				admin_url( 'admin.php?page=luwipress-settings' ) );
		}

		// WooCommerce — click → install (when missing) or wc-admin (when active).
		if ( $wc_active ) {
			$pills[] = array( 'ok', 'dashicons-cart', 'WooCommerce ' . WC_VERSION,
				__( 'WooCommerce is active. Click to open the WC dashboard.', 'luwipress' ),
				admin_url( 'admin.php?page=wc-admin' ) );
		} else {
			$pills[] = array( 'err', 'dashicons-cart', __( 'No WooCommerce', 'luwipress' ),
				__( 'WooCommerce is not active. Click to install it. Plugin runs in WC-less mode meanwhile.', 'luwipress' ),
				$install_url( 'woocommerce' ) );
		}

		// SEO — click → manage (when active) or install Rank Math (when missing).
		$seo = $environment['seo'] ?? array( 'plugin' => 'none' );
		if ( 'luwipress-native' === $seo['plugin'] ) {
			$pills[] = array( 'ok', 'dashicons-search', __( 'LuwiPress SEO', 'luwipress' ),
				__( 'Native SEO writer active (no third-party SEO plugin). Click to install Rank Math for richer features.', 'luwipress' ),
				$install_url( 'seo-by-rank-math' ) );
		} elseif ( 'none' !== $seo['plugin'] ) {
			$pills[] = array( 'ok', 'dashicons-search', $slug_label( $seo['plugin'] ),
				sprintf( __( 'SEO plugin detected: %s. Click to manage it.', 'luwipress' ), $slug_label( $seo['plugin'] ) ),
				$manage_url( $seo['plugin'] ) );
		} else {
			$pills[] = array( 'err', 'dashicons-search', __( 'No SEO plugin', 'luwipress' ),
				__( 'No SEO plugin detected. Click to install Rank Math (recommended). Yoast / AIOSEO / SEOPress also supported.', 'luwipress' ),
				$install_url( 'seo-by-rank-math' ) );
		}

		// Translation — click → manage or install Polylang (free).
		$trans = $environment['translation'] ?? array( 'plugin' => 'none' );
		if ( 'none' !== $trans['plugin'] ) {
			$lang_count = count( $trans['active_languages'] ?? array() );
			$pills[] = array( 'ok', 'dashicons-translation', $slug_label( $trans['plugin'] ) . ( $lang_count > 1 ? " ({$lang_count})" : '' ),
				sprintf( __( 'Translation plugin: %1$s with %2$d languages. Click to manage.', 'luwipress' ), $slug_label( $trans['plugin'] ), $lang_count ),
				$manage_url( $trans['plugin'] ) );
		} else {
			$pills[] = array( 'err', 'dashicons-translation', __( 'No translation', 'luwipress' ),
				__( 'No translation plugin detected. Click to install Polylang (free). WPML and TranslatePress also supported.', 'luwipress' ),
				$install_url( 'polylang' ) );
		}

		// SMTP — click → manage or install WP Mail SMTP.
		$email = $environment['email'] ?? array( 'plugin' => 'wp_mail' );
		if ( 'wp_mail' !== $email['plugin'] && 'none' !== $email['plugin'] ) {
			$pills[] = array( 'ok', 'dashicons-email-alt', $slug_label( $email['plugin'] ),
				sprintf( __( 'SMTP plugin: %s. Email proxy routes through it. Click to manage.', 'luwipress' ), $slug_label( $email['plugin'] ) ),
				$manage_url( $email['plugin'] ) );
		} else {
			$pills[] = array( 'err', 'dashicons-email-alt', __( 'No SMTP', 'luwipress' ),
				__( 'Falling back to wp_mail() (host MTA). Click to install WP Mail SMTP for reliable delivery.', 'luwipress' ),
				$install_url( 'wp-mail-smtp' ) );
		}

		// Cache — click → manage or install LiteSpeed.
		$cache = $environment['cache'] ?? array( 'plugin' => 'none' );
		if ( ! empty( $cache['plugin'] ) && 'none' !== $cache['plugin'] ) {
			$pills[] = array( 'ok', 'dashicons-performance', $slug_label( $cache['plugin'] ),
				sprintf( __( 'Cache plugin: %s. /cache/purge flushes it on content updates. Click to manage.', 'luwipress' ), $slug_label( $cache['plugin'] ) ),
				$manage_url( $cache['plugin'] ) );
		} else {
			$pills[] = array( 'err', 'dashicons-performance', __( 'No cache plugin', 'luwipress' ),
				__( 'No page-cache plugin. Click to install LiteSpeed Cache (recommended). WP Rocket / W3 Total Cache also supported.', 'luwipress' ),
				$install_url( 'litespeed-cache' ) );
		}

		// Page builder — click → manage or install Elementor.
		$page_builder = $environment['page_builder'] ?? array( 'plugin' => 'none' );
		if ( ! empty( $page_builder['plugin'] ) && 'none' !== $page_builder['plugin'] ) {
			$pills[] = array( 'ok', 'dashicons-edit', $slug_label( $page_builder['plugin'] ),
				sprintf( __( 'Page builder: %s. The 30+ Elementor REST endpoints are available. Click to manage.', 'luwipress' ), $slug_label( $page_builder['plugin'] ) ),
				$manage_url( $page_builder['plugin'] ) );
		} else {
			$pills[] = array( 'err', 'dashicons-edit', __( 'No page builder', 'luwipress' ),
				__( 'No page builder. Click to install Elementor (required for read/write/translate page structures).', 'luwipress' ),
				$install_url( 'elementor' ) );
		}

		// Analytics — click → manage or install Site Kit.
		$analytics = $environment['analytics'] ?? array( 'plugin' => 'none' );
		if ( ! empty( $analytics['plugin'] ) && 'none' !== $analytics['plugin'] ) {
			$pills[] = array( 'ok', 'dashicons-chart-bar', $slug_label( $analytics['plugin'] ),
				sprintf( __( 'Analytics: %s. Click to open dashboard.', 'luwipress' ), $slug_label( $analytics['plugin'] ) ),
				$manage_url( $analytics['plugin'] ) );
		} else {
			$pills[] = array( 'err', 'dashicons-chart-bar', __( 'No analytics', 'luwipress' ),
				__( 'No analytics plugin. Click to install Google Site Kit (recommended). GTM4WP / MonsterInsights also supported.', 'luwipress' ),
				$install_url( 'google-site-kit' ) );
		}

		// Track plugin slugs already shown to avoid duplicates across ads/feed categories
		$seen_plugins = array();

		// Google Ads — only suggested when WC active.
		$gads = $environment['google_ads'] ?? array( 'plugin' => 'none' );
		if ( ! empty( $gads['plugin'] ) && 'none' !== $gads['plugin'] ) {
			$pills[] = array( 'ok', 'dashicons-megaphone', $slug_label( $gads['plugin'] ),
				sprintf( __( 'Google Ads / Merchant Center: %s. Click to manage.', 'luwipress' ), $slug_label( $gads['plugin'] ) ),
				$manage_url( $gads['plugin'] ) );
			$seen_plugins[ $gads['plugin'] ] = true;
		} elseif ( $wc_active ) {
			$pills[] = array( 'err', 'dashicons-megaphone', __( 'No Google Ads', 'luwipress' ),
				__( 'No Google Ads / Merchant Center integration. Click to install Google Listings & Ads.', 'luwipress' ),
				$install_url( 'google-listings-and-ads' ) );
		}

		// Meta Ads — only when WC active.
		$meta = $environment['meta_ads'] ?? array( 'plugin' => 'none' );
		if ( ! empty( $meta['plugin'] ) && 'none' !== $meta['plugin'] && empty( $seen_plugins[ $meta['plugin'] ] ) ) {
			$pills[] = array( 'ok', 'dashicons-share', $slug_label( $meta['plugin'] ),
				sprintf( __( 'Meta Pixel / CAPI: %s. Click to manage.', 'luwipress' ), $slug_label( $meta['plugin'] ) ),
				$manage_url( $meta['plugin'] ) );
			$seen_plugins[ $meta['plugin'] ] = true;
		} elseif ( $wc_active ) {
			$pills[] = array( 'err', 'dashicons-share', __( 'No Meta Pixel', 'luwipress' ),
				__( 'No Meta Pixel / Instagram Shopping integration. Click to install Meta for WooCommerce.', 'luwipress' ),
				$install_url( 'facebook-for-woocommerce' ) );
		}

		// Product feed — only when WC active and Google Ads slot empty.
		$feed = $environment['product_feed'] ?? array( 'plugin' => 'none' );
		if ( ! empty( $feed['plugin'] ) && 'none' !== $feed['plugin'] && empty( $seen_plugins[ $feed['plugin'] ] ) ) {
			$pills[] = array( 'ok', 'dashicons-rss', $slug_label( $feed['plugin'] ),
				sprintf( __( 'Product feed: %s.', 'luwipress' ), $slug_label( $feed['plugin'] ) ),
				$manage_url( $feed['plugin'] ) );
		} elseif ( $wc_active && 'none' === ( $gads['plugin'] ?? 'none' ) ) {
			$pills[] = array( 'err', 'dashicons-rss', __( 'No product feed', 'luwipress' ),
				__( 'No product-feed plugin. Click to install Product Feed PRO (Google Shopping / marketplace sync).', 'luwipress' ),
				$install_url( 'product-feed-pro-for-woocommerce' ) );
		}

		foreach ( $pills as $p ) :
			$state = $p[0];
			$icon  = $p[1];
			$label = $p[2];
			$tip   = $p[3];
			$url   = $p[4];
			$cls   = 'ok' === $state ? 'pill-ok' : ( 'err' === $state ? 'pill-err' : 'pill-neutral' );
			$tag   = $url ? 'a' : 'span';
			// Thickbox (TB_iframe) URLs need the `thickbox` class to trigger
			// the WP modal handler. Otherwise the link would open the iframe
			// URL directly in the parent window. Detect by URL fragment.
			$is_thickbox = $url && false !== strpos( $url, 'TB_iframe=true' );
			$href  = $url ? ' href="' . esc_url( $url ) . '"' : '';
			$class_extra = $url ? ' lp-pill--action' : '';
			if ( $is_thickbox ) {
				$class_extra .= ' thickbox';
			}
		?>
		<<?php echo esc_html( $tag ); ?> class="lp-pill lp-pill--has-tip <?php echo esc_attr( $cls . $class_extra ); ?>" tabindex="0" title="<?php echo esc_attr( $tip ); ?>"<?php echo $href; // already escaped ?>>
			<span class="lp-pill-dot" aria-hidden="true"></span>
			<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
			<?php echo esc_html( $label ); ?>
			<?php if ( $tip ) : ?>
				<span class="lp-pill-tip" role="tooltip" style="display:none;"><?php echo esc_html( $tip ); ?></span>
			<?php endif; ?>
		</<?php echo esc_html( $tag ); ?>>
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
