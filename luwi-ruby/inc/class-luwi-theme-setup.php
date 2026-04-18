<?php
/**
 * Theme Setup — registers theme supports, menus, image sizes, and accessibility features.
 *
 * @package Luwi_Ruby
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Luwi_Theme_Setup {

	/**
	 * Initialize theme setup.
	 */
	public static function init() {
		add_action( 'after_setup_theme', array( __CLASS__, 'setup' ), 5 );
		add_action( 'widgets_init', array( __CLASS__, 'register_sidebars' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'skip_link' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'color_mode_script' ), 1 );
	}

	/**
	 * Core theme setup.
	 */
	public static function setup() {
		load_theme_textdomain( 'luwi-ruby', LUWI_THEME_DIR . '/languages' );

		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'automatic-feed-links' );
		add_theme_support( 'html5', array(
			'search-form',
			'comment-form',
			'comment-list',
			'gallery',
			'caption',
			'style',
			'script',
			'navigation-widgets',
		) );
		add_theme_support( 'custom-logo', array(
			'height'      => 80,
			'width'       => 240,
			'flex-height' => true,
			'flex-width'  => true,
		) );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'editor-styles' );
		add_theme_support( 'wp-block-styles' );
		add_theme_support( 'align-wide' );
		add_theme_support( 'custom-background', array(
			'default-color' => 'f8f6f3',
		) );

		add_editor_style( 'assets/css/editor-style.css' );

		register_nav_menus( array(
			'primary' => esc_html__( 'Primary Menu', 'luwi-ruby' ),
			'footer'  => esc_html__( 'Footer Menu', 'luwi-ruby' ),
			'mobile'  => esc_html__( 'Mobile Menu', 'luwi-ruby' ),
		) );

		add_image_size( 'luwi-product-card', 600, 600, true );
		add_image_size( 'luwi-hero', 1920, 800, true );
		add_image_size( 'luwi-blog-card', 800, 450, true );

		set_post_thumbnail_size( 600, 600, true );

		$GLOBALS['content_width'] = apply_filters( 'luwi_content_width', 1200 );
	}

	/**
	 * Register widget areas.
	 */
	public static function register_sidebars() {
		register_sidebar( array(
			'name'          => esc_html__( 'Shop Sidebar', 'luwi-ruby' ),
			'id'            => 'shop-sidebar',
			'description'   => esc_html__( 'Widgets for the shop/product archive pages.', 'luwi-ruby' ),
			'before_widget' => '<div id="%1$s" class="widget luwi-widget %2$s">',
			'after_widget'  => '</div>',
			'before_title'  => '<h3 class="widget-title">',
			'after_title'   => '</h3>',
		) );

		register_sidebar( array(
			'name'          => esc_html__( 'Blog Sidebar', 'luwi-ruby' ),
			'id'            => 'blog-sidebar',
			'description'   => esc_html__( 'Widgets for blog pages.', 'luwi-ruby' ),
			'before_widget' => '<div id="%1$s" class="widget luwi-widget %2$s">',
			'after_widget'  => '</div>',
			'before_title'  => '<h3 class="widget-title">',
			'after_title'   => '</h3>',
		) );

		register_sidebar( array(
			'name'          => esc_html__( 'Footer Column 1', 'luwi-ruby' ),
			'id'            => 'footer-1',
			'before_widget' => '<div id="%1$s" class="widget luwi-widget %2$s">',
			'after_widget'  => '</div>',
			'before_title'  => '<h4 class="widget-title">',
			'after_title'   => '</h4>',
		) );

		register_sidebar( array(
			'name'          => esc_html__( 'Footer Column 2', 'luwi-ruby' ),
			'id'            => 'footer-2',
			'before_widget' => '<div id="%1$s" class="widget luwi-widget %2$s">',
			'after_widget'  => '</div>',
			'before_title'  => '<h4 class="widget-title">',
			'after_title'   => '</h4>',
		) );

		register_sidebar( array(
			'name'          => esc_html__( 'Footer Column 3', 'luwi-ruby' ),
			'id'            => 'footer-3',
			'before_widget' => '<div id="%1$s" class="widget luwi-widget %2$s">',
			'after_widget'  => '</div>',
			'before_title'  => '<h4 class="widget-title">',
			'after_title'   => '</h4>',
		) );
	}

	/**
	 * Accessibility: skip-to-content link.
	 */
	public static function skip_link() {
		echo '<a class="skip-link screen-reader-text" href="#content">'
			. esc_html__( 'Skip to content', 'luwi-ruby' )
			. '</a>';
	}

	/**
	 * Inline script to apply color mode before paint (prevents flash).
	 */
	public static function color_mode_script() {
		?>
		<script>
		(function(){
			var mode = localStorage.getItem('luwi-color-mode');
			if (!mode || mode === 'auto') {
				mode = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
			}
			document.documentElement.setAttribute('data-color-mode', mode);
		})();
		</script>
		<?php
	}
}
