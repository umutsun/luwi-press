<?php
/**
 * KG Signals — Track D.1 (3.1.46+).
 *
 * Subscribes to first-class plugin events (`luwipress_after_*` hooks added
 * in 3.1.45) and writes structured KG event rows into the existing
 * `wp_luwipress_logs` table with `level='kg_event'`. Each event carries
 * a JSON context payload that can be queried by the KG dashboard, MCP
 * tools, and Track D.2 (Opportunity v2) for correlation + anomaly scoring.
 *
 * The class also busts the Knowledge Graph response cache for the affected
 * entity so the next dashboard refresh picks up the change immediately
 * (rather than waiting for the meta-change generic invalidation, which
 * doesn't always fire — e.g. when AI engine writes through wp_update_post
 * without touching individual meta keys).
 *
 * Public REST surface: see `class-luwipress-api.php` `/knowledge-graph/events`.
 *
 * Why reuse the logger table: retention, index, admin viewer all exist.
 * Adding a dedicated `wp_luwipress_kg_events` table would duplicate the
 * `id/timestamp/level/context` schema for marginal querying upside. If
 * D.3 autopilot ever needs sub-second event ingestion or compound indexes
 * we revisit then.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_KG_Signals {

	const EVENT_LEVEL = 'kg_event';

	/**
	 * Mapping from action hook name → terse event type string used in the
	 * context payload + REST filter param. Frontend chooses icon/colour by
	 * this value, so it must stay stable across releases.
	 */
	const EVENT_TYPES = array(
		'enrich'    => 'product_enrich',
		'seo'       => 'seo_meta_write',
		'translate' => 'translation_request',
	);

	private static $instance;

	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'luwipress_after_product_enrich',     array( $this, 'on_product_enrich' ),     10, 2 );
		add_action( 'luwipress_after_seo_meta_write',     array( $this, 'on_seo_meta_write' ),     10, 2 );
		add_action( 'luwipress_after_translation_request', array( $this, 'on_translation_request' ), 10, 3 );
	}

	/* ───────────────────────────────────────────────────────────────── *
	 * Hook subscribers                                                    *
	 * ───────────────────────────────────────────────────────────────── */

	public function on_product_enrich( $product_id, $updated_fields ) {
		$this->record_event( 'enrich', 'product', (int) $product_id, array(
			'updated_fields'  => is_array( $updated_fields ) ? array_values( $updated_fields ) : array(),
			'kg_score_after'  => $this->compute_entity_score( 'product', (int) $product_id ),
		) );
		$this->bust_kg_cache();
	}

	public function on_seo_meta_write( $post_id, $fields ) {
		$post_type = get_post_type( $post_id );
		$this->record_event( 'seo', $post_type ?: 'post', (int) $post_id, array(
			'fields'         => is_array( $fields ) ? array_values( $fields ) : array(),
			'kg_score_after' => $this->compute_entity_score( $post_type ?: 'post', (int) $post_id ),
		) );
		$this->bust_kg_cache();
	}

	public function on_translation_request( $product_id, $language, $status ) {
		$this->record_event( 'translate', 'product', (int) $product_id, array(
			'language'       => sanitize_text_field( $language ),
			'status'         => sanitize_text_field( $status ),
			'kg_score_after' => $this->compute_entity_score( 'product', (int) $product_id ),
		) );
		$this->bust_kg_cache();
	}

	/* ───────────────────────────────────────────────────────────────── *
	 * Helpers                                                             *
	 * ───────────────────────────────────────────────────────────────── */

	private function record_event( $event_type, $entity_type, $entity_id, $extra = array() ) {
		$context = array_merge(
			array(
				'event_type'  => sanitize_key( $event_type ),
				'entity_type' => sanitize_key( $entity_type ),
				'entity_id'   => (int) $entity_id,
				'snapshot_at' => current_time( 'mysql' ),
				'user_id'     => get_current_user_id(),
			),
			$extra
		);

		$message = sprintf(
			/* translators: 1: event type, 2: entity type, 3: entity id */
			'KG event: %1$s on %2$s #%3$d',
			$event_type,
			$entity_type,
			(int) $entity_id
		);

		LuwiPress_Logger::log( $message, self::EVENT_LEVEL, $context );

		/**
		 * Fires after a KG event is recorded. Companions and theme code can
		 * use this to react to specific events without re-subscribing to the
		 * underlying source hooks.
		 *
		 * @param string $event_type
		 * @param string $entity_type
		 * @param int    $entity_id
		 * @param array  $context Full context payload that was logged.
		 */
		do_action( 'luwipress_kg_event_recorded', $event_type, $entity_type, $entity_id, $context );
	}

	/**
	 * Compute the live KG opportunity score for a single entity. Cheap —
	 * we don't traverse the whole graph, just call the existing
	 * Knowledge_Graph helpers when available. Returns null if KG isn't
	 * loaded yet (early hook fire) so the event still records cleanly.
	 */
	private function compute_entity_score( $entity_type, $entity_id ) {
		if ( ! class_exists( 'LuwiPress_Knowledge_Graph' ) ) {
			return null;
		}
		if ( 'product' !== $entity_type && 'post' !== $entity_type && 'page' !== $entity_type ) {
			return null;
		}
		$kg = LuwiPress_Knowledge_Graph::get_instance();
		if ( method_exists( $kg, 'compute_post_opportunity_score' ) ) {
			return (int) $kg->compute_post_opportunity_score( (int) $entity_id );
		}
		return null;
	}

	/**
	 * Invalidate the KG response cache after a structural event. The KG
	 * class already exposes `invalidate_cache()` (see class-luwipress-
	 * knowledge-graph.php @ ~line 2660) which deletes every
	 * `_transient_luwipress_kg_*` row in one query.
	 */
	private function bust_kg_cache() {
		if ( ! class_exists( 'LuwiPress_Knowledge_Graph' ) ) {
			return;
		}
		$kg = LuwiPress_Knowledge_Graph::get_instance();
		if ( method_exists( $kg, 'invalidate_cache' ) ) {
			$kg->invalidate_cache();
		}
	}

	/* ───────────────────────────────────────────────────────────────── *
	 * Public query API                                                    *
	 * ───────────────────────────────────────────────────────────────── */

	/**
	 * Read recent KG events with simple filtering. Backed by the logger
	 * table — `level='kg_event'` is our marker. JSON `context` is parsed
	 * back to an array per row; rows that fail json_decode are dropped.
	 *
	 * @param array $args {
	 *   @type int    $limit       Max rows to return. 1..500. Default 50.
	 *   @type string $since       MySQL datetime to floor on (timestamp >=).
	 *   @type array  $event_types Whitelist of event_type values, e.g. ['enrich','translate'].
	 *   @type int    $entity_id
	 * }
	 * @return array Each row: { id, timestamp, message, event_type, entity_type, entity_id, context }
	 */
	public static function get_events( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'       => 50,
			'since'       => '',
			'event_types' => array(),
			'entity_id'   => 0,
		);
		$args  = wp_parse_args( $args, $defaults );
		$limit = max( 1, min( 500, (int) $args['limit'] ) );

		$table = $wpdb->prefix . 'luwipress_logs';
		$where = array( 'level = %s' );
		$prepa = array( self::EVENT_LEVEL );

		if ( ! empty( $args['since'] ) ) {
			$where[] = 'timestamp >= %s';
			$prepa[] = $args['since'];
		}

		$sql = "SELECT id, timestamp, message, context FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY timestamp DESC LIMIT %d";
		$prepa[] = $limit;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepa ), ARRAY_A );
		if ( empty( $rows ) ) {
			return array();
		}

		$types_filter = array_filter( array_map( 'sanitize_key', (array) $args['event_types'] ) );
		$entity_filter = (int) $args['entity_id'];

		$out = array();
		foreach ( $rows as $row ) {
			$ctx = array();
			if ( ! empty( $row['context'] ) ) {
				$decoded = json_decode( $row['context'], true );
				if ( is_array( $decoded ) ) {
					$ctx = $decoded;
				}
			}
			if ( empty( $ctx['event_type'] ) ) {
				continue;
			}
			if ( ! empty( $types_filter ) && ! in_array( $ctx['event_type'], $types_filter, true ) ) {
				continue;
			}
			if ( $entity_filter && (int) ( $ctx['entity_id'] ?? 0 ) !== $entity_filter ) {
				continue;
			}
			$out[] = array(
				'id'          => (int) $row['id'],
				'timestamp'   => $row['timestamp'],
				'message'     => $row['message'],
				'event_type'  => $ctx['event_type'],
				'entity_type' => $ctx['entity_type'] ?? '',
				'entity_id'   => (int) ( $ctx['entity_id'] ?? 0 ),
				'context'     => $ctx,
			);
		}
		return $out;
	}

	/**
	 * Lightweight aggregator — for the KG dashboard "last 24h" widget.
	 *
	 * @param int $hours Default 24.
	 * @return array { window_hours, totals: {enrich, seo, translate}, total }
	 */
	public static function get_summary( $hours = 24 ) {
		$hours = max( 1, min( 720, (int) $hours ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );
		$rows  = self::get_events( array( 'since' => $since, 'limit' => 500 ) );
		$tot   = array( 'enrich' => 0, 'seo' => 0, 'translate' => 0 );
		foreach ( $rows as $r ) {
			$t = $r['event_type'];
			if ( isset( $tot[ $t ] ) ) {
				$tot[ $t ]++;
			}
		}
		return array(
			'window_hours' => $hours,
			'totals'       => $tot,
			'total'        => array_sum( $tot ),
		);
	}
}
