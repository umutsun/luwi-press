<?php
/**
 * Template part for displaying search results.
 *
 * @package Luwi_Gold
 */
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'luwi-search-result' ); ?>>
	<h2 class="entry-title">
		<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
	</h2>
	<div class="entry-meta">
		<span class="entry-type"><?php echo esc_html( get_post_type_object( get_post_type() )->labels->singular_name ); ?></span>
		<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
			<?php echo esc_html( get_the_date() ); ?>
		</time>
	</div>
	<div class="entry-excerpt">
		<?php the_excerpt(); ?>
	</div>
</article>
