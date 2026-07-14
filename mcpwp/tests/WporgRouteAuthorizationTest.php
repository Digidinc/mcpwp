<?php

use PHPUnit\Framework\TestCase;

final class WporgRouteAuthorizationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array(
			'mcpwp_rate_limit_settings' => array( 'enabled' => false ),
		);
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 99;
		$GLOBALS['mcpwp_test_users'] = array(
			(object) array( 'ID' => 2, 'roles' => array( 'mcpwp_api_agent' ) ),
			(object) array( 'ID' => 7, 'roles' => array( 'author' ) ),
			(object) array( 'ID' => 8, 'roles' => array( 'editor' ) ),
		);
		$GLOBALS['mcpwp_test_user_caps'] = array(
			7 => array( 'read' => true, 'edit_posts' => true, 'edit_pages' => true, 'upload_files' => true, 'manage_categories' => true, 'mcpwp_manage_settings' => true ),
			8 => array( 'read' => true ),
			99 => array( 'read' => true, 'mcpwp_manage_settings' => true ),
		);
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$this->reset_limiter_singleton();
	}

	public function test_runtime_routes_match_exact_hardening_fixture(): void {
		$fixture = $this->read_fixture( 'wporg-routes.json' );
		$this->register_all_routes();
		$actual = array();
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $registration ) {
			$configurations = isset( $registration['args']['methods'] ) ? array( $registration['args'] ) : $registration['args'];
			foreach ( $configurations as $configuration ) {
				foreach ( array_map( 'trim', explode( ',', $configuration['methods'] ) ) as $method ) {
					$permission = $configuration['permission_callback'] ?? null;
					$actual[] = array(
						'route'        => $registration['route'],
						'method'       => $method,
						'permission'   => is_array( $permission ) ? $permission[1] : $permission,
						'category'     => $configuration['mcpwp_category'] ?? null,
						'scope'        => $configuration['mcpwp_scope'] ?? null,
						'capabilities' => $configuration['mcpwp_capabilities'] ?? array(),
						'mutable'      => $configuration['mcpwp_mutable'] ?? null,
						'args'         => $configuration['args'] ?? array(),
					);
				}
			}
		}
		$sort = static function ( $left, $right ) { return strcmp( $left['route'] . '|' . $left['method'], $right['route'] . '|' . $right['method'] ); };
		usort( $fixture['endpoints'], $sort );
		usort( $actual, $sort );
		$this->assertSame( $fixture['endpoints'], $actual );
	}

	public function test_runtime_tools_match_exact_hardening_fixture(): void {
		$fixture = $this->read_fixture( 'wporg-tools.json' );
		$registry = new Mcpwp_MCP_Free_Tools();
		$map = $registry->get_tool_map();
		$capabilities = $registry->get_required_capabilities();
		$actual = array();
		foreach ( $registry->get_tools() as $definition ) {
			$name = $definition['name'];
			$actual[] = array(
				'name'                 => $name,
				'dispatch'             => $map[ $name ],
				'category'             => $registry->get_tool_category( $name ),
				'description'          => $definition['description'],
				'requiredCapabilities' => $capabilities[ $name ] ?? array(),
				'annotations'          => $definition['annotations'],
				'inputSchema'          => json_decode( wp_json_encode( $definition['inputSchema'] ), true ),
			);
		}
		$this->assertSame( $fixture['tools'], $actual );
	}

	public function test_bound_key_replaces_ambient_cookie_identity_with_stored_owner(): void {
		$this->seed_key( 'owner-seven', 7, array( 'read' ) );
		$result = ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( $this->request( 'owner-seven' ) );
		$this->assertTrue( $result );
		$this->assertSame( 7, get_current_user_id() );
	}

	public function test_two_keys_install_their_distinct_stored_owners(): void {
		$this->seed_keys( array(
			$this->key_record( 'owner-seven', 7, array( 'read' ) ),
			$this->key_record( 'owner-eight', 8, array( 'read' ) ),
		) );
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		$this->assertTrue( $auth->verify_api_key( $this->request( 'owner-seven' ) ) );
		$this->assertSame( 7, get_current_user_id() );
		$GLOBALS['mcpwp_test_current_user'] = 99;
		$this->assertTrue( $auth->verify_api_key( $this->request( 'owner-eight' ) ) );
		$this->assertSame( 8, get_current_user_id() );
	}

	public function test_unbound_key_owner_fails_closed_without_role_fallback(): void {
		$this->seed_key( 'unbound', null, array( 'read' ) );
		$result = ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( $this->request( 'unbound' ) );
		$this->assertWpError( 'api_key_owner_setup_required', 500, $result );
		$this->assertSame( 99, get_current_user_id() );
	}

	public function test_deleted_bound_owner_fails_closed(): void {
		$this->seed_key( 'deleted-owner', 404, array( 'read' ) );
		$result = ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( $this->request( 'deleted-owner' ) );
		$this->assertWpError( 'api_key_owner_unavailable', 500, $result );
		$this->assertSame( 99, get_current_user_id() );
	}

	public function test_empty_or_unknown_stored_scopes_fail_closed(): void {
		foreach ( array( array(), array( 'read', 'super-admin' ) ) as $index => $scopes ) {
			$key = 'invalid-scopes-' . $index;
			$this->seed_key( $key, 7, $scopes );
			$result = ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( $this->request( $key ) );
			$this->assertWpError( 'api_key_scope_invalid', 500, $result );
		}
	}

	public function test_direct_rest_honors_per_key_category_restriction(): void {
		$this->seed_key( 'content-only', 7, array( 'read' ), array( 'content' ) );
		$result = ( new Mcpwp_REST_Site_Info() )->check_permission( $this->request( 'content-only' ) );
		$this->assertWpError( 'tool_category_forbidden', 403, $result );
	}

	public function test_direct_rest_honors_site_disabled_category(): void {
		$this->seed_key( 'site-key', 7, array( 'read' ) );
		$GLOBALS['mcpwp_test_options']['mcpwp_disabled_tool_categories'] = array( 'site' );
		$result = ( new Mcpwp_REST_Site_Info() )->check_permission( $this->request( 'site-key' ) );
		$this->assertWpError( 'tool_category_disabled', 403, $result );
	}

	public function test_route_requires_bound_owner_wordpress_capability(): void {
		$this->seed_key( 'no-read', 8, array( 'read' ) );
		$GLOBALS['mcpwp_test_user_caps'][8]['read'] = false;
		$result = ( new Mcpwp_REST_Site_Info() )->check_permission( $this->request( 'no-read' ) );
		$this->assertWpError( 'insufficient_capability', 403, $result );
	}

	public function test_limiter_receives_get_method_for_read_multiplier(): void {
		$this->enable_rate_limit( 1 );
		$this->seed_key( 'read-rate', 7, array( 'read' ) );
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		$this->assertTrue( $auth->verify_api_key( $this->request( 'read-rate', 'GET' ) ) );
		$this->assertTrue( $auth->verify_api_key( $this->request( 'read-rate', 'GET' ) ), 'GET must retain the limiter read multiplier.' );
	}

	public function test_limiter_receives_full_key_record_for_reviewed_override(): void {
		$this->enable_rate_limit( 10 );
		$record = $this->key_record( 'override-rate', 7, array( 'write' ) );
		$record['rate_limits'] = array( 'burst' => 1, 'per_minute' => 100, 'per_hour' => 100 );
		$this->seed_keys( array( $record ) );
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		$this->assertTrue( $auth->verify_api_key( $this->request( 'override-rate', 'POST', '/mcpwp/v1/posts' ) ) );
		$result = $auth->verify_api_key( $this->request( 'override-rate', 'POST', '/mcpwp/v1/posts' ) );
		$this->assertWpError( 'rate_limit_exceeded', 429, $result );
	}

	private function register_all_routes(): void {
		foreach ( array(
			new Mcpwp_REST_MCP(), new Mcpwp_REST_Site_Info(), new Mcpwp_REST_Posts(), new Mcpwp_REST_Pages(),
			new Mcpwp_REST_Media(), new Mcpwp_REST_Site_Taxonomy(), new Mcpwp_REST_Site_Settings(),
			new Mcpwp_REST_Rate_Limit(), new Mcpwp_REST_API_Keys(),
		) as $controller ) {
			$controller->register_routes();
		}
	}

	private function read_fixture( $name ): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/fixtures/' . $name ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function seed_key( $key, $user_id, $scopes, $categories = array() ): void {
		$this->seed_keys( array( $this->key_record( $key, $user_id, $scopes, $categories ) ) );
	}

	private function seed_keys( $records ): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = $records;
	}

	private function key_record( $key, $user_id, $scopes, $categories = array() ): array {
		$record = array(
			'id' => $key, 'hash' => $key, 'scopes' => $scopes, 'tool_categories' => $categories,
			'created_at' => '2026-07-13 00:00:00', 'last_used_at' => null, 'revoked_at' => null,
		);
		if ( null !== $user_id ) { $record['user_id'] = $user_id; }
		return $record;
	}

	private function request( $key, $method = 'GET', $route = '/mcpwp/v1/site-info' ): WP_REST_Request {
		return new WP_REST_Request( $method, $route, array(), array( 'X-API-Key' => $key ) );
	}

	private function assertWpError( $code, $status, $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	private function enable_rate_limit( $burst ): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_rate_limit_settings'] = array(
			'enabled' => true, 'requests_per_minute' => 100, 'requests_per_hour' => 100, 'burst_limit' => $burst, 'whitelist' => array(),
		);
		$this->reset_limiter_singleton();
	}

	private function reset_limiter_singleton(): void {
		$reflection = new ReflectionClass( Mcpwp_Rate_Limiter::class );
		$instance = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null );
	}
}
