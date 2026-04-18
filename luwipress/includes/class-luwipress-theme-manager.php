<?php
/**
 * Theme Manager — install, activate, setup, and manage Luwi themes.
 *
 * Downloads theme ZIP from remote URL, installs via Theme_Upgrader,
 * activates, and runs full site setup (pages, homepage, menus, WC pages).
 *
 * @package LuwiPress
 * @since   2.0.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Theme_Manager {

	/** @var self|null */
	private static $instance = null;

	/** @var array|null Cached registry data. */
	private $registry = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_luwipress_theme_install', array( $this, 'ajax_install' ) );
		add_action( 'wp_ajax_luwipress_theme_activate', array( $this, 'ajax_activate' ) );
		add_action( 'wp_ajax_luwipress_theme_setup', array( $this, 'ajax_setup_site' ) );
		add_action( 'wp_ajax_luwipress_theme_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_luwipress_theme_cleanup', array( $this, 'ajax_cleanup' ) );
		add_action( 'wp_ajax_luwipress_theme_rollback', array( $this, 'ajax_rollback' ) );
		add_action( 'wp_ajax_luwipress_theme_check_updates', array( $this, 'ajax_check_updates' ) );
		add_action( 'wp_ajax_luwipress_theme_update', array( $this, 'ajax_update_theme' ) );
	}

	// ─── REGISTRY ─────────────────────────────────────────────────

	/**
	 * Load theme registry from JSON.
	 *
	 * @return array
	 */
	private function get_registry() {
		if ( null !== $this->registry ) {
			return $this->registry;
		}

		$file = LUWIPRESS_PLUGIN_DIR . 'includes/theme-registry.json';
		if ( ! file_exists( $file ) ) {
			$this->registry = array( 'themes' => array() );
			return $this->registry;
		}

		$data = json_decode( file_get_contents( $file ), true );
		$this->registry = is_array( $data ) ? $data : array( 'themes' => array() );
		return $this->registry;
	}

	/**
	 * Get a single theme definition from registry.
	 *
	 * @param string $slug Theme slug.
	 * @return array|null
	 */
	public function get_theme_def( $slug ) {
		$reg = $this->get_registry();
		return $reg['themes'][ $slug ] ?? null;
	}

	/**
	 * Get full theme catalog with install/active status for each.
	 *
	 * @return array
	 */
	public function get_catalog() {
		$reg     = $this->get_registry();
		$themes  = $reg['themes'] ?? array();
		$active  = get_template();
		$updates = $this->check_github_updates();
		$result  = array();

		foreach ( $themes as $slug => $def ) {
			$wp_theme  = wp_get_theme( $slug );
			$installed = $wp_theme->exists();
			$upd       = $updates[ $slug ] ?? array();

			$result[ $slug ] = array(
				'name'            => $def['name'] ?? $slug,
				'slug'            => $slug,
				'version'         => $def['version'] ?? '1.0.0',
				'description'     => $def['description'] ?? '',
				'icon'            => $def['icon'] ?? 'dashicons-admin-appearance',
				'badge'           => $def['badge'] ?? '',
				'features'        => $def['features'] ?? array(),
				'requires'        => $def['requires'] ?? array(),
				'coming_soon'     => ! empty( $def['coming_soon'] ),
				'installed'       => $installed,
				'active'          => ( $active === $slug ),
				'installed_ver'   => $installed ? $wp_theme->get( 'Version' ) : null,
				'has_zip'         => ! empty( $def['zip_url'] ),
				'preview_url'     => $def['preview_url'] ?? '',
				'palette'         => $def['palette'] ?? array(),
				'fonts'           => $def['fonts'] ?? array(),
				'update_available' => ! empty( $upd['has_update'] ),
				'latest_version'  => $upd['latest_version'] ?? null,
				'update_changelog' => $upd['changelog'] ?? '',
			);
		}

		return $result;
	}

	// ─── STATUS ───────────────────────────────────────────────────

	/**
	 * Full status report for a theme.
	 *
	 * @param string $slug Theme slug.
	 * @return array
	 */
	public function get_status( $slug = 'luwi-gold' ) {
		$def = $this->get_theme_def( $slug );
		if ( ! $def ) {
			return array( 'error' => 'Theme not in registry' );
		}

		$wp_theme     = wp_get_theme( $slug );
		$installed    = $wp_theme->exists();
		$active       = ( get_template() === $slug );
		$has_elementor = defined( 'ELEMENTOR_VERSION' );
		$has_wc        = class_exists( 'WooCommerce' );

		// Demo pages status.
		$demo_importer = LuwiPress_Demo_Import::get_instance();
		$demo_status   = $demo_importer->get_status();

		// WooCommerce essential pages.
		$wc_pages = $this->get_wc_pages_status();

		// Homepage.
		$front     = get_option( 'show_on_front' );
		$hp_id     = absint( get_option( 'page_on_front' ) );
		$blog_id   = absint( get_option( 'page_for_posts' ) );
		$has_hp    = ( 'page' === $front && $hp_id > 0 && 'publish' === get_post_status( $hp_id ) );

		// Menu.
		$locations = get_nav_menu_locations();
		$has_menu  = ! empty( $locations['primary'] );

		// Overall readiness.
		$setup_done = $has_hp && $has_menu && ( $demo_status['missing'] ?? 0 ) === 0;

		// Product count.
		$has_products = false;
		if ( class_exists( 'WooCommerce' ) ) {
			$pc = wp_count_posts( 'product' );
			$has_products = ( ( $pc->publish ?? 0 ) > 0 );
		}

		return array(
			'theme'          => $def,
			'installed'      => $installed,
			'active'         => $active,
			'installed_ver'  => $installed ? $wp_theme->get( 'Version' ) : null,
			'has_elementor'  => $has_elementor,
			'has_wc'         => $has_wc,
			'has_homepage'   => $has_hp,
			'homepage_id'    => $hp_id,
			'blog_id'        => $blog_id,
			'has_menu'       => $has_menu,
			'wc_pages'       => $wc_pages,
			'demo_pages'     => $demo_status['pages'] ?? array(),
			'demo_existing'  => $demo_status['existing'] ?? 0,
			'demo_missing'   => $demo_status['missing'] ?? 0,
			'setup_done'     => $setup_done,
			'has_products'   => $has_products,
			'has_snapshot'   => ! empty( $this->get_snapshot() ),
			'color_presets'  => $this->get_color_presets(),
			'store_name'     => get_bloginfo( 'name' ),
		);
	}

	/**
	 * Check WooCommerce essential pages.
	 *
	 * @return array
	 */
	private function get_wc_pages_status() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}

		$pages = array(
			'shop'      => array(
				'label'   => __( 'Shop', 'luwipress' ),
				'option'  => 'woocommerce_shop_page_id',
			),
			'cart'      => array(
				'label'   => __( 'Cart', 'luwipress' ),
				'option'  => 'woocommerce_cart_page_id',
			),
			'checkout'  => array(
				'label'   => __( 'Checkout', 'luwipress' ),
				'option'  => 'woocommerce_checkout_page_id',
			),
			'myaccount' => array(
				'label'   => __( 'My Account', 'luwipress' ),
				'option'  => 'woocommerce_myaccount_page_id',
			),
		);

		$result = array();
		foreach ( $pages as $key => $pg ) {
			$page_id = absint( get_option( $pg['option'] ) );
			$ok      = $page_id > 0 && 'publish' === get_post_status( $page_id );
			$result[ $key ] = array(
				'label'   => $pg['label'],
				'page_id' => $page_id,
				'ok'      => $ok,
			);
		}

		return $result;
	}

	// ─── INSTALL ──────────────────────────────────────────────────

	/**
	 * Install a theme from remote ZIP URL.
	 *
	 * @param string $slug     Theme slug.
	 * @param array  $def_override Optional theme definition override (for updates with new zip_url).
	 * @return array Result with success/error.
	 */
	public function install_theme( $slug, $def_override = null ) {
		$def = $def_override ?? $this->get_theme_def( $slug );
		if ( ! $def ) {
			return array( 'success' => false, 'error' => 'Theme not found in registry.' );
		}

		// Already installed — will update (re-install over existing).
		$theme = wp_get_theme( $slug );
		$is_update = $theme->exists();

		// Check dependencies.
		$dep_check = $this->check_dependencies( $def );
		if ( ! empty( $dep_check ) ) {
			return array( 'success' => false, 'error' => 'Missing dependencies: ' . implode( ', ', $dep_check ) );
		}

		$zip_url = $this->resolve_zip_url( $def );
		if ( empty( $zip_url ) ) {
			return array( 'success' => false, 'error' => 'No download URL configured.' );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;

		// Remove existing theme folder to allow clean re-install / update.
		$theme_dir = get_theme_root() . '/' . $slug;
		if ( is_dir( $theme_dir ) ) {
			if ( get_template() === $slug ) {
				switch_theme( WP_DEFAULT_THEME );
			}
			$wp_filesystem->delete( $theme_dir, true );
			wp_clean_themes_cache();
		}

		// Download ZIP if remote URL.
		$local_zip = $zip_url;
		if ( ! file_exists( $zip_url ) ) {
			$tmp = download_url( $zip_url, 120 );
			if ( is_wp_error( $tmp ) ) {
				return array( 'success' => false, 'error' => $tmp->get_error_message() );
			}
			$local_zip = $tmp;
		}

		// Unzip directly to themes directory.
		$result = unzip_file( $local_zip, get_theme_root() );

		// Clean up temp file.
		if ( $local_zip !== $zip_url && file_exists( $local_zip ) ) {
			wp_delete_file( $local_zip );
		}

		if ( is_wp_error( $result ) ) {
			return array( 'success' => false, 'error' => $result->get_error_message() );
		}

		wp_clean_themes_cache();

		// Verify theme was actually extracted.
		$theme_dir = get_theme_root() . '/' . $slug;
		if ( ! is_dir( $theme_dir ) ) {
			return array(
				'success' => false,
				'error'   => 'Unzip succeeded but theme folder not found. Theme root: ' . get_theme_root() . ' | ZIP: ' . $zip_url,
			);
		}

		LuwiPress_Logger::log( 'Theme ' . ( $is_update ? 'updated' : 'installed' ) . ': ' . $slug, 'info' );

		return array( 'success' => true, 'action' => $is_update ? 'updated' : 'installed' );
	}

	/**
	 * Activate an installed theme.
	 * Saves a full snapshot of the current state BEFORE switching.
	 *
	 * @param string $slug Theme slug.
	 * @return array
	 */
	public function activate_theme( $slug ) {
		// Clear theme cache — critical after install in same request chain.
		wp_clean_themes_cache();
		clearstatcache();

		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return array( 'success' => false, 'error' => 'Theme not installed.' );
		}

		if ( get_template() === $slug ) {
			return array( 'success' => true, 'action' => 'already_active' );
		}

		// Save full snapshot BEFORE switching.
		$this->save_snapshot();

		switch_theme( $slug );

		// Verify the switch worked.
		if ( get_template() !== $slug ) {
			return array( 'success' => false, 'error' => 'Theme switch failed. Previous theme restored.' );
		}

		LuwiPress_Logger::log( 'Theme activated: ' . $slug . ' (snapshot saved)', 'info' );

		return array( 'success' => true, 'action' => 'activated', 'snapshot_saved' => true );
	}

	// ─── SNAPSHOT / ROLLBACK ──────────────────────────────────────

	private static $snapshot_key = 'luwipress_setup_snapshot';

	/**
	 * Save a full snapshot of the current site state.
	 * Captures everything needed for a safe rollback:
	 * theme, homepage, menus, widgets, WC pages, customizer.
	 */
	private function save_snapshot() {
		// Don't overwrite an existing snapshot — first snapshot is the "clean" state.
		if ( get_option( self::$snapshot_key ) ) {
			return;
		}

		$snapshot = array(
			'timestamp'      => time(),
			'theme'          => get_template(),
			'stylesheet'     => get_stylesheet(),
			'show_on_front'  => get_option( 'show_on_front' ),
			'page_on_front'  => absint( get_option( 'page_on_front' ) ),
			'page_for_posts' => absint( get_option( 'page_for_posts' ) ),
			'nav_menus'      => get_theme_mod( 'nav_menu_locations', array() ),
			'sidebars'       => wp_get_sidebars_widgets(),
			'theme_mods'     => get_theme_mods(),
		);

		// WooCommerce page IDs.
		if ( class_exists( 'WooCommerce' ) ) {
			$snapshot['wc_pages'] = array(
				'shop'      => absint( get_option( 'woocommerce_shop_page_id' ) ),
				'cart'      => absint( get_option( 'woocommerce_cart_page_id' ) ),
				'checkout'  => absint( get_option( 'woocommerce_checkout_page_id' ) ),
				'myaccount' => absint( get_option( 'woocommerce_myaccount_page_id' ) ),
			);
		}

		update_option( self::$snapshot_key, $snapshot, false );
		LuwiPress_Logger::log( 'Snapshot saved: theme=' . $snapshot['theme'], 'info' );
	}

	/**
	 * Rollback to the saved snapshot.
	 * Restores theme, homepage, menus, widgets, WC pages — full reversal.
	 *
	 * @return array
	 */
	public function rollback() {
		$snapshot = get_option( self::$snapshot_key );
		if ( empty( $snapshot ) ) {
			return array( 'success' => false, 'error' => 'No snapshot found.' );
		}

		$prev_theme = $snapshot['theme'] ?? '';

		// 1. Restore previous theme (if it still exists).
		if ( $prev_theme && get_template() !== $prev_theme ) {
			$old_theme = wp_get_theme( $prev_theme );
			if ( $old_theme->exists() ) {
				switch_theme( $prev_theme );
			} else {
				LuwiPress_Logger::log( 'Rollback: previous theme "' . $prev_theme . '" not found, keeping current', 'warning' );
			}
		}

		// 2. Restore reading settings.
		update_option( 'show_on_front', $snapshot['show_on_front'] );
		update_option( 'page_on_front', $snapshot['page_on_front'] );
		update_option( 'page_for_posts', $snapshot['page_for_posts'] );

		// 3. Restore menu locations (on the restored theme).
		if ( ! empty( $snapshot['nav_menus'] ) ) {
			set_theme_mod( 'nav_menu_locations', $snapshot['nav_menus'] );
		}

		// 4. Restore sidebar widgets.
		if ( ! empty( $snapshot['sidebars'] ) ) {
			wp_set_sidebars_widgets( $snapshot['sidebars'] );
		}

		// 5. Restore previous theme_mods.
		if ( ! empty( $snapshot['theme_mods'] ) && get_template() === $prev_theme ) {
			foreach ( $snapshot['theme_mods'] as $key => $value ) {
				set_theme_mod( $key, $value );
			}
		}

		// 6. Restore WooCommerce pages.
		if ( ! empty( $snapshot['wc_pages'] ) ) {
			$wc_map = array(
				'shop'      => 'woocommerce_shop_page_id',
				'cart'      => 'woocommerce_cart_page_id',
				'checkout'  => 'woocommerce_checkout_page_id',
				'myaccount' => 'woocommerce_myaccount_page_id',
			);
			foreach ( $wc_map as $key => $option ) {
				if ( isset( $snapshot['wc_pages'][ $key ] ) ) {
					update_option( $option, $snapshot['wc_pages'][ $key ] );
				}
			}
		}

		// 7. Trash imported starter pages.
		$cleanup = $this->cleanup();

		$this->purge_cache();
		flush_rewrite_rules();
		delete_option( self::$snapshot_key );
		delete_option( 'luwipress_setup_step' );

		LuwiPress_Logger::log( 'Full rollback completed: restored ' . $prev_theme, 'info' );

		return array(
			'success'  => true,
			'restored' => $prev_theme,
			'trashed'  => $cleanup['trashed'] ?? 0,
		);
	}

	/**
	 * Check if a snapshot exists.
	 *
	 * @return array|false
	 */
	public function get_snapshot() {
		return get_option( self::$snapshot_key, false );
	}

	// ─── CHECKPOINT ───────────────────────────────────────────────

	/**
	 * Save current setup step for resume.
	 *
	 * @param int   $step    Step number.
	 * @param array $results Partial results.
	 */
	private function save_checkpoint( $step, $results ) {
		update_option( 'luwipress_setup_step', array(
			'step'    => $step,
			'results' => $results,
			'time'    => time(),
		), false );
	}

	/**
	 * Clear checkpoint after successful setup.
	 */
	private function clear_checkpoint() {
		delete_option( 'luwipress_setup_step' );
	}

	// ─── COLOR PRESETS ────────────────────────────────────────────

	/**
	 * Get available color presets.
	 *
	 * @return array
	 */
	public function get_color_presets() {
		return array(
			'gold' => array(
				'label'   => __( 'Burnished Gold', 'luwipress' ),
				'primary' => '#735c00',
				'accent'  => '#545e76',
				'bg'      => '#fcf9f8',
				'text'    => '#1b1c1c',
			),
			'forest' => array(
				'label'   => __( 'Forest Green', 'luwipress' ),
				'primary' => '#2d5016',
				'accent'  => '#6b5e4a',
				'bg'      => '#f8faf6',
				'text'    => '#1a1c1a',
			),
			'navy' => array(
				'label'   => __( 'Deep Navy', 'luwipress' ),
				'primary' => '#1e3a5f',
				'accent'  => '#8b7355',
				'bg'      => '#f6f8fc',
				'text'    => '#1a1b1e',
			),
			'rose' => array(
				'label'   => __( 'Dusty Rose', 'luwipress' ),
				'primary' => '#7b5556',
				'accent'  => '#655982',
				'bg'      => '#fdf8f8',
				'text'    => '#1c1a1a',
			),
		);
	}

	/**
	 * Apply a color preset to the Luwi Elementor theme.
	 *
	 * @param string $preset_key Preset key.
	 * @return bool
	 */
	public function apply_color_preset( $preset_key ) {
		$presets = $this->get_color_presets();
		if ( ! isset( $presets[ $preset_key ] ) ) {
			return false;
		}

		$preset = $presets[ $preset_key ];
		set_theme_mod( 'luwi_primary_color', $preset['primary'] );
		set_theme_mod( 'luwi_accent_color', $preset['accent'] );
		set_theme_mod( 'luwi_bg_color', $preset['bg'] );
		set_theme_mod( 'luwi_text_color', $preset['text'] );

		return true;
	}

	// ─── SETUP SITE ───────────────────────────────────────────────

	/**
	 * One-click full site setup.
	 *
	 * @param string $slug         Theme slug.
	 * @param string $color_preset Color preset key (optional).
	 * @return array Detailed results.
	 */
	public function setup_site( $slug = 'luwi-gold', $color_preset = '' ) {
		// Save snapshot before making changes.
		$this->save_snapshot();

		$results = array(
			'pages_created'  => array(),
			'pages_skipped'  => array(),
			'homepage_set'   => false,
			'blog_set'       => false,
			'menus_created'  => array(),
			'wc_pages_ok'    => false,
			'wpml_registered' => 0,
			'color_preset'   => '',
			'has_products'   => false,
			'snapshot_saved' => true,
		);

		// 0. Detect store state.
		if ( class_exists( 'WooCommerce' ) ) {
			$product_count = wp_count_posts( 'product' );
			$results['has_products'] = ( ( $product_count->publish ?? 0 ) > 0 );
		}

		$this->save_checkpoint( 1, $results );

		// 1. Import demo pages — use per-theme demo content path from registry.
		$demo_importer = LuwiPress_Demo_Import::get_instance();
		$def = $this->get_theme_def( $slug );
		if ( ! empty( $def['demo_content'] ) ) {
			$demo_importer->set_demo_content_path( $def['demo_content'] );
		}
		$import_result = $demo_importer->run_import( array(), true, false );

		if ( ! empty( $import_result['pages_created'] ) ) {
			$results['pages_created'] = $import_result['pages_created'];
		}
		$results['pages_skipped']  = $import_result['pages_skipped'] ?? array();
		$results['menus_created']  = $import_result['menus_created'] ?? array();
		$results['wpml_registered'] = $import_result['wpml_registered'] ?? 0;

		$this->save_checkpoint( 2, $results );

		// 2. Set homepage — prefer the page we just created/repaired over
		//    potentially stale duplicates found by get_page_by_path().
		$home_id = 0;
		$all_import_pages = array_merge(
			$import_result['pages_created'] ?? array(),
			$import_result['pages_skipped'] ?? array()
		);
		foreach ( $all_import_pages as $ip ) {
			if ( 'home' === ( $ip['slug'] ?? '' ) && ! empty( $ip['id'] ) ) {
				// Prefer repaired/created pages over merely skipped ones.
				if ( ! empty( $ip['reason'] ) && $ip['reason'] === 'repaired' ) {
					$home_id = (int) $ip['id'];
					break;
				}
				if ( ! $home_id ) {
					$home_id = (int) $ip['id'];
				}
			}
		}
		if ( ! $home_id ) {
			$home_page = get_page_by_path( 'home' );
			if ( $home_page ) {
				$home_id = $home_page->ID;
			}
		}
		if ( $home_id && 'publish' === get_post_status( $home_id ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $home_id );
			$results['homepage_set'] = true;
		}

		// 3. Set blog page.
		$blog_page = get_page_by_path( 'blog' );
		if ( $blog_page && 'publish' === $blog_page->post_status ) {
			update_option( 'page_for_posts', $blog_page->ID );
			$results['blog_set'] = true;
		}

		$this->save_checkpoint( 3, $results );

		// 4. Setup navigation menu.
		$results['menu_created'] = $this->setup_nav_menu();

		// 5. Verify WooCommerce pages.
		if ( class_exists( 'WooCommerce' ) ) {
			$results['wc_pages_ok'] = $this->verify_wc_pages();
		}

		// 6. Apply color preset.
		if ( ! empty( $color_preset ) && $this->apply_color_preset( $color_preset ) ) {
			$results['color_preset'] = $color_preset;
		}

		// 7. Purge cache + flush rewrite.
		$this->purge_cache();
		flush_rewrite_rules();

		// 8. Health check — verify site responds.
		$results['health_ok'] = $this->health_check();

		// Clear checkpoint — setup complete.
		$this->clear_checkpoint();

		LuwiPress_Logger::log( 'Site setup completed for ' . $slug, 'info', array(
			'pages'    => count( $results['pages_created'] ),
			'homepage' => $results['homepage_set'],
			'healthy'  => $results['health_ok'],
		) );

		$results['success'] = true;
		return $results;
	}

	/**
	 * Quick health check — verify the site homepage responds with 200.
	 *
	 * @return bool
	 */
	private function health_check() {
		$response = wp_remote_get( home_url( '/' ), array(
			'timeout'   => 10,
			'sslverify' => false,
		) );

		if ( is_wp_error( $response ) ) {
			LuwiPress_Logger::log( 'Health check failed: ' . $response->get_error_message(), 'warning' );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 400 ) {
			return true;
		}

		LuwiPress_Logger::log( 'Health check returned HTTP ' . $code, 'warning' );
		return false;
	}

	/**
	 * Verify WooCommerce essential pages exist and are assigned.
	 * Creates missing ones if needed.
	 *
	 * @return bool True if all pages are OK.
	 */
	/**
	 * Create and assign navigation menu if not already set.
	 *
	 * @return bool True if menu was created or already exists.
	 */
	private function setup_nav_menu() {
		$stylesheet = get_stylesheet();
		$option_key = 'theme_mods_' . $stylesheet;
		$mods       = get_option( $option_key, array() );
		$locations  = $mods['nav_menu_locations'] ?? get_nav_menu_locations();

		// 1. Already assigned to 'primary' and menu still exists → just re-write
		//    theme_mods to be sure (handles plugin context vs theme context).
		if ( ! empty( $locations['primary'] ) ) {
			$existing = wp_get_nav_menu_object( $locations['primary'] );
			if ( $existing ) {
				$mods['nav_menu_locations'] = $locations;
				update_option( $option_key, $mods );
				LuwiPress_Logger::log( 'Nav menu #' . $locations['primary'] . ' already assigned — re-confirmed in theme_mods', 'info' );
				return true;
			}
		}

		// 2. Find any existing menu to reuse — prefer one with items.
		$menus   = wp_get_nav_menus();
		$menu_id = 0;

		// First pass: find a menu that already has items (the "real" site menu).
		foreach ( $menus as $m ) {
			$items = wp_get_nav_menu_items( $m->term_id );
			if ( ! empty( $items ) ) {
				$menu_id = $m->term_id;
				break;
			}
		}

		// Second pass: any menu at all.
		if ( ! $menu_id && ! empty( $menus ) ) {
			$menu_id = $menus[0]->term_id;
		}

		// 3. No menu at all → create a minimal one.
		if ( ! $menu_id ) {
			$menu_id = wp_create_nav_menu( 'Main Menu' );
			if ( is_wp_error( $menu_id ) ) {
				return false;
			}

			$shop_url = function_exists( 'wc_get_page_permalink' )
				? wc_get_page_permalink( 'shop' )
				: home_url( '/shop/' );

			$items = array(
				array( 'title' => 'Home',    'url' => home_url( '/' ) ),
				array( 'title' => 'Shop',    'url' => $shop_url ),
				array( 'title' => 'About',   'url' => home_url( '/about/' ) ),
				array( 'title' => 'Contact', 'url' => home_url( '/contact/' ) ),
			);

			foreach ( $items as $item ) {
				wp_update_nav_menu_item( $menu_id, 0, array(
					'menu-item-title'  => $item['title'],
					'menu-item-url'    => $item['url'],
					'menu-item-status' => 'publish',
					'menu-item-type'   => 'custom',
				) );
			}

			LuwiPress_Logger::log( 'Created new nav menu #' . $menu_id, 'info' );
		}

		// Merge into existing locations so we don't wipe other assignments.
		$locations['primary'] = $menu_id;

		// set_theme_mod is unreliable when called from plugin context.
		// Write directly to the theme_mods option for the active theme.
		$stylesheet  = get_stylesheet();
		$option_key  = 'theme_mods_' . $stylesheet;
		$mods        = get_option( $option_key, array() );
		$mods['nav_menu_locations'] = $locations;
		update_option( $option_key, $mods );

		LuwiPress_Logger::log( 'Nav menu #' . $menu_id . ' assigned to primary location (theme_mods_' . $stylesheet . ')', 'info' );

		return true;
	}

	private function verify_wc_pages() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		$required = array(
			'woocommerce_shop_page_id'      => array( 'title' => 'Shop', 'slug' => 'shop' ),
			'woocommerce_cart_page_id'       => array( 'title' => 'Cart', 'slug' => 'cart', 'shortcode' => '[woocommerce_cart]' ),
			'woocommerce_checkout_page_id'   => array( 'title' => 'Checkout', 'slug' => 'checkout', 'shortcode' => '[woocommerce_checkout]' ),
			'woocommerce_myaccount_page_id'  => array( 'title' => 'My Account', 'slug' => 'my-account', 'shortcode' => '[woocommerce_my_account]' ),
		);

		$all_ok = true;

		foreach ( $required as $option_key => $page_def ) {
			$page_id = absint( get_option( $option_key ) );

			// Page exists and published?
			if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
				continue;
			}

			// Try to find by slug.
			$existing = get_page_by_path( $page_def['slug'] );
			if ( $existing && 'publish' === $existing->post_status ) {
				update_option( $option_key, $existing->ID );
				continue;
			}

			// Create missing page.
			$content = $page_def['shortcode'] ?? '';
			$new_id  = wp_insert_post( array(
				'post_title'   => $page_def['title'],
				'post_name'    => $page_def['slug'],
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => get_current_user_id() ?: 1,
			), true );

			if ( $new_id instanceof \WP_Error ) {
				$all_ok = false;
			} else {
				update_option( $option_key, $new_id );
			}
		}

		return $all_ok;
	}

	// ─── CLEANUP ──────────────────────────────────────────────────

	/**
	 * Remove starter pages (trash) and reset homepage.
	 *
	 * @return array
	 */
	public function cleanup() {
		$demo_importer = LuwiPress_Demo_Import::get_instance();
		$demo          = $demo_importer->get_status();
		$trashed       = 0;

		foreach ( $demo['pages'] ?? array() as $pg ) {
			if ( ! empty( $pg['exists'] ) && $pg['post_id'] ) {
				wp_trash_post( $pg['post_id'] );
				$trashed++;
			}
		}

		// Reset homepage if it was a starter page.
		$hp_id = absint( get_option( 'page_on_front' ) );
		if ( $hp_id > 0 && 'trash' === get_post_status( $hp_id ) ) {
			update_option( 'show_on_front', 'posts' );
			update_option( 'page_on_front', 0 );
		}

		$blog_id = absint( get_option( 'page_for_posts' ) );
		if ( $blog_id > 0 && 'trash' === get_post_status( $blog_id ) ) {
			update_option( 'page_for_posts', 0 );
		}

		$this->purge_cache();
		LuwiPress_Logger::log( 'Theme cleanup: trashed ' . $trashed . ' pages', 'info' );

		return array( 'success' => true, 'trashed' => $trashed );
	}

	// ─── AJAX HANDLERS ────────────────────────────────────────────

	public function ajax_install() {
		check_ajax_referer( 'luwipress_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'install_themes' ) ) {
			wp_send_json_error( 'Unauthorized — install_themes capability required.' );
		}

		$slug   = sanitize_text_field( $_POST['slug'] ?? 'luwi-gold' );
		$result = $this->install_theme( $slug );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result['error'] );
		}
	}

	public function ajax_activate() {
		check_ajax_referer( 'luwipress_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'switch_themes' ) ) {
			wp_send_json_error( 'Unauthorized — switch_themes capability required.' );
		}

		$slug   = sanitize_text_field( $_POST['slug'] ?? 'luwi-gold' );
		$result = $this->activate_theme( $slug );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result['error'] );
		}
	}

	public function ajax_setup_site() {
		check_ajax_referer( 'luwipress_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$slug    = sanitize_text_field( $_POST['slug'] ?? 'luwi-gold' );
		$preset  = sanitize_text_field( $_POST['color_preset'] ?? '' );
		$result  = $this->setup_site( $slug, $preset );
		wp_send_json_success( $result );
	}

	public function ajax_rollback() {
		check_ajax_referer( 'luwipress_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$result = $this->rollback();
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result['error'] );
		}
	}

	public function ajax_status() {
		check_ajax_referer( 'luwipress_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$slug = sanitize_text_field( $_POST['slug'] ?? 'luwi-gold' );
		wp_send_json_success( $this->get_status( $slug ) );
	}

	public function ajax_cleanup() {
		check_ajax_referer( 'luwipress_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$result = $this->cleanup();
		wp_send_json_success( $result );
	}

	// ─── HELPERS ──────────────────────────────────────────────────

	/**
	 * Check theme dependencies.
	 *
	 * @param array $def Theme definition.
	 * @return array Missing dependencies (empty = all good).
	 */
	private function check_dependencies( $def ) {
		$missing = array();
		$requires = $def['requires'] ?? array();

		if ( ! empty( $requires['plugins'] ) ) {
			foreach ( $requires['plugins'] as $plugin_slug ) {
				if ( 'elementor' === $plugin_slug && ! defined( 'ELEMENTOR_VERSION' ) ) {
					$missing[] = 'Elementor';
				}
			}
		}

		if ( ! empty( $requires['woocommerce'] ) && ! class_exists( 'WooCommerce' ) ) {
			$missing[] = 'WooCommerce';
		}

		return $missing;
	}

	/**
	 * Resolve download URL for a theme.
	 *
	 * Allows override via filter for custom hosting.
	 *
	 * @param array $def Theme definition.
	 * @return string URL.
	 */
	private function resolve_zip_url( $def ) {
		$url = $def['zip_url'] ?? '';
		$url = apply_filters( 'luwipress_theme_zip_url', $url, $def['slug'] ?? '' );

		// Fallback: if URL points to this site, resolve to local file path.
		$site_url = site_url( '/' );
		if ( ! empty( $url ) && strpos( $url, $site_url ) === 0 ) {
			$relative = substr( $url, strlen( $site_url ) );
			$path     = ABSPATH . $relative;
			if ( file_exists( $path ) ) {
				return $path;
			}
		}

		return $url;
	}

	// ─── THEME UPDATES ────────────────────────────────────────────

	/**
	 * Check GitHub Releases for available theme updates.
	 *
	 * Queries the GitHub API for each theme's latest release and compares
	 * against the installed version. Results are cached as a transient
	 * for 12 hours to avoid API rate limits.
	 *
	 * @param bool $force_refresh Skip transient cache.
	 * @return array Keyed by slug: ['latest_version', 'zip_url', 'has_update', 'changelog'].
	 */
	public function check_github_updates( $force_refresh = false ) {
		$cache_key = 'luwipress_theme_updates';

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$reg    = $this->get_registry();
		$config = $reg['config'] ?? array();
		$repo   = apply_filters( 'luwipress_theme_github_repo', 'umutsun/luwi-themes' );
		$api    = "https://api.github.com/repos/{$repo}/releases";

		$response = wp_remote_get( $api, array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'LuwiPress/' . LUWIPRESS_VERSION,
			),
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			LuwiPress_Logger::log(
				'GitHub update check failed: ' . ( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) ),
				'warning'
			);
			return array();
		}

		$releases = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $releases ) ) {
			return array();
		}

		// Map: slug => prefix used in release tag names.
		// Tags follow pattern: gold-v1.0.1, emerald-v1.0.0, etc.
		$slug_prefixes = array(
			'luwi-gold'    => 'gold-v',
			'luwi-emerald' => 'emerald-v',
			'luwi-ruby'    => 'ruby-v',
		);

		$updates = array();

		foreach ( $reg['themes'] as $slug => $def ) {
			$prefix = $slug_prefixes[ $slug ] ?? str_replace( 'luwi-', '', $slug ) . '-v';

			// Find the latest release matching this theme's tag prefix.
			$latest_release = null;
			foreach ( $releases as $rel ) {
				$tag = $rel['tag_name'] ?? '';
				if ( strpos( $tag, $prefix ) === 0 && empty( $rel['draft'] ) ) {
					$latest_release = $rel;
					break; // GitHub returns newest first.
				}
			}

			if ( ! $latest_release ) {
				continue;
			}

			$latest_version = ltrim( str_replace( $prefix, '', $latest_release['tag_name'] ), 'v' );
			$installed_ver  = null;
			$wp_theme       = wp_get_theme( $slug );
			if ( $wp_theme->exists() ) {
				$installed_ver = $wp_theme->get( 'Version' );
			}

			// Find the ZIP asset in the release.
			$zip_url = '';
			foreach ( $latest_release['assets'] ?? array() as $asset ) {
				if ( substr( $asset['name'], -4 ) === '.zip' ) {
					$zip_url = $asset['browser_download_url'] ?? '';
					break;
				}
			}

			$has_update = $installed_ver && version_compare( $latest_version, $installed_ver, '>' );

			$updates[ $slug ] = array(
				'latest_version' => $latest_version,
				'installed_ver'  => $installed_ver,
				'has_update'     => $has_update,
				'zip_url'        => $zip_url,
				'changelog'      => $latest_release['body'] ?? '',
				'published_at'   => $latest_release['published_at'] ?? '',
				'tag_name'       => $latest_release['tag_name'] ?? '',
			);
		}

		set_transient( $cache_key, $updates, 12 * HOUR_IN_SECONDS );

		return $updates;
	}

	/**
	 * Update a single theme to the latest GitHub release.
	 *
	 * @param string $slug Theme slug.
	 * @return array Result with success/error.
	 */
	public function update_theme( $slug ) {
		$updates = $this->check_github_updates( true ); // always force-refresh on update

		if ( empty( $updates[ $slug ] ) || empty( $updates[ $slug ]['has_update'] ) ) {
			return array( 'success' => false, 'error' => 'No update available.' );
		}

		$zip_url = $updates[ $slug ]['zip_url'];
		if ( empty( $zip_url ) ) {
			return array( 'success' => false, 'error' => 'No ZIP asset found in release.' );
		}

		// Update the registry zip_url to point to the new release.
		$def = $this->get_theme_def( $slug );
		if ( ! $def ) {
			return array( 'success' => false, 'error' => 'Theme not in registry.' );
		}

		$def['zip_url'] = $zip_url;
		$was_active = ( get_template() === $slug );

		// Install (overwrite) the theme.
		$result = $this->install_theme( $slug, $def );

		if ( empty( $result['success'] ) ) {
			return $result;
		}

		// Re-activate if it was the active theme.
		if ( $was_active ) {
			switch_theme( $slug );
		}

		// Update the local registry with the new version + zip_url.
		$this->update_registry_version( $slug, $updates[ $slug ]['latest_version'], $zip_url );

		// Clear the update transient so UI refreshes.
		delete_transient( 'luwipress_theme_updates' );

		LuwiPress_Logger::log( "Theme {$slug} updated to v{$updates[$slug]['latest_version']}", 'info' );

		return array(
			'success'     => true,
			'slug'        => $slug,
			'old_version' => $updates[ $slug ]['installed_ver'],
			'new_version' => $updates[ $slug ]['latest_version'],
			'was_active'  => $was_active,
		);
	}

	/**
	 * Update the local registry JSON with a new version and zip_url.
	 *
	 * @param string $slug    Theme slug.
	 * @param string $version New version string.
	 * @param string $zip_url New ZIP download URL.
	 */
	private function update_registry_version( $slug, $version, $zip_url ) {
		$file = LUWIPRESS_PLUGIN_DIR . 'includes/theme-registry.json';
		$data = json_decode( file_get_contents( $file ), true );

		if ( ! isset( $data['themes'][ $slug ] ) ) {
			return;
		}

		$data['themes'][ $slug ]['version'] = $version;
		$data['themes'][ $slug ]['zip_url'] = $zip_url;

		file_put_contents( $file, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

		// Clear cached registry.
		$this->registry = null;
	}

	/**
	 * AJAX: Check for theme updates.
	 */
	public function ajax_check_updates() {
		check_ajax_referer( 'luwipress_dashboard_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$updates = $this->check_github_updates( true );
		wp_send_json_success( $updates );
	}

	/**
	 * AJAX: Update a theme to its latest version.
	 */
	public function ajax_update_theme() {
		check_ajax_referer( 'luwipress_dashboard_nonce', 'nonce' );

		if ( ! current_user_can( 'install_themes' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$slug = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
		if ( empty( $slug ) ) {
			wp_send_json_error( 'Missing theme slug.' );
		}

		$result = $this->update_theme( $slug );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result['error'] ?? 'Update failed.' );
		}
	}

	/**
	 * Purge known caches.
	 */
	private function purge_cache() {
		if ( class_exists( 'LuwiPress_Plugin_Detector' ) ) {
			$detector = LuwiPress_Plugin_Detector::get_instance();
			if ( method_exists( $detector, 'purge_all_cache' ) ) {
				$detector->purge_all_cache();
			}
		}

		if ( function_exists( 'litespeed_purge_all' ) ) {
			litespeed_purge_all();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}
}
