<?php
/**
 * Single post template.
 *
 * @package Luwi_Emerald
 */

get_header();
?>

<main id="content" class="site-main luwi-container" role="main">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'luwi-single-post' ); ?>>
			<header class="entry-header">
				<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
				<div class="entry-meta">
					<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
						<?php echo esc_html( get_the_date() ); ?>
					</time>
					<span class="entry-author"><?php the_author(); ?></span>
				</div>
			</header>

			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="entry-thumbnail">
					<?php the_post_thumbnail( 'luwi-hero' ); ?>
				</figure>
			<?php endif; ?>

			<div class="entry-content">
				<?php the_content(); ?>
			</div>

			<footer class="entry-footer">
				<?php
				$tags = get_the_tag_list( '', ', ' );
				if ( $tags ) {
					printf( '<span class="entry-tags">%s</span>', $tags );
				}
				?>
			</footer>
		</article>

		<?php
		the_post_navigation( array(
			'prev_text' => '<span class="screen-reader-text">' . esc_html__( 'Previous Post', 'luwi-emerald' ) . '</span> %title',
			'next_text' => '<span class="screen-reader-text">' . esc_html__( 'Next Post', 'luwi-emerald' ) . '</span> %title',
		) );

		if ( comments_open() || get_comments_number() ) {
			comments_template();
		}

	endwhile;
	?>
</main>

<?php
get_footer();
