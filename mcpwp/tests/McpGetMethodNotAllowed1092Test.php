<?php
/**
 * #1092 — GET /mcp must not pretend to be an SSE stream or a capability probe.
 *
 * Streamable HTTP: a server that does not offer a server-initiated SSE stream
 * answers GET with 405 Method Not Allowed and SHOULD send Allow: POST. Auth
 * still wins first — a missing/invalid credential is the same 401 refusal as
 * today (WWW-Authenticate when OAuth is on), never a 405 that would hide the
 * challenge. POST is unchanged.
 *
 * Dispatch-level cases route through mcpwp_test_match_rest_route() — the suite's
 * rest_do_request() double does not match handlers — then run permission +
 * callback the way core would, and apply rest_post_dispatch (WWW-Authenticate
 * + the Allow pin).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/helpers/rest-route-uniqueness.php';

final class McpGetMethodNotAllowed1092Test extends TestCase {

	/** @var array */
	private $key;

	/** @var Mcpwp_REST_MCP */
	private $controller;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 2;
		$GLOBALS['mcpwp_test_filters']            = array();
		$GLOBALS['mcpwp_test_dispatched_routes']  = array();
		$GLOBALS['mcpwp_test_wpdb_options']       = array();
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		Mcpwp_REST_MCP::reset_verdict_memo();

		$this->controller = new Mcpwp_REST_MCP();
		$this->controller->register_routes();
		// Discovery challenge on 401 (same filter production registers in init()).
		add_filter( 'rest_post_dispatch', array( 'Mcpwp_OAuth_Well_Known', 'add_www_authenticate_header' ), 10, 3 );
		$this->key = $this->controller->create_scoped_api_key( 'get-405', array( 'read', 'write', 'admin' ) );

		Mcpwp_Rate_Limiter::get_instance()->update_settings(
			array(
				'enabled'             => true,
				'requests_per_minute' => 500,
				'requests_per_hour'   => 5000,
				'burst_limit'         => 500,
				'whitelist'           => array(),
			)
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
	}

	private function get_request( array $headers = array(), string $route = '/mcpwp/v1/mcp' ): WP_REST_Request {
		return new WP_REST_Request( 'GET', $route, array(), $headers );
	}

	private function allow_header( WP_REST_Response $response ): string {
		$headers = $response->get_headers();
		foreach ( array( 'Allow', 'allow' ) as $key ) {
			if ( isset( $headers[ $key ] ) ) {
				return (string) $headers[ $key ];
			}
		}
		return '';
	}

	/**
	 * Core-shaped dispatch: match the registered /mcp handlers, run
	 * permission_callback, then callback (or error_to_response on refusal),
	 * set_matched_route, then rest_post_dispatch.
	 *
	 * @return WP_REST_Response
	 */
	private function dispatch( string $method, string $path, array $headers = array() ): WP_REST_Response {
		$match = mcpwp_test_match_rest_route( rest_get_server()->get_routes(), $method, $path );
		$this->assertNotNull( $match, "$method $path matched no route" );

		$request = new WP_REST_Request( $method, $path, array(), $headers );
		$perm    = call_user_func( $match['handler']['permission_callback'], $request );
		$server  = new WP_REST_Server();

		if ( is_wp_error( $perm ) ) {
			$response = $server->error_to_response( $perm );
		} elseif ( true !== $perm ) {
			$response = $server->error_to_response(
				new WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', array( 'status' => 403 ) )
			);
		} else {
			$response = call_user_func( $match['handler']['callback'], $request );
			if ( is_wp_error( $response ) ) {
				$response = $server->error_to_response( $response );
			} else {
				$response = rest_ensure_response( $response );
			}
		}

		if ( method_exists( $response, 'set_matched_route' ) ) {
			$response->set_matched_route( $match['route'] );
		}

		return apply_filters( 'rest_post_dispatch', $response, $server, $request );
	}

	public function test_authenticated_get_answers_405_with_allow_post_and_jsonrpc_error(): void {
		$request = $this->get_request(
			array(
				'X-API-Key' => $this->key['key'],
				'Accept'    => 'text/event-stream',
			)
		);

		$this->assertTrue( $this->controller->check_permission( $request ), 'Premise: the key authenticates.' );

		$response = $this->controller->handle_mcp_get( $request );
		$data     = $response->get_data();

		$this->assertSame( 405, $response->get_status() );
		$this->assertSame( 'POST', $this->allow_header( $response ) );
		$this->assertSame( 'application/json; charset=UTF-8', $response->get_headers()['Content-Type'] );
		$this->assertSame( '2.0', $data['jsonrpc'] );
		$this->assertNull( $data['id'] );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertArrayNotHasKey( 'result', $data );
		$this->assertIsInt( $data['error']['code'] );
		$this->assertNotSame( '', $data['error']['message'] );
	}

	public function test_unauthenticated_get_still_refuses_with_auth_error_not_405(): void {
		$result = $this->controller->check_permission( $this->get_request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, (int) $result->get_error_data()['status'] );
		$this->assertNotSame( 405, (int) $result->get_error_data()['status'] );
	}

	public function test_invalid_credential_get_still_refuses_with_auth_error_not_405(): void {
		$result = $this->controller->check_permission(
			$this->get_request( array( 'X-API-Key' => 'mcpwp_' . str_repeat( '0', 48 ) ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, (int) $result->get_error_data()['status'] );
	}

	public function test_post_initialize_is_unchanged(): void {
		$body    = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(
				'protocolVersion' => '2025-06-18',
				'clientInfo'      => array(
					'name'    => 'phpunit-1092',
					'version' => '1.0',
				),
			),
		);
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $this->key['key'] ),
			$body
		);
		$this->controller->verify_api_key( $request );

		$response = $this->controller->handle_mcp( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertSame( '2025-06-18', $data['result']['protocolVersion'] );
		$this->assertArrayHasKey( 'name', $data['result']['serverInfo'] );
		$this->assertNotSame( '', $data['result']['serverInfo']['name'] );
	}

	public function test_rest_post_dispatch_pins_allow_post_after_core_allow_header(): void {
		$request = $this->get_request( array( 'X-API-Key' => $this->key['key'] ) );
		$this->controller->verify_api_key( $request );

		$response = $this->controller->handle_mcp_get( $request );
		// Simulate WordPress core rest_send_allow_header overwriting Allow with
		// every method whose permission_callback passed (GET included).
		$response->header( 'Allow', 'GET, POST, OPTIONS' );

		$pinned = $this->controller->pin_mcp_get_allow_header( $response, null, $request );

		$this->assertSame( 405, $pinned->get_status() );
		$this->assertSame( 'POST', $this->allow_header( $pinned ) );
	}

	public function test_allow_pin_matches_mixed_case_route_via_matched_route(): void {
		$request = $this->get_request( array( 'X-API-Key' => $this->key['key'] ), '/MCPWP/v1/MCP' );
		$this->controller->verify_api_key( $request );

		$response = $this->controller->handle_mcp_get( $request );
		$response->header( 'Allow', 'GET, POST, OPTIONS' );
		$response->set_matched_route( '/mcpwp/v1/mcp' );

		$pinned = $this->controller->pin_mcp_get_allow_header( $response, null, $request );

		$this->assertSame( 'POST', $this->allow_header( $pinned ), 'Matched route (folded) must pin even when get_route() keeps request case.' );
	}

	public function test_allow_pin_matches_mixed_case_get_route_when_matched_route_empty(): void {
		$request = $this->get_request( array( 'X-API-Key' => $this->key['key'] ), '/MCPWP/v1/MCP' );
		$this->controller->verify_api_key( $request );

		$response = $this->controller->handle_mcp_get( $request );
		$response->header( 'Allow', 'GET, POST, OPTIONS' );
		// No set_matched_route — fold get_route() the way authorization does.

		$pinned = $this->controller->pin_mcp_get_allow_header( $response, null, $request );

		$this->assertSame( 'POST', $this->allow_header( $pinned ) );
	}

	public function test_mcp_write_handler_registers_post_only(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/mcpwp/v1/mcp', $routes );

		$methods = array();
		foreach ( $routes['/mcpwp/v1/mcp'] as $handler ) {
			foreach ( array_keys( $handler['methods'] ) as $method ) {
				$methods[ $method ] = true;
			}
		}

		$this->assertArrayHasKey( 'POST', $methods );
		$this->assertArrayHasKey( 'GET', $methods );
		$this->assertArrayHasKey( 'OPTIONS', $methods );
		$this->assertArrayNotHasKey( 'PUT', $methods );
		$this->assertArrayNotHasKey( 'PATCH', $methods );
		$this->assertNull( mcpwp_test_match_rest_route( $routes, 'PUT', '/mcpwp/v1/mcp' ) );
		$this->assertNull( mcpwp_test_match_rest_route( $routes, 'PATCH', '/mcpwp/v1/mcp' ) );
	}

	public function test_dispatch_unauthenticated_get_is_401_with_www_authenticate_when_discovery_on(): void {
		update_option(
			'mcpwp_settings',
			array(
				'oauth_enabled'       => true,
				'oauth_client_id'     => 'test_client',
				'oauth_token_ttl'     => 3600,
				'oauth_redirect_uris' => array(),
			)
		);

		$response = $this->dispatch( 'GET', '/mcpwp/v1/mcp' );
		$headers  = $response->get_headers();

		$this->assertSame( 401, $response->get_status() );
		$this->assertArrayHasKey( 'WWW-Authenticate', $headers );
		$this->assertStringContainsString( 'Bearer', $headers['WWW-Authenticate'] );
		$this->assertStringContainsString( 'resource_metadata=', $headers['WWW-Authenticate'] );
		$this->assertNotSame( 'POST', $this->allow_header( $response ), 'Allow pin must not rewrite a 401.' );
	}

	public function test_dispatch_head_is_405_with_allow_and_no_body(): void {
		$response = $this->dispatch( 'HEAD', '/mcpwp/v1/mcp', array( 'X-API-Key' => $this->key['key'] ) );

		$this->assertSame( 405, $response->get_status() );
		$this->assertSame( 'POST', $this->allow_header( $response ) );
		$this->assertNull( $response->get_data() );
	}

	public function test_dispatch_authenticated_get_is_405_with_allow(): void {
		$response = $this->dispatch(
			'GET',
			'/mcpwp/v1/mcp',
			array(
				'X-API-Key' => $this->key['key'],
				'Accept'    => 'text/event-stream',
			)
		);
		$data = $response->get_data();

		$this->assertSame( 405, $response->get_status() );
		$this->assertSame( 'POST', $this->allow_header( $response ) );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'error', $data );
	}

	public function test_allow_pin_leaves_non_405_and_other_routes_alone(): void {
		$get = $this->get_request( array( 'X-API-Key' => $this->key['key'] ) );

		$ok = new WP_REST_Response( array( 'ok' => true ), 200 );
		$ok->header( 'Allow', 'GET, POST' );
		$ok->set_matched_route( '/mcpwp/v1/mcp' );
		$untouched = $this->controller->pin_mcp_get_allow_header( $ok, null, $get );
		$this->assertSame( 'GET, POST', $this->allow_header( $untouched ), 'Non-405 /mcp must not be rewritten.' );

		$other = new WP_REST_Response( array( 'code' => 'rest_no_route' ), 405 );
		$other->header( 'Allow', 'GET, POST, OPTIONS' );
		$other->set_matched_route( '/mcpwp/v1/site-info' );
		$site = new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $this->key['key'] ) );
		$other_pinned = $this->controller->pin_mcp_get_allow_header( $other, null, $site );
		$this->assertSame( 'GET, POST, OPTIONS', $this->allow_header( $other_pinned ), 'A different route must not get the /mcp Allow pin.' );
	}

	public function test_capability_map_get_mcp_reason_is_not_a_jsonrpc_envelope(): void {
		$row = Mcpwp_Route_Capability_Map::rows()['GET /mcp'];
		$this->assertStringContainsString( '405', $row[2] );
		$this->assertStringNotContainsString( 'JSON-RPC envelope', $row[2] );
	}
}
