<?php
/**
 * Template part for displaying a message when no posts are found.
 *
 * @package Luwi_Gold
 */
?>

<section class="luwi-no-results">
	<h1><?php esc_html_e( 'Nothing here yet', 'luwi-gold' ); ?></h1>

	<?php if ( is_search() ) : ?>
		<p><?php esc_html_e( 'Sorry, no results matched your search. Try different keywords.', 'luwi-gold' ); ?></p>
		<?php get_search_form(); ?>
	<?php else : ?>
		<p><?php esc_html_e( 'It seems we can&rsquo;t find what you&rsquo;re looking for.', 'luwi-gold' ); ?></p>
	<?php endif; ?>
</section>
