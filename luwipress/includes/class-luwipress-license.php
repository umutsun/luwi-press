<?php
/**
 * LuwiPress License Manager
 *
 * Per-site license activation, tier/entitlement resolution, and a daily
 * heartbeat against the self-hosted luwi.dev license server. This is the
 * backbone of the tiered product family (Starter / Pro / Studio / Marketplace
 * / Enterprise): every core module and companion plugin queries the static
 * capability gate (`LuwiPress_License::can()` / `::tier()` / `::is_active()`).
 *
 * PHASE 1 (this release) is INFORMATIONAL ONLY — it activates, validates, and
 * surfaces status, but nothing is gated or blocked yet. The hard-block
 * enforcement (`is_blocked()` consumers) and licensed auto-update arrive in
 * later phases. The gate API is defined now so the contract is stable.
 *
 * Signing model (see CLAUDE.md license plan):
 *  - Server -> plugin responses are verified with an ASYMMETRIC Ed25519 public
 *    key shipped as a constant. A shared symmetric secret inside a GPL ZIP is
 *    trivially extractable and would let a cracker forge "active" verdicts, so
 *    we never ship one.
 *  - Plugin -> server requests (the heartbeat) are HMAC-signed with the
 *    per-activation secret the server hands back at activation time, reusing
 *    LuwiPress_HMAC (timestamp + 5-min replay window). The existing per-site
 *    luwipress_hmac_secret (webhook secret) is intentionally NOT reused.
 *
 * @package    LuwiPress
 * @subpackage License
 * @since      3.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_License {

	/**
	 * Singleton instance.
	 *
	 * @var LuwiPress_License|null
	 */
	private static $instance = null;

	/**
	 * Option holding the full license state (single serialized array).
	 *
	 * @var string
	 */
	const OPTION_KEY = 'luwipress_license';

	/**
	 * Option holding the stable per-site fingerprint (survives deactivation so
	 * a reactivating site is recognised as the same install, not a new domain).
	 *
	 * @var string
	 */
	const FP_OPTION = 'luwipress_license_fingerprint';

	/**
	 * Option holding the one-time random salt mixed into the fingerprint.
	 *
	 * @var string
	 */
	const FP_SALT_OPTION = 'luwipress_license_fp_salt';

	/**
	 * Cron hook for the daily heartbeat.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'luwipress_license_heartbeat';

	/**
	 * REST routes that stay reachable even when the plugin is hard-blocked.
	 * These are the activation surface (so the operator can always fix the key)
	 * plus the two truly public status routes. Mirrors the $public_routes
	 * pattern in LuwiPress_Security.
	 *
	 * @var string[]
	 */
	const UNBLOCKED_ROUTES = array(
		'/luwipress/v1/license/status',
		'/luwipress/v1/license/activate',
		'/luwipress/v1/license/deactivate',
		'/luwipress/v1/license/refresh',
		'/luwipress/v1/license/settings',
		'/luwipress/v1/status',
		'/luwipress/v1/health',
	);

	/**
	 * Admin page slugs that always render even when blocked — the dashboard
	 * (shows the unlicensed state + CTA) and Settings (hosts the License tab).
	 *
	 * @var string[]
	 */
	const UNBLOCKED_ADMIN_PAGES = array(
		'luwipress',
		'luwipress-settings',
	);

	/**
	 * Days of "trust without reconfirmation" granted by a successful active
	 * verdict. As long as the heartbeat keeps succeeding, grace is always ~14
	 * days ahead, so a paying site is never within 14 days of a block from a
	 * transient server outage.
	 *
	 * @var int
	 */
	const GRACE_DAYS = 14;

	/**
	 * Starting tier -> entitlement matrix. The server's signed `entitlements`
	 * array (when present) is authoritative and overrides this, so one-off
	 * grants don't require a plugin update. Tune these as the packaging plan
	 * firms up (CLAUDE.md "modular ecosystem packaging").
	 *
	 * @var array<string,string[]>
	 */
	const TIER_MATRIX = array(
		'none'        => array(),
		'starter'     => array( 'auto_update' ),
		'pro'         => array( 'auto_update', 'webmcp', 'priority_support' ),
		'studio'      => array( 'auto_update', 'webmcp', 'agentic', 'priority_support' ),
		'marketplace' => array( 'auto_update', 'webmcp', 'agentic', 'marketplace', 'priority_support' ),
		'enterprise'  => array( 'auto_update', 'webmcp', 'agentic', 'marketplace', 'priority_support', 'white_label' ),
	);

	/**
	 * Per-request memo of the blocked verdict (Phase 2 will consume it).
	 *
	 * @var bool|null
	 */
	private $blocked_memo = null;

	/**
	 * Per-request memo of the installed managed-plugin map (get_plugins() scans
	 * the filesystem — don't repeat it per filter pass).
	 *
	 * @var array<string,array{basename:string,version:string,name:string}>|null
	 */
	private $managed_plugins_memo = null;

	/**
	 * Per-request memo of the installed managed-theme map.
	 *
	 * @var array<string,array{version:string,name:string}>|null
	 */
	private $managed_themes_memo = null;

	/**
	 * Get the singleton.
	 *
	 * @return LuwiPress_License
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
		add_action( self::CRON_HOOK, array( $this, 'heartbeat' ) );

		// Self-heal the schedule. Upgrading by overwriting the ZIP does NOT fire
		// the activation hook, so we cannot rely on activate() alone to keep the
		// daily cron alive across updates. Cheap guard, admin-only.
		add_action( 'admin_init', array( $this, 'maybe_schedule_heartbeat' ) );

		// Settings page: register the License tab nav + content through the
		// existing companion-extension hooks (no settings-page.php edit needed).
		add_action( 'luwipress_settings_render_tab_nav', array( $this, 'render_tab_nav' ) );
		add_action( 'luwipress_settings_render_tab_content', array( $this, 'render_tab_content' ) );

		// Dashboard status ribbon pill.
		add_filter( 'luwipress_dashboard_pills', array( $this, 'dashboard_pill' ) );

		// ── Phase 2: hard-block enforcement (no-ops unless enforcement is
		// enabled AND the license is inactive — default OFF, see
		// enforcement_enabled()). Two surfaces: REST feature routes return 402,
		// and locked admin feature pages redirect to the License tab. The AI
		// engine gates itself by calling is_blocked() inside dispatch().
		add_filter( 'rest_pre_dispatch', array( $this, 'gate_rest_request' ), 5, 3 );
		add_action( 'admin_init', array( $this, 'gate_admin_pages' ) );

		// ── Phase 3: licensed auto-update. The luwi-family packages are not on
		// WP.org, so we teach WP's updater to pull the manifest + signed/expiring
		// ZIP from the luwi.dev license server. ONE updater here serves EVERY
		// installed luwi package — the core plugin, the companion plugins
		// (WebMCP / Agentic / Marketplace), and the luwi themes — each queried
		// per-slug against the same `/update` endpoint. Gated on the `auto_update`
		// entitlement + an activated key; the server is the final authority
		// (returns version:null when the site isn't entitled or there's nothing
		// newer). Companions/themes need NO updater of their own — core is the
		// single license + update authority they already hard-depend on.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_update_transient' ) );
		add_filter( 'pre_set_site_transient_update_themes', array( $this, 'filter_theme_update_transient' ) );
		add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 10, 3 );
		add_filter( 'themes_api', array( $this, 'filter_themes_api' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_package_download' ), 10, 4 );
		// "Check again" (update-core.php?force-check=1) must mean a REAL re-check:
		// WP deletes its own update transients but our per-slug manifest cache
		// (6h) would still answer stale — drop it so the refetch hits the server.
		add_action( 'load-update-core.php', array( $this, 'maybe_flush_update_cache_on_force_check' ) );
	}

	// ------------------------------------------------------------------
	// Configuration (all overridable for staging / self-host flexibility)
	// ------------------------------------------------------------------

	/**
	 * License server base URL (no trailing slash). Override with the
	 * LUWIPRESS_LICENSE_API constant (wp-config) or the filter.
	 *
	 * @return string
	 */
	public static function api_base() {
		$base = defined( 'LUWIPRESS_LICENSE_API' ) ? LUWIPRESS_LICENSE_API : 'https://luwi.dev/license/v1';
		$base = apply_filters( 'luwipress_license_api_base', $base );
		return rtrim( (string) $base, '/' );
	}

	/**
	 * Base64-encoded Ed25519 public key used to verify server responses.
	 * Ship the real key as the LUWIPRESS_LICENSE_PUBKEY constant (baked into a
	 * release) or inject via the filter. Empty until the keypair is provisioned
	 * — while empty, responses are accepted unverified (safe in Phase 1 because
	 * nothing is gated; Phase 2 enforcement will require a configured key).
	 *
	 * @return string
	 */
	public static function pubkey() {
		$key = defined( 'LUWIPRESS_LICENSE_PUBKEY' ) ? LUWIPRESS_LICENSE_PUBKEY : '';
		return (string) apply_filters( 'luwipress_license_pubkey', $key );
	}

	// ------------------------------------------------------------------
	// State storage
	// ------------------------------------------------------------------

	/**
	 * Default license state shape.
	 *
	 * @return array<string,mixed>
	 */
	private static function defaults() {
		return array(
			'key'               => '',
			'status'            => 'inactive', // inactive|active|expired|invalid|revoked
			'tier'              => 'none',
			'entitlements'      => array(),
			'expires'           => null,       // ISO-8601 string or null (perpetual)
			'activation_id'     => '',
			'activation_secret' => '',
			'max_activations'   => 0,
			'last_check'        => 0,          // unix ts of last SUCCESSFUL trusted verdict
			'last_attempt'      => 0,          // unix ts of last heartbeat attempt (success or fail)
			'grace_until'       => 0,          // unix ts; trust-without-reconfirmation horizon
			'last_error'        => '',
			'last_verdict'      => null,       // raw last trusted verdict (offline display)
		);
	}

	/**
	 * Read the full license state (merged with defaults).
	 *
	 * @return array<string,mixed>
	 */
	public static function get_state() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Persist a partial state update.
	 *
	 * @param array<string,mixed> $patch
	 * @return array<string,mixed> The new full state.
	 */
	private static function update_state( array $patch ) {
		$state = array_merge( self::get_state(), $patch );
		update_option( self::OPTION_KEY, $state, false ); // autoload=no — holds the activation secret.
		return $state;
	}

	/**
	 * Stable per-site fingerprint. Generated once and persisted. The server's
	 * per-domain activation limit keys on the normalised home_url host; the
	 * fingerprint is a secondary signal to tell "same DB + same domain
	 * reactivation" from a genuinely new install.
	 *
	 * @return string
	 */
	public static function fingerprint() {
		$fp = get_option( self::FP_OPTION, '' );
		if ( ! empty( $fp ) ) {
			return (string) $fp;
		}
		$salt = get_option( self::FP_SALT_OPTION, '' );
		if ( empty( $salt ) ) {
			$salt = wp_generate_password( 32, false, false );
			update_option( self::FP_SALT_OPTION, $salt, false );
		}
		$fp = hash( 'sha256', home_url() . '|' . get_current_blog_id() . '|' . $salt );
		update_option( self::FP_OPTION, $fp, false );
		return $fp;
	}

	// ------------------------------------------------------------------
	// Capability gate API (stable contract for modules + companions)
	// ------------------------------------------------------------------

	/**
	 * Is the license currently usable? True when the last trusted verdict was
	 * "active" AND we are still inside the grace horizon (so a 14-day server
	 * outage after a healthy activation does not flip this to false).
	 *
	 * @return bool
	 */
	public static function is_active() {
		$s = self::get_state();
		return 'active' === $s['status'] && time() <= (int) $s['grace_until'];
	}

	/**
	 * Running on a cached verdict because the server has not confirmed recently
	 * (used purely for the dashboard pill copy). Active, but last trusted check
	 * is stale.
	 *
	 * @return bool
	 */
	public static function in_grace() {
		if ( ! self::is_active() ) {
			return false;
		}
		$s = self::get_state();
		return ( time() - (int) $s['last_check'] ) > ( 2 * DAY_IN_SECONDS );
	}

	/**
	 * Raw status string for UI.
	 *
	 * @return string
	 */
	public static function status() {
		$s = self::get_state();
		// An "active" record whose grace lapsed reads as expired-trust to the UI.
		if ( 'active' === $s['status'] && time() > (int) $s['grace_until'] ) {
			return 'stale';
		}
		return (string) $s['status'];
	}

	/**
	 * Resolved tier slug (or 'none').
	 *
	 * @return string
	 */
	public static function tier() {
		if ( ! self::is_active() ) {
			return 'none';
		}
		$tier = (string) self::get_state()['tier'];
		return '' === $tier ? 'none' : $tier;
	}

	/**
	 * Resolved entitlements for the active license: the server's signed list
	 * when present, otherwise the local tier matrix.
	 *
	 * @return string[]
	 */
	public static function entitlements() {
		if ( ! self::is_active() ) {
			return array();
		}
		$s = self::get_state();
		$ents = isset( $s['entitlements'] ) && is_array( $s['entitlements'] ) ? $s['entitlements'] : array();
		if ( ! empty( $ents ) ) {
			return array_values( array_map( 'strval', $ents ) );
		}
		$tier = self::tier();
		return isset( self::TIER_MATRIX[ $tier ] ) ? self::TIER_MATRIX[ $tier ] : array();
	}

	/**
	 * Does the active license grant a given feature/entitlement?
	 *
	 * @param string $feature
	 * @return bool
	 */
	public static function can( $feature ) {
		if ( ! self::is_active() ) {
			return false;
		}
		return in_array( (string) $feature, self::entitlements(), true );
	}

	/**
	 * Companion-facing feature gate. Returns true when a feature should be
	 * AVAILABLE right now.
	 *
	 * Key invariant: when enforcement is OFF (the default — including on our own
	 * sites and every pre-distribution build), NOTHING is restricted. Every
	 * feature is available regardless of license state, so shipping the plugin
	 * never silently downgrades a site. Only once enforcement is ON does the
	 * entitlement actually gate the feature.
	 *
	 * Companions call this behind a forward-compat guard so they keep working
	 * against an older, license-unaware core:
	 *   $pro = ! class_exists( 'LuwiPress_License' )
	 *        || LuwiPress_License::feature_enabled( 'webmcp' );
	 *
	 * @param string $feature
	 * @return bool
	 */
	public static function feature_enabled( $feature ) {
		if ( ! self::enforcement_enabled() ) {
			return true; // enforcement off -> never restrict.
		}
		return self::can( (string) $feature );
	}

	/**
	 * Expiry as a unix timestamp, or null for perpetual / unknown.
	 *
	 * @return int|null
	 */
	public static function expires_at() {
		$exp = self::get_state()['expires'];
		if ( empty( $exp ) ) {
			return null;
		}
		$ts = strtotime( (string) $exp );
		return $ts ? $ts : null;
	}

	/**
	 * Is hard-block enforcement turned on for this install?
	 *
	 * DEFAULT OFF. This is the master safety switch: shipping the plugin (even
	 * to our own sites) never bricks anything until enforcement is deliberately
	 * enabled. Resolution order:
	 *   1. LUWIPRESS_LICENSE_ENFORCE constant — baked into the CodeCanyon build
	 *      (cannot be toggled off from the admin UI).
	 *   2. luwipress_license_enforce option — operator toggle (default false).
	 *   3. luwipress_license_enforce filter — programmatic override.
	 * Finally, a trusted server verdict carrying `"enforce": false` acts as a
	 * remote KILL SWITCH for already-activated sites (disable enforcement
	 * fleet-wide if it ever misfires in the wild).
	 *
	 * @return bool
	 */
	public static function enforcement_enabled() {
		if ( defined( 'LUWIPRESS_LICENSE_ENFORCE' ) ) {
			$on = (bool) LUWIPRESS_LICENSE_ENFORCE;
		} else {
			$on = (bool) get_option( 'luwipress_license_enforce', false );
		}
		$on = (bool) apply_filters( 'luwipress_license_enforce', $on );
		if ( ! $on ) {
			return false;
		}

		// Remote kill switch from a trusted verdict.
		$verdict = self::get_state()['last_verdict'];
		if ( is_array( $verdict ) && array_key_exists( 'enforce', $verdict ) && false === $verdict['enforce'] ) {
			return false;
		}
		return true;
	}

	/**
	 * True when a signature is REQUIRED but this host cannot verify one
	 * (libsodium missing). In that case we cannot fairly validate the license,
	 * so enforcement must fail OPEN — never block a host we can't serve.
	 *
	 * @return bool
	 */
	private static function cannot_verify_here() {
		$required = (bool) apply_filters( 'luwipress_license_require_signature', '' !== self::pubkey() );
		return $required && ! function_exists( 'sodium_crypto_sign_verify_detached' );
	}

	/**
	 * Should plugin features be blocked right now? Memoised per request.
	 *
	 * Blocks only when enforcement is enabled AND the license is not active AND
	 * the host is capable of verifying signatures. The grace horizon inside
	 * is_active() means a transient server outage after a healthy activation
	 * never flips this to true.
	 *
	 * @return bool
	 */
	public static function is_blocked() {
		$self = self::get_instance();
		if ( null !== $self->blocked_memo ) {
			return $self->blocked_memo;
		}
		$self->blocked_memo = self::enforcement_enabled()
			&& ! self::cannot_verify_here()
			&& ! self::is_active();
		return $self->blocked_memo;
	}

	/**
	 * Standard WP_Error returned by the REST + feature gates when blocked.
	 *
	 * @return WP_Error
	 */
	public static function blocked_error() {
		return new WP_Error(
			'luwipress_license_required',
			__( 'A valid LuwiPress license is required to use this feature. Activate your license under LuwiPress → Settings → License.', 'luwipress' ),
			array(
				'status'         => 402,
				'license_status' => self::status(),
				'activation_url' => add_query_arg(
					array( 'page' => 'luwipress-settings', 'tab' => 'license' ),
					admin_url( 'admin.php' )
				),
			)
		);
	}

	// ------------------------------------------------------------------
	// REST API
	// ------------------------------------------------------------------

	public function register_endpoints() {
		register_rest_route( 'luwipress/v1', '/license/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_status' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );

		register_rest_route( 'luwipress/v1', '/license/activate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_activate' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'is_admin' ),
			'args'                => array(
				'key' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'luwipress/v1', '/license/deactivate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_deactivate' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'is_admin' ),
		) );

		register_rest_route( 'luwipress/v1', '/license/refresh', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_refresh' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'is_admin' ),
		) );

		// Conventional partial-update settings endpoint. GET returns status;
		// POST persists prefs (the enforcement toggle). The key itself never
		// flows through here — it goes through /activate so the server
		// round-trip + signing run.
		register_rest_route( 'luwipress/v1', '/license/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_save_settings' ),
				'permission_callback' => array( 'LuwiPress_Permission', 'is_admin' ),
				'args'                => array(
					'enforce' => array( 'type' => 'boolean' ),
				),
			),
		) );
	}

	/**
	 * Persist license display/enforcement preferences.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_save_settings( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		if ( array_key_exists( 'enforce', $params ) ) {
			if ( defined( 'LUWIPRESS_LICENSE_ENFORCE' ) ) {
				return new WP_Error(
					'luwipress_enforce_locked',
					__( 'Enforcement is fixed by the LUWIPRESS_LICENSE_ENFORCE site constant and cannot be changed here.', 'luwipress' ),
					array( 'status' => 409 )
				);
			}
			update_option( 'luwipress_license_enforce', (bool) $params['enforce'] );
			self::get_instance()->blocked_memo = null; // toggle changes blocked-ness.
		}

		return $this->rest_status();
	}

	/**
	 * Public-facing status snapshot. Never returns the raw key or activation
	 * secret. Pure cache read — does not call the server.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_status() {
		$s = self::get_state();
		return rest_ensure_response( array(
			'status'         => self::status(),
			'is_active'      => self::is_active(),
			'in_grace'       => self::in_grace(),
			'tier'           => self::tier(),
			'entitlements'   => self::entitlements(),
			'expires'        => $s['expires'],
			'key_hint'       => self::mask_key( $s['key'] ),
			'site_url'       => home_url(),
			'fingerprint'    => self::fingerprint(),
			'last_check'     => (int) $s['last_check'] ? gmdate( 'c', (int) $s['last_check'] ) : null,
			'grace_until'    => (int) $s['grace_until'] ? gmdate( 'c', (int) $s['grace_until'] ) : null,
			'last_error'     => (string) $s['last_error'],
			'server'         => self::api_base(),
			'enforce'        => self::enforcement_enabled(),
			'enforce_locked' => defined( 'LUWIPRESS_LICENSE_ENFORCE' ),
			'is_blocked'     => self::is_blocked(),
		) );
	}

	/**
	 * Activate a license key against the server.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_activate( $request ) {
		$key = trim( (string) $request->get_param( 'key' ) );
		if ( '' === $key ) {
			return new WP_Error( 'luwipress_license_no_key', __( 'A license key is required.', 'luwipress' ), array( 'status' => 400 ) );
		}
		if ( ! self::looks_like_key( $key ) ) {
			return new WP_Error( 'luwipress_license_bad_format', __( 'That does not look like a LuwiPress license key (lwp_…) or a CodeCanyon purchase code.', 'luwipress' ), array( 'status' => 400 ) );
		}

		$verdict = $this->server_request( '/activate', array(
			'key'            => $key,
			'fingerprint'    => self::fingerprint(),
			'site_url'       => home_url(),
			'plugin_version' => defined( 'LUWIPRESS_VERSION' ) ? LUWIPRESS_VERSION : '',
		) );

		if ( is_wp_error( $verdict ) ) {
			self::update_state( array(
				'last_attempt' => time(),
				'last_error'   => $verdict->get_error_message(),
			) );
			return $verdict;
		}

		$state = $this->apply_verdict( $verdict, $key );
		$this->log( 'License activated: status=' . $state['status'] . ' tier=' . $state['tier'], 'info' );

		return $this->rest_status();
	}

	/**
	 * Deactivate locally and free the server-side slot.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_deactivate() {
		$s = self::get_state();

		if ( ! empty( $s['activation_id'] ) ) {
			// Best-effort — even if the server is unreachable we still clear local
			// state so the operator can re-key. The slot is reclaimed on the
			// server's next sweep of stale activations.
			$this->server_request( '/deactivate', array(
				'key'           => $s['key'],
				'fingerprint'   => self::fingerprint(),
				'activation_id' => $s['activation_id'],
			), true );
		}

		update_option( self::OPTION_KEY, self::defaults(), false );
		$this->blocked_memo = null; // state changed — drop the per-request memo.
		$this->log( 'License deactivated', 'info' );

		return $this->rest_status();
	}

	/**
	 * Force an immediate heartbeat (the "Re-check" button).
	 *
	 * @return WP_REST_Response
	 */
	public function rest_refresh() {
		$this->heartbeat();
		return $this->rest_status();
	}

	// ------------------------------------------------------------------
	// Server communication
	// ------------------------------------------------------------------

	/**
	 * POST a JSON body to the license server and return a verified verdict
	 * array, or WP_Error on transport / signature / server failure.
	 *
	 * @param string              $path  e.g. '/activate'
	 * @param array<string,mixed> $body
	 * @param bool                $sign  Sign the request with the activation
	 *                                   secret (heartbeat / deactivate).
	 * @return array<string,mixed>|WP_Error
	 */
	private function server_request( $path, array $body, $sign = false ) {
		$url     = self::api_base() . $path;
		$payload = wp_json_encode( $body );

		$headers = array( 'Content-Type' => 'application/json' );
		if ( $sign ) {
			$secret = (string) self::get_state()['activation_secret'];
			if ( '' !== $secret ) {
				LuwiPress_HMAC::add_signature_headers( $headers, (string) $payload, $secret );
			}
		}

		$response = wp_remote_post( $url, array(
			'headers' => $headers,
			'body'    => $payload,
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'luwipress_license_unreachable',
				sprintf( __( 'License server unreachable: %s', 'luwipress' ), $response->get_error_message() ),
				array( 'status' => 503, 'retryable' => true )
			);
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$body_raw = wp_remote_retrieve_body( $response );
		$sig      = wp_remote_retrieve_header( $response, 'x-luwipress-license-sig' );
		$data     = json_decode( $body_raw, true );

		if ( $code >= 400 ) {
			$msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : __( 'License request rejected.', 'luwipress' );
			// 4xx with a parseable, signed body is an AUTHORITATIVE negative
			// (invalid / limit_exceeded / revoked) — surface the verdict so the
			// caller can apply it, not just bubble an error.
			if ( is_array( $data ) && $this->signature_ok( $body_raw, $sig ) ) {
				$data['_http_code'] = (int) $code;
				return $data;
			}
			return new WP_Error( 'luwipress_license_rejected', $msg, array( 'status' => (int) $code ) );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'luwipress_license_bad_response', __( 'License server returned an unreadable response.', 'luwipress' ), array( 'status' => 502 ) );
		}

		if ( ! $this->signature_ok( $body_raw, $sig ) ) {
			// A response we cannot trust is treated exactly like unreachable —
			// we never act on an unverified verdict.
			return new WP_Error( 'luwipress_license_unsigned', __( 'License response failed signature verification.', 'luwipress' ), array( 'status' => 502 ) );
		}

		return $data;
	}

	/**
	 * Verify the Ed25519 signature of a server response body.
	 *
	 * Returns true when verified. When no public key is configured yet (pre
	 * key-provisioning), signature checking is skipped unless the
	 * `luwipress_license_require_signature` filter forces it — safe in Phase 1
	 * because nothing is gated.
	 *
	 * @param string $message Raw response body (the signed message).
	 * @param mixed  $sig_b64 Base64 signature from the response header.
	 * @return bool
	 */
	private function signature_ok( $message, $sig_b64 ) {
		$pub      = self::pubkey();
		$required = (bool) apply_filters( 'luwipress_license_require_signature', '' !== $pub );

		if ( ! $required ) {
			return true; // dev / pre-keypair: accept unverified.
		}
		if ( '' === $pub || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false; // required but unverifiable -> reject.
		}

		$pub_raw = base64_decode( $pub, true );
		$sig_raw = base64_decode( (string) $sig_b64, true );
		if ( false === $pub_raw || false === $sig_raw || 32 !== strlen( $pub_raw ) || 64 !== strlen( $sig_raw ) ) {
			return false;
		}

		try {
			return sodium_crypto_sign_verify_detached( $sig_raw, (string) $message, $pub_raw );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Apply a trusted verdict to local state and set the grace horizon.
	 *
	 * @param array<string,mixed> $verdict
	 * @param string|null         $key  Newly-entered key (activation only).
	 * @return array<string,mixed> New state.
	 */
	private function apply_verdict( array $verdict, $key = null ) {
		$status = isset( $verdict['status'] ) ? (string) $verdict['status'] : 'invalid';
		$now    = time();

		$patch = array(
			'status'       => $status,
			'tier'         => isset( $verdict['tier'] ) ? (string) $verdict['tier'] : 'none',
			'entitlements' => isset( $verdict['entitlements'] ) && is_array( $verdict['entitlements'] ) ? array_values( array_map( 'strval', $verdict['entitlements'] ) ) : array(),
			'expires'      => isset( $verdict['expires'] ) ? $verdict['expires'] : null,
			'last_attempt' => $now,
			'last_error'   => '',
			'last_verdict' => $verdict,
		);

		if ( null !== $key ) {
			$patch['key'] = $key;
		}
		if ( isset( $verdict['activation_id'] ) ) {
			$patch['activation_id'] = (string) $verdict['activation_id'];
		}
		if ( isset( $verdict['activation_secret'] ) ) {
			$patch['activation_secret'] = (string) $verdict['activation_secret'];
		}
		if ( isset( $verdict['max_activations'] ) ) {
			$patch['max_activations'] = (int) $verdict['max_activations'];
		}

		if ( 'active' === $status ) {
			// Healthy verdict: refresh trust + push the grace horizon forward.
			$patch['last_check']  = $now;
			$patch['grace_until'] = $now + ( self::GRACE_DAYS * DAY_IN_SECONDS );
		} else {
			// Authoritative negative (expired / revoked / invalid / limit_exceeded)
			// -> clear grace immediately so the block engages now, not in 14 days.
			$patch['grace_until'] = 0;
		}

		$this->blocked_memo = null; // state changed — drop the per-request memo.
		$this->flush_update_cache(); // tier/status may have changed entitlement.
		return self::update_state( $patch );
	}

	// ------------------------------------------------------------------
	// Heartbeat (daily cron)
	// ------------------------------------------------------------------

	/**
	 * Ensure the daily heartbeat is scheduled. Self-heals after a ZIP-overwrite
	 * upgrade (which does not fire the activation hook).
	 */
	public function maybe_schedule_heartbeat() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Daily validation. Crucially, network / signature failures are NON
	 * destructive: we keep the last good verdict and let the grace horizon (set
	 * by the last successful check) decide when trust lapses.
	 */
	public function heartbeat() {
		$s = self::get_state();

		// Nothing to validate until the site has activated.
		if ( empty( $s['key'] ) || empty( $s['activation_id'] ) ) {
			return;
		}

		$verdict = $this->server_request( '/validate', array(
			'key'            => $s['key'],
			'fingerprint'    => self::fingerprint(),
			'activation_id'  => $s['activation_id'],
			'site_url'       => home_url(),
			'plugin_version' => defined( 'LUWIPRESS_VERSION' ) ? LUWIPRESS_VERSION : '',
		), true );

		if ( is_wp_error( $verdict ) ) {
			// Unreachable / unsigned -> do NOT downgrade. Record the attempt only.
			self::update_state( array(
				'last_attempt' => time(),
				'last_error'   => $verdict->get_error_message(),
			) );
			return;
		}

		$this->apply_verdict( $verdict );
	}

	// ------------------------------------------------------------------
	// Admin UI — Settings tab (rendered via the existing extension hooks)
	// ------------------------------------------------------------------

	/**
	 * Echo the License tab nav link.
	 *
	 * @param string $active_tab
	 */
	public function render_tab_nav( $active_tab ) {
		$is_active = ( 'license' === $active_tab );
		printf(
			'<a href="%s" class="lp-hub-tab %s" role="tab" aria-selected="%s"><span class="dashicons dashicons-admin-network"></span><span>%s</span></a>',
			esc_url( add_query_arg( array( 'page' => 'luwipress-settings', 'tab' => 'license' ), admin_url( 'admin.php' ) ) ),
			$is_active ? 'lp-hub-tab--active' : '',
			$is_active ? 'true' : 'false',
			esc_html__( 'License', 'luwipress' )
		);
	}

	/**
	 * Echo the License tab content panel + its self-contained activation JS.
	 * Per the companion-extension contract, inputs carry no `name=` (not part of
	 * the main settings POST) — activation flows through the REST endpoints.
	 *
	 * @param string $active_tab
	 */
	public function render_tab_content( $active_tab ) {
		$s              = self::get_state();
		$status         = self::status();
		$active         = self::is_active();
		$tier           = self::tier();
		$enforce        = self::enforcement_enabled();
		$enforce_locked = defined( 'LUWIPRESS_LICENSE_ENFORCE' );
		$locked_page    = isset( $_GET['locked'] ) ? sanitize_text_field( wp_unslash( $_GET['locked'] ) ) : '';
		$rest_url       = esc_url_raw( rest_url( 'luwipress/v1/license' ) );
		$nonce          = wp_create_nonce( 'wp_rest' );

		$badge_class = $active ? 'pill-success' : ( in_array( $status, array( 'expired', 'revoked', 'invalid', 'stale' ), true ) ? 'pill-warning' : 'pill-neutral' );
		?>
		<div class="luwipress-tab-content <?php echo 'license' === $active_tab ? 'tab-active' : ''; ?>" id="tab-license">
			<?php if ( '' !== $locked_page ) : ?>
			<div class="luwipress-card luwipress-card--warning">
				<p><strong><?php esc_html_e( 'That feature is locked.', 'luwipress' ); ?></strong>
				<?php esc_html_e( 'Activate a valid license below to unlock LuwiPress features.', 'luwipress' ); ?></p>
			</div>
			<?php endif; ?>
			<div class="luwipress-card">
				<h2><?php esc_html_e( 'License', 'luwipress' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Activate your CodeCanyon purchase code or LuwiPress license key to unlock updates and premium features.', 'luwipress' ); ?>
				</p>

				<p>
					<span class="lp-pill <?php echo esc_attr( $badge_class ); ?>" id="lwp-license-badge">
						<?php echo esc_html( $active ? sprintf( __( 'Active — %s', 'luwipress' ), ucfirst( $tier ) ) : ucfirst( $status ) ); ?>
					</span>
					<?php if ( self::in_grace() ) : ?>
						<span class="lp-pill pill-info"><?php esc_html_e( 'Running on cached verdict (server not confirmed recently)', 'luwipress' ); ?></span>
					<?php endif; ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lwp-license-key"><?php esc_html_e( 'License key', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="lwp-license-key" class="regular-text" autocomplete="off"
								placeholder="<?php esc_attr_e( 'lwp_… license key or CodeCanyon purchase code', 'luwipress' ); ?>"
								value="<?php echo esc_attr( self::mask_key( $s['key'] ) ); ?>" />
							<p class="description">
								<?php
								echo esc_html( sprintf(
									/* translators: %s: this site's URL */
									__( 'This site (%s) will be bound to the license. Server: ', 'luwipress' ),
									home_url()
								) );
								echo '<code>' . esc_html( self::api_base() ) . '</code>';
								?>
							</p>
						</td>
					</tr>
					<?php if ( $active && ! empty( $s['expires'] ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Expires', 'luwipress' ); ?></th>
						<td><?php echo esc_html( (string) $s['expires'] ); ?></td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Licensing', 'luwipress' ); ?></th>
						<td>
							<?php if ( $enforce_locked ) : ?>
								<?php /* Distribution build: enforcement is a vendor decision baked into
									   the build, NOT a buyer-facing toggle. Show status, not a checkbox. */ ?>
								<span class="lp-pill <?php echo $active ? 'pill-success' : 'pill-warning'; ?>">
									<?php echo esc_html( $active ? __( 'Enforced — license active', 'luwipress' ) : __( 'Enforced — license required', 'luwipress' ) ); ?>
								</span>
								<p class="description">
									<?php esc_html_e( 'This is a licensed build: an active license is required to use plugin features. Enforcement is set by the build and cannot be turned off here.', 'luwipress' ); ?>
								</p>
							<?php else : ?>
								<label>
									<input type="checkbox" id="lwp-license-enforce" <?php checked( $enforce ); ?> />
									<?php esc_html_e( 'Block plugin features (REST, AI, admin tools) when no valid license is active.', 'luwipress' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Off by default — development convenience. Distributed builds enable enforcement automatically.', 'luwipress' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<p>
					<button type="button" class="button button-primary" id="lwp-license-activate"><?php esc_html_e( 'Activate', 'luwipress' ); ?></button>
					<button type="button" class="button" id="lwp-license-recheck"><?php esc_html_e( 'Re-check', 'luwipress' ); ?></button>
					<button type="button" class="button button-link-delete" id="lwp-license-deactivate"<?php echo $active ? '' : ' style="display:none"'; ?>><?php esc_html_e( 'Deactivate', 'luwipress' ); ?></button>
					<span class="spinner" id="lwp-license-spinner" style="float:none;"></span>
				</p>
				<p id="lwp-license-msg" class="description" aria-live="polite"></p>
			</div>
		</div>
		<script>
		(function(){
			var REST = <?php echo wp_json_encode( $rest_url ); ?>;
			var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
			var $ = function(id){ return document.getElementById(id); };
			var spinner = $('lwp-license-spinner'), msg = $('lwp-license-msg');
			function busy(on){ spinner.classList.toggle('is-active', !!on); }
			function say(t, ok){ msg.textContent = t || ''; msg.style.color = ok ? '' : '#b32d2e'; }
			function call(path, body, expectActive){
				busy(true); say('');
				return fetch(REST + path, {
					method: 'POST',
					headers: { 'Content-Type':'application/json', 'X-WP-Nonce': NONCE },
					body: body ? JSON.stringify(body) : null
				}).then(function(r){ return r.json().then(function(j){ return { ok:r.ok, j:j }; }); })
				.then(function(res){
					busy(false);
					if (!res.ok) { say((res.j && (res.j.message||res.j.code)) || 'Request failed', false); return; }
					// Activation honesty: HTTP 200 only means the server answered — it
					// does NOT mean the license became active. Refuse to report "Done"
					// when the activation did not take, so the operator isn't misled
					// into thinking an unlicensed site is licensed.
					if (expectActive && res.j && res.j.is_active === false) {
						var why = (res.j.last_error && String(res.j.last_error))
							|| 'Key was rejected. Check the key, its tier, and that it is not already bound to another site.';
						say('Activation failed: ' + why, false);
						return;
					}
					say('Done. Reloading…', true);
					setTimeout(function(){ location.reload(); }, 600);
				}).catch(function(e){ busy(false); say(String(e), false); });
			}
			$('lwp-license-activate').addEventListener('click', function(){
				var k = ($('lwp-license-key').value || '').trim();
				if (!k || k.indexOf('****') === 0) { say('Enter a license key.', false); return; }
				var fmt = /^lwp_[a-f0-9]{48}$/i.test(k) || /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(k);
				if (!fmt) { say('Enter a valid license key (lwp_…) or purchase code.', false); return; }
				call('/activate', { key: k }, true);
			});
			$('lwp-license-recheck').addEventListener('click', function(){ call('/refresh'); });
			$('lwp-license-deactivate').addEventListener('click', function(){
				if (!window.confirm('Deactivate this site’s license?')) return;
				call('/deactivate');
			});
			var enf = $('lwp-license-enforce');
			if (enf && !enf.disabled) {
				enf.addEventListener('change', function(){ call('/settings', { enforce: enf.checked }); });
			}
		})();
		</script>
		<?php
	}

	// ------------------------------------------------------------------
	// Dashboard status ribbon pill
	// ------------------------------------------------------------------

	/**
	 * Inject the license pill into the dashboard status ribbon.
	 *
	 * @param array<int,array> $pills
	 * @return array<int,array>
	 */
	public function dashboard_pill( $pills ) {
		$link = add_query_arg( array( 'page' => 'luwipress-settings', 'tab' => 'license' ), admin_url( 'admin.php' ) );

		if ( self::is_active() ) {
			if ( self::in_grace() ) {
				$pills[] = array( 'ok', 'dashicons-clock',
					__( 'License: grace', 'luwipress' ),
					__( 'Licensed, but the server has not confirmed recently — running on the cached verdict.', 'luwipress' ),
					$link );
			} else {
				$pills[] = array( 'ok', 'dashicons-yes-alt',
					sprintf( __( 'Licensed: %s', 'luwipress' ), ucfirst( self::tier() ) ),
					__( 'License is active.', 'luwipress' ),
					$link );
			}
		} else {
			$pills[] = array( 'err', 'dashicons-warning',
				__( 'Unlicensed', 'luwipress' ),
				__( 'No active license. Click to activate your purchase code or license key.', 'luwipress' ),
				$link );
		}

		return $pills;
	}

	// ------------------------------------------------------------------
	// Phase 2 — hard-block enforcement surfaces
	// ------------------------------------------------------------------

	/**
	 * REST chokepoint (rest_pre_dispatch, priority 5). Returns a 402 for every
	 * luwipress/v1/* feature route while blocked, except the activation +
	 * status allowlist. One guard covers all 60+ routes (and the WebMCP /mcp
	 * endpoint, which lives under our namespace) without touching any module.
	 *
	 * @param mixed            $result  Short-circuit value (null when not set).
	 * @param mixed            $server  REST server.
	 * @param WP_REST_Request  $request
	 * @return mixed
	 */
	public function gate_rest_request( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result; // another handler already short-circuited.
		}
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $result;
		}
		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/luwipress/v1/' ) ) {
			return $result; // not our namespace.
		}
		if ( in_array( $route, self::UNBLOCKED_ROUTES, true ) ) {
			return $result;
		}
		if ( ! self::is_blocked() ) {
			return $result;
		}
		return self::blocked_error();
	}

	/**
	 * Admin chokepoint (admin_init). While blocked, locked LuwiPress feature
	 * pages bounce to the License tab so the operator lands on activation
	 * instead of an empty/erroring feature screen. The dashboard + Settings
	 * always render. Fires before any output, so the redirect is safe.
	 */
	public function gate_admin_pages() {
		if ( ! isset( $_GET['page'] ) ) {
			return;
		}
		$page   = sanitize_text_field( wp_unslash( $_GET['page'] ) );
		$target = $this->admin_block_redirect( $page );
		if ( '' !== $target ) {
			wp_safe_redirect( $target );
			exit;
		}
	}

	/**
	 * Resolve the redirect target for a requested admin page while blocked, or
	 * '' when the page should render normally. Split out from gate_admin_pages
	 * so the decision is unit-testable without the redirect/exit.
	 *
	 * @param string $page The `page` query arg.
	 * @return string Redirect URL, or '' to allow.
	 */
	public function admin_block_redirect( $page ) {
		if ( ! self::is_blocked() ) {
			return '';
		}
		if ( 0 !== strpos( $page, 'luwipress' ) ) {
			return ''; // not one of our screens.
		}
		$allowed = apply_filters( 'luwipress_license_unblocked_admin_pages', self::UNBLOCKED_ADMIN_PAGES );
		if ( in_array( $page, (array) $allowed, true ) ) {
			return '';
		}
		return add_query_arg(
			array( 'page' => 'luwipress-settings', 'tab' => 'license', 'locked' => $page ),
			admin_url( 'admin.php' )
		);
	}

	// ------------------------------------------------------------------
	// Phase 3 — licensed auto-update (manifest + signed download)
	// ------------------------------------------------------------------

	// Core RELEASE slug on the license server. Renamed `luwipress` →
	// `luwipress-core` on 2026-06-10 to disambiguate the core plugin within the
	// ecosystem. The INSTALL folder stays `luwipress/` (plugin basename is the
	// plugin's identity in WP — renaming it would deactivate every existing
	// install on update), so the slug is purely the server-side release key.
	// Server keeps answering the legacy `luwipress` slug for ≤3.13.2 sites.
	const UPDATE_SLUG         = 'luwipress-core';
	const UPDATE_CACHE        = 'luwipress_update_manifest';   // legacy single-slug cache (flushed for back-compat).
	const UPDATE_CACHE_PREFIX = 'luwipress_upd_';              // + md5(slug) — per-package manifest cache.

	/**
	 * Plugin-list / update-screen icon set for every managed luwi package.
	 * Points at the core plugin's bundled logo (core is always installed +
	 * reachable for companions), so WP renders our brand mark on the Plugins
	 * screen + update list instead of the generic gray "plug" placeholder.
	 *
	 * @return array<string,string>
	 */
	private static function brand_icons() {
		$logo = defined( 'LUWIPRESS_PLUGIN_URL' ) ? LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo.png' : '';
		return array( '1x' => $logo, '2x' => $logo, 'default' => $logo );
	}

	/**
	 * The luwi-family PLUGINS this site updates, keyed by server slug, limited to
	 * those actually installed. The map (slug => plugin basename) is filterable
	 * so a future companion opts in without a core edit. Memoised per request —
	 * get_plugins() scans the filesystem.
	 *
	 * @return array<string,array{basename:string,version:string,name:string}>
	 */
	private function managed_plugins() {
		if ( null !== $this->managed_plugins_memo ) {
			return $this->managed_plugins_memo;
		}
		$map = array(
			self::UPDATE_SLUG       => defined( 'LUWIPRESS_PLUGIN_BASENAME' ) ? LUWIPRESS_PLUGIN_BASENAME : 'luwipress/luwipress.php',
			'luwipress-webmcp'      => 'luwipress-webmcp/luwipress-webmcp.php',
			'luwipress-agentic'     => 'luwipress-agentic/luwipress-agentic.php',
			'luwipress-marketplace' => 'luwipress-marketplace/luwipress-marketplace.php',
		);
		/** @var array<string,string> $map */
		$map = (array) apply_filters( 'luwipress_license_managed_plugins', $map );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();
		$out       = array();
		foreach ( $map as $slug => $basename ) {
			$basename = (string) $basename;
			if ( ! isset( $installed[ $basename ] ) ) {
				continue; // not installed on this site — nothing to update.
			}
			$out[ (string) $slug ] = array(
				'basename' => $basename,
				'version'  => isset( $installed[ $basename ]['Version'] ) ? (string) $installed[ $basename ]['Version'] : '0',
				'name'     => isset( $installed[ $basename ]['Name'] ) ? (string) $installed[ $basename ]['Name'] : (string) $slug,
			);
		}
		$this->managed_plugins_memo = $out;
		return $out;
	}

	/**
	 * The luwi THEMES this site updates, keyed by stylesheet (== server slug).
	 * Detected by the `luwipress-` stylesheet prefix or a `LuwiPress` author;
	 * filterable. Memoised per request.
	 *
	 * @return array<string,array{version:string,name:string}>
	 */
	private function managed_themes() {
		if ( null !== $this->managed_themes_memo ) {
			return $this->managed_themes_memo;
		}
		$out = array();
		if ( function_exists( 'wp_get_themes' ) ) {
			foreach ( wp_get_themes() as $stylesheet => $theme ) {
				$stylesheet = (string) $stylesheet;
				$author     = (string) $theme->get( 'Author' );
				$is_ours    = ( 0 === strpos( $stylesheet, 'luwipress-' ) ) || ( 'LuwiPress' === $author );
				if ( ! $is_ours ) {
					continue;
				}
				$out[ $stylesheet ] = array(
					'version' => (string) $theme->get( 'Version' ),
					'name'    => (string) $theme->get( 'Name' ),
				);
			}
		}
		/** @var array<string,array{version:string,name:string}> $out */
		$out = (array) apply_filters( 'luwipress_license_managed_themes', $out );
		$this->managed_themes_memo = $out;
		return $out;
	}

	/**
	 * Inject available updates into WP's plugin-update transient — one entry per
	 * installed luwi plugin (core + companions).
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function filter_update_transient( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient; // WP not ready to compare.
		}
		/** @var \stdClass $transient The update_plugins transient (dynamic props). */
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		foreach ( $this->managed_plugins() as $slug => $pkg ) {
			$m = $this->update_check( $slug, $pkg['version'] );
			if ( empty( $m ) || empty( $m['version'] ) || empty( $m['download_url'] ) ) {
				continue; // no update offered (or not entitled).
			}
			if ( version_compare( (string) $m['version'], $pkg['version'], '<=' ) ) {
				continue; // not newer.
			}
			$transient->response[ $pkg['basename'] ] = (object) array(
				'slug'         => (string) $slug,
				'plugin'       => $pkg['basename'],
				'new_version'  => (string) $m['version'],
				'package'      => (string) $m['download_url'],
				'url'          => isset( $m['homepage'] ) ? (string) $m['homepage'] : 'https://luwi.dev/luwipress',
				'tested'       => isset( $m['tested'] ) ? (string) $m['tested'] : '',
				'requires'     => isset( $m['requires'] ) ? (string) $m['requires'] : '',
				'requires_php' => isset( $m['requires_php'] ) ? (string) $m['requires_php'] : '7.4',
				'icons'        => self::brand_icons(),
			);
		}
		return $transient;
	}

	/**
	 * Inject available updates into WP's theme-update transient — one entry per
	 * installed luwi theme. Theme transient entries are ARRAYS (not objects)
	 * keyed by stylesheet.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function filter_theme_update_transient( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}
		/** @var \stdClass $transient The update_themes transient (dynamic props). */
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		foreach ( $this->managed_themes() as $stylesheet => $pkg ) {
			$m = $this->update_check( $stylesheet, $pkg['version'] );
			if ( empty( $m ) || empty( $m['version'] ) || empty( $m['download_url'] ) ) {
				continue;
			}
			if ( version_compare( (string) $m['version'], $pkg['version'], '<=' ) ) {
				continue;
			}
			$transient->response[ $stylesheet ] = array(
				'theme'        => $stylesheet,
				'new_version'  => (string) $m['version'],
				'url'          => isset( $m['homepage'] ) ? (string) $m['homepage'] : 'https://luwi.dev/luwipress',
				'package'      => (string) $m['download_url'],
				'requires'     => isset( $m['requires'] ) ? (string) $m['requires'] : '',
				'requires_php' => isset( $m['requires_php'] ) ? (string) $m['requires_php'] : '7.4',
			);
		}
		return $transient;
	}

	/**
	 * Provide the "View details" popup data for any managed luwi plugin.
	 *
	 * @param mixed  $res
	 * @param string $action
	 * @param object $args
	 * @return mixed
	 */
	public function filter_plugins_api( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $res;
		}
		$plugins = $this->managed_plugins();
		if ( ! isset( $plugins[ $args->slug ] ) ) {
			return $res; // not one of ours.
		}
		$pkg = $plugins[ $args->slug ];
		$m   = $this->update_check( (string) $args->slug, $pkg['version'] );
		if ( empty( $m ) || empty( $m['version'] ) ) {
			return $res;
		}
		$info                = new stdClass();
		$info->name          = $pkg['name'];
		$info->slug          = (string) $args->slug;
		$info->version       = (string) $m['version'];
		$info->author        = 'Luwi Developments LLC';
		$info->homepage      = isset( $m['homepage'] ) ? (string) $m['homepage'] : 'https://luwi.dev/luwipress';
		$info->requires      = isset( $m['requires'] ) ? (string) $m['requires'] : '';
		$info->tested        = isset( $m['tested'] ) ? (string) $m['tested'] : '';
		$info->requires_php  = isset( $m['requires_php'] ) ? (string) $m['requires_php'] : '7.4';
		$info->last_updated  = isset( $m['last_updated'] ) ? (string) $m['last_updated'] : '';
		$info->download_link = isset( $m['download_url'] ) ? (string) $m['download_url'] : '';
		$info->sections      = ( isset( $m['sections'] ) && is_array( $m['sections'] ) ) ? $m['sections'] : array( 'changelog' => '' );
		$info->icons         = self::brand_icons();
		$info->banners       = array();
		return $info;
	}

	/**
	 * Provide the "Theme details" popup data for any managed luwi theme.
	 *
	 * @param mixed  $res
	 * @param string $action
	 * @param object $args
	 * @return mixed
	 */
	public function filter_themes_api( $res, $action, $args ) {
		if ( 'theme_information' !== $action || empty( $args->slug ) ) {
			return $res;
		}
		$themes = $this->managed_themes();
		if ( ! isset( $themes[ $args->slug ] ) ) {
			return $res;
		}
		$pkg = $themes[ $args->slug ];
		$m   = $this->update_check( (string) $args->slug, $pkg['version'] );
		if ( empty( $m ) || empty( $m['version'] ) ) {
			return $res;
		}
		return (object) array(
			'name'          => $pkg['name'],
			'slug'          => (string) $args->slug,
			'version'       => (string) $m['version'],
			'author'        => 'LuwiPress',
			'homepage'      => isset( $m['homepage'] ) ? (string) $m['homepage'] : 'https://luwi.dev/luwipress',
			'requires'      => isset( $m['requires'] ) ? (string) $m['requires'] : '',
			'requires_php'  => isset( $m['requires_php'] ) ? (string) $m['requires_php'] : '7.4',
			'download_link' => isset( $m['download_url'] ) ? (string) $m['download_url'] : '',
			'sections'      => ( isset( $m['sections'] ) && is_array( $m['sections'] ) ) ? $m['sections'] : array( 'changelog' => '' ),
		);
	}

	/**
	 * Pending luwi-ecosystem updates already surfaced into WP's update
	 * transients (core plugin + companions + themes). READ-ONLY: consumes the
	 * cached `update_plugins` / `update_themes` site transients that
	 * filter_update_transient / filter_theme_update_transient populated — no
	 * extra HTTP, so it is safe to call on every dashboard render. Installing
	 * is always a separate, user-triggered step (WP's native update.php flow);
	 * this method only answers "is there something to offer?".
	 *
	 * @return array<int,array{type:string,slug:string,name:string,file:string,current:string,new:string}>
	 */
	public function ecosystem_pending_updates() {
		$out = array();
		$plugins = get_site_transient( 'update_plugins' );
		if ( is_object( $plugins ) && ! empty( $plugins->response ) && is_array( $plugins->response ) ) {
			foreach ( $this->managed_plugins() as $slug => $pkg ) {
				if ( ! isset( $plugins->response[ $pkg['basename'] ] ) ) {
					continue;
				}
				$r = $plugins->response[ $pkg['basename'] ];
				$out[] = array(
					'type'    => 'plugin',
					'slug'    => (string) $slug,
					'name'    => $pkg['name'],
					'file'    => $pkg['basename'],
					'current' => $pkg['version'],
					'new'     => is_object( $r ) && isset( $r->new_version ) ? (string) $r->new_version : '',
				);
			}
		}
		$themes = get_site_transient( 'update_themes' );
		if ( is_object( $themes ) && ! empty( $themes->response ) && is_array( $themes->response ) ) {
			foreach ( $this->managed_themes() as $stylesheet => $pkg ) {
				if ( ! isset( $themes->response[ $stylesheet ] ) ) {
					continue;
				}
				$r = $themes->response[ $stylesheet ];
				$out[] = array(
					'type'    => 'theme',
					'slug'    => $stylesheet,
					'name'    => $pkg['name'],
					'file'    => $stylesheet,
					'current' => $pkg['version'],
					'new'     => is_array( $r ) && isset( $r['new_version'] ) ? (string) $r['new_version'] : '',
				);
			}
		}
		return $out;
	}

	/**
	 * On update-core.php?force-check=1 drop the per-slug manifest caches so the
	 * transient rebuild that follows asks the license server fresh. Without
	 * this, "Check again" re-reads a manifest cached up to 6h ago.
	 */
	public function maybe_flush_update_cache_on_force_check() {
		// Nonce-checking is WP core's job on this screen; reading the flag is
		// a cache-drop only (no state the user can abuse).
		if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->flush_update_cache();
		}
	}

	/**
	 * Fetch + signature-verify the update manifest for one slug (cached 6h per
	 * slug). Returns the manifest array (which may carry version:null = no update
	 * available), or null on any failure. Skips entirely when not entitled to
	 * `auto_update` or never activated — the server is the final authority.
	 *
	 * @param string $slug            Server release slug (plugin folder or theme stylesheet).
	 * @param string $current_version Installed version (sent so the server can decide "newer?").
	 * @param bool   $force           Bypass the cache.
	 * @return array<string,mixed>|null
	 */
	private function update_check( $slug = self::UPDATE_SLUG, $current_version = '', $force = false ) {
		if ( ! self::feature_enabled( 'auto_update' ) ) {
			return null;
		}
		$state = self::get_state();
		if ( empty( $state['key'] ) ) {
			return null; // never activated — no licensed update channel.
		}
		$slug = (string) $slug;
		if ( '' === $current_version && self::UPDATE_SLUG === $slug && defined( 'LUWIPRESS_VERSION' ) ) {
			$current_version = LUWIPRESS_VERSION;
		}
		$cache_key = self::UPDATE_CACHE_PREFIX . md5( $slug );
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$url = self::api_base() . '/update?' . http_build_query( array(
			'slug'        => $slug,
			'key'         => $state['key'],
			'fingerprint' => self::fingerprint(),
			'version'     => (string) $current_version,
			'channel'     => apply_filters( 'luwipress_update_channel', 'stable' ),
		) );
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code     = wp_remote_retrieve_response_code( $response );
		$body_raw = wp_remote_retrieve_body( $response );
		$sig      = wp_remote_retrieve_header( $response, 'x-luwipress-license-sig' );
		if ( 200 !== (int) $code || ! $this->signature_ok( $body_raw, $sig ) ) {
			return null; // never trust an unsigned / failed manifest.
		}
		$data = json_decode( $body_raw, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		set_transient( $cache_key, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Drop every per-slug manifest cache (called when the verdict — and so the
	 * entitlement — may have changed).
	 */
	private function flush_update_cache() {
		// 'luwipress' = the pre-rename core slug; sites upgrading from ≤3.13.2
		// still carry its cached manifest — drop it alongside the live keys.
		$slugs = array( self::UPDATE_SLUG, 'luwipress', 'luwipress-webmcp', 'luwipress-agentic', 'luwipress-marketplace' );
		if ( function_exists( 'wp_get_themes' ) ) {
			foreach ( array_keys( wp_get_themes() ) as $stylesheet ) {
				if ( 0 === strpos( (string) $stylesheet, 'luwipress-' ) ) {
					$slugs[] = (string) $stylesheet;
				}
			}
		}
		foreach ( array_unique( $slugs ) as $slug ) {
			delete_transient( self::UPDATE_CACHE_PREFIX . md5( (string) $slug ) );
		}
		delete_transient( self::UPDATE_CACHE ); // legacy single-slug key.
		$this->managed_plugins_memo = null;
		$this->managed_themes_memo  = null;
	}

	/**
	 * Resolve the server slug for a package WP is about to download, from the
	 * upgrader's hook_extra (`plugin` basename or `theme` stylesheet). Falls back
	 * to the core slug so the core path is byte-for-byte as before.
	 *
	 * @param mixed $hook_extra
	 * @return string
	 */
	private function slug_from_hook_extra( $hook_extra ) {
		if ( is_array( $hook_extra ) ) {
			if ( ! empty( $hook_extra['plugin'] ) ) {
				$basename = (string) $hook_extra['plugin'];
				foreach ( $this->managed_plugins() as $slug => $pkg ) {
					if ( $pkg['basename'] === $basename ) {
						return (string) $slug;
					}
				}
			}
			if ( ! empty( $hook_extra['theme'] ) ) {
				return (string) $hook_extra['theme']; // stylesheet == slug.
			}
		}
		return self::UPDATE_SLUG;
	}

	/**
	 * Defence-in-depth: verify a downloaded ZIP's Ed25519 signature
	 * (`package_sig`) before WP installs it. Only intercepts our own signed
	 * download URLs (any managed slug); everything else passes through. If the
	 * matching manifest carries no package_sig (or signatures aren't pinned) WP
	 * downloads normally — the expiring server-signed URL is still the gate.
	 *
	 * @param mixed  $reply
	 * @param string $package
	 * @param mixed  $upgrader
	 * @param array  $hook_extra
	 * @return mixed
	 */
	public function verify_package_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( ! is_string( $package ) || 0 !== strpos( $package, self::api_base() ) ) {
			return $reply; // not our package.
		}
		$slug = $this->slug_from_hook_extra( $hook_extra );
		// FORCE a fresh manifest at install time. The manifest's download_url is
		// a short-lived signed URL (`exp` + `sig` params), but the manifest sits
		// in a 6h transient — by the time the operator clicks "update now" the
		// cached URL can already be expired, and the server answers 403 →
		// WP shows "Update failed: Forbidden" (seen live on tapadum 2026-06-10).
		// Installing is a rare moment; one extra HTTP round-trip buys a URL that
		// is always inside its validity window. Cached manifest is the fallback
		// if the fresh fetch fails (server briefly unreachable).
		$m = $this->update_check( $slug, '', true );
		if ( empty( $m['package_sig'] ) ) {
			$m = $this->update_check( $slug );
		}
		if ( empty( $m['package_sig'] ) ) {
			return $reply; // nothing to verify against — let WP handle it.
		}
		// Prefer the just-issued download URL over the (possibly stale) one WP
		// captured into the update transient earlier.
		$dl_url = ( ! empty( $m['download_url'] ) && is_string( $m['download_url'] ) ) ? $m['download_url'] : $package;
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $dl_url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$bytes = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bytes || ! $this->signature_ok( $bytes, $m['package_sig'] ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'luwipress_package_unsigned', __( 'The update package failed signature verification and was not installed.', 'luwipress' ) );
		}
		return $tmp; // verified — WP installs from this local file.
	}

	// ------------------------------------------------------------------
	// Activation / deactivation lifecycle (called from the plugin bootstrap)
	// ------------------------------------------------------------------

	/**
	 * Schedule the daily heartbeat. Called from LuwiPress::activate().
	 */
	public static function activate() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the heartbeat. Called from LuwiPress::deactivate().
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Lightweight sanity check before hitting the server: accept a native key
	 * (lwp_ + 48 hex) or a CodeCanyon/Envato purchase code (UUID). The server
	 * remains the authority — this just avoids a round-trip on obvious typos.
	 *
	 * @param string $key
	 * @return bool
	 */
	private static function looks_like_key( $key ) {
		$key = (string) $key;
		if ( preg_match( '/^lwp_[a-f0-9]{48}$/', $key ) ) {
			return true;
		}
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Non-reversible hint for a key: keeps the last 4 chars only.
	 *
	 * @param string $key
	 * @return string
	 */
	private static function mask_key( $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return '';
		}
		return '****' . substr( $key, -4 );
	}

	/**
	 * Log through the shared logger when available.
	 *
	 * @param string $message
	 * @param string $level
	 */
	private function log( $message, $level = 'info' ) {
		if ( class_exists( 'LuwiPress_Logger' ) ) {
			LuwiPress_Logger::log( $message, $level );
		}
	}
}
