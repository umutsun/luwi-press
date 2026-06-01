<?php
/**
 * LuwiPress Vendors admin page
 *
 * Operator surface for the generic Vendor/Maker/Atelier CPT module
 * (`LuwiPress_Vendors`, shipped 3.5.0+). The CPT itself uses WP's native
 * post-type screens for create/edit/list. This page configures the
 * surrounding shell — archive slug, UI labels, entity_type, field
 * visibility toggles, social-link toggles, legacy URL redirects — and
 * shows a published-vendor roster with quick links to edit each one.
 *
 * Why this page exists: until now the vendor settings surface was reachable
 * only via `/luwipress/v1/vendors/settings` + the `vendor_settings_*`
 * WebMCP tools. Customers (and Tapadum) had no way to flip "luthiers"
 * to "chefs" or toggle social-link visibility without a CLI / API tool.
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

if ( ! class_exists( 'LuwiPress_Vendors' ) ) {
	echo '<div class="wrap"><h1>' . esc_html__( 'Vendors', 'luwipress' ) . '</h1><p>' . esc_html__( 'Vendors module not available.', 'luwipress' ) . '</p></div>';
	return;
}

$rest_base  = esc_url_raw( rest_url( 'luwipress/v1/' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );

$settings   = LuwiPress_Vendors::get_all_settings();
$post_type  = LuwiPress_Vendors::POST_TYPE;
$counts     = wp_count_posts( $post_type );
$published  = (int) ( $counts->publish ?? 0 );
$drafts     = (int) ( $counts->draft ?? 0 );

// Decode legacy_redirects payload — stored as JSON string.
$legacy_redirects = array();
if ( ! empty( $settings['legacy_redirects'] ) ) {
	$decoded = is_array( $settings['legacy_redirects'] )
		? $settings['legacy_redirects']
		: json_decode( (string) $settings['legacy_redirects'], true );
	if ( is_array( $decoded ) ) {
		$legacy_redirects = $decoded;
	}
}

$entity_options = array(
	'organization'  => __( 'Organization (atelier, workshop, brand)', 'luwipress' ),
	'person'        => __( 'Person (individual maestro, author, artist)', 'luwipress' ),
	'localbusiness' => __( 'Local Business (physical-store vendor)', 'luwipress' ),
);

$social_fields = array(
	'facebook'   => 'Facebook',
	'instagram'  => 'Instagram',
	'youtube'    => 'YouTube',
	'soundcloud' => 'SoundCloud',
	'linkedin'   => 'LinkedIn',
	'x'          => 'X (Twitter)',
	'behance'    => 'Behance',
	'website'    => __( 'Personal website', 'luwipress' ),
);

$profile_fields = array(
	'location'   => __( 'Location', 'luwipress' ),
	'specialty'  => __( 'Specialty', 'luwipress' ),
	'years'      => __( 'Years active', 'luwipress' ),
	'quote'      => __( 'Featured quote', 'luwipress' ),
);
?>
<?php $luwipress_hub_mode = defined( 'LUWIPRESS_HUB_INCLUDED' ); ?>
<?php if ( ! $luwipress_hub_mode ) : ?>
<div class="wrap luwipress-vendors">
<?php endif; ?>
	<?php if ( ! $luwipress_hub_mode ) : ?>
	<div class="lp-header">
			<div class="lp-header-left">
				<h1 class="lp-title">
					<img class="lp-logo" width="28" height="28"
					     src="<?php echo esc_url( LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo.png' ); ?>"
					     alt="LuwiPress" />
					<?php esc_html_e( 'Vendors', 'luwipress' ); ?>
				</h1>
			</div>
			<div class="lp-header-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress' ) ); ?>"
				   class="lp-pill lp-pill--action pill-neutral lp-pill--icon"
				   title="<?php esc_attr_e( 'Dashboard', 'luwipress' ); ?>">
					<span class="dashicons dashicons-dashboard"></span>
					<span class="screen-reader-text"><?php esc_html_e( 'Dashboard', 'luwipress' ); ?></span>
				</a>
				<span class="lp-pill pill-neutral" title="<?php esc_attr_e( 'Plugin version', 'luwipress' ); ?>">
					v<?php echo esc_html( LUWIPRESS_VERSION ); ?>
				</span>
			</div>
		</div>
	<?php endif; ?>
	<p class="lp-page-intro">
		<?php esc_html_e( 'The Vendor CPT is a generic profile system for makers, ateliers, luthiers, chefs, artists, or whichever entity makes the things your store sells. Configure the URL slug, UI labels, Schema.org entity type, and which profile fields show on the front-end. Vendors carry verified social URLs that feed into JSON-LD sameAs — a strong E-E-A-T signal for Google.', 'luwipress' ); ?>
	</p>

	<!-- Hero summary -->
	<div class="lwp-v-hero">
		<div class="lp-stat-row lwp-v-hero-stats">
			<div class="lp-stat lp-stat--success">
				<div class="lp-stat-label"><?php esc_html_e( 'Published vendors', 'luwipress' ); ?></div>
				<div class="lp-stat-value"><?php echo esc_html( (string) $published ); ?></div>
				<?php if ( $drafts > 0 ) : ?>
				<div class="lp-stat-caption lwp-v-c--warning"><?php echo esc_html( sprintf( /* translators: %d count */ __( '+ %d draft', 'luwipress' ), $drafts ) ); ?></div>
				<?php endif; ?>
			</div>
			<div class="lp-stat lp-stat--info">
				<div class="lp-stat-label"><?php esc_html_e( 'Archive URL', 'luwipress' ); ?></div>
				<div class="lp-stat-value lwp-v-archive-url">/<?php echo esc_html( $settings['archive_slug'] ); ?>/</div>
				<a href="<?php echo esc_url( home_url( '/' . $settings['archive_slug'] . '/' ) ); ?>"
				   target="_blank" rel="noopener" class="lwp-v-archive-link">
					<?php esc_html_e( 'Open archive →', 'luwipress' ); ?>
				</a>
			</div>
			<div class="lp-stat">
				<div class="lp-stat-label"><?php esc_html_e( 'Label', 'luwipress' ); ?></div>
				<div class="lp-stat-value lwp-v-label-pair">
					<?php echo esc_html( $settings['singular_label'] ); ?> / <?php echo esc_html( $settings['plural_label'] ); ?>
				</div>
			</div>
			<div class="lp-stat lp-stat--muted">
				<div class="lp-stat-label"><?php esc_html_e( 'Schema entity', 'luwipress' ); ?></div>
				<div class="lp-stat-value lwp-v-entity"><?php echo esc_html( $settings['entity_type'] ); ?></div>
			</div>
		</div>

		<div class="lwp-v-hero-actions">
			<a class="lp-btn lp-btn--primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>">
				<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
				<?php esc_html_e( 'Open vendor list', 'luwipress' ); ?>
			</a>
			<a class="lp-btn lp-btn--outline" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ); ?>">
				<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
				<?php esc_html_e( 'Add new vendor', 'luwipress' ); ?>
			</a>
		</div>
	</div>

	<form id="lwp-vendors-form" method="post" autocomplete="off">
		<?php wp_nonce_field( 'lwp_vendors_save', 'lwp_vendors_nonce' ); ?>

		<div class="lwp-collapse-stack">

		<!-- Identity -->
		<details class="lp-collapse" open>
		<summary><span class="dashicons dashicons-id-alt"></span> <span><?php esc_html_e( 'Identity', 'luwipress' ); ?></span></summary>
		<div class="lp-collapse-body">
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="lwp-v-archive_slug"><?php esc_html_e( 'Archive URL slug', 'luwipress' ); ?></label></th>
					<td>
						<input type="text" id="lwp-v-archive_slug" name="archive_slug" class="regular-text" value="<?php echo esc_attr( $settings['archive_slug'] ); ?>" style="font-family:monospace;">
						<p class="description"><?php esc_html_e( 'URL path for the vendor archive — luthiers / chefs / artists / etc. Changing flushes rewrite rules automatically.', 'luwipress' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="lwp-v-singular"><?php esc_html_e( 'Singular label', 'luwipress' ); ?></label></th>
					<td><input type="text" id="lwp-v-singular" name="singular_label" class="regular-text" value="<?php echo esc_attr( $settings['singular_label'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="lwp-v-plural"><?php esc_html_e( 'Plural label', 'luwipress' ); ?></label></th>
					<td><input type="text" id="lwp-v-plural" name="plural_label" class="regular-text" value="<?php echo esc_attr( $settings['plural_label'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="lwp-v-entity"><?php esc_html_e( 'Schema.org entity type', 'luwipress' ); ?></label></th>
					<td>
						<select id="lwp-v-entity" name="entity_type">
							<?php foreach ( $entity_options as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $settings['entity_type'], $val ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Drives the JSON-LD @type emitted on vendor archive pages. "Person" requires accurate individual data; "Organization" is the safe default for ateliers.', 'luwipress' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="lwp-v-occupation"><?php esc_html_e( 'Default occupation', 'luwipress' ); ?></label></th>
					<td>
						<input type="text" id="lwp-v-occupation" name="default_occupation" class="regular-text" value="<?php echo esc_attr( $settings['default_occupation'] ); ?>" placeholder="e.g. Luthier, Chef, Artisan, Brand">
						<p class="description"><?php esc_html_e( 'Fallback occupation for vendors that have not filled their own — used in Schema.org hasOccupation / jobTitle.', 'luwipress' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="lwp-v-icon"><?php esc_html_e( 'Menu icon (Dashicons class)', 'luwipress' ); ?></label></th>
					<td><input type="text" id="lwp-v-icon" name="menu_icon" class="regular-text" style="font-family:monospace;" value="<?php echo esc_attr( $settings['menu_icon'] ); ?>" placeholder="dashicons-store"></td>
				</tr>
			</table>
		</div><!-- .lp-collapse-body -->
		</details>

		<!-- Permalink & archive -->
		<details class="lp-collapse">
		<summary><span class="dashicons dashicons-admin-links"></span> <span><?php esc_html_e( 'Permalink & archive', 'luwipress' ); ?></span></summary>
		<div class="lp-collapse-body">
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Permalink prefix', 'luwipress' ); ?></th>
					<td>
						<label><input type="checkbox" name="with_front" value="1" <?php checked( ! empty( $settings['with_front'] ) ); ?>> <?php esc_html_e( 'Prepend WP permalink base (usually off)', 'luwipress' ); ?></label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Archive enabled', 'luwipress' ); ?></th>
					<td>
						<label><input type="checkbox" name="archive_enabled" value="1" <?php checked( ! empty( $settings['archive_enabled'] ) ); ?>> <?php esc_html_e( 'Public /<archive>/ index page', 'luwipress' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off = individual vendor permalinks still work, but the /<archive>/ landing page returns 404.', 'luwipress' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="lwp-v-single-pattern"><?php esc_html_e( 'Single URL pattern', 'luwipress' ); ?></label></th>
					<td>
						<input type="text" id="lwp-v-single-pattern" name="single_slug_pattern" class="regular-text" style="font-family:monospace;" value="<?php echo esc_attr( $settings['single_slug_pattern'] ); ?>">
						<p class="description"><?php esc_html_e( 'Default %postname% emits /<archive>/vendor-slug/. Future: %year%/%postname%, %category%/%postname%.', 'luwipress' ); ?></p>
					</td>
				</tr>
			</table>
		</div><!-- .lp-collapse-body -->
		</details>

		<!-- Profile field visibility -->
		<details class="lp-collapse">
		<summary><span class="dashicons dashicons-visibility"></span> <span><?php esc_html_e( 'Profile field visibility', 'luwipress' ); ?></span></summary>
		<div class="lp-collapse-body">
			<p class="description"><?php esc_html_e( 'Show or hide standard profile fields on the front-end single-vendor template.', 'luwipress' ); ?></p>
			<div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:8px;">
				<?php foreach ( $profile_fields as $key => $label ) :
					$opt = 'show_' . $key;
				?>
					<label style="display:inline-flex;align-items:center;gap:8px;">
						<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>" value="1" <?php checked( ! empty( $settings[ $opt ] ) ); ?>>
						<span><?php echo esc_html( $label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</div><!-- .lp-collapse-body -->
		</details>

		<!-- Social link fields -->
		<details class="lp-collapse">
		<summary><span class="dashicons dashicons-share"></span> <span><?php esc_html_e( 'Social link fields', 'luwipress' ); ?></span></summary>
		<div class="lp-collapse-body">
			<p class="description"><?php esc_html_e( 'Toggle which social link inputs appear on each vendor edit screen. Populated URLs flow into JSON-LD sameAs.', 'luwipress' ); ?></p>
			<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:8px;">
				<?php foreach ( $social_fields as $key => $label ) :
					$opt = 'social_' . $key;
				?>
					<label style="display:inline-flex;align-items:center;gap:8px;padding:6px 12px;background:#f6f7f7;border-radius:4px;">
						<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>" value="1" <?php checked( ! empty( $settings[ $opt ] ) ); ?>>
						<span><?php echo esc_html( $label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</div><!-- .lp-collapse-body -->
		</details>

		<!-- Legacy URL redirects -->
		<details class="lp-collapse">
		<summary><span class="dashicons dashicons-randomize"></span> <span><?php esc_html_e( 'Legacy URL redirects', 'luwipress' ); ?></span></summary>
		<div class="lp-collapse-body">
			<p class="description">
				<?php esc_html_e( 'Old slug renamed (e.g. /masters/ → /luthiers/) ? Add 301 redirect pairs so existing bookmarks + Google index entries route to the new URL. Prefix-match: both /masters/ and /masters/yildirim-palabiyik/ redirect.', 'luwipress' ); ?>
			</p>
			<table class="widefat" id="lwp-v-redirects-table" style="margin-top:8px;">
				<thead>
					<tr>
						<th style="width:45%;"><?php esc_html_e( 'From (old path)', 'luwipress' ); ?></th>
						<th style="width:45%;"><?php esc_html_e( 'To (new URL or path)', 'luwipress' ); ?></th>
						<th style="width:60px;"></th>
					</tr>
				</thead>
				<tbody id="lwp-v-redirects-body">
					<?php if ( empty( $legacy_redirects ) ) :
						$legacy_redirects = array( array( 'from' => '', 'to' => '' ) );
					endif;
					foreach ( $legacy_redirects as $r ) : ?>
					<tr class="lwp-v-redirect-row">
						<td><input type="text" class="regular-text" style="width:100%;font-family:monospace;" placeholder="/masters/" value="<?php echo esc_attr( $r['from'] ?? '' ); ?>" data-key="from"></td>
						<td><input type="text" class="regular-text" style="width:100%;font-family:monospace;" placeholder="/luthiers/" value="<?php echo esc_attr( $r['to'] ?? '' ); ?>" data-key="to"></td>
						<td><button type="button" class="button button-link-delete lwp-v-redirect-remove">×</button></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<button type="button" class="button" id="lwp-v-redirect-add" style="margin-top:8px;">+ <?php esc_html_e( 'Add row', 'luwipress' ); ?></button>
		</div><!-- .lp-collapse-body -->
		</details>

		</div><!-- .lwp-collapse-stack -->

		<!-- Save / flush row -->
		<div class="luwipress-card luwipress-card--primary lwp-v-save-row">
			<button type="submit" class="lp-btn lp-btn--primary lp-btn--lg" id="lwp-v-save">
				<span class="dashicons dashicons-saved" aria-hidden="true"></span>
				<?php esc_html_e( 'Save settings', 'luwipress' ); ?>
			</button>
			<button type="button" class="lp-btn lp-btn--outline" id="lwp-v-flush-rewrite">
				<span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span>
				<?php esc_html_e( 'Flush rewrite rules', 'luwipress' ); ?>
			</button>
			<span id="lwp-v-status" class="lp-form-hint"></span>
		</div>
	</form>

	<!-- Vendor roster → defers to the native CPT list table -->
	<?php
	// The WordPress CPT list table for vendors (edit.php?post_type=lwp_vendor)
	// is the canonical roster: it already ships WPML/Polylang language columns,
	// SEO columns, bulk actions, search, sort and pagination — everything a
	// custom roster would have to re-implement. We point the operator there
	// instead of maintaining a parallel, less-capable table.
	$lwp_v_list_url = admin_url( 'edit.php?post_type=' . $post_type );
	$lwp_v_new_url  = admin_url( 'post-new.php?post_type=' . $post_type );
	?>
	<div class="luwipress-card luwipress-card--info">
		<h2><span class="dashicons dashicons-groups" aria-hidden="true"></span> <?php echo esc_html( sprintf( /* translators: %s plural label */ __( '%s roster', 'luwipress' ), $settings['plural_label'] ) ); ?></h2>
		<p class="lp-form-hint">
			<?php
			echo esc_html( sprintf(
				/* translators: 1: count, 2: plural label */
				_n( '%1$d published %2$s.', '%1$d published %2$s.', $published, 'luwipress' ),
				$published,
				strtolower( $settings['plural_label'] )
			) );
			echo ' ';
			esc_html_e( 'The full roster — with language columns, SEO status, search and bulk actions — lives in the WordPress list table.', 'luwipress' );
			?>
		</p>
		<p>
			<a href="<?php echo esc_url( $lwp_v_list_url ); ?>" class="button button-primary">
				<span class="dashicons dashicons-list-view" style="vertical-align:text-bottom;"></span>
				<?php echo esc_html( sprintf( /* translators: %s plural label */ __( 'Manage all %s', 'luwipress' ), $settings['plural_label'] ) ); ?>
			</a>
			<a href="<?php echo esc_url( $lwp_v_new_url ); ?>" class="button">
				<span class="dashicons dashicons-plus-alt2" style="vertical-align:text-bottom;"></span>
				<?php echo esc_html( sprintf( /* translators: %s singular label */ __( 'Add new %s', 'luwipress' ), $settings['singular_label'] ) ); ?>
			</a>
		</p>
	</div>
<?php if ( ! $luwipress_hub_mode ) : ?>
</div>
<?php endif; ?>

<style>
/* Hero — stat row + action column.  */
.lwp-v-hero {
	display: grid;
	grid-template-columns: 1fr auto;
	gap: 16px;
	align-items: stretch;
	margin: 0 0 20px;
}
.lwp-v-hero-stats { margin: 0; }
.lwp-v-hero-actions {
	display: flex;
	flex-direction: column;
	gap: 8px;
	justify-content: center;
	padding: 4px 0;
}

.lwp-v-c--warning { color: var(--lp-warning); }
.lwp-v-archive-url {
	font-size: 18px;
	font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.lwp-v-archive-link {
	font-size: 12px;
	color: var(--lp-primary);
	display: inline-block;
	margin-top: 6px;
}
.lwp-v-label-pair { font-size: 18px; }
.lwp-v-entity { font-size: 18px; text-transform: capitalize; }

.lwp-v-save-row {
	display: flex;
	gap: 12px;
	align-items: center;
	flex-wrap: wrap;
}
.lwp-v-roster-status { margin-bottom: 8px; }
.lwp-v-empty { color: var(--lp-text-secondary); font-style: italic; }

/* Card form layout — wp form-table widened columns and tokenised colour. */
.luwipress-vendors .form-table th,
.luwipress-hub-site .form-table th { width: 200px; color: var(--lp-text); }

@media (max-width: 900px) {
	.lwp-v-hero { grid-template-columns: 1fr; }
	.lwp-v-hero-actions { flex-direction: row; flex-wrap: wrap; }
}
</style>

<script>
(function () {
	'use strict';
	var REST_BASE  = <?php echo wp_json_encode( $rest_base ); ?>;
	var REST_NONCE = <?php echo wp_json_encode( $rest_nonce ); ?>;
	var POST_TYPE  = <?php echo wp_json_encode( $post_type ); ?>;
	var EDIT_URL   = <?php echo wp_json_encode( admin_url( 'post.php?action=edit&post=' ) ); ?>;

	function api(path, opts) {
		opts = opts || {};
		opts.headers = opts.headers || {};
		opts.headers['X-WP-Nonce'] = REST_NONCE;
		opts.headers['Accept'] = 'application/json';
		if (opts.body && typeof opts.body !== 'string') {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(opts.body);
		}
		return fetch(REST_BASE + path, opts).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) throw new Error((j && (j.message || j.code)) || ('HTTP ' + r.status));
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

	/* ─────────── Legacy redirects table ─────────── */
	var redirectsBody = document.getElementById('lwp-v-redirects-body');
	document.getElementById('lwp-v-redirect-add').addEventListener('click', function () {
		var tr = el('tr', { class: 'lwp-v-redirect-row' });
		tr.innerHTML =
			'<td><input type="text" class="regular-text" style="width:100%;font-family:monospace;" placeholder="/old-path/" data-key="from"></td>' +
			'<td><input type="text" class="regular-text" style="width:100%;font-family:monospace;" placeholder="/new-path/" data-key="to"></td>' +
			'<td><button type="button" class="button button-link-delete lwp-v-redirect-remove">×</button></td>';
		redirectsBody.appendChild(tr);
	});
	redirectsBody.addEventListener('click', function (e) {
		if (e.target.classList.contains('lwp-v-redirect-remove')) {
			var row = e.target.closest('tr');
			if (row) row.remove();
		}
	});

	function collectRedirects() {
		var out = [];
		var rows = redirectsBody.querySelectorAll('tr.lwp-v-redirect-row');
		rows.forEach(function (r) {
			var from = (r.querySelector('[data-key="from"]').value || '').trim();
			var to   = (r.querySelector('[data-key="to"]').value || '').trim();
			if (from && to) out.push({ from: from, to: to });
		});
		return out;
	}

	/* ─────────── Save settings ─────────── */
	var statusEl = document.getElementById('lwp-v-status');

	function setStatus(msg, color) {
		statusEl.textContent = msg;
		statusEl.style.color = color || '#666';
	}

	document.getElementById('lwp-vendors-form').addEventListener('submit', function (e) {
		e.preventDefault();
		setStatus('Saving…', '#666');
		var form = e.target;
		var body = {
			archive_slug:        form['archive_slug'].value.trim(),
			singular_label:      form['singular_label'].value.trim(),
			plural_label:        form['plural_label'].value.trim(),
			entity_type:         form['entity_type'].value,
			default_occupation:  form['default_occupation'].value.trim(),
			menu_icon:           form['menu_icon'].value.trim(),
			with_front:          form['with_front'].checked      ? 1 : 0,
			archive_enabled:     form['archive_enabled'].checked  ? 1 : 0,
			single_slug_pattern: form['single_slug_pattern'].value.trim(),
			legacy_redirects:    collectRedirects(),
		};
		<?php foreach ( $profile_fields as $key => $_label ) : ?>
		body['show_<?php echo esc_js( $key ); ?>'] = form['show_<?php echo esc_js( $key ); ?>'].checked ? 1 : 0;
		<?php endforeach; ?>
		<?php foreach ( $social_fields as $key => $_label ) : ?>
		body['social_<?php echo esc_js( $key ); ?>'] = form['social_<?php echo esc_js( $key ); ?>'].checked ? 1 : 0;
		<?php endforeach; ?>

		api('vendors/settings', { method: 'POST', body: body }).then(function (j) {
			setStatus('Saved. ' + (j.slug_changed ? '(Permalinks flushed — old vendor URLs may need cache purge.)' : ''), '#2c7a2c');
		}).catch(function (e) {
			setStatus('Save failed: ' + e.message, '#c33');
		});
	});

	document.getElementById('lwp-v-flush-rewrite').addEventListener('click', function () {
		setStatus('Flushing…', '#666');
		api('vendors/sync-rewrite', { method: 'POST' })
			.then(function () { setStatus('Flushed.', '#2c7a2c'); })
			.catch(function (e) { setStatus('Flush failed: ' + e.message, '#c33'); });
	});

	/* ─────────── Roster ─────────── */
	var rosterBody = document.querySelector('#lwp-v-roster tbody');
	var rosterStatusEl = document.getElementById('lwp-v-roster-status');

	function countSocials(meta) {
		var c = 0;
		['facebook','instagram','youtube','soundcloud','linkedin','x','behance','website'].forEach(function (k) {
			if (meta && meta[k]) c++;
		});
		return c;
	}

	function renderRoster(items) {
		rosterBody.innerHTML = '';
		if (!items.length) {
			rosterBody.appendChild(el('tr', null, [el('td', { colspan: 5, css: 'color:#999;font-style:italic;text-align:center;padding:24px;' }, ['No vendors yet — add one.'])]));
			return;
		}
		items.forEach(function (v) {
			var meta = v.meta || {};
			var social = countSocials(meta);
			var socialPill = el('span', {
				css: 'display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;' +
					(social >= 3 ? 'background:#e9f5e9;color:#2c7a2c;' :
					social >= 1 ? 'background:#fff3d6;color:#a86b00;' :
					'background:#fde2e2;color:#a52a2a;')
			}, [social + ' / 8']);

			var nameCell = el('td', null, [
				el('strong', null, [v.title || '(untitled)']),
				v.image ? el('img', { src: v.image, css: 'width:32px;height:32px;border-radius:50%;object-fit:cover;margin-right:8px;float:left;' }) : null,
			].filter(Boolean));

			var row = el('tr');
			row.appendChild(nameCell);
			row.appendChild(el('td', null, [el('code', null, [v.slug || ''])]));
			row.appendChild(el('td', null, [meta.specialty || el('span', { css: 'color:#999;', text: '—' })]));
			row.appendChild(el('td', null, [socialPill]));
			var actions = el('td');
			actions.appendChild(el('a', { class: 'button button-small', href: EDIT_URL + v.id }, ['Edit']));
			actions.appendChild(document.createTextNode(' '));
			if (v.link) actions.appendChild(el('a', { class: 'button button-small', href: v.link, target: '_blank', rel: 'noopener' }, ['View']));
			row.appendChild(actions);
			rosterBody.appendChild(row);
		});
	}

	function loadRoster() {
		api('vendors?limit=200').then(function (j) {
			// API returns "people" key (legacy) — accept either.
			var items = j.people || j.vendors || [];
			renderRoster(items);
			rosterStatusEl.textContent = (j.total || items.length) + ' total';
		}).catch(function (e) {
			rosterStatusEl.textContent = 'Roster failed: ' + e.message;
			rosterStatusEl.style.color = '#c33';
		});
	}

	loadRoster();
})();
</script>
