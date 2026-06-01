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

		// Phase 3: promote each type's WooCommerce attribution (e.g. the Vendors
		// module's `_lwp_vendor_ids` product meta) to a first-class hidden
		// taxonomy on `product`, so WC-native term queries / Store API / feeds /
		// the admin products filter work without the O(n) REGEXP meta scan. The
		// meta stays canonical (theme REGEXP, KG, FR-016 normalization untouched);
		// the taxonomy is an additive, dual-written index. No-op without WC.
		add_action( 'init', array( $this, 'register_wc_attribution_taxonomies' ), 9 );
		// Backfill runs OUT-OF-BAND on cron (never inline on a visitor request) —
		// an atomic option-lock claims it once, schedules a single event, and the
		// cron handler does the (potentially large) sweep. New writes sync live
		// regardless, so a cron-deferred backfill only delays indexing pre-existing
		// attribution — never blocks a page load or retry-storms on timeout.
		add_action( 'init', array( $this, 'maybe_schedule_wc_attribution_backfill' ), 20 );
		add_action( 'luwipress_cpt_attr_backfill', array( $this, 'run_wc_attribution_backfill' ), 10, 1 );
		// Mirror one hidden term per CPT post (slug = post id, name = title).
		add_action( 'save_post', array( $this, 'mirror_cpt_post_to_term' ), 20, 2 );
		add_action( 'untrashed_post', array( $this, 'mirror_cpt_post_on_untrash' ) );
		add_action( 'before_delete_post', array( $this, 'remove_cpt_mirror_term' ) );
		add_action( 'wp_trash_post', array( $this, 'remove_cpt_mirror_term' ) );
		// Dual-write meta <-> terms (re-entrancy guarded via $this->syncing).
		add_action( 'added_post_meta', array( $this, 'sync_attribution_meta_to_terms' ), 20, 4 );
		add_action( 'updated_post_meta', array( $this, 'sync_attribution_meta_to_terms' ), 20, 4 );
		add_action( 'deleted_post_meta', array( $this, 'sync_attribution_meta_deleted' ), 20, 4 );
		add_action( 'set_object_terms', array( $this, 'sync_attribution_terms_to_meta' ), 20, 6 );
		// Admin: products-list filter dropdown per attribution taxonomy.
		add_action( 'restrict_manage_posts', array( $this, 'render_attribution_filter' ) );
	}

	/**
	 * @var bool Re-entrancy guard so meta<->term dual-write never loops. A single
	 * process-wide bool: it intentionally drops the echo of an in-flight write.
	 * Nested writes to a DIFFERENT object during a sync are not anticipated by any
	 * shipped path; the guard is always restored to its prior value (try/finally).
	 */
	private $syncing = false;

	/** @var array<string,array{post_type:string,meta:string,taxonomy:string,label:string}>|null memoized attribution map (request-immutable). */
	private $wc_map_cache = null;

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

	/* ── Phase 3: WooCommerce attribution taxonomy ──────────────────────── */

	/**
	 * Build the attribution map: every enabled type that declares a
	 * `woocommerce.attribution_meta` gets a hidden taxonomy on `product`.
	 *
	 * @return array<string,array{post_type:string,meta:string,taxonomy:string,label:string}>
	 *               keyed by taxonomy slug.
	 */
	public function wc_attribution_map() {
		// Memoized: this is consulted on every site-wide save_post / *_post_meta /
		// set_object_terms fire, and the result is request-immutable.
		if ( null !== $this->wc_map_cache ) {
			return $this->wc_map_cache;
		}
		$this->maybe_collect();
		$map = array();
		if ( ! post_type_exists( 'product' ) ) {
			// Don't memoize the empty map — WC may register `product` after this
			// first call (e.g. a very early hook) and we want the real map later.
			return $map;
		}
		foreach ( $this->types as $def ) {
			$wc = ( isset( $def['woocommerce'] ) && is_array( $def['woocommerce'] ) ) ? $def['woocommerce'] : array();
			if ( empty( $def['enabled'] ) || empty( $wc['attribution_meta'] ) ) {
				continue;
			}
			$pt  = (string) $def['post_type'];
			$tax = ! empty( $wc['taxonomy'] )
				? sanitize_key( (string) $wc['taxonomy'] )
				: 'product_' . $pt;
			// WordPress taxonomy names are capped at 32 chars. Hash-suffix an
			// over-long custom slug so two distinct long slugs can't collide.
			if ( strlen( $tax ) > 32 ) {
				$tax = rtrim( substr( $tax, 0, 27 ), '-_' ) . '_' . substr( md5( $tax ), 0, 4 );
			}
			if ( ! empty( $def['labels']['plural'] ) ) {
				$label = (string) $def['labels']['plural'];
			} elseif ( ! empty( $wc['attribution_role'] ) ) {
				$label = (string) $wc['attribution_role'];
			} else {
				$label = $pt;
			}
			$map[ $tax ] = array(
				'post_type' => $pt,
				'meta'      => (string) $wc['attribution_meta'],
				'taxonomy'  => $tax,
				'label'     => $label,
			);
		}
		$this->wc_map_cache = $map;
		return $map;
	}

	/**
	 * Register a hidden-but-REST-visible taxonomy on `product` for each
	 * attribution type. `meta_box_cb` is false: attribution is edited through
	 * the owning module's own metabox (e.g. the Vendors checkbox box) which
	 * dual-writes to this taxonomy — a second editable term box on the same
	 * screen would fight that one. Term management, admin column, products
	 * filter and REST/Store API exposure are all on. Runs init p9 (after WC
	 * registers `product` at p5).
	 */
	public function register_wc_attribution_taxonomies() {
		foreach ( $this->wc_attribution_map() as $info ) {
			if ( taxonomy_exists( $info['taxonomy'] ) ) {
				register_taxonomy_for_object_type( $info['taxonomy'], 'product' );
				continue;
			}
			register_taxonomy( $info['taxonomy'], 'product', array(
				'labels'             => array(
					'name'          => $info['label'],
					'singular_name' => $info['label'],
				),
				'public'             => false,
				'publicly_queryable' => false,
				'hierarchical'       => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_admin_column'  => true,
				'show_in_rest'       => true,
				'show_in_quick_edit' => false,
				// Edited via the owning module's metabox (avoids a conflicting
				// second box that would clobber it on save).
				'meta_box_cb'        => false,
				'query_var'          => true,
				'rewrite'            => false,
			) );
		}
	}

	/**
	 * Normalize an attribution meta value (canonical JSON of quoted IDs, a
	 * comma list, or an array) into a deduped list of positive int IDs.
	 *
	 * @param mixed $value
	 * @return int[]
	 */
	private function parse_attribution_meta_value( $value ) {
		if ( is_array( $value ) ) {
			$arr = $value;
		} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
			$decoded = json_decode( trim( $value ), true );
			$arr     = is_array( $decoded ) ? $decoded : preg_split( '/[\s,]+/', trim( $value ) );
		} else {
			return array();
		}
		$out = array();
		foreach ( (array) $arr as $v ) {
			$v = absint( $v );
			if ( $v > 0 ) {
				$out[] = $v;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Get (creating if needed) the mirror term_id for a CPT post in $taxonomy.
	 * Term slug == CPT post id (stable across renames); term name == post title.
	 *
	 * @param int    $cpt_post_id
	 * @param string $taxonomy
	 * @return int term_id, or 0 on failure.
	 */
	private function ensure_term_id_for_cpt_post( $cpt_post_id, $taxonomy ) {
		$slug = (string) (int) $cpt_post_id;
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( $term instanceof WP_Term ) {
			return (int) $term->term_id;
		}
		$post = get_post( (int) $cpt_post_id );
		$name = ( $post && '' !== $post->post_title ) ? $post->post_title : $slug;
		$res  = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
		if ( is_wp_error( $res ) ) {
			// Our (post-id) slug may already exist (race / re-entry) — reuse it.
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( $term instanceof WP_Term ) {
				return (int) $term->term_id;
			}
			// Otherwise it's a NAME collision (two CPT posts share a title, and
			// wp_insert_term enforces unique names for flat taxonomies). Retry
			// with a slug-disambiguated name; the slug stays the stable sync key.
			$res = wp_insert_term( $name . ' (#' . $slug . ')', $taxonomy, array( 'slug' => $slug ) );
			if ( is_wp_error( $res ) ) {
				return 0;
			}
		}
		return (int) $res['term_id'];
	}

	/**
	 * Upsert the mirror term when a CPT post is saved.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public function mirror_cpt_post_to_term( $post_id, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		// Only PUBLISHED posts get a mirror term — matches the publish-only
		// invariant used by the backfill and the Vendors attribution validators,
		// and stops an abandoned "Add New" auto-draft leaking a junk term into
		// the products filter. On unpublish, drop the existing mirror term too.
		foreach ( $this->wc_attribution_map() as $info ) {
			if ( $info['post_type'] !== $post->post_type ) {
				continue;
			}
			if ( ! taxonomy_exists( $info['taxonomy'] ) ) {
				return;
			}
			$slug = (string) (int) $post_id;
			$term = get_term_by( 'slug', $slug, $info['taxonomy'] );
			if ( 'publish' !== $post->post_status ) {
				if ( $term instanceof WP_Term ) {
					wp_delete_term( (int) $term->term_id, $info['taxonomy'] );
				}
				return;
			}
			if ( $term instanceof WP_Term ) {
				if ( $term->name !== $post->post_title && '' !== $post->post_title ) {
					$res = wp_update_term( $term->term_id, $info['taxonomy'], array( 'name' => $post->post_title ) );
					if ( is_wp_error( $res ) ) {
						// Name collision with another vendor's term — keep the slug
						// (the sync key) and disambiguate the display name.
						wp_update_term( $term->term_id, $info['taxonomy'], array( 'name' => $post->post_title . ' (#' . $slug . ')' ) );
					}
				}
			} else {
				$this->ensure_term_id_for_cpt_post( $post_id, $info['taxonomy'] );
			}
			return;
		}
	}

	/**
	 * Re-create the mirror term when a CPT post is restored from trash.
	 * `wp_untrash_post` does not fire `save_post`, so without this an untrashed
	 * vendor stays missing from the WC-native index until its next edit.
	 *
	 * @param int $post_id
	 */
	public function mirror_cpt_post_on_untrash( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( $post instanceof WP_Post ) {
			$this->mirror_cpt_post_to_term( (int) $post_id, $post );
		}
	}

	/**
	 * Remove the mirror term (and its product relationships) when a CPT post is
	 * deleted or trashed.
	 *
	 * Note: the additive product meta (e.g. `_lwp_vendor_ids`) may still carry the
	 * removed id until each product's next save — that is the pre-Phase-3 meta
	 * behaviour and is self-healing: the owning module's sanitize_callback drops
	 * unpublished ids on the next write, and its read path filters dead ids, so
	 * the frontend / KG / Schema never surface a deleted vendor.
	 *
	 * @param int $post_id
	 */
	public function remove_cpt_mirror_term( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return;
		}
		foreach ( $this->wc_attribution_map() as $info ) {
			if ( $info['post_type'] !== $post->post_type ) {
				continue;
			}
			if ( ! taxonomy_exists( $info['taxonomy'] ) ) {
				return;
			}
			$term = get_term_by( 'slug', (string) (int) $post_id, $info['taxonomy'] );
			if ( $term instanceof WP_Term ) {
				wp_delete_term( (int) $term->term_id, $info['taxonomy'] );
			}
			return;
		}
	}

	/**
	 * Forward sync: a product's attribution meta changed → set its terms.
	 * (added_post_meta / updated_post_meta callback.)
	 *
	 * @param int    $meta_id
	 * @param int    $object_id
	 * @param string $meta_key
	 * @param mixed  $meta_value
	 */
	public function sync_attribution_meta_to_terms( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( $this->syncing ) {
			return;
		}
		foreach ( $this->wc_attribution_map() as $info ) {
			if ( $info['meta'] !== $meta_key ) {
				continue;
			}
			if ( 'product' !== get_post_type( (int) $object_id ) || ! taxonomy_exists( $info['taxonomy'] ) ) {
				return;
			}
			$term_ids = array();
			foreach ( $this->parse_attribution_meta_value( $meta_value ) as $vid ) {
				$tid = $this->ensure_term_id_for_cpt_post( $vid, $info['taxonomy'] );
				if ( $tid ) {
					$term_ids[] = $tid;
				}
			}
			$prev          = $this->syncing;
			$this->syncing = true;
			try {
				wp_set_object_terms( (int) $object_id, $term_ids, $info['taxonomy'], false );
			} finally {
				$this->syncing = $prev;
			}
			return;
		}
	}

	/**
	 * Forward sync: attribution meta deleted → clear the product's terms.
	 *
	 * @param string[]|int[] $meta_ids
	 * @param int            $object_id
	 * @param string         $meta_key
	 * @param mixed          $meta_value
	 */
	public function sync_attribution_meta_deleted( $meta_ids, $object_id, $meta_key, $meta_value ) {
		if ( $this->syncing ) {
			return;
		}
		foreach ( $this->wc_attribution_map() as $info ) {
			if ( $info['meta'] !== $meta_key ) {
				continue;
			}
			if ( 'product' !== get_post_type( (int) $object_id ) || ! taxonomy_exists( $info['taxonomy'] ) ) {
				return;
			}
			$prev          = $this->syncing;
			$this->syncing = true;
			try {
				wp_set_object_terms( (int) $object_id, array(), $info['taxonomy'], false );
			} finally {
				$this->syncing = $prev;
			}
			return;
		}
	}

	/**
	 * Reverse sync: a product's attribution TERMS changed (REST / Store API /
	 * programmatic) → rebuild the canonical meta so the theme REGEXP, KG and
	 * Schema readers stay correct. (set_object_terms callback.)
	 *
	 * @param int    $object_id
	 * @param array  $terms
	 * @param array  $tt_ids
	 * @param string $taxonomy
	 * @param bool   $append
	 * @param array  $old_tt_ids
	 */
	public function sync_attribution_terms_to_meta( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		if ( $this->syncing ) {
			return;
		}
		$map = $this->wc_attribution_map();
		if ( ! isset( $map[ $taxonomy ] ) || 'product' !== get_post_type( (int) $object_id ) ) {
			return;
		}
		$meta     = $map[ $taxonomy ]['meta'];
		$assigned = wp_get_object_terms( (int) $object_id, $taxonomy, array( 'fields' => 'slugs' ) );
		// Never destroy canonical meta on an ambiguous read: a WP_Error here means
		// "unknown" (late de-registration / object-cache / DB fault), NOT "this
		// product genuinely has zero vendors". Only mutate on a clean read.
		if ( is_wp_error( $assigned ) ) {
			return;
		}
		$ids = array();
		foreach ( $assigned as $slug ) {
			$v = absint( $slug );
			if ( $v > 0 ) {
				$ids[] = $v;
			}
		}
		$ids = array_values( array_unique( $ids ) );

		$prev          = $this->syncing;
		$this->syncing = true;
		try {
			if ( empty( $ids ) ) {
				delete_post_meta( (int) $object_id, $meta );
			} else {
				// Let the owning module's registered sanitize_callback re-validate
				// (e.g. Vendors' normalize keeps the FR-016 canonical quoted shape).
				update_post_meta( (int) $object_id, $meta, wp_json_encode( array_map( 'strval', $ids ) ) );
			}
		} finally {
			$this->syncing = $prev;
		}
	}

	/**
	 * Schedule the one-time backfill (per post_type) on cron — never run it inline
	 * on a page request. WP's cron API dedupes an identical hook+args event within
	 * a 10-minute window, so concurrent visitors can't double-schedule. The heavy
	 * sweep runs out-of-band in run_wc_attribution_backfill().
	 *
	 * On a cron-disabled site the sweep simply never runs and pre-existing
	 * attribution stays un-indexed in the taxonomy — but every NEW write still
	 * dual-writes live, and the index self-heals as products are edited.
	 */
	public function maybe_schedule_wc_attribution_backfill() {
		if ( ! post_type_exists( 'product' ) ) {
			return;
		}
		foreach ( $this->wc_attribution_map() as $info ) {
			$pt = $info['post_type'];
			if ( get_option( 'luwipress_cpt_attr_backfill_' . $pt, false ) ) {
				continue; // already completed.
			}
			if ( ! wp_next_scheduled( 'luwipress_cpt_attr_backfill', array( $pt ) ) ) {
				wp_schedule_single_event( time() + 30, 'luwipress_cpt_attr_backfill', array( $pt ) );
			}
		}
	}

	/**
	 * Cron handler: run the one-time backfill for one post_type out-of-band, then
	 * set the completion flag. Idempotent — re-entry is gated by the flag and the
	 * underlying term/relationship writes are themselves idempotent.
	 *
	 * @param string $post_type
	 */
	public function run_wc_attribution_backfill( $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		$flag      = 'luwipress_cpt_attr_backfill_' . $post_type;
		if ( get_option( $flag, false ) ) {
			return;
		}
		foreach ( $this->wc_attribution_map() as $info ) {
			if ( $info['post_type'] !== $post_type || ! taxonomy_exists( $info['taxonomy'] ) ) {
				continue;
			}
			$this->backfill_one_wc_attribution( $info );
			update_option( $flag, current_time( 'c' ), false );
			return;
		}
	}

	/**
	 * @param array{post_type:string,meta:string,taxonomy:string,label:string} $info
	 */
	private function backfill_one_wc_attribution( array $info ) {
		$tax   = $info['taxonomy'];
		$cache = array(); // CPT post id => term id — collapses repeated get_term_by lookups.

		// 1. Mirror term for every published CPT post.
		$posts = get_posts( array(
			'post_type'      => $info['post_type'],
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		foreach ( $posts as $pid ) {
			$pid           = (int) $pid;
			$cache[ $pid ] = $this->ensure_term_id_for_cpt_post( $pid, $tax );
		}

		// 2. Set product terms from the existing attribution meta.
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.post_id, pm.meta_value
			   FROM {$wpdb->postmeta} pm
			   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			  WHERE pm.meta_key = %s AND p.post_type = 'product'",
			$info['meta']
		) );
		$products = 0;
		foreach ( $rows as $row ) {
			$term_ids = array();
			foreach ( $this->parse_attribution_meta_value( $row->meta_value ) as $vid ) {
				$vid = (int) $vid;
				if ( ! isset( $cache[ $vid ] ) ) {
					$cache[ $vid ] = $this->ensure_term_id_for_cpt_post( $vid, $tax );
				}
				if ( $cache[ $vid ] ) {
					$term_ids[] = $cache[ $vid ];
				}
			}
			// Skip the write when the product already carries exactly these terms.
			$current = wp_get_object_terms( (int) $row->post_id, $tax, array( 'fields' => 'ids' ) );
			$current = is_wp_error( $current ) ? array() : array_map( 'intval', $current );
			$want    = $term_ids;
			sort( $current );
			sort( $want );
			if ( $current === $want ) {
				continue;
			}
			$prev          = $this->syncing;
			$this->syncing = true;
			try {
				wp_set_object_terms( (int) $row->post_id, $term_ids, $tax, false );
			} finally {
				$this->syncing = $prev;
			}
			$products++;
		}

		if ( class_exists( 'LuwiPress_Logger' ) ) {
			LuwiPress_Logger::log( sprintf(
				'CPT Engine: backfilled "%s" attribution taxonomy (%d terms, %d products).',
				$tax, count( $posts ), $products
			), 'info' );
		}
	}

	/**
	 * Products-list admin filter dropdown for each attribution taxonomy. WP's
	 * edit.php applies the term filter automatically from the matching query
	 * var (taxonomy registered with query_var => true).
	 *
	 * @param string $post_type
	 */
	public function render_attribution_filter( $post_type ) {
		if ( 'product' !== $post_type ) {
			return;
		}
		foreach ( $this->wc_attribution_map() as $info ) {
			$tax = $info['taxonomy'];
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
			$current = isset( $_GET[ $tax ] ) ? sanitize_text_field( wp_unslash( $_GET[ $tax ] ) ) : '';
			echo '<select name="' . esc_attr( $tax ) . '">';
			/* translators: %s: attribution type label (e.g. Vendors) */
			echo '<option value="">' . esc_html( sprintf( __( 'All %s', 'luwipress' ), $info['label'] ) ) . '</option>';
			foreach ( $terms as $t ) {
				if ( ! $t instanceof WP_Term ) {
					continue;
				}
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $t->slug ),
					selected( $current, $t->slug, false ),
					esc_html( $t->name )
				);
			}
			echo '</select>';
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
