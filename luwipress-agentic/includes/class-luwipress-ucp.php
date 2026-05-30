<?php
/**
 * LuwiPress UCP (Universal Commerce Protocol) module
 *
 * Google's open standard for agentic checkout inside AI Mode (Search) and
 * Gemini. The merchant stays Merchant of Record; distribution rides the
 * existing Merchant Center shopping feed. To become eligible for the
 * UCP-powered "Buy" button, products must carry two feed signals:
 *
 *  - `native_commerce` (boolean)  — marks a product checkout-eligible.
 *  - `consumer_notice`  (text)    — mandatory legal warning for regulated
 *                                   items (e.g. California Prop 65).
 *
 * Merchant Center also requires a return policy (cost + window + link) and
 * customer-support info, which UCP surfaces on the agentic checkout screen.
 * Finally, the feed `id` must map to the Checkout API id — when they differ
 * the `merchant_item_id` attribute carries the bridge.
 *
 * PHASE 1 (this file, feed readiness):
 *  - Per-product UCP meta (native_commerce / consumer_notice / item_id map).
 *  - Store-level settings (return policy, support info, sandbox flag).
 *  - Eligibility validator + coverage report.
 *  - Supplemental feed generator (json / csv / xml).
 *
 * PHASE 2 (class continues, native checkout):
 *  - `wp_luwipress_ucp_sessions` table + session create/update/complete REST
 *    backed by the WooCommerce cart/order pipeline.
 *
 * Soft-dep: REST endpoints register regardless of WooCommerce so an operator
 * can configure UCP on a WC-less staging install; product-bound handlers guard
 * on `wc_get_product` existence.
 *
 * @package    LuwiPress
 * @subpackage Commerce
 * @since      3.5.9-dev
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_UCP {

	/** @var self|null */
	private static $instance = null;

	const OPTION_SETTINGS = 'luwipress_ucp_settings';

	// Per-product meta keys (canonical).
	const META_NATIVE_COMMERCE = '_luwipress_ucp_native_commerce';
	const META_CONSUMER_NOTICE = '_luwipress_ucp_consumer_notice';
	const META_ITEM_ID         = '_luwipress_ucp_item_id';

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
		add_action( 'init', array( $this, 'register_meta' ), 20 );
	}

	/* ───────────────────── Settings ─────────────────────────────────── */

	/**
	 * Read settings merged over defaults. No secrets here (support info is
	 * public-facing by design), so no masking layer is needed.
	 */
	public function get_settings() {
		$defaults = array(
			'enabled'                 => false,
			'sandbox'                 => true,   // sandbox until operator validates
			'merchant_of_record'      => true,   // merchant stays MoR (UCP default)
			'default_native_commerce' => false,  // do NEW products default eligible?
			// Return policy (Merchant Center requirement, shown at checkout).
			'return_cost'             => '',      // e.g. "Free" or "9.90 USD"
			'return_window_days'      => 0,       // return window in days
			'return_policy_url'       => '',
			// Customer support info (shown at checkout).
			'support_email'           => '',
			'support_phone'           => '',
			'support_url'             => '',
			'support_hours'           => '',
			// Feed defaults.
			'feed_format'             => 'json',  // json | csv | xml
		);
		$stored = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( $defaults, $stored );
	}

	/**
	 * Partial-update settings — only present keys are touched (canonical
	 * /enrich/settings pattern). Returns the full merged settings.
	 */
	public function save_settings( $input ) {
		$current = $this->get_settings();
		if ( ! is_array( $input ) ) {
			return $current;
		}
		$allowed = array(
			'enabled'                 => 'bool',
			'sandbox'                 => 'bool',
			'merchant_of_record'      => 'bool',
			'default_native_commerce' => 'bool',
			'return_cost'             => 'text',
			'return_window_days'      => 'int',
			'return_policy_url'       => 'url',
			'support_email'           => 'email',
			'support_phone'           => 'text',
			'support_url'             => 'url',
			'support_hours'           => 'text',
			'feed_format'             => 'feed_format',
		);
		foreach ( $allowed as $key => $type ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			switch ( $type ) {
				case 'bool':
					$current[ $key ] = ! empty( $input[ $key ] );
					break;
				case 'int':
					$current[ $key ] = max( 0, (int) $input[ $key ] );
					break;
				case 'url':
					$current[ $key ] = esc_url_raw( (string) $input[ $key ] );
					break;
				case 'email':
					$current[ $key ] = sanitize_email( (string) $input[ $key ] );
					break;
				case 'feed_format':
					$fmt             = strtolower( sanitize_text_field( (string) $input[ $key ] ) );
					$current[ $key ] = in_array( $fmt, array( 'json', 'csv', 'xml' ), true ) ? $fmt : 'json';
					break;
				default:
					$current[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}
		update_option( self::OPTION_SETTINGS, $current, false );
		return $current;
	}

	/* ───────────────────── Product meta ─────────────────────────────── */

	/**
	 * Register UCP product meta so every write path (REST, MCP, wp-cli,
	 * third-party hooks) is sanitized at the meta API boundary — the same
	 * write-canonical discipline used for `_lwp_vendor_ids`.
	 */
	public function register_meta() {
		if ( ! post_type_exists( 'product' ) ) {
			return; // WooCommerce inactive — nothing to attach to.
		}

		register_post_meta( 'product', self::META_NATIVE_COMMERCE, array(
			'type'              => 'boolean',
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => function ( $value ) {
				return ! empty( $value ) && 'false' !== $value && '0' !== (string) $value;
			},
			'auth_callback'     => array( 'LuwiPress_Permission', 'is_admin' ),
		) );

		register_post_meta( 'product', self::META_CONSUMER_NOTICE, array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => 'sanitize_textarea_field',
			'auth_callback'     => array( 'LuwiPress_Permission', 'is_admin' ),
		) );

		register_post_meta( 'product', self::META_ITEM_ID, array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => array( 'LuwiPress_Permission', 'is_admin' ),
		) );
	}

	/* ───────────────────── Eligibility / profile ────────────────────── */

	/**
	 * Build the UCP profile for a single product: resolved attributes,
	 * the mapped checkout item id, validation warnings, and the final
	 * eligibility verdict.
	 *
	 * @param int $product_id
	 * @return array|WP_Error
	 */
	public function get_product_profile( $product_id ) {
		$product_id = (int) $product_id;
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'wc_inactive', 'WooCommerce is not active.', array( 'status' => 409 ) );
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return new WP_Error( 'product_not_found', 'Product not found.', array( 'status' => 404 ) );
		}

		$settings = $this->get_settings();

		// native_commerce: explicit meta wins; otherwise the store default.
		$raw_nc      = get_post_meta( $product_id, self::META_NATIVE_COMMERCE, true );
		$has_nc_meta = ( '' !== $raw_nc && null !== $raw_nc );
		$native      = $has_nc_meta ? (bool) $raw_nc : (bool) $settings['default_native_commerce'];

		$consumer_notice = (string) get_post_meta( $product_id, self::META_CONSUMER_NOTICE, true );
		$item_id_meta    = (string) get_post_meta( $product_id, self::META_ITEM_ID, true );
		$item_id         = '' !== $item_id_meta ? $item_id_meta : (string) $product_id;

		$attributes = $this->collect_attributes( $product );
		$warnings   = $this->validate_attributes( $attributes, $settings );

		// A product is eligible when flagged native_commerce AND no blocking
		// (severity=high) warning is present.
		$blocking = array_filter( $warnings, function ( $w ) {
			return 'high' === $w['severity'];
		} );
		$eligible = $native && empty( $blocking );

		return array(
			'product_id'        => $product_id,
			'merchant_item_id'  => $item_id,
			'id_mapped'         => ( '' !== $item_id_meta ),
			'native_commerce'   => $native,
			'native_commerce_source' => $has_nc_meta ? 'product' : 'store_default',
			'consumer_notice'   => $consumer_notice,
			'attributes'        => $attributes,
			'warnings'          => array_values( $warnings ),
			'eligible'          => $eligible,
		);
	}

	/**
	 * Set UCP product meta (partial — only present keys touched). Writes go
	 * through update_post_meta, so register_meta()'s sanitize callbacks run.
	 *
	 * @param int   $product_id
	 * @param array $input
	 * @return array|WP_Error  Fresh profile, or WP_Error.
	 */
	public function set_product_meta( $product_id, $input ) {
		$product_id = (int) $product_id;
		if ( ! function_exists( 'wc_get_product' ) || ! wc_get_product( $product_id ) ) {
			return new WP_Error( 'product_not_found', 'Product not found or WooCommerce inactive.', array( 'status' => 404 ) );
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		if ( array_key_exists( 'native_commerce', $input ) ) {
			update_post_meta( $product_id, self::META_NATIVE_COMMERCE, ! empty( $input['native_commerce'] ) );
		}
		if ( array_key_exists( 'consumer_notice', $input ) ) {
			update_post_meta( $product_id, self::META_CONSUMER_NOTICE, (string) $input['consumer_notice'] );
		}
		if ( array_key_exists( 'merchant_item_id', $input ) ) {
			$mapped = sanitize_text_field( (string) $input['merchant_item_id'] );
			if ( '' === $mapped ) {
				delete_post_meta( $product_id, self::META_ITEM_ID );
			} else {
				update_post_meta( $product_id, self::META_ITEM_ID, $mapped );
			}
		}
		return $this->get_product_profile( $product_id );
	}

	/**
	 * Pull the commerce attributes UCP/agents need to compute total cost and
	 * render the checkout. Duck-typed WC_Product access (the codebase avoids
	 * `instanceof WC_Product` because WC isn't in the PHPStan stub set).
	 *
	 * @param object $product
	 * @return array
	 */
	private function collect_attributes( $product ) {
		$price = method_exists( $product, 'get_price' ) ? $product->get_price() : '';
		$gtin  = '';
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$gtin = (string) $product->get_global_unique_id(); // WC 8.6+ native GTIN
		}
		$brand = $this->resolve_brand( $product );
		$pid   = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
		$image_id = method_exists( $product, 'get_image_id' ) ? $product->get_image_id() : 0;

		return array(
			'title'        => method_exists( $product, 'get_name' ) ? $product->get_name() : '',
			'sku'          => method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '',
			'gtin'         => $gtin,
			'brand'        => $brand,
			'price'        => ( '' === $price || null === $price ) ? null : (float) $price,
			'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'in_stock'     => method_exists( $product, 'is_in_stock' ) ? (bool) $product->is_in_stock() : false,
			'purchasable'  => method_exists( $product, 'is_purchasable' ) ? (bool) $product->is_purchasable() : false,
			'has_image'    => ! empty( $image_id ),
			'permalink'    => $pid ? (string) get_permalink( $pid ) : '',
			'type'         => method_exists( $product, 'get_type' ) ? $product->get_type() : '',
		);
	}

	/**
	 * Resolve a brand string from common sources (WC brand taxonomy, then
	 * Google-feed brand meta keys used by popular feed plugins).
	 */
	private function resolve_brand( $product ) {
		$pid = method_exists( $product, 'get_id' ) ? $product->get_id() : 0;
		if ( ! $pid ) {
			return '';
		}
		// WooCommerce native Brands taxonomy (WC 9.6+) and common variants.
		foreach ( array( 'product_brand', 'pwb-brand', 'pa_brand' ) as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$terms = get_the_terms( $pid, $tax );
				if ( is_array( $terms ) && ! empty( $terms ) ) {
					return $terms[0]->name;
				}
			}
		}
		// Feed-plugin brand meta fallbacks.
		foreach ( array( '_wc_gla_brand', 'google_brand', '_brand' ) as $key ) {
			$val = get_post_meta( $pid, $key, true );
			if ( ! empty( $val ) && is_string( $val ) ) {
				return $val;
			}
		}
		return '';
	}

	/**
	 * Grade a product's attributes against UCP/Merchant-Center requirements.
	 * `high` severity blocks eligibility; `medium`/`low` are advisory.
	 *
	 * @return array<int,array{code:string,severity:string,message:string}>
	 */
	private function validate_attributes( $attributes, $settings ) {
		$warnings = array();

		if ( null === $attributes['price'] || $attributes['price'] <= 0 ) {
			$warnings[] = array( 'code' => 'missing_price', 'severity' => 'high', 'message' => 'Product has no positive price — agents cannot compute total cost.' );
		}
		if ( ! $attributes['in_stock'] || ! $attributes['purchasable'] ) {
			$warnings[] = array( 'code' => 'not_purchasable', 'severity' => 'high', 'message' => 'Product is out of stock or not purchasable.' );
		}
		if ( ! $attributes['has_image'] ) {
			$warnings[] = array( 'code' => 'missing_image', 'severity' => 'medium', 'message' => 'No featured image — checkout surfaces show a product image.' );
		}
		if ( '' === $attributes['gtin'] && ( '' === $attributes['brand'] || '' === $attributes['sku'] ) ) {
			$warnings[] = array( 'code' => 'weak_identifiers', 'severity' => 'medium', 'message' => 'No GTIN and incomplete brand+MPN(SKU) — provide a GTIN or a brand + MPN pair.' );
		}
		// Store-level return policy is a Merchant-of-Record requirement.
		if ( empty( $settings['return_policy_url'] ) && empty( $settings['return_window_days'] ) ) {
			$warnings[] = array( 'code' => 'no_return_policy', 'severity' => 'high', 'message' => 'No store return policy configured (return window + link required by Merchant Center).' );
		}
		if ( empty( $settings['support_email'] ) && empty( $settings['support_url'] ) && empty( $settings['support_phone'] ) ) {
			$warnings[] = array( 'code' => 'no_support_info', 'severity' => 'medium', 'message' => 'No customer-support contact configured (shown on the checkout screen).' );
		}
		if ( 'variable' === $attributes['type'] ) {
			$warnings[] = array( 'code' => 'variable_product', 'severity' => 'low', 'message' => 'Variable product — each purchasable variation needs its own feed row / item id.' );
		}

		return $warnings;
	}

	/**
	 * Store-wide eligibility coverage report. Counts are exact; the
	 * missing-attribute breakdown is sampled (bounded by $sample) to keep
	 * the call cheap on large catalogs.
	 *
	 * @param int $sample  Max products to deep-validate for the breakdown.
	 * @return array
	 */
	public function get_eligibility_report( $sample = 100 ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array( 'wc_active' => false );
		}
		$sample = max( 1, min( 500, (int) $sample ) );

		$counts        = (array) wp_count_posts( 'product' );
		$total_publish = isset( $counts['publish'] ) ? (int) $counts['publish'] : 0;

		// Exact count of products explicitly flagged native_commerce=true.
		$flagged = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'     => self::META_NATIVE_COMMERCE,
					'value'   => '1',
					'compare' => '=',
				),
			),
			'no_found_rows'  => true,
		) );
		$flagged_count = count( $flagged );

		// Sample for the missing-attribute breakdown.
		$ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => $sample,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );

		$settings   = $this->get_settings();
		$breakdown  = array();
		$eligible   = 0;
		$sampled    = 0;
		foreach ( (array) $ids as $pid ) {
			$profile = $this->get_product_profile( (int) $pid );
			if ( is_wp_error( $profile ) ) {
				continue;
			}
			$sampled++;
			if ( $profile['eligible'] ) {
				$eligible++;
			}
			foreach ( $profile['warnings'] as $w ) {
				$code               = $w['code'];
				$breakdown[ $code ] = isset( $breakdown[ $code ] ) ? $breakdown[ $code ] + 1 : 1;
			}
		}

		return array(
			'wc_active'                 => true,
			'enabled'                   => (bool) $settings['enabled'],
			'sandbox'                   => (bool) $settings['sandbox'],
			'total_published_products'  => $total_publish,
			'native_commerce_flagged'   => $flagged_count,
			'default_native_commerce'   => (bool) $settings['default_native_commerce'],
			'return_policy_configured'  => ( ! empty( $settings['return_policy_url'] ) || ! empty( $settings['return_window_days'] ) ),
			'support_info_configured'   => ( ! empty( $settings['support_email'] ) || ! empty( $settings['support_url'] ) || ! empty( $settings['support_phone'] ) ),
			'sample'                    => array(
				'size'              => $sampled,
				'eligible'          => $eligible,
				'warning_breakdown' => $breakdown,
			),
			'detected_feed_plugin'      => class_exists( 'LuwiPress_Plugin_Detector' )
				? LuwiPress_Plugin_Detector::get_instance()->detect_product_feed()
				: null,
		);
	}

	/* ───────────────────── Supplemental feed ────────────────────────── */

	/**
	 * Build the UCP supplemental feed rows. We deliberately emit a SUPPLEMENTAL
	 * feed (id + UCP attributes only), so it overlays the primary shopping feed
	 * without touching its product data — Google's recommended approach.
	 *
	 * @param string $include 'eligible' (default) or 'all'.
	 * @param int    $limit
	 * @return array<int,array>
	 */
	public function build_feed_rows( $include = 'eligible', $limit = 1000 ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$limit    = max( 1, min( 5000, (int) $limit ) );
		$settings = $this->get_settings();

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => $limit,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);
		// 'eligible' restricts to explicitly-flagged products unless the store
		// defaults everything to native_commerce.
		if ( 'all' !== $include && empty( $settings['default_native_commerce'] ) ) {
			$args['meta_query'] = array(
				array(
					'key'     => self::META_NATIVE_COMMERCE,
					'value'   => '1',
					'compare' => '=',
				),
			);
		}

		$ids  = get_posts( $args );
		$rows = array();
		foreach ( (array) $ids as $pid ) {
			$profile = $this->get_product_profile( (int) $pid );
			if ( is_wp_error( $profile ) ) {
				continue;
			}
			if ( 'all' !== $include && ! $profile['native_commerce'] ) {
				continue;
			}
			$rows[] = array(
				'id'               => $profile['merchant_item_id'],
				'merchant_item_id' => $profile['merchant_item_id'],
				'native_commerce'  => $profile['native_commerce'] ? 'true' : 'false',
				'consumer_notice'  => $profile['consumer_notice'],
			);
		}
		return $rows;
	}

	/**
	 * Render feed rows in the requested format. Returns a WP_REST_Response so
	 * the Content-Type is correct for csv/xml downloads.
	 *
	 * @return WP_REST_Response
	 */
	public function render_feed( $format, $rows ) {
		$format = in_array( $format, array( 'json', 'csv', 'xml' ), true ) ? $format : 'json';

		if ( 'csv' === $format ) {
			$lines = array( 'id,merchant_item_id,native_commerce,consumer_notice' );
			foreach ( $rows as $r ) {
				$lines[] = implode( ',', array(
					$this->csv_cell( $r['id'] ),
					$this->csv_cell( $r['merchant_item_id'] ),
					$this->csv_cell( $r['native_commerce'] ),
					$this->csv_cell( $r['consumer_notice'] ),
				) );
			}
			$resp = new WP_REST_Response( implode( "\n", $lines ) );
			$resp->header( 'Content-Type', 'text/csv; charset=utf-8' );
			return $resp;
		}

		if ( 'xml' === $format ) {
			$xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
			$xml .= "<rss version=\"2.0\" xmlns:g=\"http://base.google.com/ns/1.0\">\n  <channel>\n";
			foreach ( $rows as $r ) {
				$xml .= "    <item>\n";
				$xml .= '      <g:id>' . esc_html( $r['id'] ) . "</g:id>\n";
				$xml .= '      <g:native_commerce>' . esc_html( $r['native_commerce'] ) . "</g:native_commerce>\n";
				if ( '' !== $r['consumer_notice'] ) {
					$xml .= '      <g:consumer_notice>' . esc_html( $r['consumer_notice'] ) . "</g:consumer_notice>\n";
				}
				$xml .= "    </item>\n";
			}
			$xml .= "  </channel>\n</rss>\n";
			$resp = new WP_REST_Response( $xml );
			$resp->header( 'Content-Type', 'application/xml; charset=utf-8' );
			return $resp;
		}

		return new WP_REST_Response( array(
			'generated_at' => current_time( 'mysql' ),
			'format'       => 'json',
			'count'        => count( $rows ),
			'products'     => array_values( $rows ),
		) );
	}

	private function csv_cell( $value ) {
		$value = (string) $value;
		if ( false !== strpos( $value, ',' ) || false !== strpos( $value, '"' ) || false !== strpos( $value, "\n" ) ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}

	/* ───────────────────── REST ─────────────────────────────────────── */

	public function register_endpoints() {
		$auth = array( 'LuwiPress_Permission', 'check_token_or_admin' );

		register_rest_route( 'luwipress/v1', '/ucp/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_settings' ),
				'permission_callback' => $auth,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_save_settings' ),
				'permission_callback' => $auth,
			),
		) );

		register_rest_route( 'luwipress/v1', '/ucp/eligibility', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_eligibility' ),
			'permission_callback' => $auth,
			'args'                => array(
				'sample' => array( 'default' => 100, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( 'luwipress/v1', '/ucp/product/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_product' ),
				'permission_callback' => $auth,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_set_product' ),
				'permission_callback' => $auth,
			),
		) );

		register_rest_route( 'luwipress/v1', '/ucp/feed', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_feed' ),
			'permission_callback' => $auth,
			'args'                => array(
				'format'  => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'include' => array( 'default' => 'eligible', 'sanitize_callback' => 'sanitize_text_field' ),
				'limit'   => array( 'default' => 1000, 'sanitize_callback' => 'absint' ),
			),
		) );
	}

	public function rest_get_settings() {
		return rest_ensure_response( $this->get_settings() );
	}

	public function rest_save_settings( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}
		return rest_ensure_response( $this->save_settings( $body ) );
	}

	public function rest_eligibility( $request ) {
		return rest_ensure_response( $this->get_eligibility_report( (int) $request->get_param( 'sample' ) ) );
	}

	public function rest_get_product( $request ) {
		$profile = $this->get_product_profile( (int) $request['id'] );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		return rest_ensure_response( $profile );
	}

	public function rest_set_product( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}
		$profile = $this->set_product_meta( (int) $request['id'], $body );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		return rest_ensure_response( $profile );
	}

	public function rest_feed( $request ) {
		$format = strtolower( (string) $request->get_param( 'format' ) );
		if ( '' === $format ) {
			$settings = $this->get_settings();
			$format   = $settings['feed_format'];
		}
		$rows = $this->build_feed_rows(
			(string) $request->get_param( 'include' ),
			(int) $request->get_param( 'limit' )
		);
		return $this->render_feed( $format, $rows );
	}
}
