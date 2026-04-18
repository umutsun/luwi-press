<?php
/**
 * PHPStan bootstrap — defines constants and stubs needed for static analysis.
 * This file is NOT part of the distributed plugin.
 */

// WordPress core constants (guarded — WP stubs may define some)
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', '/tmp/wordpress/wp-content' );
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '/tmp/wordpress/wp-content/plugins' );
}

// LuwiPress constants
if ( ! defined( 'LUWIPRESS_PLUGIN_DIR' ) ) {
	define( 'LUWIPRESS_PLUGIN_DIR', __DIR__ . '/luwipress/' );
}
if ( ! defined( 'LUWIPRESS_PLUGIN_URL' ) ) {
	define( 'LUWIPRESS_PLUGIN_URL', 'https://example.com/wp-content/plugins/luwipress/' );
}
if ( ! defined( 'LUWIPRESS_PLUGIN_BASENAME' ) ) {
	define( 'LUWIPRESS_PLUGIN_BASENAME', 'luwipress/luwipress.php' );
}
if ( ! defined( 'LUWIPRESS_VERSION' ) ) {
	define( 'LUWIPRESS_VERSION', '2.0.8' );
}
