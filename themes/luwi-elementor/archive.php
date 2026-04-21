<?php
/**
 * Archive template — category, tag, date, author archives.
 *
 * @package Luwi_Elementor
 */

get_header();
?>

<main id="content" class="site-main luwi-container" role="main">
	<?php if ( function_exists( 'woocommerce_breadcrumb' ) ) { woocommerce_breadcrumb(); } ?>

	<?php if ( have_posts() ) : ?>

		<header class="archive-header">
			<?php the_archive_title( '<h1 class="archive-title">', '</h1>' ); ?>
			<?php the_archive_description( '<div class="archive-description">', '</div>' ); ?>
		</header>

		<div class="luwi-posts-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				get_template_part( 'template-parts/content', get_post_type() );
			endwhile;
			?>
		</div>

		<?php the_posts_pagination(); ?>

	<?php else : ?>

		<?php get_template_part( 'template-parts/content', 'none' ); ?>

	<?php endif; ?>
</main>

<?php
get_footer();
