<?php
/**
 * LuwiPress Events — CPT Engine preset #2.
 *
 * Promotes "events" from a manual Schema Registry payload (`_luwipress_schema_event`)
 * to a first-class, engine-described custom post type (`lwp_event`) with structured
 * fields, a vendor relationship (organizer / performer → `lwp_vendor`), ICS calendar
 * export, and Schema.org Event JSON-LD that reuses the existing Schema Registry
 * `event` type's renderer.
 *
 * One module, many verticals: a music store schedules concerts + workshops, an
 * academy runs classes, a gallery hosts openings. The post type stays `lwp_event`
 * (stable identifier); the rewrite slug + UI labels are configurable per site.
 *
 * DORMANT BY DEFAULT (enabled = 0). Nothing registers until an operator turns it
 * on (Settings page / REST `POST /events/settings {enabled:1}` / MCP
 * `event_settings_set`) — so an upgrade never adds a surprise Events menu, CPT,
 * archive or rewrite-flush to the install base. Mirrors LuwiPress_Vendors
 * (preset #1) structure 1:1.
 *
 * REST surface (luwipress/v1):
 *   GET  /events/settings      — read config
 *   POST /events/settings      — update config (enable, slug, labels)
 *   GET  /events               — list events
 *   GET  /events/{id}          — single event + Event schema preview
 *   POST /events/{id}/meta     — write event meta fields
 *   GET  /events/{id}/ics      — ICS text + download URL
 *   POST /events/sync-rewrite  — flush rewrite rules
 *
 * @package LuwiPress
 * @since   3.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Events {

	const POST_TYPE     = 'lwp_event';
	const TAXONOMY      = 'lwp_event_category';
	const OPTION_PREFIX = 'luwipress_events_';

	/** Default config. Each value lives under option key `luwipress_events_<key>`. */
	const DEFAULTS = array(
		'enabled'          => 0, // DORMANT until the operator turns it on.
		'archive_slug'     => 'events',
		'singular_label'   => 'Event',
		'plural_label'     => 'Events',
		'menu_icon'        => 'dashicons-calendar-alt',
		'with_front'       => 0,
		'archive_enabled'  => 1,
		'category_enabled' => 1, // register the flat lwp_event_category taxonomy
		// Field toggles (drive the metabox UI).
		'show_venue'       => 1,
		'show_online'      => 1,
		'show_offers'      => 1,
		'show_organizer'   => 1,
		'show_performer'   => 1,
	);

	/**
	 * Meta keys. Prefixed `_lwp_event_` to scope under our module and stay hidden
	 * from the Custom Fields metabox.
	 */
	const META_KEYS = array(
		'start'         => '_lwp_event_start',          // ISO 8601 (date or datetime) — required for schema
		'end'           => '_lwp_event_end',            // ISO 8601, optional
		'status'        => '_lwp_event_status',         // EventScheduled|EventCancelled|EventPostponed|EventRescheduled
		'attendance'    => '_lwp_event_attendance',     // offline|online|mixed
		'venue_name'    => '_lwp_event_venue_name',     // translatable
		'venue_address' => '_lwp_event_venue_address',  // translatable
		'online_url'    => '_lwp_event_online_url',
		'ticket_url'    => '_lwp_event_ticket_url',
		'price'         => '_lwp_event_price',
		'currency'      => '_lwp_event_currency',
	);

	const ORGANIZER_META = '_lwp_event_organizer_ids'; // JSON array of lwp_vendor IDs
	const PERFORMER_META = '_lwp_event_performer_ids'; // JSON array of lwp_vendor IDs

	const STATUS_VALUES     = array( 'EventScheduled', 'EventCancelled', 'EventPostponed', 'EventRescheduled', 'EventMovedOnline' );
	const ATTENDANCE_VALUES = array( 'offline', 'online', 'mixed' );

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// CPT + meta register only when enabled (early-return inside).
		add_action( 'init', array( $this, 'register_cpt' ), 5 );
		add_action( 'init', array( $this, 'register_meta_fields' ), 6 );

		add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );

		// Describe this module to the CPT Engine as preset #2 (pure metadata —
		// Events self-registers its own CPT above; engine must not double-register).
		add_action( 'luwipress_cpt_engine_register', array( $this, 'register_with_cpt_engine' ) );

		// Reuse the Schema Registry's built-in `event` type by adding an auto_data
		// callback so a single lwp_event page emits valid schema.org/Event.
		add_action( 'luwipress_schema_registry_init', array( $this, 'wire_event_schema' ) );

		// Editor metaboxes (event details + organizer/performer vendor pickers) + save.
		add_action( 'add_meta_boxes', array( $this, 'add_metaboxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_event_meta' ), 10, 2 );

		// ICS download: /?lwp_ics=1 on a single event permalink.
		add_action( 'template_redirect', array( $this, 'maybe_serve_ics' ), 1 );

		// Always-visible settings submenu so the operator can ENABLE the module
		// even while the CPT menu is still hidden (dormant default). p11 = after
		// the core LuwiPress menu (p10).
		add_action( 'admin_menu', array( $this, 'register_settings_page' ), 11 );

		// Auto-flush rewrite rules when permalink-affecting options change.
		foreach ( array( 'archive_slug', 'with_front', 'enabled', 'archive_enabled' ) as $opt ) {
			add_action( 'update_option_' . self::OPTION_PREFIX . $opt, array( $this, 'on_slug_change' ), 10, 2 );
		}
	}

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

	public static function is_enabled() {
		return (int) self::get_setting( 'enabled' ) === 1;
	}

	/** The Vendors post type, whether or not the Vendors module is enabled. */
	private function vendor_post_type() {
		return class_exists( 'LuwiPress_Vendors' ) ? LuwiPress_Vendors::POST_TYPE : 'lwp_vendor';
	}

	/* ─── CPT REGISTRATION ────────────────────────────────────────────── */

	public function register_cpt() {
		if ( ! self::is_enabled() ) {
			return;
		}

		$singular        = (string) self::get_setting( 'singular_label' );
		$plural          = (string) self::get_setting( 'plural_label' );
		$slug            = sanitize_title( (string) self::get_setting( 'archive_slug' ) ) ?: 'events';
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
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'rest_base'          => 'events',
			'menu_position'      => 23,
			'menu_icon'          => (string) self::get_setting( 'menu_icon' ),
			'capability_type'    => 'post',
			'has_archive'        => $archive_enabled ? $slug : false,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ),
			'rewrite'            => array(
				'slug'       => $slug,
				'with_front' => $with_front,
				'feeds'      => false,
			),
			'show_in_nav_menus'  => true,
		);

		register_post_type( self::POST_TYPE, apply_filters( 'luwipress_events_cpt_args', $args ) );

		// Flat event-category taxonomy (optional) — lets stores partition concerts /
		// workshops / classes and gives the Translation Manager a taxonomy to surface.
		if ( (int) self::get_setting( 'category_enabled' ) === 1 ) {
			$tax_args = array(
				'labels'            => array(
					'name'          => __( 'Event Categories', 'luwipress' ),
					'singular_name' => __( 'Event Category', 'luwipress' ),
					'menu_name'     => __( 'Categories', 'luwipress' ),
					'all_items'     => __( 'All Categories', 'luwipress' ),
					'edit_item'     => __( 'Edit Category', 'luwipress' ),
					'add_new_item'  => __( 'Add New Category', 'luwipress' ),
					'search_items'  => __( 'Search Categories', 'luwipress' ),
				),
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_nav_menus' => true,
				'show_in_rest'      => true,
				'rest_base'         => 'event-categories',
				'hierarchical'      => false,
				'rewrite'           => array( 'slug' => $slug . '/category', 'with_front' => $with_front ),
			);
			register_taxonomy(
				self::TAXONOMY,
				array( self::POST_TYPE ),
				apply_filters( 'luwipress_event_category_taxonomy_args', $tax_args )
			);
		}
	}

	public function register_meta_fields() {
		if ( ! self::is_enabled() ) {
			return;
		}
		$auth = function () {
			return current_user_can( 'edit_posts' );
		};

		foreach ( self::META_KEYS as $short => $meta_key ) {
			register_post_meta( self::POST_TYPE, $meta_key, array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'description'       => sprintf( 'LuwiPress Event — %s', $short ),
				'sanitize_callback' => $this->sanitizer_for( $short ),
				'auth_callback'     => $auth,
			) );
		}

		// Organizer / performer vendor references — JSON array of lwp_vendor IDs.
		// show_in_rest:false (internal JSON shape, edited via metabox / REST meta endpoint).
		foreach ( array( self::ORGANIZER_META, self::PERFORMER_META ) as $rel_key ) {
			register_post_meta( self::POST_TYPE, $rel_key, array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'description'       => 'LuwiPress Event — vendor references (JSON array of lwp_vendor IDs).',
				'sanitize_callback' => array( $this, 'normalize_vendor_ref_ids' ),
				'auth_callback'     => $auth,
			) );
		}
	}

	private function sanitizer_for( $short ) {
		$url_keys = array( 'online_url', 'ticket_url' );
		if ( in_array( $short, $url_keys, true ) ) {
			return function ( $value ) {
				$value = trim( (string) $value );
				return $value === '' ? '' : esc_url_raw( $value );
			};
		}
		if ( 'status' === $short ) {
			return function ( $value ) {
				$value = sanitize_text_field( (string) $value );
				return in_array( $value, self::STATUS_VALUES, true ) ? $value : '';
			};
		}
		if ( 'attendance' === $short ) {
			return function ( $value ) {
				$value = strtolower( sanitize_text_field( (string) $value ) );
				return in_array( $value, self::ATTENDANCE_VALUES, true ) ? $value : '';
			};
		}
		if ( 'currency' === $short ) {
			return function ( $value ) {
				$value = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
				// Only a full 3-letter ISO-4217 code is valid; drop fragments.
				return strlen( $value ) >= 3 ? substr( $value, 0, 3 ) : '';
			};
		}
		// start / end / price / venue_* → plain text (ISO/strings).
		return 'sanitize_text_field';
	}

	/**
	 * Normalize a vendor-reference meta value (JSON array, comma list, array, or
	 * single int) into a canonical `["123","456"]` JSON string of published
	 * lwp_vendor IDs. Returns '' when none valid. Mirrors the Vendors
	 * `_lwp_vendor_ids` canonical shape.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public function normalize_vendor_ref_ids( $value ) {
		$ids = $this->decode_ref_ids( $value );
		$vpt = $this->vendor_post_type();
		$valid = array();
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			if ( $p && $p->post_type === $vpt && $p->post_status === 'publish' ) {
				$valid[] = (string) $id;
			}
		}
		$valid = array_values( array_unique( $valid ) );
		return empty( $valid ) ? '' : wp_json_encode( $valid );
	}

	/**
	 * Decode a vendor-reference meta value (JSON / comma list / array / int) into
	 * a deduped list of positive int IDs. Shape-agnostic (read-tolerant).
	 *
	 * @param mixed $raw
	 * @return int[]
	 */
	private function decode_ref_ids( $raw ) {
		if ( is_array( $raw ) ) {
			$arr = $raw;
		} elseif ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( trim( $raw ), true );
			$arr     = is_array( $decoded ) ? $decoded : preg_split( '/[\s,]+/', trim( $raw ) );
		} elseif ( is_numeric( $raw ) ) {
			$arr = array( $raw );
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

	/* ─── CPT ENGINE DESCRIPTION (preset #2) ──────────────────────────── */

	/**
	 * Describe the Events module to the CPT Engine. Pure metadata — Events
	 * self-registers its own CPT/taxonomy/meta. NO `woocommerce` key: events do
	 * not attribute to products (the engine's WC-attribution-taxonomy machinery
	 * never touches them); the vendor link is an Event→Vendor relationship.
	 *
	 * @param LuwiPress_CPT_Engine $engine
	 */
	public function register_with_cpt_engine( $engine ) {
		if ( ! is_object( $engine ) || ! method_exists( $engine, 'register_type' ) ) {
			return;
		}

		$fields = array(
			array( 'key' => self::META_KEYS['start'],         'type' => 'datetime',     'label' => __( 'Start', 'luwipress' ),         'translatable' => false ),
			array( 'key' => self::META_KEYS['end'],           'type' => 'datetime',     'label' => __( 'End', 'luwipress' ),           'translatable' => false ),
			array( 'key' => self::META_KEYS['status'],        'type' => 'select',       'label' => __( 'Status', 'luwipress' ),        'translatable' => false ),
			array( 'key' => self::META_KEYS['attendance'],    'type' => 'select',       'label' => __( 'Attendance mode', 'luwipress' ), 'translatable' => false ),
			array( 'key' => self::META_KEYS['venue_name'],    'type' => 'text',         'label' => __( 'Venue name', 'luwipress' ),    'translatable' => true ),
			array( 'key' => self::META_KEYS['venue_address'], 'type' => 'text',         'label' => __( 'Venue address', 'luwipress' ), 'translatable' => true ),
			array( 'key' => self::META_KEYS['online_url'],    'type' => 'url',          'label' => __( 'Online URL', 'luwipress' ),    'translatable' => false ),
			array( 'key' => self::META_KEYS['ticket_url'],    'type' => 'url',          'label' => __( 'Ticket URL', 'luwipress' ),    'translatable' => false ),
			array( 'key' => self::META_KEYS['price'],         'type' => 'text',         'label' => __( 'Price', 'luwipress' ),         'translatable' => false ),
			array( 'key' => self::META_KEYS['currency'],      'type' => 'text',         'label' => __( 'Currency', 'luwipress' ),      'translatable' => false ),
			array( 'key' => self::ORGANIZER_META,             'type' => 'relationship', 'label' => __( 'Organizers (vendors)', 'luwipress' ), 'translatable' => false ),
			array( 'key' => self::PERFORMER_META,             'type' => 'relationship', 'label' => __( 'Performers (vendors)', 'luwipress' ), 'translatable' => false ),
		);

		$taxonomies = array();
		if ( (int) self::get_setting( 'category_enabled' ) === 1 ) {
			$taxonomies[] = array(
				'slug'         => self::TAXONOMY,
				'label'        => __( 'Event Categories', 'luwipress' ),
				'hierarchical' => false,
				'translatable' => true,
			);
		}

		$engine->register_type( 'events', array(
			'post_type'      => self::POST_TYPE,
			'source'         => 'preset',
			'self_registers' => true,
			'enabled'        => self::is_enabled(),
			'labels'         => array(
				'singular' => (string) self::get_setting( 'singular_label' ),
				'plural'   => (string) self::get_setting( 'plural_label' ),
			),
			'permalink'      => array(
				'archive_slug' => (string) self::get_setting( 'archive_slug' ),
			),
			'field_schema'   => $fields,
			'taxonomies'     => $taxonomies,
			'schema_mapping' => array( 'type' => 'event' ),
			// No 'woocommerce' key — events are not attributed to products.
		) );
	}

	/* ─── SCHEMA REGISTRY INTEGRATION (reuse built-in `event` type) ───── */

	/**
	 * Add an auto_data generator to the Schema Registry's built-in `event` type so
	 * a single lwp_event page emits Event JSON-LD built from the CPT fields. The
	 * built-in type's renderer (render_event) + sanitizer + meta_key are preserved.
	 *
	 * No double-emit: there is still only ONE `event` type. render_for_request
	 * prefers a manually stored `_luwipress_schema_event` payload over auto_data,
	 * so an operator override still wins; auto_data only fires when none is stored.
	 *
	 * @param LuwiPress_Schema_Registry $registry
	 */
	public function wire_event_schema( $registry ) {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'get_type' ) || ! method_exists( $registry, 'register_type' ) ) {
			return;
		}
		$cfg = $registry->get_type( 'event' );
		if ( ! is_array( $cfg ) ) {
			return;
		}
		$cfg['auto_data'] = array( $this, 'auto_event_schema' );
		$registry->register_type( 'event', $cfg );
	}

	/**
	 * Build the Event payload (in the shape render_event consumes) from lwp_event
	 * CPT meta. Returns array() for every other post type so the `post:*` built-in
	 * `event` type behaves exactly as before on regular posts/products.
	 *
	 * @param array $ctx { object_type, object_id, subtype }
	 * @return array
	 */
	public function auto_event_schema( $ctx ) {
		$id = (int) ( $ctx['object_id'] ?? 0 );
		if ( $id <= 0 || get_post_type( $id ) !== self::POST_TYPE ) {
			return array();
		}
		$start = (string) get_post_meta( $id, self::META_KEYS['start'], true );
		$name  = get_the_title( $id );
		if ( '' === $start || '' === $name ) {
			return array(); // render_event requires name + startDate.
		}

		$data = array(
			'name'      => $name,
			'startDate' => $start,
		);

		$end = (string) get_post_meta( $id, self::META_KEYS['end'], true );
		if ( '' !== $end ) {
			$data['endDate'] = $end;
		}

		$post = get_post( $id );
		$desc = $post && $post->post_excerpt ? $post->post_excerpt : wp_strip_all_tags( wp_trim_words( $post ? $post->post_content : '', 50, '' ) );
		if ( $desc ) {
			$data['description'] = $desc;
		}
		$image = get_the_post_thumbnail_url( $id, 'full' );
		if ( $image ) {
			$data['image'] = esc_url_raw( $image );
		}

		$status = (string) get_post_meta( $id, self::META_KEYS['status'], true );
		if ( in_array( $status, self::STATUS_VALUES, true ) ) {
			$data['eventStatus'] = 'https://schema.org/' . $status;
		}
		$attendance = (string) get_post_meta( $id, self::META_KEYS['attendance'], true );
		$att_map    = array(
			'offline' => 'OfflineEventAttendanceMode',
			'online'  => 'OnlineEventAttendanceMode',
			'mixed'   => 'MixedEventAttendanceMode',
		);
		if ( isset( $att_map[ $attendance ] ) ) {
			$data['eventAttendanceMode'] = 'https://schema.org/' . $att_map[ $attendance ];
		}

		// Location — physical (name/address) and/or virtual (online URL).
		$venue_name = (string) get_post_meta( $id, self::META_KEYS['venue_name'], true );
		$venue_addr = (string) get_post_meta( $id, self::META_KEYS['venue_address'], true );
		$online_url = (string) get_post_meta( $id, self::META_KEYS['online_url'], true );
		$loc = array();
		if ( '' !== $venue_name ) {
			$loc['name'] = $venue_name;
		}
		if ( '' !== $venue_addr ) {
			$loc['address'] = $venue_addr;
		}
		if ( '' === $venue_name && '' === $venue_addr && '' !== $online_url ) {
			$loc['url'] = esc_url_raw( $online_url );
		}
		if ( ! empty( $loc ) ) {
			$data['location'] = $loc;
		}

		// Offers — ticketing.
		$ticket_url = (string) get_post_meta( $id, self::META_KEYS['ticket_url'], true );
		$price      = (string) get_post_meta( $id, self::META_KEYS['price'], true );
		$currency   = (string) get_post_meta( $id, self::META_KEYS['currency'], true );
		$offers = array();
		if ( '' !== $price ) {
			$offers['price'] = $price;
		}
		if ( '' !== $currency ) {
			$offers['priceCurrency'] = $currency;
		}
		if ( '' !== $ticket_url ) {
			$offers['url'] = esc_url_raw( $ticket_url );
		}
		if ( ! empty( $offers ) ) {
			$data['offers'] = $offers;
		}

		// Organizer / performer — resolve vendor IDs to nodes (single or list).
		$organizers = $this->vendor_ref_nodes( self::ORGANIZER_META, $id );
		if ( ! empty( $organizers ) ) {
			$data['organizer'] = count( $organizers ) === 1 ? $organizers[0] : $organizers;
		}
		$performers = $this->vendor_ref_nodes( self::PERFORMER_META, $id );
		if ( ! empty( $performers ) ) {
			$data['performer'] = count( $performers ) === 1 ? $performers[0] : $performers;
		}

		return $data;
	}

	/**
	 * Resolve a vendor-reference meta to an array of schema agent nodes
	 * `{@type, name, url}`. @type follows the vendor's resolved entity type when
	 * the Vendors module is available, else Organization. Skips dead / unpublished
	 * vendor IDs.
	 *
	 * @param string $meta_key
	 * @param int    $event_id
	 * @return array<int,array>
	 */
	private function vendor_ref_nodes( $meta_key, $event_id ) {
		$ids = $this->decode_ref_ids( get_post_meta( $event_id, $meta_key, true ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$vpt        = $this->vendor_post_type();
		$type_map   = array( 'organization' => 'Organization', 'person' => 'Person', 'localbusiness' => 'LocalBusiness' );
		$nodes      = array();
		$has_module = class_exists( 'LuwiPress_Vendors' );
		foreach ( $ids as $vid ) {
			$p = get_post( $vid );
			if ( ! $p || $p->post_type !== $vpt || $p->post_status !== 'publish' ) {
				continue;
			}
			$at_type = 'Organization';
			if ( $has_module && method_exists( 'LuwiPress_Vendors', 'get_instance' ) ) {
				$etype = LuwiPress_Vendors::get_instance()->resolve_entity_type( $vid );
				if ( isset( $type_map[ $etype ] ) ) {
					$at_type = $type_map[ $etype ];
				}
			}
			$nodes[] = array(
				'@type' => $at_type,
				'name'  => get_the_title( $p ),
				'url'   => esc_url_raw( get_permalink( $p ) ),
			);
		}
		return $nodes;
	}

	/* ─── EDITOR METABOXES ────────────────────────────────────────────── */

	public function add_metaboxes() {
		add_meta_box(
			'lwp_event_details',
			__( 'Event Details', 'luwipress' ),
			array( $this, 'render_details_metabox' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
		if ( (int) self::get_setting( 'show_organizer' ) === 1 ) {
			add_meta_box(
				'lwp_event_organizers',
				__( 'Organizers', 'luwipress' ),
				array( $this, 'render_organizer_metabox' ),
				self::POST_TYPE,
				'side',
				'default'
			);
		}
		if ( (int) self::get_setting( 'show_performer' ) === 1 ) {
			add_meta_box(
				'lwp_event_performers',
				__( 'Performers', 'luwipress' ),
				array( $this, 'render_performer_metabox' ),
				self::POST_TYPE,
				'side',
				'default'
			);
		}
	}

	public function render_details_metabox( $post ) {
		wp_nonce_field( 'lwp_event_details_save', 'lwp_event_details_nonce' );
		$get = function ( $short ) use ( $post ) {
			return esc_attr( (string) get_post_meta( $post->ID, self::META_KEYS[ $short ], true ) );
		};
		$status     = (string) get_post_meta( $post->ID, self::META_KEYS['status'], true );
		$attendance = (string) get_post_meta( $post->ID, self::META_KEYS['attendance'], true );
		$show_venue  = (int) self::get_setting( 'show_venue' ) === 1;
		$show_online = (int) self::get_setting( 'show_online' ) === 1;
		$show_offers = (int) self::get_setting( 'show_offers' ) === 1;
		?>
		<style>.lwp-evt-grid{display:grid;grid-template-columns:160px 1fr;gap:10px 14px;align-items:center;max-width:680px}.lwp-evt-grid label{font-weight:600}.lwp-evt-grid input[type=text],.lwp-evt-grid input[type=url],.lwp-evt-grid input[type=datetime-local],.lwp-evt-grid select{width:100%}.lwp-evt-grid .description{grid-column:2}</style>
		<div class="lwp-evt-grid">
			<label for="lwp_evt_start"><?php esc_html_e( 'Start', 'luwipress' ); ?></label>
			<input type="datetime-local" id="lwp_evt_start" name="lwp_event[start]" value="<?php echo esc_attr( $this->to_input_datetime( $get( 'start' ) ) ); ?>" />
			<label for="lwp_evt_end"><?php esc_html_e( 'End', 'luwipress' ); ?></label>
			<input type="datetime-local" id="lwp_evt_end" name="lwp_event[end]" value="<?php echo esc_attr( $this->to_input_datetime( $get( 'end' ) ) ); ?>" />

			<label for="lwp_evt_status"><?php esc_html_e( 'Status', 'luwipress' ); ?></label>
			<select id="lwp_evt_status" name="lwp_event[status]">
				<option value=""><?php esc_html_e( '— Scheduled (default) —', 'luwipress' ); ?></option>
				<?php foreach ( self::STATUS_VALUES as $sv ) : ?>
					<option value="<?php echo esc_attr( $sv ); ?>" <?php selected( $status, $sv ); ?>><?php echo esc_html( $sv ); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="lwp_evt_attendance"><?php esc_html_e( 'Attendance', 'luwipress' ); ?></label>
			<select id="lwp_evt_attendance" name="lwp_event[attendance]">
				<option value="offline" <?php selected( $attendance, 'offline' ); ?>><?php esc_html_e( 'In person', 'luwipress' ); ?></option>
				<option value="online" <?php selected( $attendance, 'online' ); ?>><?php esc_html_e( 'Online', 'luwipress' ); ?></option>
				<option value="mixed" <?php selected( $attendance, 'mixed' ); ?>><?php esc_html_e( 'Hybrid', 'luwipress' ); ?></option>
			</select>

			<?php if ( $show_venue ) : ?>
				<label for="lwp_evt_venue_name"><?php esc_html_e( 'Venue name', 'luwipress' ); ?></label>
				<input type="text" id="lwp_evt_venue_name" name="lwp_event[venue_name]" value="<?php echo $get( 'venue_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr'd in $get ?>" />
				<label for="lwp_evt_venue_address"><?php esc_html_e( 'Venue address', 'luwipress' ); ?></label>
				<input type="text" id="lwp_evt_venue_address" name="lwp_event[venue_address]" value="<?php echo $get( 'venue_address' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr'd in $get ?>" />
			<?php endif; ?>

			<?php if ( $show_online ) : ?>
				<label for="lwp_evt_online_url"><?php esc_html_e( 'Online URL', 'luwipress' ); ?></label>
				<input type="url" id="lwp_evt_online_url" name="lwp_event[online_url]" value="<?php echo $get( 'online_url' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" placeholder="https://" />
			<?php endif; ?>

			<?php if ( $show_offers ) : ?>
				<label for="lwp_evt_ticket_url"><?php esc_html_e( 'Ticket URL', 'luwipress' ); ?></label>
				<input type="url" id="lwp_evt_ticket_url" name="lwp_event[ticket_url]" value="<?php echo $get( 'ticket_url' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" placeholder="https://" />
				<label for="lwp_evt_price"><?php esc_html_e( 'Price', 'luwipress' ); ?></label>
				<input type="text" id="lwp_evt_price" name="lwp_event[price]" value="<?php echo $get( 'price' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" placeholder="0.00" />
				<label for="lwp_evt_currency"><?php esc_html_e( 'Currency', 'luwipress' ); ?></label>
				<input type="text" id="lwp_evt_currency" name="lwp_event[currency]" value="<?php echo $get( 'currency' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" placeholder="USD" maxlength="3" />
			<?php endif; ?>
		</div>
		<p class="description"><?php esc_html_e( 'Powers the Event JSON-LD schema, the ICS calendar download, and (when set) the ticketing offer. Start date is required for schema + ICS.', 'luwipress' ); ?></p>
		<?php
	}

	public function render_organizer_metabox( $post ) {
		$this->render_vendor_picker( $post, self::ORGANIZER_META, 'lwp_event_organizers', __( 'Pick the organizing vendor(s).', 'luwipress' ) );
	}

	public function render_performer_metabox( $post ) {
		$this->render_vendor_picker( $post, self::PERFORMER_META, 'lwp_event_performers', __( 'Pick the performing vendor(s).', 'luwipress' ) );
	}

	private function render_vendor_picker( $post, $meta_key, $field, $hint ) {
		$selected = $this->decode_ref_ids( get_post_meta( $post->ID, $meta_key, true ) );
		$vpt      = $this->vendor_post_type();
		$vendors  = get_posts( array(
			'post_type'      => $vpt,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		wp_nonce_field( 'lwp_event_vendor_save', 'lwp_event_vendor_nonce' );
		if ( empty( $vendors ) ) {
			echo '<p><em>' . esc_html__( 'No vendors yet. Add them in LuwiPress → Vendors, then attach them here.', 'luwipress' ) . '</em></p>';
			return;
		}
		echo '<p style="font-size:12px;color:#646970;margin:0 0 8px;">' . esc_html( $hint ) . '</p>';
		echo '<ul style="margin:0;max-height:200px;overflow:auto;padding:0;list-style:none;">';
		foreach ( $vendors as $v ) {
			$checked = in_array( (int) $v->ID, $selected, true ) ? 'checked' : '';
			echo '<li style="margin:0 0 6px;"><label style="display:flex;align-items:center;gap:6px;cursor:pointer;">';
			echo '<input type="checkbox" name="' . esc_attr( $field ) . '[]" value="' . esc_attr( (string) $v->ID ) . '" ' . esc_attr( $checked ) . ' />';
			echo '<span>' . esc_html( $v->post_title ) . '</span></label></li>';
		}
		echo '</ul>';
	}

	/**
	 * Save the event detail + vendor-reference metaboxes. Each metabox carries its
	 * own nonce; we save whichever ones are present in the request.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public function save_event_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Detail metabox.
		if ( isset( $_POST['lwp_event_details_nonce'] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['lwp_event_details_nonce'] ) ), 'lwp_event_details_save' )
			&& isset( $_POST['lwp_event'] ) && is_array( $_POST['lwp_event'] ) ) {
			$raw = wp_unslash( $_POST['lwp_event'] ); // phpcs:ignore WordPress.Security.ValidatedSanitized — each field sanitized below.
			foreach ( self::META_KEYS as $short => $meta_key ) {
				if ( ! array_key_exists( $short, $raw ) ) {
					continue;
				}
				$value = $raw[ $short ];
				if ( 'start' === $short || 'end' === $short ) {
					$value = $this->from_input_datetime( (string) $value );
				}
				$sanitizer = $this->sanitizer_for( $short );
				$clean     = call_user_func( $sanitizer, $value );
				if ( '' === $clean ) {
					delete_post_meta( $post_id, $meta_key );
				} else {
					update_post_meta( $post_id, $meta_key, $clean );
				}
			}
		}

		// Vendor pickers (organizer / performer share one nonce).
		if ( isset( $_POST['lwp_event_vendor_nonce'] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['lwp_event_vendor_nonce'] ) ), 'lwp_event_vendor_save' ) ) {
			foreach ( array( 'lwp_event_organizers' => self::ORGANIZER_META, 'lwp_event_performers' => self::PERFORMER_META ) as $field => $meta_key ) {
				// A picker box is only rendered (and present) when its toggle is on;
				// skip absent fields so we never clear a value whose box wasn't shown.
				$present = ( 'lwp_event_organizers' === $field )
					? (int) self::get_setting( 'show_organizer' ) === 1
					: (int) self::get_setting( 'show_performer' ) === 1;
				if ( ! $present ) {
					continue;
				}
				$picked = isset( $_POST[ $field ] ) ? (array) wp_unslash( $_POST[ $field ] ) : array();
				$clean  = $this->normalize_vendor_ref_ids( array_map( 'absint', $picked ) );
				if ( '' === $clean ) {
					delete_post_meta( $post_id, $meta_key );
				} else {
					update_post_meta( $post_id, $meta_key, $clean );
				}
			}
		}
	}

	/* ─── DATETIME HELPERS ────────────────────────────────────────────── */

	/**
	 * Parse a stored/entered value into normalized parts WITHOUT timezone
	 * conversion — event times are naive wall-clock (what the operator typed),
	 * so strtotime()+gmdate() would shift them by the server UTC offset.
	 *
	 * @param mixed $value
	 * @return array{date:string,time:?string}|null
	 */
	private function parse_iso_parts( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ](\d{1,2}):(\d{2})(?::(\d{2}))?)?/', $value, $m ) ) {
			return null;
		}
		$date = sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
		if ( isset( $m[4] ) && '' !== $m[4] ) {
			$time = sprintf( '%02d:%02d:%02d', (int) $m[4], (int) $m[5], isset( $m[6] ) && '' !== $m[6] ? (int) $m[6] : 0 );
			return array( 'date' => $date, 'time' => $time );
		}
		return array( 'date' => $date, 'time' => null );
	}

	/** Stored ISO → datetime-local input value (YYYY-MM-DDTHH:MM). No tz shift. */
	private function to_input_datetime( $iso ) {
		$p = $this->parse_iso_parts( $iso );
		if ( null === $p ) {
			return '';
		}
		$time = ( null === $p['time'] ) ? '00:00:00' : $p['time'];
		return $p['date'] . 'T' . substr( $time, 0, 5 );
	}

	/** datetime-local / REST input → stored ISO (naive wall-clock; no tz shift). */
	private function from_input_datetime( $value ) {
		$p = $this->parse_iso_parts( $value );
		if ( null === $p ) {
			return '';
		}
		if ( null === $p['time'] ) {
			return $p['date']; // pure date entry
		}
		return $p['date'] . 'T' . substr( $p['time'], 0, 5 );
	}

	/* ─── ICS EXPORT ──────────────────────────────────────────────────── */

	/**
	 * Serve an ICS download for a single event when `?lwp_ics=1` is present. Runs
	 * at template_redirect p1, emits text/calendar + exit.
	 */
	public function maybe_serve_ics() {
		if ( ! self::is_enabled() || ! is_singular( self::POST_TYPE ) ) {
			return;
		}
		if ( ! isset( $_GET['lwp_ics'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only download.
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$ics = $this->build_ics( $post->ID );
		if ( '' === $ics ) {
			return; // no start date → fall through to normal rendering.
		}
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="event-' . (int) $post->ID . '.ics"' );
		echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw iCalendar body.
		exit;
	}

	/**
	 * Build an RFC 5545 VCALENDAR/VEVENT for an event. Returns '' if no start date.
	 *
	 * @param int $event_id
	 * @return string
	 */
	public function build_ics( $event_id ) {
		$event_id = (int) $event_id;
		$start    = (string) get_post_meta( $event_id, self::META_KEYS['start'], true );
		if ( '' === $start ) {
			return '';
		}
		$post = get_post( $event_id );
		if ( ! $post ) {
			return '';
		}
		$end      = (string) get_post_meta( $event_id, self::META_KEYS['end'], true );
		$venue    = trim( implode( ', ', array_filter( array(
			(string) get_post_meta( $event_id, self::META_KEYS['venue_name'], true ),
			(string) get_post_meta( $event_id, self::META_KEYS['venue_address'], true ),
		) ) ) );
		$summary  = get_the_title( $event_id );
		$desc     = $post->post_excerpt ? $post->post_excerpt : wp_strip_all_tags( wp_trim_words( $post->post_content, 60, '' ) );
		$url      = get_permalink( $event_id );
		$host     = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'luwipress';
		$now      = gmdate( 'Ymd\THis\Z' );

		$lines   = array();
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//LuwiPress//Events//EN';
		$lines[] = 'CALSCALE:GREGORIAN';
		$lines[] = 'METHOD:PUBLISH';
		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:event-' . $event_id . '@' . $host;
		$lines[] = 'DTSTAMP:' . $now;
		$lines[] = $this->ics_dt( 'DTSTART', $start );
		if ( '' !== $end ) {
			$lines[] = $this->ics_dt( 'DTEND', $end );
		}
		$lines[] = 'SUMMARY:' . $this->ics_escape( $summary );
		if ( $desc ) {
			$lines[] = 'DESCRIPTION:' . $this->ics_escape( $desc );
		}
		if ( '' !== $venue ) {
			$lines[] = 'LOCATION:' . $this->ics_escape( $venue );
		}
		if ( $url ) {
			$lines[] = 'URL:' . $this->ics_escape( $url );
		}
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/** Format a DTSTART/DTEND line from a stored ISO value (date or local datetime). */
	private function ics_dt( $prop, $iso ) {
		$p = $this->parse_iso_parts( $iso );
		if ( null === $p ) {
			return $prop . ':' . preg_replace( '/[^0-9T]/', '', (string) $iso );
		}
		$d = str_replace( '-', '', $p['date'] );
		// Date-only → VALUE=DATE; otherwise floating local datetime (no TZID/Z so
		// calendar clients interpret it in the viewer's local time, which matches
		// how the operator entered it).
		if ( null === $p['time'] ) {
			return $prop . ';VALUE=DATE:' . $d;
		}
		return $prop . ':' . $d . 'T' . str_replace( ':', '', $p['time'] );
	}

	/** Escape a text value per RFC 5545 (backslash, comma, semicolon, newlines). */
	private function ics_escape( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = str_replace( array( '\\', ',', ';' ), array( '\\\\', '\\,', '\\;' ), $text );
		$text = str_replace( array( "\r\n", "\r", "\n" ), '\\n', $text );
		return $text;
	}

	/* ─── REST ENDPOINTS ──────────────────────────────────────────────── */

	public function register_rest_endpoints() {
		$ns = 'luwipress/v1';

		register_rest_route( $ns, '/events/settings', array(
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

		register_rest_route( $ns, '/events', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_list' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'limit'   => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ),
				'orderby' => array( 'type' => 'string',  'default' => 'date' ),
				'order'   => array( 'type' => 'string',  'default' => 'DESC' ),
			),
		) );

		register_rest_route( $ns, '/events/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_get_one' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/events/(?P<id>\d+)/meta', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_set_meta' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );

		register_rest_route( $ns, '/events/(?P<id>\d+)/ics', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_get_ics' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/events/sync-rewrite', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_flush_rewrite' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );
	}

	public function rest_get_settings() {
		return rest_ensure_response( array(
			'settings'  => self::get_all_settings(),
			'post_type' => self::POST_TYPE,
			'enabled'   => self::is_enabled(),
			'count'     => self::is_enabled() && post_type_exists( self::POST_TYPE ) ? ( wp_count_posts( self::POST_TYPE )->publish ?? 0 ) : 0,
		) );
	}

	public function rest_update_settings( WP_REST_Request $req ) {
		$params       = $req->get_json_params() ?: $req->get_params();
		$updated      = array();
		$slug_changed = false;
		$was_enabled  = self::is_enabled();

		foreach ( self::DEFAULTS as $key => $default ) {
			if ( ! array_key_exists( $key, $params ) ) {
				continue;
			}
			$value = $params[ $key ];
			if ( 'archive_slug' === $key ) {
				$value = sanitize_title( (string) $value ) ?: 'events';
				if ( $value !== self::get_setting( 'archive_slug' ) ) {
					$slug_changed = true;
				}
			} elseif ( 'menu_icon' === $key ) {
				$value = sanitize_text_field( (string) $value );
			} elseif ( is_int( $default ) ) {
				$value = (int) (bool) $value;
			} else {
				$value = sanitize_text_field( (string) $value );
			}
			$this->update_setting( $key, $value );
			$updated[ $key ] = $value;
		}

		// Re-register + flush when the slug changed OR the module was just enabled
		// (the CPT didn't exist on this request until now).
		$now_enabled = self::is_enabled();
		if ( $slug_changed || ( ! $was_enabled && $now_enabled ) ) {
			$this->register_cpt();
			$this->register_meta_fields();
			flush_rewrite_rules( false );
		}

		return rest_ensure_response( array(
			'status'       => 'updated',
			'updated'      => $updated,
			'slug_changed' => $slug_changed,
			'enabled'      => $now_enabled,
			'all_settings' => self::get_all_settings(),
		) );
	}

	public function rest_list( WP_REST_Request $req ) {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return rest_ensure_response( array( 'count' => 0, 'total' => 0, 'events' => array(), 'note' => 'Events module is disabled.' ) );
		}
		$limit   = (int) $req->get_param( 'limit' );
		$orderby = sanitize_key( $req->get_param( 'orderby' ) );
		$order   = strtoupper( sanitize_key( $req->get_param( 'order' ) ) ) === 'ASC' ? 'ASC' : 'DESC';

		$q = new WP_Query( array(
			'post_type'      => self::POST_TYPE,
			'posts_per_page' => $limit,
			'post_status'    => 'publish',
			'orderby'        => $orderby ?: 'date',
			'order'          => $order,
		) );

		$out = array();
		foreach ( $q->posts as $p ) {
			$out[] = $this->shape_event( $p );
		}
		return rest_ensure_response( array(
			'count'  => count( $out ),
			'total'  => (int) $q->found_posts,
			'events' => $out,
		) );
	}

	public function rest_get_one( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		$p  = get_post( $id );
		if ( ! $p || $p->post_type !== self::POST_TYPE || ! $this->can_read_event( $p ) ) {
			return new WP_Error( 'not_found', 'Event not found', array( 'status' => 404 ) );
		}
		// Build the exact node the front-end emits by running the payload through
		// the Schema Registry's own render_event (Place/VirtualLocation, agent
		// @types, offers @type) — an accurate preview, not a hand-rolled merge.
		$ctx     = array( 'object_type' => 'post', 'object_id' => $id, 'subtype' => self::POST_TYPE );
		$payload = $this->auto_event_schema( $ctx );
		$schema  = null;
		if ( ! empty( $payload ) && class_exists( 'LuwiPress_Schema_Registry' ) ) {
			$rendered = LuwiPress_Schema_Registry::get_instance()->render_event( $payload, $ctx );
			$schema   = is_array( $rendered ) ? $rendered : null;
		}
		return rest_ensure_response( array(
			'event'   => $this->shape_event( $p ),
			'schema'  => $schema,
			'ics_url' => add_query_arg( 'lwp_ics', '1', get_permalink( $id ) ),
		) );
	}

	public function rest_set_meta( WP_REST_Request $req ) {
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'disabled', 'Events module is disabled.', array( 'status' => 409 ) );
		}
		$id = (int) $req->get_param( 'id' );
		$p  = get_post( $id );
		if ( ! $p || $p->post_type !== self::POST_TYPE ) {
			return new WP_Error( 'not_found', 'Event not found', array( 'status' => 404 ) );
		}
		$params  = $req->get_json_params() ?: $req->get_params();
		$updated = array();
		foreach ( self::META_KEYS as $short => $meta_key ) {
			if ( ! array_key_exists( $short, $params ) ) {
				continue;
			}
			$sanitizer = $this->sanitizer_for( $short );
			$clean     = call_user_func( $sanitizer, $params[ $short ] );
			if ( '' === $clean ) {
				delete_post_meta( $id, $meta_key );
			} else {
				update_post_meta( $id, $meta_key, $clean );
			}
			$updated[ $short ] = $clean;
		}
		foreach ( array( 'organizers' => self::ORGANIZER_META, 'performers' => self::PERFORMER_META ) as $short => $meta_key ) {
			if ( ! array_key_exists( $short, $params ) ) {
				continue;
			}
			$clean = $this->normalize_vendor_ref_ids( $params[ $short ] );
			if ( '' === $clean ) {
				delete_post_meta( $id, $meta_key );
			} else {
				update_post_meta( $id, $meta_key, $clean );
			}
			$updated[ $short ] = $this->decode_ref_ids( $clean );
		}
		return rest_ensure_response( array( 'status' => 'updated', 'id' => $id, 'updated' => $updated ) );
	}

	public function rest_get_ics( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		$p  = get_post( $id );
		if ( ! $p || $p->post_type !== self::POST_TYPE || ! $this->can_read_event( $p ) ) {
			return new WP_Error( 'not_found', 'Event not found', array( 'status' => 404 ) );
		}
		return rest_ensure_response( array(
			'id'           => $id,
			'ics'          => $this->build_ics( $id ),
			'download_url' => add_query_arg( 'lwp_ics', '1', get_permalink( $id ) ),
		) );
	}

	public function rest_flush_rewrite() {
		$this->register_cpt();
		flush_rewrite_rules( false );
		return rest_ensure_response( array( 'status' => 'flushed' ) );
	}

	/* ─── DATA SHAPER ─────────────────────────────────────────────────── */

	/**
	 * Whether a caller may read this event over the public REST/ICS surface.
	 * Published events are public; drafts/private only for users who can edit
	 * them — so an anonymous caller can't pull unpublished event content/schema.
	 *
	 * @param WP_Post $p
	 * @return bool
	 */
	private function can_read_event( $p ) {
		return $p instanceof WP_Post
			&& ( 'publish' === $p->post_status || current_user_can( 'edit_post', $p->ID ) );
	}

	public function shape_event( WP_Post $p ) {
		$meta = array();
		foreach ( self::META_KEYS as $short => $meta_key ) {
			$meta[ $short ] = get_post_meta( $p->ID, $meta_key, true );
		}
		$cats = array();
		$terms = get_the_terms( $p->ID, self::TAXONOMY );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				$cats[] = array( 'term_id' => (int) $t->term_id, 'slug' => $t->slug, 'name' => $t->name );
			}
		}
		return array(
			'id'         => $p->ID,
			'title'      => get_the_title( $p ),
			'slug'       => $p->post_name,
			'link'       => get_permalink( $p ),
			'excerpt'    => $p->post_excerpt,
			'image'      => get_the_post_thumbnail_url( $p, 'large' ) ?: '',
			'meta'       => $meta,
			'organizers' => $this->decode_ref_ids( get_post_meta( $p->ID, self::ORGANIZER_META, true ) ),
			'performers' => $this->decode_ref_ids( get_post_meta( $p->ID, self::PERFORMER_META, true ) ),
			'categories' => $cats,
		);
	}

	/* ─── ADMIN SETTINGS PAGE ─────────────────────────────────────────── */

	public function register_settings_page() {
		$hook = add_submenu_page(
			'luwipress',
			__( 'Events', 'luwipress' ),
			__( 'Events', 'luwipress' ),
			'manage_options',
			'luwipress-events',
			array( $this, 'render_settings_page' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'handle_settings_post' ) );
		}
	}

	/** Handle the settings form POST (nonce-checked) before the page renders. */
	public function handle_settings_post() {
		if ( ! isset( $_POST['lwp_events_settings_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['lwp_events_settings_nonce'] ) ), 'lwp_events_settings_save' ) ) {
			return;
		}
		$was_enabled  = self::is_enabled();
		$slug_changed = false;
		foreach ( self::DEFAULTS as $key => $default ) {
			if ( is_int( $default ) ) {
				// Checkbox: present = 1, absent = 0.
				$this->update_setting( $key, isset( $_POST[ 'lwp_events_' . $key ] ) ? 1 : 0 );
			} elseif ( isset( $_POST[ 'lwp_events_' . $key ] ) ) {
				$raw = sanitize_text_field( wp_unslash( $_POST[ 'lwp_events_' . $key ] ) );
				if ( 'archive_slug' === $key ) {
					$raw = sanitize_title( $raw ) ?: 'events';
					if ( $raw !== self::get_setting( 'archive_slug' ) ) {
						$slug_changed = true;
					}
				}
				$this->update_setting( $key, $raw );
			}
		}
		$now_enabled = self::is_enabled();
		if ( $slug_changed || ( ! $was_enabled && $now_enabled ) ) {
			$this->register_cpt();
			$this->register_meta_fields();
			flush_rewrite_rules( false );
		}
		add_settings_error( 'luwipress_events', 'saved', __( 'Events settings saved.', 'luwipress' ), 'success' );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
		}
		$settings = self::get_all_settings();
		$enabled  = self::is_enabled();
		$cpt_url  = admin_url( 'edit.php?post_type=' . self::POST_TYPE );
		require LUWIPRESS_PLUGIN_DIR . 'admin/events-page.php';
	}

	/* ─── SLUG CHANGE HOOK ────────────────────────────────────────────── */

	public function on_slug_change( $old, $new ) {
		$this->register_cpt();
		flush_rewrite_rules( false );
	}
}
