<?php
/**
 * LuwiPress_Permission shim — only loaded when the core LuwiPress plugin
 * is NOT active. Provides the exact same static surface (is_admin /
 * check_token / check_token_or_admin / is_token_authenticated) the
 * companion's REST routes call into, backed by a WebMCP-owned option
 * key so the operator can configure an API token without Core present.
 *
 * When Core IS active, the real `LuwiPress_Permission` class (in
 * `luwipress/includes/class-luwipress-permission.php`) loads first via
 * plugins_loaded priority 10, and this shim never registers — the
 * class_exists guard in `luwipress_webmcp_register_permission_shim`
 * short-circuits before requiring this file.
 *
 * @package    LuwiPress_WebMCP
 * @subpackage Permission
 * @since      1.0.28
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'LuwiPress_Permission' ) ) {
	return;
}

class LuwiPress_Permission {

	const TOKEN_OPTION_PRIMARY  = 'luwipress_seo_api_token';   // Core's option name — honoured if set.
	const TOKEN_OPTION_FALLBACK = 'luwipress_webmcp_api_token'; // Lite mode's own option name.

	public static function is_admin() {
		return current_user_can( 'manage_options' );
	}

	public static function is_wc_manager() {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function check_token( $request ) {
		$stored = self::get_stored_token();
		if ( empty( $stored ) ) {
			return false;
		}
		$auth = $request->get_header( 'authorization' );
		if ( ! empty( $auth ) ) {
			$token = trim( str_replace( 'Bearer ', '', $auth ) );
			if ( ! empty( $token ) && hash_equals( $stored, $token ) ) {
				return true;
			}
		}
		$custom = $request->get_header( 'x-luwipress-token' );
		if ( ! empty( $custom ) && hash_equals( $stored, $custom ) ) {
			return true;
		}
		return false;
	}

	public static function check_token_or_admin( $request ) {
		if ( self::check_token( $request ) ) {
			return true;
		}
		return self::is_admin();
	}

	public static function require_token( $request ) {
		if ( self::check_token( $request ) ) {
			return true;
		}
		return new WP_Error(
			'luwipress_unauthorized',
			'Valid API token required',
			array( 'status' => 401 )
		);
	}

	/**
	 * Stateless token-authenticated check (no $request reference required).
	 * Mirrors core 3.2.11+ helper so Theme Bridge token-auth (if Theme
	 * Bridge ships standalone in the future) and any other internal path
	 * can ask "is this a token caller?" without the request object.
	 *
	 * @since 1.0.28
	 * @return bool
	 */
	public static function is_token_authenticated() {
		$stored = self::get_stored_token();
		if ( empty( $stored ) ) {
			return false;
		}
		$auth = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
		if ( empty( $auth ) && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}
		if ( ! empty( $auth ) ) {
			$token = trim( str_replace( 'Bearer ', '', $auth ) );
			if ( ! empty( $token ) && hash_equals( $stored, $token ) ) {
				return true;
			}
		}
		$custom = isset( $_SERVER['HTTP_X_LUWIPRESS_TOKEN'] ) ? $_SERVER['HTTP_X_LUWIPRESS_TOKEN'] : '';
		if ( ! empty( $custom ) && hash_equals( $stored, $custom ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Resolve the stored token, preferring Core's option name (so an
	 * operator who upgrades Core → Lite or vice versa keeps the same
	 * token), falling back to WebMCP's own option in pure-Lite installs.
	 *
	 * @return string
	 */
	private static function get_stored_token() {
		$primary = (string) get_option( self::TOKEN_OPTION_PRIMARY, '' );
		if ( '' !== $primary ) {
			return $primary;
		}
		return (string) get_option( self::TOKEN_OPTION_FALLBACK, '' );
	}
}
