<?php
/**
 * Hepsiburada Marketplace Adapter
 *
 * Publishes WooCommerce products to Hepsiburada via Merchant API.
 *
 * @package LuwiPress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Marketplace_Hepsiburada implements LuwiPress_Marketplace_Adapter {

	private static $base_url = 'https://mpop-sit.hepsiburada.com';

	public function get_name() {
		return 'hepsiburada';
	}

	public function get_label() {
		return 'Hepsiburada';
	}

	public function is_configured() {
		return ! empty( get_option( 'luwipress_hepsiburada_api_key', '' ) )
			&& ! empty( get_option( 'luwipress_hepsiburada_api_secret', '' ) )
			&& ! empty( get_option( 'luwipress_hepsiburada_merchant_id', '' ) );
	}

	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Hepsiburada API credentials not configured.', 'luwipress' ) );
		}
		$merchant = get_option( 'luwipress_hepsiburada_merchant_id', '' );
		$response = $this->api_request( 'GET', '/product/api/merchants/' . $merchant . '/products' );
		return is_wp_error( $response ) ? $response : true;
	}

	public function publish_product( array $product_data ) {
		$merchant = get_option( 'luwipress_hepsiburada_merchant_id', '' );
		$response = $this->api_request( 'POST', '/product/api/merchants/' . $merchant . '/products', $product_data );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'marketplace_product_id' => $product_data['merchantSku'] ?? $product_data['barcode'] ?? '',
			'status'                 => 'PROCESSING',
		);
	}

	public function update_product( $marketplace_product_id, array $product_data ) {
		$merchant = get_option( 'luwipress_hepsiburada_merchant_id', '' );
		$response = $this->api_request( 'PUT', '/product/api/merchants/' . $merchant . '/products', $product_data );
		return is_wp_error( $response ) ? $response : true;
	}

	public function delete_product( $marketplace_product_id ) {
		$merchant = get_option( 'luwipress_hepsiburada_merchant_id', '' );
		$response = $this->api_request( 'DELETE', '/product/api/merchants/' . $merchant . '/products/' . rawurlencode( $marketplace_product_id ) );
		return is_wp_error( $response ) ? $response : true;
	}

	public function map_product_data( $product ) {
		$image_url = wp_get_attachment_url( $product->get_image_id() );
		$gallery   = array_filter( array_map( 'wp_get_attachment_url', $product->get_gallery_image_ids() ) );
		$images    = array_values( array_filter( array_merge( array( $image_url ), $gallery ) ) );

		return array(
			'merchantSku'  => $product->get_sku() ?: 'LP-' . $product->get_id(),
			'barcode'      => $product->get_sku() ?: '',
			'productName'  => $product->get_name(),
			'description'  => wp_strip_all_tags( $product->get_description() ?: $product->get_short_description() ),
			'price'        => floatval( $product->get_regular_price() ),
			'listPrice'    => floatval( $product->get_price() ),
			'stock'        => $product->get_stock_quantity() ?: 0,
			'images'       => $images,
			'categoryId'   => 0,
			'brand'        => $product->get_attribute( 'brand' ) ?: get_bloginfo( 'name' ),
			'weight'       => floatval( $product->get_weight() ) ?: 0,
		);
	}

	public function get_categories( $query = '' ) {
		$response = $this->api_request( 'GET', '/product/api/categories/get-all-categories' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return $response['data'] ?? array();
	}

	private function api_request( $method, $path, $data = array() ) {
		$api_key    = get_option( 'luwipress_hepsiburada_api_key', '' );
		$api_secret = get_option( 'luwipress_hepsiburada_api_secret', '' );
		$url        = self::$base_url . $path;

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
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
			LuwiPress_Logger::log( 'Hepsiburada API error: ' . $response->get_error_message(), 'error', array( 'path' => $path ) );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$error_msg = $body['message'] ?? "Hepsiburada API returned HTTP {$code}";
			LuwiPress_Logger::log( 'Hepsiburada API error: ' . $error_msg, 'error', array( 'path' => $path, 'code' => $code ) );
			return new WP_Error( 'hepsiburada_api_error', $error_msg, array( 'status' => $code ) );
		}

		return $body ?: array();
	}
}
