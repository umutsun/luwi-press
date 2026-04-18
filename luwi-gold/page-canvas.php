<?php
/**
 * Template Name: Canvas (No Header/Footer)
 * Template Post Type: page
 *
 * Blank canvas — no header, no footer.
 * For coming soon pages, landing pages, special Elementor layouts.
 *
 * @package Luwi_Gold
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'luwi-canvas' ); ?>>
<?php wp_body_open(); ?>

<main id="content" class="site-main" role="main">
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
</main>

<?php wp_footer(); ?>
</body>
</html>
