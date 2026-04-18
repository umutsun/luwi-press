<?php
/**
 * Luwi Product Card Widget — advanced WooCommerce product grid.
 *
 * Stitch "Featured Curations" design: hover zoom, Playfair heading,
 * gradient CTA, sale badge, overlay/below card styles.
 *
 * @package Luwi_Emerald
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Luwi_Widget_Product_Card extends Luwi_Widget_Base {

	public function get_name() {
		return 'luwi-product-card';
	}

	public function get_title() {
		return __( 'Luwi Product Card', 'luwi-emerald' );
	}

	public function get_icon() {
		return 'eicon-products';
	}

	public function get_keywords() {
		return array( 'product', 'card', 'grid', 'shop', 'woocommerce', 'luwi' );
	}

	protected function register_controls() {

		/* ---------------------------------------------------------------
		 * Content Tab — Query
		 * ------------------------------------------------------------- */
		$this->start_controls_section(
			'query_section',
			array(
				'label' => __( 'Query', 'luwi-emerald' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'source',
			array(
				'label'   => __( 'Source', 'luwi-emerald' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'latest',
				'options' => array(
					'latest'   => __( 'Latest Products', 'luwi-emerald' ),
					'featured' => __( 'Featured', 'luwi-emerald' ),
					'sale'     => __( 'On Sale', 'luwi-emerald' ),
					'best'     => __( 'Best Selling', 'luwi-emerald' ),
					'category' => __( 'By Category', 'luwi-emerald' ),
				),
			)
		);

		$this->add_control(
			'category',
			array(
				'label'     => __( 'Category', 'luwi-emerald' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => '',
				'condition' => array( 'source' => 'category' ),
				'description' => __( 'Enter category slug', 'luwi-emerald' ),
			)
		);

		$this->add_control(
			'count',
			array(
				'label'   => __( 'Number of Products', 'luwi-emerald' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 6,
				'min'     => 1,
				'max'     => 24,
			)
		);

		$this->add_responsive_control(
			'columns',
			array(
				'label'   => __( 'Columns', 'luwi-emerald' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '3',
				'options' => array(
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
				),
				'selectors' => array(
					'{{WRAPPER}} .luwi-product-grid' => 'grid-template-columns: repeat({{VALUE}}, 1fr);',
				),
			)
		);

		$this->end_controls_section();

		/* ---------------------------------------------------------------
		 * Content Tab — Card Options
		 * ------------------------------------------------------------- */
		$this->start_controls_section(
			'card_section',
			array(
				'label' => __( 'Card', 'luwi-emerald' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'card_style',
			array(
				'label'   => __( 'Card Style', 'luwi-emerald' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'below',
				'options' => array(
					'below'   => __( 'Title Below Image', 'luwi-emerald' ),
					'overlay' => __( 'Image with Overlay', 'luwi-emerald' ),
				),
			)
		);

		$this->add_control(
			'show_badge',
			array(
				'label'        => __( 'Show Sale Badge', 'luwi-emerald' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'show_category',
			array(
				'label'        => __( 'Show Category', 'luwi-emerald' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'show_rating',
			array(
				'label'        => __( 'Show Rating', 'luwi-emerald' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'show_cta',
			array(
				'label'        => __( 'Show Add to Cart Button', 'luwi-emerald' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'cta_text',
			array(
				'label'     => __( 'Button Text', 'luwi-emerald' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Add to Cart', 'luwi-emerald' ),
				'condition' => array( 'show_cta' => 'yes' ),
			)
		);

		$this->add_control(
			'show_quick_view',
			array(
				'label'        => __( 'Show Quick View on Hover', 'luwi-emerald' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
			)
		);

		$this->end_controls_section();

		/* ---------------------------------------------------------------
		 * Style Tab — Card
		 * ------------------------------------------------------------- */
		$this->start_controls_section(
			'style_card_section',
			array(
				'label' => __( 'Card Style', 'luwi-emerald' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'card_bg',
			array(
				'label'     => __( 'Card Background', 'luwi-emerald' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .luwi-product-card' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'card_padding',
			array(
				'label'      => __( 'Card Body Padding', 'luwi-emerald' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .luwi-product-card__body' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'grid_gap',
			array(
				'label'      => __( 'Grid Gap', 'luwi-emerald' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array( 'min' => 0, 'max' => 80 ),
				),
				'selectors'  => array(
					'{{WRAPPER}} .luwi-product-grid' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="luwi-widget-placeholder">';
				echo esc_html__( 'WooCommerce is required for this widget.', 'luwi-emerald' );
				echo '</div>';
			}
			return;
		}

		$settings   = $this->get_settings_for_display();
		$card_style = $settings['card_style'];

		$args = array(
			'limit'  => absint( $settings['count'] ),
			'status' => 'publish',
			'return' => 'objects',
		);

		switch ( $settings['source'] ) {
			case 'featured':
				$args['featured'] = true;
				break;
			case 'sale':
				$args['include'] = wc_get_product_ids_on_sale();
				if ( empty( $args['include'] ) ) {
					$args['include'] = array( 0 );
				}
				break;
			case 'best':
				$args['orderby']  = 'popularity';
				break;
			case 'category':
				if ( ! empty( $settings['category'] ) ) {
					$args['category'] = array( sanitize_text_field( $settings['category'] ) );
				}
				break;
			default:
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
		}

		$products = wc_get_products( $args );

		if ( empty( $products ) ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="luwi-widget-placeholder">';
				echo esc_html__( 'No products found. Add products or change the query settings.', 'luwi-emerald' );
				echo '</div>';
			}
			return;
		}

		echo '<div class="luwi-widget luwi-product-grid">';

		foreach ( $products as $product ) {
			$this->render_product_card( $product, $settings );
		}

		echo '</div>';

		$this->render_luwipress_upsell(
			__( 'With LuwiPress: AI-recommended products from Knowledge Graph.', 'luwi-emerald' )
		);
	}

	/**
	 * Render a single product card.
	 *
	 * @param WC_Product $product  Product object.
	 * @param array      $settings Widget settings.
	 */
	private function render_product_card( $product, $settings ) {
		$card_style  = $settings['card_style'];
		$show_badge  = $settings['show_badge'] === 'yes';
		$show_cat    = $settings['show_category'] === 'yes';
		$show_rating = $settings['show_rating'] === 'yes';
		$show_cta    = $settings['show_cta'] === 'yes';
		$show_qv     = $settings['show_quick_view'] === 'yes';

		$permalink   = $product->get_permalink();
		$image_id    = $product->get_image_id();
		$image_url   = $image_id ? wp_get_attachment_image_url( $image_id, 'luwi-product-card' ) : wc_placeholder_img_src( 'luwi-product-card' );
		$image_alt   = $image_id ? get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : $product->get_name();

		$classes = 'luwi-product-card';
		if ( 'overlay' === $card_style ) {
			$classes .= ' luwi-product-card--overlay';
		}

		printf( '<div class="%s">', esc_attr( $classes ) );

		// Image container.
		echo '<a href="' . esc_url( $permalink ) . '" class="luwi-product-card__image">';
		printf( '<img src="%s" alt="%s" loading="lazy">', esc_url( $image_url ), esc_attr( $image_alt ) );

		// Sale badge.
		if ( $show_badge && $product->is_on_sale() ) {
			$regular = (float) $product->get_regular_price();
			$sale    = (float) $product->get_sale_price();
			if ( $regular > 0 && $sale > 0 ) {
				$percent = round( ( ( $regular - $sale ) / $regular ) * 100 );
				printf(
					'<span class="luwi-product-card__badge">-%d%%</span>',
					absint( $percent )
				);
			} else {
				echo '<span class="luwi-product-card__badge">' . esc_html__( 'Sale', 'luwi-emerald' ) . '</span>';
			}
		}

		// Quick view overlay.
		if ( $show_qv ) {
			echo '<div class="luwi-product-card__quick-view">';
			printf(
				'<button class="luwi-product-card__quick-view-btn" data-product-id="%d" aria-label="%s">%s</button>',
				absint( $product->get_id() ),
				esc_attr__( 'Quick view', 'luwi-emerald' ),
				esc_html__( 'Quick View', 'luwi-emerald' )
			);
			echo '</div>';
		}

		echo '</a>';

		// Card body.
		echo '<div class="luwi-product-card__body">';

		// Category label.
		if ( $show_cat ) {
			$terms = get_the_terms( $product->get_id(), 'product_cat' );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$cat = $terms[0];
				printf( '<span class="luwi-product-card__category">%s</span>', esc_html( $cat->name ) );
			}
		}

		// Title.
		printf(
			'<a href="%s" class="luwi-product-card__title-link"><h3 class="luwi-product-card__title">%s</h3></a>',
			esc_url( $permalink ),
			esc_html( $product->get_name() )
		);

		// Rating.
		if ( $show_rating && $product->get_average_rating() > 0 ) {
			$rating = $product->get_average_rating();
			$count  = $product->get_review_count();
			echo '<div class="luwi-product-card__rating">';
			for ( $i = 1; $i <= 5; $i++ ) {
				if ( $i <= round( $rating ) ) {
					echo '<span aria-hidden="true">&#9733;</span>';
				} else {
					echo '<span aria-hidden="true" style="opacity:0.3">&#9733;</span>';
				}
			}
			if ( $count > 0 ) {
				printf( '<span class="luwi-product-card__rating-count">(%d)</span>', absint( $count ) );
			}
			echo '</div>';
		}

		// Price.
		echo '<div class="luwi-product-card__price">' . wp_kses_post( $product->get_price_html() ) . '</div>';

		// CTA button.
		if ( $show_cta && $product->is_purchasable() && $product->is_in_stock() ) {
			$cta_text = ! empty( $settings['cta_text'] ) ? $settings['cta_text'] : __( 'Add to Cart', 'luwi-emerald' );
			printf(
				'<a href="%s" data-quantity="1" data-product_id="%d" class="luwi-product-card__cta add_to_cart_button ajax_add_to_cart" aria-label="%s">%s</a>',
				esc_url( $product->add_to_cart_url() ),
				absint( $product->get_id() ),
				/* translators: %s: product name */
				esc_attr( sprintf( __( 'Add "%s" to cart', 'luwi-emerald' ), $product->get_name() ) ),
				esc_html( $cta_text )
			);
		}

		echo '</div>'; // .luwi-product-card__body
		echo '</div>'; // .luwi-product-card
	}
}
