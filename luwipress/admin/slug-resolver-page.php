<?php
/**
 * LuwiPress Slug Resolver admin page
 *
 * Operator-facing view of the six-pass page→product_cat redirect engine
 * (`LuwiPress_Slug_Resolver`, shipped 3.1.56+). Surfaces the full discovered
 * map, current overrides, runtime diagnostics, and a probe field for
 * verifying any slug before a DNS swap / migration.
 *
 * Why this page exists: until now the resolver was reachable only via
 * `/luwipress/v1/slug-resolver/*` REST + 5 WebMCP tools. Non-WebMCP
 * customers had no way to confirm "31 EN + 12 IT slug collisions are
 * being auto-resolved correctly" before a swap. This page closes that
 * gap with a 3-card layout: status hero → map table → overrides editor.
 *
 * @package LuwiPress
 * @since   3.5.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
}

$rest_base  = esc_url_raw( rest_url( 'luwipress/v1/' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );

// Pull initial diag synchronously so the page renders with real data on first
// paint (no spinner-on-arrival). The page JS re-fetches on action buttons.
$diag = null;
if ( class_exists( 'LuwiPress_Slug_Resolver' ) ) {
	$resolver = LuwiPress_Slug_Resolver::get_instance();
	if ( method_exists( $resolver, 'rest_diag' ) ) {
		$req  = new WP_REST_Request( 'GET', '/luwipress/v1/slug-resolver/diag' );
		$resp = $resolver->rest_diag( $req );
		if ( $resp instanceof WP_REST_Response ) {
			$diag = $resp->get_data();
		} elseif ( is_array( $resp ) ) {
			$diag = $resp;
		}
	}
}

$enabled       = ! empty( $diag['enabled'] );
$map_size      = (int) ( $diag['map_size'] ?? 0 );
$override_size = (int) ( $diag['overrides_size'] ?? 0 );
$hook_attached = ! empty( $diag['hook_attached'] );
$p1_callbacks  = (int) ( $diag['p1_callback_count'] ?? 0 );
$wpml          = ! empty( $diag['wpml_active'] );
$polylang      = ! empty( $diag['polylang_active'] );
$wc            = ! empty( $diag['wc_active'] );
$last_build    = is_array( $diag['last_build'] ?? null ) ? $diag['last_build'] : array();
?>
<div class="wrap luwipress-slug-resolver">
	<h1><span class="dashicons dashicons-randomize"></span> <?php esc_html_e( 'Slug Resolver', 'luwipress' ); ?></h1>
	<p class="description" style="max-width:820px;">
		<?php esc_html_e( 'Auto-redirects legacy `/<hub>/` page URLs to their matching `/product-category/<hub>/` archive using six discovery passes (exact / cross-language / fuzzy / Levenshtein-1 / menu-parent / ancestor fallback). Critical before a DNS swap so visitors and Google land on the live archive, not a stale editorial page.', 'luwipress' ); ?>
	</p>

	<!-- Status hero -->
	<div class="luwipress-card" style="display:flex;gap:24px;flex-wrap:wrap;align-items:stretch;">
		<div style="flex:1;min-width:180px;">
			<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#666;"><?php esc_html_e( 'Status', 'luwipress' ); ?></div>
			<div style="font-size:28px;font-weight:600;color:<?php echo $enabled ? '#2c7a2c' : '#c33'; ?>;margin-top:4px;">
				<?php echo $enabled ? esc_html__( 'Enabled', 'luwipress' ) : esc_html__( 'Disabled', 'luwipress' ); ?>
			</div>
			<label style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;cursor:pointer;">
				<input type="checkbox" id="lwp-sr-enabled" <?php checked( $enabled ); ?>>
				<span><?php esc_html_e( 'Toggle resolver', 'luwipress' ); ?></span>
			</label>
		</div>
		<div style="flex:1;min-width:180px;">
			<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#666;"><?php esc_html_e( 'Map size', 'luwipress' ); ?></div>
			<div style="font-size:28px;font-weight:600;color:#222;margin-top:4px;"><?php echo esc_html( $map_size ); ?></div>
			<div style="color:#666;font-size:12px;margin-top:4px;"><?php esc_html_e( 'auto-discovered slug → target', 'luwipress' ); ?></div>
		</div>
		<div style="flex:1;min-width:180px;">
			<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#666;"><?php esc_html_e( 'Overrides', 'luwipress' ); ?></div>
			<div style="font-size:28px;font-weight:600;color:#222;margin-top:4px;"><?php echo esc_html( $override_size ); ?></div>
			<div style="color:#666;font-size:12px;margin-top:4px;"><?php esc_html_e( 'manual operator rules', 'luwipress' ); ?></div>
		</div>
		<div style="flex:1;min-width:180px;">
			<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#666;"><?php esc_html_e( 'Environment', 'luwipress' ); ?></div>
			<div style="margin-top:6px;display:flex;flex-direction:column;gap:3px;font-size:12px;">
				<span><?php echo $hook_attached ? '<span style="color:#2c7a2c;">●</span>' : '<span style="color:#c33;">●</span>'; ?> <?php esc_html_e( 'template_redirect hook', 'luwipress' ); ?></span>
				<span><?php echo $wc ? '<span style="color:#2c7a2c;">●</span>' : '<span style="color:#999;">●</span>'; ?> WooCommerce</span>
				<span><?php echo $wpml ? '<span style="color:#2c7a2c;">●</span>' : '<span style="color:#999;">●</span>'; ?> WPML</span>
				<span><?php echo $polylang ? '<span style="color:#2c7a2c;">●</span>' : '<span style="color:#999;">●</span>'; ?> Polylang</span>
				<?php if ( $p1_callbacks > 1 ) : ?>
				<span style="color:#a86b00;" title="<?php esc_attr_e( 'Another plugin or theme is also hooked at template_redirect priority 1 — verify no conflict.', 'luwipress' ); ?>">
					<span style="color:#a86b00;">●</span> <?php echo esc_html( sprintf( /* translators: %d count of callbacks */ __( '%d callbacks at p1', 'luwipress' ), $p1_callbacks ) ); ?>
				</span>
				<?php endif; ?>
			</div>
		</div>
		<div style="flex:0 0 auto;align-self:center;display:flex;flex-direction:column;gap:6px;">
			<button class="button button-primary" id="lwp-sr-rebuild">
				<span class="dashicons dashicons-update" style="line-height:1.6;"></span>
				<?php esc_html_e( 'Force rebuild', 'luwipress' ); ?>
			</button>
			<button class="button" id="lwp-sr-refresh-diag">
				<?php esc_html_e( 'Refresh diag', 'luwipress' ); ?>
			</button>
			<?php if ( ! empty( $last_build['time'] ) ) : ?>
			<div style="font-size:11px;color:#888;text-align:center;">
				<?php
				$ago = human_time_diff( (int) $last_build['time'], current_time( 'timestamp' ) );
				/* translators: %s human-readable time difference */
				echo esc_html( sprintf( __( 'Built %s ago', 'luwipress' ), $ago ) );
				?>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<!-- Probe form — pre-swap verification: paste a list of swap-day slugs,
	     confirm every one resolves to the expected archive. -->
	<div class="luwipress-card">
		<h2><?php esc_html_e( 'Probe slugs', 'luwipress' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Paste any slugs (one per line, or comma-separated) to verify each one resolves before swap day. Probe runs the same six-pass discovery the live redirect uses.', 'luwipress' ); ?></p>
		<textarea id="lwp-sr-probe-input" rows="3" style="width:100%;font-family:monospace;font-size:13px;" placeholder="percussions&#10;duduk&#10;ney&#10;santur"></textarea>
		<div style="margin-top:8px;display:flex;gap:8px;align-items:center;">
			<button class="button button-primary" id="lwp-sr-probe-go">
				<span class="dashicons dashicons-search" style="line-height:1.6;"></span>
				<?php esc_html_e( 'Probe', 'luwipress' ); ?>
			</button>
			<span id="lwp-sr-probe-status" style="color:#666;font-size:13px;"></span>
		</div>
		<div id="lwp-sr-probe-results" style="margin-top:12px;"></div>
	</div>

	<!-- Map table -->
	<div class="luwipress-card">
		<h2 style="display:flex;align-items:center;gap:10px;">
			<?php esc_html_e( 'Composed redirect map', 'luwipress' ); ?>
			<input type="search" id="lwp-sr-map-filter" placeholder="<?php esc_attr_e( 'Filter slugs…', 'luwipress' ); ?>" class="regular-text" style="flex:1;max-width:260px;font-size:13px;">
		</h2>
		<p class="description">
			<?php esc_html_e( 'Slug → resolved target. Overrides win over auto-discovery. `true` means "auto-redirect to /product-category/<slug>/". `false` means "do not redirect".', 'luwipress' ); ?>
		</p>
		<div id="lwp-sr-map-status" style="color:#666;font-size:13px;margin-bottom:8px;"><?php esc_html_e( 'Loading map…', 'luwipress' ); ?></div>
		<table class="wp-list-table widefat striped" id="lwp-sr-map-table">
			<thead>
				<tr>
					<th style="width:30%;"><?php esc_html_e( 'Slug', 'luwipress' ); ?></th>
					<th style="width:15%;"><?php esc_html_e( 'Source', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Target', 'luwipress' ); ?></th>
					<th style="width:160px;"><?php esc_html_e( 'Actions', 'luwipress' ); ?></th>
				</tr>
			</thead>
			<tbody><tr><td colspan="4" style="color:#999;font-style:italic;"><?php esc_html_e( 'Loading…', 'luwipress' ); ?></td></tr></tbody>
		</table>
	</div>

	<!-- Add override -->
	<div class="luwipress-card">
		<h2><?php esc_html_e( 'Add manual override', 'luwipress' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Use this when the auto-discovery picks the wrong target, or for legacy slugs not in the live site map (e.g. retired URLs you still want to 301).', 'luwipress' ); ?>
		</p>
		<form id="lwp-sr-override-form" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
			<div>
				<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;letter-spacing:1px;"><?php esc_html_e( 'Slug', 'luwipress' ); ?></label>
				<input type="text" id="lwp-sr-override-slug" placeholder="kemence-de-la-mer-noire" class="regular-text" style="font-family:monospace;" required>
			</div>
			<div>
				<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;letter-spacing:1px;"><?php esc_html_e( 'Target', 'luwipress' ); ?></label>
				<select id="lwp-sr-override-type">
					<option value="term_id"><?php esc_html_e( 'Term ID', 'luwipress' ); ?></option>
					<option value="url"><?php esc_html_e( 'URL or path', 'luwipress' ); ?></option>
					<option value="true"><?php esc_html_e( 'Auto (true)', 'luwipress' ); ?></option>
					<option value="false"><?php esc_html_e( 'Suppress (false)', 'luwipress' ); ?></option>
				</select>
			</div>
			<div style="flex:1;min-width:240px;">
				<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;letter-spacing:1px;"><?php esc_html_e( 'Value', 'luwipress' ); ?></label>
				<input type="text" id="lwp-sr-override-value" placeholder="27 or /product-category/bowed/" class="regular-text" style="width:100%;font-family:monospace;">
			</div>
			<div>
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Save override', 'luwipress' ); ?>
				</button>
			</div>
		</form>
		<div id="lwp-sr-override-status" style="margin-top:8px;color:#666;font-size:13px;"></div>
	</div>
</div>

<style>
.luwipress-slug-resolver .luwipress-card {
	background: #fff;
	border: 1px solid #ddd;
	border-radius: 6px;
	padding: 16px 20px;
	margin: 16px 0;
}
.luwipress-slug-resolver .luwipress-card h2 {
	margin-top: 0;
	font-size: 17px;
}
.luwipress-slug-resolver .lwp-sr-target-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 11px;
	font-weight: 600;
	margin-right: 4px;
}
.luwipress-slug-resolver .lwp-sr-badge-auto    { background:#e0f0ff;color:#0070b5; }
.luwipress-slug-resolver .lwp-sr-badge-override{ background:#fff3d6;color:#a86b00; }
.luwipress-slug-resolver .lwp-sr-badge-suppress{ background:#fde2e2;color:#a52a2a; }
.luwipress-slug-resolver .lwp-sr-badge-term    { background:#e9f5e9;color:#2c7a2c; }
.luwipress-slug-resolver .lwp-sr-badge-url     { background:#eee;color:#444; }
.luwipress-slug-resolver code { font-size:12px; }
</style>

<script>
(function () {
	'use strict';
	var REST_BASE  = <?php echo wp_json_encode( $rest_base ); ?>;
	var REST_NONCE = <?php echo wp_json_encode( $rest_nonce ); ?>;

	function api(path, opts) {
		opts = opts || {};
		opts.headers = opts.headers || {};
		opts.headers['X-WP-Nonce'] = REST_NONCE;
		opts.headers['Accept']     = 'application/json';
		if (opts.body && typeof opts.body !== 'string') {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(opts.body);
		}
		return fetch(REST_BASE + path, opts).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) {
					var msg = (j && (j.message || j.code)) || ('HTTP ' + r.status);
					throw new Error(msg);
				}
				return j;
			});
		});
	}

	function el(tag, attrs, children) {
		var n = document.createElement(tag);
		if (attrs) Object.keys(attrs).forEach(function (k) {
			if (k === 'html') n.innerHTML = attrs[k];
			else if (k === 'text') n.textContent = attrs[k];
			else if (k === 'css') n.setAttribute('style', attrs[k]);
			else n.setAttribute(k, attrs[k]);
		});
		(children || []).forEach(function (c) {
			n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return n;
	}

	function describeTarget(rule) {
		// Returns {label, kind, raw}.
		if (rule === true)  return { label: 'auto → /product-category/<slug>/', kind: 'auto' };
		if (rule === false) return { label: 'suppress (no redirect)',           kind: 'suppress' };
		if (rule === null || typeof rule === 'undefined') return { label: '(none)', kind: '' };
		if (typeof rule === 'number') return { label: 'term #' + rule, kind: 'term', value: rule };
		if (typeof rule === 'string') {
			if (/^\d+$/.test(rule)) return { label: 'term #' + rule, kind: 'term', value: rule };
			return { label: rule, kind: 'url', value: rule };
		}
		return { label: String(rule), kind: '' };
	}

	function badgeFor(rule, isOverride) {
		var info = describeTarget(rule);
		var classes = 'lwp-sr-target-badge ';
		if (isOverride)             classes += 'lwp-sr-badge-override';
		else if (info.kind === 'auto')      classes += 'lwp-sr-badge-auto';
		else if (info.kind === 'suppress')  classes += 'lwp-sr-badge-suppress';
		else if (info.kind === 'term')      classes += 'lwp-sr-badge-term';
		else                                  classes += 'lwp-sr-badge-url';
		return el('span', { class: classes, text: info.label });
	}

	/* ─────────── Map table ─────────── */
	var mapData    = { map: {}, overrides: {}, composed: {} };
	var mapTableBody = document.querySelector('#lwp-sr-map-table tbody');
	var mapStatusEl  = document.getElementById('lwp-sr-map-status');
	var mapFilterEl  = document.getElementById('lwp-sr-map-filter');

	function renderMap() {
		var filter = (mapFilterEl.value || '').toLowerCase().trim();
		mapTableBody.innerHTML = '';
		var composed = mapData.composed || {};
		var overrides = mapData.overrides || {};
		var slugs = Object.keys(composed).sort();
		var shown = 0;
		slugs.forEach(function (slug) {
			if (filter && slug.indexOf(filter) === -1) return;
			shown++;
			var rule = composed[slug];
			var isOverride = Object.prototype.hasOwnProperty.call(overrides, slug);
			var row = el('tr');
			row.appendChild(el('td', null, [el('code', null, [slug])]));
			row.appendChild(el('td', null, [isOverride ? el('span', { class: 'lwp-sr-badge-override lwp-sr-target-badge', text: 'override' }) : el('span', { class: 'lwp-sr-badge-auto lwp-sr-target-badge', text: 'auto' })]));
			row.appendChild(el('td', null, [badgeFor(rule, isOverride)]));
			var actions = el('td');
			if (isOverride) {
				var rm = el('button', { class: 'button button-small button-link-delete', type: 'button', text: 'Remove' });
				rm.addEventListener('click', function () { saveOverride(slug, null); });
				actions.appendChild(rm);
			}
			row.appendChild(actions);
			mapTableBody.appendChild(row);
		});
		if (shown === 0) {
			mapTableBody.appendChild(el('tr', null, [el('td', { colspan: 4, css: 'color:#999;font-style:italic;text-align:center;padding:24px;' }, [filter ? 'No slugs match this filter.' : 'Map is empty — try Force rebuild.'])]));
		}
		mapStatusEl.textContent = shown + ' / ' + slugs.length + ' shown';
	}

	function loadMap() {
		mapStatusEl.textContent = 'Loading map…';
		return api('slug-resolver/map').then(function (j) {
			mapData = j;
			renderMap();
		}).catch(function (e) {
			mapStatusEl.textContent = 'Map load failed: ' + e.message;
			mapStatusEl.style.color = '#c33';
		});
	}

	if (mapFilterEl) mapFilterEl.addEventListener('input', renderMap);

	/* ─────────── Overrides ─────────── */
	function saveOverride(slug, target) {
		var body = { slug: slug };
		if (target === null) {
			body.target = null;
		} else {
			body.target = target;
		}
		return api('slug-resolver/override', { method: 'POST', body: body })
			.then(function () { return loadMap(); })
			.catch(function (e) {
				var s = document.getElementById('lwp-sr-override-status');
				if (s) { s.textContent = 'Save failed: ' + e.message; s.style.color = '#c33'; }
			});
	}

	var ovForm = document.getElementById('lwp-sr-override-form');
	if (ovForm) {
		ovForm.addEventListener('submit', function (e) {
			e.preventDefault();
			var slug  = (document.getElementById('lwp-sr-override-slug').value || '').trim();
			var type  = document.getElementById('lwp-sr-override-type').value;
			var value = (document.getElementById('lwp-sr-override-value').value || '').trim();
			var status = document.getElementById('lwp-sr-override-status');
			if (!slug) { status.textContent = 'Slug required'; status.style.color = '#c33'; return; }
			var target;
			if (type === 'true')         target = true;
			else if (type === 'false')   target = false;
			else if (type === 'term_id') {
				if (!/^\d+$/.test(value)) { status.textContent = 'Term ID must be numeric'; status.style.color = '#c33'; return; }
				target = parseInt(value, 10);
			} else {
				if (!value) { status.textContent = 'URL or path required'; status.style.color = '#c33'; return; }
				target = value;
			}
			status.textContent = 'Saving…'; status.style.color = '#666';
			saveOverride(slug, target).then(function () {
				status.textContent = 'Saved.'; status.style.color = '#2c7a2c';
				document.getElementById('lwp-sr-override-slug').value = '';
				document.getElementById('lwp-sr-override-value').value = '';
			});
		});
	}

	/* ─────────── Toggle enabled ─────────── */
	var enabledCb = document.getElementById('lwp-sr-enabled');
	if (enabledCb) {
		enabledCb.addEventListener('change', function () {
			api('slug-resolver/settings', { method: 'POST', body: { enabled: enabledCb.checked } })
				.then(function () { loadMap(); });
		});
	}

	/* ─────────── Force rebuild ─────────── */
	var rebuildBtn = document.getElementById('lwp-sr-rebuild');
	if (rebuildBtn) {
		rebuildBtn.addEventListener('click', function () {
			rebuildBtn.disabled = true;
			rebuildBtn.textContent = 'Rebuilding…';
			api('slug-resolver/rebuild', { method: 'POST' })
				.then(function () { return loadMap(); })
				.catch(function () {})
				.then(function () {
					rebuildBtn.disabled = false;
					rebuildBtn.innerHTML = '<span class="dashicons dashicons-update" style="line-height:1.6;"></span> Force rebuild';
				});
		});
	}

	var refreshBtn = document.getElementById('lwp-sr-refresh-diag');
	if (refreshBtn) {
		refreshBtn.addEventListener('click', function () {
			window.location.reload();
		});
	}

	/* ─────────── Probe form ─────────── */
	var probeBtn  = document.getElementById('lwp-sr-probe-go');
	var probeIn   = document.getElementById('lwp-sr-probe-input');
	var probeOut  = document.getElementById('lwp-sr-probe-results');
	var probeStat = document.getElementById('lwp-sr-probe-status');

	if (probeBtn) {
		probeBtn.addEventListener('click', function () {
			var raw = (probeIn.value || '').trim();
			if (!raw) { probeStat.textContent = 'Enter at least one slug.'; return; }
			var slugs = raw.split(/[\n,]+/).map(function (s) { return s.trim(); }).filter(Boolean);
			if (!slugs.length) return;
			probeStat.textContent = 'Probing ' + slugs.length + ' slug(s)…';
			probeStat.style.color = '#666';
			probeOut.innerHTML = '';
			var qs = slugs.map(function (s) { return 'probe[]=' + encodeURIComponent(s); }).join('&');
			api('slug-resolver/diag?' + qs).then(function (j) {
				var probe = j.probe || {};
				var table = el('table', { class: 'wp-list-table widefat striped' });
				var thead = el('thead', null, [el('tr', null, [
					el('th', { css: 'width:25%;' }, ['Slug']),
					el('th', { css: 'width:10%;' }, ['In map']),
					el('th', null, ['Rule']),
					el('th', null, ['Resolved target']),
				])]);
				table.appendChild(thead);
				var tbody = el('tbody');
				slugs.forEach(function (slug) {
					var p = probe[slug] || { in_map: false, rule: null, resolved_target: null };
					var inMapPill = el('span', { css: p.in_map ? 'color:#2c7a2c;font-weight:600;' : 'color:#c33;font-weight:600;', text: p.in_map ? '✓ yes' : '✗ no' });
					var row = el('tr', null, [
						el('td', null, [el('code', null, [slug])]),
						el('td', null, [inMapPill]),
						el('td', null, [badgeFor(p.rule, false)]),
						el('td', null, p.resolved_target ? [el('a', { href: p.resolved_target, target: '_blank', rel: 'noopener' }, [p.resolved_target])] : [el('span', { css: 'color:#999;font-style:italic;', text: '(no target)' })]),
					]);
					tbody.appendChild(row);
				});
				table.appendChild(tbody);
				probeOut.appendChild(table);
				var hits = slugs.filter(function (s) { return probe[s] && probe[s].in_map; }).length;
				probeStat.textContent = hits + ' / ' + slugs.length + ' resolve';
				probeStat.style.color = hits === slugs.length ? '#2c7a2c' : '#a86b00';
			}).catch(function (e) {
				probeStat.textContent = 'Probe failed: ' + e.message;
				probeStat.style.color = '#c33';
			});
		});
	}

	loadMap();
})();
</script>
