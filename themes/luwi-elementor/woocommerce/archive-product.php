<?php
/**
 * Shop / Product Archive page template.
 *
 * Overrides: woocommerce/templates/archive-product.php
 * Stitch "Luthier Artisan" — editorial shop header, grid + optional sidebar.
 *
 * @package Luwi_Elementor
 */

defined( 'ABSPATH' ) || exit;

get_header( 'shop' );

/**
 * woocommerce_before_main_content hook.
 *
 * @hooked woocommerce_output_content_wrapper - 10 (outputs opening divs)
 * @hooked woocommerce_breadcrumb - 20
 * @hooked WC_Structured_Data::generate_website_data() - 30
 */
do_action( 'woocommerce_before_main_content' );
?>

<div class="luwi-shop-page luwi-container">

	<?php if ( apply_filters( 'woocommerce_show_page_title', true ) ) : ?>
		<header class="luwi-shop-header">
			<span class="luwi-section-label"><?php esc_html_e( 'Collection', 'luwi-elementor' ); ?></span>
			<h1 class="woocommerce-products-header__title page-title luwi-shop-header__title">
				<?php woocommerce_page_title(); ?>
			</h1>
			<?php
			/**
			 * woocommerce_archive_description hook.
			 *
			 * @hooked woocommerce_taxonomy_archive_description - 10
			 * @hooked woocommerce_product_archive_description - 10
			 */
			do_action( 'woocommerce_archive_description' );
			?>
		</header>
	<?php endif; ?>

	<div class="luwi-shop-layout">

		<?php if ( is_active_sidebar( 'shop-sidebar' ) ) : ?>
			<aside class="luwi-shop-sidebar" role="complementary" aria-label="<?php esc_attr_e( 'Shop filters', 'luwi-elementor' ); ?>">
				<?php dynamic_sidebar( 'shop-sidebar' ); ?>
			</aside>
		<?php endif; ?>

		<div class="luwi-shop-content">

			<?php
			/**
			 * woocommerce_before_shop_loop hook.
			 *
			 * @hooked woocommerce_output_all_notices - 10
			 * @hooked woocommerce_result_count - 20
			 * @hooked woocommerce_catalog_ordering - 30
			 */
			do_action( 'woocommerce_before_shop_loop' );
			?>

			<?php
			if ( woocommerce_product_loop() ) {
				woocommerce_product_loop_start();

				if ( wc_get_loop_prop( 'total' ) ) {
					while ( have_posts() ) {
						the_post();

						/**
						 * woocommerce_shop_loop hook.
						 */
						do_action( 'woocommerce_shop_loop' );

						wc_get_template_part( 'content', 'product' );
					}
				}

				woocommerce_product_loop_end();

				/**
				 * woocommerce_after_shop_loop hook.
				 *
				 * @hooked woocommerce_pagination - 10
				 */
				do_action( 'woocommerce_after_shop_loop' );

			} else {
				/**
				 * woocommerce_no_products_found hook.
				 *
				 * @hooked wc_no_products_found - 10
				 */
				do_action( 'woocommerce_no_products_found' );
			}
			?>

		</div>

	</div>

</div>

<?php
do_action( 'woocommerce_after_main_content' );

get_footer( 'shop' );
