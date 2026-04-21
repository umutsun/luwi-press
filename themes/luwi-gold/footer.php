<?php
/**
 * Footer template — minimal markup, Elementor Theme Builder overrides this.
 *
 * @package Luwi_Gold
 */
?>

	<?php if ( ! Luwi_Elementor_Compat::has_template( 'footer' ) ) : ?>
	<footer id="colophon" class="site-footer" role="contentinfo">
		<div class="luwi-container">

			<?php if ( is_active_sidebar( 'footer-1' ) || is_active_sidebar( 'footer-2' ) || is_active_sidebar( 'footer-3' ) ) : ?>
			<div class="site-footer__widgets">
				<?php if ( is_active_sidebar( 'footer-1' ) ) : ?>
					<div class="site-footer__col"><?php dynamic_sidebar( 'footer-1' ); ?></div>
				<?php endif; ?>
				<?php if ( is_active_sidebar( 'footer-2' ) ) : ?>
					<div class="site-footer__col"><?php dynamic_sidebar( 'footer-2' ); ?></div>
				<?php endif; ?>
				<?php if ( is_active_sidebar( 'footer-3' ) ) : ?>
					<div class="site-footer__col"><?php dynamic_sidebar( 'footer-3' ); ?></div>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<div class="site-footer__bottom">
				<p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>.
				<?php esc_html_e( 'All rights reserved.', 'luwi-gold' ); ?></p>
				<?php if ( has_nav_menu( 'footer' ) ) : ?>
					<?php wp_nav_menu( array(
						'theme_location' => 'footer',
						'container'      => false,
						'depth'          => 1,
						'fallback_cb'    => false,
					) ); ?>
				<?php endif; ?>
			</div>

		</div>
	</footer>
	<?php endif; ?>

</div><!-- #page -->

<!-- Stitch: Back to top button -->
<button class="luwi-back-to-top" aria-label="<?php esc_attr_e( 'Back to top', 'luwi-gold' ); ?>">
	<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="18 15 12 9 6 15"/></svg>
</button>

<?php wp_footer(); ?>
</body>
</html>
