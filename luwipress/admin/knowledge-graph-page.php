<?php
/**
 * LuwiPress Knowledge Graph Visualizer
 *
 * Interactive D3.js force-directed graph showing the complete
 * WordPress/WooCommerce store state: products, categories, languages,
 * SEO coverage, enrichment status, and AI opportunities.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
}

// JS + config injection handled by admin_enqueue_scripts ('luwipress-knowledge-graph' handle).
?>

<div class="wrap luwipress-kg-wrap">
	<!-- Header -->
	<div class="kg-header">
		<div class="kg-header-left">
			<h1 class="kg-title">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--lp-primary)" stroke-width="2"><circle cx="12" cy="12" r="3"/><circle cx="4" cy="8" r="2"/><circle cx="20" cy="8" r="2"/><circle cx="4" cy="16" r="2"/><circle cx="20" cy="16" r="2"/><line x1="6" y1="8" x2="9" y2="10"/><line x1="18" y1="8" x2="15" y2="10"/><line x1="6" y1="16" x2="9" y2="14"/><line x1="18" y1="16" x2="15" y2="14"/></svg>
				<?php esc_html_e( 'Knowledge Graph', 'luwipress' ); ?>
			</h1>
			<!-- Store Health pill — score + mini progress bar, click expands the
			     full dimension chips + achievements panel below the header.
			     Replaces the separate hero banner; saves ~40px of vertical space. -->
			<button type="button" class="kg-health-pill" id="kg-hero-toggle" aria-expanded="false" aria-controls="kg-hero-detail" title="<?php esc_attr_e( 'Store Health — click for breakdown', 'luwipress' ); ?>" hidden>
				<span class="kg-health-pill-score" id="kg-hero-score">—</span><span class="kg-health-pill-unit">%</span>
				<span class="kg-health-pill-bar"><span class="kg-health-pill-bar-fill" id="kg-hero-bar" style="width:0%"></span></span>
				<svg class="kg-health-pill-chevron" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
			</button>
			<span id="kg-cache-badge" class="kg-cache-badge" hidden></span>
		</div>
		<div class="kg-header-right">
			<div class="kg-controls">
				<div class="kg-search" role="search">
					<svg class="kg-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
					<input type="search" id="kg-search-input" class="kg-search-input" placeholder="<?php esc_attr_e( 'Search products, posts, categories... (press /)', 'luwipress' ); ?>" autocomplete="off" spellcheck="false" aria-label="<?php esc_attr_e( 'Search knowledge graph', 'luwipress' ); ?>">
					<button type="button" id="kg-search-clear" class="kg-search-clear" aria-label="<?php esc_attr_e( 'Clear search', 'luwipress' ); ?>" hidden>&times;</button>
					<div id="kg-search-results" class="kg-search-results" hidden></div>
				</div>
				<div class="kg-dropdown" id="kg-preset-dd">
					<button type="button" class="kg-btn kg-btn-outline" id="kg-preset-trigger" aria-haspopup="true">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M7 12h10M11 18h2"/></svg>
						<?php esc_html_e( 'Preset', 'luwipress' ); ?>
						<span class="kg-preset-label" id="kg-preset-label"><?php esc_html_e( 'All', 'luwipress' ); ?></span>
					</button>
					<div class="kg-dropdown-menu" id="kg-preset-menu" hidden>
						<button type="button" class="kg-dropdown-item" data-preset="all"><?php esc_html_e( 'All items', 'luwipress' ); ?></button>
						<button type="button" class="kg-dropdown-item" data-preset="needs_seo"><?php esc_html_e( 'Needs SEO meta', 'luwipress' ); ?></button>
						<button type="button" class="kg-dropdown-item" data-preset="not_enriched"><?php esc_html_e( 'Not enriched', 'luwipress' ); ?></button>
						<button type="button" class="kg-dropdown-item" data-preset="thin_content"><?php esc_html_e( 'Thin content', 'luwipress' ); ?></button>
						<button type="button" class="kg-dropdown-item" data-preset="translation_backlog"><?php esc_html_e( 'Translation backlog', 'luwipress' ); ?></button>
						<button type="button" class="kg-dropdown-item" data-preset="high_opportunity"><?php esc_html_e( 'High opportunity', 'luwipress' ); ?></button>
					</div>
				</div>
				<div class="kg-dropdown" id="kg-export-dd">
					<button type="button" class="kg-btn kg-btn-outline" id="kg-export-trigger" aria-haspopup="true">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
						<?php esc_html_e( 'Export', 'luwipress' ); ?>
					</button>
					<div class="kg-dropdown-menu" id="kg-export-menu" hidden>
						<button type="button" class="kg-dropdown-item" data-export="csv_opportunities"><?php esc_html_e( 'CSV — Opportunity list', 'luwipress' ); ?></button>
						<button type="button" class="kg-dropdown-item" data-export="csv_missing_seo"><?php esc_html_e( 'CSV — Missing SEO', 'luwipress' ); ?></button>
						<button type="button" class="kg-dropdown-item" data-export="json"><?php esc_html_e( 'JSON — Raw graph', 'luwipress' ); ?></button>
						<button type="button" class="kg-dropdown-item" data-export="png"><?php esc_html_e( 'PNG — Snapshot', 'luwipress' ); ?></button>
						<hr class="kg-dropdown-separator">
						<button type="button" class="kg-dropdown-item kg-dropdown-item-upload" data-export="upload_csv"><?php esc_html_e( 'Upload CSV → apply SEO meta…', 'luwipress' ); ?></button>
					</div>
					<input type="file" id="kg-csv-upload-input" accept=".csv,text/csv" hidden>
				</div>
				<button type="button" id="kg-refresh" class="kg-btn kg-btn-primary" title="<?php esc_attr_e( 'Force reload from database (bypasses cache)', 'luwipress' ); ?>">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
					<?php esc_html_e( 'Refresh', 'luwipress' ); ?>
				</button>
				<div class="kg-view-switch">
					<button type="button" class="kg-view-btn active" data-view="product">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
						<?php esc_html_e( 'Products', 'luwipress' ); ?>
					</button>
					<button type="button" class="kg-view-btn" data-view="post">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
						<?php esc_html_e( 'Posts', 'luwipress' ); ?>
					</button>
					<button type="button" class="kg-view-btn" data-view="page">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
						<?php esc_html_e( 'Pages', 'luwipress' ); ?>
					</button>
					<button type="button" class="kg-view-btn" data-view="customer">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
						<?php esc_html_e( 'Customers', 'luwipress' ); ?>
					</button>
				</div>
			</div>
		</div>
	</div>

	<!-- Store Health details — expansion panel, opens when the header pill is
	     clicked. Contains the qualitative subtitle, the per-dimension chips
	     (weakest first), and the achievement badges. Hidden at rest so the
	     graph is one click away from fullscreen focus. -->
	<div class="kg-hero-detail-wrap" id="kg-hero-detail" hidden>
		<p class="kg-hero-subtitle" id="kg-hero-subtitle"><?php esc_html_e( 'Weighted average across content, translation, design, and media metrics.', 'luwipress' ); ?></p>
		<div class="kg-hero-meta" id="kg-hero-meta"></div>
		<div class="kg-achievements" id="kg-achievements"></div>
	</div>

	<!-- Stats Bar (animated counters) -->
	<div class="kg-stats" id="kg-stats">
		<div class="kg-stat kg-stat-skeleton kg-stat-clickable" id="kg-stat-products" data-view="product" data-preset="all">
			<div class="kg-stat-value" data-counter="total_products">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'Products', 'luwipress' ); ?></div>
			<div class="kg-stat-cue"><?php esc_html_e( 'Show all →', 'luwipress' ); ?></div>
		</div>
		<div class="kg-stat kg-stat-skeleton kg-stat-clickable" id="kg-stat-posts" data-view="post" data-preset="all">
			<div class="kg-stat-value" data-counter="total_posts">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'Posts', 'luwipress' ); ?></div>
			<div class="kg-stat-cue"><?php esc_html_e( 'Show posts →', 'luwipress' ); ?></div>
		</div>
		<div class="kg-stat kg-stat-skeleton kg-stat-clickable" id="kg-stat-seo" data-view="product" data-preset="needs_seo">
			<div class="kg-stat-value" data-counter="seo_coverage">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'SEO Coverage', 'luwipress' ); ?></div>
			<div class="kg-stat-cue"><?php esc_html_e( 'Needs SEO →', 'luwipress' ); ?></div>
		</div>
		<div class="kg-stat kg-stat-skeleton kg-stat-clickable" id="kg-stat-enriched" data-view="product" data-preset="not_enriched">
			<div class="kg-stat-value" data-counter="enrichment_coverage">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'Enriched', 'luwipress' ); ?></div>
			<div class="kg-stat-cue"><?php esc_html_e( 'Needs enrich →', 'luwipress' ); ?></div>
		</div>
		<div class="kg-stat kg-stat-skeleton kg-stat-clickable" id="kg-stat-opportunities" data-view="product" data-preset="high_opportunity">
			<div class="kg-stat-value" data-counter="opportunity_score_total">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'Opportunities', 'luwipress' ); ?></div>
			<div class="kg-stat-cue"><?php esc_html_e( 'Top wins →', 'luwipress' ); ?></div>
		</div>
		<div class="kg-stat kg-stat-skeleton kg-stat-clickable" id="kg-stat-design">
			<div class="kg-stat-value" data-counter="design_health">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'Design Health', 'luwipress' ); ?></div>
			<div class="kg-stat-cue"><?php esc_html_e( 'View details →', 'luwipress' ); ?></div>
		</div>
		<div class="kg-stat kg-stat-skeleton kg-stat-clickable" id="kg-stat-plugins">
			<div class="kg-stat-value" data-counter="plugin_readiness">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'Plugin Health', 'luwipress' ); ?></div>
			<div class="kg-stat-cue"><?php esc_html_e( 'View details →', 'luwipress' ); ?></div>
		</div>
		<div class="kg-stat kg-stat-skeleton kg-stat-clickable" id="kg-stat-revenue">
			<div class="kg-stat-value" data-counter="revenue_30d">—</div>
			<div class="kg-stat-label"><?php esc_html_e( '30-Day Revenue', 'luwipress' ); ?></div>
			<div class="kg-stat-cue"><?php esc_html_e( 'View analytics →', 'luwipress' ); ?></div>
		</div>
		<div class="kg-stat kg-stat-skeleton kg-stat-clickable" id="kg-stat-taxonomy">
			<div class="kg-stat-value" data-counter="taxonomy_coverage">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'Taxonomy Coverage', 'luwipress' ); ?></div>
			<div class="kg-stat-cue"><?php esc_html_e( 'View heatmap →', 'luwipress' ); ?></div>
		</div>
		<div class="kg-stat kg-stat-skeleton kg-stat-clickable" id="kg-stat-media">
			<div class="kg-stat-value" data-counter="media_health">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'Media Health', 'luwipress' ); ?></div>
			<div class="kg-stat-cue"><?php esc_html_e( 'View library →', 'luwipress' ); ?></div>
		</div>
	</div>

	<!-- Graph Canvas -->
	<div class="kg-graph-container" id="kg-graph-container">
		<div class="kg-loading" id="kg-loading">
			<div class="kg-spinner"></div>
			<p><?php esc_html_e( 'Building knowledge graph...', 'luwipress' ); ?></p>
		</div>
		<svg id="kg-svg" role="img" aria-label="<?php esc_attr_e( 'Interactive knowledge graph of store content', 'luwipress' ); ?>"></svg>
		<!-- Tooltip -->
		<div class="kg-tooltip" id="kg-tooltip"></div>
		<!-- Zoom controls -->
		<div class="kg-zoom-controls">
			<button type="button" id="kg-zoom-in" class="kg-zoom-btn" title="<?php esc_attr_e( 'Zoom in', 'luwipress' ); ?>">+</button>
			<button type="button" id="kg-zoom-out" class="kg-zoom-btn" title="<?php esc_attr_e( 'Zoom out', 'luwipress' ); ?>">−</button>
			<button type="button" id="kg-zoom-fit" class="kg-zoom-btn" title="<?php esc_attr_e( 'Fit to screen', 'luwipress' ); ?>">⊡</button>
			<button type="button" id="kg-layout-reset" class="kg-zoom-btn" title="<?php esc_attr_e( 'Reset saved layout for this view', 'luwipress' ); ?>">↺</button>
		</div>
	</div>

	<!-- Action Queue — "Next 3 wins" suggestions. Sits BELOW the graph so the
	     operator explores the store first, then hits the recommended actions.
	     Ranked by impact ÷ effort. -->
	<div class="kg-action-queue" id="kg-action-queue" hidden>
		<div class="kg-action-queue-header">
			<span class="kg-action-queue-title"><?php esc_html_e( 'Next wins', 'luwipress' ); ?></span>
			<span class="kg-action-queue-sub"><?php esc_html_e( 'Ranked by impact ÷ effort — tackle from top', 'luwipress' ); ?></span>
		</div>
		<div class="kg-action-queue-list" id="kg-action-queue-list"></div>
	</div>

	<!-- Autopilot panel (Track D.3) — settings + manual run + recent dispatch log.
	     Default: disabled + dry-run. Operator opts in explicitly. -->
	<div class="kg-autopilot" id="kg-autopilot">
		<div class="kg-autopilot-header">
			<span class="kg-autopilot-title">
				<span class="kg-autopilot-icon" aria-hidden="true">⚙</span>
				<?php esc_html_e( 'AI Autopilot', 'luwipress' ); ?>
			</span>
			<span class="kg-autopilot-sub" id="kg-autopilot-state">—</span>
		</div>
		<div class="kg-autopilot-body" id="kg-autopilot-body">
			<div class="kg-autopilot-settings" id="kg-autopilot-settings">
				<div class="kg-autopilot-row">
					<label class="kg-autopilot-toggle">
						<input type="checkbox" id="kg-autopilot-enabled" />
						<span><?php esc_html_e( 'Enable autopilot', 'luwipress' ); ?></span>
					</label>
					<label class="kg-autopilot-toggle">
						<input type="checkbox" id="kg-autopilot-dry-run" checked />
						<span><?php esc_html_e( 'Dry-run only (logs, no real dispatch)', 'luwipress' ); ?></span>
					</label>
				</div>
				<div class="kg-autopilot-row">
					<label class="kg-autopilot-field">
						<span><?php esc_html_e( 'Min confidence', 'luwipress' ); ?></span>
						<input type="number" id="kg-autopilot-min-confidence" min="0" max="100" value="60" />
					</label>
					<label class="kg-autopilot-field">
						<span><?php esc_html_e( 'Window (hours)', 'luwipress' ); ?></span>
						<input type="number" id="kg-autopilot-window" min="1" max="720" value="24" />
					</label>
					<label class="kg-autopilot-field">
						<span><?php esc_html_e( 'Cap: enrich/day', 'luwipress' ); ?></span>
						<input type="number" id="kg-autopilot-cap-enrich" min="0" max="100" value="5" />
					</label>
					<label class="kg-autopilot-field">
						<span><?php esc_html_e( 'Cap: seo/day', 'luwipress' ); ?></span>
						<input type="number" id="kg-autopilot-cap-seo" min="0" max="100" value="5" />
					</label>
					<label class="kg-autopilot-field">
						<span><?php esc_html_e( 'Cap: translate/day', 'luwipress' ); ?></span>
						<input type="number" id="kg-autopilot-cap-translate" min="0" max="100" value="3" />
					</label>
				</div>
				<div class="kg-autopilot-actions">
					<button type="button" class="button button-secondary" id="kg-autopilot-save"><?php esc_html_e( 'Save', 'luwipress' ); ?></button>
					<button type="button" class="button" id="kg-autopilot-run-now"><?php esc_html_e( 'Run cycle now', 'luwipress' ); ?></button>
					<span class="kg-autopilot-msg" id="kg-autopilot-msg"></span>
				</div>
			</div>
			<div class="kg-autopilot-log-wrap">
				<h4 class="kg-autopilot-log-title"><?php esc_html_e( 'Recent dispatches', 'luwipress' ); ?></h4>
				<div class="kg-autopilot-log" id="kg-autopilot-log"><?php esc_html_e( 'Loading…', 'luwipress' ); ?></div>
			</div>
		</div>
	</div>

	<!-- Activity Feed — last N operator actions (enrichment, translation, AEO).
	     Polls /logs every 30s while visible to surface background job completions. -->
	<div class="kg-activity" id="kg-activity" hidden>
		<div class="kg-activity-header">
			<span class="kg-activity-title"><?php esc_html_e( 'Recent activity', 'luwipress' ); ?></span>
			<span class="kg-activity-sub" id="kg-activity-sub">—</span>
		</div>
		<div class="kg-activity-list" id="kg-activity-list"></div>
	</div>

	<!-- Detail Panel (slides in on node click) -->
	<div class="kg-detail-panel" id="kg-detail-panel">
		<button type="button" class="kg-detail-close" id="kg-detail-close">&times;</button>
		<div id="kg-detail-content"></div>
	</div>

	<!-- Batch Monitor (floating, appears when enrich-batch is running) -->
	<div class="kg-batch-monitor" id="kg-batch-monitor" hidden>
		<div class="kg-batch-monitor-header">
			<strong class="kg-batch-monitor-title"><?php esc_html_e( 'Enrichment Batch', 'luwipress' ); ?></strong>
			<button type="button" class="kg-batch-monitor-close" id="kg-batch-monitor-close" aria-label="<?php esc_attr_e( 'Dismiss', 'luwipress' ); ?>">&times;</button>
		</div>
		<div class="kg-batch-monitor-body">
			<div class="kg-batch-monitor-progress">
				<div class="kg-batch-monitor-bar" id="kg-batch-monitor-bar"></div>
			</div>
			<div class="kg-batch-monitor-stats" id="kg-batch-monitor-stats">—</div>
			<div class="kg-batch-monitor-detail" id="kg-batch-monitor-detail"></div>
		</div>
	</div>
</div>

<?php
// Knowledge Graph JS is enqueued via admin_enqueue_scripts (see luwipress.php).
// Config (apiUrl, apiToken, nonce, restBase) is injected via wp_localize_script as window.lpKgConfig.
