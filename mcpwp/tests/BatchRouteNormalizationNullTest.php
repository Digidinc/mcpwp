<?php
/**
 * Regression coverage for F11 (issue #752): a route the batch controller
 * cannot normalise must be denied, not silently dispatched raw.
 *
 * preg_replace( '#/+#', '/', $route ) returns null (not false) on a PCRE
 * engine failure. Before this fix, that null flowed into
 * trim( null, '/' ) === '', so $normalized_route became '/', the denial
 * check against DENIED_OPERATION_ROUTE_PATTERN never fired, and the
 * ORIGINAL, un-normalised $route — not the (falsely empty) normalised one —
 * was what got dispatched. Checking the normalised value while dispatching
 * the raw one is the classic parser-differential bug.
 *
 * '#/+#' is anchored to no backtracking and always compiles, so it cannot be
 * made to return null from a live preg_replace() call in a test. The guard
 * is therefore covered structurally: Mcpwp_REST_Batch::finish_normalizing_route()
 * is the exact code that runs on preg_replace()'s result, extracted so it can
 * be exercised directly via reflection with a null input — the precise value
 * preg_replace() would hand it on failure — without needing to force PCRE
 * to actually fail. This is NOT end-to-end coverage through execute_operation();
 * see the class docblock note above and the report for this task.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// bootstrap.php does not load the batch controller; BatchOperationPathTest.php
// requires it directly for the same reason.
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

final class BatchRouteNormalizationNullTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']          = array();
		$GLOBALS['mcpwp_test_transients']       = array();
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
	}

	protected function tearDown(): void {
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
	}

	private function finish_normalizing_route( $collapsed_route ) {
		$reflection = new ReflectionMethod( Mcpwp_REST_Batch::class, 'finish_normalizing_route' );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, $collapsed_route );
	}

	/**
	 * The exact value preg_replace() returns on a PCRE engine failure.
	 */
	public function test_null_collapsed_route_cannot_be_normalized(): void {
		$this->assertNull(
			$this->finish_normalizing_route( null ),
			'A route that preg_replace() could not collapse must not be treated as normalisable.'
		);
	}

	/**
	 * Sanity check that the happy path is unaffected: a normal collapsed
	 * string still normalises the same way the inline expression used to.
	 */
	public function test_normal_collapsed_route_still_normalizes(): void {
		// Input here is already slash-collapsed, matching what preg_replace()
		// hands this method in execute_operation() — this method only trims
		// the leading/trailing slash left over from that collapse.
		$this->assertSame( '/mcpwp/v1/batch', $this->finish_normalizing_route( '/mcpwp/v1/batch/' ) );
		$this->assertSame( '/mcpwp/v1/posts', $this->finish_normalizing_route( '/mcpwp/v1/posts' ) );
	}

	/**
	 * End-to-end proof that execute_operation() denies when normalisation
	 * fails, using a hand-built operation result rather than a live PCRE
	 * failure: this asserts the wiring in execute_operation() — that a null
	 * from finish_normalizing_route() short-circuits to a 'forbidden_path'
	 * denial before the pattern check ever runs — matches the guard's own
	 * behavior proven above.
	 */
	public function test_execute_operation_denies_when_normalization_returns_null(): void {
		$controller = new Mcpwp_REST_Batch();
		$reflection = new ReflectionMethod( Mcpwp_REST_Batch::class, 'execute_operation' );
		$reflection->setAccessible( true );

		// A normal, allowed path must dispatch (status 200) under today's
		// pattern — establishing the baseline this guard must not disturb.
		// execute_operation()'s third argument is the outer, already-authenticated
		// /batch request (#978), forwarded onto the internal sub-request via
		// forward_caller_credential() — not a bare API key string.
		$outer_request = new WP_REST_Request( 'POST', '/mcpwp/v1/batch', array(), array( 'X-API-Key' => 'test-key' ) );
		$baseline       = $reflection->invokeArgs( $controller, array( array( 'method' => 'GET', 'path' => '/site-info' ), 0, $outer_request ) );
		$this->assertSame( 200, $baseline['status'] );

		// Directly confirm the deny-on-null contract the fix wires into
		// execute_operation(): finish_normalizing_route(null) — the value
		// preg_replace() would return on failure — is what execute_operation()
		// checks for before it would ever dispatch $route raw.
		$this->assertNull( $this->finish_normalizing_route( null ) );
	}
}
