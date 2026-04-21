<?php
/**
 * Asset loading — CSS, JS, and fonts.
 *
 * @package Luwi_Gold
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Luwi_Assets {

	/**
	 * Initialize asset hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'wp_head', array( __CLASS__, 'preload_fonts' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'preconnect_hints' ), 1 );
	}

	/**
	 * Enqueue front-end styles.
	 */
	public static function enqueue_styles() {
		$version = LUWI_THEME_VERSION;

		// Theme-specific tokens (CSS custom properties) — loaded FIRST.
		wp_enqueue_style(
			'luwi-tokens',
			LUWI_THEME_URI . '/assets/css/tokens.css',
			array(),
			$version
		);

		// Shared base (reset, typography, layout, components).
		wp_enqueue_style(
			'luwi-base',
			LUWI_THEME_URI . '/assets/css/base.css',
			array( 'luwi-tokens' ),
			$version
		);

		// Theme-specific personality (component overrides).
		wp_enqueue_style(
			'luwi-personality',
			LUWI_THEME_URI . '/assets/css/personality.css',
			array( 'luwi-base' ),
			$version
		);

		if ( class_exists( 'WooCommerce' ) ) {
			wp_enqueue_style(
				'luwi-woocommerce',
				LUWI_THEME_URI . '/assets/css/woocommerce.css',
				array( 'luwi-base' ),
				$version
			);
		}

		wp_enqueue_style(
			'luwi-responsive',
			LUWI_THEME_URI . '/assets/css/responsive.css',
			array( 'luwi-base' ),
			$version
		);

		// Plugin compatibility styles.
		wp_enqueue_style(
			'luwi-plugins',
			LUWI_THEME_URI . '/assets/css/plugins.css',
			array( 'luwi-base' ),
			$version
		);
	}

	/**
	 * Enqueue front-end scripts.
	 */
	public static function enqueue_scripts() {
		wp_enqueue_script(
			'luwi-theme',
			LUWI_THEME_URI . '/assets/js/theme.js',
			array(),
			LUWI_THEME_VERSION,
			array( 'strategy' => 'defer', 'in_footer' => true )
		);
	}

	/**
	 * Preload critical fonts.
	 */
	public static function preload_fonts() {
		$heading_font = get_theme_mod( 'luwi_heading_font', 'Playfair Display' );
		$body_font    = get_theme_mod( 'luwi_body_font', 'Inter' );

		$fonts = rawurlencode( $heading_font ) . ':ital,wght@0,400;0,700;1,400&family='
			. 'Noto+Serif:wght@400;700&family='
			. rawurlencode( $body_font ) . ':wght@300;400;500;600';

		printf(
			'<link rel="preload" href="https://fonts.googleapis.com/css2?family=%s&display=swap" as="style" onload="this.onload=null;this.rel=\'stylesheet\'">' . "\n",
			esc_attr( $fonts )
		);
		printf(
			'<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=%s&display=swap"></noscript>' . "\n",
			esc_attr( $fonts )
		);
	}

	/**
	 * DNS preconnect hints for external resources.
	 */
	public static function preconnect_hints() {
		echo '<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>' . "\n";
		echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
	}
}
