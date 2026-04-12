<?php
/**
 * Google AI (Gemini) Provider
 *
 * @package N8nPress
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class N8nPress_Provider_Google implements N8nPress_AI_Provider {

	const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->api_key = get_option( 'n8npress_google_api_key', '' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function chat( array $messages, array $options = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'n8npress_no_api_key', __( 'Google AI API key is not configured.', 'n8npress' ) );
		}

		$model       = $options['model'] ?? $this->get_default_model();
		$max_tokens  = $options['max_tokens'] ?? 1024;
		$temperature = $options['temperature'] ?? 0.7;

		// Separate system instruction from conversation.
		$system_text = '';
		$contents    = array();

		foreach ( $messages as $msg ) {
			if ( 'system' === $msg['role'] ) {
				$system_text .= $msg['content'] . "\n";
			} else {
				// Gemini uses 'user' and 'model' roles (not 'assistant').
				$role = ( 'assistant' === $msg['role'] ) ? 'model' : 'user';
				$contents[] = array(
					'role'  => $role,
					'parts' => array( array( 'text' => $msg['content'] ) ),
				);
			}
		}

		$body = array(
			'contents'         => $contents,
			'generationConfig' => array(
				'maxOutputTokens' => (int) $max_tokens,
				'temperature'     => (float) $temperature,
			),
		);

		if ( ! empty( $system_text ) ) {
			$body['systemInstruction'] = array(
				'parts' => array( array( 'text' => trim( $system_text ) ) ),
			);
		}

		$url = self::API_BASE . $model . ':generateContent?key=' . $this->api_key;

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => (int) ( $options['timeout'] ?? 60 ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'n8npress_api_error',
				sprintf( __( 'Google AI API request failed: %s', 'n8npress' ), $response->get_error_message() )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body_raw, true );

		if ( $status_code >= 400 ) {
			$error_msg = $data['error']['message'] ?? $body_raw;
			return new WP_Error(
				'n8npress_api_error',
				sprintf( __( 'Google AI API error (%d): %s', 'n8npress' ), $status_code, $error_msg )
			);
		}

		$content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
		if ( empty( $content ) ) {
			return new WP_Error( 'n8npress_empty_response', __( 'Google AI returned an empty response.', 'n8npress' ) );
		}

		$usage = $data['usageMetadata'] ?? array();

		return array(
			'content'       => $content,
			'input_tokens'  => $usage['promptTokenCount'] ?? 0,
			'output_tokens' => $usage['candidatesTokenCount'] ?? 0,
			'model'         => $model,
			'provider'      => 'google',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return 'google';
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
		return get_option( 'n8npress_google_model', 'gemini-2.0-flash' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_available_models() {
		return array(
			'gemini-2.0-flash'    => 'Gemini 2.0 Flash (fast, cheap)',
			'gemini-2.5-flash'    => 'Gemini 2.5 Flash (balanced)',
			'gemini-2.5-pro'      => 'Gemini 2.5 Pro (powerful)',
		);
	}
}
