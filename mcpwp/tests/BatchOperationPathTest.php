<?php
/**
 * Regression coverage for batch operation-path denial.
 *
 * /batch and /mcp both mark their internal dispatches so the auth layer skips
 * rate counting. An operation targeting either of them turns one counted
 * request into a tree of uncounted ones, with the per-call operation ceiling
 * re-applying fresh at every level. Operations must therefore be refused when
 * their resolved route lands on those endpoints — and refused individually,
 * without aborting the rest of the batch.
 *
 * The static sub-request flag is shared with the MCP tool dispatcher, which
 * sets it around every tools/call — including the built-in tool that maps to
 * /batch. Guarding on that flag would break every legitimate batch invoked
 * over MCP, so the guard is path-based and the flag is saved and restored
 * rather than reset. Both properties are pinned below.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

final class BatchOperationPathTest extends TestCase {

	private Mcpwp_REST_Batch $controller;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_dispatched_routes']  = array();
		Mcpwp_REST_Batch::$is_batch_sub_request   = false;
		$this->controller                         = new Mcpwp_REST_Batch();
	}

	protected function tearDown(): void {
		Mcpwp_REST_Batch::$is_batch_sub_request  = false;
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
	}

	private function run_operation( array $operation, int $index = 0 ): array {
		$reflection = new ReflectionMethod( Mcpwp_REST_Batch::class, 'execute_operation' );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $this->controller, array( $operation, $index, 'test-key' ) );
	}

	public function denied_path_provider(): array {
		return array(
			'batch'                => array( '/batch' ),
			'batch upper'          => array( '/Batch' ),
			'batch mixed'          => array( '/bAtCh' ),
			'batch no slash'       => array( 'batch' ),
			'batch double slash'   => array( '//batch' ),
			'batch trailing slash' => array( '/batch/' ),
			'batch child'          => array( '/batch/anything' ),
			'mcp'                  => array( '/mcp' ),
			'mcp upper'            => array( '/MCP' ),
			'mcp trailing slash'   => array( '/mcp/' ),
			'mcp no slash'         => array( 'mcp' ),
			// A query string or fragment terminates the route just as a slash
			// does. WordPress' anchored matcher rejects these forms today, but
			// the denial must not lean on that downstream accident.
			'batch query'          => array( '/batch?x=1' ),
			'batch query upper'    => array( '/Batch?x=1' ),
			'batch fragment'       => array( '/batch#f' ),
			'mcp query'            => array( '/mcp?a=b' ),
			'mcp fragment'         => array( '/mcp#frag' ),
			// #752 F12: the terminator is "not a route-name character", not a
			// list of three. An enumerated list has to be right about every
			// character an attacker might append, and the previous one was not
			// — each of these was inert only because WordPress' route matcher
			// is anchored, which is the downstream accident this denial is
			// explicitly not allowed to lean on.
			'batch path parameter' => array( '/batch;p=1' ),
			'mcp path parameter'   => array( '/mcp;p=1' ),
			'batch nul byte'       => array( "/batch\0" ),
			'batch backslash'      => array( '/batch\\x' ),
			'batch extension'      => array( '/batch.json' ),
			'batch encoded slash'  => array( '/batch%2Fx' ),
			'batch trailing space' => array( '/batch ' ),
			'batch leading space'  => array( ' /batch' ),
			'mcp leading tab'      => array( "\t/mcp" ),
		);
	}

	/**
	 * @dataProvider denied_path_provider
	 */
	public function test_denied_operation_paths_are_rejected_without_dispatch( string $path ): void {
		$result = $this->run_operation(
			array(
				'method' => 'POST',
				'path'   => $path,
			)
		);

		$this->assertSame( 400, $result['status'] );
		$this->assertSame( 'forbidden_path', $result['data']['code'] );
		$this->assertFalse(
			Mcpwp_REST_Batch::$is_batch_sub_request,
			'A rejected operation must never mark a sub-request as in flight.'
		);
		$this->assertSame(
			array(),
			$GLOBALS['mcpwp_test_dispatched_routes'],
			'A denied operation must never reach rest_do_request() — zero dispatches, not merely a denial response.'
		);
	}

	public function allowed_path_provider(): array {
		return array(
			'posts'      => array( '/posts' ),
			'page by id' => array( '/pages/12' ),
			'site info'  => array( '/site-info' ),
			'seo audit'  => array( '/seo/audit' ),
			'batchelor'  => array( '/batchelor' ),
			'mcp server' => array( '/mcp-server' ),
			// Hyphenated siblings must survive the terminator, which is why it
			// is an explicit set and not a word boundary.
			'batch status' => array( '/batch-status' ),
		);
	}

	/**
	 * @dataProvider allowed_path_provider
	 */
	public function test_legitimate_operation_paths_still_dispatch( string $path ): void {
		$result = $this->run_operation(
			array(
				'method' => 'GET',
				'path'   => $path,
			)
		);

		$this->assertSame( 200, $result['status'] );
		$this->assertArrayNotHasKey( 'code', (array) $result['data'] );
		$this->assertContains(
			'/mcpwp/v1' . $path,
			$GLOBALS['mcpwp_test_dispatched_routes'],
			'A non-denied operation must actually reach rest_do_request(), not merely avoid the denial branch.'
		);
	}

	public function test_sub_request_flag_is_restored_to_its_previous_value(): void {
		// Simulates the MCP tool dispatcher, which sets this flag around every
		// tools/call — including the built-in tool that maps to /batch.
		Mcpwp_REST_Batch::$is_batch_sub_request = true;

		$this->run_operation(
			array(
				'method' => 'GET',
				'path'   => '/site-info',
			)
		);

		$this->assertTrue(
			Mcpwp_REST_Batch::$is_batch_sub_request,
			'Dispatching an operation must not clear an outer caller\'s sub-request flag.'
		);
	}

	public function test_batch_still_runs_when_the_sub_request_flag_is_already_set(): void {
		// The built-in wp_batch_update tool reaches this controller with the
		// flag already true. Rejecting on that flag would break every such call.
		Mcpwp_REST_Batch::$is_batch_sub_request = true;

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/batch' );
		$request->set_param(
			'operations',
			array(
				array(
					'method' => 'GET',
					'path'   => '/site-info',
				),
				array(
					'method' => 'GET',
					'path'   => '/posts',
				),
			)
		);

		$response = $this->controller->handle_batch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 2, $data['total'] );
		$this->assertSame( 200, $data['results'][0]['status'] );
		$this->assertSame( 200, $data['results'][1]['status'] );
		$this->assertTrue( Mcpwp_REST_Batch::$is_batch_sub_request );
		$this->assertSame(
			array( '/mcpwp/v1/site-info', '/mcpwp/v1/posts' ),
			$GLOBALS['mcpwp_test_dispatched_routes'],
			'Both sub-operations must actually dispatch even when the sub-request flag is already set on entry.'
		);
	}

	public function test_a_denied_operation_does_not_abort_the_batch(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/batch' );
		$request->set_param(
			'operations',
			array(
				array(
					'method' => 'POST',
					'path'   => '/batch',
				),
				array(
					'method' => 'GET',
					'path'   => '/site-info',
				),
			)
		);

		$response = $this->controller->handle_batch( $request );
		$data     = $response->get_data();

		$this->assertSame( 2, $data['total'] );
		$this->assertSame( 400, $data['results'][0]['status'] );
		$this->assertSame( 'forbidden_path', $data['results'][0]['data']['code'] );
		$this->assertSame( 200, $data['results'][1]['status'] );
	}

	public function test_denial_pattern_is_version_agnostic(): void {
		$pattern = Mcpwp_REST_Batch::DENIED_OPERATION_ROUTE_PATTERN;

		$this->assertSame( 1, preg_match( $pattern, '/mcpwp/v2/batch' ) );
		$this->assertSame( 1, preg_match( $pattern, '/mcpwp/v10/mcp' ) );
		$this->assertSame( 0, preg_match( $pattern, '/mcpwp/v1/posts' ) );
	}

	/**
	 * A pattern that fails to compile makes preg_match() return false, and a
	 * falsy result here means the operation is dispatched — this guard fails
	 * open, so its compilation is itself worth asserting. The delimiter must
	 * not be one of the characters the pattern matches.
	 */
	public function test_denial_pattern_compiles(): void {
		$this->assertSame(
			1,
			@preg_match( Mcpwp_REST_Batch::DENIED_OPERATION_ROUTE_PATTERN, '/mcpwp/v1/batch' ),
			'The denial pattern must compile; a compile failure would fail open.'
		);
		$this->assertSame( PREG_NO_ERROR, preg_last_error() );
	}

	/**
	 * The terminator is an explicit character set rather than a word boundary,
	 * so hyphenated and longer sibling routes must still be reachable even
	 * when they carry a query string.
	 */
	public function test_near_miss_routes_are_not_denied(): void {
		$pattern = Mcpwp_REST_Batch::DENIED_OPERATION_ROUTE_PATTERN;

		foreach ( array( '/mcpwp/v1/batch-status', '/mcpwp/v1/mcp-server', '/mcpwp/v1/batchelor', '/mcpwp/v1/posts?x=1' ) as $route ) {
			$this->assertSame( 0, preg_match( $pattern, $route ), $route . ' must not be denied.' );
		}
	}
}
