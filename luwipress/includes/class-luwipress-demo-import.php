<?php
/**
 * Demo Content Import Engine
 *
 * Safely imports predesigned Elementor pages, creates menus,
 * sets homepage, and registers pages with WPML.
 * Never overwrites existing content — skips pages that already exist.
 *
 * @package LuwiPress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Demo_Import {

	/** @var self|null */
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
		add_action( 'wp_ajax_luwipress_demo_import', array( $this, 'ajax_import' ) );
		add_action( 'wp_ajax_luwipress_demo_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_luwipress_demo_cleanup', array( $this, 'ajax_cleanup' ) );
	}

	/**
	 * Register REST endpoints.
	 */
	public function register_endpoints() {
		register_rest_route( 'luwipress/v1', '/demo/import', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_import' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'pages' => array( 'type' => 'array', 'default' => array() ),
				'menus' => array( 'type' => 'boolean', 'default' => true ),
				'homepage' => array( 'type' => 'boolean', 'default' => true ),
			),
		) );

		register_rest_route( 'luwipress/v1', '/demo/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_status' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'luwipress/v1', '/demo/repair', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_repair' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'post_id' => array( 'type' => 'integer', 'required' => true ),
				'slug'    => array( 'type' => 'string', 'required' => true ),
				'theme'   => array( 'type' => 'string', 'default' => '' ),
			),
		) );

		register_rest_route( 'luwipress/v1', '/theme/setup', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_theme_setup' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'slug' => array( 'type' => 'string', 'default' => '' ),
			),
		) );

		register_rest_route( 'luwipress/v1', '/theme/update', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_theme_update' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'slug' => array( 'type' => 'string', 'required' => true ),
			),
		) );
	}

	// ─── AJAX HANDLERS ────────────────────────────────────────────

	/**
	 * AJAX: Run demo import.
	 */
	public function ajax_import() {
		check_ajax_referer( 'luwipress_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$pages    = isset( $_POST['pages'] ) ? array_map( 'sanitize_text_field', (array) $_POST['pages'] ) : array();
		$menus    = ! empty( $_POST['menus'] );
		$homepage = ! empty( $_POST['homepage'] );

		$result = $this->run_import( $pages, $menus, $homepage );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Get current status.
	 */
	public function ajax_status() {
		check_ajax_referer( 'luwipress_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		wp_send_json_success( $this->get_status() );
	}

	/**
	 * AJAX: Cleanup — move starter pages to trash.
	 */
	public function ajax_cleanup() {
		check_ajax_referer( 'luwipress_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$demo = $this->load_demo_data();
		if ( is_wp_error( $demo ) ) {
			wp_send_json_error( $demo->get_error_message() );
		}

		$this->wpml_switch_all();

		$trashed = 0;
		foreach ( $demo['pages'] as $page_def ) {
			$page = get_page_by_path( $page_def['slug'] );
			if ( $page ) {
				wp_trash_post( $page->ID );
				$trashed++;
			}
		}

		$this->wpml_restore();

		// Reset homepage if it was a starter page.
		$hp_id = get_option( 'page_on_front' );
		if ( $hp_id && get_post_status( $hp_id ) === 'trash' ) {
			update_option( 'show_on_front', 'posts' );
			update_option( 'page_on_front', 0 );
		}

		$this->purge_cache();

		LuwiPress_Logger::log( 'Demo cleanup: trashed ' . $trashed . ' pages', 'info' );

		wp_send_json_success( array( 'trashed' => $trashed ) );
	}

	// ─── REST HANDLERS ────────────────────────────────────────────

	/**
	 * REST: Run demo import.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_import( $request ) {
		$pages    = $request->get_param( 'pages' ) ?: array();
		$menus    = (bool) $request->get_param( 'menus' );
		$homepage = (bool) $request->get_param( 'homepage' );

		$result = $this->run_import( $pages, $menus, $homepage );
		return rest_ensure_response( $result );
	}

	/**
	 * REST: Get import status.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_status( $request ) {
		return rest_ensure_response( $this->get_status() );
	}

	/**
	 * REST: Repair a specific page by injecting Elementor data from demo JSON.
	 *
	 * @param WP_REST_Request $request Must contain post_id and slug.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_repair( $request ) {
		$post_id    = absint( $request->get_param( 'post_id' ) );
		$slug       = sanitize_text_field( $request->get_param( 'slug' ) );
		$theme_slug = sanitize_text_field( $request->get_param( 'theme' ) );

		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
		}

		// Auto-resolve demo content path from theme slug or active theme.
		if ( ! $this->demo_content_path && class_exists( 'LuwiPress_Theme_Manager' ) ) {
			$lookup = $theme_slug ?: get_stylesheet();
			$def    = LuwiPress_Theme_Manager::get_instance()->get_theme_def( $lookup );
			if ( ! empty( $def['demo_content'] ) ) {
				$this->set_demo_content_path( $def['demo_content'] );
			}
		}

		$demo = $this->load_demo_data();
		if ( is_wp_error( $demo ) ) {
			return $demo;
		}

		$page_def = null;
		foreach ( $demo['pages'] as $p ) {
			if ( $p['slug'] === $slug ) {
				$page_def = $p;
				break;
			}
		}

		if ( ! $page_def || empty( $page_def['elementor_data'] ) ) {
			return new WP_Error( 'no_demo', 'No demo data found for slug: ' . $slug, array( 'status' => 404 ) );
		}

		$this->repair_page( $post_id, $page_def );

		return rest_ensure_response( array(
			'success' => true,
			'post_id' => $post_id,
			'slug'    => $slug,
			'repaired' => true,
		) );
	}

	// ─── CORE IMPORT LOGIC ────────────────────────────────────────

	/**
	 * Run the demo import.
	 *
	 * @param array $selected_pages Page slugs to import (empty = all).
	 * @param bool  $import_menus   Whether to create menus.
	 * @param bool  $set_homepage   Whether to set homepage.
	 * @return array Import results.
	 */
	public function run_import( $selected_pages = array(), $import_menus = true, $set_homepage = true ) {
		$demo = $this->load_demo_data();
		if ( is_wp_error( $demo ) ) {
			return array( 'success' => false, 'error' => $demo->get_error_message() );
		}

		$results = array(
			'pages_created'  => array(),
			'pages_skipped'  => array(),
			'menus_created'  => array(),
			'homepage_set'   => false,
			'wpml_registered' => 0,
		);

		// Bypass WPML language filter during import
		$this->wpml_switch_all();

		// Import pages
		foreach ( $demo['pages'] as $page_def ) {
			$slug = $page_def['slug'];

			// Skip if not in selection (when selection is provided)
			if ( ! empty( $selected_pages ) && ! in_array( $slug, $selected_pages, true ) ) {
				continue;
			}

			// Check if page already exists (bypass WPML language filter).
			$existing = get_page_by_path( $slug );
			if ( ! $existing ) {
				// Fallback: direct DB query to catch WPML-hidden pages.
				global $wpdb;
				$existing = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'page' AND post_status IN ('publish','draft') LIMIT 1",
					$slug
				) );
			}
			if ( $existing ) {
				$existing_id = (int) $existing->ID;

				// If the existing page is missing Elementor data but the demo
				// definition includes it, inject the template instead of skipping.
				$has_elementor = (bool) get_post_meta( $existing_id, '_elementor_data', true );
				if ( ! $has_elementor && ! empty( $page_def['elementor_data'] ) ) {
					$this->repair_page( $existing_id, $page_def );
					$results['pages_created'][] = array(
						'slug'   => $slug,
						'title'  => $page_def['title'],
						'id'     => $existing_id,
						'reason' => 'repaired',
					);
				} else {
					$results['pages_skipped'][] = array(
						'slug'  => $slug,
						'title' => $page_def['title'],
						'id'    => $existing_id,
						'reason' => 'already_exists',
					);
				}
				continue;
			}

			// Create the page
			$page_id = $this->create_page( $page_def );
			if ( is_wp_error( $page_id ) ) {
				$results['pages_skipped'][] = array(
					'slug'   => $slug,
					'title'  => $page_def['title'],
					'reason' => $page_id->get_error_message(),
				);
				continue;
			}

			$results['pages_created'][] = array(
				'slug'  => $slug,
				'title' => $page_def['title'],
				'id'    => $page_id,
			);

			// Register with WPML
			if ( $this->register_wpml( $page_id ) ) {
				$results['wpml_registered']++;
			}
		}

		// Set homepage
		if ( $set_homepage && ! empty( $demo['settings']['page_on_front'] ) ) {
			$hp_slug = $demo['settings']['page_on_front'];
			$hp_page = get_page_by_path( $hp_slug );
			if ( $hp_page ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $hp_page->ID );
				$results['homepage_set'] = true;
			}
		}

		// Create menus
		if ( $import_menus && ! empty( $demo['menus'] ) ) {
			foreach ( $demo['menus'] as $location => $menu_def ) {
				$menu_result = $this->create_menu( $location, $menu_def );
				if ( ! is_wp_error( $menu_result ) ) {
					$results['menus_created'][] = $menu_def['name'];
				}
			}
		}

		// Restore WPML language
		$this->wpml_restore();

		// Flush rewrite rules
		flush_rewrite_rules();

		// Purge cache
		$this->purge_cache();

		LuwiPress_Logger::log( 'Demo import completed', 'info', array(
			'created' => count( $results['pages_created'] ),
			'skipped' => count( $results['pages_skipped'] ),
		) );

		$results['success'] = true;
		return $results;
	}

	/**
	 * Get current import status — which pages exist, which are missing.
	 *
	 * @return array
	 */
	public function get_status() {
		$demo = $this->load_demo_data();
		if ( is_wp_error( $demo ) ) {
			return array( 'available' => false, 'error' => $demo->get_error_message() );
		}

		$this->wpml_switch_all();

		$pages = array();
		foreach ( $demo['pages'] as $page_def ) {
			$existing = get_page_by_path( $page_def['slug'] );
			$pages[] = array(
				'slug'    => $page_def['slug'],
				'title'   => $page_def['title'],
				'exists'  => ! empty( $existing ),
				'post_id' => $existing ? $existing->ID : null,
			);
		}

		$this->wpml_restore();

		// Theme check — is active theme one of our registered themes?
		$active_theme = get_template();
		$is_luwi      = false;
		if ( class_exists( 'LuwiPress_Theme_Manager' ) ) {
			$catalog  = LuwiPress_Theme_Manager::get_instance()->get_catalog();
			$is_luwi  = isset( $catalog[ $active_theme ] ) && ! ( $catalog[ $active_theme ]['coming_soon'] ?? true );
		}

		// Homepage check
		$front = get_option( 'show_on_front' );
		$hp_id = get_option( 'page_on_front' );

		return array(
			'available'    => true,
			'theme'        => $active_theme,
			'is_luwi'      => $is_luwi,
			'elementor'    => defined( 'ELEMENTOR_VERSION' ),
			'pages'        => $pages,
			'has_homepage' => ( 'page' === $front && $hp_id > 0 ),
			'homepage_id'  => absint( $hp_id ),
			'total_pages'  => count( $pages ),
			'existing'     => count( array_filter( $pages, function( $p ) { return $p['exists']; } ) ),
			'missing'      => count( array_filter( $pages, function( $p ) { return ! $p['exists']; } ) ),
		);
	}

	// ─── PAGE CREATION ────────────────────────────────────────────

	/**
	 * Get store placeholder values from WooCommerce / WordPress settings.
	 *
	 * @return array Key => value map.
	 */
	private function get_store_placeholders() {
		$name    = get_bloginfo( 'name' ) ?: 'Our Store';
		$email   = get_option( 'woocommerce_email_from_address', get_option( 'admin_email', 'info@example.com' ) );
		$country = ( class_exists( 'WooCommerce' ) && function_exists( 'WC' ) ) ? WC()->countries->get_base_country() : '';
		$state   = ( class_exists( 'WooCommerce' ) && function_exists( 'WC' ) ) ? WC()->countries->get_base_state() : '';
		$city    = class_exists( 'WooCommerce' ) ? get_option( 'woocommerce_store_city', '' ) : '';
		$addr1   = class_exists( 'WooCommerce' ) ? get_option( 'woocommerce_store_address', '' ) : '';
		$phone   = get_option( 'woocommerce_store_phone', '' );

		// Build readable address.
		$parts = array_filter( array( $addr1, $city, $state, $country ) );
		$address = ! empty( $parts ) ? implode( ', ', $parts ) : 'Your Store Address';

		if ( empty( $phone ) ) {
			$phone = '+1 000 000 0000';
		}

		return array(
			'{{store_name}}'    => $name,
			'{{store_email}}'   => $email,
			'{{store_phone}}'   => $phone,
			'{{store_address}}' => $address,
			'{{site_url}}'      => home_url( '/' ),
		);
	}

	/**
	 * Replace {{placeholders}} in a nested array recursively.
	 *
	 * @param mixed $data   Data to process.
	 * @param array $tokens Placeholder => value map.
	 * @return mixed
	 */
	private function replace_placeholders( $data, $tokens ) {
		if ( is_string( $data ) ) {
			return str_replace( array_keys( $tokens ), array_values( $tokens ), $data );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->replace_placeholders( $value, $tokens );
			}
		}
		return $data;
	}

	/**
	 * Create a single page with Elementor data.
	 *
	 * @param array $page_def Page definition from demo JSON.
	 * @return int|WP_Error Post ID on success.
	 */
	private function create_page( $page_def ) {
		// Replace store placeholders in page definition.
		$tokens   = $this->get_store_placeholders();
		$page_def = $this->replace_placeholders( $page_def, $tokens );

		$post_data = array(
			'post_title'   => sanitize_text_field( $page_def['title'] ),
			'post_name'    => sanitize_title( $page_def['slug'] ),
			'post_content' => '',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => get_current_user_id() ?: 1,
		);

		$page_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		// Set page template
		if ( ! empty( $page_def['template'] ) ) {
			update_post_meta( $page_id, '_wp_page_template', $page_def['template'] );
		}

		// Set Elementor data
		if ( ! empty( $page_def['elementor_data'] ) ) {
			$this->set_elementor_data( $page_id, $page_def['elementor_data'] );
		}

		return $page_id;
	}

	/**
	 * Repair an existing page that was created without Elementor data.
	 *
	 * Injects _elementor_data, sets the correct page template, and
	 * replaces store placeholders — without re-creating the post.
	 *
	 * @param int   $page_id  Existing post ID.
	 * @param array $page_def Page definition from demo JSON.
	 */
	private function repair_page( $page_id, $page_def ) {
		// Replace store placeholders.
		$tokens   = $this->get_store_placeholders();
		$page_def = $this->replace_placeholders( $page_def, $tokens );

		// Set page template.
		if ( ! empty( $page_def['template'] ) ) {
			update_post_meta( $page_id, '_wp_page_template', $page_def['template'] );
		}

		// Inject Elementor data.
		if ( ! empty( $page_def['elementor_data'] ) ) {
			$this->set_elementor_data( $page_id, $page_def['elementor_data'] );
		}

		LuwiPress_Logger::log( 'Repaired page #' . $page_id . ' with Elementor data', 'info' );
	}

	/**
	 * Set Elementor page data with proper meta keys.
	 *
	 * @param int   $page_id       Post ID.
	 * @param array $elementor_data Elementor elements array.
	 */
	private function set_elementor_data( $page_id, $elementor_data ) {
		// Add unique IDs to all elements
		$elementor_data = $this->add_element_ids( $elementor_data );

		$json = wp_json_encode( $elementor_data );

		// Elementor requires wp_slash for JSON stored in postmeta
		update_post_meta( $page_id, '_elementor_data', wp_slash( $json ) );
		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $page_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );
		update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );

		// Generate Elementor CSS
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			$css = new \Elementor\Core\Files\CSS\Post( $page_id );
			$css->update();
		}
	}

	/**
	 * Recursively add unique IDs to Elementor elements.
	 *
	 * @param array $elements Elements array.
	 * @return array Elements with IDs.
	 */
	private function add_element_ids( $elements ) {
		foreach ( $elements as &$el ) {
			if ( empty( $el['id'] ) ) {
				$el['id'] = $this->generate_element_id();
			}
			if ( ! empty( $el['elements'] ) ) {
				$el['elements'] = $this->add_element_ids( $el['elements'] );
			}
		}
		return $elements;
	}

	/**
	 * Generate a random 7-char hex ID (Elementor format).
	 *
	 * @return string
	 */
	private function generate_element_id() {
		return substr( md5( wp_generate_uuid4() ), 0, 7 );
	}

	// ─── MENU CREATION ────────────────────────────────────────────

	/**
	 * Create a navigation menu.
	 *
	 * @param string $location Menu location slug.
	 * @param array  $menu_def Menu definition.
	 * @return int|WP_Error Menu ID.
	 */
	private function create_menu( $location, $menu_def ) {
		$menu_name = sanitize_text_field( $menu_def['name'] );

		// Check if menu already exists
		$existing = wp_get_nav_menu_object( $menu_name );
		if ( $existing ) {
			return $existing->term_id;
		}

		$menu_id = wp_create_nav_menu( $menu_name );
		if ( is_wp_error( $menu_id ) ) {
			return $menu_id;
		}

		// Add menu items
		$order = 0;
		foreach ( $menu_def['items'] as $item ) {
			$order++;
			$args = array(
				'menu-item-title'  => sanitize_text_field( $item['title'] ),
				'menu-item-status' => 'publish',
				'menu-item-position' => $order,
			);

			if ( ! empty( $item['page_slug'] ) ) {
				$page = get_page_by_path( $item['page_slug'] );
				if ( $page ) {
					$args['menu-item-type']      = 'post_type';
					$args['menu-item-object']    = 'page';
					$args['menu-item-object-id'] = $page->ID;
				}
			} elseif ( ! empty( $item['url'] ) ) {
				$args['menu-item-type'] = 'custom';
				$args['menu-item-url']  = esc_url( home_url( $item['url'] ) );
			}

			wp_update_nav_menu_item( $menu_id, 0, $args );
		}

		// Assign to theme location
		$locations = get_theme_mod( 'nav_menu_locations', array() );
		$locations[ $location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );

		return $menu_id;
	}

	// ─── WPML INTEGRATION ─────────────────────────────────────────

	/**
	 * Register a page with WPML as default language.
	 *
	 * @param int $page_id Post ID.
	 * @return bool
	 */
	private function register_wpml( $page_id ) {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return false;
		}

		global $sitepress;
		if ( ! $sitepress ) {
			return false;
		}

		$default_lang = $sitepress->get_default_language();
		$post_type    = get_post_type( $page_id );
		$element_type = 'post_' . $post_type;

		// Use WPML API to register the page in the default language.
		$trid = $sitepress->get_element_trid( $page_id, $element_type );
		if ( ! $trid ) {
			$sitepress->set_element_language_details(
				$page_id,
				$element_type,
				null,
				$default_lang
			);
		}

		return true;
	}

	/**
	 * Switch WPML to show all languages.
	 */
	private function wpml_switch_all() {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			global $sitepress;
			if ( $sitepress ) {
				$sitepress->switch_lang( 'all' );
			}
		}
	}

	/**
	 * Restore WPML to default language.
	 */
	private function wpml_restore() {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			global $sitepress;
			if ( $sitepress ) {
				$sitepress->switch_lang( $sitepress->get_default_language() );
			}
		}
	}

	// ─── HELPERS ──────────────────────────────────────────────────

	/**
	 * Load demo data from JSON file.
	 *
	 * @return array|WP_Error
	 */
	/**
	 * @var string|null Override demo content path for multi-theme support.
	 */
	private $demo_content_path = null;

	/**
	 * Set custom demo content path (used by Theme Manager for per-theme content).
	 *
	 * @param string $relative_path Path relative to plugin includes/ dir.
	 */
	public function set_demo_content_path( $relative_path ) {
		$this->demo_content_path = $relative_path;
	}

	private function load_demo_data() {
		$path = $this->demo_content_path ?: 'demo-content/pages.json';
		$file = LUWIPRESS_PLUGIN_DIR . 'includes/' . $path;

		if ( ! file_exists( $file ) ) {
			return new WP_Error( 'no_demo_data', __( 'Demo content file not found.', 'luwipress' ) );
		}

		$json = file_get_contents( $file );
		$data = json_decode( $json, true );

		if ( empty( $data ) || ! is_array( $data ) ) {
			return new WP_Error( 'invalid_demo_data', __( 'Invalid demo content file.', 'luwipress' ) );
		}

		return $data;
	}

	/**
	 * Purge all known cache plugins.
	 */
	private function purge_cache() {
		$detector = LuwiPress_Plugin_Detector::get_instance();
		// Plugin detector handles LiteSpeed, WP Rocket, W3TC
		if ( method_exists( $detector, 'purge_all_cache' ) ) {
			$detector->purge_all_cache();
		}

		// Manual fallbacks
		if ( function_exists( 'litespeed_purge_all' ) ) {
			litespeed_purge_all();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	/**
	 * Permission callback.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function check_permission( $request ) {
		return LuwiPress_Permission::check_token_or_admin( $request );
	}

	/**
	 * REST: Run full site setup for active theme.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_theme_setup( $request ) {
		$slug = sanitize_text_field( $request->get_param( 'slug' ) );
		if ( empty( $slug ) ) {
			$slug = get_template();
		}

		$tm     = LuwiPress_Theme_Manager::get_instance();
		$result = $tm->setup_site( $slug );

		return rest_ensure_response( $result );
	}

	/**
	 * REST: Update theme from GitHub.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_theme_update( $request ) {
		$slug = sanitize_text_field( $request->get_param( 'slug' ) );

		$tm     = LuwiPress_Theme_Manager::get_instance();
		$result = $tm->update_theme( $slug );

		if ( empty( $result['success'] ) ) {
			return new WP_Error( 'update_failed', $result['error'] ?? 'Update failed.', array( 'status' => 400 ) );
		}

		return rest_ensure_response( $result );
	}
}
