<?php
/**
 * PHPUnit Bootstrap for LuwiPress unit tests.
 *
 * Uses Brain\Monkey to mock WordPress functions — no WordPress installation needed.
 */

// Composer autoloader
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define WordPress constants that plugin code expects
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'LUWIPRESS_PLUGIN_DIR' ) ) {
	define( 'LUWIPRESS_PLUGIN_DIR', dirname( __DIR__ ) . '/luwipress/' );
}
if ( ! defined( 'LUWIPRESS_PLUGIN_URL' ) ) {
	define( 'LUWIPRESS_PLUGIN_URL', 'https://example.com/wp-content/plugins/luwipress/' );
}
if ( ! defined( 'LUWIPRESS_VERSION' ) ) {
	define( 'LUWIPRESS_VERSION', '2.0.8' );
}
