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

	private function batch_tool_request( int $n ): WP_REST_Request {
		$ops = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$ops[] = array( 'method' => 'PUT', 'path' => '/posts/' . $i, 'body' => array( 'title' => 'T' ) );
		}
		return $this->mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'wp_batch_update', 'arguments' => array( 'operations' => $ops ) ),
			)
		);
	}

	public function test_a_batch_tool_inside_tools_call_is_charged_per_operation(): void {
		// tools/call → wp_batch_update → /batch runs as an internal
		// sub-request, which is exempt from metering. Without this the HTTP
		// request costs one slot and carries a hundred writes.
		$controller = new Mcpwp_REST_MCP();
		$request    = $this->batch_tool_request( 4 );

		$this->assertTrue( $controller->verify_api_key( $request ) );
		$this->assertSame( 9, Mcpwp_Rate_Limiter::get_instance()->get_budget()['remaining'], 'The HTTP request itself.' );

		$controller->handle_mcp( $request );

		$this->assertSame( 6, Mcpwp_Rate_Limiter::get_instance()->get_budget()['remaining'], 'Plus the other three operations.' );
	}

	public function test_every_list_argument_that_dispatches_writes_is_counted(): void {
		// wp_bulk_upload_media takes urls OR items for the same 20 writes; the
		// first cut counted only items, so the price depended on spelling.
		$controller = new Mcpwp_REST_MCP();
		foreach ( array( 'urls' => array( 'https://a/1.jpg', 'https://a/2.jpg', 'https://a/3.jpg' ), 'items' => array( array( 'url' => 'https://a/1.jpg' ), array( 'url' => 'https://a/2.jpg' ), array( 'url' => 'https://a/3.jpg' ) ) ) as $key => $list ) {
			$GLOBALS['mcpwp_test_transients'] = array();
			Mcpwp_REST_MCP::reset_verdict_memo();
			$request = $this->mcp_request(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => array( 'name' => 'wp_bulk_upload_media', 'arguments' => array( $key => $list ) ),
				)
			);
			$controller->verify_api_key( $request );
			$controller->handle_mcp( $request );

			$this->assertSame( 7, Mcpwp_Rate_Limiter::get_instance()->get_budget()['remaining'], $key . ': one request plus two more operations.' );
		}
	}

	public function test_a_batch_tool_that_does_not_fit_is_refused_before_it_runs(): void {
		$controller = new Mcpwp_REST_MCP();
		$this->assertTrue( $controller->verify_api_key( $this->mcp_request( $this->rpc_batch( 3 ) ) ) ); // 7 left

		Mcpwp_REST_MCP::reset_verdict_memo();
		$request = $this->batch_tool_request( 8 );
		$this->assertTrue( $controller->verify_api_key( $request ) ); // 6 left; needs 7 more
		$data = $controller->handle_mcp( $request )->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32029, $data['error']['code'] );
		$this->assertSame( 0, count( $GLOBALS['mcpwp_test_dispatched_routes'] ), 'Nothing ran.' );
	}

	public function test_a_request_heavier_than_a_whole_window_is_not_told_to_wait(): void {
		$refused = Mcpwp_Rate_Limiter::get_instance()->check_limit( 'w4', 'POST', null, 11 );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'batch_exceeds_limit', $refused->get_error_code() );
		$this->assertSame( 413, $refused->get_error_data()['status'] );
	}

	public function test_the_reported_budget_is_the_tightest_window(): void {
		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$limiter->update_settings( array( 'requests_per_minute' => 100, 'burst_limit' => 100, 'requests_per_hour' => 1000 ) );
		set_transient( 'mcpwp_rate_' . md5( 'w5_hour' ), array( 'count' => 999, 'reset' => time() + 1800 ), 1800 );

		$limiter->check_limit( 'w5', 'POST' );
		$budget = $limiter->get_budget();

		$this->assertSame( 'hour', $budget['window'] );
		$this->assertSame( 0, $budget['remaining'] );
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

	// ---------------------------------------------------------------
	// Round 4: weight binds at the burst window; one list, one price.
	// ---------------------------------------------------------------

	public function test_weight_is_enforced_at_the_burst_window_not_only_per_minute(): void {
		// Round-4 M14: burst < minute; a 25-op call must be refused by burst.
		Mcpwp_Rate_Limiter::get_instance()->update_settings(
			array( 'enabled' => true, 'requests_per_minute' => 60, 'requests_per_hour' => 1000, 'burst_limit' => 20, 'whitelist' => array() )
		);
		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$this->assertTrue( $limiter->check_limit( 'b', 'POST', null, 15 ) );
		$refused = $limiter->check_limit( 'b', 'POST', null, 10 );
		$this->assertInstanceOf( WP_Error::class, $refused, '15 + 10 > 20 at the burst window.' );
		$this->assertSame( 'burst', $refused->get_error_data()['window'] );
	}

	public function test_two_spellings_of_one_list_are_charged_once(): void {
		// Round-4 P2-2: urls beside items, or items beside updates, summed.
		$count = new ReflectionMethod( Mcpwp_REST_MCP::class, 'nested_operation_count' );
		$count->setAccessible( true );
		$controller = new Mcpwp_REST_MCP();

		$this->assertSame( 5, $count->invoke( $controller, array( 'urls' => range( 1, 5 ), 'items' => range( 1, 5 ) ) ) );
		$this->assertSame( 7, $count->invoke( $controller, array( 'items' => range( 1, 7 ), 'updates' => range( 1, 3 ) ) ), 'Aliases: the larger list is the price.' );
		$this->assertSame( 20, $count->invoke( $controller, array( 'pages' => range( 1, 10 ), 'posts' => range( 1, 10 ) ) ), 'Different lists: each is charged (round 5).' );
		foreach ( array( 'post_ids', 'rows', 'widgets', 'sections' ) as $key ) {
			$this->assertSame( 500, $count->invoke( $controller, array( $key => range( 1, 500 ) ) ), $key . ' fans out and is counted.' );
		}
	}
}
