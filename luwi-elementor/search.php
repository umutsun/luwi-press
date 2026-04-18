<?php
/**
 * Search results template.
 *
 * @package Luwi_Elementor
 */

get_header();
?>

<main id="content" class="site-main luwi-container" role="main">
	<?php if ( have_posts() ) : ?>

		<header class="archive-header">
			<h1 class="archive-title">
				<?php printf(
					/* translators: %s: search query */
					esc_html__( 'Search results for: %s', 'luwi-elementor' ),
					'<span>' . get_search_query() . '</span>'
				); ?>
			</h1>
		</header>

		<div class="luwi-posts-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				get_template_part( 'template-parts/content', 'search' );
			endwhile;
			?>
		</div>

		<?php the_posts_pagination(); ?>

	<?php else : ?>

		<div class="luwi-no-results">
			<h2><?php esc_html_e( 'Nothing found', 'luwi-elementor' ); ?></h2>
			<p><?php esc_html_e( 'Sorry, no results matched your search. Try different keywords.', 'luwi-elementor' ); ?></p>
			<?php get_search_form(); ?>
		</div>

	<?php endif; ?>
</main>

<?php
get_footer();
