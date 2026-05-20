<?php
/**
 * LuwiPress Bot Defense — unified admin surface for Bot Accounts + Bot Shield.
 *
 * Tabs:
 *   • Overview — combined health (flagged accounts, active blocks, denials today, delegation)
 *   • Accounts — score-based fake-user detection + safe deletion (tier filter)
 *   • Shield   — UA blocklist + rate limit + honeypot + REST/XML-RPC + active blocks
 *   • Settings — both modules' settings + allowlist in one form panel
 *
 * Back-compat: the old menu slugs `luwipress-bot-accounts` + `luwipress-bot-shield`
 * are still valid URLs and include this file with $active_tab preselected.
 *
 * Design language: `lp-header` + `.luwipress-stat-card` + `.lp-pill` from
 * assets/css/admin.css — all colors flow through --lp-* tokens.
 *
 * @package LuwiPress
 * @since   3.2.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
}

$cleaner  = LuwiPress_Bot_Account_Cleaner::get_instance();
$shield   = LuwiPress_Bot_Shield::get_instance();

$acct_stats    = $cleaner->get_stats();
$acct_settings = $cleaner->get_settings();
$sh_stats      = $shield->get_stats();
$sh_settings   = $shield->get_settings();

// Resolve active tab from query OR from legacy slug entry point.
$page_slug  = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'luwipress-bot-defense';
$default    = 'overview';
if ( $page_slug === 'luwipress-bot-accounts' ) {
	$default = 'accounts';
} elseif ( $page_slug === 'luwipress-bot-shield' ) {
	$default = 'shield';
}
$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : $default;
if ( ! in_array( $active_tab, array( 'overview', 'accounts', 'shield' ), true ) ) {
	$active_tab = 'overview';
}

// All configuration moved to the main Settings page (Bot sub-tab) so operators
// have a single place to tune everything.
$settings_url = admin_url( 'admin.php?page=luwipress-settings&tab=bot' );

$nonce        = wp_create_nonce( 'wp_rest' );
$rest_acct    = esc_url_raw( rest_url( 'luwipress/v1/bot-accounts/' ) );
$rest_shield  = esc_url_raw( rest_url( 'luwipress/v1/bot-shield/' ) );

$flagged       = (int) $acct_stats['flagged'];
$buckets       = $acct_stats['buckets'];
$active_blocks = (int) $sh_stats['active_blocks'];
$today_denials = (int) $sh_stats['today_denials'];

// Severity → stat-card accent helper (shared by both modules).
$state_class = function ( $count, $warn = 10, $err = 100 ) {
	if ( $count >= $err )  return 'stat-error';
	if ( $count >= $warn ) return 'stat-warning';
	if ( $count > 0 )      return 'stat-translation';
	return 'stat-success';
};

$tab_url = function ( $tab ) {
	return add_query_arg( array( 'page' => 'luwipress-bot-defense', 'tab' => $tab ), admin_url( 'admin.php' ) );
};
?>
<div class="wrap luwipress-admin luwipress-dashboard luwipress-bot-defense-page">

	<div class="lp-header">
		<div class="lp-header-left">
			<h1 class="lp-title">
				<img class="lp-logo" width="28" height="28" src="<?php echo esc_url( LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo.png' ); ?>" alt="LuwiPress" />
				<?php esc_html_e( 'Bot Defense', 'luwipress' ); ?>
			</h1>
			<p class="lp-subtitle">
				<?php esc_html_e( 'Unified surface for fake-account cleanup and front-edge scraper/brute-force filtering.', 'luwipress' ); ?>
			</p>
		</div>
		<div class="lp-header-actions">
			<?php if ( $sh_settings['enabled'] ) : ?>
				<span class="lp-pill pill-success" title="<?php esc_attr_e( 'Shield active', 'luwipress' ); ?>">
					<span class="dashicons dashicons-shield-alt" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Shield on', 'luwipress' ); ?>
				</span>
			<?php else : ?>
				<span class="lp-pill pill-warning" title="<?php esc_attr_e( 'Shield disabled', 'luwipress' ); ?>">
					<?php esc_html_e( 'Shield off', 'luwipress' ); ?>
				</span>
			<?php endif; ?>
			<span class="lp-pill pill-neutral" title="<?php esc_attr_e( 'Accounts flagged at current threshold', 'luwipress' ); ?>">
				<?php
				/* translators: %d: count */
				printf( esc_html__( '%d flagged accounts', 'luwipress' ), $flagged );
				?>
			</span>
			<span class="lp-pill pill-neutral" title="<?php esc_attr_e( 'IPs currently blocked', 'luwipress' ); ?>">
				<?php
				/* translators: %d: count */
				printf( esc_html__( '%d active blocks', 'luwipress' ), $active_blocks );
				?>
			</span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress' ) ); ?>"
			   class="lp-pill lp-pill--action pill-neutral lp-pill--icon"
			   title="<?php esc_attr_e( 'Back to LuwiPress Dashboard', 'luwipress' ); ?>">
				<span class="dashicons dashicons-admin-home"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Dashboard', 'luwipress' ); ?></span>
			</a>
		</div>
	</div>

	<nav class="nav-tab-wrapper luwipress-tabs">
		<a href="<?php echo esc_url( $tab_url( 'overview' ) ); ?>"
		   class="nav-tab <?php echo $active_tab === 'overview' ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-chart-bar"></span>
			<?php esc_html_e( 'Overview', 'luwipress' ); ?>
		</a>
		<a href="<?php echo esc_url( $tab_url( 'accounts' ) ); ?>"
		   class="nav-tab <?php echo $active_tab === 'accounts' ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-admin-users"></span>
			<?php esc_html_e( 'Accounts', 'luwipress' ); ?>
		</a>
		<a href="<?php echo esc_url( $tab_url( 'shield' ) ); ?>"
		   class="nav-tab <?php echo $active_tab === 'shield' ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-shield"></span>
			<?php esc_html_e( 'Shield', 'luwipress' ); ?>
		</a>
		<a href="<?php echo esc_url( $settings_url ); ?>"
		   class="nav-tab"
		   title="<?php esc_attr_e( 'All bot settings live under the main Settings page', 'luwipress' ); ?>">
			<span class="dashicons dashicons-admin-generic"></span>
			<?php esc_html_e( 'Settings', 'luwipress' ); ?>
			<span class="dashicons dashicons-external" style="font-size:14px; vertical-align:middle; opacity:.6;"></span>
		</a>
	</nav>

	<?php /* ============================ OVERVIEW ============================ */ ?>
	<?php if ( $active_tab === 'overview' ) : ?>

		<div class="luwipress-stat-grid" style="margin-top:16px;">
			<div class="luwipress-stat-card <?php echo esc_attr( $state_class( $flagged, 1, 25 ) ); ?>">
				<div class="stat-value"><?php echo number_format_i18n( $flagged ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Flagged accounts', 'luwipress' ); ?></div>
				<a href="<?php echo esc_url( $tab_url( 'accounts' ) ); ?>" class="stat-link"><?php esc_html_e( 'Review →', 'luwipress' ); ?></a>
			</div>
			<div class="luwipress-stat-card <?php echo esc_attr( $state_class( $active_blocks, 10, 200 ) ); ?>">
				<div class="stat-value"><?php echo number_format_i18n( $active_blocks ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Active IP blocks', 'luwipress' ); ?></div>
				<a href="<?php echo esc_url( $tab_url( 'shield' ) ); ?>" class="stat-link"><?php esc_html_e( 'Inspect →', 'luwipress' ); ?></a>
			</div>
			<div class="luwipress-stat-card stat-translation">
				<div class="stat-value"><?php echo number_format_i18n( $today_denials ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Denials today', 'luwipress' ); ?></div>
			</div>
			<div class="luwipress-stat-card stat-success">
				<div class="stat-value"><?php echo number_format_i18n( (int) $acct_stats['by_status']['deleted'] ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Deleted total', 'luwipress' ); ?></div>
			</div>
			<div class="luwipress-stat-card stat-success">
				<div class="stat-value"><?php echo number_format_i18n( count( (array) $sh_stats['allowlist']['ips'] ) + count( (array) $sh_stats['allowlist']['uas'] ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Allowlist entries', 'luwipress' ); ?></div>
			</div>
		</div>

		<div class="luwipress-section" style="margin-top:20px; display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
			<div>
				<h2 style="margin-top:0;">
					<span class="dashicons dashicons-admin-users" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Account scoring tiers', 'luwipress' ); ?>
				</h2>
				<table class="wp-list-table widefat striped">
					<tbody>
						<tr>
							<td><span class="lp-pill pill-warning"><?php esc_html_e( 'High risk (80+)', 'luwipress' ); ?></span></td>
							<td style="text-align:right;"><strong><?php echo (int) $buckets['high']; ?></strong></td>
						</tr>
						<tr>
							<td><span class="lp-pill pill-neutral"><?php esc_html_e( 'Medium (60–79)', 'luwipress' ); ?></span></td>
							<td style="text-align:right;"><strong><?php echo (int) $buckets['medium']; ?></strong></td>
						</tr>
						<tr>
							<td><span class="lp-pill pill-neutral"><?php esc_html_e( 'Low / borderline (40–59)', 'luwipress' ); ?></span></td>
							<td style="text-align:right;"><strong><?php echo (int) $buckets['low']; ?></strong></td>
						</tr>
						<tr>
							<td><span class="lp-pill pill-success"><?php esc_html_e( 'Noise (< 40)', 'luwipress' ); ?></span></td>
							<td style="text-align:right;"><strong><?php echo (int) $buckets['noise']; ?></strong></td>
						</tr>
					</tbody>
				</table>
				<p class="description" style="margin-top:8px;">
					<?php
					/* translators: %d: threshold value */
					printf( esc_html__( 'Current flag threshold: %d. Lower it in Settings to surface borderline accounts.', 'luwipress' ), (int) $acct_settings['threshold'] );
					?>
				</p>
			</div>

			<div>
				<h2 style="margin-top:0;">
					<span class="dashicons dashicons-shield" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Top block reasons', 'luwipress' ); ?>
				</h2>
				<?php if ( empty( $sh_stats['by_reason'] ) ) : ?>
					<p class="description"><?php esc_html_e( 'No blocks recorded yet.', 'luwipress' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat striped">
						<tbody>
							<?php $i = 0; foreach ( $sh_stats['by_reason'] as $reason => $n ) : if ( $i++ >= 6 ) break; ?>
								<tr>
									<td><code><?php echo esc_html( $reason ); ?></code></td>
									<td style="text-align:right;"><strong><?php echo (int) $n; ?></strong></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<div class="luwipress-section" style="margin-top:20px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Quick actions', 'luwipress' ); ?></h2>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $tab_url( 'accounts' ) ); ?>">
					<span class="dashicons dashicons-search" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Scan accounts', 'luwipress' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( $tab_url( 'shield' ) ); ?>">
					<span class="dashicons dashicons-shield" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Open shield', 'luwipress' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( $settings_url ); ?>">
					<span class="dashicons dashicons-admin-generic" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Edit settings', 'luwipress' ); ?>
				</a>
			</p>
		</div>

	<?php /* ============================ ACCOUNTS ============================ */ ?>
	<?php elseif ( $active_tab === 'accounts' ) : ?>

		<div class="luwipress-stat-grid" style="margin-top:16px;">
			<div class="luwipress-stat-card <?php echo esc_attr( $state_class( $buckets['high'], 1, 25 ) ); ?>">
				<div class="stat-value"><?php echo (int) $buckets['high']; ?></div>
				<div class="stat-label"><?php esc_html_e( 'High risk (80+)', 'luwipress' ); ?></div>
			</div>
			<div class="luwipress-stat-card <?php echo esc_attr( $state_class( $buckets['medium'], 5, 50 ) ); ?>">
				<div class="stat-value"><?php echo (int) $buckets['medium']; ?></div>
				<div class="stat-label"><?php esc_html_e( 'Medium (60–79)', 'luwipress' ); ?></div>
			</div>
			<div class="luwipress-stat-card stat-translation">
				<div class="stat-value"><?php echo (int) $buckets['low']; ?></div>
				<div class="stat-label"><?php esc_html_e( 'Low (40–59)', 'luwipress' ); ?></div>
			</div>
			<div class="luwipress-stat-card stat-success">
				<div class="stat-value"><?php echo (int) $acct_stats['by_status']['deleted']; ?></div>
				<div class="stat-label"><?php esc_html_e( 'Deleted total', 'luwipress' ); ?></div>
			</div>
			<div class="luwipress-stat-card stat-success">
				<div class="stat-value"><?php echo (int) $acct_stats['whitelist_size']; ?></div>
				<div class="stat-label"><?php esc_html_e( 'Whitelisted', 'luwipress' ); ?></div>
			</div>
		</div>

		<div class="luwipress-section" style="margin-top:20px;">
			<div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
				<button id="lwp-bot-scan-btn" class="button button-primary">
					<span class="dashicons dashicons-search" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Run scan now', 'luwipress' ); ?>
				</button>
				<label style="display:inline-flex; align-items:center; gap:6px; margin-left:6px;">
					<span class="dashicons dashicons-filter" style="opacity:.7;"></span>
					<?php esc_html_e( 'Show tier:', 'luwipress' ); ?>
					<select id="lwp-bot-tier" style="min-width:140px;">
						<option value="threshold" selected><?php
							/* translators: %d: threshold */
							printf( esc_html__( 'At threshold (≥ %d)', 'luwipress' ), (int) $acct_settings['threshold'] );
						?></option>
						<option value="80"><?php esc_html_e( 'High risk only (≥ 80)', 'luwipress' ); ?></option>
						<option value="60"><?php esc_html_e( 'Medium+ (≥ 60)', 'luwipress' ); ?></option>
						<option value="40"><?php esc_html_e( 'Borderline+ (≥ 40)', 'luwipress' ); ?></option>
						<option value="1"><?php esc_html_e( 'Everything scored', 'luwipress' ); ?></option>
					</select>
				</label>
				<button id="lwp-bot-delete-btn" class="button button-secondary" disabled>
					<span class="dashicons dashicons-trash" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Delete selected (dry-run)', 'luwipress' ); ?>
				</button>
				<button id="lwp-bot-delete-confirm-btn" class="button" style="color:var(--lp-error,#b91c1c); border-color:var(--lp-error,#b91c1c);" disabled>
					<span class="dashicons dashicons-warning" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Delete selected (CONFIRM)', 'luwipress' ); ?>
				</button>
				<span style="display:inline-block; width:1px; height:24px; background:var(--lp-border); margin:0 4px;"></span>
				<button id="lwp-bot-sweep-btn" class="button lwp-bot-sweep-btn" title="<?php esc_attr_e( 'Animated sweep across every account matching the current tier. Customers, email-verified, and realistic-name users are skipped automatically.', 'luwipress' ); ?>">
					<span class="dashicons dashicons-trash" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Sweep matching', 'luwipress' ); ?>
				</button>
				<span id="lwp-bot-scan-status" style="color:var(--lp-text-muted);"></span>
			</div>
			<div id="lwp-bot-progress" style="display:none; margin-top:12px;">
				<span class="lp-working-pill" id="lwp-bot-progress-pill"><?php esc_html_e( 'Scanning users…', 'luwipress' ); ?></span>
				<div class="luwipress-progress-bar luwipress-progress-bar--indeterminate" style="margin-top:8px;" role="progressbar" aria-label="<?php esc_attr_e( 'Scanning users', 'luwipress' ); ?>"></div>
			</div>
			<div id="lwp-bot-sweep-panel" class="lwp-bot-sweep-panel" style="display:none; margin-top:12px;">
				<div class="lwp-bot-sweep-row">
					<span class="lp-working-pill lwp-bot-sweep-pill"><?php esc_html_e( 'Sweeping…', 'luwipress' ); ?></span>
					<span class="lwp-bot-sweep-counts">
						<span class="lp-pill pill-neutral">
							<?php esc_html_e( 'Processed', 'luwipress' ); ?>
							<strong id="lwp-bot-sweep-processed">0</strong> / <strong id="lwp-bot-sweep-total">0</strong>
						</span>
						<span class="lp-pill pill-success">
							<?php esc_html_e( 'Deleted', 'luwipress' ); ?>
							<strong id="lwp-bot-sweep-deleted">0</strong>
						</span>
						<span class="lp-pill pill-neutral">
							<?php esc_html_e( 'Kept', 'luwipress' ); ?>
							<strong id="lwp-bot-sweep-kept">0</strong>
						</span>
					</span>
					<span class="lwp-bot-sweep-actions">
						<button id="lwp-bot-sweep-pause" class="button button-small"><?php esc_html_e( 'Pause', 'luwipress' ); ?></button>
						<button id="lwp-bot-sweep-stop"  class="button button-small" style="color:var(--lp-error,#b91c1c); border-color:var(--lp-error,#b91c1c);"><?php esc_html_e( 'Stop', 'luwipress' ); ?></button>
					</span>
				</div>
				<div class="luwipress-progress-bar lwp-bot-sweep-bar" role="progressbar" aria-label="<?php esc_attr_e( 'Sweep progress', 'luwipress' ); ?>">
					<div class="lwp-bot-sweep-bar-fill" id="lwp-bot-sweep-bar-fill" style="width:0%;"></div>
				</div>
			</div>
		</div>

		<div class="luwipress-section" style="margin-top:20px;">
			<h2 style="margin-top:0;">
				<?php esc_html_e( 'Scored accounts', 'luwipress' ); ?>
				<span id="lwp-bot-count" class="lp-pill pill-warning" style="margin-left:8px;"><?php echo (int) $flagged; ?></span>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Switch the tier filter to surface borderline accounts (≥ 40). Dry-run always shows what WOULD be deleted; the red CONFIRM button is irreversible. Three protection tiers are enforced automatically — WooCommerce customers with any order, email-verified users, and users with a realistic first + last name are never deleted, regardless of score.', 'luwipress' ); ?>
			</p>

			<table class="wp-list-table widefat fixed striped" id="lwp-bot-table">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<input type="checkbox" id="lwp-bot-select-all" />
						</td>
						<th><?php esc_html_e( 'Score', 'luwipress' ); ?></th>
						<th><?php esc_html_e( 'User', 'luwipress' ); ?></th>
						<th><?php esc_html_e( 'Email', 'luwipress' ); ?></th>
						<th><?php esc_html_e( 'Registered', 'luwipress' ); ?></th>
						<th><?php esc_html_e( 'Signals', 'luwipress' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'luwipress' ); ?></th>
					</tr>
				</thead>
				<tbody id="lwp-bot-tbody">
					<tr><td colspan="7" style="text-align:center; padding:24px; color:var(--lp-text-muted);">
						<?php esc_html_e( 'Click "Run scan now" to populate this list.', 'luwipress' ); ?>
					</td></tr>
				</tbody>
			</table>
		</div>

	<?php /* ============================ SHIELD ============================ */ ?>
	<?php elseif ( $active_tab === 'shield' ) : ?>

		<div class="luwipress-stat-grid" style="margin-top:16px;">
			<div class="luwipress-stat-card <?php echo esc_attr( $state_class( $active_blocks, 10, 200 ) ); ?>">
				<div class="stat-value"><?php echo number_format_i18n( $active_blocks ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Active blocks', 'luwipress' ); ?></div>
			</div>
			<div class="luwipress-stat-card stat-translation">
				<div class="stat-value"><?php echo number_format_i18n( $today_denials ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Denials today', 'luwipress' ); ?></div>
			</div>
			<div class="luwipress-stat-card stat-success">
				<div class="stat-value"><?php echo count( (array) $sh_stats['allowlist']['ips'] ) + count( (array) $sh_stats['allowlist']['uas'] ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Allowlist entries', 'luwipress' ); ?></div>
			</div>
			<div class="luwipress-stat-card stat-success">
				<div class="stat-value"><?php echo (int) $sh_settings['rate_limit_threshold']; ?></div>
				<div class="stat-label"><?php esc_html_e( 'Rate limit (req/window)', 'luwipress' ); ?></div>
			</div>
		</div>

		<div class="luwipress-section" style="margin-top:20px; display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
			<div>
				<h2 style="margin-top:0;"><?php esc_html_e( 'Top block reasons', 'luwipress' ); ?></h2>
				<?php if ( empty( $sh_stats['by_reason'] ) ) : ?>
					<p class="description"><?php esc_html_e( 'No blocks recorded yet — enable the shield in Settings to start collecting.', 'luwipress' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat striped">
						<thead><tr>
							<th><?php esc_html_e( 'Reason', 'luwipress' ); ?></th>
							<th style="text-align:right;"><?php esc_html_e( 'Count', 'luwipress' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $sh_stats['by_reason'] as $reason => $n ) : ?>
								<tr><td><code><?php echo esc_html( $reason ); ?></code></td><td style="text-align:right;"><?php echo (int) $n; ?></td></tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<div>
				<h2 style="margin-top:0;"><?php esc_html_e( 'Quick test (dry-run)', 'luwipress' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Preview whether a request would be denied without actually firing the shield.', 'luwipress' ); ?></p>
				<table class="form-table">
					<tr><th><label for="bs-test-ip">IP</label></th><td><input id="bs-test-ip" type="text" value="" class="regular-text" placeholder="1.2.3.4" /></td></tr>
					<tr><th><label for="bs-test-ua">UA</label></th><td><input id="bs-test-ua" type="text" value="AhrefsBot/7.0" class="regular-text" /></td></tr>
					<tr><th><label for="bs-test-path">Path</label></th><td><input id="bs-test-path" type="text" value="/wp-login.php" class="regular-text" /></td></tr>
				</table>
				<p>
					<button id="bs-test-btn" class="button button-primary"><?php esc_html_e( 'Run test', 'luwipress' ); ?></button>
					<span id="bs-test-result" style="margin-left:12px;"></span>
				</p>
			</div>
		</div>

		<div class="luwipress-section" style="margin-top:20px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Manually block an IP', 'luwipress' ); ?></h2>
			<form id="bs-manual-block" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
				<input id="bs-block-ip" type="text" placeholder="IP" required />
				<input id="bs-block-reason" type="text" placeholder="<?php esc_attr_e( 'reason (default: manual)', 'luwipress' ); ?>" />
				<input id="bs-block-ttl" type="number" value="1440" min="1" placeholder="<?php esc_attr_e( 'ttl minutes', 'luwipress' ); ?>" style="width:120px;" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Block', 'luwipress' ); ?></button>
				<span id="bs-block-status" style="color:var(--lp-text-muted);"></span>
			</form>
		</div>

		<?php
			$comments_mode      = isset( $sh_settings['comments_mode'] ) ? (string) $sh_settings['comments_mode'] : 'moderate';
			$comments_threshold = (int) ( $sh_settings['comments_threshold'] ?? 40 );
			$comments_max_links = (int) ( $sh_settings['comments_max_links'] ?? 2 );
			$comments_enabled   = ! empty( $sh_settings['comments_enabled'] );
			$mode_options       = array(
				'off'      => __( 'Off (no comment scoring)', 'luwipress' ),
				'moderate' => __( 'Moderate — hold for review (recommended)', 'luwipress' ),
				'spam'     => __( 'Spam — route to spam queue', 'luwipress' ),
				'reject'   => __( 'Reject — silent 403, no row written', 'luwipress' ),
			);
		?>
		<div class="luwipress-section" style="margin-top:20px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Comment review (bot-shaped submissions)', 'luwipress' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Logged-in users + allowlist always bypass. Score is the sum of weighted signals: link density, spam-token hits, author-shape heuristics, URL-only body, duplicates within 10min/IP, IP already on the blocklist. At or above the threshold, the configured action fires.', 'luwipress' ); ?></p>
			<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-top:12px;">
				<div>
					<table class="form-table">
						<tr>
							<th><label for="bs-c-enabled"><?php esc_html_e( 'Enabled', 'luwipress' ); ?></label></th>
							<td><label><input id="bs-c-enabled" type="checkbox" <?php checked( $comments_enabled ); ?> /> <?php esc_html_e( 'Filter comment submissions', 'luwipress' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="bs-c-mode"><?php esc_html_e( 'Mode', 'luwipress' ); ?></label></th>
							<td>
								<select id="bs-c-mode">
									<?php foreach ( $mode_options as $val => $label ) : ?>
										<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $comments_mode, $val ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="bs-c-threshold"><?php esc_html_e( 'Threshold', 'luwipress' ); ?></label></th>
							<td>
								<input id="bs-c-threshold" type="number" min="10" max="200" value="<?php echo (int) $comments_threshold; ?>" />
								<p class="description"><?php esc_html_e( 'Score at or above this value triggers the action. Default 40.', 'luwipress' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="bs-c-max-links"><?php esc_html_e( 'Max links / body', 'luwipress' ); ?></label></th>
							<td>
								<input id="bs-c-max-links" type="number" min="0" max="20" value="<?php echo (int) $comments_max_links; ?>" />
								<p class="description"><?php esc_html_e( 'Above this count, link-density signal fires. Default 2.', 'luwipress' ); ?></p>
							</td>
						</tr>
					</table>
					<p>
						<button id="bs-c-save" class="button button-primary"><?php esc_html_e( 'Save comment-review settings', 'luwipress' ); ?></button>
						<span id="bs-c-save-status" style="margin-left:12px; color:var(--lp-text-muted);"></span>
					</p>
				</div>
				<div>
					<h3 style="margin-top:0;"><?php esc_html_e( 'Quick test (dry-run)', 'luwipress' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Paste a sample comment body and see what would happen — score + matched signals — without touching live data.', 'luwipress' ); ?></p>
					<table class="form-table">
						<tr><th><label for="bs-c-test-author"><?php esc_html_e( 'Author', 'luwipress' ); ?></label></th>
							<td><input id="bs-c-test-author" type="text" class="regular-text" placeholder="qwer1234" /></td></tr>
						<tr><th><label for="bs-c-test-url"><?php esc_html_e( 'URL', 'luwipress' ); ?></label></th>
							<td><input id="bs-c-test-url" type="text" class="regular-text" placeholder="https://spam.example/promo" /></td></tr>
						<tr><th><label for="bs-c-test-body"><?php esc_html_e( 'Body', 'luwipress' ); ?></label></th>
							<td><textarea id="bs-c-test-body" rows="3" class="large-text" placeholder="Cheap rolex! visit https://example.com and https://other.com for crypto signal"></textarea></td></tr>
					</table>
					<p>
						<button id="bs-c-test-btn" class="button"><?php esc_html_e( 'Run test', 'luwipress' ); ?></button>
						<span id="bs-c-test-result" style="margin-left:12px;"></span>
					</p>
				</div>
			</div>
			<div style="margin-top:16px;">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Recent caught comments', 'luwipress' ); ?></h3>
				<table class="wp-list-table widefat striped" id="bs-c-recent-table">
					<thead>
						<tr>
							<th style="width:140px;"><?php esc_html_e( 'When', 'luwipress' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Action', 'luwipress' ); ?></th>
							<th style="width:60px;"><?php esc_html_e( 'Score', 'luwipress' ); ?></th>
							<th><?php esc_html_e( 'Author / IP', 'luwipress' ); ?></th>
							<th><?php esc_html_e( 'Signals', 'luwipress' ); ?></th>
							<th><?php esc_html_e( 'Snippet', 'luwipress' ); ?></th>
						</tr>
					</thead>
					<tbody id="bs-c-recent-tbody">
						<tr><td colspan="6" style="text-align:center; padding:18px;"><?php esc_html_e( 'Loading…', 'luwipress' ); ?></td></tr>
					</tbody>
				</table>
			</div>
		</div>

		<div class="luwipress-section" style="margin-top:20px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Active blocks', 'luwipress' ); ?></h2>
			<table class="wp-list-table widefat fixed striped" id="bs-blocks-table">
				<thead><tr>
					<th><?php esc_html_e( 'IP', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Reason', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Hits', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'First seen', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Last seen', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Expires', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Source', 'luwipress' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody id="bs-blocks-tbody">
					<tr><td colspan="8" style="text-align:center; padding:24px;"><?php esc_html_e( 'Loading…', 'luwipress' ); ?></td></tr>
				</tbody>
			</table>
		</div>

	<?php endif; ?>

</div>

<script>
(function () {
	var REST_ACCT   = <?php echo wp_json_encode( $rest_acct ); ?>;
	var REST_SHIELD = <?php echo wp_json_encode( $rest_shield ); ?>;
	var NONCE       = <?php echo wp_json_encode( $nonce ); ?>;
	var ACTIVE_TAB  = <?php echo wp_json_encode( $active_tab ); ?>;
	var THRESHOLD   = <?php echo (int) $acct_settings['threshold']; ?>;

	function api(root, path, opts) {
		opts = opts || {};
		opts.headers = Object.assign({ 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE }, opts.headers || {});
		opts.credentials = 'same-origin';
		return fetch(root + path, opts).then(function (r) {
			return r.json().then(function (data) { return { ok: r.ok, status: r.status, data: data }; });
		});
	}
	function apiAcct(p, o)   { return api(REST_ACCT,   p, o); }
	function apiShield(p, o) { return api(REST_SHIELD, p, o); }

	/* -------------------- ACCOUNTS TAB -------------------- */
	if (ACTIVE_TAB === 'accounts') {

		function fmtSignals(sig) {
			if (!sig || typeof sig !== 'object') return '<span style="color:var(--lp-text-muted);">—</span>';
			var entries = Object.keys(sig).filter(function (k) { return sig[k] > 0; })
				.map(function (k) { return [k, sig[k]]; })
				.sort(function (a, b) { return b[1] - a[1]; });
			if (!entries.length) return '<span style="color:var(--lp-text-muted);">—</span>';
			var top  = entries.slice(0, 3);
			var rest = entries.length - top.length;
			var html = '<div style="display:flex; flex-wrap:wrap; gap:4px; align-items:center;">';
			html += top.map(function (e) {
				var label = e[0].replace(/_/g, ' ');
				return '<span class="lp-pill pill-neutral" style="font-size:10px; padding:2px 8px; line-height:1.4;" title="' + e[0] + ' +' + e[1] + '">' + label + '</span>';
			}).join('');
			if (rest > 0) {
				var tooltip = entries.slice(3).map(function (e) { return e[0] + ' +' + e[1]; }).join(', ');
				html += '<span class="lp-pill pill-neutral" style="font-size:10px; padding:2px 8px; line-height:1.4; opacity:.65;" title="' + tooltip + '">+' + rest + '</span>';
			}
			html += '</div>';
			return html;
		}
		function fmtScore(score) {
			var cls = 'pill-success';
			if (score >= 80) cls = 'pill-warning';
			else if (score >= 60) cls = 'pill-neutral';
			else if (score >= 40) cls = 'pill-neutral';
			return '<span class="lp-pill ' + cls + '" style="font-weight:600;">' + score + '</span>';
		}
		function currentMinScore() {
			var v = document.getElementById('lwp-bot-tier').value;
			if (v === 'threshold') return null; // server uses configured threshold
			return parseInt(v, 10);
		}
		function renderRows(items) {
			var tbody = document.getElementById('lwp-bot-tbody');
			if (!items.length) {
				tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:24px; color:var(--lp-text-muted);"><?php echo esc_js( __( 'No accounts at this tier. Try a lower tier or run a fresh scan.', 'luwipress' ) ); ?></td></tr>';
				return;
			}
			tbody.innerHTML = items.map(function (it) {
				var userLine = '<strong>' + (it.display_name || it.user_login) + '</strong><br><small style="color:var(--lp-text-muted);">' + it.user_login + ' · #' + it.user_id + '</small>';
				return (
					'<tr data-uid="' + it.user_id + '">' +
					'<th class="check-column"><input type="checkbox" class="lwp-bot-cb" value="' + it.user_id + '" /></th>' +
					'<td>' + fmtScore(it.score) + '</td>' +
					'<td>' + userLine + '</td>' +
					'<td><code>' + it.user_email + '</code></td>' +
					'<td>' + (it.user_registered || '').split(' ')[0] + '</td>' +
					'<td>' + fmtSignals(it.signals) + '</td>' +
					'<td><button class="button button-small lwp-bot-whitelist" data-uid="' + it.user_id + '"><?php echo esc_js( __( 'Whitelist', 'luwipress' ) ); ?></button></td>' +
					'</tr>'
				);
			}).join('');
			updateDeleteBtn();
		}
		function setBusy(on, label) {
			var pBox  = document.getElementById('lwp-bot-progress');
			var pPill = document.getElementById('lwp-bot-progress-pill');
			var scan  = document.getElementById('lwp-bot-scan-btn');
			if (pBox)  pBox.style.display = on ? 'block' : 'none';
			if (pPill && label) pPill.textContent = label;
			if (scan)  scan.disabled = !!on;
		}
		function loadList() {
			var status = document.getElementById('lwp-bot-scan-status');
			status.textContent = '<?php echo esc_js( __( 'Loading…', 'luwipress' ) ); ?>';
			setBusy(true, '<?php echo esc_js( __( 'Loading scored accounts…', 'luwipress' ) ); ?>');
			var qs = 'per_page=100';
			var ms = currentMinScore();
			if (ms !== null) qs += '&min_score=' + ms;
			apiAcct('list?' + qs).then(function (r) {
				setBusy(false);
				if (!r.ok) { status.textContent = '<?php echo esc_js( __( 'Failed to load list.', 'luwipress' ) ); ?>'; return; }
				renderRows(r.data.items || []);
				document.getElementById('lwp-bot-count').textContent = (r.data.total || 0);
				status.textContent = (r.data.total || 0) + ' <?php echo esc_js( __( 'matching.', 'luwipress' ) ); ?>';
			});
		}
		function updateDeleteBtn() {
			var checked = document.querySelectorAll('.lwp-bot-cb:checked').length;
			document.getElementById('lwp-bot-delete-btn').disabled = checked === 0;
			document.getElementById('lwp-bot-delete-confirm-btn').disabled = checked === 0;
		}

		document.addEventListener('change', function (e) {
			if (e.target && e.target.classList && e.target.classList.contains('lwp-bot-cb')) updateDeleteBtn();
			if (e.target && e.target.id === 'lwp-bot-select-all') {
				document.querySelectorAll('.lwp-bot-cb').forEach(function (cb) { cb.checked = e.target.checked; });
				updateDeleteBtn();
			}
			if (e.target && e.target.id === 'lwp-bot-tier') loadList();
		});

		document.addEventListener('click', function (e) {
			if (!e.target) return;
			var t = e.target;
			var parent = t.parentNode;

			if (t.id === 'lwp-bot-scan-btn' || (parent && parent.id === 'lwp-bot-scan-btn')) {
				var status = document.getElementById('lwp-bot-scan-status');
				status.textContent = '';
				var totalScanned = 0;
				var totalFlagged = 0;
				function runOnePass(offset) {
					setBusy(true, '<?php echo esc_js( __( 'Scanning users…', 'luwipress' ) ); ?>');
					return apiAcct('scan', { method: 'POST', body: JSON.stringify({ offset: offset || 0 }) }).then(function (r) {
						if (!r.ok) {
							setBusy(false);
							status.textContent = '<?php echo esc_js( __( 'Scan failed.', 'luwipress' ) ); ?>';
							return;
						}
						totalScanned += (r.data.scanned || 0);
						totalFlagged = r.data.flagged || totalFlagged;
						var total = r.data.total || totalScanned;
						if (!r.data.complete) {
							// Pick up where the previous call's time budget ran out.
							status.textContent = '<?php echo esc_js( __( 'Continuing… scanned', 'luwipress' ) ); ?> ' + totalScanned + ' / ' + total;
							return runOnePass(r.data.next_offset || 0);
						}
						setBusy(false);
						status.textContent = '<?php echo esc_js( __( 'Done. Scanned', 'luwipress' ) ); ?> ' + totalScanned + ' / ' + total + ', <?php echo esc_js( __( 'flagged', 'luwipress' ) ); ?>: ' + totalFlagged;
						loadList();
					});
				}
				runOnePass(0);
				return;
			}

			if (t.classList && t.classList.contains('lwp-bot-whitelist')) {
				var uid = parseInt(t.getAttribute('data-uid'), 10);
				apiAcct('whitelist', { method: 'POST', body: JSON.stringify({ user_id: uid, action: 'add' }) }).then(function () { loadList(); });
				return;
			}

			var isDryRun  = t.id === 'lwp-bot-delete-btn'  || (parent && parent.id === 'lwp-bot-delete-btn');
			var isConfirm = t.id === 'lwp-bot-delete-confirm-btn' || (parent && parent.id === 'lwp-bot-delete-confirm-btn');
			if (isDryRun || isConfirm) {
				var ids = Array.from(document.querySelectorAll('.lwp-bot-cb:checked')).map(function (cb) { return parseInt(cb.value, 10); });
				if (!ids.length) return;
				if (isConfirm) {
					var msg = '<?php echo esc_js( __( 'Permanently delete', 'luwipress' ) ); ?> ' + ids.length + ' <?php echo esc_js( __( 'accounts? This cannot be undone.', 'luwipress' ) ); ?>';
					if (!window.confirm(msg)) return;
				}
				var status2 = document.getElementById('lwp-bot-scan-status');
				status2.textContent = isConfirm ? '<?php echo esc_js( __( 'Deleting…', 'luwipress' ) ); ?>' : '<?php echo esc_js( __( 'Dry-run…', 'luwipress' ) ); ?>';
				apiAcct('delete', { method: 'POST', body: JSON.stringify({ user_ids: ids, confirm: isConfirm }) }).then(function (r) {
					if (!r.ok) { status2.textContent = '<?php echo esc_js( __( 'Delete failed.', 'luwipress' ) ); ?>'; return; }
					var d = r.data;
					status2.textContent = (d.dry_run ? '<?php echo esc_js( __( 'Dry-run:', 'luwipress' ) ); ?> ' : '<?php echo esc_js( __( 'Deleted:', 'luwipress' ) ); ?> ') +
						d.deleted.length + ' · <?php echo esc_js( __( 'skipped:', 'luwipress' ) ); ?> ' + d.skipped.length;
					if (!d.dry_run) loadList();
				});
			}
		});

		/* -------------------- ANIMATED SWEEP -------------------- */
		var sweepState = { running: false, paused: false, cancelled: false };

		function sweepReasonLabel(reason) {
			// reason from server is "protected:has_orders" / "protected:email_verified" / "protected:realistic_name" / "wp_delete_user_failed" / etc.
			var r = String(reason || '').replace(/^protected:/, '');
			var map = {
				'has_orders':        '<?php echo esc_js( __( 'has orders', 'luwipress' ) ); ?>',
				'email_verified':    '<?php echo esc_js( __( 'email verified', 'luwipress' ) ); ?>',
				'realistic_name':    '<?php echo esc_js( __( 'realistic name', 'luwipress' ) ); ?>',
				'whitelisted':       '<?php echo esc_js( __( 'whitelisted', 'luwipress' ) ); ?>',
				'role_not_eligible': '<?php echo esc_js( __( 'role protected', 'luwipress' ) ); ?>',
				'not_found':         '<?php echo esc_js( __( 'not found', 'luwipress' ) ); ?>',
				'invalid_or_reassign_target': '<?php echo esc_js( __( 'reserved', 'luwipress' ) ); ?>',
				'wp_delete_user_failed': '<?php echo esc_js( __( 'delete failed', 'luwipress' ) ); ?>'
			};
			if (map[r]) return map[r];
			if (r.indexOf('protected_role:') === 0) return '<?php echo esc_js( __( 'role protected', 'luwipress' ) ); ?>';
			return r.replace(/_/g, ' ');
		}

		function animateRowDeleted(uid) {
			var tr = document.querySelector('tr[data-uid="' + uid + '"]');
			if (!tr) return;
			tr.classList.add('lwp-bot-row--deleting');
			setTimeout(function () {
				tr.classList.add('lwp-bot-row--deleted');
				setTimeout(function () { if (tr.parentNode) tr.parentNode.removeChild(tr); }, 360);
			}, 180);
		}

		function animateRowKept(uid, reason) {
			var tr = document.querySelector('tr[data-uid="' + uid + '"]');
			if (!tr) return;
			tr.classList.add('lwp-bot-row--kept');
			var signalsCell = tr.children[5];
			if (signalsCell) {
				var pill = document.createElement('span');
				pill.className = 'lp-pill pill-success lwp-bot-keep-pill';
				pill.textContent = '✓ ' + sweepReasonLabel(reason);
				pill.style.marginLeft = '4px';
				pill.style.fontSize = '10px';
				signalsCell.appendChild(pill);
			}
		}

		function setSweepUI(on) {
			var panel = document.getElementById('lwp-bot-sweep-panel');
			var btn   = document.getElementById('lwp-bot-sweep-btn');
			if (panel) panel.style.display = on ? 'block' : 'none';
			if (btn)   btn.disabled = !!on;
			document.getElementById('lwp-bot-scan-btn').disabled = !!on;
			document.getElementById('lwp-bot-delete-btn').disabled = !!on || document.querySelectorAll('.lwp-bot-cb:checked').length === 0;
			document.getElementById('lwp-bot-delete-confirm-btn').disabled = !!on || document.querySelectorAll('.lwp-bot-cb:checked').length === 0;
		}

		function updateSweepCounts(processed, deleted, kept, totalRemaining, initialTotal) {
			document.getElementById('lwp-bot-sweep-processed').textContent = processed;
			document.getElementById('lwp-bot-sweep-deleted').textContent   = deleted;
			document.getElementById('lwp-bot-sweep-kept').textContent      = kept;
			document.getElementById('lwp-bot-sweep-total').textContent     = initialTotal;
			var pct = initialTotal > 0 ? Math.min(100, Math.round(100 * processed / initialTotal)) : 0;
			document.getElementById('lwp-bot-sweep-bar-fill').style.width = pct + '%';
		}

		function sweepSleep(ms) { return new Promise(function (resolve) { setTimeout(resolve, ms); }); }

		async function runSweep() {
			if (sweepState.running) return;
			var tierEl = document.getElementById('lwp-bot-tier');
			var minScore = tierEl.value === 'threshold' ? THRESHOLD : parseInt(tierEl.value, 10);
			if (isNaN(minScore) || minScore < 40) {
				window.alert('<?php echo esc_js( __( 'Sweep requires score ≥ 40. Switch the tier filter to a higher value first.', 'luwipress' ) ); ?>');
				return;
			}
			var countEl = document.getElementById('lwp-bot-count');
			var initialTotal = parseInt(countEl.textContent, 10) || 0;
			if (initialTotal <= 0) {
				window.alert('<?php echo esc_js( __( 'Nothing to sweep at this tier.', 'luwipress' ) ); ?>');
				return;
			}
			var msg = '<?php echo esc_js( __( 'Sweep will scan and delete up to', 'luwipress' ) ); ?> ' + initialTotal +
				' <?php echo esc_js( __( 'accounts at score ≥', 'luwipress' ) ); ?> ' + minScore + '.\n\n' +
				'<?php echo esc_js( __( 'Protected automatically: WooCommerce customers, email-verified users, and users with a realistic first + last name.', 'luwipress' ) ); ?>\n\n' +
				'<?php echo esc_js( __( 'Continue?', 'luwipress' ) ); ?>';
			if (!window.confirm(msg)) return;

			sweepState = { running: true, paused: false, cancelled: false };
			setSweepUI(true);
			updateSweepCounts(0, 0, 0, initialTotal, initialTotal);

			var cursor = 0;
			var totals = { processed: 0, deleted: 0, kept: 0 };
			var failures = 0;
			var status   = document.getElementById('lwp-bot-scan-status');
			status.textContent = '<?php echo esc_js( __( 'Sweep running…', 'luwipress' ) ); ?>';

			while (!sweepState.cancelled) {
				if (sweepState.paused) { await sweepSleep(120); continue; }
				var resp;
				try {
					resp = await apiAcct('delete-by-filter', {
						method: 'POST',
						body: JSON.stringify({
							min_score:     minScore,
							confirm:       true,
							limit:         12,
							after_user_id: cursor
						})
					});
				} catch (e) {
					failures++;
					if (failures >= 3) { sweepState.cancelled = true; break; }
					await sweepSleep(500);
					continue;
				}
				if (!resp.ok) {
					failures++;
					if (failures >= 3) { sweepState.cancelled = true; break; }
					await sweepSleep(500);
					continue;
				}
				failures = 0;
				var d = resp.data || {};
				(d.deleted || []).forEach(function (uid) { animateRowDeleted(uid); totals.deleted++; });
				(d.skipped || []).forEach(function (sk) { animateRowKept(sk.id, sk.reason); totals.kept++; });
				totals.processed += (d.processed || 0);
				updateSweepCounts(totals.processed, totals.deleted, totals.kept, d.total_remaining, initialTotal);

				if (d.complete) break;
				cursor = d.next_cursor || cursor;
				await sweepSleep(180);
			}

			sweepState.running = false;
			setSweepUI(false);
			var summary = (sweepState.cancelled ? '<?php echo esc_js( __( 'Sweep stopped.', 'luwipress' ) ); ?>' : '<?php echo esc_js( __( 'Sweep complete.', 'luwipress' ) ); ?>') +
				' <?php echo esc_js( __( 'Deleted', 'luwipress' ) ); ?> ' + totals.deleted +
				' · <?php echo esc_js( __( 'kept', 'luwipress' ) ); ?> ' + totals.kept;
			status.textContent = summary;
			// Refresh after a beat so the user sees the final animation frames.
			setTimeout(loadList, 700);
		}

		document.addEventListener('click', function (e) {
			if (!e.target) return;
			var t = e.target;
			var parent = t.parentNode;
			if (t.id === 'lwp-bot-sweep-btn' || (parent && parent.id === 'lwp-bot-sweep-btn')) {
				runSweep();
				return;
			}
			if (t.id === 'lwp-bot-sweep-pause') {
				sweepState.paused = !sweepState.paused;
				t.textContent = sweepState.paused ? '<?php echo esc_js( __( 'Resume', 'luwipress' ) ); ?>' : '<?php echo esc_js( __( 'Pause', 'luwipress' ) ); ?>';
				return;
			}
			if (t.id === 'lwp-bot-sweep-stop') {
				sweepState.cancelled = true;
				sweepState.paused = false;
				return;
			}
		});

		// Auto-load.
		if (document.getElementById('lwp-bot-tbody')) loadList();
	}

	/* -------------------- SHIELD TAB -------------------- */
	if (ACTIVE_TAB === 'shield') {

		var testBtn = document.getElementById('bs-test-btn');
		if (testBtn) {
			testBtn.addEventListener('click', function () {
				var payload = {
					ip:   document.getElementById('bs-test-ip').value,
					ua:   document.getElementById('bs-test-ua').value,
					path: document.getElementById('bs-test-path').value
				};
				apiShield('test', { method: 'POST', body: JSON.stringify(payload) }).then(function (r) {
					var out = document.getElementById('bs-test-result');
					if (!r.ok) { out.textContent = '<?php echo esc_js( __( 'Test failed.', 'luwipress' ) ); ?>'; return; }
					var cls = r.data.verdict === 'deny' ? 'pill-warning' : 'pill-success';
					out.innerHTML = '<span class="lp-pill ' + cls + '">' + r.data.verdict.toUpperCase() + '</span> <code>' + (r.data.reason || '—') + '</code>';
				});
			});
		}

		var bTbody = document.getElementById('bs-blocks-tbody');
		function loadBlocks() {
			if (!bTbody) return;
			apiShield('blocks?per_page=200').then(function (r) {
				if (!r.ok || !r.data.items || !r.data.items.length) {
					bTbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:24px;"><?php echo esc_js( __( 'No active blocks.', 'luwipress' ) ); ?></td></tr>';
					return;
				}
				bTbody.innerHTML = r.data.items.map(function (it) {
					return '<tr>' +
						'<td><code>' + it.ip + '</code></td>' +
						'<td>' + it.reason + '</td>' +
						'<td>' + it.hit_count + '</td>' +
						'<td>' + it.first_seen + '</td>' +
						'<td>' + it.last_seen + '</td>' +
						'<td>' + (it.expires_at || '—') + '</td>' +
						'<td>' + it.source + '</td>' +
						'<td><button class="button button-small bs-unblock" data-ip="' + it.ip + '"><?php echo esc_js( __( 'Unblock', 'luwipress' ) ); ?></button></td>' +
						'</tr>';
				}).join('');
			});
		}
		loadBlocks();

		document.addEventListener('click', function (e) {
			if (e.target && e.target.classList && e.target.classList.contains('bs-unblock')) {
				var ip = e.target.getAttribute('data-ip');
				apiShield('unblock', { method: 'POST', body: JSON.stringify({ ip: ip }) }).then(loadBlocks);
			}
		});

		var mb = document.getElementById('bs-manual-block');
		if (mb) mb.addEventListener('submit', function (e) {
			e.preventDefault();
			var p = {
				ip:          document.getElementById('bs-block-ip').value,
				reason:      document.getElementById('bs-block-reason').value || 'manual',
				ttl_minutes: parseInt(document.getElementById('bs-block-ttl').value, 10) || 1440
			};
			apiShield('block', { method: 'POST', body: JSON.stringify(p) }).then(function (r) {
				document.getElementById('bs-block-status').textContent = r.ok ? '<?php echo esc_js( __( 'Blocked.', 'luwipress' ) ); ?>' : '<?php echo esc_js( __( 'Failed.', 'luwipress' ) ); ?>';
				loadBlocks();
			});
		});
	}

})();
</script>
