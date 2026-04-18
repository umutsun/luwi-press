<?php
/**
 * N11 Marketplace Adapter
 *
 * Publishes WooCommerce products to N11.com via REST API.
 *
 * @package LuwiPress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Marketplace_N11 implements LuwiPress_Marketplace_Adapter {

	private static $base_url = 'https://api.n11.com/ws';

	public function get_name() {
		return 'n11';
	}

	public function get_label() {
		return 'N11';
	}

	public function is_configured() {
		return ! empty( get_option( 'luwipress_n11_api_key', '' ) )
			&& ! empty( get_option( 'luwipress_n11_api_secret', '' ) );
	}

	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'N11 API credentials not configured.', 'luwipress' ) );
		}
		$response = $this->api_request( 'GET', '/categoryService/GetTopLevelCategories' );
		return is_wp_error( $response ) ? $response : true;
	}

	public function publish_product( array $product_data ) {
		$response = $this->api_request( 'POST', '/productService/SaveProduct', array(
			'product' => $product_data,
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'marketplace_product_id' => $response['product']['id'] ?? $product_data['productSellerCode'],
			'status'                 => 'PUBLISHED',
		);
	}

	public function update_product( $marketplace_product_id, array $product_data ) {
		$product_data['id'] = $marketplace_product_id;
		$response = $this->api_request( 'POST', '/productService/SaveProduct', array(
			'product' => $product_data,
		) );
		return is_wp_error( $response ) ? $response : true;
	}

	public function delete_product( $marketplace_product_id ) {
		$response = $this->api_request( 'POST', '/productService/DeleteProductById', array(
			'productId' => $marketplace_product_id,
		) );
		return is_wp_error( $response ) ? $response : true;
	}

	public function map_product_data( $product ) {
		$image_url = wp_get_attachment_url( $product->get_image_id() );
		$gallery   = array_filter( array_map( 'wp_get_attachment_url', $product->get_gallery_image_ids() ) );

		$images = array();
		if ( ! empty( $image_url ) ) {
			$images[] = array( 'url' => $image_url, 'order' => 0 );
		}
		$i = 1;
		foreach ( $gallery as $url ) {
			$images[] = array( 'url' => $url, 'order' => $i++ );
		}

		return array(
			'productSellerCode' => $product->get_sku() ?: 'LP-' . $product->get_id(),
			'title'             => $product->get_name(),
			'subtitle'          => $product->get_short_description() ? wp_strip_all_tags( $product->get_short_description() ) : '',
			'description'       => $product->get_description() ?: $product->get_short_description(),
			'price'             => floatval( $product->get_regular_price() ),
			'currencyType'      => get_woocommerce_currency() === 'TRY' ? 1 : 2,
			'stockItems'        => array(
				array(
					'quantity'        => $product->get_stock_quantity() ?: 0,
					'sellerStockCode' => $product->get_sku() ?: 'LP-' . $product->get_id(),
				),
			),
			'images'            => $images,
			'brand'             => $product->get_attribute( 'brand' ) ?: get_bloginfo( 'name' ),
		);
	}

	public function get_categories( $query = '' ) {
		$response = $this->api_request( 'GET', '/categoryService/GetTopLevelCategories' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return $response['categoryList'] ?? array();
	}

	private function api_request( $method, $path, $data = array() ) {
		$api_key    = get_option( 'luwipress_n11_api_key', '' );
		$api_secret = get_option( 'luwipress_n11_api_secret', '' );
		$url        = self::$base_url . $path;

		// N11 uses auth block in request body
		$auth = array(
			'appKey'    => $api_key,
			'appSecret' => $api_secret,
		);

		$body = array_merge( array( 'auth' => $auth ), $data );

		$args = array(
			'method'  => 'POST', // N11 SOAP-like REST always uses POST
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
				'User-Agent'   => 'LuwiPress/' . LUWIPRESS_VERSION,
			),
			'body'    => wp_json_encode( $body ),
		);

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			LuwiPress_Logger::log( 'N11 API error: ' . $response->get_error_message(), 'error', array( 'path' => $path ) );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$rbody = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || ( isset( $rbody['result']['status'] ) && $rbody['result']['status'] === 'failure' ) ) {
			$error_msg = $rbody['result']['errorMessage'] ?? "N11 API returned HTTP {$code}";
			LuwiPress_Logger::log( 'N11 API error: ' . $error_msg, 'error', array( 'path' => $path, 'code' => $code ) );
			return new WP_Error( 'n11_api_error', $error_msg, array( 'status' => $code ) );
		}

		return $rbody ?: array();
	}
}
