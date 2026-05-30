<?php
/**
 * LuwiPress AP2 (Agent Payments Protocol) module
 *
 * AP2 is the payment-authorization layer beneath agentic commerce. Its core
 * primitive is the *Mandate* — a cryptographically-signed, verifiable-credential-
 * backed contract:
 *
 *   Intent Mandate  (user intent + rules)
 *        └─▶ Cart Mandate (approved exact cart + price)
 *                 └─▶ payment link ──▶ non-repudiable audit trail
 *
 * The merchant's role in AP2 is deliberately narrow: VERIFY the Cart Mandate an
 * agent presents, PERSIST the Intent → Cart mandate chain as an order audit
 * trail, and FULFIL. Minting / signing verifiable credentials is the agent's
 * and the payment processor's job — we never issue credentials.
 *
 * VERIFICATION STRATEGY (operator chose store-and-flag + pluggable verifier):
 *   - We do structural validation, expiry checks, and a merchant-side
 *     amount-match ("what you see is what you pay for" — the Cart Mandate total
 *     must equal the order total).
 *   - Cryptographic JWS / VC signature verification is delegated to the
 *     `luwipress_ap2_verify_mandate` filter, so a payment-processor SDK or an
 *     issuer-JWKS verifier can plug in. By default a structurally-valid mandate
 *     is recorded as `unverified` (stored, not cryptographically trusted).
 *   - `require_verification` (default OFF) makes an unverified / amount-mismatch
 *     mandate ABORT order completion (strict mode).
 *
 * The mandate chain is stored as order meta; a rolling audit log keeps recent
 * verification verdicts for the admin Transactions view.
 *
 * @package    LuwiPress
 * @subpackage Commerce
 * @since      3.5.9-dev
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_AP2 {

	/** @var self|null */
	private static $instance = null;

	const OPTION_SETTINGS = 'luwipress_ap2_settings';
	const OPTION_LOG      = 'luwipress_ap2_log';
	const LOG_RETENTION   = 100;

	// Order meta keys.
	const META_INTENT       = '_luwipress_ap2_intent_mandate';
	const META_CART         = '_luwipress_ap2_cart_mandate';
	const META_CHAIN        = '_luwipress_ap2_mandate_chain';
	const META_VERIFICATION = '_luwipress_ap2_verification';

	// Amount-match tolerance (minor currency rounding).
	const AMOUNT_EPSILON = 0.01;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	/* ───────────────────── Settings ─────────────────────────────────── */

	public function get_settings() {
		$defaults = array(
			'enabled'              => false,
			'require_verification' => false, // strict mode: abort on unverified / mismatch
			'amount_match'         => true,  // enforce Cart Mandate total == order total
			'issuer_jwks_url'      => '',     // optional — for a future signature verifier
			'allowed_issuers'      => '',     // optional comma-list allowlist
		);
		$stored = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( $defaults, $stored );
	}

	public function save_settings( $input ) {
		$current = $this->get_settings();
		if ( ! is_array( $input ) ) {
			return $current;
		}
		$allowed = array(
			'enabled'              => 'bool',
			'require_verification' => 'bool',
			'amount_match'         => 'bool',
			'issuer_jwks_url'      => 'url',
			'allowed_issuers'      => 'text',
		);
		foreach ( $allowed as $key => $type ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			switch ( $type ) {
				case 'bool':
					$current[ $key ] = ! empty( $input[ $key ] );
					break;
				case 'url':
					$current[ $key ] = esc_url_raw( (string) $input[ $key ] );
					break;
				default:
					$current[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}
		update_option( self::OPTION_SETTINGS, $current, false );
		return $current;
	}

	/* ───────────────────── Verification ─────────────────────────────── */

	/**
	 * Verify a single mandate. Structural + expiry + issuer-allowlist checks
	 * run here; the cryptographic signature check is delegated to the
	 * `luwipress_ap2_verify_mandate` filter (default: signature unverified).
	 *
	 * @param mixed $mandate  The mandate object (decoded array preferred).
	 * @param array $context  Optional {kind:'cart'|'intent', order_id, ...}.
	 * @return array {status, signature_verified, issuer, expires_at, checks[], reason}
	 */
	public function verify_mandate( $mandate, $context = array() ) {
		$verdict = array(
			'status'             => 'invalid',
			'signature_verified' => false,
			'issuer'             => '',
			'expires_at'         => '',
			'checks'             => array(),
			'reason'             => '',
		);

		if ( is_string( $mandate ) ) {
			$decoded = json_decode( $mandate, true );
			if ( is_array( $decoded ) ) {
				$mandate = $decoded;
			}
		}
		if ( ! is_array( $mandate ) || empty( $mandate ) ) {
			$verdict['reason']   = 'Mandate is empty or not an object.';
			$verdict['checks'][] = array( 'structure', false );
			return $verdict;
		}
		$verdict['checks'][] = array( 'structure', true );

		// Issuer extraction (tolerant of several shapes).
		$issuer = $this->dig( $mandate, array( 'issuer', 'iss', array( 'vc', 'issuer' ), array( 'credential', 'issuer' ) ) );
		$verdict['issuer'] = is_string( $issuer ) ? $issuer : '';

		// Issuer allowlist (if configured).
		$settings = $this->get_settings();
		if ( ! empty( $settings['allowed_issuers'] ) ) {
			$allow = array_filter( array_map( 'trim', explode( ',', (string) $settings['allowed_issuers'] ) ) );
			$ok    = ( '' !== $verdict['issuer'] && in_array( $verdict['issuer'], $allow, true ) );
			$verdict['checks'][] = array( 'issuer_allowed', $ok );
			if ( ! $ok ) {
				$verdict['status'] = 'failed';
				$verdict['reason'] = 'Issuer not in allowlist.';
				return $verdict;
			}
		}

		// Expiry (exp / expires_at / validUntil).
		$exp = $this->dig( $mandate, array( 'exp', 'expires_at', 'validUntil', array( 'vc', 'expirationDate' ) ) );
		if ( $exp ) {
			$ts = is_numeric( $exp ) ? (int) $exp : strtotime( (string) $exp );
			$verdict['expires_at'] = $ts ? gmdate( 'c', $ts ) : (string) $exp;
			$expired = ( $ts && $ts < time() );
			$verdict['checks'][] = array( 'not_expired', ! $expired );
			if ( $expired ) {
				$verdict['status'] = 'failed';
				$verdict['reason'] = 'Mandate expired.';
				return $verdict;
			}
		}

		// Structurally valid + not expired = at least "unverified".
		$verdict['status'] = 'unverified';

		/**
		 * Cryptographic verification hook. A processor SDK / JWKS verifier
		 * should set status='verified' + signature_verified=true (or
		 * 'failed' on a bad signature). Receives the raw mandate + context.
		 *
		 * @param array $verdict
		 * @param mixed $mandate
		 * @param array $context
		 */
		$verdict = apply_filters( 'luwipress_ap2_verify_mandate', $verdict, $mandate, $context );

		if ( ! is_array( $verdict ) || ! isset( $verdict['status'] ) ) {
			// A misbehaving filter must not break completion.
			$verdict = array( 'status' => 'unverified', 'signature_verified' => false, 'issuer' => '', 'expires_at' => '', 'checks' => array(), 'reason' => 'verifier returned invalid verdict' );
		}
		return $verdict;
	}

	/**
	 * Extract the monetary amount + currency a Cart Mandate commits to, across
	 * the several shapes the spec / SDKs emit. Returns [amount|null, currency].
	 */
	public function extract_mandate_amount( $mandate ) {
		if ( is_string( $mandate ) ) {
			$decoded = json_decode( $mandate, true );
			$mandate = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $mandate ) ) {
			return array( null, '' );
		}
		$amount = $this->dig( $mandate, array(
			array( 'cart', 'total' ),
			array( 'cart', 'total_amount' ),
			array( 'cart_mandate', 'total' ),
			array( 'payment', 'amount' ),
			array( 'payment_request', 'total', 'amount', 'value' ),
			'total',
			'amount',
		) );
		$currency = $this->dig( $mandate, array(
			array( 'cart', 'currency' ),
			array( 'payment', 'currency' ),
			array( 'payment_request', 'total', 'amount', 'currency' ),
			'currency',
		) );
		return array( ( null === $amount || '' === $amount ) ? null : (float) $amount, is_string( $currency ) ? $currency : '' );
	}

	/**
	 * Dig a value out of a nested array by trying multiple key paths.
	 * Each path is either a string key or an array of nested keys.
	 *
	 * @return mixed|null
	 */
	private function dig( $arr, $paths ) {
		foreach ( $paths as $path ) {
			$path = (array) $path;
			$node = $arr;
			$ok   = true;
			foreach ( $path as $key ) {
				if ( is_array( $node ) && array_key_exists( $key, $node ) ) {
					$node = $node[ $key ];
				} else {
					$ok = false;
					break;
				}
			}
			if ( $ok && null !== $node && '' !== $node ) {
				return $node;
			}
		}
		return null;
	}

	/* ───────────────────── Order attachment ─────────────────────────── */

	/**
	 * Verify + persist the mandate chain on an order. Called from the UCP
	 * checkout `complete` step when an `ap2_cart_mandate` accompanies the
	 * request. In strict mode (`require_verification`) an unverified mandate
	 * or an amount mismatch returns a WP_Error that aborts the order.
	 *
	 * @param mixed $order  WC order (duck-typed; methods guarded with method_exists).
	 * @param array $body   Completion body; reads ap2_cart_mandate + ap2_intent_mandate.
	 * @return true|WP_Error
	 */
	public function attach_to_order( $order, $body ) {
		$settings = $this->get_settings();
		$cart_mandate   = $body['ap2_cart_mandate'] ?? null;
		$intent_mandate = $body['ap2_intent_mandate'] ?? null;
		if ( empty( $cart_mandate ) ) {
			return true; // nothing to do
		}

		$order_total = method_exists( $order, 'get_total' ) ? (float) $order->get_total() : null;
		$order_id    = method_exists( $order, 'get_id' ) ? $order->get_id() : 0;

		$verdict = $this->verify_mandate( $cart_mandate, array( 'kind' => 'cart', 'order_id' => $order_id ) );

		// Merchant-side amount match — independent of crypto. "What you see is
		// what you pay for": the mandate's committed total must equal ours.
		$amount_match = null;
		if ( ! empty( $settings['amount_match'] ) && null !== $order_total ) {
			list( $m_amount, $m_currency ) = $this->extract_mandate_amount( $cart_mandate );
			if ( null !== $m_amount ) {
				$amount_match = ( abs( $m_amount - $order_total ) <= self::AMOUNT_EPSILON );
				$verdict['checks'][] = array( 'amount_match', $amount_match );
				$verdict['mandate_amount'] = $m_amount;
				$verdict['order_total']    = $order_total;
				if ( $m_currency ) {
					$verdict['mandate_currency'] = $m_currency;
				}
			} else {
				$verdict['checks'][] = array( 'amount_match', 'unknown' );
			}
		}

		// Persist the chain regardless (store-and-flag audit trail).
		$chain = array(
			'intent'      => $intent_mandate,
			'cart'        => $cart_mandate,
			'verdict'     => $verdict,
			'verified_at' => current_time( 'mysql' ),
		);
		if ( method_exists( $order, 'update_meta_data' ) ) {
			if ( ! empty( $intent_mandate ) ) {
				$order->update_meta_data( self::META_INTENT, wp_json_encode( $intent_mandate ) );
			}
			$order->update_meta_data( self::META_CART, wp_json_encode( $cart_mandate ) );
			$order->update_meta_data( self::META_CHAIN, wp_json_encode( $chain ) );
			$order->update_meta_data( self::META_VERIFICATION, wp_json_encode( $verdict ) );
			if ( method_exists( $order, 'add_order_note' ) ) {
				$order->add_order_note( 'AP2 Cart Mandate attached — status: ' . $verdict['status'] . ( null === $amount_match ? '' : ( $amount_match ? ', amount matches' : ', AMOUNT MISMATCH' ) ) );
			}
		}

		$this->log( array(
			'order_id'     => $order_id,
			'status'       => $verdict['status'],
			'issuer'       => $verdict['issuer'],
			'amount_match' => $amount_match,
			'at'           => current_time( 'mysql' ),
		) );

		// Strict mode gates.
		if ( ! empty( $settings['require_verification'] ) ) {
			if ( 'verified' !== $verdict['status'] ) {
				return new WP_Error( 'ap2_unverified', 'AP2 Cart Mandate could not be verified (' . $verdict['status'] . ').', array( 'status' => 402, 'verdict' => $verdict ) );
			}
			if ( false === $amount_match ) {
				return new WP_Error( 'ap2_amount_mismatch', 'AP2 Cart Mandate amount does not match the order total.', array( 'status' => 402, 'verdict' => $verdict ) );
			}
		}

		return true;
	}

	/**
	 * Read the stored mandate chain + verdict for an order.
	 *
	 * @return array|WP_Error
	 */
	public function get_transaction( $order_id ) {
		$order_id = (int) $order_id;
		if ( ! function_exists( 'wc_get_order' ) || ! wc_get_order( $order_id ) ) {
			return new WP_Error( 'order_not_found', 'Order not found or WooCommerce inactive.', array( 'status' => 404 ) );
		}
		$order = wc_get_order( $order_id );
		$decode = function ( $key ) use ( $order ) {
			$raw = $order->get_meta( $key, true );
			if ( empty( $raw ) ) {
				return null;
			}
			$d = json_decode( (string) $raw, true );
			return null === $d ? $raw : $d;
		};
		return array(
			'order_id'     => $order_id,
			'order_status' => $order->get_status(),
			'has_mandate'  => (bool) $order->get_meta( self::META_CART, true ),
			'intent'       => $decode( self::META_INTENT ),
			'cart'         => $decode( self::META_CART ),
			'chain'        => $decode( self::META_CHAIN ),
			'verification' => $decode( self::META_VERIFICATION ),
		);
	}

	/* ───────────────────── Audit log ────────────────────────────────── */

	private function log( $entry ) {
		$log = get_option( self::OPTION_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::LOG_RETENTION );
		update_option( self::OPTION_LOG, $log, false );
	}

	public function get_log( $limit = 50 ) {
		$log = get_option( self::OPTION_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		return array_slice( $log, 0, max( 1, min( self::LOG_RETENTION, (int) $limit ) ) );
	}

	/* ───────────────────── REST ─────────────────────────────────────── */

	public function register_endpoints() {
		$auth = array( 'LuwiPress_Permission', 'check_token_or_admin' );

		register_rest_route( 'luwipress/v1', '/ap2/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_settings' ),
				'permission_callback' => $auth,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_save_settings' ),
				'permission_callback' => $auth,
			),
		) );

		register_rest_route( 'luwipress/v1', '/ap2/mandate/verify', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_verify' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( 'luwipress/v1', '/ap2/log', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_log' ),
			'permission_callback' => $auth,
			'args'                => array(
				'limit' => array( 'default' => 50, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( 'luwipress/v1', '/ap2/transaction/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_transaction' ),
			'permission_callback' => $auth,
		) );

		// Convenience: complete a UCP session WITH an AP2 cart mandate in one
		// call. Thin proxy onto the checkout module's complete step.
		register_rest_route( 'luwipress/v1', '/ap2/checkout/complete', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_checkout_complete' ),
			'permission_callback' => $auth,
		) );
	}

	private function json_body( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}
		return is_array( $body ) ? $body : array();
	}

	public function rest_get_settings() {
		return rest_ensure_response( $this->get_settings() );
	}

	public function rest_save_settings( $request ) {
		return rest_ensure_response( $this->save_settings( $this->json_body( $request ) ) );
	}

	public function rest_verify( $request ) {
		$body    = $this->json_body( $request );
		$mandate = $body['mandate'] ?? null;
		if ( empty( $mandate ) ) {
			return new WP_Error( 'no_mandate', 'A `mandate` is required.', array( 'status' => 422 ) );
		}
		$context = isset( $body['context'] ) && is_array( $body['context'] ) ? $body['context'] : array();
		$verdict = $this->verify_mandate( $mandate, $context );
		list( $amount, $currency ) = $this->extract_mandate_amount( $mandate );
		$verdict['extracted_amount']   = $amount;
		$verdict['extracted_currency'] = $currency;
		return rest_ensure_response( $verdict );
	}

	public function rest_log( $request ) {
		return rest_ensure_response( array( 'entries' => $this->get_log( (int) $request->get_param( 'limit' ) ) ) );
	}

	public function rest_transaction( $request ) {
		$res = $this->get_transaction( (int) $request['id'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public function rest_checkout_complete( $request ) {
		if ( ! class_exists( 'LuwiPress_UCP_Checkout' ) ) {
			return new WP_Error( 'checkout_unavailable', 'UCP checkout module not available.', array( 'status' => 409 ) );
		}
		$body = $this->json_body( $request );
		$sid  = isset( $body['session_id'] ) ? sanitize_text_field( (string) $body['session_id'] ) : '';
		if ( '' === $sid ) {
			return new WP_Error( 'no_session', 'session_id is required.', array( 'status' => 422 ) );
		}
		$res = LuwiPress_UCP_Checkout::get_instance()->complete_session( $sid, $body );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}
}
