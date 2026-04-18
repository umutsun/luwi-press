<?php
/**
 * My Account — Orders list.
 *
 * Overrides: woocommerce/templates/myaccount/orders.php
 * Stitch "Luthier Artisan" — card-based order list, status badges.
 *
 * @package Luwi_Gold
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_account_orders', $has_orders );
?>

<?php if ( $has_orders ) : ?>

	<div class="luwi-orders">

		<?php
		foreach ( $customer_orders->orders as $customer_order ) {
			$order      = wc_get_order( $customer_order );
			$item_count = $order->get_item_count() - $order->get_item_count_refunded();
			?>
			<div class="luwi-orders__item">

				<div class="luwi-orders__header">
					<div class="luwi-orders__id">
						<strong>
							<?php
							printf(
								/* translators: %s: order number */
								esc_html__( 'Order #%s', 'luwi-gold' ),
								esc_html( $order->get_order_number() )
							);
							?>
						</strong>
						<span class="luwi-orders__date">
							<?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?>
						</span>
					</div>
					<span class="luwi-dashboard__order-status luwi-dashboard__order-status--<?php echo esc_attr( $order->get_status() ); ?>">
						<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
					</span>
				</div>

				<div class="luwi-orders__meta">
					<span class="luwi-orders__count">
						<?php
						printf(
							/* translators: %d: number of items */
							esc_html( _n( '%d item', '%d items', $item_count, 'luwi-gold' ) ),
							absint( $item_count )
						);
						?>
					</span>
					<strong class="luwi-orders__total">
						<?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>
					</strong>
				</div>

				<div class="luwi-orders__actions">
					<?php
					$actions = wc_get_account_orders_actions( $order );
					if ( ! empty( $actions ) ) {
						foreach ( $actions as $key => $action ) {
							printf(
								'<a href="%s" class="luwi-orders__action luwi-orders__action--%s">%s</a>',
								esc_url( $action['url'] ),
								esc_attr( sanitize_html_class( $key ) ),
								esc_html( $action['name'] )
							);
						}
					}
					?>
				</div>

			</div>
			<?php
		}
		?>

	</div>

	<?php do_action( 'woocommerce_before_account_orders_pagination' ); ?>

	<?php if ( 1 < $customer_orders->max_num_pages ) : ?>
		<div class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination">
			<?php if ( 1 !== $current_page ) : ?>
				<a class="woocommerce-button woocommerce-button--previous woocommerce-Button woocommerce-Button--previous button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" href="<?php echo esc_url( wc_get_endpoint_url( 'orders', $current_page - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'luwi-gold' ); ?></a>
			<?php endif; ?>

			<?php if ( intval( $customer_orders->max_num_pages ) !== $current_page ) : ?>
				<a class="woocommerce-button woocommerce-button--next woocommerce-Button woocommerce-Button--next button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" href="<?php echo esc_url( wc_get_endpoint_url( 'orders', $current_page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'luwi-gold' ); ?></a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

<?php else : ?>

	<div class="luwi-empty-state">
		<div class="luwi-empty-state__icon" aria-hidden="true">
			<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
				<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>
			</svg>
		</div>
		<h2 class="luwi-empty-state__title"><?php esc_html_e( 'No orders yet', 'luwi-gold' ); ?></h2>
		<p class="luwi-empty-state__text"><?php esc_html_e( 'Browse our collection and place your first order.', 'luwi-gold' ); ?></p>
		<a class="luwi-btn luwi-btn--primary" href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>">
			<?php esc_html_e( 'Start Shopping', 'luwi-gold' ); ?>
		</a>
	</div>

<?php endif; ?>

<?php do_action( 'woocommerce_after_account_orders', $has_orders ); ?>
