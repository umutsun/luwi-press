<?php
/**
 * View Order detail page.
 *
 * Overrides: woocommerce/templates/myaccount/view-order.php
 * Stitch "Luthier Artisan" — order detail card, status badge, items list.
 *
 * @package Luwi_Ruby
 */

defined( 'ABSPATH' ) || exit;

$notes = $order->get_customer_order_notes();
?>

<div class="luwi-view-order">

	<div class="luwi-view-order__header">
		<div>
			<span class="luwi-section-label">
				<?php
				printf(
					/* translators: %s: order number */
					esc_html__( 'Order #%s', 'luwi-ruby' ),
					esc_html( $order->get_order_number() )
				);
				?>
			</span>
			<p class="luwi-view-order__date"><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></p>
		</div>
		<span class="luwi-dashboard__order-status luwi-dashboard__order-status--<?php echo esc_attr( $order->get_status() ); ?>">
			<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
		</span>
	</div>

	<?php if ( $notes ) : ?>
		<div class="luwi-view-order__notes">
			<h3><?php esc_html_e( 'Order Updates', 'luwi-ruby' ); ?></h3>
			<ol class="luwi-view-order__notes-list">
				<?php foreach ( $notes as $note ) : ?>
					<li class="luwi-view-order__note">
						<span class="luwi-view-order__note-date"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $note->comment_date ) ) ); ?></span>
						<div class="luwi-view-order__note-content"><?php echo wpautop( wptexturize( wp_kses_post( $note->comment_content ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
	<?php endif; ?>

	<?php do_action( 'woocommerce_view_order', $order_id ); ?>

</div>
