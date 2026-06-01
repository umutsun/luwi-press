<?php
/**
 * LuwiPress CPT Engine — generic custom-post-type registry.
 *
 * The single source of truth for "what content types exist in this store":
 * Vendors (preset #1), and future presets (Events, Team, Venues, …). Each TYPE
 * DEFINITION is a normalized array describing the CPT's labels, permalink rules,
 * FIELD SCHEMA (attributes), taxonomies, schema.org mapping and WooCommerce
 * relationship. Downstream integrations (Translation Manager, Elementor,
 * WooCommerce, Schema Registry) read this registry to enumerate every type's
 * taxonomies + fields instead of hard-coding per-module knowledge.
 *
 * PHASE 0 (this file): the engine is a DIRECTORY. It COLLECTS type definitions
 * — from code presets via the `luwipress_cpt_engine_register` action, and from
 * operator-defined types stored in the `luwipress_cpt_engine_types` option — but
 * does NOT register the post types itself. Existing modules (LuwiPress_Vendors)
 * still self-register their CPT and merely DESCRIBE themselves to the engine, so
 * Phase 0 is zero-breakage.
 *
 * PHASE 1+ will let operator-defined (option-stored) types self-register their
 * post type, taxonomies and meta fields directly from the definition; Phase 2
 * wires the directory into the Translation Manager + Elementor; Phase 3 promotes
 * the WooCommerce relationship to a first-class taxonomy.
 *
 * @since 3.7.x
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_CPT_Engine {

	/** Option holding operator-defined type definitions (keyed by engine key). */
	const OPTION_TYPES = 'luwipress_cpt_engine_types';

	private static $instance = null;

	/** @var array<string,array> engine key => normalized definition */
	private $types = array();

	/** @var bool guard so collection runs exactly once */
	private $collected = false;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Collect definitions once, early on init (priority 1) — before modules
		// register their post types (priority 5) — so the directory is populated
		// for every consumer. Modules add their `luwipress_cpt_engine_register`
		// callbacks in their own constructors (plugins_loaded), i.e. before init.
		add_action( 'init', array( $this, 'collect_types' ), 1 );

		// Phase 1: register operator-defined CPTs (source=option) from their
		// definitions — after collect (p1), before module self-registration (p5).
		// Preset types that flag self_registers (e.g. Vendors) are skipped.
		add_action( 'init', array( $this, 'register_engine_cpts' ), 4 );

		// Type-definition CRUD surface.
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );

		// Phase 2: every enabled engine-managed post type is translatable. This is
		// the pipeline-side gate behind the Translation Manager content steps —
		// without it LuwiPress_Translation::request_translation() 404s engine CPTs.
		add_filter( 'luwipress_translatable_post_types', array( $this, 'add_translatable_post_types' ) );
	}

	/**
	 * Fire the registration action (code presets describe themselves) and load
	 * operator-defined types from the option store.
	 */
	public function collect_types() {
		if ( $this->collected ) {
			return;
		}
		$this->collected = true; // set first — guards against re-entrancy.

		/**
		 * Code-side presets describe themselves into the engine here.
		 *
		 * @param LuwiPress_CPT_Engine $engine
		 */
		do_action( 'luwipress_cpt_engine_register', $this );

		// Operator-defined types. Phase 1 will self-register these as real CPTs;
		// Phase 0 keeps them in the directory for inspection only.
		$stored = get_option( self::OPTION_TYPES, array() );
		if ( is_array( $stored ) ) {
			foreach ( $stored as $key => $def ) {
				if ( is_array( $def ) && ! isset( $this->types[ $key ] ) ) {
					$def['source'] = 'option';
					$this->register_type( (string) $key, $def );
				}
			}
		}
	}

	/**
	 * Register (describe) a CPT type definition.
	 *
	 * @param string $key Stable engine key (e.g. 'vendors', 'events').
	 * @param array  $def Definition — see normalize_definition() for the shape.
	 */
	public function register_type( $key, array $def ) {
		$key = sanitize_key( $key );
		if ( '' === $key ) {
			return;
		}
		$this->types[ $key ] = $this->normalize_definition( $key, $def );
	}

	/**
	 * Coerce an incoming definition to the canonical shape so every consumer can
	 * trust the keys exist.
	 *
	 * @param string $key
	 * @param array  $def
	 * @return array
	 */
	private function normalize_definition( $key, array $def ) {
		$post_type = isset( $def['post_type'] ) ? sanitize_key( (string) $def['post_type'] ) : $key;

		$url_field = function ( $f ) {
			return isset( $f['type'] ) ? (string) $f['type'] : 'text';
		};

		$fields = array();
		if ( ! empty( $def['field_schema'] ) && is_array( $def['field_schema'] ) ) {
			foreach ( $def['field_schema'] as $f ) {
				if ( ! is_array( $f ) || empty( $f['key'] ) ) {
					continue;
				}
				$fields[] = array(
					'key'          => (string) $f['key'],
					'type'         => $url_field( $f ),
					'label'        => isset( $f['label'] ) ? (string) $f['label'] : '',
					'translatable' => ! empty( $f['translatable'] ),
					'in_elementor' => ! isset( $f['in_elementor'] ) ? true : (bool) $f['in_elementor'],
					'schema_prop'  => isset( $f['schema_prop'] ) ? (string) $f['schema_prop'] : '',
				);
			}
		}

		$taxes = array();
		if ( ! empty( $def['taxonomies'] ) && is_array( $def['taxonomies'] ) ) {
			foreach ( $def['taxonomies'] as $t ) {
				$slug = is_array( $t ) ? sanitize_key( (string) ( $t['slug'] ?? '' ) ) : sanitize_key( (string) $t );
				if ( '' === $slug ) {
					continue;
				}
				$taxes[] = array(
					'slug'         => $slug,
					'label'        => ( is_array( $t ) && isset( $t['label'] ) ) ? (string) $t['label'] : $slug,
					'hierarchical' => is_array( $t ) && ! empty( $t['hierarchical'] ),
					'translatable' => is_array( $t ) ? ( ! isset( $t['translatable'] ) ? true : (bool) $t['translatable'] ) : true,
					'is_attribute' => is_array( $t ) && ! empty( $t['is_attribute'] ),
				);
			}
		}

		return array(
			'key'            => $key,
			'post_type'      => $post_type,
			'source'         => isset( $def['source'] ) ? (string) $def['source'] : 'preset',
			// Types that register their own CPT in a dedicated module (e.g.
			// Vendors) flag this so the engine never double-registers them.
			'self_registers' => ! empty( $def['self_registers'] ),
			'enabled'        => ! isset( $def['enabled'] ) ? true : (bool) $def['enabled'],
			'labels'         => ( isset( $def['labels'] ) && is_array( $def['labels'] ) ) ? $def['labels'] : array(),
			'permalink'      => ( isset( $def['permalink'] ) && is_array( $def['permalink'] ) ) ? $def['permalink'] : array(),
			'field_schema'   => $fields,
			'taxonomies'     => $taxes,
			'schema_mapping' => ( isset( $def['schema_mapping'] ) && is_array( $def['schema_mapping'] ) ) ? $def['schema_mapping'] : array(),
			'woocommerce'    => ( isset( $def['woocommerce'] ) && is_array( $def['woocommerce'] ) ) ? $def['woocommerce'] : null,
		);
	}

	/* ── Read API — consumed by Translation Manager / Elementor / WC / Schema ── */

	/** @return array<string,array> all registered type definitions */
	public function get_types() {
		$this->maybe_collect();
		return $this->types;
	}

	/**
	 * @param string $key
	 * @return array|null
	 */
	public function get_type( $key ) {
		$this->maybe_collect();
		$key = sanitize_key( $key );
		return isset( $this->types[ $key ] ) ? $this->types[ $key ] : null;
	}

	/**
	 * @param string $post_type
	 * @return array|null
	 */
	public function get_type_by_post_type( $post_type ) {
		$this->maybe_collect();
		$post_type = sanitize_key( $post_type );
		foreach ( $this->types as $def ) {
			if ( $def['post_type'] === $post_type ) {
				return $def;
			}
		}
		return null;
	}

	/**
	 * Every enabled engine-managed post type. Feeds luwipress_translatable_post_types,
	 * Elementor location registration, etc.
	 *
	 * @return string[]
	 */
	public function get_post_types() {
		$this->maybe_collect();
		$out = array();
		foreach ( $this->types as $def ) {
			if ( ! empty( $def['enabled'] ) ) {
				$out[] = $def['post_type'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Filter callback for `luwipress_translatable_post_types` — appends every
	 * enabled engine-managed post type to the translatable whitelist so the
	 * Translation Manager pipeline accepts them. Idempotent (de-dupes against
	 * post types another caller already listed, e.g. the hardcoded lwp_vendor).
	 *
	 * @param array $types
	 * @return array
	 */
	public function add_translatable_post_types( $types ) {
		if ( ! is_array( $types ) ) {
			$types = array();
		}
		foreach ( $this->get_post_types() as $pt ) {
			if ( '' !== $pt && ! in_array( $pt, $types, true ) ) {
				$types[] = $pt;
			}
		}
		return $types;
	}

	/**
	 * All taxonomies declared by enabled engine types, each tagged with its
	 * owning post type + translatable flag. Feeds the Translation Manager
	 * "Translate Taxonomies" step (Phase 2).
	 *
	 * @return array<int,array>
	 */
	public function get_taxonomies() {
		$this->maybe_collect();
		$out = array();
		foreach ( $this->types as $def ) {
			if ( empty( $def['enabled'] ) ) {
				continue;
			}
			foreach ( $def['taxonomies'] as $t ) {
				$t['post_type'] = $def['post_type'];
				$out[]          = $t;
			}
		}
		return $out;
	}

	/**
	 * Field schema for a post type — feeds Elementor dynamic tags + the
	 * translatable-field map for WPML config (Phase 2+).
	 *
	 * @param string $post_type
	 * @return array<int,array>
	 */
	public function get_field_schema( $post_type ) {
		$def = $this->get_type_by_post_type( $post_type );
		return $def ? $def['field_schema'] : array();
	}

	/** Late-caller safety net: collect on demand once init has fired. */
	private function maybe_collect() {
		if ( ! $this->collected && did_action( 'init' ) ) {
			$this->collect_types();
		}
	}

	/* ── Phase 1: register operator-defined CPTs from their definitions ── */

	/**
	 * Register every enabled, non-self-registering type's CPT + taxonomies +
	 * meta fields. Runs on init p4. Skips types whose post type already exists
	 * (collision guard — never clobber another plugin's post type).
	 */
	public function register_engine_cpts() {
		$this->maybe_collect();
		foreach ( $this->types as $def ) {
			if ( ! empty( $def['self_registers'] ) || empty( $def['enabled'] ) ) {
				continue;
			}
			$pt = $def['post_type'];
			if ( '' === $pt || post_type_exists( $pt ) ) {
				continue;
			}
			$this->register_one_cpt( $def );
		}
	}

	private function register_one_cpt( array $def ) {
		$pt   = $def['post_type'];
		$perm = $def['permalink'];
		$slug = ( isset( $perm['archive_slug'] ) && '' !== $perm['archive_slug'] )
			? sanitize_title( (string) $perm['archive_slug'] )
			: $pt;

		register_post_type( $pt, array(
			'labels'             => $this->build_labels( $def ),
			'public'             => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'has_archive'        => $slug,
			'hierarchical'       => false,
			'menu_icon'          => isset( $def['labels']['menu_icon'] ) ? (string) $def['labels']['menu_icon'] : 'dashicons-admin-post',
			'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ),
			'rewrite'            => array( 'slug' => $slug, 'with_front' => ! empty( $perm['with_front'] ) ),
		) );

		foreach ( $def['taxonomies'] as $t ) {
			if ( taxonomy_exists( $t['slug'] ) ) {
				register_taxonomy_for_object_type( $t['slug'], $pt );
				continue;
			}
			register_taxonomy( $t['slug'], $pt, array(
				'labels'            => array( 'name' => $t['label'], 'singular_name' => $t['label'] ),
				'public'            => true,
				'hierarchical'      => ! empty( $t['hierarchical'] ),
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
			) );
		}

		foreach ( $def['field_schema'] as $f ) {
			register_post_meta( $pt, $f['key'], array(
				'type'              => $this->meta_type_for( $f['type'] ),
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => $this->sanitize_cb_for( $f['type'] ),
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			) );
		}
	}

	private function build_labels( array $def ) {
		$l        = $def['labels'];
		$singular = ( isset( $l['singular'] ) && '' !== $l['singular'] ) ? (string) $l['singular'] : ucfirst( $def['key'] );
		$plural   = ( isset( $l['plural'] ) && '' !== $l['plural'] ) ? (string) $l['plural'] : $singular . 's';
		return array(
			'name'          => $plural,
			'singular_name' => $singular,
			'menu_name'     => $plural,
			/* translators: %s: CPT singular label */
			'add_new_item'  => sprintf( __( 'Add new %s', 'luwipress' ), $singular ),
			/* translators: %s: CPT singular label */
			'edit_item'     => sprintf( __( 'Edit %s', 'luwipress' ), $singular ),
			/* translators: %s: CPT plural label */
			'all_items'     => sprintf( __( 'All %s', 'luwipress' ), $plural ),
			/* translators: %s: CPT plural label */
			'search_items'  => sprintf( __( 'Search %s', 'luwipress' ), $plural ),
		);
	}

	private function meta_type_for( $type ) {
		return 'number' === $type ? 'integer' : 'string';
	}

	private function sanitize_cb_for( $type ) {
		switch ( $type ) {
			case 'url':
				return 'esc_url_raw';
			case 'number':
				return 'absint';
			case 'textarea':
				return 'wp_kses_post';
			case 'email':
				return 'sanitize_email';
			default:
				return 'sanitize_text_field';
		}
	}

	/* ── Type-definition CRUD (REST) ── */

	public function register_rest() {
		$ns = 'luwipress/v1';
		register_rest_route( $ns, '/cpt-engine/types', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_list_types' ),
				'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_set_type' ),
				'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			),
		) );
		register_rest_route( $ns, '/cpt-engine/types/(?P<key>[a-z0-9_\-]+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'rest_delete_type' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );
	}

	public function rest_list_types( $request ) {
		return rest_ensure_response( array(
			'types' => array_values( $this->get_types() ),
		) );
	}

	/** Reserved / built-in post types an operator type may never override. */
	private function reserved_post_types() {
		return array(
			'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css',
			'customize_changeset', 'oembed_cache', 'user_request', 'wp_block',
			'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
			'product', 'product_variation', 'shop_order', 'shop_coupon', 'lwp_vendor',
		);
	}

	public function rest_set_type( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}
		$key = sanitize_key( (string) ( $body['key'] ?? '' ) );
		$pt  = sanitize_key( (string) ( $body['post_type'] ?? $key ) );

		if ( '' === $key || '' === $pt ) {
			return new WP_Error( 'invalid', 'key and post_type are required.', array( 'status' => 400 ) );
		}
		if ( strlen( $pt ) > 20 ) {
			return new WP_Error( 'invalid_post_type', 'post_type must be 20 characters or fewer.', array( 'status' => 400 ) );
		}
		if ( in_array( $pt, $this->reserved_post_types(), true ) ) {
			return new WP_Error( 'reserved_post_type', sprintf( 'post_type "%s" is reserved.', $pt ), array( 'status' => 409 ) );
		}

		$stored = get_option( self::OPTION_TYPES, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		// Collision with a post type that exists and isn't this same engine key.
		if ( post_type_exists( $pt ) && ! isset( $stored[ $key ] ) ) {
			return new WP_Error( 'post_type_exists', sprintf( 'post_type "%s" already exists.', $pt ), array( 'status' => 409 ) );
		}

		$stored[ $key ] = $this->clean_def_for_storage( $pt, $body );
		update_option( self::OPTION_TYPES, $stored, false );
		flush_rewrite_rules( false );

		return rest_ensure_response( array(
			'ok'        => true,
			'key'       => $key,
			'post_type' => $pt,
			'note'      => 'CPT registers on the next request; rewrite rules flushed.',
		) );
	}

	public function rest_delete_type( $request ) {
		$key    = sanitize_key( (string) $request->get_param( 'key' ) );
		$stored = get_option( self::OPTION_TYPES, array() );
		if ( ! is_array( $stored ) || ! isset( $stored[ $key ] ) ) {
			return new WP_Error( 'not_found', 'No operator-defined type with that key.', array( 'status' => 404 ) );
		}
		unset( $stored[ $key ] );
		update_option( self::OPTION_TYPES, $stored, false );
		flush_rewrite_rules( false );
		return rest_ensure_response( array( 'ok' => true, 'deleted' => $key ) );
	}

	/**
	 * Sanitize an incoming definition into the safe shape persisted to the
	 * option store. (normalize_definition() re-shapes it for runtime use.)
	 *
	 * @param string $pt
	 * @param array  $body
	 * @return array
	 */
	private function clean_def_for_storage( $pt, array $body ) {
		$allowed_types = array( 'text', 'textarea', 'number', 'url', 'email', 'image', 'date', 'datetime', 'select', 'relationship' );

		$labels = array();
		if ( isset( $body['labels'] ) && is_array( $body['labels'] ) ) {
			foreach ( array( 'singular', 'plural', 'menu_icon' ) as $lk ) {
				if ( isset( $body['labels'][ $lk ] ) ) {
					$labels[ $lk ] = sanitize_text_field( (string) $body['labels'][ $lk ] );
				}
			}
		}

		$permalink = array();
		if ( isset( $body['permalink'] ) && is_array( $body['permalink'] ) ) {
			if ( isset( $body['permalink']['archive_slug'] ) ) {
				$permalink['archive_slug'] = sanitize_title( (string) $body['permalink']['archive_slug'] );
			}
			$permalink['with_front'] = ! empty( $body['permalink']['with_front'] );
		}

		$fields = array();
		if ( isset( $body['field_schema'] ) && is_array( $body['field_schema'] ) ) {
			foreach ( $body['field_schema'] as $f ) {
				if ( ! is_array( $f ) || empty( $f['key'] ) ) {
					continue;
				}
				$type = isset( $f['type'] ) && in_array( $f['type'], $allowed_types, true ) ? $f['type'] : 'text';
				$fields[] = array(
					'key'          => sanitize_key( (string) $f['key'] ),
					'type'         => $type,
					'label'        => isset( $f['label'] ) ? sanitize_text_field( (string) $f['label'] ) : '',
					'translatable' => ! empty( $f['translatable'] ),
					'in_elementor' => ! isset( $f['in_elementor'] ) ? true : (bool) $f['in_elementor'],
					'schema_prop'  => isset( $f['schema_prop'] ) ? sanitize_text_field( (string) $f['schema_prop'] ) : '',
				);
			}
		}

		$taxes = array();
		if ( isset( $body['taxonomies'] ) && is_array( $body['taxonomies'] ) ) {
			foreach ( $body['taxonomies'] as $t ) {
				$slug = is_array( $t ) ? sanitize_key( (string) ( $t['slug'] ?? '' ) ) : sanitize_key( (string) $t );
				if ( '' === $slug ) {
					continue;
				}
				$taxes[] = array(
					'slug'         => $slug,
					'label'        => ( is_array( $t ) && isset( $t['label'] ) ) ? sanitize_text_field( (string) $t['label'] ) : $slug,
					'hierarchical' => is_array( $t ) && ! empty( $t['hierarchical'] ),
					'translatable' => is_array( $t ) ? ( ! isset( $t['translatable'] ) ? true : (bool) $t['translatable'] ) : true,
					'is_attribute' => is_array( $t ) && ! empty( $t['is_attribute'] ),
				);
			}
		}

		return array(
			'post_type'      => $pt,
			'source'         => 'option',
			'enabled'        => ! isset( $body['enabled'] ) ? true : (bool) $body['enabled'],
			'labels'         => $labels,
			'permalink'      => $permalink,
			'field_schema'   => $fields,
			'taxonomies'     => $taxes,
			'schema_mapping' => ( isset( $body['schema_mapping'] ) && is_array( $body['schema_mapping'] ) ) ? $body['schema_mapping'] : array(),
			'woocommerce'    => ( isset( $body['woocommerce'] ) && is_array( $body['woocommerce'] ) ) ? $body['woocommerce'] : null,
		);
	}
}
