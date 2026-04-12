<?php
/**
 * OpenAI AI Provider (GPT + DALL-E)
 *
 * @package N8nPress
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
				sprintf( __( 'OpenAI API request failed: %s', 'luwipress' ), $response->get_error_message() )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body_raw, true );

		if ( $status_code >= 400 ) {
			$error_msg = $data['error']['message'] ?? $body_raw;
			return new WP_Error(
				'luwipress_api_error',
				sprintf( __( 'OpenAI API error (%d): %s', 'luwipress' ), $status_code, $error_msg )
			);
		}

		$content = $data['choices'][0]['message']['content'] ?? '';
		if ( empty( $content ) ) {
			return new WP_Error( 'luwipress_empty_response', __( 'OpenAI returned an empty response.', 'luwipress' ) );
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

		$body = array(
			'model'   => $options['model'] ?? 'dall-e-3',
			'prompt'  => $prompt,
			'n'       => 1,
			'size'    => $options['size'] ?? '1792x1024',
			'quality' => $options['quality'] ?? 'standard',
		);

		$response = wp_remote_post(
			self::IMAGE_API_URL,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 120,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'luwipress_api_error',
				sprintf( __( 'DALL-E API request failed: %s', 'luwipress' ), $response->get_error_message() )
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

		if ( empty( $data['data'][0]['url'] ) ) {
			return new WP_Error( 'luwipress_empty_response', __( 'DALL-E returned no image.', 'luwipress' ) );
		}

		return array(
			'url'             => $data['data'][0]['url'],
			'revised_prompt'  => $data['data'][0]['revised_prompt'] ?? $prompt,
		);
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
