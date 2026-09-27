<?php
/**
 * #943 S2 review round 2, W6: site graph reads are charged their weight to
 * the RATE LIMITER on every entry point — direct REST, resources/read and
 * tools/call — from the one Mcpwp_Operation_Weights::graph_weight() table,
 * and never twice when /mcp dispatches internally.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-graph.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

/** Records every rate-limit charge instead of applying it. */
class Mcpwp_Graph_Rate_Spy_Graph extends Mcpwp_REST_Graph {
	public $charges = array();
	protected function check_rate_limit( $identifier = null, $method = 'POST', $key_record = null, $weight = 1 ) {
		$this->charges[] = (int) $weight;
		return true;
	}
}

/** Same spy on the /mcp controller. */
class Mcpwp_Graph_Rate_Spy_Mcp extends Mcpwp_REST_MCP {
	public $charges = array();
	protected function check_rate_limit( $identifier = null, $method = 'POST', $key_record = null, $weight = 1 ) {
		$this->charges[] = (int) $weight;
		return true;
	}
}

final class GraphRateWeightTest extends TestCase {

	/** @var string */
	private $key;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 0;
		$GLOBALS['mcpwp_test_is_admin']          = false;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		Mcpwp_REST_Batch::$is_batch_sub_request  = false;

		$created   = ( new Mcpwp_REST_Graph() )->create_scoped_api_key( 'reader', array( 'read' ) );
		$this->key = $created['key'];
	}

	protected function tearDown(): void {
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
		$GLOBALS['mcpwp_test_options']          = array();
		$GLOBALS['mcpwp_test_transients']       = array();
	}

	private function rest( string $route, array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', $route, array(), array( 'X-API-Key' => $this->key ) );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		return $request;
	}

	private function mcp( array $body ): WP_REST_Request {
		return new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $this->key, 'Content-Type' => 'application/json' ), $body );
	}

	public function test_direct_rest_graph_reads_are_charged_their_weight(): void {
		$cases = array(
			array( '/mcpwp/v1/graph/query', array(), 5 ),
			array( '/mcpwp/v1/graph', array(), 5 ),
			array( '/mcpwp/v1/graph/neighbors', array( 'node_key' => 'post:1', 'depth' => 2 ), 5 ),
			array( '/mcpwp/v1/graph/neighbors', array( 'node_key' => 'post:1' ), 1 ),
			array( '/mcpwp/v1/graph/node', array( 'post_id' => 1 ), 1 ),
		);
		foreach ( $cases as list( $route, $params, $weight ) ) {
			$spy = new Mcpwp_Graph_Rate_Spy_Graph();
			$this->assertTrue( $spy->verify_api_key( $this->rest( $route, $params ) ), $route );
			$this->assertSame( array( $weight ), $spy->charges, $route );
		}
	}

	public function test_internal_sub_request_is_not_charged_again(): void {
		// tools/call and resources/read dispatch /graph/* with this flag set;
		// the outer /mcp request already paid, so the inner one charges nothing.
		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		$spy = new Mcpwp_Graph_Rate_Spy_Graph();
		$this->assertTrue( $spy->verify_api_key( $this->rest( '/mcpwp/v1/graph/query' ) ) );
		$this->assertSame( array(), $spy->charges );
	}

	/**
	 * @return int Total slots charged for one /mcp request.
	 */
	private function mcp_cost( array $body ): int {
		$spy     = new Mcpwp_Graph_Rate_Spy_Mcp();
		$request = $this->mcp( $body );
		$this->assertTrue( $spy->verify_api_key( $request ) );
		$spy->handle_mcp( $request );
		return array_sum( $spy->charges );
	}

	public function test_resources_read_mcpwp_graph_costs_five(): void {
		$cost = $this->mcp_cost( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => array( 'uri' => 'mcpwp://graph' ) ) );
		$this->assertSame( 5, $cost );
		$this->assertContains( '/mcpwp/v1/graph', $GLOBALS['mcpwp_test_dispatched_routes'] );
	}

	public function test_other_resources_still_cost_one(): void {
		$this->assertSame( 1, $this->mcp_cost( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => array( 'uri' => 'mcpwp://elementor-status' ) ) ) );
	}

	public function test_tools_call_graph_weights(): void {
		$call = static function ( $name, $args ) {
			return array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => $name, 'arguments' => $args ) );
		};
		$this->assertSame( 1, $this->mcp_cost( $call( 'wp_graph_node', array( 'post_id' => 1 ) ) ) );
		$this->assertSame( 5, $this->mcp_cost( $call( 'wp_graph_query', array( 'orphan' => true ) ) ) );
		$this->assertSame( 5, $this->mcp_cost( $call( 'wp_graph_neighbors', array( 'post_id' => 1, 'depth' => 2 ) ) ) );
	}
}
