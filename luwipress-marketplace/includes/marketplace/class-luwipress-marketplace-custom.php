<?php
/**
 * Custom Marketplace Adapter
 *
 * @package LuwiPress_Marketplace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Marketplace_Custom implements LuwiPress_Marketplace_Adapter {

	private $id;
	private $label;
	private $color;

	public function __construct( $id, $label, $color ) {
		$this->id    = $id;
		$this->label = $label;
		$this->color = $color;
	}

	public function get_name() {
		return $this->id;
	}

	public function get_label() {
		return $this->label;
	}

	public function get_brand_color() {
		return $this->color;
	}

	public function get_settings_schema() {
		return array(
			array( 'luwipress_' . $this->id . '_api_url', 'API URL', 'text', '' ),
			array( 'luwipress_' . $this->id . '_method', 'Method', 'select', 'POST', array( 'POST' => 'POST', 'PUT' => 'PUT' ) ),
			array( 'luwipress_' . $this->id . '_auth', 'Auth Header', 'password', '' ),
			array( 'luwipress_' . $this->id . '_mapping', 'JSON Payload', 'text', '{"id":"{{id}}","sku":"{{sku}}","title":"{{title}}","price":{{price}}}' ),
		);
	}

	public function is_configured() {
		$url = get_option( 'luwipress_' . $this->id . '_api_url', '' );
		return ! empty( $url );
	}

	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', 'API URL is required.' );
		}
		return true;
	}

	public function publish_product( array $product_data ) {
		return $this->send_request( $product_data );
	}

	public function update_product( $marketplace_product_id, array $product_data ) {
		return $this->send_request( $product_data );
	}

	public function delete_product( $marketplace_product_id ) {
		return new WP_Error( 'not_supported', 'Delete operation is not supported by default custom webhook.' );
	}

	public function map_product_data( $product ) {
		return array(
			'id'    => $product->get_id(),
			'sku'   => $product->get_sku(),
			'title' => $product->get_name(),
			'price' => $product->get_price(),
			'stock' => $product->get_stock_quantity(),
		);
	}

	public function get_categories( $query = '' ) {
		return array();
	}

	private function send_request( $product_data ) {
		$url     = get_option( 'luwipress_' . $this->id . '_api_url', '' );
		$method  = get_option( 'luwipress_' . $this->id . '_method', 'POST' );
		$auth    = get_option( 'luwipress_' . $this->id . '_auth', '' );
		$mapping = get_option( 'luwipress_' . $this->id . '_mapping', '' );

		if ( empty( $url ) ) {
			return new WP_Error( 'no_url', 'No API URL defined.' );
		}

		$payload = $mapping;
		foreach ( $product_data as $key => $value ) {
			$payload = str_replace( '{{' . $key . '}}', is_scalar( $value ) ? wp_json_encode( $value ) : '""', $payload );
		}

		$headers = array(
			'Content-Type' => 'application/json',
		);
		if ( ! empty( $auth ) ) {
			$headers['Authorization'] = $auth;
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'body'    => $payload,
			'timeout' => 15,
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return array( 'marketplace_product_id' => 'custom_' . $product_data['id'] );
		}

		return new WP_Error( 'api_error', 'API responded with code ' . $code, wp_remote_retrieve_body( $response ) );
	}
}
