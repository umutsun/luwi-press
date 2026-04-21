<?php
/**
 * Luwi Color Mode Toggle Widget — light/dark mode switcher for header.
 *
 * @package Luwi_Emerald
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Luwi_Widget_Color_Mode_Toggle extends Luwi_Widget_Base {

	public function get_name() {
		return 'luwi-color-mode-toggle';
	}

	public function get_title() {
		return __( 'Luwi Dark Mode Toggle', 'luwi-emerald' );
	}

	public function get_icon() {
		return 'eicon-adjust';
	}

	public function get_keywords() {
		return array( 'dark', 'light', 'mode', 'toggle', 'theme', 'luwi' );
	}

	protected function register_controls() {

		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Toggle', 'luwi-emerald' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'style',
			array(
				'label'   => __( 'Style', 'luwi-emerald' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'icon',
				'options' => array(
					'icon'   => __( 'Icon Only', 'luwi-emerald' ),
					'pill'   => __( 'Pill Toggle', 'luwi-emerald' ),
					'switch' => __( 'Switch', 'luwi-emerald' ),
				),
			)
		);

		$this->add_responsive_control(
			'size',
			array(
				'label'      => __( 'Size', 'luwi-emerald' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 24, 'max' => 64 ) ),
				'default'    => array( 'size' => 40, 'unit' => 'px' ),
				'selectors'  => array(
					'{{WRAPPER}} .luwi-color-toggle' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .luwi-color-toggle svg' => 'width: calc({{SIZE}}{{UNIT}} * 0.5); height: calc({{SIZE}}{{UNIT}} * 0.5);',
				),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$style    = $settings['style'];

		?>
		<button class="luwi-color-toggle luwi-color-toggle--<?php echo esc_attr( $style ); ?>"
			aria-label="<?php esc_attr_e( 'Toggle color mode', 'luwi-emerald' ); ?>"
			data-luwi-color-toggle>
			<svg class="luwi-color-toggle__sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
				<circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
			</svg>
			<svg class="luwi-color-toggle__moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
				<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
			</svg>
		</button>
		<?php
	}
}
