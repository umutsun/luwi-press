<?php
/**
 * Elementor compatibility — Theme Builder locations, widget registration, editor integration.
 *
 * @package Luwi_Ruby
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Luwi_Elementor_Compat {

	/**
	 * Initialize Elementor hooks.
	 */
	public static function init() {
		add_action( 'elementor/theme/register_locations', array( __CLASS__, 'register_locations' ) );
		add_action( 'elementor/elements/categories_registered', array( __CLASS__, 'register_widget_category' ) );
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widgets' ) );
		add_action( 'elementor/editor/after_enqueue_styles', array( __CLASS__, 'editor_styles' ) );
		add_action( 'elementor/preview/enqueue_styles', array( __CLASS__, 'preview_styles' ) );
		add_action( 'elementor/frontend/after_enqueue_styles', array( __CLASS__, 'widget_styles' ) );
	}

	/**
	 * Register Theme Builder locations for full site editing.
	 *
	 * @param \Elementor\Core\Theme_Support\Theme_Support_Manager $manager Location manager.
	 */
	public static function register_locations( $manager ) {
		$manager->register_all_core_location();
	}

	/**
	 * Register "Luwi Widgets" category in the Elementor panel.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager Elements manager.
	 */
	public static function register_widget_category( $elements_manager ) {
		$elements_manager->add_category(
			'luwi-widgets',
			array(
				'title' => __( 'Luwi Widgets', 'luwi-ruby' ),
				'icon'  => 'eicon-apps',
			)
		);
	}

	/**
	 * Register all Luwi Widgets.
	 *
	 * Tier 1 widgets always load (theme-only).
	 * Tier 2 widgets always load (enhanced when LuwiPress active).
	 * Tier 3 widgets only load when LuwiPress is active.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Widgets manager.
	 */
	public static function register_widgets( $widgets_manager ) {
		// Load base class.
		require_once LUWI_THEME_DIR . '/widgets/class-luwi-widget-base.php';

		// --- Tier 1: Theme-Only (always available) ---
		$tier1_widgets = array(
			'product-card'       => 'Luwi_Widget_Product_Card',
			'trust-badges'       => 'Luwi_Widget_Trust_Badges',
			'color-mode-toggle'  => 'Luwi_Widget_Color_Mode_Toggle',
			'countdown'          => 'Luwi_Widget_Countdown',
			'category-showcase'  => 'Luwi_Widget_Category_Showcase',
		);

		foreach ( $tier1_widgets as $file => $class ) {
			$path = LUWI_THEME_DIR . '/widgets/class-luwi-' . $file . '.php';
			if ( file_exists( $path ) ) {
				require_once $path;
				if ( class_exists( $class ) ) {
					$widgets_manager->register( new $class() );
				}
			}
		}

		// --- Tier 2: LuwiPress-Enhanced (always available, enhanced with plugin) ---
		$tier2_widgets = array(
			'faq-accordion'     => 'Luwi_Widget_FAQ_Accordion',
			'smart-search'      => 'Luwi_Widget_Smart_Search',
		);

		foreach ( $tier2_widgets as $file => $class ) {
			$path = LUWI_THEME_DIR . '/widgets/class-luwi-' . $file . '.php';
			if ( file_exists( $path ) ) {
				require_once $path;
				if ( class_exists( $class ) ) {
					$widgets_manager->register( new $class() );
				}
			}
		}

		// --- Tier 3: LuwiPress-Required (only when plugin active) ---
		if ( luwi_is_luwipress_active() ) {
			$tier3_widgets = array(
				'ai-chat'            => 'Luwi_Widget_AI_Chat',
				'knowledge-graph'    => 'Luwi_Widget_Knowledge_Graph',
				'marketplace-status' => 'Luwi_Widget_Marketplace_Status',
			);

			foreach ( $tier3_widgets as $file => $class ) {
				$path = LUWI_THEME_DIR . '/widgets/class-luwi-' . $file . '.php';
				if ( file_exists( $path ) ) {
					require_once $path;
					if ( class_exists( $class ) ) {
						$widgets_manager->register( new $class() );
					}
				}
			}
		}
	}

	/**
	 * Check if Elementor is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Check if current page uses an Elementor Theme Builder template for a given location.
	 *
	 * @param string $location Location name (header, footer, etc.).
	 * @return bool
	 */
	public static function has_template( $location ) {
		if ( ! self::is_active() ) {
			return false;
		}

		$manager = \Elementor\Plugin::instance()->modules_manager->get_modules( 'theme-builder' );
		if ( ! $manager ) {
			return false;
		}

		$conditions = $manager->get_conditions_manager();
		return ! empty( $conditions->get_documents_for_location( $location ) );
	}

	/**
	 * Enqueue styles in the Elementor editor.
	 */
	public static function editor_styles() {
		wp_enqueue_style(
			'luwi-editor',
			LUWI_THEME_URI . '/assets/css/base.css',
			array(),
			LUWI_THEME_VERSION
		);
	}

	/**
	 * Enqueue styles in the Elementor preview.
	 */
	public static function preview_styles() {
		wp_enqueue_style(
			'luwi-preview',
			LUWI_THEME_URI . '/assets/css/base.css',
			array(),
			LUWI_THEME_VERSION
		);
	}

	/**
	 * Enqueue widget-specific styles on the frontend.
	 */
	public static function widget_styles() {
		$path = LUWI_THEME_DIR . '/assets/css/widgets.css';
		if ( file_exists( $path ) ) {
			wp_enqueue_style(
				'luwi-widgets',
				LUWI_THEME_URI . '/assets/css/widgets.css',
				array( 'luwi-base' ),
				LUWI_THEME_VERSION
			);
		}
	}
}
