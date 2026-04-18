<?php
/**
 * Amazon Marketplace Adapter
 *
 * Publishes WooCommerce products to Amazon via SP-API.
 *
 * @package LuwiPress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Marketplace_Amazon implements LuwiPress_Marketplace_Adapter {

	/**
	 * Amazon SP-API base URLs per region.
	 */
	private static $endpoints = array(
		'na' => 'https://sellingpartnerapi-na.amazon.com',
		'eu' => 'https://sellingpartnerapi-eu.amazon.com',
		'fe' => 'https://sellingpartnerapi-fe.amazon.com',
	);

	/** @return string */
	public function get_name() {
		return 'amazon';
	}

	/** @return string */
	public function get_label() {
		return 'Amazon';
	}

	/** @return bool */
	public function is_configured() {
		return ! empty( get_option( 'luwipress_amazon_api_key', '' ) )
			&& ! empty( get_option( 'luwipress_amazon_seller_id', '' ) );
	}

	/**
	 * Test connection by hitting the sellers endpoint.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Amazon API credentials not configured.', 'luwipress' ) );
		}

		$response = $this->api_request( 'GET', '/sellers/v1/marketplaceParticipations' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Publish product to Amazon.
	 *
	 * @param array $product_data Mapped product data.
	 * @return array|WP_Error
	 */
	public function publish_product( array $product_data ) {
		$response = $this->api_request( 'POST', '/listings/2021-08-01/items/' . rawurlencode( get_option( 'luwipress_amazon_seller_id' ) ) . '/' . rawurlencode( $product_data['sku'] ), array(
			'productType' => $product_data['product_type'] ?? 'PRODUCT',
			'attributes'  => $product_data,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'marketplace_product_id' => $product_data['sku'],
			'status'                 => $response['status'] ?? 'ACCEPTED',
		);
	}

	/**
	 * Update existing Amazon listing.
	 *
	 * @param string $marketplace_product_id SKU.
	 * @param array  $product_data           Mapped product data.
	 * @return true|WP_Error
	 */
	public function update_product( $marketplace_product_id, array $product_data ) {
		$response = $this->api_request( 'PUT', '/listings/2021-08-01/items/' . rawurlencode( get_option( 'luwipress_amazon_seller_id' ) ) . '/' . rawurlencode( $marketplace_product_id ), array(
			'productType' => $product_data['product_type'] ?? 'PRODUCT',
			'attributes'  => $product_data,
		) );

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Delete Amazon listing.
	 *
	 * @param string $marketplace_product_id SKU.
	 * @return true|WP_Error
	 */
	public function delete_product( $marketplace_product_id ) {
		$response = $this->api_request( 'DELETE', '/listings/2021-08-01/items/' . rawurlencode( get_option( 'luwipress_amazon_seller_id' ) ) . '/' . rawurlencode( $marketplace_product_id ) );
		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Map WC product to Amazon format.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @return array
	 */
	public function map_product_data( $product ) {
		$image_url  = wp_get_attachment_url( $product->get_image_id() );
		$gallery    = array_filter( array_map( 'wp_get_attachment_url', $product->get_gallery_image_ids() ) );
		$categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );

		return array(
			'sku'               => $product->get_sku() ?: 'LP-' . $product->get_id(),
			'title'             => $product->get_name(),
			'description'       => wp_strip_all_tags( $product->get_description() ),
			'bullet_points'     => $this->extract_bullet_points( $product ),
			'price'             => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'currency'          => get_woocommerce_currency(),
			'quantity'          => $product->get_stock_quantity() ?: 0,
			'main_image_url'    => $image_url ?: '',
			'gallery_image_urls' => array_values( $gallery ),
			'brand'             => $this->get_brand( $product ),
			'categories'        => is_array( $categories ) ? $categories : array(),
			'weight'            => $product->get_weight(),
			'weight_unit'       => get_option( 'woocommerce_weight_unit', 'kg' ),
			'dimensions'        => array(
				'length' => $product->get_length(),
				'width'  => $product->get_width(),
				'height' => $product->get_height(),
			),
			'dimension_unit'    => get_option( 'woocommerce_dimension_unit', 'cm' ),
			'product_type'      => 'PRODUCT',
		);
	}

	/**
	 * Get Amazon category suggestions.
	 *
	 * @param string $query Search query.
	 * @return array|WP_Error
	 */
	public function get_categories( $query = '' ) {
		$params = array( 'keywords' => $query ?: 'general' );
		$response = $this->api_request( 'GET', '/catalog/2022-04-01/items', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['items'] ?? array();
	}

	// ────────────────────────────────────────────────────────────────
	// Private helpers
	// ────────────────────────────────────────────────────────────────

	/**
	 * Make an authenticated request to Amazon SP-API.
	 *
	 * @param string $method   HTTP method.
	 * @param string $path     API path.
	 * @param array  $data     Request body or query params.
	 * @return array|WP_Error
	 */
	private function api_request( $method, $path, $data = array() ) {
		$api_key   = get_option( 'luwipress_amazon_api_key', '' );
		$region    = get_option( 'luwipress_amazon_region', 'eu' );
		$base_url  = self::$endpoints[ $region ] ?? self::$endpoints['eu'];

		$url = $base_url . $path;

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'x-amz-access-token' => $api_key,
				'Content-Type'       => 'application/json',
				'User-Agent'         => 'LuwiPress/' . LUWIPRESS_VERSION,
			),
		);

		if ( 'GET' === $method && ! empty( $data ) ) {
			$url = add_query_arg( $data, $url );
		} elseif ( ! empty( $data ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			LuwiPress_Logger::log( 'Amazon API error: ' . $response->get_error_message(), 'error', array( 'path' => $path ) );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$error_msg = $body['errors'][0]['message'] ?? "Amazon API returned HTTP {$code}";
			LuwiPress_Logger::log( 'Amazon API error: ' . $error_msg, 'error', array( 'path' => $path, 'code' => $code ) );
			return new WP_Error( 'amazon_api_error', $error_msg, array( 'status' => $code ) );
		}

		return $body ?: array();
	}

	/**
	 * Extract bullet points from short description or attributes.
	 *
	 * @param WC_Product $product
	 * @return array
	 */
	private function extract_bullet_points( $product ) {
		$short = $product->get_short_description();
		if ( empty( $short ) ) {
			return array();
		}

		// Try to split by <li> tags
		if ( preg_match_all( '/<li[^>]*>(.*?)<\/li>/si', $short, $matches ) ) {
			return array_map( 'wp_strip_all_tags', $matches[1] );
		}

		// Fall back to first 5 sentences
		$sentences = preg_split( '/(?<=[.!?])\s+/', wp_strip_all_tags( $short ), 5 );
		return array_filter( $sentences );
	}

	/**
	 * Get brand from product attributes or global option.
	 *
	 * @param WC_Product $product
	 * @return string
	 */
	private function get_brand( $product ) {
		// Check pa_brand attribute
		$brand = $product->get_attribute( 'brand' );
		if ( ! empty( $brand ) ) {
			return $brand;
		}

		// Fall back to store name
		return get_bloginfo( 'name' );
	}
}
