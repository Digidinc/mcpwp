<?php
/**
 * Coverage for #802 — "A 100-op wp_batch completes in one round trip with
 * per-op status."
 *
 * The REST /batch controller already did the work: per-operation dispatch,
 * the /batch and /mcp denial, per-op auth, partial-failure semantics. What was
 * missing was an MCP tool with the name the issue asks for, so an agent that
 * lives inside MCP never has to leave it for a bulk job. wp_batch dispatches
 * through that controller unchanged — every protection below is the
 * controller's, and these tests pin that none of them was bypassed on the way
 * in.
 *
 * The stubbed rest_do_request() does not route, so the one route under test is
 * routed here through core's rest_dispatch_request hook: tools/call really
 * reaches Mcpwp_REST_Batch::handle_batch(), and each operation really reaches
 * rest_do_request().
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

final class WpBatchToolTest extends TestCase {

	/** @var array */
	private $key;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		$GLOBALS['mcpwp_test_refused_routes']    = array();
		$GLOBALS['mcpwp_test_wpdb_options']      = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
		Mcpwp_REST_Batch::$is_batch_sub_request = false;

		$this->key = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'bulk', array( 'admin' ) );

		Mcpwp_Rate_Limiter::get_instance()->update_settings(
			array(
				'enabled'             => true,
				'requests_per_minute' => 1000,
				'requests_per_hour'   => 5000,
				'burst_limit'         => 1000,
				'whitelist'           => array(),
			)
		);

		add_filter( 'rest_dispatch_request', array( $this, 'route_batch' ), 10, 4 );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		$GLOBALS['mcpwp_test_refused_routes']    = array();
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
	}

	/**
	 * Core's router, for the one route under test.
	 */
	public function route_batch( $result, $request, $route = '', $handler = array() ) {
		if ( null === $result && preg_match( '#^/mcpwp/v1/batch$#i', (string) $route ) ) {
			return ( new Mcpwp_REST_Batch() )->handle_batch( $request );
		}
		return $result;
	}

	private function request( string $tool, array $arguments, $request_id = null, $key = null ): WP_REST_Request {
		$params = array( 'name' => $tool, 'arguments' => $arguments );
		if ( null !== $request_id ) {
			$params['_meta'] = array( 'request_id' => $request_id );
		}
		return new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $key ?: $this->key['key'] ),
			array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params )
		);
	}

	private function call( string $tool, array $arguments, $request_id = null ): array {
		$controller = new Mcpwp_REST_MCP();
		$request    = $this->request( $tool, $arguments, $request_id );
		$this->assertTrue( $controller->verify_api_key( $request ), 'Test premise: the key authenticates.' );

		return $controller->handle_mcp( $request )->get_data();
	}

	private function body( array $data ): array {
		$this->assertArrayHasKey( 'result', $data, 'Expected a result, got: ' . wp_json_encode( $data ) );
		return json_decode( $data['result']['content'][0]['text'], true );
	}

	private function reads( int $n ): array {
		$ops = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$ops[] = array( 'method' => 'GET', 'path' => '/posts/' . $i );
		}
		return $ops;
	}

	private function operation_dispatches(): array {
		return array_values(
			array_filter(
				$GLOBALS['mcpwp_test_dispatched_routes'],
				function ( $route ) {
					return '/mcpwp/v1/batch' !== $route;
				}
			)
		);
	}

	// ---------------------------------------------------------------
	// Registration.
	// ---------------------------------------------------------------

	public function test_wp_batch_is_a_free_content_tool_that_maps_to_the_batch_controller(): void {
		$registry = new Mcpwp_MCP_Free_Tools();
		$map      = $registry->get_tool_map();

		$this->assertArrayHasKey( 'wp_batch', $map );
		$this->assertSame( 'POST', $map['wp_batch']['method'] );
		$this->assertSame( '/batch', $map['wp_batch']['route'], 'The existing controller, not a second implementation.' );
		$this->assertSame( 'content', $registry->get_tool_categories()['wp_batch'] );

		$tool = null;
		foreach ( $registry->get_tools() as $candidate ) {
			if ( 'wp_batch' === $candidate['name'] ) {
				$tool = $candidate;
			}
		}
		$this->assertNotNull( $tool, 'wp_batch is advertised in tools/list.' );
		$this->assertSame( array( 'operations' ), $tool['inputSchema']['required'] );

		$operations = $tool['inputSchema']['properties']['operations'];
		$this->assertSame( 'array', $operations['type'] );
		$this->assertSame( Mcpwp_REST_Batch::MAX_OPERATIONS, $operations['maxItems'], 'The schema states the controller\'s cap, so a client can refuse 101 before spending slots on it.' );
		$this->assertSame( array( 'method', 'path' ), $operations['items']['required'] );
		$this->assertSame( array( 'GET', 'POST', 'PUT', 'DELETE' ), $operations['items']['properties']['method']['enum'] );
	}

	public function test_wp_batch_needs_the_same_scope_as_the_batch_tool_it_sits_beside(): void {
		$scope      = new ReflectionMethod( Mcpwp_REST_MCP::class, 'get_required_scope_for_tool_name' );
		$scope->setAccessible( true );
		$controller = new Mcpwp_REST_MCP();

		$this->assertSame( 'admin', $scope->invoke( $controller, 'wp_batch' ) );
		$this->assertSame( $scope->invoke( $controller, 'wp_batch_update' ), $scope->invoke( $controller, 'wp_batch' ) );

		// And the gate uses it: a write-scoped key is refused at the door.
		$writer  = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'writer', array( 'write' ) );
		$verdict = ( new Mcpwp_REST_MCP() )->verify_api_key( $this->request( 'wp_batch', array( 'operations' => $this->reads( 1 ) ), null, $writer['key'] ) );

		$this->assertInstanceOf( WP_Error::class, $verdict );
		$this->assertSame( 'insufficient_scope', $verdict->get_error_code() );
	}

	// ---------------------------------------------------------------
	// The acceptance criterion.
	// ---------------------------------------------------------------

	public function test_a_hundred_operation_wp_batch_completes_in_one_round_trip_with_per_op_status(): void {
		$data = $this->call( 'wp_batch', array( 'operations' => $this->reads( 100 ) ) );
		$body = $this->body( $data );

		$this->assertFalse( $data['result']['isError'] );
		$this->assertSame( 100, $body['total'] );
		$this->assertSame( 100, $body['succeeded'] );
		$this->assertSame( 0, $body['failed'] );
		$this->assertCount( 100, $body['results'] );
		foreach ( $body['results'] as $position => $result ) {
			$this->assertSame( $position, $result['index'] );
			$this->assertSame( 200, $result['status'] );
		}

		$this->assertCount( 100, $this->operation_dispatches(), 'Every operation really dispatched.' );
		$this->assertContains( '/mcpwp/v1/batch', $GLOBALS['mcpwp_test_dispatched_routes'], 'Through the batch controller.' );
	}

	public function test_a_wp_batch_is_charged_one_rate_limit_slot_per_operation(): void {
		$this->call( 'wp_batch', array( 'operations' => $this->reads( 100 ) ) );

		$this->assertSame( 900, Mcpwp_Rate_Limiter::get_instance()->get_budget()['remaining'], 'One slot for the HTTP request, 99 more for the rest of the operations.' );
	}

	// ---------------------------------------------------------------
	// The controller's protections survive the new door.
	// ---------------------------------------------------------------

	public function test_operations_still_cannot_reach_the_batch_or_mcp_entry_points_so_wp_batch_cannot_recurse(): void {
		$body = $this->body(
			$this->call(
				'wp_batch',
				array(
					'operations' => array(
						array( 'method' => 'POST', 'path' => '/mcp', 'body' => array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_batch' ) ) ),
						array( 'method' => 'POST', 'path' => '/batch', 'body' => array( 'operations' => $this->reads( 100 ) ) ),
						array( 'method' => 'POST', 'path' => '//Batch/' ),
						array( 'method' => 'PATCH', 'path' => '/posts/1' ),
						array( 'method' => 'GET', 'path' => '/posts/1' ),
					),
				)
			)
		);

		$this->assertSame( 400, $body['results'][0]['status'] );
		$this->assertSame( 'forbidden_path', $body['results'][0]['data']['code'] );
		$this->assertSame( 'forbidden_path', $body['results'][1]['data']['code'] );
		$this->assertSame( 'forbidden_path', $body['results'][2]['data']['code'] );
		$this->assertSame( 'invalid_method', $body['results'][3]['data']['code'] );
		$this->assertSame( 200, $body['results'][4]['status'], 'A refused operation does not abort the rest.' );
		$this->assertSame( 4, $body['failed'] );
		$this->assertSame( array( '/mcpwp/v1/posts/1' ), $this->operation_dispatches() );
	}

	public function test_a_wp_batch_with_a_failed_operation_is_not_pinned_and_a_clean_one_is(): void {
		$partial = $this->call(
			'wp_batch',
			array( 'operations' => array( array( 'method' => 'GET', 'path' => '/posts/1' ), array( 'method' => 'POST', 'path' => '/mcp' ) ) ),
			'job:partial'
		);
		$this->assertSame( 'not_stored', $partial['result']['_meta']['idempotency'], 'A retry must be allowed to re-run the failed operation.' );

		Mcpwp_REST_MCP::reset_verdict_memo();
		$clean = $this->call( 'wp_batch', array( 'operations' => $this->reads( 2 ) ), 'job:clean' );
		$this->assertSame( 'stored', $clean['result']['_meta']['idempotency'] );
		$ran = count( $this->operation_dispatches() );

		Mcpwp_REST_MCP::reset_verdict_memo();
		$again = $this->call( 'wp_batch', array( 'operations' => $this->reads( 2 ) ), 'job:clean' );
		$this->assertTrue( $again['result']['_meta']['replayed'] );
		$this->assertSame( $ran, count( $this->operation_dispatches() ), 'A replayed batch runs nothing.' );
	}
}
