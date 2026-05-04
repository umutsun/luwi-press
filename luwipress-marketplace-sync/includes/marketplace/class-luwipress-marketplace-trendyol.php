<?php
/**
 * Trendyol Marketplace Adapter
 *
 * Publishes WooCommerce products to Trendyol via Integration API.
 *
 * @package LuwiPress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Marketplace_Trendyol implements LuwiPress_Marketplace_Adapter {

	private static $base_url = 'https://api.trendyol.com/sapigw';

	/** @return string */
	public function get_name() {
		return 'trendyol';
	}

	/** @return string */
	public function get_label() {
		return 'Trendyol';
	}

	/** @return bool */
	public function is_configured() {
		return ! empty( get_option( 'luwipress_trendyol_api_key', '' ) )
			&& ! empty( get_option( 'luwipress_trendyol_api_secret', '' ) )
			&& ! empty( get_option( 'luwipress_trendyol_seller_id', '' ) );
	}

	/**
	 * Test connection by fetching supplier addresses.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Trendyol API credentials not configured.', 'luwipress' ) );
		}

		$seller_id = get_option( 'luwipress_trendyol_seller_id', '' );
		$response  = $this->api_request( 'GET', '/suppliers/' . $seller_id . '/addresses' );

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Publish product to Trendyol.
	 *
	 * @param array $product_data Mapped product data.
	 * @return array|WP_Error
	 */
	public function publish_product( array $product_data ) {
		$seller_id = get_option( 'luwipress_trendyol_seller_id', '' );

		$response = $this->api_request( 'POST', '/suppliers/' . $seller_id . '/v2/products', array(
			'items' => array( $product_data ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'marketplace_product_id' => $response['batchRequestId'] ?? $product_data['barcode'],
			'batch_id'               => $response['batchRequestId'] ?? '',
			'status'                 => 'PROCESSING',
		);
	}

	/**
	 * Update existing Trendyol listing.
	 *
	 * @param string $marketplace_product_id Barcode.
	 * @param array  $product_data           Mapped product data.
	 * @return true|WP_Error
	 */
	public function update_product( $marketplace_product_id, array $product_data ) {
		$seller_id = get_option( 'luwipress_trendyol_seller_id', '' );

		$response = $this->api_request( 'PUT', '/suppliers/' . $seller_id . '/v2/products', array(
			'items' => array( $product_data ),
		) );

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Delete Trendyol listing.
	 *
	 * @param string $marketplace_product_id Barcode.
	 * @return true|WP_Error
	 */
	public function delete_product( $marketplace_product_id ) {
		$seller_id = get_option( 'luwipress_trendyol_seller_id', '' );

		$response = $this->api_request( 'DELETE', '/suppliers/' . $seller_id . '/v2/products', array(
			'items' => array(
				array( 'barcode' => $marketplace_product_id ),
			),
		) );

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Map WC product to Trendyol format.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @return array
	 */
	public function map_product_data( $product ) {
		$image_url = wp_get_attachment_url( $product->get_image_id() );
		$gallery   = array_filter( array_map( 'wp_get_attachment_url', $product->get_gallery_image_ids() ) );

		$images = array();
		if ( ! empty( $image_url ) ) {
			$images[] = array( 'url' => $image_url );
		}
		foreach ( $gallery as $url ) {
			$images[] = array( 'url' => $url );
		}

		// Build attributes array from WC attributes
		$attributes = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( is_a( $attr, 'WC_Product_Attribute' ) ) {
				$options = $attr->get_options();
				if ( ! empty( $options ) ) {
					$attributes[] = array(
						'attributeName'  => wc_attribute_label( $attr->get_name() ),
						'attributeValue' => implode( ', ', $options ),
					);
				}
			}
		}

		$brand = $product->get_attribute( 'brand' ) ?: get_bloginfo( 'name' );

		return array(
			'barcode'          => $product->get_sku() ?: 'LP-' . $product->get_id(),
			'title'            => $product->get_name(),
			'productMainId'    => (string) $product->get_id(),
			'brandName'        => $brand,
			'categoryName'     => $this->get_primary_category( $product ),
			'quantity'         => $product->get_stock_quantity() ?: 0,
			'stockCode'        => $product->get_sku() ?: 'LP-' . $product->get_id(),
			'dimensionalWeight' => floatval( $product->get_weight() ) ?: 0,
			'description'      => wp_strip_all_tags( $product->get_description() ?: $product->get_short_description() ),
			'currencyType'     => get_woocommerce_currency() === 'TRY' ? 'TRY' : get_woocommerce_currency(),
			'listPrice'        => floatval( $product->get_regular_price() ),
			'salePrice'        => floatval( $product->get_price() ),
			'vatRate'          => $this->get_vat_rate( $product ),
			'cargoCompanyId'   => absint( get_option( 'luwipress_trendyol_cargo_company_id', 10 ) ),
			'images'           => $images,
			'attributes'       => $attributes,
		);
	}

	/**
	 * Get Trendyol category list.
	 *
	 * @param string $query Search query.
	 * @return array|WP_Error
	 */
	public function get_categories( $query = '' ) {
		$response = $this->api_request( 'GET', '/product-categories' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$categories = $response['categories'] ?? array();

		// Filter by query if provided
		if ( ! empty( $query ) ) {
			$query = mb_strtolower( $query );
			$categories = array_filter( $categories, function ( $cat ) use ( $query ) {
				return false !== mb_strpos( mb_strtolower( $cat['name'] ?? '' ), $query );
			} );
		}

		return array_values( $categories );
	}

	// ────────────────────────────────────────────────────────────────
	// Private helpers
	// ────────────────────────────────────────────────────────────────

	/**
	 * Make an authenticated request to Trendyol API.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   API path.
	 * @param array  $data   Request body or query params.
	 * @return array|WP_Error
	 */
	private function api_request( $method, $path, $data = array() ) {
		$api_key    = get_option( 'luwipress_trendyol_api_key', '' );
		$api_secret = get_option( 'luwipress_trendyol_api_secret', '' );

		$url = self::$base_url . $path;

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				// Trendyol uses Basic auth with API Key:Secret
				'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'LuwiPress/' . LUWIPRESS_VERSION . ' - SelfIntegration',
			),
		);

		if ( 'GET' === $method && ! empty( $data ) ) {
			$url = add_query_arg( $data, $url );
		} elseif ( ! empty( $data ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			LuwiPress_Logger::log( 'Trendyol API error: ' . $response->get_error_message(), 'error', array( 'path' => $path ) );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$error_msg = $body['errors'][0]['message'] ?? "Trendyol API returned HTTP {$code}";
			LuwiPress_Logger::log( 'Trendyol API error: ' . $error_msg, 'error', array( 'path' => $path, 'code' => $code ) );
			return new WP_Error( 'trendyol_api_error', $error_msg, array( 'status' => $code ) );
		}

		return $body ?: array();
	}

	/**
	 * Get primary WC category name.
	 *
	 * @param WC_Product $product
	 * @return string
	 */
	private function get_primary_category( $product ) {
		$cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		return ! empty( $cats ) && ! is_wp_error( $cats ) ? $cats[0] : '';
	}

	/**
	 * Get VAT rate from WC tax class.
	 *
	 * @param WC_Product $product
	 * @return int
	 */
	private function get_vat_rate( $product ) {
		$tax_class = $product->get_tax_class();
		if ( empty( $tax_class ) ) {
			return 18; // Default Turkish VAT
		}

		$rates = WC_Tax::get_rates_for_tax_class( $tax_class );
		if ( ! empty( $rates ) ) {
			$rate = reset( $rates );
			return intval( $rate->tax_rate );
		}

		return 18;
	}
}
