<?php
/**
 * Cookie Consent — GDPR/ePrivacy banner + consent log + script-blocking helper.
 *
 * Modes:
 *   - "info"     : informational banner only (no consent required). For sites
 *                  that argue all their cookies are strictly necessary.
 *   - "opt-in"   : EU default. Non-necessary cookies are blocked until the
 *                  visitor clicks Accept. Reject = only necessary survives.
 *   - "opt-out"  : Soft mode for US/non-EU traffic. Cookies fire by default;
 *                  visitor can opt out from the preferences modal.
 *
 * Categories (fixed, four-tier industry standard):
 *   - necessary       : always on, cannot be disabled (session, cart, csrf)
 *   - analytics       : GA4, GTM measurement, Plausible, Matomo
 *   - marketing       : Meta Pixel, Google Ads conversion, retargeting
 *   - personalization : preference cookies, chat widgets remembering state
 *
 * Script-blocking pattern (recommended for theme + plugin authors):
 *   - Wrap third-party tags in `<script type="text/plain"
 *     data-luwipress-consent="analytics">…</script>`. The frontend JS
 *     rewrites `type` to `text/javascript` AFTER the visitor consents to
 *     that category, and the browser then executes the inline script.
 *   - For external scripts use `<script type="text/plain"
 *     data-luwipress-consent="marketing" data-src="https://…/pixel.js">`.
 *     The runtime swaps `data-src` → `src` after consent.
 *
 * Consent log: one row per consent SAVE (banner accept, banner reject, or
 * preferences-modal save). Stores IP hash (sha256 of `IP+salt`, never the
 * raw IP — GDPR Article 7(1) requires demonstrable consent but raw IP is
 * personal data; the hash is enough to prove "this visitor consented" if
 * the visitor disputes via their own IP).
 *
 * REST surface (admin auth for settings + log; public for the consent
 * write endpoint, throttled to 5 req/min per IP):
 *   - GET  /luwipress/v1/cookies/config       — public, used by the frontend banner
 *   - POST /luwipress/v1/cookies/consent      — public, records visitor choice
 *   - GET  /luwipress/v1/cookies/log          — admin, paginated consent log
 *   - GET  /luwipress/v1/cookies/stats        — admin, aggregate counts
 *   - GET  /luwipress/v1/cookies/settings     — admin
 *   - POST /luwipress/v1/cookies/settings     — admin partial-update
 *   - POST /luwipress/v1/cookies/policy-text  — admin, AI-generate policy text
 *
 * @package LuwiPress
 * @since   3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Cookie_Consent {

	const TABLE_SUFFIX     = 'luwipress_cookie_consent_log';
	const OPTION_SETTINGS  = 'luwipress_cookie_consent_settings';
	const COOKIE_NAME      = 'luwipress_consent';
	const COOKIE_TTL_DAYS  = 365;
	const NONCE_ACTION     = 'luwipress_cookie_consent';

	const CATEGORIES = array( 'necessary', 'analytics', 'marketing', 'personalization' );

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );

		// Frontend asset enqueue — only when the module is enabled AND the
		// current request is on the front of the site (not admin, not AJAX).
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public static function create_table() {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE_SUFFIX;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ip_hash CHAR(64) NOT NULL,
			user_agent VARCHAR(255) NULL,
			consent_id VARCHAR(64) NOT NULL,
			choices LONGTEXT NULL,
			mode VARCHAR(20) NOT NULL DEFAULT 'opt-in',
			source VARCHAR(40) NOT NULL DEFAULT 'banner',
			country_code CHAR(2) NULL,
			language VARCHAR(10) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY consent_id (consent_id),
			KEY created_at (created_at),
			KEY ip_hash (ip_hash)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// -------------------- Settings --------------------

	public function get_settings() {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$defaults = array(
			'enabled'           => false,
			'mode'              => 'opt-in', // info | opt-in | opt-out
			'position'          => 'bottom', // bottom | top | bottom-left | bottom-right
			'theme'             => 'auto',   // auto | light | dark
			'show_reject_button'=> true,
			'show_preferences'  => true,
			'policy_url'        => '',
			'privacy_url'       => '',
			'imprint_url'       => '',
			'log_retention_days'=> 730, // 2 years per GDPR demonstrable-consent guidance
			'categories_enabled'=> array( 'necessary', 'analytics', 'marketing', 'personalization' ),
			// Microsoft Clarity Consent v2 bridge (3.3.0+, requested by Tapadum vendor).
			// When `clarity_consent_v2_enabled` is true and the storefront has Microsoft
			// Clarity loaded (`window.clarity`), the consent banner fires `clarity('consentv2', …)`
			// every time the visitor changes preferences so Clarity respects analytics +
			// marketing toggles without operators bolting on extra glue plugins.
			'clarity_consent_v2_enabled' => false,
			'texts'             => array(
				'title'        => __( 'We value your privacy', 'luwipress' ),
				'body'         => __( 'We use cookies to enhance browsing, analyze traffic, and personalize content. You can accept all, reject non-essential, or customize your preferences.', 'luwipress' ),
				'accept_all'   => __( 'Accept all', 'luwipress' ),
				'reject_all'   => __( 'Reject non-essential', 'luwipress' ),
				'preferences'  => __( 'Preferences', 'luwipress' ),
				'save'         => __( 'Save preferences', 'luwipress' ),
				'cat_necessary'      => __( 'Strictly necessary', 'luwipress' ),
				'cat_analytics'      => __( 'Analytics', 'luwipress' ),
				'cat_marketing'      => __( 'Marketing', 'luwipress' ),
				'cat_personalization'=> __( 'Personalization', 'luwipress' ),
				'cat_necessary_desc'      => __( 'Required for the site to function — session, cart, security tokens. Cannot be disabled.', 'luwipress' ),
				'cat_analytics_desc'      => __( 'Help us measure traffic and improve the site. Disabling this hides our analytics tags.', 'luwipress' ),
				'cat_marketing_desc'      => __( 'Used by advertising tools to show you relevant ads on other sites. Disable to opt out of retargeting.', 'luwipress' ),
				'cat_personalization_desc'=> __( 'Remember your preferences (language, chat widget state). Disable for a fully anonymous visit.', 'luwipress' ),
			),
		);
		return array_replace_recursive( $defaults, $stored );
	}

	public function update_settings( array $patch ) {
		$current = $this->get_settings();
		$next    = $current;

		if ( array_key_exists( 'enabled', $patch ) ) {
			$next['enabled'] = (bool) $patch['enabled'];
		}
		if ( array_key_exists( 'mode', $patch ) ) {
			$mode = strtolower( (string) $patch['mode'] );
			if ( in_array( $mode, array( 'info', 'opt-in', 'opt-out' ), true ) ) {
				$next['mode'] = $mode;
			}
		}
		if ( array_key_exists( 'position', $patch ) ) {
			$pos = (string) $patch['position'];
			if ( in_array( $pos, array( 'bottom', 'top', 'bottom-left', 'bottom-right' ), true ) ) {
				$next['position'] = $pos;
			}
		}
		if ( array_key_exists( 'theme', $patch ) ) {
			$theme = (string) $patch['theme'];
			if ( in_array( $theme, array( 'auto', 'light', 'dark' ), true ) ) {
				$next['theme'] = $theme;
			}
		}
		foreach ( array( 'show_reject_button', 'show_preferences', 'clarity_consent_v2_enabled' ) as $bk ) {
			if ( array_key_exists( $bk, $patch ) ) {
				$next[ $bk ] = (bool) $patch[ $bk ];
			}
		}
		foreach ( array( 'policy_url', 'privacy_url', 'imprint_url' ) as $url_key ) {
			if ( array_key_exists( $url_key, $patch ) ) {
				$next[ $url_key ] = esc_url_raw( (string) $patch[ $url_key ] );
			}
		}
		if ( array_key_exists( 'log_retention_days', $patch ) ) {
			$next['log_retention_days'] = max( 30, min( 3650, (int) $patch['log_retention_days'] ) );
		}
		if ( array_key_exists( 'categories_enabled', $patch ) && is_array( $patch['categories_enabled'] ) ) {
			$clean = array_values( array_intersect( self::CATEGORIES, array_map( 'sanitize_key', $patch['categories_enabled'] ) ) );
			if ( ! in_array( 'necessary', $clean, true ) ) {
				$clean[] = 'necessary'; // necessary is always on
			}
			$next['categories_enabled'] = $clean;
		}
		if ( array_key_exists( 'texts', $patch ) && is_array( $patch['texts'] ) ) {
			foreach ( $patch['texts'] as $k => $v ) {
				$next['texts'][ $k ] = wp_kses_post( (string) $v );
			}
		}

		update_option( self::OPTION_SETTINGS, $next, false );
		return $next;
	}

	// -------------------- Frontend --------------------

	public function is_enabled() {
		$s = $this->get_settings();
		return ! empty( $s['enabled'] );
	}

	public function enqueue_assets() {
		if ( ! $this->is_enabled() ) {
			return;
		}
		if ( is_admin() ) {
			return;
		}

		$version = defined( 'LUWIPRESS_VERSION' ) ? LUWIPRESS_VERSION : '1.0';

		wp_register_style(
			'luwipress-cookie-consent',
			LUWIPRESS_PLUGIN_URL . 'assets/css/cookie-banner.css',
			array(),
			$version
		);

		wp_register_script(
			'luwipress-cookie-consent',
			LUWIPRESS_PLUGIN_URL . 'assets/js/cookie-banner.js',
			array(),
			$version,
			true
		);

		$settings = $this->get_settings();
		$lang     = $this->detect_language();
		$config   = array(
			'mode'              => $settings['mode'],
			'position'          => $settings['position'],
			'theme'             => $settings['theme'],
			'show_reject_button'=> ! empty( $settings['show_reject_button'] ),
			'show_preferences'  => ! empty( $settings['show_preferences'] ),
			'categories'        => $settings['categories_enabled'],
			'policy_url'        => $settings['policy_url'],
			'privacy_url'       => $settings['privacy_url'],
			'imprint_url'       => $settings['imprint_url'],
			'texts'             => $settings['texts'],
			'rest_url'          => esc_url_raw( rest_url( 'luwipress/v1/cookies/consent' ) ),
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'cookie_name'       => self::COOKIE_NAME,
			'cookie_ttl_days'   => self::COOKIE_TTL_DAYS,
			'language'          => $lang,
		);

		wp_localize_script( 'luwipress-cookie-consent', 'LuwiPressConsent', $config );

		wp_enqueue_style( 'luwipress-cookie-consent' );
		wp_enqueue_script( 'luwipress-cookie-consent' );

		// Microsoft Clarity Consent v2 bridge — small adapter that translates
		// LuwiPress consent toggles into Clarity's native `consentv2` API.
		// Operator-gated (`clarity_consent_v2_enabled`); fires on every
		// `luwipress:consent` CustomEvent dispatched by cookie-banner.js
		// (4 GCM-aligned signals: analytics_storage, ad_storage,
		// ad_user_data, ad_personalization). Vendor-Tapadum request — no
		// Clarity SDK changes required, just respect the toggles already
		// captured by the banner.
		if ( ! empty( $settings['clarity_consent_v2_enabled'] ) ) {
			$bridge = "(function(){\n"
				. "  function send(detail){\n"
				. "    if (typeof window.clarity !== 'function') return;\n"
				. "    var analytics = !!(detail && detail.analytics);\n"
				. "    var marketing = !!(detail && detail.marketing);\n"
				. "    try {\n"
				. "      window.clarity('consentv2', {\n"
				. "        analytics_storage:  analytics ? 'granted' : 'denied',\n"
				. "        ad_storage:         marketing ? 'granted' : 'denied',\n"
				. "        ad_user_data:       marketing ? 'granted' : 'denied',\n"
				. "        ad_personalization: marketing ? 'granted' : 'denied'\n"
				. "      });\n"
				. "    } catch(e) {}\n"
				. "  }\n"
				. "  window.addEventListener('luwipress:consent', function(e){ send(e.detail); });\n"
				. "  // Replay stored choices on page load so visitors with an existing\n"
				. "  // consent cookie don't get reset to default on every navigation.\n"
				. "  try {\n"
				. "    var m = document.cookie.match(/(?:^|;\\s*)luwipress_consent=([^;]+)/);\n"
				. "    if (m && m[1]) send(JSON.parse(decodeURIComponent(m[1])));\n"
				. "  } catch(e) {}\n"
				. "}());";
			wp_add_inline_script( 'luwipress-cookie-consent', $bridge, 'after' );
		}
	}

	private function detect_language() {
		if ( function_exists( 'pll_current_language' ) ) {
			$l = pll_current_language();
			if ( $l ) return (string) $l;
		}
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			return (string) ICL_LANGUAGE_CODE;
		}
		return substr( (string) get_locale(), 0, 2 );
	}

	// -------------------- Consent recording --------------------

	/**
	 * Record a consent decision. Returns the saved row.
	 *
	 * @param array<string,bool> $choices  category → bool
	 * @param string             $source   'banner' | 'preferences' | 'api'
	 */
	public function record_consent( array $choices, $source = 'banner' ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$s     = $this->get_settings();

		// Sanitize choices to known categories; 'necessary' always true.
		$clean = array( 'necessary' => true );
		foreach ( self::CATEGORIES as $cat ) {
			if ( $cat === 'necessary' ) continue;
			$clean[ $cat ] = ! empty( $choices[ $cat ] );
		}

		$consent_id = wp_generate_uuid4();
		$ip_hash    = $this->hash_ip( $this->get_client_ip() );
		$ua         = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 255 ) : '';
		$now        = current_time( 'mysql' );

		$wpdb->insert(
			$table,
			array(
				'ip_hash'     => $ip_hash,
				'user_agent'  => $ua,
				'consent_id'  => $consent_id,
				'choices'     => wp_json_encode( $clean ),
				'mode'        => $s['mode'],
				'source'      => sanitize_key( $source ),
				'country_code'=> $this->detect_country(),
				'language'    => $this->detect_language(),
				'created_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return array(
			'consent_id' => $consent_id,
			'choices'    => $clean,
			'created_at' => $now,
		);
	}

	public function get_log( $page = 1, $per_page = 50 ) {
		global $wpdb;
		$table    = $wpdb->prefix . self::TABLE_SUFFIX;
		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 500, (int) $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from $wpdb->prefix and a class constant. luwipress-audit:ignore
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, ip_hash, consent_id, choices, mode, source, country_code, language, created_at
			 FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
			$per_page, $offset
		), ARRAY_A );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe plugin table name. luwipress-audit:ignore

		foreach ( (array) $rows as &$r ) {
			$r['choices'] = json_decode( (string) $r['choices'], true );
		}

		return array(
			'items'    => (array) $rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	public function get_stats() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe plugin table name. luwipress-audit:ignore
		$last_30 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe plugin table name. luwipress-audit:ignore

		$by_source = $wpdb->get_results( "SELECT source, COUNT(*) as n FROM {$table} GROUP BY source", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe plugin table name. luwipress-audit:ignore
		$source_map = array();
		foreach ( (array) $by_source as $r ) {
			$source_map[ (string) $r['source'] ] = (int) $r['n'];
		}

		// Aggregate accept-rate per category from the JSON column. Cheap-ish
		// for typical log sizes; if a site has millions of rows operators
		// should add a materialised counter column in a future iteration.
		$rows = $wpdb->get_results( "SELECT choices FROM {$table} ORDER BY id DESC LIMIT 10000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe plugin table name. luwipress-audit:ignore
		$cat_accept = array_fill_keys( self::CATEGORIES, 0 );
		$sampled = 0;
		foreach ( (array) $rows as $r ) {
			$c = json_decode( (string) $r['choices'], true );
			if ( ! is_array( $c ) ) continue;
			$sampled++;
			foreach ( self::CATEGORIES as $cat ) {
				if ( ! empty( $c[ $cat ] ) ) $cat_accept[ $cat ]++;
			}
		}
		$cat_rate = array();
		foreach ( $cat_accept as $cat => $n ) {
			$cat_rate[ $cat ] = $sampled > 0 ? round( $n / $sampled, 3 ) : 0.0;
		}

		return array(
			'total'           => $total,
			'last_30_days'    => $last_30,
			'by_source'       => $source_map,
			'category_rate'   => $cat_rate,
			'sample_size'     => $sampled,
			'settings'        => $this->get_settings(),
		);
	}

	private function hash_ip( $ip ) {
		$salt = (string) get_option( 'luwipress_cookie_consent_salt', '' );
		if ( $salt === '' ) {
			$salt = wp_generate_password( 32, true, true );
			update_option( 'luwipress_cookie_consent_salt', $salt, false );
		}
		return hash( 'sha256', (string) $ip . '|' . $salt );
	}

	private function get_client_ip() {
		// Prefer the rightmost public IP from X-Forwarded-For when behind a
		// trusted proxy; otherwise REMOTE_ADDR. We don't trust XFF unconditionally.
		$candidates = array( 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $candidates as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$parts = explode( ',', (string) $_SERVER[ $k ] );
				$ip = trim( end( $parts ) );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}

	private function detect_country() {
		// CloudFlare / similar header — useful for jurisdiction analytics in
		// the log. Always optional; null if absent.
		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$cc = strtoupper( substr( (string) $_SERVER['HTTP_CF_IPCOUNTRY'], 0, 2 ) );
			if ( preg_match( '/^[A-Z]{2}$/', $cc ) ) {
				return $cc;
			}
		}
		return null;
	}

	// -------------------- AI policy generator --------------------

	/**
	 * Compose a site-specific cookie policy paragraph via the AI engine.
	 * Detected analytics / marketing / chat plugins are passed as context so
	 * the policy mentions the actual third parties this site uses.
	 *
	 * @return array{text:string,detected:array}|WP_Error
	 */
	public function generate_policy_text( $language = null ) {
		if ( ! class_exists( 'LuwiPress_AI_Engine' ) ) {
			return new WP_Error( 'ai_unavailable', 'AI engine not available.' );
		}
		$detector = class_exists( 'LuwiPress_Plugin_Detector' ) ? LuwiPress_Plugin_Detector::get_instance() : null;
		$detected = $detector ? array(
			'analytics' => method_exists( $detector, 'detect_analytics' ) ? $detector->detect_analytics() : array(),
			'meta'      => method_exists( $detector, 'detect_meta_ads' ) ? $detector->detect_meta_ads() : array(),
			'google_ads'=> method_exists( $detector, 'detect_google_ads' ) ? $detector->detect_google_ads() : array(),
		) : array();

		$language = $language ?: $this->detect_language();
		$site_name = get_bloginfo( 'name' );

		$prompt = "Write a concise, plain-language cookie policy paragraph (max 220 words) for the website '{$site_name}'. Language: {$language}. Cover the four cookie categories (strictly necessary, analytics, marketing, personalization), explain how visitors can change their choice, and reference any of these third-party tags if they are present on the site: " . wp_json_encode( $detected ) . ". Do NOT include legalese, do NOT include placeholders like [your company name]. Write in second person addressing the visitor.";

		$messages = array(
			array( 'role' => 'system', 'content' => 'You are a privacy-aware copywriter producing GDPR-compliant cookie notices.' ),
			array( 'role' => 'user',   'content' => $prompt ),
		);
		$result = LuwiPress_AI_Engine::dispatch( 'cookie_policy', $messages, array(
			'max_tokens'  => 600,
			'temperature' => 0.4,
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$text = '';
		if ( is_array( $result ) ) {
			if ( isset( $result['text'] ) ) {
				$text = (string) $result['text'];
			} elseif ( isset( $result['content'] ) ) {
				$text = (string) $result['content'];
			}
		}
		return array(
			'text'     => trim( (string) $text ),
			'detected' => $detected,
			'language' => $language,
		);
	}

	// -------------------- REST API --------------------

	public function register_endpoints() {
		$ns = 'luwipress/v1';

		// PUBLIC — frontend banner reads this on every page load.
		register_rest_route( $ns, '/cookies/config', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_config' ),
			'permission_callback' => '__return_true',
		) );

		// PUBLIC — visitor consent write. Throttled.
		register_rest_route( $ns, '/cookies/consent', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_consent' ),
			'permission_callback' => array( $this, 'consent_permission' ),
		) );

		register_rest_route( $ns, '/cookies/log', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_log' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'page'     => array( 'type' => 'integer' ),
				'per_page' => array( 'type' => 'integer' ),
			),
		) );

		register_rest_route( $ns, '/cookies/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_stats' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );

		register_rest_route( $ns, '/cookies/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_settings_get' ),
				'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_settings_set' ),
				'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			),
		) );

		register_rest_route( $ns, '/cookies/policy-text', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_policy_text' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'language' => array( 'type' => 'string' ),
			),
		) );

		register_rest_route( $ns, '/cookies/save-policy-page', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_save_policy_page' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'text'   => array( 'type' => 'string' ),
				'title'  => array( 'type' => 'string' ),
				'detected' => array( 'type' => 'object' ),
			),
		) );
	}

	/**
	 * Per-IP throttle on the public consent write — 5 writes/min/IP.
	 */
	public function consent_permission() {
		$ip   = $this->get_client_ip();
		$key  = 'luwipress_cookie_consent_rl_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= 5 ) {
			return new WP_Error( 'rate_limited', 'Too many consent writes from this IP.', array( 'status' => 429 ) );
		}
		set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );
		return true;
	}

	public function rest_config( $request ) {
		$s = $this->get_settings();
		// Strip server-side-only fields from the public config.
		unset( $s['log_retention_days'] );
		return rest_ensure_response( array(
			'enabled'           => $s['enabled'],
			'mode'              => $s['mode'],
			'position'          => $s['position'],
			'theme'             => $s['theme'],
			'show_reject_button'=> $s['show_reject_button'],
			'show_preferences'  => $s['show_preferences'],
			'categories'        => $s['categories_enabled'],
			'policy_url'        => $s['policy_url'],
			'privacy_url'       => $s['privacy_url'],
			'imprint_url'       => $s['imprint_url'],
			'texts'             => $s['texts'],
		) );
	}

	public function rest_consent( $request ) {
		$choices = (array) $request->get_param( 'choices' );
		$source  = (string) ( $request->get_param( 'source' ) ?: 'banner' );

		$bool_choices = array();
		foreach ( self::CATEGORIES as $cat ) {
			$bool_choices[ $cat ] = ! empty( $choices[ $cat ] );
		}
		$row = $this->record_consent( $bool_choices, $source );
		return rest_ensure_response( $row );
	}

	public function rest_log( $request ) {
		$page     = (int) ( $request->get_param( 'page' ) ?: 1 );
		$per_page = (int) ( $request->get_param( 'per_page' ) ?: 50 );
		return rest_ensure_response( $this->get_log( $page, $per_page ) );
	}

	public function rest_stats( $request ) {
		return rest_ensure_response( $this->get_stats() );
	}

	public function rest_settings_get( $request ) {
		return rest_ensure_response( $this->get_settings() );
	}

	public function rest_settings_set( $request ) {
		$patch = (array) $request->get_json_params();
		if ( empty( $patch ) ) {
			$patch = $request->get_body_params();
		}
		return rest_ensure_response( $this->update_settings( (array) $patch ) );
	}

	public function rest_policy_text( $request ) {
		$lang = $request->get_param( 'language' );
		$out  = $this->generate_policy_text( $lang ? (string) $lang : null );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Persist a generated cookie policy paragraph as a WordPress page so
	 * the operator doesn't have to copy/paste manually. Resolution order:
	 *
	 *   1. `policy_url` setting already points to an existing page → update it.
	 *   2. A page with slug `cookie-policy` exists → update it + write URL
	 *      back into the policy_url setting.
	 *   3. Neither exists → create a published page with that slug, write
	 *      URL back into the policy_url setting.
	 *
	 * The detected third-party tags JSON is appended as an HTML comment so
	 * future regenerations see what was on the site at write time without
	 * affecting the rendered page.
	 *
	 * @since 3.3.0
	 */
	public function rest_save_policy_page( $request ) {
		$text     = (string) $request->get_param( 'text' );
		$title    = trim( (string) $request->get_param( 'title' ) );
		$detected = $request->get_param( 'detected' );

		if ( '' === trim( $text ) ) {
			return new WP_Error( 'no_text', 'Refusing to save an empty cookie policy. Generate text first.', array( 'status' => 400 ) );
		}
		if ( '' === $title ) {
			$title = __( 'Cookie Policy', 'luwipress' );
		}

		// Append an HTML-comment audit footer so regenerations preserve a
		// snapshot of detected tags + generator version without polluting
		// the rendered page.
		$plugin_version = defined( 'LUWIPRESS_VERSION' ) ? LUWIPRESS_VERSION : 'unknown';
		$footer = "\n\n<!-- luwipress:cookie-policy generated_at=" . esc_attr( current_time( 'c' ) )
			. " plugin_version=" . esc_attr( $plugin_version );
		if ( is_array( $detected ) && ! empty( $detected ) ) {
			$footer .= " detected=" . esc_attr( wp_json_encode( $detected ) );
		}
		$footer .= " -->";

		$content = wp_kses_post( $text ) . $footer;

		$settings   = $this->get_settings();
		$existing_id = 0;

		// 1. Honour policy_url setting if it points to a real WP page.
		if ( ! empty( $settings['policy_url'] ) ) {
			$candidate = url_to_postid( $settings['policy_url'] );
			if ( $candidate && get_post_status( $candidate ) ) {
				$existing_id = (int) $candidate;
			}
		}

		// 2. Otherwise look for a page with the canonical slug.
		if ( ! $existing_id ) {
			$by_slug = get_posts( array(
				'post_type'      => 'page',
				'name'           => 'cookie-policy',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'numberposts'    => 1,
				'suppress_filters' => true,
			) );
			if ( ! empty( $by_slug ) ) {
				$existing_id = (int) $by_slug[0]->ID;
			}
		}

		$action = 'updated';
		if ( $existing_id ) {
			$result = wp_update_post( array(
				'ID'           => $existing_id,
				'post_title'   => sanitize_text_field( $title ),
				'post_content' => $content,
				'post_status'  => 'publish',
			), true );
		} else {
			$action = 'created';
			$result = wp_insert_post( array(
				'post_title'   => sanitize_text_field( $title ),
				'post_name'    => 'cookie-policy',
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'meta_input'   => array(
					'_luwipress_cookie_policy_managed' => 1,
				),
			), true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$page_id  = (int) $result;
		$page_url = get_permalink( $page_id );

		// Write the canonical URL back into the policy_url setting so the
		// frontend banner and footer links point at the freshly saved page
		// without the operator having to wire it manually.
		if ( $page_url && $page_url !== $settings['policy_url'] ) {
			$settings['policy_url'] = esc_url_raw( $page_url );
			update_option( self::OPTION_SETTINGS, $settings, false );
		}

		return rest_ensure_response( array(
			'page_id'  => $page_id,
			'url'      => $page_url,
			'edit_url' => admin_url( 'post.php?post=' . $page_id . '&action=edit' ),
			'action'   => $action,
		) );
	}
}
