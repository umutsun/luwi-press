<?php
/**
 * LuwiPress Theme Bridge
 *
 * Single contract surface between the LuwiPress plugin and the active theme
 * (e.g. luwipress-gold). Themes register maintenance tools and theme_mod
 * proxies via two filters; the plugin renders them in the admin "Theme" tab,
 * exposes them as REST endpoints, and surfaces them through WebMCP.
 *
 *   add_filter( 'luwipress_theme_tools',    fn($tools, $slug) => ... );
 *   add_filter( 'luwipress_theme_settings', fn($settings, $slug) => ... );
 *
 * Tool shape:
 *   [
 *     'id'          => 'elementor_shell_cleanup',
 *     'label'       => __( 'Elementor Shell Cleanup', 'luwipress-gold' ),
 *     'description' => '...',
 *     'category'    => 'maintenance',          // maintenance | audit | migration
 *     'capability'  => 'edit_others_posts',    // required cap
 *     'wpml_aware'  => true,                   // auto-expand trid siblings
 *     'destructive' => true,                   // execute() mutates posts
 *     'callbacks'   => [
 *       'scan'    => callable,  // ($args) => [ 'candidates' => [...], 'meta' => [...] ]
 *       'execute' => callable,  // ($post_ids, $args) => [ 'mutated' => N, 'backup_id' => '...' ]
 *       'restore' => callable,  // ($backup_id, $args) => [ 'restored' => N ]
 *     ],
 *   ]
 *
 * Setting shape:
 *   [
 *     'id'        => 'loader_enabled',
 *     'theme_mod' => 'luwipress_gold_loader_enabled',
 *     'label'     => __( 'Page loader overlay', 'luwipress-gold' ),
 *     'type'      => 'checkbox',  // checkbox | text | number | select
 *     'default'   => true,
 *     'group'     => 'performance',
 *     'choices'   => [ ... ],     // for type=select
 *     'min'/'max' => N,           // for type=number
 *   ]
 *
 * Backups live in wp_options.luwipress_theme_tool_backups — JSON array,
 * pruned to the last 20 entries to defend against the 412 KB option-size
 * truncation we hit on Kit CSS.
 *
 * @package LuwiPress
 * @since   3.1.48
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Theme_Bridge {

	private static $instance = null;

	const BACKUPS_OPTION = 'luwipress_theme_tool_backups';
	const BACKUPS_LIMIT  = 20;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// REST routes register on rest_api_init.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Admin AJAX (cookie + nonce auth) — same handlers, different transport.
		add_action( 'wp_ajax_luwipress_theme_tools_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_luwipress_theme_tools_run', array( $this, 'ajax_run' ) );
		add_action( 'wp_ajax_luwipress_theme_tools_restore', array( $this, 'ajax_restore' ) );
		add_action( 'wp_ajax_luwipress_theme_tools_backups', array( $this, 'ajax_backups' ) );
		add_action( 'wp_ajax_luwipress_theme_settings_save', array( $this, 'ajax_settings_save' ) );
		add_action( 'wp_ajax_luwipress_theme_settings_reset', array( $this, 'ajax_settings_reset' ) );

		// KG Action Queue integration — surfaces audit findings as candidates
		// alongside the core enrichment / SEO opportunities. Cached for an hour
		// so the dashboard doesn't HEAD-check 200 archives on every refresh.
		add_filter( 'luwipress_kg_action_queue_external_candidates', array( $this, 'inject_kg_candidates' ) );

		// Bust the candidate cache whenever a tool is executed (the result of
		// the run usually clears the underlying finding). Triggered via the
		// internal action fired by run_tool() below.
		add_action( 'luwipress_theme_tool_executed', array( $this, 'bust_kg_candidate_cache' ) );
	}

	/**
	 * Currently active theme slug (stylesheet). Companions can register tools
	 * for any slug; we filter to only show the active theme's registry.
	 */
	public function active_theme_slug() {
		return (string) get_stylesheet();
	}

	/**
	 * All tools registered for the active theme. Returns an array of tool
	 * definitions (see file header for shape). Empty array when no theme
	 * has registered.
	 *
	 * @return array
	 */
	public function get_tools() {
		$slug  = $this->active_theme_slug();
		$tools = apply_filters( 'luwipress_theme_tools', array(), $slug );
		if ( ! is_array( $tools ) ) {
			$tools = array();
		}
		// Normalise + drop malformed entries (missing id or scan callback).
		$normalised = array();
		foreach ( $tools as $tool ) {
			if ( empty( $tool['id'] ) || empty( $tool['callbacks']['scan'] ) ) {
				continue;
			}
			$normalised[] = wp_parse_args( $tool, array(
				'id'          => '',
				'label'       => $tool['id'],
				'description' => '',
				'category'    => 'maintenance',
				'capability'  => 'manage_options',
				'wpml_aware'  => false,
				'destructive' => false,
				'callbacks'   => array(),
			) );
		}
		return $normalised;
	}

	/**
	 * One tool by id. Null when the tool isn't registered for the active theme.
	 */
	public function get_tool( $id ) {
		$id = sanitize_key( $id );
		foreach ( $this->get_tools() as $tool ) {
			if ( $tool['id'] === $id ) {
				return $tool;
			}
		}
		return null;
	}

	/**
	 * Settings registered for the active theme.
	 *
	 * @return array
	 */
	public function get_settings() {
		$slug     = $this->active_theme_slug();
		$settings = apply_filters( 'luwipress_theme_settings', array(), $slug );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$normalised = array();
		foreach ( $settings as $s ) {
			if ( empty( $s['id'] ) || empty( $s['theme_mod'] ) ) {
				continue;
			}
			$normalised[] = wp_parse_args( $s, array(
				'id'        => '',
				'theme_mod' => '',
				'label'     => $s['id'],
				'type'      => 'text',
				'default'   => '',
				'group'     => 'general',
				'choices'   => array(),
			) );
		}
		return $normalised;
	}

	/**
	 * Expand a list of source post IDs to include their WPML or Polylang
	 * siblings. Only called when a tool's `wpml_aware` flag is true.
	 *
	 * @param  int[] $post_ids
	 * @return int[]
	 */
	public function expand_to_siblings( array $post_ids ) {
		$expanded = array();
		foreach ( $post_ids as $pid ) {
			$pid = (int) $pid;
			if ( $pid <= 0 ) {
				continue;
			}
			$expanded[] = $pid;
			$type       = get_post_type( $pid );
			if ( ! $type ) {
				continue;
			}
			if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
				$translations = apply_filters(
					'wpml_get_element_translations',
					null,
					apply_filters( 'wpml_element_trid', null, $pid, 'post_' . $type ),
					'post_' . $type
				);
				if ( is_array( $translations ) ) {
					foreach ( $translations as $t ) {
						if ( ! empty( $t->element_id ) ) {
							$expanded[] = (int) $t->element_id;
						}
					}
				}
			} elseif ( function_exists( 'pll_get_post_translations' ) ) {
				$sibs = pll_get_post_translations( $pid );
				foreach ( (array) $sibs as $sib_id ) {
					if ( $sib_id ) {
						$expanded[] = (int) $sib_id;
					}
				}
			}
		}
		return array_values( array_unique( array_filter( $expanded ) ) );
	}

	/**
	 * Run a tool action. Centralises capability check, nonce (when called via
	 * REST/AJAX wrappers), and WPML expansion.
	 *
	 * @param  string $tool_id
	 * @param  string $action  scan | execute | restore
	 * @param  array  $args
	 * @return array|WP_Error
	 */
	public function run_tool( $tool_id, $action, $args = array() ) {
		$tool = $this->get_tool( $tool_id );
		if ( ! $tool ) {
			return new WP_Error( 'tool_not_found', sprintf( 'Tool "%s" not registered for theme "%s".', $tool_id, $this->active_theme_slug() ), array( 'status' => 404 ) );
		}

		$action = sanitize_key( $action );
		if ( ! in_array( $action, array( 'scan', 'execute', 'restore' ), true ) ) {
			return new WP_Error( 'invalid_action', 'Action must be scan, execute, or restore.', array( 'status' => 400 ) );
		}

		// Capability gate. Admin-cookie callers go through current_user_can();
		// MCP/token callers are admin-equivalent (token holders are operators by
		// definition — same trust level as a logged-in admin) and bypass the cap
		// check because check_token() does NOT call wp_set_current_user(), so
		// current_user_can() would otherwise return false for valid token requests
		// and lock every theme tool behind a 403 (Vendor-FR-008, 2026-05-21).
		$cap = $tool['capability'];
		if ( $cap && ! current_user_can( $cap ) && ! ( class_exists( 'LuwiPress_Permission' ) && LuwiPress_Permission::is_token_authenticated() ) ) {
			return new WP_Error( 'forbidden', sprintf( 'Capability "%s" required.', $cap ), array( 'status' => 403 ) );
		}

		if ( empty( $tool['callbacks'][ $action ] ) || ! is_callable( $tool['callbacks'][ $action ] ) ) {
			return new WP_Error( 'callback_missing', sprintf( 'Tool "%s" does not implement "%s".', $tool_id, $action ), array( 'status' => 501 ) );
		}

		// Auto-expand post IDs for WPML-aware tools on execute/restore actions.
		// Scans are deliberately NOT expanded — they discover candidates first;
		// the operator confirms which to mutate, then execute/restore expands.
		if ( ! empty( $tool['wpml_aware'] ) && in_array( $action, array( 'execute', 'restore' ), true ) && ! empty( $args['post_ids'] ) ) {
			$args['_expanded_post_ids'] = $this->expand_to_siblings( (array) $args['post_ids'] );
		}

		try {
			$result = call_user_func( $tool['callbacks'][ $action ], $args, $tool );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( '[%s] %s threw: %s', $tool_id, $action, $e->getMessage() ), 'error', array( 'file' => $e->getFile(), 'line' => $e->getLine() ) );
			return new WP_Error( 'tool_exception', $e->getMessage(), array( 'status' => 500 ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			$result = array( 'result' => $result );
		}
		$result['tool_id'] = $tool_id;
		$result['action']  = $action;

		// Persist a backup record on execute. The tool itself is responsible
		// for capturing the pre-mutation payload and returning it under
		// `_backup_payload`; the bridge wraps and stores it.
		if ( 'execute' === $action && ! empty( $result['_backup_payload'] ) ) {
			$backup_id = $this->store_backup( $tool_id, $result['_backup_payload'], isset( $args['_expanded_post_ids'] ) ? $args['_expanded_post_ids'] : ( $args['post_ids'] ?? array() ) );
			$result['backup_id'] = $backup_id;
			unset( $result['_backup_payload'] );
		}

		$this->log( sprintf( '[%s] %s ran', $tool_id, $action ), 'info', array(
			'theme'     => $this->active_theme_slug(),
			'mutated'   => $result['mutated'] ?? null,
			'candidates'=> isset( $result['candidates'] ) ? count( $result['candidates'] ) : null,
			'backup_id' => $result['backup_id'] ?? null,
		) );

		/**
		 * Fires after a Theme Bridge tool action completes successfully.
		 * KG Action Queue cache invalidation listens to this; companions can
		 * use it to react to specific tool runs (analytics, slack pings, etc).
		 */
		do_action( 'luwipress_theme_tool_executed', $tool_id, $action, $result );

		return $result;
	}

	/**
	 * Store a backup record. Pruned to BACKUPS_LIMIT entries to keep option
	 * size bounded.
	 */
	public function store_backup( $tool_id, $payload, $post_ids = array() ) {
		$all = $this->load_backups_raw();
		$id  = wp_generate_uuid4();
		$entry = array(
			'id'       => $id,
			'tool_id'  => sanitize_key( $tool_id ),
			'ts'       => time(),
			'post_ids' => array_values( array_map( 'intval', (array) $post_ids ) ),
			'payload'  => $payload,
		);
		array_unshift( $all, $entry );
		if ( count( $all ) > self::BACKUPS_LIMIT ) {
			$all = array_slice( $all, 0, self::BACKUPS_LIMIT );
		}
		update_option( self::BACKUPS_OPTION, $all, false );
		return $id;
	}

	/**
	 * Public listing — strips heavy `payload` so the table view stays small.
	 */
	public function get_backups( $tool_id = null ) {
		$all = $this->load_backups_raw();
		$out = array();
		foreach ( $all as $entry ) {
			if ( $tool_id && $entry['tool_id'] !== sanitize_key( $tool_id ) ) {
				continue;
			}
			$out[] = array(
				'id'         => $entry['id'],
				'tool_id'    => $entry['tool_id'],
				'ts'         => $entry['ts'],
				'date'       => date_i18n( 'Y-m-d H:i', $entry['ts'] ),
				'post_ids'   => $entry['post_ids'],
				'post_count' => count( $entry['post_ids'] ),
			);
		}
		return $out;
	}

	/**
	 * Internal — returns the full backup record by id (with payload).
	 */
	public function load_backup( $backup_id ) {
		$backup_id = sanitize_text_field( $backup_id );
		foreach ( $this->load_backups_raw() as $entry ) {
			if ( $entry['id'] === $backup_id ) {
				return $entry;
			}
		}
		return null;
	}

	private function load_backups_raw() {
		$all = get_option( self::BACKUPS_OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Save a single setting (theme_mod proxy). Validates against the registered
	 * setting definition.
	 */
	public function save_setting( $setting_id, $value ) {
		$setting_id = sanitize_key( $setting_id );
		$def = null;
		foreach ( $this->get_settings() as $s ) {
			if ( $s['id'] === $setting_id ) {
				$def = $s;
				break;
			}
		}
		if ( ! $def ) {
			return new WP_Error( 'setting_not_found', sprintf( 'Setting "%s" not registered.', $setting_id ), array( 'status' => 404 ) );
		}

		switch ( $def['type'] ) {
			case 'checkbox':
				$value = (bool) $value;
				break;
			case 'number':
				$value = (int) $value;
				if ( isset( $def['min'] ) ) { $value = max( (int) $def['min'], $value ); }
				if ( isset( $def['max'] ) ) { $value = min( (int) $def['max'], $value ); }
				break;
			case 'select':
				$choices = array_keys( $def['choices'] );
				if ( ! in_array( (string) $value, array_map( 'strval', $choices ), true ) ) {
					return new WP_Error( 'invalid_choice', 'Value not in allowed choices.', array( 'status' => 400 ) );
				}
				$value = sanitize_text_field( $value );
				break;
			default:
				$value = sanitize_text_field( $value );
		}

		set_theme_mod( $def['theme_mod'], $value );
		return array( 'saved' => true, 'id' => $setting_id, 'value' => $value );
	}

	// ─── REST routes ───────────────────────────────────────────────────────

	public function register_rest_routes() {
		$ns = 'luwipress/v1';
		$auth = array( 'LuwiPress_Permission', 'check_token_or_admin' );

		register_rest_route( $ns, '/theme/tools', array(
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => array( $this, 'rest_list_tools' ),
		) );

		register_rest_route( $ns, '/theme/tools/scan', array(
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => array( $this, 'rest_scan' ),
		) );

		register_rest_route( $ns, '/theme/tools/run', array(
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => array( $this, 'rest_run' ),
		) );

		register_rest_route( $ns, '/theme/tools/restore', array(
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => array( $this, 'rest_restore' ),
		) );

		register_rest_route( $ns, '/theme/tools/backups', array(
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => array( $this, 'rest_backups' ),
		) );

		register_rest_route( $ns, '/theme/settings', array(
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => array( $this, 'rest_settings_get' ),
		) );

		register_rest_route( $ns, '/theme/settings', array(
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => array( $this, 'rest_settings_post' ),
		) );

		register_rest_route( $ns, '/theme/status', array(
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => array( $this, 'rest_status' ),
		) );
	}

	public function rest_list_tools( $request ) {
		$tools = array();
		foreach ( $this->get_tools() as $t ) {
			$tools[] = $this->shape_tool_for_listing( $t );
		}
		return rest_ensure_response( array(
			'theme' => $this->active_theme_slug(),
			'tools' => $tools,
		) );
	}

	public function rest_scan( $request ) {
		$id   = sanitize_key( (string) $request->get_param( 'tool_id' ) );
		$args = (array) $request->get_param( 'args' );
		$res  = $this->run_tool( $id, 'scan', $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( $res );
	}

	public function rest_run( $request ) {
		$id    = sanitize_key( (string) $request->get_param( 'tool_id' ) );
		$args  = (array) $request->get_param( 'args' );
		$ids   = (array) $request->get_param( 'post_ids' );
		if ( ! empty( $ids ) ) {
			$args['post_ids'] = array_map( 'intval', $ids );
		}
		$res = $this->run_tool( $id, 'execute', $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( $res );
	}

	public function rest_restore( $request ) {
		$id        = sanitize_key( (string) $request->get_param( 'tool_id' ) );
		$backup_id = sanitize_text_field( (string) $request->get_param( 'backup_id' ) );
		$args      = (array) $request->get_param( 'args' );
		$args['backup_id'] = $backup_id;
		$res = $this->run_tool( $id, 'restore', $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( $res );
	}

	public function rest_backups( $request ) {
		$tool_id = $request->get_param( 'tool_id' );
		$tool_id = $tool_id ? sanitize_key( $tool_id ) : null;
		return rest_ensure_response( array(
			'theme'   => $this->active_theme_slug(),
			'backups' => $this->get_backups( $tool_id ),
		) );
	}

	public function rest_settings_get( $request ) {
		$out = array();
		foreach ( $this->get_settings() as $s ) {
			$default = $s['default'] ?? '';
			$value   = get_theme_mod( $s['theme_mod'], $default );
			$out[]   = array(
				'id'        => $s['id'],
				'theme_mod' => $s['theme_mod'],
				'label'     => $s['label'],
				'type'      => $s['type'],
				'group'     => $s['group'],
				'default'   => $default,
				'value'     => $value,
				'choices'   => $s['choices'] ?? array(),
				'min'       => $s['min'] ?? null,
				'max'       => $s['max'] ?? null,
			);
		}
		return rest_ensure_response( array(
			'theme'    => $this->active_theme_slug(),
			'settings' => $out,
		) );
	}

	public function rest_settings_post( $request ) {
		$values = (array) $request->get_param( 'values' );
		if ( empty( $values ) ) {
			$id    = sanitize_key( (string) $request->get_param( 'id' ) );
			$value = $request->get_param( 'value' );
			if ( $id !== '' ) {
				$values = array( $id => $value );
			}
		}
		$saved = array();
		$errors = array();
		foreach ( $values as $id => $value ) {
			$res = $this->save_setting( $id, $value );
			if ( is_wp_error( $res ) ) {
				$errors[ $id ] = $res->get_error_message();
				continue;
			}
			$saved[ $id ] = $res['value'];
		}
		return rest_ensure_response( array(
			'saved'  => $saved,
			'errors' => $errors,
		) );
	}

	public function rest_status( $request ) {
		$detector = class_exists( 'LuwiPress_Plugin_Detector' ) ? LuwiPress_Plugin_Detector::get_instance() : null;
		$theme    = $detector ? $detector->detect_theme() : array( 'slug' => $this->active_theme_slug(), 'detected' => true );
		$companion= apply_filters( 'luwipress_theme_companion', array() );
		$slug     = $this->active_theme_slug();
		return rest_ensure_response( array(
			'theme'        => $theme,
			'capabilities' => $companion[ $slug ] ?? null,
			'tool_count'   => count( $this->get_tools() ),
			'setting_count'=> count( $this->get_settings() ),
		) );
	}

	// ─── Admin AJAX (cookie+nonce) ─────────────────────────────────────────

	public function ajax_scan() {
		$this->ajax_dispatch( 'scan' );
	}
	public function ajax_run() {
		$this->ajax_dispatch( 'execute' );
	}
	public function ajax_restore() {
		$this->ajax_dispatch( 'restore' );
	}

	private function ajax_dispatch( $action ) {
		check_ajax_referer( 'luwipress_theme_tools', 'nonce' );
		$tool_id = sanitize_key( $_POST['tool_id'] ?? '' );
		$args    = isset( $_POST['args'] ) && is_array( $_POST['args'] ) ? wp_unslash( $_POST['args'] ) : array();
		$ids     = isset( $_POST['post_ids'] ) ? (array) $_POST['post_ids'] : array();
		if ( ! empty( $ids ) ) {
			$args['post_ids'] = array_map( 'intval', $ids );
		}
		if ( 'restore' === $action ) {
			$args['backup_id'] = sanitize_text_field( $_POST['backup_id'] ?? '' );
		}
		$res = $this->run_tool( $tool_id, $action, $args );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message(), 'code' => $res->get_error_code() ), 400 );
		}
		wp_send_json_success( $res );
	}

	public function ajax_backups() {
		check_ajax_referer( 'luwipress_theme_tools', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		$tool_id = isset( $_GET['tool_id'] ) ? sanitize_key( $_GET['tool_id'] ) : null;
		wp_send_json_success( array( 'backups' => $this->get_backups( $tool_id ) ) );
	}

	public function ajax_settings_save() {
		check_ajax_referer( 'luwipress_theme_tools', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		$values = isset( $_POST['values'] ) && is_array( $_POST['values'] ) ? wp_unslash( $_POST['values'] ) : array();
		$saved = array();
		$errors = array();
		foreach ( $values as $id => $value ) {
			$res = $this->save_setting( $id, $value );
			if ( is_wp_error( $res ) ) {
				$errors[ $id ] = $res->get_error_message();
				continue;
			}
			$saved[ $id ] = $res['value'];
		}
		wp_send_json_success( array( 'saved' => $saved, 'errors' => $errors ) );
	}

	// ─── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Drop callback closures from the listing payload — they're internal
	 * implementation details, not API contract.
	 */
	private function shape_tool_for_listing( $tool ) {
		$out = $tool;
		unset( $out['callbacks'] );
		$out['actions'] = array_keys( $tool['callbacks'] );
		return $out;
	}

	private function log( $message, $level = 'info', $context = array() ) {
		if ( class_exists( 'LuwiPress_Logger' ) ) {
			LuwiPress_Logger::log( '[ThemeBridge] ' . $message, $level, $context );
		}
	}

	// ─── KG Action Queue integration ─────────────────────────────────────

	/**
	 * Convert active-theme audit findings into KG Action Queue candidates.
	 * Hooks into `luwipress_kg_action_queue_external_candidates`.
	 *
	 * Cached for one hour in the `luwipress_theme_kg_candidates` transient
	 * to keep the dashboard responsive — running every audit on every KG
	 * refresh would HEAD-check dozens of URLs and rebuild Triangle Health
	 * synchronously.
	 *
	 * Tools ranked by severity: 5xx/404 hits get the highest impact, missing
	 * translations next, then orphan/cleanup work last. Each finding becomes
	 * one candidate so the operator can snooze/dismiss individually.
	 */
	public function inject_kg_candidates( $candidates ) {
		if ( ! is_array( $candidates ) ) {
			$candidates = array();
		}
		$cache_key = 'luwipress_theme_kg_candidates';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return array_merge( $candidates, $cached );
		}

		$out = array();

		// Mapping: tool_id → (impact, effort, tier, label) — drives ROI rank
		// and how the candidate reads in the Action Queue.
		$rubric = array(
			// SEO + redirect tier — visibility & indexation impact
			'canonical_audit'                  => array( 88, 12, 'high',   __( 'Canonical mismatch', 'luwipress' ) ),
			'hreflang_reciprocity_audit'       => array( 82, 18, 'high',   __( 'Hreflang drift', 'luwipress' ) ),
			'redirect_chain_detector'          => array( 78, 10, 'high',   __( 'Redirect chain', 'luwipress' ) ),
			'sitemap_indexation_parity'        => array( 76, 12, 'high',   __( 'Sitemap entry leaks', 'luwipress' ) ),
			// WC + WPML tier
			'subcategory_template_parity'      => array( 90, 20, 'high',   __( 'Broken subcategory archive', 'luwipress' ) ),
			'wpml_term_repair'                 => array( 85, 15, 'high',   __( 'Missing translated term', 'luwipress' ) ),
			'elementor_to_default_editor'      => array( 88, 5,  'high',   __( 'Elementor hijacking single-post layout', 'luwipress' ) ),
			'product_translation_completeness' => array( 75, 25, 'medium', __( 'Untranslated product', 'luwipress' ) ),
			'wpml_translation_drift'           => array( 60, 20, 'medium', __( 'Translation out of sync', 'luwipress' ) ),
			'broken_internal_links'            => array( 70, 10, 'medium', __( 'Broken internal link', 'luwipress' ) ),
			'unwanted_landing_pages'           => array( 50, 5,  'medium', __( 'Orphan SEO landing', 'luwipress' ) ),
			'empty_term_archives'              => array( 40, 8,  'low',    __( 'Empty category in menu', 'luwipress' ) ),
			'kit_css_health'                   => array( 55, 30, 'medium', __( 'Kit CSS approaching limit', 'luwipress' ) ),
			'page_speed_signals'               => array( 65, 15, 'medium', __( 'Performance signal regression', 'luwipress' ) ),
			'wpml_string_translation_pending'  => array( 45, 10, 'low',    __( 'Pending UI string translation', 'luwipress' ) ),
		);

		foreach ( array_keys( $rubric ) as $tool_id ) {
			$tool = $this->get_tool( $tool_id );
			if ( ! $tool ) {
				continue;
			}
			try {
				$res = $this->run_tool( $tool_id, 'scan', array() );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( is_wp_error( $res ) || empty( $res['candidates'] ) ) {
				continue;
			}
			list( $impact, $effort, $tier, $label ) = $rubric[ $tool_id ];

			// Only the first 3 findings per tool become individual candidates.
			// The rest are summarised under a single roll-up so the queue
			// doesn't get drowned in low-tier work from a single audit.
			$findings = array_slice( (array) $res['candidates'], 0, 3 );
			$leftover = max( 0, count( (array) $res['candidates'] ) - count( $findings ) );

			foreach ( $findings as $idx => $f ) {
				$id = sprintf( 'theme:%s:%s', $tool_id, isset( $f['id'] ) ? sanitize_key( (string) $f['id'] ) : 'finding' . $idx );
				$out[] = array(
					'id'         => $id,
					'type'       => 'theme_health_finding',
					'title'      => $label,
					'body'       => isset( $f['title'] ) ? (string) $f['title'] : (string) $f['id'],
					'detail'     => $f['meta'] ?? '',
					'impact'     => $impact,
					'effort_min' => $effort,
					'roi'        => $impact / max( 1, $effort ),
					'tier'       => $tier,
					'workflow'   => 'theme-tools',
					'tool_id'    => $tool_id,
					'why'        => array(
						'primary_signal'      => sprintf( 'Tool %s flagged %s', $tool_id, $f['title'] ?? $f['id'] ?? 'item' ),
						'supporting_signals'  => array( $f['meta'] ?? '' ),
						'baseline_comparison' => null,
					),
					'cta_url'    => admin_url( 'admin.php?page=luwipress-theme&tab=tools' ),
				);
			}
			if ( $leftover > 0 ) {
				$out[] = array(
					'id'         => sprintf( 'theme:%s:more', $tool_id ),
					'type'       => 'theme_health_finding',
					'title'      => sprintf( '%s — %d more', $label, $leftover ),
					'body'       => sprintf( __( '%d additional finding(s) — review in LuwiPress → Theme', 'luwipress' ), $leftover ),
					'impact'     => max( 30, $impact - 20 ),
					'effort_min' => $effort * 2,
					'roi'        => max( 30, $impact - 20 ) / max( 1, $effort * 2 ),
					'tier'       => 'low',
					'workflow'   => 'theme-tools',
					'tool_id'    => $tool_id,
					'why'        => array(
						'primary_signal'      => sprintf( '%d more findings under %s', $leftover, $tool_id ),
						'supporting_signals'  => array(),
						'baseline_comparison' => null,
					),
					'cta_url'    => admin_url( 'admin.php?page=luwipress-theme&tab=tools' ),
				);
			}
		}

		set_transient( $cache_key, $out, HOUR_IN_SECONDS );
		return array_merge( $candidates, $out );
	}

	public function bust_kg_candidate_cache() {
		delete_transient( 'luwipress_theme_kg_candidates' );
	}

	// ─── Settings reset ──────────────────────────────────────────────────

	public function reset_setting( $setting_id ) {
		$setting_id = sanitize_key( $setting_id );
		foreach ( $this->get_settings() as $s ) {
			if ( $s['id'] === $setting_id ) {
				remove_theme_mod( $s['theme_mod'] );
				return array( 'reset' => true, 'id' => $setting_id, 'theme_mod' => $s['theme_mod'], 'default' => $s['default'] );
			}
		}
		return new WP_Error( 'setting_not_found', sprintf( 'Setting "%s" not registered.', $setting_id ), array( 'status' => 404 ) );
	}

	public function ajax_settings_reset() {
		check_ajax_referer( 'luwipress_theme_tools', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		$ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['ids'] ) ) : array();
		$group = isset( $_POST['group'] ) ? sanitize_key( $_POST['group'] ) : '';
		// Group reset: enumerate registered settings in the group, drop those mods.
		if ( $group ) {
			foreach ( $this->get_settings() as $s ) {
				if ( $s['group'] === $group ) {
					$ids[] = $s['id'];
				}
			}
			$ids = array_values( array_unique( $ids ) );
		}
		$reset = array();
		$errors = array();
		foreach ( $ids as $id ) {
			$res = $this->reset_setting( $id );
			if ( is_wp_error( $res ) ) {
				$errors[ $id ] = $res->get_error_message();
				continue;
			}
			$reset[] = $id;
		}
		wp_send_json_success( array( 'reset' => $reset, 'errors' => $errors ) );
	}

	// ─── Status snapshot for hero stat-bar ───────────────────────────────

	/**
	 * Compute the 6 hero metrics shown on the Status tab. Cheap audits only;
	 * cached 5 minutes so opening the page doesn't trigger a full sweep.
	 */
	public function status_snapshot() {
		$cache_key = 'luwipress_theme_status_snapshot';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$tools     = $this->get_tools();
		$settings  = $this->get_settings();

		// Findings — cheap subset only (audits without HTTP).
		$findings_total = 0;
		$cheap_audits = array( 'kit_css_health', 'orphan_media_scan', 'wpml_translation_drift', 'unwanted_landing_pages', 'empty_term_archives' );
		foreach ( $cheap_audits as $tool_id ) {
			if ( ! $this->get_tool( $tool_id ) ) {
				continue;
			}
			try {
				$res = $this->run_tool( $tool_id, 'scan', array() );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( ! is_wp_error( $res ) ) {
				$findings_total += isset( $res['count'] ) ? (int) $res['count'] : 0;
			}
		}

		// Untranslated products — quick estimate via product_translation_completeness.
		$untranslated = 0;
		if ( $this->get_tool( 'product_translation_completeness' ) ) {
			try {
				$res = $this->run_tool( 'product_translation_completeness', 'scan', array( 'limit' => 50 ) );
				if ( ! is_wp_error( $res ) ) {
					$untranslated = isset( $res['count'] ) ? (int) $res['count'] : 0;
				}
			} catch ( \Throwable $e ) {}
		}

		// Kit CSS headroom (bytes) — direct option read, no HTTP.
		$kit_css = (string) get_option( 'luwipress_kit_css', '' );
		$kit_css_size = strlen( $kit_css );
		$kit_css_pct  = $kit_css_size > 0 ? round( ( $kit_css_size / ( 412 * 1024 ) ) * 100, 1 ) : 0;

		// Backups across all tools.
		$backups_total = count( $this->get_backups() );

		$snapshot = array(
			'tools'         => count( $tools ),
			'settings'      => count( $settings ),
			'findings'      => $findings_total,
			'untranslated'  => $untranslated,
			'backups'       => $backups_total,
			'kit_css'       => array(
				'bytes'      => $kit_css_size,
				'pct_limit'  => $kit_css_pct,
				'soft_limit' => 412 * 1024,
			),
			'computed_at'   => time(),
		);

		set_transient( $cache_key, $snapshot, 5 * MINUTE_IN_SECONDS );
		return $snapshot;
	}
}
