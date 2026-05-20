<?php
/**
 * LuwiPress Customer Chat
 *
 * Customer-facing AI chatbot with RAG context retrieval and
 * WhatsApp/Telegram escalation via deep links.
 *
 * @package LuwiPress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Customer_Chat {

	private static $instance = null;

	/**
	 * Canonical English source for every fallback chip / chip-row label
	 * the widget can emit. This is the DATA dictionary — runtime UX strings
	 * are derived from this via localize_chips() (AI-translated + cached
	 * per language) or via the `luwipress_chat_chip_strings` filter for
	 * power-user overrides. Never read this map directly from rendering
	 * code — always go through localize_chips() so the global ecosystem
	 * gets the right language for the customer.
	 *
	 * Placeholders: %s = product/category label (already in customer's
	 * language because it comes from the WPML-translated post/term).
	 */
	private const CHIP_VOCAB_EN = array(
		// Action chips — appear as clickable next-message text. English
		// here is the canonical source for AI translation; templates are
		// phrased to translate cleanly across languages (avoid awkward
		// constructions like "Other %s options" where translators struggle
		// to keep %s in a grammatical position).
		'shipping'       => 'How long is shipping?',
		'returns'        => 'What about returns?',
		'talk_to_team'   => 'Talk to our team',
		'premium_picks'  => 'Show premium picks',
		'top_rated'      => 'Best picks',
		'whats_new'      => "What's new?",
		'did_you_mean'   => 'Did you mean %s?',
		'show_me'        => 'Show me %s',
		'more_like'      => 'Show similar to %s',
		'other_options'  => 'More from %s',
		'top_rated_x'    => 'Best %s',
		'whats_new_in'   => "What's new in %s?",
		'yes_show_me'    => 'Yes, show me %s',
		// Row labels — appear above the chip row.
		'clarify_label'  => 'Pick one to clarify:',
		'followup_label' => 'You can also ask:',
		// System messages — assistant-voice fallbacks shown in-bubble.
		'msg_rate_limit' => "You're sending messages too quickly. Please wait a moment.",
		'msg_error'      => 'Sorry, something went wrong. Please try again.',
		'msg_connecting' => 'Connecting you with our team. A new window will open shortly.',
	);

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
		// Async chip-pack translator — fires from wp_cron so first-visitor
		// page loads never block on the AI round-trip.
		add_action( 'luwipress_chat_translate_chip_pack', array( $this, 'cron_translate_chip_pack' ), 10, 1 );
	}

	/**
	 * Create database tables for chat conversations and messages.
	 * Called from LuwiPress::activate().
	 */
	public static function create_table() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$conv_table = $wpdb->prefix . 'luwipress_chat_conversations';
		$msg_table  = $wpdb->prefix . 'luwipress_chat_messages';

		$sql_conv = "CREATE TABLE {$conv_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(64) NOT NULL,
			customer_id BIGINT UNSIGNED DEFAULT NULL,
			customer_email VARCHAR(200) DEFAULT NULL,
			customer_name VARCHAR(200) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			escalated_to VARCHAR(20) DEFAULT NULL,
			page_url VARCHAR(500) DEFAULT NULL,
			ip_address VARCHAR(45) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY session_id_idx (session_id),
			KEY customer_id_idx (customer_id),
			KEY status_idx (status)
		) {$charset};";

		$sql_msg = "CREATE TABLE {$msg_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL,
			content TEXT NOT NULL,
			source VARCHAR(20) DEFAULT 'web',
			metadata LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY conversation_id_idx (conversation_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_conv );
		dbDelta( $sql_msg );
	}

	/**
	 * Register all chat REST API endpoints.
	 */
	public function register_endpoints() {
		register_rest_route( 'luwipress/v1', '/chat/message', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_message' ),
			'permission_callback' => array( $this, 'check_chat_permission' ),
			'args'                => array(
				'session_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'message'    => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'validate_callback' => function( $value ) {
						return is_string( $value ) && mb_strlen( $value ) <= 1000;
					},
				),
				'page_url'   => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		) );

		register_rest_route( 'luwipress/v1', '/chat/session/(?P<session_id>[a-f0-9]{32,64})', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_get_session' ),
			'permission_callback' => array( $this, 'check_chat_permission' ),
			'args'                => array(
				'session_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'luwipress/v1', '/chat/config', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_get_config' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( 'luwipress/v1', '/chat/session/escalate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_escalate' ),
			'permission_callback' => array( $this, 'check_chat_permission' ),
			'args'                => array(
				'session_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'channel'    => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// Read chat module settings (admin-only, mirrors Customer Chat tab)
		register_rest_route( 'luwipress/v1', '/chat/settings', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_get_settings' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		// Admin trigger to warm / re-translate chip packs for one or all
		// active site languages. POST with no body warms all uncached;
		// POST { lang: "fr", force: true } re-translates a specific lang
		// from scratch (deletes the cached option first).
		register_rest_route( 'luwipress/v1', '/chat/warm-translations', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_warm_translations' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'lang'  => array( 'required' => false, 'type' => 'string' ),
				'force' => array( 'required' => false, 'type' => 'boolean' ),
			),
		) );

		// Write chat module settings — partial update
		register_rest_route( 'luwipress/v1', '/chat/settings', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_set_settings' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'enabled'             => array( 'required' => false, 'type' => 'boolean' ),
				'greeting'            => array( 'required' => false, 'type' => 'string' ),
				'tone'                => array( 'required' => false, 'type' => 'string' ),
				'custom_instructions' => array( 'required' => false, 'type' => 'string' ),
				'shipping_policy'     => array( 'required' => false, 'type' => 'string' ),
				'returns_policy'      => array( 'required' => false, 'type' => 'string' ),
				'escalation_channel'  => array( 'required' => false, 'type' => 'string' ),
				'daily_budget'        => array( 'required' => false, 'type' => 'number' ),
				'max_messages'        => array( 'required' => false, 'type' => 'integer' ),
				'rate_limit'          => array( 'required' => false, 'type' => 'integer' ),
			),
		) );

		// GET /chat/sessions — admin paginated list with filters (FR-003)
		register_rest_route( 'luwipress/v1', '/chat/sessions', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_list_sessions' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'limit'          => array( 'required' => false, 'type' => 'integer' ),
				'offset'         => array( 'required' => false, 'type' => 'integer' ),
				'status'         => array( 'required' => false, 'type' => 'string' ),
				'escalated_only' => array( 'required' => false, 'type' => 'boolean' ),
				'customer_email' => array( 'required' => false, 'type' => 'string' ),
				'since'          => array( 'required' => false, 'type' => 'string' ),
			),
		) );

		// GET /chat/messages/search — admin content search (FR-003)
		register_rest_route( 'luwipress/v1', '/chat/messages/search', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_search_messages' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'q'     => array( 'required' => true, 'type' => 'string' ),
				'limit' => array( 'required' => false, 'type' => 'integer' ),
				'role'  => array( 'required' => false, 'type' => 'string' ),
				'since' => array( 'required' => false, 'type' => 'string' ),
			),
		) );
	}

	/**
	 * Admin-only permission for settings read/write.
	 */
	public function check_admin_permission( $request ) {
		return LuwiPress_Permission::check_token_or_admin( $request );
	}

	/**
	 * POST /chat/warm-translations — admin trigger.
	 *
	 * Body:
	 *   {}                          — queue every active site lang that's uncached
	 *   { "lang": "fr" }            — queue just FR
	 *   { "lang": "fr", "force": 1 } — delete FR cache then queue (re-translate)
	 */
	public function handle_warm_translations( $request ) {
		$data  = $request->get_json_params();
		if ( empty( $data ) ) { $data = $request->get_body_params(); }
		$lang  = isset( $data['lang'] )  ? sanitize_key( substr( (string) $data['lang'], 0, 2 ) ) : '';
		$force = ! empty( $data['force'] );

		if ( $lang ) {
			if ( $force ) {
				delete_option( 'luwipress_chat_chips_' . $lang );
			}
			$this->schedule_chip_pack_translation( $lang );
			return rest_ensure_response( array( 'scheduled' => array( $lang ), 'force' => $force ) );
		}

		$scheduled = array();
		foreach ( $this->get_active_site_languages() as $l ) {
			if ( $l === 'en' ) { continue; }
			if ( $force ) {
				delete_option( 'luwipress_chat_chips_' . $l );
			}
			if ( ! get_option( 'luwipress_chat_chips_' . $l, null ) ) {
				$this->schedule_chip_pack_translation( $l );
				$scheduled[] = $l;
			}
		}
		return rest_ensure_response( array( 'scheduled' => $scheduled, 'force' => $force ) );
	}

	/**
	 * GET /chat/settings — mirrors the Customer Chat tab.
	 */
	public function handle_get_settings( $request ) {
		return array(
			'enabled'             => (bool) get_option( 'luwipress_chat_enabled', 0 ),
			'greeting'            => (string) get_option( 'luwipress_chat_greeting', 'Hi! How can I help you today?' ),
			'tone'                => (string) get_option( 'luwipress_chat_tone', 'friendly' ),
			'custom_instructions' => (string) get_option( 'luwipress_chat_custom_instructions', '' ),
			'shipping_policy'     => (string) get_option( 'luwipress_chat_shipping_policy', '' ),
			'returns_policy'      => (string) get_option( 'luwipress_chat_returns_policy', '' ),
			'escalation_channel'  => (string) get_option( 'luwipress_chat_escalation_channel', 'whatsapp' ),
			'daily_budget'        => (float) get_option( 'luwipress_chat_daily_budget', 0.50 ),
			'max_messages'        => absint( get_option( 'luwipress_chat_max_messages', 10 ) ),
			'rate_limit'          => absint( get_option( 'luwipress_chat_rate_limit', 30 ) ),
		);
	}

	/**
	 * POST /chat/settings — partial update.
	 */
	public function handle_set_settings( $request ) {
		$data = $request->get_json_params();
		if ( empty( $data ) ) {
			$data = $request->get_body_params();
		}
		$updated = array();

		$text_keys = array(
			'greeting'            => 'sanitize_textarea_field',
			'custom_instructions' => 'sanitize_textarea_field',
			'shipping_policy'     => 'sanitize_textarea_field',
			'returns_policy'      => 'sanitize_textarea_field',
			'tone'                => 'sanitize_text_field',
			'escalation_channel'  => 'sanitize_text_field',
		);

		foreach ( $text_keys as $key => $sanitizer ) {
			if ( array_key_exists( $key, $data ) ) {
				update_option( 'luwipress_chat_' . $key, call_user_func( $sanitizer, (string) $data[ $key ] ) );
				$updated[] = $key;
			}
		}

		if ( array_key_exists( 'enabled', $data ) ) {
			update_option( 'luwipress_chat_enabled', ! empty( $data['enabled'] ) ? 1 : 0 );
			$updated[] = 'enabled';
		}

		if ( array_key_exists( 'daily_budget', $data ) ) {
			update_option( 'luwipress_chat_daily_budget', max( 0, floatval( $data['daily_budget'] ) ) );
			$updated[] = 'daily_budget';
		}

		if ( array_key_exists( 'max_messages', $data ) ) {
			update_option( 'luwipress_chat_max_messages', max( 1, min( 100, absint( $data['max_messages'] ) ) ) );
			$updated[] = 'max_messages';
		}

		if ( array_key_exists( 'rate_limit', $data ) ) {
			update_option( 'luwipress_chat_rate_limit', max( 1, min( 600, absint( $data['rate_limit'] ) ) ) );
			$updated[] = 'rate_limit';
		}

		LuwiPress_Logger::log( 'Chat settings updated via REST: ' . implode( ', ', $updated ), 'info' );

		return array(
			'success'  => true,
			'updated'  => $updated,
			'settings' => $this->handle_get_settings( $request ),
		);
	}

	/**
	 * Rate limiting and enabled check for chat endpoints.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error
	 */
	public function check_chat_permission( $request ) {
		if ( ! get_option( 'luwipress_chat_enabled', 0 ) ) {
			return new WP_Error( 'chat_disabled', 'Chat is not available', array( 'status' => 403 ) );
		}

		// Only message-send + escalate (write methods) consume the
		// rate-limit budget. The chat widget fires a GET on every
		// page load to bootstrap the session — counting those drains
		// the per-IP allowance within a normal browsing session and
		// shows up as a sitewide 429 on /chat/session/{id}.
		$method = strtoupper( $request->get_method() );
		if ( ! in_array( $method, array( 'POST', 'PUT', 'DELETE' ), true ) ) {
			return true;
		}

		$ip    = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
		$key   = 'luwipress_chatrl_' . md5( $ip );
		$count = (int) get_transient( $key );
		$limit = absint( get_option( 'luwipress_chat_rate_limit', 30 ) );
		if ( $count >= $limit ) {
			return new WP_Error( 'rate_limit', 'Too many messages. Please try again later.', array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Main message handler — core pipeline.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_message( $request ) {
		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
		$message    = sanitize_textarea_field( $request->get_param( 'message' ) );
		$page_url   = esc_url_raw( $request->get_param( 'page_url' ) ?? '' );

		// Validate session_id format (hex, 32-64 chars)
		if ( ! preg_match( '/^[a-f0-9]{32,64}$/', $session_id ) ) {
			return new WP_Error( 'invalid_session', 'Invalid session ID', array( 'status' => 400 ) );
		}

		// Message length cap
		if ( mb_strlen( $message ) > 1000 ) {
			$message = mb_substr( $message, 0, 1000 );
		}

		// Get or create conversation
		$conversation = $this->get_or_create_conversation( $session_id, $page_url );

		// Save customer message
		$this->save_message( $conversation->id, 'customer', $message, 'web' );

		// Check max messages — auto-escalation suggestion
		$msg_count = $this->get_message_count( $conversation->id );
		$max_msgs  = absint( get_option( 'luwipress_chat_max_messages', 10 ) );

		// Process through RAG pipeline
		$response = $this->process_message( $message, $conversation, $msg_count );

		// Save assistant response
		$this->save_message( $conversation->id, 'assistant', $response['content'], $response['source'], $response['metadata'] ?? null );

		// Update conversation timestamp
		$this->touch_conversation( $conversation->id );

		$result = array(
			'response'            => $response['content'],
			'source'              => $response['source'],
			'session_id'          => $session_id,
			'message_count'       => $msg_count + 1,
			'suggest_escalation'  => ( $msg_count + 1 ) >= $max_msgs,
		);

		if ( ! empty( $response['chips'] ) ) {
			$result['chips']     = array_values( $response['chips'] );
			$result['chip_kind'] = $response['chip_kind'] ?? 'follow_up';
		}

		// Include cost/token info for transparency (visible in network tab)
		if ( ! empty( $response['metadata'] ) ) {
			$meta = json_decode( $response['metadata'], true );
			if ( ! empty( $meta['tokens'] ) ) {
				$result['tokens_used'] = $meta['tokens'];
			}
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Restore an existing conversation by session ID.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_get_session( $request ) {
		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
		if ( ! preg_match( '/^[a-f0-9]{32,64}$/', $session_id ) ) {
			return new WP_Error( 'invalid_session', 'Invalid session ID', array( 'status' => 400 ) );
		}

		global $wpdb;
		$conversation = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, status, escalated_to, created_at FROM {$wpdb->prefix}luwipress_chat_conversations WHERE session_id = %s",
			$session_id
		) );

		if ( ! $conversation ) {
			return rest_ensure_response( array( 'exists' => false ) );
		}

		$messages = $wpdb->get_results( $wpdb->prepare(
			"SELECT role, content, source, created_at FROM {$wpdb->prefix}luwipress_chat_messages
			 WHERE conversation_id = %d ORDER BY id ASC LIMIT 50",
			$conversation->id
		) );

		return rest_ensure_response( array(
			'exists'       => true,
			'status'       => $conversation->status,
			'escalated_to' => $conversation->escalated_to,
			'messages'     => $messages,
		) );
	}

	/**
	 * GET /chat/sessions — admin paginated list with filters (FR-003).
	 *
	 * Query: limit (1-200, default 50), offset (default 0), status, escalated_only,
	 * customer_email (partial), since (ISO datetime).
	 *
	 * Returns: { total, items[], limit, offset } where each item carries
	 * session_id, customer_*, status, escalated_to, page_url, ip_address,
	 * created_at, updated_at, message_count.
	 */
	public function handle_list_sessions( $request ) {
		global $wpdb;
		$limit  = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 ) { $limit = 50; }
		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );

		$where  = array( '1=1' );
		$params = array();

		$status = sanitize_text_field( (string) $request->get_param( 'status' ) );
		if ( $status !== '' ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( $request->get_param( 'escalated_only' ) ) {
			$where[] = "escalated_to IS NOT NULL AND escalated_to <> ''";
		}
		$email = sanitize_text_field( (string) $request->get_param( 'customer_email' ) );
		if ( $email !== '' ) {
			$where[]  = 'customer_email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $email ) . '%';
		}
		$since = sanitize_text_field( (string) $request->get_param( 'since' ) );
		if ( $since !== '' ) {
			$ts = strtotime( $since );
			if ( $ts ) {
				$where[]  = 'created_at >= %s';
				$params[] = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		$where_sql = implode( ' AND ', $where );
		$conv_tbl  = $wpdb->prefix . 'luwipress_chat_conversations';
		$msg_tbl   = $wpdb->prefix . 'luwipress_chat_messages';

		$total_sql = "SELECT COUNT(*) FROM {$conv_tbl} WHERE {$where_sql}";
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) )
			: $wpdb->get_var( $total_sql ) );

		$list_sql = "SELECT c.id, c.session_id, c.customer_id, c.customer_email, c.customer_name,
		                    c.status, c.escalated_to, c.page_url, c.ip_address,
		                    c.created_at, c.updated_at,
		                    (SELECT COUNT(*) FROM {$msg_tbl} m WHERE m.conversation_id = c.id) AS message_count
		             FROM {$conv_tbl} c
		             WHERE {$where_sql}
		             ORDER BY c.updated_at DESC
		             LIMIT %d OFFSET %d";
		$args = array_merge( $params, array( $limit, $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $args ) );

		$items = array();
		foreach ( $rows as $r ) {
			$items[] = array(
				'session_id'     => $r->session_id,
				'customer_id'    => (int) $r->customer_id,
				'customer_email' => $r->customer_email,
				'customer_name'  => $r->customer_name,
				'status'         => $r->status,
				'escalated_to'   => $r->escalated_to,
				'page_url'       => $r->page_url,
				'ip_address'     => $r->ip_address,
				'created_at'     => $r->created_at,
				'updated_at'     => $r->updated_at,
				'message_count'  => (int) $r->message_count,
			);
		}

		return rest_ensure_response( array(
			'total'  => $total,
			'items'  => $items,
			'limit'  => $limit,
			'offset' => $offset,
		) );
	}

	/**
	 * GET /chat/messages/search — admin content search via plain LIKE (FR-003).
	 *
	 * Query: q (required, ≥2 chars), limit (1-200, default 50), role
	 * (user/assistant/system), since (ISO datetime).
	 *
	 * Returns: { query, total, items[], limit } where each item carries
	 * session_id, role, content_snippet (≤240 chars, centered on match),
	 * source, created_at, page_url, customer_email.
	 */
	public function handle_search_messages( $request ) {
		global $wpdb;
		$q = trim( (string) $request->get_param( 'q' ) );
		if ( mb_strlen( $q ) < 2 ) {
			return new WP_Error( 'invalid_query', 'q must be at least 2 characters', array( 'status' => 400 ) );
		}
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 ) { $limit = 50; }
		$limit = max( 1, min( 200, $limit ) );
		$role  = sanitize_text_field( (string) $request->get_param( 'role' ) );
		$since = sanitize_text_field( (string) $request->get_param( 'since' ) );

		$conv_tbl = $wpdb->prefix . 'luwipress_chat_conversations';
		$msg_tbl  = $wpdb->prefix . 'luwipress_chat_messages';

		$where  = array( 'm.content LIKE %s' );
		$params = array( '%' . $wpdb->esc_like( $q ) . '%' );
		if ( $role !== '' ) {
			$where[]  = 'm.role = %s';
			$params[] = $role;
		}
		if ( $since !== '' ) {
			$ts = strtotime( $since );
			if ( $ts ) {
				$where[]  = 'm.created_at >= %s';
				$params[] = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}
		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT m.id, m.role, m.content, m.source, m.created_at,
		               c.session_id, c.customer_email, c.page_url
		        FROM {$msg_tbl} m
		        JOIN {$conv_tbl} c ON m.conversation_id = c.id
		        WHERE {$where_sql}
		        ORDER BY m.created_at DESC
		        LIMIT %d";
		$args = array_merge( $params, array( $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		$items = array();
		foreach ( $rows as $r ) {
			$snippet = (string) $r->content;
			if ( mb_strlen( $snippet ) > 240 ) {
				$pos = mb_stripos( $snippet, $q );
				if ( $pos !== false ) {
					$start   = max( 0, $pos - 80 );
					$snippet = ( $start > 0 ? '…' : '' ) . mb_substr( $snippet, $start, 240 ) . '…';
				} else {
					$snippet = mb_substr( $snippet, 0, 240 ) . '…';
				}
			}
			$items[] = array(
				'session_id'      => $r->session_id,
				'role'            => $r->role,
				'content_snippet' => $snippet,
				'source'          => $r->source,
				'created_at'      => $r->created_at,
				'page_url'        => $r->page_url,
				'customer_email'  => $r->customer_email,
			);
		}

		return rest_ensure_response( array(
			'query' => $q,
			'total' => count( $items ),
			'items' => $items,
			'limit' => $limit,
		) );
	}

	/**
	 * Return widget configuration for the frontend.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public function handle_get_config( $request ) {
		return rest_ensure_response( array(
			'enabled'    => (bool) get_option( 'luwipress_chat_enabled', 0 ),
			'greeting'   => get_option( 'luwipress_chat_greeting', 'Hi! How can I help you today?' ),
			'store_name' => get_bloginfo( 'name' ),
			'primary'    => get_option( 'luwipress_chat_color_primary', '#6366f1' ),
			'position'   => get_option( 'luwipress_chat_position', 'bottom-right' ),
		) );
	}

	/**
	 * Mark a conversation as escalated to WhatsApp or Telegram.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_escalate( $request ) {
		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
		$channel    = sanitize_text_field( $request->get_param( 'channel' ) );

		if ( ! in_array( $channel, array( 'whatsapp', 'telegram' ), true ) ) {
			return new WP_Error( 'invalid_channel', 'Channel must be whatsapp or telegram', array( 'status' => 400 ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'luwipress_chat_conversations';
		$wpdb->update(
			$table,
			array( 'status' => 'escalated', 'escalated_to' => $channel, 'updated_at' => current_time( 'mysql' ) ),
			array( 'session_id' => $session_id ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);

		LuwiPress_Logger::log( 'Chat escalated to ' . $channel, 'info', array( 'session_id' => $session_id ) );

		return rest_ensure_response( array( 'escalated' => true, 'channel' => $channel ) );
	}

	/**
	 * Process a message through the RAG pipeline.
	 *
	 * @param string   $message      The customer message.
	 * @param object   $conversation The conversation row.
	 * @param int      $msg_count    Current message count.
	 * @return array   Array with 'content', 'source', and optional 'metadata'.
	 */
	private function process_message( $message, $conversation, $msg_count ) {
		// 1. Intent classification
		$intent = $this->classify_intent( $message );

		// 2. FAQ short-circuit (zero AI cost)
		$faq_answer = $this->try_faq_match( $message );
		if ( $faq_answer ) {
			$faq_context = $this->build_rag_context( $message, $intent, $conversation );
			return array(
				'content'   => $faq_answer['answer'],
				'source'    => 'faq',
				'chips'     => $this->fallback_chips( $faq_context, 'follow_up', $message ),
				'chip_kind' => 'follow_up',
				'metadata'  => wp_json_encode( array(
					'product_id' => $faq_answer['product_id'],
					'intent'     => $intent,
				) ),
			);
		}

		// 3. Build context based on intent
		$context = $this->build_rag_context( $message, $intent, $conversation );

		// 4. Check chat budget
		if ( $this->is_chat_budget_exceeded() ) {
			return array(
				'content'   => get_option( 'luwipress_chat_budget_message', 'Our chat assistant is currently unavailable. Please contact our team directly.' ),
				'source'    => 'local',
				'chips'     => $this->fallback_chips( $context, 'clarify', $message ),
				'chip_kind' => 'clarify',
			);
		}

		// 5. Build messages array for AI (reverse DESC order to chronological)
		$history = $this->get_recent_messages( $conversation->id, 6 );
		$history = array_reverse( $history );
		$system_prompt = $this->build_system_prompt( $context, $intent );

		$messages = array();
		$messages[] = array( 'role' => 'system', 'content' => $system_prompt );
		foreach ( $history as $msg ) {
			$role = $msg->role === 'customer' ? 'user' : 'assistant';
			$messages[] = array( 'role' => $role, 'content' => $msg->content );
		}
		$messages[] = array( 'role' => 'user', 'content' => $message );

		// 6. AI dispatch
		$result = LuwiPress_AI_Engine::dispatch( 'customer-chat', $messages, array(
			'temperature' => 0.3,
			'timeout'     => 15,
			'max_tokens'  => 500,
		) );

		if ( is_wp_error( $result ) ) {
			LuwiPress_Logger::log( 'Customer chat AI error: ' . $result->get_error_message(), 'error' );
			return array(
				'content'   => 'I apologize, but I\'m having trouble right now. Would you like to speak with our team directly?',
				'source'    => 'local',
				'chips'     => $this->fallback_chips( $context, 'clarify', $message ),
				'chip_kind' => 'clarify',
			);
		}

		// Parse JSON envelope { reply, chip_kind, chips }. Tolerant fallback:
		// if the model returned plain prose, treat it as the reply and
		// generate browse-style chips from category context so the
		// customer always has a next step.
		$parsed = LuwiPress_AI_Engine::extract_json( $result['content'] );
		$reply  = '';
		$chips  = array();
		$kind   = 'follow_up';

		if ( is_array( $parsed ) && ! empty( $parsed['reply'] ) ) {
			$reply = (string) $parsed['reply'];
			if ( ! empty( $parsed['chips'] ) && is_array( $parsed['chips'] ) ) {
				foreach ( $parsed['chips'] as $chip ) {
					$chip = trim( (string) $chip );
					if ( $chip !== '' ) {
						$chips[] = mb_substr( $chip, 0, 80 );
					}
					if ( count( $chips ) >= 4 ) {
						break;
					}
				}
			}
			if ( ! empty( $parsed['chip_kind'] ) && in_array( $parsed['chip_kind'], array( 'clarify', 'follow_up' ), true ) ) {
				$kind = $parsed['chip_kind'];
			}
		} else {
			// Model ignored the JSON envelope (older provider, mid-flight
			// reroute) — treat the raw content as the reply, but only if it
			// doesn't look like a JSON object we just failed to parse.
			$raw = trim( $result['content'] );
			$reply = ( strlen( $raw ) > 0 && $raw[0] === '{' && substr( $raw, -1 ) === '}' )
				? 'I\'m here to help — could you tell me a bit more about what you\'re looking for?'
				: $raw;
		}

		if ( empty( $chips ) ) {
			$chips = $this->fallback_chips( $context, $kind, $message );
		}

		return array(
			'content'   => $reply,
			'source'    => 'ai',
			'chips'     => $chips,
			'chip_kind' => $kind,
			'metadata'  => wp_json_encode( array(
				'intent' => $intent,
				'tokens' => $result['input_tokens'] + $result['output_tokens'],
			) ),
		);
	}

	/**
	 * Generate fallback chips from RAG context.
	 *
	 * Used when the AI is unavailable (budget, error, JSON parse failure) or
	 * via REST 429 hook on the frontend. Layered priority:
	 *   1. Page context — "More like {viewing}" / "Other {category} options"
	 *   2. KG signals — top-rated / new-arrivals / segment-premium (one each, max two)
	 *   3. Category browse — top 2 categories
	 *   4. Tail — "Talk to our team" (clarify) or shipping/returns (follow_up)
	 *
	 * @param array  $context Context from build_rag_context().
	 * @param string $kind    'clarify' or 'follow_up'.
	 * @return array Array of chip label strings (max 4).
	 */
	private function fallback_chips( $context, $kind = 'clarify', $message = '' ) {
		$chips = array();
		$sig   = $context['kg_signals'] ?? array();
		$slots = 3; // main chips; tail is appended after, capped at 4 total.
		$pack  = $this->localize_chips( $this->detect_chat_language( $message ) );

		$add = function ( $chip ) use ( &$chips, &$slots ) {
			if ( $slots > 0 && $chip !== '' && ! in_array( $chip, $chips, true ) ) {
				$chips[] = $chip;
				$slots--;
			}
		};

		// 0. Typo recovery (highest priority — if we have phonetic
		// candidates, the customer probably mistyped and we should offer
		// the suggestion before anything else).
		if ( ! empty( $context['typo_candidates'] ) ) {
			foreach ( array_slice( $context['typo_candidates'], 0, 2 ) as $tc ) {
				$add( sprintf( $pack['did_you_mean'], $this->shorten_name( $tc['label'] ) ) );
			}
		}

		// 1. Page-context anchor (closest to user intent).
		if ( ! empty( $sig['viewing'] ) ) {
			$v = $sig['viewing'];
			if ( $v['type'] === 'product' ) {
				$add( sprintf( $pack['more_like'], $this->shorten_name( $v['name'] ) ) );
				if ( ! empty( $v['category'] ) ) {
					$add( sprintf( $pack['other_options'], $v['category'] ) );
				}
			} elseif ( $v['type'] === 'category' ) {
				$add( sprintf( $pack['top_rated_x'], $v['name'] ) );
				$add( sprintf( $pack['whats_new_in'], $v['name'] ) );
			}
		}

		// 2. KG signal-driven chips.
		if ( ! empty( $sig['top_rated'] ) ) {
			$add( $pack['top_rated'] );
		}
		if ( ! empty( $sig['new_arrivals'] ) ) {
			$add( $pack['whats_new'] );
		}
		if ( ! empty( $sig['segment'] ) && in_array( $sig['segment'], array( 'vip', 'active', 'loyal' ), true ) ) {
			$add( $pack['premium_picks'] );
		}

		// 3. Category browse fallback — fills remaining slots.
		if ( ! empty( $context['categories'] ) ) {
			foreach ( $context['categories'] as $cat ) {
				if ( $slots <= 0 ) {
					break;
				}
				$name = trim( preg_replace( '/\s*\(\d+\)\s*$/', '', (string) $cat ) );
				if ( $name !== '' ) {
					$add( sprintf( $pack['show_me'], $name ) );
				}
			}
		}

		// 4. Tail — always appended; total kept at 4.
		if ( $kind === 'follow_up' ) {
			$chips[] = $pack['shipping'];
		} else {
			$chips[] = $pack['talk_to_team'];
		}

		$chips = array_values( array_unique( $chips ) );
		return array_slice( $chips, 0, 4 );
	}

	/**
	 * Truncate a product name to fit the chip width budget.
	 * Chips are ≤ 6 words; long product names break the layout.
	 */
	private function shorten_name( $name ) {
		$words = preg_split( '/\s+/', trim( (string) $name ) );
		if ( count( $words ) <= 3 ) {
			return $name;
		}
		return implode( ' ', array_slice( $words, 0, 3 ) ) . '…';
	}

	/**
	 * Classify the customer message intent using keyword matching.
	 *
	 * @param string $message The customer message.
	 * @return string The detected intent.
	 */
	private function classify_intent( $message ) {
		$msg = mb_strtolower( $message );

		if ( preg_match( '/order|tracking|shipment|where is my|sipariş|kargo|takip/i', $msg ) ) {
			return 'order_status';
		}
		if ( preg_match( '/return|refund|exchange|send back|iade|değişim/i', $msg ) ) {
			return 'returns';
		}
		if ( preg_match( '/shipping|deliver|how long|cost to ship|teslimat|kargo ücreti/i', $msg ) ) {
			return 'shipping';
		}
		if ( preg_match( '/price|cost|how much|discount|coupon|fiyat|indirim|kupon/i', $msg ) ) {
			return 'product_inquiry';
		}
		if ( preg_match( '/stock|available|in stock|out of stock|stok|mevcut/i', $msg ) ) {
			return 'stock_check';
		}
		if ( preg_match( '/talk to|speak with|human|agent|representative|temsilci|yetkili|canlı destek/i', $msg ) ) {
			return 'escalation';
		}

		return 'general';
	}

	/**
	 * Attempt to match the message against cached FAQ entries.
	 * Returns null if no match meets the 0.6 threshold.
	 *
	 * @param string $message The customer message.
	 * @return array|null Matched FAQ with 'answer' and 'product_id', or null.
	 */
	private function try_faq_match( $message ) {
		$index = get_transient( 'luwipress_chat_faq_index' );

		if ( false === $index ) {
			global $wpdb;
			$rows = $wpdb->get_results(
				"SELECT p.ID, p.post_title, pm.meta_value
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_luwipress_faq'
				   AND pm.meta_value != ''
				   AND pm.meta_value != 'a:0:{}'
				   AND p.post_status = 'publish'
				 LIMIT 500"
			);

			$index = array();
			foreach ( $rows as $row ) {
				$faq_data = maybe_unserialize( $row->meta_value );
				if ( ! is_array( $faq_data ) ) {
					$faq_data = json_decode( $row->meta_value, true );
				}
				if ( ! is_array( $faq_data ) ) {
					continue;
				}

				foreach ( $faq_data as $item ) {
					if ( ! empty( $item['question'] ) && ! empty( $item['answer'] ) ) {
						$index[] = array(
							'question'     => $item['question'],
							'answer'       => $item['answer'],
							'product_id'   => absint( $row->ID ),
							'product_name' => $row->post_title,
						);
					}
				}
			}

			set_transient( 'luwipress_chat_faq_index', $index, HOUR_IN_SECONDS );
		}

		if ( empty( $index ) ) {
			return null;
		}

		// Keyword overlap scoring
		$msg_words = array_filter( explode( ' ', mb_strtolower( $message ) ), function( $w ) {
			return mb_strlen( $w ) >= 3;
		} );
		if ( empty( $msg_words ) ) {
			return null;
		}

		$best_match = null;
		$best_score = 0;

		foreach ( $index as $faq ) {
			$q_words = array_filter( explode( ' ', mb_strtolower( $faq['question'] ) ), function( $w ) {
				return mb_strlen( $w ) >= 3;
			} );
			if ( empty( $q_words ) ) {
				continue;
			}

			$overlap = count( array_intersect( $msg_words, $q_words ) );
			$score   = $overlap / max( count( $msg_words ), count( $q_words ) );

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_match = $faq;
			}
		}

		if ( $best_score >= 0.6 && $best_match ) {
			$attribution = ! empty( $best_match['product_name'] ) ? "\n\n— " . $best_match['product_name'] : '';
			return array(
				'answer'     => $best_match['answer'] . $attribution,
				'product_id' => $best_match['product_id'],
			);
		}

		return null;
	}

	/**
	 * Build RAG context based on message intent and conversation state.
	 *
	 * @param string $message      The customer message.
	 * @param string $intent       Classified intent.
	 * @param object $conversation The conversation row.
	 * @return array Contextual data for the system prompt.
	 */
	private function build_rag_context( $message, $intent, $conversation ) {
		$context = array();

		// Load Knowledge Graph data for rich context
		$kg_data = $this->get_kg_data();

		// Product search — BM25 first, fallback to KG keyword match
		if ( in_array( $intent, array( 'product_inquiry', 'stock_check', 'general' ), true ) ) {
			$products = $this->search_products_bm25( $message );
			if ( empty( $products ) ) {
				$products = $this->search_products_from_kg( $message, $kg_data );
			}
			if ( ! empty( $products ) ) {
				$context['products'] = $products;
			} else {
				// Search returned nothing — most common cause is a typo or a
				// cross-language transliteration ("bouziki" → "buzuq"). Try
				// phonetic + edit-distance match against product/category
				// names so the AI can offer "Did you mean X?" instead of
				// listing unrelated categories.
				$typo = $this->find_typo_candidates( $message, $kg_data );
				if ( ! empty( $typo ) ) {
					$context['typo_candidates'] = $typo;
				}
			}
		}

		// Store overview — categories, total products (always include for general context)
		if ( ! empty( $kg_data['summary'] ) ) {
			$context['store_summary'] = array(
				'total_products'   => $kg_data['summary']['total_products'] ?? 0,
				'total_categories' => $kg_data['summary']['total_categories'] ?? 0,
			);
		}

		// Category list for browsing suggestions
		if ( ! empty( $kg_data['nodes']['categories'] ) ) {
			$cats = array();
			foreach ( array_slice( $kg_data['nodes']['categories'], 0, 10 ) as $cat ) {
				$cats[] = $cat['name'] . ' (' . $cat['product_count'] . ')';
			}
			$context['categories'] = $cats;
		}

		// Store policies
		if ( in_array( $intent, array( 'shipping', 'returns', 'general' ), true ) ) {
			$shipping = get_option( 'luwipress_chat_shipping_policy', '' );
			$returns  = get_option( 'luwipress_chat_returns_policy', '' );
			if ( ! empty( $shipping ) ) {
				$context['shipping_policy'] = $shipping;
			}
			if ( ! empty( $returns ) ) {
				$context['returns_policy'] = $returns;
			}
		}

		// Order status — logged in customers only
		if ( $intent === 'order_status' && $conversation->customer_id ) {
			$context['orders'] = $this->get_customer_orders( $conversation->customer_id );
		}

		// KG-derived signals: new arrivals, top-rated, best-ready,
		// out-of-stock count, customer segment, current page context.
		// All derivations operate on $kg_data already in memory — no
		// extra queries — so this stays cheap on every chat turn.
		$context['kg_signals'] = $this->derive_kg_signals( $kg_data, $conversation );

		return $context;
	}

	/**
	 * Derive opportunity-aware signals from the KG snapshot.
	 *
	 * Returns a compact array the system prompt + fallback chip generator
	 * can both reason over. Empty/zero values are normal — callers should
	 * treat missing keys as "signal not actionable on this store right now".
	 *
	 * @param array  $kg_data      KG snapshot from get_kg_data().
	 * @param object $conversation Conversation row (carries customer_id + page_url).
	 * @return array Signals: new_arrivals, top_rated, best_ready, out_of_stock_count, segment, viewing.
	 */
	private function derive_kg_signals( $kg_data, $conversation ) {
		$signals = array(
			'new_arrivals'       => array(),
			'top_rated'          => array(),
			'best_ready'         => array(),
			'out_of_stock_count' => 0,
			'segment'            => null,
			'viewing'            => null,
		);

		$products = $kg_data['nodes']['products'] ?? array();

		if ( ! empty( $products ) ) {
			// New arrivals: modified in last 30 days, sort ascending by recency.
			$fresh = array_filter( $products, function ( $p ) {
				return isset( $p['days_since_modified'] ) && $p['days_since_modified'] <= 30;
			} );
			usort( $fresh, function ( $a, $b ) {
				return ( $a['days_since_modified'] ?? 99 ) <=> ( $b['days_since_modified'] ?? 99 );
			} );
			foreach ( array_slice( $fresh, 0, 3 ) as $p ) {
				$signals['new_arrivals'][] = array(
					'name' => $p['name'],
					'url'  => get_permalink( $p['id'] ),
					'days' => $p['days_since_modified'],
				);
			}

			// Top rated: avg_rating >= 4 with >= 3 reviews (significance gate).
			$rated = array_filter( $products, function ( $p ) {
				$rc = $p['reviews']['count'] ?? 0;
				$ar = $p['reviews']['avg_rating'] ?? 0;
				return $rc >= 3 && $ar >= 4.0;
			} );
			usort( $rated, function ( $a, $b ) {
				return ( $b['reviews']['avg_rating'] ?? 0 ) <=> ( $a['reviews']['avg_rating'] ?? 0 );
			} );
			foreach ( array_slice( $rated, 0, 3 ) as $p ) {
				$signals['top_rated'][] = array(
					'name'   => $p['name'],
					'url'    => get_permalink( $p['id'] ),
					'rating' => $p['reviews']['avg_rating'],
					'count'  => $p['reviews']['count'],
				);
			}

			// Best-ready: lowest opportunity_score (= fully enriched, in stock,
			// translated, reviews present). These are the safest products to
			// recommend — they're not missing context the AI would hallucinate.
			$ready = array_filter( $products, function ( $p ) {
				return ( $p['stock_status'] ?? 'instock' ) === 'instock';
			} );
			usort( $ready, function ( $a, $b ) {
				return ( $a['opportunity_score'] ?? 99 ) <=> ( $b['opportunity_score'] ?? 99 );
			} );
			foreach ( array_slice( $ready, 0, 3 ) as $p ) {
				$signals['best_ready'][] = array(
					'name'  => $p['name'],
					'url'   => get_permalink( $p['id'] ),
					'score' => $p['opportunity_score'] ?? 0,
				);
			}

			// Out of stock count for inventory-aware copy.
			foreach ( $products as $p ) {
				if ( ( $p['stock_status'] ?? 'instock' ) === 'outofstock' ) {
					$signals['out_of_stock_count']++;
				}
			}
		}

		// Customer segment (CRM bridge writes this to user_meta).
		if ( ! empty( $conversation->customer_id ) ) {
			$segment = get_user_meta( absint( $conversation->customer_id ), '_luwipress_crm_segment', true );
			if ( $segment ) {
				$signals['segment'] = sanitize_key( $segment );
			}
		}

		// Page-aware: parse page_url to detect product/category context.
		$page_url = $conversation->page_url ?? '';
		if ( $page_url ) {
			$path = wp_parse_url( $page_url, PHP_URL_PATH );
			if ( $path ) {
				$slug = trim( basename( rtrim( $path, '/' ) ) );
				if ( $slug !== '' && $slug !== '/' ) {
					// Try product match first (more specific).
					foreach ( $products as $p ) {
						if ( ! empty( $p['slug'] ) && $p['slug'] === $slug ) {
							$cat_name = null;
							if ( ! empty( $p['categories'] ) && ! empty( $kg_data['nodes']['categories'] ) ) {
								foreach ( $kg_data['nodes']['categories'] as $c ) {
									if ( $c['id'] === $p['categories'][0] ) {
										$cat_name = $c['name'];
										break;
									}
								}
							}
							$signals['viewing'] = array(
								'type'     => 'product',
								'name'     => $p['name'],
								'url'      => get_permalink( $p['id'] ),
								'category' => $cat_name,
							);
							break;
						}
					}
					// Fall back to category match.
					if ( ! $signals['viewing'] && ! empty( $kg_data['nodes']['categories'] ) ) {
						foreach ( $kg_data['nodes']['categories'] as $c ) {
							if ( ! empty( $c['slug'] ) && $c['slug'] === $slug ) {
								$signals['viewing'] = array(
									'type' => 'category',
									'name' => $c['name'],
									'url'  => get_term_link( (int) $c['id'], 'product_cat' ),
								);
								break;
							}
						}
					}
				}
			}
		}

		return $signals;
	}

	/**
	 * Find typo / phonetic-near matches against KG product + category names.
	 *
	 * Two-stage match so the common case stays cheap:
	 *   1. Metaphone phonetic key lookup — catches cross-language
	 *      transliteration drift ("bouziki" / "bouzouki" / "buzuq" all
	 *      collapse to similar consonant skeletons).
	 *   2. Levenshtein distance on metaphone-matched candidates — rejects
	 *      false positives where the phonetic key is too short to be
	 *      meaningful (e.g. "saz" vs "say"), and ranks the survivors.
	 *
	 * Tokens are normalized with `remove_accents()` first because PHP's
	 * native levenshtein()/metaphone() are byte-oriented and miscount
	 * multibyte characters (Turkish ş/ü/ğ etc.).
	 *
	 * @param string $message Raw customer message.
	 * @param array  $kg_data KG snapshot.
	 * @param int    $limit   Max candidates to return.
	 * @return array Each entry: {label, type, original, matched, distance}.
	 */
	private function find_typo_candidates( $message, $kg_data, $limit = 3 ) {
		$tokens = $this->extract_normalized_tokens( $message );
		if ( empty( $tokens ) ) {
			return array();
		}

		// Build corpus once: phonetic-key index of all category names +
		// product slug tokens. We use slug tokens (not full names) so
		// multi-word products like "Professional Turkish Lavta" surface as
		// "professional"/"turkish"/"lavta" — one of which usually matches
		// the customer's intent word.
		$index = $this->build_typo_corpus( $kg_data );
		if ( empty( $index ) ) {
			return array();
		}

		$matches = array();
		foreach ( $tokens as $tok ) {
			$tok_len = strlen( $tok );
			if ( $tok_len < 4 ) {
				continue; // 3-letter tokens (oud/saz) are too short to typo-detect safely
			}

			$tok_meta = metaphone( $tok, 4 );
			if ( strlen( $tok_meta ) < 2 ) {
				continue;
			}

			// Distance budget: ~33% of token length, min 2, max 4.
			$threshold = max( 2, min( 4, (int) floor( $tok_len / 3 ) ) );

			// Pull all corpus tokens whose phonetic key shares a prefix with
			// the customer token's metaphone. Sharing the first 2 metaphone
			// chars is usually enough to bridge English↔Arabic spellings.
			$tok_meta_short = substr( $tok_meta, 0, 2 );
			foreach ( $index as $cand_tok => $entries ) {
				$cand_meta = $entries[0]['meta'];
				if ( substr( $cand_meta, 0, 2 ) !== $tok_meta_short ) {
					continue;
				}

				$cand_len = strlen( $cand_tok );
				// Length sanity — drop pairs whose lengths differ wildly.
				if ( abs( $cand_len - $tok_len ) > $threshold + 3 ) {
					continue;
				}

				// Composite acceptance:
				//   (a) classic Levenshtein within threshold, OR
				//   (b) phonetic-Levenshtein ≤ 1 AND raw distance within
				//       threshold+2. Path (b) catches near-rhymes where the
				//       string distance is large but the consonant skeleton
				//       is essentially the same (e.g. "kemence" ↔ "kamancheh",
				//       lev(KMNS,KMNX)=1 but lev(kemence,kamancheh)=4).
				$lev_str  = levenshtein( $tok, $cand_tok );
				$lev_meta = levenshtein( $tok_meta, $cand_meta );

				$accept = ( $lev_str <= $threshold )
					|| ( $lev_meta <= 1 && $lev_str <= $threshold + 2 );
				if ( ! $accept ) {
					continue;
				}

				// Ranking distance: phonetic-equal pairs get a discount so
				// "buziki" → "Buzuq" (BSK=BSK) outranks plain edit-near
				// matches. Cap at the raw string distance so we never
				// inflate the score above what we just measured.
				$dist = $lev_str;
				if ( $lev_meta === 0 ) {
					$dist = max( 0, $dist - 1 );
				}

				foreach ( $entries as $entry ) {
					$key = $entry['type'] . ':' . $entry['label'];
					if ( ! isset( $matches[ $key ] ) || $matches[ $key ]['distance'] > $dist ) {
						$matches[ $key ] = array(
							'label'    => $entry['label'],
							'type'     => $entry['type'],
							'url'      => $entry['url'] ?? '',
							'original' => $tok,
							'matched'  => $cand_tok,
							'distance' => $dist,
						);
					}
				}
			}
		}

		if ( empty( $matches ) ) {
			return array();
		}

		// Sort by distance ascending, prefer category > product on ties
		// (categories give a broader landing page; products are too specific
		// for a "did you mean" suggestion unless the match is very tight).
		usort( $matches, function ( $a, $b ) {
			if ( $a['distance'] !== $b['distance'] ) {
				return $a['distance'] - $b['distance'];
			}
			if ( $a['type'] === $b['type'] ) {
				return 0;
			}
			return $a['type'] === 'category' ? -1 : 1;
		} );

		return array_slice( array_values( $matches ), 0, $limit );
	}

	/**
	 * Build the per-request phonetic + token index used for typo lookup.
	 * Cached on the instance because both products and categories iterate
	 * the same KG snapshot.
	 */
	private function build_typo_corpus( $kg_data ) {
		static $cache = null;
		if ( $cache !== null ) {
			return $cache;
		}
		$index = array();

		$push = function ( $raw_token, $label, $type, $url ) use ( &$index ) {
			$tok = $this->normalize_text( $raw_token );
			if ( strlen( $tok ) < 4 ) {
				return;
			}
			$meta = metaphone( $tok, 4 );
			if ( strlen( $meta ) < 2 ) {
				return;
			}
			if ( ! isset( $index[ $tok ] ) ) {
				$index[ $tok ] = array();
			}
			$index[ $tok ][] = array(
				'label' => $label,
				'type'  => $type,
				'meta'  => $meta,
				'url'   => $url,
			);
		};

		foreach ( $kg_data['nodes']['categories'] ?? array() as $c ) {
			$url = get_term_link( (int) $c['id'], 'product_cat' );
			$url = is_wp_error( $url ) ? '' : $url;
			// Each word of the category name + its slug.
			foreach ( preg_split( '/[\s\-,()]+/u', (string) $c['name'] ) as $word ) {
				$push( $word, $c['name'], 'category', $url );
			}
			if ( ! empty( $c['slug'] ) ) {
				foreach ( explode( '-', $c['slug'] ) as $word ) {
					$push( $word, $c['name'], 'category', $url );
				}
			}
		}

		foreach ( $kg_data['nodes']['products'] ?? array() as $p ) {
			$url = get_permalink( $p['id'] );
			foreach ( preg_split( '/[\s\-,()]+/u', (string) $p['name'] ) as $word ) {
				$push( $word, $p['name'], 'product', $url );
			}
			if ( ! empty( $p['slug'] ) ) {
				foreach ( explode( '-', $p['slug'] ) as $word ) {
					$push( $word, $p['name'], 'product', $url );
				}
			}
		}

		$cache = $index;
		return $cache;
	}

	/**
	 * Split a message into normalized ASCII tokens suitable for levenshtein/metaphone.
	 */
	private function extract_normalized_tokens( $message ) {
		$normalized = $this->normalize_text( $message );
		$tokens     = preg_split( '/[\s,.\/()\-?!:;"\']+/u', $normalized );
		$out        = array();
		$seen       = array();
		foreach ( $tokens as $t ) {
			if ( $t === '' || strlen( $t ) < 4 ) {
				continue;
			}
			if ( isset( $seen[ $t ] ) ) {
				continue;
			}
			$seen[ $t ] = true;
			$out[]       = $t;
		}
		return $out;
	}

	/**
	 * Lowercase + ASCII-fold a string so levenshtein/metaphone don't
	 * over-count multibyte characters as edit distance.
	 */
	private function normalize_text( $text ) {
		$text = mb_strtolower( (string) $text, 'UTF-8' );
		if ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		}
		// Drop anything that isn't ASCII letter/digit/space — keeps the
		// byte-oriented native string functions safe.
		$text = preg_replace( '/[^a-z0-9\s\-]/i', ' ', $text );
		return trim( preg_replace( '/\s+/', ' ', (string) $text ) );
	}

	/**
	 * Get Knowledge Graph data (cached).
	 *
	 * @return array KG data with products, categories, summary.
	 */
	private function get_kg_data() {
		$cache_key = 'luwipress_chat_kg_data';
		$data = get_transient( $cache_key );

		// Treat stored-but-empty as miss so we don't serve a broken snapshot
		// forever (previous bug: empty array passed `false !== $data` and the
		// AI got zero context until the transient expired an hour later).
		$has_products = ! empty( $data['nodes']['products'] );
		$has_cats     = ! empty( $data['nodes']['categories'] );
		if ( false !== $data && ( $has_products || $has_cats ) ) {
			return $data;
		}

		// Fetch KG data directly via the class (no HTTP call)
		if ( ! class_exists( 'LuwiPress_Knowledge_Graph' ) ) {
			return $this->synthesize_fallback_snapshot();
		}

		$kg      = LuwiPress_Knowledge_Graph::get_instance();
		$request = new WP_REST_Request( 'GET', '/luwipress/v1/knowledge-graph' );
		$request->set_param( 'section', 'products,categories' );

		$response = $kg->handle_knowledge_graph( $request );
		$data     = ( $response instanceof WP_REST_Response ) ? $response->get_data() : $response;

		// Cold-start fallback: if KG returns nothing usable (e.g. handler errored,
		// cache transient collision, permission misfire), synthesise a minimal
		// snapshot directly from WP so the chat never degrades to "no info".
		if ( empty( $data['nodes']['categories'] ) && empty( $data['nodes']['products'] ) ) {
			$data = $this->synthesize_fallback_snapshot();
		}

		// Cache for 1 hour
		set_transient( $cache_key, $data, HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * Build a minimal KG-shaped snapshot from WP/WC when the Knowledge Graph
	 * handler returns nothing. Ensures the chat always has categories + a few
	 * flagship products in context.
	 *
	 * @return array KG-shaped snapshot with summary, nodes.products, nodes.categories.
	 */
	private function synthesize_fallback_snapshot() {
		$data = array( 'summary' => array(), 'nodes' => array( 'products' => array(), 'categories' => array() ) );

		if ( ! function_exists( 'wc_get_products' ) ) {
			return $data;
		}

		// Top categories by product count
		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'number'     => 20,
			'orderby'    => 'count',
			'order'      => 'DESC',
		) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$data['nodes']['categories'][] = array(
					'id'            => $term->term_id,
					'name'          => $term->name,
					'slug'          => $term->slug,
					'product_count' => (int) $term->count,
				);
			}
		}

		// Top N products (most recent publish)
		$products = wc_get_products( array(
			'status'  => 'publish',
			'limit'   => 20,
			'orderby' => 'date',
			'order'   => 'DESC',
		) );
		if ( is_array( $products ) ) {
			foreach ( $products as $p ) {
				$pid = $p->get_id();
				$cat_ids = array();
				$cat_terms = get_the_terms( $pid, 'product_cat' );
				if ( $cat_terms && ! is_wp_error( $cat_terms ) ) {
					foreach ( $cat_terms as $t ) { $cat_ids[] = $t->term_id; }
				}
				$data['nodes']['products'][] = array(
					'id'           => $pid,
					'name'         => $p->get_name(),
					'slug'         => $p->get_slug(),
					'sku'          => $p->get_sku(),
					'price'        => $p->get_price(),
					'stock_status' => $p->get_stock_status(),
					'categories'   => $cat_ids,
				);
			}
		}

		$data['summary'] = array(
			'total_products'   => (int) wp_count_posts( 'product' )->publish,
			'total_categories' => count( $data['nodes']['categories'] ),
		);

		return $data;
	}

	/**
	 * Search products from Knowledge Graph data with rich context.
	 *
	 * Searches title, SKU, and categories. Returns enriched product data
	 * including description excerpt, price, stock, categories, and review info.
	 *
	 * @param string $query   The search query.
	 * @param array  $kg_data Knowledge Graph data.
	 * @param int    $limit   Maximum results.
	 * @return array Matched products with full context.
	 */
	private function search_products_from_kg( $query, $kg_data, $limit = 5 ) {
		// Build category name map
		$cat_map = array();
		if ( ! empty( $kg_data['nodes']['categories'] ) ) {
			foreach ( $kg_data['nodes']['categories'] as $cat ) {
				$cat_map[ $cat['id'] ] = $cat['name'];
			}
		}

		$keywords = array_filter( explode( ' ', mb_strtolower( $query ) ), function( $w ) {
			return mb_strlen( $w ) >= 2;
		} );

		// Try KG keyword match first
		$results = array();
		if ( ! empty( $kg_data['nodes']['products'] ) && ! empty( $keywords ) ) {
			foreach ( $kg_data['nodes']['products'] as $p ) {
				$score = 0;

				// Search in title (highest weight)
				$title = mb_strtolower( $p['name'] ?? '' );
				foreach ( $keywords as $kw ) {
					if ( mb_strpos( $title, $kw ) !== false ) {
						$score += 3;
					}
				}

				// Search in slug — products often have friendly slugs that differ from title
				// (e.g. product "Çağlama - Double Pick Up" has slug "caglama-double-pick-up").
				// Customer typing "caglama" should match.
				$slug = mb_strtolower( $p['slug'] ?? '' );
				if ( ! empty( $slug ) ) {
					foreach ( $keywords as $kw ) {
						if ( mb_strpos( $slug, $kw ) !== false ) {
							$score += 2;
						}
					}
				}

				// Search in SKU
				$sku = mb_strtolower( $p['sku'] ?? '' );
				if ( ! empty( $sku ) ) {
					foreach ( $keywords as $kw ) {
						if ( mb_strpos( $sku, $kw ) !== false ) {
							$score += 2;
						}
					}
				}

				// Search in category names
				$cat_names = '';
				foreach ( $p['categories'] ?? array() as $cat_id ) {
					$cat_names .= ' ' . mb_strtolower( $cat_map[ $cat_id ] ?? '' );
				}
				foreach ( $keywords as $kw ) {
					if ( mb_strpos( $cat_names, $kw ) !== false ) {
						$score += 1;
					}
				}

				if ( $score > 0 ) {
					$results[] = array( 'product' => $p, 'score' => $score );
				}
			}
		}

		// If KG match found, return enriched results
		if ( ! empty( $results ) ) {
			usort( $results, function( $a, $b ) {
				return $b['score'] - $a['score'];
			} );
			$top = array_slice( $results, 0, $limit );
			$matched = array_map( function( $r ) { return $r['product']; }, $top );
			return $this->enrich_kg_products( $matched, $cat_map );
		}

		// Fallback: WP_Query search (works with WPML translated titles + post content)
		$wp_results = $this->search_products_wp( $query, $limit );
		if ( ! empty( $wp_results ) ) {
			return $wp_results;
		}

		// Last resort: return top products from KG
		if ( ! empty( $kg_data['nodes']['products'] ) ) {
			$products = $kg_data['nodes']['products'];
			usort( $products, function( $a, $b ) {
				return ( $a['opportunity_score'] ?? 99 ) <=> ( $b['opportunity_score'] ?? 99 );
			} );
			return $this->enrich_kg_products( array_slice( $products, 0, $limit ), $cat_map );
		}

		return array();
	}

	/**
	 * BM25 ranked product search.
	 */
	private function search_products_bm25( $query, $limit = 5 ) {
		if ( ! class_exists( 'LuwiPress_Search_Index' ) ) {
			return array();
		}

		$index = LuwiPress_Search_Index::get_instance();
		if ( ! $index->is_indexed() ) {
			return array();
		}

		return $index->search( $query, $limit );
	}

	/**
	 * Fallback product search using WP_Query (searches all languages, content, title).
	 */
	private function search_products_wp( $query, $limit = 5 ) {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			's'              => sanitize_text_field( $query ),
			'posts_per_page' => $limit,
		);

		// Let WPML/Polylang search across all languages
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			do_action( 'wpml_switch_language', 'all' );
		}

		$wp_query = new WP_Query( $args );

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			do_action( 'wpml_switch_language', null );
		}

		if ( ! $wp_query->have_posts() ) {
			return array();
		}

		$output = array();
		foreach ( $wp_query->posts as $post ) {
			$product_id = $post->ID;
			$price      = get_post_meta( $product_id, '_price', true );
			$stock      = get_post_meta( $product_id, '_stock_status', true );
			$currency   = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';

			// Get categories
			$terms = get_the_terms( $product_id, 'product_cat' );
			$cat_names = array();
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$cat_names[] = $term->name;
				}
			}

			$output[] = array(
				'id'          => $product_id,
				'name'        => $post->post_title,
				'price'       => ( $price ?: 'N/A' ) . $currency,
				'stock'       => $stock ?: 'instock',
				'sku'         => get_post_meta( $product_id, '_sku', true ) ?: '',
				'categories'  => $cat_names,
				'description' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 ),
				'reviews'     => array( 'count' => (int) $post->comment_count, 'avg_rating' => 0 ),
				'url'         => get_permalink( $product_id ),
			);
		}

		return $output;
	}

	/**
	 * Enrich KG product nodes with additional data for AI context.
	 *
	 * @param array $products KG product nodes.
	 * @param array $cat_map  Category ID → name map.
	 * @return array Enriched products.
	 */
	private function enrich_kg_products( $products, $cat_map ) {
		$output = array();
		foreach ( $products as $p ) {
			$product_id = $p['id'];

			// Get description excerpt
			$post = get_post( $product_id );
			$description = '';
			if ( $post ) {
				$description = wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 );
			}

			// Category names
			$cat_names = array();
			foreach ( $p['categories'] ?? array() as $cat_id ) {
				if ( isset( $cat_map[ $cat_id ] ) ) {
					$cat_names[] = $cat_map[ $cat_id ];
				}
			}

			$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';

			$output[] = array(
				'id'          => $product_id,
				'name'        => $p['name'],
				'price'       => ( $p['price'] ?? 'N/A' ) . $currency,
				'stock'       => $p['stock_status'] ?? 'instock',
				'sku'         => $p['sku'] ?? '',
				'categories'  => $cat_names,
				'description' => $description,
				'reviews'     => $p['reviews'] ?? array( 'count' => 0, 'avg_rating' => 0 ),
				'url'         => get_permalink( $product_id ),
			);
		}
		return $output;
	}

	/**
	 * Get recent orders for a logged-in customer.
	 *
	 * @param int $customer_id WP user ID.
	 * @param int $limit       Maximum orders to return.
	 * @return array Order summaries.
	 */
	private function get_customer_orders( $customer_id, $limit = 3 ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders( array(
			'customer_id' => absint( $customer_id ),
			'limit'       => $limit,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'return'      => 'objects',
		) );

		$list = array();
		foreach ( $orders as $order ) {
			$list[] = array(
				'number' => $order->get_order_number(),
				'status' => wc_get_order_status_name( $order->get_status() ),
				'total'  => $order->get_total() . ' ' . $order->get_currency(),
				'date'   => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d' ) : '',
			);
		}
		return $list;
	}

	/**
	 * Build the system prompt with RAG context injected.
	 *
	 * @param array  $context Contextual data from build_rag_context().
	 * @param string $intent  Classified intent.
	 * @return string The system prompt for the AI.
	 */
	private function build_system_prompt( $context, $intent ) {
		$store_name = get_bloginfo( 'name' );
		$store_url  = get_site_url();
		$currency   = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$tone       = get_option( 'luwipress_chat_tone', 'friendly' );
		$custom_instructions = get_option( 'luwipress_chat_custom_instructions', '' );

		$prompt = "You are the customer support assistant for {$store_name}.\n";
		$prompt .= "Store: {$store_url}\n";
		if ( $currency ) {
			$prompt .= "Currency: {$currency}\n";
		}

		// Tone / personality
		$tone_instructions = $this->get_tone_instructions( $tone );
		$prompt .= "\nPERSONALITY: {$tone_instructions}\n";

		// Store overview
		if ( ! empty( $context['store_summary'] ) ) {
			$prompt .= "Store has {$context['store_summary']['total_products']} products in {$context['store_summary']['total_categories']} categories.\n";
		}

		// Categories
		if ( ! empty( $context['categories'] ) ) {
			$prompt .= "\nPRODUCT CATEGORIES: " . implode( ', ', $context['categories'] ) . "\n";
		}

		$prompt .= "\n";

		// Add product context with rich details
		if ( ! empty( $context['products'] ) ) {
			$prompt .= "RELEVANT PRODUCTS:\n";
			foreach ( $context['products'] as $p ) {
				$stock_text = ( $p['stock'] ?? 'instock' ) === 'instock' ? 'In Stock' : 'Out of Stock';
				$prompt .= "- {$p['name']} — {$p['price']} — {$stock_text}\n";
				if ( ! empty( $p['categories'] ) ) {
					$prompt .= "  Categories: " . implode( ', ', $p['categories'] ) . "\n";
				}
				if ( ! empty( $p['description'] ) ) {
					$prompt .= "  Description: {$p['description']}\n";
				}
				if ( ! empty( $p['reviews']['count'] ) && $p['reviews']['count'] > 0 ) {
					$prompt .= "  Reviews: {$p['reviews']['count']} reviews, {$p['reviews']['avg_rating']}/5 rating\n";
				}
				$prompt .= "  Link: {$p['url']}\n";
			}
			$prompt .= "\n";
		}

		// Add policies
		if ( ! empty( $context['shipping_policy'] ) ) {
			$prompt .= "SHIPPING POLICY:\n{$context['shipping_policy']}\n\n";
		}
		if ( ! empty( $context['returns_policy'] ) ) {
			$prompt .= "RETURNS POLICY:\n{$context['returns_policy']}\n\n";
		}

		// Add order info
		if ( ! empty( $context['orders'] ) ) {
			$prompt .= "CUSTOMER'S RECENT ORDERS:\n";
			foreach ( $context['orders'] as $o ) {
				$prompt .= "- Order #{$o['number']} — {$o['status']} — {$o['total']} — {$o['date']}\n";
			}
			$prompt .= "\n";
		}

		// KG SIGNALS — opportunity-aware hints the model uses to ground
		// chips beyond raw category browse. Only emit non-empty signals so
		// the prompt stays compact when the store has no actionable data
		// (e.g. new store with no reviews yet).
		$sig = $context['kg_signals'] ?? array();
		$signal_lines = array();
		if ( ! empty( $sig['viewing'] ) ) {
			$v = $sig['viewing'];
			$signal_lines[] = sprintf( '- Customer is viewing: %s "%s"%s', $v['type'], $v['name'], ! empty( $v['category'] ) ? ' (in ' . $v['category'] . ')' : '' );
		}
		if ( ! empty( $sig['segment'] ) ) {
			$signal_lines[] = '- Customer segment: ' . $sig['segment'];
		}
		if ( ! empty( $sig['new_arrivals'] ) ) {
			$names = array_map( function ( $p ) { return $p['name']; }, $sig['new_arrivals'] );
			$signal_lines[] = '- New arrivals (last 30 days): ' . implode( ', ', $names );
		}
		if ( ! empty( $sig['top_rated'] ) ) {
			$names = array_map( function ( $p ) { return $p['name'] . ' (' . $p['rating'] . '★)'; }, $sig['top_rated'] );
			$signal_lines[] = '- Top rated: ' . implode( ', ', $names );
		}
		if ( ! empty( $sig['best_ready'] ) ) {
			$names = array_map( function ( $p ) { return $p['name']; }, $sig['best_ready'] );
			$signal_lines[] = '- Best-ready picks: ' . implode( ', ', $names );
		}
		if ( ! empty( $sig['out_of_stock_count'] ) ) {
			$signal_lines[] = '- Out-of-stock products: ' . $sig['out_of_stock_count'];
		}
		if ( ! empty( $signal_lines ) ) {
			$prompt .= "KG SIGNALS (use these to make chips smarter — pick what fits the moment):\n";
			$prompt .= implode( "\n", $signal_lines ) . "\n\n";
		}

		// POSSIBLE TYPO MATCHES — fired only when the product search came up
		// empty. Tell the model these are candidates the customer MIGHT have
		// meant; the model should confirm gently rather than assume.
		if ( ! empty( $context['typo_candidates'] ) ) {
			$prompt .= "POSSIBLE TYPO MATCHES (customer's text didn't match any product/category exactly; these are phonetic near-matches):\n";
			foreach ( $context['typo_candidates'] as $tc ) {
				$prompt .= sprintf( "- \"%s\" (%s) — close to customer's word \"%s\"\n", $tc['label'], $tc['type'], $tc['original'] );
			}
			$prompt .= "If one looks plausible, ASK in the reply (e.g. \"Did you mean Buzuq?\") instead of listing unrelated categories. The first chip MUST be a customer-voice confirmation like \"Yes, show me {Label}\".\n\n";
		}

		$prompt .= "RULES:\n";
		$prompt .= "- Use ONLY the product information provided above — never invent products or prices\n";
		$prompt .= "- When mentioning products, always include their link\n";
		$prompt .= "- NEVER reply with \"I don't have information about our products.\" If no specific product matched, say so briefly and then ALWAYS list 3–5 relevant categories from PRODUCT CATEGORIES above with a short pitch — the goal is to keep the conversation productive and route the customer to something real.\n";
		$prompt .= "- If asked about categories or browsing, suggest relevant categories from the list with a one-liner for each\n";
		$prompt .= "- If you truly cannot help (order issue, complaint, refund), suggest the customer speak with the team via WhatsApp (link in the widget)\n";
		$prompt .= "- Keep the reply under 150 words\n";
		$prompt .= "- Respond in the same language the customer uses\n";

		if ( ! empty( $custom_instructions ) ) {
			$prompt .= "\nADDITIONAL INSTRUCTIONS:\n{$custom_instructions}\n";
		}

		$prompt .= "\nOUTPUT FORMAT — return a single JSON object, NO markdown fences, NO commentary:\n";
		$prompt .= '{ "reply": "<answer text>", "chip_kind": "clarify" | "follow_up", "chips": ["<choice 1>", "<choice 2>", "<choice 3>"] }' . "\n";
		$prompt .= "Chip rules:\n";
		$prompt .= "- Exactly 3 chips. Each chip ≤ 6 words. Written from the CUSTOMER'S voice (as if the customer were tapping a quick reply).\n";
		$prompt .= "- Ground chips in KG SIGNALS when relevant. Examples:\n";
		$prompt .= "  • If \"Customer is viewing: product X\" → one chip should reference similar items or that category (\"More like {X}\", \"Other {category} options\").\n";
		$prompt .= "  • If \"New arrivals\" listed → one chip can be \"What's new?\" or name a fresh arrival.\n";
		$prompt .= "  • If \"Top rated\" listed → one chip can be \"Top-rated picks\" or name a 5★ product.\n";
		$prompt .= "  • If segment=vip/active → at most one premium chip (\"Show premium picks\"); never expose the segment label literally.\n";
		$prompt .= "- chip_kind=\"clarify\": Use when the customer's question is broad/ambiguous (e.g. \"oud options?\", \"what do you have?\"). Chips are SPECIFIC refined requests grounded in real categories, signals, or attributes. Example for \"oud options?\" on a store with KG signals: [\"Show me Turkish ouds\", \"Top-rated ouds\", \"What's new in ouds?\"].\n";
		$prompt .= "- chip_kind=\"follow_up\": Use after a confident specific answer. Chips are natural next questions a customer might tap. Example: [\"How long is shipping?\", \"Returns policy?\", \"Show me similar items\"].\n";
		$prompt .= "- Chips must be in the SAME language as the reply.\n";
		$prompt .= "- Do NOT invent products. Only use product/category names that appear in PRODUCT CATEGORIES, RELEVANT PRODUCTS, or KG SIGNALS above.\n";
		$prompt .= "- All JSON keys and string values must use double quotes. Escape any double quotes inside strings.\n";

		return $prompt;
	}

	/**
	 * Get tone instructions for the system prompt.
	 *
	 * @param string $tone Tone key from settings.
	 * @return string Tone instruction text.
	 */
	private function get_tone_instructions( $tone ) {
		$tones = array(
			'friendly'     => 'You are warm, approachable, and enthusiastic. Use a conversational tone with occasional emojis. Make customers feel welcome and valued.',
			'professional' => 'You are polite, formal, and precise. Use complete sentences and proper grammar. Maintain a respectful business tone throughout.',
			'casual'       => 'You are laid-back, fun, and relatable. Keep it light and use casual language. Feel free to use humor when appropriate.',
			'expert'       => 'You are a knowledgeable specialist. Share insights about instrument craftsmanship, materials, and playing techniques. Be authoritative but approachable.',
			'luxury'       => 'You are refined and elegant. Emphasize quality, craftsmanship, and exclusivity. Use sophisticated language befitting a premium shopping experience.',
			'custom'       => get_option( 'luwipress_chat_custom_instructions', '' ),
		);

		return $tones[ $tone ] ?? $tones['friendly'];
	}

	/**
	 * Check if the daily AI budget for customer chat has been exceeded.
	 *
	 * @return bool True if budget is exceeded.
	 */
	private function is_chat_budget_exceeded() {
		$budget = floatval( get_option( 'luwipress_chat_daily_budget', 0.50 ) );
		if ( $budget <= 0 ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'luwipress_token_usage';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return false;
		}

		$today_cost = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(estimated_cost), 0) FROM {$table} /* luwipress-audit:ignore */ WHERE workflow = %s AND date = %s",
			'customer-chat',
			current_time( 'Y-m-d' )
		) );

		return $today_cost >= $budget;
	}

	/**
	 * Get or create a conversation record for the given session ID.
	 *
	 * @param string $session_id The unique session identifier.
	 * @param string $page_url   The page URL where chat was initiated.
	 * @return object The conversation database row.
	 */
	private function get_or_create_conversation( $session_id, $page_url = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'luwipress_chat_conversations';

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} /* luwipress-audit:ignore */ WHERE session_id = %s",
			$session_id
		) );

		if ( $existing ) {
			return $existing;
		}

		$customer_id    = get_current_user_id() ?: null;
		$customer_name  = null;
		$customer_email = null;
		if ( $customer_id ) {
			$user = get_userdata( $customer_id );
			if ( $user ) {
				$customer_name  = $user->display_name;
				$customer_email = $user->user_email;
			}
		}

		$wpdb->insert( $table, array(
			'session_id'     => $session_id,
			'customer_id'    => $customer_id,
			'customer_name'  => $customer_name,
			'customer_email' => $customer_email,
			'status'         => 'active',
			'page_url'       => $page_url,
			'ip_address'     => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		), array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} /* luwipress-audit:ignore */ WHERE session_id = %s",
			$session_id
		) );
	}

	/**
	 * Save a message to the chat messages table.
	 *
	 * @param int         $conversation_id The conversation ID.
	 * @param string      $role            Message role (customer/assistant/agent).
	 * @param string      $content         Message content.
	 * @param string      $source          Message source (web/faq/local/ai).
	 * @param string|null $metadata        Optional JSON metadata.
	 */
	private function save_message( $conversation_id, $role, $content, $source = 'web', $metadata = null ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'luwipress_chat_messages',
			array(
				'conversation_id' => absint( $conversation_id ),
				'role'            => sanitize_text_field( $role ),
				'content'         => wp_kses_post( $content ),
				'source'          => sanitize_text_field( $source ),
				'metadata'        => $metadata,
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get recent messages for a conversation (newest first).
	 *
	 * @param int $conversation_id The conversation ID.
	 * @param int $limit           Maximum messages to return.
	 * @return array Database rows ordered by id DESC.
	 */
	private function get_recent_messages( $conversation_id, $limit = 6 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT role, content FROM {$wpdb->prefix}luwipress_chat_messages
			 WHERE conversation_id = %d ORDER BY id DESC LIMIT %d",
			absint( $conversation_id ),
			$limit
		) );
	}

	/**
	 * Count total messages in a conversation.
	 *
	 * @param int $conversation_id The conversation ID.
	 * @return int Message count.
	 */
	private function get_message_count( $conversation_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}luwipress_chat_messages WHERE conversation_id = %d",
			absint( $conversation_id )
		) );
	}

	/**
	 * Detect the language to localize chips to.
	 *
	 * Priority chain:
	 *   1. WPML current language (`wpml_current_language` filter)
	 *   2. Polylang current language (`pll_current_language()`)
	 *   3. Customer message — quick script/charset heuristic so a Turkish
	 *      customer typing on an English page still gets Turkish chips
	 *   4. WP site locale (`get_locale()`)
	 *   5. 'en' (canonical source)
	 *
	 * Returns a 2-letter ISO 639-1 code (en, tr, fr, ar, …).
	 *
	 * @param string $message Optional customer message for script heuristic.
	 * @return string 2-letter lowercase language code.
	 */
	public function detect_chat_language( $message = '' ) {
		// 1. WPML
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$wpml = apply_filters( 'wpml_current_language', null );
			if ( $wpml ) {
				return strtolower( substr( (string) $wpml, 0, 2 ) );
			}
		}

		// 2. Polylang
		if ( function_exists( 'pll_current_language' ) ) {
			$pl = pll_current_language( 'slug' );
			if ( $pl ) {
				return strtolower( substr( (string) $pl, 0, 2 ) );
			}
		}

		// 3. Script heuristic on customer message — covers the case where
		// a Turkish customer chats on the English language version of the
		// store. We look for distinctive characters / common stop words.
		if ( $message ) {
			$msg = mb_strtolower( $message, 'UTF-8' );
			if ( preg_match( '/[ıİğĞşŞçÇöÖüÜ]|\b(istiyorum|isterim|nasıl|hangi|kaç|nedir|merhaba|teşekkür)\b/u', $msg ) ) {
				return 'tr';
			}
			if ( preg_match( '/[\x{0600}-\x{06FF}]/u', $msg ) ) {
				return 'ar';
			}
			if ( preg_match( '/\b(bonjour|comment|merci|combien|quel|prix|livraison)\b/u', $msg ) ) {
				return 'fr';
			}
			if ( preg_match( '/\b(hallo|wie|danke|wieviel|welche|preis|versand)\b/u', $msg ) ) {
				return 'de';
			}
			if ( preg_match( '/\b(hola|cómo|como|gracias|cuánto|cuanto|cuál|cual|precio|envío|envio)\b/u', $msg ) ) {
				return 'es';
			}
			if ( preg_match( '/\b(ciao|come|grazie|quanto|quale|prezzo|spedizione)\b/u', $msg ) ) {
				return 'it';
			}
		}

		// 4. Site locale
		$locale = get_locale();
		if ( $locale ) {
			return strtolower( substr( $locale, 0, 2 ) );
		}

		return 'en';
	}

	/**
	 * Get the chip vocabulary localized for the given language.
	 *
	 * Lookup order:
	 *   1. `luwipress_chat_chip_strings` filter (operator/theme override).
	 *   2. `luwipress_chat_chips_<lang>` option (cached AI translation).
	 *   3. On miss for a non-English language with AI configured, translate
	 *      the entire vocab in a single AI call and persist the result.
	 *   4. Fall back to the English canonical source on any failure path.
	 *
	 * Persistent cache lives in wp_options — survives chat budgets / restarts
	 * and only re-translates when explicitly cleared. Per-language packs are
	 * tiny (~1KB) so the storage footprint stays negligible even with many
	 * languages.
	 *
	 * @param string $lang 2-letter language code. Defaults to detect.
	 * @return array Map of vocab key → localized string (same keys as CHIP_VOCAB_EN).
	 */
	public function localize_chips( $lang = '' ) {
		if ( ! $lang ) {
			$lang = $this->detect_chat_language();
		}
		$lang = sanitize_key( strtolower( substr( $lang, 0, 2 ) ) );
		if ( ! $lang ) {
			$lang = 'en';
		}

		// Operator/theme override has highest priority and short-circuits
		// any AI translation. Override may return only a subset of keys;
		// missing keys fall back to English so partial overrides are safe.
		$override = apply_filters( 'luwipress_chat_chip_strings', null, $lang );
		if ( is_array( $override ) && ! empty( $override ) ) {
			return array_merge( self::CHIP_VOCAB_EN, array_intersect_key( $override, self::CHIP_VOCAB_EN ) );
		}

		if ( $lang === 'en' ) {
			return self::CHIP_VOCAB_EN;
		}

		$option_key = 'luwipress_chat_chips_' . $lang;
		$cached     = get_option( $option_key, null );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			// Heal partial caches in case CHIP_VOCAB_EN grew since last cache.
			return array_merge( self::CHIP_VOCAB_EN, array_intersect_key( $cached, self::CHIP_VOCAB_EN ) );
		}

		// Cache miss — schedule async translation and return English for
		// this page load. The customer's NEXT chat session in this language
		// will see translated chips (cron usually fires within seconds).
		// Sync translation here would block first-visitor page loads for
		// up to 15s on AI latency, which is unacceptable for a global site.
		$this->schedule_chip_pack_translation( $lang );

		return self::CHIP_VOCAB_EN;
	}

	/**
	 * Queue a background translation job for the given language. Idempotent
	 * — if an event is already scheduled or the cache already exists, this
	 * is a no-op. Random 1-10s offset so a freshly-installed multilingual
	 * store doesn't fire N parallel AI calls in the same second.
	 */
	private function schedule_chip_pack_translation( $lang ) {
		$lang = sanitize_key( strtolower( substr( $lang, 0, 2 ) ) );
		if ( ! $lang || $lang === 'en' ) {
			return;
		}
		if ( get_option( 'luwipress_chat_chips_' . $lang, null ) ) {
			return;
		}
		if ( wp_next_scheduled( 'luwipress_chat_translate_chip_pack', array( $lang ) ) ) {
			return;
		}
		wp_schedule_single_event( time() + wp_rand( 1, 10 ), 'luwipress_chat_translate_chip_pack', array( $lang ) );
	}

	/**
	 * wp_cron handler that performs the actual translation. Runs out-of-band
	 * so the chat asset render and first chat turn never block on AI latency.
	 *
	 * @param string $lang 2-letter language code from the scheduled event.
	 */
	public function cron_translate_chip_pack( $lang ) {
		$lang = sanitize_key( strtolower( substr( (string) $lang, 0, 2 ) ) );
		if ( ! $lang || $lang === 'en' ) {
			return;
		}
		if ( get_option( 'luwipress_chat_chips_' . $lang, null ) ) {
			return; // already filled by another path (e.g. manual REST trigger)
		}
		$translated = $this->translate_chip_pack( $lang );
		if ( ! empty( $translated ) ) {
			update_option( 'luwipress_chat_chips_' . $lang, $translated, false );
			LuwiPress_Logger::log( 'Chat chip pack translated to ' . $lang, 'info' );
		}
	}

	/**
	 * Read every language the site currently serves (WPML > Polylang > site
	 * locale). Used by the auto-warmer to pre-translate chip packs for all
	 * active languages, so first visitors in any of them never see English
	 * fallback chips.
	 *
	 * @return string[] Unique 2-letter codes, lowercased.
	 */
	public function get_active_site_languages() {
		$langs = array();

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$wpml = apply_filters( 'wpml_active_languages', null );
			if ( is_array( $wpml ) ) {
				foreach ( array_keys( $wpml ) as $code ) {
					$langs[] = strtolower( substr( (string) $code, 0, 2 ) );
				}
			}
		}

		if ( function_exists( 'pll_languages_list' ) ) {
			$pl = pll_languages_list();
			if ( is_array( $pl ) ) {
				foreach ( $pl as $slug ) {
					$langs[] = strtolower( substr( (string) $slug, 0, 2 ) );
				}
			}
		}

		// Fall back to site locale so monolingual sites still get warmed.
		$locale = get_locale();
		if ( $locale ) {
			$langs[] = strtolower( substr( $locale, 0, 2 ) );
		}

		return array_values( array_unique( array_filter( $langs ) ) );
	}

	/**
	 * Idempotent warmer: queue translation for every active site language
	 * that doesn't have a cached pack yet. Safe to call on every chat asset
	 * render — uses a static flag + wp_next_scheduled gate to avoid spam.
	 */
	public function warm_chip_packs() {
		static $checked = false;
		if ( $checked ) {
			return;
		}
		$checked = true;

		foreach ( $this->get_active_site_languages() as $lang ) {
			$this->schedule_chip_pack_translation( $lang );
		}
	}

	/**
	 * One-shot AI translation of the entire chip vocabulary. Called only on
	 * the first chat session in a previously-unseen language; the result is
	 * persisted so subsequent sessions in that language hit the option cache.
	 *
	 * Refuses to translate when the AI engine is unavailable / unconfigured
	 * so the chat never blocks; English fallback is the safe degradation.
	 *
	 * @param string $lang 2-letter language code.
	 * @return array|null Translated vocab keyed identically to CHIP_VOCAB_EN, or null on failure.
	 */
	private function translate_chip_pack( $lang ) {
		if ( ! class_exists( 'LuwiPress_AI_Engine' ) ) {
			return null;
		}

		$vocab_json = wp_json_encode( self::CHIP_VOCAB_EN, JSON_UNESCAPED_UNICODE );
		if ( ! $vocab_json ) {
			return null;
		}

		$lang_name = $this->iso_to_name( $lang );

		$system = "You translate UI strings for an e-commerce chat widget into {$lang_name} ({$lang}).\n\n"
			. "Output rules:\n"
			. "- Return ONLY a valid JSON object — no markdown fences, no commentary.\n"
			. "- Keep every key EXACTLY as given. Translate only the values.\n"
			. "- Preserve every %s placeholder verbatim — runtime fills it with a product or category name.\n\n"
			. "Quality rules:\n"
			. "- Use ONLY native {$lang_name} vocabulary. NEVER keep English words like 'top-rated', 'premium', 'team', 'options' as anglicisms — translate them to their proper {$lang_name} equivalents.\n"
			. "- Adjust word order so the placeholder %s sits naturally in the target-language grammar. Add prepositions/articles around %s if the target language requires them (e.g. 'Other %s options' → 'More items from %s', not 'Other %s options').\n"
			. "- Use ONE consistent politeness register throughout: formal/polite \"you\" form (e.g. French 'vous', German 'Sie', Spanish 'usted' OR informal 'tú' — pick one and apply to ALL strings). Default to the form a reputable e-commerce site would use in {$lang_name}-speaking markets.\n"
			. "- Match grammatical number to context: %s in chip templates is typically a CATEGORY name (plural products implied) — use plural-agreeing adjectives where applicable.\n"
			. "- Keep each chip ≤ 6 words. System messages (msg_*) can be one short sentence.\n"
			. "- Chip strings represent what the CUSTOMER taps as their next message (customer voice), except msg_* which are assistant-voice fallbacks.\n"
			. "- Preserve typographic conventions (e.g. Spanish ¿…?, French space before ?/!/:, German Sie capitalisation).";

		$result = LuwiPress_AI_Engine::dispatch(
			'translation-pipeline',
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user',   'content' => $vocab_json ),
			),
			array(
				'temperature' => 0.1,
				'max_tokens'  => 800,
				'timeout'     => 20,
			)
		);

		if ( is_wp_error( $result ) || empty( $result['content'] ) ) {
			LuwiPress_Logger::log( 'Chip pack translation failed for ' . $lang, 'warning' );
			return null;
		}

		$parsed = LuwiPress_AI_Engine::extract_json( $result['content'] );
		if ( ! is_array( $parsed ) ) {
			return null;
		}

		// Validate: every key must be present and a non-empty string.
		// Sanitise — chip strings must not contain HTML.
		$out = array();
		foreach ( self::CHIP_VOCAB_EN as $k => $en ) {
			$v = $parsed[ $k ] ?? '';
			$v = is_string( $v ) ? trim( wp_strip_all_tags( $v ) ) : '';
			$out[ $k ] = $v !== '' ? $v : $en;
		}

		// Guard: if the model returned the English source verbatim for too
		// many keys, treat it as a failed translation rather than poison
		// the cache with English masquerading as the target language.
		$identical = 0;
		foreach ( self::CHIP_VOCAB_EN as $k => $en ) {
			if ( $out[ $k ] === $en ) {
				$identical++;
			}
		}
		if ( $identical >= count( self::CHIP_VOCAB_EN ) - 2 ) {
			return null;
		}

		return $out;
	}

	/**
	 * Map an ISO 639-1 code to its English name so the translation prompt
	 * gets unambiguous language identification. Falls back to the code
	 * itself for any language we don't have a friendly name for.
	 */
	private function iso_to_name( $iso ) {
		static $map = array(
			'en' => 'English',  'tr' => 'Turkish',     'fr' => 'French',
			'de' => 'German',   'es' => 'Spanish',     'it' => 'Italian',
			'pt' => 'Portuguese','ar' => 'Arabic',     'fa' => 'Persian',
			'ru' => 'Russian',  'pl' => 'Polish',      'nl' => 'Dutch',
			'sv' => 'Swedish',  'da' => 'Danish',      'no' => 'Norwegian',
			'fi' => 'Finnish',  'el' => 'Greek',       'he' => 'Hebrew',
			'zh' => 'Chinese',  'ja' => 'Japanese',    'ko' => 'Korean',
			'hi' => 'Hindi',    'id' => 'Indonesian',  'th' => 'Thai',
			'vi' => 'Vietnamese','uk' => 'Ukrainian',  'ro' => 'Romanian',
			'cs' => 'Czech',    'hu' => 'Hungarian',   'bg' => 'Bulgarian',
		);
		return $map[ $iso ] ?? $iso;
	}

	/**
	 * Update the conversation's updated_at timestamp.
	 *
	 * @param int $conversation_id The conversation ID.
	 */
	private function touch_conversation( $conversation_id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'luwipress_chat_conversations',
			array( 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => absint( $conversation_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * GDPR cleanup — delete conversations and messages older than N days.
	 *
	 * @param int $days Number of days to retain data.
	 */
	public static function cleanup( $days = 90 ) {
		global $wpdb;
		$conv_table = $wpdb->prefix . 'luwipress_chat_conversations';
		$msg_table  = $wpdb->prefix . 'luwipress_chat_messages';

		// Delete messages for old conversations
		$wpdb->query( $wpdb->prepare(
			"DELETE m FROM {$msg_table} /* luwipress-audit:ignore */ m
			 INNER JOIN {$conv_table} /* luwipress-audit:ignore */ c ON m.conversation_id = c.id
			 WHERE c.created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			absint( $days )
		) );

		// Delete old conversations
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$conv_table} /* luwipress-audit:ignore */ WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			absint( $days )
		) );
	}

	/**
	 * Delete all data for a specific session (GDPR erasure request).
	 *
	 * @param string $session_id The session to delete.
	 */
	public static function delete_customer_data( $session_id ) {
		global $wpdb;
		$conv_table = $wpdb->prefix . 'luwipress_chat_conversations';
		$msg_table  = $wpdb->prefix . 'luwipress_chat_messages';

		$conversation = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$conv_table} /* luwipress-audit:ignore */ WHERE session_id = %s",
			$session_id
		) );

		if ( $conversation ) {
			$wpdb->delete( $msg_table, array( 'conversation_id' => $conversation->id ), array( '%d' ) );
			$wpdb->delete( $conv_table, array( 'id' => $conversation->id ), array( '%d' ) );
		}
	}
}
