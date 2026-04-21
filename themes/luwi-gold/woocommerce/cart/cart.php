<?php
/**
 * Cart page template.
 *
 * Overrides: woocommerce/templates/cart/cart.php
 * Stitch "sepetim" reference — 2:1 grid, sticky summary sidebar,
 * horizontal cart items, glassmorphic totals panel.
 *
 * @package Luwi_Gold
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_cart' );
?>

<form class="woocommerce-cart-form" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">

	<?php do_action( 'woocommerce_before_cart_table' ); ?>

	<div class="luwi-cart-items">

		<h2 class="luwi-cart-items__heading">
			<?php
			printf(
				/* translators: %d: number of items in cart */
				esc_html( _n( 'Your Cart (%d item)', 'Your Cart (%d items)', WC()->cart->get_cart_contents_count(), 'luwi-gold' ) ),
				absint( WC()->cart->get_cart_contents_count() )
			);
			?>
		</h2>

		<?php do_action( 'woocommerce_before_cart_contents' ); ?>

		<?php
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$_product   = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
			$product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );

			if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
				$product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );
				?>
				<div class="luwi-cart-item <?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>">

					<div class="luwi-cart-item__image">
						<?php
						$thumbnail = apply_filters( 'woocommerce_cart_item_thumbnail', $_product->get_image( 'luwi-product-card' ), $cart_item, $cart_item_key );
						if ( ! $product_permalink ) {
							echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							printf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $thumbnail ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</div>

					<div class="luwi-cart-item__details">
						<div class="luwi-cart-item__info">
							<h3 class="luwi-cart-item__name">
								<?php
								if ( ! $product_permalink ) {
									echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key ) . '&nbsp;' );
								} else {
									echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', sprintf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $_product->get_name() ), $cart_item, $cart_item_key ) );
								}
								?>
							</h3>

							<?php
							// Variation data / meta.
							echo wc_get_formatted_cart_item_data( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>

							<div class="luwi-cart-item__price">
								<?php
								echo apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $_product ), $cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							</div>
						</div>

						<div class="luwi-cart-item__actions">
							<div class="luwi-cart-item__quantity">
								<?php
								if ( $_product->is_sold_individually() ) {
									$min_quantity = 1;
									$max_quantity = 1;
								} else {
									$min_quantity = 0;
									$max_quantity = $_product->get_max_purchase_quantity();
								}

								$product_quantity = woocommerce_quantity_input(
									array(
										'input_name'   => "cart[{$cart_item_key}][qty]",
										'input_value'  => $cart_item['quantity'],
										'max_value'    => $max_quantity,
										'min_value'    => $min_quantity,
										'product_name' => $_product->get_name(),
									),
									$_product,
									false
								);

								echo apply_filters( 'woocommerce_cart_item_quantity', $product_quantity, $cart_item_key, $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							</div>

							<div class="luwi-cart-item__subtotal">
								<?php
								echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							</div>

							<?php
							echo apply_filters(
								'woocommerce_cart_item_remove_link',
								sprintf(
									'<a href="%s" class="luwi-cart-item__remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">&times;</a>',
									esc_url( wc_get_cart_remove_url( $cart_item_key ) ),
									/* translators: %s: product name */
									esc_attr( sprintf( __( 'Remove %s from cart', 'luwi-gold' ), wp_strip_all_tags( $_product->get_name() ) ) ),
									esc_attr( $product_id ),
									esc_attr( $_product->get_sku() )
								),
								$cart_item_key
							);
							?>
						</div>
					</div>

				</div>
				<?php
			}
		}
		?>

		<?php do_action( 'woocommerce_cart_contents' ); ?>

		<div class="luwi-cart-actions">
			<div class="luwi-cart-coupon coupon">
				<label for="coupon_code" class="screen-reader-text"><?php esc_html_e( 'Coupon:', 'luwi-gold' ); ?></label>
				<input type="text" name="coupon_code" class="input-text" id="coupon_code" value="" placeholder="<?php esc_attr_e( 'Gift card or discount code', 'luwi-gold' ); ?>" />
				<button type="submit" class="button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="apply_coupon" value="<?php esc_attr_e( 'Apply', 'luwi-gold' ); ?>"><?php esc_html_e( 'Apply', 'luwi-gold' ); ?></button>
				<?php do_action( 'woocommerce_cart_coupon' ); ?>
			</div>

			<button type="submit" class="button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="update_cart" value="<?php esc_attr_e( 'Update cart', 'luwi-gold' ); ?>"><?php esc_html_e( 'Update cart', 'luwi-gold' ); ?></button>

			<?php do_action( 'woocommerce_cart_actions' ); ?>

			<?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
		</div>

		<?php do_action( 'woocommerce_after_cart_contents' ); ?>

	</div>

	<?php do_action( 'woocommerce_after_cart_table' ); ?>

</form>

<?php do_action( 'woocommerce_before_cart_collaterals' ); ?>

<div class="cart-collaterals">
	<?php
	/**
	 * woocommerce_cart_collaterals hook.
	 *
	 * @hooked woocommerce_cross_sell_display
	 * @hooked woocommerce_cart_totals - 10
	 */
	do_action( 'woocommerce_cart_collaterals' );
	?>
</div>

<?php do_action( 'woocommerce_after_cart' ); ?>
