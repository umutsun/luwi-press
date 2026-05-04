<?php
/**
 * eBay Marketplace Adapter
 *
 * Publishes WooCommerce products to eBay via Inventory API.
 *
 * @package LuwiPress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Marketplace_Ebay implements LuwiPress_Marketplace_Adapter {

	private static $endpoints = array(
		'sandbox'    => 'https://api.sandbox.ebay.com',
		'production' => 'https://api.ebay.com',
	);

	/** @return string */
	public function get_name() {
		return 'ebay';
	}

	/** @return string */
	public function get_label() {
		return 'eBay';
	}

	/** @return bool */
	public function is_configured() {
		return ! empty( get_option( 'luwipress_ebay_api_key', '' ) );
	}

	/**
	 * Test connection by getting user identity.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'eBay API credentials not configured.', 'luwipress' ) );
		}

		$response = $this->api_request( 'GET', '/sell/account/v1/privilege' );
		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Publish product to eBay via Inventory API.
	 *
	 * @param array $product_data Mapped product data.
	 * @return array|WP_Error
	 */
	public function publish_product( array $product_data ) {
		$sku = $product_data['sku'];

		// Step 1: Create inventory item
		$response = $this->api_request( 'PUT', '/sell/inventory/v1/inventory_item/' . rawurlencode( $sku ), array(
			'availability' => array(
				'shipToLocationAvailability' => array(
					'quantity' => $product_data['quantity'],
				),
			),
			'condition'    => 'NEW',
			'product'      => array(
				'title'       => $product_data['title'],
				'description' => $product_data['description'],
				'imageUrls'   => $product_data['image_urls'],
				'aspects'     => $product_data['aspects'] ?? array(),
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Step 2: Create offer
		$offer = $this->api_request( 'POST', '/sell/inventory/v1/offer', array(
			'sku'               => $sku,
			'marketplaceId'     => get_option( 'luwipress_ebay_marketplace_id', 'EBAY_US' ),
			'format'            => 'FIXED_PRICE',
			'listingDescription' => $product_data['description'],
			'pricingSummary'    => array(
				'price' => array(
					'value'    => $product_data['price'],
					'currency' => $product_data['currency'],
				),
			),
			'availableQuantity' => $product_data['quantity'],
		) );

		if ( is_wp_error( $offer ) ) {
			return $offer;
		}

		$offer_id = $offer['offerId'] ?? '';

		// Step 3: Publish offer
		if ( ! empty( $offer_id ) ) {
			$publish = $this->api_request( 'POST', '/sell/inventory/v1/offer/' . rawurlencode( $offer_id ) . '/publish' );
			if ( is_wp_error( $publish ) ) {
				return $publish;
			}
		}

		return array(
			'marketplace_product_id' => $offer_id ?: $sku,
			'listing_id'             => $publish['listingId'] ?? '',
			'status'                 => 'PUBLISHED',
		);
	}

	/**
	 * Update existing eBay listing.
	 *
	 * @param string $marketplace_product_id Offer ID.
	 * @param array  $product_data           Mapped product data.
	 * @return true|WP_Error
	 */
	public function update_product( $marketplace_product_id, array $product_data ) {
		// Update inventory item
		$response = $this->api_request( 'PUT', '/sell/inventory/v1/inventory_item/' . rawurlencode( $product_data['sku'] ), array(
			'availability' => array(
				'shipToLocationAvailability' => array(
					'quantity' => $product_data['quantity'],
				),
			),
			'condition'    => 'NEW',
			'product'      => array(
				'title'       => $product_data['title'],
				'description' => $product_data['description'],
				'imageUrls'   => $product_data['image_urls'],
			),
		) );

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Delete eBay listing.
	 *
	 * @param string $marketplace_product_id Offer ID.
	 * @return true|WP_Error
	 */
	public function delete_product( $marketplace_product_id ) {
		$response = $this->api_request( 'DELETE', '/sell/inventory/v1/offer/' . rawurlencode( $marketplace_product_id ) );
		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Map WC product to eBay format.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @return array
	 */
	public function map_product_data( $product ) {
		$image_url  = wp_get_attachment_url( $product->get_image_id() );
		$gallery    = array_filter( array_map( 'wp_get_attachment_url', $product->get_gallery_image_ids() ) );
		$image_urls = array_values( array_filter( array_merge( array( $image_url ), $gallery ) ) );

		// Build aspects from attributes
		$aspects = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( is_a( $attr, 'WC_Product_Attribute' ) ) {
				$name    = $attr->get_name();
				$options = $attr->get_options();
				if ( ! empty( $options ) ) {
					$aspects[ wc_attribute_label( $name ) ] = array_map( 'strval', $options );
				}
			}
		}

		$brand = $product->get_attribute( 'brand' );
		if ( ! empty( $brand ) ) {
			$aspects['Brand'] = array( $brand );
		}

		return array(
			'sku'         => $product->get_sku() ?: 'LP-' . $product->get_id(),
			'title'       => mb_substr( $product->get_name(), 0, 80 ),
			'description' => $product->get_description() ?: $product->get_short_description(),
			'price'       => $product->get_regular_price(),
			'sale_price'  => $product->get_sale_price(),
			'currency'    => get_woocommerce_currency(),
			'quantity'    => $product->get_stock_quantity() ?: 0,
			'image_urls'  => $image_urls,
			'aspects'     => $aspects,
			'weight'      => $product->get_weight(),
			'dimensions'  => array(
				'length' => $product->get_length(),
				'width'  => $product->get_width(),
				'height' => $product->get_height(),
			),
		);
	}

	/**
	 * Get eBay category suggestions.
	 *
	 * @param string $query Search query.
	 * @return array|WP_Error
	 */
	public function get_categories( $query = '' ) {
		$marketplace_id = get_option( 'luwipress_ebay_marketplace_id', 'EBAY_US' );
		$response = $this->api_request( 'GET', '/commerce/taxonomy/v1/category_tree/0/get_categories_by_keyword', array(
			'q' => $query ?: 'general',
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['categorySuggestions'] ?? array();
	}

	// ────────────────────────────────────────────────────────────────
	// Private helpers
	// ────────────────────────────────────────────────────────────────

	/**
	 * Make an authenticated request to eBay API.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   API path.
	 * @param array  $data   Request body or query params.
	 * @return array|WP_Error
	 */
	private function api_request( $method, $path, $data = array() ) {
		$api_key  = get_option( 'luwipress_ebay_api_key', '' );
		$env      = get_option( 'luwipress_ebay_environment', 'production' );
		$base_url = self::$endpoints[ $env ] ?? self::$endpoints['production'];

		$url = $base_url . $path;

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'LuwiPress/' . LUWIPRESS_VERSION,
			),
		);

		if ( 'GET' === $method && ! empty( $data ) ) {
			$url = add_query_arg( $data, $url );
		} elseif ( ! empty( $data ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			LuwiPress_Logger::log( 'eBay API error: ' . $response->get_error_message(), 'error', array( 'path' => $path ) );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$error_msg = $body['errors'][0]['message'] ?? "eBay API returned HTTP {$code}";
			LuwiPress_Logger::log( 'eBay API error: ' . $error_msg, 'error', array( 'path' => $path, 'code' => $code ) );
			return new WP_Error( 'ebay_api_error', $error_msg, array( 'status' => $code ) );
		}

		// eBay returns 204 No Content for some PUT/DELETE operations
		if ( 204 === $code ) {
			return array( 'success' => true );
		}

		return $body ?: array();
	}
}
