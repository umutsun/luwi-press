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

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
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
			return array(
				'content'  => $faq_answer['answer'],
				'source'   => 'faq',
				'metadata' => wp_json_encode( array(
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
				'content' => get_option( 'luwipress_chat_budget_message', 'Our chat assistant is currently unavailable. Please contact our team directly.' ),
				'source'  => 'local',
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
		) );

		if ( is_wp_error( $result ) ) {
			LuwiPress_Logger::log( 'Customer chat AI error: ' . $result->get_error_message(), 'error' );
			return array(
				'content' => 'I apologize, but I\'m having trouble right now. Would you like to speak with our team directly?',
				'source'  => 'local',
			);
		}

		return array(
			'content'  => $result['content'],
			'source'   => 'ai',
			'metadata' => wp_json_encode( array(
				'intent' => $intent,
				'tokens' => $result['input_tokens'] + $result['output_tokens'],
			) ),
		);
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

		return $context;
	}

	/**
	 * Get Knowledge Graph data (cached).
	 *
	 * @return array KG data with products, categories, summary.
	 */
	private function get_kg_data() {
		$cache_key = 'luwipress_chat_kg_data';
		$data = get_transient( $cache_key );

		if ( false !== $data ) {
			return $data;
		}

		// Fetch KG data directly via the class (no HTTP call)
		if ( ! class_exists( 'LuwiPress_Knowledge_Graph' ) ) {
			return array();
		}

		$kg      = LuwiPress_Knowledge_Graph::get_instance();
		$request = new WP_REST_Request( 'GET', '/luwipress/v1/knowledge-graph' );
		$request->set_param( 'section', 'products,categories' );

		$response = $kg->handle_knowledge_graph( $request );
		$data     = ( $response instanceof WP_REST_Response ) ? $response->get_data() : $response;

		// Cache for 1 hour
		set_transient( $cache_key, $data, HOUR_IN_SECONDS );

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

		$prompt = "You are the customer support assistant for {$store_name}.\n";
		$prompt .= "Store: {$store_url}\n";
		if ( $currency ) {
			$prompt .= "Currency: {$currency}\n";
		}

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

		$prompt .= "RULES:\n";
		$prompt .= "- Be helpful, concise, and friendly\n";
		$prompt .= "- Use ONLY the product information provided above — never invent products or prices\n";
		$prompt .= "- When mentioning products, always include their link\n";
		$prompt .= "- If asked about categories or browsing, suggest relevant categories from the list\n";
		$prompt .= "- If you cannot answer confidently, suggest the customer speak with the team\n";
		$prompt .= "- Keep responses under 150 words\n";
		$prompt .= "- Respond in the same language the customer uses\n";

		return $prompt;
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
			"SELECT COALESCE(SUM(estimated_cost), 0) FROM {$table} WHERE workflow = %s AND date = %s",
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
			"SELECT * FROM {$table} WHERE session_id = %s",
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
			"SELECT * FROM {$table} WHERE session_id = %s",
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
			"DELETE m FROM {$msg_table} m
			 INNER JOIN {$conv_table} c ON m.conversation_id = c.id
			 WHERE c.created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			absint( $days )
		) );

		// Delete old conversations
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$conv_table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
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
			"SELECT id FROM {$conv_table} WHERE session_id = %s",
			$session_id
		) );

		if ( $conversation ) {
			$wpdb->delete( $msg_table, array( 'conversation_id' => $conversation->id ), array( '%d' ) );
			$wpdb->delete( $conv_table, array( 'id' => $conversation->id ), array( '%d' ) );
		}
	}
}
