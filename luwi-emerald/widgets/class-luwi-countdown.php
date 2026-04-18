<?php
/**
 * Luwi Countdown Timer Widget — sale/event countdown.
 *
 * @package Luwi_Emerald
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Luwi_Widget_Countdown extends Luwi_Widget_Base {

	public function get_name() {
		return 'luwi-countdown';
	}

	public function get_title() {
		return __( 'Luwi Countdown', 'luwi-emerald' );
	}

	public function get_icon() {
		return 'eicon-countdown';
	}

	public function get_keywords() {
		return array( 'countdown', 'timer', 'sale', 'event', 'luwi' );
	}

	public function get_script_depends() {
		return array( 'luwi-theme' );
	}

	protected function register_controls() {

		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Countdown', 'luwi-emerald' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'target_date',
			array(
				'label'   => __( 'Target Date', 'luwi-emerald' ),
				'type'    => \Elementor\Controls_Manager::DATE_TIME,
				'default' => gmdate( 'Y-m-d H:i', strtotime( '+7 days' ) ),
			)
		);

		$this->add_control(
			'label_text',
			array(
				'label'   => __( 'Label', 'luwi-emerald' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Sale ends in', 'luwi-emerald' ),
			)
		);

		$this->add_control(
			'expired_text',
			array(
				'label'   => __( 'Expired Text', 'luwi-emerald' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'This offer has ended', 'luwi-emerald' ),
			)
		);

		$this->add_control(
			'show_days',
			array(
				'label'        => __( 'Show Days', 'luwi-emerald' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->end_controls_section();

		// Style.
		$this->start_controls_section(
			'style_section',
			array(
				'label' => __( 'Style', 'luwi-emerald' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'number_color',
			array(
				'label'     => __( 'Number Color', 'luwi-emerald' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'var(--luwi-text)',
				'selectors' => array(
					'{{WRAPPER}} .luwi-countdown__number' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'bg_color',
			array(
				'label'     => __( 'Box Background', 'luwi-emerald' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'var(--luwi-surface)',
				'selectors' => array(
					'{{WRAPPER}} .luwi-countdown__block' => 'background: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings    = $this->get_settings_for_display();
		$target      = $settings['target_date'];
		$label       = $settings['label_text'];
		$expired     = $settings['expired_text'];
		$show_days   = $settings['show_days'] === 'yes';

		?>
		<div class="luwi-widget luwi-countdown"
			data-luwi-countdown
			data-target="<?php echo esc_attr( $target ); ?>"
			data-expired="<?php echo esc_attr( $expired ); ?>">

			<?php if ( $label ) : ?>
				<div class="luwi-countdown__label"><?php echo esc_html( $label ); ?></div>
			<?php endif; ?>

			<div class="luwi-countdown__timer">
				<?php if ( $show_days ) : ?>
				<div class="luwi-countdown__block">
					<span class="luwi-countdown__number" data-days>00</span>
					<span class="luwi-countdown__unit"><?php esc_html_e( 'Days', 'luwi-emerald' ); ?></span>
				</div>
				<span class="luwi-countdown__sep">:</span>
				<?php endif; ?>
				<div class="luwi-countdown__block">
					<span class="luwi-countdown__number" data-hours>00</span>
					<span class="luwi-countdown__unit"><?php esc_html_e( 'Hours', 'luwi-emerald' ); ?></span>
				</div>
				<span class="luwi-countdown__sep">:</span>
				<div class="luwi-countdown__block">
					<span class="luwi-countdown__number" data-minutes>00</span>
					<span class="luwi-countdown__unit"><?php esc_html_e( 'Min', 'luwi-emerald' ); ?></span>
				</div>
				<span class="luwi-countdown__sep">:</span>
				<div class="luwi-countdown__block">
					<span class="luwi-countdown__number" data-seconds>00</span>
					<span class="luwi-countdown__unit"><?php esc_html_e( 'Sec', 'luwi-emerald' ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}
}
