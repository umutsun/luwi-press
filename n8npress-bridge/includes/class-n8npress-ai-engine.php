<?php
/**
 * n8nPress AI Engine
 *
 * Direct AI API caller — replaces n8n workflow dependency.
 * Supports OpenAI, Anthropic (Claude), and Google (Gemini).
 *
 * @package n8nPress
 * @since 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class N8nPress_AI_Engine {

	/**
	 * Make an AI API call with the configured provider.
	 *
	 * @param string $system_prompt System instructions.
	 * @param string $user_prompt   User message / task.
	 * @param array  $options       Optional overrides: provider, model, max_tokens, temperature.
	 * @return array|WP_Error       Parsed JSON response or error.
	 */
	public static function call( $system_prompt, $user_prompt, $options = array() ) {
		$provider   = $options['provider']   ?? get_option( 'n8npress_ai_provider', 'openai' );
		$model      = $options['model']      ?? get_option( 'n8npress_ai_model', 'gpt-4o-mini' );
		$max_tokens = $options['max_tokens'] ?? absint( get_option( 'n8npress_max_output_tokens', 1024 ) );
		$temperature = $options['temperature'] ?? 0.7;

		// Budget check
		if ( class_exists( 'N8nPress_Token_Tracker' ) ) {
			$budget = N8nPress_Token_Tracker::check_budget( $options['workflow'] ?? 'ai-engine' );
			if ( is_wp_error( $budget ) ) {
				return $budget;
			}
		}

		// Route to provider
		switch ( $provider ) {
			case 'anthropic':
				$result = self::call_anthropic( $system_prompt, $user_prompt, $model, $max_tokens, $temperature );
				break;
			case 'google':
				$result = self::call_google( $system_prompt, $user_prompt, $model, $max_tokens, $temperature );
				break;
			case 'openai':
			default:
				$result = self::call_openai( $system_prompt, $user_prompt, $model, $max_tokens, $temperature );
				break;
		}

		if ( is_wp_error( $result ) ) {
			N8nPress_Logger::log( 'AI Engine error: ' . $result->get_error_message(), 'error', array(
				'provider' => $provider,
				'model'    => $model,
			) );
			return $result;
		}

		// Track token usage
		if ( class_exists( 'N8nPress_Token_Tracker' ) ) {
			N8nPress_Token_Tracker::record( array(
				'workflow'      => $options['workflow'] ?? 'ai-engine',
				'provider'      => $provider,
				'model'         => $model,
				'input_tokens'  => $result['usage']['input_tokens'] ?? 0,
				'output_tokens' => $result['usage']['output_tokens'] ?? 0,
			) );
		}

		return $result;
	}

	/**
	 * Call and parse JSON response — convenience wrapper.
	 */
	public static function call_json( $system_prompt, $user_prompt, $options = array() ) {
		$result = self::call( $system_prompt, $user_prompt, $options );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$text = $result['content'] ?? '';

		// Extract JSON from response (AI sometimes wraps in markdown code blocks)
		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $text, $m ) ) {
			$text = trim( $m[1] );
		}

		$parsed = json_decode( $text, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			// Try to find JSON object/array in the text
			if ( preg_match( '/(\{[\s\S]*\}|\[[\s\S]*\])/', $text, $m2 ) ) {
				$parsed = json_decode( $m2[1], true );
			}
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new WP_Error( 'json_parse', 'Failed to parse AI response as JSON', array(
					'raw' => substr( $text, 0, 500 ),
				) );
			}
		}

		$parsed['_usage'] = $result['usage'] ?? array();
		return $parsed;
	}

	// ─── OpenAI ─────────────────────────────────────────────────────────

	private static function call_openai( $system, $user, $model, $max_tokens, $temperature ) {
		$api_key = get_option( 'n8npress_openai_api_key', '' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'OpenAI API key not configured.' );
		}

		$body = array(
			'model'       => $model,
			'messages'    => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
		);

		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => 90,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body' => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'openai_error', $data['error']['message'] ?? "HTTP $code", array( 'status' => $code ) );
		}

		return array(
			'content' => $data['choices'][0]['message']['content'] ?? '',
			'usage'   => array(
				'input_tokens'  => $data['usage']['prompt_tokens'] ?? 0,
				'output_tokens' => $data['usage']['completion_tokens'] ?? 0,
			),
		);
	}

	// ─── Anthropic (Claude) ─────────────────────────────────────────────

	private static function call_anthropic( $system, $user, $model, $max_tokens, $temperature ) {
		$api_key = get_option( 'n8npress_anthropic_api_key', '' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'Anthropic API key not configured.' );
		}

		$body = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'system'     => $system,
			'messages'   => array(
				array( 'role' => 'user', 'content' => $user ),
			),
		);

		if ( $temperature !== null ) {
			$body['temperature'] = $temperature;
		}

		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 90,
			'headers' => array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			'body' => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'anthropic_error', $data['error']['message'] ?? "HTTP $code", array( 'status' => $code ) );
		}

		return array(
			'content' => $data['content'][0]['text'] ?? '',
			'usage'   => array(
				'input_tokens'  => $data['usage']['input_tokens'] ?? 0,
				'output_tokens' => $data['usage']['output_tokens'] ?? 0,
			),
		);
	}

	// ─── Google (Gemini) ────────────────────────────────────────────────

	private static function call_google( $system, $user, $model, $max_tokens, $temperature ) {
		$api_key = get_option( 'n8npress_google_ai_api_key', '' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'Google AI API key not configured.' );
		}

		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;

		$body = array(
			'systemInstruction' => array(
				'parts' => array( array( 'text' => $system ) ),
			),
			'contents' => array(
				array(
					'parts' => array( array( 'text' => $user ) ),
				),
			),
			'generationConfig' => array(
				'maxOutputTokens' => $max_tokens,
				'temperature'     => $temperature,
			),
		);

		$response = wp_remote_post( $url, array(
			'timeout' => 90,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$err_msg = $data['error']['message'] ?? "HTTP $code";
			return new WP_Error( 'google_error', $err_msg, array( 'status' => $code ) );
		}

		$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
		$usage = $data['usageMetadata'] ?? array();

		return array(
			'content' => $text,
			'usage'   => array(
				'input_tokens'  => $usage['promptTokenCount'] ?? 0,
				'output_tokens' => $usage['candidatesTokenCount'] ?? 0,
			),
		);
	}

	// ─── Prompt Templates ───────────────────────────────────────────────

	/**
	 * Get a prompt template for a specific task.
	 *
	 * @param string $task    Task type: enrich_product, translate_product, translate_taxonomy, generate_aeo.
	 * @param array  $context Data to fill into the template.
	 * @return array          ['system' => '...', 'user' => '...']
	 */
	public static function get_prompt( $task, $context = array() ) {
		switch ( $task ) {

			case 'enrich_product':
				return array(
					'system' => 'You are an e-commerce SEO expert. Generate SEO-optimized content for the given product. Respond ONLY in valid JSON format, no extra text.',
					'user'   => self::build_enrich_prompt( $context ),
				);

			case 'translate_product':
				return array(
					'system' => 'You are an expert SEO-aware translator for e-commerce. Translate content preserving HTML structure, brand names, and SEO intent. Respond ONLY with valid JSON.',
					'user'   => self::build_translate_product_prompt( $context ),
				);

			case 'translate_taxonomy':
				return array(
					'system' => 'You are a professional translator. Translate taxonomy terms accurately. Keep brand names as-is. Create SEO-friendly slugs. Respond ONLY with valid JSON array.',
					'user'   => self::build_translate_taxonomy_prompt( $context ),
				);

			case 'generate_aeo':
				return array(
					'system' => 'You are an SEO and AEO (Answer Engine Optimization) expert. Generate structured data content optimized for search engines and voice assistants. Respond ONLY with valid JSON.',
					'user'   => self::build_aeo_prompt( $context ),
				);

			default:
				return array(
					'system' => 'You are a helpful assistant. Respond in valid JSON format.',
					'user'   => $context['prompt'] ?? '',
				);
		}
	}

	private static function build_enrich_prompt( $c ) {
		$lang = $c['language'] ?? 'English';
		$p = "Generate SEO-optimized content for the following product in {$lang}:\n\n";
		$p .= "Product Name: " . ( $c['name'] ?? '' ) . "\n";
		$p .= "Category: " . ( is_array( $c['categories'] ?? null ) ? implode( ', ', $c['categories'] ) : ( $c['categories'] ?? '' ) ) . "\n";
		$p .= "Price: " . ( $c['price'] ?? '' ) . " " . ( $c['currency'] ?? 'USD' ) . "\n";
		$p .= "SKU: " . ( $c['sku'] ?? '' ) . "\n";
		$p .= "Current Description: " . ( $c['description'] ?? 'None' ) . "\n";
		$p .= "Short Description: " . ( $c['short_description'] ?? 'None' ) . "\n";

		if ( ! empty( $c['attributes'] ) ) {
			$p .= "Attributes: " . ( is_string( $c['attributes'] ) ? $c['attributes'] : wp_json_encode( $c['attributes'] ) ) . "\n";
		}

		$p .= "\nRespond in JSON:\n";
		$p .= '{"description":"Detailed HTML product description (min 200 words, use <h3>, <p>, <ul>)",';
		$p .= '"short_description":"Short description (1-2 sentences with keyword)",';
		$p .= '"meta_title":"SEO title (max 60 chars)",';
		$p .= '"meta_description":"SEO meta description (max 155 chars)",';
		$p .= '"faq":[{"question":"Q1","answer":"A1"},{"question":"Q2","answer":"A2"},{"question":"Q3","answer":"A3"}],';
		$p .= '"alt_text_main":"Main image alt text",';
		$p .= '"tags":["suggested","tags"]}';

		return $p;
	}

	private static function build_translate_product_prompt( $c ) {
		$source = $c['source_language'] ?? 'English';
		$target = $c['target_language'] ?? 'French';

		$p = "Translate the following product from {$source} to {$target}.\n\n";
		$p .= "RULES: Preserve brand names as-is. Meta title <60 chars. Meta description <160 chars. Natural language. Preserve HTML.\n\n";
		$p .= "Product ID: " . ( $c['product_id'] ?? '' ) . "\n";
		$p .= "Title: " . ( $c['name'] ?? '' ) . "\n";
		$p .= "Description: " . ( $c['description'] ?? '' ) . "\n";
		$p .= "Short Description: " . ( $c['short_description'] ?? '' ) . "\n";
		$p .= "Meta Title: " . ( $c['meta_title'] ?? '' ) . "\n";
		$p .= "Meta Description: " . ( $c['meta_description'] ?? '' ) . "\n";
		$p .= "Focus Keyword: " . ( $c['focus_keyword'] ?? '' ) . "\n\n";
		$p .= 'Respond ONLY with valid JSON: {"product_id":' . ( $c['product_id'] ?? 0 ) . ',"target_language":"' . esc_attr( $target ) . '",';
		$p .= '"name":"","description":"","short_description":"","meta_title":"","meta_description":"","focus_keyword":"","slug":""}';

		return $p;
	}

	private static function build_translate_taxonomy_prompt( $c ) {
		$source = $c['source_language'] ?? 'English';
		$target = $c['target_language'] ?? 'French';
		$taxonomy = $c['taxonomy'] ?? 'product_cat';

		$p = "Translate the following {$taxonomy} names from {$source} to {$target}.\n\nTerms:\n";

		foreach ( $c['terms'] ?? array() as $term ) {
			$p .= "- term_id: " . $term['term_id'] . ", name: \"" . $term['name'] . "\", slug: \"" . $term['slug'] . "\"\n";
		}

		$p .= "\nRULES:\n- Keep brand names as-is\n- Create SEO-friendly slugs (lowercase, hyphens, no special chars)\n- Return ONLY valid JSON array\n\n";
		$p .= 'Respond with: [{"term_id":123,"name":"translated name","slug":"translated-slug","language":"' . esc_attr( $target ) . '"}]';

		return $p;
	}

	private static function build_aeo_prompt( $c ) {
		$lang = $c['language'] ?? 'English';

		$p = "For the following product, generate AEO content in {$lang}:\n\n";
		$p .= "Product: " . ( $c['name'] ?? '' ) . "\n";
		$p .= "Description: " . ( $c['description'] ?? '' ) . "\n";
		$p .= "Categories: " . ( is_array( $c['categories'] ?? null ) ? implode( ', ', $c['categories'] ) : ( $c['categories'] ?? '' ) ) . "\n\n";
		$p .= "Generate:\n";
		$p .= "1. \"faqs\": Array of 5 FAQ pairs optimized for People Also Ask. Each with \"question\" and \"answer\" keys.\n";
		$p .= "2. \"howto\": If applicable, a HowTo schema with \"name\", \"description\", and \"steps\" array (each step has \"name\" and \"text\"). Set to null if not applicable.\n";
		$p .= "3. \"speakable\": A 2-3 sentence summary optimized for voice search.\n\n";
		$p .= 'Respond ONLY with valid JSON: {"faqs":[{"question":"","answer":""}],"howto":null,"speakable":""}';

		return $p;
	}

	// ─── High-Level Task Methods ────────────────────────────────────────

	/**
	 * Enrich a WooCommerce product with AI-generated content.
	 *
	 * @param int   $product_id WooCommerce product ID.
	 * @param array $options    Optional overrides.
	 * @return array|WP_Error   Enrichment result.
	 */
	public static function enrich_product( $product_id, $options = array() ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'wc_required', 'WooCommerce is required.' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', 'Product not found.' );
		}

		$language = get_option( 'n8npress_target_language', 'en' );
		$lang_names = array( 'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French', 'ar' => 'Arabic', 'es' => 'Spanish', 'it' => 'Italian' );
		$lang_name = $lang_names[ $language ] ?? ucfirst( $language );

		$context = array(
			'name'              => $product->get_name(),
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'price'             => $product->get_price(),
			'sku'               => $product->get_sku(),
			'categories'        => wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) ),
			'attributes'        => $product->get_attributes(),
			'currency'          => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'language'          => $lang_name,
		);

		$prompt = self::get_prompt( 'enrich_product', $context );
		$options['workflow'] = 'product-enricher';
		$options['max_tokens'] = $options['max_tokens'] ?? 2048;

		return self::call_json( $prompt['system'], $prompt['user'], $options );
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

		$source = get_option( 'n8npress_target_language', 'en' );
		$lang_names = array( 'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French', 'ar' => 'Arabic', 'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch', 'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese', 'pt-pt' => 'Portuguese', 'ko' => 'Korean' );

		// Get SEO meta
		$detector = N8nPress_Plugin_Detector::get_instance();
		$seo = $detector->detect_seo();
		$meta_title = '';
		$meta_desc = '';
		$focus_kw = '';
		if ( ! empty( $seo['meta_keys'] ) ) {
			$meta_title = get_post_meta( $product_id, $seo['meta_keys']['title'] ?? '', true );
			$meta_desc  = get_post_meta( $product_id, $seo['meta_keys']['description'] ?? '', true );
			$focus_kw   = get_post_meta( $product_id, $seo['meta_keys']['focus_kw'] ?? $seo['meta_keys']['focus_keyword'] ?? '', true );
		}

		$context = array(
			'product_id'       => $product_id,
			'name'             => $product->get_name(),
			'description'      => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'meta_title'       => $meta_title,
			'meta_description' => $meta_desc,
			'focus_keyword'    => $focus_kw,
			'source_language'  => $lang_names[ $source ] ?? ucfirst( $source ),
			'target_language'  => $lang_names[ $target_language ] ?? ucfirst( $target_language ),
		);

		$prompt = self::get_prompt( 'translate_product', $context );
		$options['workflow'] = 'translation-pipeline';
		$options['max_tokens'] = $options['max_tokens'] ?? 4096;

		return self::call_json( $prompt['system'], $prompt['user'], $options );
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

		$language = get_option( 'n8npress_target_language', 'en' );
		$lang_names = array( 'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French', 'ar' => 'Arabic', 'es' => 'Spanish', 'it' => 'Italian' );

		$context = array(
			'name'        => $product->get_name(),
			'description' => wp_strip_all_tags( $product->get_description() ),
			'categories'  => wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) ),
			'language'    => $lang_names[ $language ] ?? ucfirst( $language ),
		);

		$prompt = self::get_prompt( 'generate_aeo', $context );
		$options['workflow'] = 'aeo-generator';
		$options['max_tokens'] = $options['max_tokens'] ?? 2000;

		return self::call_json( $prompt['system'], $prompt['user'], $options );
	}
}
