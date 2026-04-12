<?php
/**
 * n8nPress Open Claw — AI Assistant for WordPress Management
 *
 * Provides a chat-like admin interface where site administrators can
 * give natural language commands to manage their WordPress + WooCommerce
 * store through n8nPress and n8n workflows.
 *
 * Examples:
 *   "Enrich all products in the Baglama category"
 *   "Translate missing products to German and French"
 *   "Show me thin content products"
 *   "Generate a blog post about oud maintenance tips"
 *   "What's the review sentiment for our top products?"
 *   "Run AEO generation for products missing FAQ"
 *
 * Multi-channel support:
 *   Admin panel → AJAX → PHP intent parser → response
 *   Telegram    → n8n Telegram Trigger → POST /claw/channel-message → response → n8n → Telegram reply
 *   WhatsApp    → n8n WhatsApp Trigger → POST /claw/channel-message → response → n8n → WhatsApp reply
 *
 * Architecture:
 *   Admin chat → AJAX → PHP intent parser → n8n AI workflow → callback → response
 *
 * @package n8nPress
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class N8nPress_Open_Claw {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
		add_action( 'wp_ajax_n8npress_claw_send', array( $this, 'ajax_send_message' ) );
		add_action( 'wp_ajax_n8npress_claw_history', array( $this, 'ajax_get_history' ) );
		add_action( 'wp_ajax_n8npress_claw_execute', array( $this, 'ajax_execute_action' ) );
		add_action( 'wp_ajax_n8npress_claw_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_n8npress_claw_clear_history', array( $this, 'ajax_clear_history' ) );
	}

	public function register_endpoints() {
		// n8n callback: AI response
		register_rest_route( 'n8npress/v1', '/claw/callback', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_ai_callback' ),
			'permission_callback' => array( $this, 'check_n8n_token' ),
		) );

		// Execute a confirmed action (called after user approves)
		register_rest_route( 'n8npress/v1', '/claw/execute', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_execute_action' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		// Channel message: incoming from Telegram, WhatsApp, etc. via n8n
		register_rest_route( 'n8npress/v1', '/claw/channel-message', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_channel_message' ),
			'permission_callback' => array( $this, 'check_n8n_token' ),
		) );

		// Channel action execution (from Telegram/WhatsApp callback buttons)
		register_rest_route( 'n8npress/v1', '/claw/channel-execute', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_channel_execute' ),
			'permission_callback' => array( $this, 'check_n8n_token' ),
		) );

		// Connected channels status
		register_rest_route( 'n8npress/v1', '/claw/channels', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_get_channels' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );
	}

	// ─── AJAX: Send user message ───────────────────────────────────────

	public function ajax_send_message() {
		check_ajax_referer( 'n8npress_claw_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$message = sanitize_textarea_field( $_POST['message'] ?? '' );
		if ( empty( $message ) ) {
			wp_send_json_error( 'Message is required' );
		}

		$conversation_id = sanitize_text_field( $_POST['conversation_id'] ?? wp_generate_uuid4() );

		// Save user message
		$this->save_message( $conversation_id, 'user', $message );

		// Build site context for AI
		$context = $this->build_site_context();

		// Try local intent resolution first (fast queries)
		$local_result = $this->try_local_resolution( $message );
		if ( $local_result ) {
			$this->save_message( $conversation_id, 'assistant', $local_result['response'], $local_result['actions'] ?? array() );
			N8nPress_Logger::log( 'Open Claw: "' . mb_substr( $message, 0, 60 ) . '" → resolved locally', 'info', array(
				'query' => mb_substr( $message, 0, 100 ),
				'has_actions' => ! empty( $local_result['actions'] ),
			) );
			wp_send_json_success( array(
				'conversation_id' => $conversation_id,
				'response'        => $local_result['response'],
				'actions'         => $local_result['actions'] ?? array(),
				'source'          => 'local',
			) );
			return;
		}

		// Check daily token limit before AI call
		if ( class_exists( 'N8nPress_Token_Tracker' ) && N8nPress_Token_Tracker::is_limit_exceeded() ) {
			$limit = get_option( 'n8npress_daily_token_limit', 0 );
			$today = N8nPress_Token_Tracker::get_today_cost();
			$this->save_message( $conversation_id, 'assistant', sprintf(
				'Daily AI budget limit reached ($%.2f / $%.2f). AI features are paused until tomorrow. Local commands (/scan, /seo, /translate, etc.) still work.',
				$today, $limit
			) );
			wp_send_json_success( array(
				'conversation_id' => $conversation_id,
				'response'        => sprintf(
					"**Daily AI budget limit reached** ($%.2f / $%.2f).\n\nAI-powered features are paused until tomorrow to protect your API costs. You can still use local commands:\n\n`/scan` `/seo` `/translate` `/thin` `/stale` `/products` `/revenue` `/help`",
					$today, $limit
				),
				'actions'         => array(),
				'source'          => 'limit',
			) );
			return;
		}

		// Send to n8n for AI processing
		$result = $this->send_to_n8n( $message, $conversation_id, $context );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		// If n8n returned a synchronous response, use it directly
		if ( is_array( $result ) && ! empty( $result['response'] ) ) {
			wp_send_json_success( array(
				'conversation_id' => $conversation_id,
				'response'        => $result['response'],
				'actions'         => $result['actions'] ?? array(),
				'source'          => 'n8n',
			) );
			return;
		}

		wp_send_json_success( array(
			'conversation_id' => $conversation_id,
			'response'        => null,
			'status'          => 'processing',
			'source'          => 'n8n',
		) );
	}

	// ─── AJAX: Get conversation history ────────────────────────────────

	public function ajax_get_history() {
		check_ajax_referer( 'n8npress_claw_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$conversation_id = sanitize_text_field( $_POST['conversation_id'] ?? '' );
		$messages = $this->get_conversation( $conversation_id );

		wp_send_json_success( array(
			'conversation_id' => $conversation_id,
			'messages'        => $messages,
		) );
	}

	// ─── AJAX: Execute a confirmed action ──────────────────────────────

	public function ajax_execute_action() {
		check_ajax_referer( 'n8npress_claw_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$action_type = sanitize_text_field( $_POST['execute_action'] ?? $_POST['action_type'] ?? '' );
		$raw_params  = $_POST['params'] ?? $_POST['action_data'] ?? '{}';
		$action_data = is_string( $raw_params ) ? json_decode( stripslashes( $raw_params ), true ) : (array) $raw_params;
		if ( ! is_array( $action_data ) ) {
			$action_data = array();
		}
		$conversation_id = sanitize_text_field( $_POST['conversation_id'] ?? '' );

		$result = $this->execute_action( $action_type, $action_data );

		if ( is_wp_error( $result ) ) {
			N8nPress_Logger::log( 'Action failed: ' . $action_type . ' → ' . $result->get_error_message(), 'error', array(
				'action' => $action_type,
				'error'  => $result->get_error_message(),
			) );
			wp_send_json_error( $result->get_error_message() );
		}

		N8nPress_Logger::log( 'Action: ' . $action_type . ' → ' . mb_substr( $result['message'] ?? 'done', 0, 80 ), 'info', array(
			'action'  => $action_type,
			'result' => $result['message'] ?? '',
		) );

		$this->save_message( $conversation_id, 'system', 'Action executed: ' . $action_type, array(
			'result' => $result,
		) );

		wp_send_json_success( $result );
	}

	/**
	 * POST /claw/execute — REST handler for executing a confirmed action.
	 */
	public function handle_execute_action( $request ) {
		$data        = $request->get_json_params();
		$action_type = sanitize_text_field( $data['action'] ?? $data['action_type'] ?? '' );
		$action_data = isset( $data['params'] ) && is_array( $data['params'] ) ? $data['params'] : array();
		$conv_id     = sanitize_text_field( $data['conversation_id'] ?? '' );

		if ( empty( $action_type ) ) {
			return new WP_Error( 'missing_action', 'action is required', array( 'status' => 400 ) );
		}

		// Sanitize action_data values
		$action_data = array_map( 'sanitize_text_field', $action_data );

		$result = $this->execute_action( $action_type, $action_data );

		if ( is_wp_error( $result ) ) {
			N8nPress_Logger::log( 'Claw execute failed: ' . $action_type, 'error', array(
				'error' => $result->get_error_message(),
			) );
			return $result;
		}

		if ( $conv_id ) {
			$this->save_message( $conv_id, 'system', 'Action executed: ' . $action_type, array(
				'result' => $result,
			) );
		}

		N8nPress_Logger::log( 'Claw execute: ' . $action_type, 'info' );

		return rest_ensure_response( array(
			'success' => true,
			'action'  => $action_type,
			'result'  => $result,
		) );
	}

	// ─── AJAX: Test Open Claw connection ───────────────────────────────

	public function ajax_test_connection() {
		check_ajax_referer( 'n8npress_claw_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$openclaw_url = get_option( 'n8npress_openclaw_url', '' );
		if ( empty( $openclaw_url ) ) {
			wp_send_json_error( __( 'Open Claw URL is not configured. Please enter the URL and save settings first.', 'n8npress' ) );
		}

		$test_url = trailingslashit( $openclaw_url ) . 'health';

		$response = wp_remote_get( $test_url, array(
			'timeout' => 10,
			'headers' => array(
				'Accept' => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( sprintf(
				__( 'Connection failed: %s', 'n8npress' ),
				$response->get_error_message()
			) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code >= 200 && $code < 300 ) {
			$data = json_decode( $body, true );
			wp_send_json_success( array(
				'message' => __( 'Connected to Open Claw successfully!', 'n8npress' ),
				'status'  => $code,
				'data'    => $data,
			) );
		} else {
			wp_send_json_error( sprintf(
				__( 'Open Claw returned HTTP %d. Please check the URL.', 'n8npress' ),
				$code
			) );
		}
	}

	// ─── AJAX: Clear conversation history ──────────────────────────────

	public function ajax_clear_history() {
		check_ajax_referer( 'n8npress_claw_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$conversation_id = sanitize_text_field( $_POST['conversation_id'] ?? '' );
		if ( ! empty( $conversation_id ) ) {
			delete_option( 'n8npress_claw_' . $conversation_id );
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			delete_user_meta( $user_id, '_n8npress_claw_conversation' );
		}

		wp_send_json_success( array( 'message' => 'Conversation cleared.' ) );
	}

	// ─── CALLBACK: AI response from n8n ────────────────────────────────

	public function handle_ai_callback( $request ) {
		$data = $request->get_json_params();

		$conversation_id = sanitize_text_field( $data['conversation_id'] ?? '' );
		$response        = sanitize_textarea_field( $data['response'] ?? '' );
		$actions         = isset( $data['actions'] ) ? (array) $data['actions'] : array();

		if ( empty( $conversation_id ) || empty( $response ) ) {
			return new WP_Error( 'missing_data', 'conversation_id and response are required.', array( 'status' => 400 ) );
		}

		// Sanitize actions
		$clean_actions = array();
		foreach ( $actions as $action ) {
			$clean_actions[] = array(
				'type'        => sanitize_text_field( $action['type'] ?? '' ),
				'label'       => sanitize_text_field( $action['label'] ?? '' ),
				'description' => sanitize_text_field( $action['description'] ?? '' ),
				'data'        => $action['data'] ?? array(),
				'confirm'     => ! empty( $action['confirm'] ),
			);
		}

		$this->save_message( $conversation_id, 'assistant', $response, $clean_actions );

		return array(
			'success'         => true,
			'conversation_id' => $conversation_id,
		);
	}

	// ─── CHANNEL: Incoming message from Telegram/WhatsApp via n8n ──────

	public function handle_channel_message( $request ) {
		$data = $request->get_json_params();

		$channel   = sanitize_text_field( $data['channel'] ?? '' ); // telegram, whatsapp
		$sender_id = sanitize_text_field( $data['sender_id'] ?? '' );
		$sender_name = sanitize_text_field( $data['sender_name'] ?? '' );
		$message   = sanitize_textarea_field( $data['message'] ?? '' );

		if ( empty( $channel ) || empty( $message ) ) {
			return new WP_Error( 'missing_data', 'channel and message are required.', array( 'status' => 400 ) );
		}

		// Verify the sender is an authorized admin
		if ( ! $this->is_authorized_channel_user( $channel, $sender_id ) ) {
			N8nPress_Logger::log( 'Unauthorized channel message attempt', 'warning', array(
				'channel'   => $channel,
				'sender_id' => $sender_id,
			) );
			return array(
				'success' => false,
				'response' => 'You are not authorized to use Open Claw. Ask the site admin to add your ID in n8nPress Settings > Open Claw.',
			);
		}

		// Create conversation ID from channel + sender
		$conversation_id = 'channel_' . $channel . '_' . $sender_id;

		// Save incoming message
		$this->save_channel_message( $conversation_id, 'user', $message, $channel, $sender_id );

		// Try local resolution first
		$local_result = $this->try_local_resolution( $message );
		if ( $local_result ) {
			$response_text = $local_result['response'];

			// Build action buttons for channel (simplified for messaging apps)
			$channel_actions = array();
			if ( ! empty( $local_result['actions'] ) ) {
				foreach ( $local_result['actions'] as $action ) {
					$channel_actions[] = array(
						'type'  => $action['type'],
						'label' => $action['label'],
						'data'  => $action['data'] ?? array(),
					);
				}
			}

			$this->save_channel_message( $conversation_id, 'assistant', $response_text, $channel, $sender_id );

			N8nPress_Logger::log( 'Channel message processed locally', 'info', array(
				'channel'   => $channel,
				'sender_id' => $sender_id,
			) );

			return array(
				'success'  => true,
				'response' => $this->format_for_channel( $response_text, $channel ),
				'actions'  => $channel_actions,
				'source'   => 'local',
			);
		}

		// For complex queries, delegate to AI via n8n
		$context = $this->build_site_context();
		$context['channel'] = $channel;
		$context['sender']  = array( 'id' => $sender_id, 'name' => $sender_name );

		$result = $this->send_to_n8n( $message, $conversation_id, $context );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'  => false,
				'response' => 'I could not process that right now. Error: ' . $result->get_error_message(),
			);
		}

		// If n8n returned an immediate response, format it for the channel
		if ( is_array( $result ) && ! empty( $result['response'] ) ) {
			return array(
				'success'  => true,
				'response' => $this->format_for_channel( $result['response'], $channel ),
				'actions'  => $result['actions'] ?? array(),
				'source'   => 'n8n',
			);
		}

		return array(
			'success'  => true,
			'response' => 'Processing your request... I will reply shortly.',
			'status'   => 'processing',
			'source'   => 'n8n',
		);
	}

	// ─── CHANNEL: Execute action from messaging app callback button ────

	public function handle_channel_execute( $request ) {
		$data = $request->get_json_params();

		$channel     = sanitize_text_field( $data['channel'] ?? '' );
		$sender_id   = sanitize_text_field( $data['sender_id'] ?? '' );
		$action_type = sanitize_text_field( $data['action_type'] ?? '' );
		$action_data = isset( $data['action_data'] ) ? (array) $data['action_data'] : array();

		if ( ! $this->is_authorized_channel_user( $channel, $sender_id ) ) {
			return array(
				'success'  => false,
				'response' => 'Not authorized.',
			);
		}

		$result = $this->execute_action( $action_type, $action_data );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'  => false,
				'response' => 'Action failed: ' . $result->get_error_message(),
			);
		}

		$response_text = $result['message'] ?? 'Action completed.';
		$conversation_id = 'channel_' . $channel . '_' . $sender_id;
		$this->save_channel_message( $conversation_id, 'system', 'Action: ' . $action_type . ' — ' . $response_text, $channel, $sender_id );

		return array(
			'success'  => true,
			'response' => $this->format_for_channel( $response_text, $channel ),
		);
	}

	// ─── CHANNEL: Get connected channels status ────────────────────────

	public function handle_get_channels( $request ) {
		$channels = array();

		// Telegram
		$tg_token  = get_option( 'n8npress_telegram_bot_token', '' );
		$tg_admins = get_option( 'n8npress_telegram_admin_ids', '' );
		$channels['telegram'] = array(
			'enabled'    => ! empty( $tg_token ),
			'configured' => ! empty( $tg_token ) && ! empty( $tg_admins ),
			'admin_ids'  => array_filter( array_map( 'trim', explode( ',', $tg_admins ) ) ),
		);

		// WhatsApp
		$wa_number = get_option( 'n8npress_whatsapp_number', '' );
		$wa_admins = get_option( 'n8npress_whatsapp_admin_ids', '' );
		$channels['whatsapp'] = array(
			'enabled'    => ! empty( $wa_number ),
			'configured' => ! empty( $wa_number ) && ! empty( $wa_admins ),
			'admin_ids'  => array_filter( array_map( 'trim', explode( ',', $wa_admins ) ) ),
		);

		return rest_ensure_response( array(
			'channels' => $channels,
			'endpoint' => rest_url( 'n8npress/v1/claw/channel-message' ),
		) );
	}

	// ─── CHANNEL HELPERS ──────────────────────────────────────────────

	private function is_authorized_channel_user( $channel, $sender_id ) {
		if ( empty( $sender_id ) ) {
			return false;
		}

		$option_key = '';
		if ( 'telegram' === $channel ) {
			$option_key = 'n8npress_telegram_admin_ids';
		} elseif ( 'whatsapp' === $channel ) {
			$option_key = 'n8npress_whatsapp_admin_ids';
		} else {
			return false;
		}

		$ids = get_option( $option_key, '' );
		$allowed = array_filter( array_map( 'trim', explode( ',', $ids ) ) );

		return in_array( $sender_id, $allowed, true );
	}

	private function format_for_channel( $text, $channel ) {
		// Convert markdown-style to channel-appropriate format
		if ( 'telegram' === $channel ) {
			// Telegram supports Markdown natively
			return $text;
		}

		if ( 'whatsapp' === $channel ) {
			// WhatsApp uses *bold* and _italic_ (same as our markdown)
			return $text;
		}

		// Strip markdown for plain text channels
		$text = preg_replace( '/\*\*(.+?)\*\*/', '$1', $text );
		$text = preg_replace( '/`(.+?)`/', '$1', $text );
		return $text;
	}

	private function save_channel_message( $conversation_id, $role, $content, $channel, $sender_id ) {
		$messages = $this->get_conversation( $conversation_id );

		$messages[] = array(
			'role'      => $role,
			'content'   => $content,
			'channel'   => $channel,
			'sender_id' => $sender_id,
			'time'      => current_time( 'mysql' ),
		);

		$messages = array_slice( $messages, -50 );
		update_option( 'n8npress_claw_' . $conversation_id, $messages, 'no' );
	}

	// ─── LOCAL RESOLUTION: Handle common queries without AI ────────────

	private function try_local_resolution( $message ) {
		$msg = mb_strtolower( trim( $message ) );

		// ─── SLASH COMMANDS ────────────────────────────────────────────
		if ( '/' === mb_substr( $msg, 0, 1 ) ) {
			$cmd = trim( mb_substr( $msg, 1 ) );
			$parts = explode( ' ', $cmd, 2 );
			$command = $parts[0];
			$args = $parts[1] ?? '';

			switch ( $command ) {
				case 'scan':
					// Redirect to opportunity scan
					return $this->try_local_resolution( 'Run a content opportunity scan' );

				case 'seo':
					return $this->try_local_resolution( 'Show products without SEO meta' );

				case 'translate':
					return $this->try_local_resolution( 'Translate all untranslated products' );

				case 'stale':
					return $this->try_local_resolution( 'List stale content that needs updating' );

				case 'thin':
					return $this->try_local_resolution( 'thin content' );

				case 'enrich':
					$threshold = absint( get_option( 'n8npress_thin_content_threshold', 300 ) );
					global $wpdb;
					$ids = $wpdb->get_col( $wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts}
						 WHERE post_type = 'product' AND post_status = 'publish'
						   AND LENGTH(post_content) < %d
						 ORDER BY LENGTH(post_content) ASC LIMIT 50",
						$threshold
					) );
					if ( empty( $ids ) ) {
						$ids = $wpdb->get_col(
							"SELECT ID FROM {$wpdb->posts}
							 WHERE post_type = 'product' AND post_status = 'publish'
							 ORDER BY ID ASC LIMIT 50"
						);
					}
					return array(
						'response' => sprintf( '**Batch enrichment** ready for %d products. Confirm to start AI enrichment.', count( $ids ) ),
						'actions'  => array( array(
							'type' => 'batch_enrich',
							'label' => 'Enrich ' . count( $ids ) . ' Products',
							'data' => array( 'product_ids' => array_map( 'absint', $ids ) ),
							'confirm' => true,
						) ),
					);

				case 'generate':
					$topic = $args ?: '';
					return $this->try_local_resolution( 'generate a blog post about ' . $topic );

				case 'reviews':
					return $this->try_local_resolution( 'reviews' );

				case 'aeo':
					return $this->try_local_resolution( 'aeo coverage' );

				case 'plugins':
				case 'env':
					return $this->try_local_resolution( 'what plugins are installed' );

				case 'customers':
				case 'crm':
					return $this->try_local_resolution( 'customer segments' );

				case 'revenue':
				case 'sales':
					return $this->try_local_resolution( 'revenue' );

				case 'products':
					return $this->try_local_resolution( 'how many products' );

				case 'help':
					return array(
						'response' => "**Available Commands:**\n\n"
							. "| Command | Description |\n"
							. "|---------|-------------|\n"
							. "| `/scan` | Content opportunity scan |\n"
							. "| `/seo` | Products missing SEO meta |\n"
							. "| `/translate` | Start translation pipeline |\n"
							. "| `/enrich` | Batch AI enrichment |\n"
							. "| `/thin` | Thin content products |\n"
							. "| `/stale` | Stale content list |\n"
							. "| `/generate [topic]` | Generate blog post |\n"
							. "| `/aeo` | AEO schema coverage |\n"
							. "| `/reviews` | Review overview |\n"
							. "| `/plugins` | Plugin environment |\n"
							. "| `/crm` | Customer segments |\n"
							. "| `/revenue` | Sales & revenue |\n"
							. "| `/products` | Product count |\n"
							. "| `/help` | This help message |",
					);

				default:
					return array(
						'response' => "Unknown command: `/$command`. Type `/help` for available commands.",
					);
			}
		}

		// Product stats
		if ( preg_match( '/how many product/i', $msg ) || preg_match( '/product count/i', $msg ) ) {
			$counts = wp_count_posts( 'product' );
			return array(
				'response' => sprintf(
					"You have **%d published products**, %d drafts, and %d total.\n\nWant me to analyze any of these for thin content or missing SEO?",
					$counts->publish, $counts->draft, $counts->publish + $counts->draft
				),
			);
		}

		// Content opportunity scan
		if ( preg_match( '/opportunit|scan|content scan/i', $msg ) ) {
			$config  = N8nPress_Site_Config::get_instance();
			$request = new WP_REST_Request( 'GET', '/n8npress/v1/content/opportunities' );
			$request->set_param( 'limit', 50 );
			$response = $config->get_content_opportunities( $request );
			$data     = $response->get_data();

			$summary = array();
			$actions  = array();

			$thin    = count( $data['thin_content'] ?? array() );
			$no_seo  = count( $data['missing_seo_meta'] ?? array() );
			$no_alt  = count( $data['missing_alt_text'] ?? array() );
			$no_tr   = count( $data['missing_translations'] ?? array() );
			$stale   = count( $data['stale_content'] ?? array() );

			if ( $thin > 0 ) {
				$summary[] = sprintf( "- **Thin content**: %d products need richer descriptions", $thin );
				$ids = array_column( $data['thin_content'], 'id' );
				$actions[] = array(
					'type'        => 'batch_enrich',
					'label'       => "Enrich {$thin} Thin Products",
					'description' => 'Send these products to AI for content enrichment',
					'data'        => array( 'product_ids' => $ids ),
					'confirm'     => true,
				);
			}
			if ( $no_seo > 0 )  { $summary[] = sprintf( "- **Missing SEO meta**: %d products", $no_seo ); }
			if ( $no_alt > 0 )  { $summary[] = sprintf( "- **Missing alt text**: %d images", $no_alt ); }
			if ( $no_tr > 0 )   { $summary[] = sprintf( "- **Missing translations**: %d products", $no_tr ); }
			if ( $stale > 0 )   { $summary[] = sprintf( "- **Stale content**: %d posts/products not updated in 90+ days", $stale ); }

			if ( empty( $summary ) ) {
				return array( 'response' => "Great news! No content opportunities found. Your store content looks healthy." );
			}

			return array(
				'response' => "**Content Opportunity Scan Results:**\n\n" . implode( "\n", $summary ) . "\n\nTotal issues: " . array_sum( array_filter( array( $thin, $no_seo, $no_alt, $no_tr, $stale ) ) ),
				'actions'  => $actions,
			);
		}

		// Missing SEO meta
		if ( preg_match( '/seo meta|without seo|missing seo|no seo|no meta/i', $msg ) ) {
			$config  = N8nPress_Site_Config::get_instance();
			$request = new WP_REST_Request( 'GET', '/n8npress/v1/content/opportunities' );
			$request->set_param( 'limit', 50 );
			$response = $config->get_content_opportunities( $request );
			$data     = $response->get_data();
			$missing  = $data['missing_seo_meta'] ?? array();

			if ( empty( $missing ) ) {
				return array( 'response' => 'All published products have SEO meta descriptions.' );
			}

			$ids  = array_column( $missing, 'id' );
			$list = '';
			foreach ( array_slice( $missing, 0, 15 ) as $item ) {
				$list .= sprintf( "- **%s** (ID: %d)\n", $item['title'], $item['id'] );
			}
			if ( count( $missing ) > 15 ) {
				$list .= sprintf( "- … and %d more\n", count( $missing ) - 15 );
			}

			return array(
				'response' => sprintf(
					"**%d products** are missing SEO descriptions:\n\n%s\nWould you like to enrich these with AI-generated SEO meta?",
					count( $missing ), $list
				),
				'actions' => array(
					array(
						'type'        => 'batch_enrich',
						'label'       => 'Enrich ' . count( $missing ) . ' Products with SEO Meta',
						'description' => 'Generate optimized SEO descriptions for these products',
						'data'        => array( 'product_ids' => $ids ),
						'confirm'     => true,
					),
				),
			);
		}

		// Thin content query
		if ( preg_match( '/thin content|thin product/i', $msg ) ) {
			$threshold = absint( get_option( 'n8npress_thin_content_threshold', 300 ) );
			global $wpdb;
			$thin = $wpdb->get_results( $wpdb->prepare(
				"SELECT ID, post_title, LENGTH(post_content) as chars
				 FROM {$wpdb->posts}
				 WHERE post_type = 'product' AND post_status = 'publish'
				   AND LENGTH(post_content) < %d
				 ORDER BY chars ASC LIMIT 20",
				$threshold
			) );

			if ( empty( $thin ) ) {
				return array( 'response' => "Great news! No products with thin content (below {$threshold} characters) found." );
			}

			$list = '';
			$ids = array();
			foreach ( $thin as $p ) {
				$list .= sprintf( "- **%s** (%d chars)\n", $p->post_title, $p->chars );
				$ids[] = $p->ID;
			}

			return array(
				'response' => sprintf(
					"Found **%d products** with thin content (below %d chars):\n\n%s\nWould you like to enrich these with AI?",
					count( $thin ), $threshold, $list
				),
				'actions' => array(
					array(
						'type'        => 'batch_enrich',
						'label'       => 'Enrich All ' . count( $thin ) . ' Products',
						'description' => 'Send these products to AI for content enrichment',
						'data'        => array( 'product_ids' => $ids ),
						'confirm'     => true,
					),
				),
			);
		}

		// Missing translations
		if ( preg_match( '/missing translation|untranslated/i', $msg ) ) {
			$config  = N8nPress_Site_Config::get_instance();
			$request = new WP_REST_Request( 'GET', '/n8npress/v1/content/opportunities' );
			$request->set_param( 'limit', 20 );
			$response = $config->get_content_opportunities( $request );
			$data     = $response->get_data();
			$missing  = $data['missing_translations'] ?? array();

			if ( empty( $missing ) ) {
				return array( 'response' => 'All products are fully translated across active languages.' );
			}

			$by_lang = array();
			foreach ( $missing as $item ) {
				$lang = $item['missing_language'] ?? 'unknown';
				$by_lang[ $lang ] = ( $by_lang[ $lang ] ?? 0 ) + 1;
			}

			$list = '';
			foreach ( $by_lang as $lang => $count ) {
				$list .= sprintf( "- **%s**: %d products\n", strtoupper( $lang ), $count );
			}

			return array(
				'response' => sprintf(
					"Found **%d missing translations**:\n\n%s\nWould you like to trigger the translation pipeline?",
					count( $missing ), $list
				),
				'actions' => array(
					array(
						'type'        => 'trigger_translation',
						'label'       => 'Start Translation Pipeline',
						'description' => 'Send untranslated products to AI translation workflow',
						'data'        => array( 'languages' => array_keys( $by_lang ) ),
						'confirm'     => true,
					),
				),
			);
		}

		// Generate content / blog post
		if ( preg_match( '/generate|blog post|write a post|best.selling/i', $msg ) ) {
			$webhook_url = get_option( 'n8npress_seo_webhook_url', '' );
			if ( empty( $webhook_url ) ) {
				return array(
					'response' => "**Content generation** requires the n8n Webhook URL to be configured.\n\nGo to **n8nPress → Settings → Connection** and enter your n8n webhook URL (e.g. `https://your-n8n.example.com/webhook/`).",
				);
			}

			// Extract topic from message if possible
			$topic = '';
			if ( preg_match( '/about (.+)/i', $msg, $m ) ) {
				$topic = trim( $m[1] );
			}

			if ( empty( $topic ) ) {
				return array(
					'response' => "What topic should I write about? Tell me the subject and I'll generate a blog post. For example:\n\n- *Generate a blog post about baglama maintenance tips*\n- *Write a post about best beginner instruments*",
				);
			}

			return array(
				'response' => sprintf( "Generating a blog post about **%s**...\n\nThe content scheduler will create a full post with AI-generated content and images. You'll be notified when it's ready.", $topic ),
				'actions' => array(
					array(
						'type'    => 'generate_content',
						'label'   => 'Generate Post: ' . mb_substr( $topic, 0, 40 ),
						'description' => 'Create AI blog post via n8n Content Scheduler',
						'data'    => array( 'topic' => $topic ),
						'confirm' => true,
					),
				),
			);
		}

		// Stale content
		if ( preg_match( '/stale|outdated/i', $msg ) ) {
			$days   = absint( get_option( 'n8npress_freshness_stale_days', 90 ) );
			$cutoff = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
			global $wpdb;
			$stale = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type IN ('product','post') AND post_status = 'publish'
				   AND post_modified < %s",
				$cutoff
			) );

			return array(
				'response' => sprintf(
					"There are **%d posts/products** not updated in the last %d days (since %s).\n\nThese may benefit from AI content refresh.",
					$stale, $days, $cutoff
				),
			);
		}

		// Plugin environment
		if ( preg_match( '/what plugin|which plugin|detect|environment/i', $msg ) ) {
			$detector = N8nPress_Plugin_Detector::get_instance();
			$env      = $detector->get_environment();

			$list = '';
			$labels = array(
				'seo' => 'SEO', 'translation' => 'Translation', 'email' => 'Email/SMTP',
				'crm' => 'CRM', 'page_builder' => 'Page Builder', 'cache' => 'Cache',
			);
			foreach ( $labels as $key => $label ) {
				$plugin  = $env[ $key ]['plugin'] ?? 'none';
				$version = $env[ $key ]['version'] ?? '';
				$display = 'none' !== $plugin ? ucwords( str_replace( '-', ' ', $plugin ) ) : 'Not detected';
				$list .= sprintf( "- **%s**: %s%s\n", $label, $display, $version ? " v{$version}" : '' );
			}

			return array(
				'response' => "Detected plugin environment:\n\n{$list}\nn8nPress integrates with all detected plugins automatically.",
			);
		}

		// Review summary
		if ( preg_match( '/review|sentiment/i', $msg ) ) {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return array( 'response' => 'WooCommerce is not active. Review analytics require WooCommerce.' );
			}

			$review_count = get_comments( array(
				'type'  => 'review',
				'count' => true,
				'status' => 'approve',
			) );

			$pending = get_comments( array(
				'type'  => 'review',
				'count' => true,
				'status' => 'hold',
			) );

			return array(
				'response' => sprintf(
					"Review overview:\n- **%d** approved reviews\n- **%d** pending reviews\n\nWant me to run AI sentiment analysis on recent reviews?",
					$review_count, $pending
				),
			);
		}

		// AEO coverage
		if ( preg_match( '/aeo|faq|schema|structured data/i', $msg ) ) {
			if ( class_exists( 'N8nPress_AEO' ) ) {
				$aeo     = N8nPress_AEO::get_instance();
				$request = new WP_REST_Request( 'GET', '/n8npress/v1/aeo/coverage' );
				$data    = $aeo->get_aeo_coverage( $request );

				if ( is_array( $data ) ) {
					return array(
						'response' => sprintf(
							"AEO Coverage:\n- FAQ: **%d%%** of products\n- HowTo: **%d%%**\n- Speakable: **%d%%**\n\nWant me to generate AEO content for uncovered products?",
							$data['faq_coverage'] ?? 0,
							$data['howto_coverage'] ?? 0,
							$data['speakable_coverage'] ?? 0
						),
					);
				}
			}
			return null; // Fall through to AI
		}

		// Customer / CRM queries
		if ( preg_match( '/customer|vip|at.risk|segment|dormant|loyal|win.back|lifecycle/i', $msg ) ) {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return array( 'response' => 'WooCommerce is not active. Customer intelligence requires WooCommerce.' );
			}

			$crm = N8nPress_CRM_Bridge::get_instance();

			// Segment overview
			if ( preg_match( '/segment|overview/i', $msg ) ) {
				$counts = get_option( 'n8npress_crm_segment_counts', array() );
				if ( empty( $counts ) ) {
					return array(
						'response' => "Customer segments haven't been computed yet. Would you like me to run the analysis now?",
						'actions'  => array(
							array(
								'type'  => 'crm_refresh',
								'label' => 'Refresh Customer Segments',
								'data'  => array(),
								'confirm' => true,
							),
						),
					);
				}

				$list = '';
				$segment_labels = array(
					'vip' => 'VIP', 'loyal' => 'Loyal', 'active' => 'Active', 'new' => 'New',
					'at_risk' => 'At Risk', 'dormant' => 'Dormant', 'lost' => 'Lost', 'one_time' => 'One-Time',
				);
				$total = 0;
				foreach ( $segment_labels as $key => $label ) {
					$c = $counts[ $key ] ?? 0;
					$total += $c;
					if ( $c > 0 ) {
						$list .= sprintf( "- **%s**: %d customers\n", $label, $c );
					}
				}

				return array(
					'response' => sprintf( "Customer Segments (%d total):\n\n%s\nLast updated: %s", $total, $list, get_option( 'n8npress_crm_last_refresh', 'Never' ) ),
				);
			}

			// VIP customers
			if ( preg_match( '/vip/i', $msg ) ) {
				$vips = get_users( array(
					'meta_key' => '_n8npress_crm_segment', 'meta_value' => 'vip',
					'number' => 10, 'role' => 'customer',
				) );

				if ( empty( $vips ) ) {
					return array( 'response' => 'No VIP customers found yet. Run a segment refresh to compute customer segments.' );
				}

				update_meta_cache( 'user', wp_list_pluck( $vips, 'ID' ) );
				$list = '';
				foreach ( $vips as $user ) {
					$stats = get_user_meta( $user->ID, '_n8npress_crm_stats', true );
					$list .= sprintf( "- **%s** — %d orders, $%.2f total\n", $user->display_name, $stats['order_count'] ?? 0, $stats['total_spent'] ?? 0 );
				}

				return array( 'response' => sprintf( "Top VIP Customers:\n\n%s", $list ) );
			}

			// At-risk customers
			if ( preg_match( '/at.risk|risk/i', $msg ) ) {
				$at_risk = get_users( array(
					'meta_key' => '_n8npress_crm_segment', 'meta_value' => 'at_risk',
					'number' => 10, 'role' => 'customer',
				) );

				if ( empty( $at_risk ) ) {
					return array( 'response' => 'No at-risk customers detected. Great news!' );
				}

				update_meta_cache( 'user', wp_list_pluck( $at_risk, 'ID' ) );
				$list = '';
				foreach ( $at_risk as $user ) {
					$stats = get_user_meta( $user->ID, '_n8npress_crm_stats', true );
					$list .= sprintf( "- **%s** — last order %s (%d days ago)\n", $user->display_name, $stats['last_order'] ?? '?', $stats['days_since'] ?? 0 );
				}

				return array(
					'response' => sprintf( "At-Risk Customers (%d):\n\n%s\nWould you like to trigger a win-back campaign?", count( $at_risk ), $list ),
					'actions'  => array(
						array(
							'type'  => 'trigger_winback',
							'label' => 'Send Win-Back Emails',
							'data'  => array( 'segment' => 'at_risk' ),
							'confirm' => true,
						),
					),
				);
			}

			// Generic customer count
			$total = count_users();
			$customer_count = $total['avail_roles']['customer'] ?? 0;
			return array(
				'response' => sprintf(
					"You have **%d registered customers**.\n\nSay `customer segments` for breakdown, `VIP customers` for top spenders, or `at-risk customers` for win-back targets.",
					$customer_count
				),
			);
		}

		// Revenue / sales query
		if ( preg_match( '/revenue|sales|order count/i', $msg ) ) {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return array( 'response' => 'WooCommerce is not active.' );
			}

			global $wpdb;
			$thirty = $wpdb->get_row(
				"SELECT COUNT(*) as orders, COALESCE(SUM(pm.meta_value), 0) as revenue
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
				 WHERE p.post_type = 'shop_order'
				   AND p.post_status IN ('wc-completed', 'wc-processing')
				   AND p.post_date > DATE_SUB(NOW(), INTERVAL 30 DAY)"
			);

			$currency = get_woocommerce_currency_symbol();

			return array(
				'response' => sprintf(
					"Last 30 days:\n- **%d** orders\n- **%s%.2f** revenue\n\nWant a customer segment breakdown or product performance?",
					absint( $thirty->orders ),
					$currency,
					floatval( $thirty->revenue )
				),
			);
		}

		// No local match — let AI handle it
		return null;
	}

	// ─── ACTION EXECUTION ──────────────────────────────────────────────

	private function execute_action( $type, $data ) {
		// Check daily limit for actions that use AI
		$ai_actions = array( 'batch_enrich', 'trigger_translation', 'generate_content', 'aeo_generate', 'enrich_stale', 'trigger_winback' );
		if ( in_array( $type, $ai_actions, true ) && class_exists( 'N8nPress_Token_Tracker' ) && N8nPress_Token_Tracker::is_limit_exceeded() ) {
			$limit = get_option( 'n8npress_daily_token_limit', 0 );
			return new WP_Error( 'limit_exceeded', sprintf( 'Daily AI budget limit reached ($%.2f). Try again tomorrow.', $limit ) );
		}

		switch ( $type ) {
			case 'batch_enrich':
				$ids = isset( $data['product_ids'] ) ? array_map( 'absint', (array) $data['product_ids'] ) : array();
				if ( empty( $ids ) ) {
					return new WP_Error( 'no_ids', 'No product IDs provided' );
				}

				$ai = N8nPress_AI_Content::get_instance();
				$request = new WP_REST_Request( 'POST', '/n8npress/v1/product/enrich-batch' );
				$request->set_param( 'product_ids', $ids );
				$request->set_param( 'options', array() );
				$result = $ai->handle_batch_enrich_request( $request );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return array(
					'success' => true,
					'message' => sprintf( 'Batch enrichment started. %d products queued.', $result['queued'] ?? 0 ),
					'batch_id' => $result['batch_id'] ?? '',
				);

			case 'trigger_translation':
				$webhook_url = get_option( 'n8npress_seo_webhook_url', '' );
				if ( empty( $webhook_url ) ) {
					return new WP_Error( 'no_webhook', 'n8n webhook URL is not configured' );
				}

				$url = trailingslashit( $webhook_url ) . 'translation-request';
				$response = wp_remote_post( $url, array(
					'headers' => array(
						'Content-Type' => 'application/json',
						'Authorization' => 'Bearer ' . get_option( 'n8npress_seo_api_token', '' ),
					),
					'body' => wp_json_encode( array(
						'event'            => 'translate_missing',
						'target_languages' => implode( ',', array_map( 'sanitize_text_field', (array) ( $data['languages'] ?? array() ) ) ),
						'fetch_pending'    => true,
						'site_url'         => get_site_url(),
					) ),
					'timeout' => 10,
				) );

				if ( is_wp_error( $response ) ) {
					N8nPress_Logger::log( 'Translation pipeline failed', 'error', array(
						'languages' => $data['languages'] ?? array(),
						'error'     => $response->get_error_message(),
					) );
					return $response;
				}

				$tr_code = wp_remote_retrieve_response_code( $response );
				if ( $tr_code < 200 || $tr_code >= 300 ) {
					N8nPress_Logger::log( 'Translation pipeline n8n returned HTTP ' . $tr_code, 'error', array(
						'languages' => $data['languages'] ?? array(),
					) );
					return new WP_Error( 'n8n_error', 'n8n returned HTTP ' . $tr_code );
				}

				N8nPress_Logger::log( 'Translation pipeline triggered', 'info', array(
					'languages' => $data['languages'] ?? array(),
				) );

				return array(
					'success' => true,
					'message' => 'Translation pipeline triggered for languages: ' . implode( ', ', $data['languages'] ?? array() ),
				);

			case 'generate_content':
				$webhook_url = get_option( 'n8npress_seo_webhook_url', '' );
				if ( empty( $webhook_url ) ) {
					return new WP_Error( 'no_webhook', 'n8n webhook URL is not configured' );
				}

				$url = trailingslashit( $webhook_url ) . 'n8npress-content-scheduler';
				$response = wp_remote_post( $url, array(
					'headers' => array(
						'Content-Type' => 'application/json',
						'Authorization' => 'Bearer ' . get_option( 'n8npress_seo_api_token', '' ),
					),
					'body' => wp_json_encode( array(
						'action'    => 'generate_content',
						'topic'     => sanitize_text_field( $data['topic'] ?? '' ),
						'language'  => get_option( 'n8npress_target_language', 'tr' ),
						'tone'      => sanitize_text_field( $data['tone'] ?? 'professional' ),
						'word_count' => absint( $data['word_count'] ?? 1500 ),
						'site_name' => get_bloginfo( 'name' ),
						'site_url'  => get_site_url(),
						'callback_url' => rest_url( 'n8npress/v1/schedule/callback' ),
					) ),
					'timeout' => 10,
				) );

				if ( is_wp_error( $response ) ) {
					N8nPress_Logger::log( 'Content generation failed', 'error', array(
						'topic' => $data['topic'] ?? '',
						'error' => $response->get_error_message(),
					) );
					return $response;
				}

				$gen_code = wp_remote_retrieve_response_code( $response );
				if ( $gen_code < 200 || $gen_code >= 300 ) {
					N8nPress_Logger::log( 'Content generation n8n returned HTTP ' . $gen_code, 'error', array(
						'topic' => $data['topic'] ?? '',
					) );
					return new WP_Error( 'n8n_error', 'n8n returned HTTP ' . $gen_code );
				}

				N8nPress_Logger::log( 'Content generation triggered: ' . ( $data['topic'] ?? '' ), 'info', array(
					'topic' => $data['topic'] ?? '',
				) );

				return array(
					'success' => true,
					'message' => 'Content generation triggered for: ' . ( $data['topic'] ?? 'unknown topic' ),
				);

			case 'crm_refresh':
				if ( class_exists( 'N8nPress_CRM_Bridge' ) ) {
					$crm_bridge = N8nPress_CRM_Bridge::get_instance();
					$crm_bridge->cron_refresh_segments();
					$counts = get_option( 'n8npress_crm_segment_counts', array() );
					return array(
						'success' => true,
						'message' => 'Customer segments refreshed. Total customers segmented: ' . array_sum( $counts ),
					);
				}
				return new WP_Error( 'no_crm', 'CRM Bridge is not active' );

			case 'trigger_winback':
				$webhook_url = get_option( 'n8npress_seo_webhook_url', '' );
				if ( empty( $webhook_url ) ) {
					return new WP_Error( 'no_webhook', 'n8n webhook URL is not configured' );
				}

				$url = trailingslashit( $webhook_url ) . 'crm-lifecycle';
				$response = wp_remote_post( $url, array(
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . get_option( 'n8npress_seo_api_token', '' ),
					),
					'body'    => wp_json_encode( array(
						'event'        => 'win_back_campaign',
						'segment'      => $data['segment'] ?? 'at_risk',
						'site_url'     => get_site_url(),
						'callback_url' => rest_url( 'n8npress/v1/crm/lifecycle-callback' ),
					) ),
					'timeout' => 10,
				) );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				return array(
					'success' => true,
					'message' => 'Win-back campaign triggered for at-risk customers via n8n.',
				);

			case 'scan_opportunities':
				$config  = N8nPress_Site_Config::get_instance();
				$req     = new WP_REST_Request( 'GET', '/n8npress/v1/content/opportunities' );
				$req->set_param( 'limit', 50 );
				$opps_response = $config->get_content_opportunities( $req );
				$opps    = $opps_response->get_data();
				$summary = array();
				foreach ( $opps as $key => $val ) {
					if ( $key === 'summary' || ! is_array( $val ) ) {
						continue;
					}
					$summary[] = ucwords( str_replace( '_', ' ', $key ) ) . ': ' . count( $val );
				}
				return array(
					'success' => true,
					'message' => "Content opportunities found:\n" . implode( "\n", $summary ),
					'data'    => $opps['summary'] ?? array(),
				);

			case 'aeo_generate':
				$webhook_url = get_option( 'n8npress_seo_webhook_url', '' );
				if ( empty( $webhook_url ) ) {
					return new WP_Error( 'no_webhook', 'n8n webhook URL is not configured' );
				}

				$url = trailingslashit( $webhook_url ) . 'aeo-generate';
				$response = wp_remote_post( $url, array(
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . get_option( 'n8npress_seo_api_token', '' ),
					),
					'body'    => wp_json_encode( array(
						'event'     => 'aeo_generate',
						'site_url'  => get_site_url(),
						'product_ids' => isset( $data['product_ids'] ) ? array_map( 'absint', (array) $data['product_ids'] ) : array(),
					) ),
					'timeout' => 10,
				) );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				return array(
					'success' => true,
					'message' => 'AEO generation triggered. FAQ/HowTo/Speakable schema will be generated.',
				);

			case 'enrich_stale':
				$stale_posts = get_posts( array(
					'post_type'   => array( 'product', 'post' ),
					'post_status' => 'publish',
					'date_query'  => array( array( 'before' => '90 days ago' ) ),
					'fields'      => 'ids',
					'numberposts' => 20,
					'meta_query'  => array(
						array(
							'key'     => '_n8npress_enriched',
							'compare' => 'NOT EXISTS',
						),
					),
				) );

				if ( empty( $stale_posts ) ) {
					return array(
						'success' => true,
						'message' => 'No stale content found that needs enrichment.',
					);
				}

				$ai = N8nPress_AI_Content::get_instance();
				$request = new WP_REST_Request( 'POST', '/n8npress/v1/product/enrich-batch' );
				$request->set_param( 'product_ids', $stale_posts );
				$request->set_param( 'options', array() );
				$result = $ai->handle_batch_enrich_request( $request );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return array(
					'success' => true,
					'message' => sprintf( 'Stale content enrichment started. %d items queued.', count( $stale_posts ) ),
				);

			default:
				return new WP_Error( 'unknown_action', 'Unknown action type: ' . $type );
		}
	}

	// ─── DIRECT AI CALL (built-in engine, no n8n) ─────────────────────

	private function call_ai_directly( $message, $conversation_id, $context ) {
		$store = $context['store'] ?? array();
		$store_name = $store['name'] ?? get_bloginfo( 'name' );
		$products   = $store['product_count'] ?? 0;
		$seo        = $store['seo_plugin'] ?? 'none';
		$trans      = $store['translation_plugin'] ?? 'none';
		$languages  = $store['languages'] ?? array();

		$system_prompt = "You are the AI assistant for {$store_name}, a WooCommerce store. "
			. "Store has {$products} products. SEO: {$seo}. Translation: {$trans}. "
			. ( ! empty( $languages ) ? 'Languages: ' . implode( ', ', $languages ) . '. ' : '' )
			. "Be concise and helpful. To execute actions, include JSON: {\"action\": \"type\", \"params\": {...}}";

		// Build conversation messages with history
		$history  = get_option( 'n8npress_claw_' . $conversation_id, array() );
		$messages = array( array( 'role' => 'system', 'content' => $system_prompt ) );
		foreach ( array_slice( $history, -10 ) as $msg ) {
			if ( in_array( $msg['role'] ?? '', array( 'user', 'assistant' ), true ) ) {
				$messages[] = array( 'role' => $msg['role'], 'content' => $msg['content'] ?? '' );
			}
		}
		$messages[] = array( 'role' => 'user', 'content' => $message );

		$result = N8nPress_AI_Engine::dispatch( 'open-claw', $messages, array( 'timeout' => 45 ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response_text = $result['content'] ?? '';
		$actions       = array();

		// Extract action JSON if present
		if ( preg_match( '/\{"action".*?\}/s', $response_text, $match ) ) {
			$action_json = json_decode( $match[0], true );
			if ( $action_json && ! empty( $action_json['action'] ) ) {
				$actions[] = $action_json;
				$response_text = trim( str_replace( $match[0], '', $response_text ) );
			}
		}

		$this->save_message( $conversation_id, 'assistant', sanitize_textarea_field( $response_text ), $actions );

		N8nPress_Logger::log( 'Open Claw (direct): "' . mb_substr( $message, 0, 60 ) . '"', 'info' );

		return array(
			'response'        => $response_text,
			'actions'         => $actions,
			'conversation_id' => $conversation_id,
			'success'         => true,
		);
	}

	// ─── AI DISPATCH ────────────────────────────────────────────────────

	private function send_to_n8n( $message, $conversation_id, $context ) {
		if ( ! class_exists( 'N8nPress_AI_Engine' ) ) {
			return new WP_Error( 'no_ai', 'No AI provider configured. Set an API key in Settings → AI API Keys.' );
		}
		return $this->call_ai_directly( $message, $conversation_id, $context );
	}

	// ─── CONTEXT: Build site snapshot for AI ───────────────────────────

	private function build_site_context() {
		$detector = N8nPress_Plugin_Detector::get_instance();
		$env      = $detector->get_environment();

		$product_counts = wp_count_posts( 'product' );
		$post_counts    = wp_count_posts( 'post' );

		$ai_provider = get_option( 'n8npress_ai_provider', 'openai' );
		$ai_model    = get_option( 'n8npress_ai_model', 'gpt-4o-mini' );

		// Determine API URL based on provider
		$api_urls = array(
			'openai'    => 'https://api.openai.com/v1/chat/completions',
			'anthropic' => 'https://api.anthropic.com/v1/messages',
			'google'    => 'https://generativelanguage.googleapis.com/v1beta/models/' . $ai_model . ':generateContent',
		);

		// Get the active API key
		$key_map = array(
			'openai'    => 'n8npress_openai_api_key',
			'anthropic' => 'n8npress_anthropic_api_key',
			'google'    => 'n8npress_google_ai_api_key',
		);
		$ai_key = get_option( $key_map[ $ai_provider ] ?? '', '' );

		return array(
			'site_name'   => get_bloginfo( 'name' ),
			'site_url'    => get_site_url(),
			'language'    => get_option( 'n8npress_target_language', 'tr' ),
			'products'    => absint( $product_counts->publish ?? 0 ),
			'posts'       => absint( $post_counts->publish ?? 0 ),
			'seo_plugin'  => $env['seo']['plugin'] ?? 'none',
			'translation_plugin' => $env['translation']['plugin'] ?? 'none',
			'active_languages'   => $env['translation']['active_languages'] ?? array(),
			'ai_provider' => $ai_provider,
			'ai'          => array(
				'provider' => $ai_provider,
				'model'    => $ai_model,
				'api_key'  => $ai_key,
				'api_url'  => $api_urls[ $ai_provider ] ?? $api_urls['openai'],
			),
			'available_actions' => array(
				'batch_enrich'       => 'Enrich products with AI-generated content',
				'trigger_translation' => 'Start AI translation pipeline',
				'generate_content'   => 'Generate a blog post with AI',
				'scan_opportunities' => 'Scan for content opportunities',
				'aeo_generate'       => 'Generate FAQ/HowTo/Speakable schema',
			),
		);
	}

	// ─── MESSAGE STORAGE: transient-based conversation ──────────────────

	private function save_message( $conversation_id, $role, $content, $actions = array() ) {
		$messages = $this->get_conversation( $conversation_id );

		$messages[] = array(
			'role'    => $role,
			'content' => $content,
			'actions' => $actions,
			'time'    => current_time( 'mysql' ),
		);

		// Keep last 50 messages per conversation
		$messages = array_slice( $messages, -50 );

		update_option( 'n8npress_claw_' . $conversation_id, $messages, 'no' );

		// Track active conversation ID for the user
		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, '_n8npress_claw_conversation', $conversation_id );
		}
	}

	private function get_conversation( $conversation_id ) {
		if ( empty( $conversation_id ) ) {
			return array();
		}

		$messages = get_option( 'n8npress_claw_' . $conversation_id, array() );
		return is_array( $messages ) ? $messages : array();
	}

	// ─── PERMISSIONS ───────────────────────────────────────────────────

	public function check_admin_permission( $request ) {
		return N8nPress_Permission::check_token_or_admin( $request );
	}

	public function check_n8n_token( $request ) {
		return N8nPress_Permission::check_token( $request );
	}
}
