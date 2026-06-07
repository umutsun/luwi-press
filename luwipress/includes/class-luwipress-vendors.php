<?php
/**
 * LuwiPress Vendors — generic CPT for vendor / maker / atelier profiles
 * with E-E-A-T trust signals.
 *
 * One module, many verticals. The mental model is "vendor entity that makes
 * the things you sell": a music store calls them "Luthiers", a restaurant
 * "Chefs", an art gallery "Artists", a bakery "Bakers", an agency "Team".
 * Each site picks the right vocabulary via Settings.
 *
 * The underlying post type stays `lwp_vendor` so themes + integrations can
 * target a single, stable identifier. The rewrite slug + UI labels are
 * configurable per site, so URLs read naturally: /luthiers/yildirim-palabiyik/,
 * /chefs/maria-rossi/, /artists/john-doe/, /vendors/acme-roastery/.
 *
 * Entity type is toggleable per site (entity_type setting):
 *   - 'organization'  → Schema.org Organization (atelier, workshop, brand)
 *   - 'person'        → Schema.org Person       (individual maestro / author)
 *   - 'localbusiness' → Schema.org LocalBusiness (physical-store vendor)
 *
 * E-E-A-T payload: each vendor carries verified social URLs (Facebook,
 * Instagram, YouTube, SoundCloud, LinkedIn, X, Behance, Website) that
 * flow into the JSON-LD `sameAs` array via the Schema Registry, giving
 * Google a strong author/vendor identity signal.
 *
 * REST surface (luwipress/v1):
 *   GET  /vendors/settings       — read current config
 *   POST /vendors/settings       — update config (rewrite slug, labels, fields)
 *   GET  /people                — list people (delegates to WP REST + extras)
 *   GET  /vendors/{id}           — single vendor + meta + schema preview
 *   POST /vendors/{id}/meta      — write meta fields
 *   POST /vendors/sync-rewrite   — flush rewrite rules after slug change
 *
 * @package LuwiPress
 * @since   3.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Vendors {

	const POST_TYPE      = 'lwp_vendor';
	const TAXONOMY_GROUP = 'lwp_vendor_group';
	const OPTION_PREFIX  = 'luwipress_vendors_';

	// Per-group archive slugs (3.7.5) — each lwp_vendor_group term may carry
	// its own URL base so /team/<name>/ and /luthiers/<name>/ coexist under one
	// CPT. A vendor's canonical permalink follows its _primary_group base when
	// set, otherwise falls back to the global archive_slug (zero breakage for
	// existing vendors that have no primary group).
	const GROUP_SLUG_META       = 'lwp_group_archive_slug';   // term meta: per-group URL base
	const PRIMARY_GROUP_META    = '_lwp_vendor_primary_group'; // post meta: canonical group term_id
	const TRANSIENT_GROUP_BASES = 'luwipress_vendor_group_bases_v1';

	/**
	 * Default config. Each value lives under
	 * option key `luwipress_vendors_<key>`. Operator overrides via Settings UI
	 * or REST POST /vendors/settings.
	 */
	const DEFAULTS = array(
		'enabled'             => 1,
		'archive_slug'        => 'vendors',
		'singular_label'      => 'Vendor',
		'plural_label'        => 'Vendors',
		'menu_icon'           => 'dashicons-store',
		'entity_type'         => 'organization', // organization | person | localbusiness
		'default_occupation'  => '',
		// Permalink controls — operator-tunable per site so URL design fits the brand.
		'with_front'          => 0,           // prepend WP permalink base (e.g., /blog/) — usually off
		'single_slug_pattern' => '%postname%', // single post URL tail; future: %year%/%postname%, %category%/%postname%
		'archive_enabled'     => 1,           // false = no /<archive>/ index, individual permalinks still work
		// Profile field toggles
		'show_location'       => 1,
		'show_specialty'      => 1,
		'show_years'          => 1,
		'show_quote'          => 1,
		// Social link field toggles
		'social_facebook'     => 1,
		'social_instagram'    => 1,
		'social_youtube'      => 1,
		'social_soundcloud'   => 0,
		'social_linkedin'     => 0,
		'social_x'            => 0,
		'social_behance'      => 0,
		'social_website'      => 1,
		// Redirect (legacy URLs) — JSON array of {from, to} pairs, e.g. /masters/ -> /luthiers/
		'legacy_redirects'    => '',
	);

	/**
	 * Meta keys. All prefixed with _lwp_vendor_ to scope under our module
	 * and stay hidden from Custom Fields metabox unless explicitly opted in.
	 */
	const META_KEYS = array(
		'location'   => '_lwp_vendor_location',
		'occupation' => '_lwp_vendor_occupation',
		'specialty'  => '_lwp_vendor_specialty',
		'years'      => '_lwp_vendor_years_active',
		'quote'      => '_lwp_vendor_quote',
		'facebook'   => '_lwp_vendor_facebook',
		'instagram'  => '_lwp_vendor_instagram',
		'youtube'    => '_lwp_vendor_youtube',
		'soundcloud' => '_lwp_vendor_soundcloud',
		'linkedin'   => '_lwp_vendor_linkedin',
		'x'          => '_lwp_vendor_x',
		'behance'    => '_lwp_vendor_behance',
		'website'    => '_lwp_vendor_website',
	);

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ), 5 );
		add_action( 'init', array( $this, 'register_meta_fields' ), 6 );
		add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );

		// Hook into Schema Registry to add 'person' type
		add_action( 'luwipress_schema_registry_init', array( $this, 'register_vendor_schema' ) );

		// Legacy URL redirects (e.g., /masters/ -> /luthiers/) — runs before
		// WP's 404 to catch operator-defined redirect pairs.
		add_action( 'template_redirect', array( $this, 'maybe_redirect_legacy' ), 1 );

		// Auto-flush rewrite rules when permalink-affecting options change
		foreach ( array( 'archive_slug', 'with_front', 'enabled', 'archive_enabled' ) as $opt ) {
			add_action( 'update_option_' . self::OPTION_PREFIX . $opt, array( $this, 'on_slug_change' ), 10, 2 );
		}

		// WP Settings → Permalinks page: add a notice pointing to our settings
		add_action( 'admin_init', array( $this, 'register_permalinks_page_notice' ) );

		// Vendor edit screen: per-vendor Schema.org @type override metabox (FR-019).
		// Not WC-gated — vendor profiles exist independently of WooCommerce.
		add_action( 'add_meta_boxes', array( $this, 'add_vendor_entity_type_metabox' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_vendor_entity_type' ), 10, 2 );

		// WooCommerce integration (loaded only when WC is active)
		add_action( 'woocommerce_init', array( $this, 'init_woocommerce_integration' ) );

		// ── Per-group archive slugs (3.7.5) ──────────────────────────────
		// Dynamic rewrite rules per group base (runs after register_cpt p5).
		add_action( 'init', array( $this, 'register_group_rewrites' ), 7 );
		// Canonical permalink → primary group's base (falls back to global).
		add_filter( 'post_type_link', array( $this, 'filter_vendor_permalink' ), 10, 2 );
		// Tell the core Slug Resolver to leave group bases alone (no 301) — keeps
		// the resolver generic; the vendor module owns the base knowledge.
		add_filter( 'luwipress_slug_resolver_skip_slugs', array( $this, 'resolver_skip_group_bases' ) );
		// Group term edit screens: archive_slug field + save (+ rewrite flush).
		add_action( self::TAXONOMY_GROUP . '_add_form_fields', array( $this, 'group_archive_slug_add_field' ) );
		add_action( self::TAXONOMY_GROUP . '_edit_form_fields', array( $this, 'group_archive_slug_edit_field' ), 10, 2 );
		add_action( 'created_' . self::TAXONOMY_GROUP, array( $this, 'save_group_archive_slug' ) );
		add_action( 'edited_' . self::TAXONOMY_GROUP, array( $this, 'save_group_archive_slug' ) );
		add_action( 'delete_' . self::TAXONOMY_GROUP, array( $this, 'bust_group_bases_cache' ) );
		// Vendor edit screen: pick the canonical (primary) group.
		add_action( 'add_meta_boxes', array( $this, 'add_primary_group_metabox' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_primary_group' ), 10, 2 );

		// CPT Engine (3.7.x+): describe this module to the generic registry as
		// preset #1 so downstream integrations (Translation Manager, Elementor,
		// WooCommerce) can enumerate it generically. Pure metadata — Vendors
		// still self-registers its own CPT/taxonomy/meta above.
		add_action( 'luwipress_cpt_engine_register', array( $this, 'register_with_cpt_engine' ) );
	}

	/**
	 * Describe the Vendors module to the CPT Engine as preset #1.
	 *
	 * @param LuwiPress_CPT_Engine $engine
	 */
	public function register_with_cpt_engine( $engine ) {
		if ( ! is_object( $engine ) || ! method_exists( $engine, 'register_type' ) ) {
			return;
		}

		$url_keys   = array( 'facebook', 'instagram', 'youtube', 'soundcloud', 'linkedin', 'x', 'behance', 'website' );
		$translate  = array( 'location', 'specialty', 'quote', 'occupation' );
		$fields     = array();
		foreach ( self::META_KEYS as $name => $meta_key ) {
			if ( in_array( $name, $url_keys, true ) ) {
				$type = 'url';
			} elseif ( 'years' === $name ) {
				$type = 'number';
			} elseif ( 'quote' === $name ) {
				$type = 'textarea';
			} else {
				$type = 'text';
			}
			$fields[] = array(
				'key'          => $meta_key,
				'type'         => $type,
				'label'        => ucwords( str_replace( '_', ' ', $name ) ),
				'translatable' => in_array( $name, $translate, true ),
				'in_elementor' => true,
			);
		}

		// Per-vendor Schema @type override — a structural enum, not profile text:
		// kept OUT of Elementor + not translated, but declared so the WPML/Polylang
		// config generator emits the same `copy` rule the shipped wpml-config.xml
		// carries (single source of truth, no static-vs-generated drift).
		$fields[] = array(
			'key'          => self::ENTITY_TYPE_META,
			'type'         => 'select',
			'label'        => __( 'Schema entity type', 'luwipress' ),
			'translatable' => false,
			'in_elementor' => false,
		);

		$engine->register_type( 'vendors', array(
			'post_type'      => self::POST_TYPE,
			'source'         => 'preset',
			'self_registers' => true, // Vendors registers its own CPT below — engine must not double-register.
			'enabled'        => ( (int) self::get_setting( 'enabled' ) === 1 ),
			'labels'         => array(
				'singular' => (string) self::get_setting( 'singular_label' ),
				'plural'   => (string) self::get_setting( 'plural_label' ),
			),
			'permalink'      => array(
				'archive_slug'        => (string) self::get_setting( 'archive_slug' ),
				'single_slug_pattern' => (string) self::get_setting( 'single_slug_pattern' ),
			),
			'field_schema'   => $fields,
			'taxonomies'     => array(
				array(
					'slug'         => self::TAXONOMY_GROUP,
					'label'        => __( 'Vendor Groups', 'luwipress' ),
					'hierarchical' => false,
					'translatable' => true,
				),
			),
			'schema_mapping' => array(
				'type'          => 'person',
				'override_meta' => self::ENTITY_TYPE_META,
			),
			'woocommerce'    => array(
				'attribution_meta' => self::PRODUCT_VENDORS_META,
				'attribution_role' => __( 'Made by', 'luwipress' ),
			),
		) );
	}

	/* ─── WOOCOMMERCE INTEGRATION ─────────────────────────────────────── */

	/**
	 * Wire the Vendor CPT into WooCommerce: product admin meta box,
	 * PDP "Made by" line, Product Schema.org manufacturer field.
	 */
	public function init_woocommerce_integration() {
		// Product admin: meta box on edit-product screen for selecting vendors.
		add_action( 'add_meta_boxes', array( $this, 'add_product_vendor_metabox' ) );
		add_action( 'save_post_product', array( $this, 'save_product_vendor' ), 10, 2 );

		// Register the product→vendor attribution meta so it has a sanitize
		// pass on EVERY write — direct update_post_meta, REST, MCP meta_set,
		// wp-cli, third-party seed scripts. Normalizes the JSON payload to the
		// canonical ["<id>","<id>"] quoted-string form so downstream LIKE/REGEXP
		// queries built around the quoted shape don't silently miss integer-
		// shaped (`[123]`) writes from external callers (closes the Tapadum
		// FR-016 Feramis-render miss where direct seed writes produced
		// `[36633]` and the template's `"%d"` LIKE pattern didn't match).
		register_post_meta(
			'product',
			self::PRODUCT_VENDORS_META,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'description'       => 'LuwiPress Vendors — attributed vendor post IDs (JSON array of strings).',
				'sanitize_callback' => array( $this, 'normalize_product_vendor_ids' ),
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		// PDP frontend: render "Made by" line in the product summary.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_vendor_line' ), 25 );

		// One-time data migration — normalize any pre-existing `_lwp_vendor_ids`
		// meta values that were written outside the canonical save path
		// (direct DB writes, third-party seed scripts, MCP meta_set with raw
		// integer arrays). Idempotent; flag stored in wp_options.
		add_action( 'init', array( $this, 'maybe_migrate_vendor_ids_format' ), 20 );

		// Product Schema.org: inject manufacturer / author linking to vendor URL.
		add_filter( 'woocommerce_structured_data_product', array( $this, 'enrich_product_schema_with_vendor' ), 10, 2 );

		// Rank Math replaces WooCommerce's native structured-data printer with its
		// own @graph, so the woocommerce_structured_data_product result is
		// discarded on Rank-Math sites — mirror the manufacturer/author injection
		// into Rank Math's Product node. Late priority (99) so we run AFTER other
		// rank_math/json_ld subscribers that may rebuild the product node.
		add_filter( 'rank_math/json_ld', array( $this, 'enrich_rank_math_product_schema' ), 99, 2 );

		// Bust the per-vendor makesOffer transient whenever a product's vendor
		// attribution changes through ANY write path (admin save, REST, MCP,
		// wp-cli, seed scripts) — all funnel through the post-meta lifecycle.
		// update_post_meta / delete_post_meta fire BEFORE the DB write (old value
		// still readable), added_post_meta AFTER — the callback unions both so a
		// de-attached vendor's cache is busted too.
		add_action( 'update_post_meta', array( $this, 'invalidate_vendor_offers_on_meta' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'invalidate_vendor_offers_on_meta' ), 10, 4 );
		add_action( 'delete_post_meta', array( $this, 'invalidate_vendor_offers_on_meta' ), 10, 4 );
	}

	const PRODUCT_VENDORS_META = '_lwp_vendor_ids';

	// Optional per-vendor Schema.org @type override (organization|person|
	// localbusiness). Empty = inherit the site-global luwipress_vendors_entity_type.
	// Lets a single atelier/organization vendor emit @type Organization while
	// every other vendor on the same site stays the global default (FR-019).
	const ENTITY_TYPE_META = '_lwp_vendor_entity_type';

	public function add_product_vendor_metabox() {
		$singular = (string) self::get_setting( 'singular_label' );
		$plural   = (string) self::get_setting( 'plural_label' );
		add_meta_box(
			'lwp_product_vendors',
			sprintf( __( 'LuwiPress %s', 'luwipress' ), $singular ),
			array( $this, 'render_product_vendor_metabox' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Resolve a vendor to its WPML/Polylang source-language (default-language)
	 * sibling id. Mirrors LuwiPress_CPT_Engine::source_post_id() so the
	 * attribution metabox lists + saves the canonical source id (FR-016) — one
	 * entry per vendor regardless of how many languages it is translated into.
	 * Returns the input id unchanged when no translation plugin is active.
	 *
	 * @param int $vendor_id
	 * @return int
	 */
	private function source_vendor_id( $vendor_id ) {
		$vendor_id = (int) $vendor_id;
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$default = apply_filters( 'wpml_default_language', null );
			if ( $default ) {
				// $return_original_if_missing = true → falls back to $vendor_id.
				$resolved = apply_filters( 'wpml_object_id', $vendor_id, self::POST_TYPE, true, $default );
				if ( $resolved ) {
					return (int) $resolved;
				}
			}
		}
		if ( function_exists( 'pll_get_post' ) && function_exists( 'pll_default_language' ) ) {
			$default = pll_default_language();
			if ( $default ) {
				$resolved = pll_get_post( $vendor_id, $default );
				if ( $resolved ) {
					return (int) $resolved;
				}
			}
		}
		return $vendor_id;
	}

	public function render_product_vendor_metabox( $post ) {
		$attached_raw = get_post_meta( $post->ID, self::PRODUCT_VENDORS_META, true );
		$attached     = is_string( $attached_raw ) && $attached_raw !== ''
			? (array) json_decode( $attached_raw, true )
			: array();
		$attached_ids = array_map( 'intval', $attached );

		$all = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		// Collapse WPML/Polylang language siblings to ONE entry per vendor. The
		// query can return every translation (each language a separate row), so
		// without this the checkbox list shows the same vendor N× (once per lang).
		// We also normalize the checkbox value to the SOURCE-language id, because
		// product attribution meta (_lwp_vendor_ids) is canonically keyed to the
		// source id (FR-016) — saving a translation id would break the theme
		// REGEXP match, the KG made_by edge, and the CPT-engine mirror term.
		$seen   = array();
		$unique = array();
		foreach ( $all as $v ) {
			$src = $this->source_vendor_id( (int) $v->ID );
			if ( isset( $seen[ $src ] ) ) {
				continue;
			}
			$seen[ $src ] = true;
			if ( $src !== (int) $v->ID ) {
				$src_post = get_post( $src );
				$unique[] = ( $src_post instanceof WP_Post && $src_post->post_type === self::POST_TYPE )
					? $src_post
					: $v;
			} else {
				$unique[] = $v;
			}
		}
		usort( $unique, static function ( $a, $b ) {
			return strcasecmp( (string) $a->post_title, (string) $b->post_title );
		} );
		$all = $unique;

		$singular = (string) self::get_setting( 'singular_label' );
		$plural   = (string) self::get_setting( 'plural_label' );

		wp_nonce_field( 'lwp_product_vendors_save', 'lwp_product_vendors_nonce' );

		if ( empty( $all ) ) {
			echo '<p><em>' . esc_html( sprintf(
				/* translators: %s: plural label */
				__( 'No %s yet. Add them in LuwiPress → Vendors.', 'luwipress' ),
				strtolower( $plural )
			) ) . '</em></p>';
			return;
		}

		echo '<p style="font-size:12px;color:#646970;margin:0 0 8px;">' . esc_html( sprintf(
			/* translators: %s: plural label */
			__( 'Attribute this product to one or more %s. Shows on the PDP and the Schema.org manufacturer field.', 'luwipress' ),
			strtolower( $plural )
		) ) . '</p>';

		echo '<ul style="margin:0;max-height:200px;overflow:auto;padding:0;list-style:none;">';
		foreach ( $all as $v ) {
			$checked = in_array( (int) $v->ID, $attached_ids, true ) ? 'checked' : '';
			echo '<li style="margin:0 0 6px;">';
			echo '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;">';
			echo '<input type="checkbox" name="lwp_product_vendors[]" value="' . esc_attr( (string) $v->ID ) . '" ' . esc_attr( $checked ) . ' />';
			echo '<span>' . esc_html( $v->post_title ) . '</span>';
			echo '</label></li>';
		}
		echo '</ul>';
	}

	public function save_product_vendor( $post_id, $post ) {
		if ( ! isset( $_POST['lwp_product_vendors_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_POST['lwp_product_vendors_nonce'] ), 'lwp_product_vendors_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$raw = isset( $_POST['lwp_product_vendors'] ) ? (array) $_POST['lwp_product_vendors'] : array();
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $raw ) ) ) );
		if ( empty( $ids ) ) {
			delete_post_meta( $post_id, self::PRODUCT_VENDORS_META );
			return;
		}
		// Validate each ID is an actual published lwp_vendor.
		$valid = array();
		foreach ( $ids as $vid ) {
			$pp = get_post( $vid );
			if ( $pp && $pp->post_type === self::POST_TYPE && $pp->post_status === 'publish' ) {
				$valid[] = $vid;
			}
		}
		if ( empty( $valid ) ) {
			delete_post_meta( $post_id, self::PRODUCT_VENDORS_META );
			return;
		}
		// Stored as JSON string-encoded array so meta_query LIKE matches survive.
		update_post_meta( $post_id, self::PRODUCT_VENDORS_META, wp_json_encode( array_map( 'strval', $valid ) ) );
	}

	/**
	 * Vendor edit screen — Schema.org @type override metabox (FR-019). Lets an
	 * operator flip a single vendor (e.g. an atelier / workshop) to Organization
	 * while the rest of the site keeps the global default.
	 */
	public function add_vendor_entity_type_metabox() {
		add_meta_box(
			'lwp_vendor_entity_type',
			__( 'Schema entity type', 'luwipress' ),
			array( $this, 'render_vendor_entity_type_metabox' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	public function render_vendor_entity_type_metabox( $post ) {
		$current = (string) get_post_meta( $post->ID, self::ENTITY_TYPE_META, true );
		$global  = (string) self::get_setting( 'entity_type' );
		wp_nonce_field( 'lwp_vendor_entity_type_save', 'lwp_vendor_entity_type_nonce' );
		$options = array(
			''              => sprintf( __( 'Inherit site default (%s)', 'luwipress' ), ucfirst( $global ) ),
			'organization'  => __( 'Organization', 'luwipress' ),
			'person'        => __( 'Person', 'luwipress' ),
			'localbusiness' => __( 'LocalBusiness', 'luwipress' ),
		);
		echo '<p style="font-size:12px;color:#646970;margin:0 0 8px;">' . esc_html__( 'Overrides the Schema.org @type for this profile and its product attribution.', 'luwipress' ) . '</p>';
		echo '<select name="lwp_vendor_entity_type" style="width:100%;">';
		foreach ( $options as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public function save_vendor_entity_type( $post_id, $post ) {
		if ( ! isset( $_POST['lwp_vendor_entity_type_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_POST['lwp_vendor_entity_type_nonce'] ), 'lwp_vendor_entity_type_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$val = isset( $_POST['lwp_vendor_entity_type'] ) ? sanitize_text_field( wp_unslash( $_POST['lwp_vendor_entity_type'] ) ) : '';
		if ( in_array( $val, array( 'organization', 'person', 'localbusiness' ), true ) ) {
			update_post_meta( $post_id, self::ENTITY_TYPE_META, $val );
		} else {
			delete_post_meta( $post_id, self::ENTITY_TYPE_META );
		}
	}

	/**
	 * One-time normalization sweep of pre-existing `_lwp_vendor_ids` meta.
	 *
	 * Runs once per site (gated by `luwipress_vendor_ids_normalized` option).
	 * Walks every product carrying the meta and re-writes it through the
	 * canonical sanitize callback so integer-shaped JSON (`[123]`) becomes
	 * the quoted-string canonical form (`["123"]`). Idempotent — flag is set
	 * after the first successful sweep, so subsequent page loads skip the
	 * work.
	 *
	 * Closes the Tapadum FR-016 Feramis-render miss where direct seed writes
	 * produced unquoted integer JSON that downstream queries couldn't match.
	 */
	public function maybe_migrate_vendor_ids_format() {
		if ( get_option( 'luwipress_vendor_ids_normalized', false ) ) {
			return;
		}
		// Avoid running during AJAX / cron / REST sub-requests — only on real
		// WP page loads where we have time and a stable hookchain.
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.post_id, pm.meta_value
			   FROM {$wpdb->postmeta} pm
			   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			  WHERE pm.meta_key = %s
			    AND p.post_type = 'product'
			    AND p.post_status = 'publish'",
			self::PRODUCT_VENDORS_META
		) );

		$touched = 0;
		foreach ( $rows as $row ) {
			$normalized = $this->normalize_product_vendor_ids( $row->meta_value );
			if ( $normalized !== (string) $row->meta_value ) {
				update_post_meta( (int) $row->post_id, self::PRODUCT_VENDORS_META, $normalized );
				$touched++;
			}
		}

		update_option( 'luwipress_vendor_ids_normalized', current_time( 'c' ) );
		if ( $touched > 0 && class_exists( 'LuwiPress_Logger' ) ) {
			LuwiPress_Logger::log( sprintf( 'Vendors module: normalized _lwp_vendor_ids on %d products to canonical quoted-string JSON.', $touched ), 'info' );
		}
	}

	/**
	 * Sanitize-callback for the `_lwp_vendor_ids` product meta.
	 *
	 * Accepts: a JSON-encoded array (string), a comma-separated list (string),
	 * an actual array, or empty. Always returns either an empty string or a
	 * canonical `["123","456"]` JSON string with absint'd, deduped, validated
	 * vendor IDs. This is the single source of truth for the meta format so
	 * downstream LIKE/REGEXP queries can rely on the quoted-string shape.
	 *
	 * @param mixed $value Incoming meta value (any shape).
	 * @return string Normalized JSON string or empty.
	 */
	public function normalize_product_vendor_ids( $value ) {
		// Already an array — normalize directly.
		if ( is_array( $value ) ) {
			$ids = $value;
		} elseif ( is_string( $value ) ) {
			$trimmed = trim( $value );
			if ( $trimmed === '' ) {
				return '';
			}
			// Try JSON first (covers both `["1","2"]` and `[1,2]`).
			$decoded = json_decode( $trimmed, true );
			if ( is_array( $decoded ) ) {
				$ids = $decoded;
			} else {
				// Fall back to comma / space separated.
				$ids = preg_split( '/[\s,]+/', $trimmed );
			}
		} elseif ( is_numeric( $value ) ) {
			$ids = array( $value );
		} else {
			return '';
		}

		// Validate each entry against actual published lwp_vendor posts.
		$valid = array();
		foreach ( (array) $ids as $id ) {
			$id = absint( $id );
			if ( $id <= 0 ) {
				continue;
			}
			$post = get_post( $id );
			if ( $post && $post->post_type === self::POST_TYPE && $post->post_status === 'publish' ) {
				$valid[] = (string) $id;
			}
		}
		$valid = array_values( array_unique( $valid ) );
		if ( empty( $valid ) ) {
			return '';
		}
		return wp_json_encode( $valid );
	}

	/**
	 * Get the vendor posts attached to a WC product.
	 *
	 * @param int $product_id
	 * @return array Array of [id, title, link, image, location, specialty]
	 */
	public function get_product_vendors( $product_id ) {
		// Source 1: canonical _lwp_vendor_ids meta (JSON array of vendor post IDs).
		$ids = array();
		$raw = get_post_meta( $product_id, self::PRODUCT_VENDORS_META, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$ids = array_map( 'intval', (array) json_decode( $raw, true ) );
		}

		// Source 2 (FR-017): the WC attribution taxonomy mirror term. The CPT Engine
		// (Phase 3) promotes attribution to a hidden product_<post_type> taxonomy and
		// dual-writes meta<->terms; a product attributed term-first (admin filter /
		// seed / Store API) can carry the TERM without the meta. The mirror term's slug
		// IS the vendor post id, so reading terms here makes the manufacturer/author
		// schema fire regardless of which write path attributed the product -- symmetric
		// with the vendor-side makesOffer, which already resolves via this taxonomy.
		$tax = 'product_' . self::POST_TYPE;
		if ( taxonomy_exists( $tax ) ) {
			$terms = get_the_terms( $product_id, $tax );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$vid = (int) $term->slug;
					if ( $vid > 0 ) {
						$ids[] = $vid;
					}
				}
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$out = array();
		foreach ( $ids as $vid ) {
			$p = get_post( $vid );
			if ( ! $p || $p->post_type !== self::POST_TYPE || $p->post_status !== 'publish' ) {
				continue;
			}
			$out[] = array(
				'id'        => $p->ID,
				'title'     => get_the_title( $p ),
				'link'      => get_permalink( $p ),
				'image'     => get_the_post_thumbnail_url( $p, 'thumbnail' ) ?: '',
				'location'    => (string) get_post_meta( $p->ID, self::META_KEYS['location'],  true ),
				'specialty'   => (string) get_post_meta( $p->ID, self::META_KEYS['specialty'], true ),
				'entity_type' => $this->resolve_entity_type( $p->ID ),
			);
		}
		return $out;
	}

	public function render_product_vendor_line() {
		global $product;
		// Duck-type — WC product passes a WC_Product instance via the woocommerce_single_product_summary action.
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}
		$vendors = $this->get_product_vendors( $product->get_id() );
		if ( empty( $vendors ) ) {
			return;
		}
		$singular = strtolower( (string) self::get_setting( 'singular_label' ) );

		echo '<p class="lwp-product-vendors">';
		echo '<span class="lwp-product-vendors__label">' . esc_html( sprintf(
			/* translators: %s: singular vendor label */
			__( 'Made by this %s:', 'luwipress' ),
			$singular
		) ) . '</span> ';
		$links = array();
		foreach ( $vendors as $v ) {
			$links[] = '<a href="' . esc_url( $v['link'] ) . '" class="lwp-product-vendors__name" rel="author">' . esc_html( $v['title'] ) . '</a>';
		}
		echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped per element.
		echo '</p>';
	}

	/**
	 * Build the manufacturer/author schema payload for a product's attributed
	 * vendors. Shared by the WooCommerce-native and Rank Math json_ld paths so
	 * both emit an identical node. Returns null when the product has no
	 * attributed vendors (or an invalid id).
	 *
	 * @param int $product_id
	 * @return array{field:string,value:array}|null
	 */
	private function build_vendor_schema_payload( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return null;
		}
		$vendors = $this->get_product_vendors( $product_id );
		if ( empty( $vendors ) ) {
			return null;
		}

		$schema_type_map = array(
			'organization'  => 'Organization',
			'person'        => 'Person',
			'localbusiness' => 'LocalBusiness',
		);
		// The wrapper field key (manufacturer vs author) is product-level and
		// follows the site-global entity type — schema.org can't mix author and
		// manufacturer keys on one Product. Each vendor NODE, however, carries
		// its OWN @type from its per-vendor override (FR-019), so an atelier
		// Organization and a luthier Person attributed to the same product each
		// render with the correct @type under that single key.
		$global_entity = (string) self::get_setting( 'entity_type' );
		if ( ! isset( $schema_type_map[ $global_entity ] ) ) {
			$global_entity = 'organization';
		}
		$schema_field = $global_entity === 'person' ? 'author' : 'manufacturer';

		// Single vendor — emit one object. Multiple → array. Per-vendor @type.
		$vendor_payloads = array_map( function ( $v ) use ( $schema_type_map, $global_entity ) {
			$etype   = ( isset( $v['entity_type'] ) && isset( $schema_type_map[ $v['entity_type'] ] ) ) ? $v['entity_type'] : $global_entity;
			$payload = array(
				'@type' => $schema_type_map[ $etype ],
				'name'  => $v['title'],
				'url'   => esc_url_raw( $v['link'] ),
			);
			if ( ! empty( $v['image'] ) ) {
				$payload['image'] = esc_url_raw( $v['image'] );
			}
			return $payload;
		}, $vendors );

		return array(
			'field' => $schema_field,
			'value' => count( $vendor_payloads ) === 1 ? $vendor_payloads[0] : $vendor_payloads,
		);
	}

	/**
	 * Add `manufacturer` (or `author` for entity_type=person) to the WC Product
	 * Schema.org payload — strong vendor attribution signal. Rendered on sites
	 * using WooCommerce's native structured data (non-Rank-Math SEO plugins).
	 */
	public function enrich_product_schema_with_vendor( $markup, $product ) {
		// Duck-type — WC passes a WC_Product instance via the woocommerce_structured_data_product filter.
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $markup;
		}
		$payload = $this->build_vendor_schema_payload( $product->get_id() );
		if ( null === $payload ) {
			return $markup;
		}
		if ( ! isset( $markup[ $payload['field'] ] ) ) {
			$markup[ $payload['field'] ] = $payload['value'];
		}
		return $markup;
	}

	/**
	 * Mirror the manufacturer/author injection into Rank Math's @graph. On
	 * Rank-Math sites WC's native structured-data printer is disabled and Rank
	 * Math emits its own Product node, so the woocommerce_structured_data_product
	 * result never reaches the page — this lands the same node in Rank Math's
	 * graph.
	 *
	 * @param mixed $data   Rank Math json_ld node collection (assoc array).
	 * @param mixed $jsonld Rank Math JsonLD object (exposes get_post_id()).
	 * @return mixed
	 */
	public function enrich_rank_math_product_schema( $data, $jsonld ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$product_id = ( is_object( $jsonld ) && method_exists( $jsonld, 'get_post_id' ) )
			? (int) $jsonld->get_post_id()
			: 0;
		$payload = $this->build_vendor_schema_payload( $product_id );
		if ( null === $payload ) {
			return $data;
		}

		// Locate the Product node and inject. Write by KEY into $data — never
		// mutate a foreach copy (that write would be silently discarded). Match
		// @type as a string OR array; tolerate ProductGroup (variable products)
		// and don't clobber an operator-filled Rank Math Brand/manufacturer.
		$found = false;
		foreach ( $data as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$types = array_map( 'strval', (array) ( $entry['@type'] ?? array() ) );
			if ( ! in_array( 'Product', $types, true ) && ! in_array( 'ProductGroup', $types, true ) ) {
				continue;
			}
			if ( ! isset( $data[ $key ][ $payload['field'] ] ) ) {
				$data[ $key ][ $payload['field'] ] = $payload['value'];
			}
			$found = true;
			break;
		}

		if ( ! $found && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// No top-level Product node — log the shape so the live graph can be
			// inspected if attribution ever fails to land.
			error_log( 'LuwiPress Vendors: rank_math/json_ld carried no top-level Product node. Keys=' . wp_json_encode( array_keys( $data ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $data;
	}

	/**
	 * Decode a `_lwp_vendor_ids` meta value (JSON quoted-string array, integer
	 * array, or single int) into an int[] of vendor IDs. Shape-agnostic.
	 *
	 * @param mixed $raw
	 * @return int[]
	 */
	private function decode_vendor_ids( $raw ) {
		if ( is_array( $raw ) ) {
			$decoded = $raw;
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				$decoded = array( $raw );
			}
		} elseif ( is_numeric( $raw ) ) {
			$decoded = array( $raw );
		} else {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $decoded ) ) ) );
	}

	/**
	 * Current language code for cache scoping (WPML / Polylang), or 'all' in a
	 * language-neutral context.
	 *
	 * @return string
	 */
	private function current_lang_code() {
		$lang = apply_filters( 'wpml_current_language', null );
		if ( ! $lang && function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language();
		}
		return $lang ? (string) $lang : 'all';
	}

	/**
	 * Every language code a vendor-offers transient may have been cached under,
	 * for invalidation. Includes the 'all' (language-neutral) fallback.
	 *
	 * @return string[]
	 */
	private function active_lang_codes() {
		$codes = array();
		$wpml  = apply_filters( 'wpml_active_languages', null );
		if ( is_array( $wpml ) ) {
			$codes = array_keys( $wpml );
		}
		if ( function_exists( 'pll_languages_list' ) ) {
			$codes = array_merge( $codes, (array) pll_languages_list() );
		}
		$codes[] = 'all';
		return array_values( array_unique( array_filter( array_map( 'strval', $codes ) ) ) );
	}

	/**
	 * Delete the makesOffer transient for the given vendors across every cached
	 * language.
	 *
	 * @param int[] $vendor_ids
	 */
	private function invalidate_vendor_offer_cache( $vendor_ids ) {
		$langs = $this->active_lang_codes();
		foreach ( array_unique( array_map( 'intval', (array) $vendor_ids ) ) as $vid ) {
			if ( $vid <= 0 ) {
				continue;
			}
			foreach ( $langs as $lang ) {
				delete_transient( 'luwipress_vendor_offers_' . $vid . '_' . $lang );
			}
		}
	}

	/**
	 * Post-meta lifecycle listener — busts the makesOffer cache for the OLD and
	 * NEW vendor sets whenever `_lwp_vendor_ids` changes on a product through any
	 * write path. Hooked on update_post_meta / added_post_meta / delete_post_meta.
	 *
	 * @param int|int[] $meta_id    Unused (single id on add/update, array on delete).
	 * @param int       $object_id
	 * @param string    $meta_key
	 * @param mixed     $meta_value
	 */
	public function invalidate_vendor_offers_on_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id );
		if ( self::PRODUCT_VENDORS_META !== $meta_key ) {
			return;
		}
		$ids = $this->decode_vendor_ids( $meta_value );
		// On the BEFORE-write hooks (update_post_meta / delete_post_meta) the DB
		// still holds the prior value — union it so a de-attached vendor's cache
		// is busted too.
		$ids = array_merge( $ids, $this->decode_vendor_ids( get_post_meta( (int) $object_id, $meta_key, true ) ) );
		if ( ! empty( $ids ) ) {
			$this->invalidate_vendor_offer_cache( $ids );
		}
	}

	/* ─── END WC INTEGRATION ──────────────────────────────────────────── */

	/* ─── CONFIG HELPERS ──────────────────────────────────────────────── */

	public static function get_setting( $key, $fallback = null ) {
		$default = self::DEFAULTS[ $key ] ?? $fallback;
		$value   = get_option( self::OPTION_PREFIX . $key, $default );
		// Normalize the slug pattern on every read so a stray quote that crept
		// into the stored value (e.g. "%postname%'") can never leak out through
		// the admin UI, REST, or MCP — it was the trailing apostrophe, not a
		// "clipped leading %", that made the value look corrupted over MCP.
		if ( 'single_slug_pattern' === $key ) {
			$value = self::sanitize_slug_pattern( $value );
		}
		return $value;
	}

	/**
	 * Clamp the single-post slug pattern to a known, clean token. Strips stray
	 * surrounding/trailing quotes & backticks left behind by shell/JSON quoting
	 * accidents, then validates against the supported set. `%category%/%postname%`
	 * is intentionally NOT supported — per-group archive_slug is the canonical
	 * way to namespace vendor URLs (see LuwiPress_Vendors group bases).
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function sanitize_slug_pattern( $value ) {
		$value   = trim( (string) $value );
		$value   = trim( $value, "\"'` \t\n\r" );
		$allowed = array( '%postname%' );
		return in_array( $value, $allowed, true ) ? $value : '%postname%';
	}

	/**
	 * Effective Schema.org entity type for a vendor: the per-post override
	 * (_lwp_vendor_entity_type) when set to a valid value, else the site-global
	 * setting. Always clamped to organization|person|localbusiness (FR-019).
	 *
	 * @param int $vendor_id
	 * @return string
	 */
	public function resolve_entity_type( $vendor_id ) {
		$valid    = array( 'organization', 'person', 'localbusiness' );
		$override = (string) get_post_meta( (int) $vendor_id, self::ENTITY_TYPE_META, true );
		if ( in_array( $override, $valid, true ) ) {
			return $override;
		}
		$global = (string) self::get_setting( 'entity_type' );
		return in_array( $global, $valid, true ) ? $global : 'organization';
	}

	public static function get_all_settings() {
		$out = array();
		foreach ( self::DEFAULTS as $key => $default ) {
			$out[ $key ] = self::get_setting( $key );
		}
		return $out;
	}

	private function update_setting( $key, $value ) {
		update_option( self::OPTION_PREFIX . $key, $value );
	}

	/* ─── CPT REGISTRATION ────────────────────────────────────────────── */

	public function register_cpt() {
		if ( (int) self::get_setting( 'enabled' ) !== 1 ) {
			return;
		}

		$singular        = (string) self::get_setting( 'singular_label' );
		$plural          = (string) self::get_setting( 'plural_label' );
		$slug            = sanitize_title( (string) self::get_setting( 'archive_slug' ) ) ?: 'people';
		$with_front      = (int) self::get_setting( 'with_front' ) === 1;
		$archive_enabled = (int) self::get_setting( 'archive_enabled' ) === 1;

		$labels = array(
			'name'               => $plural,
			'singular_name'      => $singular,
			'menu_name'          => $plural,
			'name_admin_bar'     => $singular,
			'add_new'            => sprintf( __( 'Add %s', 'luwipress' ), $singular ),
			'add_new_item'       => sprintf( __( 'Add new %s', 'luwipress' ), $singular ),
			'new_item'           => sprintf( __( 'New %s', 'luwipress' ), $singular ),
			'edit_item'          => sprintf( __( 'Edit %s', 'luwipress' ), $singular ),
			'view_item'          => sprintf( __( 'View %s', 'luwipress' ), $singular ),
			'all_items'          => sprintf( __( 'All %s', 'luwipress' ), $plural ),
			'search_items'       => sprintf( __( 'Search %s', 'luwipress' ), $plural ),
			'not_found'          => sprintf( __( 'No %s found.', 'luwipress' ), strtolower( $plural ) ),
			'not_found_in_trash' => sprintf( __( 'No %s found in trash.', 'luwipress' ), strtolower( $plural ) ),
			'archives'           => sprintf( __( '%s archive', 'luwipress' ), $singular ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true,
			'rest_base'           => 'people',
			'menu_position'       => 22,
			'menu_icon'           => (string) self::get_setting( 'menu_icon' ),
			'capability_type'     => 'post',
			'has_archive'         => $archive_enabled ? $slug : false,
			'hierarchical'        => false,
			'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ),
			'rewrite'             => array(
				'slug'       => $slug,
				'with_front' => $with_front,
				'feeds'      => false,
			),
			'show_in_nav_menus'   => true,
			'taxonomies'          => array(),
		);

		register_post_type( self::POST_TYPE, apply_filters( 'luwipress_vendors_cpt_args', $args ) );

		// Vendor groups (3.5.7+) — one site can run multiple personnel
		// verticals side-by-side (e.g. "Luthiers" who make products +
		// "Team" who run the storefront). The CPT stays single — we
		// only add a flat, tag-style taxonomy so the same vendor pool
		// can be partitioned into named groups without doubling the
		// data model. Theme widgets + REST query both filter by this
		// taxonomy term to render a focused subset.
		$group_args = array(
			'labels'            => array(
				'name'              => __( 'Vendor Groups', 'luwipress' ),
				'singular_name'     => __( 'Vendor Group', 'luwipress' ),
				'all_items'         => __( 'All Groups', 'luwipress' ),
				'edit_item'         => __( 'Edit Group', 'luwipress' ),
				'view_item'         => __( 'View Group', 'luwipress' ),
				'update_item'       => __( 'Update Group', 'luwipress' ),
				'add_new_item'      => __( 'Add New Group', 'luwipress' ),
				'new_item_name'     => __( 'New Group', 'luwipress' ),
				'menu_name'         => __( 'Groups', 'luwipress' ),
				'search_items'      => __( 'Search Groups', 'luwipress' ),
				'not_found'         => __( 'No groups found.', 'luwipress' ),
			),
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_nav_menus' => true,
			'show_in_rest'      => true,
			'rest_base'         => 'vendor-groups',
			'hierarchical'      => false, // tag-style; flat is enough for partitioning
			'rewrite'           => array(
				'slug'         => $slug . '/group',
				'with_front'   => $with_front,
				'hierarchical' => false,
			),
		);
		register_taxonomy(
			self::TAXONOMY_GROUP,
			array( self::POST_TYPE ),
			apply_filters( 'luwipress_vendor_group_taxonomy_args', $group_args )
		);
	}

	public function register_meta_fields() {
		$base = array(
			'type'         => 'string',
			'single'       => true,
			'show_in_rest' => true,
			'auth_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		);
		foreach ( self::META_KEYS as $key => $meta_key ) {
			register_post_meta(
				self::POST_TYPE,
				$meta_key,
				array_merge( $base, array(
					'description'       => sprintf( 'LuwiPress Vendor — %s', $key ),
					'sanitize_callback' => $this->sanitizer_for( $key ),
				) )
			);
		}

		// Per-vendor entity_type override (FR-019) — kept OUT of META_KEYS (those
		// are free-text / url profile fields); this is an enum with its own
		// sanitizer. Settable via REST /vendors/{id}/meta, MCP meta_set, wp-cli.
		register_post_meta(
			self::POST_TYPE,
			self::ENTITY_TYPE_META,
			array_merge( $base, array(
				'description'       => 'LuwiPress Vendor — Schema.org @type override (organization|person|localbusiness; empty = inherit global)',
				'sanitize_callback' => function ( $value ) {
					$value = (string) $value;
					return in_array( $value, array( 'organization', 'person', 'localbusiness' ), true ) ? $value : '';
				},
			) )
		);

		// Canonical (primary) group for a vendor's permalink base (3.7.5).
		// Integer term_id of a lwp_vendor_group term; 0/empty = global default.
		register_post_meta(
			self::POST_TYPE,
			self::PRIMARY_GROUP_META,
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'description'       => 'LuwiPress Vendor — canonical group term_id driving the permalink base (0 = global archive_slug).',
				'sanitize_callback' => 'absint',
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		// Per-group archive slug (term meta on lwp_vendor_group).
		register_term_meta(
			self::TAXONOMY_GROUP,
			self::GROUP_SLUG_META,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'description'       => 'LuwiPress Vendor Group — URL base for this group (e.g. team, music-academy-teachers). Empty = vendors fall back to the global archive_slug.',
				'sanitize_callback' => 'sanitize_title',
				'auth_callback'     => function () {
					return current_user_can( 'manage_categories' );
				},
			)
		);
	}

	private function sanitizer_for( $key ) {
		// Social/website fields → URL sanitizer. Years → int. Rest → text.
		$url_keys  = array( 'facebook', 'instagram', 'youtube', 'soundcloud', 'linkedin', 'x', 'behance', 'website' );
		$int_keys  = array( 'years' );
		if ( in_array( $key, $url_keys, true ) ) {
			return function ( $value ) {
				$value = trim( (string) $value );
				if ( $value === '' ) {
					return '';
				}
				return esc_url_raw( $value );
			};
		}
		if ( in_array( $key, $int_keys, true ) ) {
			return function ( $value ) {
				return max( 0, absint( $value ) );
			};
		}
		if ( $key === 'quote' ) {
			return 'wp_kses_post';
		}
		return 'sanitize_text_field';
	}

	/* ─── PER-GROUP ARCHIVE SLUGS (3.7.5) ─────────────────────────────── */

	/**
	 * Map of [ base_slug => group_term_slug ] for every lwp_vendor_group term
	 * that defines an archive_slug. Cached 1h; busted on group save/delete.
	 *
	 * @return array<string,string>
	 */
	public function group_bases_map() {
		$cached = get_transient( self::TRANSIENT_GROUP_BASES );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$map   = array();
		$terms = get_terms( array(
			'taxonomy'   => self::TAXONOMY_GROUP,
			'hide_empty' => false,
		) );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( ! $t instanceof WP_Term ) {
					continue;
				}
				$base = sanitize_title( (string) get_term_meta( $t->term_id, self::GROUP_SLUG_META, true ) );
				if ( $base !== '' ) {
					$map[ $base ] = $t->slug;
				}
			}
		}
		set_transient( self::TRANSIENT_GROUP_BASES, $map, HOUR_IN_SECONDS );
		return $map;
	}

	/** Bust the group-bases cache (group term save/delete). */
	public function bust_group_bases_cache() {
		delete_transient( self::TRANSIENT_GROUP_BASES );
	}

	/**
	 * Register dynamic rewrite rules for each group base:
	 *   /<base>/<vendor>/  → single vendor
	 *   /<base>/           → that group's term archive
	 * Runs on init p7 (after register_cpt p5). Rules are added to the in-memory
	 * rewrite set every load; a flush (on group save) persists them so request
	 * matching actually uses them.
	 */
	public function register_group_rewrites() {
		if ( (int) self::get_setting( 'enabled' ) !== 1 ) {
			return;
		}
		foreach ( $this->group_bases_map() as $base => $term_slug ) {
			// Slugs come from sanitize_title (regex-safe). Single first, then archive.
			add_rewrite_rule(
				'^' . $base . '/([^/]+)/?$',
				'index.php?' . self::POST_TYPE . '=$matches[1]',
				'top'
			);
			add_rewrite_rule(
				'^' . $base . '/?$',
				'index.php?' . self::TAXONOMY_GROUP . '=' . $term_slug,
				'top'
			);
		}
	}

	/**
	 * Rewrite a vendor's permalink to its primary group's base. Falls back to
	 * the global archive_slug when no primary group is set, so the existing
	 * vendor pool keeps its current /<archive_slug>/<name>/ URLs (zero breakage).
	 *
	 * @param string  $post_link
	 * @param WP_Post $post
	 * @return string
	 */
	public function filter_vendor_permalink( $post_link, $post ) {
		if ( ! $post instanceof WP_Post || $post->post_type !== self::POST_TYPE ) {
			return $post_link;
		}
		$base = $this->primary_group_base( $post->ID );
		if ( $base === '' ) {
			return $post_link;
		}
		$global = sanitize_title( (string) self::get_setting( 'archive_slug' ) ) ?: 'people';
		if ( $base === $global ) {
			return $post_link;
		}
		// Swap the first /<global>/ path segment for /<base>/.
		$swapped = preg_replace( '#/' . preg_quote( $global, '#' ) . '/#', '/' . $base . '/', (string) $post_link, 1 );
		return is_string( $swapped ) ? $swapped : $post_link;
	}

	/** Resolve a vendor's primary-group base slug, or '' for the global default. */
	public function primary_group_base( $vendor_id ) {
		$term_id = (int) get_post_meta( (int) $vendor_id, self::PRIMARY_GROUP_META, true );
		if ( $term_id <= 0 ) {
			return '';
		}
		return sanitize_title( (string) get_term_meta( $term_id, self::GROUP_SLUG_META, true ) );
	}

	/**
	 * Slug Resolver integration: append every group base to the resolver's skip
	 * list so /team/<vendor>/ (etc.) is never auto-301'd to the global archive.
	 * Keeps the core resolver generic — the vendor module owns the base list.
	 *
	 * @param string[] $slugs
	 * @return string[]
	 */
	public function resolver_skip_group_bases( $slugs ) {
		if ( ! is_array( $slugs ) ) {
			$slugs = array();
		}
		foreach ( array_keys( $this->group_bases_map() ) as $base ) {
			$slugs[] = $base;
		}
		return $slugs;
	}

	/* ─── GROUP TERM archive_slug FIELD ───────────────────────────────── */

	/** Group "Add new" screen: archive_slug field. */
	public function group_archive_slug_add_field() {
		?>
		<div class="form-field term-lwp-archive-slug-wrap">
			<label for="lwp_group_archive_slug"><?php esc_html_e( 'Archive slug (URL base)', 'luwipress' ); ?></label>
			<input type="text" name="lwp_group_archive_slug" id="lwp_group_archive_slug" value="" />
			<p><?php esc_html_e( 'Optional. Gives this group its own URL base, e.g. "team" → /team/<vendor>/. Leave empty to use the global Vendors archive slug.', 'luwipress' ); ?></p>
		</div>
		<?php
	}

	/** Group "Edit" screen: archive_slug field. */
	public function group_archive_slug_edit_field( $term, $taxonomy ) {
		$val = (string) get_term_meta( $term->term_id, self::GROUP_SLUG_META, true );
		?>
		<tr class="form-field term-lwp-archive-slug-wrap">
			<th scope="row"><label for="lwp_group_archive_slug"><?php esc_html_e( 'Archive slug (URL base)', 'luwipress' ); ?></label></th>
			<td>
				<input type="text" name="lwp_group_archive_slug" id="lwp_group_archive_slug" value="<?php echo esc_attr( $val ); ?>" />
				<p class="description"><?php esc_html_e( 'Gives this group its own URL base, e.g. "team" → /team/<vendor>/. Empty = global Vendors archive slug. Vendors point here via "Primary group" on the vendor edit screen.', 'luwipress' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save the group archive_slug term meta + flush rewrites so the new base
	 * resolves. Fires inside WP's nonce-checked term-edit flow; we cap-check and
	 * sanitize the field. Only flushes when the value actually changed.
	 *
	 * @param int $term_id
	 */
	public function save_group_archive_slug( $term_id ) {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		if ( ! isset( $_POST['lwp_group_archive_slug'] ) ) {
			return;
		}
		$raw  = sanitize_title( (string) wp_unslash( $_POST['lwp_group_archive_slug'] ) );
		$prev = (string) get_term_meta( $term_id, self::GROUP_SLUG_META, true );
		if ( $raw === '' ) {
			delete_term_meta( $term_id, self::GROUP_SLUG_META );
		} else {
			update_term_meta( $term_id, self::GROUP_SLUG_META, $raw );
		}
		$this->bust_group_bases_cache();
		if ( $raw !== $prev ) {
			$this->register_group_rewrites();
			flush_rewrite_rules( false );
		}
	}

	/* ─── VENDOR _primary_group METABOX ───────────────────────────────── */

	/** Vendor edit: "Primary group" metabox (canonical permalink base). */
	public function add_primary_group_metabox() {
		add_meta_box(
			'lwp_vendor_primary_group',
			__( 'Primary group (URL base)', 'luwipress' ),
			array( $this, 'render_primary_group_metabox' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	public function render_primary_group_metabox( $post ) {
		$current = (int) get_post_meta( $post->ID, self::PRIMARY_GROUP_META, true );
		$terms   = get_the_terms( $post->ID, self::TAXONOMY_GROUP );
		wp_nonce_field( 'lwp_primary_group_save', 'lwp_primary_group_nonce' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			echo '<p>' . esc_html__( 'Assign this vendor to a group (in the Groups box) to pick a URL base. Until then the global Vendors archive slug is used.', 'luwipress' ) . '</p>';
			return;
		}
		echo '<select name="lwp_primary_group" style="width:100%;">';
		echo '<option value="0">' . esc_html__( '— Global default —', 'luwipress' ) . '</option>';
		foreach ( $terms as $t ) {
			$base  = sanitize_title( (string) get_term_meta( $t->term_id, self::GROUP_SLUG_META, true ) );
			$label = $base !== '' ? $t->name . ' (/' . $base . '/)' : $t->name . ' — ' . __( 'no base set', 'luwipress' );
			printf(
				'<option value="%d" %s%s>%s</option>',
				(int) $t->term_id,
				selected( $current, (int) $t->term_id, false ),
				$base === '' ? ' disabled' : '',
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Canonical URL base for this vendor. Groups without an archive slug are disabled here.', 'luwipress' ) . '</p>';
	}

	/**
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public function save_primary_group( $post_id, $post ) {
		if ( ! isset( $_POST['lwp_primary_group_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['lwp_primary_group_nonce'] ) ), 'lwp_primary_group_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$term_id = isset( $_POST['lwp_primary_group'] ) ? absint( $_POST['lwp_primary_group'] ) : 0;
		if ( $term_id > 0 ) {
			update_post_meta( $post_id, self::PRIMARY_GROUP_META, $term_id );
		} else {
			delete_post_meta( $post_id, self::PRIMARY_GROUP_META );
		}
	}

	/* ─── REST ENDPOINTS ──────────────────────────────────────────────── */

	public function register_rest_endpoints() {
		$ns = 'luwipress/v1';

		register_rest_route( $ns, '/vendors/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_settings' ),
				'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_update_settings' ),
				'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			),
		) );

		register_rest_route( $ns, '/vendors', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_list' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'limit'   => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ),
				'orderby' => array( 'type' => 'string',  'default' => 'menu_order' ),
				'order'   => array( 'type' => 'string',  'default' => 'ASC' ),
				'group'   => array( 'type' => 'string',  'default' => '' ),
			),
		) );

		register_rest_route( $ns, '/vendors/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_get_one' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/vendors/(?P<id>\d+)/meta', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_set_meta' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );

		register_rest_route( $ns, '/vendors/sync-rewrite', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_flush_rewrite' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );
	}

	public function rest_get_settings() {
		return rest_ensure_response( array(
			'settings' => self::get_all_settings(),
			'post_type'=> self::POST_TYPE,
			'count'    => wp_count_posts( self::POST_TYPE )->publish ?? 0,
		) );
	}

	public function rest_update_settings( WP_REST_Request $req ) {
		$params = $req->get_json_params() ?: $req->get_params();
		$updated = array();
		$slug_changed = false;

		foreach ( self::DEFAULTS as $key => $default ) {
			if ( ! array_key_exists( $key, $params ) ) {
				continue;
			}
			$value = $params[ $key ];
			if ( $key === 'archive_slug' ) {
				$value = sanitize_title( (string) $value ) ?: 'people';
				if ( $value !== self::get_setting( 'archive_slug' ) ) {
					$slug_changed = true;
				}
			} elseif ( $key === 'legacy_redirects' ) {
				// Accept array of {from,to} pairs OR JSON string. Store as JSON.
				if ( is_array( $value ) ) {
					$pairs = array();
					foreach ( $value as $pair ) {
						if ( ! is_array( $pair ) ) continue;
						$from = isset( $pair['from'] ) ? sanitize_text_field( $pair['from'] ) : '';
						$to   = isset( $pair['to'] )   ? esc_url_raw( $pair['to'] )         : '';
						if ( $from && $to ) {
							$pairs[] = array( 'from' => $from, 'to' => $to );
						}
					}
					$value = wp_json_encode( $pairs );
				} else {
					// Assume JSON string — validate by decode + re-encode
					$decoded = json_decode( (string) $value, true );
					$value   = is_array( $decoded ) ? wp_json_encode( $decoded ) : '';
				}
			} elseif ( $key === 'single_slug_pattern' ) {
				$value = self::sanitize_slug_pattern( $value );
			} elseif ( $key === 'default_occupation' || $key === 'entity_type' || $key === 'menu_icon' ) {
				$value = sanitize_text_field( (string) $value );
			} elseif ( is_int( $default ) ) {
				$value = (int) (bool) $value;
			} else {
				$value = sanitize_text_field( (string) $value );
			}
			$this->update_setting( $key, $value );
			$updated[ $key ] = $value;
		}

		if ( $slug_changed ) {
			$this->register_cpt();
			flush_rewrite_rules( false );
		}

		return rest_ensure_response( array(
			'status'        => 'updated',
			'updated'       => $updated,
			'slug_changed'  => $slug_changed,
			'all_settings'  => self::get_all_settings(),
		) );
	}

	public function rest_list( WP_REST_Request $req ) {
		$limit   = (int) $req->get_param( 'limit' );
		$orderby = sanitize_key( $req->get_param( 'orderby' ) );
		$order   = strtoupper( sanitize_key( $req->get_param( 'order' ) ) ) === 'DESC' ? 'DESC' : 'ASC';
		$group   = $req->get_param( 'group' );

		$args = array(
			'post_type'      => self::POST_TYPE,
			'posts_per_page' => $limit,
			'post_status'    => 'publish',
			'orderby'        => $orderby,
			'order'          => $order,
		);

		// Vendor group filter (3.5.7+) — accepts term slug, term ID, or
		// comma-separated list of either. Lets callers pull just the
		// "Team" subset, just "Luthiers", etc. without an N+1 fetch.
		if ( ! empty( $group ) ) {
			$terms_in = array_filter( array_map( 'trim', explode( ',', (string) $group ) ) );
			if ( ! empty( $terms_in ) ) {
				$field = ctype_digit( (string) $terms_in[0] ) ? 'term_id' : 'slug';
				if ( $field === 'term_id' ) {
					$terms_in = array_map( 'absint', $terms_in );
				}
				$args['tax_query'] = array(
					array(
						'taxonomy' => self::TAXONOMY_GROUP,
						'field'    => $field,
						'terms'    => $terms_in,
					),
				);
			}
		}

		$q = new WP_Query( $args );

		$out = array();
		foreach ( $q->posts as $p ) {
			$out[] = $this->shape_vendor( $p );
		}

		return rest_ensure_response( array(
			'count'  => count( $out ),
			'total'  => (int) $q->found_posts,
			'people' => $out,
		) );
	}

	public function rest_get_one( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		$p  = get_post( $id );
		if ( ! $p || $p->post_type !== self::POST_TYPE ) {
			return new WP_Error( 'not_found', 'Vendor not found', array( 'status' => 404 ) );
		}
		return rest_ensure_response( array(
			'vendor' => $this->shape_vendor( $p ),
			'schema' => $this->build_vendor_schema( $p->ID ),
		) );
	}

	public function rest_set_meta( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		$p  = get_post( $id );
		if ( ! $p || $p->post_type !== self::POST_TYPE ) {
			return new WP_Error( 'not_found', 'Vendor not found', array( 'status' => 404 ) );
		}
		$params  = $req->get_json_params() ?: $req->get_params();
		$updated = array();
		foreach ( self::META_KEYS as $short => $meta_key ) {
			if ( ! array_key_exists( $short, $params ) ) {
				continue;
			}
			$sanitizer = $this->sanitizer_for( $short );
			$value     = call_user_func( $sanitizer, $params[ $short ] );
			update_post_meta( $id, $meta_key, $value );
			$updated[ $short ] = $value;
		}
		// Per-vendor entity_type override (FR-019) — not part of META_KEYS.
		if ( array_key_exists( 'entity_type', $params ) ) {
			$etype = (string) $params['entity_type'];
			if ( in_array( $etype, array( 'organization', 'person', 'localbusiness' ), true ) ) {
				update_post_meta( $id, self::ENTITY_TYPE_META, $etype );
				$updated['entity_type'] = $etype;
			} else {
				delete_post_meta( $id, self::ENTITY_TYPE_META );
				$updated['entity_type'] = '';
			}
		}
		return rest_ensure_response( array(
			'status'  => 'updated',
			'id'      => $id,
			'updated' => $updated,
		) );
	}

	public function rest_flush_rewrite() {
		$this->register_cpt();
		flush_rewrite_rules( false );
		return rest_ensure_response( array( 'status' => 'flushed' ) );
	}

	/* ─── DATA SHAPERS ────────────────────────────────────────────────── */

	public function shape_vendor( WP_Post $p ) {
		$meta = array();
		foreach ( self::META_KEYS as $short => $meta_key ) {
			$meta[ $short ] = get_post_meta( $p->ID, $meta_key, true );
		}

		// Vendor groups (3.5.7+) — emit each attached term as
		// {slug, name} pairs so UIs can render filter pills + widgets
		// can route by slug without a second round trip.
		$group_terms = get_the_terms( $p->ID, self::TAXONOMY_GROUP );
		$groups      = array();
		if ( is_array( $group_terms ) ) {
			foreach ( $group_terms as $t ) {
				$groups[] = array(
					'term_id' => (int) $t->term_id,
					'slug'    => $t->slug,
					'name'    => $t->name,
				);
			}
		}

		return array(
			'id'        => $p->ID,
			'title'     => get_the_title( $p ),
			'slug'      => $p->post_name,
			'link'      => get_permalink( $p ),
			'excerpt'   => $p->post_excerpt,
			'image'     => get_the_post_thumbnail_url( $p, 'large' ) ?: '',
			'meta'      => $meta,
			'groups'    => $groups,
		);
	}

	/* ─── SCHEMA REGISTRY INTEGRATION ─────────────────────────────────── */

	public function register_vendor_schema( $registry ) {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register_type' ) ) {
			return;
		}
		$registry->register_type( 'person', array(
			'schema_type' => 'Person',
			'meta_key'    => '_lwp_vendor_schema_override', // optional manual override
			'contexts'    => array( 'post:' . self::POST_TYPE ),
			'sanitizer'   => function ( $data ) {
				// Manual override is a free-form schema.org Person/Organization array.
				// Recursive URL + kses preserve.
				return $this->kses_preserve( $data );
			},
			'renderer'    => function ( $data, $ctx ) {
				// $data is either the stored manual override OR the auto_data
				// payload (which is already a fully-built schema array). Either
				// way we just return it as-is — the Schema Registry handles JSON
				// encoding + script wrapping.
				return $data;
			},
			'auto_data'   => function ( $ctx ) {
				// Build the Person / Organization / LocalBusiness schema from
				// CPT meta. Returned non-empty so the registry's empty-check
				// passes and the renderer fires (registry skips emission when
				// auto_data + stored data are both empty).
				$id = (int) ( $ctx['object_id'] ?? 0 );
				$schema = $this->build_vendor_schema( $id );
				return is_array( $schema ) ? $schema : array();
			},
			'description' => 'Vendor / Person / Organization profile (E-E-A-T) — auto-built from CPT meta',
		) );
	}

	private function kses_preserve( $node ) {
		if ( is_array( $node ) ) {
			return array_map( array( $this, 'kses_preserve' ), $node );
		}
		if ( is_string( $node ) ) {
			if ( preg_match( '#^https?://#i', trim( $node ) ) ) {
				return esc_url_raw( $node );
			}
			return wp_kses_post( $node );
		}
		return $node;
	}

	/**
	 * Product IDs a vendor makes — the inverse of the `_lwp_vendor_ids` product
	 * meta. Powers the vendor's makesOffer schema node. Capped, cached per
	 * (vendor, language), and de-duplicated across WPML language siblings so a
	 * language-neutral context (REST /vendors/{id}) doesn't list a product once
	 * per language.
	 *
	 * @param int $vendor_id
	 * @param int $limit
	 * @return int[]
	 */
	private function get_vendor_product_ids( $vendor_id, $limit = 50 ) {
		$vendor_id = (int) $vendor_id;
		if ( $vendor_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$lang      = $this->current_lang_code();
		$cache_key = 'luwipress_vendor_offers_' . $vendor_id . '_' . $lang;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// JSON-array-element-aware REGEXP — tolerates quoted "36633" and legacy
		// bare 36633, and the boundaries stop 36633 false-matching 366331.
		$regex = '(\\[|,)[[:space:]]*"?' . preg_quote( (string) $vendor_id, '/' ) . '"?[[:space:]]*(,|\\])';

		$q = new WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => self::PRODUCT_VENDORS_META,
					'value'   => $regex,
					'compare' => 'REGEXP',
				),
			),
		) );
		$ids = array_map( 'intval', (array) $q->posts );

		// Collapse WPML language siblings to one canonical id per trid (no-op
		// when WPML is inactive or the query already returned one language).
		if ( count( $ids ) > 1 ) {
			$seen    = array();
			$deduped = array();
			foreach ( $ids as $pid ) {
				$trid = apply_filters( 'wpml_element_trid', null, $pid, 'post_product' );
				if ( $trid ) {
					if ( isset( $seen[ $trid ] ) ) {
						continue;
					}
					$seen[ $trid ] = true;
				}
				$deduped[] = $pid;
			}
			$ids = $deduped;
		}

		set_transient( $cache_key, $ids, HOUR_IN_SECONDS );
		return $ids;
	}

	public function build_vendor_schema( $vendor_id ) {
		$p = get_post( $vendor_id );
		if ( ! $p || $p->post_type !== self::POST_TYPE ) {
			return null;
		}

		$entity_type = $this->resolve_entity_type( $vendor_id );

		$schema_type_map = array(
			'organization'  => 'Organization',
			'person'        => 'Person',
			'localbusiness' => 'LocalBusiness',
		);

		$same_as = array();
		foreach ( array( 'facebook', 'instagram', 'youtube', 'soundcloud', 'linkedin', 'x', 'behance', 'website' ) as $key ) {
			$url = get_post_meta( $p->ID, self::META_KEYS[ $key ], true );
			if ( $url ) {
				$same_as[] = $url;
			}
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => $schema_type_map[ $entity_type ],
			'name'     => get_the_title( $p ),
			'url'      => esc_url_raw( get_permalink( $p ) ),
		);

		$image = get_the_post_thumbnail_url( $p, 'full' );
		if ( $image ) {
			$schema['image'] = esc_url_raw( $image );
		}

		$desc = $p->post_excerpt ?: wp_strip_all_tags( wp_trim_words( $p->post_content, 40, '' ) );
		if ( $desc ) {
			$schema['description'] = $desc;
		}

		$location   = get_post_meta( $p->ID, self::META_KEYS['location'],   true );
		$occupation = get_post_meta( $p->ID, self::META_KEYS['occupation'], true );
		$specialty  = get_post_meta( $p->ID, self::META_KEYS['specialty'],  true );
		$years      = (int) get_post_meta( $p->ID, self::META_KEYS['years'], true );
		$website    = get_post_meta( $p->ID, self::META_KEYS['website'],    true );

		// Person-specific fields.
		if ( $entity_type === 'person' ) {
			if ( $location ) {
				$schema['homeLocation'] = array( '@type' => 'Place', 'name' => $location );
			}
			if ( $occupation || $specialty ) {
				$schema['jobTitle'] = trim( $occupation ?: $specialty );
			}
			if ( $years > 0 ) {
				$schema['hasOccupation'] = array(
					'@type'                  => 'Occupation',
					'name'                   => $occupation ?: $specialty,
					'experienceRequirements' => array(
						'@type'              => 'OccupationalExperienceRequirements',
						'monthsOfExperience' => $years * 12,
					),
				);
			}

			// worksFor — link the person to the store as an Organization. Inline
			// + self-contained (name + url) so the node is always valid (never a
			// dangling @id reference); the `luwipress_vendor_works_for` filter
			// lets operators point it at a specific org or suppress it (return an
			// empty array / non-array).
			$works_for = apply_filters(
				'luwipress_vendor_works_for',
				array(
					'@type' => 'Organization',
					'name'  => get_bloginfo( 'name' ),
					'url'   => esc_url_raw( home_url( '/' ) ),
				),
				$vendor_id,
				$p
			);
			if ( is_array( $works_for ) && ! empty( $works_for ) ) {
				$schema['worksFor'] = $works_for;
			}
		}

		// Organization / LocalBusiness-specific fields.
		if ( $entity_type === 'organization' || $entity_type === 'localbusiness' ) {
			if ( $location ) {
				if ( $entity_type === 'localbusiness' ) {
					$schema['address'] = array( '@type' => 'PostalAddress', 'addressLocality' => $location );
				} else {
					$schema['location'] = array( '@type' => 'Place', 'name' => $location );
				}
			}
			if ( $specialty ) {
				$schema['knowsAbout'] = array_values( array_filter( array_map( 'trim', preg_split( '/[,·•|]/', $specialty ) ) ) );
			}
			if ( $occupation ) {
				$schema['slogan'] = $occupation; // org tagline
			}
			if ( $years > 0 ) {
				// Best-effort founding year from years_active (vendor longevity).
				$schema['foundingDate'] = (string) ( (int) gmdate( 'Y' ) - $years );
			}
		}

		// Universal: knowsAbout for any entity type (Person above also sets it via the array form when specialty exists).
		if ( $entity_type === 'person' && $specialty ) {
			$schema['knowsAbout'] = array_values( array_filter( array_map( 'trim', preg_split( '/[,·•|]/', $specialty ) ) ) );
		}

		if ( ! empty( $same_as ) ) {
			$schema['sameAs'] = $same_as;
		}

		// makesOffer — the products this vendor makes (inverse of _lwp_vendor_ids).
		// Valid on Person, Organization and LocalBusiness. Compact Offer nodes;
		// price/priceCurrency/availability filled from WooCommerce when known.
		$offer_pids = $this->get_vendor_product_ids( $vendor_id, 50 );
		if ( ! empty( $offer_pids ) && function_exists( 'wc_get_product' ) ) {
			$offers = array();
			foreach ( $offer_pids as $pid ) {
				$wc = wc_get_product( $pid );
				if ( ! is_object( $wc ) || ! method_exists( $wc, 'get_id' ) ) {
					continue;
				}
				$url   = esc_url_raw( get_permalink( $pid ) );
				$offer = array(
					'@type'       => 'Offer',
					'url'         => $url,
					'itemOffered' => array(
						'@type' => 'Product',
						'name'  => get_the_title( $pid ),
						'url'   => $url,
					),
				);
				$price = method_exists( $wc, 'get_price' ) ? $wc->get_price() : null;
				if ( '' !== $price && null !== $price && is_numeric( $price ) ) {
					$offer['price']         = (string) $price;
					$offer['priceCurrency'] = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
					if ( method_exists( $wc, 'is_in_stock' ) ) {
						$offer['availability'] = $wc->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
					}
				}
				$offers[] = $offer;
			}
			if ( ! empty( $offers ) ) {
				$schema['makesOffer'] = $offers;
			}
		}

		return $schema;
	}

	/* ─── SLUG CHANGE HOOK ────────────────────────────────────────────── */

	public function on_slug_change( $old, $new ) {
		// Re-register CPT with new slug + flush rewrite next page load.
		$this->register_cpt();
		flush_rewrite_rules( false );
	}

	/* ─── LEGACY URL REDIRECTS ────────────────────────────────────────── */

	/**
	 * Operator-defined legacy redirect pairs. Stored as JSON array of
	 * { from, to } objects, e.g. [{ "from": "/masters/", "to": "/luthiers/" }].
	 * Runs at template_redirect priority 1 — before WP picks 404, so any
	 * old indexed URLs land on the new permalink space cleanly.
	 */
	public function maybe_redirect_legacy() {
		$raw = (string) self::get_setting( 'legacy_redirects' );
		if ( ! $raw ) {
			return;
		}
		$pairs = json_decode( $raw, true );
		if ( ! is_array( $pairs ) || empty( $pairs ) ) {
			return;
		}
		$current = trim( strtok( $_SERVER['REQUEST_URI'] ?? '', '?' ) );
		if ( ! $current ) {
			return;
		}
		// Normalize trailing slash for matching
		$current_norm = '/' . trim( $current, '/' ) . '/';
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || empty( $pair['from'] ) || empty( $pair['to'] ) ) {
				continue;
			}
			$from = '/' . trim( (string) $pair['from'], '/' ) . '/';
			$to   = $pair['to'];
			// Exact match
			if ( $current_norm === $from ) {
				wp_safe_redirect( esc_url_raw( $to ), 301 );
				exit;
			}
			// Prefix-with-tail match: /old/foo/ → /new/foo/
			if ( strlen( $from ) > 1 && strpos( $current_norm, $from ) === 0 ) {
				$tail   = substr( $current_norm, strlen( $from ) );
				$target = rtrim( $to, '/' ) . '/' . ltrim( $tail, '/' );
				wp_safe_redirect( esc_url_raw( $target ), 301 );
				exit;
			}
		}
	}

	/* ─── WP PERMALINKS PAGE INTEGRATION ──────────────────────────────── */

	/**
	 * Add a section to WP Settings → Permalinks that points operators to
	 * LuwiPress → Vendors for CPT slug + redirect configuration. Improves
	 * discoverability for operators who instinctively go to native settings.
	 */
	public function register_permalinks_page_notice() {
		add_settings_section(
			'luwipress_vendors_permalinks_section',
			__( 'LuwiPress Vendors — Custom Post Type permalinks', 'luwipress' ),
			array( $this, 'render_permalinks_page_notice' ),
			'permalink'
		);
	}

	public function render_permalinks_page_notice() {
		$singular = esc_html( self::get_setting( 'singular_label' ) );
		$plural   = esc_html( self::get_setting( 'plural_label' ) );
		$slug     = esc_html( self::get_setting( 'archive_slug' ) );
		$url      = esc_url( admin_url( 'admin.php?page=luwipress-settings#people' ) );
		echo '<p>' . sprintf(
			/* translators: 1: plural label, 2: archive slug, 3: settings URL */
			esc_html__( 'The %1$s custom post type uses the archive base /%2$s/. To rename it, change labels, toggle social-link fields, or add legacy URL redirects, manage it in %3$s.', 'luwipress' ),
			'<strong>' . $plural . '</strong>',
			$slug,
			'<a href="' . $url . '">LuwiPress → Vendors</a>'
		) . '</p>';
	}
}
