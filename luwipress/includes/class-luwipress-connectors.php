<?php
/**
 * WordPress 7.0 Connectors Bridge
 *
 * WordPress 7.0 ships a native "Settings → Connectors" UI plus a `WP AI Client`
 * abstraction so plugins consume API keys from a single trusted source instead
 * of storing their own copies. This helper lets LuwiPress consume keys from
 * native Connectors when present, and silently fall back to its own legacy
 * `luwipress_{provider}_api_key` options on WP < 7.0 or when a connector is
 * not configured for a given provider.
 *
 * Detection probe is intentionally permissive:
 *  - `function_exists('wp_ai_client_get_provider')`
 *  - `class_exists('WP_AI_Client')`
 *  - WP version >= 7.0
 *  - Operator override via `luwipress_wp7_connectors_active` filter
 *
 * Any TRUE triggers native consumption. The version-compare fallback exists
 * because the final function/class naming may shift between WP 7.0 RC and GA;
 * the filter exists for operators on early-adopter builds or who want to
 * force-disable Connectors integration during debugging.
 *
 * @package LuwiPress
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Connectors {

	/**
	 * Providers that map cleanly to WP 7.0 Connectors. OpenAI-compatible
	 * vendors (DeepSeek, Kimi, Groq, Together) are NOT in WP Connectors
	 * scope — they stay in their own `luwipress_oai_compat_*` option layer.
	 *
	 * @var array
	 */
	private static $native_providers = array( 'openai', 'anthropic', 'google' );

	/**
	 * Cached active connector list for the current request.
	 *
	 * @var array|null
	 */
	private static $active_cache = null;

	/**
	 * Is WP 7.0 (or compatible) native Connectors layer detected?
	 *
	 * Multiple probes OR-ed together so a name change between RC and GA
	 * doesn't silently break consumption. Operators can override via the
	 * `luwipress_wp7_connectors_active` filter.
	 */
	public static function is_active() {
		$native = function_exists( 'wp_ai_client_get_provider' )
			|| function_exists( 'wp_ai_client_get_api_key' )
			|| class_exists( 'WP_AI_Client' )
			|| ( isset( $GLOBALS['wp_version'] ) && version_compare( (string) $GLOBALS['wp_version'], '7.0', '>=' ) );

		return (bool) apply_filters( 'luwipress_wp7_connectors_active', $native );
	}

	/**
	 * Resolve an API key for the given provider, preferring WP 7.0 Connectors
	 * and falling back to the legacy LuwiPress option.
	 *
	 * @param string $provider 'openai' | 'anthropic' | 'google' | 'openai-compatible'
	 * @return string Empty string when nothing is configured anywhere.
	 */
	public static function get_api_key( $provider ) {
		$provider = (string) $provider;

		if ( in_array( $provider, self::$native_providers, true ) && self::is_active() ) {
			$key = self::probe_native_key( $provider );
			if ( ! empty( $key ) ) {
				return (string) $key;
			}
		}

		$canonical = (string) get_option( self::legacy_option_name( $provider ), '' );
		if ( '' !== $canonical ) {
			return $canonical;
		}

		foreach ( self::legacy_option_name_aliases( $provider ) as $alias ) {
			$alt = (string) get_option( $alias, '' );
			if ( '' !== $alt ) {
				return $alt;
			}
		}

		return '';
	}

	/**
	 * Try every plausible WP 7.0 native-key surface. Returns empty string
	 * when nothing answers — caller falls back to legacy options.
	 */
	private static function probe_native_key( $provider ) {
		// Filter-style API: `apply_filters('wp_ai_client_get_api_key', null, 'openai')`.
		// Returns null when no connector configured, the secret string otherwise.
		$key = apply_filters( 'wp_ai_client_get_api_key', null, $provider );
		if ( is_string( $key ) && '' !== $key ) {
			return $key;
		}

		// Function-style API.
		if ( function_exists( 'wp_ai_client_get_api_key' ) ) {
			$key = call_user_func( 'wp_ai_client_get_api_key', $provider );
			if ( is_string( $key ) && '' !== $key ) {
				return $key;
			}
		}

		// Class-style API: `WP_AI_Client::get_provider($name)->get_api_key()`.
		if ( class_exists( 'WP_AI_Client' ) && method_exists( 'WP_AI_Client', 'get_provider' ) ) {
			try {
				$obj = call_user_func( array( 'WP_AI_Client', 'get_provider' ), $provider );
				if ( is_object( $obj ) && method_exists( $obj, 'get_api_key' ) ) {
					$key = $obj->get_api_key();
					if ( is_string( $key ) && '' !== $key ) {
						return $key;
					}
				}
			} catch ( Exception $e ) {
				// Soft-fail — fall back to legacy.
			}
		}

		return '';
	}

	/**
	 * Map a LuwiPress provider slug to its legacy option name. Kept here as
	 * the single source of truth so admin migration code and providers agree.
	 */
	public static function legacy_option_name( $provider ) {
		switch ( (string) $provider ) {
			case 'openai':
				return 'luwipress_openai_api_key';
			case 'anthropic':
				return 'luwipress_anthropic_api_key';
			case 'google':
				// Settings UI persists under `luwipress_google_ai_api_key`. The
				// historical provider class read `luwipress_google_api_key` —
				// a latent mismatch. We canonicalise on the UI name; legacy
				// reads also fall through `legacy_option_name_aliases()` below.
				return 'luwipress_google_ai_api_key';
			case 'openai-compatible':
				return 'luwipress_oai_compat_api_key';
		}
		return '';
	}

	/**
	 * Historical alias names for legacy options. `get_api_key()` walks these
	 * after the canonical name returns empty, so a key stored under a former
	 * spelling still resolves.
	 */
	public static function legacy_option_name_aliases( $provider ) {
		switch ( (string) $provider ) {
			case 'google':
				return array( 'luwipress_google_api_key' );
		}
		return array();
	}

	/**
	 * Mirror of the three native-provider connector states for the admin UI.
	 * Each entry: { has_legacy: bool, in_connectors: bool, source: 'connectors'|'legacy'|'none' }.
	 */
	public static function list_active_connectors() {
		if ( is_array( self::$active_cache ) ) {
			return self::$active_cache;
		}

		$out      = array();
		$wp7_live = self::is_active();

		foreach ( self::$native_providers as $provider ) {
			$legacy = (string) get_option( self::legacy_option_name( $provider ), '' );
			if ( '' === $legacy ) {
				foreach ( self::legacy_option_name_aliases( $provider ) as $alias ) {
					$alt = (string) get_option( $alias, '' );
					if ( '' !== $alt ) {
						$legacy = $alt;
						break;
					}
				}
			}
			$native = $wp7_live ? self::probe_native_key( $provider ) : '';

			if ( '' !== $native ) {
				$source = 'connectors';
			} elseif ( '' !== $legacy ) {
				$source = 'legacy';
			} else {
				$source = 'none';
			}

			$out[ $provider ] = array(
				'has_legacy'    => ( '' !== $legacy ),
				'in_connectors' => ( '' !== $native ),
				'source'        => $source,
			);
		}

		self::$active_cache = $out;
		return $out;
	}

	/**
	 * Bust the request-local cache (e.g. right after a migrate-execute call so
	 * the UI repaints the "Aktif Connectors" pills without a page reload).
	 */
	public static function flush_cache() {
		self::$active_cache = null;
	}

	/**
	 * Best-effort write of a key INTO native Connectors. WP 7.0's official
	 * registration surface name is still in flux (Automattic discussed both
	 * `wp_ai_client_register_provider` and `WP_AI_Client::register`); we try
	 * every plausible entrypoint and return success/failure per provider.
	 *
	 * Caller is responsible for confirming success and only then deleting
	 * the legacy option — see migrate-execute REST handler.
	 *
	 * @param string $provider
	 * @param string $api_key
	 * @return true|WP_Error
	 */
	public static function write_native_key( $provider, $api_key ) {
		$provider = (string) $provider;
		$api_key  = (string) $api_key;

		if ( '' === $api_key ) {
			return new WP_Error(
				'luwipress_connectors_empty_key',
				__( 'Cannot migrate an empty key.', 'luwipress' )
			);
		}

		if ( ! in_array( $provider, self::$native_providers, true ) ) {
			return new WP_Error(
				'luwipress_connectors_unsupported',
				/* translators: %s: provider slug */
				sprintf( __( 'Provider %s is not supported by WP Connectors.', 'luwipress' ), $provider )
			);
		}

		if ( ! self::is_active() ) {
			return new WP_Error(
				'luwipress_connectors_inactive',
				__( 'WordPress 7.0 Connectors is not active on this site.', 'luwipress' )
			);
		}

		// Try function-style API first.
		if ( function_exists( 'wp_ai_client_register_provider' ) ) {
			try {
				$ok = call_user_func( 'wp_ai_client_register_provider', $provider, $api_key );
				if ( false === $ok || is_wp_error( $ok ) ) {
					return is_wp_error( $ok ) ? $ok : new WP_Error( 'luwipress_connectors_write_failed', __( 'Native registration returned false.', 'luwipress' ) );
				}
				return true;
			} catch ( Exception $e ) {
				return new WP_Error( 'luwipress_connectors_write_exception', $e->getMessage() );
			}
		}

		// Class-style API.
		if ( class_exists( 'WP_AI_Client' ) && method_exists( 'WP_AI_Client', 'register_provider' ) ) {
			try {
				$ok = call_user_func( array( 'WP_AI_Client', 'register_provider' ), $provider, $api_key );
				if ( false === $ok || is_wp_error( $ok ) ) {
					return is_wp_error( $ok ) ? $ok : new WP_Error( 'luwipress_connectors_write_failed', __( 'Native registration returned false.', 'luwipress' ) );
				}
				return true;
			} catch ( Exception $e ) {
				return new WP_Error( 'luwipress_connectors_write_exception', $e->getMessage() );
			}
		}

		// Action-style API: hooks may exist that accept (provider, key).
		if ( has_action( 'wp_ai_client_register_provider' ) ) {
			do_action( 'wp_ai_client_register_provider', $provider, $api_key );
			// We can't tell from a do_action call whether anyone actually
			// persisted the key — re-probe and trust empty-vs-set as the signal.
			self::flush_cache();
			$check = self::probe_native_key( $provider );
			if ( '' !== $check ) {
				return true;
			}
		}

		return new WP_Error(
			'luwipress_connectors_no_writer',
			__( 'WordPress 7.0 Connectors did not expose a writable registration surface. Please add the key manually under Settings → Connectors.', 'luwipress' )
		);
	}

	/**
	 * Convenience: list of providers WP 7.0 Connectors covers.
	 */
	public static function native_providers() {
		return self::$native_providers;
	}
}
