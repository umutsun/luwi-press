<?php
/**
 * Bot Account Cleaner — score-based detection + safe deletion of fake/bot
 * WordPress user accounts (typical spam signup on long-lived WooCommerce
 * stores: disposable email + 0 orders + 0 comments + 0 logins).
 *
 * Detection signals (each contributes to a 0-100 score; threshold-based):
 *
 *   - Email pattern:
 *       - Disposable / temp-mail domain (200+ domain blocklist).
 *       - `+` alias on gmail/outlook/yahoo (bot-farming technique).
 *       - High-entropy local part (8+ chars of random alphanumerics).
 *       - Numeric-only or numeric-tail local part (e.g. `xqkj4829`).
 *   - Username pattern:
 *       - Random alphanumeric, same heuristics as email local part.
 *       - Numeric suffix on a real-looking root (`john847362`).
 *   - Activity:
 *       - 0 WooCommerce orders (when WC active).
 *       - 0 comments.
 *       - 0 logins after registration (no `wp_user-settings` / `session_tokens`).
 *       - Registration age > 30 days (the older + still inactive = stronger signal).
 *   - Profile completeness:
 *       - Empty display_name / first_name / last_name.
 *       - display_name === user_login (auto-set default).
 *       - No avatar uploaded (Gravatar default doesn't count as "real").
 *   - Burst registration:
 *       - 10+ accounts created in the same hour share the score-boost.
 *   - Role guard:
 *       - Only `subscriber` and `customer` are eligible for deletion.
 *       - `administrator`, `editor`, `shop_manager`, `author` are NEVER touched.
 *
 * Safety rules (3 protected tiers — hard-coded, not toggleable):
 *
 *   1. `has_orders` — a user with ANY WooCommerce order is NEVER auto-deleted,
 *      regardless of score.
 *   2. `email_verified` (3.2.7+) — proven inbox via WC Email Verification
 *      meta, generic meta keys, or `luwipress_user_email_verified` filter.
 *   3. `realistic_name` (3.2.7+) — both first + last name populated, letters-
 *      only, vowel-bearing, non-repeating, distinct from user_login.
 *      Override via `luwipress_user_has_realistic_name` filter.
 *
 *   - Whitelist is permanent until removed; whitelisted users are skipped
 *     in every scan + can't be deleted via this module's REST/MCP surface.
 *   - Hard delete reassigns post authorship to admin (user_id 1 fallback)
 *     and removes WP user row + usermeta. WC customer data follows
 *     `wp_delete_user`'s standard hooks (privacy plugins can intercept).
 *   - Dry-run is the default for bulk delete; pass `confirm=true` to execute.
 *
 * Operator workflow:
 *
 *   1. Run scan → produces ranked list of suspects (`luwipress_bot_account_scores` table).
 *   2. Review high-score rows in admin UI or via MCP.
 *   3. Whitelist any false positives.
 *   4. Bulk delete (dry-run first), then `confirm=true` once satisfied.
 *      For thousand-scale cleanups use the animated "Sweep matching" UI
 *      which calls /delete-by-filter in cursor-paginated chunks.
 *
 * REST surface (admin auth via LuwiPress_Permission::check_token_or_admin):
 *   - GET  /luwipress/v1/bot-accounts/scan             — trigger fresh scan
 *   - GET  /luwipress/v1/bot-accounts/list             — paginated suspect list
 *   - GET  /luwipress/v1/bot-accounts/score/{id}       — single-user score breakdown
 *   - POST /luwipress/v1/bot-accounts/delete           — bulk delete by IDs (dry-run default)
 *   - POST /luwipress/v1/bot-accounts/delete-by-filter — cursor-paginated chunked
 *                                                        delete at threshold (3.2.7+)
 *   - POST /luwipress/v1/bot-accounts/whitelist        — add/remove whitelist
 *   - GET  /luwipress/v1/bot-accounts/stats            — aggregate counts
 *   - GET  /luwipress/v1/bot-accounts/settings         — threshold + min-age + role-allow
 *   - POST /luwipress/v1/bot-accounts/settings         — partial-update settings
 *
 * @package LuwiPress
 * @since   3.1.60
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Bot_Account_Cleaner {

	const TABLE_SUFFIX           = 'luwipress_bot_account_scores';
	const OPTION_SETTINGS        = 'luwipress_bot_account_settings';
	const OPTION_WHITELIST       = 'luwipress_bot_account_whitelist';
	const OPTION_LAST_SCAN       = 'luwipress_bot_account_last_scan';
	const TRANSIENT_BURST_CACHE  = 'luwipress_bot_account_burst_cache';

	/** Minimum score (out of 100) to consider a user a suspect.
	 *  Lowered from 60 → 50 in 3.2.2 alongside the compound-inactivity signal:
	 *  the new signal pushes typical "register-and-vanish" bots from ~48 → ~63,
	 *  but a slightly lower threshold also surfaces the borderline tier where
	 *  the most actionable false-negatives previously hid. */
	const DEFAULT_THRESHOLD = 50;

	/** Minimum age in days before a 0-activity account is flagged. */
	const DEFAULT_MIN_AGE_DAYS = 30;

	private static $instance = null;

	/** @var array<string,bool>|null Lazy-loaded disposable email domain set. */
	private $disposable_domains = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	/**
	 * Create the scores table. Called from the main plugin's activate() hook.
	 * Schema:
	 *   id          — PK
	 *   user_id     — WP user id (unique)
	 *   score       — 0-100
	 *   signals     — JSON dict of signal -> contribution
	 *   status      — 'scored' | 'whitelisted' | 'deleted'
	 *   scanned_at  — datetime
	 */
	public static function create_table() {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE_SUFFIX;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			score TINYINT UNSIGNED NOT NULL DEFAULT 0,
			signals LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'scored',
			scanned_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			KEY score_status (score, status),
			KEY scanned_at (scanned_at)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// -------------------- Settings + Whitelist --------------------

	/**
	 * Effective settings (defaults merged with stored option).
	 */
	public function get_settings() {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$defaults = array(
			'threshold'              => self::DEFAULT_THRESHOLD,
			'min_age_days'           => self::DEFAULT_MIN_AGE_DAYS,
			'allowed_roles'          => array( 'subscriber', 'customer' ),
			'protect_roles'          => array( 'administrator', 'editor', 'shop_manager', 'author', 'contributor' ),
			'protect_with_orders'    => true,
			'protect_email_verified' => true,
			'protect_realistic_name' => true,
			'scan_batch_size'        => 500,
		);
		return array_merge( $defaults, $stored );
	}

	public function update_settings( array $patch ) {
		$current = $this->get_settings();
		$next    = $current;

		if ( array_key_exists( 'threshold', $patch ) ) {
			$next['threshold'] = max( 0, min( 100, (int) $patch['threshold'] ) );
		}
		if ( array_key_exists( 'min_age_days', $patch ) ) {
			$next['min_age_days'] = max( 0, (int) $patch['min_age_days'] );
		}
		if ( array_key_exists( 'allowed_roles', $patch ) && is_array( $patch['allowed_roles'] ) ) {
			$next['allowed_roles'] = array_values( array_unique( array_map( 'sanitize_key', $patch['allowed_roles'] ) ) );
		}
		if ( array_key_exists( 'scan_batch_size', $patch ) ) {
			$next['scan_batch_size'] = max( 50, min( 5000, (int) $patch['scan_batch_size'] ) );
		}

		// Protect_roles + protect_with_orders are NOT operator-tweakable — these
		// are safety invariants. Storing them in the option only to keep the
		// shape stable across reads; we always re-merge defaults on get_settings.
		update_option( self::OPTION_SETTINGS, $next, false );
		return $next;
	}

	public function get_whitelist() {
		$list = get_option( self::OPTION_WHITELIST, array() );
		if ( ! is_array( $list ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'intval', $list ) ) );
	}

	public function set_whitelist( array $user_ids ) {
		$clean = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		update_option( self::OPTION_WHITELIST, $clean, false );

		// Mark whitelisted rows in the scores table so the UI hides them.
		if ( ! empty( $clean ) ) {
			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_SUFFIX;
			$placeholders = implode( ',', array_fill( 0, count( $clean ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from $wpdb->prefix and a class constant.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'whitelisted' WHERE user_id IN ({$placeholders})", $clean ) );
		}

		return $clean;
	}

	public function whitelist_add( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) return false;
		$list = $this->get_whitelist();
		if ( ! in_array( $user_id, $list, true ) ) {
			$list[] = $user_id;
			$this->set_whitelist( $list );
		}
		return true;
	}

	public function whitelist_remove( $user_id ) {
		$user_id = (int) $user_id;
		$list = array_values( array_filter( $this->get_whitelist(), function ( $id ) use ( $user_id ) {
			return $id !== $user_id;
		} ) );
		update_option( self::OPTION_WHITELIST, $list, false );

		// Demote the row back to 'scored' so the UI shows it again.
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$wpdb->update( $table, array( 'status' => 'scored' ), array( 'user_id' => $user_id ) );

		return true;
	}

	// -------------------- Scoring engine --------------------

	/**
	 * Compute the bot-likelihood score for a single user.
	 *
	 * @param int|WP_User $user
	 * @return array{score:int,signals:array<string,int>,user_id:int,protected:bool,reason?:string}
	 */
	public function score_user( $user ) {
		$wp_user = is_object( $user ) ? $user : get_user_by( 'id', (int) $user );
		if ( ! $wp_user instanceof \WP_User ) {
			return array( 'score' => 0, 'signals' => array(), 'user_id' => 0, 'protected' => true, 'reason' => 'not_found' );
		}
		$settings = $this->get_settings();

		// Role protection short-circuit — protected roles get score 0.
		$roles = (array) $wp_user->roles;
		foreach ( (array) $settings['protect_roles'] as $protected_role ) {
			if ( in_array( $protected_role, $roles, true ) ) {
				return array(
					'score'     => 0,
					'signals'   => array( 'protected_role' => -100 ),
					'user_id'   => $wp_user->ID,
					'protected' => true,
					'reason'    => 'protected_role:' . $protected_role,
				);
			}
		}

		// Only eligible roles get scored.
		$eligible = false;
		foreach ( (array) $settings['allowed_roles'] as $allowed_role ) {
			if ( in_array( $allowed_role, $roles, true ) ) {
				$eligible = true;
				break;
			}
		}
		if ( ! $eligible ) {
			return array(
				'score'     => 0,
				'signals'   => array( 'role_not_eligible' => 0 ),
				'user_id'   => $wp_user->ID,
				'protected' => true,
				'reason'    => 'role_not_eligible',
			);
		}

		// Whitelist guard.
		if ( in_array( (int) $wp_user->ID, $this->get_whitelist(), true ) ) {
			return array(
				'score'     => 0,
				'signals'   => array( 'whitelisted' => -100 ),
				'user_id'   => $wp_user->ID,
				'protected' => true,
				'reason'    => 'whitelisted',
			);
		}

		// WC order guard — never score a paying customer as bot.
		if ( ! empty( $settings['protect_with_orders'] ) && $this->user_has_orders( $wp_user->ID ) ) {
			return array(
				'score'     => 0,
				'signals'   => array( 'has_orders' => -100 ),
				'user_id'   => $wp_user->ID,
				'protected' => true,
				'reason'    => 'has_orders',
			);
		}

		// Email-verified guard — a user who proved control of their inbox is not a bot.
		// Detected via WC Email Verification meta, generic plugin meta keys, or
		// the `luwipress_user_email_verified` filter for custom integrations.
		if ( ! empty( $settings['protect_email_verified'] ) && $this->is_email_verified( $wp_user ) ) {
			return array(
				'score'     => 0,
				'signals'   => array( 'email_verified' => -100 ),
				'user_id'   => $wp_user->ID,
				'protected' => true,
				'reason'    => 'email_verified',
			);
		}

		// Realistic-name guard (tier 3) — bots almost never populate both
		// first + last with vowel-bearing, letters-only, non-repeating tokens
		// that diverge from user_login. When they do, the cost of misclassifying
		// is too high. See has_realistic_name() for the full heuristic.
		if ( ! empty( $settings['protect_realistic_name'] ) && $this->has_realistic_name( $wp_user ) ) {
			return array(
				'score'     => 0,
				'signals'   => array( 'realistic_name' => -100 ),
				'user_id'   => $wp_user->ID,
				'protected' => true,
				'reason'    => 'realistic_name',
			);
		}

		$signals = array();

		// --- Email signals ---
		$email = strtolower( (string) $wp_user->user_email );
		if ( $email !== '' && strpos( $email, '@' ) !== false ) {
			list( $local, $domain ) = explode( '@', $email, 2 );
			if ( $this->is_disposable_domain( $domain ) ) {
				$signals['disposable_domain'] = 35;
			}
			if ( $this->is_plus_alias( $local ) ) {
				$signals['plus_alias'] = 10;
			}
			if ( $this->is_high_entropy( $local ) ) {
				$signals['random_email_local'] = 20;
			}
			if ( preg_match( '/[a-z]+\d{4,}$/i', $local ) ) {
				$signals['numeric_tail_email'] = 8;
			}
			// Email local part that's 100% digits (e.g. `382947@gmail.com`) — a
			// very common throwaway-signup pattern that the numeric-tail regex
			// above misses because it requires letters first.
			if ( preg_match( '/^\d{4,}$/', $local ) ) {
				$signals['numeric_only_email'] = 12;
			}
		}

		// --- Username signals ---
		$login = (string) $wp_user->user_login;
		if ( $login !== '' && strtolower( $login ) !== strtolower( $local ?? '' ) ) {
			// Only score username separately when it diverges from email local
			// (otherwise we'd double-count the same entropy signal).
			if ( $this->is_high_entropy( $login ) ) {
				$signals['random_username'] = 15;
			}
		}
		if ( preg_match( '/^[a-z]+\d{4,}$/i', $login ) ) {
			$signals['numeric_tail_username'] = 5;
		}
		if ( preg_match( '/^\d{4,}$/', $login ) ) {
			$signals['numeric_only_username'] = 8;
		}

		// --- Activity signals ---
		$comment_count = (int) get_comments( array( 'user_id' => $wp_user->ID, 'count' => true ) );
		if ( $comment_count === 0 ) {
			$signals['no_comments'] = 5;
		}

		$has_session = get_user_meta( $wp_user->ID, 'session_tokens', true );
		$has_settings = get_user_meta( $wp_user->ID, 'wp_user-settings', true );
		if ( empty( $has_session ) && empty( $has_settings ) ) {
			$signals['never_logged_in'] = 15;
		}

		$registered_ts = strtotime( (string) $wp_user->user_registered );
		$age_days = $registered_ts ? max( 0, (int) floor( ( time() - $registered_ts ) / DAY_IN_SECONDS ) ) : 0;
		if ( $age_days >= (int) $settings['min_age_days'] ) {
			$signals['stale_account'] = 10;
		}

		// --- Profile completeness ---
		$display = trim( (string) $wp_user->display_name );
		$first   = trim( (string) get_user_meta( $wp_user->ID, 'first_name', true ) );
		$last    = trim( (string) get_user_meta( $wp_user->ID, 'last_name', true ) );
		if ( $first === '' && $last === '' ) {
			$signals['no_real_name'] = 5;
		}
		if ( $display === $login || $display === '' ) {
			$signals['default_display_name'] = 5;
		}

		// --- Burst registration ---
		if ( $registered_ts && $this->is_burst_registration( $registered_ts ) ) {
			$signals['burst_registration'] = 12;
		}

		// --- Compound zero-activity bonus (3.2.2) ---
		// Without this, a typical "user123@gmail.com" with no orders / no logins /
		// stale / no real name / default display name lands at ~48 and slips under
		// any threshold >= 50. The individual penalties are weak on purpose (each
		// one alone is a weak signal), but ALL of them firing together is a strong
		// passive-bot signature. +15 here pushes that profile to ~63 = flagged.
		$has_stale       = isset( $signals['stale_account'] );
		$has_no_login    = isset( $signals['never_logged_in'] );
		$has_no_comments = isset( $signals['no_comments'] );
		$has_no_name     = isset( $signals['no_real_name'] );
		$has_default_dn  = isset( $signals['default_display_name'] );
		if ( $has_stale && $has_no_login && $has_no_comments && $has_no_name && $has_default_dn ) {
			$signals['compound_inactive'] = 15;
		}

		$score = 0;
		foreach ( $signals as $weight ) {
			$score += (int) $weight;
		}
		$score = max( 0, min( 100, $score ) );

		/**
		 * Allow operators / extensions to adjust the score or add custom signals.
		 * Filter may return either:
		 *   - an int (replaces the score)
		 *   - an array with keys 'score' and 'signals' (replaces both)
		 *
		 * @param int       $score   0-100
		 * @param array     $signals signal => weight
		 * @param \WP_User  $wp_user
		 */
		$adjusted = apply_filters( 'luwipress_bot_account_score', $score, $signals, $wp_user );
		/** @var mixed $adjusted */
		if ( is_array( $adjusted ) ) {
			if ( isset( $adjusted['score'] ) && isset( $adjusted['signals'] ) && is_array( $adjusted['signals'] ) ) {
				$score   = max( 0, min( 100, (int) $adjusted['score'] ) );
				$signals = $adjusted['signals'];
			}
		} else {
			$score = max( 0, min( 100, (int) $adjusted ) );
		}

		return array(
			'score'     => $score,
			'signals'   => $signals,
			'user_id'   => (int) $wp_user->ID,
			'protected' => false,
		);
	}

	/**
	 * Run scan across all users in eligible roles. Iterates through every
	 * page of eligible users up to a 25-second time budget so older accounts
	 * get covered too — the previous one-batch implementation only saw the
	 * newest 500 users which let bot accounts older than that slip through.
	 *
	 * @param int|null $limit       Per-batch size (defaults to settings['scan_batch_size'])
	 * @param int      $start_offset User-query offset to start from (cursor threaded
	 *                              across multi-call scans so the next call picks up
	 *                              where the last one's time budget ran out)
	 * @return array<string,mixed> Summary: scanned, total, flagged, protected, complete, next_offset, finished_at
	 */
	public function run_scan( $limit = null, $start_offset = 0 ) {
		global $wpdb;
		$table    = $wpdb->prefix . self::TABLE_SUFFIX;
		$settings = $this->get_settings();
		$batch    = $limit ? (int) $limit : (int) $settings['scan_batch_size'];

		// Total eligible user count — only used for progress reporting and the
		// `complete` flag the front-end uses to decide whether to re-fire.
		$count_query = new \WP_User_Query( array(
			'role__in'    => (array) $settings['allowed_roles'],
			'number'      => 1,
			'count_total' => true,
			'fields'      => 'ID',
		) );
		$total_users = (int) $count_query->get_total();

		// Time budget: stop iterating after ~25s so PHP doesn't time out on
		// large user tables. The remaining accounts get picked up on the next
		// call (front-end re-fires the scan when `complete` is false).
		$time_budget    = 25.0;
		$start_time     = microtime( true );
		$now            = current_time( 'mysql' );
		$offset         = max( 0, (int) $start_offset );
		$scanned        = 0;
		$flagged        = 0;
		$protected_count = 0;

		while ( true ) {
			$users = get_users( array(
				'role__in' => (array) $settings['allowed_roles'],
				'number'   => $batch,
				'offset'   => $offset,
				'fields'   => array( 'ID', 'user_login', 'user_email', 'display_name', 'user_registered' ),
				'orderby'  => 'registered',
				'order'    => 'DESC',
			) );
			if ( empty( $users ) ) {
				break; // No more eligible users.
			}

			foreach ( $users as $u ) {
				$scanned++;
				$result = $this->score_user( (int) $u->ID );

				if ( $result['protected'] ) {
					$protected_count++;
					continue;
				}

				if ( $result['score'] >= (int) $settings['threshold'] ) {
					$flagged++;
				}

				$wpdb->replace(
					$table,
					array(
						'user_id'    => $result['user_id'],
						'score'      => $result['score'],
						'signals'    => wp_json_encode( $result['signals'] ),
						'status'     => 'scored',
						'scanned_at' => $now,
					),
					array( '%d', '%d', '%s', '%s', '%s' )
				);
			}

			$offset += $batch;

			// Budget exhausted → stop iterating; caller can re-fire to continue.
			if ( ( microtime( true ) - $start_time ) > $time_budget ) {
				break;
			}
		}

		$complete = ( $offset >= $total_users );
		$summary = array(
			'scanned'     => $scanned,
			'total'       => $total_users,
			'flagged'     => $flagged,
			'protected'   => $protected_count,
			'threshold'   => (int) $settings['threshold'],
			'finished_at' => $now,
			'complete'    => $complete,
			'next_offset' => $complete ? 0 : $offset,
			'duration_s'  => round( microtime( true ) - $start_time, 2 ),
		);
		update_option( self::OPTION_LAST_SCAN, $summary, false );

		if ( class_exists( 'LuwiPress_Logger' ) ) {
			LuwiPress_Logger::log( 'Bot account scan completed', 'info', $summary );
		}

		return $summary;
	}

	/**
	 * Delete users by id. Honors dry-run by default.
	 *
	 * @param int[] $user_ids
	 * @param bool  $confirm  false = dry-run, true = actually delete
	 * @return array{deleted:int[],skipped:int[],errors:array<int,string>,dry_run:bool}
	 */
	public function delete_users( array $user_ids, $confirm = false ) {
		$deleted = array();
		$skipped = array();
		$errors  = array();

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		// Reassign content to user 1 (typically admin) so orphan posts don't
		// vanish silently. If user 1 is one of the targets we abort that one.
		$reassign_to = 1;

		foreach ( $user_ids as $uid ) {
			$uid = (int) $uid;
			if ( $uid <= 0 || $uid === $reassign_to ) {
				$errors[ $uid ] = 'invalid_or_reassign_target';
				$skipped[] = $uid;
				continue;
			}

			$user = get_user_by( 'id', $uid );
			if ( ! $user ) {
				$errors[ $uid ] = 'not_found';
				$skipped[] = $uid;
				continue;
			}

			// Re-score on the fly to enforce protection invariants — never
			// trust the stored score for a destructive op.
			$rescore = $this->score_user( $user );
			if ( $rescore['protected'] ) {
				$errors[ $uid ] = 'protected:' . ( $rescore['reason'] ?? 'unknown' );
				$skipped[] = $uid;
				continue;
			}

			if ( ! $confirm ) {
				$deleted[] = $uid; // Dry-run: report what would be deleted.
				continue;
			}

			$ok = wp_delete_user( $uid, $reassign_to );
			if ( $ok ) {
				$deleted[] = $uid;
				global $wpdb;
				$table = $wpdb->prefix . self::TABLE_SUFFIX;
				$wpdb->update( $table, array( 'status' => 'deleted' ), array( 'user_id' => $uid ) );
			} else {
				$errors[ $uid ] = 'wp_delete_user_failed';
				$skipped[] = $uid;
			}
		}

		if ( class_exists( 'LuwiPress_Logger' ) ) {
			LuwiPress_Logger::log(
				$confirm ? 'Bot accounts deleted' : 'Bot accounts dry-run',
				$confirm ? 'warning' : 'info',
				array(
					'deleted_count' => count( $deleted ),
					'skipped_count' => count( $skipped ),
					'dry_run'       => ! $confirm,
				)
			);
		}

		return array(
			'deleted' => $deleted,
			'skipped' => $skipped,
			'errors'  => $errors,
			'dry_run' => ! $confirm,
		);
	}

	/**
	 * Cursor-paginated chunked delete across all suspects at a score threshold.
	 *
	 * Designed for the admin "Sweep suspects" animated flow: client calls in a
	 * loop, passing `after_user_id` from the previous response, until
	 * `complete: true`. Server is stateless; cursor lives in the client.
	 *
	 * Cursor-by-id (not OFFSET) is deliberate — OFFSET would skip rows after
	 * deletes change row positions in the result set, AND would loop forever
	 * over skipped/protected rows in confirm mode. Cursor advances past every
	 * row the chunk touched, regardless of outcome.
	 *
	 * Each ID still goes through `delete_users()` which re-scores at delete
	 * time, so the protected guards (orders, email-verified, role, whitelist)
	 * fire even if the suspect-table snapshot is stale.
	 *
	 * @param int      $min_score     Score floor (clamped to [40, 100]).
	 * @param bool     $confirm       false = dry-run (count what would happen).
	 * @param int      $limit         Batch size (clamped to [1, 50]).
	 * @param int      $after_user_id Cursor; only rows with user_id > this are picked up.
	 * @return array<string,mixed>
	 */
	public function delete_by_filter( $min_score, $confirm, $limit = 10, $after_user_id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$min_score     = max( 40, min( 100, (int) $min_score ) );
		$limit         = max( 1, min( 50, (int) $limit ) );
		$after_user_id = max( 0, (int) $after_user_id );
		$confirm       = (bool) $confirm;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT user_id FROM {$table} /* luwipress-audit:ignore */
			 WHERE status = 'scored' AND score >= %d AND user_id > %d
			 ORDER BY user_id ASC
			 LIMIT %d",
			$min_score, $after_user_id, $limit
		) );
		$ids = array_map( 'intval', (array) $ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from $wpdb->prefix and a class constant. luwipress-audit:ignore
		$total_remaining = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} /* luwipress-audit:ignore */ WHERE status = 'scored' AND score >= %d",
			$min_score
		) );

		if ( empty( $ids ) ) {
			return array(
				'deleted'         => array(),
				'skipped'         => array(),
				'processed'       => 0,
				'total_remaining' => $total_remaining,
				'next_cursor'     => $after_user_id,
				'complete'        => true,
				'dry_run'         => ! $confirm,
				'min_score'       => $min_score,
			);
		}

		$result = $this->delete_users( $ids, $confirm );

		$skipped_with_reason = array();
		foreach ( (array) $result['skipped'] as $uid ) {
			$skipped_with_reason[] = array(
				'id'     => (int) $uid,
				'reason' => isset( $result['errors'][ $uid ] ) ? (string) $result['errors'][ $uid ] : 'unknown',
			);
		}

		$next_cursor = (int) max( $ids );
		$complete    = count( $ids ) < $limit;

		return array(
			'deleted'         => array_map( 'intval', (array) $result['deleted'] ),
			'skipped'         => $skipped_with_reason,
			'processed'       => count( $ids ),
			'total_remaining' => $total_remaining,
			'next_cursor'     => $next_cursor,
			'complete'        => $complete,
			'dry_run'         => ! $confirm,
			'min_score'       => $min_score,
		);
	}

	/**
	 * Suspect list with score >= threshold, ordered by score desc.
	 */
	public function list_suspects( $page = 1, $per_page = 50, $min_score = null ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 200, (int) $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$settings  = $this->get_settings();
		$threshold = $min_score !== null ? max( 0, min( 100, (int) $min_score ) ) : (int) $settings['threshold'];

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, user_id, score, signals, status, scanned_at
			 FROM {$table} /* luwipress-audit:ignore */
			 WHERE status = 'scored' AND score >= %d
			 ORDER BY score DESC, scanned_at DESC
			 LIMIT %d OFFSET %d",
			$threshold, $per_page, $offset
		), ARRAY_A );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} /* luwipress-audit:ignore */ WHERE status = 'scored' AND score >= %d",
			$threshold
		) );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$u = get_user_by( 'id', (int) $r['user_id'] );
			if ( ! $u ) continue;
			$out[] = array(
				'user_id'         => (int) $r['user_id'],
				'score'           => (int) $r['score'],
				'signals'         => json_decode( (string) $r['signals'], true ),
				'status'          => (string) $r['status'],
				'scanned_at'      => (string) $r['scanned_at'],
				'user_login'      => $u->user_login,
				'user_email'      => $u->user_email,
				'display_name'    => $u->display_name,
				'user_registered' => $u->user_registered,
				'roles'           => $u->roles,
			);
		}

		return array(
			'items'      => $out,
			'total'      => $total,
			'page'       => $page,
			'per_page'   => $per_page,
			'threshold'  => $threshold,
		);
	}

	public function get_stats() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$counts = $wpdb->get_results(
			"SELECT status, COUNT(*) as n FROM {$table} /* luwipress-audit:ignore */ GROUP BY status",
			ARRAY_A
		);
		$by_status = array( 'scored' => 0, 'whitelisted' => 0, 'deleted' => 0 );
		foreach ( (array) $counts as $row ) {
			$by_status[ $row['status'] ] = (int) $row['n'];
		}

		$settings  = $this->get_settings();
		$threshold = (int) $settings['threshold'];

		$flagged = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} /* luwipress-audit:ignore */ WHERE status = 'scored' AND score >= %d",
			$threshold
		) );

		$buckets = $wpdb->get_results(
			"SELECT
				SUM(CASE WHEN score >= 80 THEN 1 ELSE 0 END) as high,
				SUM(CASE WHEN score >= 60 AND score < 80 THEN 1 ELSE 0 END) as medium,
				SUM(CASE WHEN score >= 40 AND score < 60 THEN 1 ELSE 0 END) as low,
				SUM(CASE WHEN score < 40 THEN 1 ELSE 0 END) as noise
			 FROM {$table} WHERE status = 'scored'",
			ARRAY_A
		);
		$buckets = ! empty( $buckets[0] ) ? array_map( 'intval', $buckets[0] ) : array( 'high' => 0, 'medium' => 0, 'low' => 0, 'noise' => 0 );

		return array(
			'by_status'   => $by_status,
			'flagged'     => $flagged,
			'threshold'   => $threshold,
			'buckets'     => $buckets,
			'whitelist_size' => count( $this->get_whitelist() ),
			'last_scan'   => get_option( self::OPTION_LAST_SCAN, null ),
		);
	}

	// -------------------- Signal helpers --------------------

	private function user_has_orders( $user_id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}
		$orders = wc_get_orders( array(
			'customer_id' => (int) $user_id,
			'limit'       => 1,
			'return'      => 'ids',
		) );
		return ! empty( $orders );
	}

	/**
	 * Email-verified detection across known plugin conventions.
	 *
	 * Detection sources (checked in order, first match wins):
	 *   1. Filter `luwipress_user_email_verified` — returns null to pass through,
	 *      bool to override. Lets custom integrations declare a user verified.
	 *   2. WooCommerce Email Verification (WPFactory + variants): `_wc_email_verified`
	 *      user meta is '1' / truthy.
	 *   3. Generic plugin meta keys: `email_verified`, `is_email_verified`,
	 *      `_email_verified`, `wc_email_verified`.
	 *   4. Default false (not detectable → treat as unverified, score normally).
	 *
	 * @param \WP_User $wp_user
	 * @return bool
	 */
	private function is_email_verified( $wp_user ) {
		if ( ! $wp_user instanceof \WP_User ) {
			return false;
		}

		/**
		 * Override hook for custom email-verification integrations.
		 *
		 * @param bool|null $verified null to skip override, bool to force.
		 * @param \WP_User  $wp_user
		 */
		$override = apply_filters( 'luwipress_user_email_verified', null, $wp_user );
		if ( $override !== null ) {
			return (bool) $override;
		}

		$truthy_meta_keys = array(
			'_wc_email_verified',
			'wc_email_verified',
			'email_verified',
			'is_email_verified',
			'_email_verified',
		);
		foreach ( $truthy_meta_keys as $key ) {
			$val = get_user_meta( $wp_user->ID, $key, true );
			if ( $val === '1' || $val === 1 || $val === true || $val === 'yes' || $val === 'true' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Heuristic: does this user look like a real human based on their
	 * first_name + last_name profile fields?
	 *
	 * A name "part" is realistic when ALL of these hold:
	 *   - 2..40 chars after trim
	 *   - Contains at least 2 letters (unicode-aware: Turkish, accented OK)
	 *   - Contains at least one vowel (latin + Turkish ı/i set)
	 *   - No digits
	 *   - Not a simple repetition (e.g. "asdasd", "abcabc")
	 *   - Doesn't contain known keyboard-mash sequences (asdf, qwer, zxcv...)
	 *
	 * Then the user qualifies when:
	 *   - Both first_name and last_name are realistic
	 *   - Neither equals user_login (case-insensitive)
	 *   - first != last (no "Aaron Aaron" defaults)
	 *
	 * The `luwipress_user_has_realistic_name` filter lets integrations
	 * override the verdict (e.g. CRM with explicit human-confirmed flag).
	 *
	 * @param \WP_User $wp_user
	 * @return bool
	 */
	private function has_realistic_name( $wp_user ) {
		if ( ! $wp_user instanceof \WP_User ) {
			return false;
		}
		$first = (string) get_user_meta( $wp_user->ID, 'first_name', true );
		$last  = (string) get_user_meta( $wp_user->ID, 'last_name', true );

		if ( ! $this->looks_like_realistic_name_part( $first ) ) {
			return false;
		}
		if ( ! $this->looks_like_realistic_name_part( $last ) ) {
			return false;
		}

		$login_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $wp_user->user_login, 'UTF-8' ) : strtolower( (string) $wp_user->user_login );
		$first_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $first, 'UTF-8' ) : strtolower( $first );
		$last_lc  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $last,  'UTF-8' ) : strtolower( $last );

		if ( $first_lc === $login_lc || $last_lc === $login_lc ) {
			return false;
		}
		if ( $first_lc === $last_lc ) {
			return false;
		}

		/**
		 * Override the realistic-name verdict.
		 *
		 * @param bool     $is_realistic
		 * @param \WP_User $wp_user
		 * @param string   $first
		 * @param string   $last
		 */
		return (bool) apply_filters( 'luwipress_user_has_realistic_name', true, $wp_user, $first, $last );
	}

	/**
	 * Per-part realism check used by has_realistic_name().
	 *
	 * @param string $s Raw name token (first OR last).
	 * @return bool
	 */
	private function looks_like_realistic_name_part( $s ) {
		$s = trim( (string) $s );
		if ( $s === '' ) {
			return false;
		}
		$len_chars = function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
		if ( $len_chars < 2 || $len_chars > 40 ) {
			return false;
		}
		// At least 2 letters (unicode-aware) — \p{L} matches Turkish, accents, CJK.
		if ( ! preg_match( '/\p{L}.*\p{L}/u', $s ) ) {
			return false;
		}
		// Reject digits in name (real names don't have digits).
		if ( preg_match( '/\d/', $s ) ) {
			return false;
		}
		// Require at least one vowel-ish character (latin + Turkish + common accented).
		if ( ! preg_match( '/[aeiouıâêîôûäëïöüáéíóúàèìòùAEIOUİÂÊÎÔÛÄËÏÖÜÁÉÍÓÚÀÈÌÒÙ]/u', $s ) ) {
			return false;
		}
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		// Reject simple-half repetitions ("asdasd", "abcabc", "yoyoyo").
		$half_chars = (int) floor( $len_chars / 2 );
		if ( $half_chars >= 2 ) {
			$h1 = function_exists( 'mb_substr' ) ? mb_substr( $lower, 0, $half_chars, 'UTF-8' ) : substr( $lower, 0, $half_chars );
			$h2 = function_exists( 'mb_substr' ) ? mb_substr( $lower, $half_chars, $half_chars, 'UTF-8' ) : substr( $lower, $half_chars, $half_chars );
			if ( $h1 === $h2 ) {
				return false;
			}
		}
		// Reject common keyboard-mash sequences.
		$mashes = array( 'asdf', 'asdfg', 'qwer', 'qwert', 'qwerty', 'zxcv', 'zxcvb', 'hjkl', 'sdfg', 'wxcv', 'wsxedc' );
		foreach ( $mashes as $m ) {
			if ( strpos( $lower, $m ) !== false ) {
				return false;
			}
		}
		// Reject 3+ same char in a row ("aaaa", "lllo").
		if ( preg_match( '/(\p{L})\1{2,}/u', $lower ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Shannon-entropy-style cheap heuristic: a string with many distinct chars
	 * across letters + digits and no recognizable substring scores high. We
	 * proxy this with: length >= 8 AND mixed case/digits AND no English word.
	 */
	private function is_high_entropy( $s ) {
		$s = (string) $s;
		if ( strlen( $s ) < 8 ) {
			return false;
		}
		$has_letter = preg_match( '/[a-z]/i', $s );
		$has_digit  = preg_match( '/\d/', $s );
		if ( ! $has_letter || ! $has_digit ) {
			return false;
		}
		$distinct_ratio = count( array_unique( str_split( strtolower( $s ) ) ) ) / max( 1, strlen( $s ) );
		if ( $distinct_ratio < 0.55 ) {
			return false;
		}
		// Veto if contains a common 4+ letter dictionary fragment.
		$common = array( 'john', 'mike', 'sara', 'anna', 'maria', 'admin', 'shop', 'store', 'mail', 'test', 'user' );
		foreach ( $common as $w ) {
			if ( stripos( $s, $w ) !== false ) {
				return false;
			}
		}
		return true;
	}

	private function is_plus_alias( $local ) {
		return strpos( (string) $local, '+' ) !== false;
	}

	private function is_disposable_domain( $domain ) {
		$domain = strtolower( (string) $domain );
		if ( $domain === '' ) return false;
		$set = $this->get_disposable_domains();
		return isset( $set[ $domain ] );
	}

	/**
	 * Detect burst registration: 10+ accounts created in the same UTC hour
	 * as the target registration. Cached per scan run to keep DB queries low.
	 */
	private function is_burst_registration( $registered_ts ) {
		$hour_key = gmdate( 'Y-m-d-H', (int) $registered_ts );
		$cache    = get_transient( self::TRANSIENT_BURST_CACHE );
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}
		if ( isset( $cache[ $hour_key ] ) ) {
			return $cache[ $hour_key ] >= 10;
		}
		global $wpdb;
		$start = gmdate( 'Y-m-d H:00:00', (int) $registered_ts );
		$end   = gmdate( 'Y-m-d H:59:59', (int) $registered_ts );
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered BETWEEN %s AND %s",
			$start, $end
		) );
		$cache[ $hour_key ] = $count;
		set_transient( self::TRANSIENT_BURST_CACHE, $cache, 5 * MINUTE_IN_SECONDS );
		return $count >= 10;
	}

	/**
	 * Curated disposable-email-domain blocklist. Source: well-known
	 * temp-mail providers as of 2026. Operators can extend with the
	 * `luwipress_bot_disposable_domains` filter (return string[]).
	 *
	 * @return array<string,bool>
	 */
	private function get_disposable_domains() {
		if ( $this->disposable_domains !== null ) {
			return $this->disposable_domains;
		}
		$list = array(
			'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org',
			'tempmail.com', 'temp-mail.org', 'temp-mail.io', 'tempmailaddress.com',
			'10minutemail.com', '10minutemail.net', '20minutemail.com', '30minutemail.com',
			'yopmail.com', 'yopmail.fr', 'yopmail.net',
			'sharklasers.com', 'grr.la', 'guerrillamail.biz', 'spam4.me',
			'trashmail.com', 'trashmail.net', 'trashmail.de', 'trashmail.io',
			'maildrop.cc', 'mailnesia.com', 'mailcatch.com', 'mintemail.com',
			'getairmail.com', 'getnada.com', 'nada.email',
			'fakeinbox.com', 'fakemail.net', 'fakemailgenerator.com',
			'discard.email', 'dispostable.com', 'mytemp.email',
			'emailondeck.com', 'emailtemporanea.com', 'throwawaymail.com',
			'mohmal.com', 'mohmal.in', 'mailtemp.info',
			'tempr.email', 'tempinbox.com', 'tempemail.com', 'tempemail.net',
			'incognitomail.org', 'incognitomail.net', 'incognitomail.com',
			'spambox.us', 'spambog.com', 'spambog.de', 'spambog.ru',
			'mt2014.com', 'mt2015.com', 'mt2016.com',
			'mvrht.net', 'mvrht.com',
			'jourrapide.com', 'cuvox.de', 'rhyta.com', 'gustr.com', 'einrot.com', 'fleckens.hu',
			'armyspy.com', 'dayrep.com', 'teleworm.us',
			'mailbox.in.ua', 'mailbox.org.ua',
			'tmail.com', 'tmail.io', 'tmail.ws',
			'mailnator.com', 'mailtothis.com', 'mailtome.de',
			'wegwerfemail.de', 'wegwerf-emails.de', 'wegwerfmail.net', 'wegwerfmail.org',
			'byom.de', 'mail-temporaire.fr',
			'asooemail.com', 'asooemail.org', 'asooemail.net',
			'inboxbear.com', 'mintemail.org',
			'smailpro.com', 'smailpro.org',
			'tempmail.dev', 'tempmail.email',
			'mailpoof.com',
			'fakemailbox.net', 'fakeemailbox.com',
			'banit.club',
			'tempr.email', 'discard.cf', 'discard.ga', 'discard.gq', 'discard.ml', 'discard.tk',
			'tempmail.plus', 'altmails.com',
			'spam.la', 'spam.ml',
			'dropmail.me', 'minuteinbox.com',
			'crazymailing.com', 'mail7.io',
			'tempemail.co', 'tempemails.io',
			'mailtomy.club', 'mailto.plus',
			'33mail.com',
			'inboxkitten.com', 'plexolan.de',
			'mailpoof.com', 'mailcuk.com',
			'tempmail.lol', 'tempemail.us',
			'cloudtempmail.com', 'simplelogin.io',
			'duck.com',
			'kasmail.com',
			'spamgourmet.com', 'spamgourmet.net', 'spamgourmet.org',
			'spamdecoy.net',
			'emltmp.com', 'getmaila.com',
			'mail-bay.com', 'mailbay.com',
			'oneoffemail.com',
			'meltmail.com',
			'mailtemp.net', 'mailtemp.uk',
			'tafmail.com',
			'minute-mail.net',
			'evopo.com',
			'shortmail.com',
			'snapmail.cc',
			'spamfree24.org', 'spamfree24.de', 'spamfree24.com',
			'tempm.com',
			'temporarioemail.com.br',
			'wuwuwa.org',
			'inboxalias.com',
			'tempm.ml', 'tempm.tk', 'tempm.gq',
			'forwardemail.net',
		);
		$filtered = apply_filters( 'luwipress_bot_disposable_domains', $list );
		$set = array();
		foreach ( (array) $filtered as $d ) {
			$d = strtolower( trim( (string) $d ) );
			if ( $d !== '' ) $set[ $d ] = true;
		}
		$this->disposable_domains = $set;
		return $set;
	}

	// -------------------- REST API --------------------

	public function register_endpoints() {
		$ns = 'luwipress/v1';

		register_rest_route( $ns, '/bot-accounts/scan', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_scan' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'limit'  => array( 'type' => 'integer' ),
				'offset' => array( 'type' => 'integer' ),
			),
		) );

		register_rest_route( $ns, '/bot-accounts/list', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_list' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'page'      => array( 'type' => 'integer' ),
				'per_page'  => array( 'type' => 'integer' ),
				'min_score' => array( 'type' => 'integer' ),
			),
		) );

		register_rest_route( $ns, '/bot-accounts/score/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_score_one' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'id' => array( 'type' => 'integer', 'required' => true ),
			),
		) );

		register_rest_route( $ns, '/bot-accounts/delete', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_delete' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'user_ids' => array( 'required' => true ),
				'confirm'  => array( 'type' => 'boolean' ),
			),
		) );

		register_rest_route( $ns, '/bot-accounts/delete-by-filter', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_delete_by_filter' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'min_score'     => array( 'type' => 'integer', 'required' => true ),
				'confirm'       => array( 'type' => 'boolean' ),
				'limit'         => array( 'type' => 'integer' ),
				'after_user_id' => array( 'type' => 'integer' ),
			),
		) );

		register_rest_route( $ns, '/bot-accounts/whitelist', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_whitelist' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'user_id' => array( 'type' => 'integer', 'required' => true ),
				'action'  => array( 'type' => 'string' ),
			),
		) );

		register_rest_route( $ns, '/bot-accounts/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_stats' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );

		register_rest_route( $ns, '/bot-accounts/settings', array(
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
	}

	public function rest_scan( $request ) {
		$limit  = $request->get_param( 'limit' );
		$offset = (int) ( $request->get_param( 'offset' ) ?: 0 );
		$summary = $this->run_scan( $limit ? (int) $limit : null, $offset );
		return rest_ensure_response( $summary );
	}

	public function rest_list( $request ) {
		$page      = (int) ( $request->get_param( 'page' ) ?: 1 );
		$per_page  = (int) ( $request->get_param( 'per_page' ) ?: 50 );
		$min_score = $request->get_param( 'min_score' );
		$min_score = $min_score !== null ? (int) $min_score : null;
		return rest_ensure_response( $this->list_suspects( $page, $per_page, $min_score ) );
	}

	public function rest_score_one( $request ) {
		$id = (int) $request['id'];
		return rest_ensure_response( $this->score_user( $id ) );
	}

	public function rest_delete( $request ) {
		$ids = $request->get_param( 'user_ids' );
		if ( ! is_array( $ids ) ) {
			$ids = array_filter( array_map( 'intval', explode( ',', (string) $ids ) ) );
		}
		$ids = array_map( 'intval', $ids );
		$confirm = (bool) $request->get_param( 'confirm' );
		return rest_ensure_response( $this->delete_users( $ids, $confirm ) );
	}

	public function rest_delete_by_filter( $request ) {
		$min_score     = (int) $request->get_param( 'min_score' );
		$confirm       = (bool) $request->get_param( 'confirm' );
		$limit         = (int) ( $request->get_param( 'limit' ) ?: 10 );
		$after_user_id = (int) ( $request->get_param( 'after_user_id' ) ?: 0 );
		return rest_ensure_response( $this->delete_by_filter( $min_score, $confirm, $limit, $after_user_id ) );
	}

	public function rest_whitelist( $request ) {
		$user_id = (int) $request->get_param( 'user_id' );
		$action  = (string) ( $request->get_param( 'action' ) ?: 'add' );
		if ( $action === 'remove' ) {
			$this->whitelist_remove( $user_id );
		} else {
			$this->whitelist_add( $user_id );
		}
		return rest_ensure_response( array(
			'whitelist' => $this->get_whitelist(),
			'action'    => $action,
			'user_id'   => $user_id,
		) );
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
		$next = $this->update_settings( (array) $patch );
		return rest_ensure_response( $next );
	}
}
