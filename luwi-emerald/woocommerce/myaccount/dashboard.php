<?php
/**
 * My Account Dashboard.
 *
 * Overrides: woocommerce/templates/myaccount/dashboard.php
 * Stitch "hesab_m" reference — bento card grid, welcome summary,
 * quick links, recent orders preview.
 *
 * @package Luwi_Emerald
 */

defined( 'ABSPATH' ) || exit;

$current_user = wp_get_current_user();
?>

<div class="luwi-dashboard">

	<div class="luwi-dashboard__welcome">
		<div class="luwi-dashboard__avatar">
			<?php echo get_avatar( $current_user->ID, 80, '', '', array( 'class' => 'luwi-dashboard__avatar-img' ) ); ?>
		</div>
		<div class="luwi-dashboard__greeting">
			<span class="luwi-section-label"><?php esc_html_e( 'Account', 'luwi-emerald' ); ?></span>
			<h2 class="luwi-dashboard__name">
				<?php
				printf(
					/* translators: %s: customer display name */
					esc_html__( 'Welcome, %s', 'luwi-emerald' ),
					esc_html( $current_user->display_name )
				);
				?>
			</h2>
			<p class="luwi-dashboard__email"><?php echo esc_html( $current_user->user_email ); ?></p>
		</div>
	</div>

	<div class="luwi-dashboard__grid">

		<a href="<?php echo esc_url( wc_get_endpoint_url( 'orders' ) ); ?>" class="luwi-dashboard__card">
			<div class="luwi-dashboard__card-icon" aria-hidden="true">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
					<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>
				</svg>
			</div>
			<h3 class="luwi-dashboard__card-title"><?php esc_html_e( 'Orders', 'luwi-emerald' ); ?></h3>
			<p class="luwi-dashboard__card-desc"><?php esc_html_e( 'Track, return, or repurchase', 'luwi-emerald' ); ?></p>
		</a>

		<a href="<?php echo esc_url( wc_get_endpoint_url( 'downloads' ) ); ?>" class="luwi-dashboard__card">
			<div class="luwi-dashboard__card-icon" aria-hidden="true">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
					<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
				</svg>
			</div>
			<h3 class="luwi-dashboard__card-title"><?php esc_html_e( 'Downloads', 'luwi-emerald' ); ?></h3>
			<p class="luwi-dashboard__card-desc"><?php esc_html_e( 'Access your digital files', 'luwi-emerald' ); ?></p>
		</a>

		<a href="<?php echo esc_url( wc_get_endpoint_url( 'edit-address' ) ); ?>" class="luwi-dashboard__card">
			<div class="luwi-dashboard__card-icon" aria-hidden="true">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
					<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>
				</svg>
			</div>
			<h3 class="luwi-dashboard__card-title"><?php esc_html_e( 'Addresses', 'luwi-emerald' ); ?></h3>
			<p class="luwi-dashboard__card-desc"><?php esc_html_e( 'Billing & shipping addresses', 'luwi-emerald' ); ?></p>
		</a>

		<a href="<?php echo esc_url( wc_get_endpoint_url( 'edit-account' ) ); ?>" class="luwi-dashboard__card">
			<div class="luwi-dashboard__card-icon" aria-hidden="true">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
					<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
				</svg>
			</div>
			<h3 class="luwi-dashboard__card-title"><?php esc_html_e( 'Account Details', 'luwi-emerald' ); ?></h3>
			<p class="luwi-dashboard__card-desc"><?php esc_html_e( 'Name, email & password', 'luwi-emerald' ); ?></p>
		</a>

	</div>

	<?php
	// Recent orders preview.
	$customer_orders = wc_get_orders(
		array(
			'customer' => $current_user->ID,
			'limit'    => 3,
			'orderby'  => 'date',
			'order'    => 'DESC',
		)
	);

	if ( ! empty( $customer_orders ) ) :
		?>
		<div class="luwi-dashboard__recent">
			<div class="luwi-dashboard__recent-header">
				<h3 class="luwi-dashboard__recent-title"><?php esc_html_e( 'Recent Orders', 'luwi-emerald' ); ?></h3>
				<a href="<?php echo esc_url( wc_get_endpoint_url( 'orders' ) ); ?>" class="luwi-dashboard__recent-link"><?php esc_html_e( 'View All', 'luwi-emerald' ); ?></a>
			</div>

			<?php foreach ( $customer_orders as $order ) : ?>
				<div class="luwi-dashboard__order">
					<div class="luwi-dashboard__order-info">
						<strong class="luwi-dashboard__order-number">
							<?php
							printf(
								/* translators: %s: order number */
								esc_html__( '#%s', 'luwi-emerald' ),
								esc_html( $order->get_order_number() )
							);
							?>
						</strong>
						<span class="luwi-dashboard__order-date">
							<?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?>
						</span>
					</div>
					<div class="luwi-dashboard__order-meta">
						<span class="luwi-dashboard__order-status luwi-dashboard__order-status--<?php echo esc_attr( $order->get_status() ); ?>">
							<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
						</span>
						<strong class="luwi-dashboard__order-total">
							<?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>
						</strong>
					</div>
				</div>
			<?php endforeach; ?>

		</div>
	<?php endif; ?>

	<?php
	/**
	 * My Account dashboard.
	 *
	 * @since 2.6.0
	 */
	do_action( 'woocommerce_account_dashboard' );

	/**
	 * Deprecated woocommerce_before_my_account action.
	 *
	 * @deprecated 2.6.0
	 */
	do_action( 'woocommerce_before_my_account' );

	/**
	 * Deprecated woocommerce_after_my_account action.
	 *
	 * @deprecated 2.6.0
	 */
	do_action( 'woocommerce_after_my_account' );
	?>

</div>
