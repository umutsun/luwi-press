<?php
/**
 * Base class for all Luwi Elementor widgets.
 *
 * Provides common utilities: LuwiPress detection, cross-marketing notices,
 * shared render helpers, and the "Luwi Widgets" category.
 *
 * @package Luwi_Emerald
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Luwi_Widget_Base extends \Elementor\Widget_Base {

	/**
	 * Widget category.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'luwi-widgets' );
	}

	/**
	 * Widget icon prefix.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-apps';
	}

	/**
	 * Check if LuwiPress plugin is active.
	 *
	 * @return bool
	 */
	protected function is_luwipress_active() {
		return class_exists( 'LuwiPress' );
	}

	/**
	 * Render a "Powered by LuwiPress" cross-marketing notice in the editor
	 * when the plugin is not active.
	 *
	 * @param string $feature_description What the widget gains with LuwiPress.
	 */
	protected function render_luwipress_upsell( $feature_description = '' ) {
		if ( $this->is_luwipress_active() ) {
			return;
		}

		if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			$message = $feature_description
				? $feature_description
				: __( 'This widget gains AI-powered features with LuwiPress plugin.', 'luwi-emerald' );

			printf(
				'<div class="luwi-widget-upsell">'
				. '<span class="luwi-widget-upsell__icon">&#9889;</span>'
				. '<span class="luwi-widget-upsell__text">%s</span>'
				. '</div>',
				esc_html( $message )
			);
		}
	}

	/**
	 * Get a LuwiPress option value safely.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	protected function get_luwipress_option( $option, $default = false ) {
		if ( ! $this->is_luwipress_active() ) {
			return $default;
		}
		return get_option( $option, $default );
	}

	/**
	 * Common style section: spacing, colors, typography.
	 *
	 * @param string $section_id  Section ID.
	 * @param string $section_label Section label.
	 */
	protected function register_luwi_style_section( $section_id, $section_label ) {
		$this->start_controls_section(
			$section_id,
			array(
				'label' => $section_label,
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			$section_id . '_padding',
			array(
				'label'      => __( 'Padding', 'luwi-emerald' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .luwi-widget' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}
}
