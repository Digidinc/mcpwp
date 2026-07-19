<?php
/**
 * Tests for trusted custom-tool callbacks dispatched through MCPWP.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class CallbackToolDispatchTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_filters']          = array();
		$GLOBALS['mcpwp_test_options']          = array();
		$GLOBALS['mcpwp_test_transients']       = array();
		$GLOBALS['mcpwp_test_current_user']     = 2;
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	private function register_callback_tool( $callback, array $extra = array() ): void {
		add_filter(
			'mcpwp_register_tools',
			function ( $tools ) use ( $callback, $extra ) {
				$tools[] = array_merge(
					array(
						'name'        => 'digid_callback_tool',
						'description' => 'Run a trusted callback.',
						'callback'    => $callback,
					),
					$extra
				);
				return $tools;
			}
		);
	}

	private function call_tool( array $arguments = array() ): array {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 22,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'digid_callback_tool',
					'arguments' => $arguments,
				),
			)
		);

		return $controller->handle_mcp( $request )->get_data();
	}

	public function test_callback_receives_remapped_arguments_and_array_result_uses_mcp_response_flow(): void {
		$received = null;
		$this->register_callback_tool(
			function ( array $arguments ) use ( &$received ) {
				$received = $arguments;
				return array( 'updated' => true );
			},
			array(
				'input_props' => array(
					'from' => array( 'type' => 'string', 'description' => 'Source value.' ),
				),
				'param_remap' => array( 'from' => 'to' ),
			)
		);

		$data = $this->call_tool( array( 'from' => 'value' ) );

		$this->assertSame( array( 'to' => 'value' ), $received );
		$this->assertSame(
			array( 'updated' => true ),
			json_decode( $data['result']['content'][0]['text'], true )
		);
	}

	public function test_callback_rest_response_uses_existing_success_response_flow(): void {
		$this->register_callback_tool(
			function ( array $arguments ) {
				return new WP_REST_Response( array( 'updated' => true ), 201 );
			}
		);

		$data = $this->call_tool();

		$this->assertSame(
			array( 'updated' => true ),
			json_decode( $data['result']['content'][0]['text'], true )
		);
	}

	public function test_callback_wp_error_uses_existing_error_response_flow(): void {
		$this->register_callback_tool(
			function ( array $arguments ) {
				return new WP_Error(
					'update_failed',
					'Could not update the theme.',
					array( 'status' => 422, 'hint' => 'Check the update package.' )
				);
			}
		);

		$data = $this->call_tool();

		$this->assertSame( -32000, $data['error']['code'] );
		$this->assertStringContainsString( 'Could not update the theme.', $data['error']['message'] );
		$this->assertSame( 'update_failed', $data['error']['data']['code'] );
		$this->assertSame( 422, $data['error']['data']['status'] );
		$this->assertSame( 'Check the update package.', $data['error']['data']['hint'] );
	}

	/**
	 * Proves a write-scoped key is denied by the authenticated MCP route before
	 * an extension callback can run when that exact tool requires admin scope.
	 */
	public function test_write_scoped_key_cannot_reach_admin_callback(): void {
		$called = false;
		$this->register_callback_tool(
			function ( array $arguments ) use ( &$called ) {
				$called = true;
				return array( 'updated' => true );
			}
		);
		add_filter(
			'mcpwp_required_scope_for_tool',
			static function ( $scope, $tool_name ) {
				return 'digid_callback_tool' === $tool_name ? 'admin' : $scope;
			},
			10,
			2
		);

		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'write key', array( 'write' ) );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $key['key'] ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 23,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'digid_callback_tool',
					'arguments' => array(),
				),
			)
		);

		$result = $controller->check_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
		$this->assertFalse( $called );
	}

	/**
	 * Proves a disabled category rejects the custom tool before its callback.
	 */
	public function test_disabled_category_cannot_reach_callback(): void {
		$called = false;
		$this->register_callback_tool(
			function ( array $arguments ) use ( &$called ) {
				$called = true;
				return array( 'updated' => true );
			},
			array( 'category' => 'admin' )
		);
		update_option( 'mcpwp_disabled_tool_categories', array( 'admin' ) );

		$data = $this->call_tool();

		$this->assertSame( -32003, $data['error']['code'] );
		$this->assertFalse( $called );
	}

	/** Dispatch the tool with a given API key record set on the controller. */
	private function call_tool_as( array $key_record, array $arguments = array() ): array {
		$controller = new Mcpwp_REST_MCP();

		$prop = new ReflectionProperty( Mcpwp_REST_API::class, 'current_api_key_record' );
		$prop->setAccessible( true );
		$prop->setValue( $controller, $key_record );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 23,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'digid_callback_tool',
					'arguments' => $arguments,
				),
			)
		);

		return $controller->handle_mcp( $request )->get_data();
	}

	public function test_read_scoped_key_cannot_reach_mutating_callback(): void {
		$called = false;
		// A callback whose name carries no write verb defaults to read scope, but
		// it still executes arbitrary code — a read key must not reach it.
		$this->register_callback_tool(
			function ( array $arguments ) use ( &$called ) {
				$called = true;
				return array( 'updated' => true );
			}
		);

		$data = $this->call_tool_as( array( 'id' => 'k_read', 'scopes' => array( 'read' ) ) );

		$this->assertSame( -32003, $data['error']['code'] );
		$this->assertFalse( $called, 'Callback must not run for a read-only key' );
	}

	public function test_write_scoped_key_reaches_callback(): void {
		$called = false;
		$this->register_callback_tool(
			function ( array $arguments ) use ( &$called ) {
				$called = true;
				return array( 'updated' => true );
			}
		);

		$data = $this->call_tool_as( array( 'id' => 'k_write', 'scopes' => array( 'read', 'write' ) ) );

		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertTrue( $called, 'Callback should run for a write-scoped key' );
	}
}
