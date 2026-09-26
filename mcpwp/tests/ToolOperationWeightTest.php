<?php
/**
 * Coverage for #802 — two tools touch many posts but carried no list, so the
 * weighted limiter charged each of them one slot.
 *
 * wp_bulk_find_replace rewrites Elementor data across a type or the whole site
 * (up to 200 posts a run); wp_delete_all_drafts removes every draft. Neither
 * carries an `operations`/`items`-style list, so nested_operation_count() saw
 * one operation. They now cost a fixed weight of 10, from one per-tool weight
 * map the weight calculation consults — filterable through
 * `mcpwp_tool_operation_weight` — and an explicit id list longer than that is
 * charged per id.
 *
 * #891 narrowed that fixed weight to wp_bulk_find_replace calls that did NOT
 * name their posts. A single-id (or short `ids`) call is `scoped` — it costs
 * what it actually touches, never the fixed weight a whole post type or the
 * whole site costs — because a single-post find/replace was burning the
 * caller's entire write burst on one post and refusing the very next call.
 * wp_delete_all_drafts has no such narrowing (it always touches every draft
 * of a type) and keeps the flat weight unconditionally.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ToolOperationWeightTest extends TestCase {

	/** @var array */
	private $key;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		Mcpwp_REST_MCP::reset_verdict_memo();

		$this->key = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'weights', array( 'admin' ) );

		Mcpwp_Rate_Limiter::get_instance()->update_settings(
			array(
				'enabled'             => true,
				'requests_per_minute' => 100,
				'requests_per_hour'   => 1000,
				'burst_limit'         => 100,
				'whitelist'           => array(),
			)
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters']    = array();
	}

	private function weight( string $tool, array $arguments ): int {
		$count = new ReflectionMethod( Mcpwp_REST_MCP::class, 'nested_operation_count' );
		$count->setAccessible( true );

		return $count->invoke( new Mcpwp_REST_MCP(), $arguments, $tool );
	}

	public function test_a_wide_find_replace_and_delete_all_drafts_cost_a_fixed_ten(): void {
		$this->assertSame( 10, $this->weight( 'wp_bulk_find_replace', array( 'search' => 'a', 'replace' => 'b', 'scope' => 'site' ) ) );
		$this->assertSame( 10, $this->weight( 'wp_bulk_find_replace', array( 'search' => 'a', 'replace' => 'b', 'post_type' => 'page' ) ) );
		$this->assertSame( 10, $this->weight( 'wp_delete_all_drafts', array() ) );
		$this->assertSame( 10, $this->weight( 'wp_delete_all_drafts', array( 'type' => 'page', 'force' => true ) ) );
	}

	public function test_a_find_replace_named_to_one_post_costs_one(): void {
		// #891: a single-id wp_bulk_find_replace was priced identically to a
		// site-wide run — the fixed weight of 10 — which could exhaust the
		// caller's entire write burst on one post and refuse the very next
		// call. Naming exactly one post now costs exactly one slot.
		$this->assertSame( 1, $this->weight( 'wp_bulk_find_replace', array( 'search' => 'a', 'replace' => 'b', 'id' => 5 ) ) );
	}

	public function test_an_explicit_id_list_is_charged_its_length_not_the_fixed_weight(): void {
		$this->assertSame( 50, $this->weight( 'wp_bulk_find_replace', array( 'search' => 'a', 'replace' => 'b', 'ids' => range( 1, 50 ) ) ) );
		// #891: named to 3 posts costs 3 — the fixed weight is what NOT
		// naming posts costs, never a floor under a narrow, named run.
		$this->assertSame( 3, $this->weight( 'wp_bulk_find_replace', array( 'search' => 'a', 'replace' => 'b', 'ids' => range( 1, 3 ) ) ) );
	}

	public function test_a_comma_separated_id_list_is_counted_like_an_array(): void {
		// Core sanitises the route's `ids` (type array) from "1,2,3" into a list,
		// so the handler processes every id; the price must count them too.
		$this->assertSame( 50, $this->weight( 'wp_bulk_find_replace', array( 'search' => 'a', 'replace' => 'b', 'ids' => implode( ',', range( 1, 50 ) ) ) ) );
		$this->assertSame( 12, $this->weight( 'wp_bulk_find_replace', array( 'search' => 'a', 'replace' => 'b', 'ids' => ' 1, 2 ,3,4,5,6,7,8,9,10,11,12,, ' ) ) );
		$this->assertSame( 2, $this->weight( 'wp_bulk_find_replace', array( 'search' => 'a', 'replace' => 'b', 'ids' => '1,2' ) ), '#891: 2 named posts costs 2, not the fixed weight.' );
	}

	public function test_the_weight_is_filterable_and_a_value_that_is_not_a_weight_keeps_the_default(): void {
		add_filter(
			'mcpwp_tool_operation_weight',
			function ( $weight, $tool ) {
				return 'wp_bulk_find_replace' === $tool ? 3 : $weight;
			},
			10,
			2
		);
		$this->assertSame( 3, $this->weight( 'wp_bulk_find_replace', array( 'search' => 'a', 'replace' => 'b' ) ) );
		$this->assertSame( 5, $this->weight( 'wp_bulk_find_replace', array( 'search' => 'a', 'replace' => 'b', 'ids' => range( 1, 5 ) ) ), 'An id list still prices above a lowered weight.' );
		$this->assertSame( 10, $this->weight( 'wp_delete_all_drafts', array() ), 'The filter saw the other tool and left it alone.' );

		foreach ( array( 'many', -2, null, array( 10 ), true, NAN ) as $junk ) {
			$GLOBALS['mcpwp_test_filters'] = array();
			add_filter(
				'mcpwp_tool_operation_weight',
				function () use ( $junk ) {
					return $junk;
				}
			);
			$this->assertSame( 10, $this->weight( 'wp_delete_all_drafts', array() ), 'A weight that could not be read is the default, never free: ' . var_export( $junk, true ) );
		}
	}

	public function test_a_filter_can_price_a_tool_that_is_not_in_the_map_but_never_below_its_list(): void {
		add_filter(
			'mcpwp_tool_operation_weight',
			function ( $weight, $tool ) {
				return 'wp_create_page' === $tool ? 4 : 1;
			},
			10,
			2
		);

		$this->assertSame( 4, $this->weight( 'wp_create_page', array( 'title' => 'x' ) ) );
		$this->assertSame( 7, $this->weight( 'wp_bulk_create_posts', array( 'posts' => range( 1, 7 ) ) ), 'A list of 7 costs 7 whatever a filter says.' );
	}

	public function test_ordinary_tools_are_unchanged(): void {
		$this->assertSame( 1, $this->weight( 'wp_create_page', array( 'title' => 'x' ) ) );
		$this->assertSame( 7, $this->weight( 'wp_bulk_create_posts', array( 'posts' => range( 1, 7 ) ) ) );
		$this->assertSame( 5, $this->weight( 'wp_batch_update', array( 'operations' => range( 1, 5 ) ) ) );
	}

	public function test_delete_all_drafts_is_charged_ten_slots_on_the_wire(): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $this->key['key'] ),
			array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_delete_all_drafts', 'arguments' => array() ) )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
		$data = $controller->handle_mcp( $request )->get_data();

		$this->assertArrayHasKey( 'result', $data );
		$this->assertSame( 90, Mcpwp_Rate_Limiter::get_instance()->get_budget()['remaining'], 'One slot at the door, nine more for the fixed weight.' );
	}
}
