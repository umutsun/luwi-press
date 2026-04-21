<?php
/**
 * OpenAI-Compatible AI Provider
 *
 * Single provider class that talks to any vendor exposing OpenAI's
 * /chat/completions schema (DeepSeek, Moonshot/Kimi, Groq, Together.ai,
 * self-hosted Ollama/vLLM/LM Studio, etc.). Adding a new vendor is a
 * one-line preset addition — no new class required.
 *
 * Settings:
 *   luwipress_oai_compat_preset     → 'deepseek' | 'kimi' | 'groq' | 'together' | 'custom'
 *   luwipress_oai_compat_api_key    → Bearer token for the selected vendor
 *   luwipress_oai_compat_base_url   → Only used when preset = 'custom'
 *   luwipress_oai_compat_model      → Override the preset's default model
 *
 * @package LuwiPress
 * @since   3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Provider_OpenAI_Compatible implements LuwiPress_AI_Provider {

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * @var string Selected preset name (deepseek, kimi, groq, together, custom).
	 */
	private $preset;

	/**
	 * @var string Resolved base URL (no trailing slash).
	 */
	private $base_url;

	public function __construct() {
		$this->api_key = get_option( 'luwipress_oai_compat_api_key', '' );
		$this->preset  = get_option( 'luwipress_oai_compat_preset', 'deepseek' );

		$presets = self::get_presets();

		if ( 'custom' === $this->preset ) {
			$this->base_url = rtrim( (string) get_option( 'luwipress_oai_compat_base_url', '' ), '/' );
		} else {
			$this->base_url = rtrim( $presets[ $this->preset ]['base_url'] ?? '', '/' );
		}
	}

	/**
	 * Preset catalog. Extend this array to add a new OpenAI-compatible vendor.
	 *
	 * Off-peak hints are UTC windows where some vendors offer discounts. The
	 * engine does NOT auto-defer calls — callers can inspect this hint if they
	 * want to schedule work during the discount window.
	 */
	public static function get_presets() {
		return array(
			'deepseek' => array(
				'label'    => 'DeepSeek',
				'base_url' => 'https://api.deepseek.com/v1',
				'models'   => array(
					'deepseek-chat'     => 'DeepSeek-V3 (fast, cheap)',
					'deepseek-reasoner' => 'DeepSeek-R1 (reasoning)',
				),
				'default_model' => 'deepseek-chat',
				'off_peak_utc'  => array( '16:30', '00:30' ), // 50% discount window
			),
			'kimi' => array(
				'label'    => 'Moonshot Kimi',
				'base_url' => 'https://api.moonshot.cn/v1',
				'models'   => array(
					'moonshot-v1-8k'   => 'Kimi 8K context',
					'moonshot-v1-32k'  => 'Kimi 32K context',
					'moonshot-v1-128k' => 'Kimi 128K context',
				),
				'default_model' => 'moonshot-v1-32k',
				'off_peak_utc'  => null,
			),
			'groq' => array(
				'label'    => 'Groq',
				'base_url' => 'https://api.groq.com/openai/v1',
				'models'   => array(
					'llama-3.3-70b-versatile' => 'Llama 3.3 70B (versatile)',
					'llama-3.1-8b-instant'    => 'Llama 3.1 8B (instant)',
					'mixtral-8x7b-32768'      => 'Mixtral 8x7B',
				),
				'default_model' => 'llama-3.3-70b-versatile',
				'off_peak_utc'  => null,
			),
			'together' => array(
				'label'    => 'Together.ai',
				'base_url' => 'https://api.together.xyz/v1',
				'models'   => array(
					'meta-llama/Llama-3.3-70B-Instruct-Turbo'    => 'Llama 3.3 70B Turbo',
					'deepseek-ai/DeepSeek-V3'                    => 'DeepSeek-V3 (hosted)',
					'Qwen/Qwen2.5-72B-Instruct-Turbo'            => 'Qwen 2.5 72B Turbo',
				),
				'default_model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
				'off_peak_utc'  => null,
			),
			'custom' => array(
				'label'    => 'Custom (self-hosted / other)',
				'base_url' => '', // user-supplied
				'models'   => array(), // user-supplied via luwipress_oai_compat_model option
				'default_model' => '',
				'off_peak_utc'  => null,
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function chat( array $messages, array $options = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'luwipress_no_api_key',
				__( 'OpenAI-Compatible provider is not configured (missing API key or base URL).', 'luwipress' )
			);
		}

		$model       = $options['model'] ?? $this->get_default_model();
		$max_tokens  = $options['max_tokens'] ?? 1024;
		$temperature = $options['temperature'] ?? 0.7;

		if ( empty( $model ) ) {
			return new WP_Error(
				'luwipress_no_model',
				__( 'No model selected for the OpenAI-Compatible provider.', 'luwipress' )
			);
		}

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

		// json_mode — most OpenAI-compatible vendors accept response_format.
		// DeepSeek + Moonshot + Groq + Together all support it.
		if ( ! empty( $options['json_mode'] ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$url = $this->base_url . '/chat/completions';

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => (int) ( $options['timeout'] ?? 60 ),
			)
		);

		$vendor_label = $this->preset;

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'luwipress_api_error',
				sprintf(
					/* translators: 1: vendor preset name, 2: error message */
					__( '%1$s API request failed: %2$s', 'luwipress' ),
					$vendor_label,
					$response->get_error_message()
				),
				array( 'status_code' => 0, 'retryable' => true, 'provider' => 'openai-compatible' )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body_raw, true );

		if ( $status_code >= 400 ) {
			$error_msg = $data['error']['message'] ?? $body_raw;
			return new WP_Error(
				'luwipress_api_error',
				sprintf(
					/* translators: 1: vendor preset name, 2: HTTP status, 3: error message */
					__( '%1$s API error (%2$d): %3$s', 'luwipress' ),
					$vendor_label,
					$status_code,
					$error_msg
				),
				array(
					'status_code' => $status_code,
					'retryable'   => ( 429 === $status_code ) || ( $status_code >= 500 && $status_code < 600 ),
					'provider'    => 'openai-compatible',
				)
			);
		}

		$content = $data['choices'][0]['message']['content'] ?? '';
		if ( empty( $content ) ) {
			return new WP_Error(
				'luwipress_empty_response',
				sprintf(
					/* translators: %s: vendor preset name */
					__( '%s returned an empty response.', 'luwipress' ),
					$vendor_label
				),
				array( 'status_code' => $status_code, 'retryable' => true, 'provider' => 'openai-compatible' )
			);
		}

		return array(
			'content'       => $content,
			'input_tokens'  => $data['usage']['prompt_tokens'] ?? 0,
			'output_tokens' => $data['usage']['completion_tokens'] ?? 0,
			'model'         => $data['model'] ?? $model,
			'provider'      => 'openai-compatible',
			'preset'        => $this->preset,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return 'openai-compatible';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_configured() {
		if ( empty( $this->api_key ) ) {
			return false;
		}
		if ( empty( $this->base_url ) ) {
			return false;
		}
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_default_model() {
		$override = get_option( 'luwipress_oai_compat_model', '' );
		if ( ! empty( $override ) ) {
			return $override;
		}
		$presets = self::get_presets();
		return $presets[ $this->preset ]['default_model'] ?? '';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_available_models() {
		$presets = self::get_presets();
		$models  = $presets[ $this->preset ]['models'] ?? array();

		// Custom preset: surface the override as the only option so UI stays useful.
		if ( 'custom' === $this->preset ) {
			$override = get_option( 'luwipress_oai_compat_model', '' );
			if ( ! empty( $override ) ) {
				$models = array( $override => $override . ' (custom)' );
			}
		}

		return $models;
	}

	/**
	 * Return the active preset's off-peak hint, if any.
	 *
	 * @return array|null ['start' => 'HH:MM', 'end' => 'HH:MM'] in UTC, or null.
	 */
	public function get_off_peak_window() {
		$presets = self::get_presets();
		$window  = $presets[ $this->preset ]['off_peak_utc'] ?? null;
		if ( ! is_array( $window ) || count( $window ) !== 2 ) {
			return null;
		}
		return array( 'start' => $window[0], 'end' => $window[1] );
	}
}
