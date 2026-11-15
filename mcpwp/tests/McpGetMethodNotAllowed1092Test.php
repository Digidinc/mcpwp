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
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class McpGetMethodNotAllowed1092Test extends TestCase {

	/** @var array */
	private $key;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		$GLOBALS['mcpwp_test_wpdb_options']      = array();
		Mcpwp_REST_MCP::reset_verdict_memo();

		$this->key = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'get-405', array( 'admin' ) );

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
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
	}

	private function get_request( array $headers = array() ): WP_REST_Request {
		return new WP_REST_Request( 'GET', '/mcpwp/v1/mcp', array(), $headers );
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

	public function test_authenticated_get_answers_405_with_allow_post_and_jsonrpc_error(): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = $this->get_request(
			array(
				'X-API-Key' => $this->key['key'],
				'Accept'    => 'text/event-stream',
			)
		);

		$this->assertTrue( $controller->check_permission( $request ), 'Premise: the key authenticates.' );

		$response = $controller->handle_mcp_get( $request );
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
		$controller = new Mcpwp_REST_MCP();
		$request    = $this->get_request();

		$result = $controller->check_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, (int) $result->get_error_data()['status'] );
		$this->assertNotSame( 405, (int) $result->get_error_data()['status'] );
	}

	public function test_invalid_credential_get_still_refuses_with_auth_error_not_405(): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = $this->get_request( array( 'X-API-Key' => 'mcpwp_' . str_repeat( '0', 48 ) ) );

		$result = $controller->check_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, (int) $result->get_error_data()['status'] );
	}

	public function test_post_initialize_is_unchanged(): void {
		$controller = new Mcpwp_REST_MCP();
		$body       = array(
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
		$controller->verify_api_key( $request );

		$response = $controller->handle_mcp( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertSame( '2025-06-18', $data['result']['protocolVersion'] );
		$this->assertArrayHasKey( 'name', $data['result']['serverInfo'] );
		$this->assertNotSame( '', $data['result']['serverInfo']['name'] );
	}

	public function test_rest_post_dispatch_pins_allow_post_after_core_allow_header(): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = $this->get_request( array( 'X-API-Key' => $this->key['key'] ) );
		$controller->verify_api_key( $request );

		$response = $controller->handle_mcp_get( $request );
		// Simulate WordPress core rest_send_allow_header overwriting Allow with
		// every method whose permission_callback passed (GET included).
		$response->header( 'Allow', 'GET, POST, OPTIONS' );

		$pinned = $controller->pin_mcp_get_allow_header( $response, null, $request );

		$this->assertSame( 405, $pinned->get_status() );
		$this->assertSame( 'POST', $this->allow_header( $pinned ) );
	}
}
