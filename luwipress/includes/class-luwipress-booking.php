<?php
/**
 * LuwiPress Booking — remote tour configuration.
 *
 * REST surface (luwipress/v1) for reading and writing tour-booking data on
 * WooCommerce products. Tours are ordinary WC products flagged
 * `_fbd_is_tour='yes'`; this module lets an operator (or an MCP client / remote
 * fleet manager) flag and configure them without opening the product editor.
 *
 * ── Schema contract ─────────────────────────────────────────────────────────
 * The `_fbd_*` meta is OWNED by the Amber theme's booking module
 * (themes/luwipress-amber-elementor/inc/booking/). The theme renders the box,
 * does the cart math, builds the voucher + TouristTrip schema, and is the
 * source of truth for the field shapes. Core here only provides the remote
 * read/write surface: it writes the RAW meta keys and mirrors the theme's
 * validation/sanitization (lwp_amber_sanitize_addons / _string_list /
 * lwp_amber_tour_config). KEEP THIS VALIDATION IN SYNC with
 * inc/booking/class-tour-product.php + inc/booking/helpers.php.
 *
 * Why core (not theme REST): the theme registers only the 9 SCALAR `_fbd_*`
 * keys via register_post_meta(show_in_rest), so `_fbd_time_slots` and
 * `_fbd_addons` (arrays) are not REST-writable through the theme. This module
 * closes that gap and gives one validated, token-authenticated write path.
 *
 * @package LuwiPress
 * @since 3.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Booking {

	private static $instance = null;

	/** Option prefix for module defaults. */
	const OPTION_PREFIX = 'luwipress_booking_';

	/** Allowed duration buckets (mirror class-tour-product.php). Empty string = auto. */
	const DURATION_BUCKETS = array( '', 'short', 'half', 'full', 'multi' );

	/** Scalar product meta keys -> type. Mirror of the theme's registered-meta loop. */
	const SCALAR_KEYS = array(
		'_fbd_is_tour'         => 'enum_yesno',
		'_fbd_duration'        => 'string',
		'_fbd_duration_bucket' => 'bucket',
		'_fbd_pax_min'         => 'int',
		'_fbd_pax_max'         => 'int',
		'_fbd_pax_default'     => 'int',
		'_fbd_pickup_included' => 'enum_yesno',
		'_fbd_cancellation'    => 'string',
		'_fbd_deposit_pct'     => 'pct',
	);

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	/* ───────────────────────── Settings (defaults) ───────────────────────── */

	/** Module default settings applied when flagging a product with no per-product value. */
	public static function default_settings() {
		return array(
			'default_pax_min'      => 1,
			'default_pax_max'      => 12,
			'default_pax_default'  => 2,
			'default_pickup'       => false,
			'default_cancellation' => '',
			'default_time_slots'   => array(),
			'default_addons'       => array(),
		);
	}

	public static function get_all_settings() {
		$out = array();
		foreach ( self::default_settings() as $key => $default ) {
			$out[ $key ] = get_option( self::OPTION_PREFIX . $key, $default );
		}
		return $out;
	}

	/* ───────────────────────────── REST routes ───────────────────────────── */

	public function register_endpoints() {
		$ns   = 'luwipress/v1';
		$perm = array( 'LuwiPress_Permission', 'check_token_or_admin' );

		// List bookable tours (optionally all products with ?all=1 for discovery).
		register_rest_route( $ns, '/booking/tours', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_list_tours' ),
			'permission_callback' => $perm,
			'args'                => array(
				'all'   => array( 'type' => 'boolean', 'default' => false ),
				'limit' => array( 'type' => 'integer', 'default' => 100 ),
			),
		) );

		// Single tour config + schema preview.
		register_rest_route( $ns, '/booking/tour/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_tour' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_set_tour' ),
				'permission_callback' => $perm,
			),
		) );

		// Convenience flag toggle.
		register_rest_route( $ns, '/booking/tour/(?P<id>\d+)/flag', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_flag_tour' ),
			'permission_callback' => $perm,
			'args'                => array(
				'is_tour' => array( 'type' => 'boolean', 'required' => true ),
			),
		) );

		// Module defaults.
		register_rest_route( $ns, '/booking/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_settings' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_set_settings' ),
				'permission_callback' => $perm,
			),
		) );
	}

	/* ─────────────────────────── REST handlers ───────────────────────────── */

	public function rest_list_tours( $request ) {
		if ( ! $this->wc_active() ) {
			return $this->wc_error();
		}
		$all   = (bool) $request->get_param( 'all' );
		$limit = max( 1, min( 500, (int) $request->get_param( 'limit' ) ?: 100 ) );

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		);
		if ( ! $all ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_fbd_is_tour',
					'value' => 'yes',
				),
			);
		}

		$ids   = get_posts( $args );
		$tours = array();
		foreach ( $ids as $pid ) {
			$tours[] = $this->shape_tour( (int) $pid );
		}

		return rest_ensure_response( array(
			'count' => count( $tours ),
			'tours' => $tours,
		) );
	}

	public function rest_get_tour( $request ) {
		if ( ! $this->wc_active() ) {
			return $this->wc_error();
		}
		$id = (int) $request->get_param( 'id' );
		if ( ! $this->is_product( $id ) ) {
			return new WP_Error( 'not_found', 'Product not found.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( array(
			'tour'   => $this->shape_tour( $id ),
			'schema' => $this->schema_preview( $id ),
		) );
	}

	/**
	 * POST /booking/tour/{id} — partial update of any subset of the 11 keys.
	 * This is the only validated write path for the array meta
	 * (_fbd_time_slots, _fbd_addons).
	 */
	public function rest_set_tour( $request ) {
		if ( ! $this->wc_active() ) {
			return $this->wc_error();
		}
		$id = (int) $request->get_param( 'id' );
		if ( ! $this->is_product( $id ) ) {
			return new WP_Error( 'not_found', 'Product not found.', array( 'status' => 404 ) );
		}

		$data = $request->get_json_params();
		if ( empty( $data ) ) {
			$data = $request->get_body_params();
		}
		$data = is_array( $data ) ? $data : array();

		$result = $this->apply_config( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array(
			'success' => true,
			'updated' => $result,
			'tour'    => $this->shape_tour( $id ),
		) );
	}

	public function rest_flag_tour( $request ) {
		if ( ! $this->wc_active() ) {
			return $this->wc_error();
		}
		$id = (int) $request->get_param( 'id' );
		if ( ! $this->is_product( $id ) ) {
			return new WP_Error( 'not_found', 'Product not found.', array( 'status' => 404 ) );
		}
		$result = $this->apply_config( $id, array( 'is_tour' => (bool) $request->get_param( 'is_tour' ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array(
			'success' => true,
			'updated' => $result,
			'tour'    => $this->shape_tour( $id ),
		) );
	}

	public function rest_get_settings( $request ) {
		return rest_ensure_response( array( 'settings' => self::get_all_settings() ) );
	}

	public function rest_set_settings( $request ) {
		$data = $request->get_json_params();
		if ( empty( $data ) ) {
			$data = $request->get_body_params();
		}
		$data    = is_array( $data ) ? $data : array();
		$updated = array();

		if ( array_key_exists( 'default_pax_min', $data ) ) {
			update_option( self::OPTION_PREFIX . 'default_pax_min', max( 1, absint( $data['default_pax_min'] ) ) );
			$updated[] = 'default_pax_min';
		}
		if ( array_key_exists( 'default_pax_max', $data ) ) {
			update_option( self::OPTION_PREFIX . 'default_pax_max', max( 1, absint( $data['default_pax_max'] ) ) );
			$updated[] = 'default_pax_max';
		}
		if ( array_key_exists( 'default_pax_default', $data ) ) {
			update_option( self::OPTION_PREFIX . 'default_pax_default', max( 1, absint( $data['default_pax_default'] ) ) );
			$updated[] = 'default_pax_default';
		}
		if ( array_key_exists( 'default_pickup', $data ) ) {
			update_option( self::OPTION_PREFIX . 'default_pickup', (bool) $data['default_pickup'] );
			$updated[] = 'default_pickup';
		}
		if ( array_key_exists( 'default_cancellation', $data ) ) {
			update_option( self::OPTION_PREFIX . 'default_cancellation', sanitize_text_field( (string) $data['default_cancellation'] ) );
			$updated[] = 'default_cancellation';
		}
		if ( array_key_exists( 'default_time_slots', $data ) ) {
			update_option( self::OPTION_PREFIX . 'default_time_slots', $this->sanitize_string_list( $data['default_time_slots'] ) );
			$updated[] = 'default_time_slots';
		}
		if ( array_key_exists( 'default_addons', $data ) ) {
			update_option( self::OPTION_PREFIX . 'default_addons', $this->sanitize_addons( $data['default_addons'] ) );
			$updated[] = 'default_addons';
		}

		if ( class_exists( 'LuwiPress_Logger' ) ) {
			LuwiPress_Logger::log( 'Booking settings updated via REST: ' . implode( ', ', $updated ), 'info' );
		}

		return rest_ensure_response( array(
			'success'  => true,
			'updated'  => $updated,
			'settings' => self::get_all_settings(),
		) );
	}

	/* ─────────────────────── Core config apply (shared) ──────────────────── */

	/**
	 * Validate + write a partial booking config to a product. Returns the list
	 * of updated keys, or WP_Error on a validation failure. Shared by REST and
	 * by the WebMCP companion's booking tools.
	 *
	 * Accepts both short keys (is_tour, duration, pax_min, …) and raw meta keys
	 * (_fbd_is_tour, …). pax min/max/default are clamped together.
	 *
	 * @param int   $product_id
	 * @param array $data
	 * @return array|WP_Error
	 */
	public function apply_config( $product_id, array $data ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', 'Product not found.', array( 'status' => 404 ) );
		}

		// Normalize short keys -> raw meta keys.
		$map = array(
			'is_tour'         => '_fbd_is_tour',
			'duration'        => '_fbd_duration',
			'duration_bucket' => '_fbd_duration_bucket',
			'pax_min'         => '_fbd_pax_min',
			'pax_max'         => '_fbd_pax_max',
			'pax_default'     => '_fbd_pax_default',
			'pickup_included' => '_fbd_pickup_included',
			'cancellation'    => '_fbd_cancellation',
			'deposit_pct'     => '_fbd_deposit_pct',
			'time_slots'      => '_fbd_time_slots',
			'addons'          => '_fbd_addons',
		);
		$in = array();
		foreach ( $data as $k => $v ) {
			$meta_key        = isset( $map[ $k ] ) ? $map[ $k ] : $k;
			$in[ $meta_key ] = $v;
		}

		$updated = array();

		// Scalars.
		foreach ( self::SCALAR_KEYS as $meta_key => $type ) {
			if ( ! array_key_exists( $meta_key, $in ) ) {
				continue;
			}
			$value = $in[ $meta_key ];
			switch ( $type ) {
				case 'enum_yesno':
					$value = $this->to_yesno( $value );
					break;
				case 'bucket':
					$value = (string) $value;
					if ( ! in_array( $value, self::DURATION_BUCKETS, true ) ) {
						return new WP_Error(
							'invalid_bucket',
							'duration_bucket must be one of: (empty), short, half, full, multi.',
							array( 'status' => 400 )
						);
					}
					break;
				case 'int':
					$value = max( 1, absint( $value ) );
					break;
				case 'pct':
					$value = max( 0, min( 100, (int) $value ) );
					break;
				case 'string':
				default:
					$value = sanitize_text_field( (string) $value );
					break;
			}
			$product->update_meta_data( $meta_key, $value );
			$updated[] = $meta_key;
		}

		// Arrays — the keys the theme REST can't write.
		if ( array_key_exists( '_fbd_time_slots', $in ) ) {
			$product->update_meta_data( '_fbd_time_slots', $this->sanitize_string_list( $in['_fbd_time_slots'] ) );
			$updated[] = '_fbd_time_slots';
		}
		if ( array_key_exists( '_fbd_addons', $in ) ) {
			$product->update_meta_data( '_fbd_addons', $this->sanitize_addons( $in['_fbd_addons'] ) );
			$updated[] = '_fbd_addons';
		}

		// Clamp pax trio (min <= default <= max) using the merged final state.
		$this->clamp_pax( $product );

		$product->save();

		if ( class_exists( 'LuwiPress_Logger' ) ) {
			LuwiPress_Logger::log(
				sprintf( 'Booking config updated for product %d: %s', (int) $product_id, implode( ', ', $updated ) ),
				'info'
			);
		}

		return $updated;
	}

	/** Read the normalized config. Prefers the theme's helper for an exact mirror. */
	public function shape_tour( $product_id ) {
		$product_id = (int) $product_id;
		if ( function_exists( 'lwp_amber_tour_config' ) ) {
			$cfg = lwp_amber_tour_config( $product_id );
			if ( ! empty( $cfg ) ) {
				$cfg['is_tour'] = ( 'yes' === get_post_meta( $product_id, '_fbd_is_tour', true ) );
				return $cfg;
			}
		}
		return $this->shape_tour_raw( $product_id );
	}

	/** Theme-independent reader (used on non-Amber sites). */
	private function shape_tour_raw( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array();
		}
		$pax_min = max( 1, (int) ( $product->get_meta( '_fbd_pax_min' ) ?: 1 ) );
		$pax_max = (int) ( $product->get_meta( '_fbd_pax_max' ) ?: 12 );
		if ( $pax_max < $pax_min ) {
			$pax_max = $pax_min;
		}
		$pax_default = (int) ( $product->get_meta( '_fbd_pax_default' ) ?: 2 );
		$pax_default = min( $pax_max, max( $pax_min, $pax_default ) );

		$addons = $product->get_meta( '_fbd_addons' );
		$slots  = $product->get_meta( '_fbd_time_slots' );
		$price  = $product->get_price();

		return array(
			'product_id'      => $product_id,
			'name'            => $product->get_name(),
			'is_tour'         => ( 'yes' === $product->get_meta( '_fbd_is_tour' ) ),
			'per_person'      => ( '' === $price || null === $price ) ? 0.0 : (float) $price,
			'currency'        => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'duration'        => (string) $product->get_meta( '_fbd_duration' ),
			'duration_bucket' => (string) $product->get_meta( '_fbd_duration_bucket' ),
			'pax_min'         => $pax_min,
			'pax_max'         => $pax_max,
			'pax_default'     => $pax_default,
			'pickup_included' => ( 'yes' === $product->get_meta( '_fbd_pickup_included' ) ),
			'deposit_pct'     => max( 0, min( 100, (int) $product->get_meta( '_fbd_deposit_pct' ) ) ),
			'cancellation'    => (string) $product->get_meta( '_fbd_cancellation' ),
			'time_slots'      => is_array( $slots ) ? array_values( array_filter( array_map( 'strval', $slots ) ) ) : array(),
			'addons'          => is_array( $addons ) ? array_values( $addons ) : array(),
		);
	}

	/** Minimal TouristTrip preview (the theme builds the real one via Schema Registry). */
	private function schema_preview( $product_id ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return array();
		}
		return array(
			'@context' => 'https://schema.org',
			'@type'    => 'TouristTrip',
			'name'     => $product->get_name(),
			'url'      => get_permalink( $product_id ),
			'offers'   => array(
				'@type'         => 'Offer',
				'price'         => (float) $product->get_price(),
				'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
				'url'           => get_permalink( $product_id ),
			),
		);
	}

	/* ───────────────────────────── Sanitizers ────────────────────────────── */

	/** Mirror of lwp_amber_sanitize_string_list(). */
	private function sanitize_string_list( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_text_field', $value ), static function ( $v ) {
			return '' !== trim( (string) $v );
		} ) );
	}

	/** Mirror of lwp_amber_sanitize_addons(). */
	private function sanitize_addons( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}
			$price = isset( $row['price'] ) && function_exists( 'wc_format_decimal' )
				? (float) wc_format_decimal( $row['price'] )
				: ( isset( $row['price'] ) ? (float) $row['price'] : 0.0 );
			$out[] = array( 'label' => $label, 'price' => $price );
		}
		return $out;
	}

	private function to_yesno( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, array( 'yes', '1', 'true', 'on' ), true ) ? 'yes' : 'no';
	}

	/** Re-clamp pax_default into [pax_min, pax_max] after a partial write. */
	private function clamp_pax( $product ) {
		$min = max( 1, (int) ( $product->get_meta( '_fbd_pax_min' ) ?: 1 ) );
		$max = (int) ( $product->get_meta( '_fbd_pax_max' ) ?: 12 );
		if ( $max < $min ) {
			$max = $min;
			$product->update_meta_data( '_fbd_pax_max', $max );
		}
		$default = (int) ( $product->get_meta( '_fbd_pax_default' ) ?: 2 );
		$clamped = min( $max, max( $min, $default ) );
		if ( $clamped !== $default ) {
			$product->update_meta_data( '_fbd_pax_default', $clamped );
		}
	}

	/* ─────────────────────────────── Guards ──────────────────────────────── */

	private function wc_active() {
		return class_exists( 'LuwiPress' ) ? LuwiPress::is_wc_active() : class_exists( 'WooCommerce' );
	}

	private function wc_error() {
		return new WP_Error( 'wc_inactive', 'WooCommerce is required for tour booking.', array( 'status' => 503 ) );
	}

	private function is_product( $id ) {
		$post = get_post( (int) $id );
		return $post && 'product' === $post->post_type;
	}
}
