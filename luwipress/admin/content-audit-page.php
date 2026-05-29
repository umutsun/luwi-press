<?php
/**
 * LuwiPress Content Audit admin page
 *
 * Unified surface over three scanners that the Health Score brand-voice
 * and content-depth pillars already use under the hood:
 *
 *   1. Promotional Phrases — GMC-sensitive urgency/sale language across
 *      title, meta, excerpt, body. Backend: LuwiPress_Content_Audit
 *      (`/content/promotional-phrase-audit`, shipped 3.4.1).
 *
 *   2. AI-Tells — stock LLM phrasings ("In the world of...", "stands as
 *      one of the most...", "In conclusion,"). 2024+ Helpful Content
 *      Update flags these as machine-generated boilerplate. Backend:
 *      `/content/ai-tell-audit` (shipped 3.5.4).
 *
 *   3. Word Count — share of published content within the per-CPT band
 *      configured in Settings → AI Content. Backend: Health Score
 *      Content Depth pillar (`/health/score`).
 *
 * Strategic role: this page is the visible quick-win surface for non-
 * WebMCP customers. WebMCP users call the same backends via MCP tools;
 * this page makes those audits operator-discoverable through a
 * gamified KG-style UX.
 *
 * @package LuwiPress
 * @since   3.5.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
}

$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'promotional';
if ( ! in_array( $active_tab, array( 'promotional', 'ai-tell', 'word-count' ), true ) ) {
	$active_tab = 'promotional';
}

// REST base + nonce so the inline scanner JS can hit the endpoints
// authenticated as the current admin without exposing the API token.
$rest_base   = esc_url_raw( rest_url( 'luwipress/v1/' ) );
$rest_nonce  = wp_create_nonce( 'wp_rest' );

// Health Score snapshot — shown in the page hero so the operator sees
// the score numbers the audits below feed into.
//
// 3.5.6+: read the cached score only. compute() on every admin pageview
// could block for seconds on a cold cache (6-pillar scan over up to 1000
// posts). Hourly cron warmer (LuwiPress_Health_Score::cron_warm_cache)
// refills the transient out-of-band so the hero is virtually always
// pre-warmed. If we DO land on a cold cache, the hero falls back to "—"
// placeholders and the operator's first audit click below seeds it.
$brand_voice   = null;
$content_depth = null;
$hs_cold       = false;
if ( class_exists( 'LuwiPress_Health_Score' ) ) {
	$snap = get_transient( LuwiPress_Health_Score::CACHE_KEY );
	if ( is_array( $snap ) && ! empty( $snap['pillars'] ) ) {
		foreach ( $snap['pillars'] as $p ) {
			if ( ( $p['key'] ?? '' ) === 'brand_voice' )   { $brand_voice   = $p; }
			if ( ( $p['key'] ?? '' ) === 'content_depth' ) { $content_depth = $p; }
		}
	} else {
		$hs_cold = true;
	}
}

// Per-CPT word count band table, used by the "Word Count" tab.
$wc_targets = class_exists( 'LuwiPress_Health_Score' )
	? LuwiPress_Health_Score::get_word_count_targets()
	: array();

?>
<?php $luwipress_hub_mode = defined( 'LUWIPRESS_HUB_INCLUDED' ); ?>
<?php if ( ! $luwipress_hub_mode ) : ?>
<div class="wrap luwipress-content-audit">
<?php endif; ?>
	<?php if ( ! $luwipress_hub_mode ) : ?>
	<h1><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Content Audit', 'luwipress' ); ?></h1>
	<?php endif; ?>
	<p class="lp-page-intro">
		<?php esc_html_e( 'Catch promotional phrases that trigger GMC disapproval, stock LLM phrasings that 2024+ Helpful Content Update flags, and content that falls outside your per-type word count target. Each tab runs against your live content and surfaces 1-click fix paths.', 'luwipress' ); ?>
	</p>

	<!-- Hero score row — Brand Voice + Content Depth pillars, design-token accent. -->
	<?php
	$status_class = function ( $status ) {
		switch ( $status ) {
			case 'good': return 'lp-stat--success';
			case 'warn': return 'lp-stat--warning';
			case 'bad':  return 'lp-stat--error';
			default:     return 'lp-stat--muted';
		}
	};
	$value_class = function ( $status ) {
		switch ( $status ) {
			case 'good': return 'lp-stat-value--success';
			case 'warn': return 'lp-stat-value--warning';
			case 'bad':  return 'lp-stat-value--error';
			default:     return '';
		}
	};

	$render_pillar_card = function ( $p, $title, $hint ) use ( $status_class, $value_class ) {
		$val    = ( $p && isset( $p['value'] ) && $p['value'] !== null ) ? round( (float) $p['value'] ) : null;
		$status = $p['status'] ?? 'n_a';
		?>
		<div class="lp-stat <?php echo esc_attr( $status_class( $status ) ); ?> lwp-ca-pillar">
			<div class="lp-stat-label"><?php echo esc_html( $title ); ?></div>
			<div class="lp-stat-value <?php echo esc_attr( $value_class( $status ) ); ?> lwp-ca-pillar-value">
				<?php echo $val === null ? '—' : (int) $val; ?><span class="lwp-ca-pillar-unit">%</span>
			</div>
			<div class="lp-stat-caption"><?php echo esc_html( $hint ); ?></div>
		</div>
		<?php
	};
	?>
	<div class="lp-stat-row lwp-ca-pillars">
		<?php
		$render_pillar_card(
			$brand_voice,
			__( 'Brand Voice Compliance', 'luwipress' ),
			__( 'Share of recent content free of promotional + AI-tell patterns.', 'luwipress' )
		);
		$render_pillar_card(
			$content_depth,
			__( 'Content Depth', 'luwipress' ),
			__( 'Share of content within the per-CPT word count target band.', 'luwipress' )
		);
		?>
	</div>

	<?php if ( $hs_cold ) : ?>
		<div class="luwipress-card luwipress-card--warning lwp-hs-cold-notice">
			<p style="margin:0;">
				<span class="dashicons dashicons-clock" aria-hidden="true"></span>
				<?php esc_html_e( 'Health Score cache is cold — hero values will populate after the next hourly cron tick. Click below to recompute now.', 'luwipress' ); ?>
			</p>
			<div class="lp-btn-row" style="margin-top:10px;">
				<button type="button" class="lp-btn lp-btn--outline lp-btn--sm" id="lwp-hs-recompute">
					<span class="dashicons dashicons-update" aria-hidden="true"></span>
					<?php esc_html_e( 'Recompute now', 'luwipress' ); ?>
				</button>
				<span id="lwp-hs-recompute-status" class="lp-form-hint"></span>
			</div>
		</div>
		<script>
			(function () {
				var btn = document.getElementById('lwp-hs-recompute');
				if (!btn) return;
				btn.addEventListener('click', function () {
					var statusEl = document.getElementById('lwp-hs-recompute-status');
					btn.disabled = true;
					if (statusEl) statusEl.textContent = '⏳ <?php echo esc_js( __( 'Computing…', 'luwipress' ) ); ?>';
					fetch(<?php echo wp_json_encode( $rest_base . 'health/score?force=true' ); ?>, {
						headers: { 'X-WP-Nonce': <?php echo wp_json_encode( $rest_nonce ); ?>, 'Accept': 'application/json' },
						credentials: 'same-origin'
					})
						.then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
						.then(function () {
							if (statusEl) statusEl.textContent = '✓ <?php echo esc_js( __( 'Done — reloading…', 'luwipress' ) ); ?>';
							setTimeout(function () { window.location.reload(); }, 700);
						})
						.catch(function (err) {
							if (statusEl) { statusEl.textContent = '✗ ' + err.message; statusEl.style.color = '#c33'; }
							btn.disabled = false;
						});
				});
			})();
		</script>
	<?php endif; ?>

	<!-- Sub-tab nav — design-token strip identical to .lp-hub-tabs but
	     scoped to this page. URL preserved when rendered inside the
	     Content hub (page=luwipress-content&tab=audit&sub=...). -->
	<?php
	// Build the URL for each sub-tab, preserving the outer hub context
	// when this page is rendered inside `luwipress-content`.
	$ca_tab_url = function ( $sub ) use ( $luwipress_hub_mode ) {
		if ( $luwipress_hub_mode ) {
			return add_query_arg(
				array( 'page' => 'luwipress-content', 'tab' => 'audit', 'sub' => $sub ),
				admin_url( 'admin.php' )
			);
		}
		return add_query_arg(
			array( 'page' => 'luwipress-content-audit', 'tab' => $sub ),
			admin_url( 'admin.php' )
		);
	};
	?>
	<nav class="lp-hub-tabs lwp-ca-subtabs" role="tablist" aria-label="<?php esc_attr_e( 'Content audit sub-sections', 'luwipress' ); ?>">
		<a href="<?php echo esc_url( $ca_tab_url( 'promotional' ) ); ?>"
		   class="lp-hub-tab <?php echo 'promotional' === $active_tab ? 'lp-hub-tab--active' : ''; ?>"
		   role="tab"
		   aria-selected="<?php echo 'promotional' === $active_tab ? 'true' : 'false'; ?>">
			<span class="dashicons dashicons-warning"></span>
			<span><?php esc_html_e( 'Promotional Phrases', 'luwipress' ); ?></span>
		</a>
		<a href="<?php echo esc_url( $ca_tab_url( 'ai-tell' ) ); ?>"
		   class="lp-hub-tab <?php echo 'ai-tell' === $active_tab ? 'lp-hub-tab--active' : ''; ?>"
		   role="tab"
		   aria-selected="<?php echo 'ai-tell' === $active_tab ? 'true' : 'false'; ?>">
			<span class="dashicons dashicons-format-quote"></span>
			<span><?php esc_html_e( 'AI-Tells', 'luwipress' ); ?></span>
		</a>
		<a href="<?php echo esc_url( $ca_tab_url( 'word-count' ) ); ?>"
		   class="lp-hub-tab <?php echo 'word-count' === $active_tab ? 'lp-hub-tab--active' : ''; ?>"
		   role="tab"
		   aria-selected="<?php echo 'word-count' === $active_tab ? 'true' : 'false'; ?>">
			<span class="dashicons dashicons-text"></span>
			<span><?php esc_html_e( 'Word Count', 'luwipress' ); ?></span>
		</a>
	</nav>

	<!-- PROMOTIONAL PHRASES TAB -->
	<div class="luwipress-tab-content lwp-ca-tab-body <?php echo 'promotional' === $active_tab ? 'tab-active' : ''; ?>"
	     id="tab-promotional" <?php if ( 'promotional' !== $active_tab ) echo 'hidden'; ?>>
		<div class="luwipress-card luwipress-card--warning">
			<h2><?php esc_html_e( 'Promotional Phrase Audit', 'luwipress' ); ?></h2>
			<p class="lp-form-hint">
				<?php esc_html_e( 'Scans for GMC-prohibited urgency / sale-pressure phrases ("free shipping", "best price", "limited time", "today only", etc.) across product title, meta title, meta description, excerpt and body. High-severity findings in meta will trigger Google Merchant Center disapproval; medium in title; low in body (flagged for editorial sweep).', 'luwipress' ); ?>
			</p>

			<form class="lwp-audit-form lwp-ca-form" data-audit-kind="promotional">
				<div class="lp-form-row lwp-ca-form-field">
					<label class="lp-form-label" for="lwp-ca-promo-type"><?php esc_html_e( 'Post type', 'luwipress' ); ?></label>
					<select class="lp-form-select" id="lwp-ca-promo-type" name="post_type">
						<option value="product" selected>product</option>
						<option value="post">post</option>
						<option value="page">page</option>
					</select>
				</div>
				<div class="lp-form-row lwp-ca-form-field">
					<label class="lp-form-label" for="lwp-ca-promo-scope"><?php esc_html_e( 'Scope', 'luwipress' ); ?></label>
					<select class="lp-form-select" id="lwp-ca-promo-scope" name="scope">
						<option value="all"><?php esc_html_e( 'All (meta + body)', 'luwipress' ); ?></option>
						<option value="meta"><?php esc_html_e( 'Meta only', 'luwipress' ); ?></option>
						<option value="body"><?php esc_html_e( 'Body only', 'luwipress' ); ?></option>
					</select>
				</div>
				<div class="lp-form-row lwp-ca-form-field">
					<label class="lp-form-label" for="lwp-ca-promo-limit"><?php esc_html_e( 'Limit', 'luwipress' ); ?></label>
					<input class="lp-form-input" id="lwp-ca-promo-limit" type="number" name="limit" value="100" min="1" max="500" />
				</div>
				<div class="lp-form-row lwp-ca-form-submit">
					<button type="submit" class="lp-btn lp-btn--primary">
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
						<?php esc_html_e( 'Run scan', 'luwipress' ); ?>
					</button>
				</div>
			</form>

			<div class="lwp-audit-status lwp-ca-status" data-kind="promotional"></div>
			<div class="lwp-audit-results lwp-ca-results" data-kind="promotional"></div>
		</div>
	</div>

	<!-- AI-TELLS TAB -->
	<div class="luwipress-tab-content lwp-ca-tab-body <?php echo 'ai-tell' === $active_tab ? 'tab-active' : ''; ?>"
	     id="tab-ai-tell" <?php if ( 'ai-tell' !== $active_tab ) echo 'hidden'; ?>>
		<div class="luwipress-card luwipress-card--info">
			<h2><?php esc_html_e( 'AI-Tell Scanner', 'luwipress' ); ?></h2>
			<p class="lp-form-hint">
				<?php esc_html_e( 'Surfaces stock LLM phrasings — "In the world of...", "stands as one of the most...", "In conclusion,", "Unleash your creativity" — that 2024+ Helpful Content Update flags as machine-generated boilerplate. All findings are editorial soft-flags; rewrite by hand for proprietary voice, or rerun your enrichment workflow with a tighter prompt.', 'luwipress' ); ?>
			</p>

			<form class="lwp-audit-form lwp-ca-form" data-audit-kind="ai-tell">
				<div class="lp-form-row lwp-ca-form-field">
					<label class="lp-form-label" for="lwp-ca-ait-type"><?php esc_html_e( 'Post type', 'luwipress' ); ?></label>
					<select class="lp-form-select" id="lwp-ca-ait-type" name="post_type">
						<option value="post" selected>post</option>
						<option value="product">product</option>
						<option value="page">page</option>
					</select>
				</div>
				<div class="lp-form-row lwp-ca-form-field">
					<label class="lp-form-label" for="lwp-ca-ait-scope"><?php esc_html_e( 'Scope', 'luwipress' ); ?></label>
					<select class="lp-form-select" id="lwp-ca-ait-scope" name="scope">
						<option value="all"><?php esc_html_e( 'All (meta + body)', 'luwipress' ); ?></option>
						<option value="meta"><?php esc_html_e( 'Meta only', 'luwipress' ); ?></option>
						<option value="body"><?php esc_html_e( 'Body only', 'luwipress' ); ?></option>
					</select>
				</div>
				<div class="lp-form-row lwp-ca-form-field">
					<label class="lp-form-label" for="lwp-ca-ait-limit"><?php esc_html_e( 'Limit', 'luwipress' ); ?></label>
					<input class="lp-form-input" id="lwp-ca-ait-limit" type="number" name="limit" value="100" min="1" max="500" />
				</div>
				<div class="lp-form-row lwp-ca-form-submit">
					<button type="submit" class="lp-btn lp-btn--primary">
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
						<?php esc_html_e( 'Run scan', 'luwipress' ); ?>
					</button>
				</div>
			</form>

			<div class="lwp-audit-status lwp-ca-status" data-kind="ai-tell"></div>
			<div class="lwp-audit-results lwp-ca-results" data-kind="ai-tell"></div>
		</div>
	</div>

	<!-- WORD COUNT TAB -->
	<div class="luwipress-tab-content lwp-ca-tab-body <?php echo 'word-count' === $active_tab ? 'tab-active' : ''; ?>"
	     id="tab-word-count" <?php if ( 'word-count' !== $active_tab ) echo 'hidden'; ?>>
		<div class="luwipress-card luwipress-card--primary">
			<h2><?php esc_html_e( 'Word Count Audit', 'luwipress' ); ?></h2>
			<p class="lp-form-hint">
				<?php esc_html_e( 'Per-CPT word count compliance — share of published content within the [min, max] target band configured in Settings → AI Content. Below min = thin (action priority), above max = bloat (informational).', 'luwipress' ); ?>
			</p>

			<?php if ( $content_depth && ! empty( $content_depth['details']['per_cpt'] ) ) :
				$per_cpt = $content_depth['details']['per_cpt'];
			?>
			<table class="widefat striped lwp-ca-wc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Content type', 'luwipress' ); ?></th>
						<th class="lwp-ca-num"><?php esc_html_e( 'Total', 'luwipress' ); ?></th>
						<th class="lwp-ca-num lwp-ca-h--success"><?php esc_html_e( 'On band', 'luwipress' ); ?></th>
						<th class="lwp-ca-num lwp-ca-h--error"><?php esc_html_e( 'Thin', 'luwipress' ); ?></th>
						<th class="lwp-ca-num lwp-ca-h--warning"><?php esc_html_e( 'Bloat', 'luwipress' ); ?></th>
						<th class="lwp-ca-num"><?php esc_html_e( '% Band', 'luwipress' ); ?></th>
						<th class="lwp-ca-band"><?php esc_html_e( 'Target band', 'luwipress' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $wc_targets as $cpt => $band ) :
						$row = $per_cpt[ $cpt ] ?? null;
						if ( ! $row || ! empty( $row['skipped'] ) ) {
							continue;
						}
						$share       = isset( $row['pct_band'] ) ? (float) $row['pct_band'] : 0;
						$share_class = $share >= 85 ? 'lwp-ca-c--success' : ( $share >= 70 ? 'lwp-ca-c--warning' : 'lwp-ca-c--error' );
						$thin_strong = ! empty( $row['thin'] ) ? ' lwp-ca-strong' : '';
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $band['label'] ); ?></strong>
							<br><code class="lwp-ca-cpt-code"><?php echo esc_html( $cpt ); ?></code>
						</td>
						<td class="lwp-ca-num"><?php echo (int) ( $row['total'] ?? 0 ); ?></td>
						<td class="lwp-ca-num lwp-ca-c--success"><?php echo (int) ( $row['on_band'] ?? 0 ); ?></td>
						<td class="lwp-ca-num lwp-ca-c--error<?php echo esc_attr( $thin_strong ); ?>"><?php echo (int) ( $row['thin'] ?? 0 ); ?></td>
						<td class="lwp-ca-num lwp-ca-c--warning"><?php echo (int) ( $row['bloat'] ?? 0 ); ?></td>
						<td class="lwp-ca-num lwp-ca-strong <?php echo esc_attr( $share_class ); ?>">
							<?php echo number_format( $share, 1 ); ?>%
						</td>
						<td class="lwp-ca-band">
							<?php echo (int) $band['min']; ?> – <strong><?php echo (int) $band['target']; ?></strong> – <?php echo (int) $band['max']; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			// "Thin content" drilldown — list specific posts that need attention
			// per CPT. Capped at 50 per CPT by the measurement function so we
			// don't blow the DOM on huge catalogues.
			$any_thin = false;
			foreach ( $per_cpt as $row ) {
				if ( ! empty( $row['thin_ids'] ) ) { $any_thin = true; break; }
			}
			if ( $any_thin ) :
			?>
				<h3 class="lp-section-title lwp-ca-section-title"><?php esc_html_e( 'Thin content — action queue', 'luwipress' ); ?></h3>
				<p class="lp-form-hint"><?php esc_html_e( 'Posts below the min word count for their type. Highest-priority cleanup target (Helpful Content Update penalises thin content site-wide).', 'luwipress' ); ?></p>
				<?php foreach ( $per_cpt as $cpt => $row ) :
					if ( empty( $row['thin_ids'] ) ) {
						continue;
					}
					$band = $row['band'] ?? array( 'min' => 0, 'label' => $cpt );
				?>
				<details class="lwp-ca-thin-group">
					<summary>
						<?php echo esc_html( $band['label'] ); ?>
						<span class="lwp-ca-c--error">— <?php echo (int) count( $row['thin_ids'] ); ?> <?php esc_html_e( 'posts under', 'luwipress' ); ?> <?php echo (int) $band['min']; ?> <?php esc_html_e( 'words', 'luwipress' ); ?></span>
					</summary>
					<table class="widefat striped lwp-ca-thin-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Title', 'luwipress' ); ?></th>
								<th class="lwp-ca-num"><?php esc_html_e( 'Words', 'luwipress' ); ?></th>
								<th class="lwp-ca-edit"><?php esc_html_e( 'Edit', 'luwipress' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $row['thin_ids'] as $entry ) : ?>
								<tr>
									<td><?php echo esc_html( $entry['title'] ); ?></td>
									<td class="lwp-ca-num lwp-ca-strong lwp-ca-c--error"><?php echo (int) $entry['word_count']; ?></td>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $entry['post_id'] ) ); ?>" class="lp-btn lp-btn--ghost lp-btn--sm">
											<span class="dashicons dashicons-edit" aria-hidden="true"></span>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php else : ?>
				<p class="lp-form-hint"><em><?php esc_html_e( 'Health Score not loaded or no content measured yet. Reload after publishing some content.', 'luwipress' ); ?></em></p>
			<?php endif; ?>

			<div class="lp-btn-row lwp-ca-settings-link">
				<a class="lp-btn lp-btn--outline" href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-settings&tab=ai' ) ); ?>">
					<span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
					<?php esc_html_e( 'Edit word count targets', 'luwipress' ); ?>
				</a>
			</div>
		</div>
	</div>
<?php if ( ! $luwipress_hub_mode ) : ?>
</div>
<?php endif; ?>

<style>
/* Page-local layout helpers — colour + typography all flow through
   `--lp-*` tokens so dark mode + brand theming stay consistent. */
