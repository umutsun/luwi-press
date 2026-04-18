<?php
/**
 * Template part for displaying post content in loops.
 *
 * @package Luwi_Elementor
 */
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'luwi-card' ); ?>>
	<?php if ( has_post_thumbnail() ) : ?>
		<a href="<?php the_permalink(); ?>" class="luwi-card__image">
			<?php the_post_thumbnail( 'luwi-blog-card' ); ?>
		</a>
	<?php endif; ?>

	<div class="luwi-card__body">
		<?php the_title( '<h2 class="entry-title"><a href="' . esc_url( get_permalink() ) . '">', '</a></h2>' ); ?>

		<div class="entry-meta">
			<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
				<?php echo esc_html( get_the_date() ); ?>
			</time>
		</div>

		<div class="entry-excerpt">
			<?php the_excerpt(); ?>
		</div>
	</div>
</article>
