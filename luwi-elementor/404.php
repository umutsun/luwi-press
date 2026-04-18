<?php
/**
 * 404 template.
 *
 * Stitch "Luthier Artisan" — editorial 404, search form, popular products.
 *
 * @package Luwi_Elementor
 */

get_header();
?>

<main id="content" class="site-main" role="main">
	<div class="luwi-404">

		<div class="luwi-404__hero">
			<span class="luwi-section-label"><?php esc_html_e( 'Lost in the Atelier', 'luwi-elementor' ); ?></span>
			<h1 class="luwi-404__number">404</h1>
			<h2 class="luwi-404__title"><?php esc_html_e( 'This page could not be found', 'luwi-elementor' ); ?></h2>
			<p class="luwi-404__text"><?php esc_html_e( 'The page you are looking for might have been removed, renamed, or is temporarily unavailable.', 'luwi-elementor' ); ?></p>
		</div>

		<div class="luwi-404__search">
			<?php get_search_form(); ?>
		</div>

		<div class="luwi-404__actions">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="luwi-btn luwi-btn--primary">
				<?php esc_html_e( 'Back to Home', 'luwi-elementor' ); ?>
			</a>
			<?php if ( function_exists( 'wc_get_page_permalink' ) ) : ?>
				<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="luwi-btn luwi-btn--outline">
					<?php esc_html_e( 'Browse Collection', 'luwi-elementor' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<?php
		// Show popular products if WooCommerce is active.
		if ( function_exists( 'wc_get_products' ) ) :
			$popular = wc_get_products(
				array(
					'limit'   => 4,
					'orderby' => 'popularity',
					'status'  => 'publish',
					'return'  => 'objects',
				)
			);
			if ( ! empty( $popular ) ) :
				?>
				<div class="luwi-404__products">
					<h3 class="luwi-404__products-title"><?php esc_html_e( 'Popular Right Now', 'luwi-elementor' ); ?></h3>
					<div class="luwi-404__products-grid">
						<?php foreach ( $popular as $product ) : ?>
							<a href="<?php echo esc_url( $product->get_permalink() ); ?>" class="luwi-404__product luwi-card">
								<div class="luwi-card__image luwi-img-zoom">
									<?php
									$img_id = $product->get_image_id();
									if ( $img_id ) {
										echo wp_get_attachment_image( $img_id, 'luwi-product-card', false, array( 'loading' => 'lazy' ) );
									} else {
										printf( '<img src="%s" alt="%s" loading="lazy">', esc_url( wc_placeholder_img_src( 'luwi-product-card' ) ), esc_attr( $product->get_name() ) );
									}
									?>
								</div>
								<div class="luwi-card__body">
									<h4 class="luwi-product-card__title"><?php echo esc_html( $product->get_name() ); ?></h4>
									<span class="luwi-product-card__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
								</div>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
		<?php endif; ?>

	</div>
</main>

<?php
get_footer();
