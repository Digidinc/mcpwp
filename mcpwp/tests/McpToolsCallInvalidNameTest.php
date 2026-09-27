<?php
/**
 * #1016 — tools/call with a non-string `name` returned HTTP 500.
 *
 * Found in adversarial review of PR #1014 (round 2): `{"name": ["wp_list_pages"]}`
 * reached `isset( $tool_map[ $tool_name ] ) )` inside
 * Mcpwp_REST_MCP::handle_tools_call() with `$tool_name` still an array —
 * `isset( $array[ $array_key ] )` throws `TypeError: Cannot access offset of
 * type array` in PHP 8, which is an uncaught fatal, not a JSON-RPC error.
 * `empty( $tool_name )` (the only guard that existed) is false for a
 * non-empty array, so it never caught this shape at all.
 *
 * The fix validates `params.name` is a non-empty STRING at the very top of
 * handle_tools_call(), before fire_tool_called()/log_mcp_activity() or any
 * other array-key lookup ever sees the raw value, and answers a clean
 * JSON-RPC `invalid_params` (-32602) error instead.
 *
 * Two more shapes are covered here because they share the exact mechanism:
 *  - the same bad shape wrapped as mcpwp_call's OWN `arguments.name` (#907) —
 *    Mcpwp_REST_MCP::resolve_mcpwp_call() already guards this with its own
 *    is_string() check and answers `invalid_arguments` before dispatch, so
 *    these are regression locks, not new fixes.
 *  - Mcpwp_Api_Auth::get_required_scope_for_mcp_request() (trait), which
 *    read `(string) $message['params']['name']` — a PHP "Array to string
 *    conversion" warning for an array `name`, not a crash, but it silently
 *    misclassified the tool as literal string "Array" instead of the ''
 *    every other caller of effective_mcp_tool_call() already normalizes a
 *    non-string name to. Fixed to pass the raw value through unconverted.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/**
 * Exposes the trait's scope resolver directly, matching the harness in
 * McpCallScopeBypassTest.php.
 */
class Mcpwp_Invalid_Name_Scope_Harness {
	use Mcpwp_Api_Auth;

	/**
	 * @param array $body One JSON-RPC message, or a batch array of them.
	 */
	public function scope_for( array $body ): string {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array(), $body );
		return $this->get_required_scope_for_request( $request );
	}
}

final class McpToolsCallInvalidNameTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_filters']      = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters']    = array();
	}

	private function tools_call_request( $name_value, $has_name_key = true, $arguments = array() ) {
		$params = array();
		if ( $has_name_key ) {
			$params['name'] = $name_value;
		}
		if ( array() !== $arguments ) {
			$params['arguments'] = $arguments;
		}

		return new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 42,
				'method'  => 'tools/call',
				'params'  => $params,
			)
		);
	}

	/**
	 * @return array<string,array{0:mixed}> Shapes that must never reach an
	 *                                       array-key lookup as the tool name.
	 */
	public function invalid_name_shapes(): array {
		return array(
			'array'       => array( array( 'wp_list_pages' ) ),
			'nested_array' => array( array( 'name' => 'wp_list_pages' ) ),
			'int'         => array( 42 ),
			'float'       => array( 1.5 ),
			'bool_true'   => array( true ),
			'null'        => array( null ),
		);
	}

	// ── Direct tools/call: {"name": <bad shape>} ────────────────────────────

	/**
	 * @dataProvider invalid_name_shapes
	 */
	public function test_direct_call_with_invalid_name_shape_returns_clean_json_rpc_error( $bad_name ): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = $this->tools_call_request( $bad_name );

		$response = $controller->handle_mcp( $request );

		$this->assertSame( 200, $response->get_status(), 'A JSON-RPC error still rides HTTP 200, never a 500.' );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data, 'Must be a JSON-RPC protocol error, not a tool result.' );
		$this->assertSame( -32602, $data['error']['code'] );
		$this->assertStringContainsString( 'name', $data['error']['message'] );
	}

	public function test_missing_name_key_returns_clean_json_rpc_error(): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = $this->tools_call_request( null, false );

		$response = $controller->handle_mcp( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( -32602, $data['error']['code'] );
	}

	public function test_empty_string_name_returns_clean_json_rpc_error(): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = $this->tools_call_request( '' );

		$response = $controller->handle_mcp( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( -32602, $data['error']['code'] );
	}

	public function test_valid_string_name_still_dispatches_normally(): void {
		// Precondition/non-regression: the new guard must not reject a real call.
		$controller = new Mcpwp_REST_MCP();
		$request    = $this->tools_call_request( 'wp_site_info' );

		$response = $controller->handle_mcp( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayNotHasKey( 'error', $data );
	}

	// ── Wrapped in mcpwp_call: {"name":"mcpwp_call","arguments":{"name": <bad shape>}} ──

	/**
	 * @dataProvider invalid_name_shapes
	 */
	public function test_mcpwp_call_wrapped_invalid_inner_name_returns_clean_error( $bad_name ): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 43,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'mcpwp_call',
					'arguments' => array( 'name' => $bad_name ),
				),
			)
		);

		$response = $controller->handle_mcp( $request );

		$this->assertSame( 200, $response->get_status(), 'A wrapped call with a bad inner name still never returns a 500.' );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'result', $data, 'mcpwp_call reports its own failures as a tools/call result (isError), not a JSON-RPC error.' );
		$this->assertTrue( $data['result']['isError'] );
		$this->assertSame( 'invalid_arguments', $data['result']['structuredContent']['error']['code'] );
	}

	public function test_mcpwp_call_missing_inner_name_returns_clean_error(): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 44,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'mcpwp_call',
					'arguments' => array(),
				),
			)
		);

		$response = $controller->handle_mcp( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['result']['isError'] );
		$this->assertSame( 'invalid_arguments', $data['result']['structuredContent']['error']['code'] );
	}

	// ── The trait's scope resolver (effective_mcp_tool_call's caller) ──────

	/**
	 * @dataProvider invalid_name_shapes
	 */
	public function test_scope_resolver_never_crashes_on_a_bad_direct_name_and_defaults_to_read( $bad_name ): void {
		$auth = new Mcpwp_Invalid_Name_Scope_Harness();

		$scope = $auth->scope_for(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array( 'name' => $bad_name ),
			)
		);

		// A name that resolves to no known tool carries no elevated
		// requirement — same as an unknown tool name today.
		$this->assertSame( 'read', $scope );
	}

	/**
	 * @dataProvider invalid_name_shapes
	 */
	public function test_scope_resolver_never_crashes_on_a_bad_wrapped_inner_name( $bad_name ): void {
		$auth = new Mcpwp_Invalid_Name_Scope_Harness();

		$scope = $auth->scope_for(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'mcpwp_call',
					'arguments' => array( 'name' => $bad_name ),
				),
			)
		);

		// effective_mcp_tool_call() cannot resolve a bad-shaped inner name, so
		// it reports the wrapper itself ("mcpwp_call") rather than '' — and
		// the tool-name scope table classifies an unresolvable wrapper as
		// admin, never below what any real dispatch through it could need
		// (see effective_mcp_tool_call()'s own docblock). The crash this test
		// guards against is a TypeError/warning, not a specific scope value.
		$this->assertSame( 'admin', $scope );
	}

	/**
	 * The unwrap helper itself (#907's shared chokepoint) must normalize
	 * every non-string shape to '' for both the outer name and a wrapped
	 * inner name — never emit "Array" (a PHP array-to-string conversion)
	 * or throw.
	 */
	public function test_effective_mcp_tool_call_normalizes_every_bad_shape_to_empty_string(): void {
		$auth   = new Mcpwp_Invalid_Name_Scope_Harness();
		$method = new ReflectionMethod( $auth, 'effective_mcp_tool_call' );
		$method->setAccessible( true );

		foreach ( $this->invalid_name_shapes() as $label => $case ) {
			$bad_name = $case[0];

			// Direct: the outer name itself is the bad shape.
			$direct = $method->invoke( $auth, $bad_name, array() );
			$this->assertSame( '', $direct['name'], "direct/$label" );

			// Wrapped: mcpwp_call's own arguments.name is the bad shape.
			$wrapped = $method->invoke( $auth, 'mcpwp_call', array( 'name' => $bad_name ) );
			$this->assertSame( 'mcpwp_call', $wrapped['name'], "wrapped/$label: an unresolvable wrapper reports as itself." );
		}
	}
}
