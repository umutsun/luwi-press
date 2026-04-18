<?php
/**
 * Header template — minimal markup, Elementor Theme Builder overrides this.
 *
 * @package Luwi_Ruby
 */

$color_mode    = luwi_get_color_mode();
$high_contrast = get_theme_mod( 'luwi_high_contrast', false );
$reduce_motion = get_theme_mod( 'luwi_reduce_motion', false );
?>
<!doctype html>
<html <?php language_attributes(); ?>
	data-color-mode="<?php echo esc_attr( $color_mode === 'auto' ? '' : $color_mode ); ?>"
	<?php if ( $high_contrast ) : ?>data-high-contrast="true"<?php endif; ?>
	<?php if ( $reduce_motion ) : ?>data-reduce-motion="true"<?php endif; ?>
>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div id="page" class="site">

	<?php if ( ! Luwi_Elementor_Compat::has_template( 'header' ) ) : ?>
	<header id="masthead" class="site-header" role="banner">
		<div class="luwi-container luwi-container--wide">
			<div class="site-header__inner">

				<div class="site-branding">
					<?php if ( has_custom_logo() ) : ?>
						<?php the_custom_logo(); ?>
					<?php else : ?>
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="site-title" rel="home">
							<?php bloginfo( 'name' ); ?>
						</a>
					<?php endif; ?>
				</div>

				<?php if ( has_nav_menu( 'primary' ) ) : ?>
				<nav id="site-navigation" class="main-navigation" role="navigation"
					aria-label="<?php esc_attr_e( 'Primary Menu', 'luwi-ruby' ); ?>">
					<button class="menu-toggle" aria-controls="primary-menu" aria-expanded="false"
						aria-label="<?php esc_attr_e( 'Toggle Menu', 'luwi-ruby' ); ?>">
						<span class="menu-toggle__bar"></span>
						<span class="menu-toggle__bar"></span>
						<span class="menu-toggle__bar"></span>
					</button>
					<?php
					wp_nav_menu( array(
						'theme_location' => 'primary',
						'menu_id'        => 'primary-menu',
						'container'      => false,
						'fallback_cb'    => false,
					) );
					?>
				</nav>
				<?php endif; ?>

				<div class="site-header__actions">
					<?php if ( get_theme_mod( 'luwi_show_mode_toggle', true ) ) : ?>
						<button class="luwi-color-mode-toggle" aria-label="<?php esc_attr_e( 'Toggle color mode', 'luwi-ruby' ); ?>">
							<svg class="luwi-color-mode-toggle__icon luwi-color-mode-toggle__sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
							</svg>
							<svg class="luwi-color-mode-toggle__icon luwi-color-mode-toggle__moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
							</svg>
						</button>
					<?php endif; ?>
				</div>

			</div>
		</div>
	</header>
	<?php endif; ?>
