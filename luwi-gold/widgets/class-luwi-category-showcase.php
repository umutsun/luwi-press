<?php
/**
 * Luwi Category Showcase Widget — visual product category cards grid.
 *
 * @package Luwi_Gold
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Luwi_Widget_Category_Showcase extends Luwi_Widget_Base {

	public function get_name() {
		return 'luwi-category-showcase';
	}

	public function get_title() {
		return __( 'Luwi Category Showcase', 'luwi-gold' );
	}

	public function get_icon() {
		return 'eicon-gallery-grid';
	}

	public function get_keywords() {
		return array( 'category', 'showcase', 'grid', 'cards', 'shop', 'luwi' );
	}

	protected function register_controls() {

		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Categories', 'luwi-gold' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'source',
			array(
				'label'   => __( 'Source', 'luwi-gold' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'auto',
				'options' => array(
					'auto'   => __( 'Auto (Top WooCommerce Categories)', 'luwi-gold' ),
					'manual' => __( 'Manual Selection', 'luwi-gold' ),
				),
			)
		);

		$this->add_control(
			'count',
			array(
				'label'     => __( 'Number of Categories', 'luwi-gold' ),
				'type'      => \Elementor\Controls_Manager::NUMBER,
				'default'   => 6,
				'min'       => 2,
				'max'       => 12,
				'condition' => array( 'source' => 'auto' ),
			)
		);

		$this->add_responsive_control(
			'columns',
			array(
				'label'   => __( 'Columns', 'luwi-gold' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '3',
				'options' => array(
					'2' => '2',
					'3' => '3',
					'4' => '4',
					'6' => '6',
				),
				'selectors' => array(
					'{{WRAPPER}} .luwi-category-grid' => 'grid-template-columns: repeat({{VALUE}}, 1fr);',
				),
			)
		);

		$this->add_control(
			'show_count',
			array(
				'label'        => __( 'Show Product Count', 'luwi-gold' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'card_style',
			array(
				'label'   => __( 'Card Style', 'luwi-gold' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'overlay',
				'options' => array(
					'overlay' => __( 'Image with Overlay', 'luwi-gold' ),
					'below'   => __( 'Title Below Image', 'luwi-gold' ),
					'minimal' => __( 'Minimal (Icon + Text)', 'luwi-gold' ),
				),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings   = $this->get_settings_for_display();
		$source     = $settings['source'];
		$card_style = $settings['card_style'];
		$show_count = $settings['show_count'] === 'yes';

		$categories = array();

		if ( $source === 'auto' && function_exists( 'get_terms' ) ) {
			$count = absint( $settings['count'] );
			$terms = get_terms( array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => $count,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'exclude'    => array( get_option( 'default_product_cat', 0 ) ),
			) );

			if ( ! is_wp_error( $terms ) ) {
				$categories = $terms;
			}
		}

		if ( empty( $categories ) ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="luwi-widget-placeholder">';
				echo esc_html__( 'No WooCommerce categories found. Add product categories or switch to manual mode.', 'luwi-gold' );
				echo '</div>';
			}
			return;
		}

		echo '<div class="luwi-widget luwi-category-grid">';

		foreach ( $categories as $cat ) {
			$link      = get_term_link( $cat );
			$thumb_id  = get_term_meta( $cat->term_id, 'thumbnail_id', true );
			$image_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'luwi-blog-card' ) : '';

			printf( '<a href="%s" class="luwi-category-card luwi-category-card--%s">', esc_url( $link ), esc_attr( $card_style ) );

			if ( $card_style !== 'minimal' && $image_url ) {
				printf(
					'<div class="luwi-category-card__image"><img src="%s" alt="%s" loading="lazy"></div>',
					esc_url( $image_url ),
					esc_attr( $cat->name )
				);
			}

			echo '<div class="luwi-category-card__content">';
			printf( '<h3 class="luwi-category-card__title">%s</h3>', esc_html( $cat->name ) );

			if ( $show_count ) {
				printf(
					'<span class="luwi-category-card__count">%s</span>',
					/* translators: %d: product count */
					esc_html( sprintf( _n( '%d product', '%d products', $cat->count, 'luwi-gold' ), $cat->count ) )
				);
			}

			echo '</div>';
			echo '</a>';
		}

		echo '</div>';

		$this->render_luwipress_upsell(
			__( 'With LuwiPress: highlights top-opportunity categories from Knowledge Graph.', 'luwi-gold' )
		);
	}
}
