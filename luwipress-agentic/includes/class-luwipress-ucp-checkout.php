<?php
/**
 * LuwiPress UCP Native Checkout
 *
 * Implements Google UCP's three checkout endpoints (session create / update /
 * complete) on top of the WooCommerce order pipeline, so an agent on a Google
 * AI surface can build a cart, resolve shipping + tax, and place an order
 * without the buyer leaving the conversation. The merchant stays Merchant of
 * Record; this module never captures payment itself — payment is carried by a
 * UCP payment token or (phase 3) an AP2 Cart Mandate that a processor settles.
 *
 * Totals are computed by WooCommerce itself: each session is backed by a real
 * `checkout-draft` order (the same status the WC Store API / block checkout
 * uses), so tax, coupons, and shipping rates come from the store's own engine
 * rather than a re-implementation. WC's `wc_cleanup_draft_orders` cron purges
 * abandoned drafts automatically.
 *
 * Sandbox mode (default ON) computes everything but never transitions the draft
 * to a payable state — the operator validates against Google's sandbox first.
 *
 * Session state lives in `wp_luwipress_ucp_sessions`; the draft order id is the
 * source of truth for line items + totals.
 *
 * @package    LuwiPress
 * @subpackage Commerce
 * @since      3.5.9-dev
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_UCP_Checkout {

	/** @var self|null */
	private static $instance = null;

	const TABLE_SUFFIX   = 'luwipress_ucp_sessions';
	const SESSION_TTL    = DAY_IN_SECONDS;       // sessions expire after 24h
	const ORDER_SESSION  = '_luwipress_ucp_session_id';
	const ORDER_SANDBOX  = '_luwipress_ucp_sandbox';
	const DRAFT_STATUS    = 'checkout-draft';

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	/* ───────────────────── Table ────────────────────────────────────── */

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE $table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(64) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			order_id BIGINT(20) UNSIGNED NULL,
			currency VARCHAR(8) NULL,
			sandbox TINYINT(1) NOT NULL DEFAULT 1,
			idempotency_key VARCHAR(64) NULL,
			payload LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			expires_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_id (session_id),
			KEY status (status),
			KEY order_id (order_id),
			KEY idempotency_key (idempotency_key)
		) $charset;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ───────────────────── Row helpers ──────────────────────────────── */

	private function get_row( $session_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE session_id = %s", $session_id ), ARRAY_A );
	}

	private function insert_row( $data ) {
		global $wpdb;
		$wpdb->insert( self::table(), $data );
		return $wpdb->insert_id;
	}

	private function update_row( $session_id, $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( self::table(), $data, array( 'session_id' => $session_id ) );
	}

	/* ───────────────────── Product resolution ───────────────────────── */

	/**
	 * Resolve an item reference to a WooCommerce product. A merchant_item_id
	 * mapping wins; otherwise the reference is treated as a product ID.
	 *
	 * @return object|null WC_Product (duck-typed) or null.
	 */
	private function resolve_product( $ref ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		// merchant_item_id mapping override.
		$mapped = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'meta_key'       => LuwiPress_UCP::META_ITEM_ID,
			'meta_value'     => $ref,
			'no_found_rows'  => true,
		) );
		if ( ! empty( $mapped ) ) {
			return wc_get_product( (int) $mapped[0] );
		}
		if ( ctype_digit( $ref ) ) {
			$p = wc_get_product( (int) $ref );
			if ( $p ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Load a WC order by id, guarded so the call is safe on a WC-less install.
	 *
	 * @return mixed WC_Order (duck-typed) or null.
	 */
	private function wc_order( $oid ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( (int) $oid );
		return $order ? $order : null;
	}

	/* ───────────────────── Draft order construction ─────────────────── */

	/**
	 * Build (or rebuild) the draft order line items from a list of
	 * {item, quantity} entries. Returns [WP_Error|true, line_errors].
	 */
	private function apply_items_to_order( $order, $items ) {
		// Clear existing line items first (update path rebuilds cleanly).
		foreach ( $order->get_items() as $item_id => $item ) {
			$order->remove_item( $item_id );
		}
		$errors = array();
		$added  = 0;
		foreach ( (array) $items as $entry ) {
			$ref = '';
			if ( isset( $entry['merchant_item_id'] ) && '' !== (string) $entry['merchant_item_id'] ) {
				$ref = (string) $entry['merchant_item_id'];
			} elseif ( isset( $entry['product_id'] ) ) {
				$ref = (string) $entry['product_id'];
			} elseif ( isset( $entry['id'] ) ) {
				$ref = (string) $entry['id'];
			}
			$qty     = max( 1, (int) ( $entry['quantity'] ?? 1 ) );
			$product = $this->resolve_product( $ref );
			if ( ! $product ) {
				$errors[] = array( 'ref' => $ref, 'error' => 'product_not_found' );
				continue;
			}
			if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				$errors[] = array( 'ref' => $ref, 'error' => 'not_purchasable' );
				continue;
			}
			$order->add_product( $product, $qty );
			$added++;
		}
		if ( 0 === $added ) {
			return array( new WP_Error( 'no_valid_items', 'No purchasable items resolved.', array( 'status' => 422 ) ), $errors );
		}
		return array( true, $errors );
	}

	/**
	 * Best-effort shipping rate discovery for the order's destination. Returns
	 * an array of options [{id,label,cost}]. Never throws — shipping is
	 * advisory until the agent selects a rate.
	 */
	private function get_shipping_options( $order ) {
		$options = array();
		if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) {
			return $options;
		}
		$country = $order->get_shipping_country();
		if ( '' === $country ) {
			return $options; // no destination yet
		}
		try {
			$contents = array();
			$subtotal = 0.0;
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( ! $product ) {
					continue;
				}
				$line_total = (float) $item->get_total();
				$subtotal  += $line_total;
				$contents[ $item->get_id() ] = array(
					'data'         => $product,
					'quantity'     => $item->get_quantity(),
					'line_total'   => $line_total,
					'line_subtotal'=> (float) $item->get_subtotal(),
				);
			}
			$package = array(
				'contents'        => $contents,
				'contents_cost'   => $subtotal,
				'applied_coupons' => array(),
				'destination'     => array(
					'country'   => $country,
					'state'     => $order->get_shipping_state(),
					'postcode'  => $order->get_shipping_postcode(),
					'city'      => $order->get_shipping_city(),
					'address'   => $order->get_shipping_address_1(),
					'address_2' => $order->get_shipping_address_2(),
				),
				'cart_subtotal'   => $subtotal,
			);
			$packages = WC()->shipping()->calculate_shipping_for_package( $package );
			if ( ! empty( $packages['rates'] ) && is_array( $packages['rates'] ) ) {
				foreach ( $packages['rates'] as $rate_id => $rate ) {
					$options[] = array(
						'id'    => $rate_id,
						'label' => method_exists( $rate, 'get_label' ) ? $rate->get_label() : (string) $rate_id,
						'cost'  => method_exists( $rate, 'get_cost' ) ? (float) $rate->get_cost() : 0.0,
					);
				}
			}
		} catch ( \Throwable $e ) {
			LuwiPress_Logger::log( 'UCP shipping calc failed: ' . $e->getMessage(), 'warning' );
		}
		return $options;
	}

	/**
	 * Apply a chosen shipping rate (by rate id) to the order. Clears prior
	 * shipping lines first. No-op if the rate id isn't among the options.
	 */
	private function apply_shipping_rate( $order, $rate_id, $options ) {
		foreach ( $order->get_items( 'shipping' ) as $sid => $sitem ) {
			$order->remove_item( $sid );
		}
		foreach ( $options as $opt ) {
			if ( $opt['id'] === $rate_id ) {
				if ( class_exists( 'WC_Order_Item_Shipping' ) ) {
					$item = new WC_Order_Item_Shipping();
					$item->set_method_title( $opt['label'] );
					$item->set_method_id( $rate_id );
					$item->set_total( (string) $opt['cost'] );
					$order->add_item( $item );
				}
				return true;
			}
		}
		return false;
	}

	/* ───────────────────── Session shaping ──────────────────────────── */

	private function build_session_response( $row, $order = null ) {
		$payload = array();
		if ( ! empty( $row['payload'] ) ) {
			$decoded = json_decode( $row['payload'], true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}
		$resp = array(
			'session_id'       => $row['session_id'],
			'status'           => $row['status'],
			'sandbox'          => (bool) (int) $row['sandbox'],
			'currency'         => $row['currency'],
			'order_id'         => $row['order_id'] ? (int) $row['order_id'] : null,
			'expires_at'       => $row['expires_at'],
			'line_items'       => $payload['line_items'] ?? array(),
			'totals'           => $payload['totals'] ?? array(),
			'shipping_options' => $payload['shipping_options'] ?? array(),
			'selected_shipping'=> $payload['selected_shipping'] ?? null,
			'item_errors'      => $payload['item_errors'] ?? array(),
		);
		if ( $order ) {
			$resp['order_number'] = $order->get_order_number();
			$resp['order_status'] = $order->get_status();
		}
		return $resp;
	}

	/**
	 * Snapshot the order's line items + totals into the payload arrays.
	 */
	private function snapshot_order( $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$pid     = $product ? $product->get_id() : 0;
			$item_id = $pid ? (string) get_post_meta( $pid, LuwiPress_UCP::META_ITEM_ID, true ) : '';
			$lines[] = array(
				'product_id'       => $pid,
				'merchant_item_id' => '' !== $item_id ? $item_id : (string) $pid,
				'name'             => $item->get_name(),
				'quantity'         => $item->get_quantity(),
				'line_total'       => (float) $item->get_total(),
			);
		}
		$totals = array(
			'currency'       => $order->get_currency(),
			'items_subtotal' => (float) $order->get_subtotal(),
			'discount'       => (float) $order->get_total_discount(),
			'shipping'       => (float) $order->get_shipping_total(),
			'tax'            => (float) $order->get_total_tax(),
			'total'          => (float) $order->get_total(),
		);
		return array( 'line_items' => $lines, 'totals' => $totals );
	}

	/* ───────────────────── Session operations ───────────────────────── */

	public function create_session( $body ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return new WP_Error( 'wc_inactive', 'WooCommerce is not active.', array( 'status' => 409 ) );
		}
		$settings = LuwiPress_UCP::get_instance()->get_settings();
		$sandbox  = isset( $body['sandbox'] ) ? ! empty( $body['sandbox'] ) : (bool) $settings['sandbox'];
		$items    = isset( $body['items'] ) && is_array( $body['items'] ) ? $body['items'] : array();
		$idem     = isset( $body['idempotency_key'] ) ? substr( sanitize_text_field( (string) $body['idempotency_key'] ), 0, 64 ) : '';

		if ( empty( $items ) ) {
			return new WP_Error( 'no_items', 'At least one item is required.', array( 'status' => 422 ) );
		}

		// Idempotency: return the existing open session for this key.
		if ( '' !== $idem ) {
			global $wpdb;
			$table = self::table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE idempotency_key = %s AND status = 'open' ORDER BY id DESC LIMIT 1", $idem ), ARRAY_A );
			if ( $existing ) {
				$order = $this->wc_order( $existing['order_id'] );
				return $this->build_session_response( $existing, $order );
			}
		}

		$order = wc_create_order( array( 'status' => self::DRAFT_STATUS ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		list( $ok, $errors ) = $this->apply_items_to_order( $order, $items );
		if ( is_wp_error( $ok ) ) {
			$order->delete( true );
			return $ok;
		}
		$order->set_created_via( 'ucp' );
		$order->update_meta_data( self::ORDER_SANDBOX, $sandbox ? '1' : '0' );
		$order->calculate_totals();
		$order->save();

		$session_id = wp_generate_uuid4();
		$order->update_meta_data( self::ORDER_SESSION, $session_id );
		$order->save();

		$snap = $this->snapshot_order( $order );
		$snap['item_errors'] = $errors;
		$now = current_time( 'mysql' );
		$this->insert_row( array(
			'session_id'      => $session_id,
			'status'          => 'open',
			'order_id'        => $order->get_id(),
			'currency'        => $order->get_currency(),
			'sandbox'         => $sandbox ? 1 : 0,
			'idempotency_key' => '' !== $idem ? $idem : null,
			'payload'         => wp_json_encode( $snap ),
			'created_at'      => $now,
			'updated_at'      => $now,
			'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + self::SESSION_TTL ),
		) );

		return $this->get_session( $session_id );
	}

	public function get_session( $session_id ) {
		$row = $this->get_row( $session_id );
		if ( ! $row ) {
			return new WP_Error( 'session_not_found', 'Checkout session not found.', array( 'status' => 404 ) );
		}
		$order = $row['order_id'] ? $this->wc_order( $row['order_id'] ) : null;
		return $this->build_session_response( $row, $order );
	}

	public function update_session( $session_id, $body ) {
		$row = $this->get_row( $session_id );
		if ( ! $row ) {
			return new WP_Error( 'session_not_found', 'Checkout session not found.', array( 'status' => 404 ) );
		}
		if ( 'completed' === $row['status'] ) {
			return new WP_Error( 'session_closed', 'Session already completed.', array( 'status' => 409 ) );
		}
		$order = $this->wc_order( $row['order_id'] );
		if ( ! $order ) {
			return new WP_Error( 'order_missing', 'Backing order missing.', array( 'status' => 410 ) );
		}

		$payload = json_decode( (string) $row['payload'], true );
		$payload = is_array( $payload ) ? $payload : array();

		// Optional item changes.
		if ( isset( $body['items'] ) && is_array( $body['items'] ) ) {
			list( $ok, $errors ) = $this->apply_items_to_order( $order, $body['items'] );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$payload['item_errors'] = $errors;
		}

		// Address (drives tax + shipping). Accept a flat address object.
		if ( isset( $body['shipping_address'] ) && is_array( $body['shipping_address'] ) ) {
			$this->set_address( $order, 'shipping', $body['shipping_address'] );
			// Mirror to billing for tax base if billing not separately given.
			if ( empty( $body['billing_address'] ) ) {
				$this->set_address( $order, 'billing', $body['shipping_address'] );
			}
		}
		if ( isset( $body['billing_address'] ) && is_array( $body['billing_address'] ) ) {
			$this->set_address( $order, 'billing', $body['billing_address'] );
		}

		// Shipping options + selection.
		$options = $this->get_shipping_options( $order );
		$payload['shipping_options'] = $options;
		$selected = isset( $body['selected_shipping'] ) ? sanitize_text_field( (string) $body['selected_shipping'] ) : ( $payload['selected_shipping'] ?? '' );
		if ( $selected ) {
			if ( $this->apply_shipping_rate( $order, $selected, $options ) ) {
				$payload['selected_shipping'] = $selected;
			}
		}

		$order->calculate_totals();
		$order->save();

		$snap                        = $this->snapshot_order( $order );
		$snap['shipping_options']    = $payload['shipping_options'];
		$snap['selected_shipping']   = $payload['selected_shipping'] ?? null;
		$snap['item_errors']         = $payload['item_errors'] ?? array();

		$this->update_row( $session_id, array(
			'currency' => $order->get_currency(),
			'status'   => 'ready',
			'payload'  => wp_json_encode( $snap ),
		) );

		return $this->get_session( $session_id );
	}

	public function complete_session( $session_id, $body ) {
		$row = $this->get_row( $session_id );
		if ( ! $row ) {
			return new WP_Error( 'session_not_found', 'Checkout session not found.', array( 'status' => 404 ) );
		}
		$order = $this->wc_order( $row['order_id'] );
		if ( ! $order ) {
			return new WP_Error( 'order_missing', 'Backing order missing.', array( 'status' => 410 ) );
		}

		// Idempotency — already completed, return existing order ref.
		if ( 'completed' === $row['status'] ) {
			return $this->finalize_response( $row, $order, true );
		}

		// Buyer + addresses.
		$buyer = isset( $body['buyer'] ) && is_array( $body['buyer'] ) ? $body['buyer'] : array();
		if ( ! empty( $buyer['email'] ) ) {
			$order->set_billing_email( sanitize_email( $buyer['email'] ) );
		}
		if ( ! empty( $buyer['phone'] ) ) {
			$order->set_billing_phone( sanitize_text_field( $buyer['phone'] ) );
		}
		if ( isset( $body['billing_address'] ) && is_array( $body['billing_address'] ) ) {
			$this->set_address( $order, 'billing', $body['billing_address'] );
		}
		if ( isset( $body['shipping_address'] ) && is_array( $body['shipping_address'] ) ) {
			$this->set_address( $order, 'shipping', $body['shipping_address'] );
		}

		// Re-apply selected shipping then recompute, so the final total is
		// authoritative (never trust a client-supplied total).
		$payload  = json_decode( (string) $row['payload'], true );
		$payload  = is_array( $payload ) ? $payload : array();
		$selected = $payload['selected_shipping'] ?? ( isset( $body['selected_shipping'] ) ? sanitize_text_field( (string) $body['selected_shipping'] ) : '' );
		if ( $selected ) {
			$options = $this->get_shipping_options( $order );
			$this->apply_shipping_rate( $order, $selected, $options );
		}
		$order->calculate_totals();

		// Phase 3 extension point: an AP2 Cart Mandate may accompany the
		// completion. When the AP2 module is present, let it verify + attach
		// the mandate chain before we finalize. A WP_Error aborts the order.
		if ( ! empty( $body['ap2_cart_mandate'] ) && class_exists( 'LuwiPress_AP2' ) ) {
			$verdict = LuwiPress_AP2::get_instance()->attach_to_order( $order, $body );
			if ( is_wp_error( $verdict ) ) {
				return $verdict;
			}
		}

		$sandbox = (bool) (int) $row['sandbox'];
		if ( $sandbox ) {
			// Sandbox: do NOT create a real payable order. Keep the draft,
			// flag it, and return a simulated success with authoritative totals.
			$order->add_order_note( 'UCP sandbox checkout — simulated completion (no payment).' );
			$order->save();
			$this->update_row( $session_id, array(
				'status'  => 'completed',
				'payload' => wp_json_encode( array_merge( $payload, $this->snapshot_order( $order ), array( 'simulated' => true ) ) ),
			) );
			$out             = $this->finalize_response( $this->get_row( $session_id ), $order, false );
			$out['simulated'] = true;
			return $out;
		}

		// Live: transition the draft into a real pending order. Payment capture
		// is the processor's job (UCP token / AP2 mandate) — we hand off a
		// pending order and fire the normal WC status hooks (attribution etc.).
		$final_status = apply_filters( 'luwipress_ucp_completed_order_status', 'pending', $order, $body );
		$order->set_status( $final_status, 'UCP native checkout completed by agent.' );
		$order->save();

		$this->update_row( $session_id, array(
			'status'  => 'completed',
			'payload' => wp_json_encode( array_merge( $payload, $this->snapshot_order( $order ) ) ),
		) );

		return $this->finalize_response( $this->get_row( $session_id ), $order, false );
	}

	private function finalize_response( $row, $order, $idempotent_replay ) {
		$resp                    = $this->build_session_response( $row, $order );
		$resp['completed']       = true;
		$resp['idempotent_replay'] = (bool) $idempotent_replay;
		$resp['order_id']        = $order->get_id();
		$resp['order_number']    = $order->get_order_number();
		$resp['order_status']    = $order->get_status();
		return $resp;
	}

	/**
	 * Map a flat address array onto the order's billing/shipping fields.
	 */
	private function set_address( $order, $type, $addr ) {
		$map = array(
			'first_name', 'last_name', 'company', 'address_1', 'address_2',
			'city', 'state', 'postcode', 'country',
		);
		foreach ( $map as $field ) {
			if ( isset( $addr[ $field ] ) ) {
				$setter = "set_{$type}_{$field}";
				if ( method_exists( $order, $setter ) ) {
					$order->{$setter}( sanitize_text_field( (string) $addr[ $field ] ) );
				}
			}
		}
		// billing-only contact fields
		if ( 'billing' === $type ) {
			if ( isset( $addr['email'] ) ) {
				$order->set_billing_email( sanitize_email( (string) $addr['email'] ) );
			}
			if ( isset( $addr['phone'] ) ) {
				$order->set_billing_phone( sanitize_text_field( (string) $addr['phone'] ) );
			}
		}
	}

	/* ───────────────────── REST ─────────────────────────────────────── */

	public function register_endpoints() {
		$auth = array( 'LuwiPress_Permission', 'check_token_or_admin' );

		register_rest_route( 'luwipress/v1', '/ucp/checkout/session', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_create' ),
			'permission_callback' => $auth,
		) );

		// More specific /complete route registered before the id-only route.
		register_rest_route( 'luwipress/v1', '/ucp/checkout/session/(?P<id>[A-Za-z0-9\-]+)/complete', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_complete' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( 'luwipress/v1', '/ucp/checkout/session/(?P<id>[A-Za-z0-9\-]+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get' ),
				'permission_callback' => $auth,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_update' ),
				'permission_callback' => $auth,
			),
		) );
	}

	private function json_body( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}
		return is_array( $body ) ? $body : array();
	}

	public function rest_create( $request ) {
		$res = $this->create_session( $this->json_body( $request ) );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public function rest_get( $request ) {
		$res = $this->get_session( sanitize_text_field( (string) $request['id'] ) );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public function rest_update( $request ) {
		$res = $this->update_session( sanitize_text_field( (string) $request['id'] ), $this->json_body( $request ) );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public function rest_complete( $request ) {
		$res = $this->complete_session( sanitize_text_field( (string) $request['id'] ), $this->json_body( $request ) );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}
}
