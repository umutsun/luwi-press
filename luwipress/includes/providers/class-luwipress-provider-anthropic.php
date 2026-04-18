<?php
/**
 * Anthropic (Claude) AI Provider
 *
 * @package LuwiPress
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Provider_Anthropic implements LuwiPress_AI_Provider {

	const API_URL         = 'https://api.anthropic.com/v1/messages';
	const ANTHROPIC_VERSION = '2023-06-01';

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->api_key = get_option( 'luwipress_anthropic_api_key', '' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function chat( array $messages, array $options = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'luwipress_no_api_key', __( 'Anthropic API key is not configured.', 'luwipress' ) );
		}

		$model      = $options['model'] ?? $this->get_default_model();
		$max_tokens = $options['max_tokens'] ?? 1024;
		$temperature = $options['temperature'] ?? 0.7;

		// Separate system message from conversation messages.
		$system          = '';
		$chat_messages   = array();

		foreach ( $messages as $msg ) {
			if ( 'system' === $msg['role'] ) {
				$system .= $msg['content'] . "\n";
			} else {
				$chat_messages[] = array(
					'role'    => $msg['role'],
					'content' => $msg['content'],
				);
			}
		}

		$body = array(
			'model'      => $model,
			'max_tokens' => (int) $max_tokens,
			'temperature' => (float) $temperature,
			'messages'   => $chat_messages,
		);

		if ( ! empty( $system ) ) {
			$body['system'] = trim( $system );
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $this->api_key,
					'anthropic-version'  => self::ANTHROPIC_VERSION,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => (int) ( $options['timeout'] ?? 60 ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'luwipress_api_error',
				sprintf( __( 'Anthropic API request failed: %s', 'luwipress' ), $response->get_error_message() ),
				array( 'status_code' => 0, 'retryable' => true, 'provider' => 'anthropic' )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body_raw, true );

		if ( $status_code >= 400 ) {
			$error_msg = $data['error']['message'] ?? $body_raw;
			return new WP_Error(
				'luwipress_api_error',
				sprintf( __( 'Anthropic API error (%d): %s', 'luwipress' ), $status_code, $error_msg ),
				array(
					'status_code' => $status_code,
					'retryable'   => ( 429 === $status_code ) || ( 529 === $status_code ) || ( $status_code >= 500 && $status_code < 600 ),
					'provider'    => 'anthropic',
				)
			);
		}

		if ( empty( $data['content'][0]['text'] ) ) {
			return new WP_Error(
				'luwipress_empty_response',
				__( 'Anthropic returned an empty response.', 'luwipress' ),
				array( 'status_code' => $status_code, 'retryable' => true, 'provider' => 'anthropic' )
			);
		}

		return array(
			'content'       => $data['content'][0]['text'],
			'input_tokens'  => $data['usage']['input_tokens'] ?? 0,
			'output_tokens' => $data['usage']['output_tokens'] ?? 0,
			'model'         => $data['model'] ?? $model,
			'provider'      => 'anthropic',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return 'anthropic';
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
		return get_option( 'luwipress_anthropic_model', 'claude-haiku-4-5-20241022' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_available_models() {
		return array(
			'claude-haiku-4-5-20241022'  => 'Claude Haiku 4.5 (fast, cheap)',
			'claude-sonnet-4-20250514'   => 'Claude Sonnet 4 (balanced)',
			'claude-opus-4-20250514'     => 'Claude Opus 4 (powerful)',
		);
	}
}