.lwp-ca-pillars { margin: 0 0 16px; }
.lwp-ca-pillar-value { font-size: 36px; }
.lwp-ca-pillar-unit  { font-size: 14px; color: var(--lp-text-secondary); font-weight: 400; }

.lwp-hs-cold-notice { margin: 0 0 20px; }
.lwp-hs-cold-notice .dashicons { color: var(--lp-warning); }

.lwp-ca-subtabs { margin-top: 16px; margin-bottom: 18px; }

.lwp-ca-tab-body { margin-top: 0; }

/* Form row inside an audit card — flex layout with labels on top,
   submit aligned to bottom. */
.lwp-ca-form {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	align-items: flex-end;
	margin: 14px 0 0;
}
.lwp-ca-form-field { min-width: 180px; margin: 0; }
.lwp-ca-form-submit { margin: 0; align-self: flex-end; }
.lwp-ca-status,
.lwp-ca-results { margin-top: 12px; font-size: 13px; color: var(--lp-text-secondary); }

/* Word-count audit table — numeric columns right-aligned, semantic
   header tints + body cell tints all via tokens. */
.lwp-ca-wc-table { margin-top: 12px; }
.lwp-ca-wc-table .lwp-ca-num   { text-align: right; width: 100px; }
.lwp-ca-wc-table .lwp-ca-band  { width: 160px; font-size: 12px; color: var(--lp-text-secondary); }
.lwp-ca-h--success { color: var(--lp-success); }
.lwp-ca-h--warning { color: var(--lp-warning); }
.lwp-ca-h--error   { color: var(--lp-error); }
.lwp-ca-c--success { color: var(--lp-success); }
.lwp-ca-c--warning { color: var(--lp-warning); }
.lwp-ca-c--error   { color: var(--lp-error); }
.lwp-ca-strong     { font-weight: 600; }
.lwp-ca-cpt-code   { font-size: 11px; color: var(--lp-text-secondary); }

