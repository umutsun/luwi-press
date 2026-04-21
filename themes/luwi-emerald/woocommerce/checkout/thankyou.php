<?php
/**
 * Order received / Thank You page.
 *
 * Overrides: woocommerce/templates/checkout/thankyou.php
 * Stitch "Luthier Artisan" — confirmation card, order summary,
 * trust reinforcement.
 *
 * @package Luwi_Emerald
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="woocommerce-order luwi-thankyou">

	<?php
	if ( $order ) :

		do_action( 'woocommerce_before_thankyou', $order->get_id() );
		?>

		<?php if ( $order->has_status( 'failed' ) ) : ?>

			<div class="luwi-thankyou__status luwi-thankyou__status--failed">
				<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed">
					<?php esc_html_e( 'Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again.', 'luwi-emerald' ); ?>
				</p>

				<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed-actions">
					<a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="button pay"><?php esc_html_e( 'Pay', 'luwi-emerald' ); ?></a>
					<?php if ( is_user_logged_in() ) : ?>
						<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="button pay"><?php esc_html_e( 'My account', 'luwi-emerald' ); ?></a>
					<?php endif; ?>
				</p>
			</div>

		<?php else : ?>

			<div class="luwi-thankyou__header">
				<div class="luwi-thankyou__icon" aria-hidden="true">
					<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
						<polyline points="22 4 12 14.01 9 11.01"/>
					</svg>
				</div>
				<span class="luwi-section-label"><?php esc_html_e( 'Order Confirmed', 'luwi-emerald' ); ?></span>
				<h2 class="luwi-thankyou__title"><?php esc_html_e( 'Thank you for your order', 'luwi-emerald' ); ?></h2>
				<p class="luwi-thankyou__text">
					<?php esc_html_e( 'Your order has been received and is being processed. You will receive a confirmation email shortly.', 'luwi-emerald' ); ?>
				</p>
			</div>

			<div class="luwi-thankyou__details">
				<div class="luwi-thankyou__detail">
					<span class="luwi-thankyou__detail-label"><?php esc_html_e( 'Order number', 'luwi-emerald' ); ?></span>
					<strong class="luwi-thankyou__detail-value"><?php echo esc_html( $order->get_order_number() ); ?></strong>
				</div>
				<div class="luwi-thankyou__detail">
					<span class="luwi-thankyou__detail-label"><?php esc_html_e( 'Date', 'luwi-emerald' ); ?></span>
					<strong class="luwi-thankyou__detail-value"><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></strong>
				</div>
				<?php if ( is_user_logged_in() && $order->get_user_id() === get_current_user_id() && $order->get_billing_email() ) : ?>
					<div class="luwi-thankyou__detail">
						<span class="luwi-thankyou__detail-label"><?php esc_html_e( 'Email', 'luwi-emerald' ); ?></span>
						<strong class="luwi-thankyou__detail-value"><?php echo esc_html( $order->get_billing_email() ); ?></strong>
					</div>
				<?php endif; ?>
				<div class="luwi-thankyou__detail">
					<span class="luwi-thankyou__detail-label"><?php esc_html_e( 'Total', 'luwi-emerald' ); ?></span>
					<strong class="luwi-thankyou__detail-value"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong>
				</div>
				<?php if ( $order->get_payment_method_title() ) : ?>
					<div class="luwi-thankyou__detail">
						<span class="luwi-thankyou__detail-label"><?php esc_html_e( 'Payment method', 'luwi-emerald' ); ?></span>
						<strong class="luwi-thankyou__detail-value"><?php echo esc_html( wp_kses_post( $order->get_payment_method_title() ) ); ?></strong>
					</div>
				<?php endif; ?>
			</div>

		<?php endif; ?>

		<?php do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() ); ?>
		<?php do_action( 'woocommerce_thankyou', $order->get_id() ); ?>

	<?php else : ?>

		<div class="luwi-thankyou__header">
			<span class="luwi-section-label"><?php esc_html_e( 'Order Confirmed', 'luwi-emerald' ); ?></span>
			<h2 class="luwi-thankyou__title"><?php esc_html_e( 'Thank you. Your order has been received.', 'luwi-emerald' ); ?></h2>
		</div>

	<?php endif; ?>

</div>
