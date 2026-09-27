<?php
/**
 * Tool EXECUTION failures reach the model as isError tools/call RESULTS,
 * not JSON-RPC protocol errors.
 *
 * Per the MCP spec ("Error Handling", modelcontextprotocol.io), a protocol
 * `error` is for parse failures, malformed requests, unknown methods/tools
 * and genuine server faults. A failure AFTER the tool name resolved to a
 * real, callable tool — missing/invalid arguments, Pro-required, a disabled
 * category, a denied scope, a rate/spend refusal, an idempotency conflict,
 * or the underlying REST route failing — is a normal `result` with
 * `isError: true`. Many MCP clients drop `error.data` entirely, which is
 * exactly where this plugin's `hint`/`guide` used to live, so a model could
 * never see why a call failed or how to fix it (#752-adjacent finding).
 *
 * Per-category coverage of the conversion itself (pro_required,
 * category_disabled, scope_denied, rate_limited, spend_budget_exceeded/
 * exhausted, idempotency_conflict/in_progress/corrupt, batch_too_large,
 * invalid_path_parameter) lives beside each feature's own tests
 * (StableHardeningTest, DisabledToolCategoriesTest, CallbackToolDispatchTest,
 * CustomToolPlaceholderTest, IdempotentToolCallTest, SpendBudgetTest,
 * BatchBudgetTest, WpBatchToolTest). This file covers the two things that
 * cut across all of them — the isError result shape itself, and the line
 * that must NOT move: a genuinely malformed request or an unknown tool name
 * stays a JSON-RPC protocol error.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ToolExecutionIsErrorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
	}

	private function call( string $tool_name, array $arguments = array(), $id = 11 ): array {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => $tool_name,
					'arguments' => $arguments,
				),
			)
		);

		return $controller->handle_mcp( $request )->get_data();
	}

	// ---------------------------------------------------------------
	// A resolved tool that fails is a RESULT, never a protocol error.
	// ---------------------------------------------------------------

	public function test_missing_required_parameter_is_an_iserror_result_not_a_protocol_error(): void {
		// wp_get_page_by_slug is real and resolves; "slug" is required and omitted.
		$data = $this->call( 'wp_get_page_by_slug', array() );

		$this->assertArrayNotHasKey( 'error', $data, 'A resolved tool with a bad call is a result, not a protocol error.' );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertTrue( $data['result']['isError'] );

		$error = $data['result']['structuredContent']['error'];
		$this->assertSame( 'invalid_arguments', $error['code'] );
		$this->assertStringContainsString( 'slug', $error['message'] );
		$this->assertStringContainsString( 'Fix:', $data['result']['content'][0]['text'] );
	}

	public function test_a_rest_error_with_hint_and_guide_is_preserved_verbatim_and_taught_in_text(): void {
		// Simulate the underlying REST route (wp_elementor_widget_help ->
		// /elementor/widget-help/{widget_type}) failing with a WP_Error that
		// carries both a hint and a guide — the exact shape an unknown
		// Elementor widget type produces in production.
		add_filter(
			'rest_dispatch_request',
			function ( $result, $request ) {
				if ( preg_match( '#/elementor/widget-help/#', (string) $request->get_route() ) ) {
					return new WP_Error(
						'unknown_widget',
						"Unknown widget type 'headng'. Did you mean 'heading'?",
						array(
							'status' => 404,
							'hint'   => 'Widget type "headng" does not exist. Did you mean "heading"? Use wp_get_elementor_widgets to list all available widget types on this site.',
							'guide'  => "wp_get_guide(topic='elementor_widgets')",
						)
					);
				}
				return $result;
			},
			10,
			2
		);

		$data = $this->call( 'wp_elementor_widget_help', array( 'widget_type' => 'headng' ) );

		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertTrue( $data['result']['isError'] );

		// Every machine field a programmatic client previously read from
		// error.data must still be there, verbatim, under structuredContent.error.
		$error = $data['result']['structuredContent']['error'];
		$this->assertSame( 'unknown_widget', $error['code'] );
		$this->assertSame( 404, $error['status'] );
		$this->assertStringContainsString( 'does not exist', $error['hint'] );
		$this->assertSame( "wp_get_guide(topic='elementor_widgets')", $error['guide'] );

		// The whole point: a client that drops error.data must still see the
		// hint and the next-step guide, because they are in the text too.
		$text = $data['result']['content'][0]['text'];
		$this->assertStringContainsString( "Unknown widget type 'headng'", $text );
		$this->assertStringContainsString( 'Fix: ' . $error['hint'], $text );
		$this->assertStringContainsString( "Next: wp_get_guide(topic='elementor_widgets')", $text );
	}

	public function test_a_category_disabled_refusal_is_an_iserror_result(): void {
		// `wp_list_menus`, not `wp_site_info` (#1007 WARN-2): wp_site_info is
		// on the discovery allowlist and deliberately exempt from this gate —
		// see DisabledToolCategoriesTest::test_discovery_allowlist_tool_stays_callable_even_when_its_category_is_disabled().
		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );

		$data = $this->call( 'wp_list_menus' );

		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertTrue( $data['result']['isError'] );
		$this->assertSame( 'category_disabled', $data['result']['structuredContent']['error']['code'] );
	}

	// ---------------------------------------------------------------
	// What must NOT move: malformed requests and unknown tools.
	// ---------------------------------------------------------------

	public function test_unknown_tool_is_still_a_protocol_error(): void {
		$data = $this->call( 'wp_this_tool_does_not_exist_anywhere' );

		$this->assertArrayHasKey( 'error', $data, 'A name that resolves to no tool at all is a protocol error per spec.' );
		$this->assertArrayNotHasKey( 'result', $data );
		$this->assertSame( -32602, $data['error']['code'] );
		$this->assertStringContainsString( 'Unknown tool', $data['error']['message'] );
	}

	public function test_a_tools_call_missing_the_name_field_is_still_a_protocol_error(): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 12,
				'method'  => 'tools/call',
				'params'  => array( 'arguments' => array() ), // no "name" — malformed.
			)
		);

		$data = $controller->handle_mcp( $request )->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertArrayNotHasKey( 'result', $data );
		$this->assertSame( -32602, $data['error']['code'] );
	}

	public function test_an_unknown_json_rpc_method_is_still_a_protocol_error(): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array( 'jsonrpc' => '2.0', 'id' => 13, 'method' => 'not/a/real/method' )
		);

		$data = $controller->handle_mcp( $request )->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32601, $data['error']['code'] );
	}
}
