<?php
/**
 * n8nPress Token Usage Dashboard
 *
 * Live cost monitoring for AI API calls — shows today/monthly spend,
 * workflow breakdown, recent calls table, and emergency stop.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Unauthorized', 'n8npress' ) );
}

// Nonce for AJAX
$nonce = wp_create_nonce( 'n8npress_dashboard_nonce' );

// REST endpoints (used in JS fetch)
$rest_stats  = rest_url( 'n8npress/v1/token-stats' );
$rest_recent = rest_url( 'n8npress/v1/token-recent' );
$ajax_url    = admin_url( 'admin-ajax.php' );

// Initial data (server-side render — JS will refresh every 30s)
$stats  = class_exists( 'N8nPress_Token_Tracker' ) ? N8nPress_Token_Tracker::get_stats( 30 ) : array();
$recent = class_exists( 'N8nPress_Token_Tracker' ) ? N8nPress_Token_Tracker::get_recent_calls( 20 ) : array();

$today_cost  = floatval( $stats['today']['cost'] ?? 0 );
$today_calls = intval( $stats['today']['calls'] ?? 0 );
$month_cost  = floatval( $stats['month']['cost'] ?? 0 );
$month_calls = intval( $stats['month']['calls'] ?? 0 );
$daily_limit = floatval( $stats['daily_limit'] ?? 0 );
$limit_pct   = intval( $stats['limit_used'] ?? 0 );
$by_workflow = $stats['by_workflow'] ?? array();

// Limit bar colour
if ( $limit_pct >= 90 ) {
	$bar_colour = '#dc2626';
	$card_style = 'border-left: 4px solid #dc2626;';
} elseif ( $limit_pct >= 70 ) {
	$bar_colour = '#f59e0b';
	$card_style = 'border-left: 4px solid #f59e0b;';
} else {
	$bar_colour = '#16a34a';
	$card_style = 'border-left: 4px solid #16a34a;';
}
?>
<div class="wrap" id="n8npress-token-dashboard">
	<h1><?php esc_html_e( 'AI Token Usage', 'n8npress' ); ?></h1>
	<p class="description" style="margin-bottom:20px;">
		<?php esc_html_e( 'Live AI spend tracking. Refreshes every 30 seconds.', 'n8npress' ); ?>
		<span id="n8npress-last-refresh" style="color:#6b7280;font-size:12px;margin-left:10px;"></span>
	</p>

	<!-- ── HEADER CARDS ─────────────────────────────────────────────── -->
	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;">

		<!-- Today cost -->
		<div class="postbox" style="margin:0;padding:16px 20px;<?php echo esc_attr( $card_style ); ?>">
			<p style="margin:0 0 4px;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;">
				<?php esc_html_e( "Today's Spend", 'n8npress' ); ?>
			</p>
			<p id="card-today-cost" style="margin:0;font-size:28px;font-weight:700;color:#111827;">
				$<?php echo esc_html( number_format( $today_cost, 4 ) ); ?>
			</p>
			<p id="card-today-calls" style="margin:4px 0 0;font-size:12px;color:#6b7280;">
				<?php echo esc_html( number_format( $today_calls ) ); ?> <?php esc_html_e( 'calls', 'n8npress' ); ?>
			</p>
		</div>

		<!-- Monthly cost -->
		<div class="postbox" style="margin:0;padding:16px 20px;">
			<p style="margin:0 0 4px;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;">
				<?php esc_html_e( 'This Month', 'n8npress' ); ?>
			</p>
			<p id="card-month-cost" style="margin:0;font-size:28px;font-weight:700;color:#111827;">
				$<?php echo esc_html( number_format( $month_cost, 4 ) ); ?>
			</p>
			<p id="card-month-calls" style="margin:4px 0 0;font-size:12px;color:#6b7280;">
				<?php echo esc_html( number_format( $month_calls ) ); ?> <?php esc_html_e( 'calls', 'n8npress' ); ?>
			</p>
		</div>

		<!-- Daily limit progress -->
		<div class="postbox" style="margin:0;padding:16px 20px;">
			<p style="margin:0 0 4px;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;">
				<?php esc_html_e( 'Daily Limit', 'n8npress' ); ?>
			</p>
			<?php if ( $daily_limit > 0 ) : ?>
				<p id="card-limit-pct" style="margin:0;font-size:28px;font-weight:700;color:#111827;">
					<?php echo esc_html( $limit_pct ); ?>%
				</p>
				<div style="margin-top:8px;height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;">
					<div id="card-limit-bar" style="height:100%;width:<?php echo esc_attr( $limit_pct ); ?>%;background:<?php echo esc_attr( $bar_colour ); ?>;transition:width .4s;"></div>
				</div>
				<p id="card-limit-label" style="margin:4px 0 0;font-size:12px;color:#6b7280;">
					$<?php echo esc_html( number_format( $today_cost, 4 ) ); ?> / $<?php echo esc_html( number_format( $daily_limit, 2 ) ); ?>
				</p>
			<?php else : ?>
				<p style="margin:0;font-size:16px;color:#6b7280;"><?php esc_html_e( 'No limit set', 'n8npress' ); ?></p>
				<p style="margin:4px 0 0;font-size:12px;color:#6b7280;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=n8npress-settings&tab=api' ) ); ?>">
						<?php esc_html_e( 'Set a daily limit', 'n8npress' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>

		<!-- Emergency Stop -->
		<div class="postbox" style="margin:0;padding:16px 20px;border-left:4px solid #dc2626;">
			<p style="margin:0 0 8px;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;">
				<?php esc_html_e( 'Emergency Stop', 'n8npress' ); ?>
			</p>
			<p style="margin:0 0 12px;font-size:12px;color:#374151;">
				<?php esc_html_e( 'Disables all AI enrichment and sets limit to $0.001.', 'n8npress' ); ?>
			</p>
			<button id="n8npress-emergency-stop" class="button" style="background:#dc2626;color:#fff;border-color:#dc2626;">
				&#9888; <?php esc_html_e( 'Stop All AI', 'n8npress' ); ?>
			</button>
			<div id="n8npress-stop-result" style="margin-top:8px;font-size:12px;"></div>
		</div>

	</div>

	<!-- ── WORKFLOW BREAKDOWN TABLE ─────────────────────────────────── -->
	<div class="postbox" style="margin-bottom:24px;">
		<div class="postbox-header" style="padding:12px 16px;border-bottom:1px solid #e5e7eb;">
			<h2 style="margin:0;font-size:14px;"><?php esc_html_e( 'Workflow Cost (Last 30 Days)', 'n8npress' ); ?></h2>
		</div>
		<div style="padding:16px;">
			<table class="wp-list-table widefat fixed striped" id="workflow-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Workflow', 'n8npress' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Calls', 'n8npress' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Total Tokens', 'n8npress' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Est. Cost', 'n8npress' ); ?></th>
					</tr>
				</thead>
				<tbody id="workflow-tbody">
				<?php if ( empty( $by_workflow ) ) : ?>
					<tr><td colspan="4" style="text-align:center;color:#6b7280;"><?php esc_html_e( 'No data yet.', 'n8npress' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $by_workflow as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $row->workflow ); ?></code></td>
							<td style="text-align:right;"><?php echo esc_html( number_format( (int) $row->calls ) ); ?></td>
							<td style="text-align:right;"><?php echo esc_html( number_format( (int) $row->tokens ) ); ?></td>
							<td style="text-align:right;font-weight:600;">$<?php echo esc_html( number_format( (float) $row->cost, 4 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- ── RECENT API CALLS TABLE ───────────────────────────────────── -->
	<div class="postbox">
		<div class="postbox-header" style="padding:12px 16px;border-bottom:1px solid #e5e7eb;">
			<h2 style="margin:0;font-size:14px;"><?php esc_html_e( 'Recent API Calls', 'n8npress' ); ?></h2>
		</div>
		<div style="padding:16px;overflow-x:auto;">
			<table class="wp-list-table widefat fixed striped" id="recent-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date/Time', 'n8npress' ); ?></th>
						<th><?php esc_html_e( 'Workflow', 'n8npress' ); ?></th>
						<th><?php esc_html_e( 'Model', 'n8npress' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Input', 'n8npress' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Output', 'n8npress' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Cost', 'n8npress' ); ?></th>
					</tr>
				</thead>
				<tbody id="recent-tbody">
				<?php if ( empty( $recent ) ) : ?>
					<tr><td colspan="6" style="text-align:center;color:#6b7280;"><?php esc_html_e( 'No calls recorded yet.', 'n8npress' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $recent as $row ) : ?>
						<tr>
							<td style="white-space:nowrap;font-size:12px;"><?php echo esc_html( $row->created_at ); ?></td>
							<td><code style="font-size:11px;"><?php echo esc_html( $row->workflow ); ?></code></td>
							<td style="font-size:12px;"><?php echo esc_html( $row->model ); ?></td>
							<td style="text-align:right;"><?php echo esc_html( number_format( (int) $row->input_tokens ) ); ?></td>
							<td style="text-align:right;"><?php echo esc_html( number_format( (int) $row->output_tokens ) ); ?></td>
							<td style="text-align:right;font-weight:600;">$<?php echo esc_html( number_format( (float) $row->estimated_cost, 5 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>

</div><!-- .wrap -->

<script>
(function($) {
	var restStats  = <?php echo wp_json_encode( $rest_stats ); ?>;
	var restRecent = <?php echo wp_json_encode( $rest_recent ); ?>;
	var ajaxUrl    = <?php echo wp_json_encode( $ajax_url ); ?>;
	var nonce      = <?php echo wp_json_encode( $nonce ); ?>;
	var restNonce  = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

	function fmt(n, decimals) {
		return parseFloat(n).toFixed(decimals !== undefined ? decimals : 4);
	}
	function fmtInt(n) {
		return parseInt(n, 10).toLocaleString();
	}

	function refreshStats() {
		fetch(restStats, { headers: { 'X-WP-Nonce': restNonce } })
			.then(function(r) { return r.json(); })
			.then(function(data) {
				var today = data.today || {};
				var month = data.month || {};
				var limit = parseFloat(data.daily_limit || 0);
				var todayCost = parseFloat(today.cost || 0);
				var pct = limit > 0 ? Math.min(100, Math.round((todayCost / limit) * 100)) : 0;

				$('#card-today-cost').text('$' + fmt(todayCost, 4));
				$('#card-today-calls').text(fmtInt(today.calls || 0) + ' calls');
				$('#card-month-cost').text('$' + fmt(month.cost || 0, 4));
				$('#card-month-calls').text(fmtInt(month.calls || 0) + ' calls');

				if (limit > 0) {
					var colour = pct >= 90 ? '#dc2626' : (pct >= 70 ? '#f59e0b' : '#16a34a');
					$('#card-limit-pct').text(pct + '%');
					$('#card-limit-bar').css({ width: pct + '%', background: colour });
					$('#card-limit-label').text('$' + fmt(todayCost, 4) + ' / $' + fmt(limit, 2));
				}

				// Workflow table
				var wfRows = data.by_workflow || [];
				var wfHtml = wfRows.length ? '' : '<tr><td colspan="4" style="text-align:center;color:#6b7280;">No data yet.</td></tr>';
				wfRows.forEach(function(r) {
					wfHtml += '<tr>'
						+ '<td><code>' + escHtml(r.workflow) + '</code></td>'
						+ '<td style="text-align:right;">' + fmtInt(r.calls) + '</td>'
						+ '<td style="text-align:right;">' + fmtInt(r.tokens) + '</td>'
						+ '<td style="text-align:right;font-weight:600;">$' + fmt(r.cost, 4) + '</td>'
						+ '</tr>';
				});
				$('#workflow-tbody').html(wfHtml);

				// Timestamp
				$('#n8npress-last-refresh').text('Last updated: ' + new Date().toLocaleTimeString());
			})
			.catch(function() {
				$('#n8npress-last-refresh').text('Refresh failed');
			});
	}

	function refreshRecent() {
		fetch(restRecent, { headers: { 'X-WP-Nonce': restNonce } })
			.then(function(r) { return r.json(); })
			.then(function(rows) {
				if (!Array.isArray(rows)) { return; }
				var html = rows.length ? '' : '<tr><td colspan="6" style="text-align:center;color:#6b7280;">No calls recorded yet.</td></tr>';
				rows.forEach(function(r) {
					html += '<tr>'
						+ '<td style="white-space:nowrap;font-size:12px;">' + escHtml(r.created_at) + '</td>'
						+ '<td><code style="font-size:11px;">' + escHtml(r.workflow) + '</code></td>'
						+ '<td style="font-size:12px;">' + escHtml(r.model) + '</td>'
						+ '<td style="text-align:right;">' + fmtInt(r.input_tokens) + '</td>'
						+ '<td style="text-align:right;">' + fmtInt(r.output_tokens) + '</td>'
						+ '<td style="text-align:right;font-weight:600;">$' + parseFloat(r.estimated_cost).toFixed(5) + '</td>'
						+ '</tr>';
				});
				$('#recent-tbody').html(html);
			});
	}

	function escHtml(str) {
		return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	// Emergency Stop
	$('#n8npress-emergency-stop').on('click', function() {
		if (!confirm('Are you sure? This will disable all AI enrichment and set the daily limit to $0.001.')) {
			return;
		}
		var $btn = $(this).prop('disabled', true).text('Stopping...');
		$.post(ajaxUrl, { action: 'n8npress_emergency_stop', nonce: nonce }, function(res) {
			if (res.success) {
				$('#n8npress-stop-result').css('color', '#16a34a').text('✓ ' + (res.data.message || 'Emergency stop activated.'));
			} else {
				$('#n8npress-stop-result').css('color', '#dc2626').text('Error: ' + (res.data || 'Unknown error'));
			}
			$btn.prop('disabled', false).text('⚠ Stop All AI');
		});
	});

	// Initial load + 30s interval
	refreshStats();
	refreshRecent();
	setInterval(function() {
		refreshStats();
		refreshRecent();
	}, 30000);

})(jQuery);
</script>
