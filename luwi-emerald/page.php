<?php
/**
 * Page template — for Elementor pages, outputs the_content() directly.
 *
 * @package Luwi_Emerald
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
