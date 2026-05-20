<?php
/**
 * Bot Shield — WordPress + WooCommerce edge filter for bad bots, scrapers,
 * brute-force, and REST/XML-RPC enumeration.
 *
 * Defence layers (each independently toggleable):
 *
 *   1. User-Agent blocklist — common scraper / hostile crawler UAs are
 *      403'd at `init` priority 1 before WP does any heavy work. Operator
 *      can extend via REST + `apply_filters('luwipress_bot_shield_ua_blocklist',
 *      $list)`.
 *
 *   2. Rate limiter — per-IP request counter against sensitive endpoints
 *      (`/wp-login.php`, `/xmlrpc.php`, `/wp-json/wp/v2/users`, search bombs
 *      `/?s=`). Configurable threshold + window. Exceeding the threshold
 *      auto-bans the IP for `block_ttl_minutes`.
 *
 *   3. REST user-enumeration block — refuses
 *      `GET /wp-json/wp/v2/users` and `?author=N` queries unless the
 *      caller is authenticated. Prevents username harvesting that feeds
 *      credential-stuffing attacks.
 *
 *   4. XML-RPC throttle — when XML-RPC is enabled at all, the multicall
 *      pingback-amplification vector is closed and the per-IP request
 *      counter applies. Operator can fully disable XML-RPC via setting.
 *
 *   5. Honeypot URLs — synthetic paths like `/wp-admin/install.php`,
 *      `/wp-config-old.php` get the requesting IP banned for 24h on hit.
 *      Real admins never touch these; bots scanning common vuln paths
 *      trip immediately.
 *
 *   6. Allowlist — IPs/CIDRs/UAs in the allowlist bypass every check.
 *      Crawler verification: Googlebot / Bingbot UAs are reverse-DNS
 *      verified before being allowed in (defeats UA spoofing).
 *
 * Hard guards (non-toggleable):
 *   - Logged-in admins NEVER hit the shield (they're trusted).
 *   - Localhost / private IP space (127.0.0.0/8, 10.0.0.0/8, 192.168/16)
 *     is never auto-blocked.
 *
 * Storage:
 *   - `wp_luwipress_bot_blocks` — one row per active block. (ip, reason,
 *      hit_count, first_seen, last_seen, expires_at, ua_sample).
 *   - Per-IP rate-limit counters live in transients (`luwipress_bs_rl_<sha1(ip)>`).
 *
 * REST surface (admin-token):
 *   - GET  /luwipress/v1/bot-shield/stats     — aggregate counts + 24h activity
 *   - GET  /luwipress/v1/bot-shield/blocks    — paginated active blocks
 *   - POST /luwipress/v1/bot-shield/block     — manually add a block
 *   - POST /luwipress/v1/bot-shield/unblock   — remove a block
 *   - POST /luwipress/v1/bot-shield/allowlist — add/remove allowlist entry
 *   - GET  /luwipress/v1/bot-shield/settings  — read
 *   - POST /luwipress/v1/bot-shield/settings  — partial-update
 *   - POST /luwipress/v1/bot-shield/test      — dry-run probe a UA+IP+path
 *
 * @package LuwiPress
 * @since   3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Bot_Shield {

	const TABLE_BLOCKS_SUFFIX = 'luwipress_bot_blocks';
	const OPTION_SETTINGS     = 'luwipress_bot_shield_settings';
	const OPTION_ALLOWLIST    = 'luwipress_bot_shield_allowlist';
	const OPTION_STATS        = 'luwipress_bot_shield_stats';
	const OPTION_COMMENT_LOG  = 'luwipress_bot_shield_comment_log';
	const TRANSIENT_PREFIX_RL = 'luwipress_bs_rl_';
	const TRANSIENT_PREFIX_B  = 'luwipress_bs_b_';
	const TRANSIENT_PREFIX_C  = 'luwipress_bs_cdup_';
	const COMMENT_LOG_MAX     = 100;

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );

		$s = $this->get_settings();
		if ( empty( $s['enabled'] ) ) {
			return; // every defence is off → don't hook anything else.
		}

		// Front-edge guard runs as early as practical. `init` p1 is BEFORE
		// `template_redirect` (which our slug-resolver uses at p1), and
		// after `plugins_loaded` (we need plugins to be available for the
		// is_user_logged_in check). When a friendly security plugin
		// (Wordfence) is active we still hook the edge filter — it
		// short-circuits internally for delegated layers but keeps
		// running the rest (honeypot / REST enum / allowlist / manual
		// blocks).
		add_action( 'init', array( $this, 'edge_filter' ), 1 );

		// XML-RPC kill switch.
		if ( ! empty( $s['disable_xmlrpc'] ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			// Also strip pingback multicall amplifier.
			add_filter( 'xmlrpc_methods', function ( $methods ) {
				unset( $methods['pingback.ping'] );
				unset( $methods['pingback.extensions.getPingbacks'] );
				unset( $methods['system.multicall'] );
				return $methods;
			} );
		}

		// REST user-enumeration block.
		if ( ! empty( $s['block_user_enumeration'] ) ) {
			add_filter( 'rest_endpoints', array( $this, 'block_users_endpoint' ) );
			add_action( 'parse_request', array( $this, 'block_author_query' ), 1 );
		}

		// Comment review filter — separate toggle from the edge filter so
		// operators can run just the comment layer (or just the edge layer)
		// without all-or-nothing. Default ON in moderate mode (non-destructive).
		if ( ! empty( $s['comments_enabled'] ) && $s['comments_mode'] !== 'off' ) {
			add_filter( 'preprocess_comment',   array( $this, 'preprocess_comment_score' ), 1 );
			add_filter( 'pre_comment_approved', array( $this, 'pre_comment_approved_route' ), 1, 2 );
		}
	}

	public static function create_table() {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE_BLOCKS_SUFFIX;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ip VARCHAR(45) NOT NULL,
			reason VARCHAR(64) NOT NULL,
			hit_count INT UNSIGNED NOT NULL DEFAULT 1,
			ua_sample VARCHAR(255) NULL,
			path_sample VARCHAR(255) NULL,
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			expires_at DATETIME NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'auto',
			PRIMARY KEY  (id),
			UNIQUE KEY ip (ip),
			KEY reason (reason),
			KEY expires_at (expires_at),
			KEY last_seen (last_seen)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// -------------------- Settings + allowlist --------------------

	public function get_settings() {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$defaults = array(
			'enabled'                => false,
			'block_ua_scrapers'      => true,
			'rate_limit_enabled'     => true,
			'rate_limit_threshold'   => 60,    // requests per window
			'rate_limit_window'      => 60,    // seconds
			'block_ttl_minutes'      => 60,    // how long an auto-block lasts
			'block_user_enumeration' => true,
			'disable_xmlrpc'         => false, // some sites still rely on it
			'honeypot_enabled'       => true,
			'verify_search_engines'  => true,  // reverse-DNS Googlebot/Bingbot
			'sensitive_paths'        => array(
				'/wp-login.php', '/xmlrpc.php', '/wp-json/wp/v2/users',
			),
			'honeypot_paths'         => array(
				'/wp-admin/install.php', '/wp-config-old.php',
				'/.env', '/wp-content/plugins/wp-file-manager/lib/files/',
			),
			'ua_blocklist'           => array(
				'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'BLEXBot',
				'PetalBot', 'DataForSeoBot', 'serpstatbot', 'megaIndex',
				'Mauibot', 'AspiegelBot', 'masscan', 'nikto', 'sqlmap',
				'nmap', 'ZmEu', 'WPScan', 'Wappalyzer', 'PycURL',
				'python-requests', 'Go-http-client', 'Java/',
			),
			'log_block_events'       => true,
			// Comment review layer (3.3.0+) — independent of edge filter.
			// Defaults: ON in moderate mode → bot-shaped comments held for
			// manual review rather than silently rejected or auto-spammed.
			'comments_enabled'        => true,
			'comments_mode'           => 'moderate', // off | moderate | spam | reject
			'comments_threshold'      => 40,
			'comments_max_links'      => 2,
			'comments_block_ip_on_spam' => false,
			'comments_spam_tokens'    => array(
				'viagra', 'cialis', 'casino', 'porn', 'xxx', 'crypto signal',
				'forex signal', 'bitcoin doubler', 'seo backlinks', 'cheap rolex',
				'replica watch', 'free download crack', 'gambling site',
				'escort service', 'loan offer', 'inheritance funds',
			),
		);
		return array_replace_recursive( $defaults, $stored );
	}

	public function update_settings( array $patch ) {
		$current = $this->get_settings();
		$next    = $current;

		$bool_keys = array(
			'enabled', 'block_ua_scrapers', 'rate_limit_enabled',
			'block_user_enumeration', 'disable_xmlrpc', 'honeypot_enabled',
			'verify_search_engines', 'log_block_events',
			'comments_enabled', 'comments_block_ip_on_spam',
		);
		foreach ( $bool_keys as $k ) {
			if ( array_key_exists( $k, $patch ) ) {
				$next[ $k ] = (bool) $patch[ $k ];
			}
		}
		if ( array_key_exists( 'rate_limit_threshold', $patch ) ) {
			$next['rate_limit_threshold'] = max( 1, min( 10000, (int) $patch['rate_limit_threshold'] ) );
		}
		if ( array_key_exists( 'rate_limit_window', $patch ) ) {
			$next['rate_limit_window'] = max( 1, min( 3600, (int) $patch['rate_limit_window'] ) );
		}
		if ( array_key_exists( 'block_ttl_minutes', $patch ) ) {
			$next['block_ttl_minutes'] = max( 1, min( 43200, (int) $patch['block_ttl_minutes'] ) );
		}
		foreach ( array( 'sensitive_paths', 'honeypot_paths', 'ua_blocklist', 'comments_spam_tokens' ) as $arr_key ) {
			if ( array_key_exists( $arr_key, $patch ) && is_array( $patch[ $arr_key ] ) ) {
				$clean = array_values( array_unique( array_filter( array_map( 'strval', $patch[ $arr_key ] ) ) ) );
				$next[ $arr_key ] = $clean;
			}
		}
		if ( array_key_exists( 'comments_mode', $patch ) ) {
			$mode = sanitize_key( (string) $patch['comments_mode'] );
			if ( in_array( $mode, array( 'off', 'moderate', 'spam', 'reject' ), true ) ) {
				$next['comments_mode'] = $mode;
			}
		}
		if ( array_key_exists( 'comments_threshold', $patch ) ) {
			$next['comments_threshold'] = max( 10, min( 200, (int) $patch['comments_threshold'] ) );
		}
		if ( array_key_exists( 'comments_max_links', $patch ) ) {
			$next['comments_max_links'] = max( 0, min( 20, (int) $patch['comments_max_links'] ) );
		}

		update_option( self::OPTION_SETTINGS, $next, false );
		return $next;
	}

	public function get_allowlist() {
		$list = get_option( self::OPTION_ALLOWLIST, array() );
		if ( ! is_array( $list ) ) {
			return array( 'ips' => array(), 'uas' => array() );
		}
		return array_merge( array( 'ips' => array(), 'uas' => array() ), $list );
	}

	public function update_allowlist( $entry_type, $value, $action = 'add' ) {
		$list = $this->get_allowlist();
		$key  = $entry_type === 'ua' ? 'uas' : 'ips';
		$value = trim( (string) $value );
		if ( $value === '' ) return $list;
		if ( $action === 'remove' ) {
			$list[ $key ] = array_values( array_filter( $list[ $key ], function ( $v ) use ( $value ) { return $v !== $value; } ) );
		} else {
			if ( ! in_array( $value, $list[ $key ], true ) ) {
				$list[ $key ][] = $value;
			}
		}
		update_option( self::OPTION_ALLOWLIST, $list, false );
		return $list;
	}

	// -------------------- Edge filter --------------------

	public function edge_filter() {
		// Never gate admin or REST internal requests to admin-ajax
		// from a logged-in admin.
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		$ip   = $this->get_client_ip();
		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';

		// Private/loopback IPs are never blocked.
		if ( $this->is_private_ip( $ip ) ) {
			return;
		}

		// Allowlist short-circuit.
		if ( $this->is_allowlisted( $ip, $ua ) ) {
			return;
		}

		// Active block check — runs even when Wordfence is delegated
		// because operators may manually block via our admin/MCP surface.
		if ( $this->is_blocked( $ip ) ) {
			$this->deny_request( 'blocked', $ip, $ua, $path );
			return;
		}

		$s = $this->get_settings();
		$delegated = $this->delegated_layers();

		// Honeypot.
		if ( ! empty( $s['honeypot_enabled'] ) && ! in_array( 'honeypot', $delegated, true ) ) {
			foreach ( (array) $s['honeypot_paths'] as $hp ) {
				if ( $hp && strpos( $path, $hp ) === 0 ) {
					$this->record_block( $ip, 'honeypot', $ua, $path, 24 * 60 );
					$this->deny_request( 'honeypot', $ip, $ua, $path );
					return;
				}
			}
		}

		// UA blocklist.
		if ( ! empty( $s['block_ua_scrapers'] ) && $ua !== '' && ! in_array( 'ua_blocklist', $delegated, true ) ) {
			foreach ( (array) $s['ua_blocklist'] as $needle ) {
				if ( $needle === '' ) continue;
				if ( stripos( $ua, $needle ) !== false ) {
					$this->record_block( $ip, 'ua_blocklist:' . $needle, $ua, $path, (int) $s['block_ttl_minutes'] );
					$this->deny_request( 'ua_blocklist', $ip, $ua, $path );
					return;
				}
			}
		}

		// Rate limit on sensitive paths.
		if ( ! empty( $s['rate_limit_enabled'] ) && ! in_array( 'rate_limit', $delegated, true ) ) {
			$sensitive = false;
			foreach ( (array) $s['sensitive_paths'] as $sp ) {
				if ( $sp && strpos( $path, $sp ) === 0 ) {
					$sensitive = true;
					break;
				}
			}
			if ( $sensitive ) {
				$hit = $this->bump_rate_counter( $ip, (int) $s['rate_limit_window'] );
				if ( $hit > (int) $s['rate_limit_threshold'] ) {
					$this->record_block( $ip, 'rate_limit', $ua, $path, (int) $s['block_ttl_minutes'] );
					$this->deny_request( 'rate_limit', $ip, $ua, $path );
					return;
				}
			}
		}
	}

	private function deny_request( $reason, $ip, $ua, $path ) {
		if ( ! headers_sent() ) {
			status_header( 403 );
			header( 'Cache-Control: no-store, private, must-revalidate' );
			header( 'X-LuwiPress-Shield: ' . $reason );
		}
		if ( ! empty( $this->get_settings()['log_block_events'] ) && class_exists( 'LuwiPress_Logger' ) ) {
			LuwiPress_Logger::log(
				'Bot Shield denied request',
				'warning',
				array( 'reason' => $reason, 'ip' => $this->mask_ip( $ip ), 'ua' => substr( $ua, 0, 120 ), 'path' => substr( $path, 0, 120 ) )
			);
		}
		$this->bump_stat( $reason );
		wp_die(
			esc_html__( 'Request blocked by site security.', 'luwipress' ),
			'Forbidden',
			array( 'response' => 403 )
		);
	}

	private function bump_rate_counter( $ip, $window ) {
		$key = self::TRANSIENT_PREFIX_RL . sha1( $ip );
		$n   = (int) get_transient( $key );
		$n++;
		set_transient( $key, $n, $window );
		return $n;
	}

	private function bump_stat( $reason ) {
		$stats = get_option( self::OPTION_STATS, array() );
		if ( ! is_array( $stats ) ) $stats = array();
		$day = gmdate( 'Y-m-d' );
		if ( ! isset( $stats[ $day ] ) ) $stats[ $day ] = array();
		$stats[ $day ][ $reason ] = ( $stats[ $day ][ $reason ] ?? 0 ) + 1;
		// Keep last 14 days only.
		krsort( $stats );
		$stats = array_slice( $stats, 0, 14, true );
		update_option( self::OPTION_STATS, $stats, false );
	}

	// -------------------- Block CRUD --------------------

	public function record_block( $ip, $reason, $ua = '', $path = '', $ttl_minutes = 60 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_BLOCKS_SUFFIX;
		$now   = current_time( 'mysql' );
		$expires = $ttl_minutes > 0 ? gmdate( 'Y-m-d H:i:s', time() + $ttl_minutes * 60 ) : null;

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, hit_count FROM {$table} WHERE ip = %s", $ip ), ARRAY_A );
		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'last_seen'   => $now,
					'hit_count'   => (int) $existing['hit_count'] + 1,
					'expires_at'  => $expires,
					'reason'      => $reason,
					'ua_sample'   => substr( $ua, 0, 255 ),
					'path_sample' => substr( $path, 0, 255 ),
				),
				array( 'id' => (int) $existing['id'] )
			);
		} else {
			$wpdb->insert( $table, array(
				'ip'          => $ip,
				'reason'      => $reason,
				'hit_count'   => 1,
				'ua_sample'   => substr( $ua, 0, 255 ),
				'path_sample' => substr( $path, 0, 255 ),
				'first_seen'  => $now,
				'last_seen'   => $now,
				'expires_at'  => $expires,
				'source'      => 'auto',
			) );
		}

		set_transient( self::TRANSIENT_PREFIX_B . sha1( $ip ), 1, $ttl_minutes * 60 );
	}

	public function manual_block( $ip, $reason = 'manual', $ttl_minutes = 1440 ) {
		$this->record_block( $ip, sanitize_key( $reason ), '', '', (int) $ttl_minutes );
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_BLOCKS_SUFFIX;
		$wpdb->update( $table, array( 'source' => 'manual' ), array( 'ip' => $ip ) );
		return true;
	}

	public function unblock( $ip ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_BLOCKS_SUFFIX;
		$wpdb->delete( $table, array( 'ip' => $ip ) );
		delete_transient( self::TRANSIENT_PREFIX_B . sha1( $ip ) );
		return true;
	}

	public function is_blocked( $ip ) {
		$cached = get_transient( self::TRANSIENT_PREFIX_B . sha1( $ip ) );
		if ( $cached === '1' || $cached === 1 ) return true;

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_BLOCKS_SUFFIX;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from $wpdb->prefix and a class constant. luwipress-audit:ignore
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT expires_at FROM {$table} /* luwipress-audit:ignore */ WHERE ip = %s",
			$ip
		), ARRAY_A );
		if ( ! $row ) return false;
		if ( ! $row['expires_at'] ) return true; // permanent block
		if ( strtotime( $row['expires_at'] ) > time() ) {
			set_transient( self::TRANSIENT_PREFIX_B . sha1( $ip ), 1, max( 60, strtotime( $row['expires_at'] ) - time() ) );
			return true;
		}
		// Expired — remove.
		$wpdb->delete( $table, array( 'ip' => $ip ) );
		return false;
	}

	public function list_blocks( $page = 1, $per_page = 50 ) {
		global $wpdb;
		$table    = $wpdb->prefix . self::TABLE_BLOCKS_SUFFIX;
		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 500, (int) $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from $wpdb->prefix and a class constant. luwipress-audit:ignore
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} /* luwipress-audit:ignore */ ORDER BY last_seen DESC LIMIT %d OFFSET %d",
			$per_page, $offset
		), ARRAY_A );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe plugin table name. luwipress-audit:ignore

		return array(
			'items'    => (array) $rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	public function get_stats() {
		$stats = (array) get_option( self::OPTION_STATS, array() );
		$today = gmdate( 'Y-m-d' );

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_BLOCKS_SUFFIX;
		$active_blocks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE expires_at IS NULL OR expires_at > UTC_TIMESTAMP()" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe plugin table name. luwipress-audit:ignore

		$by_reason = $wpdb->get_results( "SELECT reason, COUNT(*) as n FROM {$table} GROUP BY reason ORDER BY n DESC LIMIT 20", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe plugin table name. luwipress-audit:ignore
		$reason_map = array();
		foreach ( (array) $by_reason as $r ) $reason_map[ (string) $r['reason'] ] = (int) $r['n'];

		$delegated_security = class_exists( 'LuwiPress_Plugin_Detector' )
			? LuwiPress_Plugin_Detector::get_instance()->detect_security()
			: array( 'plugin' => 'none' );

		return array(
			'enabled'             => ! empty( $this->get_settings()['enabled'] ),
			'active_blocks'       => $active_blocks,
			'today_denials'       => isset( $stats[ $today ] ) ? array_sum( (array) $stats[ $today ] ) : 0,
			'by_day'              => $stats,
			'by_reason'           => $reason_map,
			'allowlist'           => $this->get_allowlist(),
			'friendly_security'   => $delegated_security,
			'delegated_layers'    => $this->delegated_layers(),
		);
	}

	// -------------------- User-enumeration block --------------------

	public function block_users_endpoint( $endpoints ) {
		if ( is_user_logged_in() ) return $endpoints;
		if ( isset( $endpoints['/wp/v2/users'] ) )         unset( $endpoints['/wp/v2/users'] );
		if ( isset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] ) ) unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		return $endpoints;
	}

	public function block_author_query( $wp ) {
		if ( is_user_logged_in() ) return;
		if ( ! empty( $_GET['author'] ) && is_numeric( $_GET['author'] ) ) {
			wp_die( esc_html__( 'Author enumeration blocked.', 'luwipress' ), 'Forbidden', array( 'response' => 403 ) );
		}
	}

	// -------------------- Helpers --------------------

	/**
	 * Which Bot Shield layers should be skipped because a friendly security
	 * plugin (currently Wordfence) is already handling them. Returns an
	 * array of layer names: 'ua_blocklist', 'rate_limit', 'honeypot'.
	 * Cookie consent / REST user enumeration / XML-RPC kill are NEVER
	 * delegated because Wordfence Free does not provide them.
	 *
	 * @return string[]
	 */
	public function delegated_layers() {
		if ( ! class_exists( 'LuwiPress_Plugin_Detector' ) ) {
			return array();
		}
		$detect = LuwiPress_Plugin_Detector::get_instance()->detect_security();
		if ( empty( $detect['plugin'] ) || $detect['plugin'] === 'none' ) {
			return array();
		}
		$layers = isset( $detect['delegate_layers'] ) && is_array( $detect['delegate_layers'] ) ? $detect['delegate_layers'] : array();
		/**
		 * Filter the list of layers Bot Shield delegates to the detected
		 * security plugin. Return an empty array to force LuwiPress to
		 * run every layer regardless (not recommended — risks double-block).
		 *
		 * @param string[] $layers
		 * @param array    $detect Plugin Detector security payload.
		 */
		return (array) apply_filters( 'luwipress_bot_shield_delegated_layers', $layers, $detect );
	}

	private function get_client_ip() {
		$candidates = array( 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $candidates as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$parts = explode( ',', (string) $_SERVER[ $k ] );
				$ip = trim( end( $parts ) );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
			}
		}
		return '0.0.0.0';
	}

	private function is_private_ip( $ip ) {
		return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	private function mask_ip( $ip ) {
		// Anonymise the last octet for IPv4 in logs (GDPR-friendly).
		if ( strpos( $ip, '.' ) !== false ) {
			$parts = explode( '.', $ip );
			if ( count( $parts ) === 4 ) {
				$parts[3] = '0';
				return implode( '.', $parts );
			}
		}
		return $ip;
	}

	private function is_allowlisted( $ip, $ua ) {
		$list = $this->get_allowlist();
		if ( in_array( $ip, $list['ips'], true ) ) return true;
		if ( $ua !== '' ) {
			foreach ( (array) $list['uas'] as $needle ) {
				if ( $needle !== '' && stripos( $ua, $needle ) !== false ) return true;
			}
		}

		// Search-engine verification: trust Googlebot / Bingbot only when
		// reverse-DNS resolves back into the expected domain.
		$s = $this->get_settings();
		if ( ! empty( $s['verify_search_engines'] ) && $ua !== '' ) {
			$bots = array( 'Googlebot' => array( '.googlebot.com', '.google.com' ), 'bingbot' => array( '.search.msn.com' ) );
			foreach ( $bots as $name => $suffixes ) {
				if ( stripos( $ua, $name ) === false ) continue;
				$host = @gethostbyaddr( $ip );
				if ( $host === false || $host === $ip ) return false;
				foreach ( $suffixes as $sfx ) {
					if ( substr( $host, - strlen( $sfx ) ) === $sfx ) {
						// Forward-DNS confirm to defeat fake reverse zones.
						$resolved = @gethostbyname( $host );
						if ( $resolved === $ip ) return true;
					}
				}
				return false;
			}
		}
		return false;
	}

	// -------------------- REST API --------------------

	public function register_endpoints() {
		$ns = 'luwipress/v1';

		register_rest_route( $ns, '/bot-shield/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_stats' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );

		register_rest_route( $ns, '/bot-shield/blocks', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_blocks' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'page'     => array( 'type' => 'integer' ),
				'per_page' => array( 'type' => 'integer' ),
			),
		) );

		register_rest_route( $ns, '/bot-shield/block', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_block' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'ip'          => array( 'type' => 'string', 'required' => true ),
				'reason'      => array( 'type' => 'string' ),
				'ttl_minutes' => array( 'type' => 'integer' ),
			),
		) );

		register_rest_route( $ns, '/bot-shield/unblock', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_unblock' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'ip' => array( 'type' => 'string', 'required' => true ),
			),
		) );

		register_rest_route( $ns, '/bot-shield/allowlist', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_allowlist' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'type'   => array( 'type' => 'string' ),
				'value'  => array( 'type' => 'string', 'required' => true ),
				'action' => array( 'type' => 'string' ),
			),
		) );

		register_rest_route( $ns, '/bot-shield/settings', array(
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

		register_rest_route( $ns, '/bot-shield/test', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_test' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'ip'   => array( 'type' => 'string' ),
				'ua'   => array( 'type' => 'string' ),
				'path' => array( 'type' => 'string' ),
			),
		) );

		register_rest_route( $ns, '/bot-shield/comments/recent', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_comments_recent' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'limit' => array( 'type' => 'integer' ),
			),
		) );

		register_rest_route( $ns, '/bot-shield/comments/test', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_comments_test' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'author'  => array( 'type' => 'string' ),
				'email'   => array( 'type' => 'string' ),
				'url'     => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string', 'required' => true ),
				'ip'      => array( 'type' => 'string' ),
				'post_id' => array( 'type' => 'integer' ),
			),
		) );
	}

	public function rest_comments_recent( $request ) {
		$limit = (int) ( $request->get_param( 'limit' ) ?: 50 );
		return rest_ensure_response( array(
			'items' => $this->get_recent_comment_events( $limit ),
			'count' => count( $this->get_recent_comment_events( $limit ) ),
		) );
	}

	public function rest_comments_test( $request ) {
		$payload = array(
			'author'  => (string) $request->get_param( 'author' ),
			'email'   => (string) $request->get_param( 'email' ),
			'url'     => (string) $request->get_param( 'url' ),
			'content' => (string) $request->get_param( 'content' ),
			'ip'      => (string) $request->get_param( 'ip' ),
			'post_id' => (int) ( $request->get_param( 'post_id' ) ?: 0 ),
		);
		return rest_ensure_response( $this->test_comment_payload( $payload ) );
	}

	public function rest_stats( $request ) {
		return rest_ensure_response( $this->get_stats() );
	}

	public function rest_blocks( $request ) {
		$page     = (int) ( $request->get_param( 'page' ) ?: 1 );
		$per_page = (int) ( $request->get_param( 'per_page' ) ?: 50 );
		return rest_ensure_response( $this->list_blocks( $page, $per_page ) );
	}

	public function rest_block( $request ) {
		$ip   = (string) $request->get_param( 'ip' );
		$reason = (string) ( $request->get_param( 'reason' ) ?: 'manual' );
		$ttl  = (int) ( $request->get_param( 'ttl_minutes' ) ?: 1440 );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new WP_Error( 'invalid_ip', 'Invalid IP address.', array( 'status' => 400 ) );
		}
		$this->manual_block( $ip, $reason, $ttl );
		return rest_ensure_response( array( 'ok' => true, 'ip' => $ip, 'reason' => $reason, 'ttl_minutes' => $ttl ) );
	}

	public function rest_unblock( $request ) {
		$ip = (string) $request->get_param( 'ip' );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new WP_Error( 'invalid_ip', 'Invalid IP address.', array( 'status' => 400 ) );
		}
		$this->unblock( $ip );
		return rest_ensure_response( array( 'ok' => true, 'ip' => $ip ) );
	}

	public function rest_allowlist( $request ) {
		$type   = (string) ( $request->get_param( 'type' ) ?: 'ip' );
		$value  = (string) $request->get_param( 'value' );
		$action = (string) ( $request->get_param( 'action' ) ?: 'add' );
		$list = $this->update_allowlist( $type, $value, $action );
		return rest_ensure_response( $list );
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

	/**
	 * Dry-run a UA/IP/path combo against the current rule set and report
	 * what would happen without actually denying anything. Useful for
	 * tuning thresholds without locking yourself out.
	 */
	public function rest_test( $request ) {
		$ip   = (string) ( $request->get_param( 'ip' )   ?: '0.0.0.0' );
		$ua   = (string) ( $request->get_param( 'ua' )   ?: '' );
		$path = (string) ( $request->get_param( 'path' ) ?: '/' );

		$s        = $this->get_settings();
		$verdict  = 'allow';
		$reason   = null;

		if ( $this->is_private_ip( $ip ) ) {
			return rest_ensure_response( array( 'verdict' => 'allow', 'reason' => 'private_ip', 'ip' => $ip, 'ua' => $ua, 'path' => $path ) );
		}
		if ( $this->is_allowlisted( $ip, $ua ) ) {
			return rest_ensure_response( array( 'verdict' => 'allow', 'reason' => 'allowlisted', 'ip' => $ip, 'ua' => $ua, 'path' => $path ) );
		}
		if ( $this->is_blocked( $ip ) ) {
			return rest_ensure_response( array( 'verdict' => 'deny', 'reason' => 'already_blocked', 'ip' => $ip, 'ua' => $ua, 'path' => $path ) );
		}
		if ( ! empty( $s['honeypot_enabled'] ) ) {
			foreach ( (array) $s['honeypot_paths'] as $hp ) {
				if ( $hp && strpos( $path, $hp ) === 0 ) {
					return rest_ensure_response( array( 'verdict' => 'deny', 'reason' => 'honeypot:' . $hp, 'ip' => $ip, 'ua' => $ua, 'path' => $path ) );
				}
			}
		}
		if ( ! empty( $s['block_ua_scrapers'] ) && $ua !== '' ) {
			foreach ( (array) $s['ua_blocklist'] as $needle ) {
				if ( $needle !== '' && stripos( $ua, $needle ) !== false ) {
					return rest_ensure_response( array( 'verdict' => 'deny', 'reason' => 'ua_blocklist:' . $needle, 'ip' => $ip, 'ua' => $ua, 'path' => $path ) );
				}
			}
		}
		if ( ! empty( $s['rate_limit_enabled'] ) ) {
			foreach ( (array) $s['sensitive_paths'] as $sp ) {
				if ( $sp && strpos( $path, $sp ) === 0 ) {
					$verdict = 'allow';
					$reason  = 'sensitive_path_rate_limited';
					break;
				}
			}
		}
		return rest_ensure_response( array(
			'verdict' => $verdict,
			'reason'  => $reason,
			'ip'      => $ip,
			'ua'      => $ua,
			'path'    => $path,
		) );
	}

	/* ═══════════════════════════════════════════════════════════════════
	 *  COMMENT REVIEW LAYER (3.3.0+)
	 *
	 *  Multi-signal scorer for comment submissions. Logged-in users + IPs
	 *  on the Bot Shield allowlist bypass entirely. Score >= threshold ⇒
	 *  the configured action (moderate / spam / reject) applies. Every
	 *  caught comment is logged to an option-ring buffer (last 100 events)
	 *  so operators can review on the admin page or via MCP.
	 * ═══════════════════════════════════════════════════════════════════ */

	/**
	 * Storage for the score computed in `preprocess_comment` so the
	 * downstream `pre_comment_approved` filter can route without
	 * re-scoring. Keyed by a hash of (author + ip + content_sha) since
	 * `preprocess_comment` doesn't yield a comment ID yet.
	 *
	 * @var array<string,array>
	 */
	private $pending_comment_scores = array();

	/**
	 * Hooked to `preprocess_comment` p1. Runs the multi-signal scorer and
	 * stashes the result for `pre_comment_approved`. Returns the original
	 * commentdata unchanged unless mode === reject AND score >= threshold,
	 * in which case wp_die() is called.
	 *
	 * @param  array $commentdata
	 * @return array
	 */
	public function preprocess_comment_score( $commentdata ) {
		// Logged-in users bypass the comment filter entirely — they go
		// through WP's own moderation. We only inspect anonymous + email-form
		// submissions, which is where bot spam comes from.
		if ( is_user_logged_in() ) {
			return $commentdata;
		}
		// Pingbacks / trackbacks are out of scope; WP has its own dedicated
		// moderation flow and they're not the bot-spam class operators are
		// asking us to filter.
		$type = isset( $commentdata['comment_type'] ) ? (string) $commentdata['comment_type'] : '';
		if ( $type === 'pingback' || $type === 'trackback' ) {
			return $commentdata;
		}

		$ip = $this->get_client_ip();
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

		// Allowlist & private IPs bypass comment scoring too — they bypass
		// every other Bot Shield layer, so being inconsistent here would
		// surprise the operator.
		if ( $this->is_private_ip( $ip ) || $this->is_allowlisted( $ip, $ua ) ) {
			return $commentdata;
		}

		$scoring = $this->score_comment( $commentdata, $ip );
		$key     = $this->comment_score_key( $commentdata, $ip );
		$this->pending_comment_scores[ $key ] = $scoring;

		$s        = $this->get_settings();
		$mode     = isset( $s['comments_mode'] ) ? (string) $s['comments_mode'] : 'moderate';
		$threshold = (int) ( $s['comments_threshold'] ?? 40 );

		// Reject mode hits here — we abort the whole request before WP
		// writes anything. Moderate / spam modes route via the
		// `pre_comment_approved` filter so the row IS written, just held
		// or routed to spam queue (operator can see it).
		if ( $mode === 'reject' && $scoring['score'] >= $threshold ) {
			$this->log_comment_event( $commentdata, $scoring, $ip, $ua, 'rejected' );
			$this->bump_stat( 'comment_rejected' );

			if ( ! empty( $s['comments_block_ip_on_spam'] ) ) {
				$this->record_block( $ip, 'comment_spam', $ua, '/wp-comments-post.php', max( 60, (int) ( $s['block_ttl_minutes'] ?? 60 ) ) );
			}

			if ( ! empty( $s['log_block_events'] ) && class_exists( 'LuwiPress_Logger' ) ) {
				LuwiPress_Logger::log(
					'Bot Shield rejected comment',
					'warning',
					array(
						'score'   => $scoring['score'],
						'signals' => $scoring['signals'],
						'ip'      => $this->mask_ip( $ip ),
					)
				);
			}

			wp_die(
				esc_html__( 'Your comment was blocked by site security. If you believe this is an error, please contact the site administrator.', 'luwipress' ),
				'Forbidden',
				array( 'response' => 403 )
			);
		}

		return $commentdata;
	}

	/**
	 * Hooked to `pre_comment_approved` p1. Consults the stashed score and
	 * returns the appropriate WP approval flag.
	 *
	 *   moderate ⇒ 0 (held for manual moderation)
	 *   spam     ⇒ 'spam' (routed to spam queue, never shown publicly)
	 *
	 * @param  int|string $approved The current WP-computed approval flag.
	 * @param  array      $commentdata
	 * @return int|string
	 */
	public function pre_comment_approved_route( $approved, $commentdata ) {
		if ( is_user_logged_in() ) {
			return $approved;
		}
		$ip = $this->get_client_ip();
		$key = $this->comment_score_key( $commentdata, $ip );
		if ( ! isset( $this->pending_comment_scores[ $key ] ) ) {
			return $approved;
		}
		$scoring   = $this->pending_comment_scores[ $key ];
		$s         = $this->get_settings();
		$mode      = isset( $s['comments_mode'] ) ? (string) $s['comments_mode'] : 'moderate';
		$threshold = (int) ( $s['comments_threshold'] ?? 40 );

		if ( $scoring['score'] < $threshold ) {
			return $approved; // Below threshold — let WP's own decision stand.
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

		if ( $mode === 'spam' ) {
			$this->log_comment_event( $commentdata, $scoring, $ip, $ua, 'spam' );
			$this->bump_stat( 'comment_spam' );
			if ( ! empty( $s['comments_block_ip_on_spam'] ) ) {
				$this->record_block( $ip, 'comment_spam', $ua, '/wp-comments-post.php', max( 60, (int) ( $s['block_ttl_minutes'] ?? 60 ) ) );
			}
			return 'spam';
		}

		// Moderate (default) — hold for manual review.
		$this->log_comment_event( $commentdata, $scoring, $ip, $ua, 'moderated' );
		$this->bump_stat( 'comment_moderated' );
		return 0;
	}

	/**
	 * Multi-signal scorer. Each matched signal contributes a weight; the
	 * sum determines the action. Signals + total are returned together so
	 * the admin UI can explain WHY a comment was caught.
	 *
	 * @param  array  $commentdata
	 * @param  string $ip
	 * @return array{score:int,signals:array<int,array{name:string,weight:int,detail:string}>}
	 */
	public function score_comment( $commentdata, $ip ) {
		$signals = array();
		$score   = 0;

		$author  = isset( $commentdata['comment_author'] )         ? (string) $commentdata['comment_author']         : '';
		$email   = isset( $commentdata['comment_author_email'] )   ? (string) $commentdata['comment_author_email']   : '';
		$url     = isset( $commentdata['comment_author_url'] )     ? (string) $commentdata['comment_author_url']     : '';
		$content = isset( $commentdata['comment_content'] )        ? (string) $commentdata['comment_content']        : '';

		$add = function ( $name, $weight, $detail ) use ( &$signals, &$score ) {
			$signals[] = array( 'name' => $name, 'weight' => (int) $weight, 'detail' => (string) $detail );
			$score    += (int) $weight;
		};

		// 1. Author-URL provided when not strictly necessary — common SEO-spam tell.
		if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$add( 'author_url_filled', 15, $url );
		}

		// 2. Author name shape heuristics.
		if ( $author !== '' ) {
			$digits = preg_match_all( '/[0-9]/', $author, $_m );
			if ( $digits >= 3 && $digits / max( 1, strlen( $author ) ) > 0.2 ) {
				$add( 'author_digits_heavy', 20, $author );
			}
			$keyboard = array( 'asdf', 'qwer', 'zxcv', 'jklh', 'fghjkl', 'hjkl' );
			$lc       = strtolower( $author );
			foreach ( $keyboard as $kb ) {
				if ( strpos( $lc, $kb ) !== false ) {
					$add( 'author_keyboard_mash', 25, $kb );
					break;
				}
			}
			// 6+ identical consecutive chars or 5+ consonants in a row → bot-like.
			if ( preg_match( '/(.)\1{4,}/u', $author ) ) {
				$add( 'author_repeat_char', 20, $author );
			}
			if ( preg_match( '/[bcdfghjklmnpqrstvwxyz]{6,}/i', $author ) ) {
				$add( 'author_consonant_run', 15, $author );
			}
		}

		// 3. Body link density.
		$link_count = 0;
		if ( $content !== '' ) {
			$link_count += preg_match_all( '#https?://[^\s<>"\']+#i', $content, $_m );
			$link_count += preg_match_all( '#<a\s[^>]*href#i',         $content, $_m );
		}
		$max_links = (int) ( $this->get_settings()['comments_max_links'] ?? 2 );
		if ( $link_count > $max_links ) {
			$add( 'body_link_density', min( 60, 15 * ( $link_count - $max_links ) ), sprintf( '%d links (max %d)', $link_count, $max_links ) );
		}

		// 4. URL-only or near-URL-only body.
		if ( $content !== '' ) {
			$plain     = trim( wp_strip_all_tags( $content ) );
			$url_chars = 0;
			if ( preg_match_all( '#https?://[^\s<>"\']+#i', $plain, $matches ) ) {
				foreach ( $matches[0] as $u ) $url_chars += strlen( $u );
			}
			if ( $url_chars > 0 && strlen( $plain ) > 0 && ( $url_chars / strlen( $plain ) ) >= 0.7 ) {
				$add( 'body_url_dominant', 50, sprintf( '%d%% URL chars', (int) ( 100 * $url_chars / strlen( $plain ) ) ) );
			}
		}

		// 5. Spam-token hits in body.
		$tokens = (array) ( $this->get_settings()['comments_spam_tokens'] ?? array() );
		if ( $content !== '' && ! empty( $tokens ) ) {
			$body_lc = strtolower( $content );
			$hits    = array();
			foreach ( $tokens as $tok ) {
				$tok = trim( (string) $tok );
				if ( $tok === '' ) continue;
				if ( stripos( $body_lc, strtolower( $tok ) ) !== false ) {
					$hits[] = $tok;
				}
			}
			if ( ! empty( $hits ) ) {
				$add( 'body_spam_token', 30 * count( $hits ), implode( ', ', array_slice( $hits, 0, 5 ) ) );
			}
		}

		// 6. Repeat content within the rate-limit window per IP.
		if ( $content !== '' ) {
			$fingerprint = sha1( $ip . '|' . substr( $content, 0, 500 ) );
			$dup_key     = self::TRANSIENT_PREFIX_C . $fingerprint;
			if ( get_transient( $dup_key ) ) {
				$add( 'duplicate_recent_post', 40, 'matched fingerprint within window' );
			} else {
				set_transient( $dup_key, 1, 10 * MINUTE_IN_SECONDS );
			}
		}

		// 7. IP already on Bot Shield blocklist — instant high.
		if ( $this->is_blocked( $ip ) ) {
			$add( 'ip_already_blocked', 100, $this->mask_ip( $ip ) );
		}

		// 8. Email anti-pattern: random local-part heuristic (consonant run + digits).
		if ( $email !== '' && strpos( $email, '@' ) !== false ) {
			$local = strstr( $email, '@', true );
			if ( $local && preg_match( '/[bcdfghjklmnpqrstvwxyz]{6,}/i', $local ) && preg_match( '/[0-9]{3,}/', $local ) ) {
				$add( 'email_shape_random', 15, $local );
			}
		}

		// 9. Open filter — third-party plugins can append signals.
		$external = apply_filters( 'luwipress_bot_shield_comment_extra_signals', array(), $commentdata, $ip );
		if ( is_array( $external ) ) {
			foreach ( $external as $sig ) {
				if ( is_array( $sig ) && isset( $sig['name'], $sig['weight'] ) ) {
					$add( (string) $sig['name'], (int) $sig['weight'], isset( $sig['detail'] ) ? (string) $sig['detail'] : '' );
				}
			}
		}

		return array(
			'score'      => $score,
			'signals'    => $signals,
			'link_count' => $link_count,
		);
	}

	/**
	 * Stable key for the in-request score-stash. WP doesn't expose the
	 * eventual comment ID at `preprocess_comment` time, so we key on
	 * (author + email + ip + content sha) which is stable across the two
	 * filter invocations within the same submit.
	 *
	 * @param  array  $commentdata
	 * @param  string $ip
	 * @return string
	 */
	private function comment_score_key( $commentdata, $ip ) {
		return sha1( implode( '|', array(
			$commentdata['comment_author']       ?? '',
			$commentdata['comment_author_email'] ?? '',
			$ip,
			substr( (string) ( $commentdata['comment_content'] ?? '' ), 0, 500 ),
		) ) );
	}

	/**
	 * Persist a comment-catch event to the option-ring buffer. Last
	 * COMMENT_LOG_MAX events kept — older entries are pruned.
	 *
	 * @param array  $commentdata
	 * @param array  $scoring   ['score','signals','link_count']
	 * @param string $ip
	 * @param string $ua
	 * @param string $action    moderated | spam | rejected
	 */
	private function log_comment_event( $commentdata, $scoring, $ip, $ua, $action ) {
		$log = get_option( self::OPTION_COMMENT_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$entry = array(
			'time'       => time(),
			'author'     => isset( $commentdata['comment_author'] ) ? substr( (string) $commentdata['comment_author'], 0, 80 ) : '',
			'email'      => isset( $commentdata['comment_author_email'] ) ? substr( (string) $commentdata['comment_author_email'], 0, 120 ) : '',
			'url'        => isset( $commentdata['comment_author_url'] ) ? substr( (string) $commentdata['comment_author_url'], 0, 200 ) : '',
			'post_id'    => isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0,
			'ip'         => $this->mask_ip( $ip ),
			'ua'         => substr( $ua, 0, 200 ),
			'score'      => (int) $scoring['score'],
			'signals'    => array_map( function ( $sig ) {
				return array( 'name' => $sig['name'], 'weight' => (int) $sig['weight'], 'detail' => substr( (string) $sig['detail'], 0, 120 ) );
			}, (array) $scoring['signals'] ),
			'link_count' => (int) ( $scoring['link_count'] ?? 0 ),
			'action'     => $action,
			'snippet'    => substr( wp_strip_all_tags( (string) ( $commentdata['comment_content'] ?? '' ) ), 0, 240 ),
		);
		array_unshift( $log, $entry );
		if ( count( $log ) > self::COMMENT_LOG_MAX ) {
			$log = array_slice( $log, 0, self::COMMENT_LOG_MAX );
		}
		update_option( self::OPTION_COMMENT_LOG, $log, false );
	}

	/**
	 * Return recent comment-catch events (newest first).
	 *
	 * @param  int $limit  Default 50, cap at COMMENT_LOG_MAX.
	 * @return array<int,array>
	 */
	public function get_recent_comment_events( $limit = 50 ) {
		$log = (array) get_option( self::OPTION_COMMENT_LOG, array() );
		$limit = max( 1, min( self::COMMENT_LOG_MAX, (int) $limit ) );
		return array_slice( $log, 0, $limit );
	}

	/**
	 * Dry-run a comment payload against the current rule set and return
	 * the verdict + signal breakdown. Used by the admin UI test panel
	 * and the matching MCP tool.
	 *
	 * @param  array $payload  ['author','email','url','content','ip','post_id']
	 * @return array
	 */
	public function test_comment_payload( array $payload ) {
		$commentdata = array(
			'comment_author'        => isset( $payload['author'] )   ? (string) $payload['author']   : '',
			'comment_author_email'  => isset( $payload['email'] )    ? (string) $payload['email']    : '',
			'comment_author_url'    => isset( $payload['url'] )      ? (string) $payload['url']      : '',
			'comment_content'       => isset( $payload['content'] )  ? (string) $payload['content']  : '',
			'comment_post_ID'       => isset( $payload['post_id'] )  ? (int) $payload['post_id']     : 0,
			'comment_type'          => '',
		);
		$ip = isset( $payload['ip'] ) && filter_var( $payload['ip'], FILTER_VALIDATE_IP ) ? (string) $payload['ip'] : '0.0.0.0';

		$scoring   = $this->score_comment( $commentdata, $ip );
		$s         = $this->get_settings();
		$threshold = (int) ( $s['comments_threshold'] ?? 40 );
		$mode      = isset( $s['comments_mode'] ) ? (string) $s['comments_mode'] : 'moderate';

		$verdict = 'allow';
		if ( $scoring['score'] >= $threshold ) {
			$verdict = $mode === 'off' ? 'allow' : $mode; // moderate / spam / reject
		}

		return array(
			'verdict'   => $verdict,
			'score'     => (int) $scoring['score'],
			'threshold' => $threshold,
			'signals'   => $scoring['signals'],
			'mode'      => $mode,
			'enabled'   => ! empty( $s['comments_enabled'] ),
		);
	}
}
