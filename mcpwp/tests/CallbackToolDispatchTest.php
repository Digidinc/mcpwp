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
}
