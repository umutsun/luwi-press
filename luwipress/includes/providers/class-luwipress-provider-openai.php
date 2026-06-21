<?php
/**
 * OpenAI AI Provider (GPT + DALL-E)
 *
 * @package LuwiPress
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Provider_OpenAI implements LuwiPress_AI_Provider {

	const CHAT_API_URL  = 'https://api.openai.com/v1/chat/completions';
	const IMAGE_API_URL = 'https://api.openai.com/v1/images/generations';

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * Reads the API key from the `luwipress_openai_api_key` option.
	 */
	public function __construct() {
		$this->api_key = get_option( 'luwipress_openai_api_key', '' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function chat( array $messages, array $options = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'luwipress_no_api_key', __( 'OpenAI API key is not configured.', 'luwipress' ) );
		}

		$model       = $options['model'] ?? $this->get_default_model();
		$max_tokens  = $options['max_tokens'] ?? 1024;
		$temperature = $options['temperature'] ?? 0.7;

		// OpenAI accepts system messages inline in the messages array.
		$formatted = array();
		foreach ( $messages as $msg ) {
			$formatted[] = array(
				'role'    => $msg['role'],
				'content' => $msg['content'],
			);
		}

		// Vision: fold any attached images into the last user message as
		// image_url parts (data URIs). Needs a vision-capable model (gpt-4o,
		// gpt-4o-mini, gpt-4-turbo all qualify).
		if ( ! empty( $options['images'] ) && is_array( $options['images'] ) ) {
			for ( $i = count( $formatted ) - 1; $i >= 0; $i-- ) {
				if ( 'user' !== $formatted[ $i ]['role'] ) {
					continue;
				}
				$parts = array( array( 'type' => 'text', 'text' => (string) $formatted[ $i ]['content'] ) );
				foreach ( $options['images'] as $img ) {
					if ( empty( $img['data'] ) ) {
						continue;
					}
					$mime    = ! empty( $img['mime'] ) ? $img['mime'] : 'image/jpeg';
					$parts[] = array(
						'type'      => 'image_url',
						'image_url' => array( 'url' => 'data:' . $mime . ';base64,' . $img['data'] ),
					);
				}
				$formatted[ $i ]['content'] = $parts;
				break;
			}
		}

		$body = array(
			'model'       => $model,
			'max_tokens'  => (int) $max_tokens,
			'temperature' => (float) $temperature,
			'messages'    => $formatted,
		);

		if ( ! empty( $options['json_mode'] ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$response = wp_remote_post(
			self::CHAT_API_URL,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => (int) ( $options['timeout'] ?? 60 ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'luwipress_api_error',
				sprintf( __( 'OpenAI API request failed: %s', 'luwipress' ), $response->get_error_message() ),
				array( 'status_code' => 0, 'retryable' => true, 'provider' => 'openai' )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body_raw, true );

		if ( $status_code >= 400 ) {
			$error_msg = $data['error']['message'] ?? $body_raw;
			return new WP_Error(
				'luwipress_api_error',
				sprintf( __( 'OpenAI API error (%d): %s', 'luwipress' ), $status_code, $error_msg ),
				array(
					'status_code' => $status_code,
					'retryable'   => ( 429 === $status_code ) || ( $status_code >= 500 && $status_code < 600 ),
					'provider'    => 'openai',
				)
			);
		}

		$content = $data['choices'][0]['message']['content'] ?? '';
		if ( empty( $content ) ) {
			return new WP_Error(
				'luwipress_empty_response',
				__( 'OpenAI returned an empty response.', 'luwipress' ),
				array( 'status_code' => $status_code, 'retryable' => true, 'provider' => 'openai' )
			);
		}

		return array(
			'content'       => $content,
			'input_tokens'  => $data['usage']['prompt_tokens'] ?? 0,
			'output_tokens' => $data['usage']['completion_tokens'] ?? 0,
			'model'         => $data['model'] ?? $model,
			'provider'      => 'openai',
		);
	}

	/**
	 * Generate an image using DALL-E.
	 *
	 * @param string $prompt  Image description.
	 * @param array  $options Optional: size, quality, model.
	 * @return array|WP_Error ['url' => string, 'revised_prompt' => string]
	 */
	public function generate_image( $prompt, array $options = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'luwipress_no_api_key', __( 'OpenAI API key is not configured.', 'luwipress' ) );
		}

		// OpenAI deprecated dall-e-3 (the API now 400s "model does not exist").
		// gpt-image-1 is the current model: base64-only response, and a different
		// size/quality vocabulary — so build the request per model family.
		$model        = $options['model'] ?? 'gpt-image-1';
		$is_gpt_image = ( false !== strpos( $model, 'gpt-image' ) );
		if ( $is_gpt_image ) {
			$size = $options['size'] ?? '1536x1024';
			$size = array( '1792x1024' => '1536x1024', '1024x1792' => '1024x1536' )[ $size ] ?? $size;
			if ( ! in_array( $size, array( '1024x1024', '1536x1024', '1024x1536', 'auto' ), true ) ) { $size = 'auto'; }
			$quality = $options['quality'] ?? 'high';
			$quality = array( 'standard' => 'high', 'hd' => 'high' )[ $quality ] ?? $quality;
			if ( ! in_array( $quality, array( 'low', 'medium', 'high', 'auto' ), true ) ) { $quality = 'high'; }
			$body = array( 'model' => $model, 'prompt' => $prompt, 'n' => 1, 'size' => $size, 'quality' => $quality );
		} else {
			$body = array(
				'model'   => $model,
				'prompt'  => $prompt,
				'n'       => 1,
				'size'    => $options['size'] ?? '1792x1024',
				'quality' => $options['quality'] ?? 'standard',
			);
		}

		$response = wp_remote_post(
			self::IMAGE_API_URL,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 180,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'luwipress_api_error',
				sprintf( __( 'Image API request failed: %s', 'luwipress' ), $response->get_error_message() )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body_raw, true );

		if ( $status_code >= 400 ) {
			$error_msg = $data['error']['message'] ?? $body_raw;
			return new WP_Error(
				'luwipress_api_error',
				sprintf( __( 'DALL-E API error (%d): %s', 'luwipress' ), $status_code, $error_msg )
			);
		}

		$item = ( isset( $data['data'][0] ) && is_array( $data['data'][0] ) ) ? $data['data'][0] : array();
		// gpt-image-1 returns base64; dall-e legacy returns a url.
		if ( ! empty( $item['b64_json'] ) ) {
			return array(
				'b64'            => $item['b64_json'],
				'revised_prompt' => $item['revised_prompt'] ?? $prompt,
			);
		}
		if ( ! empty( $item['url'] ) ) {
			return array(
				'url'            => $item['url'],
				'revised_prompt' => $item['revised_prompt'] ?? $prompt,
			);
		}
		return new WP_Error( 'luwipress_empty_response', __( 'Image model returned no image.', 'luwipress' ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return 'openai';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_configured() {
		return ! empty( $this->api_key );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_default_model() {
		return get_option( 'luwipress_openai_model', 'gpt-4o-mini' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_available_models() {
		return array(
			'gpt-4o-mini'  => 'GPT-4o Mini (fast, cheap)',
			'gpt-4o'       => 'GPT-4o (balanced)',
			'gpt-4-turbo'  => 'GPT-4 Turbo (powerful)',
		);
	}
}
