<?php
/**
 * LuwiPress Translation Sync — cross-language synchronization orchestrator (3.1.54+).
 *
 * Consolidates four detect-routines under a single audit surface and a single
 * fix dispatcher. Read-mostly; mutations route through existing endpoints
 * (force-retranslate, sync-structure, aeo/save-faq) so this class is glue.
 *
 * Findings shape (uniform across all 4 detect types):
 *   {
 *     finding_id:     string  e.g. "drift:1234:fr"
 *     type:           "drift" | "outdated" | "structural_gap" | "schema_parity"
 *     severity:       "high" | "medium" | "low"
 *     source_id:      int  (default-language post)
 *     target_id:      int  (translation post)
 *     lang:           string  e.g. "fr"
 *     title:          string  (source post title for display)
 *     gap_summary:    string  (one-sentence "what's wrong")
 *     fix_action:     "force_retranslate" | "sync_structure" | "copy_faq" | "manual"
 *     fix_args:       array   (ready to pass to the dispatcher)
 *     metric:         array   (per-type numeric signal, e.g. {score: 0.12} or {lag_hours: 48})
 *   }
 *
 * Public REST:
 *   GET  /translation/sync-audit           — run all (or one) detect type, return findings
 *   POST /translation/sync-fix             — execute fix_action for an array of finding_ids
 *   GET  /translation/sync-settings        — read drift threshold + sweep enable
 *   POST /translation/sync-settings        — write drift threshold + sweep enable
 *
 * Cron:
 *   luwipress_translation_sync_sweep       — hourly, opt-in via setting.
 *                                            Runs audit, optionally auto-fixes
 *                                            high-severity findings, posts a
 *                                            summary into the activity log.
 *
 * KG Action Queue:
 *   Hooks luwipress_kg_action_queue_external_candidates to inject the top N
 *   findings as "Next wins" candidates so operators see drift surface in the
 *   KG dashboard, not only the Translation Manager.
 *
 * @since 3.1.54
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Translation_Sync {

	const OPTION_DRIFT_THRESHOLD = 'luwipress_translation_sync_drift_threshold';
	const OPTION_SWEEP_ENABLED   = 'luwipress_translation_sync_sweep_enabled';
	const OPTION_SWEEP_AUTOFIX   = 'luwipress_translation_sync_sweep_autofix';
	const OPTION_LAST_AUDIT      = 'luwipress_translation_sync_last_audit';
	const CRON_HOOK              = 'luwipress_translation_sync_sweep';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
		add_filter( 'luwipress_kg_action_queue_external_candidates', array( $this, 'emit_kg_candidates' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_sweep' ) );

		// Cron schedule guard — register the hourly tick when sweep is enabled,
		// unregister when disabled. Setting toggle reschedules in update_option.
		add_action( 'init', array( $this, 'maybe_schedule_sweep' ) );
		add_action( 'update_option_' . self::OPTION_SWEEP_ENABLED, array( $this, 'on_sweep_setting_changed' ), 10, 2 );
	}

	/* ═══════════════════════════════════════════════════════════════════
	 *  REST
	 * ═══════════════════════════════════════════════════════════════════ */

	public function register_endpoints() {
		$ns = 'luwipress/v1';

		register_rest_route( $ns, '/translation/sync-audit', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_audit' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args' => array(
				'type'      => array( 'default' => 'all', 'description' => 'all | drift | outdated | structural_gap | schema_parity' ),
				'post_type' => array( 'default' => 'product', 'sanitize_callback' => 'sanitize_text_field' ),
				'languages' => array( 'required' => false, 'description' => 'Comma-separated target languages. Defaults to all active non-source.' ),
				'limit'     => array( 'default' => 200, 'sanitize_callback' => 'absint' ),
				'threshold' => array( 'required' => false, 'description' => 'Drift threshold 0.0..1.0. Overrides stored setting for this call.' ),
			),
		) );

		register_rest_route( $ns, '/translation/sync-fix', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_fix' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args' => array(
				'finding_ids' => array( 'required' => true, 'description' => 'Array of finding_id strings from /translation/sync-audit.' ),
				'async'       => array( 'default' => true, 'description' => 'Queue via wp_cron (true) or run inline (false).' ),
			),
		) );

		register_rest_route( $ns, '/translation/sync-settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_settings_get' ),
				'permission_callback' => array( $this, 'check_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_settings_set' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args' => array(
					'drift_threshold' => array( 'required' => false ),
					'sweep_enabled'   => array( 'required' => false ),
					'sweep_autofix'   => array( 'required' => false ),
				),
			),
		) );
	}

	public function check_permission( $request ) {
		return LuwiPress_Permission::check_token_or_admin( $request );
	}

	public function rest_audit( $request ) {
		$type      = sanitize_text_field( $request->get_param( 'type' ) ?: 'all' );
		$post_type = sanitize_text_field( $request->get_param( 'post_type' ) ?: 'product' );
		$limit     = max( 1, min( 500, absint( $request->get_param( 'limit' ) ) ?: 200 ) );
		$langs_raw = $request->get_param( 'languages' );
		$threshold = $request->get_param( 'threshold' );
		$threshold = ( $threshold !== null && $threshold !== '' )
			? max( 0.0, min( 1.0, (float) $threshold ) )
			: (float) get_option( self::OPTION_DRIFT_THRESHOLD, 0.45 );

		$languages = $this->normalize_languages_param( $langs_raw );

		$findings = $this->run_audit(
			$type,
			array(
				'post_type' => $post_type,
				'languages' => $languages,
				'limit'     => $limit,
				'threshold' => $threshold,
			)
		);

		// Persist a last-audit summary so the admin UI / KG can show "last run" timestamp
		// without re-scanning every page load.
		update_option( self::OPTION_LAST_AUDIT, array(
			'timestamp'      => current_time( 'c' ),
			'type'           => $type,
			'post_type'      => $post_type,
			'languages'      => $languages,
			'findings_total' => count( $findings ),
			'findings_by_type' => $this->count_by_type( $findings ),
		), false );

		return rest_ensure_response( array(
			'type'      => $type,
			'post_type' => $post_type,
			'languages' => $languages,
			'threshold' => $threshold,
			'count'     => count( $findings ),
			'by_type'   => $this->count_by_type( $findings ),
			'findings'  => $findings,
		) );
	}

	public function rest_fix( $request ) {
		$finding_ids = $request->get_param( 'finding_ids' );
		if ( is_string( $finding_ids ) ) {
			$finding_ids = array_filter( array_map( 'trim', explode( ',', $finding_ids ) ) );
		}
		if ( ! is_array( $finding_ids ) || empty( $finding_ids ) ) {
			return new WP_Error( 'missing_finding_ids', 'finding_ids required (array or comma-separated string)', array( 'status' => 400 ) );
		}
		$async = (bool) $request->get_param( 'async' );

		$results = array();
		foreach ( $finding_ids as $fid ) {
			$results[ $fid ] = $this->dispatch_fix( (string) $fid, $async );
		}

		return rest_ensure_response( array(
			'status'  => 'completed',
			'count'   => count( $results ),
			'results' => $results,
		) );
	}

	public function rest_settings_get( $request ) {
		return rest_ensure_response( array(
			'drift_threshold' => (float) get_option( self::OPTION_DRIFT_THRESHOLD, 0.45 ),
			'sweep_enabled'   => (bool) get_option( self::OPTION_SWEEP_ENABLED, false ),
			'sweep_autofix'   => (bool) get_option( self::OPTION_SWEEP_AUTOFIX, false ),
			'next_sweep_at'   => wp_next_scheduled( self::CRON_HOOK ),
			'last_audit'      => get_option( self::OPTION_LAST_AUDIT, null ),
		) );
	}

	public function rest_settings_set( $request ) {
		$threshold = $request->get_param( 'drift_threshold' );
		if ( $threshold !== null && $threshold !== '' ) {
			update_option( self::OPTION_DRIFT_THRESHOLD, max( 0.0, min( 1.0, (float) $threshold ) ) );
		}
		$sweep = $request->get_param( 'sweep_enabled' );
		if ( $sweep !== null && $sweep !== '' ) {
			update_option( self::OPTION_SWEEP_ENABLED, (bool) $sweep );
		}
		$autofix = $request->get_param( 'sweep_autofix' );
		if ( $autofix !== null && $autofix !== '' ) {
			update_option( self::OPTION_SWEEP_AUTOFIX, (bool) $autofix );
		}
		return $this->rest_settings_get( $request );
	}

	/* ═══════════════════════════════════════════════════════════════════
	 *  AUDIT — orchestration
	 * ═══════════════════════════════════════════════════════════════════ */

	/**
	 * Run one or all detect types and return a unified findings array.
	 *
	 * @param string $type  "all" | "drift" | "outdated" | "structural_gap" | "schema_parity"
	 * @param array  $args  { post_type, languages[], limit, threshold }
	 * @return array
	 */
	public function run_audit( $type, $args ) {
		$findings = array();

		if ( $type === 'all' || $type === 'drift' ) {
			foreach ( $this->detect_drift( $args ) as $f ) {
				$findings[] = $f;
			}
		}
		if ( $type === 'all' || $type === 'outdated' ) {
			foreach ( $this->detect_outdated( $args ) as $f ) {
				$findings[] = $f;
			}
		}
		if ( $type === 'all' || $type === 'structural_gap' ) {
			foreach ( $this->detect_structural_gap( $args ) as $f ) {
				$findings[] = $f;
			}
		}
		if ( $type === 'all' || $type === 'schema_parity' ) {
			foreach ( $this->detect_schema_parity( $args ) as $f ) {
				$findings[] = $f;
			}
		}

		// Sort by severity (high > medium > low) then by ROI proxy (numeric metric).
		usort( $findings, array( $this, 'severity_sort' ) );

		// Cap to requested limit.
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 200;
		return array_slice( $findings, 0, $limit );
	}

	/* ─── Detect routines ────────────────────────────────────────────── */

	/**
	 * Drift: translation body text scores low on the target-language stop-word
	 * ratio. Delegates to LuwiPress_Translation::get_language_drift().
	 */
	private function detect_drift( $args ) {
		$translation = LuwiPress_Translation::get_instance();
		$req = new WP_REST_Request( 'GET', '/luwipress/v1/translation/language-drift' );
		$req->set_param( 'post_type', $args['post_type'] );
		$req->set_param( 'limit',     $args['limit'] );
		$req->set_param( 'threshold', $args['threshold'] );
		if ( ! empty( $args['languages'] ) ) {
			$req->set_param( 'languages', implode( ',', $args['languages'] ) );
		}
		$resp = $translation->get_language_drift( $req );
		$data = $this->unwrap_rest( $resp );
		if ( empty( $data['drifted'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $data['drifted'] as $row ) {
			$score = isset( $row['target_score'] ) ? (float) $row['target_score'] : 0.0;
			$out[] = array(
				'finding_id'  => sprintf( 'drift:%d:%s', $row['translation_id'], $row['language'] ),
				'type'        => 'drift',
				'severity'    => $score < 0.2 ? 'high' : ( $score < 0.35 ? 'medium' : 'low' ),
				'source_id'   => isset( $row['source_id'] ) ? (int) $row['source_id'] : 0,
				'target_id'   => (int) $row['translation_id'],
				'lang'        => (string) $row['language'],
				'title'       => isset( $row['title'] ) ? (string) $row['title'] : '',
				'gap_summary' => sprintf(
					'%s body is %d%% target-language; re-translate from source.',
					strtoupper( $row['language'] ),
					(int) round( $score * 100 )
				),
				'fix_action'  => 'force_retranslate',
				'fix_args'    => array(
					'post_ids'  => array( isset( $row['source_id'] ) ? (int) $row['source_id'] : (int) $row['translation_id'] ),
					'languages' => array( (string) $row['language'] ),
				),
				'metric'      => array( 'target_score' => $score ),
			);
		}
		return $out;
	}

	/**
	 * Outdated: source.post_modified_gmt > translation._luwipress_synced_source_modified.
	 * Delegates to LuwiPress_Translation::get_outdated_translations().
	 */
	private function detect_outdated( $args ) {
		$translation = LuwiPress_Translation::get_instance();
		$req = new WP_REST_Request( 'GET', '/luwipress/v1/translation/outdated' );
		$req->set_param( 'post_type', $args['post_type'] );
		$req->set_param( 'limit',     $args['limit'] );
		$resp = $translation->get_outdated_translations( $req );
		$data = $this->unwrap_rest( $resp );
		if ( empty( $data['sources'] ) ) {
			return array();
		}
		$lang_filter = $args['languages'] ?? array();
		$out = array();
		foreach ( $data['sources'] as $src ) {
			foreach ( $src['translations'] as $t ) {
				if ( ! empty( $lang_filter ) && ! in_array( $t['language'], $lang_filter, true ) ) {
					continue;
				}
				$lag = isset( $t['lag_hours'] ) ? (float) $t['lag_hours'] : 0.0;
				$out[] = array(
					'finding_id'  => sprintf( 'outdated:%d:%s', $t['translation_id'], $t['language'] ),
					'type'        => 'outdated',
					'severity'    => $lag > 168 ? 'high' : ( $lag > 24 ? 'medium' : 'low' ),
					'source_id'   => (int) $src['source_id'],
					'target_id'   => (int) $t['translation_id'],
					'lang'        => (string) $t['language'],
					'title'       => (string) $src['title'],
					'gap_summary' => sprintf(
						'%s translation is %s hours behind source (last synced %s).',
						strtoupper( $t['language'] ),
						$lag > 24 ? round( $lag, 1 ) : round( $lag, 1 ),
						$t['synced_at']
					),
					'fix_action'  => 'sync_structure',
					'fix_args'    => array(
						'source_id'  => (int) $src['source_id'],
						'target_ids' => array( (int) $t['translation_id'] ),
					),
					'metric'      => array( 'lag_hours' => $lag ),
				);
			}
		}
		return $out;
	}

	/**
	 * Structural gap: source has more top-level sections than translation
	 * (i.e. translation is missing section IDs that source has).
	 *
	 * Distinct from "outdated" — outdated checks the modified timestamp,
	 * structural_gap actually compares _elementor_data section counts. A
	 * translation can be NEWER than source but still missing a section
	 * because the operator edited the translation independently.
	 */
	private function detect_structural_gap( $args ) {
		if ( ! class_exists( 'LuwiPress_Elementor' ) ) {
			return array();
		}
		$elementor = LuwiPress_Elementor::get_instance();
		$post_type = $args['post_type'];
		$limit     = (int) $args['limit'];
		$lang_filter = $args['languages'] ?? array();

		// Enumerate source-language posts of the requested type that HAVE Elementor data.
		$default_lang = LuwiPress_Translation::get_default_language();
		global $wpdb;
		$source_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_elementor_data'
			 WHERE p.post_type = %s AND p.post_status = 'publish'
			 ORDER BY p.post_modified_gmt DESC LIMIT %d",
			$post_type, $limit
		) );
		if ( empty( $source_ids ) ) {
			return array();
		}

		$out = array();
		foreach ( $source_ids as $sid ) {
			$sid = (int) $sid;
			// Confirm this IS a source post (not a translation), via WPML.
			if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
				$lang = apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $sid, 'element_type' => 'post_' . $post_type ) );
				if ( $lang && $lang !== $default_lang ) {
					continue;
				}
			}
			$src_data = $elementor->get_elementor_data( $sid );
			if ( is_wp_error( $src_data ) || ! is_array( $src_data ) ) {
				continue;
			}
			$src_section_count = count( $src_data );
			if ( $src_section_count < 1 ) {
				continue;
			}
			$src_section_ids = $this->collect_section_ids( $src_data );

			$translations = $elementor->get_translation_ids( $sid );
			foreach ( $translations as $tlang => $tid ) {
				if ( ! empty( $lang_filter ) && ! in_array( $tlang, $lang_filter, true ) ) {
					continue;
				}
				$t_data = $elementor->get_elementor_data( $tid );
				if ( is_wp_error( $t_data ) || ! is_array( $t_data ) ) {
					// Translation has no Elementor data at all → biggest gap.
					$out[] = $this->make_structural_finding( $sid, (int) $tid, (string) $tlang, $src_section_count, 0, array() );
					continue;
				}
				$t_section_ids = $this->collect_section_ids( $t_data );
				$missing       = array_values( array_diff( $src_section_ids, $t_section_ids ) );
				if ( ! empty( $missing ) || count( $t_data ) < $src_section_count ) {
					$out[] = $this->make_structural_finding( $sid, (int) $tid, (string) $tlang, $src_section_count, count( $t_data ), $missing );
				}
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	private function make_structural_finding( $source_id, $target_id, $lang, $src_count, $t_count, $missing_section_ids ) {
		$post = get_post( $source_id );
		$gap  = $src_count - $t_count;
		return array(
			'finding_id'  => sprintf( 'structural_gap:%d:%s', $target_id, $lang ),
			'type'        => 'structural_gap',
			'severity'    => $gap >= 3 ? 'high' : ( $gap >= 1 ? 'medium' : 'low' ),
			'source_id'   => (int) $source_id,
			'target_id'   => (int) $target_id,
			'lang'        => (string) $lang,
			'title'       => $post ? (string) $post->post_title : '',
			'gap_summary' => sprintf(
				'%s translation has %d sections vs source %d (%d missing).',
				strtoupper( $lang ),
				$t_count, $src_count, max( 0, $src_count - $t_count )
			),
			'fix_action'  => 'sync_structure',
			'fix_args'    => array(
				'source_id'     => (int) $source_id,
				'target_ids'    => array( (int) $target_id ),
				'preserve_text' => true,
			),
			'metric'      => array(
				'source_sections'  => $src_count,
				'target_sections'  => $t_count,
				'missing_section_ids' => array_slice( $missing_section_ids, 0, 10 ),
			),
		);
	}

	private function collect_section_ids( $elementor_data ) {
		$ids = array();
		if ( ! is_array( $elementor_data ) ) {
			return $ids;
		}
		foreach ( $elementor_data as $section ) {
			if ( is_array( $section ) && ! empty( $section['id'] ) ) {
				$ids[] = (string) $section['id'];
			}
		}
		return $ids;
	}

	/**
	 * Schema parity: source has FAQ/HowTo/Speakable but a translation doesn't.
	 * Looks at _luwipress_faq, _luwipress_howto, _luwipress_speakable meta
	 * across WPML trid siblings and flags missing keys per translation.
	 */
	private function detect_schema_parity( $args ) {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return array();
		}
		$post_type    = $args['post_type'];
		$limit        = (int) $args['limit'];
		$lang_filter  = $args['languages'] ?? array();
		$default_lang = LuwiPress_Translation::get_default_language();

		global $wpdb;
		// Find source-language posts that have AT LEAST ONE schema meta.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT p.ID
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			   AND pm.meta_key IN ('_luwipress_faq','_luwipress_howto','_luwipress_speakable')
			   AND pm.meta_value != ''
			   AND pm.meta_value != 'a:0:{}'
			 JOIN {$wpdb->prefix}icl_translations t
			   ON t.element_id = p.ID
			  AND t.language_code = %s
			  AND t.source_language_code IS NULL
			 WHERE p.post_type = %s AND p.post_status = 'publish'
			 ORDER BY p.ID DESC LIMIT %d",
			$default_lang, $post_type, $limit
		) );
		if ( empty( $rows ) ) {
			return array();
		}

		$elementor = class_exists( 'LuwiPress_Elementor' ) ? LuwiPress_Elementor::get_instance() : null;
		$out = array();
		foreach ( $rows as $r ) {
			$sid = (int) $r->ID;
			$src_meta = array(
				'faq'        => $this->meta_has( $sid, '_luwipress_faq' ),
				'howto'      => $this->meta_has( $sid, '_luwipress_howto' ),
				'speakable'  => $this->meta_has( $sid, '_luwipress_speakable' ),
			);
			if ( ! array_filter( $src_meta ) ) {
				continue;
			}

			$translations = $elementor ? $elementor->get_translation_ids( $sid ) : $this->wpml_translations_fallback( $sid, $post_type, $default_lang );
			foreach ( $translations as $tlang => $tid ) {
				if ( ! empty( $lang_filter ) && ! in_array( $tlang, $lang_filter, true ) ) {
					continue;
				}
				$missing = array();
				foreach ( $src_meta as $kind => $has_in_source ) {
					if ( $has_in_source && ! $this->meta_has( (int) $tid, '_luwipress_' . $kind ) ) {
						$missing[] = $kind;
					}
				}
				if ( empty( $missing ) ) {
					continue;
				}
				$post = get_post( $sid );
				$out[] = array(
					'finding_id'  => sprintf( 'schema_parity:%d:%s', $tid, $tlang ),
					'type'        => 'schema_parity',
					'severity'    => count( $missing ) >= 2 ? 'high' : 'medium',
					'source_id'   => $sid,
					'target_id'   => (int) $tid,
					'lang'        => (string) $tlang,
					'title'       => $post ? (string) $post->post_title : '',
					'gap_summary' => sprintf(
						'%s translation missing %s schema (source has it).',
						strtoupper( $tlang ),
						implode( ' + ', $missing )
					),
					'fix_action'  => 'copy_schema_from_source',
					'fix_args'    => array(
						'source_id' => $sid,
						'target_id' => (int) $tid,
						'lang'      => (string) $tlang,
						'keys'      => $missing,
					),
					'metric'      => array(
						'missing_schemas' => $missing,
						'source_has'      => array_keys( array_filter( $src_meta ) ),
					),
				);
			}
		}
		return $out;
	}

	private function meta_has( $post_id, $key ) {
		$v = get_post_meta( $post_id, $key, true );
		if ( empty( $v ) ) {
			return false;
		}
		if ( is_array( $v ) && empty( array_filter( $v ) ) ) {
			return false;
		}
		return true;
	}

	private function wpml_translations_fallback( $source_id, $post_type, $default_lang ) {
		global $wpdb;
		$element_type = 'post_' . $post_type;
		$trid = apply_filters( 'wpml_element_trid', null, $source_id, $element_type );
		if ( ! $trid ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT element_id, language_code FROM {$wpdb->prefix}icl_translations
			 WHERE trid = %d AND element_type = %s AND language_code != %s AND element_id IS NOT NULL",
			$trid, $element_type, $default_lang
		) );
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (string) $r->language_code ] = (int) $r->element_id;
		}
		return $out;
	}

	/* ═══════════════════════════════════════════════════════════════════
	 *  FIX — dispatcher
	 * ═══════════════════════════════════════════════════════════════════ */

	/**
	 * Re-run the audit JUST to look up the finding by ID. We don't trust the
	 * caller to send fix_args directly — recompute server-side so a stale
	 * UI cache can't trigger fixes on stale data. This is paranoid but cheap
	 * (a single audit call) and prevents replay-style attacks where a finding
	 * could be repurposed against the wrong post.
	 */
	public function dispatch_fix( $finding_id, $async = true ) {
		$parts = explode( ':', $finding_id );
		if ( count( $parts ) < 3 ) {
			return array( 'status' => 'invalid', 'reason' => 'malformed finding_id' );
		}
		list( $type, $target_id, $lang ) = $parts;
		$target_id = (int) $target_id;
		$lang      = (string) $lang;

		// Resolve the finding on demand so fix_args are server-truth.
		$findings = $this->run_audit( $type, array(
			'post_type' => $this->guess_post_type( $target_id ),
			'languages' => array( $lang ),
			'limit'     => 500,
			'threshold' => (float) get_option( self::OPTION_DRIFT_THRESHOLD, 0.45 ),
		) );
		$match = null;
		foreach ( $findings as $f ) {
			if ( $f['finding_id'] === $finding_id ) {
				$match = $f;
				break;
			}
		}
		if ( ! $match ) {
			return array( 'status' => 'not_found', 'finding_id' => $finding_id );
		}

		switch ( $match['fix_action'] ) {
			case 'force_retranslate':
				return $this->fix_force_retranslate( $match, $async );
			case 'sync_structure':
				return $this->fix_sync_structure( $match );
			case 'copy_schema_from_source':
				return $this->fix_copy_schema( $match );
		}
		return array( 'status' => 'unsupported', 'fix_action' => $match['fix_action'] );
	}

	private function fix_force_retranslate( $finding, $async ) {
		$translation = LuwiPress_Translation::get_instance();
		$req = new WP_REST_Request( 'POST', '/luwipress/v1/translation/force-retranslate' );
		$req->set_param( 'post_ids',  $finding['fix_args']['post_ids'] );
		$req->set_param( 'languages', $finding['fix_args']['languages'] );
		$req->set_param( 'async',     $async );
		$resp = $translation->force_retranslate( $req );
		return $this->unwrap_rest( $resp );
	}

	private function fix_sync_structure( $finding ) {
		if ( ! class_exists( 'LuwiPress_Elementor' ) ) {
			return array( 'status' => 'unavailable', 'reason' => 'Elementor module not loaded' );
		}
		$elementor = LuwiPress_Elementor::get_instance();
		$req = new WP_REST_Request( 'POST', '/luwipress/v1/elementor/sync-structure' );
		$req->set_param( 'source_id',     $finding['fix_args']['source_id'] );
		$req->set_param( 'target_ids',    $finding['fix_args']['target_ids'] );
		$req->set_param( 'preserve_text', isset( $finding['fix_args']['preserve_text'] ) ? (bool) $finding['fix_args']['preserve_text'] : true );
		$resp = $elementor->rest_sync_structure( $req );
		return $this->unwrap_rest( $resp );
	}

	/**
	 * Copy FAQ/HowTo/Speakable meta from source to target. NOTE: this copies the
	 * RAW source text (English). Operator must run translation afterwards if the
	 * site policy requires localized FAQ. Surfaced explicitly in the response so
	 * the operator knows the parity is structural, not linguistic.
	 */
	private function fix_copy_schema( $finding ) {
		$source_id = (int) $finding['fix_args']['source_id'];
		$target_id = (int) $finding['fix_args']['target_id'];
		$keys      = isset( $finding['fix_args']['keys'] ) && is_array( $finding['fix_args']['keys'] )
			? $finding['fix_args']['keys']
			: array();
		$copied = array();
		foreach ( $keys as $kind ) {
			$meta_key = '_luwipress_' . $kind;
			$src_val  = get_post_meta( $source_id, $meta_key, true );
			if ( ! empty( $src_val ) ) {
				update_post_meta( $target_id, $meta_key, $src_val );
				$copied[] = $kind;
			}
		}
		delete_transient( 'luwipress_aeo_coverage' );
		return array(
			'status'      => 'copied',
			'source_id'   => $source_id,
			'target_id'   => $target_id,
			'copied'      => $copied,
			'note'        => 'Schema text copied verbatim from source. Run /translation/request if localized FAQ is required.',
		);
	}

	/* ═══════════════════════════════════════════════════════════════════
	 *  KG candidate emitter
	 * ═══════════════════════════════════════════════════════════════════ */

	/**
	 * Surface drift + structural_gap findings as KG Action Queue candidates so
	 * operators see translation sync gaps in the dashboard, not just in the
	 * Translation Manager. Caps at the top 8 findings by severity to avoid
	 * crowding out core opportunities.
	 */
	public function emit_kg_candidates( $candidates ) {
		// Only emit if WPML/Polylang is detected — without a multilingual setup
		// there's nothing to sync.
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return $candidates;
		}

		// Use the cached last-audit summary if recent (< 1h) to avoid re-scanning
		// 500 posts on every KG page load. Caller can force a fresh audit via the
		// REST endpoint when they want truth.
		$last = get_option( self::OPTION_LAST_AUDIT, null );
		$run_fresh = true;
		if ( is_array( $last ) && ! empty( $last['timestamp'] ) ) {
			$age = time() - strtotime( $last['timestamp'] );
			if ( $age < HOUR_IN_SECONDS ) {
				$run_fresh = false;
			}
		}

		$findings = array();
		if ( $run_fresh ) {
			$findings = $this->run_audit( 'all', array(
				'post_type' => 'product',
				'languages' => LuwiPress_Translation::get_active_languages(),
				'limit'     => 50,
				'threshold' => (float) get_option( self::OPTION_DRIFT_THRESHOLD, 0.45 ),
			) );
			update_option( self::OPTION_LAST_AUDIT, array(
				'timestamp'        => current_time( 'c' ),
				'type'             => 'all',
				'post_type'        => 'product',
				'findings_total'   => count( $findings ),
				'findings_by_type' => $this->count_by_type( $findings ),
			), false );
		}

		// Take top 8 by severity and emit a CG candidate per group-of-language.
		$by_type_lang = array();
		foreach ( $findings as $f ) {
			$k = $f['type'] . '|' . $f['lang'];
			if ( ! isset( $by_type_lang[ $k ] ) ) {
				$by_type_lang[ $k ] = array(
					'type'     => $f['type'],
					'lang'     => $f['lang'],
					'severity' => $f['severity'],
					'count'    => 0,
					'sample'   => $f,
				);
			}
			$by_type_lang[ $k ]['count']++;
			if ( $this->severity_rank( $f['severity'] ) > $this->severity_rank( $by_type_lang[ $k ]['severity'] ) ) {
				$by_type_lang[ $k ]['severity'] = $f['severity'];
				$by_type_lang[ $k ]['sample']   = $f;
			}
		}
		// Sort groups by severity then count
		uasort( $by_type_lang, function( $a, $b ) {
			$sa = self::severity_rank( $a['severity'] );
			$sb = self::severity_rank( $b['severity'] );
			if ( $sa !== $sb ) return $sb - $sa;
			return $b['count'] - $a['count'];
		} );
		$emit_count = 0;
		foreach ( $by_type_lang as $g ) {
			if ( $emit_count >= 8 ) break;
			$type_labels = array(
				'drift'          => 'language drift',
				'outdated'       => 'outdated translations',
				'structural_gap' => 'structural gaps',
				'schema_parity'  => 'schema parity gaps',
			);
			$label = $type_labels[ $g['type'] ] ?? $g['type'];
			$impact = $g['severity'] === 'high' ? 65 : ( $g['severity'] === 'medium' ? 45 : 25 );
			$effort = max( 5, min( 30, $g['count'] * 2 ) );
			$candidates[] = array(
				'id'          => 'translation_sync:' . $g['type'] . ':' . $g['lang'],
				'type'        => 'translation_sync_gap',
				'title'       => sprintf( '%d %s in %s', $g['count'], $label, strtoupper( $g['lang'] ) ),
				'body'        => $g['sample']['gap_summary'],
				'impact'      => $impact,
				'effort_min'  => $effort,
				'roi'         => $impact / $effort,
				'tier'        => $g['severity'] === 'high' ? 'high' : 'standard',
				'entity_type' => 'translation_sync',
				'entity_id'   => $g['lang'],
				'why'         => array(
					'primary_signal'      => sprintf( 'Translation Sync detected %d %s entries in %s.', $g['count'], $g['type'], $g['lang'] ),
					'supporting_signals'  => array( $g['sample']['gap_summary'] ),
					'baseline_comparison' => sprintf( 'Severity: %s', $g['severity'] ),
				),
				'cta'         => array(
					'label' => __( 'Open Translation Sync', 'luwipress' ),
					'href'  => admin_url( 'admin.php?page=luwipress-translation#sync-audit' ),
				),
			);
			$emit_count++;
		}
		return $candidates;
	}

	/* ═══════════════════════════════════════════════════════════════════
	 *  CRON — hourly sweep (opt-in)
	 * ═══════════════════════════════════════════════════════════════════ */

	public function maybe_schedule_sweep() {
		$enabled = (bool) get_option( self::OPTION_SWEEP_ENABLED, false );
		$next    = wp_next_scheduled( self::CRON_HOOK );
		if ( $enabled && ! $next ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		} elseif ( ! $enabled && $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
	}

	public function on_sweep_setting_changed( $old, $new ) {
		$this->maybe_schedule_sweep();
	}

	/**
	 * Hourly sweep: run audit, optionally auto-fix high-severity findings,
	 * log a summary into the activity log.
	 */
	public function run_sweep() {
		$autofix = (bool) get_option( self::OPTION_SWEEP_AUTOFIX, false );
		$findings = $this->run_audit( 'all', array(
			'post_type' => 'product',
			'languages' => LuwiPress_Translation::get_active_languages(),
			'limit'     => 100,
			'threshold' => (float) get_option( self::OPTION_DRIFT_THRESHOLD, 0.45 ),
		) );
		$summary = $this->count_by_type( $findings );

		$fixed = 0;
		if ( $autofix ) {
			foreach ( $findings as $f ) {
				if ( $f['severity'] !== 'high' ) {
					continue;
				}
				$res = $this->dispatch_fix( $f['finding_id'], /* async */ true );
				if ( isset( $res['status'] ) && ! in_array( $res['status'], array( 'invalid', 'not_found', 'unsupported', 'unavailable' ), true ) ) {
					$fixed++;
				}
				// Don't drown the wp_cron tick with too many fixes per sweep — cap at 20.
				if ( $fixed >= 20 ) {
					break;
				}
			}
		}

		if ( class_exists( 'LuwiPress_Logger' ) ) {
			LuwiPress_Logger::log(
				sprintf( 'Translation sync sweep: %d findings (autofix=%s, fixed=%d). %s',
					count( $findings ),
					$autofix ? 'on' : 'off',
					$fixed,
					wp_json_encode( $summary )
				),
				'info',
				array( 'workflow' => 'translation_sync_sweep' )
			);
		}

		update_option( self::OPTION_LAST_AUDIT, array(
			'timestamp'        => current_time( 'c' ),
			'type'             => 'all',
			'post_type'        => 'product',
			'findings_total'   => count( $findings ),
			'findings_by_type' => $summary,
			'autofix_run'      => $autofix,
			'autofix_count'    => $fixed,
		), false );
	}

	/* ═══════════════════════════════════════════════════════════════════
	 *  Helpers
	 * ═══════════════════════════════════════════════════════════════════ */

	private function normalize_languages_param( $raw ) {
		if ( empty( $raw ) ) {
			$default_lang = LuwiPress_Translation::get_default_language();
			return array_values( array_filter(
				LuwiPress_Translation::get_active_languages(),
				function( $l ) use ( $default_lang ) { return $l !== $default_lang; }
			) );
		}
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		return array_values( array_filter( array_map( function( $x ) { return strtolower( sanitize_text_field( trim( $x ) ) ); }, (array) $raw ) ) );
	}

	private function guess_post_type( $post_id ) {
		$p = get_post( $post_id );
		return $p ? $p->post_type : 'product';
	}

	private function count_by_type( $findings ) {
		$c = array( 'drift' => 0, 'outdated' => 0, 'structural_gap' => 0, 'schema_parity' => 0 );
		foreach ( $findings as $f ) {
			$t = $f['type'] ?? '';
			if ( isset( $c[ $t ] ) ) $c[ $t ]++;
		}
		return $c;
	}

	private function unwrap_rest( $resp ) {
		if ( is_wp_error( $resp ) ) {
			return array( 'error' => $resp->get_error_message() );
		}
		if ( $resp instanceof WP_REST_Response ) {
			return $resp->get_data();
		}
		return is_array( $resp ) ? $resp : array( 'data' => $resp );
	}

	public function severity_sort( $a, $b ) {
		$ra = self::severity_rank( $a['severity'] ?? 'low' );
		$rb = self::severity_rank( $b['severity'] ?? 'low' );
		if ( $ra !== $rb ) return $rb - $ra;
		// Tie-break by numeric metric where it makes sense.
		$ma = isset( $a['metric']['lag_hours'] ) ? $a['metric']['lag_hours']
			: ( isset( $a['metric']['target_score'] ) ? ( 1.0 - $a['metric']['target_score'] ) * 100 : 0 );
		$mb = isset( $b['metric']['lag_hours'] ) ? $b['metric']['lag_hours']
			: ( isset( $b['metric']['target_score'] ) ? ( 1.0 - $b['metric']['target_score'] ) * 100 : 0 );
		return $mb <=> $ma;
	}

	private static function severity_rank( $sev ) {
		return $sev === 'high' ? 3 : ( $sev === 'medium' ? 2 : 1 );
	}
}
