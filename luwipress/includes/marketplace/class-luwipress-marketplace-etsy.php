<?php
/**
 * Etsy Marketplace Adapter
 *
 * Publishes WooCommerce products to Etsy via Open API v3.
 *
 * @package LuwiPress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Marketplace_Etsy implements LuwiPress_Marketplace_Adapter {

	private static $base_url = 'https://openapi.etsy.com/v3/application';

	public function get_name() {
		return 'etsy';
	}

	public function get_label() {
		return 'Etsy';
	}

	public function is_configured() {
		return ! empty( get_option( 'luwipress_etsy_api_key', '' ) )
			&& ! empty( get_option( 'luwipress_etsy_shop_id', '' ) );
	}

	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Etsy API credentials not configured.', 'luwipress' ) );
		}
		$shop_id  = get_option( 'luwipress_etsy_shop_id', '' );
		$response = $this->api_request( 'GET', '/shops/' . rawurlencode( $shop_id ) );
		return is_wp_error( $response ) ? $response : true;
	}

	public function publish_product( array $product_data ) {
		$shop_id  = get_option( 'luwipress_etsy_shop_id', '' );
		$response = $this->api_request( 'POST', '/shops/' . rawurlencode( $shop_id ) . '/listings', $product_data );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'marketplace_product_id' => $response['listing_id'] ?? '',
			'status'                 => 'DRAFT',
		);
	}

	public function update_product( $marketplace_product_id, array $product_data ) {
		$response = $this->api_request( 'PUT', '/listings/' . rawurlencode( $marketplace_product_id ), $product_data );
		return is_wp_error( $response ) ? $response : true;
	}

	public function delete_product( $marketplace_product_id ) {
		$response = $this->api_request( 'DELETE', '/listings/' . rawurlencode( $marketplace_product_id ) );
		return is_wp_error( $response ) ? $response : true;
	}

	public function map_product_data( $product ) {
		$categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		$tags       = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );

		return array(
			'title'           => mb_substr( $product->get_name(), 0, 140 ),
			'description'     => wp_strip_all_tags( $product->get_description() ?: $product->get_short_description() ),
			'price'           => floatval( $product->get_regular_price() ),
			'quantity'        => $product->get_stock_quantity() ?: 0,
			'who_made'        => 'i_did',
			'when_made'       => 'made_to_order',
			'taxonomy_id'     => 0,
			'tags'            => is_array( $tags ) ? array_slice( $tags, 0, 13 ) : array(),
			'sku'             => $product->get_sku() ?: 'LP-' . $product->get_id(),
			'shipping_profile_id' => 0,
		);
	}

	public function get_categories( $query = '' ) {
		$response = $this->api_request( 'GET', '/seller-taxonomy/nodes' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return $response['results'] ?? array();
	}

	private function api_request( $method, $path, $data = array() ) {
		$api_key = get_option( 'luwipress_etsy_api_key', '' );
		$url     = self::$base_url . $path;

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'x-api-key'    => $api_key,
				'Content-Type' => 'application/json',
				'User-Agent'   => 'LuwiPress/' . LUWIPRESS_VERSION,
			),
		);

		if ( 'GET' === $method && ! empty( $data ) ) {
			$url = add_query_arg( $data, $url );
		} elseif ( ! empty( $data ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			LuwiPress_Logger::log( 'Etsy API error: ' . $response->get_error_message(), 'error', array( 'path' => $path ) );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$error_msg = $body['error'] ?? "Etsy API returned HTTP {$code}";
			LuwiPress_Logger::log( 'Etsy API error: ' . $error_msg, 'error', array( 'path' => $path, 'code' => $code ) );
			return new WP_Error( 'etsy_api_error', $error_msg, array( 'status' => $code ) );
		}

		return $body ?: array();
	}
}
