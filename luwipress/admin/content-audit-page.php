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
// the score numbers the audits below feed into. Computed (cached) once.
$brand_voice   = null;
$content_depth = null;
if ( class_exists( 'LuwiPress_Health_Score' ) ) {
	$snap = LuwiPress_Health_Score::get_instance()->compute();
	foreach ( $snap['pillars'] ?? array() as $p ) {
		if ( ( $p['key'] ?? '' ) === 'brand_voice' )   { $brand_voice   = $p; }
		if ( ( $p['key'] ?? '' ) === 'content_depth' ) { $content_depth = $p; }
	}
}

// Per-CPT word count band table, used by the "Word Count" tab.
$wc_targets = class_exists( 'LuwiPress_Health_Score' )
	? LuwiPress_Health_Score::get_word_count_targets()
	: array();

?>
<div class="wrap luwipress-content-audit">
	<h1><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Content Audit', 'luwipress' ); ?></h1>
	<p class="description" style="max-width:780px;">
		<?php esc_html_e( 'Catch promotional phrases that trigger GMC disapproval, stock LLM phrasings that 2024+ Helpful Content Update flags, and content that falls outside your per-type word count target. Each tab runs against your live content and surfaces 1-click fix paths.', 'luwipress' ); ?>
	</p>

	<!-- Hero score row — what changes if you fix the findings below -->
	<div class="luwipress-card" style="display:flex;gap:24px;flex-wrap:wrap;align-items:stretch;">
		<?php
		$band_color = function( $status ) {
			switch ( $status ) {
				case 'good': return '#2c7a2c';
				case 'warn': return '#a86b00';
				case 'bad':  return '#c33';
				default:     return '#999';
			}
		};

		$render_pillar_card = function( $p, $title, $hint ) use ( $band_color ) {
			$val   = ( $p && isset( $p['value'] ) && $p['value'] !== null ) ? round( (float) $p['value'] ) : null;
			$color = $band_color( $p['status'] ?? 'n_a' );
			?>
			<div style="flex:1 1 280px;padding:12px 14px;border:1px solid #e0e0e0;border-radius:8px;background:#fff;">
				<div style="font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#666;">
					<?php echo esc_html( $title ); ?>
				</div>
				<div style="font-size:36px;font-weight:700;line-height:1.1;color:<?php echo esc_attr( $color ); ?>;margin-top:4px;">
					<?php echo $val === null ? '—' : (int) $val; ?><span style="font-size:14px;color:#999;font-weight:400;">%</span>
				</div>
				<div style="font-size:12px;color:#666;margin-top:6px;line-height:1.4;">
					<?php echo esc_html( $hint ); ?>
				</div>
			</div>
			<?php
		};
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

	<!-- Tab nav -->
	<nav class="nav-tab-wrapper luwipress-tabs" style="margin-top:12px;">
		<a href="?page=luwipress-content-audit&tab=promotional" class="nav-tab <?php echo 'promotional' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Promotional Phrases', 'luwipress' ); ?>
		</a>
		<a href="?page=luwipress-content-audit&tab=ai-tell" class="nav-tab <?php echo 'ai-tell' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-format-quote"></span> <?php esc_html_e( 'AI-Tells', 'luwipress' ); ?>
		</a>
		<a href="?page=luwipress-content-audit&tab=word-count" class="nav-tab <?php echo 'word-count' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-text"></span> <?php esc_html_e( 'Word Count', 'luwipress' ); ?>
		</a>
	</nav>

	<!-- PROMOTIONAL PHRASES TAB -->
	<div class="luwipress-tab-content <?php echo 'promotional' === $active_tab ? 'tab-active' : ''; ?>" id="tab-promotional" <?php if ( 'promotional' !== $active_tab ) echo 'hidden'; ?>>
		<div class="luwipress-card">
			<h2><?php esc_html_e( 'Promotional Phrase Audit', 'luwipress' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Scans for GMC-prohibited urgency / sale-pressure phrases ("free shipping", "best price", "limited time", "today only", etc.) across product title, meta title, meta description, excerpt and body. High-severity findings in meta will trigger Google Merchant Center disapproval; medium in title; low in body (flagged for editorial sweep).', 'luwipress' ); ?>
			</p>

			<form class="lwp-audit-form" data-audit-kind="promotional">
				<label><?php esc_html_e( 'Post type', 'luwipress' ); ?>
					<select name="post_type">
						<option value="product"<?php selected( 'product', 'product' ); ?>>product</option>
						<option value="post">post</option>
						<option value="page">page</option>
					</select>
				</label>
				<label><?php esc_html_e( 'Scope', 'luwipress' ); ?>
					<select name="scope">
						<option value="all"><?php esc_html_e( 'All (meta + body)', 'luwipress' ); ?></option>
						<option value="meta"><?php esc_html_e( 'Meta only', 'luwipress' ); ?></option>
						<option value="body"><?php esc_html_e( 'Body only', 'luwipress' ); ?></option>
					</select>
				</label>
				<label><?php esc_html_e( 'Limit', 'luwipress' ); ?>
					<input type="number" name="limit" value="100" min="1" max="500" />
				</label>
				<button type="submit" class="button button-primary"><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Run scan', 'luwipress' ); ?></button>
			</form>

			<div class="lwp-audit-status" data-kind="promotional" style="margin-top:12px;color:#666;font-size:13px;"></div>
			<div class="lwp-audit-results" data-kind="promotional" style="margin-top:12px;"></div>
		</div>
	</div>

	<!-- AI-TELLS TAB -->
	<div class="luwipress-tab-content <?php echo 'ai-tell' === $active_tab ? 'tab-active' : ''; ?>" id="tab-ai-tell" <?php if ( 'ai-tell' !== $active_tab ) echo 'hidden'; ?>>
		<div class="luwipress-card">
			<h2><?php esc_html_e( 'AI-Tell Scanner', 'luwipress' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Surfaces stock LLM phrasings — "In the world of...", "stands as one of the most...", "In conclusion,", "Unleash your creativity" — that 2024+ Helpful Content Update flags as machine-generated boilerplate. All findings are editorial soft-flags; rewrite by hand for proprietary voice, or rerun your enrichment workflow with a tighter prompt.', 'luwipress' ); ?>
			</p>

			<form class="lwp-audit-form" data-audit-kind="ai-tell">
				<label><?php esc_html_e( 'Post type', 'luwipress' ); ?>
					<select name="post_type">
						<option value="post" selected>post</option>
						<option value="product">product</option>
						<option value="page">page</option>
					</select>
				</label>
				<label><?php esc_html_e( 'Scope', 'luwipress' ); ?>
					<select name="scope">
						<option value="all"><?php esc_html_e( 'All (meta + body)', 'luwipress' ); ?></option>
						<option value="meta"><?php esc_html_e( 'Meta only', 'luwipress' ); ?></option>
						<option value="body"><?php esc_html_e( 'Body only', 'luwipress' ); ?></option>
					</select>
				</label>
				<label><?php esc_html_e( 'Limit', 'luwipress' ); ?>
					<input type="number" name="limit" value="100" min="1" max="500" />
				</label>
				<button type="submit" class="button button-primary"><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Run scan', 'luwipress' ); ?></button>
			</form>

			<div class="lwp-audit-status" data-kind="ai-tell" style="margin-top:12px;color:#666;font-size:13px;"></div>
			<div class="lwp-audit-results" data-kind="ai-tell" style="margin-top:12px;"></div>
		</div>
	</div>

	<!-- WORD COUNT TAB -->
	<div class="luwipress-tab-content <?php echo 'word-count' === $active_tab ? 'tab-active' : ''; ?>" id="tab-word-count" <?php if ( 'word-count' !== $active_tab ) echo 'hidden'; ?>>
		<div class="luwipress-card">
			<h2><?php esc_html_e( 'Word Count Audit', 'luwipress' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Per-CPT word count compliance — share of published content within the [min, max] target band configured in Settings → AI Content. Below min = thin (action priority), above max = bloat (informational).', 'luwipress' ); ?>
			</p>

			<?php if ( $content_depth && ! empty( $content_depth['details']['per_cpt'] ) ) :
				$per_cpt = $content_depth['details']['per_cpt'];
			?>
			<table class="widefat striped" style="margin-top:12px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Content type', 'luwipress' ); ?></th>
						<th style="text-align:right;width:100px;"><?php esc_html_e( 'Total', 'luwipress' ); ?></th>
						<th style="text-align:right;width:100px;color:#2c7a2c;"><?php esc_html_e( 'On band', 'luwipress' ); ?></th>
						<th style="text-align:right;width:100px;color:#c33;"><?php esc_html_e( 'Thin', 'luwipress' ); ?></th>
						<th style="text-align:right;width:100px;color:#a86b00;"><?php esc_html_e( 'Bloat', 'luwipress' ); ?></th>
						<th style="text-align:right;width:100px;"><?php esc_html_e( '% Band', 'luwipress' ); ?></th>
						<th style="width:160px;"><?php esc_html_e( 'Target band', 'luwipress' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $wc_targets as $cpt => $band ) :
						$row = $per_cpt[ $cpt ] ?? null;
						if ( ! $row || ! empty( $row['skipped'] ) ) {
							continue;
						}
						$share = isset( $row['pct_band'] ) ? (float) $row['pct_band'] : 0;
						$share_color = $share >= 85 ? '#2c7a2c' : ( $share >= 70 ? '#a86b00' : '#c33' );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $band['label'] ); ?></strong>
							<br><code style="font-size:11px;color:#666;"><?php echo esc_html( $cpt ); ?></code>
						</td>
						<td style="text-align:right;"><?php echo (int) ( $row['total'] ?? 0 ); ?></td>
						<td style="text-align:right;color:#2c7a2c;"><?php echo (int) ( $row['on_band'] ?? 0 ); ?></td>
						<td style="text-align:right;color:#c33;font-weight:<?php echo ! empty( $row['thin'] ) ? '600' : '400'; ?>;"><?php echo (int) ( $row['thin'] ?? 0 ); ?></td>
						<td style="text-align:right;color:#a86b00;"><?php echo (int) ( $row['bloat'] ?? 0 ); ?></td>
						<td style="text-align:right;font-weight:600;color:<?php echo esc_attr( $share_color ); ?>;">
							<?php echo number_format( $share, 1 ); ?>%
						</td>
						<td style="font-size:12px;color:#666;">
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
				<h3 style="margin-top:24px;"><?php esc_html_e( 'Thin content — action queue', 'luwipress' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Posts below the min word count for their type. Highest-priority cleanup target (Helpful Content Update penalises thin content site-wide).', 'luwipress' ); ?></p>
				<?php foreach ( $per_cpt as $cpt => $row ) :
					if ( empty( $row['thin_ids'] ) ) {
						continue;
					}
					$band = $row['band'] ?? array( 'min' => 0, 'label' => $cpt );
				?>
				<details style="margin-top:8px;background:#fff;border:1px solid #ddd;border-radius:6px;padding:8px 12px;">
					<summary style="cursor:pointer;font-weight:600;">
						<?php echo esc_html( $band['label'] ); ?>
						<span style="color:#c33;">— <?php echo (int) count( $row['thin_ids'] ); ?> <?php esc_html_e( 'posts under', 'luwipress' ); ?> <?php echo (int) $band['min']; ?> <?php esc_html_e( 'words', 'luwipress' ); ?></span>
					</summary>
					<table class="widefat striped" style="margin-top:8px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Title', 'luwipress' ); ?></th>
								<th style="width:120px;text-align:right;"><?php esc_html_e( 'Words', 'luwipress' ); ?></th>
								<th style="width:80px;"><?php esc_html_e( 'Edit', 'luwipress' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $row['thin_ids'] as $entry ) : ?>
								<tr>
									<td><?php echo esc_html( $entry['title'] ); ?></td>
									<td style="text-align:right;font-weight:600;color:#c33;"><?php echo (int) $entry['word_count']; ?></td>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $entry['post_id'] ) ); ?>" class="button button-small">
											<span class="dashicons dashicons-edit" style="font-size:14px;line-height:1.5;"></span>
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
				<p><em><?php esc_html_e( 'Health Score not loaded or no content measured yet. Reload after publishing some content.', 'luwipress' ); ?></em></p>
			<?php endif; ?>

			<p style="margin-top:16px;">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-settings&tab=ai' ) ); ?>">
					<span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Edit word count targets', 'luwipress' ); ?>
				</a>
			</p>
		</div>
	</div>
</div>

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
