<?php
/**
 * Sidebar template.
 *
 * @package Luwi_Gold
 */

if ( ! is_active_sidebar( 'blog-sidebar' ) ) {
	return;
}
?>

<aside id="secondary" class="widget-area" role="complementary"
	aria-label="<?php esc_attr_e( 'Sidebar', 'luwi-gold' ); ?>">
	<?php dynamic_sidebar( 'blog-sidebar' ); ?>
</aside>
