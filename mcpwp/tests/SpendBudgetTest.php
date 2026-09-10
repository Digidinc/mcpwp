<?php
/**
 * Coverage for #752 F9 — fan-out spend amplification.
 *
 * The sub-request COUNT of one HTTP request was bounded; its SPEND was not.
 * One JSON-RPC batch of tool calls, each a wp_batch of 100 operations, each
 * operation a bulk route or a call against the site's OpenAI key, multiplied
 * into thousands of writes or hundreds of paid generations from one request.
 * The rate limiter does not see it — and a whitelisted key, or a site with the
 * limiter switched off, sees nothing at all.
 *
 * The decision (#752, 2026-09-06): one spend budget per top-level HTTP request,
 * shared by its whole fan-out tree. Default 1000 units (filter
 * `mcpwp_request_spend_budget`). An ordinary operation costs 1, a bulk route the
 * items it processes, a credential-spending or egress route 25 per call or URL
 * item (filter `mcpwp_spend_weight`). Exhaustion refuses the remaining
 * operations one by one with `spend_budget_exhausted` — never a silent drop,
 * never undoing what already ran — and the response says what is left.
 *
 * Every test here runs with the rate limiter OFF: the budget is a separate
 * control and must bind without it.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

final class SpendBudgetTest extends TestCase {

	const EGRESS = array(
		'/integrations/generate-image',
		'/integrations/generate-featured-image',
		'/integrations/text-to-speech',
		'/integrations/describe-image',
		'/integrations/generate-alt-text',
		'/integrations/generate-excerpt',
		'/media/from-url',
	);

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

		$this->key = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'spend', array( 'admin' ) );

		Mcpwp_Rate_Limiter::get_instance()->update_settings(
			array(
				'enabled'             => false,
				'requests_per_minute' => 60,
				'requests_per_hour'   => 1000,
				'burst_limit'         => 30,
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
	 * Core's router, for the one route that fans out.
	 */
	public function route_batch( $result, $request, $route = '', $handler = array() ) {
		if ( null === $result && preg_match( '#^/mcpwp/v1/batch$#i', (string) $route ) ) {
			return ( new Mcpwp_REST_Batch() )->handle_batch( $request );
		}
		return $result;
	}

	private function budget( int $units ): void {
		add_filter(
			'mcpwp_request_spend_budget',
			function () use ( $units ) {
				return $units;
			}
		);
	}

	private function op( string $method, string $path, array $body = array() ): array {
		return array( 'method' => $method, 'path' => $path, 'body' => $body );
	}

	private function times( int $n, array $op ): array {
		return array_fill( 0, $n, $op );
	}

	private function image(): array {
		return $this->op( 'POST', '/integrations/generate-image', array( 'prompt' => 'a lighthouse' ) );
	}

	private function batch( array $operations, array $extra_params = array() ): array {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/batch', array(), array( 'X-API-Key' => $this->key['key'] ) );
		$request->set_param( 'operations', $operations );
		foreach ( $extra_params as $name => $value ) {
			$request->set_param( $name, $value );
		}

		$response = ( new Mcpwp_REST_Batch() )->handle_batch( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		return $response->get_data();
	}

	private function spent( array $operations ): int {
		$data = $this->batch( $operations );
		$this->assertArrayHasKey( 'spend_budget', $data, 'The batch response reports its budget.' );
		return $data['spend_budget']['spent'];
	}

	private function tool( string $name, array $arguments, $id = 1, array $meta = array() ): array {
		$params = array( 'name' => $name, 'arguments' => $arguments );
		if ( ! empty( $meta ) ) {
			$params['_meta'] = $meta;
		}
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => $params );
	}

	private function mcp( $body ) {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $this->key['key'] ), $body );
		$this->assertTrue( $controller->verify_api_key( $request ) );

		return $controller->handle_mcp( $request )->get_data();
	}

	private function tool_body( array $response ): array {
		$this->assertArrayHasKey( 'result', $response, 'Expected a result, got: ' . wp_json_encode( $response ) );
		return json_decode( $response['result']['content'][0]['text'], true );
	}

	private function statuses( array $data ): array {
		return array_map(
			function ( $result ) {
				return $result['status'];
			},
			$data['results']
		);
	}

	// ---------------------------------------------------------------
	// The finding, on REST /batch.
	// ---------------------------------------------------------------

	public function test_credential_spending_operations_stop_at_the_budget_and_the_rest_are_refused_one_by_one(): void {
		$data = $this->batch( $this->times( 50, $this->image() ) );

		$this->assertSame( 50, $data['total'], 'Every operation is reported — nothing is silently dropped.' );
		$this->assertSame( array_fill( 0, 40, 200 ), array_slice( $this->statuses( $data ), 0, 40 ), '1000 / 25 = 40 generations run.' );
		foreach ( array_slice( $data['results'], 40 ) as $refused ) {
			$this->assertSame( 413, $refused['status'] );
			$this->assertSame( 'spend_budget_exhausted', $refused['data']['code'] );
		}
		$this->assertSame( 40, $data['succeeded'] );
		$this->assertSame( 10, $data['failed'] );
		$this->assertCount( 40, $GLOBALS['mcpwp_test_dispatched_routes'], 'The refused ten never reached their handler.' );

		$this->assertSame( 1000, $data['spend_budget']['limit'] );
		$this->assertSame( 1000, $data['spend_budget']['spent'] );
		$this->assertSame( 0, $data['spend_budget']['remaining'] );
		$this->assertTrue( $data['spend_budget']['exhausted'] );
		$this->assertSame( range( 40, 49 ), $data['spend_budget']['refused'] );
	}

	public function test_a_refusal_says_what_the_operation_needed_and_what_was_left(): void {
		$this->budget( 30 );
		$data    = $this->batch( array( $this->image(), $this->image() ) );
		$refusal = $data['results'][1]['data'];

		$this->assertSame( 'spend_budget_exhausted', $refusal['code'] );
		$this->assertNotEmpty( $refusal['message'] );
		$this->assertSame( 413, $refusal['data']['status'] );
		$this->assertSame( 25, $refusal['data']['weight'] );
		$this->assertSame( 5, $refusal['data']['remaining'] );
		$this->assertSame( 30, $refusal['data']['limit'] );
		$this->assertNotEmpty( $refusal['data']['hint'] );
	}

	public function test_after_the_first_refusal_every_later_operation_is_refused_too(): void {
		// Otherwise the refused set is scattered through the batch and a
		// small operation after a big refused one runs out of order.
		$this->budget( 30 );
		$data = $this->batch( array( $this->image(), $this->image(), $this->op( 'GET', '/posts/1' ) ) );

		$this->assertSame( array( 200, 413, 413 ), $this->statuses( $data ) );
		$this->assertSame( array( 1, 2 ), $data['spend_budget']['refused'] );
		$this->assertSame( 25, $data['spend_budget']['spent'] );
		$this->assertSame( 5, $data['spend_budget']['remaining'] );
		$this->assertSame( array( '/mcpwp/v1/integrations/generate-image' ), $GLOBALS['mcpwp_test_dispatched_routes'] );
	}

	// ---------------------------------------------------------------
	// Weights.
	// ---------------------------------------------------------------

	public function test_every_listed_credential_or_egress_route_costs_twenty_five(): void {
		foreach ( self::EGRESS as $route ) {
			$this->assertSame( 25, $this->spent( array( $this->op( 'POST', $route ) ) ), $route );
		}
	}

	public function test_an_ordinary_operation_costs_one(): void {
		$operations = array();
		for ( $i = 1; $i <= 100; $i++ ) {
			$operations[] = 0 === $i % 2 ? $this->op( 'GET', '/posts/' . $i ) : $this->op( 'PUT', '/pages/' . $i, array( 'title' => 'T' ) );
		}
		$data = $this->batch( $operations );

		$this->assertSame( 100, $data['spend_budget']['spent'] );
		$this->assertSame( 900, $data['spend_budget']['remaining'] );
		$this->assertFalse( $data['spend_budget']['exhausted'] );
		$this->assertSame( array(), $data['spend_budget']['refused'] );
	}

	public function test_media_bulk_costs_twenty_five_per_url_item_whichever_key_carries_them(): void {
		$urls  = array_map( function ( $i ) { return 'https://example.com/' . $i . '.jpg'; }, range( 1, 20 ) );
		$items = array_map( function ( $url ) { return array( 'url' => $url ); }, $urls );

		$this->assertSame( 500, $this->spent( array( $this->op( 'POST', '/media/bulk', array( 'urls' => $urls ) ) ) ) );
		$this->assertSame( 500, $this->spent( array( $this->op( 'POST', '/media/bulk', array( 'items' => $items ) ) ) ) );
		$this->assertSame( 500, $this->spent( array( $this->op( 'POST', '/media/bulk', array( 'urls' => $urls, 'items' => array_slice( $items, 0, 3 ) ) ) ) ), 'Two spellings: the larger list is the price, whichever the handler reads.' );
	}

	public function test_a_bulk_route_costs_the_items_it_processes(): void {
		$this->assertSame( 50, $this->spent( array( $this->op( 'POST', '/posts/bulk', array( 'posts' => array_fill( 0, 50, array( 'title' => 'x' ) ) ) ) ) ) );
		$this->assertSame( 40, $this->spent( array( $this->op( 'PUT', '/pages/bulk', array( 'updates' => array_fill( 0, 40, array( 'id' => 1 ) ) ) ) ) ) );
		$this->assertSame( 30, $this->spent( array( $this->op( 'POST', '/seo/bulk', array( 'updates' => array_fill( 0, 30, array( 'id' => 1, 'title' => 't' ) ) ) ) ) ) );
		// /elementor/bulk carries its ids as one comma-separated string.
		$this->assertSame( 5, $this->spent( array( $this->op( 'GET', '/elementor/bulk', array( 'ids' => '1,2,3,4,5' ) ) ) ) );
		$this->assertSame( 3, $this->spent( array( $this->op( 'GET', '/elementor/bulk', array( 'ids' => ' 7 , 8,9,,' ) ) ) ) );
		$this->assertSame( 4, $this->spent( array( $this->op( 'GET', '/elementor/bulk', array( 'ids' => array( 1, 2, 3, 4 ) ) ) ) ) );
		$this->assertSame( 1, $this->spent( array( $this->op( 'GET', '/elementor/bulk', array( 'ids' => '' ) ) ) ), 'A malformed list still costs one operation, never zero.' );
	}

	public function test_the_fixed_weight_tools_cost_the_same_in_the_spend_budget(): void {
		$this->assertSame( 10, $this->spent( array( $this->op( 'POST', '/elementor/find-replace', array( 'search' => 'a', 'replace' => 'b', 'scope' => 'site' ) ) ) ) );
		$this->assertSame( 50, $this->spent( array( $this->op( 'POST', '/elementor/find-replace', array( 'search' => 'a', 'replace' => 'b', 'ids' => range( 1, 50 ) ) ) ) ) );
		$this->assertSame( 10, $this->spent( array( $this->op( 'DELETE', '/drafts/delete-all' ) ) ) );

		add_filter(
			'mcpwp_tool_operation_weight',
			function () {
				return 3;
			}
		);
		$this->assertSame( 3, $this->spent( array( $this->op( 'DELETE', '/drafts/delete-all' ) ) ), 'One map, one filter, both calculations.' );
	}

	public function test_the_spelling_of_a_route_does_not_change_its_price(): void {
		foreach ( array( '/Integrations/Generate-Image', 'integrations/generate-image', '//integrations//generate-image/', " /MEDIA/FROM-URL\n", '/media/from-url?x=1', '/media/from-url#f' ) as $path ) {
			$this->assertSame( 25, $this->spent( array( $this->op( 'POST', $path ) ) ), var_export( $path, true ) );
		}
	}

	public function test_weights_are_filterable_and_a_value_that_is_not_a_weight_keeps_the_default(): void {
		add_filter(
			'mcpwp_spend_weight',
			function ( $weight, $route ) {
				return 0 === strpos( $route, '/posts' ) ? 400 : $weight;
			},
			10,
			2
		);
		$data = $this->batch( $this->times( 3, $this->op( 'GET', '/posts/1' ) ) );
		$this->assertSame( array( 200, 200, 413 ), $this->statuses( $data ) );

		foreach ( array( 'free', -1, null, array(), NAN ) as $junk ) {
			$GLOBALS['mcpwp_test_filters'] = array();
			add_filter( 'rest_dispatch_request', array( $this, 'route_batch' ), 10, 4 );
			add_filter(
				'mcpwp_spend_weight',
				function () use ( $junk ) {
					return $junk;
				}
			);
			$this->assertSame( 50, $this->spent( $this->times( 2, $this->image() ) ), 'Unreadable weight is the computed default, never free: ' . var_export( $junk, true ) );
		}
	}

	public function test_the_budget_is_filterable_and_a_value_that_is_not_a_budget_is_the_default_not_unlimited(): void {
		$this->budget( 50 );
		$this->assertSame( array( 200, 200, 413 ), $this->statuses( $this->batch( $this->times( 3, $this->image() ) ) ) );

		foreach ( array( 'lots', null, -5, array(), true, NAN ) as $junk ) {
			$GLOBALS['mcpwp_test_filters'] = array();
			add_filter( 'rest_dispatch_request', array( $this, 'route_batch' ), 10, 4 );
			add_filter(
				'mcpwp_request_spend_budget',
				function () use ( $junk ) {
					return $junk;
				}
			);
			$data = $this->batch( $this->times( 41, $this->image() ) );
			$this->assertSame( 1000, $data['spend_budget']['limit'], var_export( $junk, true ) );
			$this->assertSame( 413, $data['results'][40]['status'], 'Still bounded: ' . var_export( $junk, true ) );
		}
	}

	public function test_a_client_cannot_raise_its_own_budget_or_lower_a_price(): void {
		$operations   = $this->times( 40, $this->image() );
		$operations[] = $this->op( 'POST', '/integrations/generate-image', array( 'spend_budget' => 1000000, 'weight' => 0, '_meta' => array( 'spend_budget' => 1000000 ) ) );

		$data = $this->batch( $operations, array( 'spend_budget' => 1000000, 'budget' => 1000000, '_meta' => array( 'spend_budget' => 1000000 ) ) );

		$this->assertSame( 1000, $data['spend_budget']['limit'] );
		$this->assertSame( 413, $data['results'][40]['status'] );

		$mcp = $this->mcp( $this->tool( 'wp_list_posts', array(), 1, array( 'spend_budget' => array( 'limit' => 1000000, 'spent' => 0 ) ) ) );
		$this->assertSame( 1000, $mcp['result']['_meta']['spend_budget']['limit'] );
	}

	// ---------------------------------------------------------------
	// One budget for the whole tree.
	// ---------------------------------------------------------------

	public function test_the_whole_fan_out_tree_of_one_request_shares_one_budget(): void {
		$wp_batch = $this->tool( 'wp_batch', array( 'operations' => $this->times( 20, $this->image() ) ) );
		$wp_batch_2 = $wp_batch;
		$wp_batch_2['id'] = 2;
		$wp_batch_3 = $wp_batch;
		$wp_batch_3['id'] = 3;

		$responses = $this->mcp( array( $wp_batch, $wp_batch_2, $wp_batch_3 ) );

		$this->assertCount( 3, $responses );
		$this->assertSame( 20, $this->tool_body( $responses[0] )['succeeded'] );
		$this->assertSame( 20, $this->tool_body( $responses[1] )['succeeded'] );
		$third = $this->tool_body( $responses[2] );
		$this->assertSame( 0, $third['succeeded'], 'Three nested batches do not get three budgets.' );
		$this->assertSame( 20, $third['failed'] );
		$this->assertSame( 'spend_budget_exhausted', $third['results'][0]['data']['code'] );
		$this->assertSame( 0, $responses[2]['result']['_meta']['spend_budget']['remaining'] );

		$this->assertCount( 40, array_filter( $GLOBALS['mcpwp_test_dispatched_routes'], function ( $route ) { return '/mcpwp/v1/batch' !== $route; } ) );
	}

	public function test_a_nested_batch_joins_the_budget_it_finds_instead_of_opening_a_fresh_one(): void {
		$this->budget( 60 );
		$url = array( 'url' => 'https://example.com/a.jpg' );

		$responses = $this->mcp(
			array(
				$this->tool( 'wp_upload_media_from_url', $url, 1 ),
				$this->tool( 'wp_upload_media_from_url', $url, 2 ),
				$this->tool( 'wp_batch', array( 'operations' => array( $this->image(), $this->op( 'GET', '/posts/1' ) ) ), 3 ),
			)
		);

		$inner = $this->tool_body( $responses[2] );
		$this->assertSame( array( 413, 413 ), $this->statuses( $inner ), '10 units were left when the nested batch began; it did not see 60.' );
		$this->assertSame( 60, $inner['spend_budget']['limit'] );
		$this->assertSame( 50, $inner['spend_budget']['spent'] );
	}

	public function test_the_batch_container_itself_is_free_so_nothing_is_charged_twice(): void {
		$response = $this->mcp( $this->tool( 'wp_batch', array( 'operations' => $this->times( 3, $this->op( 'GET', '/posts/1' ) ) ) ) );

		$this->assertSame( 3, $response['result']['_meta']['spend_budget']['spent'] );
		$this->assertSame( 3, $this->tool_body( $response )['spend_budget']['spent'] );
	}

	// ---------------------------------------------------------------
	// JSON-RPC batches in /mcp.
	// ---------------------------------------------------------------

	public function test_a_refused_tool_call_is_a_jsonrpc_error_with_the_code_and_the_batch_continues(): void {
		$this->budget( 60 );
		$url = array( 'url' => 'https://example.com/a.jpg' );

		$responses = $this->mcp(
			array(
				$this->tool( 'wp_upload_media_from_url', $url, 1 ),
				$this->tool( 'wp_upload_media_from_url', $url, 2 ),
				$this->tool( 'wp_upload_media_from_url', $url, 3 ),
				$this->tool( 'wp_list_posts', array(), 4 ),
				array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'ping' ),
			)
		);

		$this->assertCount( 5, $responses );
		$this->assertArrayHasKey( 'result', $responses[0] );
		$this->assertArrayHasKey( 'result', $responses[1] );
		foreach ( array( 2, 3 ) as $refused ) {
			$this->assertSame( -32030, $responses[ $refused ]['error']['code'] );
			$this->assertSame( 'spend_budget_exhausted', $responses[ $refused ]['error']['data']['code'] );
			$this->assertSame( 10, $responses[ $refused ]['error']['data']['remaining'] );
			$this->assertSame( 60, $responses[ $refused ]['error']['data']['limit'] );
		}
		$this->assertSame( 25, $responses[2]['error']['data']['weight'] );
		$this->assertArrayHasKey( 'result', $responses[4], 'A method that dispatches nothing is not refused.' );
		$this->assertCount( 2, $GLOBALS['mcpwp_test_dispatched_routes'] );
	}

	public function test_every_mcp_result_reports_the_budget(): void {
		$response = $this->mcp( $this->tool( 'wp_list_posts', array() ) );

		$this->assertSame( array( 'limit' => 1000, 'spent' => 1, 'remaining' => 999, 'exhausted' => false ), $response['result']['_meta']['spend_budget'] );
	}

	public function test_methods_that_dispatch_nothing_are_not_charged(): void {
		$responses = $this->mcp( array( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ), array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ) ) );

		$this->assertSame( 0, $responses[1]['result']['_meta']['spend_budget']['spent'] );
	}

	public function test_a_wp_batch_with_a_refused_operation_is_not_pinned(): void {
		$this->budget( 30 );
		$response = $this->mcp( $this->tool( 'wp_batch', array( 'operations' => array( $this->image(), $this->image() ) ), 1, array( 'request_id' => 'job:spend' ) ) );

		$this->assertSame( 'not_stored', $response['result']['_meta']['idempotency'], 'The refused operation must stay retryable.' );
	}

	// ---------------------------------------------------------------
	// No leak across requests.
	// ---------------------------------------------------------------

	public function test_a_spent_budget_does_not_outlive_its_request(): void {
		$first = $this->batch( $this->times( 41, $this->image() ) );
		$this->assertTrue( $first['spend_budget']['exhausted'] );

		$second = $this->batch( array( $this->image() ) );
		$this->assertSame( array( 200 ), $this->statuses( $second ) );
		$this->assertSame( 25, $second['spend_budget']['spent'] );
		$this->assertFalse( $second['spend_budget']['exhausted'] );

		$this->mcp( array( $this->tool( 'wp_batch', array( 'operations' => $this->times( 41, $this->image() ) ) ) ) );
		$third = $this->batch( array( $this->image() ) );
		$this->assertSame( 25, $third['spend_budget']['spent'], 'Nor does one opened by /mcp.' );
	}

	public function test_a_throw_mid_batch_does_not_leave_its_budget_behind(): void {
		add_filter(
			'rest_dispatch_request',
			function ( $result, $request ) {
				if ( '/mcpwp/v1/boom' === $request->get_route() ) {
					throw new RuntimeException( 'handler blew up' );
				}
				return $result;
			},
			5,
			2
		);

		try {
			$this->batch( array( $this->image(), $this->op( 'GET', '/boom' ) ) );
			$this->fail( 'Test premise: the second operation throws.' );
		} catch ( RuntimeException $expected ) {
			$this->assertSame( 'handler blew up', $expected->getMessage() );
		}

		$after = $this->batch( array( $this->image() ) );
		$this->assertSame( 25, $after['spend_budget']['spent'], 'The thrown request\'s 25 units are not carried into the next request.' );
		$this->assertSame( 1000, $after['spend_budget']['limit'] );
	}
}
