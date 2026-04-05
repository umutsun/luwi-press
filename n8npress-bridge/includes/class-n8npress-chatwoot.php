<?php
/**
 * n8nPress Chatwoot Integration
 *
 * Bridges Chatwoot customer support platform with n8n workflows.
 * Receives Chatwoot webhook events, syncs contacts with WooCommerce customers,
 * and enables AI-powered auto-responses via n8n.
 *
 * @package n8nPress
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class N8nPress_Chatwoot {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_ajax_n8npress_chatwoot_test', array( $this, 'ajax_test_connection' ) );
	}

	/**
	 * Check if Chatwoot integration is enabled
	 */
	public static function is_enabled() {
		return (bool) get_option( 'n8npress_chatwoot_enabled', 0 );
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		// Webhook receiver — Chatwoot sends events here
		register_rest_route( 'n8npress/v1', '/chatwoot/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_webhook' ),
			'permission_callback' => array( $this, 'verify_webhook' ),
		) );

		// Contact sync — lookup WooCommerce customer by email
		register_rest_route( 'n8npress/v1', '/chatwoot/customer-lookup', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'customer_lookup' ),
			'permission_callback' => array( $this, 'check_api_permission' ),
			'args'                => array(
				'email' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
				),
			),
		) );

		// Push message to Chatwoot conversation
		register_rest_route( 'n8npress/v1', '/chatwoot/send-message', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'send_message' ),
			'permission_callback' => array( $this, 'check_api_permission' ),
			'args'                => array(
				'conversation_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'message' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'message_type' => array(
					'default'           => 'outgoing',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// Get Chatwoot status / stats
		register_rest_route( 'n8npress/v1', '/chatwoot/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_status' ),
			'permission_callback' => array( $this, 'check_api_permission' ),
		) );
	}

	/**
	 * Verify incoming Chatwoot webhook (token-based)
	 */
	public function verify_webhook( $request ) {
		$token = get_option( 'n8npress_chatwoot_webhook_token', '' );

		// If no token set, allow all (user hasn't configured yet)
		if ( empty( $token ) ) {
			return true;
		}

		$header_token = $request->get_header( 'X-Chatwoot-Token' );
		if ( $header_token === $token ) {
			return true;
		}

		// Also check query param
		$param_token = $request->get_param( 'token' );
		if ( $param_token === $token ) {
			return true;
		}

		return new WP_Error( 'unauthorized', __( 'Invalid webhook token', 'n8npress' ), array( 'status' => 401 ) );
	}

	/**
	 * Check API permission (same as site-config)
	 */
	public function check_api_permission( $request ) {
		$auth_header = $request->get_header( 'Authorization' );
		if ( $auth_header && 0 === strpos( $auth_header, 'Bearer ' ) ) {
			$token = substr( $auth_header, 7 );
			$stored = get_option( 'n8npress_seo_api_token', '' );
			if ( ! empty( $stored ) && hash_equals( $stored, $token ) ) {
				return true;
			}
		}

		$x_token = $request->get_header( 'X-N8nPress-Token' );
		if ( $x_token ) {
			$stored = get_option( 'n8npress_seo_api_token', '' );
			if ( ! empty( $stored ) && hash_equals( $stored, $x_token ) ) {
				return true;
			}
		}

		return new WP_Error( 'unauthorized', __( 'Unauthorized', 'n8npress' ), array( 'status' => 401 ) );
	}

	// ------------------------------------------------------------------
	// Webhook Handler — receives events from Chatwoot
	// ------------------------------------------------------------------

	/**
	 * Handle incoming Chatwoot webhook event
	 */
	public function handle_webhook( $request ) {
		if ( ! self::is_enabled() ) {
			return new WP_REST_Response( array( 'status' => 'disabled' ), 200 );
		}

		$body  = $request->get_json_params();
		$event = $body['event'] ?? '';

		N8nPress_Logger::log( 'Chatwoot webhook received: ' . $event, 'info', array(
			'event'           => $event,
			'conversation_id' => $body['id'] ?? $body['conversation']['id'] ?? null,
		) );

		// Forward to n8n for processing
		$forward_to_n8n = get_option( 'n8npress_chatwoot_forward_to_n8n', 1 );
		$n8n_response   = null;

		if ( $forward_to_n8n ) {
			$n8n_response = $this->forward_to_n8n( $event, $body );
		}

		// Handle specific events locally
		switch ( $event ) {
			case 'message_created':
				$this->on_message_created( $body );
				break;

			case 'conversation_created':
				$this->on_conversation_created( $body );
				break;

			case 'conversation_status_changed':
				$this->on_conversation_status_changed( $body );
				break;

			case 'contact_created':
			case 'contact_updated':
				$this->on_contact_changed( $body );
				break;
		}

		return new WP_REST_Response( array(
			'status'       => 'ok',
			'event'        => $event,
			'n8n_response' => $n8n_response,
		), 200 );
	}

	/**
	 * Forward webhook event to n8n
	 */
	private function forward_to_n8n( $event, $body ) {
		$webhook_url = get_option( 'n8npress_seo_webhook_url', '' );
		if ( empty( $webhook_url ) ) {
			return null;
		}

		$chatwoot_path = get_option( 'n8npress_chatwoot_n8n_path', 'chatwoot' );
		$url = trailingslashit( $webhook_url ) . $chatwoot_path;

		$response = wp_remote_post( $url, array(
			'timeout'     => 30,
			'httpversion' => '1.1',
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( array(
				'event'   => $event,
				'_meta'   => n8npress_build_meta_block(),
				'payload' => $body,
				'source'  => 'n8npress',
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			N8nPress_Logger::log( 'Chatwoot → n8n forward failed: ' . $response->get_error_message(), 'error' );
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	// ------------------------------------------------------------------
	// Event Handlers
	// ------------------------------------------------------------------

	/**
	 * New message in a conversation
	 */
	private function on_message_created( $body ) {
		$message_type = $body['message_type'] ?? '';
		$content      = $body['content'] ?? '';
		$conversation = $body['conversation'] ?? array();
		$sender       = $body['sender'] ?? array();

		// Only process incoming messages (from customer)
		if ( 'incoming' !== $message_type ) {
			return;
		}

		// Try to match contact to WooCommerce customer
		$email = $sender['email'] ?? '';
		if ( ! empty( $email ) ) {
			$this->enrich_contact_with_wc_data( $sender, $email );
		}

		N8nPress_Logger::log( 'Chatwoot message from customer', 'info', array(
			'conversation_id' => $conversation['id'] ?? null,
			'sender'          => $sender['name'] ?? $email,
			'channel'         => $conversation['channel'] ?? 'unknown',
		) );
	}

	/**
	 * New conversation started
	 */
	private function on_conversation_created( $body ) {
		$contact = $body['meta']['sender'] ?? $body['contact'] ?? array();
		$email   = $contact['email'] ?? '';

		if ( ! empty( $email ) ) {
			$this->enrich_contact_with_wc_data( $contact, $email );
		}

		N8nPress_Logger::log( 'Chatwoot new conversation', 'info', array(
			'conversation_id' => $body['id'] ?? null,
			'contact'         => $contact['name'] ?? $email,
		) );
	}

	/**
	 * Conversation status changed (open, resolved, pending)
	 */
	private function on_conversation_status_changed( $body ) {
		$status = $body['status'] ?? '';
		N8nPress_Logger::log( 'Chatwoot conversation status: ' . $status, 'info', array(
			'conversation_id' => $body['id'] ?? null,
			'status'          => $status,
		) );
	}

	/**
	 * Contact created or updated in Chatwoot
	 */
	private function on_contact_changed( $body ) {
		$email = $body['email'] ?? '';
		if ( empty( $email ) ) {
			return;
		}

		// Sync label to Chatwoot if WC customer
		$this->enrich_contact_with_wc_data( $body, $email );
	}

	// ------------------------------------------------------------------
	// WooCommerce ↔ Chatwoot Contact Enrichment
	// ------------------------------------------------------------------

	/**
	 * Enrich a Chatwoot contact with WooCommerce customer data
	 */
	private function enrich_contact_with_wc_data( $contact, $email ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$customer = $this->find_wc_customer( $email );
		if ( ! $customer ) {
			return;
		}

		$chatwoot_contact_id = $contact['id'] ?? null;
		if ( ! $chatwoot_contact_id ) {
			return;
		}

		// Update Chatwoot contact with WC data via API
		$this->update_chatwoot_contact( $chatwoot_contact_id, array(
			'custom_attributes' => array(
				'wc_customer_id'  => $customer['id'],
				'wc_total_orders' => $customer['total_orders'],
				'wc_total_spent'  => $customer['total_spent'],
				'wc_last_order'   => $customer['last_order_date'],
				'wc_segment'      => $customer['segment'],
			),
		) );
	}

	/**
	 * Find WooCommerce customer by email
	 */
	private function find_wc_customer( $email ) {
		if ( ! class_exists( 'WC_Customer' ) ) {
			return null;
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			// Check guest orders
			$orders = wc_get_orders( array(
				'billing_email' => $email,
				'limit'         => 1,
				'orderby'       => 'date',
				'order'         => 'DESC',
			) );

			if ( empty( $orders ) ) {
				return null;
			}

			$order = $orders[0];
			return array(
				'id'              => 0,
				'email'           => $email,
				'name'            => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'total_orders'    => count( wc_get_orders( array( 'billing_email' => $email, 'limit' => -1, 'return' => 'ids' ) ) ),
				'total_spent'     => array_sum( array_map( function( $o ) { return $o->get_total(); }, wc_get_orders( array( 'billing_email' => $email, 'limit' => -1 ) ) ) ),
				'last_order_date' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : null,
				'segment'         => 'guest',
			);
		}

		$customer = new WC_Customer( $user->ID );
		$total_spent = (float) $customer->get_total_spent();
		$order_count = (int) $customer->get_order_count();

		// Determine segment
		$vip_threshold = (float) get_option( 'n8npress_crm_vip_threshold', 1000 );
		$loyal_orders  = (int) get_option( 'n8npress_crm_loyal_orders', 3 );
		$segment = 'regular';
		if ( $total_spent >= $vip_threshold ) {
			$segment = 'vip';
		} elseif ( $order_count >= $loyal_orders ) {
			$segment = 'loyal';
		}

		$last_order = $customer->get_last_order();

		return array(
			'id'              => $user->ID,
			'email'           => $email,
			'name'            => $customer->get_first_name() . ' ' . $customer->get_last_name(),
			'total_orders'    => $order_count,
			'total_spent'     => $total_spent,
			'last_order_date' => $last_order ? $last_order->get_date_created()->date( 'Y-m-d' ) : null,
			'segment'         => $segment,
		);
	}

	// ------------------------------------------------------------------
	// Chatwoot API Calls
	// ------------------------------------------------------------------

	/**
	 * Update a contact in Chatwoot
	 */
	private function update_chatwoot_contact( $contact_id, $data ) {
		$api = $this->get_api_config();
		if ( ! $api ) {
			return false;
		}

		$url = trailingslashit( $api['url'] ) . 'api/v1/accounts/' . $api['account_id'] . '/contacts/' . $contact_id;

		$response = wp_remote_request( $url, array(
			'method'  => 'PUT',
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'api_access_token' => $api['token'],
			),
			'body' => wp_json_encode( $data ),
		) );

		if ( is_wp_error( $response ) ) {
			N8nPress_Logger::log( 'Chatwoot API error: ' . $response->get_error_message(), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Send a message to a Chatwoot conversation
	 */
	public function send_message( $request ) {
		$api = $this->get_api_config();
		if ( ! $api ) {
			return new WP_Error( 'not_configured', __( 'Chatwoot API not configured', 'n8npress' ), array( 'status' => 400 ) );
		}

		$conversation_id = $request->get_param( 'conversation_id' );
		$message         = $request->get_param( 'message' );
		$message_type    = $request->get_param( 'message_type' );

		$url = trailingslashit( $api['url'] ) . 'api/v1/accounts/' . $api['account_id'] . '/conversations/' . $conversation_id . '/messages';

		$response = wp_remote_post( $url, array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type'     => 'application/json',
				'api_access_token' => $api['token'],
			),
			'body' => wp_json_encode( array(
				'content'      => $message,
				'message_type' => $message_type,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			return new WP_Error( 'chatwoot_error', $body['message'] ?? 'Chatwoot API error', array( 'status' => $code ) );
		}

		return new WP_REST_Response( $body, 200 );
	}

	/**
	 * Customer lookup endpoint
	 */
	public function customer_lookup( $request ) {
		$email = $request->get_param( 'email' );
		$customer = $this->find_wc_customer( $email );

		if ( ! $customer ) {
			return new WP_REST_Response( array(
				'found'   => false,
				'email'   => $email,
				'message' => 'No WooCommerce customer found',
			), 200 );
		}

		// Get recent orders
		$recent_orders = array();
		if ( class_exists( 'WooCommerce' ) ) {
			$orders = wc_get_orders( array(
				'customer' => $customer['id'] > 0 ? $customer['id'] : $email,
				'limit'    => 5,
				'orderby'  => 'date',
				'order'    => 'DESC',
			) );

			foreach ( $orders as $order ) {
				$recent_orders[] = array(
					'id'     => $order->get_id(),
					'status' => $order->get_status(),
					'total'  => $order->get_total(),
					'date'   => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : null,
					'items'  => count( $order->get_items() ),
				);
			}
		}

		return new WP_REST_Response( array(
			'found'         => true,
			'customer'      => $customer,
			'recent_orders' => $recent_orders,
		), 200 );
	}

	/**
	 * Get Chatwoot status endpoint
	 */
	public function get_status( $request ) {
		$enabled = self::is_enabled();
		$api     = $this->get_api_config();

		$result = array(
			'enabled'    => $enabled,
			'configured' => ! empty( $api ),
			'url'        => get_option( 'n8npress_chatwoot_url', '' ),
		);

		// Test connection if configured
		if ( $api ) {
			$test = $this->test_api_connection( $api );
			$result['connection'] = $test;
		}

		return new WP_REST_Response( $result, 200 );
	}

	// ------------------------------------------------------------------
	// AJAX — Test Connection
	// ------------------------------------------------------------------

	/**
	 * Test Chatwoot API connection
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'n8npress_settings_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized', 'n8npress' ) );
		}

		$api = $this->get_api_config();
		if ( ! $api ) {
			wp_send_json_error( __( 'Chatwoot URL and API Access Token are required', 'n8npress' ) );
		}

		$test = $this->test_api_connection( $api );

		if ( $test['ok'] ) {
			wp_send_json_success( array(
				'message' => sprintf(
					__( 'Connected to %s (Account #%d)', 'n8npress' ),
					$api['url'],
					$api['account_id']
				),
				'account' => $test['account'] ?? null,
			) );
		} else {
			wp_send_json_error( $test['error'] ?? __( 'Connection failed', 'n8npress' ) );
		}
	}

	/**
	 * Test API connection
	 */
	private function test_api_connection( $api ) {
		$url = trailingslashit( $api['url'] ) . 'api/v1/profile';

		$response = wp_remote_get( $url, array(
			'timeout' => 10,
			'headers' => array(
				'api_access_token' => $api['token'],
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return array( 'ok' => false, 'error' => 'HTTP ' . $code . ': ' . ( $body['message'] ?? 'Unknown error' ) );
		}

		return array(
			'ok'      => true,
			'account' => array(
				'name'  => $body['name'] ?? '',
				'email' => $body['email'] ?? '',
			),
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Get Chatwoot API configuration
	 */
	private function get_api_config() {
		$url        = get_option( 'n8npress_chatwoot_url', '' );
		$token      = get_option( 'n8npress_chatwoot_api_token', '' );
		$account_id = get_option( 'n8npress_chatwoot_account_id', 1 );

		if ( empty( $url ) || empty( $token ) ) {
			return null;
		}

		return array(
			'url'        => rtrim( $url, '/' ),
			'token'      => $token,
			'account_id' => absint( $account_id ),
		);
	}

	/**
	 * Get widget script for frontend embedding
	 */
	public static function get_widget_script() {
		if ( ! self::is_enabled() ) {
			return '';
		}

		$url       = get_option( 'n8npress_chatwoot_url', '' );
		$token     = get_option( 'n8npress_chatwoot_widget_token', '' );
		$position  = get_option( 'n8npress_chatwoot_widget_position', 'right' );
		$locale    = get_option( 'n8npress_chatwoot_widget_locale', '' );

		if ( empty( $url ) || empty( $token ) ) {
			return '';
		}

		if ( empty( $locale ) ) {
			$locale = substr( get_locale(), 0, 2 );
		}

		return sprintf(
			'<script>
window.chatwootSettings={hideMessageBubble:false,position:"%4$s",locale:"%5$s",type:"standard"};
(function(d,t){var g=d.createElement(t),s=d.getElementsByTagName(t)[0];g.src="%1$s/packs/js/sdk.js";g.defer=true;g.async=true;s.parentNode.insertBefore(g,s);g.onload=function(){window.chatwootSDK.run({websiteToken:"%2$s",baseUrl:"%1$s"})}})(document,"script");
</script>',
			esc_url( rtrim( $url, '/' ) ),
			esc_attr( $token ),
			esc_url( rtrim( $url, '/' ) ),
			esc_attr( $position ),
			esc_attr( $locale )
		);
	}

	/**
	 * Output widget in wp_footer if enabled
	 */
	public static function maybe_output_widget() {
		if ( ! self::is_enabled() ) {
			return;
		}

		$show_widget = get_option( 'n8npress_chatwoot_show_widget', 0 );
		if ( ! $show_widget ) {
			return;
		}

		// Don't show in admin
		if ( is_admin() ) {
			return;
		}

		echo self::get_widget_script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
