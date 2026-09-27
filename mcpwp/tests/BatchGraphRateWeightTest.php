<?php
/**
 * #1008 — a /batch (or wp_batch / wp_batch_update via tools/call) carrying
 * /graph* sub-requests counted 1 rate-limit slot per operation, same as any
 * other route, even though the same graph read charges its graph_weight()
 * (5, or 1 for a single node / depth-1 neighbor page) on every other entry
 * point: direct REST, resources/read and a plain tools/call. The spend
 * budget already priced a batched graph read correctly (#943 S2 review W6,
 * default_spend_weight()); only the RATE limiter's per-operation count did
 * not follow.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

final class BatchGraphRateWeightTest extends TestCase {

	/** @var array{key:string,id:string} */
	private $key;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
		Mcpwp_REST_Batch::$is_batch_sub_request = false;

		$this->key = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'graph-batch', array( 'admin' ) );

		Mcpwp_Rate_Limiter::get_instance()->update_settings(
			array(
				'enabled'             => true,
				'requests_per_minute' => 1000,
				'requests_per_hour'   => 10000,
				'burst_limit'         => 1000,
				'whitelist'           => array(),
			)
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']          = array();
		$GLOBALS['mcpwp_test_transients']       = array();
		$GLOBALS['mcpwp_test_filters']          = array();
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
		$this->reset_rate_limiter();
	}

	/**
	 * The limiter is a singleton that keeps the settings it was last given in
	 * memory; clearing the options store does not reach it.
	 */
	private function reset_rate_limiter(): void {
		$ref  = new ReflectionClass( Mcpwp_Rate_Limiter::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null );
	}

	private function graph_op( string $path, array $body = array() ): array {
		return array( 'method' => 'GET', 'path' => $path, 'body' => $body );
	}

	private function rest_batch_request( array $operations ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/batch', array(), array( 'X-API-Key' => $this->key['key'] ) );
		$request->set_param( 'operations', $operations );
		return $request;
	}

	private function mcp_request( array $body ): WP_REST_Request {
		return new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $this->key['key'] ), $body );
	}

	private function budget_remaining(): int {
		return Mcpwp_Rate_Limiter::get_instance()->get_budget()['remaining'];
	}

	// ---------------------------------------------------------------
	// Mcpwp_Operation_Weights unit coverage.
	// ---------------------------------------------------------------

	public function test_a_single_graph_operation_costs_its_graph_weight(): void {
		$this->assertSame( 5, Mcpwp_Operation_Weights::batch_operation_weight( $this->graph_op( '/graph/query' ) ) );
		$this->assertSame( 5, Mcpwp_Operation_Weights::batch_operation_weight( $this->graph_op( '/graph' ) ) );
		$this->assertSame( 1, Mcpwp_Operation_Weights::batch_operation_weight( $this->graph_op( '/graph/node', array( 'post_id' => 1 ) ) ) );
		$this->assertSame( 1, Mcpwp_Operation_Weights::batch_operation_weight( $this->graph_op( '/graph/neighbors', array( 'post_id' => 1 ) ) ) );
		$this->assertSame( 5, Mcpwp_Operation_Weights::batch_operation_weight( $this->graph_op( '/graph/neighbors', array( 'post_id' => 1, 'depth' => 2 ) ) ), 'A depth-2 walk costs 5, like every other entry point.' );
	}

	public function test_an_ordinary_operation_still_costs_one(): void {
		$this->assertSame( 1, Mcpwp_Operation_Weights::batch_operation_weight( array( 'method' => 'GET', 'path' => '/posts/1' ) ) );
		$this->assertSame( 1, Mcpwp_Operation_Weights::batch_operation_weight( 'not-an-array' ) );
	}

	public function test_three_graph_operations_charge_fifteen(): void {
		$ops = array(
			$this->graph_op( '/graph/query' ),
			$this->graph_op( '/graph' ),
			$this->graph_op( '/graph/neighbors', array( 'post_id' => 1, 'depth' => 2 ) ),
		);

		$this->assertSame( 15, Mcpwp_Operation_Weights::batch_operations_weight( $ops ) );
	}

	public function test_a_mixed_batch_sums_correctly(): void {
		$ops = array(
			$this->graph_op( '/graph/query' ),           // 5
			array( 'method' => 'GET', 'path' => '/posts/1' ), // 1
			$this->graph_op( '/graph/node', array( 'post_id' => 2 ) ), // 1
			array( 'method' => 'PUT', 'path' => '/posts/2', 'body' => array( 'title' => 'x' ) ), // 1
		);

		$this->assertSame( 8, Mcpwp_Operation_Weights::batch_operations_weight( $ops ) );
	}

	// ---------------------------------------------------------------
	// On the wire: /batch.
	// ---------------------------------------------------------------

	public function test_a_rest_batch_of_three_graph_ops_charges_fifteen_slots(): void {
		$controller = new Mcpwp_REST_Batch();
		$ops        = array( $this->graph_op( '/graph/query' ), $this->graph_op( '/graph' ), $this->graph_op( '/graph/query' ) );

		$this->assertTrue( $controller->verify_api_key( $this->rest_batch_request( $ops ) ) );
		$this->assertSame( 985, $this->budget_remaining(), '1000 - 15.' );
	}

	public function test_a_rest_batch_mixing_graph_and_ordinary_ops_sums(): void {
		$controller = new Mcpwp_REST_Batch();
		$ops        = array(
			$this->graph_op( '/graph/query' ),
			array( 'method' => 'GET', 'path' => '/posts/1' ),
			array( 'method' => 'GET', 'path' => '/posts/2' ),
		);

		$this->assertTrue( $controller->verify_api_key( $this->rest_batch_request( $ops ) ) );
		$this->assertSame( 993, $this->budget_remaining(), '1000 - (5 + 1 + 1).' );
	}

	public function test_a_rest_batch_over_the_ceiling_is_refused_whole_before_anything_runs(): void {
		// A batch priced above the smallest window ceiling can never fit, so
		// it is refused at the door (413 batch_exceeds_limit) — never told to
		// wait, and never partially run.
		Mcpwp_Rate_Limiter::get_instance()->update_settings( array( 'requests_per_minute' => 10, 'burst_limit' => 10 ) );

		$controller = new Mcpwp_REST_Batch();
		$ops        = array( $this->graph_op( '/graph/query' ), $this->graph_op( '/graph' ), $this->graph_op( '/graph/query' ) ); // 15 > 10

		$refused = $controller->verify_api_key( $this->rest_batch_request( $ops ) );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'batch_exceeds_limit', $refused->get_error_code() );
		$this->assertSame( 15, $refused->get_error_data()['requested'] );
		$this->assertSame( 0, count( $GLOBALS['mcpwp_test_dispatched_routes'] ), 'Refused at the door — nothing dispatched.' );
	}

	public function test_a_rest_batch_over_the_remaining_budget_is_refused_whole_before_anything_runs(): void {
		// Within the ceiling but not what is left this window: still all or
		// nothing, never a partial run followed by a 429.
		Mcpwp_Rate_Limiter::get_instance()->update_settings( array( 'requests_per_minute' => 1000, 'burst_limit' => 20, 'requests_per_hour' => 100000 ) );

		$controller = new Mcpwp_REST_Batch();
		$first      = array( $this->graph_op( '/graph/query' ), $this->graph_op( '/graph' ) ); // 10
		$this->assertTrue( $controller->verify_api_key( $this->rest_batch_request( $first ) ) );
		$this->assertSame( 10, $this->budget_remaining(), '20 - 10.' );

		$second  = array( $this->graph_op( '/graph/query' ), $this->graph_op( '/graph' ), $this->graph_op( '/graph/query' ) ); // 15 > 10 left
		$refused = ( new Mcpwp_REST_Batch() )->verify_api_key( $this->rest_batch_request( $second ) );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'rate_limit_exceeded', $refused->get_error_code() );
		$this->assertSame( 0, count( $GLOBALS['mcpwp_test_dispatched_routes'] ), 'Refused before anything from the second batch ran.' );
	}

	// ---------------------------------------------------------------
	// On the wire: wp_batch / wp_batch_update via tools/call.
	// ---------------------------------------------------------------

	private function batch_tool_call( string $tool, array $operations ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => array( 'name' => $tool, 'arguments' => array( 'operations' => $operations ) ),
		);
	}

	public function test_wp_batch_with_graph_operations_charges_the_graph_weight(): void {
		$controller = new Mcpwp_REST_MCP();
		$ops        = array( $this->graph_op( '/graph/query' ), $this->graph_op( '/graph' ), $this->graph_op( '/graph/query' ) );
		$request    = $this->mcp_request( $this->batch_tool_call( 'wp_batch', $ops ) );

		$this->assertTrue( $controller->verify_api_key( $request ) );
		// The HTTP request itself already paid one slot (999 left); the
		// remaining charge happens at dispatch, before the sub-requests run.
		$controller->handle_mcp( $request );

		$this->assertSame( 985, $this->budget_remaining(), '1000 - 15 total, same as the direct /batch entry point.' );
	}

	public function test_wp_batch_update_is_the_same_price_as_wp_batch(): void {
		$controller = new Mcpwp_REST_MCP();
		$ops        = array( $this->graph_op( '/graph/query' ), array( 'method' => 'GET', 'path' => '/posts/1' ) );
		$request    = $this->mcp_request( $this->batch_tool_call( 'wp_batch_update', $ops ) );

		$this->assertTrue( $controller->verify_api_key( $request ) );
		$controller->handle_mcp( $request );

		$this->assertSame( 994, $this->budget_remaining(), '1000 - (5 + 1).' );
	}

	public function test_a_wp_batch_call_over_budget_is_refused_before_it_runs(): void {
		Mcpwp_Rate_Limiter::get_instance()->update_settings( array( 'requests_per_minute' => 10, 'burst_limit' => 10 ) );

		$controller = new Mcpwp_REST_MCP();
		$ops        = array( $this->graph_op( '/graph/query' ), $this->graph_op( '/graph' ), $this->graph_op( '/graph/query' ) ); // 15 > 9 left
		$request    = $this->mcp_request( $this->batch_tool_call( 'wp_batch', $ops ) );

		$this->assertTrue( $controller->verify_api_key( $request ) ); // 1 slot at the door, 9 left
		$data = $controller->handle_mcp( $request )->get_data();

		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertTrue( $data['result']['isError'] );
		$this->assertSame( 'rate_limited', $data['result']['structuredContent']['error']['code'] );
		$this->assertSame( 0, count( $GLOBALS['mcpwp_test_dispatched_routes'] ), 'Nothing ran.' );
	}
}
