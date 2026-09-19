<?php
/**
 * Real-HTTP-shaped coverage for GH #669 (backport of the wp.org audit's
 * finding #4) at the REST layer: Mcpwp_REST_Site_Updates::
 * can_manage_api_keys() must reject a key that lacks admin scope on
 * /api-keys and /rate-limit, and must not lock out a genuinely admin-scoped
 * key now that mcpwp_manage_settings is no longer granted to the shared
 * mcpwp_api_agent role every key authenticates as.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ApiKeyAdminRouteCapabilityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 2;
		$GLOBALS['mcpwp_test_filters']     = array();
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}

		$ref  = new ReflectionClass( Mcpwp_Rate_Limiter::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
	}

	private function authed( $controller, WP_REST_Request $request, string $key ) {
		$request->set_header( 'X-API-Key', $key );
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}
		return $controller->verify_api_key( $request );
	}

	// ---------------------------------------------------------------
	// A non-admin-scoped ("agent") key never reaches the handler at all —
	// verify_api_key() itself refuses it before can_manage_api_keys() runs.
	// ---------------------------------------------------------------

	public function test_a_write_scoped_key_is_refused_before_reaching_list_api_keys(): void {
		$agent_key = ( new Mcpwp_REST_Site_Updates() )->create_scoped_api_key( 'agent', array( 'read', 'write' ) );

		$controller = new Mcpwp_REST_Site_Updates();
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/api-keys' );
		$verdict    = $this->authed( $controller, $request, $agent_key['key'] );

		$this->assertInstanceOf( WP_Error::class, $verdict );
		$this->assertSame( 'insufficient_scope', $verdict->get_error_code() );
	}

	public function test_a_write_scoped_key_is_refused_before_reaching_reset_rate_limit(): void {
		$agent_key = ( new Mcpwp_REST_Site_Updates() )->create_scoped_api_key( 'agent', array( 'read', 'write' ) );

		$controller = new Mcpwp_REST_Site_Updates();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/rate-limit', array( 'identifier' => 'key:agent' ) );
		$verdict    = $this->authed( $controller, $request, $agent_key['key'] );

		$this->assertInstanceOf( WP_Error::class, $verdict );
		$this->assertSame( 'insufficient_scope', $verdict->get_error_code() );
	}

	/**
	 * Direct unit coverage of the capability method itself: even if it were
	 * ever reached by a non-admin-scoped key (e.g. a future route
	 * misclassification in get_required_scope_for_request()'s table), it
	 * independently refuses on the key's own scope rather than on the
	 * (now administrator-only, and therefore always-false-for-a-key)
	 * mcpwp_manage_settings capability.
	 */
	public function test_can_manage_api_keys_independently_refuses_a_non_admin_scoped_key(): void {
		$agent_key = ( new Mcpwp_REST_Site_Updates() )->create_scoped_api_key( 'agent', array( 'read', 'write' ) );

		$controller = new Mcpwp_REST_Site_Updates();
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/some-future-route' );
		$request->set_header( 'X-API-Key', $agent_key['key'] );

		// Populate current_api_key_record without going through the outer
		// route-classification gate, to isolate can_manage_api_keys() itself.
		$auth_ref = new ReflectionMethod( $controller, 'verify_api_key_auth_only' );
		$auth_ref->setAccessible( true );
		$this->assertTrue( $auth_ref->invoke( $controller, $request ) );

		$method = new ReflectionMethod( $controller, 'can_manage_api_keys' );
		$method->setAccessible( true );
		$this->assertFalse( $method->invoke( $controller ) );
	}

	// ---------------------------------------------------------------
	// Do not lock out the legitimate admin flow.
	// ---------------------------------------------------------------

	public function test_an_admin_scoped_key_still_lists_api_keys(): void {
		$admin_key = ( new Mcpwp_REST_Site_Updates() )->create_scoped_api_key( 'owner', array( 'admin' ) );

		$controller = new Mcpwp_REST_Site_Updates();
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/api-keys' );
		$this->assertTrue( $this->authed( $controller, $request, $admin_key['key'] ) );

		$response = $controller->list_api_keys( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_an_admin_scoped_key_still_creates_api_keys(): void {
		$admin_key = ( new Mcpwp_REST_Site_Updates() )->create_scoped_api_key( 'owner', array( 'admin' ) );

		$controller = new Mcpwp_REST_Site_Updates();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/api-keys', array( 'label' => 'sub-agent', 'scopes' => array( 'read' ) ) );
		$this->assertTrue( $this->authed( $controller, $request, $admin_key['key'] ) );

		$response = $controller->create_api_key( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
	}

	public function test_an_admin_scoped_key_still_revokes_api_keys(): void {
		$admin_key = ( new Mcpwp_REST_Site_Updates() )->create_scoped_api_key( 'owner', array( 'admin' ) );
		$victim    = ( new Mcpwp_REST_Site_Updates() )->create_scoped_api_key( 'victim', array( 'read' ) );

		$controller = new Mcpwp_REST_Site_Updates();
		$request    = new WP_REST_Request( 'DELETE', '/mcpwp/v1/api-keys/' . $victim['id'], array( 'id' => $victim['id'] ) );
		$this->assertTrue( $this->authed( $controller, $request, $admin_key['key'] ) );

		$response = $controller->revoke_api_key( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_an_admin_scoped_key_still_resets_the_rate_limit(): void {
		$admin_key = ( new Mcpwp_REST_Site_Updates() )->create_scoped_api_key( 'owner', array( 'admin' ) );

		$controller = new Mcpwp_REST_Site_Updates();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/rate-limit', array( 'identifier' => 'key:someone' ) );
		$this->assertTrue( $this->authed( $controller, $request, $admin_key['key'] ) );

		$response = $controller->reset_rate_limit( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_message() : '' );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * The GET status route stays reachable at any scope — only the mutating
	 * rate-limit routes require admin. Unaffected by this fix; pinned here so
	 * a future change to can_manage_api_keys() cannot accidentally widen it
	 * to gate the status read too.
	 */
	public function test_a_read_scoped_key_can_still_read_rate_limit_status(): void {
		$read_key = ( new Mcpwp_REST_Site_Updates() )->create_scoped_api_key( 'reader', array( 'read' ) );

		$controller = new Mcpwp_REST_Site_Updates();
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/rate-limit' );
		$this->assertTrue( $this->authed( $controller, $request, $read_key['key'] ) );
	}

	/**
	 * With no matched API key at all (e.g. a genuinely privileged in-process
	 * call), can_manage_api_keys() falls back to the real WordPress
	 * capability — administrator-only after this fix.
	 */
	public function test_falls_back_to_the_wordpress_capability_when_no_key_is_matched(): void {
		$controller = new Mcpwp_REST_Site_Updates();

		$method = new ReflectionMethod( $controller, 'can_manage_api_keys' );
		$method->setAccessible( true );

		// bootstrap.php's current_user_can() grants everything by default
		// when no per-user cap map is configured for the current test user.
		$this->assertNull( $controller->get_current_api_key_record() );
		$this->assertTrue( $method->invoke( $controller ) );
	}
}
