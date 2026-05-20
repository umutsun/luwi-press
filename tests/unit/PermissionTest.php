<?php
/**
 * Unit tests for the shared REST permission helper.
 */

namespace LuwiPress\Tests\Unit;

use Brain\Monkey\Functions;

require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-permission.php';

class PermissionTest extends TestCase {

	public function test_check_token_rejects_when_no_token_is_configured(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$request = new FakeRestRequest( array(
			'authorization' => 'Bearer supplied-token',
		) );

		$this->assertFalse( \LuwiPress_Permission::check_token( $request ) );
	}

	public function test_check_token_accepts_matching_bearer_token(): void {
		Functions\when( 'get_option' )->justReturn( 'stored-token' );

		$request = new FakeRestRequest( array(
			'authorization' => 'Bearer stored-token',
		) );

		$this->assertTrue( \LuwiPress_Permission::check_token( $request ) );
	}

	public function test_check_token_accepts_matching_custom_header(): void {
		Functions\when( 'get_option' )->justReturn( 'stored-token' );

		$request = new FakeRestRequest( array(
			'x-luwipress-token' => 'stored-token',
		) );

		$this->assertTrue( \LuwiPress_Permission::check_token( $request ) );
	}

	public function test_check_token_rejects_non_matching_token(): void {
		Functions\when( 'get_option' )->justReturn( 'stored-token' );

		$request = new FakeRestRequest( array(
			'authorization' => 'Bearer wrong-token',
		) );

		$this->assertFalse( \LuwiPress_Permission::check_token( $request ) );
	}

	public function test_check_token_or_admin_accepts_admin_when_token_is_missing(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'current_user_can' )->alias(
			static function ( $capability ) {
				return 'manage_options' === $capability;
			}
		);

		$request = new FakeRestRequest();

		$this->assertTrue( \LuwiPress_Permission::check_token_or_admin( $request ) );
	}

	public function test_require_token_returns_wp_error_for_invalid_token(): void {
		Functions\when( 'get_option' )->justReturn( 'stored-token' );

		$request = new FakeRestRequest( array(
			'authorization' => 'Bearer wrong-token',
		) );

		$result = \LuwiPress_Permission::require_token( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'luwipress_unauthorized', $result->get_error_code() );
		$this->assertSame( array( 'status' => 401 ), $result->get_error_data() );
	}

	public function test_require_token_returns_true_for_valid_token(): void {
		Functions\when( 'get_option' )->justReturn( 'stored-token' );

		$request = new FakeRestRequest( array(
			'authorization' => 'Bearer stored-token',
		) );

		$this->assertTrue( \LuwiPress_Permission::require_token( $request ) );
	}
}

class FakeRestRequest {

	private $headers;

	public function __construct( array $headers = array() ) {
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
	}

	public function get_header( string $name ): string {
		$name = strtolower( $name );

		return $this->headers[ $name ] ?? '';
	}
}
