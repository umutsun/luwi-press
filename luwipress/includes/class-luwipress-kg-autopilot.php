<?php
/**
 * KG Autopilot — Track D.3 (3.1.47+).
 *
 * The third layer of the KG middleware backbone. Where D.1 (Signals) records
 * what just happened and D.2 (Opportunities v2) ranks what should happen
 * next, D.3 actually DOES the work — autonomously, within hard caps, with
 * dry-run semantics, and with full audit. The default is OFF; even when an
 * operator turns it on, it ships in dry-run mode so the first run only logs
 * "would dispatch X" without touching content.
 *
 * Pipeline per cycle:
 *
 *   1. Read settings option `luwipress_kg_autopilot_settings`.
 *      `enabled=false` → exit immediately (cron does nothing).
 *   2. Build candidates via `LuwiPress_KG_Opportunities::build_candidates()`.
 *   3. Filter by `confidence_score >= min_confidence`.
 *   4. Sort by ROI desc, group by workflow.
 *   5. For each workflow, enforce daily cap: count rows in `wp_luwipress_logs`
 *      with `level='kg_autopilot'` + matching workflow + within window.
 *   6. For each remaining candidate (within cap):
 *        - dry_run=true → record `{action:'would_dispatch', candidate, dispatched:false}`
 *        - dry_run=false → enqueue `wp_schedule_single_event` for async dispatch
 *          and record `{action:'dispatched', candidate, dispatched:true, scheduled_at}`.
 *   7. Idempotency: skip if entity has `_luwipress_kg_autopilot_dispatched_at`
 *      set within `dispatch_window_hours`.
 *
 * Manual trigger via REST endpoint runs the cycle once on demand (still
 * respects caps + dry-run).
 *
 * Workflow support v1: only `enrich` is wired through. `seo`, `translate`,
 * `queue-review` candidates are recognized but recorded as "dispatch_pending"
 * placeholder in the log so the operator can see "what autopilot would have
 * done" until each workflow is wired in subsequent releases.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_KG_Autopilot {

	const SETTINGS_OPTION   = 'luwipress_kg_autopilot_settings';
	const LOG_LEVEL         = 'kg_autopilot';
	const CRON_HOOK         = 'luwipress_kg_autopilot_cycle';
	const DISPATCH_HOOK     = 'luwipress_kg_autopilot_dispatch_one';
	const ENTITY_META_KEY   = '_luwipress_kg_autopilot_dispatched_at';

	/**
	 * Default settings. Operator-facing keys are stable across releases.
	 */
	const DEFAULTS = array(
		'enabled'               => false,
		'dry_run'               => true,
		'min_confidence'        => 60,
		'dispatch_window_hours' => 24,
		'caps'                  => array(
			'enrich'    => 5,
			'translate' => 3,
			'seo'       => 5,
			'queue-review' => 0, // not autopiloted
		),
	);

	private static $instance;

	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init',                  array( $this, 'maybe_schedule_cron' ) );
		add_action( self::CRON_HOOK,         array( $this, 'cron_run_cycle' ) );
		add_action( self::DISPATCH_HOOK,     array( $this, 'dispatch_one' ), 10, 1 );
	}

	/**
	 * Cron-bound entrypoint. Wraps `run_cycle()` so the action callback
	 * returns void (PHPStan is strict about action returns).
	 */
	public function cron_run_cycle() {
		$this->run_cycle();
	}

	public function maybe_schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/* ───────────────────────────────────────────────────────────────── *
	 * Settings                                                            *
	 * ───────────────────────────────────────────────────────────────── */

	public function get_settings() {
		$raw = get_option( self::SETTINGS_OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array_replace_recursive( self::DEFAULTS, $raw );
	}

	/**
	 * Partial-update merge — only present keys touched, rest stay at saved
	 * (or default) value. Caps key merges per-workflow.
	 */
	public function set_settings( $patch ) {
		$cur = $this->get_settings();
		if ( isset( $patch['enabled'] ) )               $cur['enabled']               = (bool) $patch['enabled'];
		if ( isset( $patch['dry_run'] ) )               $cur['dry_run']               = (bool) $patch['dry_run'];
		if ( isset( $patch['min_confidence'] ) )        $cur['min_confidence']        = max( 0, min( 100, (int) $patch['min_confidence'] ) );
		if ( isset( $patch['dispatch_window_hours'] ) ) $cur['dispatch_window_hours'] = max( 1, min( 720, (int) $patch['dispatch_window_hours'] ) );
		if ( isset( $patch['caps'] ) && is_array( $patch['caps'] ) ) {
			foreach ( $patch['caps'] as $wf => $cap ) {
				$wf = sanitize_key( $wf );
				if ( isset( $cur['caps'][ $wf ] ) ) {
					$cur['caps'][ $wf ] = max( 0, min( 100, (int) $cap ) );
				}
			}
		}
		update_option( self::SETTINGS_OPTION, $cur, false );
		return $cur;
	}

	/* ───────────────────────────────────────────────────────────────── *
	 * Cycle                                                               *
	 * ───────────────────────────────────────────────────────────────── */

	/**
	 * Main cycle entrypoint. Safe to call multiple times — caps + idempotency
	 * meta prevent double-dispatch.
	 *
	 * @return array { dispatched: int, would_dispatch: int, skipped: int, caps_used }
	 */
	public function run_cycle( $force_run = false ) {
		$s = $this->get_settings();
		if ( ! $s['enabled'] && ! $force_run ) {
			return array( 'enabled' => false, 'message' => 'Autopilot is disabled.' );
		}
		if ( ! class_exists( 'LuwiPress_KG_Opportunities' ) ) {
			return array( 'error' => 'KG opportunities layer not loaded.' );
		}

		$candidates = LuwiPress_KG_Opportunities::get_instance()->build_candidates( 50 );

		$dispatched = 0;
		$would      = 0;
		$skipped    = 0;
		$caps_used  = array_fill_keys( array_keys( $s['caps'] ), 0 );

		// Pre-compute current cap usage for each workflow within the window.
		foreach ( $caps_used as $wf => $_ ) {
			$caps_used[ $wf ] = $this->dispatch_audit_count( $wf, $s['dispatch_window_hours'] );
		}

		foreach ( $candidates as $c ) {
			$wf  = isset( $c['workflow'] ) ? sanitize_key( $c['workflow'] ) : '';
			$cap = $s['caps'][ $wf ] ?? 0;

			$confidence = isset( $c['confidence_score'] ) ? (int) $c['confidence_score'] : $this->derive_confidence( $c );

			if ( $confidence < $s['min_confidence'] ) {
				$skipped++;
				continue;
			}
			if ( $cap <= 0 ) {
				// workflow not autopiloted (e.g. queue-review)
				$skipped++;
				continue;
			}
			if ( $caps_used[ $wf ] >= $cap ) {
				$skipped++;
				continue;
			}
			if ( ! empty( $c['entity_id'] ) && $this->was_recently_dispatched( (int) $c['entity_id'], $s['dispatch_window_hours'] ) ) {
				$skipped++;
				continue;
			}

			if ( $s['dry_run'] ) {
				$this->log_dispatch( $c, $confidence, 'would_dispatch', null );
				$would++;
				$caps_used[ $wf ]++; // count dry-run too so the operator sees what real run would do
				continue;
			}

			// Real dispatch — async via single-event cron so the cycle stays cheap.
			wp_schedule_single_event( time() + 5 + $dispatched, self::DISPATCH_HOOK, array( array(
				'candidate_id'   => $c['id'],
				'workflow'       => $wf,
				'entity_type'    => $c['entity_type'] ?? '',
				'entity_id'      => (int) ( $c['entity_id'] ?? 0 ),
				'title'          => $c['title'] ?? '',
				'why'            => $c['why'] ?? array(),
				'confidence'     => $confidence,
			) ) );
			$this->log_dispatch( $c, $confidence, 'dispatched', array( 'scheduled_in_seconds' => 5 + $dispatched ) );
			$this->mark_dispatched( (int) ( $c['entity_id'] ?? 0 ) );
			$dispatched++;
			$caps_used[ $wf ]++;
		}

		$summary = array(
			'enabled'        => true,
			'dry_run'        => $s['dry_run'],
			'dispatched'     => $dispatched,
			'would_dispatch' => $would,
			'skipped'        => $skipped,
			'caps_used'      => $caps_used,
			'caps'           => $s['caps'],
			'cycle_at'       => current_time( 'mysql' ),
		);

		LuwiPress_Logger::log(
			sprintf( 'KG autopilot cycle complete: dispatched=%d would=%d skipped=%d', $dispatched, $would, $skipped ),
			self::LOG_LEVEL,
			array_merge( array( 'action' => 'cycle_summary' ), $summary )
		);

		return $summary;
	}

	/**
	 * Single-event callback that actually fires a workflow. Runs in its own
	 * cron tick so cycle() stays cheap. Only `enrich` is wired in v1; other
	 * workflows record a "pending_implementation" entry.
	 */
	public function dispatch_one( $job ) {
		if ( ! is_array( $job ) || empty( $job['workflow'] ) || empty( $job['entity_id'] ) ) {
			return;
		}
		$wf  = sanitize_key( $job['workflow'] );
		$pid = (int) $job['entity_id'];

		switch ( $wf ) {
			case 'enrich':
				if ( ! class_exists( 'LuwiPress_AI_Content' ) || ! function_exists( 'wc_get_product' ) ) {
					$this->log_dispatch_outcome( $job, 'skipped', 'AI content / WC unavailable' );
					return;
				}
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					$this->log_dispatch_outcome( $job, 'skipped', 'product not found' );
					return;
				}
				// Synthetic REST request so we go through the same validation
				// as a normal /product/enrich call (translation guards, locks,
				// auto-snapshot, etc.).
				$req = new WP_REST_Request( 'POST', '/luwipress/v1/product/enrich' );
				$req->set_param( 'product_id', $pid );
				$req->set_param( 'options', array( 'autopilot' => true ) );
				$res = LuwiPress_AI_Content::get_instance()->handle_enrich_request( $req );
				if ( is_wp_error( $res ) ) {
					$this->log_dispatch_outcome( $job, 'failed', $res->get_error_message() );
					return;
				}
				$this->log_dispatch_outcome( $job, 'completed', null );
				return;

			case 'seo':
			case 'translate':
				$this->log_dispatch_outcome( $job, 'pending_implementation', 'workflow ' . $wf . ' not wired in v1' );
				return;

			default:
				$this->log_dispatch_outcome( $job, 'skipped', 'unknown workflow ' . $wf );
				return;
		}
	}

	/* ───────────────────────────────────────────────────────────────── *
	 * Confidence + dispatch helpers                                       *
	 * ───────────────────────────────────────────────────────────────── */

	/**
	 * Derive a 0-100 confidence score from candidate metadata when the
	 * Opportunities layer didn't populate one. Heuristic: high impact +
	 * tier=high → high confidence; stale entries with very old enrichment
	 * trend toward 80; regressed entries scale by pct_change.
	 */
	private function derive_confidence( $c ) {
		$type = $c['type'] ?? '';
		if ( 'recently_regressed' === $type ) {
			$pct = (float) ( $c['why']['baseline_comparison']['pct_change'] ?? 0 );
			return max( 0, min( 100, (int) round( 50 + $pct * 4 ) ) );
		}
		if ( 'stale_enriched' === $type ) {
			// Stale > 90d hits 65; > 180d hits 85; > 365d hits 95.
			$signal = $c['why']['primary_signal'] ?? '';
			if ( preg_match( '/(\d+)d old/', $signal, $m ) ) {
				$days = (int) $m[1];
				if ( $days > 365 ) return 95;
				if ( $days > 180 ) return 85;
				if ( $days > 120 ) return 75;
				return 65;
			}
			return 65;
		}
		// Fallback — derive from tier + impact magnitude.
		$tier_map = array( 'high' => 80, 'medium' => 60, 'low' => 40 );
		return $tier_map[ $c['tier'] ?? 'medium' ] ?? 50;
	}

	private function dispatch_audit_count( $workflow, $hours ) {
		global $wpdb;
		$table = $wpdb->prefix . 'luwipress_logs';
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );
		$sql   = $wpdb->prepare(
			"SELECT context FROM {$table} WHERE level = %s AND timestamp >= %s ORDER BY id DESC LIMIT 500",
			self::LOG_LEVEL,
			$since
		);
		$rows = $wpdb->get_col( $sql );
		$n    = 0;
		foreach ( $rows as $raw ) {
			$ctx = json_decode( $raw, true );
			if ( ! is_array( $ctx ) ) continue;
			$action = $ctx['action'] ?? '';
			if ( 'dispatched' !== $action && 'would_dispatch' !== $action ) continue;
			if ( ( $ctx['workflow'] ?? '' ) === $workflow ) $n++;
		}
		return $n;
	}

	private function was_recently_dispatched( $entity_id, $hours ) {
		if ( $entity_id <= 0 ) return false;
		$last = (int) get_post_meta( $entity_id, self::ENTITY_META_KEY, true );
		if ( ! $last ) return false;
		return ( time() - $last ) < ( $hours * HOUR_IN_SECONDS );
	}

	private function mark_dispatched( $entity_id ) {
		if ( $entity_id <= 0 ) return;
		update_post_meta( $entity_id, self::ENTITY_META_KEY, time() );
	}

	private function log_dispatch( $candidate, $confidence, $action, $extra = null ) {
		$ctx = array(
			'action'         => $action,
			'workflow'       => $candidate['workflow'] ?? '',
			'candidate_id'   => $candidate['id'] ?? '',
			'candidate_type' => $candidate['type'] ?? '',
			'entity_type'    => $candidate['entity_type'] ?? '',
			'entity_id'      => (int) ( $candidate['entity_id'] ?? 0 ),
			'title'          => $candidate['title'] ?? '',
			'confidence'     => (int) $confidence,
			'tier'           => $candidate['tier'] ?? '',
			'roi'            => $candidate['roi'] ?? 0,
		);
		if ( is_array( $extra ) ) {
			$ctx = array_merge( $ctx, $extra );
		}
		LuwiPress_Logger::log(
			sprintf( '[autopilot %s] %s', $action, $candidate['title'] ?? '?' ),
			self::LOG_LEVEL,
			$ctx
		);
	}

	private function log_dispatch_outcome( $job, $outcome, $note ) {
		LuwiPress_Logger::log(
			sprintf( '[autopilot dispatch] %s — %s', $outcome, $job['title'] ?? '?' ),
			self::LOG_LEVEL,
			array(
				'action'        => 'dispatch_outcome',
				'outcome'       => $outcome,
				'workflow'      => $job['workflow'] ?? '',
				'candidate_id'  => $job['candidate_id'] ?? '',
				'entity_id'     => (int) ( $job['entity_id'] ?? 0 ),
				'note'          => (string) $note,
			)
		);
	}

	/* ───────────────────────────────────────────────────────────────── *
	 * Public log query                                                    *
	 * ───────────────────────────────────────────────────────────────── */

	public function get_log( $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'luwipress_logs';
		$limit = max( 1, min( 500, (int) $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, timestamp, message, context FROM {$table} WHERE level = %s ORDER BY id DESC LIMIT %d",
				self::LOG_LEVEL,
				$limit
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$ctx = ! empty( $row['context'] ) ? json_decode( $row['context'], true ) : array();
			$out[] = array(
				'id'        => (int) $row['id'],
				'timestamp' => $row['timestamp'],
				'message'   => $row['message'],
				'context'   => is_array( $ctx ) ? $ctx : array(),
			);
		}
		return $out;
	}
}
