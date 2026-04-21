<?php
/**
 * Luwi Trust Badges Widget — configurable trust indicators row.
 *
 * @package Luwi_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Luwi_Widget_Trust_Badges extends Luwi_Widget_Base {

	public function get_name() {
		return 'luwi-trust-badges';
	}

	public function get_title() {
		return __( 'Luwi Trust Badges', 'luwi-elementor' );
	}

	public function get_icon() {
		return 'eicon-check-circle';
	}

	public function get_keywords() {
		return array( 'trust', 'badges', 'shipping', 'warranty', 'secure', 'luwi' );
	}

	protected function register_controls() {

		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Badges', 'luwi-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$repeater = new \Elementor\Repeater();

		$repeater->add_control(
			'badge_icon',
			array(
				'label'   => __( 'Icon', 'luwi-elementor' ),
				'type'    => \Elementor\Controls_Manager::ICONS,
				'default' => array(
					'value'   => 'fas fa-shipping-fast',
					'library' => 'fa-solid',
				),
			)
		);

		$repeater->add_control(
			'badge_title',
			array(
				'label'   => __( 'Title', 'luwi-elementor' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Free Shipping', 'luwi-elementor' ),
			)
		);

		$repeater->add_control(
			'badge_description',
			array(
				'label'   => __( 'Description', 'luwi-elementor' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'On orders over $100', 'luwi-elementor' ),
			)
		);

		$this->add_control(
			'badges',
			array(
				'label'   => __( 'Trust Badges', 'luwi-elementor' ),
				'type'    => \Elementor\Controls_Manager::REPEATER,
				'fields'  => $repeater->get_controls(),
				'default' => array(
					array(
						'badge_title'       => __( 'Free Shipping', 'luwi-elementor' ),
						'badge_description' => __( 'On orders over $100', 'luwi-elementor' ),
						'badge_icon'        => array( 'value' => 'fas fa-shipping-fast', 'library' => 'fa-solid' ),
					),
					array(
						'badge_title'       => __( '15-Day Returns', 'luwi-elementor' ),
						'badge_description' => __( 'Money-back guarantee', 'luwi-elementor' ),
						'badge_icon'        => array( 'value' => 'fas fa-undo', 'library' => 'fa-solid' ),
					),
					array(
						'badge_title'       => __( 'Secure Payment', 'luwi-elementor' ),
						'badge_description' => __( '256-bit SSL encryption', 'luwi-elementor' ),
						'badge_icon'        => array( 'value' => 'fas fa-lock', 'library' => 'fa-solid' ),
					),
					array(
						'badge_title'       => __( 'Expert Support', 'luwi-elementor' ),
						'badge_description' => __( 'Instrument specialists', 'luwi-elementor' ),
						'badge_icon'        => array( 'value' => 'fas fa-headset', 'library' => 'fa-solid' ),
					),
				),
				'title_field' => '{{{ badge_title }}}',
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'luwi-elementor' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'row',
				'options' => array(
					'row'  => __( 'Horizontal Row', 'luwi-elementor' ),
					'grid' => __( 'Grid (2x2)', 'luwi-elementor' ),
				),
			)
		);

		$this->end_controls_section();

		// Style section.
		$this->start_controls_section(
			'style_section',
			array(
				'label' => __( 'Style', 'luwi-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'icon_color',
			array(
				'label'     => __( 'Icon Color', 'luwi-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'var(--luwi-primary)',
				'selectors' => array(
					'{{WRAPPER}} .luwi-trust-badge__icon' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'show_dividers',
			array(
				'label'        => __( 'Show Dividers', 'luwi-elementor' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$badges   = $settings['badges'];
		$layout   = $settings['layout'];
		$dividers = $settings['show_dividers'] === 'yes' ? ' luwi-trust-badges--dividers' : '';

		if ( empty( $badges ) ) {
			return;
		}

		printf( '<div class="luwi-widget luwi-trust-badges luwi-trust-badges--%s%s">', esc_attr( $layout ), esc_attr( $dividers ) );

		foreach ( $badges as $index => $badge ) {
			echo '<div class="luwi-trust-badge">';

			if ( ! empty( $badge['badge_icon']['value'] ) ) {
				echo '<div class="luwi-trust-badge__icon">';
				\Elementor\Icons_Manager::render_icon( $badge['badge_icon'], array( 'aria-hidden' => 'true' ) );
				echo '</div>';
			}

			echo '<div class="luwi-trust-badge__content">';
			if ( ! empty( $badge['badge_title'] ) ) {
				printf( '<strong class="luwi-trust-badge__title">%s</strong>', esc_html( $badge['badge_title'] ) );
			}
			if ( ! empty( $badge['badge_description'] ) ) {
				printf( '<span class="luwi-trust-badge__desc">%s</span>', esc_html( $badge['badge_description'] ) );
			}
			echo '</div>';

			echo '</div>';
		}

		echo '</div>';
	}
}
