<?php
/**
 * Product card template for WooCommerce shop/archive loops.
 *
 * Overrides: woocommerce/templates/content-product.php
 * Stitch "Luthier Artisan" design: No-Line Rule, Playfair heading,
 * image zoom hover, gradient CTA, sale badge.
 *
 * @package Luwi_Elementor
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( ! is_a( $product, 'WC_Product' ) || ! $product->is_visible() ) {
	return;
}
?>
<li <?php wc_product_class( 'luwi-product-loop-card', $product ); ?>>

	<a href="<?php echo esc_url( $product->get_permalink() ); ?>" class="woocommerce-loop-product__link luwi-img-zoom">

		<?php
		// Sale badge.
		if ( $product->is_on_sale() ) {
			$regular = (float) $product->get_regular_price();
			$sale    = (float) $product->get_sale_price();

			if ( $regular > 0 && $sale > 0 ) {
				$percent = round( ( ( $regular - $sale ) / $regular ) * 100 );
				printf(
					'<span class="luwi-product-card__badge">-%d%%</span>',
					absint( $percent )
				);
			} else {
				echo '<span class="luwi-product-card__badge">' . esc_html__( 'Sale', 'luwi-elementor' ) . '</span>';
			}
		}
		?>

		<?php
		// Product image.
		$image_id  = $product->get_image_id();
		$image_url = $image_id
			? wp_get_attachment_image_url( $image_id, 'luwi-product-card' )
			: wc_placeholder_img_src( 'luwi-product-card' );
		$image_alt = $image_id
			? get_post_meta( $image_id, '_wp_attachment_image_alt', true )
			: $product->get_name();

		printf(
			'<img src="%s" alt="%s" class="attachment-luwi-product-card" loading="lazy">',
			esc_url( $image_url ),
			esc_attr( $image_alt )
		);
		?>

	</a>

	<div class="luwi-product-loop-card__body">

		<?php
		// Category label.
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) :
			?>
			<span class="luwi-product-card__category"><?php echo esc_html( $terms[0]->name ); ?></span>
		<?php endif; ?>

		<a href="<?php echo esc_url( $product->get_permalink() ); ?>" class="woocommerce-loop-product__link">
			<?php
			// Product title — Playfair Display.
			echo '<h2 class="woocommerce-loop-product__title">' . esc_html( $product->get_name() ) . '</h2>';
			?>
		</a>

		<?php
		// Star rating.
		if ( $product->get_average_rating() > 0 ) {
			echo wc_get_rating_html( $product->get_average_rating(), $product->get_review_count() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>

		<?php
		// Price.
		if ( $product->get_price_html() ) {
			echo '<div class="price">' . wp_kses_post( $product->get_price_html() ) . '</div>';
		}
		?>

		<?php
		// Add to Cart CTA — only for simple, in-stock products.
		if ( $product->is_purchasable() && $product->is_in_stock() && $product->is_type( 'simple' ) ) {
			printf(
				'<a href="%s" data-quantity="1" data-product_id="%d" data-product_sku="%s" class="button product_type_simple add_to_cart_button ajax_add_to_cart" aria-label="%s">%s</a>',
				esc_url( $product->add_to_cart_url() ),
				absint( $product->get_id() ),
				esc_attr( $product->get_sku() ),
				/* translators: %s: product name */
				esc_attr( sprintf( __( 'Add &ldquo;%s&rdquo; to your cart', 'luwi-elementor' ), $product->get_name() ) ),
				esc_html( $product->add_to_cart_text() )
			);
		} elseif ( ! $product->is_type( 'simple' ) ) {
			printf(
				'<a href="%s" class="button product_type_%s" aria-label="%s">%s</a>',
				esc_url( $product->get_permalink() ),
				esc_attr( $product->get_type() ),
				/* translators: %s: product name */
				esc_attr( sprintf( __( 'View &ldquo;%s&rdquo;', 'luwi-elementor' ), $product->get_name() ) ),
				esc_html( $product->add_to_cart_text() )
			);
		}
		?>

	</div>

</li>
