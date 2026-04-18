<?php
/**
 * Alibaba Marketplace Adapter
 *
 * Publishes WooCommerce products to Alibaba.com via Open Platform API.
 *
 * @package LuwiPress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Marketplace_Alibaba implements LuwiPress_Marketplace_Adapter {

	private static $base_url = 'https://gw.open.1688.com/openapi';

	/** @return string */
	public function get_name() {
		return 'alibaba';
	}

	/** @return string */
	public function get_label() {
		return 'Alibaba';
	}

	/** @return bool */
	public function is_configured() {
		return ! empty( get_option( 'luwipress_alibaba_app_key', '' ) )
			&& ! empty( get_option( 'luwipress_alibaba_app_secret', '' ) );
	}

	/**
	 * Test connection.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Alibaba API credentials not configured.', 'luwipress' ) );
		}

		$response = $this->api_request( 'alibaba.account.basic', array() );
		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Publish product to Alibaba.
	 *
	 * @param array $product_data Mapped product data.
	 * @return array|WP_Error
	 */
	public function publish_product( array $product_data ) {
		$response = $this->api_request( 'alibaba.product.add', $product_data );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'marketplace_product_id' => $response['productID'] ?? '',
			'status'                 => 'PUBLISHED',
		);
	}

	/**
	 * Update existing Alibaba listing.
	 *
	 * @param string $marketplace_product_id Alibaba product ID.
	 * @param array  $product_data           Mapped product data.
	 * @return true|WP_Error
	 */
	public function update_product( $marketplace_product_id, array $product_data ) {
		$product_data['productID'] = $marketplace_product_id;
		$response = $this->api_request( 'alibaba.product.edit', $product_data );
		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Delete Alibaba listing.
	 *
	 * @param string $marketplace_product_id Alibaba product ID.
	 * @return true|WP_Error
	 */
	public function delete_product( $marketplace_product_id ) {
		$response = $this->api_request( 'alibaba.product.delete', array(
			'productID' => $marketplace_product_id,
		) );
		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Map WC product to Alibaba format.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @return array
	 */
	public function map_product_data( $product ) {
		$image_url = wp_get_attachment_url( $product->get_image_id() );
		$gallery   = array_filter( array_map( 'wp_get_attachment_url', $product->get_gallery_image_ids() ) );
		$all_images = array_values( array_filter( array_merge( array( $image_url ), $gallery ) ) );

		// Build product attributes
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

		$categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );

		return array(
			'subject'         => $product->get_name(),
			'description'     => $product->get_description() ?: $product->get_short_description(),
			'productType'     => 'wholesale',
			'categoryName'    => ! empty( $categories ) && ! is_wp_error( $categories ) ? $categories[0] : '',
			'groupID'         => 0,
			'price'           => floatval( $product->get_regular_price() ),
			'currency'        => get_woocommerce_currency(),
			'unit'            => 'piece',
			'minOrderQuantity' => 1,
			'quantityAvailable' => $product->get_stock_quantity() ?: 0,
			'productImages'   => $all_images,
			'attributes'      => $attributes,
			'weight'          => floatval( $product->get_weight() ) ?: 0,
			'dimensions'      => array(
				'length' => floatval( $product->get_length() ),
				'width'  => floatval( $product->get_width() ),
				'height' => floatval( $product->get_height() ),
			),
			'keywords'        => implode( ', ', wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) ) ?: array() ),
		);
	}

	/**
	 * Get Alibaba category list.
	 *
	 * @param string $query Search query.
	 * @return array|WP_Error
	 */
	public function get_categories( $query = '' ) {
		$response = $this->api_request( 'alibaba.category.search', array(
			'keyword' => $query ?: 'general',
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['categories'] ?? array();
	}

	// ────────────────────────────────────────────────────────────────
	// Private helpers
	// ────────────────────────────────────────────────────────────────

	/**
	 * Make a signed request to Alibaba Open Platform.
	 *
	 * @param string $api_name API method name.
	 * @param array  $params   Request parameters.
	 * @return array|WP_Error
	 */
	private function api_request( $api_name, $params = array() ) {
		$app_key    = get_option( 'luwipress_alibaba_app_key', '' );
		$app_secret = get_option( 'luwipress_alibaba_app_secret', '' );
		$access_token = get_option( 'luwipress_alibaba_access_token', '' );

		// Build signed params
		$system_params = array(
			'method'         => $api_name,
			'app_key'        => $app_key,
			'timestamp'      => gmdate( 'Y-m-d H:i:s' ),
			'format'         => 'json',
			'v'              => '2.0',
			'sign_method'    => 'hmac-sha256',
		);

		if ( ! empty( $access_token ) ) {
			$system_params['session'] = $access_token;
		}

		$all_params = array_merge( $system_params, $params );

		// Generate signature
		ksort( $all_params );
		$sign_string = '';
		foreach ( $all_params as $k => $v ) {
			if ( is_array( $v ) ) {
				$v = wp_json_encode( $v );
			}
			$sign_string .= $k . $v;
		}
		$all_params['sign'] = strtoupper( hash_hmac( 'sha256', $sign_string, $app_secret ) );

		$url = self::$base_url . '/' . str_replace( '.', '/', $api_name );

		$response = wp_remote_post( $url, array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'User-Agent'   => 'LuwiPress/' . LUWIPRESS_VERSION,
			),
			'body'    => $all_params,
		) );

		if ( is_wp_error( $response ) ) {
			LuwiPress_Logger::log( 'Alibaba API error: ' . $response->get_error_message(), 'error', array( 'api' => $api_name ) );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || ! empty( $body['error_response'] ) ) {
			$error_msg = $body['error_response']['msg'] ?? "Alibaba API returned HTTP {$code}";
			LuwiPress_Logger::log( 'Alibaba API error: ' . $error_msg, 'error', array( 'api' => $api_name, 'code' => $code ) );
			return new WP_Error( 'alibaba_api_error', $error_msg, array( 'status' => $code ) );
		}

		return $body ?: array();
	}
}
