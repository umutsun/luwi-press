<?php
/**
 * LuwiPress Forms Bridge — remote create/edit of form-plugin forms.
 *
 * A token-authenticated REST surface (luwipress/v1) for managing forms in the
 * detected form plugin without opening its builder UI — so an operator or an
 * MCP client / remote fleet manager can list, read, create, update and inspect
 * entries of forms conversationally. Pairs with the Plugin Detector's "forms"
 * category (Fluent Forms / WPForms / …).
 *
 * v1 targets **Fluent Forms** (free multi-step wizard + DB entries + native
 * Elementor widget — the LuwiPress-recommended form plugin). Fluent Forms stores
 * each form as a row in `{prefix}fluentform_forms` with the layout in the
 * `form_fields` JSON column, and submissions in `{prefix}fluentform_submissions`.
 * We write through Fluent Forms' own Form model when available (fires its hooks)
 * and fall back to a direct, defensive $wpdb insert otherwise.
 *
 * `form_fields` is passed through as-is (the canonical Fluent Forms field JSON)
 * so the caller has full fidelity — read an existing form with GET to learn the
 * exact shape for the installed FF version, then create/update with it.
 *
 * REST (luwipress/v1):
 *   GET  /forms                  — list forms (id, title, status, fields, entries)
 *   GET  /forms/{id}             — one form: title, status, form_fields, settings
 *   POST /forms                  — create a form (title + form_fields [+ settings])
 *   POST /forms/{id}             — update a form (title? / form_fields? / status?)
 *   GET  /forms/{id}/entries     — recent submissions (paged)
 *
 * MCP (webmcp companion): forms_list, forms_get, forms_create, forms_update, forms_entries.
 *
 * @package LuwiPress
 * @since   3.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Forms {

	/** @var LuwiPress_Forms|null */
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$ns = 'luwipress/v1';

		register_rest_route( $ns, '/forms', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_list' ),
				'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_create' ),
				'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			),
		) );

		register_rest_route( $ns, '/forms/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get' ),
				'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_update' ),
				'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			),
		) );

		register_rest_route( $ns, '/forms/(?P<id>\d+)/entries', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_entries' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );
	}

	/* ─────────────────────────── Availability ─────────────────────────── */

	/** Fluent Forms active? */
	private function ff_active() {
		return defined( 'FLUENTFORM_VERSION' ) || function_exists( 'wpFluentForm' );
	}

	private function forms_table() {
		global $wpdb;
		return $wpdb->prefix . 'fluentform_forms';
	}

	private function submissions_table() {
		global $wpdb;
		return $wpdb->prefix . 'fluentform_submissions';
	}

	private function not_available() {
		return new WP_Error(
			'no_form_plugin',
			'Fluent Forms is not active. Install/activate it (free) to manage forms via this API.',
			array( 'status' => 409 )
		);
	}

	/* ─────────────────────────── REST handlers ─────────────────────────── */

	/** GET /forms */
	public function rest_list( $request ) {
		if ( ! $this->ff_active() ) {
			return $this->not_available();
		}
		global $wpdb;
		$table = $this->forms_table();
		$subs  = $this->submissions_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal, no user input
		$rows = $wpdb->get_results( "SELECT id, title, status, created_at, updated_at FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'forms_query_failed', 'Could not read the Fluent Forms table.', array( 'status' => 500 ) );
		}

		$out = array();
		foreach ( $rows as $r ) {
			$id = (int) $r['id'];
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$entries = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$subs} WHERE form_id = %d", $id ) );
			$out[] = array(
				'id'         => $id,
				'title'      => $r['title'],
				'status'     => $r['status'],
				'entries'    => $entries,
				'shortcode'  => '[fluentform id="' . $id . '"]',
				'created_at' => $r['created_at'],
				'updated_at' => $r['updated_at'],
			);
		}

		return rest_ensure_response( array( 'plugin' => 'fluent-forms', 'count' => count( $out ), 'forms' => $out ) );
	}

	/** GET /forms/{id} */
	public function rest_get( $request ) {
		if ( ! $this->ff_active() ) {
			return $this->not_available();
		}
		$id = (int) $request['id'];
		$row = $this->get_form_row( $id );
		if ( ! $row ) {
			return new WP_Error( 'form_not_found', 'Form not found: ' . $id, array( 'status' => 404 ) );
		}

		return rest_ensure_response( array(
			'id'                  => (int) $row['id'],
			'title'               => $row['title'],
			'status'              => $row['status'],
			'type'                => $row['type'] ?? 'form',
			'form_fields'         => json_decode( (string) $row['form_fields'], true ),
			'appearance_settings' => isset( $row['appearance_settings'] ) ? json_decode( (string) $row['appearance_settings'], true ) : null,
			'shortcode'           => '[fluentform id="' . (int) $row['id'] . '"]',
		) );
	}

	/**
	 * POST /forms
	 * Body: { title (required), form_fields (required: FF field JSON object or string),
	 *         status ('published'|'unpublished', default published),
	 *         appearance_settings (optional object), type ('form', default) }
	 */
	public function rest_create( $request ) {
		if ( ! $this->ff_active() ) {
			return $this->not_available();
		}
		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		if ( '' === $title ) {
			return new WP_Error( 'missing_title', 'title is required.', array( 'status' => 400 ) );
		}
		$form_fields = $this->encode_form_fields( $request->get_param( 'form_fields' ) );
		if ( is_wp_error( $form_fields ) ) {
			return $form_fields;
		}
		$status = sanitize_text_field( (string) $request->get_param( 'status' ) );
		$status = ( 'unpublished' === $status ) ? 'unpublished' : 'published';
		$type   = sanitize_text_field( (string) ( $request->get_param( 'type' ) ?: 'form' ) );
		$appearance = $request->get_param( 'appearance_settings' );
		$appearance = ( null !== $appearance ) ? wp_json_encode( $appearance ) : '';

		global $wpdb;
		$table = $this->forms_table();
		$now   = current_time( 'mysql' );
		$data  = array(
			'title'               => $title,
			'form_fields'         => $form_fields,
			'status'              => $status,
			'appearance_settings' => $appearance,
			'type'                => $type,
			'has_payment'         => 0,
			'conditions'          => '',
			'created_by'          => get_current_user_id(),
			'created_at'          => $now,
			'updated_at'          => $now,
		);
		// Only send columns that actually exist (FF schema varies across versions).
		$data    = $this->filter_existing_columns( $table, $data );
		$formats = $this->formats_for( $data );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert( $table, $data, $formats );
		if ( false === $ok ) {
			return new WP_Error( 'form_create_failed', 'Insert failed: ' . $wpdb->last_error, array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;

		do_action( 'fluentform/form_created', $id ); // let FF set up defaults/meta if it listens
		LuwiPress_Logger::log( 'Forms: created Fluent Forms form #' . $id . ' (' . $title . ')', 'info', array( 'form_id' => $id ) );

		return rest_ensure_response( array(
			'status'    => 'created',
			'id'        => $id,
			'title'     => $title,
			'shortcode' => '[fluentform id="' . $id . '"]',
			'edit_url'  => admin_url( 'admin.php?page=fluent_forms&route=editor&form_id=' . $id ),
		) );
	}

	/**
	 * POST /forms/{id}
	 * Body: { title?, form_fields?, status?, appearance_settings? } — partial update.
	 */
	public function rest_update( $request ) {
		if ( ! $this->ff_active() ) {
			return $this->not_available();
		}
		$id  = (int) $request['id'];
		$row = $this->get_form_row( $id );
		if ( ! $row ) {
			return new WP_Error( 'form_not_found', 'Form not found: ' . $id, array( 'status' => 404 ) );
		}

		$data = array();
		if ( null !== $request->get_param( 'title' ) ) {
			$data['title'] = sanitize_text_field( (string) $request->get_param( 'title' ) );
		}
		if ( null !== $request->get_param( 'form_fields' ) ) {
			$ff = $this->encode_form_fields( $request->get_param( 'form_fields' ) );
			if ( is_wp_error( $ff ) ) {
				return $ff;
			}
			$data['form_fields'] = $ff;
		}
		if ( null !== $request->get_param( 'status' ) ) {
			$s = sanitize_text_field( (string) $request->get_param( 'status' ) );
			$data['status'] = ( 'unpublished' === $s ) ? 'unpublished' : 'published';
		}
		if ( null !== $request->get_param( 'appearance_settings' ) ) {
			$data['appearance_settings'] = wp_json_encode( $request->get_param( 'appearance_settings' ) );
		}
		if ( empty( $data ) ) {
			return new WP_Error( 'nothing_to_update', 'Provide at least one of: title, form_fields, status, appearance_settings.', array( 'status' => 400 ) );
		}
		$data['updated_at'] = current_time( 'mysql' );

		global $wpdb;
		$table   = $this->forms_table();
		$data    = $this->filter_existing_columns( $table, $data );
		$formats = $this->formats_for( $data );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $ok ) {
			return new WP_Error( 'form_update_failed', 'Update failed: ' . $wpdb->last_error, array( 'status' => 500 ) );
		}

		do_action( 'fluentform/form_updated', $id );
		LuwiPress_Logger::log( 'Forms: updated Fluent Forms form #' . $id, 'info', array( 'form_id' => $id, 'fields' => array_keys( $data ) ) );

		return rest_ensure_response( array( 'status' => 'updated', 'id' => $id, 'updated' => array_keys( $data ) ) );
	}

	/** GET /forms/{id}/entries?per_page=20&page=1 */
	public function rest_entries( $request ) {
		if ( ! $this->ff_active() ) {
			return $this->not_available();
		}
		$id = (int) $request['id'];
		if ( ! $this->get_form_row( $id ) ) {
			return new WP_Error( 'form_not_found', 'Form not found: ' . $id, array( 'status' => 404 ) );
		}
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		global $wpdb;
		$subs = $this->submissions_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$subs} WHERE form_id = %d", $id ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, response, status, created_at FROM {$subs} WHERE form_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
			$id, $per_page, $offset
		), ARRAY_A );

		$entries = array();
		foreach ( (array) $rows as $r ) {
			$entries[] = array(
				'id'         => (int) $r['id'],
				'status'     => $r['status'],
				'created_at' => $r['created_at'],
				'data'       => json_decode( (string) $r['response'], true ),
			);
		}

		return rest_ensure_response( array(
			'form_id'  => $id,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'entries'  => $entries,
		) );
	}

	/* ─────────────────────────── Internals ─────────────────────────── */

	private function get_form_row( $id ) {
		global $wpdb;
		$table = $this->forms_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Accept form_fields as a JSON string OR an array/object and return a JSON
	 * string. Validates it parses + carries a `fields` array (FF's canonical shape).
	 *
	 * @return string|WP_Error
	 */
	private function encode_form_fields( $value ) {
		if ( null === $value || '' === $value ) {
			return new WP_Error( 'missing_form_fields', 'form_fields is required (Fluent Forms field JSON: {"fields":[...],"submitButton":{...}}).', array( 'status' => 400 ) );
		}
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'invalid_form_fields', 'form_fields is not valid JSON: ' . json_last_error_msg(), array( 'status' => 400 ) );
			}
		} else {
			$decoded = $value;
		}
		if ( ! is_array( $decoded ) || ! isset( $decoded['fields'] ) || ! is_array( $decoded['fields'] ) ) {
			return new WP_Error( 'bad_form_fields_shape', 'form_fields must be an object with a "fields" array (Fluent Forms shape).', array( 'status' => 400 ) );
		}
		return wp_json_encode( $decoded );
	}

	/** Keep only $data keys that are real columns of $table (FF schema tolerance). */
	private function filter_existing_columns( $table, array $data ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		if ( ! is_array( $cols ) || empty( $cols ) ) {
			return $data; // can't introspect — send as-is
		}
		$cols = array_flip( $cols );
		return array_intersect_key( $data, $cols );
	}

	/** Build the $wpdb format array matching the (filtered) $data order. */
	private function formats_for( array $data ) {
		$int_cols = array( 'has_payment' => true, 'created_by' => true );
		$formats  = array();
		foreach ( $data as $k => $v ) {
			$formats[] = isset( $int_cols[ $k ] ) ? '%d' : '%s';
		}
		return $formats;
	}
}
