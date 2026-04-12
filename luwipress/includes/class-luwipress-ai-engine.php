<?php
/**
 * AI Engine — Central dispatcher for all AI operations.
 *
 * Replaces per-class send_to_n8n() methods with a unified dispatch()
 * call that routes to either local AI providers or the n8n webhook
 * based on the configured processing mode.
 *
 * @package LuwiPress
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_AI_Engine {

	const MODE_LOCAL = 'local';

	/**
	 * Provider instances cache.
	 *
	 * @var array
	 */
	private static $providers = array();

	/**
	 * Default provider per workflow (extracted from n8n workflow configs).
	 *
	 * @var array
	 */
	private static $workflow_providers = array(
		'product-enricher'       => 'anthropic',
		'product-enricher-batch' => 'anthropic',
		'aeo-generator'          => 'anthropic',
		'review-responder'       => 'anthropic',
		'content-scheduler'      => 'anthropic',
		'translation-pipeline'   => 'openai',
		'internal-linker'        => 'anthropic',
		'open-claw'              => 'openai',
	);

	/**
	 * Default max_tokens per workflow.
	 *
	 * @var array
	 */
	private static $workflow_max_tokens = array(
		'product-enricher'       => 1024,
		'product-enricher-batch' => 2000,
		'aeo-generator'          => 2000,
		'review-responder'       => 500,
		'content-scheduler'      => 4096,
		'translation-pipeline'   => 4000,
		'internal-linker'        => 1000,
		'open-claw'              => 1000,
	);

	// ─── PUBLIC API ───────────────────────────────────────────────

	/**
	 * Always returns 'local' — n8n mode removed in v2.0.
	 * Kept for backward compatibility with modules that still check mode.
	 */
	public static function get_mode() {
		return self::MODE_LOCAL;
	}

	/**
	 * Main dispatch — calls AI provider directly, parses response, returns structured result.
	 *
	 * @param string $workflow     Workflow identifier (e.g. 'product-enricher').
	 * @param array  $messages     Messages array: [['role' => 'system|user', 'content' => '...'], ...]
	 * @param array  $options      Options: provider, model, max_tokens, temperature, timeout.
	 * @return array|WP_Error      Normalized AI response or WP_Error.
	 */
	public static function dispatch( $workflow, array $messages, array $options = array() ) {
		// Budget check.
		if ( class_exists( 'LuwiPress_Token_Tracker' ) ) {
			$budget = LuwiPress_Token_Tracker::check_budget( $workflow );
			if ( is_wp_error( $budget ) ) {
				return $budget;
			}
		}

		return self::call_ai( $workflow, $messages, $options );
	}

	/**
	 * Call AI provider directly (local mode).
	 *
	 * @param string $workflow Workflow identifier.
	 * @param array  $messages Messages array.
	 * @param array  $options  Options.
	 * @return array|WP_Error  Normalized response with 'content', 'input_tokens', 'output_tokens', etc.
	 */
	public static function call_ai( $workflow, array $messages, array $options = array() ) {
		$provider_name = $options['provider'] ?? self::get_workflow_provider( $workflow );
		$provider      = self::get_provider( $provider_name );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$call_options = array(
			'model'       => $options['model'] ?? $provider->get_default_model(),
			'max_tokens'  => $options['max_tokens'] ?? self::get_workflow_max_tokens( $workflow ),
			'temperature' => $options['temperature'] ?? 0.7,
			'timeout'     => $options['timeout'] ?? 60,
		);

		if ( ! empty( $options['json_mode'] ) ) {
			$call_options['json_mode'] = true;
		}

		$result = $provider->chat( $messages, $call_options );

		if ( is_wp_error( $result ) ) {
			self::log_error( $workflow, $result );
			return $result;
		}

		// Record token usage.
		if ( class_exists( 'LuwiPress_Token_Tracker' ) ) {
			LuwiPress_Token_Tracker::record( array(
				'workflow'      => $workflow,
				'provider'      => $result['provider'],
				'model'         => $result['model'],
				'input_tokens'  => $result['input_tokens'],
				'output_tokens' => $result['output_tokens'],
				'execution_id'  => 'local-' . wp_generate_uuid4(),
			) );
		}

		self::log_success( $workflow, $result );

		return $result;
	}

	/**
	 * Dispatch and parse JSON response from AI.
	 *
	 * Convenience method: calls dispatch(), then extracts JSON from the response.
	 * Most workflows expect JSON output, so this is the primary method to use.
	 *
	 * @param string $workflow Workflow identifier.
	 * @param array  $messages Messages array.
	 * @param array  $options  Options.
	 * @return array|WP_Error  Parsed JSON data or WP_Error.
	 */
	public static function dispatch_json( $workflow, array $messages, array $options = array() ) {
		$result = self::dispatch( $workflow, $messages, $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// In n8n mode, dispatch returns the webhook response, not parsed JSON.
		if ( ! empty( $result['n8n_forwarded'] ) ) {
			return $result;
		}

		$parsed = self::extract_json( $result['content'] );

		if ( null === $parsed ) {
			return new WP_Error(
				'luwipress_json_parse_error',
				__( 'Failed to parse JSON from AI response.', 'luwipress' ),
				array(
					'raw_content' => mb_substr( $result['content'], 0, 500 ),
					'workflow'    => $workflow,
				)
			);
		}

		// Attach token metadata to parsed result.
		$parsed['_ai_meta'] = array(
			'input_tokens'  => $result['input_tokens'],
			'output_tokens' => $result['output_tokens'],
			'model'         => $result['model'],
			'provider'      => $result['provider'],
		);

		return $parsed;
	}

	/**
	 * Stub — n8n forwarding removed in v2.0. Returns error if called.
	 */
	public static function forward_to_n8n( $event, array $payload = array(), $callback_url = '' ) {
		return new WP_Error( 'luwipress_no_n8n', __( 'n8n integration has been removed. All AI processing is handled natively.', 'luwipress' ) );
	}

	// ─── PROVIDER MANAGEMENT ──────────────────────────────────────

	/**
	 * Get a provider instance.
	 *
	 * @param string $name Provider name: 'anthropic', 'openai', 'google'.
	 * @return LuwiPress_AI_Provider|WP_Error
	 */
	public static function get_provider( $name ) {
		if ( isset( self::$providers[ $name ] ) ) {
			return self::$providers[ $name ];
		}

		switch ( $name ) {
			case 'anthropic':
				$provider = new LuwiPress_Provider_Anthropic();
				break;
			case 'openai':
				$provider = new LuwiPress_Provider_OpenAI();
				break;
			case 'google':
				$provider = new LuwiPress_Provider_Google();
				break;
			default:
				return new WP_Error(
					'luwipress_unknown_provider',
					sprintf( __( 'Unknown AI provider: %s', 'luwipress' ), $name )
				);
		}

		if ( ! $provider->is_configured() ) {
			return new WP_Error(
				'luwipress_provider_not_configured',
				sprintf(
					/* translators: %s: provider display name */
					__( '%s API key is not configured. Go to LuwiPress Settings > AI Providers to add your API key.', 'luwipress' ),
					ucfirst( $name )
				)
			);
		}

		self::$providers[ $name ] = $provider;
		return $provider;
	}

	/**
	 * Get all configured providers.
	 *
	 * @return array Associative array of name => LuwiPress_AI_Provider.
	 */
	public static function get_configured_providers() {
		$configured = array();
		foreach ( array( 'anthropic', 'openai', 'google' ) as $name ) {
			$provider = self::get_provider( $name );
			if ( ! is_wp_error( $provider ) ) {
				$configured[ $name ] = $provider;
			}
		}
		return $configured;
	}

	/**
	 * Get the preferred provider for a workflow.
	 *
	 * @param string $workflow Workflow identifier.
	 * @return string Provider name.
	 */
	public static function get_workflow_provider( $workflow ) {
		// Allow per-workflow override via option.
		$override = get_option( 'luwipress_workflow_provider_' . $workflow, '' );
		if ( ! empty( $override ) ) {
			return $override;
		}

		// Fall back to global default, then workflow default.
		$global = get_option( 'luwipress_default_provider', '' );
		if ( ! empty( $global ) ) {
			return $global;
		}

		return self::$workflow_providers[ $workflow ] ?? 'anthropic';
	}

	/**
	 * Get max tokens for a workflow.
	 *
	 * @param string $workflow Workflow identifier.
	 * @return int
	 */
	public static function get_workflow_max_tokens( $workflow ) {
		return self::$workflow_max_tokens[ $workflow ] ?? absint( get_option( 'luwipress_max_output_tokens', 1024 ) );
	}

	// ─── JSON PARSING ─────────────────────────────────────────────

	/**
	 * Extract JSON from AI response text.
	 *
	 * AI models often wrap JSON in markdown code blocks or add extra text.
	 * This method handles all common patterns.
	 *
	 * @param string $text Raw AI response text.
	 * @return array|null  Parsed JSON or null on failure.
	 */
	public static function extract_json( $text ) {
		$text = trim( $text );

		// 1. Try direct decode.
		$decoded = json_decode( $text, true );
		if ( null !== $decoded ) {
			return $decoded;
		}

		// 2. Strip markdown code fences: ```json ... ``` or ``` ... ```
		if ( preg_match( '/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $text, $matches ) ) {
			$decoded = json_decode( trim( $matches[1] ), true );
			if ( null !== $decoded ) {
				return $decoded;
			}
		}

		// 3. Find first { to last } (object).
		$first_brace = strpos( $text, '{' );
		$last_brace  = strrpos( $text, '}' );
		if ( false !== $first_brace && false !== $last_brace && $last_brace > $first_brace ) {
			$candidate = substr( $text, $first_brace, $last_brace - $first_brace + 1 );
			$decoded   = json_decode( $candidate, true );
			if ( null !== $decoded ) {
				return $decoded;
			}
		}

		// 4. Find first [ to last ] (array).
		$first_bracket = strpos( $text, '[' );
		$last_bracket  = strrpos( $text, ']' );
		if ( false !== $first_bracket && false !== $last_bracket && $last_bracket > $first_bracket ) {
			$candidate = substr( $text, $first_bracket, $last_bracket - $first_bracket + 1 );
			$decoded   = json_decode( $candidate, true );
			if ( null !== $decoded ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Build messages array from a prompt pair.
	 *
	 * @param array $prompt ['system' => string, 'user' => string] from LuwiPress_Prompts.
	 * @return array Messages array suitable for provider chat().
	 */
	public static function build_messages( array $prompt ) {
		$messages = array();

		if ( ! empty( $prompt['system'] ) ) {
			$messages[] = array( 'role' => 'system', 'content' => $prompt['system'] );
		}

		if ( ! empty( $prompt['user'] ) ) {
			$messages[] = array( 'role' => 'user', 'content' => $prompt['user'] );
		}

		return $messages;
	}

	// ─── TEST CONNECTION ──────────────────────────────────────────

	/**
	 * Test an AI provider connection with a minimal request.
	 *
	 * @param string $provider_name Provider name.
	 * @return array|WP_Error       ['success' => bool, 'model' => string, 'latency_ms' => int]
	 */
	public static function test_connection( $provider_name ) {
		$provider = self::get_provider( $provider_name );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$start = microtime( true );

		$result = $provider->chat(
			array(
				array( 'role' => 'user', 'content' => 'Say "OK" and nothing else.' ),
			),
			array(
				'max_tokens'  => 10,
				'temperature' => 0,
				'timeout'     => 15,
			)
		);

		$latency = round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'    => true,
			'model'      => $result['model'],
			'provider'   => $provider_name,
			'latency_ms' => $latency,
			'response'   => mb_substr( $result['content'], 0, 50 ),
		);
	}

	// ─── LOGGING ──────────────────────────────────────────────────

	/**
	 * Log a successful AI call.
	 *
	 * @param string $workflow Workflow name.
	 * @param array  $result   AI response.
	 */
	private static function log_success( $workflow, array $result ) {
		if ( class_exists( 'LuwiPress_Logger' ) ) {
			LuwiPress_Logger::log(
				sprintf(
					'AI call success: %s via %s/%s (%d+%d tokens)',
					$workflow,
					$result['provider'],
					$result['model'],
					$result['input_tokens'],
					$result['output_tokens']
				),
				'debug',
				array( 'workflow' => $workflow )
			);
		}
	}

	/**
	 * Log a failed AI call.
	 *
	 * @param string   $workflow Workflow name.
	 * @param WP_Error $error    Error object.
	 */
	private static function log_error( $workflow, WP_Error $error ) {
		if ( class_exists( 'LuwiPress_Logger' ) ) {
			LuwiPress_Logger::log(
				sprintf( 'AI call failed: %s — %s', $workflow, $error->get_error_message() ),
				'error',
				array( 'workflow' => $workflow, 'code' => $error->get_error_code() )
			);
		}
	}

	// ─── HIGH-LEVEL TASK METHODS ──────────────────────────────────

	/**
	 * Enrich a WooCommerce product with AI-generated content.
	 */
	public static function enrich_product( $product_id, $options = array() ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'wc_required', 'WooCommerce is required.' );
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', 'Product not found.' );
		}

		$context = array(
			'name'              => $product->get_name(),
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'price'             => $product->get_price(),
			'sku'               => $product->get_sku(),
			'categories'        => wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) ),
			'tags'              => wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'names' ) ),
			'attributes'        => $product->get_attributes(),
			'weight'            => $product->get_weight(),
			'dimensions'        => array(
				'length' => $product->get_length(),
				'width'  => $product->get_width(),
				'height' => $product->get_height(),
			),
			'currency'          => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
		);

		$prompt   = LuwiPress_Prompts::product_enrichment( $context, $options );
		$messages = self::build_messages( $prompt );

		return self::dispatch_json( 'product-enricher', $messages, array_merge( $options, array(
			'max_tokens' => 2048,
		) ) );
	}

	/**
	 * Translate a product to a target language.
	 */
	public static function translate_product( $product_id, $target_language, $options = array() ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'wc_required', 'WooCommerce is required.' );
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', 'Product not found.' );
		}

		$detector  = LuwiPress_Plugin_Detector::get_instance();
		$seo       = $detector->detect_seo();
		$meta_keys = $seo['meta_keys'] ?? array();

		$lang_names = array( 'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French', 'ar' => 'Arabic', 'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch', 'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese', 'pt-pt' => 'Portuguese', 'ko' => 'Korean' );
		$source_code = get_option( 'luwipress_target_language', 'en' );

		$content = array(
			'name'             => $product->get_name(),
			'description'      => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'meta_title'       => get_post_meta( $product_id, $meta_keys['title'] ?? '', true ),
			'meta_description' => get_post_meta( $product_id, $meta_keys['description'] ?? '', true ),
			'focus_keyword'    => get_post_meta( $product_id, $meta_keys['focus_kw'] ?? $meta_keys['focus_keyword'] ?? '', true ),
		);

		$source_lang = $lang_names[ $source_code ] ?? ucfirst( $source_code );
		$target_lang = $lang_names[ $target_language ] ?? ucfirst( $target_language );

		$prompt   = LuwiPress_Prompts::translation( $content, $source_lang, $target_lang, $product_id );
		$messages = self::build_messages( $prompt );

		return self::dispatch_json( 'translation-pipeline', $messages, array_merge( $options, array(
			'max_tokens' => 4096,
		) ) );
	}

	/**
	 * Generate AEO content (FAQ, HowTo, Speakable) for a product.
	 */
	public static function generate_aeo( $product_id, $options = array() ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'wc_required', 'WooCommerce is required.' );
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', 'Product not found.' );
		}

		$context = array(
			'name'        => $product->get_name(),
			'description' => wp_strip_all_tags( $product->get_description() ),
			'categories'  => wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) ),
		);

		$prompt   = LuwiPress_Prompts::aeo_faq( $context, $options );
		$messages = self::build_messages( $prompt );

		return self::dispatch_json( 'aeo-generator', $messages, array_merge( $options, array(
			'max_tokens' => 2000,
		) ) );
	}

	/**
	 * Translate taxonomy terms.
	 */
	public static function translate_taxonomy( $terms, $target_language, $taxonomy = 'product_cat', $options = array() ) {
		$lang_names = array( 'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French', 'ar' => 'Arabic', 'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch' );
		$source_code = get_option( 'luwipress_target_language', 'en' );
		$source_lang = $lang_names[ $source_code ] ?? ucfirst( $source_code );
		$target_lang = $lang_names[ $target_language ] ?? ucfirst( $target_language );

		$prompt   = LuwiPress_Prompts::taxonomy_translation( $terms, $taxonomy, $source_lang, $target_lang );
		$messages = self::build_messages( $prompt );

		return self::dispatch_json( 'translation-pipeline', $messages, array_merge( $options, array(
			'max_tokens' => 2000,
		) ) );
	}

	/**
	 * Generate AI response to a product review.
	 */
	public static function respond_to_review( $comment_id, $options = array() ) {
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return new WP_Error( 'not_found', 'Comment not found.' );
		}
		$product_name = get_the_title( $comment->comment_post_ID );
		$rating       = get_comment_meta( $comment_id, 'rating', true );

		$context = array(
			'product_name'  => $product_name,
			'review_author' => $comment->comment_author,
			'review_text'   => $comment->comment_content,
			'rating'        => intval( $rating ),
		);

		$store_name = get_bloginfo( 'name' );
		$prompt   = LuwiPress_Prompts::review_response( $context, $store_name );
		$messages = self::build_messages( $prompt );

		return self::dispatch_json( 'review-responder', $messages, array_merge( $options, array(
			'max_tokens' => 500,
		) ) );
	}

	/**
	 * Generate a blog post on a given topic.
	 */
	public static function generate_blog_post( $topic, $language = 'en', $options = array() ) {
		$context = array(
			'topic'    => $topic,
			'language' => $language,
			'store'    => get_bloginfo( 'name' ),
		);

		$prompt   = LuwiPress_Prompts::content_generation( $context );
		$messages = self::build_messages( $prompt );

		return self::dispatch_json( 'content-scheduler', $messages, array_merge( $options, array(
			'max_tokens' => 4096,
		) ) );
	}

	/**
	 * Generate an AI image (DALL-E / Gemini Imagen).
	 */
	public static function generate_image( $prompt_text, $options = array() ) {
		$provider = get_option( 'luwipress_image_provider', 'dall-e-3' );
		$api_key  = get_option( 'luwipress_openai_api_key', '' );

		if ( strpos( $provider, 'dall-e' ) !== false ) {
			if ( empty( $api_key ) ) {
				return new WP_Error( 'no_key', 'OpenAI API key required for DALL-E.' );
			}
			$response = wp_remote_post( 'https://api.openai.com/v1/images/generations', array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body' => wp_json_encode( array(
					'model'  => $provider,
					'prompt' => $prompt_text,
					'n'      => 1,
					'size'   => '1024x1024',
				) ),
			) );

			if ( is_wp_error( $response ) ) return $response;
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			$url  = $data['data'][0]['url'] ?? '';

			if ( empty( $url ) ) {
				return new WP_Error( 'no_image', $data['error']['message'] ?? 'Image generation failed.' );
			}
			return array( 'url' => $url, 'provider' => $provider );
		}

		return new WP_Error( 'unsupported', 'Image provider not supported: ' . $provider );
	}

	/**
	 * Resolve internal link markers in a post.
	 */
	public static function resolve_internal_links( $post_id, $markers, $candidates, $options = array() ) {
		$post = get_post( $post_id );
		$post_data = array(
			'title'   => $post ? $post->post_title : '',
			'content' => $post ? wp_strip_all_tags( substr( $post->post_content, 0, 500 ) ) : '',
		);

		$prompt   = LuwiPress_Prompts::internal_linking( $post_data, $markers, get_bloginfo( 'name' ), get_site_url() );
		$messages = self::build_messages( $prompt );

		return self::dispatch_json( 'internal-linker', $messages, array_merge( $options, array(
			'max_tokens' => 1000,
		) ) );
	}
}
