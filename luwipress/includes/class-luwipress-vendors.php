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

	const POST_TYPE     = 'lwp_vendor';
	const OPTION_PREFIX = 'luwipress_vendors_';

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

		// WooCommerce integration (loaded only when WC is active)
		add_action( 'woocommerce_init', array( $this, 'init_woocommerce_integration' ) );
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

		// PDP frontend: render "Made by" line in the product summary.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_vendor_line' ), 25 );

		// Product Schema.org: inject manufacturer / author linking to vendor URL.
		add_filter( 'woocommerce_structured_data_product', array( $this, 'enrich_product_schema_with_vendor' ), 10, 2 );
	}

	const PRODUCT_VENDORS_META = '_lwp_vendor_ids';

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
	 * Get the vendor posts attached to a WC product.
	 *
	 * @param int $product_id
	 * @return array Array of [id, title, link, image, location, specialty]
	 */
	public function get_product_vendors( $product_id ) {
		$raw = get_post_meta( $product_id, self::PRODUCT_VENDORS_META, true );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return array();
		}
		$ids = (array) json_decode( $raw, true );
		$ids = array_filter( array_map( 'intval', $ids ) );
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
				'location'  => (string) get_post_meta( $p->ID, self::META_KEYS['location'],  true ),
				'specialty' => (string) get_post_meta( $p->ID, self::META_KEYS['specialty'], true ),
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
	 * Add `manufacturer` (or `author` for entity_type=person) to the
	 * WC Product Schema.org payload — strong vendor attribution signal
	 * for Google product search.
	 */
	public function enrich_product_schema_with_vendor( $markup, $product ) {
		// Duck-type — WC passes a WC_Product instance via the woocommerce_structured_data_product filter.
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $markup;
		}
		$vendors = $this->get_product_vendors( $product->get_id() );
		if ( empty( $vendors ) ) {
			return $markup;
		}

		$entity_type = (string) self::get_setting( 'entity_type' );
		$schema_type_map = array(
			'organization'  => 'Organization',
			'person'        => 'Person',
			'localbusiness' => 'LocalBusiness',
		);
		$type = $schema_type_map[ $entity_type ] ?? 'Organization';
		$schema_field = $entity_type === 'person' ? 'author' : 'manufacturer';

		// Single vendor — emit one object. Multiple → array.
		$vendor_payloads = array_map( function ( $v ) use ( $type ) {
			$payload = array(
				'@type' => $type,
				'name'  => $v['title'],
				'url'   => $v['link'],
			);
			if ( ! empty( $v['image'] ) ) {
				$payload['image'] = $v['image'];
			}
			return $payload;
		}, $vendors );

		$markup[ $schema_field ] = count( $vendor_payloads ) === 1 ? $vendor_payloads[0] : $vendor_payloads;
		return $markup;
	}

	/* ─── END WC INTEGRATION ──────────────────────────────────────────── */

	/* ─── CONFIG HELPERS ──────────────────────────────────────────────── */

	public static function get_setting( $key, $fallback = null ) {
		$default = self::DEFAULTS[ $key ] ?? $fallback;
		return get_option( self::OPTION_PREFIX . $key, $default );
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
			} elseif ( $key === 'single_slug_pattern' || $key === 'default_occupation' || $key === 'entity_type' || $key === 'menu_icon' ) {
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

		$q = new WP_Query( array(
			'post_type'      => self::POST_TYPE,
			'posts_per_page' => $limit,
			'post_status'    => 'publish',
			'orderby'        => $orderby,
			'order'          => $order,
		) );

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
		return array(
			'id'        => $p->ID,
			'title'     => get_the_title( $p ),
			'slug'      => $p->post_name,
			'link'      => get_permalink( $p ),
			'excerpt'   => $p->post_excerpt,
			'image'     => get_the_post_thumbnail_url( $p, 'large' ) ?: '',
			'meta'      => $meta,
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

	public function build_vendor_schema( $vendor_id ) {
		$p = get_post( $vendor_id );
		if ( ! $p || $p->post_type !== self::POST_TYPE ) {
			return null;
		}

		$entity_type = (string) self::get_setting( 'entity_type' );
		if ( ! in_array( $entity_type, array( 'organization', 'person', 'localbusiness' ), true ) ) {
			$entity_type = 'organization';
		}

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
			'url'      => get_permalink( $p ),
		);

		$image = get_the_post_thumbnail_url( $p, 'full' );
		if ( $image ) {
			$schema['image'] = $image;
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
