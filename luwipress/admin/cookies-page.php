<?php
/**
 * LuwiPress Cookie Consent admin page
 *
 * Three tabs:
 *   • Settings — toggle, mode, position, theme, URLs, text overrides
 *   • Log      — paginated consent records
 *   • Policy   — AI-generated cookie policy paragraph (copy/paste into your page)
 *
 * @package LuwiPress
 * @since   3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
}

$cc       = LuwiPress_Cookie_Consent::get_instance();
$settings = $cc->get_settings();
$stats    = $cc->get_stats();

$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
if ( ! in_array( $active_tab, array( 'settings', 'log', 'policy' ), true ) ) {
	$active_tab = 'settings';
}

$nonce     = wp_create_nonce( 'wp_rest' );
$rest_root = esc_url_raw( rest_url( 'luwipress/v1/cookies/' ) );
?>
<div class="wrap luwipress-admin luwipress-dashboard">

	<div class="lp-header">
		<div class="lp-header-left">
			<h1 class="lp-title">
				<img class="lp-logo" width="28" height="28" src="<?php echo esc_url( LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo.png' ); ?>" alt="LuwiPress" />
				<?php esc_html_e( 'Cookie Consent', 'luwipress' ); ?>
			</h1>
			<p class="lp-subtitle">
				<?php esc_html_e( 'GDPR/ePrivacy compliant cookie banner + consent log + AI policy generator.', 'luwipress' ); ?>
			</p>
		</div>
		<div class="lp-header-actions">
			<?php if ( $settings['enabled'] ) : ?>
				<span class="lp-pill pill-success"><?php esc_html_e( 'Enabled', 'luwipress' ); ?></span>
			<?php else : ?>
				<span class="lp-pill pill-warning"><?php esc_html_e( 'Disabled', 'luwipress' ); ?></span>
			<?php endif; ?>
			<span class="lp-pill pill-neutral"><?php echo esc_html( strtoupper( $settings['mode'] ) ); ?></span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress' ) ); ?>" class="lp-pill pill-neutral lp-pill--icon">
				<span class="dashicons dashicons-admin-home"></span>
			</a>
		</div>
	</div>

	<nav class="nav-tab-wrapper luwipress-tabs">
		<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'luwipress-cookies', 'tab' => 'settings' ), admin_url( 'admin.php' ) ) ); ?>"
		   class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'luwipress' ); ?></a>
		<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'luwipress-cookies', 'tab' => 'log' ), admin_url( 'admin.php' ) ) ); ?>"
		   class="nav-tab <?php echo $active_tab === 'log' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Log', 'luwipress' ); ?></a>
		<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'luwipress-cookies', 'tab' => 'policy' ), admin_url( 'admin.php' ) ) ); ?>"
		   class="nav-tab <?php echo $active_tab === 'policy' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'AI Policy', 'luwipress' ); ?></a>
	</nav>

	<?php if ( $active_tab === 'settings' ) : ?>

		<div class="luwipress-stat-grid" style="margin-top:16px;">
			<div class="luwipress-stat-card stat-success">
				<div class="stat-value"><?php echo number_format_i18n( $stats['total'] ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Total consents', 'luwipress' ); ?></div>
			</div>
			<div class="luwipress-stat-card stat-translation">
				<div class="stat-value"><?php echo number_format_i18n( $stats['last_30_days'] ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Last 30 days', 'luwipress' ); ?></div>
			</div>
			<div class="luwipress-stat-card stat-success">
				<div class="stat-value"><?php echo (int) round( ( $stats['category_rate']['analytics'] ?? 0 ) * 100 ); ?>%</div>
				<div class="stat-label"><?php esc_html_e( 'Analytics accept-rate', 'luwipress' ); ?></div>
			</div>
			<div class="luwipress-stat-card stat-warning">
				<div class="stat-value"><?php echo (int) round( ( $stats['category_rate']['marketing'] ?? 0 ) * 100 ); ?>%</div>
				<div class="stat-label"><?php esc_html_e( 'Marketing accept-rate', 'luwipress' ); ?></div>
			</div>
		</div>

		<div class="luwipress-section" style="margin-top:20px;">
			<form id="lwp-cc-settings-form" style="max-width:720px;">
				<table class="form-table">
					<tr>
						<th><label for="cc-enabled"><?php esc_html_e( 'Enable cookie banner', 'luwipress' ); ?></label></th>
						<td>
							<label><input type="checkbox" id="cc-enabled" <?php checked( $settings['enabled'] ); ?> /> <?php esc_html_e( 'Show the consent banner to frontend visitors.', 'luwipress' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><label for="cc-mode"><?php esc_html_e( 'Mode', 'luwipress' ); ?></label></th>
						<td>
							<select id="cc-mode">
								<option value="opt-in"  <?php selected( $settings['mode'], 'opt-in' ); ?>><?php esc_html_e( 'Opt-in (EU default — strict)', 'luwipress' ); ?></option>
								<option value="opt-out" <?php selected( $settings['mode'], 'opt-out' ); ?>><?php esc_html_e( 'Opt-out (US default — cookies fire by default)', 'luwipress' ); ?></option>
								<option value="info"    <?php selected( $settings['mode'], 'info' ); ?>><?php esc_html_e( 'Info only (no consent collected)', 'luwipress' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="cc-position"><?php esc_html_e( 'Position', 'luwipress' ); ?></label></th>
						<td>
							<select id="cc-position">
								<option value="bottom"        <?php selected( $settings['position'], 'bottom' ); ?>><?php esc_html_e( 'Bottom (centered)', 'luwipress' ); ?></option>
								<option value="top"           <?php selected( $settings['position'], 'top' ); ?>><?php esc_html_e( 'Top (centered)', 'luwipress' ); ?></option>
								<option value="bottom-left"   <?php selected( $settings['position'], 'bottom-left' ); ?>><?php esc_html_e( 'Bottom left', 'luwipress' ); ?></option>
								<option value="bottom-right"  <?php selected( $settings['position'], 'bottom-right' ); ?>><?php esc_html_e( 'Bottom right', 'luwipress' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="cc-theme"><?php esc_html_e( 'Theme', 'luwipress' ); ?></label></th>
						<td>
							<select id="cc-theme">
								<option value="auto"  <?php selected( $settings['theme'], 'auto' ); ?>><?php esc_html_e( 'Auto (follow OS)', 'luwipress' ); ?></option>
								<option value="light" <?php selected( $settings['theme'], 'light' ); ?>><?php esc_html_e( 'Light', 'luwipress' ); ?></option>
								<option value="dark"  <?php selected( $settings['theme'], 'dark' ); ?>><?php esc_html_e( 'Dark', 'luwipress' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="cc-reject"><?php esc_html_e( 'Reject button', 'luwipress' ); ?></label></th>
						<td>
							<label><input type="checkbox" id="cc-reject" <?php checked( $settings['show_reject_button'] ); ?> /> <?php esc_html_e( 'Show "Reject non-essential" button next to Accept (required for GDPR equality).', 'luwipress' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><label for="cc-prefs"><?php esc_html_e( 'Preferences modal', 'luwipress' ); ?></label></th>
						<td>
							<label><input type="checkbox" id="cc-prefs" <?php checked( $settings['show_preferences'] ); ?> /> <?php esc_html_e( 'Show "Preferences" link for per-category control.', 'luwipress' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><label for="cc-policy-url"><?php esc_html_e( 'Cookie policy URL', 'luwipress' ); ?></label></th>
						<td><input type="url" id="cc-policy-url" value="<?php echo esc_attr( $settings['policy_url'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="cc-privacy-url"><?php esc_html_e( 'Privacy policy URL', 'luwipress' ); ?></label></th>
						<td><input type="url" id="cc-privacy-url" value="<?php echo esc_attr( $settings['privacy_url'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="cc-retention"><?php esc_html_e( 'Log retention (days)', 'luwipress' ); ?></label></th>
						<td><input type="number" id="cc-retention" value="<?php echo (int) $settings['log_retention_days']; ?>" min="30" max="3650" /></td>
					</tr>
				</table>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'luwipress' ); ?></button>
					<span id="lwp-cc-status" style="margin-left:12px; color:var(--lp-text-muted);"></span>
				</p>
			</form>

			<h3 style="margin-top:32px;"><?php esc_html_e( 'How to block third-party scripts', 'luwipress' ); ?></h3>
			<p><?php esc_html_e( 'Wrap any analytics/marketing tag in a placeholder script. After the visitor consents to that category, the script is automatically rewritten and executed.', 'luwipress' ); ?></p>
			<pre style="background:var(--lp-bg-muted,#f4f4f4); padding:12px; border-radius:6px; overflow:auto;"><code>&lt;script type="text/plain" data-luwipress-consent="analytics"&gt;
  // your GA4 / Plausible / Matomo snippet here
&lt;/script&gt;

&lt;script type="text/plain" data-luwipress-consent="marketing"
        data-src="https://connect.facebook.net/en_US/fbevents.js"&gt;&lt;/script&gt;</code></pre>
		</div>

	<?php elseif ( $active_tab === 'log' ) : ?>

		<div class="luwipress-section" style="margin-top:16px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Consent log', 'luwipress' ); ?></h2>
			<p class="description"><?php esc_html_e( 'IP addresses are stored as one-way salted hashes. Each row proves a single visitor decision.', 'luwipress' ); ?></p>
			<table class="wp-list-table widefat fixed striped" id="lwp-cc-log-table">
				<thead><tr>
					<th><?php esc_html_e( 'When', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Source', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Choices', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Country', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Language', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Consent ID', 'luwipress' ); ?></th>
				</tr></thead>
				<tbody id="lwp-cc-log-tbody">
					<tr><td colspan="6" style="text-align:center; padding:24px;"><?php esc_html_e( 'Loading…', 'luwipress' ); ?></td></tr>
				</tbody>
			</table>
		</div>

	<?php else: /* policy */ ?>

		<div class="luwipress-section" style="margin-top:16px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'AI cookie policy generator', 'luwipress' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Generate a plain-language policy paragraph customized to the third-party tags actually detected on this site. Paste the result into your Cookie Policy page.', 'luwipress' ); ?></p>
			<p>
				<button id="lwp-cc-gen-btn" class="button button-primary">
					<span class="dashicons dashicons-art" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Generate policy text', 'luwipress' ); ?>
				</button>
				<span id="lwp-cc-gen-status" style="margin-left:12px; color:var(--lp-text-muted);"></span>
			</p>
			<textarea id="lwp-cc-policy-text" rows="14" style="width:100%; max-width:880px; font-family:Georgia,serif; font-size:14px; line-height:1.6;"></textarea>
			<p class="description"><?php esc_html_e( 'Detected third-party tags appear in the JSON block on the right after generation.', 'luwipress' ); ?></p>
			<pre id="lwp-cc-policy-detected" style="background:var(--lp-bg-muted,#f4f4f4); padding:12px; border-radius:6px; max-width:880px; overflow:auto;"></pre>
		</div>

	<?php endif; ?>

