<?php
/**
 * n8nPress Dashboard Page
 *
 * Main admin dashboard showing environment detection, content opportunities,
 * workflow activity, and module status.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Core data
$detector       = N8nPress_Plugin_Detector::get_instance();
$environment    = $detector->get_environment();
$logs           = N8nPress_Logger::get_logs( 50 );
$recent_errors  = N8nPress_Logger::get_logs( 10, 'error' );
$workflow_stats = N8nPress_Workflow_Tracker::get_stats( 7 );
$webhook_url    = get_option( 'n8npress_seo_webhook_url', '' );

// Log counts (last 7 days)
$log_counts = array( 'info' => 0, 'warning' => 0, 'error' => 0 );
foreach ( $logs as $log ) {
	if ( isset( $log_counts[ $log->level ] ) ) {
		$log_counts[ $log->level ]++;
	}
}

// Product counts
$product_counts = wp_count_posts( 'product' );
$total_products = absint( $product_counts->publish ?? 0 );
$post_counts    = wp_count_posts( 'post' );
$total_posts    = absint( $post_counts->publish ?? 0 );

// Workflow stats
$wf_totals      = $workflow_stats['totals'] ?? (object) array();
$wf_total       = intval( $wf_totals->total ?? 0 );
$wf_success     = intval( $wf_totals->success_count ?? 0 );
$wf_errors      = intval( $wf_totals->error_count ?? 0 );
$wf_success_rate = $wf_total > 0 ? round( ( $wf_success / $wf_total ) * 100 ) : 0;

// Translation data
$translation   = $environment['translation'];
$lang_count    = count( $translation['active_languages'] ?? array() );
$missing_langs = max( 0, $lang_count - 1 ); // exclude default

// WooCommerce
$wc_active = class_exists( 'WooCommerce' );
$wc_currency = $wc_active ? get_woocommerce_currency() : '—';
?>

<div class="wrap n8npress-dashboard">
	<h1 class="n8npress-title">
		<span class="dashicons dashicons-networking"></span>
		n8nPress
		<span class="n8npress-version">v<?php echo esc_html( N8NPRESS_VERSION ); ?></span>
	</h1>

	<!-- Connection Status Bar -->
	<div class="n8npress-status-bar">
		<div class="n8npress-status-item <?php echo ! empty( $webhook_url ) ? 'status-ok' : 'status-error'; ?>">
			<span class="dashicons <?php echo ! empty( $webhook_url ) ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
			<strong><?php esc_html_e( 'n8n:', 'n8npress' ); ?></strong>
			<?php if ( ! empty( $webhook_url ) ) : ?>
				<span class="status-text"><?php esc_html_e( 'Connected', 'n8npress' ); ?></span>
			<?php else : ?>
				<span class="status-text"><?php esc_html_e( 'Not configured', 'n8npress' ); ?></span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=n8npress-settings' ) ); ?>" class="button button-small"><?php esc_html_e( 'Setup', 'n8npress' ); ?></a>
			<?php endif; ?>
		</div>
		<div class="n8npress-status-item <?php echo $wc_active ? 'status-ok' : 'status-warning'; ?>">
			<span class="dashicons <?php echo $wc_active ? 'dashicons-yes-alt' : 'dashicons-info-outline'; ?>"></span>
			<strong><?php esc_html_e( 'WooCommerce:', 'n8npress' ); ?></strong>
			<span class="status-text"><?php echo $wc_active ? esc_html( 'v' . WC_VERSION . ' (' . $wc_currency . ')' ) : esc_html__( 'Not active', 'n8npress' ); ?></span>
		</div>
		<div class="n8npress-status-item status-ok">
			<span class="dashicons dashicons-rest-api"></span>
			<strong><?php esc_html_e( 'REST API:', 'n8npress' ); ?></strong>
			<span class="status-text"><?php esc_html_e( 'Active', 'n8npress' ); ?></span>
		</div>
		<?php
		$pb = $environment['page_builder'] ?? array();
		if ( ! empty( $pb['plugin'] ) && 'none' !== $pb['plugin'] ) : ?>
		<div class="n8npress-status-item status-ok">
			<span class="dashicons dashicons-layout"></span>
			<strong><?php echo esc_html( ucwords( str_replace( '-', ' ', $pb['plugin'] ) ) ); ?></strong>
			<span class="status-text"><?php echo esc_html( 'v' . ( $pb['version'] ?? '' ) ); ?></span>
		</div>
		<?php endif;
		$cache = $environment['cache'] ?? array();
		if ( ! empty( $cache['plugin'] ) && 'none' !== $cache['plugin'] ) : ?>
		<div class="n8npress-status-item status-ok">
			<span class="dashicons dashicons-performance"></span>
			<strong><?php echo esc_html( ucwords( str_replace( '-', ' ', $cache['plugin'] ) ) ); ?></strong>
			<span class="status-text"><?php echo esc_html( 'v' . ( $cache['version'] ?? '' ) ); ?></span>
		</div>
		<?php endif; ?>
	</div>

	<!-- Quick Actions -->
	<div class="n8npress-quick-actions">
		<button type="button" class="button n8npress-quick-btn" id="n8npress-qa-scan" title="Scan content opportunities">
			<span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Scan Opportunities', 'n8npress' ); ?>
		</button>
		<button type="button" class="button n8npress-quick-btn" id="n8npress-qa-enrich" title="Enrich thin content with AI">
			<span class="dashicons dashicons-edit-large"></span> <?php esc_html_e( 'Bulk Enrich', 'n8npress' ); ?>
		</button>
		<button type="button" class="button n8npress-quick-btn" id="n8npress-qa-content" title="Generate AI content">
			<span class="dashicons dashicons-welcome-write-blog"></span> <?php esc_html_e( 'Generate Content', 'n8npress' ); ?>
		</button>
		<button type="button" class="button n8npress-quick-btn" id="n8npress-qa-translate" title="Translate missing content">
			<span class="dashicons dashicons-translation"></span> <?php esc_html_e( 'Translate', 'n8npress' ); ?>
		</button>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=n8npress-claw' ) ); ?>" class="button button-primary n8npress-quick-btn">
			<span class="dashicons dashicons-superhero-alt"></span> <?php esc_html_e( 'Open Claw AI', 'n8npress' ); ?>
		</a>
	</div>

	<!-- ============================================
	     DETECTED PLUGINS (Environment Snapshot)
	     ============================================ -->
	<div class="n8npress-section">
		<h2>
			<span class="dashicons dashicons-plugins-checked"></span>
			<?php esc_html_e( 'Detected Environment', 'n8npress' ); ?>
		</h2>
		<p class="description" style="margin-top:-8px;margin-bottom:16px;">
			<?php esc_html_e( 'n8nPress automatically detects and integrates with your existing plugins. No duplicate configuration needed.', 'n8npress' ); ?>
		</p>

		<div class="n8npress-detected-grid">
			<?php
			$plugin_cards = array(
				array(
					'label'    => __( 'SEO', 'n8npress' ),
					'icon'     => 'dashicons-search',
					'data'     => $environment['seo'],
					'color'    => '#16a34a',
					'how'      => __( 'Reads & writes meta titles, descriptions, focus keywords', 'n8npress' ),
				),
				array(
					'label'    => __( 'Translation', 'n8npress' ),
					'icon'     => 'dashicons-translation',
					'data'     => $environment['translation'],
					'color'    => '#2563eb',
					'how'      => __( 'Saves translations via plugin API, reads active languages', 'n8npress' ),
					'extra'    => $lang_count > 1 ? sprintf( __( '%d languages active', 'n8npress' ), $lang_count ) : '',
					'warning'  => ( ! empty( $environment['translation']['plugin'] ) && 'none' !== $environment['translation']['plugin'] && empty( $environment['translation']['features']['woocommerce'] ) && class_exists( 'WooCommerce' ) )
						? sprintf( __( '%s for WooCommerce not detected — product translations will not work', 'n8npress' ), ucwords( str_replace( '-', ' ', $environment['translation']['plugin'] ) ) )
						: '',
				),
				array(
					'label'    => __( 'Email / SMTP', 'n8npress' ),
					'icon'     => 'dashicons-email-alt',
					'data'     => $environment['email'],
					'color'    => '#ea580c',
					'how'      => __( 'All emails sent via wp_mail() using your SMTP config', 'n8npress' ),
					'extra'    => ! empty( $environment['email']['from_email'] ) ? $environment['email']['from_email'] : '',
				),
				array(
					'label'    => __( 'CRM', 'n8npress' ),
					'icon'     => 'dashicons-groups',
					'data'     => $environment['crm'],
					'color'    => '#7c3aed',
					'how'      => __( 'Avoids duplicate customer campaigns', 'n8npress' ),
				),
				array(
					'label'    => __( 'Customer Support', 'n8npress' ),
					'icon'     => 'dashicons-format-chat',
					'data'     => $environment['customer_support'],
					'color'    => '#0ea5e9',
					'how'      => __( 'Receives conversations, syncs WC customer data', 'n8npress' ),
				),
				// Page Builder and Cache are shown in the status bar above
			);

			foreach ( $plugin_cards as $card ) :
				$detected = $card['data']['plugin'] ?? 'none';
				$version  = $card['data']['version'] ?? '';
				$is_found = ( 'none' !== $detected && 'wp_mail' !== $detected );
				$display  = $is_found ? ucwords( str_replace( '-', ' ', $detected ) ) : ( 'wp_mail' === $detected ? 'wp_mail()' : __( 'Not detected', 'n8npress' ) );
			?>
			<div class="n8npress-detected-card <?php echo $is_found || 'wp_mail' === $detected ? 'detected-active' : 'detected-none'; ?>">
				<div class="detected-header">
					<span class="dashicons <?php echo esc_attr( $card['icon'] ); ?>" style="color:<?php echo esc_attr( $card['color'] ); ?>"></span>
					<span class="detected-label"><?php echo esc_html( $card['label'] ); ?></span>
				</div>
				<div class="detected-plugin-name">
					<?php echo esc_html( $display ); ?>
					<?php if ( $version ) : ?>
						<span class="detected-version">v<?php echo esc_html( $version ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $card['how'] ) && ( $is_found || 'wp_mail' === $detected ) ) : ?>
					<div class="detected-how"><?php echo esc_html( $card['how'] ); ?></div>
				<?php endif; ?>
				<?php if ( ! empty( $card['extra'] ) ) : ?>
					<div class="detected-extra"><?php echo esc_html( $card['extra'] ); ?></div>
				<?php endif; ?>
				<?php if ( ! empty( $card['warning'] ) ) : ?>
					<div class="detected-warning" style="margin-top:6px;padding:4px 8px;background:#fef3c7;border-left:3px solid #f59e0b;font-size:11px;color:#92400e;border-radius:3px;">
						<span class="dashicons dashicons-warning" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:2px;"></span>
						<?php echo esc_html( $card['warning'] ); ?>
					</div>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- ============================================
	     AI AUTOMATION SUMMARY — What n8nPress adds
	     ============================================ -->
	<div class="n8npress-section n8npress-ai-summary">
		<h2>
			<span class="dashicons dashicons-superhero-alt"></span>
			<?php esc_html_e( 'AI Automation — What Your Plugins Can\'t Do Alone', 'n8npress' ); ?>
		</h2>
		<p class="description" style="margin-top:-8px;margin-bottom:16px;">
			<?php esc_html_e( 'n8nPress fills the automation gap. Your plugins handle the basics — we add AI intelligence on top.', 'n8npress' ); ?>
		</p>

		<div class="n8npress-ai-capabilities-grid">
			<?php
			$seo_name   = 'none' !== $environment['seo']['plugin'] ? ucwords( str_replace( '-', ' ', $environment['seo']['plugin'] ) ) : null;
			$trans_name = 'none' !== $translation['plugin'] ? ucwords( str_replace( '-', ' ', $translation['plugin'] ) ) : null;

			$capabilities = array(
				array(
					'icon'       => 'dashicons-edit-large',
					'color'      => '#6366f1',
					'title'      => __( 'AI Product Enrichment', 'n8npress' ),
					'plugin'     => $seo_name,
					'plugin_does' => $seo_name ? sprintf( __( '%s stores meta titles & descriptions', 'n8npress' ), $seo_name ) : __( 'No SEO plugin detected', 'n8npress' ),
					'we_add'     => __( 'AI generates compelling descriptions, FAQ, schema & alt text automatically', 'n8npress' ),
				),
				array(
					'icon'       => 'dashicons-translation',
					'color'      => '#2563eb',
					'title'      => __( 'SEO-Aware Translation', 'n8npress' ),
					'plugin'     => $trans_name,
					'plugin_does' => $trans_name ? sprintf( __( '%s manages language structure', 'n8npress' ), $trans_name ) : __( 'No translation plugin detected', 'n8npress' ),
					'we_add'     => __( 'AI translates with SEO intent — keyword-optimized titles, natural descriptions', 'n8npress' ),
				),
				array(
					'icon'       => 'dashicons-microphone',
					'color'      => '#ec4899',
					'title'      => __( 'Answer Engine Optimization', 'n8npress' ),
					'plugin'     => $seo_name,
					'plugin_does' => $seo_name ? sprintf( __( '%s handles basic schema markup', 'n8npress' ), $seo_name ) : __( 'Manual schema only', 'n8npress' ),
					'we_add'     => __( 'AI generates FAQ, HowTo & Speakable structured data for voice/AI search', 'n8npress' ),
				),
				array(
					'icon'       => 'dashicons-format-chat',
					'color'      => '#ea580c',
					'title'      => __( 'AI Review Responses', 'n8npress' ),
					'plugin'     => __( 'WooCommerce', 'n8npress' ),
					'plugin_does' => __( 'WooCommerce collects product reviews', 'n8npress' ),
					'we_add'     => __( 'AI drafts professional, personalized replies to every review', 'n8npress' ),
				),
				array(
					'icon'       => 'dashicons-calendar-alt',
					'color'      => '#16a34a',
					'title'      => __( 'AI Content Scheduling', 'n8npress' ),
					'plugin'     => __( 'WordPress', 'n8npress' ),
					'plugin_does' => __( 'WordPress can schedule posts', 'n8npress' ),
					'we_add'     => __( 'AI generates fresh blog posts & product content on autopilot', 'n8npress' ),
				),
				array(
					'icon'       => 'dashicons-format-chat',
					'color'      => '#0ea5e9',
					'title'      => __( 'Customer Support Automation', 'n8npress' ),
					'plugin'     => ! empty( $environment['customer_support']['plugin'] ) && 'none' !== $environment['customer_support']['plugin'] ? 'Chatwoot' : null,
					'plugin_does' => ! empty( $environment['customer_support']['plugin'] ) && 'none' !== $environment['customer_support']['plugin'] ? __( 'Chatwoot collects conversations from all channels', 'n8npress' ) : __( 'No customer support platform detected', 'n8npress' ),
					'we_add'     => __( 'AI auto-responses, WooCommerce customer sync, order lookup in conversations', 'n8npress' ),
				),
				array(
					'icon'       => 'dashicons-email-alt',
					'color'      => '#7c3aed',
					'title'      => __( 'Unified Email Bridge', 'n8npress' ),
					'plugin'     => ucwords( str_replace( '-', ' ', $environment['email']['plugin'] ) ),
					'plugin_does' => sprintf( __( '%s handles delivery', 'n8npress' ), ucwords( str_replace( '-', ' ', $environment['email']['plugin'] ) ) ),
					'we_add'     => __( 'n8n workflows send transactional emails through your existing SMTP', 'n8npress' ),
				),
			);

			foreach ( $capabilities as $cap ) : ?>
			<div class="n8npress-capability-card">
				<div class="capability-header">
					<span class="dashicons <?php echo esc_attr( $cap['icon'] ); ?>" style="color:<?php echo esc_attr( $cap['color'] ); ?>"></span>
					<strong><?php echo esc_html( $cap['title'] ); ?></strong>
				</div>
				<div class="capability-comparison">
					<div class="capability-row capability-plugin">
						<span class="capability-badge badge-plugin"><?php echo esc_html( $cap['plugin'] ?? '—' ); ?></span>
						<span class="capability-text"><?php echo esc_html( $cap['plugin_does'] ); ?></span>
					</div>
					<div class="capability-row capability-n8npress">
						<span class="capability-badge badge-n8npress">+ n8nPress</span>
						<span class="capability-text"><?php echo esc_html( $cap['we_add'] ); ?></span>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- ============================================
	     CONTENT OPPORTUNITIES (AI can fix these)
	     ============================================ -->
	<div class="n8npress-section">
		<h2>
			<span class="dashicons dashicons-chart-area"></span>
			<?php esc_html_e( 'Content Opportunities', 'n8npress' ); ?>
			<span style="display:flex;gap:8px;">
				<button type="button" class="button button-small" id="n8npress-refresh-opportunities">
					<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Scan Now', 'n8npress' ); ?>
				</button>
				<button type="button" class="button button-small button-primary" id="n8npress-bulk-enrich-thin">
					<span class="dashicons dashicons-edit-large"></span> <?php esc_html_e( 'Bulk Enrich Thin Content', 'n8npress' ); ?>
				</button>
			</span>
		</h2>
		<p class="description" style="margin-top:-8px;margin-bottom:16px;">
			<?php esc_html_e( 'Content gaps that n8nPress AI workflows can fill automatically.', 'n8npress' ); ?>
		</p>

		<div class="n8npress-opportunities-grid" id="n8npress-opportunities">
			<div class="n8npress-opp-card opp-translation">
				<div class="opp-icon"><span class="dashicons dashicons-translation"></span></div>
				<div class="opp-content">
					<span class="opp-number" id="opp-missing-translations">—</span>
					<span class="opp-label"><?php esc_html_e( 'Missing Translations', 'n8npress' ); ?></span>
					<span class="opp-action"><?php esc_html_e( 'AI can translate', 'n8npress' ); ?></span>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=n8npress-translations' ) ); ?>" class="opp-link">View &rarr;</a>
				</div>
			</div>
			<div class="n8npress-opp-card opp-thin">
				<div class="opp-icon"><span class="dashicons dashicons-editor-expand"></span></div>
				<div class="opp-content">
					<span class="opp-number" id="opp-thin-content">—</span>
					<span class="opp-label"><?php esc_html_e( 'Thin Content', 'n8npress' ); ?></span>
					<span class="opp-action"><?php esc_html_e( 'AI can enrich', 'n8npress' ); ?></span>
						<a href="#" class="opp-link" id="opp-enrich-thin-link">Enrich Now &rarr;</a>
				</div>
			</div>
			<div class="n8npress-opp-card opp-alt">
				<div class="opp-icon"><span class="dashicons dashicons-format-image"></span></div>
				<div class="opp-content">
					<span class="opp-number" id="opp-missing-alt">—</span>
					<span class="opp-label"><?php esc_html_e( 'Missing Alt Text', 'n8npress' ); ?></span>
					<span class="opp-action"><?php esc_html_e( 'AI can generate', 'n8npress' ); ?></span>
						<a href="<?php echo esc_url( admin_url( 'upload.php?mode=list' ) ); ?>" class="opp-link">View Media &rarr;</a>
				</div>
			</div>
			<div class="n8npress-opp-card opp-stale">
				<div class="opp-icon"><span class="dashicons dashicons-calendar-alt"></span></div>
				<div class="opp-content">
					<span class="opp-number" id="opp-stale-content">—</span>
					<span class="opp-label"><?php esc_html_e( 'Stale Content', 'n8npress' ); ?></span>
					<span class="opp-action"><?php esc_html_e( 'AI can refresh', 'n8npress' ); ?></span>
						<a href="#" class="opp-link" id="opp-enrich-stale-link">Refresh Now &rarr;</a>
				</div>
			</div>
			<div class="n8npress-opp-card opp-seo">
				<div class="opp-icon"><span class="dashicons dashicons-search"></span></div>
				<div class="opp-content">
					<span class="opp-number" id="opp-missing-seo">—</span>
					<span class="opp-label"><?php esc_html_e( 'Missing SEO Meta', 'n8npress' ); ?></span>
					<span class="opp-action"><?php esc_html_e( 'AI can write', 'n8npress' ); ?></span>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>" class="opp-link">View Products &rarr;</a>
				</div>
			</div>
		</div>
	</div>

	<!-- ============================================
	     QUICK STATS + WORKFLOW ACTIVITY
	     ============================================ -->
	<div class="n8npress-stats-row">
		<div class="n8npress-stat-card">
			<div class="stat-icon"><span class="dashicons dashicons-cart"></span></div>
			<div class="stat-content">
				<span class="stat-number"><?php echo intval( $total_products ); ?></span>
				<span class="stat-label"><?php esc_html_e( 'Products', 'n8npress' ); ?></span>
			</div>
		</div>
		<div class="n8npress-stat-card">
			<div class="stat-icon"><span class="dashicons dashicons-admin-post"></span></div>
			<div class="stat-content">
				<span class="stat-number"><?php echo intval( $total_posts ); ?></span>
				<span class="stat-label"><?php esc_html_e( 'Posts', 'n8npress' ); ?></span>
			</div>
		</div>
		<div class="n8npress-stat-card">
			<div class="stat-icon"><span class="dashicons dashicons-update"></span></div>
			<div class="stat-content">
				<span class="stat-number"><?php echo intval( $wf_total ); ?></span>
				<span class="stat-label"><?php esc_html_e( 'Workflows (7d)', 'n8npress' ); ?></span>
			</div>
		</div>
		<div class="n8npress-stat-card <?php echo $wf_errors > 0 ? 'stat-error' : ''; ?>">
			<div class="stat-icon"><span class="dashicons dashicons-<?php echo $wf_errors > 0 ? 'dismiss' : 'yes-alt'; ?>"></span></div>
			<div class="stat-content">
				<span class="stat-number"><?php echo intval( $wf_success_rate ); ?>%</span>
				<span class="stat-label"><?php esc_html_e( 'Success Rate', 'n8npress' ); ?></span>
			</div>
		</div>
	</div>

	<!-- ============================================
	     MODULES
	     ============================================ -->
	<div class="n8npress-section">
		<h2><?php esc_html_e( 'Active Modules', 'n8npress' ); ?></h2>
		<div class="n8npress-modules-grid">
			<?php
			$ai_key = get_option('n8npress_ai_api_key', '') ?: get_option('n8npress_anthropic_api_key', '') ?: get_option('n8npress_openai_api_key', '');
			$module_health = array(
				'ai_content' => array(
					'status' => (!empty($webhook_url) && !empty($ai_key)) ? 'green' : (!empty($webhook_url) ? 'yellow' : 'red'),
					'link' => admin_url('admin.php?page=n8npress-settings&tab=ai-content'),
				),
				'aeo' => array(
					'status' => !empty($webhook_url) ? 'green' : 'red',
					'link' => admin_url('admin.php?page=n8npress-settings&tab=ai-content'),
				),
				'translation' => array(
					'status' => ($translation['plugin'] !== 'none' && !empty($webhook_url)) ? 'green' : ($translation['plugin'] !== 'none' ? 'yellow' : 'red'),
					'link' => admin_url('admin.php?page=n8npress-settings&tab=translation'),
				),
				'scheduler' => array(
					'status' => !empty($webhook_url) ? 'green' : 'red',
					'link' => admin_url('admin.php?page=n8npress-scheduler'),
				),
				'review' => array(
					'status' => ($wc_active && !empty($webhook_url)) ? 'green' : 'yellow',
					'link' => admin_url('admin.php?page=n8npress-settings&tab=general'),
				),
				'email' => array(
					'status' => 'green',
					'link' => admin_url('admin.php?page=n8npress-settings&tab=general'),
				),
				'chatwoot' => array(
					'status' => (get_option('n8npress_chatwoot_enabled') && !empty(get_option('n8npress_chatwoot_url')) && !empty(get_option('n8npress_chatwoot_api_token'))) ? 'green' : (get_option('n8npress_chatwoot_enabled') ? 'yellow' : 'red'),
					'link' => admin_url('admin.php?page=n8npress-settings&tab=chatwoot'),
				),
			);

			$modules = array(
				array(
					'name'       => __( 'AI Content Engine', 'n8npress' ),
					'icon'       => 'dashicons-edit-large',
					'class'      => 'N8nPress_AI_Content',
					'desc'       => __( 'AI product enrichment — descriptions, meta, FAQ, schema, alt text', 'n8npress' ),
					'color'      => '#6366f1',
					'health_key' => 'ai_content',
				),
				array(
					'name'       => __( 'AEO Generator', 'n8npress' ),
					'icon'       => 'dashicons-microphone',
					'class'      => 'N8nPress_AEO',
					'desc'       => __( 'Answer Engine Optimization — FAQ, HowTo, Speakable structured data', 'n8npress' ),
					'color'      => '#ec4899',
					'health_key' => 'aeo',
				),
				array(
					'name'       => __( 'Translation Bridge', 'n8npress' ),
					'icon'       => 'dashicons-translation',
					'class'      => 'N8nPress_Translation',
					'desc'       => sprintf(
						__( 'SEO-aware AI translation via %s', 'n8npress' ),
						'none' !== $translation['plugin'] ? ucwords( str_replace( '-', ' ', $translation['plugin'] ) ) : 'n8nPress native'
					),
					'color'      => '#2563eb',
					'health_key' => 'translation',
				),
				array(
					'name'       => __( 'Content Scheduler', 'n8npress' ),
					'icon'       => 'dashicons-calendar-alt',
					'class'      => 'N8nPress_Content_Scheduler',
					'desc'       => __( 'AI-generated blog posts & product content on schedule via n8n', 'n8npress' ),
					'color'      => '#16a34a',
					'health_key' => 'scheduler',
				),
				array(
					'name'       => __( 'AI Review Responder', 'n8npress' ),
					'icon'       => 'dashicons-format-chat',
					'class'      => 'n8n-workflow',
					'desc'       => __( 'Automatically respond to product reviews with AI via n8n workflow', 'n8npress' ),
					'color'      => '#ea580c',
					'health_key' => 'review',
				),
				array(
					'name'       => __( 'Email Proxy', 'n8npress' ),
					'icon'       => 'dashicons-email-alt',
					'class'      => 'N8nPress_Email_Proxy',
					'desc'       => sprintf(
						__( 'All workflow emails via wp_mail() — using %s', 'n8npress' ),
						ucwords( str_replace( '-', ' ', $environment['email']['plugin'] ) )
					),
					'color'      => '#7c3aed',
					'health_key' => 'email',
				),
				array(
					'name'       => __( 'Chatwoot', 'n8npress' ),
					'icon'       => 'dashicons-format-chat',
					'class'      => 'N8nPress_Chatwoot',
					'desc'       => __( 'Customer support — WhatsApp, website widget, Instagram. AI auto-responses via n8n.', 'n8npress' ),
					'color'      => '#0ea5e9',
					'health_key' => 'chatwoot',
				),
			);

			foreach ( $modules as $mod ) :
				$is_workflow = ( 'n8n-workflow' === $mod['class'] );
				$active = $is_workflow || class_exists( $mod['class'] );
				$badge_label = $is_workflow ? __( 'n8n Workflow', 'n8npress' ) : ( $active ? __( 'Active', 'n8npress' ) : __( 'Inactive', 'n8npress' ) );
				$badge_class = $is_workflow ? 'badge-workflow' : ( $active ? 'badge-active' : 'badge-inactive' );
				$health_key    = $mod['health_key'] ?? '';
				$health_status = isset( $module_health[ $health_key ] ) ? $module_health[ $health_key ]['status'] : 'green';
				$health_link   = isset( $module_health[ $health_key ] ) ? $module_health[ $health_key ]['link'] : '#';
			?>
			<a href="<?php echo esc_url( $health_link ); ?>" class="n8npress-module-card <?php echo $active ? 'module-active' : 'module-inactive'; ?>" style="text-decoration:none;color:inherit;display:block;">
				<div class="module-header">
					<span class="module-health module-health-<?php echo esc_attr( $health_status ); ?>"></span>
					<span class="dashicons <?php echo esc_attr( $mod['icon'] ); ?>" style="color:<?php echo esc_attr( $mod['color'] ); ?>"></span>
					<h3><?php echo esc_html( $mod['name'] ); ?></h3>
					<span class="module-badge <?php echo esc_attr( $badge_class ); ?>">
						<?php echo esc_html( $badge_label ); ?>
					</span>
				</div>
				<p class="module-description"><?php echo esc_html( $mod['desc'] ); ?></p>
			</a>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- ============================================
	     QUICK ACTIONS
	     ============================================ -->
	<div class="n8npress-section">
		<h2>
			<span class="dashicons dashicons-controls-play"></span>
			<?php esc_html_e( 'Quick Actions', 'n8npress' ); ?>
		</h2>
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
			<?php
			$actions = array(
				array(
					'label' => __( 'Translate Products', 'n8npress' ),
					'desc'  => __( 'AI translation for all languages', 'n8npress' ),
					'icon'  => 'dashicons-translation',
					'url'   => admin_url( 'admin.php?page=n8npress-translations' ),
					'color' => '#2563eb',
				),
				array(
					'label' => __( 'Enrich Products', 'n8npress' ),
					'desc'  => __( 'AI descriptions, meta, FAQ', 'n8npress' ),
					'icon'  => 'dashicons-edit-large',
					'url'   => admin_url( 'admin.php?page=n8npress-settings&tab=ai-content' ),
					'color' => '#6366f1',
				),
				array(
					'label' => __( 'Open Claw Chat', 'n8npress' ),
					'desc'  => __( 'AI assistant for your store', 'n8npress' ),
					'icon'  => 'dashicons-format-chat',
					'url'   => admin_url( 'admin.php?page=n8npress-claw' ),
					'color' => '#16a34a',
				),
				array(
					'label' => __( 'Usage & Logs', 'n8npress' ),
					'desc'  => __( 'AI spend, activity, API calls', 'n8npress' ),
					'icon'  => 'dashicons-chart-area',
					'url'   => admin_url( 'admin.php?page=n8npress-usage' ),
					'color' => '#f59e0b',
				),
			);
			foreach ( $actions as $act ) : ?>
			<a href="<?php echo esc_url( $act['url'] ); ?>" style="display:flex;gap:12px;align-items:center;padding:14px 16px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;border-left:4px solid <?php echo esc_attr( $act['color'] ); ?>;text-decoration:none;transition:box-shadow .2s;" onmouseover="this.style.boxShadow='0 2px 8px rgba(0,0,0,.08)'" onmouseout="this.style.boxShadow='none'">
				<span class="dashicons <?php echo esc_attr( $act['icon'] ); ?>" style="font-size:24px;width:24px;height:24px;color:<?php echo esc_attr( $act['color'] ); ?>;"></span>
				<div>
					<div style="font-size:14px;font-weight:600;color:#1e293b;"><?php echo esc_html( $act['label'] ); ?></div>
					<div style="font-size:12px;color:#6b7280;"><?php echo esc_html( $act['desc'] ); ?></div>
				</div>
			</a>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- ============================================
	     RECENT ACTIVITY + AI SPEND (compact)
	     ============================================ -->
	<div class="n8npress-two-col">
		<!-- Recent Activity (compact) -->
		<div class="n8npress-section">
			<h2>
				<span class="dashicons dashicons-clock"></span>
				<?php esc_html_e( 'Recent Activity', 'n8npress' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=n8npress-usage&tab=logs' ) ); ?>" class="n8npress-view-all"><?php esc_html_e( 'View All', 'n8npress' ); ?></a>
			</h2>
			<div class="n8npress-activity-feed">
				<?php
				$recent_logs = array_slice( $logs, 0, 8 );
				if ( empty( $recent_logs ) ) : ?>
					<div class="activity-empty"><?php esc_html_e( 'No recent activity.', 'n8npress' ); ?></div>
				<?php else :
					foreach ( $recent_logs as $log ) :
						$icon_class = 'info' === $log->level ? 'dashicons-yes-alt' : ( 'warning' === $log->level ? 'dashicons-warning' : 'dashicons-dismiss' );
						$level_class = esc_attr( $log->level );
					?>
					<div class="activity-item activity-<?php echo $level_class; ?>" data-level="<?php echo $level_class; ?>">
						<span class="activity-icon activity-icon-<?php echo $level_class; ?>">
							<span class="dashicons <?php echo esc_attr( $icon_class ); ?>"></span>
						</span>
						<div class="activity-body">
							<span class="activity-message"><?php echo esc_html( $log->message ); ?></span>
							<span class="activity-time"><?php echo esc_html( human_time_diff( strtotime( $log->timestamp ), current_time( 'timestamp' ) ) ); ?> ago</span>
						</div>
					</div>
					<?php endforeach;
				endif; ?>
			</div>
		</div>

		<!-- AI Spend (compact) -->
		<?php
		$token_stats = class_exists( 'N8nPress_Token_Tracker' ) ? N8nPress_Token_Tracker::get_stats( 30 ) : null;
		if ( $token_stats ) :
			$today     = $token_stats['today'];
			$month     = $token_stats['month'];
			$limit     = $token_stats['daily_limit'];
			$limit_pct = $token_stats['limit_used'];
		?>
		<div class="n8npress-section">
			<h2>
				<span class="dashicons dashicons-chart-bar"></span>
				<?php esc_html_e( 'AI Spend', 'n8npress' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=n8npress-usage' ) ); ?>" class="n8npress-view-all"><?php esc_html_e( 'Details', 'n8npress' ); ?></a>
			</h2>
			<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
				<div style="text-align:center;padding:12px;background:#f8fafc;border-radius:8px;">
					<div style="font-size:11px;color:#6b7280;text-transform:uppercase;"><?php esc_html_e( 'Today', 'n8npress' ); ?></div>
					<div style="font-size:22px;font-weight:700;">$<?php echo number_format( $today['cost'], 4 ); ?></div>
					<div style="font-size:11px;color:#6b7280;"><?php echo $today['calls']; ?> calls</div>
				</div>
				<div style="text-align:center;padding:12px;background:#f8fafc;border-radius:8px;">
					<div style="font-size:11px;color:#6b7280;text-transform:uppercase;"><?php esc_html_e( 'Month', 'n8npress' ); ?></div>
					<div style="font-size:22px;font-weight:700;">$<?php echo number_format( $month['cost'], 4 ); ?></div>
					<div style="font-size:11px;color:#6b7280;"><?php echo $month['calls']; ?> calls</div>
				</div>
				<div style="text-align:center;padding:12px;background:<?php echo $limit_pct >= 90 ? '#fef2f2' : '#f8fafc'; ?>;border-radius:8px;">
					<div style="font-size:11px;color:#6b7280;text-transform:uppercase;"><?php esc_html_e( 'Limit', 'n8npress' ); ?></div>
					<?php if ( $limit > 0 ) : ?>
						<div style="font-size:22px;font-weight:700;color:<?php echo $limit_pct >= 90 ? '#dc2626' : '#111'; ?>;"><?php echo $limit_pct; ?>%</div>
						<div style="margin-top:4px;height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;">
							<div style="height:100%;width:<?php echo min( 100, $limit_pct ); ?>%;background:<?php echo $limit_pct >= 90 ? '#dc2626' : ( $limit_pct >= 70 ? '#f59e0b' : '#16a34a' ); ?>;"></div>
						</div>
					<?php else : ?>
						<div style="font-size:18px;color:#6b7280;">--</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php endif; ?>
	</div>

	<!-- Customer Intelligence -->
	<?php if ( $wc_active ) :
		$crm_detector = N8nPress_Plugin_Detector::get_instance();
		$crm_info     = $crm_detector->detect_crm();
		$crm_counts   = get_option( 'n8npress_crm_segment_counts', array() );
		$crm_refresh  = get_option( 'n8npress_crm_last_refresh', '' );
	?>
	<div class="n8npress-section">
		<h2>
			<span><span class="dashicons dashicons-groups"></span> <?php esc_html_e( 'Customer Intelligence', 'n8npress' ); ?></span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=n8npress-settings&tab=crm' ) ); ?>" class="n8npress-link-small"><?php esc_html_e( 'Settings', 'n8npress' ); ?> &rarr;</a>
		</h2>

		<?php if ( 'none' !== $crm_info['plugin'] ) : ?>
		<div class="n8npress-info-box" style="margin-bottom:16px;">
			<span class="dashicons dashicons-info-outline"></span>
			<?php printf(
				esc_html__( '%s detected — n8nPress adds AI customer insights on top.', 'n8npress' ),
				'<strong>' . esc_html( ucwords( str_replace( '-', ' ', $crm_info['plugin'] ) ) ) . '</strong>'
			); ?>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $crm_counts ) ) : ?>
		<div class="n8npress-stats-row" style="margin-bottom:12px;">
			<?php
			$segment_display = array(
				'vip'      => array( 'label' => 'VIP',      'icon' => 'star-filled',    'color' => '#6366f1' ),
				'loyal'    => array( 'label' => 'Loyal',     'icon' => 'heart',           'color' => '#16a34a' ),
				'active'   => array( 'label' => 'Active',    'icon' => 'businessman',     'color' => '#0ea5e9' ),
				'at_risk'  => array( 'label' => 'At Risk',   'icon' => 'warning',         'color' => '#eab308' ),
				'dormant'  => array( 'label' => 'Dormant',   'icon' => 'clock',           'color' => '#f97316' ),
				'lost'     => array( 'label' => 'Lost',      'icon' => 'dismiss',         'color' => '#dc2626' ),
			);
			foreach ( $segment_display as $seg_key => $seg_info ) :
				$seg_count = $crm_counts[ $seg_key ] ?? 0;
				if ( $seg_count === 0 && in_array( $seg_key, array( 'dormant', 'lost' ), true ) ) continue;
			?>
			<div class="n8npress-stat-card" style="border-left-color:<?php echo esc_attr( $seg_info['color'] ); ?>;">
				<div class="stat-icon"><span class="dashicons dashicons-<?php echo esc_attr( $seg_info['icon'] ); ?>" style="color:<?php echo esc_attr( $seg_info['color'] ); ?>;"></span></div>
				<div class="stat-content">
					<span class="stat-number"><?php echo esc_html( $seg_count ); ?></span>
					<span class="stat-label"><?php echo esc_html( $seg_info['label'] ); ?></span>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<p style="font-size:12px;color:#9ca3af;">
			<?php printf( esc_html__( 'Last refreshed: %s', 'n8npress' ), esc_html( $crm_refresh ?: 'Never' ) ); ?>
			&mdash;
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=n8npress-claw' ) ); ?>"><?php esc_html_e( 'Ask Open Claw for details', 'n8npress' ); ?></a>
		</p>
		<?php else : ?>
		<p style="color:#6b7280;">
			<?php esc_html_e( 'Customer segments will be computed on the next weekly cron run, or ask Open Claw: "customer segments".', 'n8npress' ); ?>
		</p>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- Footer -->
	<div class="n8npress-footer">
		<p>
			n8nPress v<?php echo esc_html( N8NPRESS_VERSION ); ?> &mdash;
			WordPress <?php echo esc_html( get_bloginfo( 'version' ) ); ?> &mdash;
			PHP <?php echo esc_html( phpversion() ); ?>
			<?php if ( $wc_active ) : ?>&mdash; WooCommerce <?php echo esc_html( WC_VERSION ); ?><?php endif; ?>
		</p>
	</div>
</div>
