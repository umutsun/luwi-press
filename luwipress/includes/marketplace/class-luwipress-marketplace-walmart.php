<?php
/**
 * Walmart Marketplace Adapter
 *
 * Publishes WooCommerce products to Walmart via Marketplace API.
 *
 * @package LuwiPress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Marketplace_Walmart implements LuwiPress_Marketplace_Adapter {

	private static $base_url = 'https://marketplace.walmartapis.com/v3';

	public function get_name() {
		return 'walmart';
	}

	public function get_label() {
		return 'Walmart';
	}

	public function is_configured() {
		return ! empty( get_option( 'luwipress_walmart_client_id', '' ) )
			&& ! empty( get_option( 'luwipress_walmart_client_secret', '' ) );
	}

	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Walmart API credentials not configured.', 'luwipress' ) );
		}
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		return true;
	}

	public function publish_product( array $product_data ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = $this->api_request( 'POST', '/feeds?feedType=item', $product_data, $token );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'marketplace_product_id' => $product_data['sku'] ?? '',
			'feed_id'               => $response['feedId'] ?? '',
			'status'                => 'PROCESSING',
		);
	}

	public function update_product( $marketplace_product_id, array $product_data ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$response = $this->api_request( 'PUT', '/items/' . rawurlencode( $marketplace_product_id ), $product_data, $token );
		return is_wp_error( $response ) ? $response : true;
	}

	public function delete_product( $marketplace_product_id ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$response = $this->api_request( 'DELETE', '/items/' . rawurlencode( $marketplace_product_id ), array(), $token );
		return is_wp_error( $response ) ? $response : true;
	}

	public function map_product_data( $product ) {
		$image_url = wp_get_attachment_url( $product->get_image_id() );

		return array(
			'sku'              => $product->get_sku() ?: 'LP-' . $product->get_id(),
			'productName'      => $product->get_name(),
			'shortDescription' => wp_strip_all_tags( $product->get_short_description() ?: mb_substr( $product->get_description(), 0, 200 ) ),
			'longDescription'  => wp_strip_all_tags( $product->get_description() ),
			'price'            => floatval( $product->get_regular_price() ),
			'currency'         => get_woocommerce_currency(),
			'quantity'         => $product->get_stock_quantity() ?: 0,
			'mainImageUrl'     => $image_url ?: '',
			'brand'            => $product->get_attribute( 'brand' ) ?: get_bloginfo( 'name' ),
			'weight'           => floatval( $product->get_weight() ) ?: 0,
			'weightUnit'       => get_option( 'woocommerce_weight_unit', 'kg' ) === 'lbs' ? 'LB' : 'KG',
		);
	}

	public function get_categories( $query = '' ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$response = $this->api_request( 'GET', '/taxonomy/departments', array(), $token );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return $response['payload'] ?? array();
	}

	/**
	 * Get OAuth access token (cached via transient).
	 *
	 * @return string|WP_Error
	 */
	private function get_access_token() {
		$cached = get_transient( 'luwipress_walmart_token' );
		if ( ! empty( $cached ) ) {
			return $cached;
		}

		$client_id     = get_option( 'luwipress_walmart_client_id', '' );
		$client_secret = get_option( 'luwipress_walmart_client_secret', '' );

		$response = wp_remote_post( 'https://marketplace.walmartapis.com/v3/token', array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Accept'        => 'application/json',
				'WM_SVC.NAME'   => 'LuwiPress',
				'WM_QOS.CORRELATION_ID' => wp_generate_uuid4(),
			),
			'body'    => 'grant_type=client_credentials',
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$token = $body['access_token'] ?? '';

		if ( empty( $token ) ) {
			return new WP_Error( 'walmart_auth_error', 'Failed to obtain Walmart access token.' );
		}

		$expires = intval( $body['expires_in'] ?? 900 ) - 60;
		set_transient( 'luwipress_walmart_token', $token, $expires );

		return $token;
	}

	private function api_request( $method, $path, $data = array(), $token = '' ) {
		$url = self::$base_url . $path;

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization'         => 'Bearer ' . $token,
				'Content-Type'          => 'application/json',
				'Accept'                => 'application/json',
				'WM_SVC.NAME'           => 'LuwiPress',
				'WM_QOS.CORRELATION_ID' => wp_generate_uuid4(),
				'User-Agent'            => 'LuwiPress/' . LUWIPRESS_VERSION,
			),
		);

		if ( 'GET' === $method && ! empty( $data ) ) {
			$url = add_query_arg( $data, $url );
		} elseif ( ! empty( $data ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			LuwiPress_Logger::log( 'Walmart API error: ' . $response->get_error_message(), 'error', array( 'path' => $path ) );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$error_msg = $body['errors'][0]['description'] ?? "Walmart API returned HTTP {$code}";
			LuwiPress_Logger::log( 'Walmart API error: ' . $error_msg, 'error', array( 'path' => $path, 'code' => $code ) );
			return new WP_Error( 'walmart_api_error', $error_msg, array( 'status' => $code ) );
		}

		return $body ?: array();
	}
}
