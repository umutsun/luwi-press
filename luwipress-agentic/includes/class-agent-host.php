<?php
/**
 * Agent Host
 *
 * Registry + dispatcher. Decouples the chat surface from any single AI
 * runtime. Adapters register via the `luwipress_agent_register` action;
 * the active one is read from `luwipress_agent_active` option (default
 * `open-claw`).
 *
 * @package LuwiPress\Agent
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Agent_Host {

	private static $instance = null;

	/** @var LuwiPress_Agent_Adapter_Interface[] */
	private $adapters = array();

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		do_action( 'luwipress_agent_register', $this );
	}

	public function register( LuwiPress_Agent_Adapter_Interface $adapter ) {
		$this->adapters[ $adapter->get_id() ] = $adapter;
	}

	/** @return LuwiPress_Agent_Adapter_Interface[] */
	public function get_adapters() {
		return $this->adapters;
	}

	/** @return LuwiPress_Agent_Adapter_Interface|null */
	public function get_adapter( $id ) {
		return isset( $this->adapters[ $id ] ) ? $this->adapters[ $id ] : null;
	}

	public function get_active_id() {
		// Default to `hermes`: the Open Claw hosted backend (oc.luwi.dev) is
		// decommissioned, so a fresh install that never picked a backend should
		// land on the live one rather than a dead default. Sites that already
		// set the option keep their explicit choice. get_active_adapter() also
		// falls back to the first registered adapter if this id is unknown.
		return (string) get_option( 'luwipress_agent_active', 'hermes' );
	}

	public function set_active_id( $id ) {
		$id = sanitize_key( $id );
		if ( ! isset( $this->adapters[ $id ] ) ) {
			return new WP_Error( 'unknown_agent', "Unknown agent: {$id}" );
		}
		update_option( 'luwipress_agent_active', $id );
		return true;
	}

	/**
	 * Return the active adapter, falling back to the first registered one
	 * when the configured active id no longer exists (e.g. adapter plugin
	 * uninstalled).
	 *
	 * @return LuwiPress_Agent_Adapter_Interface|null
	 */
	public function get_active_adapter() {
		$id = $this->get_active_id();
		if ( isset( $this->adapters[ $id ] ) ) {
			return $this->adapters[ $id ];
		}
		return $this->adapters ? reset( $this->adapters ) : null;
	}
}
