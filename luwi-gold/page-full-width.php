<?php
/**
 * Template Name: Full Width
 * Template Post Type: page
 *
 * Full-width page template — no sidebar, wider container.
 * Ideal for Elementor-built landing pages.
 *
 * @package Luwi_Gold
 */

get_header();
?>

<main id="content" class="site-main" role="main">
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
</main>

<?php
get_footer();
