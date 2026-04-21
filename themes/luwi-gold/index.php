<?php
/**
 * Main template file — fallback for all content types.
 *
 * @package Luwi_Gold
 */

get_header();
?>

<main id="content" class="site-main luwi-container" role="main">
	<?php if ( have_posts() ) : ?>

		<div class="luwi-posts-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				get_template_part( 'template-parts/content', get_post_type() );
			endwhile;
			?>
		</div>

		<?php the_posts_pagination( array(
			'prev_text' => '<span class="screen-reader-text">' . esc_html__( 'Previous', 'luwi-gold' ) . '</span>',
			'next_text' => '<span class="screen-reader-text">' . esc_html__( 'Next', 'luwi-gold' ) . '</span>',
		) ); ?>

	<?php else : ?>

		<?php get_template_part( 'template-parts/content', 'none' ); ?>

	<?php endif; ?>
</main>

<?php
get_footer();
