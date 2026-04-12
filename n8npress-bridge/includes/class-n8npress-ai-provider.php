<?php
/**
 * AI Provider Interface
 *
 * All AI providers (Anthropic, OpenAI, Google) implement this interface
 * to provide a normalized API for the AI Engine dispatcher.
 *
 * @package N8nPress
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface N8nPress_AI_Provider
 */
interface N8nPress_AI_Provider {

	/**
	 * Send a chat completion request.
	 *
	 * @param array $messages Array of messages: [['role' => 'system|user|assistant', 'content' => '...'], ...]
	 * @param array $options  Optional settings: model, max_tokens, temperature, json_mode.
	 * @return array|WP_Error Normalized response:
	 *   [
	 *     'content'       => string,  // Raw text response from the model
	 *     'input_tokens'  => int,     // Prompt tokens used
	 *     'output_tokens' => int,     // Completion tokens used
	 *     'model'         => string,  // Actual model used
	 *     'provider'      => string,  // Provider name (anthropic, openai, google)
	 *   ]
	 */
	public function chat( array $messages, array $options = array() );

	/**
	 * Get the provider name.
	 *
	 * @return string e.g. 'anthropic', 'openai', 'google'
	 */
	public function get_name();

	/**
	 * Check if the provider has a valid API key configured.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Get the default model for this provider.
	 *
	 * @return string
	 */
	public function get_default_model();

	/**
	 * Get available models for this provider.
	 *
	 * @return array Associative array of model_id => display_name.
	 */
	public function get_available_models();
}
