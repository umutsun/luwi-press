<?php
/**
 * LuwiPress Schema Preview admin page
 *
 * Operator-facing wrapper over `LuwiPress_Frontend_Inspector::rest_render_dump`
 * (`/luwipress/v1/frontend/render-dump`, shipped 3.4.1). Fetches a live URL
 * with cache-bypass, extracts every JSON-LD block from the rendered head,
 * pretty-prints them, and offers a one-click handoff to Google's Rich
 * Results Test for validation.
 *
 * Strategic role: closes a recurring loop where operators had to either
 * (a) open the live URL, view source, manually find each
 *     <script type="application/ld+json"> and paste into Rich Results, or
 * (b) round-trip through chrome-devtools MCP to get the same data — neither
 * usable for non-WebMCP customers.
 *
 * The page also surfaces the Schema Registry's "what types are registered"
 * view so the operator can see at a glance what should be emitting and
 * what's actually emitting on a sample URL.
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

$rest_base  = esc_url_raw( rest_url( 'luwipress/v1/' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );

// Schema Registry — list registered types so the operator sees the target
// surface (what SHOULD render on relevant pages).
$registered_types = array();
if ( class_exists( 'LuwiPress_Schema_Registry' ) ) {
	$reg = LuwiPress_Schema_Registry::get_instance();
	if ( method_exists( $reg, 'get_registered_types' ) ) {
		$registered_types = $reg->get_registered_types();
	}
}

// Quick-pick URLs — homepage, first product, first product category — so
// the operator can run a preview in one click on the first visit.
//
// 3.5.6+: cached in a 6h transient so repeated admin pageviews don't keep
// querying `get_posts` + `get_terms(hide_empty=true)` (the latter triggers
// term-count subqueries even for `number=1`). Busted automatically when
// products / categories change because the same `save_post_product` and
// `created/edit/delete_product_cat` hooks the KG already listens on will
// fire `clean_term_cache` etc. — we use a short TTL instead of registering
// a dedicated invalidator since this is purely a UI affordance.
// NOTE the `_v2` key bump (3.7.1): get_term_link() / get_permalink() can
// return a WP_Error / false (notably under WPML when a term's language
// context can't resolve a link). The pre-3.7.1 code stored that WP_Error
// object straight into the transient, then `esc_attr( $q['url'] )` at
// render time fataled with "Object of class WP_Error could not be
// converted to string" — surfacing as the admin "critical error" box. The
// guards below skip any non-string URL; the new key sidesteps a transient
// already poisoned with a WP_Error on live sites.
$quick_urls = get_transient( 'luwipress_schema_preview_quick_urls_v2' );
if ( false === $quick_urls ) {
	$quick_urls = array();
	$quick_urls[] = array(
		'label' => __( 'Homepage', 'luwipress' ),
		'url'   => home_url( '/' ),
	);
	if ( post_type_exists( 'product' ) ) {
		$recent_products = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );
		if ( ! empty( $recent_products ) ) {
			$prod_link = get_permalink( $recent_products[0] );
			if ( is_string( $prod_link ) && '' !== $prod_link ) {
				$quick_urls[] = array(
					'label' => __( 'Latest product', 'luwipress' ) . ' — ' . get_the_title( $recent_products[0] ),
					'url'   => $prod_link,
				);
			}
		}
		$recent_cats = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'number'     => 1,
		) );
		if ( ! empty( $recent_cats ) && ! is_wp_error( $recent_cats ) ) {
			$cat_link = get_term_link( $recent_cats[0] );
			if ( is_string( $cat_link ) && '' !== $cat_link ) {
				$quick_urls[] = array(
					'label' => __( 'Product category', 'luwipress' ) . ' — ' . $recent_cats[0]->name,
					'url'   => $cat_link,
				);
			}
		}
	}
	set_transient( 'luwipress_schema_preview_quick_urls_v2', $quick_urls, 6 * HOUR_IN_SECONDS );
}
?>
<?php $luwipress_hub_mode = defined( 'LUWIPRESS_HUB_INCLUDED' ); ?>
<?php if ( ! $luwipress_hub_mode ) : ?>
<div class="wrap luwipress-schema-preview">
<?php endif; ?>
	<?php if ( ! $luwipress_hub_mode ) : ?>
	<h1><span class="dashicons dashicons-code-standards"></span> <?php esc_html_e( 'Schema Preview', 'luwipress' ); ?></h1>
	<?php endif; ?>
	<p class="lp-page-intro">
		<?php esc_html_e( 'Fetch any live URL and inspect every JSON-LD schema block embedded in its head. Cache-bypassed so what you see is what Google would see right now. One-click handoff to Rich Results Test for validation.', 'luwipress' ); ?>
	</p>

	<!-- FAQ Editor cross-link -->
	<div class="luwipress-card luwipress-card--warning lwp-spv-cta">
		<p class="lwp-spv-cta-line">
			<span class="dashicons dashicons-edit" aria-hidden="true"></span>
			<strong><?php esc_html_e( 'Need to add FAQ to a product or post?', 'luwipress' ); ?></strong>
			<span><?php esc_html_e( 'Edit the post — the LuwiPress FAQ metabox now ships on every product / post / page.', 'luwipress' ); ?></span>
			<a class="lp-btn lp-btn--outline lp-btn--sm" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>">
				<?php esc_html_e( 'Edit a product →', 'luwipress' ); ?>
			</a>
		</p>
	</div>

	<!-- Registry overview (collapsible — read-only reference, default-collapsed to declutter) -->
	<?php if ( ! empty( $registered_types ) ) : ?>
	<details class="lp-collapse lwp-spv-registry">
		<summary>
			<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
			<span><?php esc_html_e( 'Schema Registry — registered types', 'luwipress' ); ?></span>
			<span class="lp-collapse-hint"><?php echo esc_html( (string) count( $registered_types ) ); ?></span>
		</summary>
		<div class="lp-collapse-body">
		<p class="lp-form-hint"><?php esc_html_e( 'Types LuwiPress will emit on relevant content when their meta key carries data. Use the preview below to see which ones are actually rendering on a sample URL.', 'luwipress' ); ?></p>
		<div class="lwp-spv-type-row">
			<?php foreach ( $registered_types as $type_key => $type_def ) :
				$label = $type_def['label'] ?? $type_key;
			?>
				<span class="lp-pill pill-neutral lwp-spv-type-pill">
					<strong><?php echo esc_html( $label ); ?></strong>
					<?php if ( ! empty( $type_def['meta_key'] ) ) : ?>
						<code class="lwp-spv-meta-key"><?php echo esc_html( $type_def['meta_key'] ); ?></code>
					<?php endif; ?>
				</span>
			<?php endforeach; ?>
		</div>
		</div><!-- .lp-collapse-body -->
	</details>
	<?php endif; ?>

	<!-- Preview form -->
	<div class="luwipress-card luwipress-card--primary">
		<h2><span class="dashicons dashicons-search" aria-hidden="true"></span> <?php esc_html_e( 'Run preview', 'luwipress' ); ?></h2>
		<form id="lwp-schema-preview-form" class="lwp-spv-form">
			<input type="url" id="lwp-schema-preview-url" name="url"
			       class="lp-form-input lwp-spv-url-input"
			       placeholder="https://example.com/page-to-inspect/" required>
			<button type="submit" class="lp-btn lp-btn--primary">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<?php esc_html_e( 'Inspect schema', 'luwipress' ); ?>
			</button>
		</form>

		<?php if ( ! empty( $quick_urls ) ) : ?>
		<div class="lwp-spv-quick">
			<span class="lp-form-hint lwp-spv-quick-label"><?php esc_html_e( 'Quick targets:', 'luwipress' ); ?></span>
			<?php foreach ( $quick_urls as $q ) : ?>
				<?php if ( empty( $q['url'] ) || ! is_string( $q['url'] ) ) { continue; } ?>
				<button type="button" class="lp-btn lp-btn--outline lp-btn--sm lwp-schema-quick" data-url="<?php echo esc_attr( $q['url'] ); ?>">
					<?php echo esc_html( $q['label'] ); ?>
				</button>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<div id="lwp-schema-preview-status" class="lp-form-hint lwp-spv-status"></div>
		<div id="lwp-schema-preview-results" class="lwp-spv-results"></div>
	</div>
<?php if ( ! $luwipress_hub_mode ) : ?>
</div>
<?php endif; ?>

<style>
.lwp-spv-cta { padding: 14px 16px; }
.lwp-spv-cta-line {
	margin: 0;
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
	color: var(--lp-text);
}
.lwp-spv-cta-line .dashicons { color: var(--lp-warning); }
.lwp-spv-cta-line a { margin-left: auto; }

.lwp-spv-type-row {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 10px;
}
.lwp-spv-type-pill { padding-right: 12px; }
.lwp-spv-meta-key {
	font-size: 11px;
	color: var(--lp-text-secondary);
	background: transparent;
	padding: 0;
}

.lwp-spv-form {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
	margin: 10px 0 0;
}
.lwp-spv-url-input { flex: 1; min-width: 320px; }

.lwp-spv-quick {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	margin-top: 12px;
}
.lwp-spv-quick-label { margin-right: 4px; }

.lwp-spv-status,
.lwp-spv-results { margin-top: 12px; }
</style>

<script>
(function () {
	'use strict';
	var REST_BASE  = <?php echo wp_json_encode( $rest_base ); ?>;
	var REST_NONCE = <?php echo wp_json_encode( $rest_nonce ); ?>;

	var statusEl  = document.getElementById('lwp-schema-preview-status');
	var resultsEl = document.getElementById('lwp-schema-preview-results');
	var urlInput  = document.getElementById('lwp-schema-preview-url');

	function setStatus(msg, isError) {
		if (!statusEl) return;
		statusEl.textContent = msg;
		statusEl.style.color = isError ? '#c33' : '#666';
	}

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

	// Type-extraction helper — JSON-LD blocks can be a single object, a
	// graph wrapper, or an array of objects. Collect every @type we find
	// and label it sensibly for the operator.
	function extractTypes(block) {
		var types = [];
		function walk(node) {
			if (!node) return;
			if (Array.isArray(node)) { node.forEach(walk); return; }
			if (typeof node !== 'object') return;
			if (node['@type']) {
				if (Array.isArray(node['@type'])) {
					node['@type'].forEach(function (t) { types.push(t); });
				} else {
					types.push(node['@type']);
				}
			}
			if (node['@graph']) walk(node['@graph']);
		}
		walk(block);
		return types;
	}

	function renderSchemaBlocks(blocks) {
		resultsEl.innerHTML = '';
		if (!blocks.length) {
			resultsEl.appendChild(el('p', { css: 'color:#a86b00;font-weight:600;' }, ['No JSON-LD schema blocks found in head.']));
			return;
		}

		// Summary row — what types render on this URL
		var allTypes = [];
		blocks.forEach(function (b) {
			(b.parsed_types || []).forEach(function (t) {
				if (allTypes.indexOf(t) === -1) allTypes.push(t);
			});
		});
		var summary = el('p', { css: 'font-size:13px;' }, [
			el('strong', null, [blocks.length + ' JSON-LD block(s) found. Types: ']),
		]);
		allTypes.forEach(function (t) {
			summary.appendChild(el('span', {
				css: 'display:inline-block;padding:2px 8px;background:#2c7a2c;color:#fff;border-radius:4px;font-size:11px;margin-right:4px;'
			}, [t]));
		});
		resultsEl.appendChild(summary);

		blocks.forEach(function (b, i) {
			var card = el('details', { css: 'background:#fff;border:1px solid #ddd;border-radius:6px;padding:8px 12px;margin-bottom:8px;' });
			var summary = el('summary', { css: 'cursor:pointer;font-weight:600;' });
			summary.appendChild(document.createTextNode('Block #' + (i + 1) + ' — '));
			(b.parsed_types || []).forEach(function (t, idx) {
				if (idx > 0) summary.appendChild(document.createTextNode(' + '));
				summary.appendChild(el('code', { css: 'background:#eee;padding:0 4px;' }, [t]));
			});
			if (!(b.parsed_types || []).length) {
				summary.appendChild(el('em', null, ['(no @type)']));
			}
			card.appendChild(summary);

			var pre = el('pre', { css: 'background:#1e1e1e;color:#dcdcdc;padding:12px;border-radius:4px;overflow:auto;font-size:12px;max-height:480px;margin-top:8px;' });
			try {
				pre.textContent = JSON.stringify(b.parsed, null, 2);
			} catch (e) {
				pre.textContent = b.raw || '(could not parse)';
			}
			card.appendChild(pre);

			resultsEl.appendChild(card);
		});

		// Rich Results Test handoff — operator clicks, lands in Google's tool
		// with the URL prefilled.
		var currentUrl = urlInput.value || '';
		if (currentUrl) {
			var rrtUrl = 'https://search.google.com/test/rich-results?url=' + encodeURIComponent(currentUrl);
			var handoff = el('p', { css: 'margin-top:12px;' });
			handoff.appendChild(el('a', {
				href: rrtUrl, target: '_blank', rel: 'noopener',
				class: 'button button-primary'
			}, ['→ Validate in Google Rich Results Test']));
			resultsEl.appendChild(handoff);
		}
	}

	function inspect(url) {
		if (!url) {
			setStatus('Enter a URL first.', true);
			return;
		}
		setStatus('Fetching ' + url + ' …', false);
		resultsEl.innerHTML = '';

		var endpoint = REST_BASE + 'frontend/render-dump?url=' + encodeURIComponent(url) + '&scopes=schema,head';

		fetch(endpoint, {
			headers: { 'X-WP-Nonce': REST_NONCE, 'Accept': 'application/json' },
			credentials: 'same-origin'
		})
			.then(function (r) {
				if (!r.ok) { throw new Error('HTTP ' + r.status); }
				return r.json();
			})
			.then(function (data) {
				// render-dump returns schema blocks under data.schema.blocks
				// (Frontend Inspector standard shape since 3.4.1).
				var blocks = (data && data.schema && Array.isArray(data.schema.blocks)) ? data.schema.blocks : [];
				// Annotate with extracted @types for nicer rendering.
				blocks.forEach(function (b) {
					if (b.parsed) {
						b.parsed_types = extractTypes(b.parsed);
					}
				});
				setStatus('OK — fetched ' + (data.byte_size || 0) + ' bytes in ' + (data.fetched_at || 'just now') + '.', false);
				renderSchemaBlocks(blocks);
			})
			.catch(function (err) {
				setStatus('Inspection failed: ' + err.message, true);
			});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var form = document.getElementById('lwp-schema-preview-form');
		if (form) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				inspect(urlInput.value);
			});
		}
		document.querySelectorAll('.lwp-schema-quick').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var u = btn.getAttribute('data-url') || '';
				urlInput.value = u;
				inspect(u);
			});
		});
	});
})();
</script>
