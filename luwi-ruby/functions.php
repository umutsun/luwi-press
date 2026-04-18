<?php
/**
 * Luwi Ruby Theme — Bold Luxe
 *
 * @package Luwi_Ruby
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LUWI_THEME_VERSION', '1.0.0' );
define( 'LUWI_THEME_DIR', get_template_directory() );
define( 'LUWI_THEME_URI', get_template_directory_uri() );
define( 'LUWI_THEME_MIN_PHP', '7.4' );
define( 'LUWI_THEME_MIN_WP', '6.0' );

/**
 * Load theme classes.
 */
require_once LUWI_THEME_DIR . '/inc/class-luwi-theme-setup.php';
require_once LUWI_THEME_DIR . '/inc/class-luwi-assets.php';
require_once LUWI_THEME_DIR . '/inc/class-luwi-elementor.php';
require_once LUWI_THEME_DIR . '/inc/class-luwi-woocommerce.php';
require_once LUWI_THEME_DIR . '/inc/class-luwi-customizer.php';

/**
 * Initialize theme.
 */
function luwi_theme_init() {
	Luwi_Theme_Setup::init();
	Luwi_Assets::init();
	Luwi_Elementor_Compat::init();
	Luwi_Customizer::init();

	if ( class_exists( 'WooCommerce' ) ) {
		Luwi_WooCommerce_Compat::init();
	}
}
add_action( 'after_setup_theme', 'luwi_theme_init' );

/**
 * Check if LuwiPress plugin is active.
 *
 * @return bool
 */
function luwi_is_luwipress_active() {
	return class_exists( 'LuwiPress' );
}

/**
 * Get current color mode preference.
 *
 * @return string 'light' or 'dark'
 */
function luwi_get_color_mode() {
	return get_theme_mod( 'luwi_color_mode', 'auto' );
}
