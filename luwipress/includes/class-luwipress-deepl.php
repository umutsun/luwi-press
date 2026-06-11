<?php
/**
 * DeepL Translation Engine.
 *
 * Lightweight static helper around the DeepL v2 /translate REST API. DeepL is a
 * translate-only service (text in, translated text out) — it is NOT a chat
 * completion provider, so it deliberately does NOT implement LuwiPress_AI_Provider
 * and is NOT registered in LuwiPress_AI_Engine::get_provider(). It is consumed
 * directly by the translation module's hybrid path: DeepL produces the body text
 * while the configured AI provider still writes SEO meta (title, description,
 * focus keyword, slug, FAQ).
 *
 * @package LuwiPress
 * @since   3.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_DeepL {

	const ENDPOINT_FREE = 'https://api-free.deepl.com/v2/translate';
	const ENDPOINT_PRO  = 'https://api.deepl.com/v2/translate';

	/**
	 * Is a DeepL key configured?
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== trim( (string) get_option( 'luwipress_deepl_api_key', '' ) );
	}

	/**
	 * Pick the correct endpoint for a key. Free keys end with ":fx".
	 *
	 * @param string $key DeepL auth key.
	 * @return string Endpoint URL.
	 */
	public static function endpoint_for_key( $key ) {
		$key = trim( (string) $key );
		return ( ':fx' === substr( $key, -3 ) ) ? self::ENDPOINT_FREE : self::ENDPOINT_PRO;
	}

	/**
	 * Is the key a free-tier key?
	 *
	 * @param string $key DeepL auth key.
	 * @return bool
	 */
	private static function is_free_key( $key ) {
		return ':fx' === substr( trim( (string) $key ), -3 );
	}

	/**
	 * Map a WPML/Polylang language code to a DeepL language code.
	 *
	 * DeepL target codes are uppercase and a few require a region variant
	 * (EN-US, PT-PT). Source codes take the base form (EN, PT). Returns null
	 * when DeepL does not support the language so the caller can fall back to
	 * the LLM translation path.
	 *
	 * @param string $code      WPML language code (e.g. 'en', 'pt-br', 'zh-hans').
	 * @param bool   $is_source True for the source_lang slot (region stripped).
	 * @return string|null DeepL code, or null if unsupported.
	 */
	public static function map_lang_code( $code, $is_source ) {
		$code = strtolower( trim( (string) $code ) );

		// Explicit region-aware aliases (honour an operator's regional choice).
		$region_aliases = array(
			'pt-br' => 'PT-BR',
			'pt-pt' => 'PT-PT',
			'en-gb' => 'EN-GB',
			'en-us' => 'EN-US',
			'zh-hans' => 'ZH',
			'zh-hant' => 'ZH',
		);

		if ( ! $is_source && isset( $region_aliases[ $code ] ) ) {
			return $region_aliases[ $code ];
		}

		// Normalise to the base two-letter code for the lookup table.
		$base = substr( $code, 0, 2 );

		// Target codes (uppercase, region default where DeepL requires one).
		$target_map = array(
			'en' => 'EN-US',
			'pt' => 'PT-PT',
			'de' => 'DE',
			'fr' => 'FR',
			'it' => 'IT',
			'es' => 'ES',
			'nl' => 'NL',
			'ru' => 'RU',
			'ja' => 'JA',
			'zh' => 'ZH',
			'ko' => 'KO',
			'pl' => 'PL',
			'sv' => 'SV',
			'da' => 'DA',
			'fi' => 'FI',
			'cs' => 'CS',
			'el' => 'EL',
			'ro' => 'RO',
			'hu' => 'HU',
			'bg' => 'BG',
			'sk' => 'SK',
			'uk' => 'UK',
			'tr' => 'TR',
			'id' => 'ID',
			'et' => 'ET',
			'lv' => 'LV',
			'lt' => 'LT',
			'sl' => 'SL',
			'nb' => 'NB',
			'no' => 'NB',
		);

		if ( ! isset( $target_map[ $base ] ) ) {
			return null;
		}

		if ( $is_source ) {
			// Source codes strip the region (EN, PT, ZH).
			return substr( $target_map[ $base ], 0, 2 );
		}

		return $target_map[ $base ];
	}

	/**
	 * Translate a single string.
	 *
	 * @param string $text   Text (may contain HTML).
	 * @param string $source Source WPML language code (auto-detected if unmappable).
	 * @param string $target Target WPML language code.
	 * @param array  $opts   Options: timeout.
	 * @return string|WP_Error Translated text or error.
	 */
	public static function translate( $text, $source, $target, array $opts = array() ) {
		$result = self::translate_batch( array( $text ), $source, $target, $opts );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return reset( $result );
	}

	/**
	 * Translate a batch of strings, preserving the input array keys.
	 *
	 * Only non-empty values are sent to DeepL so the returned translation order
	 * lines up with the sent order; the result is re-keyed back onto the
	 * original (non-empty) keys.
	 *
	 * @param array  $texts  Associative array of key => text.
	 * @param string $source Source WPML language code.
	 * @param string $target Target WPML language code.
	 * @param array  $opts   Options: timeout.
	 * @return array|WP_Error key => translated text, or error.
	 */
	public static function translate_batch( $texts, $source, $target, array $opts = array() ) {
		$key = trim( (string) get_option( 'luwipress_deepl_api_key', '' ) );
		if ( '' === $key ) {
			return new WP_Error( 'luwipress_deepl_no_key', __( 'DeepL API key is not configured.', 'luwipress' ) );
		}

		$deepl_target = self::map_lang_code( $target, false );
		if ( null === $deepl_target ) {
			return new WP_Error(
				'luwipress_deepl_unsupported',
				sprintf( /* translators: %s: language code */ __( 'DeepL does not support target language: %s', 'luwipress' ), $target )
			);
		}

		// Send only non-empty fields so the response order maps cleanly.
		$send_keys = array();
		$send_text = array();
		foreach ( $texts as $k => $v ) {
			if ( '' !== (string) $v ) {
				$send_keys[] = $k;
				$send_text[] = (string) $v;
			}
		}

		if ( empty( $send_text ) ) {
			return array();
		}

		$body = array(
			'text'                => $send_text,
			'target_lang'         => $deepl_target,
			'tag_handling'        => 'html',
			'preserve_formatting' => '1',
		);

		// Provide source_lang only when we can map it; otherwise let DeepL
		// auto-detect (safer than failing on an unmappable source code).
		$deepl_source = self::map_lang_code( $source, true );
		if ( null !== $deepl_source ) {
			$body['source_lang'] = $deepl_source;
		}

		$response = wp_remote_post(
			self::endpoint_for_key( $key ),
			array(
				'headers' => array(
					'Authorization' => 'DeepL-Auth-Key ' . $key,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => $body,
				'timeout' => (int) ( $opts['timeout'] ?? 60 ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'luwipress_deepl_http',
				sprintf( /* translators: %s: error message */ __( 'DeepL request failed: %s', 'luwipress' ), $response->get_error_message() ),
				array( 'retryable' => true )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );

		if ( $status_code >= 400 ) {
			$data      = json_decode( $body_raw, true );
			$error_msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : $body_raw;
			return new WP_Error(
				'luwipress_deepl_api',
				sprintf( /* translators: 1: status code, 2: error message */ __( 'DeepL API error (%1$d): %2$s', 'luwipress' ), $status_code, $error_msg ),
				array(
					'status_code' => $status_code,
					'retryable'   => ( 429 === $status_code ) || ( 456 === $status_code ) || ( $status_code >= 500 && $status_code < 600 ),
				)
			);
		}

		$data = json_decode( $body_raw, true );
		if ( ! is_array( $data ) || empty( $data['translations'] ) || ! is_array( $data['translations'] ) ) {
			return new WP_Error( 'luwipress_deepl_parse', __( 'DeepL returned an unexpected response.', 'luwipress' ) );
		}

		// Re-key the ordered translations back onto the original keys.
		$out   = array();
		$total = 0;
		foreach ( $send_keys as $i => $orig_key ) {
			$translated        = $data['translations'][ $i ]['text'] ?? '';
			$out[ $orig_key ]  = $translated;
			$total            += strlen( (string) $translated );
		}

		if ( class_exists( 'LuwiPress_Logger' ) ) {
			LuwiPress_Logger::log(
				sprintf(
					'DeepL translated %d chars -> %s [%s]',
					$total,
					$deepl_target,
					self::is_free_key( $key ) ? 'free' : 'pro'
				),
				'info'
			);
		}

		return $out;
	}
}
