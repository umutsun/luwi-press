<?php
/**
 * Checkout form template.
 *
 * Overrides: woocommerce/templates/checkout/form-checkout.php
 * Stitch "Luthier Artisan" — clean 2-column layout,
 * boxed inputs, gradient CTA, order review panel.
 *
 * @package Luwi_Ruby
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_checkout_form', $checkout );

// If checkout registration is disabled and not logged in, the user cannot checkout.
if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'luwi-ruby' ) ) );
	return;
}
?>

<form name="checkout" method="post" class="checkout woocommerce-checkout luwi-checkout" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data">

	<div class="luwi-checkout__columns">

		<div class="luwi-checkout__main">

			<?php if ( $checkout->get_checkout_fields() ) : ?>

				<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

				<div id="customer_details" class="luwi-checkout__customer">

					<div class="luwi-checkout__section">
						<h3 class="luwi-checkout__section-title"><?php esc_html_e( 'Billing Details', 'luwi-ruby' ); ?></h3>
						<?php do_action( 'woocommerce_checkout_billing' ); ?>
					</div>

					<div class="luwi-checkout__section">
						<h3 class="luwi-checkout__section-title"><?php esc_html_e( 'Shipping Details', 'luwi-ruby' ); ?></h3>
						<?php do_action( 'woocommerce_checkout_shipping' ); ?>
					</div>

				</div>

				<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>

			<?php endif; ?>

		</div>

		<div class="luwi-checkout__sidebar">

			<?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>

			<h3 id="order_review_heading" class="luwi-checkout__section-title"><?php esc_html_e( 'Your Order', 'luwi-ruby' ); ?></h3>

			<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>

			<div id="order_review" class="woocommerce-checkout-review-order luwi-checkout__review">
				<?php do_action( 'woocommerce_checkout_order_review' ); ?>
			</div>

			<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>

		</div>

	</div>

</form>

<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
