<?php
/**
 * n8nPress Job Queue
 *
 * Async job processing via wp_cron — replaces n8n webhook async pattern.
 * Jobs are stored in a custom DB table and processed in batches.
 *
 * @package n8nPress
 * @since 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class N8nPress_Job_Queue {

	const TABLE_SUFFIX = 'n8npress_jobs';
	const CRON_HOOK    = 'n8npress_process_job_queue';

	/**
	 * Initialize the job queue system.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_queue' ) );

		// Schedule cron if not already scheduled
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'every_minute', self::CRON_HOOK );
		}

		// Register custom interval
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );
	}

	/**
	 * Add 1-minute cron interval.
	 */
	public static function add_cron_interval( $schedules ) {
		$schedules['every_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every Minute', 'n8npress' ),
		);
		return $schedules;
	}

	/**
	 * Create the jobs database table.
	 */
	public static function create_table() {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE_SUFFIX;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			type varchar(50) NOT NULL,
			payload longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			result longtext DEFAULT NULL,
			error_message text DEFAULT NULL,
			attempts int NOT NULL DEFAULT 0,
			max_attempts int NOT NULL DEFAULT 3,
			priority int NOT NULL DEFAULT 10,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY status_priority (status, priority, created_at),
			KEY type_status (type, status)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Add a job to the queue.
	 *
	 * @param string $type    Job type: enrich_product, translate_product, generate_aeo, generate_content.
	 * @param array  $payload Job data.
	 * @param int    $priority Lower = higher priority. Default 10.
	 * @return int|false       Job ID or false on failure.
	 */
	public static function add( $type, $payload, $priority = 10 ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->prefix . self::TABLE_SUFFIX,
			array(
				'type'       => sanitize_text_field( $type ),
				'payload'    => wp_json_encode( $payload ),
				'status'     => 'pending',
				'priority'   => $priority,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		$job_id = $wpdb->insert_id;

		N8nPress_Logger::log( "Job queued: #{$job_id} [{$type}]", 'debug', array(
			'job_id'  => $job_id,
			'type'    => $type,
			'payload' => $payload,
		) );

		return $job_id;
	}

	/**
	 * Process pending jobs from the queue.
	 * Runs via wp_cron every minute. Processes up to 3 jobs per run.
	 */
	public static function process_queue() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// Check if table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return;
		}

		// Pick up pending jobs (max 3 per run, ordered by priority then age)
		$jobs = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE status = 'pending' AND attempts < max_attempts
			 ORDER BY priority ASC, created_at ASC
			 LIMIT %d",
			3
		) );

		foreach ( $jobs as $job ) {
			self::process_job( $job );
		}

		// Retry failed jobs (with exponential backoff)
		$retry_jobs = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE status = 'failed'
			   AND attempts < max_attempts
			   AND completed_at < DATE_SUB(NOW(), INTERVAL POWER(2, attempts) MINUTE)
			 ORDER BY priority ASC
			 LIMIT %d",
			2
		) );

		foreach ( $retry_jobs as $job ) {
			$wpdb->update(
				$table,
				array( 'status' => 'pending' ),
				array( 'id' => $job->id ),
				array( '%s' ),
				array( '%d' )
			);
			self::process_job( $job );
		}
	}

	/**
	 * Process a single job.
	 */
	private static function process_job( $job ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// Mark as processing
		$wpdb->update(
			$table,
			array(
				'status'     => 'processing',
				'started_at' => current_time( 'mysql' ),
				'attempts'   => $job->attempts + 1,
			),
			array( 'id' => $job->id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);

		$payload = json_decode( $job->payload, true );
		$result  = null;
		$error   = null;

		try {
			switch ( $job->type ) {
				case 'enrich_product':
					$result = self::handle_enrich_product( $payload );
					break;

				case 'translate_product':
					$result = self::handle_translate_product( $payload );
					break;

				case 'generate_aeo':
					$result = self::handle_generate_aeo( $payload );
					break;

				default:
					$error = "Unknown job type: {$job->type}";
			}
		} catch ( \Exception $e ) {
			$error = $e->getMessage();
		}

		if ( is_wp_error( $result ) ) {
			$error = $result->get_error_message();
			$result = null;
		}

		// Update job status
		$new_status = $error ? 'failed' : 'completed';
		$wpdb->update(
			$table,
			array(
				'status'        => $new_status,
				'result'        => $result ? wp_json_encode( $result ) : null,
				'error_message' => $error,
				'completed_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $job->id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		$level = $error ? 'error' : 'info';
		N8nPress_Logger::log(
			"Job #{$job->id} [{$job->type}] → {$new_status}" . ( $error ? ": {$error}" : '' ),
			$level,
			array( 'job_id' => $job->id, 'type' => $job->type, 'attempt' => $job->attempts + 1 )
		);
	}

	// ─── Job Handlers ───────────────────────────────────────────────────

	private static function handle_enrich_product( $payload ) {
		$product_id = absint( $payload['product_id'] ?? 0 );
		if ( ! $product_id ) {
			return new WP_Error( 'invalid', 'Missing product_id' );
		}

		update_post_meta( $product_id, '_n8npress_enrich_status', 'processing' );

		$result = N8nPress_AI_Engine::enrich_product( $product_id, $payload['options'] ?? array() );
		if ( is_wp_error( $result ) ) {
			update_post_meta( $product_id, '_n8npress_enrich_status', 'failed' );
			return $result;
		}

		// Save enriched data
		$product = wc_get_product( $product_id );
		$updated = array();

		if ( ! empty( $result['description'] ) ) {
			$product->set_description( wp_kses_post( $result['description'] ) );
			$updated[] = 'description';
		}
		if ( ! empty( $result['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $result['short_description'] ) );
			$updated[] = 'short_description';
		}
		$product->save();

		// SEO meta
		if ( ! empty( $result['meta_title'] ) || ! empty( $result['meta_description'] ) ) {
			$detector = N8nPress_Plugin_Detector::get_instance();
			$seo_data = array();
			if ( ! empty( $result['meta_title'] ) )       $seo_data['title'] = $result['meta_title'];
			if ( ! empty( $result['meta_description'] ) )  $seo_data['description'] = $result['meta_description'];
			$detector->set_seo_meta( $product_id, $seo_data );
			$updated[] = 'seo_meta';
		}

		// FAQ
		if ( ! empty( $result['faq'] ) && is_array( $result['faq'] ) ) {
			$sanitized = array();
			foreach ( $result['faq'] as $item ) {
				if ( ! empty( $item['question'] ) && ! empty( $item['answer'] ) ) {
					$sanitized[] = array(
						'question' => sanitize_text_field( $item['question'] ),
						'answer'   => wp_kses_post( $item['answer'] ),
					);
				}
			}
			update_post_meta( $product_id, '_n8npress_faq', $sanitized );
			$updated[] = 'faq';
		}

		update_post_meta( $product_id, '_n8npress_enrich_status', 'completed' );
		update_post_meta( $product_id, '_n8npress_enrich_completed', current_time( 'mysql' ) );
		update_post_meta( $product_id, '_n8npress_enrich_fields', $updated );

		// Purge cache
		N8nPress_Plugin_Detector::get_instance()->purge_post_cache( $product_id );

		return array( 'product_id' => $product_id, 'updated_fields' => $updated );
	}

	private static function handle_translate_product( $payload ) {
		$product_id = absint( $payload['product_id'] ?? 0 );
		$language   = sanitize_text_field( $payload['language'] ?? '' );

		if ( ! $product_id || ! $language ) {
			return new WP_Error( 'invalid', 'Missing product_id or language' );
		}

		$result = N8nPress_AI_Engine::translate_product( $product_id, $language, $payload['options'] ?? array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Save via translation callback (reuse existing WPML/Polylang logic)
		if ( class_exists( 'N8nPress_Translation' ) ) {
			$translation = N8nPress_Translation::get_instance();

			// Build a mock REST request to reuse handle_translation_callback
			$request = new WP_REST_Request( 'POST' );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( array(
				'product_id' => $product_id,
				'language'   => $language,
				'status'     => 'completed',
				'content'    => array(
					'name'             => $result['name'] ?? $result['title'] ?? '',
					'description'      => $result['description'] ?? '',
					'short_description' => $result['short_description'] ?? '',
					'meta_title'       => $result['meta_title'] ?? '',
					'meta_description' => $result['meta_description'] ?? '',
					'focus_keyword'    => $result['focus_keyword'] ?? '',
					'slug'             => $result['slug'] ?? '',
				),
			) ) );

			return $translation->handle_translation_callback( $request );
		}

		return $result;
	}

	private static function handle_generate_aeo( $payload ) {
		$product_id = absint( $payload['product_id'] ?? 0 );
		if ( ! $product_id ) {
			return new WP_Error( 'invalid', 'Missing product_id' );
		}

		$result = N8nPress_AI_Engine::generate_aeo( $product_id, $payload['options'] ?? array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Save FAQ
		if ( ! empty( $result['faqs'] ) && is_array( $result['faqs'] ) ) {
			$sanitized = array();
			foreach ( $result['faqs'] as $item ) {
				if ( ! empty( $item['question'] ) && ! empty( $item['answer'] ) ) {
					$sanitized[] = array(
						'question' => sanitize_text_field( $item['question'] ),
						'answer'   => wp_kses_post( $item['answer'] ),
					);
				}
			}
			update_post_meta( $product_id, '_n8npress_faq', $sanitized );
		}

		// Save HowTo
		if ( ! empty( $result['howto'] ) ) {
			update_post_meta( $product_id, '_n8npress_howto', $result['howto'] );
		}

		// Save Speakable
		if ( ! empty( $result['speakable'] ) ) {
			update_post_meta( $product_id, '_n8npress_speakable', sanitize_text_field( $result['speakable'] ) );
		}

		delete_transient( 'n8npress_aeo_coverage' );
		N8nPress_Plugin_Detector::get_instance()->purge_post_cache( $product_id );

		return array( 'product_id' => $product_id, 'generated' => array_keys( array_filter( $result ) ) );
	}

	// ─── Status & Admin ─────────────────────────────────────────────────

	/**
	 * Get queue statistics.
	 */
	public static function get_stats() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return array( 'pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0 );
		}

		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status"
		);

		$stats = array( 'pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0 );
		foreach ( $rows as $r ) {
			$stats[ $r->status ] = intval( $r->cnt );
		}

		return $stats;
	}

	/**
	 * Get recent jobs for admin display.
	 */
	public static function get_recent( $limit = 20 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return array();
		}

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, type, status, attempts, error_message, created_at, started_at, completed_at
			 FROM {$table}
			 ORDER BY created_at DESC
			 LIMIT %d",
			$limit
		) );
	}

	/**
	 * Cleanup completed jobs older than N days.
	 */
	public static function cleanup( $days = 7 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE status IN ('completed', 'failed') AND completed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$days
		) );
	}

	/**
	 * Clear scheduled cron on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
