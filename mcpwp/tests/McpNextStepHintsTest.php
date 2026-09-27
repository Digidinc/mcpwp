<?php
/**
 * Coverage for next-step hints on tools/call results (#912).
 *
 * Two layers:
 *  1. Mcpwp_MCP_Next_Step_Hints::for_tool() — the pure hint map + argument
 *     builder, no WordPress or REST involved.
 *  2. Mcpwp_REST_MCP::apply_next_step_hints() (private, reached via
 *     Reflection like McpProtocolVersionTest's conform_tool_call_result
 *     coverage) — the chokepoint wiring: text line + _meta['mcpwp/next'],
 *     never structuredContent, dropped for an error result, and filtered by
 *     the same tool_currently_permitted() gate the idempotent-replay path
 *     already uses for category/role visibility.
 *  3. One true end-to-end call through Mcpwp_REST_MCP::handle_mcp() proving
 *     the wiring from process_single_request() through to the HTTP response
 *     body actually fires for a real tools/call.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class McpNextStepHintsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 2;
		// Pro active so Pro-tier hint targets (wp_set_seo, category "seo")
		// are present in the merged category map at all — otherwise
		// tool_currently_permitted() finds no category for them and the
		// disabled-category gate never has anything to bite on.
		$GLOBALS['mcpwp_test_fs'] = new Mcpwp_Test_Fs_Stub( true, true, false, 'pro' );
		$ref                      = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_fs'] = null;
		$ref                      = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	// ---------------------------------------------------------------
	// Layer 1: Mcpwp_MCP_Next_Step_Hints::for_tool()
	// ---------------------------------------------------------------

	public function test_unlisted_tool_gets_no_hints(): void {
		$this->assertSame( array(), Mcpwp_MCP_Next_Step_Hints::for_tool( 'wp_list_pages', array(), array() ) );
	}

	public function test_create_page_hints_use_the_new_page_id_from_the_result(): void {
		$hints = Mcpwp_MCP_Next_Step_Hints::for_tool( 'wp_create_page', array( 'title' => 'About' ), array( 'id' => 77 ) );

		$this->assertCount( 2, $hints );
		$this->assertSame( 'wp_set_elementor', $hints[0]['tool'] );
		$this->assertSame( array( 'id' => 77 ), $hints[0]['arguments'] );
		$this->assertSame( 'wp_set_seo', $hints[1]['tool'] );
		$this->assertSame( array( 'id' => 77 ), $hints[1]['arguments'] );
	}

	public function test_edit_tools_fall_back_to_the_id_argument_when_the_result_has_none(): void {
		// wp_edit_widget's own result does not carry a top-level `id` (it
		// returns the modified element, not the page) — the hint must fall
		// back to the `id` the caller already sent.
		$hints = Mcpwp_MCP_Next_Step_Hints::for_tool( 'wp_edit_widget', array( 'id' => 42, 'widget_id' => 'wid0001' ), array( 'success' => true ) );

		$this->assertCount( 1, $hints );
		$this->assertSame( 'wp_get_rendered_html', $hints[0]['tool'] );
		$this->assertSame( array( 'id' => 42 ), $hints[0]['arguments'] );
	}

	public function test_no_hint_when_neither_result_nor_arguments_carry_an_id(): void {
		// Never guess: if the id-bearing tool somehow gave us nothing to
		// build a next call from, no hint is better than a broken one.
		$this->assertSame( array(), Mcpwp_MCP_Next_Step_Hints::for_tool( 'wp_edit_widget', array(), array() ) );
	}

	public function test_alt_text_hint_is_skipped_when_the_caller_already_supplied_alt(): void {
		$with_alt = Mcpwp_MCP_Next_Step_Hints::for_tool(
			'wp_upload_media_from_url',
			array( 'url' => 'https://example.com/x.jpg', 'alt' => 'A description' ),
			array( 'id' => 501 )
		);
		$this->assertSame( array(), $with_alt );

		$without_alt = Mcpwp_MCP_Next_Step_Hints::for_tool(
			'wp_upload_media_from_url',
			array( 'url' => 'https://example.com/x.jpg' ),
			array( 'id' => 501 )
		);
		$this->assertCount( 1, $without_alt );
		$this->assertSame( 'wp_generate_alt_text', $without_alt[0]['tool'] );
		$this->assertSame( array( 'attachment_id' => 501 ), $without_alt[0]['arguments'] );
	}

	public function test_graph_query_hint_targets_the_first_result_node(): void {
		$hints = Mcpwp_MCP_Next_Step_Hints::for_tool(
			'wp_graph_query',
			array( 'orphan' => true ),
			array( 'results' => array( array( 'node_key' => 'post:12' ), array( 'node_key' => 'post:13' ) ) )
		);

		$this->assertCount( 1, $hints );
		$this->assertSame( 'wp_graph_node', $hints[0]['tool'] );
		$this->assertSame( array( 'node_key' => 'post:12' ), $hints[0]['arguments'] );
	}

	public function test_graph_query_has_no_hint_when_results_are_empty(): void {
		$this->assertSame( array(), Mcpwp_MCP_Next_Step_Hints::for_tool( 'wp_graph_query', array(), array( 'results' => array() ) ) );
	}

	public function test_onboard_hint_takes_no_arguments(): void {
		$hints = Mcpwp_MCP_Next_Step_Hints::for_tool( 'wp_onboard', array(), array( 'detail' => 'slim' ) );

		$this->assertCount( 1, $hints );
		$this->assertSame( 'wp_get_site_context', $hints[0]['tool'] );
		$this->assertSame( array(), $hints[0]['arguments'] );
	}

	/**
	 * Every tool named in the #912 brief as a hint source actually has at
	 * least one hint spec — a regression guard against a future edit
	 * quietly dropping one.
	 */
	public function test_every_documented_workflow_tool_has_a_hint_source(): void {
		foreach (
			array(
				'wp_create_page',
				'wp_create_post',
				'wp_set_elementor',
				'wp_build_page',
				'wp_edit_widget',
				'wp_add_section',
				'wp_set_seo',
				'wp_upload_media_from_url',
				'wp_graph_query',
				'wp_onboard',
			) as $tool_name
		) {
			$this->assertArrayHasKey( $tool_name, Mcpwp_MCP_Next_Step_Hints::HINT_SOURCES, "{$tool_name}: expected a hint source" );
		}
	}

	// ---------------------------------------------------------------
	// Layer 2: Mcpwp_REST_MCP::apply_next_step_hints() via Reflection
	// ---------------------------------------------------------------

	private function invoke_apply_next_step_hints( array $response, string $tool_name, array $arguments = array() ): array {
		$controller = new Mcpwp_REST_MCP();
		$method     = new ReflectionMethod( $controller, 'apply_next_step_hints' );
		$method->setAccessible( true );
		return $method->invoke( $controller, $response, $tool_name, $arguments );
	}

	private function success_response( array $data ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'result'  => array(
				'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $data ) ) ),
				'isError' => false,
			),
		);
	}

	public function test_hint_appends_a_next_line_and_meta_on_success(): void {
		$out = $this->invoke_apply_next_step_hints(
			$this->success_response( array( 'id' => 55 ) ),
			'wp_create_page',
			array( 'title' => 'About' )
		);

		$this->assertStringContainsString( 'Next: wp_set_elementor({"id":55})', $out['result']['content'][0]['text'] );
		$this->assertStringContainsString( 'Next: wp_set_seo({"id":55})', $out['result']['content'][0]['text'] );

		$this->assertArrayHasKey( '_meta', $out['result'] );
		$next = $out['result']['_meta']['mcpwp/next'];
		$this->assertCount( 2, $next );
		$this->assertSame( 'wp_set_elementor', $next[0]['tool'] );
		$this->assertSame( array( 'id' => 55 ), $next[0]['arguments'] );
		$this->assertArrayHasKey( 'why', $next[0] );
	}

	public function test_no_args_hint_serializes_as_a_json_object_not_an_array(): void {
		$out = $this->invoke_apply_next_step_hints(
			$this->success_response( array( 'detail' => 'slim' ) ),
			'wp_onboard'
		);

		$this->assertStringContainsString( 'Next: wp_get_site_context({})', $out['result']['content'][0]['text'] );
		$encoded = wp_json_encode( $out['result']['_meta']['mcpwp/next'][0]['arguments'] );
		$this->assertSame( '{}', $encoded, 'An empty hint-arguments object must encode as {}, not [].' );
	}

	public function test_error_results_never_get_a_hint(): void {
		$response = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'result'  => array(
				'content' => array( array( 'type' => 'text', 'text' => 'boom' ) ),
				'isError' => true,
			),
		);

		$out = $this->invoke_apply_next_step_hints( $response, 'wp_create_page', array( 'title' => 'About' ) );

		$this->assertSame( $response, $out, 'An isError result must be returned completely unchanged.' );
	}

	public function test_unlisted_tool_leaves_the_response_untouched(): void {
		$response = $this->success_response( array( 'id' => 1 ) );

		$out = $this->invoke_apply_next_step_hints( $response, 'wp_list_pages', array() );

		$this->assertSame( $response, $out );
	}

	public function test_structured_content_is_never_touched_by_a_hint(): void {
		$response                                                   = $this->success_response( array( 'id' => 55 ) );
		$response['result']['structuredContent']                    = array( 'id' => 55, 'title' => 'About' );
		$before_structured                                           = $response['result']['structuredContent'];

		$out = $this->invoke_apply_next_step_hints( $response, 'wp_create_page', array( 'title' => 'About' ) );

		$this->assertSame( $before_structured, $out['result']['structuredContent'], 'structuredContent must be byte-for-byte unchanged by a hint.' );
		$this->assertArrayNotHasKey( 'next', $out['result']['structuredContent'] );
	}

	public function test_existing_meta_keys_survive_alongside_the_hint(): void {
		$response                          = $this->success_response( array( 'id' => 55 ) );
		$response['result']['_meta']       = array( 'plugin_version' => '3.14.9', 'replayed' => true );

		$out = $this->invoke_apply_next_step_hints( $response, 'wp_create_page', array( 'title' => 'About' ) );

		$this->assertSame( '3.14.9', $out['result']['_meta']['plugin_version'] );
		$this->assertTrue( $out['result']['_meta']['replayed'] );
		$this->assertArrayHasKey( 'mcpwp/next', $out['result']['_meta'] );
	}

	/**
	 * A hint naming a tool whose category has been disabled site-wide must
	 * not be shown — same tool_currently_permitted() gate the idempotent
	 * replay path already relies on for its own permission recheck (#802).
	 */
	public function test_hint_is_dropped_when_the_target_tools_category_is_disabled(): void {
		// wp_get_site_context (the hint wp_onboard points at) is category "site".
		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );

		$out = $this->invoke_apply_next_step_hints(
			$this->success_response( array( 'detail' => 'slim' ) ),
			'wp_onboard'
		);

		$this->assertStringNotContainsString( 'Next:', $out['result']['content'][0]['text'] );
		$this->assertArrayNotHasKey( '_meta', $out['result'] );
	}

	public function test_hint_reappears_once_the_category_is_re_enabled(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );
		$blocked = $this->invoke_apply_next_step_hints( $this->success_response( array( 'detail' => 'slim' ) ), 'wp_onboard' );
		$this->assertArrayNotHasKey( '_meta', $blocked['result'] );

		delete_option( 'mcpwp_disabled_tool_categories' );
		$restored = $this->invoke_apply_next_step_hints( $this->success_response( array( 'detail' => 'slim' ) ), 'wp_onboard' );
		$this->assertArrayHasKey( 'mcpwp/next', $restored['result']['_meta'] );
	}

	public function test_a_hint_with_only_some_targets_disabled_keeps_the_reachable_ones(): void {
		// wp_create_page's two hints are wp_set_elementor (category
		// "elementor") and wp_set_seo (category "seo"). Disabling only
		// "seo" must drop the second hint but keep the first.
		update_option( 'mcpwp_disabled_tool_categories', array( 'seo' ) );

		$out = $this->invoke_apply_next_step_hints(
			$this->success_response( array( 'id' => 55 ) ),
			'wp_create_page',
			array( 'title' => 'About' )
		);

		$next = $out['result']['_meta']['mcpwp/next'];
		$this->assertCount( 1, $next );
		$this->assertSame( 'wp_set_elementor', $next[0]['tool'] );
		$this->assertStringContainsString( 'wp_set_elementor', $out['result']['content'][0]['text'] );
		$this->assertStringNotContainsString( 'wp_set_seo', $out['result']['content'][0]['text'] );
	}

	// ---------------------------------------------------------------
	// Layer 3: end-to-end through Mcpwp_REST_MCP::handle_mcp()
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_end_to_end_tools_call_carries_the_hint(): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'wp_onboard',
					'arguments' => array(),
				),
			)
		);

		$data = $controller->handle_mcp( $request )->get_data();

		$this->assertFalse( $data['result']['isError'] );
		$this->assertStringContainsString( 'Next: wp_get_site_context({})', $data['result']['content'][0]['text'] );
		$this->assertSame(
			array(
				array(
					'tool'      => 'wp_get_site_context',
					'why'       => 'read the full brand/style guide before writing or designing content',
					'arguments' => array(),
				),
			),
			json_decode( wp_json_encode( $data['result']['_meta']['mcpwp/next'] ), true )
		);
	}
}
