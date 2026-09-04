<?php
/**
 * Regression coverage for F7 (issue #752): the MCP tool dispatcher must
 * restore Mcpwp_REST_Batch::$is_batch_sub_request to its previous value
 * after a route-based tool call, not hard-reset it to false.
 *
 * The flag is shared with Mcpwp_REST_Batch::execute_operation(), which sets
 * it around every batch operation dispatch — including the built-in tool
 * that maps a batch operation back into MCP tool dispatch. If this
 * dispatcher unconditionally wrote false when it finished, it would clear
 * an outer batch caller's still-live flag, silently re-enabling rate
 * counting for the remainder of that outer dispatch. Entering this
 * dispatcher with the flag already true and asserting it is still true
 * afterward is exactly the scenario #744 fixed for the sibling controller
 * (Mcpwp_REST_Batch) and this file mirrors for Mcpwp_REST_MCP.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// bootstrap.php does not load the batch controller; BatchOperationPathTest.php
// requires it directly for the same reason.
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

final class McpSubRequestFlagTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 2;
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
	}

	protected function tearDown(): void {
		// PHPUnit does not reset statics between tests; a mid-test failure must
		// not leak the batch context into the rest of the suite.
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
	}

	private function call_tool( string $tool_name ): array {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 99,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => $tool_name,
					'arguments' => array(),
				),
			)
		);

		return $controller->handle_mcp( $request )->get_data();
	}

	/**
	 * Entering tools/call with the sub-request flag already true simulates a
	 * batch operation whose route maps back into MCP tool dispatch. A
	 * route-based tool (not a callback tool) exercises the rest_do_request()
	 * branch where the flag is set/restored.
	 */
	public function test_route_based_tool_call_restores_flag_that_was_already_true(): void {
		Mcpwp_REST_Batch::$is_batch_sub_request = true;

		// wp_site_info is a plain route-based (non-callback) read tool with no
		// plugin requirement, so it reaches the rest_do_request() dispatch.
		$data = $this->call_tool( 'wp_site_info' );

		$this->assertArrayHasKey( 'result', $data, 'The tool call itself must still succeed.' );
		$this->assertTrue(
			Mcpwp_REST_Batch::$is_batch_sub_request,
			'A route-based tool call must not clear an outer caller\'s sub-request flag.'
		);
	}

	/**
	 * The ordinary case: no outer batch context. The flag must end up false
	 * again, same as before this fix — restoring "false" to "false" is
	 * indistinguishable from a hard reset when there was nothing to preserve.
	 */
	public function test_route_based_tool_call_leaves_flag_false_when_it_started_false(): void {
		Mcpwp_REST_Batch::$is_batch_sub_request = false;

		$data = $this->call_tool( 'wp_site_info' );

		$this->assertArrayHasKey( 'result', $data );
		$this->assertFalse( Mcpwp_REST_Batch::$is_batch_sub_request );
	}
}
