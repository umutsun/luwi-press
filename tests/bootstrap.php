<?php
/**
 * PHPUnit Bootstrap for LuwiPress unit tests.
 *
 * Uses Brain\Monkey to mock WordPress functions — no WordPress installation needed.
 */

// Composer autoloader
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/unit/TestCase.php';

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
	define( 'LUWIPRESS_VERSION', '3.2.6' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}
