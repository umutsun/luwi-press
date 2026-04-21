<?php
/**
 * Edit Address form.
 *
 * Overrides: woocommerce/templates/myaccount/form-edit-address.php
 * Stitch "Luthier Artisan" — card form, ghost border inputs.
 *
 * @package Luwi_Ruby
 */

defined( 'ABSPATH' ) || exit;

$page_title = ( 'billing' === $load_address ) ? esc_html__( 'Billing Address', 'luwi-ruby' ) : esc_html__( 'Shipping Address', 'luwi-ruby' );

do_action( 'woocommerce_before_edit_account_address_form' );

if ( ! $load_address ) {
	wc_get_template( 'myaccount/my-address.php' );
} else {
	?>
	<div class="luwi-edit-address">

		<h2 class="luwi-edit-address__title"><?php echo esc_html( $page_title ); ?></h2>

		<form method="post">

			<div class="woocommerce-address-fields">
				<?php do_action( "woocommerce_before_edit_address_form_{$load_address}" ); ?>

				<div class="woocommerce-address-fields__field-wrapper">
					<?php
					foreach ( $address as $key => $field ) {
						woocommerce_form_field( $key, $field, wc_get_post_data_by_key( $key, $field['value'] ) );
					}
					?>
				</div>

				<?php do_action( "woocommerce_after_edit_address_form_{$load_address}" ); ?>

				<p>
					<button type="submit" class="button luwi-btn luwi-btn--primary<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="save_address" value="<?php esc_attr_e( 'Save address', 'luwi-ruby' ); ?>"><?php esc_html_e( 'Save address', 'luwi-ruby' ); ?></button>
					<?php wp_nonce_field( 'woocommerce-edit_address', 'woocommerce-edit-address-nonce' ); ?>
					<input type="hidden" name="action" value="edit_address" />
				</p>
			</div>

		</form>

	</div>
	<?php
}

do_action( 'woocommerce_after_edit_account_address_form' );
?>
