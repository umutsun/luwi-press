<?php
/**
 * WooCommerce compatibility — theme support, hooks, template helpers.
 *
 * @package Luwi_Emerald
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Luwi_WooCommerce_Compat {

	/**
	 * Initialize WooCommerce hooks.
	 */
	public static function init() {
		add_action( 'after_setup_theme', array( __CLASS__, 'setup' ) );
		add_filter( 'woocommerce_enqueue_styles', array( __CLASS__, 'dequeue_default_styles' ) );
		add_filter( 'loop_shop_per_page', array( __CLASS__, 'products_per_page' ) );
		add_filter( 'loop_shop_columns', array( __CLASS__, 'shop_columns' ) );
		add_filter( 'woocommerce_output_related_products_args', array( __CLASS__, 'related_products_args' ) );
		add_filter( 'woocommerce_cross_sells_columns', array( __CLASS__, 'cross_sells_columns' ) );
	}

	/**
	 * Add WooCommerce theme support.
	 */
	public static function setup() {
		add_theme_support( 'woocommerce', array(
			'thumbnail_image_width' => 600,
			'gallery_thumbnail_image_width' => 150,
			'single_image_width' => 800,
			'product_grid' => array(
				'default_rows'    => 4,
				'min_rows'        => 1,
				'default_columns' => 3,
				'min_columns'     => 1,
				'max_columns'     => 4,
			),
		) );

		add_theme_support( 'wc-product-gallery-zoom' );
		add_theme_support( 'wc-product-gallery-lightbox' );
		add_theme_support( 'wc-product-gallery-slider' );
	}

	/**
	 * Selectively dequeue default WooCommerce styles that we override.
	 *
	 * @param array $styles Default styles.
	 * @return array
	 */
	public static function dequeue_default_styles( $styles ) {
		// Keep WooCommerce layout and smallscreen; override general styling via our CSS.
		unset( $styles['woocommerce-general'] );
		return $styles;
	}

	/**
	 * Products per page (Customizer-controlled).
	 *
	 * @return int
	 */
	public static function products_per_page() {
		return absint( get_theme_mod( 'luwi_products_per_page', 12 ) );
	}

	/**
	 * Shop columns (Customizer-controlled).
	 *
	 * @return int
	 */
	public static function shop_columns() {
		return absint( get_theme_mod( 'luwi_shop_columns', 3 ) );
	}

	/**
	 * Related products configuration.
	 *
	 * @param array $args Default args.
	 * @return array
	 */
	public static function related_products_args( $args ) {
		$args['posts_per_page'] = 4;
		$args['columns']        = 4;
		return $args;
	}

	/**
	 * Cross-sells columns.
	 *
	 * @return int
	 */
	public static function cross_sells_columns() {
		return 3;
	}
}
