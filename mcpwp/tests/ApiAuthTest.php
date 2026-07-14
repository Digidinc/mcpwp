<?php

use PHPUnit\Framework\TestCase;

final class ApiAuthTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 2;
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
		update_option( 'mcpwp_legacy_migration_owner_id', 2 );

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
		$this->assertSame( 2, $keys[0]['user_id'] );
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

	public function test_public_key_projection_strips_noncanonical_authority_fields(): void {
		update_option( 'mcpwp_api_keys', array( array(
			'id' => 'canonical-id', 'label' => 'Canonical', 'hash' => 'secret', 'user_id' => 2,
			'scopes' => array( 'read' ), 'tool_categories' => array( 'content' ), 'created_at' => '2026-07-13 00:00:00',
			'last_used_at' => null, 'revoked_at' => null, 'role' => 'administrator', 'rate_limits' => array( 'burst' => 1 ), 'arbitrary' => 'authority',
		) ) );
		$keys = ( new Mcpwp_Api_Auth_Test_Harness() )->list_scoped_api_keys( true );
		$this->assertSame( array( 'id', 'label', 'user_id', 'scopes', 'tool_categories', 'created_at', 'last_used_at', 'revoked_at' ), array_keys( $keys[0] ) );
	}

	public function test_numeric_string_owner_and_unknown_categories_fail_closed(): void {
		update_option( 'mcpwp_api_keys', array( array( 'id' => 'bad-owner', 'hash' => 'bad-owner', 'user_id' => '2', 'scopes' => array( 'read' ), 'tool_categories' => array() ) ) );
		$result = ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => 'bad-owner' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'api_key_owner_setup_required', $result->get_error_code() );

		$created = ( new Mcpwp_Api_Auth_Test_Harness() )->create_scoped_api_key( 'bad categories', array( 'read' ), array( 'content', 'root' ) );
		$this->assertInstanceOf( WP_Error::class, $created );
	}

	public function test_scope_and_category_enums_are_exact_not_sanitized_into_validity(): void {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		$this->assertInstanceOf( WP_Error::class, $auth->create_scoped_api_key( 'bad scope', array( 'admin!!!' ), array() ) );
		$this->assertInstanceOf( WP_Error::class, $auth->create_scoped_api_key( 'bad category', array( 'read' ), array( 'content!!!' ) ) );
	}

	/** @dataProvider malformedIdentityRecords */
	public function test_malformed_stored_identity_types_never_authenticate_after_string_casts( $record, $presented_key ): void {
		update_option( 'mcpwp_api_keys', array( $record ) );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $presented_key ) );
		$result = ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_api_key', $result->get_error_code() );
	}

	public function malformedIdentityRecords(): array {
		$base = array( 'id' => 'valid-id', 'hash' => 'valid-hash', 'user_id' => 2, 'scopes' => array( 'read' ), 'tool_categories' => array() );
		return array(
			'numeric id' => array( array_merge( $base, array( 'id' => 123 ) ), 'valid-hash' ),
			'numeric hash' => array( array_merge( $base, array( 'hash' => 123 ) ), '123' ),
		);
	}
}
