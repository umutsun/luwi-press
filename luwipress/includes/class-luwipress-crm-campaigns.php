<?php
/**
 * CRM Campaign dispatcher — segment-driven AI-generated emails + WC coupons.
 *
 * The Knowledge Graph "Customers" view surfaces segment cohorts (one-time,
 * at-risk, new, VIP, etc.). Before 3.3.1 the only sidebar action was an
 * "Export CSV" — operators had to take that CSV into Mailchimp or
 * FluentCRM by hand. This class turns the cohort into a one-click sales
 * conversion loop:
 *
 *   preview()  → recipient count + suggested coupon defaults + AI-drafted email
 *   send()     → generate coupon, render AI envelope per recipient, wp_mail()
 *   record     → kg_event 'campaign_sent' per recipient feeds the signal layer
 *   convert    → woocommerce_order_status_completed re-checks recent campaigns,
 *                emits 'campaign_converted' kg_event so the dashboard can show
 *                "sent → opened → purchased" funnel ratios.
 *
 * Storage uses one row per (campaign, recipient) in `wp_luwipress_campaign_sends`
 * plus a campaign-summary row in `wp_luwipress_campaigns`. WC coupons are
 * created server-side via `WC_Coupon` so the operator never sees raw codes
 * (we email them, not display them in the modal).
 *
 * Hard guards:
 *  - WooCommerce required (`is_wc_active()` gate).
 *  - Per-segment recipient count capped at 200 per send to protect inbox
 *    reputation and AI budget; operator iterates if needed.
 *  - Coupon `usage_limit_per_user = 1` always — even on shared codes a single
 *    recipient can't burn the offer twice.
 *  - AI message is generated ONCE per campaign (not per recipient) so 200
 *    recipients cost 1 AI call. Personalization (first name) is templated
 *    server-side, not regenerated.
 *
 * @package LuwiPress
 * @since   3.3.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_CRM_Campaigns {

	private static $instance = null;

	const TABLE_CAMPAIGNS = 'luwipress_campaigns';
	const TABLE_SENDS     = 'luwipress_campaign_sends';

	/** Conversion attribution window — orders within N days of a campaign send count. */
	const CONVERSION_WINDOW_DAYS = 30;

	/** Hard cap on recipients per send. */
	const MAX_RECIPIENTS_PER_SEND = 200;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Conversion tracking — fires on every completed WC order, checks if
		// the customer is in any recent campaign window, records kg_event.
		add_action( 'woocommerce_order_status_completed', array( $this, 'track_order_conversion' ), 20, 1 );
	}

	// ─── DB schema ─────────────────────────────────────────────────────

	public static function maybe_create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$campaigns = $wpdb->prefix . self::TABLE_CAMPAIGNS;
		$sends     = $wpdb->prefix . self::TABLE_SENDS;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( "CREATE TABLE {$campaigns} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			segment VARCHAR(32) NOT NULL,
			template VARCHAR(64) NOT NULL DEFAULT '',
			subject VARCHAR(255) NOT NULL DEFAULT '',
			coupon_code VARCHAR(64) NOT NULL DEFAULT '',
			coupon_pct INT NOT NULL DEFAULT 0,
			coupon_days INT NOT NULL DEFAULT 0,
			recipient_count INT NOT NULL DEFAULT 0,
			sent_count INT NOT NULL DEFAULT 0,
			failed_count INT NOT NULL DEFAULT 0,
			conversion_count INT NOT NULL DEFAULT 0,
			conversion_revenue DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY segment (segment),
			KEY created_at (created_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$sends} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT(20) UNSIGNED NOT NULL,
			customer_id BIGINT(20) UNSIGNED NOT NULL,
			email VARCHAR(190) NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'sent',
			sent_at DATETIME NOT NULL,
			converted_at DATETIME DEFAULT NULL,
			conversion_order_id BIGINT(20) UNSIGNED DEFAULT NULL,
			conversion_value DECIMAL(12,2) DEFAULT NULL,
			PRIMARY KEY (id),
			KEY campaign_id (campaign_id),
			KEY customer_id (customer_id),
			KEY converted_at (converted_at)
		) {$charset};" );
	}

	// ─── REST surface ──────────────────────────────────────────────────

	public function register_rest_routes() {
		register_rest_route( 'luwipress/v1', '/crm/campaign/preview', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_preview' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'segment' => array( 'required' => true, 'type' => 'string', 'description' => 'one_time | at_risk | new | vip | loyal | active | dormant | lost' ),
			),
		) );

		register_rest_route( 'luwipress/v1', '/crm/campaign/send', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_send' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'segment'       => array( 'required' => true, 'type' => 'string' ),
				'subject'       => array( 'required' => false, 'type' => 'string', 'description' => 'Override AI-generated subject. Leave empty to use AI output.' ),
				'body_html'     => array( 'required' => false, 'type' => 'string', 'description' => 'Override AI-generated body. Leave empty to use AI output.' ),
				'coupon_pct'    => array( 'required' => false, 'type' => 'integer', 'description' => 'Discount %. 0 = no coupon.' ),
				'coupon_days'   => array( 'required' => false, 'type' => 'integer' ),
				'free_shipping' => array( 'required' => false, 'type' => 'boolean' ),
				'limit'         => array( 'required' => false, 'type' => 'integer', 'description' => 'Max recipients (clamped to MAX_RECIPIENTS_PER_SEND).' ),
				'dry_run'       => array( 'required' => false, 'type' => 'boolean', 'description' => 'Build the envelope + coupon but skip wp_mail loop.' ),
			),
		) );

		register_rest_route( 'luwipress/v1', '/crm/campaign/history', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_history' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'limit' => array( 'required' => false, 'type' => 'integer', 'default' => 20 ),
			),
		) );
	}

	// ─── Preview handler ───────────────────────────────────────────────

	public function rest_preview( $request ) {
		if ( ! LuwiPress::is_wc_active() ) {
			return new WP_Error( 'no_wc', 'WooCommerce is required for CRM campaigns.', array( 'status' => 400 ) );
		}

		$segment = $this->sanitize_segment( $request->get_param( 'segment' ) );
		if ( '' === $segment ) {
			return new WP_Error( 'invalid_segment', 'Unknown segment.', array( 'status' => 400 ) );
		}

		$recipients = $this->find_recipients( $segment, self::MAX_RECIPIENTS_PER_SEND );
		$defaults   = $this->default_coupon_for_segment( $segment );

		// Generate sample AI envelope using the FIRST recipient's first name so
		// the operator sees a realistic preview.
		$sample_name = '';
		if ( ! empty( $recipients ) ) {
			$first = $recipients[0];
			$sample_name = (string) ( $first['first_name'] ?? '' );
		}

		$envelope = $this->generate_ai_envelope( $segment, array_merge( $defaults, array(
			'customer_first_name' => $sample_name,
		) ) );

		return rest_ensure_response( array(
			'segment'         => $segment,
			'recipient_count' => count( $recipients ),
			'capped_at'       => self::MAX_RECIPIENTS_PER_SEND,
			'defaults'        => $defaults,
			'preview'         => $envelope, // { subject, preheader, body_html, cta_label } OR { error: ... }
		) );
	}

	// ─── Send handler ──────────────────────────────────────────────────

	public function rest_send( $request ) {
		if ( ! LuwiPress::is_wc_active() ) {
			return new WP_Error( 'no_wc', 'WooCommerce is required for CRM campaigns.', array( 'status' => 400 ) );
		}

		$segment = $this->sanitize_segment( $request->get_param( 'segment' ) );
		if ( '' === $segment ) {
			return new WP_Error( 'invalid_segment', 'Unknown segment.', array( 'status' => 400 ) );
		}

		$limit = absint( $request->get_param( 'limit' ) );
		if ( $limit <= 0 || $limit > self::MAX_RECIPIENTS_PER_SEND ) {
			$limit = self::MAX_RECIPIENTS_PER_SEND;
		}

		$dry_run = (bool) $request->get_param( 'dry_run' );

		$recipients = $this->find_recipients( $segment, $limit );
		if ( empty( $recipients ) ) {
			return rest_ensure_response( array(
				'ok'              => true,
				'segment'         => $segment,
				'sent'            => 0,
				'failed'          => 0,
				'note'            => 'No recipients in this segment.',
			) );
		}

		$defaults = $this->default_coupon_for_segment( $segment );
		$coupon_pct = $request->get_param( 'coupon_pct' );
		if ( null === $coupon_pct || '' === $coupon_pct ) {
			$coupon_pct = $defaults['coupon_pct'];
		}
		$coupon_pct = absint( $coupon_pct );

		$coupon_days = $request->get_param( 'coupon_days' );
		if ( null === $coupon_days || '' === $coupon_days ) {
			$coupon_days = $defaults['coupon_days'];
		}
		$coupon_days = absint( $coupon_days );
		if ( $coupon_days <= 0 ) {
			$coupon_days = 30;
		}

		$free_shipping = (bool) $request->get_param( 'free_shipping' );

		// Build coupon (shared code, per-user usage limit = 1).
		$coupon_code = '';
		$coupon_id   = 0;
		if ( $coupon_pct > 0 || $free_shipping ) {
			$coupon_payload = $this->create_campaign_coupon( $segment, $coupon_pct, $coupon_days, $free_shipping );
			if ( is_wp_error( $coupon_payload ) ) {
				return $coupon_payload;
			}
			$coupon_code = $coupon_payload['code'];
			$coupon_id   = $coupon_payload['id'];
		}

		// Generate the AI envelope ONCE (operator overrides win over AI).
		$override_subject = (string) $request->get_param( 'subject' );
		$override_body    = (string) $request->get_param( 'body_html' );

		$envelope = null;
		if ( '' === $override_subject || '' === $override_body ) {
			$envelope = $this->generate_ai_envelope( $segment, array(
				'coupon_pct'   => $coupon_pct,
				'coupon_days'  => $coupon_days,
				'free_shipping' => $free_shipping,
			) );
			if ( is_wp_error( $envelope ) ) {
				return $envelope;
			}
		}
		$subject_template = '' !== $override_subject ? $override_subject : ( $envelope['subject'] ?? 'A note from {store_name}' );
		$body_template    = '' !== $override_body    ? $override_body    : ( $envelope['body_html'] ?? '<p>Thank you for shopping with us.</p>' );

		// Persist campaign row.
		global $wpdb;
		$campaigns = $wpdb->prefix . self::TABLE_CAMPAIGNS;
		$wpdb->insert( $campaigns, array(
			'segment'         => $segment,
			'template'        => $this->template_key_for_segment( $segment ),
			'subject'         => mb_substr( wp_strip_all_tags( $subject_template ), 0, 250 ),
			'coupon_code'     => $coupon_code,
			'coupon_pct'      => $coupon_pct,
			'coupon_days'     => $coupon_days,
			'recipient_count' => count( $recipients ),
			'sent_count'      => 0,
			'failed_count'    => 0,
			'created_at'      => current_time( 'mysql' ),
		) );
		$campaign_id = (int) $wpdb->insert_id;

		// Dispatch loop. wp_mail returns bool; we count + log per recipient.
		$sent_count = 0;
		$failed_count = 0;
		$sends_table = $wpdb->prefix . self::TABLE_SENDS;

		foreach ( $recipients as $r ) {
			$personalized_subject = $this->personalize( $subject_template, $r, $coupon_code );
			$personalized_body    = $this->personalize( $body_template, $r, $coupon_code );

			// Wrap body with branded HTML shell.
			$html = $this->wrap_email_html( $personalized_subject, $personalized_body, $coupon_code, $coupon_pct, $coupon_days, $free_shipping );

			$ok = $dry_run ? true : wp_mail(
				$r['email'],
				$personalized_subject,
				$html,
				array( 'Content-Type: text/html; charset=UTF-8' )
			);

			$wpdb->insert( $sends_table, array(
				'campaign_id' => $campaign_id,
				'customer_id' => $r['id'],
				'email'       => $r['email'],
				'status'      => $ok ? ( $dry_run ? 'dry_run' : 'sent' ) : 'failed',
				'sent_at'     => current_time( 'mysql' ),
			) );

			if ( $ok ) {
				$sent_count++;
				if ( ! $dry_run && class_exists( 'LuwiPress_KG_Signals' ) ) {
					do_action( 'luwipress_kg_event_recorded', 'campaign_sent', 'customer', (int) $r['id'], array(
						'campaign_id' => $campaign_id,
						'segment'     => $segment,
						'coupon_code' => $coupon_code,
					) );
				}
			} else {
				$failed_count++;
			}
		}

		$wpdb->update(
			$campaigns,
			array( 'sent_count' => $sent_count, 'failed_count' => $failed_count ),
			array( 'id' => $campaign_id )
		);

		if ( class_exists( 'LuwiPress_Logger' ) ) {
			LuwiPress_Logger::log(
				sprintf( 'CRM campaign sent: segment=%s sent=%d failed=%d coupon=%s%s', $segment, $sent_count, $failed_count, $coupon_code, $dry_run ? ' [DRY RUN]' : '' ),
				'info',
				array( 'campaign_id' => $campaign_id )
			);
		}

		return rest_ensure_response( array(
			'ok'             => true,
			'campaign_id'    => $campaign_id,
			'segment'        => $segment,
			'sent'           => $sent_count,
			'failed'         => $failed_count,
			'recipient_count' => count( $recipients ),
			'coupon_code'    => $coupon_code,
			'coupon_id'      => $coupon_id,
			'dry_run'        => $dry_run,
		) );
	}

	// ─── History handler ───────────────────────────────────────────────

	public function rest_history( $request ) {
		global $wpdb;
		$campaigns_table = $wpdb->prefix . self::TABLE_CAMPAIGNS;
		$limit = absint( $request->get_param( 'limit' ) );
		if ( $limit <= 0 || $limit > 100 ) {
			$limit = 20;
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$campaigns_table} ORDER BY created_at DESC LIMIT %d",
			$limit
		), ARRAY_A );

		return rest_ensure_response( array(
			'campaigns' => $rows ?: array(),
		) );
	}

	// ─── Conversion tracking ───────────────────────────────────────────

	/**
	 * Fired on `woocommerce_order_status_completed`. Checks if the order's
	 * customer has any campaign send in the last CONVERSION_WINDOW_DAYS days
	 * that hasn't been attributed yet; if so, mark it converted + emit
	 * kg_event so the dashboard can show conversion ratios per cohort.
	 */
	public function track_order_conversion( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id <= 0 ) {
			return;
		}

		global $wpdb;
		$sends_table     = $wpdb->prefix . self::TABLE_SENDS;
		$campaigns_table = $wpdb->prefix . self::TABLE_CAMPAIGNS;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::CONVERSION_WINDOW_DAYS . ' days' ) );

		$send = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$sends_table}
			 WHERE customer_id = %d
			   AND status = 'sent'
			   AND converted_at IS NULL
			   AND sent_at >= %s
			 ORDER BY sent_at DESC LIMIT 1",
			$customer_id,
			$cutoff
		), ARRAY_A );

		if ( ! $send ) {
			return;
		}

		$value = (float) $order->get_total();
		$wpdb->update(
			$sends_table,
			array(
				'converted_at'        => current_time( 'mysql' ),
				'conversion_order_id' => (int) $order_id,
				'conversion_value'    => $value,
			),
			array( 'id' => (int) $send['id'] )
		);

		// Atomic increment on the campaign summary row.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$campaigns_table}
			 SET conversion_count = conversion_count + 1,
			     conversion_revenue = conversion_revenue + %f
			 WHERE id = %d",
			$value,
			(int) $send['campaign_id']
		) );

		do_action( 'luwipress_kg_event_recorded', 'campaign_converted', 'customer', $customer_id, array(
			'campaign_id'  => (int) $send['campaign_id'],
			'order_id'     => (int) $order_id,
			'order_value'  => $value,
		) );

		if ( class_exists( 'LuwiPress_Logger' ) ) {
			LuwiPress_Logger::log(
				sprintf( 'CRM campaign conversion: order #%d for campaign #%d (%.2f)', $order_id, (int) $send['campaign_id'], $value ),
				'info',
				array( 'campaign_id' => (int) $send['campaign_id'] )
			);
		}
	}

	// ─── Internal helpers ──────────────────────────────────────────────

	private function sanitize_segment( $raw ) {
		$allowed = array( 'vip', 'loyal', 'active', 'new', 'at_risk', 'dormant', 'lost', 'one_time' );
		$raw = is_string( $raw ) ? sanitize_key( $raw ) : '';
		return in_array( $raw, $allowed, true ) ? $raw : '';
	}

	/**
	 * Resolve recipients for a segment by reading `luwipress_crm_segment`
	 * user_meta written by the classifier. We never inspect WC orders here
	 * — that's the classifier's job; this method just reads the cached
	 * cohort label.
	 *
	 * Returns array of { id, email, first_name, last_name }.
	 */
	private function find_recipients( $segment, $limit ) {
		// Default `fields` (omitted) returns WP_User objects — PHPStan knows
		// WP_User::ID is defined; the `fields=array(...)` partial-stdClass
		// shape it can't type-check. Hydration cost is negligible at 200
		// recipients max.
		$users = get_users( array(
			'meta_key'   => 'luwipress_crm_segment',
			'meta_value' => $segment,
			'number'     => $limit,
			'orderby'    => 'ID',
			'order'      => 'ASC',
		) );

		$out = array();
		foreach ( $users as $u ) {
			if ( ! ( $u instanceof WP_User ) ) {
				continue;
			}
			$user_id = (int) $u->ID;
			$email   = (string) $u->user_email;
			if ( '' === $email ) {
				continue;
			}
			$first = get_user_meta( $user_id, 'first_name', true );
			$last  = get_user_meta( $user_id, 'last_name', true );
			$out[] = array(
				'id'         => $user_id,
				'email'      => $email,
				'first_name' => (string) $first,
				'last_name'  => (string) $last,
			);
		}

		return $out;
	}

	/**
	 * Suggested coupon defaults per segment — tuned by retention strategy
	 * not by random round numbers. Operator can always override in the modal.
	 */
	private function default_coupon_for_segment( $segment ) {
		$map = array(
			'one_time' => array( 'coupon_pct' => 15, 'coupon_days' => 30, 'free_shipping' => false ),
			'at_risk'  => array( 'coupon_pct' => 20, 'coupon_days' => 14, 'free_shipping' => false ),
			'dormant'  => array( 'coupon_pct' => 25, 'coupon_days' => 21, 'free_shipping' => false ),
			'new'      => array( 'coupon_pct' => 10, 'coupon_days' => 30, 'free_shipping' => false ),
			'vip'      => array( 'coupon_pct' => 0,  'coupon_days' => 90, 'free_shipping' => true ),
			'loyal'    => array( 'coupon_pct' => 10, 'coupon_days' => 60, 'free_shipping' => true ),
			'active'   => array( 'coupon_pct' => 0,  'coupon_days' => 0,  'free_shipping' => false ),
			'lost'     => array( 'coupon_pct' => 30, 'coupon_days' => 14, 'free_shipping' => true ),
		);

		return apply_filters( 'luwipress_crm_campaign_defaults', $map[ $segment ] ?? array( 'coupon_pct' => 10, 'coupon_days' => 30, 'free_shipping' => false ), $segment );
	}

	private function template_key_for_segment( $segment ) {
		switch ( $segment ) {
			case 'one_time': return 'crm_winback_one_time';
			case 'at_risk':  return 'crm_at_risk_perk';
			case 'new':      return 'crm_new_onboarding';
			case 'vip':      return 'crm_vip_perk';
			case 'loyal':    return 'crm_vip_perk'; // share VIP rhetoric
			case 'dormant':  return 'crm_winback_one_time';
			case 'lost':     return 'crm_winback_one_time';
			default:         return 'crm_winback_one_time';
		}
	}

	/**
	 * Generate the AI email envelope. Returns
	 *   ['subject', 'preheader', 'body_html', 'cta_label']
	 * or a WP_Error / array with 'error' key on failure.
	 */
	private function generate_ai_envelope( $segment, $ctx ) {
		if ( ! class_exists( 'LuwiPress_Prompts' ) || ! class_exists( 'LuwiPress_AI_Engine' ) ) {
			return array( 'error' => 'AI engine not available.' );
		}

		$lang = $this->store_language();
		$ctx = array_merge( array(
			'store_name' => get_bloginfo( 'name' ),
			'language'   => $lang,
		), is_array( $ctx ) ? $ctx : array() );

		$template = $this->template_key_for_segment( $segment );

		if ( method_exists( 'LuwiPress_Prompts', $template ) ) {
			$pair = call_user_func( array( 'LuwiPress_Prompts', $template ), $ctx );
		} else {
			$pair = LuwiPress_Prompts::crm_winback_one_time( $ctx );
		}

		$messages = array(
			array( 'role' => 'system', 'content' => $pair['system'] ),
			array( 'role' => 'user',   'content' => $pair['user'] ),
		);

		$response = LuwiPress_AI_Engine::dispatch( 'crm-campaign', $messages, array(
			'max_tokens' => 800,
			'temperature' => 0.6,
			'json_mode'  => true,
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'error'      => $response->get_error_message(),
				'subject'    => sprintf( __( 'A note from %s', 'luwipress' ), get_bloginfo( 'name' ) ),
				'preheader'  => '',
				'body_html'  => '<p>' . esc_html__( 'Thank you for shopping with us.', 'luwipress' ) . '</p>',
				'cta_label'  => __( 'Visit store', 'luwipress' ),
			);
		}

		// AI_Engine::dispatch() returns `array|WP_Error`. WP_Error was filtered
		// above, so $response is guaranteed an array here — read .content
		// directly without a redundant is_array() ternary (PHPStan flagged the
		// else branch as unreachable).
		$raw  = (string) ( $response['content'] ?? '' );
		$json = $this->extract_json( $raw );

		if ( ! is_array( $json ) ) {
			return array(
				'error'      => 'Non-JSON AI response',
				'subject'    => sprintf( __( 'A note from %s', 'luwipress' ), get_bloginfo( 'name' ) ),
				'preheader'  => '',
				'body_html'  => '<p>' . wp_kses_post( $raw ) . '</p>',
				'cta_label'  => __( 'Visit store', 'luwipress' ),
			);
		}

		return array(
			'subject'   => (string) ( $json['subject']   ?? '' ),
			'preheader' => (string) ( $json['preheader'] ?? '' ),
			'body_html' => wp_kses_post( (string) ( $json['body_html'] ?? '' ) ),
			'cta_label' => (string) ( $json['cta_label'] ?? __( 'Visit store', 'luwipress' ) ),
		);
	}

	private function extract_json( $raw ) {
		$raw = trim( (string) $raw );
		// Strip ```json fences
		if ( preg_match( '/```(?:json)?\s*(.+?)\s*```/s', $raw, $m ) ) {
			$raw = $m[1];
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private function store_language() {
		$opt = (string) get_option( 'luwipress_target_language', '' );
		if ( '' !== $opt ) {
			return $opt;
		}
		$locale = get_locale();
		$map = array(
			'en_US' => 'English', 'en_GB' => 'English',
			'tr_TR' => 'Turkish',
			'fr_FR' => 'French', 'fr_BE' => 'French', 'fr_CA' => 'French',
			'it_IT' => 'Italian',
			'es_ES' => 'Spanish', 'es_MX' => 'Spanish',
			'de_DE' => 'German', 'de_AT' => 'German',
			'pt_PT' => 'Portuguese', 'pt_BR' => 'Portuguese',
			'nl_NL' => 'Dutch',
			'ar'    => 'Arabic', 'ar_SA' => 'Arabic',
		);
		return $map[ $locale ] ?? 'English';
	}

	/**
	 * Create a shared WC coupon. Uses one code per campaign (simpler to
	 * communicate + analytics-friendly) but caps `usage_limit_per_user = 1`
	 * so a single buyer can't burn it twice.
	 *
	 * @return array|WP_Error  { id, code } on success.
	 */
	private function create_campaign_coupon( $segment, $pct, $days, $free_shipping ) {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return new WP_Error( 'no_wc_coupon', 'WC_Coupon class not available.', array( 'status' => 500 ) );
		}

		$code = $this->generate_coupon_code( $segment );

		try {
			$coupon = new WC_Coupon();
			$coupon->set_code( $code );

			if ( $pct > 0 ) {
				$coupon->set_discount_type( 'percent' );
				$coupon->set_amount( $pct );
			} elseif ( $free_shipping ) {
				$coupon->set_discount_type( 'fixed_cart' );
				$coupon->set_amount( 0 );
			}

			$coupon->set_free_shipping( (bool) $free_shipping );
			$coupon->set_individual_use( true );
			$coupon->set_usage_limit_per_user( 1 );

			if ( $days > 0 ) {
				$coupon->set_date_expires( strtotime( '+' . $days . ' days', current_time( 'timestamp' ) ) );
			}

			$coupon->set_description( sprintf(
				/* translators: 1: segment name 2: store name */
				'LuwiPress CRM campaign — segment "%1$s" — %2$s',
				$segment,
				current_time( 'mysql' )
			) );

			$id = $coupon->save();

			if ( ! $id ) {
				return new WP_Error( 'coupon_save_failed', 'WC_Coupon::save() returned 0.', array( 'status' => 500 ) );
			}

			return array( 'id' => (int) $id, 'code' => $code );
		} catch ( Exception $e ) {
			return new WP_Error( 'coupon_exception', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/** Code shape: LWP-WB-XXXXXXXX (8 alnum chars, no ambiguous 0/O/I/1). */
	private function generate_coupon_code( $segment ) {
		$prefix_map = array(
			'one_time' => 'LWP-WB-',
			'at_risk'  => 'LWP-RR-',
			'new'      => 'LWP-HI-',
			'vip'      => 'LWP-VIP-',
			'loyal'    => 'LWP-LY-',
			'dormant'  => 'LWP-WAKE-',
			'lost'     => 'LWP-LAST-',
		);
		$prefix = $prefix_map[ $segment ] ?? 'LWP-CRM-';
		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$rand = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$rand .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $prefix . $rand;
	}

	/**
	 * Token substitution for personalization. Tokens supported:
	 *   {{first_name}}  → recipient first_name, falls back to "there"
	 *   {{last_name}}
	 *   {{store_name}}
	 *   {{coupon_code}}
	 *   {{coupon_url}}  → cart URL with coupon pre-applied
	 */
	private function personalize( $template, $recipient, $coupon_code ) {
		$first = trim( (string) ( $recipient['first_name'] ?? '' ) );
		if ( '' === $first ) {
			$first = __( 'there', 'luwipress' );
		}

		$coupon_url = home_url( '/' );
		if ( '' !== $coupon_code && function_exists( 'wc_get_cart_url' ) ) {
			$coupon_url = add_query_arg( 'apply_coupon', rawurlencode( $coupon_code ), wc_get_cart_url() );
		}

		$pairs = array(
			'{{first_name}}'  => esc_html( $first ),
			'{{last_name}}'   => esc_html( (string) ( $recipient['last_name'] ?? '' ) ),
			'{{store_name}}'  => esc_html( get_bloginfo( 'name' ) ),
			'{{coupon_code}}' => esc_html( $coupon_code ),
			'{{coupon_url}}'  => esc_url( $coupon_url ),
		);

		return strtr( $template, $pairs );
	}

	/**
	 * Wrap the AI body in a minimal branded HTML shell. Plain enough that
	 * Gmail / Outlook don't strip it; expressive enough to look intentional.
	 */
	private function wrap_email_html( $subject, $body_html, $coupon_code, $coupon_pct, $coupon_days, $free_shipping ) {
		$store = esc_html( get_bloginfo( 'name' ) );
		$home  = esc_url( home_url( '/' ) );

		$coupon_block = '';
		if ( '' !== $coupon_code ) {
			$perk_line = $free_shipping && 0 === $coupon_pct
				? sprintf( __( 'Free shipping for %d days', 'luwipress' ), $coupon_days )
				: ( $coupon_pct > 0
					? sprintf( __( '%1$d%% off — expires in %2$d days', 'luwipress' ), $coupon_pct, $coupon_days )
					: __( 'A small perk inside', 'luwipress' ) );

			$coupon_block = '<div style="margin:24px 0;padding:18px;border:1px dashed #c7c7c7;background:#fafafa;text-align:center;font-family:Georgia,serif;">'
				. '<div style="font-size:13px;color:#666;letter-spacing:0.08em;text-transform:uppercase;">' . esc_html__( 'Your code', 'luwipress' ) . '</div>'
				. '<div style="font-size:24px;font-weight:600;margin:6px 0;color:#1a1a1a;letter-spacing:0.04em;">' . esc_html( $coupon_code ) . '</div>'
				. '<div style="font-size:13px;color:#666;">' . esc_html( $perk_line ) . '</div>'
				. '</div>';
		}

		return '<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>' . esc_html( $subject ) . '</title></head>
<body style="margin:0;padding:0;background:#f7f7f7;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;color:#1a1a1a;line-height:1.55;">
<div style="max-width:560px;margin:0 auto;padding:32px 28px;background:#fff;">
<div style="font-size:14px;color:#888;letter-spacing:0.1em;text-transform:uppercase;margin-bottom:20px;">' . $store . '</div>
<div style="font-size:15px;line-height:1.6;">' . $body_html . '</div>
' . $coupon_block . '
<p style="margin-top:32px;font-size:12px;color:#999;border-top:1px solid #eee;padding-top:16px;">
<a href="' . $home . '" style="color:#999;text-decoration:underline;">' . $store . '</a>
</p>
</div>
</body></html>';
	}
}