/* Thin-content drilldown */
.lwp-ca-section-title { margin-top: 24px; }
.lwp-ca-thin-group {
	margin-top: 8px;
	background: var(--lp-surface);
	border: 1px solid var(--lp-border);
	border-radius: 6px;
	padding: 10px 14px;
}
.lwp-ca-thin-group > summary {
	cursor: pointer;
	font-weight: 600;
	color: var(--lp-text);
}
.lwp-ca-thin-table { margin-top: 8px; }
.lwp-ca-thin-table .lwp-ca-num  { width: 120px; }
.lwp-ca-thin-table .lwp-ca-edit { width: 60px; }

.lwp-ca-settings-link { margin-top: 16px; }
</style>

<script>
(function () {
	'use strict';
	var REST_BASE  = <?php echo wp_json_encode( $rest_base ); ?>;
	var REST_NONCE = <?php echo wp_json_encode( $rest_nonce ); ?>;

	// Endpoint map per audit kind.
	var ENDPOINTS = {
		'promotional': 'content/promotional-phrase-audit',
		'ai-tell':     'content/ai-tell-audit'
	};

	// Severity badge palette — matches the table colours upstream.
	var SEVERITY_COLOR = {
		'high':   '#c33',
		'medium': '#a86b00',
		'low':    '#3b82f6'
	};

	function el(tag, attrs, children) {
		var n = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				if (k === 'html')      { n.innerHTML = attrs[k]; }
				else if (k === 'text') { n.textContent = attrs[k]; }
				else if (k === 'css')  { n.setAttribute('style', attrs[k]); }
				else                   { n.setAttribute(k, attrs[k]); }
			});
		}
		(children || []).forEach(function (c) {
			n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return n;
	}

	function setStatus(kind, msg, isError) {
		var el = document.querySelector('.lwp-audit-status[data-kind="' + kind + '"]');
		if (!el) return;
		el.textContent = msg;
		el.style.color = isError ? '#c33' : '#666';
	}

	function renderResults(kind, data) {
		var wrap = document.querySelector('.lwp-audit-results[data-kind="' + kind + '"]');
		if (!wrap) return;
		wrap.innerHTML = '';

		var scanned   = data.scanned        || 0;
		var withV     = data.with_violations || 0;
		var findings  = data.total_findings  || 0;
		var clean     = scanned - withV;

		var summary = el('div', { css: 'display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px;font-size:13px;' }, [
			el('span', null, ['Scanned: ' + scanned]),
			el('span', { css: 'color:#2c7a2c;' }, ['Clean: ' + clean]),
			el('span', { css: 'color:#c33;font-weight:600;' }, ['Violators: ' + withV]),
			el('span', { css: 'color:#666;' }, ['Total findings: ' + findings])
		]);
		wrap.appendChild(summary);

		if (!withV) {
			wrap.appendChild(el('p', { css: 'color:#2c7a2c;font-weight:600;' }, ['No violations found in scanned posts.']));
			return;
		}

		var results = data.results || [];
		results.forEach(function (post) {
			if (!post.finding_count) return;

			var card = el('details', { css: 'background:#fff;border:1px solid #ddd;border-radius:6px;padding:8px 12px;margin-bottom:8px;' });
			var summary = el('summary', { css: 'cursor:pointer;' });
			summary.appendChild(el('strong', null, [post.post_title || '(untitled)']));
			summary.appendChild(document.createTextNode(' '));
			summary.appendChild(el('span', { css: 'color:#666;font-size:12px;' }, ['(' + post.post_type + ' #' + post.post_id + ', ' + (post.lang || 'unknown') + ')']));
			summary.appendChild(document.createTextNode(' — '));
			summary.appendChild(el('span', { css: 'color:#c33;font-weight:600;' }, [post.finding_count + ' findings']));
			card.appendChild(summary);

			var list = el('ul', { css: 'margin:8px 0 0 0;padding-left:18px;' });
			(post.findings || []).forEach(function (f) {
				var li = el('li', { css: 'margin-bottom:6px;' });
				li.appendChild(el('span', {
					css: 'display:inline-block;padding:1px 6px;border-radius:3px;font-size:11px;text-transform:uppercase;background:' + (SEVERITY_COLOR[f.severity] || '#666') + ';color:#fff;margin-right:6px;'
				}, [f.severity || 'medium']));
				li.appendChild(el('code', { css: 'background:#fff3cd;padding:0 4px;' }, [f.phrase]));
				li.appendChild(document.createTextNode(' in ' + f.field));
				if (f.context) {
					li.appendChild(el('br'));
					li.appendChild(el('span', { css: 'color:#666;font-size:12px;font-style:italic;' }, ['…' + f.context + '…']));
				}
				list.appendChild(li);
			});
			card.appendChild(list);

			var actions = el('p', { css: 'margin:8px 0 0 0;' });
			var permalink = post.permalink || '';
			if (permalink) {
				actions.appendChild(el('a', { href: permalink, target: '_blank', rel: 'noopener', class: 'button button-small' }, ['View live']));
				actions.appendChild(document.createTextNode(' '));
			}
			// Edit link via REST-less helper — server has the canonical URL.
			actions.appendChild(el('a', {
				href: '<?php echo esc_url_raw( admin_url( 'post.php?action=edit&post=' ) ); ?>' + post.post_id,
				class: 'button button-small button-primary'
			}, ['Edit post']));
			card.appendChild(actions);

			wrap.appendChild(card);
		});
	}

	function runAudit(form) {
		var kind = form.getAttribute('data-audit-kind');
		var endpoint = ENDPOINTS[kind];
		if (!endpoint) return;

		var params = {
			post_type:       form.elements.post_type.value,
			scope:           form.elements.scope.value,
			limit:           parseInt(form.elements.limit.value, 10) || 100,
			only_violations: true
		};

		setStatus(kind, 'Scanning…', false);

		var url = REST_BASE + endpoint + '?' + Object.keys(params).map(function (k) {
			return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
		}).join('&');

		fetch(url, {
			headers: { 'X-WP-Nonce': REST_NONCE, 'Accept': 'application/json' },
			credentials: 'same-origin'
		})
			.then(function (r) {
				if (!r.ok) { throw new Error('HTTP ' + r.status); }
				return r.json();
			})
			.then(function (data) {
				setStatus(kind, 'Done — scanned ' + (data.scanned || 0) + ' posts.', false);
				renderResults(kind, data);
			})
			.catch(function (err) {
				setStatus(kind, 'Scan failed: ' + err.message, true);
			});
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.lwp-audit-form').forEach(function (form) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				runAudit(form);
			});
		});
	});
})();
</script>
