<?php
/**
 * Base test case for LuwiPress unit tests.
 *
 * Sets up Brain\Monkey for WordPress function mocking
 * and provides helpers for singleton testing.
 */

namespace LuwiPress\Tests\Unit;

use Brain\Monkey;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

abstract class TestCase extends PolyfillTestCase {

	/**
	 * Set up Brain\Monkey before each test.
	 */
	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();
	}

	/**
	 * Tear down Brain\Monkey after each test.
	 */
	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();
	}

	/**
	 * Reset a singleton instance to null via reflection.
	 *
	 * This allows each test to start with a fresh instance.
	 *
	 * @param string $class Fully qualified class name.
	 */
	protected function reset_singleton( string $class ): void {
		$ref = new \ReflectionProperty( $class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}
}
