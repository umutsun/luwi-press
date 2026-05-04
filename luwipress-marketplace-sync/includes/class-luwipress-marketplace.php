<?php
/**
 * Marketplace Manager
 *
 * Orchestrates publishing WooCommerce products to external marketplaces
 * (Amazon, eBay, Trendyol, Alibaba) via adapter pattern.
 *
 * @package LuwiPress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Marketplace {

	/** @var self|null */
	private static $instance = null;

	/** @var LuwiPress_Marketplace_Adapter[] Cached adapter instances keyed by slug. */
	private $adapters = array();

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	// ─── REST ENDPOINTS ───────────────────────────────────────────

	/**
	 * Register REST API routes.
	 */
	public function register_endpoints() {
		$ns = 'luwipress/v1';

		// Publish single product
		register_rest_route( $ns, '/marketplace/publish', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_publish' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'product_id'   => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'marketplaces' => array( 'required' => true, 'type' => 'array' ),
			),
		) );

		// Batch publish
		register_rest_route( $ns, '/marketplace/publish-batch', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_publish_batch' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'product_ids'  => array( 'required' => true, 'type' => 'array' ),
				'marketplaces' => array( 'required' => true, 'type' => 'array' ),
			),
		) );

		// Get sync status for a product
		register_rest_route( $ns, '/marketplace/status/(?P<product_id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_status' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		// Overview / stats
		register_rest_route( $ns, '/marketplace/overview', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_overview' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		// Test connection
		register_rest_route( $ns, '/marketplace/test', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_test' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'marketplace' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// Get marketplace categories
		register_rest_route( $ns, '/marketplace/categories', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_categories' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'marketplace' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'query'       => array( 'default' => '', 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
	}

	// ─── HANDLERS ─────────────────────────────────────────────────

	/**
	 * Publish a single product to selected marketplaces.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_publish( $request ) {
		$product_id   = absint( $request->get_param( 'product_id' ) );
		$marketplaces = $request->get_param( 'marketplaces' );

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'invalid_product', __( 'Product not found.', 'luwipress' ), array( 'status' => 404 ) );
		}

		$results = $this->publish_to_marketplaces( $product, (array) $marketplaces );

		return rest_ensure_response( array(
			'product_id' => $product_id,
			'results'    => $results,
		) );
	}

	/**
	 * Batch publish products to selected marketplaces.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_publish_batch( $request ) {
		$product_ids  = array_map( 'absint', array_slice( (array) $request->get_param( 'product_ids' ), 0, 50 ) );
		$marketplaces = (array) $request->get_param( 'marketplaces' );

		$results = array();

		foreach ( $product_ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				$results[ $pid ] = array( 'error' => 'Product not found' );
				continue;
			}
			$results[ $pid ] = $this->publish_to_marketplaces( $product, $marketplaces );
		}

		return rest_ensure_response( array(
			'total'   => count( $product_ids ),
			'results' => $results,
		) );
	}

	/**
	 * Get sync status for a product.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_status( $request ) {
		$product_id = absint( $request->get_param( 'product_id' ) );

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'invalid_product', __( 'Product not found.', 'luwipress' ), array( 'status' => 404 ) );
		}

		global $wpdb;
		$table    = $wpdb->prefix . 'luwipress_marketplace_listings';
		$listings = $wpdb->get_results( $wpdb->prepare(
			"SELECT marketplace, marketplace_product_id, status, last_synced, error_message
			 FROM {$table} WHERE product_id = %d",
			$product_id
		) );

		return rest_ensure_response( array(
			'product_id' => $product_id,
			'listings'   => $listings ?: array(),
		) );
	}

	/**
	 * Get marketplace overview stats.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_overview( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'luwipress_marketplace_listings';

		$stats = $wpdb->get_results(
			"SELECT marketplace, status, COUNT(*) as count
			 FROM {$table} GROUP BY marketplace, status"
		);

		$configured = array();
		foreach ( $this->get_all_adapters() as $adapter ) {
			$configured[ $adapter->get_name() ] = array(
				'label'      => $adapter->get_label(),
				'configured' => $adapter->is_configured(),
			);
		}

		return rest_ensure_response( array(
			'configured_marketplaces' => $configured,
			'listing_stats'           => $stats ?: array(),
		) );
	}

	/**
	 * Test connection to a marketplace.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_test( $request ) {
		$marketplace = sanitize_text_field( $request->get_param( 'marketplace' ) );
		$adapter     = $this->get_adapter( $marketplace );

		if ( ! $adapter ) {
			return new WP_Error( 'invalid_marketplace', __( 'Unknown marketplace.', 'luwipress' ), array( 'status' => 400 ) );
		}

		$result = $adapter->test_connection();

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( array(
				'marketplace' => $marketplace,
				'connected'   => false,
				'error'       => $result->get_error_message(),
			) );
		}

		return rest_ensure_response( array(
			'marketplace' => $marketplace,
			'connected'   => true,
		) );
	}

	/**
	 * Get categories for a marketplace.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_categories( $request ) {
		$marketplace = sanitize_text_field( $request->get_param( 'marketplace' ) );
		$query       = sanitize_text_field( $request->get_param( 'query' ) );
		$adapter     = $this->get_adapter( $marketplace );

		if ( ! $adapter ) {
			return new WP_Error( 'invalid_marketplace', __( 'Unknown marketplace.', 'luwipress' ), array( 'status' => 400 ) );
		}

		$categories = $adapter->get_categories( $query );

		if ( is_wp_error( $categories ) ) {
			return $categories;
		}

		return rest_ensure_response( array(
			'marketplace' => $marketplace,
			'categories'  => $categories,
		) );
	}

	// ─── CORE LOGIC ───────────────────────────────────────────────

	/**
	 * Publish a product to the specified marketplaces.
	 *
	 * @param WC_Product $product      WooCommerce product.
	 * @param array      $marketplaces Array of marketplace slugs.
	 * @return array Per-marketplace results.
	 */
	private function publish_to_marketplaces( $product, array $marketplaces ) {
		$results = array();

		foreach ( $marketplaces as $mp ) {
			$mp      = sanitize_text_field( $mp );
			$adapter = $this->get_adapter( $mp );

			if ( ! $adapter ) {
				$results[ $mp ] = array( 'success' => false, 'error' => 'Unknown marketplace' );
				continue;
			}

			if ( ! $adapter->is_configured() ) {
				$results[ $mp ] = array( 'success' => false, 'error' => 'Not configured' );
				continue;
			}

			// Check if already published (update instead)
			$existing_id = $this->get_listing_id( $product->get_id(), $mp );

			$product_data = $adapter->map_product_data( $product );

			if ( ! empty( $existing_id ) ) {
				// Update
				$result = $adapter->update_product( $existing_id, $product_data );
				if ( is_wp_error( $result ) ) {
					$this->save_listing( $product->get_id(), $mp, $existing_id, 'failed', $result->get_error_message() );
					$results[ $mp ] = array( 'success' => false, 'error' => $result->get_error_message() );
				} else {
					$this->save_listing( $product->get_id(), $mp, $existing_id, 'published' );
					$results[ $mp ] = array( 'success' => true, 'action' => 'updated', 'marketplace_id' => $existing_id );
				}
			} else {
				// Publish new
				$result = $adapter->publish_product( $product_data );
				if ( is_wp_error( $result ) ) {
					$this->save_listing( $product->get_id(), $mp, '', 'failed', $result->get_error_message() );
					$results[ $mp ] = array( 'success' => false, 'error' => $result->get_error_message() );
				} else {
					$mp_id = $result['marketplace_product_id'] ?? '';
					$this->save_listing( $product->get_id(), $mp, $mp_id, 'published' );
					$results[ $mp ] = array( 'success' => true, 'action' => 'created', 'marketplace_id' => $mp_id );
				}
			}

			LuwiPress_Logger::log(
				sprintf( 'Marketplace %s: product %d %s', $mp, $product->get_id(), $results[ $mp ]['success'] ? 'OK' : 'FAILED' ),
				$results[ $mp ]['success'] ? 'info' : 'error',
				array( 'product_id' => $product->get_id(), 'marketplace' => $mp )
			);
		}

		return $results;
	}

	// ─── ADAPTER MANAGEMENT ───────────────────────────────────────

	/**
	 * Get all registered adapters.
	 *
	 * @return LuwiPress_Marketplace_Adapter[]
	 */
	private function get_all_adapters() {
		if ( empty( $this->adapters ) ) {
			$this->adapters = array(
				'amazon'      => new LuwiPress_Marketplace_Amazon(),
				'ebay'        => new LuwiPress_Marketplace_Ebay(),
				'trendyol'    => new LuwiPress_Marketplace_Trendyol(),
				'alibaba'     => new LuwiPress_Marketplace_Alibaba(),
				'hepsiburada' => new LuwiPress_Marketplace_Hepsiburada(),
				'n11'         => new LuwiPress_Marketplace_N11(),
				'etsy'        => new LuwiPress_Marketplace_Etsy(),
				'walmart'     => new LuwiPress_Marketplace_Walmart(),
			);
		}
		return $this->adapters;
	}

	/**
	 * Get a specific adapter by slug.
	 *
	 * @param string $name Marketplace slug.
	 * @return LuwiPress_Marketplace_Adapter|null
	 */
	private function get_adapter( $name ) {
		$adapters = $this->get_all_adapters();
		return $adapters[ $name ] ?? null;
	}

	// ─── DATABASE ─────────────────────────────────────────────────

	/**
	 * Create the marketplace listings table.
	 */
	public static function create_table() {
		global $wpdb;
		$table   = $wpdb->prefix . 'luwipress_marketplace_listings';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			product_id bigint(20) NOT NULL,
			marketplace varchar(20) NOT NULL,
			marketplace_product_id varchar(255) DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			error_message text DEFAULT NULL,
			last_synced datetime DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY product_marketplace (product_id, marketplace),
			KEY status_idx (status),
			KEY marketplace_idx (marketplace)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Save or update a listing record.
	 *
	 * @param int    $product_id             WC product ID.
	 * @param string $marketplace            Marketplace slug.
	 * @param string $marketplace_product_id Listing ID on the marketplace.
	 * @param string $status                 Status (pending, published, failed, deleted).
	 * @param string $error_message          Error message if failed.
	 */
	private function save_listing( $product_id, $marketplace, $marketplace_product_id, $status, $error_message = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'luwipress_marketplace_listings';

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE product_id = %d AND marketplace = %s",
			$product_id,
			$marketplace
		) );

		$data = array(
			'product_id'             => $product_id,
			'marketplace'            => $marketplace,
			'marketplace_product_id' => $marketplace_product_id,
			'status'                 => $status,
			'error_message'          => $error_message ?: null,
			'last_synced'            => current_time( 'mysql' ),
		);

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => $existing ) );
		} else {
			$wpdb->insert( $table, $data );
		}

		// Also save to post meta for quick access
		update_post_meta( $product_id, '_luwipress_marketplace_' . $marketplace . '_id', $marketplace_product_id );
		update_post_meta( $product_id, '_luwipress_marketplace_' . $marketplace . '_status', $status );
	}

	/**
	 * Get an existing marketplace listing ID for a product.
	 *
	 * @param int    $product_id  WC product ID.
	 * @param string $marketplace Marketplace slug.
	 * @return string Empty string if not listed.
	 */
	private function get_listing_id( $product_id, $marketplace ) {
		return get_post_meta( $product_id, '_luwipress_marketplace_' . $marketplace . '_id', true ) ?: '';
	}

	// ─── PERMISSION ───────────────────────────────────────────────

	/**
	 * Permission callback.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function check_permission( $request ) {
		return LuwiPress_Permission::check_token_or_admin( $request );
	}
}
