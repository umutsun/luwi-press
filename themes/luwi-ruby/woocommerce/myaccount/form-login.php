<?php
/**
 * Login / Register form.
 *
 * Overrides: woocommerce/templates/myaccount/form-login.php
 * Stitch "Luthier Artisan" — glassmorphic card, ghost border inputs,
 * gradient CTA, editorial heading.
 *
 * @package Luwi_Ruby
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_customer_login_form' );
?>

<div class="luwi-auth" id="customer_login">

	<div class="luwi-auth__columns <?php if ( 'yes' !== get_option( 'woocommerce_enable_myaccount_registration' ) ) echo 'luwi-auth__columns--single'; ?>">

		<div class="luwi-auth__card luwi-auth__card--login">

			<h2 class="luwi-auth__title"><?php esc_html_e( 'Sign In', 'luwi-ruby' ); ?></h2>

			<form class="woocommerce-form woocommerce-form-login login" method="post">

				<?php do_action( 'woocommerce_login_form_start' ); ?>

				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
					<label for="username"><?php esc_html_e( 'Email address', 'luwi-ruby' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'luwi-ruby' ); ?></span></label>
					<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="username" autocomplete="username" value="<?php echo ( ! empty( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>" required aria-required="true" /><?php // phpcs:ignore WordPress.Security.NonceVerification.Missing ?>
				</p>

				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
					<label for="password"><?php esc_html_e( 'Password', 'luwi-ruby' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'luwi-ruby' ); ?></span></label>
					<input class="woocommerce-Input woocommerce-Input--text input-text" type="password" name="password" id="password" autocomplete="current-password" required aria-required="true" />
				</p>

				<?php do_action( 'woocommerce_login_form' ); ?>

				<p class="form-row luwi-auth__row">
					<label class="woocommerce-form__label woocommerce-form__label-for-checkbox woocommerce-form-login__rememberme">
						<input class="woocommerce-form__input woocommerce-form__input-checkbox" name="rememberme" type="checkbox" id="rememberme" value="forever" />
						<span><?php esc_html_e( 'Remember me', 'luwi-ruby' ); ?></span>
					</label>
					<a class="luwi-auth__forgot" href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Forgot password?', 'luwi-ruby' ); ?></a>
				</p>

				<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>

				<button type="submit" class="woocommerce-button button woocommerce-form-login__submit luwi-btn luwi-btn--primary luwi-btn--full<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="login" value="<?php esc_attr_e( 'Sign In', 'luwi-ruby' ); ?>"><?php esc_html_e( 'Sign In', 'luwi-ruby' ); ?></button>

				<?php do_action( 'woocommerce_login_form_end' ); ?>

			</form>

		</div>

		<?php if ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ) : ?>

			<div class="luwi-auth__card luwi-auth__card--register">

				<h2 class="luwi-auth__title"><?php esc_html_e( 'Create Account', 'luwi-ruby' ); ?></h2>

				<form method="post" class="woocommerce-form woocommerce-form-register register" <?php do_action( 'woocommerce_register_form_tag' ); ?>>

					<?php do_action( 'woocommerce_register_form_start' ); ?>

					<?php if ( 'no' === get_option( 'woocommerce_registration_generate_username' ) ) : ?>
						<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
							<label for="reg_username"><?php esc_html_e( 'Username', 'luwi-ruby' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'luwi-ruby' ); ?></span></label>
							<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="reg_username" autocomplete="username" value="<?php echo ( ! empty( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>" required aria-required="true" /><?php // phpcs:ignore WordPress.Security.NonceVerification.Missing ?>
						</p>
					<?php endif; ?>

					<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
						<label for="reg_email"><?php esc_html_e( 'Email address', 'luwi-ruby' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'luwi-ruby' ); ?></span></label>
						<input type="email" class="woocommerce-Input woocommerce-Input--text input-text" name="email" id="reg_email" autocomplete="email" value="<?php echo ( ! empty( $_POST['email'] ) ) ? esc_attr( wp_unslash( $_POST['email'] ) ) : ''; ?>" required aria-required="true" /><?php // phpcs:ignore WordPress.Security.NonceVerification.Missing ?>
					</p>

					<?php if ( 'no' === get_option( 'woocommerce_registration_generate_password' ) ) : ?>
						<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
							<label for="reg_password"><?php esc_html_e( 'Password', 'luwi-ruby' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'luwi-ruby' ); ?></span></label>
							<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password" id="reg_password" autocomplete="new-password" required aria-required="true" />
						</p>
					<?php else : ?>
						<p class="luwi-auth__note"><?php esc_html_e( 'A link to set a new password will be sent to your email address.', 'luwi-ruby' ); ?></p>
					<?php endif; ?>

					<?php do_action( 'woocommerce_register_form' ); ?>

					<p class="woocommerce-form-row form-row">
						<?php wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' ); ?>
						<button type="submit" class="woocommerce-Button woocommerce-button button woocommerce-form-register__submit luwi-btn luwi-btn--outline luwi-btn--full<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="register" value="<?php esc_attr_e( 'Create Account', 'luwi-ruby' ); ?>"><?php esc_html_e( 'Create Account', 'luwi-ruby' ); ?></button>
					</p>

					<?php do_action( 'woocommerce_register_form_end' ); ?>

				</form>

			</div>

		<?php endif; ?>

	</div>

</div>

<?php do_action( 'woocommerce_after_customer_login_form' ); ?>
