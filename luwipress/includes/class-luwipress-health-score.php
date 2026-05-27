<?php
/**
 * LuwiPress Content Health Score
 *
 * Pillar-weighted composite score that surfaces store content quality as a
 * single 0-100 number. The KG Store Health hero reads this; the Action Queue
 * surfaces gaps as one-click "fix" cards; achievement badges fire when a
 * pillar crosses a milestone.
 *
 * Strategic role: this is the scoring rubric that lets non-WebMCP customers
 * improve content quality through a gamified UI instead of imperative tooling.
 * Settings → Content Health tab configures the per-pillar weights, targets,
 * and action thresholds.
 *
 * Pillar measurement is read-only — measure_<pillar>() returns a 0-100 value
 * plus a count breakdown the Action Queue can turn into "X items below
 * threshold — fix now" cards.
 *
 * @package LuwiPress
 * @since   3.5.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Health_Score {

	const OPTION_PILLARS = 'luwipress_health_pillars';
	const CACHE_KEY      = 'luwipress_health_score_cache';
	const CACHE_TTL      = 900; // 15 minutes

	private static $instance = null;

	/**
	 * Default pillar contract.
	 *
	 * Each pillar:
	 *   - key                : stable identifier (used as option subkey)
	 *   - label              : i18n display name
	 *   - description        : one-line UI hint
	 *   - weight             : relative contribution to overall score (sum is renormalised)
	 *   - target             : 0-100 — value the operator should achieve (badge gold tier)
	 *   - action_threshold   : 0-100 — falling below surfaces an Action Queue card
	 *   - enabled            : whether the pillar counts in the overall score
	 *   - method             : LuwiPress_Health_Score::<method>() that returns the pillar value
	 *
	 * Weights deliberately default to round numbers so the math is auditable
	 * in the settings UI; renormalisation happens at compute time so disabling
	 * a pillar doesn't punish the score.
	 */
	private function default_pillars() {
		return array(
			'seo_coverage' => array(
				'label'            => __( 'SEO Coverage', 'luwipress' ),
				'description'      => __( 'Meta title, meta description, focus keyword presence across products/posts/pages.', 'luwipress' ),
				'weight'           => 25,
				'target'           => 90,
				'action_threshold' => 80,
				'enabled'          => true,
				'method'           => 'measure_seo_coverage',
			),
			'aeo_coverage' => array(
				'label'            => __( 'AEO Coverage', 'luwipress' ),
				'description'      => __( 'FAQ schema, HowTo schema, Speakable coverage on products and key posts.', 'luwipress' ),
				'weight'           => 20,
				'target'           => 60,
				'action_threshold' => 50,
				'enabled'          => true,
				'method'           => 'measure_aeo_coverage',
			),
			'translation_health' => array(
				'label'            => __( 'Translation Health', 'luwipress' ),
				'description'      => __( 'Coverage across active translation languages on translatable content.', 'luwipress' ),
				'weight'           => 15,
				'target'           => 95,
				'action_threshold' => 80,
				'enabled'          => true,
				'method'           => 'measure_translation_health',
			),
			'schema_coverage' => array(
				'label'            => __( 'Schema Coverage', 'luwipress' ),
				'description'      => __( 'Active Schema Registry types vs. emission on relevant content.', 'luwipress' ),
				'weight'           => 15,
				'target'           => 100,
				'action_threshold' => 70,
				'enabled'          => true,
				'method'           => 'measure_schema_coverage',
			),
			'brand_voice' => array(
				'label'            => __( 'Brand Voice Compliance', 'luwipress' ),
				'description'      => __( 'Posts free of promotional phrases (GMC-sensitive) and AI-tell patterns.', 'luwipress' ),
				'weight'           => 10,
				'target'           => 100,
				'action_threshold' => 95,
				'enabled'          => true,
				'method'           => 'measure_brand_voice',
			),
			'content_depth' => array(
				'label'            => __( 'Content Depth', 'luwipress' ),
				'description'      => __( 'Share of published content within the per-CPT word count target band (Settings → AI Content).', 'luwipress' ),
				'weight'           => 10,
				'target'           => 85,
				'action_threshold' => 70,
				'enabled'          => true,
				'method'           => 'measure_content_depth',
			),
		);
	}

	/**
	 * Default word count target bands per CPT. Min / target / max are used
	 * by the Content Depth pillar: posts in `[min, max]` count as on-band,
	 * anything below `min` is thin (high-priority gap), above `max` is bloat
	 * (low-priority informational). Source: Tapadum SEO writing guide §1.10.
	 *
	 * Operators override via Settings → AI Content → Word Count Targets;
	 * the override is persisted as `luwipress_word_count_targets` option.
	 *
	 * Filter `luwipress_word_count_targets` lets companions/themes shift
	 * defaults per vertical (e.g. a restaurant theme might lower product
	 * description targets).
	 *
	 * @return array<string,array{min:int,target:int,max:int,label:string}>
	 */
	public static function default_word_count_targets() {
		$defaults = array(
			'product' => array(
				'min'    => 500,
				'target' => 600,
				'max'    => 800,
				'label'  => __( 'Product description', 'luwipress' ),
			),
			'post' => array(
				'min'    => 1200,
				'target' => 1500,
				'max'    => 2200,
				'label'  => __( 'Blog post (standard)', 'luwipress' ),
			),
			'page' => array(
				'min'    => 300,
				'target' => 400,
				'max'    => 600,
				'label'  => __( 'Static page', 'luwipress' ),
			),
		);

		return apply_filters( 'luwipress_word_count_targets', $defaults );
	}

	/**
	 * Resolve targets with operator overrides merged on top of defaults.
	 *
	 * @return array<string,array{min:int,target:int,max:int,label:string}>
	 */
	public static function get_word_count_targets() {
		$defaults = self::default_word_count_targets();
		$stored   = get_option( 'luwipress_word_count_targets', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$resolved = array();
		foreach ( $defaults as $cpt => $row ) {
			$override = isset( $stored[ $cpt ] ) && is_array( $stored[ $cpt ] ) ? $stored[ $cpt ] : array();
			$resolved[ $cpt ] = array(
				'min'    => isset( $override['min'] )    ? max( 0, (int) $override['min'] )    : (int) $row['min'],
				'target' => isset( $override['target'] ) ? max( 0, (int) $override['target'] ) : (int) $row['target'],
				'max'    => isset( $override['max'] )    ? max( 0, (int) $override['max'] )    : (int) $row['max'],
				'label'  => $row['label'],
			);
		}
		return $resolved;
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Invalidate cache when KG-tracked content changes — mirror the KG's
		// own invalidation hooks so we never serve a stale score while the
		// KG itself has rebuilt.
		add_action( 'save_post_product', array( $this, 'invalidate_cache_on_post' ), 20, 3 );
		add_action( 'save_post_post',    array( $this, 'invalidate_cache_on_post' ), 20, 3 );
		add_action( 'save_post_page',    array( $this, 'invalidate_cache_on_post' ), 20, 3 );
		add_action( 'delete_post',       array( $this, 'invalidate_cache' ) );

		// Settings save — clear cache so a weight tweak doesn't take 15min to land.
		add_action( 'update_option_' . self::OPTION_PILLARS, array( $this, 'invalidate_cache' ) );
		add_action( 'add_option_' . self::OPTION_PILLARS,    array( $this, 'invalidate_cache' ) );

		// REST surface for the settings tab and external consumers.
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	/**
	 * Drop the cached score. Hooked on content writes + settings saves.
	 *
	 * @param int|null      $post_id Optional. Ignored except for autosave/revision guard.
	 * @param \WP_Post|null $post    Optional.
	 * @param bool          $update  Optional.
	 */
	public function invalidate_cache_on_post( $post_id = null, $post = null, $update = false ) {
		if ( $post_id && ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) ) {
			return;
		}
		$this->invalidate_cache();
	}

	public function invalidate_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Return the resolved pillar list (defaults merged with stored overrides
	 * and a filter pass for companions). Each pillar is fully-shaped — the
	 * caller never has to defend against missing keys.
	 *
	 * @return array<string,array>
	 */
	public function get_pillars() {
		$defaults = $this->default_pillars();
		$stored   = get_option( self::OPTION_PILLARS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$resolved = array();
		foreach ( $defaults as $key => $def ) {
			$override = isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ? $stored[ $key ] : array();

			$resolved[ $key ] = array(
				'key'              => $key,
				'label'            => $def['label'],
				'description'      => $def['description'],
				'method'           => $def['method'],
				'enabled'          => isset( $override['enabled'] )
					? (bool) $override['enabled']
					: (bool) $def['enabled'],
				'weight'           => isset( $override['weight'] )
					? max( 0, min( 100, (int) $override['weight'] ) )
					: (int) $def['weight'],
				'target'           => isset( $override['target'] )
					? max( 0, min( 100, (int) $override['target'] ) )
					: (int) $def['target'],
				'action_threshold' => isset( $override['action_threshold'] )
					? max( 0, min( 100, (int) $override['action_threshold'] ) )
					: (int) $def['action_threshold'],
			);
		}

		/**
		 * Allow companions to register additional pillars. New entries should
		 * be shaped the same as defaults() entries, with a `method` that
		 * resolves to a callable taking no args and returning an array with
		 * a `value` key (0-100).
		 *
		 * @param array $resolved
		 * @since 3.5.4
		 */
		return apply_filters( 'luwipress_health_pillars', $resolved );
	}

	/**
	 * Persist pillar overrides from the settings tab.
	 *
	 * Only the configurable fields (enabled, weight, target, action_threshold)
	 * are stored — labels/descriptions/methods stay in code so an upgrade
	 * doesn't strand the option with stale defaults.
	 *
	 * @param array $input Partial-update map keyed by pillar key.
	 * @return array Persisted state.
	 */
	public function save_pillar_overrides( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$pillars = $this->get_pillars();
		$store   = get_option( self::OPTION_PILLARS, array() );
		if ( ! is_array( $store ) ) {
			$store = array();
		}

		foreach ( $pillars as $key => $pillar ) {
			if ( ! isset( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
				continue;
			}
			$row    = $input[ $key ];
			$record = isset( $store[ $key ] ) && is_array( $store[ $key ] ) ? $store[ $key ] : array();

			if ( array_key_exists( 'enabled', $row ) ) {
				$record['enabled'] = rest_sanitize_boolean( $row['enabled'] );
			}
			if ( array_key_exists( 'weight', $row ) ) {
				$record['weight'] = max( 0, min( 100, (int) $row['weight'] ) );
			}
			if ( array_key_exists( 'target', $row ) ) {
				$record['target'] = max( 0, min( 100, (int) $row['target'] ) );
			}
			if ( array_key_exists( 'action_threshold', $row ) ) {
				$record['action_threshold'] = max( 0, min( 100, (int) $row['action_threshold'] ) );
			}

			$store[ $key ] = $record;
		}

		update_option( self::OPTION_PILLARS, $store, false );
		$this->invalidate_cache();
		return $store;
	}

	/**
	 * Reset all pillar overrides — restores defaults.
	 */
	public function reset_pillars() {
		delete_option( self::OPTION_PILLARS );
		$this->invalidate_cache();
	}

	// ─── COMPUTE ────────────────────────────────────────────────────────

	/**
	 * Compute the overall score. Cached for 15 minutes; pass $force=true to
	 * bypass cache (used by the settings tab "recalculate now" button).
	 *
	 * @param bool $force
	 * @return array {
	 *     @type int    overall          0-100
	 *     @type string status           good|warn|bad
	 *     @type array  pillars          List of measured pillars
	 *     @type int    computed_at      Unix timestamp
	 *     @type int    ttl              Cache TTL in seconds
	 * }
	 */
	public function compute( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['overall'] ) ) {
				return $cached;
			}
		}

		$pillars = $this->get_pillars();

		$measured = array();
		$total_weight   = 0;
		$weighted_sum   = 0;

		foreach ( $pillars as $key => $pillar ) {
			if ( empty( $pillar['enabled'] ) ) {
				$measured[] = array_merge( $pillar, array(
					'value'        => null,
					'status'       => 'disabled',
					'contribution' => 0,
					'details'      => array(),
				) );
				continue;
			}

			$method = $pillar['method'];
			if ( ! method_exists( $this, $method ) ) {
				$measured[] = array_merge( $pillar, array(
					'value'        => null,
					'status'       => 'unknown',
					'contribution' => 0,
					'details'      => array( 'error' => 'method_missing' ),
				) );
				continue;
			}

			$result = $this->$method();
			$value  = isset( $result['value'] ) ? max( 0, min( 100, (float) $result['value'] ) ) : null;

			if ( null === $value ) {
				$measured[] = array_merge( $pillar, array(
					'value'        => null,
					'status'       => 'n_a',
					'contribution' => 0,
					'details'      => $result['details'] ?? array(),
				) );
				continue;
			}

			$weight = (int) $pillar['weight'];
			$total_weight += $weight;
			$weighted_sum += $value * $weight;

			$measured[] = array_merge( $pillar, array(
				'value'        => round( $value, 1 ),
				'status'       => $this->status_band( $value, $pillar['target'], $pillar['action_threshold'] ),
				'contribution' => $weight,
				'details'      => $result['details'] ?? array(),
			) );
		}

		$overall = $total_weight > 0 ? (int) round( $weighted_sum / $total_weight ) : 0;

		$response = array(
			'overall'     => $overall,
			'status'      => $this->status_band( $overall, 80, 50 ),
			'pillars'     => $measured,
			'computed_at' => time(),
			'ttl'         => self::CACHE_TTL,
		);

		set_transient( self::CACHE_KEY, $response, self::CACHE_TTL );
		return $response;
	}

	/**
	 * Map a value to good/warn/bad. Target = good ceiling, action_threshold =
	 * warn ceiling. Below action_threshold is bad (and surfaces a card).
	 */
	private function status_band( $value, $target, $action_threshold ) {
		if ( $value >= $target ) {
			return 'good';
		}
		if ( $value >= $action_threshold ) {
			return 'warn';
		}
		return 'bad';
	}

	// ─── PILLAR MEASUREMENTS ────────────────────────────────────────────
	//
	// Each measure_<pillar>() returns:
	//   { value: float 0-100, details: array<string, mixed> }
	//
	// `value` is the canonical score; `details` carries the count breakdown
	// the Action Queue uses to render cards ("12 of 130 products missing
	// FAQ — fix now").

	/**
	 * SEO Coverage — share of published products + posts + pages with all
	 * three SEO basics: rank_math_title, rank_math_description, focus keyword.
	 *
	 * We weight the three fields equally and combine across post types so a
	 * thin blog doesn't drag down the whole score on a product-heavy store.
	 */
	public function measure_seo_coverage() {
		global $wpdb;

		$types  = array( 'product', 'post', 'page' );
		$counts = array(
			'total'       => 0,
			'with_title'  => 0,
			'with_desc'   => 0,
			'with_focus'  => 0,
		);

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$total        = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders)",
			$types
		) );
		$counts['total'] = $total;

		if ( $total === 0 ) {
			return array(
				'value'   => null,
				'details' => array( 'reason' => 'no_publishable_content' ),
			);
		}

		// Count posts with non-empty title / description / focus keyword via
		// LEFT JOIN on postmeta. Single query keeps this O(1) regardless of
		// store size.
		$count_meta = function( $meta_key ) use ( $wpdb, $placeholders, $types ) {
			$args   = array_merge( array( $meta_key ), $types );
			$sql    = "SELECT COUNT(DISTINCT p.ID)
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
					WHERE p.post_status = 'publish'
					  AND p.post_type IN ($placeholders)
					  AND pm.meta_value <> ''";
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		};

		$counts['with_title'] = $count_meta( 'rank_math_title' );
		$counts['with_desc']  = $count_meta( 'rank_math_description' );
		$counts['with_focus'] = $count_meta( 'rank_math_focus_keyword' );

		// Score = average of three coverage percentages.
		$pct_title = ( $counts['with_title'] / $total ) * 100;
		$pct_desc  = ( $counts['with_desc']  / $total ) * 100;
		$pct_focus = ( $counts['with_focus'] / $total ) * 100;
		$value     = ( $pct_title + $pct_desc + $pct_focus ) / 3;

		return array(
			'value'   => $value,
			'details' => array(
				'total_content'      => $total,
				'missing_title'      => $total - $counts['with_title'],
				'missing_description'=> $total - $counts['with_desc'],
				'missing_focus_kw'   => $total - $counts['with_focus'],
				'pct_title'          => round( $pct_title, 1 ),
				'pct_description'    => round( $pct_desc, 1 ),
				'pct_focus_keyword'  => round( $pct_focus, 1 ),
			),
		);
	}

	/**
	 * AEO Coverage — FAQ presence on products (the canonical AEO signal we
	 * actively use; HowTo + Speakable are deprecated by Google for product
	 * pages and intentionally excluded from the score).
	 *
	 * Counts products with a non-empty `_luwipress_faq` meta. Score is the
	 * coverage percentage.
	 */
	public function measure_aeo_coverage() {
		global $wpdb;

		if ( ! post_type_exists( 'product' ) ) {
			return array(
				'value'   => null,
				'details' => array( 'reason' => 'woocommerce_inactive' ),
			);
		}

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_status = 'publish' AND post_type = 'product'"
		);

		if ( $total === 0 ) {
			return array(
				'value'   => null,
				'details' => array( 'reason' => 'no_products' ),
			);
		}

		$with_faq = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_status = 'publish'
			   AND p.post_type = 'product'
			   AND pm.meta_key = '_luwipress_faq'
			   AND pm.meta_value <> ''
			   AND pm.meta_value <> 'a:0:{}'
			   AND pm.meta_value <> '[]'"
		);

		$value = ( $with_faq / $total ) * 100;

		return array(
			'value'   => $value,
			'details' => array(
				'total_products' => $total,
				'with_faq'       => $with_faq,
				'missing_faq'    => $total - $with_faq,
				'pct_faq'        => round( $value, 1 ),
			),
		);
	}

	/**
	 * Translation Health — average coverage across active target languages
	 * relative to the source language post count.
	 *
	 * Returns null when no translation plugin is active or only one language
	 * is configured (we don't want to artificially inflate the overall score
	 * on a single-language site).
	 */
	public function measure_translation_health() {
		if ( ! class_exists( 'LuwiPress_Plugin_Detector' ) ) {
			return array(
				'value'   => null,
				'details' => array( 'reason' => 'detector_missing' ),
			);
		}

		$detector = LuwiPress_Plugin_Detector::get_instance();
		$t_info   = $detector->detect_translation();

		if ( empty( $t_info ) || ( $t_info['plugin'] ?? 'none' ) === 'none' ) {
			return array(
				'value'   => null,
				'details' => array( 'reason' => 'no_translation_plugin' ),
			);
		}

		$default_lang = $t_info['default_language'] ?? '';
		$active       = $t_info['active_languages'] ?? array();
		$targets      = array_values( array_diff( $active, array( $default_lang ) ) );

		if ( empty( $targets ) ) {
			return array(
				'value'   => null,
				'details' => array( 'reason' => 'single_language' ),
			);
		}

		// Defer the actual coverage math to the Translation module if
		// available — it already has WPML/Polylang-aware counting baked in.
		$per_lang = array();
		if ( class_exists( 'LuwiPress_Translation' ) && method_exists( 'LuwiPress_Translation', 'get_instance' ) ) {
			$t = LuwiPress_Translation::get_instance();
			if ( method_exists( $t, 'get_coverage_per_language' ) ) {
				$per_lang = $t->get_coverage_per_language();
			}
		}

		if ( empty( $per_lang ) ) {
			// Fallback: rough estimate from KG summary if it has been built
			// recently. Returns null if not — better honest n/a than guessed.
			$cached_kg = get_transient( 'luwipress_kg_v3_cache' );
			if ( is_array( $cached_kg ) && isset( $cached_kg['summary']['translation_coverage'] ) ) {
				$per_lang = $cached_kg['summary']['translation_coverage'];
			} else {
				return array(
					'value'   => null,
					'details' => array( 'reason' => 'coverage_unknown', 'targets' => $targets ),
				);
			}
		}

		// Average target-language coverage (primary = 100% by definition; we
		// drop it so a single weak target doesn't get masked by the primary's
		// implicit 100%).
		$target_values = array();
		foreach ( $targets as $lang ) {
			if ( isset( $per_lang[ $lang ] ) ) {
				$target_values[ $lang ] = (float) $per_lang[ $lang ];
			}
		}

		if ( empty( $target_values ) ) {
			return array(
				'value'   => null,
				'details' => array( 'reason' => 'coverage_targets_missing', 'targets' => $targets ),
			);
		}

		$value = array_sum( $target_values ) / count( $target_values );

		return array(
			'value'   => $value,
			'details' => array(
				'plugin'           => $t_info['plugin'],
				'default_language' => $default_lang,
				'targets'          => $targets,
				'per_language'     => $target_values,
			),
		);
	}

	/**
	 * Schema Coverage — share of registered Schema Registry types that are
	 * actually emitting (i.e. registered AND at least one piece of content
	 * carries the type-specific meta key).
	 *
	 * The Schema Registry is the source of truth — we don't hardcode the
	 * type list here, so adding a type via the `luwipress_schema_registry_init`
	 * filter automatically counts.
	 */
	public function measure_schema_coverage() {
		if ( ! class_exists( 'LuwiPress_Schema_Registry' ) ) {
			return array(
				'value'   => null,
				'details' => array( 'reason' => 'registry_missing' ),
			);
		}

		$registry = LuwiPress_Schema_Registry::get_instance();
		if ( ! method_exists( $registry, 'get_registered_types' ) ) {
			// Older Schema Registry — fall back to a conservative manual count.
			return $this->measure_schema_coverage_legacy();
		}

		$types = $registry->get_registered_types();
		if ( empty( $types ) || ! is_array( $types ) ) {
			return array(
				'value'   => null,
				'details' => array( 'reason' => 'no_types_registered' ),
			);
		}

		$total   = count( $types );
		$active  = 0;
		$details = array();

		foreach ( $types as $type_key => $type_def ) {
			$meta_key = $type_def['meta_key'] ?? '';
			if ( empty( $meta_key ) ) {
				continue;
			}
			global $wpdb;
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
				$meta_key
			) );
			$details[ $type_key ] = $count;
			if ( $count > 0 ) {
				$active++;
			}
		}

		$value = $total > 0 ? ( $active / $total ) * 100 : 0;

		return array(
			'value'   => $value,
			'details' => array(
				'total_types'     => $total,
				'active_types'    => $active,
				'inactive_types'  => $total - $active,
				'per_type_counts' => $details,
			),
		);
	}

	/**
	 * Conservative fallback when the Schema Registry doesn't expose its type
	 * list. Counts the three core LuwiPress schema meta keys.
	 */
	private function measure_schema_coverage_legacy() {
		global $wpdb;
		$known = array( '_luwipress_faq', '_luwipress_howto', '_luwipress_speakable' );

		$active = 0;
		$details = array();
		foreach ( $known as $meta_key ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
				$meta_key
			) );
			$details[ $meta_key ] = $count;
			if ( $count > 0 ) {
				$active++;
			}
		}

		$value = ( $active / count( $known ) ) * 100;
		return array(
			'value'   => $value,
			'details' => array(
				'total_types'     => count( $known ),
				'active_types'    => $active,
				'per_type_counts' => $details,
				'fallback'        => true,
			),
		);
	}

	/**
	 * Brand Voice Compliance — share of published content that contains NO
	 * promotional phrases (GMC-sensitive) in scanned fields.
	 *
	 * Delegates to LuwiPress_Content_Audit's phrase scanner. We only sample
	 * up to a cap (default 200) so the cron isn't murdered on huge catalogues;
	 * the sampled percentage is a fair estimator for the whole.
	 */
	public function measure_brand_voice() {
		if ( ! class_exists( 'LuwiPress_Content_Audit' ) ) {
			return array(
				'value'   => null,
				'details' => array( 'reason' => 'audit_module_missing' ),
			);
		}

		$audit = LuwiPress_Content_Audit::get_instance();
		if ( ! method_exists( $audit, 'get_phrase_bank' ) ) {
			return array(
				'value'   => null,
				'details' => array( 'reason' => 'phrase_bank_missing' ),
			);
		}

		// Cap sample size to keep this affordable on large stores. Filterable
		// so a customer with a huge catalogue can dial it down further.
		$sample_cap = (int) apply_filters( 'luwipress_health_brand_voice_sample', 200 );
		$sample_cap = max( 25, min( 1000, $sample_cap ) );

		$post_ids = get_posts( array(
			'post_type'      => array( 'product', 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => $sample_cap,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		if ( empty( $post_ids ) ) {
			return array(
				'value'   => null,
				'details' => array( 'reason' => 'no_content_to_sample' ),
			);
		}

		$bank = $audit->get_phrase_bank();

		// Reach in via a single dispatch through the REST scanner so we don't
		// reimplement the field-collection logic. Mock a request object so
		// the existing signature works.
		$violators       = 0;
		$total_findings  = 0;
		$scanned         = 0;

		foreach ( $post_ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}
			$scanned++;

			$findings = $this->scan_post_for_phrases( $post, $bank, $audit );
			if ( ! empty( $findings ) ) {
				$violators++;
				$total_findings += count( $findings );
			}
		}

		if ( $scanned === 0 ) {
			return array(
				'value'   => null,
				'details' => array( 'reason' => 'scan_empty' ),
			);
		}

		$pct_clean = ( ( $scanned - $violators ) / $scanned ) * 100;

		return array(
			'value'   => $pct_clean,
			'details' => array(
				'scanned'        => $scanned,
				'sample_cap'     => $sample_cap,
				'violators'      => $violators,
				'total_findings' => $total_findings,
				'pct_clean'      => round( $pct_clean, 1 ),
			),
		);
	}

	/**
	 * Content Depth — share of published content (per CPT) whose body
	 * word count falls within the operator-configured target band.
	 *
	 * Per-CPT targets live in `luwipress_word_count_targets` option;
	 * defaults from `default_word_count_targets()` (Tapadum SEO writing
	 * guide §1.10).
	 *
	 * Score = average across all enabled CPTs of (on_band_count / total).
	 * On-band = `min <= word_count <= max`. Posts below `min` are tracked
	 * as `thin` (the Action Queue card will surface these); posts above
	 * `max` are tracked as `bloat` (informational).
	 *
	 * Caps: scans at most 1000 posts per CPT (filterable via
	 * `luwipress_health_content_depth_sample`) so the cron path stays
	 * affordable on huge stores. Sample is most-recently-modified.
	 */
	public function measure_content_depth() {
		$targets = self::get_word_count_targets();
		if ( empty( $targets ) ) {
			return array(
				'value'   => null,
				'details' => array( 'reason' => 'no_targets_configured' ),
			);
		}

		$cap = (int) apply_filters( 'luwipress_health_content_depth_sample', 1000 );
		$cap = max( 100, min( 5000, $cap ) );

		$per_cpt = array();
		$on_band_share = array();

		foreach ( $targets as $cpt => $band ) {
			if ( ! post_type_exists( $cpt ) ) {
				$per_cpt[ $cpt ] = array(
					'skipped' => 'post_type_inactive',
					'band'    => $band,
				);
				continue;
			}

			$ids = get_posts( array(
				'post_type'      => $cpt,
				'post_status'    => 'publish',
				'posts_per_page' => $cap,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			) );

			if ( empty( $ids ) ) {
				$per_cpt[ $cpt ] = array(
					'total'   => 0,
					'on_band' => 0,
					'thin'    => 0,
					'bloat'   => 0,
					'band'    => $band,
				);
				continue;
			}

			$total = count( $ids );
			$thin  = 0;
			$band_count = 0;
			$bloat = 0;
			$thin_ids = array();

			foreach ( $ids as $pid ) {
				$post = get_post( $pid );
				if ( ! $post ) {
					continue;
				}
				$wc = $this->count_words_for_content( $post->post_content );
				if ( $wc < (int) $band['min'] ) {
					$thin++;
					if ( count( $thin_ids ) < 50 ) {
						$thin_ids[] = array( 'post_id' => $pid, 'word_count' => $wc, 'title' => get_the_title( $post ) );
					}
				} elseif ( $wc > (int) $band['max'] ) {
					$bloat++;
				} else {
					$band_count++;
				}
			}

			$share = $total > 0 ? ( $band_count / $total ) * 100 : 0;
			$on_band_share[] = $share;
			$per_cpt[ $cpt ] = array(
				'total'    => $total,
				'on_band'  => $band_count,
				'thin'     => $thin,
				'bloat'    => $bloat,
				'pct_band' => round( $share, 1 ),
				'band'     => $band,
				'thin_ids' => $thin_ids,
			);
		}

		if ( empty( $on_band_share ) ) {
			return array(
				'value'   => null,
				'details' => array( 'reason' => 'no_measurable_cpts', 'per_cpt' => $per_cpt ),
			);
		}

		$value = array_sum( $on_band_share ) / count( $on_band_share );

		return array(
			'value'   => $value,
			'details' => array(
				'sample_cap' => $cap,
				'per_cpt'    => $per_cpt,
			),
		);
	}

	/**
	 * Word-counter for raw post_content. Strips shortcodes, blocks, HTML
	 * tags, and collapses whitespace before counting on whitespace runs.
	 * Matches Rank Math's "content words" metric closely enough for the
	 * Content Depth pillar; off by < 5% in practice on Tapadum samples.
	 */
	private function count_words_for_content( $raw ) {
		if ( '' === $raw || null === $raw ) {
			return 0;
		}
		$stripped = strip_shortcodes( (string) $raw );
		// Strip Gutenberg block delimiter comments so <!-- wp:foo --> doesn't count.
		$stripped = preg_replace( '/<!--\s*\/?wp:[^>]*-->/', '', $stripped );
		$stripped = wp_strip_all_tags( $stripped );
		$stripped = trim( preg_replace( '/\s+/u', ' ', $stripped ) );
		if ( '' === $stripped ) {
			return 0;
		}
		// preg_split with PREG_SPLIT_NO_EMPTY keeps the count clean for
		// multi-byte / accented characters. Counts hyphenated tokens as one
		// word (consistent with Rank Math).
		$words = preg_split( '/\s+/u', $stripped, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * Helper for measure_brand_voice — runs the phrase finder against a
	 * single post's scannable fields. Reflectively calls the audit module's
	 * private helpers because surfacing them as public API risks a wider
	 * contract than this pillar needs.
	 */
	private function scan_post_for_phrases( $post, $bank, $audit ) {
		$lang = '';
		if ( method_exists( $audit, 'detect_post_language' ) ) {
			$ref = new ReflectionMethod( $audit, 'detect_post_language' );
			$ref->setAccessible( true );
			$lang = $ref->invoke( $audit, $post->ID );
		}
		$phrases = isset( $bank[ $lang ] ) ? $bank[ $lang ] : ( $bank['en'] ?? array() );

		$fields = array();
		if ( method_exists( $audit, 'collect_fields' ) ) {
			$ref = new ReflectionMethod( $audit, 'collect_fields' );
			$ref->setAccessible( true );
			$fields = $ref->invoke( $audit, $post, 'all' );
		}

		$findings = array();
		if ( method_exists( $audit, 'find_phrases' ) ) {
			$ref = new ReflectionMethod( $audit, 'find_phrases' );
			$ref->setAccessible( true );
			foreach ( $fields as $field_name => $field_data ) {
				$matches = $ref->invoke( $audit, $field_data['text'], $phrases );
				foreach ( $matches as $m ) {
					$findings[] = array(
						'phrase' => $m['phrase'],
						'field'  => $field_name,
					);
				}
			}
		}
		return $findings;
	}

	// ─── REST ENDPOINTS ─────────────────────────────────────────────────

	public function register_endpoints() {
		register_rest_route( 'luwipress/v1', '/health/score', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_get_score' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'force' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		) );

		register_rest_route( 'luwipress/v1', '/health/pillars', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_get_pillars' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );

		register_rest_route( 'luwipress/v1', '/health/pillars', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_save_pillars' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );

		register_rest_route( 'luwipress/v1', '/health/reset', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_reset_pillars' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );
	}

	public function rest_get_score( $request ) {
		$force = (bool) $request->get_param( 'force' );
		return rest_ensure_response( $this->compute( $force ) );
	}

	public function rest_get_pillars( $request ) {
		return rest_ensure_response( array(
			'pillars' => array_values( $this->get_pillars() ),
		) );
	}

	public function rest_save_pillars( $request ) {
		$body  = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$input = isset( $body['pillars'] ) && is_array( $body['pillars'] ) ? $body['pillars'] : $body;
		$saved = $this->save_pillar_overrides( $input );
		return rest_ensure_response( array(
			'success' => true,
			'stored'  => $saved,
			'pillars' => array_values( $this->get_pillars() ),
		) );
	}

	public function rest_reset_pillars( $request ) {
		$this->reset_pillars();
		return rest_ensure_response( array(
			'success' => true,
			'pillars' => array_values( $this->get_pillars() ),
		) );
	}
}
