<?php
/**
 * n8nPress Usage & Logs — Unified page
 *
 * Combines AI token spend overview with grouped activity logs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Unauthorized', 'n8npress' ) );
}

// ── Handle log maintenance ──
if ( isset( $_POST['n8npress_clear_logs'] ) && check_admin_referer( 'n8npress_usage_nonce' ) ) {
	$days = absint( $_POST['n8npress_clear_days'] ?? 30 );
	N8nPress_Logger::cleanup( $days );
	echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( __( 'Logs older than %d days cleared.', 'n8npress' ), $days ) . '</p></div>';
}
if ( isset( $_POST['n8npress_clear_all_logs'] ) && check_admin_referer( 'n8npress_usage_nonce' ) ) {
	N8nPress_Logger::cleanup( 0 );
	echo '<div class="notice notice-success is-dismissible"><p>' . __( 'All logs cleared.', 'n8npress' ) . '</p></div>';
}

// ── Tab selection ──
$active_tab = sanitize_text_field( $_GET['tab'] ?? 'overview' );

// ── Token stats ──
$stats       = class_exists( 'N8nPress_Token_Tracker' ) ? N8nPress_Token_Tracker::get_stats( 30 ) : array();
$today_cost  = floatval( $stats['today']['cost'] ?? 0 );
$today_calls = intval( $stats['today']['calls'] ?? 0 );
$month_cost  = floatval( $stats['month']['cost'] ?? 0 );
$month_calls = intval( $stats['month']['calls'] ?? 0 );
$daily_limit = floatval( $stats['daily_limit'] ?? 0 );
$limit_pct   = intval( $stats['limit_used'] ?? 0 );
$by_workflow = $stats['by_workflow'] ?? array();

$bar_colour = $limit_pct >= 90 ? '#dc2626' : ( $limit_pct >= 70 ? '#f59e0b' : '#16a34a' );

// ── Logs ──
$filter_level  = sanitize_text_field( $_GET['level'] ?? '' );
$filter_search = sanitize_text_field( $_GET['search'] ?? '' );
$per_page      = 50;
$current_page  = max( 1, absint( $_GET['paged'] ?? 1 ) );

$all_logs = N8nPress_Logger::get_logs( 500 );
$level_counts = array( 'info' => 0, 'warning' => 0, 'error' => 0 );
foreach ( $all_logs as $log ) {
	if ( isset( $level_counts[ $log->level ] ) ) {
		$level_counts[ $log->level ]++;
	}
}

$logs = N8nPress_Logger::get_logs( $per_page * 5, $filter_level ?: null );
if ( ! empty( $filter_search ) ) {
	$logs = array_filter( $logs, function( $log ) use ( $filter_search ) {
		return stripos( $log->message, $filter_search ) !== false;
	} );
}
$total_logs  = count( $logs );
$total_pages = ceil( $total_logs / $per_page );
$logs        = array_slice( $logs, ( $current_page - 1 ) * $per_page, $per_page );

// ── Group logs by date ──
$grouped_logs = array();
foreach ( $logs as $log ) {
	$date = wp_date( 'Y-m-d', strtotime( $log->timestamp ) );
	$grouped_logs[ $date ][] = $log;
}

// ── Recent API calls ──
$recent_calls = class_exists( 'N8nPress_Token_Tracker' ) ? N8nPress_Token_Tracker::get_recent_calls( 20 ) : array();

$nonce     = wp_create_nonce( 'n8npress_dashboard_nonce' );
$ajax_url  = admin_url( 'admin-ajax.php' );
$base_url  = admin_url( 'admin.php?page=n8npress-usage' );
?>

<div class="wrap n8npress-dashboard">
	<h1>
		<span class="dashicons dashicons-chart-area"></span>
		<?php esc_html_e( 'Usage & Logs', 'n8npress' ); ?>
	</h1>

	<!-- ── COST CARDS ── -->
	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin:16px 0 24px;">
		<div class="postbox" style="margin:0;padding:14px 18px;border-left:4px solid <?php echo esc_attr( $bar_colour ); ?>;">
			<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;"><?php esc_html_e( "Today's Spend", 'n8npress' ); ?></div>
			<div style="font-size:26px;font-weight:700;color:#111827;">$<?php echo esc_html( number_format( $today_cost, 4 ) ); ?></div>
			<div style="font-size:12px;color:#6b7280;"><?php echo esc_html( $today_calls ); ?> <?php esc_html_e( 'calls', 'n8npress' ); ?></div>
		</div>
		<div class="postbox" style="margin:0;padding:14px 18px;">
			<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;"><?php esc_html_e( 'This Month', 'n8npress' ); ?></div>
			<div style="font-size:26px;font-weight:700;color:#111827;">$<?php echo esc_html( number_format( $month_cost, 4 ) ); ?></div>
			<div style="font-size:12px;color:#6b7280;"><?php echo esc_html( $month_calls ); ?> <?php esc_html_e( 'calls', 'n8npress' ); ?></div>
		</div>
		<div class="postbox" style="margin:0;padding:14px 18px;">
			<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;"><?php esc_html_e( 'Daily Limit', 'n8npress' ); ?></div>
			<?php if ( $daily_limit > 0 ) : ?>
				<div style="font-size:26px;font-weight:700;color:<?php echo $limit_pct >= 90 ? '#dc2626' : '#111827'; ?>;"><?php echo esc_html( $limit_pct ); ?>%</div>
				<div style="margin-top:6px;height:5px;background:#e5e7eb;border-radius:3px;overflow:hidden;">
					<div style="height:100%;width:<?php echo min( 100, $limit_pct ); ?>%;background:<?php echo esc_attr( $bar_colour ); ?>;border-radius:3px;"></div>
				</div>
				<div style="font-size:12px;color:#6b7280;margin-top:4px;">$<?php echo number_format( $today_cost, 2 ); ?> / $<?php echo number_format( $daily_limit, 2 ); ?></div>
			<?php else : ?>
				<div style="font-size:18px;color:#6b7280;">--</div>
				<a href="<?php echo admin_url( 'admin.php?page=n8npress-settings' ); ?>" style="font-size:11px;"><?php esc_html_e( 'Set limit', 'n8npress' ); ?></a>
			<?php endif; ?>
		</div>
		<div class="postbox" style="margin:0;padding:14px 18px;border-left:4px solid #dc2626;">
			<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;"><?php esc_html_e( 'Emergency', 'n8npress' ); ?></div>
			<button id="n8npress-emergency-stop" class="button" style="margin-top:8px;background:#dc2626;color:#fff;border-color:#dc2626;font-size:12px;">
				<?php esc_html_e( 'Stop All AI', 'n8npress' ); ?>
			</button>
			<div id="n8npress-stop-result" style="margin-top:4px;font-size:11px;"></div>
		</div>
	</div>

	<!-- ── TABS ── -->
	<h2 class="nav-tab-wrapper">
		<a href="<?php echo esc_url( $base_url . '&tab=overview' ); ?>" class="nav-tab <?php echo 'overview' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Overview', 'n8npress' ); ?></a>
		<a href="<?php echo esc_url( $base_url . '&tab=logs' ); ?>" class="nav-tab <?php echo 'logs' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Activity Log', 'n8npress' ); ?>
			<?php if ( $level_counts['error'] > 0 ) : ?>
				<span style="background:#dc2626;color:#fff;font-size:10px;padding:1px 6px;border-radius:8px;margin-left:4px;"><?php echo $level_counts['error']; ?></span>
			<?php endif; ?>
		</a>
		<a href="<?php echo esc_url( $base_url . '&tab=api-calls' ); ?>" class="nav-tab <?php echo 'api-calls' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'API Calls', 'n8npress' ); ?></a>
	</h2>

	<?php if ( 'overview' === $active_tab ) : ?>
	<!-- ═══════ OVERVIEW TAB ═══════ -->
	<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;">

		<!-- Workflow Cost Breakdown -->
		<div class="postbox" style="margin:0;">
			<div class="postbox-header" style="padding:12px 16px;border-bottom:1px solid #e5e7eb;">
				<h2 style="margin:0;font-size:14px;"><?php esc_html_e( 'Cost by Workflow (30d)', 'n8npress' ); ?></h2>
			</div>
			<div style="padding:12px 16px;">
				<?php if ( empty( $by_workflow ) ) : ?>
					<p style="color:#6b7280;text-align:center;"><?php esc_html_e( 'No AI calls yet.', 'n8npress' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped" style="font-size:13px;">
						<thead><tr><th><?php esc_html_e( 'Workflow', 'n8npress' ); ?></th><th style="text-align:right;"><?php esc_html_e( 'Calls', 'n8npress' ); ?></th><th style="text-align:right;"><?php esc_html_e( 'Cost', 'n8npress' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $by_workflow as $wf ) : ?>
							<tr>
								<td><?php echo esc_html( ucwords( str_replace( '-', ' ', $wf->workflow ) ) ); ?></td>
								<td style="text-align:right;"><?php echo number_format( (int) $wf->calls ); ?></td>
								<td style="text-align:right;font-weight:600;">$<?php echo number_format( (float) $wf->cost, 4 ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<!-- Recent Activity Summary -->
		<div class="postbox" style="margin:0;">
			<div class="postbox-header" style="padding:12px 16px;border-bottom:1px solid #e5e7eb;">
				<h2 style="margin:0;font-size:14px;"><?php esc_html_e( 'Recent Activity', 'n8npress' ); ?></h2>
			</div>
			<div style="padding:12px 16px;">
				<?php
				$summary_logs = array_slice( $all_logs, 0, 10 );
				if ( empty( $summary_logs ) ) : ?>
					<p style="color:#6b7280;text-align:center;"><?php esc_html_e( 'No activity yet.', 'n8npress' ); ?></p>
				<?php else : ?>
					<?php foreach ( $summary_logs as $log ) :
						$icon = 'info' === $log->level ? 'yes-alt' : ( 'warning' === $log->level ? 'warning' : 'dismiss' );
						$color = 'info' === $log->level ? '#16a34a' : ( 'warning' === $log->level ? '#f59e0b' : '#dc2626' );
					?>
					<div style="display:flex;gap:8px;align-items:flex-start;padding:6px 0;border-bottom:1px solid #f3f4f6;">
						<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" style="color:<?php echo esc_attr( $color ); ?>;font-size:16px;margin-top:2px;"></span>
						<div style="flex:1;min-width:0;">
							<div style="font-size:13px;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo esc_html( $log->message ); ?></div>
							<div style="font-size:11px;color:#9ca3af;"><?php echo esc_html( human_time_diff( strtotime( $log->timestamp ), current_time( 'timestamp' ) ) ); ?> ago</div>
						</div>
					</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php elseif ( 'logs' === $active_tab ) : ?>
	<!-- ═══════ LOGS TAB ═══════ -->
	<div style="margin-top:16px;">

		<!-- Filters -->
		<div style="display:flex;gap:8px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
			<a href="<?php echo esc_url( $base_url . '&tab=logs' ); ?>" class="button <?php echo empty( $filter_level ) ? 'button-primary' : ''; ?>">
				<?php esc_html_e( 'All', 'n8npress' ); ?> (<?php echo array_sum( $level_counts ); ?>)
			</a>
			<a href="<?php echo esc_url( $base_url . '&tab=logs&level=error' ); ?>" class="button <?php echo 'error' === $filter_level ? 'button-primary' : ''; ?>" style="<?php echo $level_counts['error'] > 0 ? 'color:#dc2626;' : ''; ?>">
				<?php esc_html_e( 'Errors', 'n8npress' ); ?> (<?php echo $level_counts['error']; ?>)
			</a>
			<a href="<?php echo esc_url( $base_url . '&tab=logs&level=warning' ); ?>" class="button <?php echo 'warning' === $filter_level ? 'button-primary' : ''; ?>">
				<?php esc_html_e( 'Warnings', 'n8npress' ); ?> (<?php echo $level_counts['warning']; ?>)
			</a>
			<a href="<?php echo esc_url( $base_url . '&tab=logs&level=info' ); ?>" class="button <?php echo 'info' === $filter_level ? 'button-primary' : ''; ?>">
				<?php esc_html_e( 'Info', 'n8npress' ); ?> (<?php echo $level_counts['info']; ?>)
			</a>
			<form method="get" style="margin-left:auto;display:flex;gap:4px;">
				<input type="hidden" name="page" value="n8npress-usage" />
				<input type="hidden" name="tab" value="logs" />
				<?php if ( $filter_level ) : ?><input type="hidden" name="level" value="<?php echo esc_attr( $filter_level ); ?>" /><?php endif; ?>
				<input type="search" name="search" value="<?php echo esc_attr( $filter_search ); ?>" placeholder="<?php esc_attr_e( 'Search...', 'n8npress' ); ?>" style="width:200px;" />
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'n8npress' ); ?></button>
			</form>
		</div>

		<!-- Grouped Logs -->
		<?php if ( empty( $grouped_logs ) ) : ?>
			<div style="text-align:center;padding:40px;color:#6b7280;">
				<span class="dashicons dashicons-yes-alt" style="font-size:40px;display:block;margin-bottom:8px;"></span>
				<?php esc_html_e( 'No logs found.', 'n8npress' ); ?>
			</div>
		<?php else : ?>
			<?php foreach ( $grouped_logs as $date => $day_logs ) : ?>
				<div style="margin-bottom:20px;">
					<div style="font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;padding:8px 0;border-bottom:2px solid #e5e7eb;">
						<?php echo esc_html( wp_date( 'l, F j, Y', strtotime( $date ) ) ); ?>
						<span style="font-weight:400;color:#9ca3af;margin-left:8px;"><?php echo count( $day_logs ); ?> <?php esc_html_e( 'entries', 'n8npress' ); ?></span>
					</div>
					<table class="wp-list-table widefat fixed striped" style="font-size:13px;">
						<tbody>
						<?php foreach ( $day_logs as $log ) :
							$level_color = 'error' === $log->level ? '#dc2626' : ( 'warning' === $log->level ? '#f59e0b' : '#16a34a' );
							$level_bg    = 'error' === $log->level ? '#fef2f2' : ( 'warning' === $log->level ? '#fffbeb' : '#f0fdf4' );
						?>
						<tr>
							<td style="width:70px;white-space:nowrap;font-size:12px;color:#6b7280;"><?php echo esc_html( wp_date( 'H:i:s', strtotime( $log->timestamp ) ) ); ?></td>
							<td style="width:70px;">
								<span style="display:inline-block;font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;color:<?php echo esc_attr( $level_color ); ?>;background:<?php echo esc_attr( $level_bg ); ?>;">
									<?php echo esc_html( strtoupper( $log->level ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $log->message ); ?></td>
							<td style="width:40px;">
								<?php if ( ! empty( $log->context ) && '{}' !== $log->context && 'null' !== $log->context ) : ?>
									<button type="button" class="button button-small n8npress-show-context" data-context="<?php echo esc_attr( $log->context ); ?>" title="<?php esc_attr_e( 'View details', 'n8npress' ); ?>">
										<span class="dashicons dashicons-info-outline" style="font-size:14px;width:14px;height:14px;margin-top:3px;"></span>
									</button>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>

			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
			<div style="text-align:center;margin:16px 0;">
				<?php for ( $i = 1; $i <= $total_pages; $i++ ) :
					$page_url = add_query_arg( 'paged', $i, $base_url . '&tab=logs' . ( $filter_level ? '&level=' . $filter_level : '' ) . ( $filter_search ? '&search=' . $filter_search : '' ) );
				?>
					<?php if ( $i === $current_page ) : ?>
						<span class="button button-primary" style="min-width:36px;"><?php echo $i; ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( $page_url ); ?>" class="button" style="min-width:36px;"><?php echo $i; ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</div>
			<?php endif; ?>
		<?php endif; ?>

		<!-- Log Maintenance -->
		<div class="postbox" style="margin-top:20px;padding:16px;">
			<form method="post" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
				<?php wp_nonce_field( 'n8npress_usage_nonce' ); ?>
				<label style="font-size:13px;">
					<?php esc_html_e( 'Clear logs older than', 'n8npress' ); ?>
					<input type="number" name="n8npress_clear_days" value="30" min="1" max="365" style="width:60px;" />
					<?php esc_html_e( 'days', 'n8npress' ); ?>
				</label>
				<button type="submit" name="n8npress_clear_logs" class="button"><?php esc_html_e( 'Clear Old', 'n8npress' ); ?></button>
				<button type="submit" name="n8npress_clear_all_logs" class="button button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Delete ALL logs?', 'n8npress' ); ?>');">
					<?php esc_html_e( 'Clear All', 'n8npress' ); ?>
				</button>
			</form>
		</div>
	</div>

	<?php elseif ( 'api-calls' === $active_tab ) : ?>
	<!-- ═══════ API CALLS TAB ═══════ -->
	<div style="margin-top:16px;">
		<table class="wp-list-table widefat fixed striped" style="font-size:13px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'n8npress' ); ?></th>
					<th><?php esc_html_e( 'Workflow', 'n8npress' ); ?></th>
					<th><?php esc_html_e( 'Model', 'n8npress' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Input', 'n8npress' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Output', 'n8npress' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Cost', 'n8npress' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $recent_calls ) ) : ?>
				<tr><td colspan="6" style="text-align:center;color:#6b7280;"><?php esc_html_e( 'No API calls recorded yet.', 'n8npress' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $recent_calls as $call ) : ?>
				<tr>
					<td style="white-space:nowrap;"><?php echo esc_html( wp_date( 'M j, H:i', strtotime( $call->created_at ) ) ); ?></td>
					<td><?php echo esc_html( ucwords( str_replace( '-', ' ', $call->workflow ) ) ); ?></td>
					<td><code style="font-size:11px;"><?php echo esc_html( $call->model ); ?></code></td>
					<td style="text-align:right;"><?php echo number_format( (int) $call->input_tokens ); ?></td>
					<td style="text-align:right;"><?php echo number_format( (int) $call->output_tokens ); ?></td>
					<td style="text-align:right;font-weight:600;">$<?php echo number_format( (float) $call->estimated_cost, 5 ); ?></td>
				</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>

</div>

<!-- Context Modal -->
<div id="n8npress-context-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;justify-content:center;align-items:center;">
	<div style="background:#fff;border-radius:8px;padding:20px;max-width:600px;width:90%;max-height:80vh;overflow:auto;">
		<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
			<h3 style="margin:0;"><?php esc_html_e( 'Details', 'n8npress' ); ?></h3>
			<button type="button" id="n8npress-close-modal" class="button">&times;</button>
		</div>
		<pre id="n8npress-context-data" style="background:#f8fafc;padding:12px;border-radius:6px;font-size:12px;overflow:auto;max-height:60vh;"></pre>
	</div>
</div>

<script>
(function($) {
	// Context modal
	$(document).on('click', '.n8npress-show-context', function() {
		var raw = $(this).attr('data-context');
		var ctx;
		try { ctx = JSON.stringify(JSON.parse(raw), null, 2); } catch(e) { ctx = raw; }
		$('#n8npress-context-data').text(ctx);
		$('#n8npress-context-modal').css('display', 'flex');
	});
	$('#n8npress-close-modal, #n8npress-context-modal').on('click', function(e) {
		if (e.target === this) $('#n8npress-context-modal').hide();
	});

	// Emergency Stop
	$('#n8npress-emergency-stop').on('click', function() {
		if (!confirm(<?php echo wp_json_encode( __( 'Disable all AI and set limit to $0.001?', 'n8npress' ) ); ?>)) return;
		var $btn = $(this).prop('disabled', true).text(<?php echo wp_json_encode( __( 'Stopping...', 'n8npress' ) ); ?>);
		$.post(<?php echo wp_json_encode( $ajax_url ); ?>, {
			action: 'n8npress_emergency_stop',
			nonce: <?php echo wp_json_encode( $nonce ); ?>
		}, function(res) {
			$('#n8npress-stop-result').css('color', res.success ? '#16a34a' : '#dc2626').text(res.success ? <?php echo wp_json_encode( __( 'All AI stopped.', 'n8npress' ) ); ?> : 'Error');
			$btn.prop('disabled', false).text(<?php echo wp_json_encode( __( 'Stop All AI', 'n8npress' ) ); ?>);
		});
	});
})(jQuery);
</script>
