<?php
/**
 * Mini-cart template.
 *
 * Overrides: woocommerce/templates/cart/mini-cart.php
 * Stitch "Luthier Artisan" — glassmorphic slide-out,
 * compact items, gradient checkout CTA.
 *
 * @package Luwi_Gold
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_mini_cart' );
?>

<?php if ( ! WC()->cart->is_empty() ) : ?>

	<ul class="woocommerce-mini-cart cart_list product_list_widget luwi-mini-cart__list <?php echo esc_attr( $args['list_class'] ); ?>">

		<?php
		do_action( 'woocommerce_before_mini_cart_contents' );

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$_product   = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
			$product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );

			if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters( 'woocommerce_widget_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
				$product_name      = apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key );
				$thumbnail         = apply_filters( 'woocommerce_cart_item_thumbnail', $_product->get_image( 'woocommerce_gallery_thumbnail' ), $cart_item, $cart_item_key );
				$product_price     = apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $_product ), $cart_item, $cart_item_key );
				$product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );
				?>
				<li class="woocommerce-mini-cart-item luwi-mini-cart__item <?php echo esc_attr( apply_filters( 'woocommerce_mini_cart_item_class', 'mini_cart_item', $cart_item, $cart_item_key ) ); ?>">
					<?php
					echo apply_filters(
						'woocommerce_cart_item_remove_link',
						sprintf(
							'<a href="%s" class="remove remove_from_cart_button luwi-mini-cart__remove" aria-label="%s" data-product_id="%s" data-cart_item_key="%s" data-product_sku="%s">&times;</a>',
							esc_url( wc_get_cart_remove_url( $cart_item_key ) ),
							/* translators: %s: product name */
							esc_attr( sprintf( __( 'Remove %s from cart', 'luwi-gold' ), wp_strip_all_tags( $product_name ) ) ),
							esc_attr( $product_id ),
							esc_attr( $cart_item_key ),
							esc_attr( $_product->get_sku() )
						),
						$cart_item_key
					);
					?>

					<div class="luwi-mini-cart__thumb">
						<?php if ( empty( $product_permalink ) ) : ?>
							<?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php else : ?>
							<a href="<?php echo esc_url( $product_permalink ); ?>">
								<?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</a>
						<?php endif; ?>
					</div>

					<div class="luwi-mini-cart__info">
						<span class="luwi-mini-cart__name">
							<?php if ( empty( $product_permalink ) ) : ?>
								<?php echo wp_kses_post( $product_name ); ?>
							<?php else : ?>
								<a href="<?php echo esc_url( $product_permalink ); ?>"><?php echo wp_kses_post( $product_name ); ?></a>
							<?php endif; ?>
						</span>
						<?php echo wc_get_formatted_cart_item_data( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo apply_filters( 'woocommerce_widget_cart_item_quantity', '<span class="quantity">' . sprintf( '%s &times; %s', $cart_item['quantity'], $product_price ) . '</span>', $cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</li>
				<?php
			}
		}

		do_action( 'woocommerce_mini_cart_contents' );
		?>

	</ul>

	<div class="luwi-mini-cart__footer">
		<div class="luwi-mini-cart__total">
			<span class="luwi-mini-cart__total-label"><?php esc_html_e( 'Subtotal', 'luwi-gold' ); ?></span>
			<span class="luwi-mini-cart__total-amount"><?php echo WC()->cart->get_cart_subtotal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		</div>

		<?php do_action( 'woocommerce_widget_shopping_cart_before_buttons' ); ?>

		<div class="luwi-mini-cart__buttons woocommerce-mini-cart__buttons buttons">
			<?php do_action( 'woocommerce_widget_shopping_cart_buttons' ); ?>
		</div>

		<?php do_action( 'woocommerce_widget_shopping_cart_after_buttons' ); ?>
	</div>

<?php else : ?>

	<div class="luwi-mini-cart__empty">
		<p class="woocommerce-mini-cart__empty-message"><?php esc_html_e( 'No items yet', 'luwi-gold' ); ?></p>
	</div>

<?php endif; ?>

<?php do_action( 'woocommerce_after_mini_cart' ); ?>
