<?php
/**
 * LuwiPress CRM Bridge — Customer Intelligence Layer
 *
 * Pure-WooCommerce customer segmentation and lifecycle event generation.
 * Segments are computed from order history; lifecycle events (post-purchase,
 * review request, win-back) are queued for consumption by the LuwiPress
 * email pipeline or any downstream automation via REST.
 *
 * No third-party CRM plugin integration. If the store uses FluentCRM,
 * Mailchimp, Klaviyo, etc., let those plugins own their own automations —
 * LuwiPress reports on WooCommerce data only.
 *
 * @package LuwiPress
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_CRM_Bridge {

	private static $instance = null;

	/** @var array Customer segment definitions */
	private $segments = array(
		'vip'        => array( 'label' => 'VIP',           'color' => '#6366f1' ),
		'loyal'      => array( 'label' => 'Loyal',         'color' => '#16a34a' ),
		'active'     => array( 'label' => 'Active',        'color' => '#0ea5e9' ),
		'new'        => array( 'label' => 'New',           'color' => '#8b5cf6' ),
		'at_risk'    => array( 'label' => 'At Risk',       'color' => '#eab308' ),
		'dormant'    => array( 'label' => 'Dormant',       'color' => '#f97316' ),
		'lost'       => array( 'label' => 'Lost',          'color' => '#dc2626' ),
		'one_time'   => array( 'label' => 'One-Time',      'color' => '#9ca3af' ),
	);

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );

		// Weekly cron: refresh customer segments
		add_action( 'luwipress_crm_segment_refresh', array( $this, 'cron_refresh_segments' ) );
		if ( ! wp_next_scheduled( 'luwipress_crm_segment_refresh' ) ) {
			wp_schedule_event( time(), 'weekly', 'luwipress_crm_segment_refresh' );
		}
	}

	public function register_endpoints() {
		// Customer intelligence overview
		register_rest_route( 'luwipress/v1', '/crm/overview', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_overview' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		// Customer segments with counts
		register_rest_route( 'luwipress/v1', '/crm/segments', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_segments' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		// Customers in a segment
		register_rest_route( 'luwipress/v1', '/crm/segment/(?P<segment>[a-z_]+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_segment_customers' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'segment' => array( 'required' => true, 'type' => 'string' ),
				'limit'   => array( 'default' => 20, 'sanitize_callback' => 'absint' ),
			),
		) );

		// Single customer profile (aggregated)
		register_rest_route( 'luwipress/v1', '/crm/customer/(?P<customer_id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_customer_profile' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		// Lifecycle events ready for downstream processing
		register_rest_route( 'luwipress/v1', '/crm/lifecycle-queue', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_lifecycle_queue' ),
			'permission_callback' => array( $this, 'check_token' ),
		) );

		// Mark a lifecycle event as processed
		register_rest_route( 'luwipress/v1', '/crm/lifecycle-callback', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_lifecycle_callback' ),
			'permission_callback' => array( $this, 'check_token' ),
		) );

		// Manual trigger — recompute all segments + regenerate lifecycle events.
		// Useful when thresholds change, after data imports, or when the weekly
		// cron tick is late. Writes user_meta, so requires token-or-admin.
		register_rest_route( 'luwipress/v1', '/crm/refresh-segments', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_refresh_segments' ),
			'permission_callback' => array( $this, 'check_token' ),
		) );

		// Settings — GET reads current thresholds, POST partial-updates any
		// subset. Typical use: `{ loyal_orders: 2 }` on a high-ticket store
		// where 3 repeat orders is too aggressive. Follows the partial-update
		// pattern used by /enrich/settings etc. — only present keys are
		// touched, absent keys keep their current value.
		register_rest_route( 'luwipress/v1', '/crm/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get_settings' ),
				'permission_callback' => array( $this, 'check_token' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_update_settings' ),
				'permission_callback' => array( $this, 'check_token' ),
			),
		) );
	}

	// ─── OVERVIEW: Store-wide customer intelligence ────────────────────

	public function handle_overview( $request ) {
		$data = array(
			'total_customers'  => $this->count_customers(),
			'segments'         => $this->get_segment_counts(),
			'revenue_summary'  => $this->get_revenue_summary(),
			'lifecycle_stats'  => $this->get_lifecycle_stats(),
			'last_refresh'     => get_option( 'luwipress_crm_last_refresh', '' ),
		);

		return rest_ensure_response( $data );
	}

	// ─── SEGMENTS: WooCommerce-derived customer segments ───────────────

	public function handle_segments( $request ) {
		return rest_ensure_response( array(
			'segments'     => $this->get_segment_counts(),
			'definitions'  => $this->segments,
			'last_refresh' => get_option( 'luwipress_crm_last_refresh', '' ),
		) );
	}

	public function handle_segment_customers( $request ) {
		$segment = $request->get_param( 'segment' );
		$limit   = min( $request->get_param( 'limit' ), 100 );

		if ( ! isset( $this->segments[ $segment ] ) ) {
			return new WP_Error( 'invalid_segment', 'Unknown segment: ' . $segment, array( 'status' => 400 ) );
		}

		$customers = $this->get_customers_in_segment( $segment, $limit );

		return rest_ensure_response( array(
			'segment'   => $segment,
			'label'     => $this->segments[ $segment ]['label'],
			'count'     => count( $customers ),
			'customers' => $customers,
		) );
	}

	// ─── CUSTOMER PROFILE: Aggregated view ─────────────────────────────

	public function handle_customer_profile( $request ) {
		$customer_id = absint( $request->get_param( 'customer_id' ) );

		$customer = new WC_Customer( $customer_id );
		if ( ! $customer->get_id() ) {
			return new WP_Error( 'not_found', 'Customer not found.', array( 'status' => 404 ) );
		}

		$profile = $this->build_customer_profile( $customer );

		return rest_ensure_response( $profile );
	}

	// ─── LIFECYCLE QUEUE ───────────────────────────────────────────────

	public function handle_lifecycle_queue( $request ) {
		$events = $this->get_pending_lifecycle_events();

		return rest_ensure_response( array(
			'count'  => count( $events ),
			'events' => $events,
		) );
	}

	public function handle_lifecycle_callback( $request ) {
		$data     = $request->get_json_params();
		$event_id = sanitize_text_field( $data['event_id'] ?? '' );
		$status   = sanitize_text_field( $data['status'] ?? 'completed' );

		if ( empty( $event_id ) ) {
			return new WP_Error( 'missing_id', 'event_id is required.', array( 'status' => 400 ) );
		}

		// Mark the event as processed
		$events = get_option( 'luwipress_crm_lifecycle_events', array() );
		if ( isset( $events[ $event_id ] ) ) {
			$events[ $event_id ]['status']       = $status;
			$events[ $event_id ]['processed_at'] = current_time( 'mysql' );
			update_option( 'luwipress_crm_lifecycle_events', $events, 'no' );
		}

		return array( 'success' => true, 'event_id' => $event_id );
	}

	// ─── CRON: Refresh segments weekly ─────────────────────────────────

	public function cron_refresh_segments() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$this->compute_all_segments();
		$this->generate_lifecycle_events();

		update_option( 'luwipress_crm_last_refresh', current_time( 'mysql' ) );

		LuwiPress_Logger::log( 'CRM segments refreshed', 'info' );
	}

	// ─── MANUAL REFRESH ENDPOINT ──────────────────────────────────────

	// ─── SETTINGS ENDPOINTS ────────────────────────────────────────────

	private function settings_schema() {
		return array(
			'vip_spend'    => array( 'option' => 'luwipress_crm_vip_threshold', 'default' => 1000, 'type' => 'float', 'description' => 'Lifetime spend (store currency) at which a customer becomes VIP (also requires loyal_orders).' ),
			'loyal_orders' => array( 'option' => 'luwipress_crm_loyal_orders',  'default' => 3,    'type' => 'int',   'description' => 'Order count at which a repeat customer becomes Loyal. Drop to 2 for high-ticket / low-frequency stores.' ),
			'active_days'  => array( 'option' => 'luwipress_crm_active_days',   'default' => 90,   'type' => 'int',   'description' => 'Recency window (days) during which a customer is considered Active.' ),
			'at_risk_days' => array( 'option' => 'luwipress_crm_at_risk_days',  'default' => 180,  'type' => 'int',   'description' => 'Max days since last order before customer becomes At Risk.' ),
			'dormant_days' => array( 'option' => 'luwipress_crm_dormant_days',  'default' => 365,  'type' => 'int',   'description' => 'Max days since last order before customer becomes Dormant (beyond this → Lost or One-Time).' ),
			'new_days'     => array( 'option' => 'luwipress_crm_new_days',      'default' => 30,   'type' => 'int',   'description' => 'First-time customer window (days since first order) tagged as New.' ),
		);
	}

	public function handle_get_settings( $request ) {
		$schema = $this->settings_schema();
		$values = array();
		foreach ( $schema as $key => $meta ) {
			$raw = get_option( $meta['option'], $meta['default'] );
			$values[ $key ] = 'float' === $meta['type'] ? floatval( $raw ) : absint( $raw );
		}
		return rest_ensure_response( array(
			'values' => $values,
			'schema' => $schema,
		) );
	}

	public function handle_update_settings( $request ) {
		$schema  = $this->settings_schema();
		$updated = array();
		$params  = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		foreach ( $schema as $key => $meta ) {
			if ( ! array_key_exists( $key, $params ) ) {
				continue;
			}
			$raw = $params[ $key ];
			if ( 'float' === $meta['type'] ) {
				$value = floatval( $raw );
				if ( $value < 0 ) {
					return new WP_Error( 'invalid_value', sprintf( '%s must be non-negative.', $key ), array( 'status' => 400 ) );
				}
			} else {
				$value = absint( $raw );
				if ( $value < 1 ) {
					return new WP_Error( 'invalid_value', sprintf( '%s must be a positive integer.', $key ), array( 'status' => 400 ) );
				}
			}
			update_option( $meta['option'], $value, false );
			$updated[ $key ] = $value;
		}

		if ( empty( $updated ) ) {
			return new WP_Error( 'no_changes', 'No recognised settings keys in request body.', array( 'status' => 400 ) );
		}

		LuwiPress_Logger::log(
			'CRM settings updated: ' . implode( ', ', array_map(
				function ( $k, $v ) { return $k . '=' . $v; },
				array_keys( $updated ),
				array_values( $updated )
			) ),
			'info',
			array( 'updated' => $updated )
		);

		return rest_ensure_response( array(
			'ok'      => true,
			'updated' => $updated,
			'hint'    => 'Call POST /crm/refresh-segments to reclassify existing customers with the new thresholds.',
		) );
	}

	public function handle_refresh_segments( $request ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'no_woocommerce', 'WooCommerce is not active.', array( 'status' => 400 ) );
		}

		$started = microtime( true );
		$counts  = $this->compute_all_segments();
		$this->generate_lifecycle_events();

		update_option( 'luwipress_crm_last_refresh', current_time( 'mysql' ) );
		LuwiPress_Logger::log( 'CRM segments refreshed (manual)', 'info' );

		return rest_ensure_response( array(
			'ok'              => true,
			'counts'          => $counts,
			'execution_time'  => round( microtime( true ) - $started, 2 ),
			'refreshed_at'    => current_time( 'mysql' ),
		) );
	}

	// ─── SEGMENT COMPUTATION ───────────────────────────────────────────

	private function compute_all_segments() {
		global $wpdb;

		$now = current_time( 'timestamp' );
		$thresholds = array(
			'vip_spend'     => floatval( get_option( 'luwipress_crm_vip_threshold', 1000 ) ),
			'active_days'   => absint( get_option( 'luwipress_crm_active_days', 90 ) ),
			'at_risk_days'  => absint( get_option( 'luwipress_crm_at_risk_days', 180 ) ),
			'dormant_days'  => absint( get_option( 'luwipress_crm_dormant_days', 365 ) ),
			'loyal_orders'  => absint( get_option( 'luwipress_crm_loyal_orders', 3 ) ),
			'new_days'      => absint( get_option( 'luwipress_crm_new_days', 30 ) ),
		);

		// Get all customers with order stats
		$customers = $wpdb->get_results(
			"SELECT pm_customer.meta_value as customer_id,
			        COUNT(p.ID) as order_count,
			        SUM(pm_total.meta_value) as total_spent,
			        MAX(p.post_date) as last_order_date,
			        MIN(p.post_date) as first_order_date
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm_customer ON p.ID = pm_customer.post_id AND pm_customer.meta_key = '_customer_user'
			 INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status IN ('wc-completed', 'wc-processing')
			   AND pm_customer.meta_value > 0
			 GROUP BY pm_customer.meta_value
			 ORDER BY total_spent DESC
			 LIMIT 5000"
		);

		$segments = array();
		foreach ( $customers as $c ) {
			$cid           = absint( $c->customer_id );
			$order_count   = absint( $c->order_count );
			$total_spent   = floatval( $c->total_spent );
			$last_order_ts = strtotime( $c->last_order_date );
			$first_order_ts = strtotime( $c->first_order_date );
			$days_since    = ( $now - $last_order_ts ) / DAY_IN_SECONDS;
			$days_as_customer = ( $now - $first_order_ts ) / DAY_IN_SECONDS;

			$segment = 'one_time'; // default

			if ( $total_spent >= $thresholds['vip_spend'] && $order_count >= $thresholds['loyal_orders'] ) {
				$segment = 'vip';
			} elseif ( $order_count >= $thresholds['loyal_orders'] && $days_since <= $thresholds['active_days'] ) {
				$segment = 'loyal';
			} elseif ( $days_since <= $thresholds['active_days'] ) {
				$segment = $days_as_customer <= $thresholds['new_days'] ? 'new' : 'active';
			} elseif ( $days_since <= $thresholds['at_risk_days'] ) {
				$segment = 'at_risk';
			} elseif ( $days_since <= $thresholds['dormant_days'] ) {
				$segment = 'dormant';
			} else {
				$segment = 'lost';
			}

			// `one_time` = single-order customer who never came back. Only relabel
			// `lost` (past the dormant window) as `one_time` — recency categories
			// (`active` / `at_risk` / `dormant`) are more actionable for 1-order
			// customers than a generic "bought once" bucket and should be kept.
			if ( $order_count === 1 && $segment === 'lost' ) {
				$segment = 'one_time';
			}

			$segments[ $cid ] = $segment;

			// Store segment on user meta for quick access
			update_user_meta( $cid, '_luwipress_crm_segment', $segment );
			update_user_meta( $cid, '_luwipress_crm_stats', array(
				'order_count'  => $order_count,
				'total_spent'  => $total_spent,
				'last_order'   => $c->last_order_date,
				'first_order'  => $c->first_order_date,
				'days_since'   => round( $days_since ),
				'computed_at'  => current_time( 'mysql' ),
			) );
		}

		// Store counts
		$counts = array_count_values( $segments );
		foreach ( array_keys( $this->segments ) as $key ) {
			$counts[ $key ] = $counts[ $key ] ?? 0;
		}
		update_option( 'luwipress_crm_segment_counts', $counts );

		return $counts;
	}

	// ─── LIFECYCLE EVENTS ──────────────────────────────────────────────

	private function generate_lifecycle_events() {
		global $wpdb;

		$events = get_option( 'luwipress_crm_lifecycle_events', array() );

		// Clean old processed events (older than 7 days)
		$cutoff = strtotime( '-7 days' );
		foreach ( $events as $id => $event ) {
			if ( 'completed' === ( $event['status'] ?? '' ) && strtotime( $event['processed_at'] ?? '' ) < $cutoff ) {
				unset( $events[ $id ] );
			}
		}

		// 1. Post-purchase thank you (orders completed in last 24h, not yet triggered)
		$recent_orders = $wpdb->get_results(
			"SELECT p.ID, pm.meta_value as customer_id
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_customer_user'
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status = 'wc-completed'
			   AND p.post_modified > DATE_SUB(NOW(), INTERVAL 24 HOUR)
			   AND pm.meta_value > 0
			 ORDER BY p.post_modified DESC
			 LIMIT 50"
		);

		foreach ( $recent_orders as $order ) {
			$event_key = 'post_purchase_' . $order->ID;
			if ( ! isset( $events[ $event_key ] ) ) {
				$customer = new WC_Customer( absint( $order->customer_id ) );
				$events[ $event_key ] = array(
					'type'        => 'post_purchase',
					'customer_id' => absint( $order->customer_id ),
					'order_id'    => absint( $order->ID ),
					'email'       => $customer->get_email(),
					'name'        => $customer->get_first_name() ?: $customer->get_display_name(),
					'status'      => 'pending',
					'created_at'  => current_time( 'mysql' ),
				);
			}
		}

		// 2. Review request (orders completed 7+ days ago, no review yet)
		$review_candidates = $wpdb->get_results(
			"SELECT p.ID, pm.meta_value as customer_id, pm2.meta_value as billing_email
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_customer_user'
			 INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_billing_email'
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status = 'wc-completed'
			   AND p.post_modified BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY)
			   AND pm.meta_value > 0
			 ORDER BY p.post_modified DESC
			 LIMIT 30"
		);

		foreach ( $review_candidates as $order ) {
			$event_key = 'review_request_' . $order->ID;
			if ( ! isset( $events[ $event_key ] ) ) {
				// Check if customer already left a review for any product in this order
				$wc_order = wc_get_order( $order->ID );
				if ( ! $wc_order ) {
					continue;
				}
				$has_review = false;
				foreach ( $wc_order->get_items() as $item ) {
					$product_id = $item->get_product_id();
					$existing = get_comments( array(
						'post_id'      => $product_id,
						'author_email' => $order->billing_email,
						'type'         => 'review',
						'count'        => true,
					) );
					if ( $existing > 0 ) {
						$has_review = true;
						break;
					}
				}

				if ( ! $has_review ) {
					$customer = new WC_Customer( absint( $order->customer_id ) );
					$events[ $event_key ] = array(
						'type'        => 'review_request',
						'customer_id' => absint( $order->customer_id ),
						'order_id'    => absint( $order->ID ),
						'email'       => $order->billing_email,
						'name'        => $customer->get_first_name() ?: $customer->get_display_name(),
						'status'      => 'pending',
						'created_at'  => current_time( 'mysql' ),
					);
				}
			}
		}

		// 3. Win-back: at_risk customers (haven't ordered in 90-180 days)
		$at_risk = $wpdb->get_results(
			"SELECT pm.meta_value as customer_id, MAX(p.post_date) as last_order
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_customer_user'
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status IN ('wc-completed', 'wc-processing')
			   AND pm.meta_value > 0
			 GROUP BY pm.meta_value
			 HAVING last_order BETWEEN DATE_SUB(NOW(), INTERVAL 180 DAY) AND DATE_SUB(NOW(), INTERVAL 90 DAY)
			 LIMIT 20"
		);

		foreach ( $at_risk as $c ) {
			$event_key = 'win_back_' . $c->customer_id . '_' . gmdate( 'Ym' );
			if ( ! isset( $events[ $event_key ] ) ) {
				$customer = new WC_Customer( absint( $c->customer_id ) );
				if ( $customer->get_email() ) {
					$events[ $event_key ] = array(
						'type'        => 'win_back',
						'customer_id' => absint( $c->customer_id ),
						'email'       => $customer->get_email(),
						'name'        => $customer->get_first_name() ?: $customer->get_display_name(),
						'last_order'  => $c->last_order,
						'status'      => 'pending',
						'created_at'  => current_time( 'mysql' ),
					);
				}
			}
		}

		update_option( 'luwipress_crm_lifecycle_events', $events, 'no' );
	}

	// ─── HELPERS ───────────────────────────────────────────────────────

	private function count_customers() {
		$result = count_users();
		// WooCommerce customer role
		return $result['avail_roles']['customer'] ?? 0;
	}

	private function get_segment_counts() {
		$counts = get_option( 'luwipress_crm_segment_counts', array() );
		if ( empty( $counts ) ) {
			// Quick compute if never run
			$counts = $this->compute_all_segments();
		}
		return $counts;
	}

	private function get_revenue_summary() {
		global $wpdb;

		$thirtydays = $wpdb->get_row(
			"SELECT COUNT(*) as orders, COALESCE(SUM(pm.meta_value), 0) as revenue
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status IN ('wc-completed', 'wc-processing')
			   AND p.post_date > DATE_SUB(NOW(), INTERVAL 30 DAY)"
		);

		$lifetime = $wpdb->get_row(
			"SELECT COUNT(*) as orders, COALESCE(SUM(pm.meta_value), 0) as revenue
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status IN ('wc-completed', 'wc-processing')"
		);

		$avg_order = $lifetime->orders > 0 ? round( $lifetime->revenue / $lifetime->orders, 2 ) : 0;

		return array(
			'last_30_days' => array(
				'orders'  => absint( $thirtydays->orders ),
				'revenue' => round( floatval( $thirtydays->revenue ), 2 ),
			),
			'lifetime' => array(
				'orders'  => absint( $lifetime->orders ),
				'revenue' => round( floatval( $lifetime->revenue ), 2 ),
			),
			'average_order_value' => $avg_order,
		);
	}

	private function get_lifecycle_stats() {
		$events = get_option( 'luwipress_crm_lifecycle_events', array() );
		$counts = array( 'post_purchase' => 0, 'review_request' => 0, 'win_back' => 0 );
		$pending = 0;

		foreach ( $events as $event ) {
			$type = $event['type'] ?? '';
			if ( isset( $counts[ $type ] ) ) {
				$counts[ $type ]++;
			}
			if ( 'pending' === ( $event['status'] ?? '' ) ) {
				$pending++;
			}
		}

		return array(
			'total_events'   => count( $events ),
			'pending_events' => $pending,
			'by_type'        => $counts,
		);
	}

	private function get_customers_in_segment( $segment, $limit = 20 ) {
		$users = get_users( array(
			'meta_key'   => '_luwipress_crm_segment',
			'meta_value' => $segment,
			'number'     => $limit,
			'role'       => 'customer',
		) );

		// Prime user meta cache in one query to avoid N+1
		$user_ids = wp_list_pluck( $users, 'ID' );
		if ( ! empty( $user_ids ) ) {
			update_meta_cache( 'user', $user_ids );
		}

		$customers = array();
		foreach ( $users as $user ) {
			$stats = get_user_meta( $user->ID, '_luwipress_crm_stats', true );
			$customers[] = array(
				'id'           => $user->ID,
				'name'         => $user->display_name,
				'email'        => $user->user_email,
				'order_count'  => $stats['order_count'] ?? 0,
				'total_spent'  => $stats['total_spent'] ?? 0,
				'last_order'   => $stats['last_order'] ?? '',
				'days_since'   => $stats['days_since'] ?? 0,
			);
		}

		return $customers;
	}

	private function build_customer_profile( $customer ) {
		$user_id = $customer->get_id();
		$stats   = get_user_meta( $user_id, '_luwipress_crm_stats', true );
		$segment = get_user_meta( $user_id, '_luwipress_crm_segment', true );

		// Recent orders
		$orders = wc_get_orders( array(
			'customer_id' => $user_id,
			'limit'       => 5,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'status'      => array( 'completed', 'processing' ),
		) );

		$recent_orders = array();
		foreach ( $orders as $order ) {
			$recent_orders[] = array(
				'id'     => $order->get_id(),
				'date'   => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d' ) : '',
				'total'  => $order->get_total(),
				'items'  => $order->get_item_count(),
				'status' => $order->get_status(),
			);
		}

		// Reviews
		$reviews = get_comments( array(
			'user_id' => $user_id,
			'type'    => 'review',
			'number'  => 5,
		) );

		$review_list = array();
		foreach ( $reviews as $review ) {
			$rating = get_comment_meta( $review->comment_ID, 'rating', true );
			$review_list[] = array(
				'product_id' => $review->comment_post_ID,
				'product'    => get_the_title( $review->comment_post_ID ),
				'rating'     => $rating ?: 0,
				'date'       => $review->comment_date,
				'excerpt'    => wp_trim_words( $review->comment_content, 20 ),
			);
		}

		return array(
			'id'            => $user_id,
			'name'          => $customer->get_first_name() . ' ' . $customer->get_last_name(),
			'email'         => $customer->get_email(),
			'registered'    => $customer->get_date_created() ? $customer->get_date_created()->format( 'Y-m-d' ) : '',
			'segment'       => $segment ?: 'unknown',
			'segment_label' => $this->segments[ $segment ]['label'] ?? 'Unknown',
			'stats'         => $stats ?: array(),
			'recent_orders' => $recent_orders,
			'reviews'       => $review_list,
			'location'      => array(
				'city'    => $customer->get_billing_city(),
				'state'   => $customer->get_billing_state(),
				'country' => $customer->get_billing_country(),
			),
		);
	}

	/**
	 * Get pending lifecycle events for downstream processing.
	 */
	private function get_pending_lifecycle_events() {
		$events  = get_option( 'luwipress_crm_lifecycle_events', array() );
		$pending = array();

		foreach ( $events as $id => $event ) {
			if ( 'pending' === ( $event['status'] ?? '' ) ) {
				$event['event_id'] = $id;
				$pending[] = $event;
			}
		}

		return array_slice( $pending, 0, 20 );
	}

	// ─── PERMISSIONS ───────────────────────────────────────────────────

	public function check_permission( $request ) {
		return LuwiPress_Permission::check_token_or_admin( $request );
	}

	public function check_token( $request ) {
		return LuwiPress_Permission::check_token( $request );
	}
}