</div>

<script>
(function () {
	var REST = <?php echo wp_json_encode( $rest_root ); ?>;
	var NONCE = <?php echo wp_json_encode( $nonce ); ?>;

	function api(path, opts) {
		opts = opts || {};
		opts.headers = Object.assign({ 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE }, opts.headers || {});
		opts.credentials = 'same-origin';
		return fetch(REST + path, opts).then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); });
	}

	// Settings save
	var f = document.getElementById('lwp-cc-settings-form');
	if (f) {
		f.addEventListener('submit', function (e) {
			e.preventDefault();
			var payload = {
				enabled: document.getElementById('cc-enabled').checked,
				mode:    document.getElementById('cc-mode').value,
				position:document.getElementById('cc-position').value,
				theme:   document.getElementById('cc-theme').value,
				show_reject_button: document.getElementById('cc-reject').checked,
				show_preferences:   document.getElementById('cc-prefs').checked,
				policy_url:  document.getElementById('cc-policy-url').value,
				privacy_url: document.getElementById('cc-privacy-url').value,
				log_retention_days: parseInt(document.getElementById('cc-retention').value, 10)
			};
			document.getElementById('lwp-cc-status').textContent = '<?php echo esc_js( __( 'Saving…', 'luwipress' ) ); ?>';
			api('settings', { method: 'POST', body: JSON.stringify(payload) }).then(function (r) {
				document.getElementById('lwp-cc-status').textContent = r.ok ? '<?php echo esc_js( __( 'Saved.', 'luwipress' ) ); ?>' : '<?php echo esc_js( __( 'Save failed.', 'luwipress' ) ); ?>';
			});
		});
	}

	// Log table
	var tbody = document.getElementById('lwp-cc-log-tbody');
	if (tbody) {
		api('log?per_page=100').then(function (r) {
			if (!r.ok || !r.data.items || !r.data.items.length) {
				tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:24px;"><?php echo esc_js( __( 'No consent records yet.', 'luwipress' ) ); ?></td></tr>';
				return;
			}
			tbody.innerHTML = r.data.items.map(function (it) {
				var c = it.choices;
				var muted = '<span style="color:var(--lp-text-muted);">—</span>';
				var pills = muted;
				if (c && typeof c === 'object' && !Array.isArray(c)) {
					var keys = Object.keys(c);
					// Guard against legacy/malformed rows where choices was stored as
					// a JSON-encoded string (numeric-only keys = character indices).
					var isLegacy = keys.length > 6 || (keys.length > 0 && keys.every(function (k) { return /^\d+$/.test(k); }));
					if (isLegacy) {
						pills = '<span class="lp-pill pill-neutral" style="font-size:10px; opacity:.65;">legacy</span>';
					} else {
						// Skip 'necessary' — always on, not informative.
						var meaningful = keys.filter(function (k) { return k !== 'necessary'; });
						var accepted = meaningful.filter(function (k) { return c[k]; });
						var rejected = meaningful.filter(function (k) { return !c[k]; });
						if (!meaningful.length) {
							pills = muted;
						} else if (accepted.length === meaningful.length) {
							pills = '<span class="lp-pill pill-success" style="font-size:10px; padding:2px 8px;">all accepted</span>';
						} else if (rejected.length === meaningful.length) {
							pills = '<span class="lp-pill pill-warning" style="font-size:10px; padding:2px 8px;">all rejected</span>';
						} else {
							pills = '<div style="display:flex; flex-wrap:wrap; gap:4px;">' + accepted.map(function (k) {
								return '<span class="lp-pill pill-success" style="font-size:10px; padding:2px 8px;">' + k + '</span>';
							}).join('') + '</div>';
						}
					}
				}
				return '<tr>' +
					'<td>' + it.created_at + '</td>' +
					'<td>' + it.source + '</td>' +
					'<td>' + pills + '</td>' +
					'<td>' + (it.country_code || '') + '</td>' +
					'<td>' + (it.language || '') + '</td>' +
					'<td><code style="font-size:11px;">' + (it.consent_id || '').substr(0, 8) + '…</code></td>' +
					'</tr>';
			}).join('');
		});
	}

	// Policy generator
	var gen = document.getElementById('lwp-cc-gen-btn');
	if (gen) {
		gen.addEventListener('click', function () {
			var status = document.getElementById('lwp-cc-gen-status');
			status.textContent = '<?php echo esc_js( __( 'Generating with AI…', 'luwipress' ) ); ?>';
			api('policy-text', { method: 'POST', body: JSON.stringify({}) }).then(function (r) {
				if (!r.ok) { status.textContent = '<?php echo esc_js( __( 'Failed.', 'luwipress' ) ); ?>'; return; }
				document.getElementById('lwp-cc-policy-text').value = r.data.text || '';
				document.getElementById('lwp-cc-policy-detected').textContent = JSON.stringify(r.data.detected || {}, null, 2);
				status.textContent = '<?php echo esc_js( __( 'Done.', 'luwipress' ) ); ?>';
			});
		});
	}
})();
</script>
