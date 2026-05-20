<?php
/**
 * Agent Adapter Interface
 *
 * Contract every agent runtime implements to plug into LuwiPress. Multiple
 * agents can coexist; the active one is selected via `luwipress_agent_active`
 * option. Open Claw and Hermes ship as default HTTP adapters; third parties
 * register additional runtimes via the `luwipress_agent_register` action.
 *
 * @package LuwiPress\Agent
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface LuwiPress_Agent_Adapter_Interface {

	/** Stable machine id, e.g. `open-claw`, `hermes`. */
	public function get_id();

	/** Human-readable label for admin UI. */
	public function get_label();

	/** True when this adapter has everything it needs to dispatch. */
	public function is_configured();

	/**
	 * Send a turn to the agent runtime.
	 *
	 * @param array $messages Chat history [{role, content}]. NO system message —
	 *                        the adapter is responsible for adding its own.
	 * @param array $context  Site/store context (name, products, plugins, langs).
	 * @param array $tools    Tool schemas the adapter may surface to the runtime.
	 * @return array|WP_Error ['response' => string, 'tool_calls' => array]
	 */
	public function dispatch( $messages, $context, $tools );
}
