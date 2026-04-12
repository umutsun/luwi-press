<?php
/**
 * AI Prompt Library
 *
 * All system and user prompts extracted from n8n workflow JSONs.
 * Every method returns ['system' => string, 'user' => string] and is
 * filterable via apply_filters('luwipress_prompt_{workflow}', ...).
 *
 * @package N8nPress
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Prompts {

	/**
	 * Product enrichment prompt.
	 *
	 * @param array $product  Product data (name, description, short_description, sku, price, categories, tags, attributes, weight, dimensions).
	 * @param array $options  Options (target_language, currency).
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function product_enrichment( array $product, array $options = array() ) {
		$language   = $options['target_language'] ?? get_option( 'luwipress_target_language', 'English' );
		$currency   = $options['currency'] ?? ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD' );
		$categories = is_array( $product['categories'] ?? null ) ? implode( ', ', $product['categories'] ) : ( $product['categories'] ?? 'Uncategorized' );
		$attributes = is_array( $product['attributes'] ?? null ) ? wp_json_encode( $product['attributes'] ) : ( $product['attributes'] ?? '{}' );
		$dimensions = is_array( $product['dimensions'] ?? null ) ? wp_json_encode( $product['dimensions'] ) : ( $product['dimensions'] ?? '{}' );

		$system = 'You are an e-commerce SEO expert. Generate SEO-optimized content for the given product. Respond ONLY in JSON format, no extra text.';

		$user = sprintf(
			'Generate SEO-optimized content for the following product in %1$s:

Product Name: %2$s
Category: %3$s
Price: %4$s %5$s
SKU: %6$s
Current Description: %7$s
Short Description: %8$s
Attributes: %9$s
Weight: %10$s
Dimensions: %11$s

Respond in JSON:
{
  "description": "Detailed HTML product description (min 200 words, use <h3>, <p>, <ul>)",
  "short_description": "Short description (1-2 sentences with keyword)",
  "meta_title": "SEO title (max 60 chars)",
  "meta_description": "SEO meta description (max 155 chars)",
  "faq": [
    { "question": "Q1", "answer": "A1" },
    { "question": "Q2", "answer": "A2" },
    { "question": "Q3", "answer": "A3" },
    { "question": "Q4", "answer": "A4" },
    { "question": "Q5", "answer": "A5" }
  ],
  "alt_text_main": "Main image alt text (product name + keyword)",
  "tags": ["suggested", "tags"]
}',
			$language,
			$product['name'] ?? '',
			$categories,
			$product['price'] ?? '',
			$currency,
			$product['sku'] ?? '',
			$product['description'] ?? 'None',
			$product['short_description'] ?? 'None',
			$attributes,
			$product['weight'] ?? 'Not specified',
			$dimensions
		);

		$prompt = array( 'system' => $system, 'user' => $user );

		return apply_filters( 'luwipress_prompt_product_enrichment', $prompt, $product, $options );
	}

	/**
	 * AEO FAQ + HowTo + Speakable prompt.
	 *
	 * @param array $product Product data (name, description, categories, price).
	 * @param array $options Options (target_language).
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function aeo_faq( array $product, array $options = array() ) {
		$language   = $options['target_language'] ?? get_option( 'luwipress_target_language', 'English' );
		$categories = is_array( $product['categories'] ?? null ) ? implode( ', ', $product['categories'] ) : ( $product['categories'] ?? '' );

		$system = 'You are an SEO and AEO (Answer Engine Optimization) expert. Generate content optimized for answer engines and voice search. Respond ONLY with valid JSON, no markdown.';

		$user = sprintf(
			'For the following product, generate content in %1$s language:

Product: %2$s
Description: %3$s
Categories: %4$s

Generate the following in valid JSON format:
1. "faqs": Array of 5 FAQ pairs optimized for People Also Ask. Each with "question" and "answer" keys.
2. "howto": If applicable, a HowTo schema with "name", "description", and "steps" array (each step has "name" and "text"). Set to null if not applicable.
3. "speakable": A 2-3 sentence summary optimized for voice search / speakable structured data.

Respond ONLY with valid JSON, no markdown.',
			$language,
			$product['name'] ?? '',
			wp_strip_all_tags( $product['description'] ?? '' ),
			$categories
		);

		$prompt = array( 'system' => $system, 'user' => $user );

		return apply_filters( 'luwipress_prompt_aeo_faq', $prompt, $product, $options );
	}

	/**
	 * AEO HowTo-only prompt.
	 *
	 * @param array $product Product data.
	 * @param array $options Options (target_language).
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function aeo_howto( array $product, array $options = array() ) {
		// The AEO generator creates both FAQ and HowTo in a single call.
		return self::aeo_faq( $product, $options );
	}

	/**
	 * Review response prompt.
	 *
	 * @param array  $review     Review data (reviewer, productName, rating, review).
	 * @param string $store_name Store name.
	 * @param string $language   Response language.
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function review_response( array $review, $store_name = '', $language = '' ) {
		if ( empty( $store_name ) ) {
			$store_name = get_bloginfo( 'name' );
		}
		if ( empty( $language ) ) {
			$language = get_option( 'luwipress_target_language', 'English' );
		}

		$system = sprintf(
			'You are the customer relations manager for %1$s, an online store. You write replies to customer reviews in %2$s.

Rules:
- Be professional and warm
- Address the customer by name
- Mention the product name in the reply
- For positive reviews (4-5 stars): thank them and invite them to shop again
- For negative reviews (1-3 stars): apologize, offer a solution, and provide contact info
- Keep replies 2-4 sentences
- Do not use emojis
- End with: "%1$s Team"',
			$store_name,
			$language
		);

		$user = sprintf(
			'Write a reply to the following customer review:

Customer: %1$s
Product: %2$s
Rating: %3$s/5
Review: %4$s',
			$review['reviewer'] ?? '',
			$review['productName'] ?? $review['product_name'] ?? '',
			$review['rating'] ?? '',
			wp_strip_all_tags( $review['review'] ?? $review['content'] ?? '' )
		);

		$prompt = array( 'system' => $system, 'user' => $user );

		return apply_filters( 'luwipress_prompt_review_response', $prompt, $review, $store_name, $language );
	}

	/**
	 * Content generation prompt.
	 *
	 * @param array $data Schedule data (topic, language, tone, word_count, keywords, site_name).
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function content_generation( array $data ) {
		$system = 'You are an expert SEO content writer. You write engaging, well-structured, SEO-optimized articles. Always include proper headings (H2, H3), internal linking opportunities marked as [INTERNAL_LINK: topic], and a clear call-to-action. Write in the specified language.';

		$user = sprintf(
			'Write a comprehensive article about: %1$s

Target language: %2$s
Tone: %3$s
Approximate word count: %4$s
SEO Keywords: %5$s
Site name: %6$s

Return a JSON object with these fields:
- title: SEO-optimized title
- content: Full HTML article content with proper heading tags (h2, h3)
- excerpt: 2-3 sentence summary (max 160 chars)
- meta_title: SEO meta title (max 60 chars)
- meta_description: SEO meta description (max 155 chars)
- tags: array of 5-8 relevant tags

IMPORTANT: Return ONLY valid JSON, no markdown code blocks.',
			$data['topic'] ?? '',
			$data['language'] ?? 'English',
			$data['tone'] ?? 'professional',
			$data['word_count'] ?? 1000,
			$data['keywords'] ?? '',
			$data['site_name'] ?? get_bloginfo( 'name' )
		);

		$prompt = array( 'system' => $system, 'user' => $user );

		return apply_filters( 'luwipress_prompt_content_generation', $prompt, $data );
	}

	/**
	 * Product translation prompt.
	 *
	 * @param array  $content     Content to translate (name, description, short_description, meta_title, meta_description, focus_keyword).
	 * @param string $source_lang Source language code.
	 * @param string $target_lang Target language code.
	 * @param int    $product_id  Product ID.
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function translation( array $content, $source_lang, $target_lang, $product_id = 0 ) {
		$system = sprintf(
			'You are an expert SEO-aware translator for e-commerce. Translate the following product from %1$s to %2$s.

RULES: Preserve brand names as-is. Meta title <60 chars. Meta description <160 chars. Natural language. Preserve HTML.',
			$source_lang,
			$target_lang
		);

		$user = sprintf(
			'Product ID: %1$d
Target Language: %2$s
Title: %3$s
Description: %4$s
Short Description: %5$s
Meta Title: %6$s
Meta Description: %7$s
Focus Keyword: %8$s

Respond ONLY with valid JSON:
{"product_id": %1$d, "target_language": "%2$s", "title": "", "description": "", "short_description": "", "meta_title": "", "meta_description": "", "focus_keyword": "", "slug": ""}',
			$product_id,
			$target_lang,
			$content['name'] ?? $content['title'] ?? '',
			$content['description'] ?? '',
			$content['short_description'] ?? '',
			$content['meta_title'] ?? '',
			$content['meta_description'] ?? '',
			$content['focus_keyword'] ?? ''
		);

		$prompt = array( 'system' => $system, 'user' => $user );

		return apply_filters( 'luwipress_prompt_translation', $prompt, $content, $source_lang, $target_lang );
	}

	/**
	 * Taxonomy translation prompt.
	 *
	 * @param array  $terms       Array of terms: [['term_id' => int, 'name' => string, 'slug' => string], ...].
	 * @param string $taxonomy    Taxonomy type (product_cat, product_tag).
	 * @param string $source_lang Source language code.
	 * @param string $target_lang Target language code.
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function taxonomy_translation( array $terms, $taxonomy, $source_lang, $target_lang ) {
		$tax_label = ( 'product_cat' === $taxonomy ) ? 'product category' : 'product tag';

		$system = sprintf(
			'You are an SEO-aware translator for e-commerce taxonomy terms. Translate %1$s names from %2$s to %3$s. Create SEO-friendly slugs (lowercase, hyphens, no special chars). Keep brand names as-is. Return ONLY valid JSON array.',
			$tax_label,
			$source_lang,
			$target_lang
		);

		$terms_list = '';
		foreach ( $terms as $t ) {
			$terms_list .= sprintf(
				"- term_id: %d, name: \"%s\", slug: \"%s\"\n",
				$t['term_id'] ?? 0,
				$t['name'] ?? '',
				$t['slug'] ?? ''
			);
		}

		$user = sprintf(
			'Translate the following %1$s names from %2$s to %3$s.

Terms:
%4$s
Respond with:
[{"term_id": 123, "name": "translated name", "slug": "translated-slug", "language": "%3$s"}]',
			$tax_label,
			$source_lang,
			$target_lang,
			$terms_list
		);

		$prompt = array( 'system' => $system, 'user' => $user );

		return apply_filters( 'luwipress_prompt_taxonomy_translation', $prompt, $terms, $taxonomy, $source_lang, $target_lang );
	}

	/**
	 * Internal linking prompt.
	 *
	 * @param array  $post_data Post data (title, post_type).
	 * @param array  $markers   Array of [INTERNAL_LINK: topic] marker strings.
	 * @param string $store_name Store name.
	 * @param string $site_url   Site URL.
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function internal_linking( array $post_data, array $markers, $store_name = '', $site_url = '' ) {
		if ( empty( $store_name ) ) {
			$store_name = get_bloginfo( 'name' );
		}
		if ( empty( $site_url ) ) {
			$site_url = get_site_url();
		}

		$system = sprintf(
			'You are an internal linking expert for %1$s (%2$s). For each topic marker, suggest the best URL and anchor text from the store\'s existing pages.

Output a JSON array of objects: [{"topic": "original topic", "url": "best matching URL", "anchor": "optimized anchor text"}]

Rules:
- Use existing product, category, or blog post URLs from the store
- Anchor text should be natural and SEO-friendly
- If you cannot find a match, set url to empty string
- Prefer product pages over category pages
- Keep anchor text under 50 characters',
			$store_name,
			$site_url
		);

		$markers_list = '';
		foreach ( $markers as $i => $marker ) {
			$markers_list .= sprintf( "%d. [INTERNAL_LINK: %s]\n", $i + 1, $marker );
		}

		$user = sprintf(
			'Post: "%1$s" (%2$s)

Resolve these internal link markers:
%3$s
Store URL: %4$s',
			$post_data['title'] ?? '',
			$post_data['post_type'] ?? 'post',
			$markers_list,
			$site_url
		);

		$prompt = array( 'system' => $system, 'user' => $user );

		return apply_filters( 'luwipress_prompt_internal_linking', $prompt, $post_data, $markers );
	}

	/**
	 * Open Claw chat prompt.
	 *
	 * @param string $message      User message.
	 * @param array  $site_context Site context (site_name, site_url, products, posts, seo_plugin, translation_plugin, active_languages, available_actions).
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function open_claw_chat( $message, array $site_context = array() ) {
		$actions_list = '';
		if ( ! empty( $site_context['available_actions'] ) && is_array( $site_context['available_actions'] ) ) {
			foreach ( $site_context['available_actions'] as $key => $desc ) {
				$actions_list .= sprintf( "- %s: %s\n", $key, $desc );
			}
		}

		$system = sprintf(
			'You are Open Claw, an AI assistant for managing WordPress + WooCommerce stores.

Store: %1$s (%2$s)
Products: %3$s | Posts: %4$s
SEO Plugin: %5$s
Translation: %6$s
Languages: %7$s

Available actions:
%8$s
Rules:
- Be concise and helpful
- Use markdown for formatting
- For actions, end with JSON: {"action": "type", "params": {}, "confirm": true}
- Include specific numbers when possible',
			$site_context['site_name'] ?? get_bloginfo( 'name' ),
			$site_context['site_url'] ?? get_site_url(),
			$site_context['products'] ?? 0,
			$site_context['posts'] ?? 0,
			$site_context['seo_plugin'] ?? 'none',
			$site_context['translation_plugin'] ?? 'none',
			is_array( $site_context['active_languages'] ?? null ) ? implode( ', ', $site_context['active_languages'] ) : 'default',
			$actions_list
		);

		$prompt = array( 'system' => $system, 'user' => $message );

		return apply_filters( 'luwipress_prompt_open_claw_chat', $prompt, $message, $site_context );
	}

	/**
	 * Image generation prompt for DALL-E.
	 *
	 * @param string $title   Article or product title.
	 * @param string $context Additional context (keywords, description).
	 * @return string DALL-E prompt.
	 */
	public static function image_generation( $title, $context = '' ) {
		$prompt = sprintf(
			'Create a professional, high-quality featured image for a blog article titled "%s". %sThe image should be clean, modern, and suitable for a professional e-commerce website. No text in the image.',
			$title,
			! empty( $context ) ? "Context: {$context}. " : ''
		);

		return apply_filters( 'luwipress_prompt_image_generation', $prompt, $title, $context );
	}
}
