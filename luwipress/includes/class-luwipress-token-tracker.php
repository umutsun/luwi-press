<?php
/**
 * Token Usage Tracker for LuwiPress
 *
 * Tracks AI API token consumption per workflow, enforces daily limits,
 * and provides usage stats for the dashboard.
 *
 * @package LuwiPress
 * @since 1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Token_Tracker {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Create the token usage table.
	 */
	public static function create_table() {
		global $wpdb;
		$table   = $wpdb->prefix . 'luwipress_token_usage';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			date date NOT NULL,
			workflow varchar(100) NOT NULL DEFAULT 'unknown',
			provider varchar(20) NOT NULL DEFAULT 'openai',
			model varchar(50) NOT NULL DEFAULT 'gpt-4o-mini',
			input_tokens int NOT NULL DEFAULT 0,
			output_tokens int NOT NULL DEFAULT 0,
			total_tokens int NOT NULL DEFAULT 0,
			estimated_cost decimal(10,6) NOT NULL DEFAULT 0,
			execution_id varchar(50) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY date_idx (date),
			KEY workflow_idx (workflow),
			KEY date_workflow (date, workflow)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Record token usage from a workflow execution.
	 */
	public static function record( $data ) {
		global $wpdb;

		$workflow      = sanitize_text_field( $data['workflow'] ?? 'unknown' );
		$provider      = sanitize_text_field( $data['provider'] ?? 'openai' );
		$model         = sanitize_text_field( $data['model'] ?? 'gpt-4o-mini' );
		$input_tokens  = absint( $data['input_tokens'] ?? 0 );
		$output_tokens = absint( $data['output_tokens'] ?? 0 );
		$total_tokens  = $input_tokens + $output_tokens;
		$execution_id  = sanitize_text_field( $data['execution_id'] ?? '' );

		// Calculate estimated cost
		$cost = self::estimate_cost( $provider, $model, $input_tokens, $output_tokens );

		$wpdb->insert(
			$wpdb->prefix . 'luwipress_token_usage',
			array(
				'date'          => current_time( 'Y-m-d' ),
				'workflow'      => $workflow,
				'provider'      => $provider,
				'model'         => $model,
				'input_tokens'  => $input_tokens,
				'output_tokens' => $output_tokens,
				'total_tokens'  => $total_tokens,
				'estimated_cost' => $cost,
				'execution_id'  => $execution_id,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%s' )
		);

		// Update daily aggregate cache
		self::update_daily_cache();

		return $cost;
	}

	/**
	 * Estimate cost based on provider pricing.
	 */
	public static function estimate_cost( $provider, $model, $input_tokens, $output_tokens ) {
		// Default pricing per million tokens — override via luwipress_token_pricing filter
		$pricing = apply_filters( 'luwipress_token_pricing', array(
			'openai' => array(
				'gpt-4o-mini'    => array( 'input' => 0.15, 'output' => 0.60 ),
				'gpt-4o'         => array( 'input' => 2.50, 'output' => 10.00 ),
				'gpt-4-turbo'    => array( 'input' => 10.00, 'output' => 30.00 ),
				'gpt-3.5-turbo'  => array( 'input' => 0.50, 'output' => 1.50 ),
			),
			'anthropic' => array(
				'claude-haiku-4-5'         => array( 'input' => 0.80,  'output' => 4.00 ),
				'claude-sonnet-4-6'        => array( 'input' => 3.00,  'output' => 15.00 ),
				'claude-sonnet-4-20250514' => array( 'input' => 3.00,  'output' => 15.00 ),
				'claude-opus-4-6'          => array( 'input' => 15.00, 'output' => 75.00 ),
			),
			'google' => array(
				'gemini-2.0-flash' => array( 'input' => 0.10, 'output' => 0.40 ),
				'gemini-2.5-flash' => array( 'input' => 0.15, 'output' => 0.60 ),
				'gemini-2.5-pro'   => array( 'input' => 1.25, 'output' => 10.00 ),
				'gemini-1.5-pro'   => array( 'input' => 1.25, 'output' => 5.00 ),
			),
		) );

		$rates = $pricing[ $provider ][ $model ] ?? $pricing['openai']['gpt-4o-mini'];

		return ( ( $input_tokens * $rates['input'] ) + ( $output_tokens * $rates['output'] ) ) / 1000000;
	}

	/**
	 * Guard: Check daily limit before any AI operation.
	 * Returns WP_Error if limit exceeded, true if allowed.
	 * Use this as a gate before every n8n webhook call that triggers AI.
	 */
	public static function check_budget( $workflow = '' ) {
		if ( ! self::is_limit_exceeded() ) {
			return true;
		}

		$limit = floatval( get_option( 'luwipress_daily_token_limit', 0 ) );
		$today = self::get_today_cost();

		LuwiPress_Logger::log(
			sprintf( 'Budget limit blocked: %s ($%.4f / $%.2f)', $workflow ?: 'unknown', $today, $limit ),
			'warning',
			array( 'workflow' => $workflow, 'today_cost' => $today, 'limit' => $limit )
		);

		return new WP_Error(
			'budget_exceeded',
			sprintf( 'Daily AI budget limit reached ($%.2f / $%.2f). Try again tomorrow.', $today, $limit ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Check if daily limit is exceeded.
	 */
	public static function is_limit_exceeded() {
		$limit = floatval( get_option( 'luwipress_daily_token_limit', 0 ) );
		if ( $limit <= 0 ) {
			return false; // No limit set
		}

		$today_cost = self::get_today_cost();
		return $today_cost >= $limit;
	}

	/**
	 * Get today's total cost.
	 */
	public static function get_today_cost() {
		$cache = get_transient( 'luwipress_today_token_cost' );
		if ( false !== $cache ) {
			return floatval( $cache );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'luwipress_token_usage';
		$cost  = $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(estimated_cost), 0) FROM {$table} WHERE date = %s",
			current_time( 'Y-m-d' )
		) );

		$cost = floatval( $cost );
		set_transient( 'luwipress_today_token_cost', $cost, 300 ); // 5 min cache
		return $cost;
	}

	/**
	 * Get usage stats for dashboard.
	 */
	public static function get_stats( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'luwipress_token_usage';

		// Today
		$today = $wpdb->get_row( $wpdb->prepare(
			"SELECT COALESCE(SUM(total_tokens), 0) as tokens,
			        COALESCE(SUM(estimated_cost), 0) as cost,
			        COUNT(*) as calls
			 FROM {$table} WHERE date = %s",
			current_time( 'Y-m-d' )
		) );

		// This month
		$month_start = current_time( 'Y-m-01' );
		$month = $wpdb->get_row( $wpdb->prepare(
			"SELECT COALESCE(SUM(total_tokens), 0) as tokens,
			        COALESCE(SUM(estimated_cost), 0) as cost,
			        COUNT(*) as calls
			 FROM {$table} WHERE date >= %s",
			$month_start
		) );

		// Daily breakdown (last N days)
		$daily = $wpdb->get_results( $wpdb->prepare(
			"SELECT date,
			        SUM(total_tokens) as tokens,
			        SUM(estimated_cost) as cost,
			        COUNT(*) as calls
			 FROM {$table}
			 WHERE date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
			 GROUP BY date ORDER BY date DESC",
			$days
		) );

		// By workflow
		$by_workflow = $wpdb->get_results( $wpdb->prepare(
			"SELECT workflow,
			        SUM(total_tokens) as tokens,
			        SUM(estimated_cost) as cost,
			        COUNT(*) as calls
			 FROM {$table}
			 WHERE date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
			 GROUP BY workflow ORDER BY cost DESC",
			$days
		) );

		$limit = floatval( get_option( 'luwipress_daily_token_limit', 0 ) );

		return array(
			'today'       => array(
				'tokens' => absint( $today->tokens ?? 0 ),
				'cost'   => round( floatval( $today->cost ?? 0 ), 4 ),
				'calls'  => absint( $today->calls ?? 0 ),
			),
			'month'       => array(
				'tokens' => absint( $month->tokens ?? 0 ),
				'cost'   => round( floatval( $month->cost ?? 0 ), 4 ),
				'calls'  => absint( $month->calls ?? 0 ),
			),
			'daily'       => $daily,
			'by_workflow' => $by_workflow,
			'daily_limit' => $limit,
			'limit_used'  => $limit > 0 ? round( ( self::get_today_cost() / $limit ) * 100 ) : 0,
		);
	}

	/**
	 * Update daily cache after recording.
	 */
	private static function update_daily_cache() {
		delete_transient( 'luwipress_today_token_cost' );
	}

	/**
	 * Get most recent API call records for dashboard table.
	 */
	public static function get_recent_calls( $limit = 20 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'luwipress_token_usage';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT date, workflow, model, input_tokens, output_tokens, total_tokens, estimated_cost, created_at
			 FROM {$table} ORDER BY created_at DESC LIMIT %d",
			$limit
		) );
	}

	/**
	 * Cleanup old records.
	 */
	public static function cleanup( $days = 90 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'luwipress_token_usage';
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
			$days
		) );
	}
}
