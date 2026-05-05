<?php
/**
 * KG Opportunities v2 — Track D.2 (3.1.46+).
 *
 * The Action Queue shipped in 3.1.21 ranks candidates by `roi = impact /
 * effort_min` from a 6-type catalogue (worst-covered SEO category, language
 * gap, taxonomy translation gap, alt-text gap, AEO gap, top single product).
 * That logic lives client-side in `admin/assets/js/knowledge-graph.js`.
 *
 * D.2 adds two server-computed candidate types whose signals require the
 * 30-day summary history + KG event stream we now have:
 *
 *   • RECENTLY_REGRESSED — entities or coverage dimensions that LOST
 *     ground in the last 7 days. Computed against `luwipress_kg_summary_history`
 *     (existing 30-day option ring written by `update_and_get_summary_trend()`).
 *
 *   • STALE_ENRICHED — products enriched > 90 days ago whose source
 *     content has been edited since. Surfaces "the AI write is now out
 *     of date" without forcing an autopilot.
 *
 * Each candidate carries a `why` payload describing the primary signal +
 * supporting evidence so the UI can explain ranking instead of just
 * showing a number. The JS Action Queue receives candidates from the
 * existing KG response under `opportunities.next_wins_v2` (additive — old
 * `next_wins` array stays so existing clients keep working).
 *
 * Persistent candidate state (snooze / dismiss / in_progress) is kept in
 * the `luwipress_kg_candidate_state` option, keyed by stable candidate ID
 * (e.g. `regressed:enrichment_coverage`, `stale:product:1234`). Snoozed
 * entries return to the queue automatically when their `until` ts passes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_KG_Opportunities {

	const STATE_OPTION   = 'luwipress_kg_candidate_state';
	const STATE_SNOOZED  = 'snoozed';
	const STATE_DISMISS  = 'dismissed';
	const STATE_PROGRESS = 'in_progress';

	const SNOOZE_DEFAULT_HOURS = 24;
	const DISMISS_TTL_DAYS     = 30;

	const STALE_AFTER_DAYS = 90;

	private static $instance;

	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Daily prune — drop dismissed entries older than DISMISS_TTL_DAYS.
		add_action( 'luwipress_daily_cleanup', array( $this, 'prune_state' ) );
	}

	/* ───────────────────────────────────────────────────────────────── *
	 * Public — candidate generators                                       *
	 * ───────────────────────────────────────────────────────────────── */

	/**
	 * Return the v2 candidate list ready for the Action Queue. Each item:
	 *   {
	 *     id, type, title, body, impact, effort_min, roi, tier,
	 *     entity_type, entity_id,
	 *     why: { primary_signal, supporting_signals[], baseline_comparison }
	 *   }
	 *
	 * @param int $limit Cap on returned candidates after filtering by state.
	 * @return array
	 */
	public function build_candidates( $limit = 6 ) {
		$candidates = array();

		foreach ( $this->build_regressed_candidates() as $c ) {
			$candidates[] = $c;
		}
		foreach ( $this->build_stale_enriched_candidates() as $c ) {
			$candidates[] = $c;
		}

		// Filter out hidden states + sort by ROI desc.
		$state = $this->get_state();
		$now   = time();
		$out   = array();
		foreach ( $candidates as $c ) {
			$id      = $c['id'];
			$persist = isset( $state[ $id ] ) ? $state[ $id ] : null;
			if ( $persist && self::STATE_DISMISS === $persist['state'] ) {
				continue;
			}
			if ( $persist && self::STATE_SNOOZED === $persist['state'] && (int) ( $persist['until'] ?? 0 ) > $now ) {
				continue;
			}
			if ( $persist && self::STATE_PROGRESS === $persist['state'] ) {
				$c['state'] = self::STATE_PROGRESS;
			}
			$out[] = $c;
		}

		usort( $out, function ( $a, $b ) {
			return ( $b['roi'] ?? 0 ) <=> ( $a['roi'] ?? 0 );
		} );

		// Attach confidence_score (Track D.3) — reused by autopilot for the
		// `min_confidence` gate. Range 0..100. Stored on the candidate so
		// API consumers + UI can surface it consistently.
		foreach ( $out as &$c ) {
			$c['confidence_score'] = $this->confidence_for_candidate( $c );
		}
		unset( $c );

		return array_slice( $out, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Derive a 0-100 confidence value from the candidate's signal strength.
	 * RECENTLY_REGRESSED: scales with pct_change (10% drop ≈ 90 confidence).
	 * STALE_ENRICHED: scales with days since enrichment.
	 * Other types: tier-based fallback.
	 */
	private function confidence_for_candidate( $c ) {
		$type = $c['type'] ?? '';
		if ( 'recently_regressed' === $type ) {
			$pct = (float) ( $c['why']['baseline_comparison']['pct_change'] ?? 0 );
			return max( 0, min( 100, (int) round( 50 + $pct * 4 ) ) );
		}
		if ( 'stale_enriched' === $type ) {
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
		$tier_map = array( 'high' => 80, 'medium' => 60, 'low' => 40 );
		return $tier_map[ $c['tier'] ?? 'medium' ] ?? 50;
	}

	/* ───────────────────────────────────────────────────────────────── *
	 * Candidate type 1 — RECENTLY_REGRESSED                              *
	 * ───────────────────────────────────────────────────────────────── */

	private function build_regressed_candidates() {
		$history = get_option( 'luwipress_kg_summary_history', array() );
		if ( ! is_array( $history ) || count( $history ) < 2 ) {
			return array();
		}

		// Sort by date asc to simplify "now vs 7 days ago" lookup.
		usort( $history, function ( $a, $b ) {
			return strcmp( (string) ( $a['date'] ?? '' ), (string) ( $b['date'] ?? '' ) );
		} );
		$now      = end( $history );
		$baseline = $this->find_baseline( $history, 7 );
		if ( ! $baseline ) {
			return array();
		}

		$out = array();
		$dimensions = array(
			'seo_coverage'        => array(
				'label'      => __( 'SEO coverage', 'luwipress' ),
				'unit'       => '%',
				'effort_min' => 30,
				'impact'     => 80,
				'workflow'   => 'seo',
			),
			'enrichment_coverage' => array(
				'label'      => __( 'Enrichment coverage', 'luwipress' ),
				'unit'       => '%',
				'effort_min' => 45,
				'impact'     => 100,
				'workflow'   => 'enrich',
			),
			'opportunity_total'   => array(
				'label'      => __( 'Opportunity total', 'luwipress' ),
				'unit'       => 'pts',
				'effort_min' => 60,
				'impact'     => 60,
				'workflow'   => 'queue-review',
				'inverted'   => true, // a higher number is WORSE for opportunity_total
			),
		);

		foreach ( $dimensions as $key => $cfg ) {
			$cur  = (float) ( $now[ $key ] ?? 0 );
			$base = (float) ( $baseline[ $key ] ?? 0 );
			if ( 0.0 === $base && 0.0 === $cur ) continue;

			$delta = empty( $cfg['inverted'] ) ? ( $cur - $base ) : ( $base - $cur );
			if ( $delta >= 0 ) {
				continue; // improving or flat — not a regression
			}

			$abs        = abs( $delta );
			$pct_change = 0.0 !== $base ? ( $abs / abs( $base ) ) * 100 : 0;

			// Skip noise — < 1.5% movement isn't a real regression.
			if ( $pct_change < 1.5 ) {
				continue;
			}

			$id     = 'regressed:' . $key;
			$impact = (int) round( $cfg['impact'] * min( 1.5, $pct_change / 5 ) );
			$out[]  = array(
				'id'          => $id,
				'type'        => 'recently_regressed',
				'title'       => sprintf(
					/* translators: 1: dimension label */
					__( '%1$s slipped this week', 'luwipress' ),
					$cfg['label']
				),
				'body'        => sprintf(
					/* translators: 1: percent change, 2: dimension label, 3: baseline date */
					__( 'Down %1$s%% vs %3$s — review the queue and run a small batch to recover %2$s.', 'luwipress' ),
					number_format( $pct_change, 1 ),
					$cfg['label'],
					$baseline['date'] ?? ''
				),
				'impact'      => $impact,
				'effort_min'  => $cfg['effort_min'],
				'roi'         => round( $impact / max( 1, $cfg['effort_min'] ), 2 ),
				'tier'        => $pct_change > 5 ? 'high' : ( $pct_change > 2 ? 'medium' : 'low' ),
				'entity_type' => 'global',
				'entity_id'   => 0,
				'workflow'    => $cfg['workflow'],
				'why'         => array(
					'primary_signal'      => sprintf( '%s -%.1f%%', $cfg['label'], $pct_change ),
					'supporting_signals'  => array(
						sprintf( 'baseline %.1f%s on %s', $base, $cfg['unit'], $baseline['date'] ?? '?' ),
						sprintf( 'current %.1f%s', $cur, $cfg['unit'] ),
					),
					'baseline_comparison' => array(
						'baseline_date'  => $baseline['date'] ?? '',
						'baseline_value' => $base,
						'current_value'  => $cur,
						'pct_change'     => $pct_change,
					),
				),
			);
		}

		return $out;
	}

	private function find_baseline( $history, $days_back ) {
		$target = strtotime( '-' . (int) $days_back . ' days', current_time( 'timestamp' ) );
		$best   = null;
		$best_d = PHP_INT_MAX;
		foreach ( $history as $row ) {
			$ts = strtotime( $row['date'] ?? '' );
			if ( ! $ts ) continue;
			$d = abs( $ts - $target );
			if ( $d < $best_d ) {
				$best_d = $d;
				$best   = $row;
			}
		}
		return $best;
	}

	/* ───────────────────────────────────────────────────────────────── *
	 * Candidate type 2 — STALE_ENRICHED                                  *
	 * ───────────────────────────────────────────────────────────────── */

	private function build_stale_enriched_candidates() {
		global $wpdb;

		// Find products whose `_luwipress_enrich_completed` is older than
		// STALE_AFTER_DAYS but whose post_modified is more recent. Limit
		// to a reasonable batch — operator surfaces them one at a time.
		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( self::STALE_AFTER_DAYS * DAY_IN_SECONDS ) );

		$sql = $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_modified, pm.meta_value AS enrich_completed
			   FROM {$wpdb->posts} p
			   INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_luwipress_enrich_completed'
			   WHERE p.post_type = 'product'
			     AND p.post_status = 'publish'
			     AND pm.meta_value < %s
			     AND p.post_modified > pm.meta_value
			   ORDER BY p.post_modified DESC
			   LIMIT 20",
			$threshold
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( empty( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$pid     = (int) $row['ID'];
			$days    = max( 1, (int) round( ( time() - strtotime( $row['enrich_completed'] ) ) / DAY_IN_SECONDS ) );
			$mod_age = max( 0, (int) round( ( time() - strtotime( $row['post_modified'] ) ) / DAY_IN_SECONDS ) );

			// Impact rises with how stale the enrich is + how recently
			// content was touched. Effort is constant — single re-enrich.
			$impact = (int) min( 60, 20 + ( $days - self::STALE_AFTER_DAYS ) / 3 );
			$out[]  = array(
				'id'          => 'stale:product:' . $pid,
				'type'        => 'stale_enriched',
				'title'       => sprintf(
					/* translators: 1: product title */
					__( '"%1$s" — re-enrich (content changed since last AI write)', 'luwipress' ),
					$row['post_title']
				),
				'body'        => sprintf(
					/* translators: 1: days since last enrich, 2: days since content modified */
					__( 'Last enriched %1$d days ago. Source content edited %2$d days ago. The AI-generated description / FAQ / schema is likely out of sync.', 'luwipress' ),
					$days,
					$mod_age
				),
				'impact'      => $impact,
				'effort_min'  => 2,
				'roi'         => round( $impact / 2, 2 ),
				'tier'        => $days > 180 ? 'high' : 'medium',
				'entity_type' => 'product',
				'entity_id'   => $pid,
				'workflow'    => 'enrich',
				'why'         => array(
					'primary_signal'     => sprintf( 'enrichment %dd old, content edited %dd ago', $days, $mod_age ),
					'supporting_signals' => array(
						sprintf( 'last enrich %s', $row['enrich_completed'] ),
						sprintf( 'last edit %s', $row['post_modified'] ),
					),
				),
			);
		}
		return $out;
	}

	/* ───────────────────────────────────────────────────────────────── *
	 * Candidate state — snooze / dismiss / in_progress                    *
	 * ───────────────────────────────────────────────────────────────── */

	public function get_state() {
		$raw = get_option( self::STATE_OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	public function set_state( $candidate_id, $state, $extra = array() ) {
		$candidate_id = sanitize_text_field( $candidate_id );
		$state        = in_array( $state, array( self::STATE_SNOOZED, self::STATE_DISMISS, self::STATE_PROGRESS ), true ) ? $state : self::STATE_SNOOZED;

		$reg = $this->get_state();
		$reg[ $candidate_id ] = array_merge(
			array(
				'state' => $state,
				'at'    => time(),
				'by'    => get_current_user_id(),
			),
			$extra
		);
		update_option( self::STATE_OPTION, $reg, false );
		return $reg[ $candidate_id ];
	}

	public function snooze( $candidate_id, $hours = self::SNOOZE_DEFAULT_HOURS ) {
		$hours = max( 1, min( 24 * 30, (int) $hours ) );
		$until = time() + ( $hours * HOUR_IN_SECONDS );
		return $this->set_state( $candidate_id, self::STATE_SNOOZED, array( 'until' => $until ) );
	}

	public function dismiss( $candidate_id ) {
		return $this->set_state( $candidate_id, self::STATE_DISMISS );
	}

	public function clear( $candidate_id ) {
		$reg = $this->get_state();
		if ( isset( $reg[ $candidate_id ] ) ) {
			unset( $reg[ $candidate_id ] );
			update_option( self::STATE_OPTION, $reg, false );
		}
		return true;
	}

	public function prune_state() {
		$reg = $this->get_state();
		if ( empty( $reg ) ) return;

		$now    = time();
		$cutoff = $now - ( self::DISMISS_TTL_DAYS * DAY_IN_SECONDS );
		$dirty  = false;
		foreach ( $reg as $id => $row ) {
			$state = $row['state'] ?? '';
			$at    = (int) ( $row['at'] ?? 0 );
			if ( self::STATE_DISMISS === $state && $at < $cutoff ) {
				unset( $reg[ $id ] );
				$dirty = true;
			} elseif ( self::STATE_SNOOZED === $state && (int) ( $row['until'] ?? 0 ) < $now ) {
				// Auto-clear expired snoozes so the candidate returns naturally.
				unset( $reg[ $id ] );
				$dirty = true;
			}
		}
		if ( $dirty ) {
			update_option( self::STATE_OPTION, $reg, false );
		}
	}
}
