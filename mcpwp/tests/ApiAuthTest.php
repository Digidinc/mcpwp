<?php

use PHPUnit\Framework\TestCase;

final class ApiAuthTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	public function test_query_parameter_key_is_not_accepted(): void {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		update_option( 'mcpwp_api_key', wp_hash_password( 'mcpwp_valid' ) );

		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/site-info',
			array( 'api_key' => 'mcpwp_valid' ),
			array()
		);

		$result = $auth->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_api_key', $result->get_error_code() );
	}

	public function test_read_scope_key_cannot_write(): void {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		$created = $auth->create_scoped_api_key( 'read only', array( 'read' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(),
			array( 'X-API-Key' => $created['key'] )
		);

		$result = $auth->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_write_scope_key_can_write(): void {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		$created = $auth->create_scoped_api_key( 'writer', array( 'write' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(),
			array( 'X-API-Key' => $created['key'] )
		);

		$result = $auth->verify_api_key( $request );

		$this->assertTrue( $result );
		$this->assertNotSame( 0, $GLOBALS['mcpwp_test_current_user'] );
	}

	public function test_plaintext_legacy_key_is_hashed_when_migrated(): void {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		update_option( 'mcpwp_api_key', 'mcpwp_legacy_plain' );

		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/site-info',
			array(),
			array( 'X-API-Key' => 'mcpwp_legacy_plain' )
		);

		$result = $auth->verify_api_key( $request );

		$this->assertTrue( $result );

		$legacy = (string) get_option( 'mcpwp_api_key' );
		$this->assertNotSame( 'mcpwp_legacy_plain', $legacy );
		$this->assertSame( 0, strpos( $legacy, '$' ) );

		$keys = get_option( 'mcpwp_api_keys', array() );
		$this->assertNotEmpty( $keys );
		$this->assertNotSame( 'mcpwp_legacy_plain', $keys[0]['hash'] );
		$this->assertTrue( wp_check_password( 'mcpwp_legacy_plain', $keys[0]['hash'] ) );
	}

	public function test_authorization_bearer_header_is_not_an_api_key_transport(): void {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		$created = $auth->create_scoped_api_key( 'read key', array( 'read' ) );
		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/site-info',
			array(),
			array( 'Authorization' => 'Bearer ' . $created['key'] )
		);
		$result = $auth->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_api_key', $result->get_error_code() );
	}
}
