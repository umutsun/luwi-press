<?php
/**
 * LuwiPress Usage & Logs — Modern UI
 *
 * AI token spend overview, grouped activity logs, API call history.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Unauthorized', 'luwipress' ) );
}

// ── Handle log maintenance ──
if ( isset( $_POST['luwipress_clear_logs'] ) && check_admin_referer( 'luwipress_usage_nonce' ) ) {
	$days = absint( $_POST['luwipress_clear_days'] ?? 30 );
	LuwiPress_Logger::cleanup( $days );
	echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Logs older than %d days cleared.', 'luwipress' ), $days ) . '</p></div>';
}
if ( isset( $_POST['luwipress_clear_all_logs'] ) && check_admin_referer( 'luwipress_usage_nonce' ) ) {
	LuwiPress_Logger::cleanup( 0 );
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All logs cleared.', 'luwipress' ) . '</p></div>';
}

// ── Tab ──
$active_tab = sanitize_text_field( $_GET['tab'] ?? 'overview' );

// ── Token stats ──
$stats       = class_exists( 'LuwiPress_Token_Tracker' ) ? LuwiPress_Token_Tracker::get_stats( 30 ) : array();
$today_cost  = floatval( $stats['today']['cost'] ?? 0 );
$today_calls = intval( $stats['today']['calls'] ?? 0 );
$month_cost  = floatval( $stats['month']['cost'] ?? 0 );
$month_calls = intval( $stats['month']['calls'] ?? 0 );
$daily_limit = floatval( $stats['daily_limit'] ?? 0 );
$limit_pct   = intval( $stats['limit_used'] ?? 0 );
$by_workflow = $stats['by_workflow'] ?? array();

// ── Logs ──
$filter_level  = sanitize_text_field( $_GET['level'] ?? '' );
$filter_search = sanitize_text_field( $_GET['search'] ?? '' );
$per_page      = 50;
$current_page  = max( 1, absint( $_GET['paged'] ?? 1 ) );

$all_logs = LuwiPress_Logger::get_logs( 500 );
$level_counts = array( 'info' => 0, 'warning' => 0, 'error' => 0 );
foreach ( $all_logs as $log ) {
	if ( isset( $level_counts[ $log->level ] ) ) {
		$level_counts[ $log->level ]++;
	}
}

$logs = LuwiPress_Logger::get_logs( $per_page * 5, $filter_level ?: null );
if ( ! empty( $filter_search ) ) {
	$logs = array_filter( $logs, function( $log ) use ( $filter_search ) {
		return stripos( $log->message, $filter_search ) !== false;
	} );
}
$total_logs  = count( $logs );
$total_pages = ceil( $total_logs / $per_page );
$logs        = array_slice( $logs, ( $current_page - 1 ) * $per_page, $per_page );

$grouped_logs = array();
foreach ( $logs as $log ) {
	$date = wp_date( 'Y-m-d', strtotime( $log->timestamp ) );
	$grouped_logs[ $date ][] = $log;
}

$recent_calls = class_exists( 'LuwiPress_Token_Tracker' ) ? LuwiPress_Token_Tracker::get_recent_calls( 20 ) : array();
$nonce    = wp_create_nonce( 'luwipress_dashboard_nonce' );
$ajax_url = admin_url( 'admin-ajax.php' );
$base_url = admin_url( 'admin.php?page=luwipress-usage' );

// REST nonce for the JS live layer (luwi-live.js reads window.luwipressLive).
$rest_nonce = wp_create_nonce( 'wp_rest' );
?>

<div class="wrap lp-tm" data-luwi-usage-root data-tab="<?php echo esc_attr( $active_tab ); ?>">

	<!-- ═══ HEADER ═══ -->
	<div class="tm-header">
		<div class="tm-header-left">
			<h1 class="tm-title">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--lp-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>
				</svg>
				<?php esc_html_e( 'Usage & Logs', 'luwipress' ); ?>
			</h1>
		</div>
		<div class="tm-header-right">
			<button type="button" id="luwipress-emergency-stop" class="tm-btn tm-btn-danger">
				<span class="dashicons dashicons-dismiss"></span>
				<?php esc_html_e( 'Emergency Stop', 'luwipress' ); ?>
			</button>
			<span id="luwipress-stop-result" class="tm-tool-result"></span>
		</div>
	</div>

	<!-- ═══ COST STATS ═══ -->
	<div class="tm-stats">
		<?php
		$bar_colour = $limit_pct >= 90 ? 'var(--lp-error)' : ( $limit_pct >= 70 ? 'var(--lp-warning)' : 'var(--lp-success)' );
		$bar_class  = $limit_pct >= 90 ? ' lp-bar-crit' : ( $limit_pct >= 70 ? ' lp-bar-warn' : '' );
		$card_pulse = $limit_pct >= 90 ? ' lp-card-pulse' : '';
		$cost_cards = array(
			array(
				'icon'       => 'dashicons-clock',
				'label'      => __( "Today's Spend", 'luwipress' ),
				'value'      => '$' . number_format( $today_cost, 4 ),
				'sub'        => $today_calls . ' ' . __( 'calls', 'luwipress' ),
				'color'      => $bar_colour,
				'stat_key'   => 'today-cost',
				'stat_raw'   => $today_cost,
				'sub_key'    => 'today-calls',
				'sub_raw'    => $today_calls,
			),
			array(
				'icon'       => 'dashicons-calendar-alt',
				'label'      => __( 'This Month', 'luwipress' ),
				'value'      => '$' . number_format( $month_cost, 4 ),
				'sub'        => $month_calls . ' ' . __( 'calls', 'luwipress' ),
				'color'      => 'var(--lp-primary)',
				'stat_key'   => 'month-cost',
				'stat_raw'   => $month_cost,
				'sub_key'    => 'month-calls',
				'sub_raw'    => $month_calls,
			),
			array(
				'icon'       => 'dashicons-shield',
				'label'      => __( 'Daily Budget', 'luwipress' ),
				'value'      => $daily_limit > 0 ? $limit_pct . '%' : '--',
				'color'      => $bar_colour,
				'bar'        => $daily_limit > 0 ? min( 100, $limit_pct ) : 0,
				'sub'        => $daily_limit > 0 ? '$' . number_format( $today_cost, 2 ) . ' / $' . number_format( $daily_limit, 2 ) : __( 'No limit set', 'luwipress' ),
				'stat_key'   => 'limit-pct',
				'stat_raw'   => $limit_pct,
				'sub_key'    => 'budget-sub',
				'sub_raw'    => '',
				'card_attr'  => 'data-live-card="budget"' . $card_pulse,
				'bar_attr'   => 'data-live-stat="budget-bar"',
				'bar_class'  => $bar_class,
			),
			array(
				'icon'       => 'dashicons-admin-tools',
				'label'      => __( 'AI Workflows', 'luwipress' ),
				'value'      => count( $by_workflow ),
				'sub'        => __( 'active this month', 'luwipress' ),
				'color'      => 'var(--lp-blue)',
				'stat_key'   => 'workflow-count',
				'stat_raw'   => count( $by_workflow ),
			),
		);
		foreach ( $cost_cards as $ci => $card ) :
			$card_cls   = ( isset( $card['card_attr'] ) && false !== strpos( $card['card_attr'], 'lp-card-pulse' ) ) ? ' lp-card-pulse' : '';
			$card_extra = isset( $card['card_attr'] ) ? ' ' . trim( str_replace( 'lp-card-pulse', '', $card['card_attr'] ) ) : '';
		?>
		<div class="tm-stat-card<?php echo $card_cls; ?>"<?php echo $card_extra; ?> style="animation-delay:<?php echo $ci * 80; ?>ms;">
			<div class="tm-stat-icon" style="color:<?php echo $card['color']; ?>;">
				<span class="dashicons <?php echo esc_attr( $card['icon'] ); ?>"></span>
			</div>
			<div class="tm-stat-body">
				<span class="tm-stat-label"><?php echo esc_html( $card['label'] ); ?></span>
				<span class="tm-stat-value" style="color:<?php echo $card['color']; ?>;"<?php if ( ! empty( $card['stat_key'] ) ) : ?> data-live-stat="<?php echo esc_attr( (string) $card['stat_key'] ); ?>" data-lp-live-value="<?php echo esc_attr( (string) $card['stat_raw'] ); ?>"<?php endif; ?>><?php echo esc_html( (string) $card['value'] ); ?></span>
				<?php if ( isset( $card['bar'] ) ) : ?>
					<div class="tm-stat-bar"><div class="tm-stat-bar-fill<?php echo isset( $card['bar_class'] ) ? esc_attr( (string) $card['bar_class'] ) : ''; ?>"<?php if ( ! empty( $card['bar_attr'] ) ) : ?> <?php echo $card['bar_attr']; ?><?php endif; ?> style="width:<?php echo (int) $card['bar']; ?>%;background:<?php echo $card['color']; ?>;"></div></div>
				<?php endif; ?>
				<?php if ( ! empty( $card['sub'] ) ) : ?>
					<span class="tm-stat-sub"<?php if ( ! empty( $card['sub_key'] ) ) : ?> data-live-stat="<?php echo esc_attr( (string) $card['sub_key'] ); ?>"<?php if ( isset( $card['sub_raw'] ) && '' !== $card['sub_raw'] ) : ?> data-lp-live-value="<?php echo esc_attr( (string) $card['sub_raw'] ); ?>"<?php endif; ?><?php endif; ?>><?php echo esc_html( (string) $card['sub'] ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php endforeach; ?>

		<!-- 30-day cost trend sparkline — 5th grid item -->
		<div class="tm-stat-card tm-stat-card--spark" style="animation-delay:320ms;">
			<div class="tm-stat-icon" style="color:var(--lp-primary);">
				<span class="dashicons dashicons-chart-line"></span>
			</div>
			<div class="tm-stat-body">
				<span class="tm-stat-label"><?php esc_html_e( '30-day cost trend', 'luwipress' ); ?></span>
				<svg class="lp-sparkline" data-live-sparkline viewBox="0 0 160 36" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="<?php esc_attr_e( '30-day cost trend', 'luwipress' ); ?>"></svg>
			</div>
		</div>
	</div>

	<!-- ═══ TABS ═══ -->
	<div class="usage-tabs">
		<a href="<?php echo esc_url( $base_url . '&tab=overview' ); ?>" class="usage-tab <?php echo 'overview' === $active_tab ? 'active' : ''; ?>">
			<span class="dashicons dashicons-chart-area"></span> <?php esc_html_e( 'Overview', 'luwipress' ); ?>
		</a>
		<a href="<?php echo esc_url( $base_url . '&tab=logs' ); ?>" class="usage-tab <?php echo 'logs' === $active_tab ? 'active' : ''; ?>">
			<span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Activity Log', 'luwipress' ); ?>
			<?php if ( $level_counts['error'] > 0 ) : ?>
				<span class="usage-tab-badge"><?php echo $level_counts['error']; ?></span>
			<?php endif; ?>
		</a>
		<a href="<?php echo esc_url( $base_url . '&tab=api-calls' ); ?>" class="usage-tab <?php echo 'api-calls' === $active_tab ? 'active' : ''; ?>">
			<span class="dashicons dashicons-cloud"></span> <?php esc_html_e( 'API Calls', 'luwipress' ); ?>
		</a>
	</div>

	<?php if ( 'overview' === $active_tab ) : ?>
	<!-- ═══ OVERVIEW ═══ -->
	<div class="usage-grid">
		<!-- Workflow Breakdown -->
		<div class="lpress-card" style="animation: lp-fade-up var(--duration-slow) var(--ease-out) both;">
			<h2><span class="dashicons dashicons-chart-bar" style="color:var(--lp-primary);"></span> <?php esc_html_e( 'Cost by Workflow (30d)', 'luwipress' ); ?></h2>
			<?php if ( empty( $by_workflow ) ) : ?>
				<div class="usage-empty">
					<span class="dashicons dashicons-chart-bar"></span>
					<p><?php esc_html_e( 'No AI calls yet.', 'luwipress' ); ?></p>
				</div>
			<?php else :
				$wf_palette = array( 'var(--lp-primary)', 'var(--lp-success)', 'var(--lp-warning)', 'var(--lp-error)', 'var(--lp-blue)', '#a855f7', '#ec4899' );
				$wf_max     = max( 0.000001, max( array_map( function ( $w ) { return floatval( $w->cost ); }, $by_workflow ) ) );
			?>
				<div class="lp-bar-meter" data-live-workflows>
					<?php foreach ( array_slice( $by_workflow, 0, 8 ) as $i => $wf ) :
						$wf_name  = ucwords( str_replace( array( '-', '_' ), ' ', $wf->workflow ) );
						$wf_cost  = floatval( $wf->cost );
						$wf_pct   = max( 2, ( $wf_cost / $wf_max ) * 100 );
						$wf_color = $wf_palette[ $i % count( $wf_palette ) ];
					?>
						<div class="lp-bar-row">
							<div class="lp-bar-head">
								<span class="lp-bar-label"><?php echo esc_html( $wf_name ); ?></span>
								<span class="lp-bar-value">$<?php echo esc_html( number_format( $wf_cost, 4 ) ); ?></span>
							</div>
							<div class="lp-bar-track">
								<div class="lp-bar-fill" style="width:<?php echo esc_attr( number_format( $wf_pct, 1 ) ); ?>%;background:<?php echo esc_attr( $wf_color ); ?>;"></div>
							</div>
							<div class="lp-bar-sub"><?php echo esc_html( number_format( (int) $wf->calls ) ); ?> <?php esc_html_e( 'calls', 'luwipress' ); ?> · <?php echo esc_html( number_format( (int) $wf->tokens ) ); ?> <?php esc_html_e( 'tokens', 'luwipress' ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<!-- Recent Activity -->
		<div class="lpress-card" style="animation: lp-fade-up var(--duration-slow) var(--ease-out) both; animation-delay: 100ms;">
			<h2><span class="dashicons dashicons-clock" style="color:var(--lp-primary);"></span> <?php esc_html_e( 'Recent Activity', 'luwipress' ); ?></h2>
			<?php
			$summary_logs = array_slice( $all_logs, 0, 10 );
			if ( empty( $summary_logs ) ) : ?>
				<div class="usage-empty">
					<span class="dashicons dashicons-clock"></span>
					<p><?php esc_html_e( 'No activity yet.', 'luwipress' ); ?></p>
				</div>
			<?php else : ?>
				<div class="usage-activity-list">
				<?php foreach ( $summary_logs as $log ) :
					$icon  = 'info' === $log->level ? 'yes-alt' : ( 'warning' === $log->level ? 'warning' : 'dismiss' );
					$color = 'info' === $log->level ? 'var(--lp-success)' : ( 'warning' === $log->level ? 'var(--lp-warning)' : 'var(--lp-error)' );
				?>
					<div class="usage-activity-item">
						<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" style="color:<?php echo esc_attr( $color ); ?>;"></span>
						<div class="usage-activity-body">
							<span class="usage-activity-msg"><?php echo esc_html( $log->message ); ?></span>
							<span class="usage-activity-time"><?php echo esc_html( human_time_diff( strtotime( $log->timestamp ), current_time( 'timestamp' ) ) ); ?> ago</span>
						</div>
					</div>
				<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php elseif ( 'logs' === $active_tab ) : ?>
	<!-- ═══ LOGS ═══ -->
	<div style="margin-top:var(--space-lg);">
		<!-- Filters -->
		<div class="usage-log-filters">
			<div class="usage-filter-pills">
				<a href="<?php echo esc_url( $base_url . '&tab=logs' ); ?>" class="usage-filter-pill <?php echo empty( $filter_level ) ? 'active' : ''; ?>">
					<?php esc_html_e( 'All', 'luwipress' ); ?> <span><?php echo array_sum( $level_counts ); ?></span>
				</a>
				<a href="<?php echo esc_url( $base_url . '&tab=logs&level=error' ); ?>" class="usage-filter-pill pill-error <?php echo 'error' === $filter_level ? 'active' : ''; ?>">
					<?php esc_html_e( 'Errors', 'luwipress' ); ?> <span><?php echo $level_counts['error']; ?></span>
				</a>
				<a href="<?php echo esc_url( $base_url . '&tab=logs&level=warning' ); ?>" class="usage-filter-pill pill-warn <?php echo 'warning' === $filter_level ? 'active' : ''; ?>">
					<?php esc_html_e( 'Warnings', 'luwipress' ); ?> <span><?php echo $level_counts['warning']; ?></span>
				</a>
				<a href="<?php echo esc_url( $base_url . '&tab=logs&level=info' ); ?>" class="usage-filter-pill pill-info <?php echo 'info' === $filter_level ? 'active' : ''; ?>">
					<?php esc_html_e( 'Info', 'luwipress' ); ?> <span><?php echo $level_counts['info']; ?></span>
				</a>
			</div>
			<form method="get" class="usage-search-form">
				<input type="hidden" name="page" value="luwipress-usage" />
				<input type="hidden" name="tab" value="logs" />
				<?php if ( $filter_level ) : ?><input type="hidden" name="level" value="<?php echo esc_attr( $filter_level ); ?>" /><?php endif; ?>
				<input type="search" name="search" value="<?php echo esc_attr( $filter_search ); ?>" placeholder="<?php esc_attr_e( 'Search logs...', 'luwipress' ); ?>" class="usage-search-input" data-live-log-search />
				<button type="submit" class="tm-btn tm-btn-secondary tm-btn-sm"><span class="dashicons dashicons-search"></span></button>
			</form>
			<label class="lp-live-toggle" title="<?php esc_attr_e( 'Poll server for new entries every 15 seconds', 'luwipress' ); ?>">
				<input type="checkbox" data-live-autorefresh />
				<span class="lp-live-dot"></span>
				<span data-live-autorefresh-label data-on="<?php esc_attr_e( 'Live', 'luwipress' ); ?>" data-off="<?php esc_attr_e( 'Paused', 'luwipress' ); ?>"><?php esc_html_e( 'Paused', 'luwipress' ); ?></span>
			</label>
		</div>

		<!-- Grouped Logs -->
		<div class="usage-empty" data-live-log-empty style="padding:var(--space-3xl);<?php echo empty( $grouped_logs ) ? '' : 'display:none;'; ?>">
			<span class="dashicons dashicons-yes-alt"></span>
			<p><?php esc_html_e( 'No logs found.', 'luwipress' ); ?></p>
		</div>
		<?php if ( ! empty( $grouped_logs ) ) : ?>
			<div data-live-log-stream>
			<?php foreach ( $grouped_logs as $date => $day_logs ) : ?>
			<div class="usage-log-group">
				<div class="usage-log-date">
					<?php echo esc_html( wp_date( 'l, F j, Y', strtotime( $date ) ) ); ?>
					<span class="usage-log-date-count"><?php echo count( $day_logs ); ?></span>
				</div>
				<?php foreach ( $day_logs as $log ) :
					$level_class = 'log-' . $log->level;
				?>
				<div class="usage-log-entry <?php echo esc_attr( $level_class ); ?>" data-live-log-id="<?php echo esc_attr( $log->id ); ?>">
					<span class="usage-log-time"><?php echo esc_html( wp_date( 'H:i:s', strtotime( $log->timestamp ) ) ); ?></span>
					<span class="usage-log-level"><?php echo esc_html( strtoupper( $log->level ) ); ?></span>
					<span class="usage-log-msg"><?php echo esc_html( $log->message ); ?></span>
					<?php if ( ! empty( $log->context ) && '{}' !== $log->context && 'null' !== $log->context ) : ?>
						<button type="button" class="usage-log-detail luwipress-show-context" data-context="<?php echo esc_attr( $log->context ); ?>">
							<span class="dashicons dashicons-info-outline"></span>
						</button>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endforeach; ?>
			</div>

			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
			<div class="usage-pagination">
				<?php for ( $i = 1; $i <= $total_pages; $i++ ) :
					$page_url = add_query_arg( 'paged', $i, $base_url . '&tab=logs' . ( $filter_level ? '&level=' . $filter_level : '' ) . ( $filter_search ? '&search=' . $filter_search : '' ) );
				?>
					<a href="<?php echo esc_url( $page_url ); ?>" class="usage-page-btn <?php echo $i === $current_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
				<?php endfor; ?>
			</div>
			<?php endif; ?>
		<?php endif; ?>

		<!-- Log Maintenance -->
		<div class="lpress-card" style="margin-top:var(--space-lg);">
			<h2><span class="dashicons dashicons-trash" style="color:var(--lp-text-secondary);"></span> <?php esc_html_e( 'Log Maintenance', 'luwipress' ); ?></h2>
			<form method="post" class="usage-maint-form">
				<?php wp_nonce_field( 'luwipress_usage_nonce' ); ?>
				<label>
					<?php esc_html_e( 'Clear logs older than', 'luwipress' ); ?>
					<input type="number" name="luwipress_clear_days" value="30" min="1" max="365" class="usage-days-input" />
					<?php esc_html_e( 'days', 'luwipress' ); ?>
				</label>
				<button type="submit" name="luwipress_clear_logs" class="tm-btn tm-btn-secondary">
					<span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Clear Old', 'luwipress' ); ?>
				</button>
				<button type="submit" name="luwipress_clear_all_logs" class="tm-btn tm-btn-danger" onclick="return confirm('<?php esc_attr_e( 'Delete ALL logs?', 'luwipress' ); ?>');">
					<?php esc_html_e( 'Clear All', 'luwipress' ); ?>
				</button>
			</form>
		</div>
	</div>

	<?php elseif ( 'api-calls' === $active_tab ) : ?>
	<!-- ═══ API CALLS ═══ -->
	<div class="lpress-card" style="margin-top:var(--space-lg); animation: lp-fade-up var(--duration-slow) var(--ease-out) both;">
		<h2><span class="dashicons dashicons-cloud" style="color:var(--lp-primary);"></span> <?php esc_html_e( 'Recent API Calls', 'luwipress' ); ?></h2>
		<table class="tm-table">
			<thead><tr>
				<th><?php esc_html_e( 'Time', 'luwipress' ); ?></th>
				<th><?php esc_html_e( 'Workflow', 'luwipress' ); ?></th>
				<th><?php esc_html_e( 'Model', 'luwipress' ); ?></th>
				<th class="tm-col-num"><?php esc_html_e( 'Input', 'luwipress' ); ?></th>
				<th class="tm-col-num"><?php esc_html_e( 'Output', 'luwipress' ); ?></th>
				<th class="tm-col-num"><?php esc_html_e( 'Cost', 'luwipress' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $recent_calls ) ) : ?>
				<tr><td colspan="6" class="usage-empty-row"><?php esc_html_e( 'No API calls recorded yet.', 'luwipress' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $recent_calls as $call ) : ?>
				<tr class="tm-lang-row">
					<td><?php echo esc_html( wp_date( 'M j, H:i', strtotime( $call->created_at ) ) ); ?></td>
					<td><strong><?php echo esc_html( ucwords( str_replace( array( '-', '_' ), ' ', $call->workflow ) ) ); ?></strong></td>
					<td><code class="usage-model-badge"><?php echo esc_html( $call->model ); ?></code></td>
					<td class="tm-col-num"><?php echo number_format( (int) $call->input_tokens ); ?></td>
					<td class="tm-col-num"><?php echo number_format( (int) $call->output_tokens ); ?></td>
					<td class="tm-col-num"><span class="tm-num-done">$<?php echo number_format( (float) $call->estimated_cost, 5 ); ?></span></td>
				</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>

</div>

<!-- Context Modal -->
<div id="luwipress-context-modal" class="usage-modal" style="display:none;">
	<div class="usage-modal-content">
		<div class="usage-modal-header">
			<h3><?php esc_html_e( 'Details', 'luwipress' ); ?></h3>
			<button type="button" id="luwipress-close-modal" class="kg-detail-close">&times;</button>
		</div>
		<pre id="luwipress-context-data" class="mcp-code-block"></pre>
	</div>
</div>

<script>
(function($) {
	// Context modal
	$(document).on('click', '.luwipress-show-context', function() {
		var raw = $(this).attr('data-context');
		var ctx;
		try { ctx = JSON.stringify(JSON.parse(raw), null, 2); } catch(e) { ctx = raw; }
		$('#luwipress-context-data').text(ctx);
		$('#luwipress-context-modal').css('display', 'flex');
	});
	$('#luwipress-close-modal, #luwipress-context-modal').on('click', function(e) {
		if (e.target === this) $('#luwipress-context-modal').hide();
	});

	// Emergency Stop
	$('#luwipress-emergency-stop').on('click', function() {
		if (!confirm(<?php echo wp_json_encode( __( 'Disable all AI and set budget to $0.001?', 'luwipress' ) ); ?>)) return;
		var $btn = $(this).prop('disabled', true).addClass('tm-btn-loading');
		$.post(<?php echo wp_json_encode( $ajax_url ); ?>, {
			action: 'luwipress_emergency_stop',
			nonce: <?php echo wp_json_encode( $nonce ); ?>
		}, function(res) {
			$('#luwipress-stop-result').addClass(res.success ? 'result-ok' : 'result-err').text(res.success ? <?php echo wp_json_encode( __( 'All AI stopped.', 'luwipress' ) ); ?> : 'Error');
			$btn.prop('disabled', false).removeClass('tm-btn-loading');
		});
	});
})(jQuery);
</script>
