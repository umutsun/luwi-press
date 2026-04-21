<?php
/**
 * Customizer — theme settings for colors, typography, layout, and accessibility.
 *
 * @package Luwi_Emerald
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Luwi_Customizer {

	/**
	 * Initialize customizer hooks.
	 */
	public static function init() {
		add_action( 'customize_register', array( __CLASS__, 'register' ) );
		add_action( 'wp_head', array( __CLASS__, 'output_css_variables' ), 5 );
	}

	/**
	 * Register customizer sections, settings, and controls.
	 *
	 * @param WP_Customize_Manager $wp_customize Customizer manager.
	 */
	public static function register( $wp_customize ) {

		/* ---------------------------------------------------------------
		 * Panel: Luwi Theme Options
		 * ------------------------------------------------------------- */
		$wp_customize->add_panel( 'luwi_panel', array(
			'title'    => __( 'Luwi Theme Options', 'luwi-emerald' ),
			'priority' => 30,
		) );

		/* ---------------------------------------------------------------
		 * Section: Color Mode
		 * ------------------------------------------------------------- */
		$wp_customize->add_section( 'luwi_color_mode_section', array(
			'title' => __( 'Color Mode', 'luwi-emerald' ),
			'panel' => 'luwi_panel',
		) );

		$wp_customize->add_setting( 'luwi_color_mode', array(
			'default'           => 'auto',
			'sanitize_callback' => array( __CLASS__, 'sanitize_color_mode' ),
			'transport'         => 'postMessage',
		) );

		$wp_customize->add_control( 'luwi_color_mode', array(
			'label'   => __( 'Default Color Mode', 'luwi-emerald' ),
			'section' => 'luwi_color_mode_section',
			'type'    => 'radio',
			'choices' => array(
				'light' => __( 'Light', 'luwi-emerald' ),
				'dark'  => __( 'Dark', 'luwi-emerald' ),
				'auto'  => __( 'Auto (System Preference)', 'luwi-emerald' ),
			),
		) );

		$wp_customize->add_setting( 'luwi_show_mode_toggle', array(
			'default'           => true,
			'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
		) );

		$wp_customize->add_control( 'luwi_show_mode_toggle', array(
			'label'   => __( 'Show Light/Dark Toggle', 'luwi-emerald' ),
			'section' => 'luwi_color_mode_section',
			'type'    => 'checkbox',
		) );

		/* ---------------------------------------------------------------
		 * Section: Colors
		 * ------------------------------------------------------------- */
		$wp_customize->add_section( 'luwi_colors_section', array(
			'title' => __( 'Brand Colors', 'luwi-emerald' ),
			'panel' => 'luwi_panel',
		) );

		$color_settings = array(
			'luwi_color_primary' => array(
				'default' => '#735c00',
				'label'   => __( 'Primary Color', 'luwi-emerald' ),
			),
			'luwi_color_accent' => array(
				'default' => '#545e76',
				'label'   => __( 'Accent Color', 'luwi-emerald' ),
			),
			'luwi_color_text' => array(
				'default' => '#1b1c1c',
				'label'   => __( 'Text Color', 'luwi-emerald' ),
			),
			'luwi_color_bg' => array(
				'default' => '#fcf9f8',
				'label'   => __( 'Background Color', 'luwi-emerald' ),
			),
		);

		foreach ( $color_settings as $id => $args ) {
			$wp_customize->add_setting( $id, array(
				'default'           => $args['default'],
				'sanitize_callback' => 'sanitize_hex_color',
				'transport'         => 'postMessage',
			) );

			$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, $id, array(
				'label'   => $args['label'],
				'section' => 'luwi_colors_section',
			) ) );
		}

		/* ---------------------------------------------------------------
		 * Section: Typography
		 * ------------------------------------------------------------- */
		$wp_customize->add_section( 'luwi_typography_section', array(
			'title' => __( 'Typography', 'luwi-emerald' ),
			'panel' => 'luwi_panel',
		) );

		$wp_customize->add_setting( 'luwi_heading_font', array(
			'default'           => 'DM Serif Display',
			'sanitize_callback' => 'sanitize_text_field',
		) );

		$wp_customize->add_control( 'luwi_heading_font', array(
			'label'   => __( 'Heading Font', 'luwi-emerald' ),
			'section' => 'luwi_typography_section',
			'type'    => 'select',
			'choices' => array(
				'DM Serif Display' => 'DM Serif Display',
				'Cormorant'        => 'Cormorant',
				'Lora'             => 'Lora',
				'Merriweather'     => 'Merriweather',
				'DM Serif Display' => 'DM Serif Display',
				'Source Serif 4'   => 'Source Serif 4',
			),
		) );

		$wp_customize->add_setting( 'luwi_body_font', array(
			'default'           => 'Plus Jakarta Sans',
			'sanitize_callback' => 'sanitize_text_field',
		) );

		$wp_customize->add_control( 'luwi_body_font', array(
			'label'   => __( 'Body Font', 'luwi-emerald' ),
			'section' => 'luwi_typography_section',
			'type'    => 'select',
			'choices' => array(
				'Plus Jakarta Sans'     => 'Plus Jakarta Sans',
				'DM Sans'   => 'DM Sans',
				'Plus Jakarta Sans' => 'Plus Jakarta Sans',
				'Outfit'    => 'Outfit',
				'Manrope'   => 'Manrope',
				'Work Sans' => 'Work Sans',
			),
		) );

		/* ---------------------------------------------------------------
		 * Section: Layout
		 * ------------------------------------------------------------- */
		$wp_customize->add_section( 'luwi_layout_section', array(
			'title' => __( 'Layout', 'luwi-emerald' ),
			'panel' => 'luwi_panel',
		) );

		$wp_customize->add_setting( 'luwi_container_width', array(
			'default'           => 1200,
			'sanitize_callback' => 'absint',
		) );

		$wp_customize->add_control( 'luwi_container_width', array(
			'label'       => __( 'Container Width (px)', 'luwi-emerald' ),
			'section'     => 'luwi_layout_section',
			'type'        => 'number',
			'input_attrs' => array( 'min' => 960, 'max' => 1600, 'step' => 10 ),
		) );

		$wp_customize->add_setting( 'luwi_sticky_header', array(
			'default'           => true,
			'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
		) );

		$wp_customize->add_control( 'luwi_sticky_header', array(
			'label'   => __( 'Sticky Header', 'luwi-emerald' ),
			'section' => 'luwi_layout_section',
			'type'    => 'checkbox',
		) );

		/* ---------------------------------------------------------------
		 * Section: WooCommerce
		 * ------------------------------------------------------------- */
		if ( class_exists( 'WooCommerce' ) ) {
			$wp_customize->add_section( 'luwi_wc_section', array(
				'title' => __( 'WooCommerce Layout', 'luwi-emerald' ),
				'panel' => 'luwi_panel',
			) );

			$wp_customize->add_setting( 'luwi_shop_columns', array(
				'default'           => 3,
				'sanitize_callback' => 'absint',
			) );

			$wp_customize->add_control( 'luwi_shop_columns', array(
				'label'   => __( 'Products Per Row', 'luwi-emerald' ),
				'section' => 'luwi_wc_section',
				'type'    => 'select',
				'choices' => array( 2 => '2', 3 => '3', 4 => '4' ),
			) );

			$wp_customize->add_setting( 'luwi_products_per_page', array(
				'default'           => 12,
				'sanitize_callback' => 'absint',
			) );

			$wp_customize->add_control( 'luwi_products_per_page', array(
				'label'       => __( 'Products Per Page', 'luwi-emerald' ),
				'section'     => 'luwi_wc_section',
				'type'        => 'number',
				'input_attrs' => array( 'min' => 4, 'max' => 48, 'step' => 4 ),
			) );
		}

		/* ---------------------------------------------------------------
		 * Section: Accessibility
		 * ------------------------------------------------------------- */
		$wp_customize->add_section( 'luwi_a11y_section', array(
			'title' => __( 'Accessibility', 'luwi-emerald' ),
			'panel' => 'luwi_panel',
		) );

		$wp_customize->add_setting( 'luwi_high_contrast', array(
			'default'           => false,
			'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
		) );

		$wp_customize->add_control( 'luwi_high_contrast', array(
			'label'       => __( 'High Contrast Mode', 'luwi-emerald' ),
			'description' => __( 'Increases color contrast ratios for better readability.', 'luwi-emerald' ),
			'section'     => 'luwi_a11y_section',
			'type'        => 'checkbox',
		) );

		$wp_customize->add_setting( 'luwi_reduce_motion', array(
			'default'           => false,
			'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
		) );

		$wp_customize->add_control( 'luwi_reduce_motion', array(
			'label'       => __( 'Reduce Motion', 'luwi-emerald' ),
			'description' => __( 'Disables animations for users sensitive to motion.', 'luwi-emerald' ),
			'section'     => 'luwi_a11y_section',
			'type'        => 'checkbox',
		) );

		$wp_customize->add_setting( 'luwi_font_size_scale', array(
			'default'           => 100,
			'sanitize_callback' => 'absint',
		) );

		$wp_customize->add_control( 'luwi_font_size_scale', array(
			'label'       => __( 'Base Font Size (%)', 'luwi-emerald' ),
			'description' => __( 'Scale all text up or down. Default: 100%.', 'luwi-emerald' ),
			'section'     => 'luwi_a11y_section',
			'type'        => 'range',
			'input_attrs' => array( 'min' => 80, 'max' => 150, 'step' => 5 ),
		) );
	}

	/**
	 * Output CSS custom properties based on Customizer settings.
	 */
	public static function output_css_variables() {
		$primary   = get_theme_mod( 'luwi_color_primary', '#735c00' );
		$accent    = get_theme_mod( 'luwi_color_accent', '#545e76' );
		$text      = get_theme_mod( 'luwi_color_text', '#1b1c1c' );
		$bg        = get_theme_mod( 'luwi_color_bg', '#fcf9f8' );
		$container = get_theme_mod( 'luwi_container_width', 1200 );
		$heading   = get_theme_mod( 'luwi_heading_font', 'DM Serif Display' );
		$body      = get_theme_mod( 'luwi_body_font', 'Plus Jakarta Sans' );
		$scale     = get_theme_mod( 'luwi_font_size_scale', 100 );

		echo "<style id=\"luwi-customizer-vars\">\n:root {\n";
		echo "\t--luwi-primary: {$primary};\n";
		echo "\t--luwi-accent: {$accent};\n";
		echo "\t--luwi-text: {$text};\n";
		echo "\t--luwi-bg: {$bg};\n";
		echo "\t--luwi-container-lg: {$container}px;\n";
		echo "\t--luwi-font-heading: '{$heading}', Georgia, serif;\n";
		echo "\t--luwi-font-body: '{$body}', -apple-system, BlinkMacSystemFont, sans-serif;\n";
		echo "\t--luwi-font-scale: {$scale}%;\n";
		echo "}\n</style>\n";
	}

	/**
	 * Sanitize color mode value.
	 *
	 * @param string $value Input value.
	 * @return string
	 */
	public static function sanitize_color_mode( $value ) {
		return in_array( $value, array( 'light', 'dark', 'auto' ), true ) ? $value : 'auto';
	}

	/**
	 * Sanitize checkbox.
	 *
	 * @param mixed $value Input value.
	 * @return bool
	 */
	public static function sanitize_checkbox( $value ) {
		return (bool) $value;
	}
}
