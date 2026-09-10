<?php
/**
 * Coverage for #802 — a bulk job needs one round trip per hundred operations,
 * and the budget has to count what actually ran.
 *
 * Per-record work (33 team members × 5 meta = 165 calls at 2–3 s each) was
 * impractical one call at a time, so the reporter wrote their own client.
 * Both batch surfaces existed and both were capped too low to help: the
 * REST /batch at 25 operations, the JSON-RPC batch at 10 requests.
 *
 * Raising a cap without weighting it would turn the budget into fiction: a
 * JSON-RPC batch was metered as ONE request whatever it carried, so 100
 * writes cost the same slot as one. The rate limiter now takes a weight, and
 * both batch surfaces charge one slot per operation. Sub-requests inside a
 * batch were already exempt from counting (Mcpwp_REST_Batch::$is_batch_sub_request),
 * so nothing is charged twice.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class BatchBudgetTest extends TestCase {

	/** @var array{key:string,id:string} */
	private $key;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();

		$this->key = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'bulk', array( 'admin' ) );

		Mcpwp_Rate_Limiter::get_instance()->update_settings(
			array(
				'enabled'             => true,
				'requests_per_minute' => 10,
				'requests_per_hour'   => 1000,
				'burst_limit'         => 10,
				'whitelist'           => array(),
			)
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
	}

	private function rpc_batch( int $n ): array {
		$ops = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$ops[] = array(
				'jsonrpc' => '2.0',
				'id'      => $i,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'wp_update_page',
					'arguments' => array( 'id' => $i, 'title' => 'T' . $i ),
				),
			);
		}
		return $ops;
	}

	private function mcp_request( $body ): WP_REST_Request {
		return new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $this->key['key'] ), $body );
	}

	private function rest_batch_request( int $n ): WP_REST_Request {
		$ops = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$ops[] = array( 'method' => 'GET', 'path' => '/posts/' . $i );
		}
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/batch', array(), array( 'X-API-Key' => $this->key['key'] ) );
		$request->set_param( 'operations', $ops );
		return $request;
	}

	// ---------------------------------------------------------------
	// The limiter takes a weight.
	// ---------------------------------------------------------------

	public function test_a_weighted_check_consumes_that_many_slots(): void {
		$limiter = Mcpwp_Rate_Limiter::get_instance();

		$this->assertTrue( $limiter->check_limit( 'w', 'POST', null, 4 ) );
		$this->assertSame( 6, $limiter->get_budget()['remaining'] );

		$this->assertTrue( $limiter->check_limit( 'w', 'POST', null, 4 ) );
		$this->assertSame( 2, $limiter->get_budget()['remaining'] );

		$refused = $limiter->check_limit( 'w', 'POST', null, 4 );
		$this->assertInstanceOf( WP_Error::class, $refused, '2 left, 4 asked: refused before any of the 4 runs, not after 2 of them.' );
		$this->assertSame( 2, $refused->get_error_data()['remaining'], 'The refusal says what IS left, so a client can shrink the batch.' );
	}

	public function test_a_weight_larger_than_the_whole_limit_is_refused_with_the_ceiling(): void {
		$refused = Mcpwp_Rate_Limiter::get_instance()->check_limit( 'w2', 'POST', null, 11 );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 10, $refused->get_error_data()['limit'] );
	}

	public function test_a_weight_below_one_counts_as_one(): void {
		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$limiter->check_limit( 'w3', 'POST', null, 0 );

		$this->assertSame( 9, $limiter->get_budget()['remaining'] );
	}

	// ---------------------------------------------------------------
	// Both batch surfaces are charged per operation.
	// ---------------------------------------------------------------

	public function test_a_json_rpc_batch_is_charged_one_slot_per_operation(): void {
		$controller = new Mcpwp_REST_MCP();

		$this->assertTrue( $controller->verify_api_key( $this->mcp_request( $this->rpc_batch( 3 ) ) ) );
		$this->assertSame( 7, Mcpwp_Rate_Limiter::get_instance()->get_budget()['remaining'], 'Three writes cost three slots, not one.' );
	}

	public function test_a_json_rpc_batch_beyond_the_budget_is_refused_whole(): void {
		$controller = new Mcpwp_REST_MCP();

		$this->assertTrue( $controller->verify_api_key( $this->mcp_request( $this->rpc_batch( 8 ) ) ) );
		$refused = $controller->verify_api_key( $this->mcp_request( $this->rpc_batch( 3 ) ) );

		// All-or-nothing at the door. Running 2 of 3 and then 429ing is the
		// mid-write corruption the issue opens with.
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'rate_limit_exceeded', $refused->get_error_code() );
	}

	public function test_a_rest_batch_is_charged_one_slot_per_operation(): void {
		$controller = new Mcpwp_REST_Batch();

		$this->assertTrue( $controller->verify_api_key( $this->rest_batch_request( 4 ) ) );
		$this->assertSame( 6, Mcpwp_Rate_Limiter::get_instance()->get_budget()['remaining'] );
	}

	// ---------------------------------------------------------------
	// The caps.
	// ---------------------------------------------------------------

	public function test_a_hundred_operations_fit_one_json_rpc_round_trip(): void {
		Mcpwp_Rate_Limiter::get_instance()->update_settings( array( 'requests_per_minute' => 1000, 'burst_limit' => 1000 ) );

		$controller = new Mcpwp_REST_MCP();
		$response   = $controller->handle_mcp( $this->mcp_request( $this->rpc_batch( 100 ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 100, $response->get_data(), 'Per-op status: one response per request, in order.' );
		$this->assertSame( 100, count( $GLOBALS['mcpwp_test_dispatched_routes'] ) );
	}

	public function test_a_hundred_and_one_is_refused_before_anything_runs(): void {
		$controller = new Mcpwp_REST_MCP();
		$response   = $controller->handle_mcp( $this->mcp_request( $this->rpc_batch( 101 ) ) );
		$data       = $response->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertStringContainsString( '100', $data['error']['message'] );
		$this->assertSame( 0, count( $GLOBALS['mcpwp_test_dispatched_routes'] ) );
	}

	public function test_the_rest_batch_cap_matches(): void {
		$this->assertSame( 100, Mcpwp_REST_Batch::MAX_OPERATIONS );

		$controller = new Mcpwp_REST_Batch();
		$response   = $controller->handle_batch( $this->rest_batch_request( 101 ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'batch_too_large', $response->get_error_code() );
	}

	public function test_a_rest_batch_reports_how_many_succeeded_and_failed(): void {
		$controller = new Mcpwp_REST_Batch();
		$response   = $controller->handle_batch( $this->rest_batch_request( 3 ) );
		$data       = $response->get_data();

		// The reporter's client had to walk every result to learn whether the
		// batch worked. Say it up front — and keep the per-op detail.
		$this->assertSame( 3, $data['total'] );
		$this->assertArrayHasKey( 'succeeded', $data );
		$this->assertArrayHasKey( 'failed', $data );
		$this->assertSame( $data['total'], $data['succeeded'] + $data['failed'] );
		$this->assertCount( 3, $data['results'] );
	}
}
