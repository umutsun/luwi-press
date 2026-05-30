<?php
/**
 * LuwiPress Commerce hub — agentic commerce surface.
 *
 * Tabs:
 *   • Overview     — UCP readiness checklist + eligibility coverage hero
 *   • Feed         — UCP feed settings (return policy / support / native_commerce)
 *                    + per-product lookup/toggle + supplemental feed preview
 *   • Checkout     — UCP Native Checkout API (phase 2)
 *   • AP2          — Agent Payments Protocol mandate verification (phase 3)
 *   • Transactions — mandate audit trail (phase 3)
 *
 * Design language: `lp-header` + `.luwipress-stat-card` + `.lp-pill` +
 * `.luwipress-card` from assets/css/admin.css — all colors via --lp-* tokens.
 *
 * @package LuwiPress
 * @since   3.5.9-dev
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
}

$lwp_ac_sub_raw = '';
if ( isset( $_GET['tab'] ) ) {
	$lwp_ac_sub_raw = sanitize_key( wp_unslash( $_GET['tab'] ) );
}
$active_tab = in_array( $lwp_ac_sub_raw, array( 'overview', 'feed', 'checkout', 'ap2', 'transactions' ), true )
	? $lwp_ac_sub_raw
	: 'overview';

$nonce    = wp_create_nonce( 'wp_rest' );
$rest_ucp = esc_url_raw( rest_url( 'luwipress/v1/ucp/' ) );
$rest_ap2 = esc_url_raw( rest_url( 'luwipress/v1/ap2/' ) );

$tab_url = function ( $tab ) {
	return esc_url( add_query_arg( array( 'page' => 'luwipress-commerce', 'tab' => $tab ), admin_url( 'admin.php' ) ) );
};
$tabs = array(
	'overview'     => array( 'label' => __( 'Overview', 'luwipress-agentic' ),     'icon' => 'dashicons-chart-area' ),
	'feed'         => array( 'label' => __( 'UCP Feed', 'luwipress-agentic' ),     'icon' => 'dashicons-rss' ),
	'checkout'     => array( 'label' => __( 'Checkout', 'luwipress-agentic' ),     'icon' => 'dashicons-cart' ),
	'ap2'          => array( 'label' => __( 'AP2', 'luwipress-agentic' ),          'icon' => 'dashicons-shield' ),
	'transactions' => array( 'label' => __( 'Transactions', 'luwipress-agentic' ), 'icon' => 'dashicons-list-view' ),
);

$lp_logo_url = defined( 'LUWIPRESS_PLUGIN_URL' ) ? LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo.png' : '';
?>
<div class="wrap luwipress-hub-wrap luwipress-commerce">

	<div class="lp-header">
		<div class="lp-header__brand">
			<?php if ( $lp_logo_url ) : ?>
				<img src="<?php echo esc_url( $lp_logo_url ); ?>" alt="LuwiPress" class="lp-header__logo" />
			<?php endif; ?>
			<div>
				<h1 class="lp-header__title"><?php esc_html_e( 'Agentic Commerce', 'luwipress-agentic' ); ?></h1>
				<p class="lp-header__subtitle"><?php esc_html_e( 'Google Universal Commerce Protocol (UCP) + Agent Payments Protocol (AP2)', 'luwipress-agentic' ); ?></p>
			</div>
		</div>
	</div>

	<nav class="lp-hub-tabs">
		<?php foreach ( $tabs as $slug => $meta ) :
			$is_active = ( $active_tab === $slug );
			?>
			<a href="<?php echo $tab_url( $slug ); // phpcs:ignore ?>" class="lp-hub-tab <?php echo $is_active ? 'is-active' : ''; ?>">
				<span class="dashicons <?php echo esc_attr( $meta['icon'] ); ?>"></span>
				<span class="lp-hub-tab__label"><?php echo esc_html( $meta['label'] ); ?></span>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="lp-hub-body">

	<?php if ( 'overview' === $active_tab ) : ?>
		<div id="lwp-ac-overview" class="luwipress-card" style="margin-top:16px;">
			<div class="luwipress-stat-grid" id="lwp-ac-stats">
				<div class="luwipress-stat-card stat-info"><div class="stat-value" id="lwp-ac-total">—</div><div class="stat-label"><?php esc_html_e( 'Published products', 'luwipress' ); ?></div></div>
				<div class="luwipress-stat-card stat-success"><div class="stat-value" id="lwp-ac-flagged">—</div><div class="stat-label"><?php esc_html_e( 'native_commerce flagged', 'luwipress' ); ?></div></div>
				<div class="luwipress-stat-card stat-translation"><div class="stat-value" id="lwp-ac-sample-elig">—</div><div class="stat-label"><?php esc_html_e( 'Eligible in sample', 'luwipress' ); ?></div></div>
				<div class="luwipress-stat-card stat-warning"><div class="stat-value" id="lwp-ac-mode">—</div><div class="stat-label"><?php esc_html_e( 'Mode', 'luwipress' ); ?></div></div>
			</div>
			<h3 style="margin-top:20px;"><?php esc_html_e( 'Readiness checklist', 'luwipress' ); ?></h3>
			<ul id="lwp-ac-checklist" class="luwipress-checklist"><li><?php esc_html_e( 'Loading…', 'luwipress' ); ?></li></ul>
			<p class="description"><?php esc_html_e( 'Sample-based breakdown of the most recent products. Configure the store-level prerequisites under the UCP Feed tab, then flag products native_commerce.', 'luwipress' ); ?></p>
			<div id="lwp-ac-breakdown"></div>
		</div>

	<?php elseif ( 'feed' === $active_tab ) : ?>
		<div class="luwipress-card" style="margin-top:16px;">
			<h3><?php esc_html_e( 'Store UCP settings', 'luwipress' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Merchant Center requires a return policy and support contact; these surface on the agentic checkout screen. Keep sandbox on until Google validates your integration.', 'luwipress' ); ?></p>
			<table class="form-table" role="presentation">
				<tr><th><?php esc_html_e( 'Enable UCP', 'luwipress' ); ?></th><td><label><input type="checkbox" id="ucp-enabled"> <?php esc_html_e( 'Master on/off', 'luwipress' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'Sandbox', 'luwipress' ); ?></th><td><label><input type="checkbox" id="ucp-sandbox"> <?php esc_html_e( 'Validate against Google sandbox (no live checkout)', 'luwipress' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'Default native_commerce', 'luwipress' ); ?></th><td><label><input type="checkbox" id="ucp-default-nc"> <?php esc_html_e( 'New products eligible by default', 'luwipress' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'Return cost', 'luwipress' ); ?></th><td><input type="text" id="ucp-return-cost" class="regular-text" placeholder="Free / 9.90 USD"></td></tr>
				<tr><th><?php esc_html_e( 'Return window (days)', 'luwipress' ); ?></th><td><input type="number" id="ucp-return-window" class="small-text" min="0"></td></tr>
				<tr><th><?php esc_html_e( 'Return policy URL', 'luwipress' ); ?></th><td><input type="url" id="ucp-return-url" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'Support email', 'luwipress' ); ?></th><td><input type="email" id="ucp-support-email" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'Support phone', 'luwipress' ); ?></th><td><input type="text" id="ucp-support-phone" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'Support URL', 'luwipress' ); ?></th><td><input type="url" id="ucp-support-url" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'Feed format', 'luwipress' ); ?></th><td><select id="ucp-feed-format"><option value="json">JSON</option><option value="csv">CSV</option><option value="xml">XML (g:)</option></select></td></tr>
			</table>
			<p><button class="button button-primary" id="ucp-save-settings"><?php esc_html_e( 'Save settings', 'luwipress' ); ?></button> <span id="ucp-save-status" class="description"></span></p>
		</div>

		<div class="luwipress-card" style="margin-top:16px;">
			<h3><?php esc_html_e( 'Product eligibility', 'luwipress' ); ?></h3>
			<p>
				<input type="number" id="ucp-pid" class="small-text" placeholder="<?php esc_attr_e( 'Product ID', 'luwipress' ); ?>">
				<button class="button" id="ucp-lookup"><?php esc_html_e( 'Look up', 'luwipress' ); ?></button>
			</p>
			<div id="ucp-product-panel" style="display:none;">
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'native_commerce', 'luwipress' ); ?></th><td><label><input type="checkbox" id="ucp-p-nc"> <?php esc_html_e( 'Eligible for UCP Buy button', 'luwipress' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'merchant_item_id', 'luwipress' ); ?></th><td><input type="text" id="ucp-p-itemid" class="regular-text" placeholder="<?php esc_attr_e( 'leave blank = product ID', 'luwipress' ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'consumer_notice', 'luwipress' ); ?></th><td><textarea id="ucp-p-notice" class="large-text" rows="2" placeholder="<?php esc_attr_e( 'Regulatory warning (e.g. Prop 65) — leave blank if none', 'luwipress' ); ?>"></textarea></td></tr>
				</table>
				<p><button class="button button-primary" id="ucp-p-save"><?php esc_html_e( 'Save product', 'luwipress' ); ?></button> <span id="ucp-p-verdict"></span></p>
				<div id="ucp-p-warnings"></div>
			</div>
		</div>

		<div class="luwipress-card" style="margin-top:16px;">
			<h3><?php esc_html_e( 'Supplemental feed preview', 'luwipress' ); ?></h3>
			<p class="description"><?php esc_html_e( 'A supplemental feed overlays your primary Merchant Center feed with the UCP signals only (id + native_commerce + consumer_notice).', 'luwipress' ); ?></p>
			<p>
				<button class="button" id="ucp-feed-preview"><?php esc_html_e( 'Preview eligible rows', 'luwipress' ); ?></button>
				<code id="ucp-feed-endpoint" style="margin-left:8px;"></code>
			</p>
			<pre id="ucp-feed-out" style="max-height:320px;overflow:auto;background:var(--lp-bg-alt,#f6f7f7);padding:12px;border-radius:6px;display:none;"></pre>
		</div>

	<?php elseif ( 'checkout' === $active_tab ) : ?>
		<div class="luwipress-card" style="margin-top:16px;">
			<h3><?php esc_html_e( 'UCP Native Checkout — session tester', 'luwipress' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Three UCP endpoints (session create / update / complete) backed by a WooCommerce draft order — totals, tax and shipping come from WooCommerce itself. Keep sandbox ON (UCP Feed tab) while testing: completion is simulated, no payable order is created.', 'luwipress' ); ?></p>

			<h4><?php esc_html_e( '1. Create session', 'luwipress' ); ?></h4>
			<p>
				<input type="text" id="cko-items" class="regular-text" placeholder="123:1, 456:2">
				<button class="button" id="cko-create"><?php esc_html_e( 'Create', 'luwipress' ); ?></button>
				<span class="description"><?php esc_html_e( 'product_id:qty pairs', 'luwipress' ); ?></span>
			</p>

			<h4><?php esc_html_e( '2. Address + shipping', 'luwipress' ); ?></h4>
			<p>
				<input type="text" id="cko-country" class="small-text" placeholder="Country (US)">
				<input type="text" id="cko-state" class="small-text" placeholder="State">
				<input type="text" id="cko-postcode" class="small-text" placeholder="Postcode">
				<input type="text" id="cko-city" class="small-text" placeholder="City">
				<button class="button" id="cko-update"><?php esc_html_e( 'Update / get shipping', 'luwipress' ); ?></button>
			</p>
			<p id="cko-ship-wrap" style="display:none;">
				<label><?php esc_html_e( 'Shipping', 'luwipress' ); ?> <select id="cko-shipping"></select></label>
				<button class="button" id="cko-pick-ship"><?php esc_html_e( 'Apply rate', 'luwipress' ); ?></button>
			</p>

			<h4><?php esc_html_e( '3. Complete', 'luwipress' ); ?></h4>
			<p>
				<input type="email" id="cko-email" class="regular-text" placeholder="buyer@example.com">
				<button class="button button-primary" id="cko-complete"><?php esc_html_e( 'Complete', 'luwipress' ); ?></button>
			</p>

			<p><strong><?php esc_html_e( 'Session', 'luwipress' ); ?>:</strong> <code id="cko-sid">—</code> <span id="cko-status"></span></p>
			<pre id="cko-out" style="max-height:340px;overflow:auto;background:var(--lp-bg-alt,#f6f7f7);padding:12px;border-radius:6px;display:none;"></pre>
		</div>

	<?php elseif ( 'ap2' === $active_tab ) : ?>
		<div class="luwipress-card" style="margin-top:16px;">
			<h3><?php esc_html_e( 'Agent Payments Protocol (AP2)', 'luwipress' ); ?></h3>
			<p class="description"><?php esc_html_e( 'When an agent presents a Cart Mandate during checkout, LuwiPress verifies it (structure, expiry, issuer, amount-match) and stores the Intent → Cart mandate chain on the order as a non-repudiable audit trail. Cryptographic signature verification plugs in via the luwipress_ap2_verify_mandate filter.', 'luwipress' ); ?></p>
			<table class="form-table" role="presentation">
				<tr><th><?php esc_html_e( 'Enable AP2', 'luwipress' ); ?></th><td><label><input type="checkbox" id="ap2-enabled"> <?php esc_html_e( 'Accept + record mandates', 'luwipress' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'Strict verification', 'luwipress' ); ?></th><td><label><input type="checkbox" id="ap2-require"> <?php esc_html_e( 'Abort checkout if mandate is unverified or amount mismatches', 'luwipress' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'Amount match', 'luwipress' ); ?></th><td><label><input type="checkbox" id="ap2-amount"> <?php esc_html_e( 'Require Cart Mandate total = order total', 'luwipress' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'Allowed issuers', 'luwipress' ); ?></th><td><input type="text" id="ap2-issuers" class="regular-text" placeholder="comma,separated (blank = any)"></td></tr>
				<tr><th><?php esc_html_e( 'Issuer JWKS URL', 'luwipress' ); ?></th><td><input type="url" id="ap2-jwks" class="regular-text" placeholder="optional — for a signature verifier"></td></tr>
			</table>
			<p><button class="button button-primary" id="ap2-save"><?php esc_html_e( 'Save settings', 'luwipress' ); ?></button> <span id="ap2-save-status" class="description"></span></p>
		</div>
		<div class="luwipress-card" style="margin-top:16px;">
			<h3><?php esc_html_e( 'Mandate verifier (diagnostic)', 'luwipress' ); ?></h3>
			<p><textarea id="ap2-mandate" class="large-text code" rows="6" placeholder='{"issuer":"...","cart":{"total":99.90,"currency":"USD"},"exp":1830000000}'></textarea></p>
			<p><button class="button" id="ap2-verify"><?php esc_html_e( 'Verify', 'luwipress' ); ?></button></p>
			<pre id="ap2-verify-out" style="max-height:280px;overflow:auto;background:var(--lp-bg-alt,#f6f7f7);padding:12px;border-radius:6px;display:none;"></pre>
		</div>

	<?php else : ?>
		<div class="luwipress-card" style="margin-top:16px;">
			<h3><?php esc_html_e( 'Mandate audit trail', 'luwipress' ); ?></h3>
			<p>
				<input type="number" id="tx-oid" class="small-text" placeholder="<?php esc_attr_e( 'Order ID', 'luwipress' ); ?>">
				<button class="button" id="tx-lookup"><?php esc_html_e( 'Look up order', 'luwipress' ); ?></button>
			</p>
			<pre id="tx-out" style="max-height:300px;overflow:auto;background:var(--lp-bg-alt,#f6f7f7);padding:12px;border-radius:6px;display:none;"></pre>
			<h4 style="margin-top:20px;"><?php esc_html_e( 'Recent verifications', 'luwipress' ); ?></h4>
			<table class="widefat striped" id="tx-log"><thead><tr><th><?php esc_html_e( 'Order', 'luwipress' ); ?></th><th><?php esc_html_e( 'Status', 'luwipress' ); ?></th><th><?php esc_html_e( 'Issuer', 'luwipress' ); ?></th><th><?php esc_html_e( 'Amount match', 'luwipress' ); ?></th><th><?php esc_html_e( 'At', 'luwipress' ); ?></th></tr></thead><tbody><tr><td colspan="5"><?php esc_html_e( 'Loading…', 'luwipress' ); ?></td></tr></tbody></table>
		</div>
	<?php endif; ?>

</div>

<script>
(function () {
	const REST     = <?php echo wp_json_encode( $rest_ucp ); ?>;
	const REST_AP2 = <?php echo wp_json_encode( $rest_ap2 ); ?>;
	const NONCE    = <?php echo wp_json_encode( $nonce ); ?>;
	const TAB      = <?php echo wp_json_encode( $active_tab ); ?>;

	function call(base, path, method, body) {
		const opts = { method: method || 'GET', headers: { 'X-WP-Nonce': NONCE } };
		if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
		return fetch(base + path, opts).then(function (r) {
			const ct = r.headers.get('content-type') || '';
			return ct.indexOf('application/json') !== -1 ? r.json().then(function (j) { return { ok: r.ok, data: j }; })
				: r.text().then(function (t) { return { ok: r.ok, data: t }; });
		});
	}
	function api(path, method, body)  { return call(REST, path, method, body); }
	function api2(path, method, body) { return call(REST_AP2, path, method, body); }
	function $(id) { return document.getElementById(id); }

	if (TAB === 'overview') {
		api('eligibility').then(function (res) {
			const d = res.data || {};
			if (d.wc_active === false) { $('lwp-ac-checklist').innerHTML = '<li>WooCommerce is not active.</li>'; return; }
			$('lwp-ac-total').textContent  = d.total_published_products != null ? d.total_published_products : '0';
			$('lwp-ac-flagged').textContent = d.native_commerce_flagged != null ? d.native_commerce_flagged : '0';
			const s = d.sample || {};
			$('lwp-ac-sample-elig').textContent = (s.eligible != null ? s.eligible : 0) + ' / ' + (s.size != null ? s.size : 0);
			$('lwp-ac-mode').textContent = d.enabled ? (d.sandbox ? 'Sandbox' : 'Live') : 'Off';

			const checks = [
				['UCP enabled', !!d.enabled],
				['Return policy configured', !!d.return_policy_configured],
				['Support info configured', !!d.support_info_configured],
				['At least one native_commerce product', (d.native_commerce_flagged || d.default_native_commerce) ? true : false]
			];
			$('lwp-ac-checklist').innerHTML = checks.map(function (c) {
				return '<li>' + (c[1] ? '✅ ' : '⬜ ') + c[0] + '</li>';
			}).join('');

			const b = (d.sample && d.sample.warning_breakdown) || {};
			const keys = Object.keys(b);
			$('lwp-ac-breakdown').innerHTML = keys.length
				? '<h4>Sample warnings</h4><ul>' + keys.map(function (k) { return '<li><code>' + k + '</code>: ' + b[k] + '</li>'; }).join('') + '</ul>'
				: '';
		});
	}

	if (TAB === 'feed') {
		$('ucp-feed-endpoint').textContent = REST + 'feed';

		api('settings').then(function (res) {
			const d = res.data || {};
			$('ucp-enabled').checked      = !!d.enabled;
			$('ucp-sandbox').checked      = !!d.sandbox;
			$('ucp-default-nc').checked   = !!d.default_native_commerce;
			$('ucp-return-cost').value    = d.return_cost || '';
			$('ucp-return-window').value  = d.return_window_days || 0;
			$('ucp-return-url').value     = d.return_policy_url || '';
			$('ucp-support-email').value  = d.support_email || '';
			$('ucp-support-phone').value  = d.support_phone || '';
			$('ucp-support-url').value    = d.support_url || '';
			$('ucp-feed-format').value    = d.feed_format || 'json';
		});

		$('ucp-save-settings').addEventListener('click', function () {
			$('ucp-save-status').textContent = 'Saving…';
			api('settings', 'POST', {
				enabled: $('ucp-enabled').checked,
				sandbox: $('ucp-sandbox').checked,
				default_native_commerce: $('ucp-default-nc').checked,
				return_cost: $('ucp-return-cost').value,
				return_window_days: parseInt($('ucp-return-window').value || '0', 10),
				return_policy_url: $('ucp-return-url').value,
				support_email: $('ucp-support-email').value,
				support_phone: $('ucp-support-phone').value,
				support_url: $('ucp-support-url').value,
				feed_format: $('ucp-feed-format').value
			}).then(function (res) {
				$('ucp-save-status').textContent = res.ok ? 'Saved ✓' : 'Error';
			});
		});

		let curPid = 0;
		function renderProfile(p) {
			curPid = p.product_id;
			$('ucp-product-panel').style.display = 'block';
			$('ucp-p-nc').checked    = !!p.native_commerce;
			$('ucp-p-itemid').value  = p.id_mapped ? p.merchant_item_id : '';
			$('ucp-p-notice').value  = p.consumer_notice || '';
			$('ucp-p-verdict').innerHTML = p.eligible
				? '<span class="lp-pill pill-success">Eligible</span>'
				: '<span class="lp-pill pill-warning">Not eligible</span>';
			const w = p.warnings || [];
			$('ucp-p-warnings').innerHTML = w.length
				? '<ul>' + w.map(function (x) { return '<li><code>' + x.severity + '</code> ' + x.message + '</li>'; }).join('') + '</ul>'
				: '<p class="description">No blocking issues.</p>';
		}
		$('ucp-lookup').addEventListener('click', function () {
			const pid = parseInt($('ucp-pid').value || '0', 10);
			if (!pid) { return; }
			api('product/' + pid).then(function (res) {
				if (res.ok) { renderProfile(res.data); }
				else { $('ucp-product-panel').style.display = 'block'; $('ucp-p-warnings').innerHTML = '<p>' + ((res.data && res.data.message) || 'Not found') + '</p>'; }
			});
		});
		$('ucp-p-save').addEventListener('click', function () {
			if (!curPid) { return; }
			api('product/' + curPid, 'POST', {
				native_commerce: $('ucp-p-nc').checked,
				merchant_item_id: $('ucp-p-itemid').value,
				consumer_notice: $('ucp-p-notice').value
			}).then(function (res) { if (res.ok) { renderProfile(res.data); } });
		});

		$('ucp-feed-preview').addEventListener('click', function () {
			api('feed?format=json&include=eligible&limit=50').then(function (res) {
				$('ucp-feed-out').style.display = 'block';
				$('ucp-feed-out').textContent = JSON.stringify(res.data, null, 2);
			});
		});
	}

	if (TAB === 'checkout') {
		let sid = '';
		function show(res) {
			$('cko-out').style.display = 'block';
			$('cko-out').textContent = JSON.stringify(res.data, null, 2);
			if (res.data && res.data.session_id) {
				sid = res.data.session_id;
				$('cko-sid').textContent = sid;
				$('cko-status').innerHTML = '<span class="lp-pill ' + (res.data.completed ? 'pill-success' : 'pill-info') + '">' + (res.data.status || '') + (res.data.simulated ? ' (sandbox)' : '') + '</span>';
			}
			const opts = (res.data && res.data.shipping_options) || [];
			if (opts.length) {
				$('cko-ship-wrap').style.display = 'block';
				$('cko-shipping').innerHTML = opts.map(function (o) { return '<option value="' + o.id + '">' + o.label + ' — ' + o.cost + '</option>'; }).join('');
			}
		}
		function parseItems() {
			return ($('cko-items').value || '').split(',').map(function (p) {
				const x = p.trim().split(':');
				if (!x[0]) { return null; }
				return { product_id: parseInt(x[0], 10), quantity: parseInt(x[1] || '1', 10) };
			}).filter(Boolean);
		}
		function addr() {
			return { country: $('cko-country').value, state: $('cko-state').value, postcode: $('cko-postcode').value, city: $('cko-city').value };
		}
		$('cko-create').addEventListener('click', function () {
			api('checkout/session', 'POST', { items: parseItems() }).then(show);
		});
		$('cko-update').addEventListener('click', function () {
			if (!sid) { return; }
			api('checkout/session/' + sid, 'POST', { shipping_address: addr() }).then(show);
		});
		$('cko-pick-ship').addEventListener('click', function () {
			if (!sid) { return; }
			api('checkout/session/' + sid, 'POST', { selected_shipping: $('cko-shipping').value }).then(show);
		});
		$('cko-complete').addEventListener('click', function () {
			if (!sid) { return; }
			api('checkout/session/' + sid + '/complete', 'POST', { buyer: { email: $('cko-email').value } }).then(show);
		});
	}

	if (TAB === 'ap2') {
		api2('settings').then(function (res) {
			const d = res.data || {};
			$('ap2-enabled').checked = !!d.enabled;
			$('ap2-require').checked  = !!d.require_verification;
			$('ap2-amount').checked   = !!d.amount_match;
			$('ap2-issuers').value    = d.allowed_issuers || '';
			$('ap2-jwks').value       = d.issuer_jwks_url || '';
		});
		$('ap2-save').addEventListener('click', function () {
			$('ap2-save-status').textContent = 'Saving…';
			api2('settings', 'POST', {
				enabled: $('ap2-enabled').checked,
				require_verification: $('ap2-require').checked,
				amount_match: $('ap2-amount').checked,
				allowed_issuers: $('ap2-issuers').value,
				issuer_jwks_url: $('ap2-jwks').value
			}).then(function (res) { $('ap2-save-status').textContent = res.ok ? 'Saved ✓' : 'Error'; });
		});
		$('ap2-verify').addEventListener('click', function () {
			let mandate;
			try { mandate = JSON.parse($('ap2-mandate').value || '{}'); }
			catch (e) { $('ap2-verify-out').style.display = 'block'; $('ap2-verify-out').textContent = 'Invalid JSON: ' + e.message; return; }
			api2('mandate/verify', 'POST', { mandate: mandate }).then(function (res) {
				$('ap2-verify-out').style.display = 'block';
				$('ap2-verify-out').textContent = JSON.stringify(res.data, null, 2);
			});
		});
	}

	if (TAB === 'transactions') {
		function loadLog() {
			api2('log?limit=50').then(function (res) {
				const rows = (res.data && res.data.entries) || [];
				const tb = $('tx-log').querySelector('tbody');
				tb.innerHTML = rows.length ? rows.map(function (e) {
					const m = e.amount_match === true ? '✅' : (e.amount_match === false ? '❌' : '—');
					return '<tr><td>' + (e.order_id || '') + '</td><td>' + (e.status || '') + '</td><td>' + (e.issuer || '') + '</td><td>' + m + '</td><td>' + (e.at || '') + '</td></tr>';
				}).join('') : '<tr><td colspan="5">No mandate activity yet.</td></tr>';
			});
		}
		$('tx-lookup').addEventListener('click', function () {
			const oid = parseInt($('tx-oid').value || '0', 10);
			if (!oid) { return; }
			api2('transaction/' + oid).then(function (res) {
				$('tx-out').style.display = 'block';
				$('tx-out').textContent = JSON.stringify(res.data, null, 2);
			});
		});
		loadLog();
	}
})();
</script>
