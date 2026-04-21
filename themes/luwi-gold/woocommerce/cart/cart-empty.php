<?php
/**
 * Empty cart page.
 *
 * Overrides: woocommerce/templates/cart/cart-empty.php
 * Stitch "Luthier Artisan" — minimal, editorial empty state.
 *
 * @package Luwi_Gold
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_cart_is_empty' );

if ( wc_get_page_id( 'shop' ) > 0 ) : ?>

	<div class="luwi-empty-state">
		<div class="luwi-empty-state__icon" aria-hidden="true">
			<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
				<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
				<path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
			</svg>
		</div>

		<h2 class="luwi-empty-state__title"><?php esc_html_e( 'Your cart is empty', 'luwi-gold' ); ?></h2>
		<p class="luwi-empty-state__text"><?php esc_html_e( 'Looks like you haven\'t added any pieces to your collection yet.', 'luwi-gold' ); ?></p>

		<a class="luwi-btn luwi-btn--primary" href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>">
			<?php esc_html_e( 'Explore Collection', 'luwi-gold' ); ?>
		</a>
	</div>

<?php endif; ?>
