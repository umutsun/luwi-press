<?php
/**
 * n8nPress CRM Bridge — Customer Intelligence Layer
 *
 * Adds AI-powered customer intelligence on top of WooCommerce data.
 * If a CRM plugin (FluentCRM, Mailchimp, Klaviyo) exists, reads their
 * tags/segments and enriches — never duplicates contact management.
 *
 * If no CRM plugin exists, provides lightweight customer segmentation
 * and lifecycle automation through n8n workflows.
 *
 * Requires Open Claw to be active for natural language customer queries.
 *
 * @package n8nPress
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class N8nPress_CRM_Bridge {

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
		add_action( 'n8npress_crm_segment_refresh', array( $this, 'cron_refresh_segments' ) );
		if ( ! wp_next_scheduled( 'n8npress_crm_segment_refresh' ) ) {
			wp_schedule_event( time(), 'weekly', 'n8npress_crm_segment_refresh' );
		}
	}

	public function register_endpoints() {
		// Customer intelligence overview
		register_rest_route( 'n8npress/v1', '/crm/overview', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_overview' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		// Customer segments with counts
		register_rest_route( 'n8npress/v1', '/crm/segments', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_segments' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		// Customers in a segment
		register_rest_route( 'n8npress/v1', '/crm/segment/(?P<segment>[a-z_]+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_segment_customers' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'segment' => array( 'required' => true, 'type' => 'string' ),
				'limit'   => array( 'default' => 20, 'sanitize_callback' => 'absint' ),
			),
		) );

		// Single customer profile (aggregated)
		register_rest_route( 'n8npress/v1', '/crm/customer/(?P<customer_id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_customer_profile' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		// Lifecycle events ready for n8n automation
		register_rest_route( 'n8npress/v1', '/crm/lifecycle-queue', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_lifecycle_queue' ),
			'permission_callback' => array( $this, 'check_n8n_token' ),
		) );

		// n8n callback: mark lifecycle event as processed
		register_rest_route( 'n8npress/v1', '/crm/lifecycle-callback', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_lifecycle_callback' ),
			'permission_callback' => array( $this, 'check_n8n_token' ),
		) );
	}

	// ─── OVERVIEW: Store-wide customer intelligence ────────────────────

	public function handle_overview( $request ) {
		$detector = N8nPress_Plugin_Detector::get_instance();
		$crm      = $detector->detect_crm();

		$data = array(
			'crm_plugin'       => $crm['plugin'],
			'crm_version'      => $crm['version'],
			'total_customers'  => $this->count_customers(),
			'segments'         => $this->get_segment_counts(),
			'revenue_summary'  => $this->get_revenue_summary(),
			'lifecycle_stats'  => $this->get_lifecycle_stats(),
			'last_refresh'     => get_option( 'n8npress_crm_last_refresh', '' ),
		);

		// If CRM plugin exists, add its tag/list info
		if ( 'fluentcrm' === $crm['plugin'] ) {
			$data['crm_info'] = $this->get_fluentcrm_info();
		} elseif ( 'mailchimp-for-woocommerce' === $crm['plugin'] ) {
			$data['crm_info'] = $this->get_mailchimp_info();
		}

		return rest_ensure_response( $data );
	}

	// ─── SEGMENTS: AI-computed customer segments ───────────────────────

	public function handle_segments( $request ) {
		return rest_ensure_response( array(
			'segments'     => $this->get_segment_counts(),
			'definitions'  => $this->segments,
			'last_refresh' => get_option( 'n8npress_crm_last_refresh', '' ),
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

	// ─── LIFECYCLE QUEUE: Events ready for n8n automation ──────────────

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
		$events = get_option( 'n8npress_crm_lifecycle_events', array() );
		if ( isset( $events[ $event_id ] ) ) {
			$events[ $event_id ]['status']       = $status;
			$events[ $event_id ]['processed_at'] = current_time( 'mysql' );
			update_option( 'n8npress_crm_lifecycle_events', $events );
		}

		return array( 'success' => true, 'event_id' => $event_id );
	}

	// ─── CRON: Refresh segments weekly ─────────────────────────────────

	public function cron_refresh_segments() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$detector = N8nPress_Plugin_Detector::get_instance();
		$crm      = $detector->detect_crm();

		// If a CRM plugin handles segmentation, skip heavy computation
		if ( 'none' !== $crm['plugin'] ) {
			N8nPress_Logger::log( 'CRM segment refresh skipped — ' . $crm['plugin'] . ' handles segmentation', 'info' );
			update_option( 'n8npress_crm_last_refresh', current_time( 'mysql' ) );
			return;
		}

		// Compute segments from WooCommerce data
		$this->compute_all_segments();

		// Generate lifecycle events
		$this->generate_lifecycle_events();

		update_option( 'n8npress_crm_last_refresh', current_time( 'mysql' ) );

		N8nPress_Logger::log( 'CRM segments refreshed', 'info' );
	}

	// ─── SEGMENT COMPUTATION ───────────────────────────────────────────

	private function compute_all_segments() {
		global $wpdb;

		$now = current_time( 'timestamp' );
		$thresholds = array(
			'vip_spend'     => floatval( get_option( 'n8npress_crm_vip_threshold', 1000 ) ),
			'active_days'   => absint( get_option( 'n8npress_crm_active_days', 90 ) ),
			'at_risk_days'  => absint( get_option( 'n8npress_crm_at_risk_days', 180 ) ),
			'dormant_days'  => absint( get_option( 'n8npress_crm_dormant_days', 365 ) ),
			'loyal_orders'  => absint( get_option( 'n8npress_crm_loyal_orders', 3 ) ),
			'new_days'      => absint( get_option( 'n8npress_crm_new_days', 30 ) ),
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

			// Override: single order customers stay one_time unless VIP-level spend
			if ( $order_count === 1 && $segment !== 'vip' && $segment !== 'new' ) {
				$segment = 'one_time';
			}

			$segments[ $cid ] = $segment;

			// Store segment on user meta for quick access
			update_user_meta( $cid, '_n8npress_crm_segment', $segment );
			update_user_meta( $cid, '_n8npress_crm_stats', array(
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
		update_option( 'n8npress_crm_segment_counts', $counts );

		return $counts;
	}

	// ─── LIFECYCLE EVENTS ──────────────────────────────────────────────

	private function generate_lifecycle_events() {
		global $wpdb;

		$detector = N8nPress_Plugin_Detector::get_instance();
		$crm      = $detector->detect_crm();

		// Don't generate lifecycle events if CRM plugin handles automations
		if ( 'none' !== $crm['plugin'] ) {
			return;
		}

		$events = get_option( 'n8npress_crm_lifecycle_events', array() );

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

		update_option( 'n8npress_crm_lifecycle_events', $events );
	}

	// ─── HELPERS ───────────────────────────────────────────────────────

	private function count_customers() {
		$result = count_users();
		// WooCommerce customer role
		return $result['avail_roles']['customer'] ?? 0;
	}

	private function get_segment_counts() {
		$counts = get_option( 'n8npress_crm_segment_counts', array() );
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
		$events = get_option( 'n8npress_crm_lifecycle_events', array() );
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
			'meta_key'   => '_n8npress_crm_segment',
			'meta_value' => $segment,
			'number'     => $limit,
			'role'       => 'customer',
		) );

		$customers = array();
		foreach ( $users as $user ) {
			$stats = get_user_meta( $user->ID, '_n8npress_crm_stats', true );
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
		$stats   = get_user_meta( $user_id, '_n8npress_crm_stats', true );
		$segment = get_user_meta( $user_id, '_n8npress_crm_segment', true );

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

		// CRM plugin data
		$crm_data = $this->get_crm_plugin_data( $user_id );

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
			'crm_plugin'    => $crm_data,
			'location'      => array(
				'city'    => $customer->get_billing_city(),
				'state'   => $customer->get_billing_state(),
				'country' => $customer->get_billing_country(),
			),
		);
	}

	private function get_crm_plugin_data( $user_id ) {
		$detector = N8nPress_Plugin_Detector::get_instance();
		$crm      = $detector->detect_crm();

		if ( 'fluentcrm' === $crm['plugin'] && function_exists( 'FluentCrmApi' ) ) {
			$contact = FluentCrmApi( 'contacts' )->getContactByUserRef( $user_id );
			if ( $contact ) {
				return array(
					'source' => 'fluentcrm',
					'tags'   => $contact->tags ? $contact->tags->pluck( 'title' )->toArray() : array(),
					'lists'  => $contact->lists ? $contact->lists->pluck( 'title' )->toArray() : array(),
					'status' => $contact->status,
				);
			}
		}

		return null;
	}

	private function get_fluentcrm_info() {
		if ( ! function_exists( 'FluentCrmApi' ) ) {
			return array();
		}

		$tags  = FluentCrmApi( 'tags' )->all();
		$lists = FluentCrmApi( 'lists' )->all();

		return array(
			'total_contacts' => FluentCrmApi( 'contacts' )->getInstance()->count(),
			'tags'           => $tags ? $tags->pluck( 'title' )->toArray() : array(),
			'lists'          => $lists ? $lists->pluck( 'title' )->toArray() : array(),
		);
	}

	private function get_mailchimp_info() {
		$store_id = get_option( 'mailchimp-woocommerce-store_id', '' );
		return array(
			'store_connected' => ! empty( $store_id ),
			'store_id'        => $store_id,
		);
	}

	/**
	 * Get pending lifecycle events for n8n processing.
	 * If a CRM plugin exists, returns empty (CRM handles automations).
	 */
	private function get_pending_lifecycle_events() {
		$detector = N8nPress_Plugin_Detector::get_instance();
		$crm      = $detector->detect_crm();

		if ( 'none' !== $crm['plugin'] ) {
			return array(); // CRM plugin handles lifecycle automation
		}

		$events  = get_option( 'n8npress_crm_lifecycle_events', array() );
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

	public function check_permission() {
		return current_user_can( 'manage_woocommerce' );
	}

	public function check_n8n_token( $request ) {
		$auth = $request->get_header( 'Authorization' );
		if ( empty( $auth ) ) {
			return false;
		}

		$token  = str_replace( 'Bearer ', '', $auth );
		$stored = get_option( 'n8npress_seo_api_token', '' );
		return ! empty( $stored ) && hash_equals( $stored, $token );
	}
}
