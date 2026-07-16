<?php

use PHPUnit\Framework\TestCase;

class Mcpwp_Api_Auth_Test_Harness {
	use Mcpwp_Api_Auth;

	public function required_scope_for_tool( string $tool_name ): string {
		return $this->get_required_scope_for_tool_name( $tool_name );
	}
}

final class ApiAuthTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	public function test_extension_can_raise_custom_tool_scope_to_admin(): void {
		add_filter(
			'mcpwp_required_scope_for_tool',
			static function ( string $scope, string $tool_name ): string {
				return 'wp_update_mumega_motion' === $tool_name ? 'admin' : $scope;
			},
			10,
			2
		);

		$auth = new Mcpwp_Api_Auth_Test_Harness();

		$this->assertSame( 'admin', $auth->required_scope_for_tool( 'wp_update_mumega_motion' ) );
	}

	public function test_extension_cannot_lower_builtin_admin_scope(): void {
		add_filter(
			'mcpwp_required_scope_for_tool',
			static function (): string {
				return 'read';
			},
			10,
			2
		);

		$auth = new Mcpwp_Api_Auth_Test_Harness();

		$this->assertSame( 'admin', $auth->required_scope_for_tool( 'wp_trigger_update' ) );
	}

	public function test_invalid_filtered_scope_is_ignored(): void {
		add_filter(
			'mcpwp_required_scope_for_tool',
			static function (): string {
				return 'root';
			},
			10,
			2
		);

		$auth = new Mcpwp_Api_Auth_Test_Harness();

		$this->assertSame( 'write', $auth->required_scope_for_tool( 'wp_update_example' ) );
	}

	public function test_scope_inference_is_unchanged_without_filter(): void {
		$auth = new Mcpwp_Api_Auth_Test_Harness();

		$this->assertSame( 'read', $auth->required_scope_for_tool( 'vendor_list_records' ) );
		$this->assertSame( 'write', $auth->required_scope_for_tool( 'wp_update_example' ) );
		$this->assertSame( 'admin', $auth->required_scope_for_tool( 'wp_trigger_update' ) );
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

	public function test_oauth_bearer_token_with_read_scope_authenticates_read_requests(): void {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		update_option(
			'mcpwp_settings',
			array(
				'oauth_enabled'            => true,
				'oauth_client_id'          => 'site_pilot_ai',
				'oauth_client_secret_hash' => wp_hash_password( 'secret' ),
				'oauth_token_ttl'          => 3600,
			)
		);

		$token_response = $auth->issue_oauth_access_token( array( 'read' ), 3600 );

		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/site-info',
			array(),
			array( 'Authorization' => 'Bearer ' . $token_response['access_token'] )
		);

		$result = $auth->verify_api_key( $request );
		$this->assertTrue( $result );
	}

	public function test_oauth_bearer_token_with_read_scope_cannot_write(): void {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		update_option(
			'mcpwp_settings',
			array(
				'oauth_enabled'            => true,
				'oauth_client_id'          => 'site_pilot_ai',
				'oauth_client_secret_hash' => wp_hash_password( 'secret' ),
				'oauth_token_ttl'          => 3600,
			)
		);

		$token_response = $auth->issue_oauth_access_token( array( 'read' ), 3600 );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(),
			array( 'Authorization' => 'Bearer ' . $token_response['access_token'] )
		);

		$result = $auth->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}
}
