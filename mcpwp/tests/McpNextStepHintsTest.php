<?php
/**
 * Coverage for next-step hints on tools/call results (#912).
 *
 * Two layers:
 *  1. Mcpwp_MCP_Next_Step_Hints::for_tool() — the pure hint map + argument
 *     builder, no WordPress or REST involved.
 *  2. Mcpwp_REST_MCP::apply_next_step_hints() (private, reached via
 *     Reflection like McpProtocolVersionTest's conform_tool_call_result
 *     coverage) — the chokepoint wiring: a hint is a NEW, SEPARATE content
 *     entry appended after whatever was already there (content[0] is
 *     read for its result data but never rewritten — a client or proxy
 *     that JSON.parse()s content[0].text directly, which is the ONLY
 *     content entry pre-2025-06-18, must never see it corrupted by an
 *     appended human-readable line), plus the same suggestions under
 *     _meta['mcpwp/next'], never structuredContent, dropped for an error
 *     result, and filtered by the same role/availability/profile
 *     visibility tools/list itself computes (#1017 — tools_visible_by_role_and_availability()
 *     / tools_visible_to_current_caller()), never a second, independent check.
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
		$GLOBALS['mcpwp_test_filters']      = array();
		// Pro active so Pro-tier hint targets (wp_set_seo, category "seo")
		// are present in the merged category map at all — otherwise
		// the visibility gate finds no category for them and the
		// disabled-category gate never has anything to bite on.
		$GLOBALS['mcpwp_test_fs'] = new Mcpwp_Test_Fs_Stub( true, true, false, 'pro' );
		$ref                      = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_fs']      = null;
		$GLOBALS['mcpwp_test_filters'] = array();
		$ref                            = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	/**
	 * Force the 'seo' capability Mcpwp_REST_MCP::is_capability_active()
	 * derives (Mcpwp_Core::get_capabilities()['yoast'] etc.) active WITHOUT
	 * defining a real WPSEO_VERSION/RANK_MATH_VERSION/... constant — those
	 * are process-global and can never be un-defined, which would leak "an
	 * SEO plugin is active" into every OTHER test in the same PHPUnit run
	 * (this is exactly why SeoPluginDetectTest uses its own
	 * `mcpwp_seo_plugin_active_override` filter instead of a constant —
	 * that filter is NOT read by Mcpwp_Core::get_capabilities() itself, so
	 * this test file uses the filter get_capabilities() DOES read).
	 * Reset for free every test via the `mcpwp_test_filters` reset above.
	 */
	private function force_seo_capability_active(): void {
		add_filter(
			'mcpwp_site_capabilities',
			static function ( $caps ) {
				$caps['yoast'] = true;
				return $caps;
			}
		);
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

	public function test_hint_appends_a_new_content_entry_and_meta_on_success(): void {
		// #1017: the hint visibility gate now also requires the hinted tool's
		// own plugin to be active (Mcpwp_REST_MCP::get_all_tools()'s
		// capability filter) — wp_set_elementor needs 'elementor', wp_set_seo
		// needs an active SEO plugin.
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		$this->force_seo_capability_active();

		$original = $this->success_response( array( 'id' => 55 ) );

		$out = $this->invoke_apply_next_step_hints( $original, 'wp_create_page', array( 'title' => 'About' ) );

		// content[0] — the tool's own result — is byte-for-byte untouched.
		$this->assertSame( $original['result']['content'][0], $out['result']['content'][0] );

		// The hint lands in a NEW, second content entry, never in content[0].
		$this->assertCount( 2, $out['result']['content'] );
		$this->assertSame( 'text', $out['result']['content'][1]['type'] );
		$this->assertStringContainsString( 'Next: wp_set_elementor({"id":55})', $out['result']['content'][1]['text'] );
		$this->assertStringContainsString( 'Next: wp_set_seo({"id":55})', $out['result']['content'][1]['text'] );
		$this->assertStringNotContainsString( 'Next:', $out['result']['content'][0]['text'] );

		$this->assertArrayHasKey( '_meta', $out['result'] );
		$next = $out['result']['_meta']['mcpwp/next'];
		$this->assertCount( 2, $next );
		$this->assertSame( 'wp_set_elementor', $next[0]['tool'] );
		$this->assertSame( array( 'id' => 55 ), $next[0]['arguments'] );
		$this->assertArrayHasKey( 'why', $next[0] );
	}

	/**
	 * The load-bearing regression for the required fix: content[0] must
	 * json_decode to the EXACT same value whether or not a hint fired —
	 * a client (or a proxy/integration) that JSON.parse()s content[0].text
	 * directly must never see it change shape because a hint was added.
	 */
	public function test_content_zero_json_decodes_identically_with_and_without_a_hint(): void {
		// A hint only fires for a target the caller can see (#1017). This
		// test used to get one only because a sibling had already defined
		// ELEMENTOR_VERSION in this process, so it failed whenever it ran
		// first (#1084). The SEO filter is reset with mcpwp_test_filters and
		// leaks nothing; a constant could not be undefined again.
		$this->force_seo_capability_active();
		$data = array( 'id' => 55, 'title' => 'About', 'status' => 'draft' );

		$without_hint = $this->invoke_apply_next_step_hints( $this->success_response( $data ), 'wp_list_pages', array() );
		$with_hint    = $this->invoke_apply_next_step_hints( $this->success_response( $data ), 'wp_create_page', array( 'title' => 'About' ) );

		// Same raw string, not just the same decoded value — a hint must
		// not touch content[0]['text'] at all, not even re-encode it.
		$this->assertSame( $without_hint['result']['content'][0]['text'], $with_hint['result']['content'][0]['text'] );
		$this->assertSame(
			json_decode( $without_hint['result']['content'][0]['text'], true ),
			json_decode( $with_hint['result']['content'][0]['text'], true )
		);
		// The hinted response did grow — just not at content[0].
		$this->assertCount( 1, $without_hint['result']['content'] );
		$this->assertCount( 2, $with_hint['result']['content'] );
	}

	public function test_no_args_hint_serializes_as_a_json_object_not_an_array(): void {
		$out = $this->invoke_apply_next_step_hints(
			$this->success_response( array( 'detail' => 'slim' ) ),
			'wp_onboard'
		);

		$this->assertStringContainsString( 'Next: wp_get_site_context({})', $out['result']['content'][1]['text'] );
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
		// #1095: wp_create_page's hints (wp_set_elementor, wp_set_seo) are
		// visible only when Elementor / an SEO plugin is active. This test used
		// to set neither and passed only when an earlier test had run
		// define( 'ELEMENTOR_VERSION' ) — a process-global constant that can
		// never be undone — so it failed whenever it ran first or alone.
		// Arrange the SEO capability itself, via the per-test filter (reset in
		// setUp), so the test owns its precondition in any order.
		$this->force_seo_capability_active();

		$response                          = $this->success_response( array( 'id' => 55 ) );
		$response['result']['_meta']       = array( 'plugin_version' => '3.14.9', 'replayed' => true );

		$out = $this->invoke_apply_next_step_hints( $response, 'wp_create_page', array( 'title' => 'About' ) );

		$this->assertSame( '3.14.9', $out['result']['_meta']['plugin_version'] );
		$this->assertTrue( $out['result']['_meta']['replayed'] );
		$this->assertArrayHasKey( 'mcpwp/next', $out['result']['_meta'] );
	}

	/**
	 * A hint naming a tool whose category has been disabled site-wide must
	 * not be shown — the same role/availability visibility tools/list
	 * itself computes (#1017, tools_visible_to_current_caller()).
	 *
	 * wp_onboard's own hint (wp_get_site_context) is no longer a usable
	 * example of that: #1007 review round 2 (owner decision) put
	 * wp_get_site_context on the discovery allowlist — site context is
	 * public brand copy, already published unauthenticated at /llms.txt — so
	 * it is exempt from the disabled-category gate entirely, and
	 * tools_visible_to_current_caller() picks that exemption up from the
	 * same get_discovery_allowlist_tool_names() the hint filter, tools/list,
	 * and handle_tools_call() all share. See
	 * test_wp_onboards_site_context_hint_survives_site_being_disabled()
	 * below for that new, deliberate behavior. The generic "disabled
	 * category drops the hint" mechanism stays covered by
	 * test_a_hint_with_only_some_targets_disabled_keeps_the_reachable_ones()
	 * (wp_set_seo, a genuinely gated tool).
	 */
	public function test_wp_onboards_site_context_hint_survives_site_being_disabled(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );

		$out = $this->invoke_apply_next_step_hints(
			$this->success_response( array( 'detail' => 'slim' ) ),
			'wp_onboard'
		);

		$this->assertArrayHasKey( 'mcpwp/next', $out['result']['_meta'], 'wp_get_site_context is on the discovery allowlist — the hint must survive.' );
		$this->assertSame( 'wp_get_site_context', $out['result']['_meta']['mcpwp/next'][0]['tool'] );
	}

	public function test_wp_onboards_site_context_hint_is_unaffected_by_re_enabling_site(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );
		$disabled = $this->invoke_apply_next_step_hints( $this->success_response( array( 'detail' => 'slim' ) ), 'wp_onboard' );

		delete_option( 'mcpwp_disabled_tool_categories' );
		$enabled = $this->invoke_apply_next_step_hints( $this->success_response( array( 'detail' => 'slim' ) ), 'wp_onboard' );

		$this->assertArrayHasKey( 'mcpwp/next', $disabled['result']['_meta'] );
		$this->assertArrayHasKey( 'mcpwp/next', $enabled['result']['_meta'] );
	}

	public function test_a_hint_with_only_some_targets_disabled_keeps_the_reachable_ones(): void {
		// wp_create_page's two hints are wp_set_elementor (category
		// "elementor") and wp_set_seo (category "seo"). Disabling only
		// "seo" must drop the second hint but keep the first — and
		// wp_set_elementor needs 'elementor' active to be visible at all
		// now that the gate also checks plugin availability (#1017).
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		update_option( 'mcpwp_disabled_tool_categories', array( 'seo' ) );

		$out = $this->invoke_apply_next_step_hints(
			$this->success_response( array( 'id' => 55 ) ),
			'wp_create_page',
			array( 'title' => 'About' )
		);

		$next = $out['result']['_meta']['mcpwp/next'];
		$this->assertCount( 1, $next );
		$this->assertSame( 'wp_set_elementor', $next[0]['tool'] );
		$this->assertStringContainsString( 'wp_set_elementor', $out['result']['content'][1]['text'] );
		$this->assertStringNotContainsString( 'wp_set_seo', $out['result']['content'][1]['text'] );
	}

	/**
	 * #1017: a hint naming a tool whose required plugin is not active on
	 * this site — the live bug (admin key offered wp_set_elementor and
	 * wp_set_seo on a site with neither Elementor nor an SEO plugin) — must
	 * be dropped, exactly like tools/list itself would never advertise that
	 * tool. Run in its own process so no OTHER test file's
	 * `define('ELEMENTOR_VERSION', ...)` leaks in and hides the bug: this
	 * test forces only the 'seo' capability (via the filter, never a real
	 * constant), leaving 'elementor' unavailable.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_hint_dropped_when_the_targets_plugin_is_not_active(): void {
		$this->force_seo_capability_active();
		$this->assertFalse( defined( 'ELEMENTOR_VERSION' ), 'Precondition: this process must not have Elementor "active".' );

		$out = $this->invoke_apply_next_step_hints(
			$this->success_response( array( 'id' => 55 ) ),
			'wp_create_page',
			array( 'title' => 'About' )
		);

		$next = $out['result']['_meta']['mcpwp/next'];
		$this->assertCount( 1, $next, 'Only wp_set_seo (SEO active) should survive; wp_set_elementor (Elementor NOT active) must be dropped.' );
		$this->assertSame( 'wp_set_seo', $next[0]['tool'] );
		$this->assertStringNotContainsString( 'wp_set_elementor', $out['result']['content'][1]['text'] );
	}

	/**
	 * #1017: under the `core` MCP tool profile (#907), a hint naming a tool
	 * that IS reachable (role + availability say yes) but sits outside the
	 * fixed core subset is not simply dropped — it is re-expressed as a
	 * call to `mcpwp_call` (always in `core`) naming the real tool, the
	 * same door a `core` client must already use to reach it directly. A
	 * hint whose target IS in the core subset is shown unchanged.
	 */
	public function test_core_profile_wraps_a_non_core_hint_target_in_mcpwp_call(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		// wp_set_elementor's two hints: wp_regenerate_elementor_css (NOT in
		// Mcpwp_REST_MCP::CORE_TOOL_PROFILE_TOOLS) and wp_get_rendered_html
		// (IS in it).
		$controller = new Mcpwp_REST_MCP();
		$profile    = new ReflectionProperty( Mcpwp_REST_MCP::class, 'resolved_tool_profile' );
		$profile->setAccessible( true );
		$profile->setValue( $controller, 'core' );

		$method = new ReflectionMethod( $controller, 'apply_next_step_hints' );
		$method->setAccessible( true );

		$out = $method->invoke(
			$controller,
			$this->success_response( array( 'id' => 55 ) ),
			'wp_set_elementor',
			array( 'id' => 55, 'elementor_data' => '[]' )
		);

		$next = $out['result']['_meta']['mcpwp/next'];
		$this->assertCount( 2, $next );

		$by_tool = array();
		foreach ( $next as $hint ) {
			$by_tool[ $hint['tool'] ][] = $hint;
		}

		// Core-listed target: shown directly, unwrapped.
		$this->assertArrayHasKey( 'wp_get_rendered_html', $by_tool, 'A core-listed hint target must still be shown directly.' );

		// Non-core but reachable target: wrapped in mcpwp_call.
		$this->assertArrayHasKey( 'mcpwp_call', $by_tool, 'A non-core but reachable hint target must be re-expressed as mcpwp_call under the core profile.' );
		$wrapped = $by_tool['mcpwp_call'][0];
		$this->assertSame( 'wp_regenerate_elementor_css', $wrapped['arguments']['name'] );
		$this->assertSame( array( 'id' => 55 ), $wrapped['arguments']['arguments'] );
		$this->assertStringContainsString( 'mcpwp_call({"name":"wp_regenerate_elementor_css"', $out['result']['content'][1]['text'] );

		// The wrapper itself is never named as a hint TARGET verbatim from
		// a hint source that already named it directly (sanity: exactly one
		// wrap, not one per non-core hint accidentally duplicated).
		$this->assertCount( 1, $by_tool['mcpwp_call'] );
	}

	/**
	 * The `full` profile (the default) never wraps — a hint for a
	 * reachable-but-non-"core"-listed tool means nothing under `full`,
	 * since `full` has no subset to begin with, and is shown directly.
	 */
	public function test_full_profile_never_wraps_a_hint_in_mcpwp_call(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		$out = $this->invoke_apply_next_step_hints(
			$this->success_response( array( 'id' => 55 ) ),
			'wp_set_elementor',
			array( 'id' => 55, 'elementor_data' => '[]' )
		);

		$next = $out['result']['_meta']['mcpwp/next'];
		$tools = array_column( $next, 'tool' );
		$this->assertContains( 'wp_regenerate_elementor_css', $tools );
		$this->assertNotContains( 'mcpwp_call', $tools );
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
		// content[0] carries the tool's own result, unmutated; the hint is
		// a distinct second content entry.
		$this->assertCount( 2, $data['result']['content'] );
		$this->assertIsArray( json_decode( $data['result']['content'][0]['text'], true ), 'content[0] must still be valid, parseable JSON of the result data.' );
		$this->assertStringContainsString( 'Next: wp_get_site_context({})', $data['result']['content'][1]['text'] );
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

	/**
	 * The idempotency store persists handle_tools_call()'s own return value
	 * — captured BEFORE conform_tool_call_result()/apply_next_step_hints()
	 * ever runs (#802) — so the stored record must never itself contain a
	 * hint content entry. Confirmed two ways: the stored transient payload
	 * has exactly the tool's own content entries, and calling the SAME
	 * idempotent request twice never grows content past one hint entry
	 * (a bug here would double it on the second call).
	 */
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_idempotent_replay_never_stores_or_duplicates_the_hint_block(): void {
		// wp_onboard is a GET/read tool and is never pinned for idempotency
		// (handle_tools_call_idempotent()'s own "read tool" -> not_applicable
		// short-circuit) — a WRITE tool, AND an authenticated principal
		// (idempotency is scoped per-key; no key context, no pinning), are
		// needed to actually exercise the stored-replay path this test is
		// about — same setup IdempotentToolCallTest (#802) uses.
		//
		// wp_set_elementor (not wp_create_page): the test bootstrap's
		// generic REST dispatch stub answers an unmapped route with a bare
		// {"ok":true} (no `id`), so a hint that only reads the RESULT's id
		// would have nothing to build from here. wp_set_elementor's hint
		// build ('id_from_result_or_args') falls back to the call's OWN
		// `id` argument — supplied directly below — so the hint still
		// resolves regardless of what the stubbed dispatch returns.
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
		// wp_set_elementor's required-capability gate (get_required_capabilities()
		// -> 'elementor') needs this defined, same as every other Elementor test
		// (a process-wide constant — defined only if a prior test hasn't already).
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'hint replay test', array( 'admin' ) );

		$make_request = function () use ( $key ) {
			return new WP_REST_Request(
				'POST',
				'/mcpwp/v1/mcp',
				array(),
				array( 'X-API-Key' => $key['key'] ),
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'wp_set_elementor',
						'arguments' => array( 'id' => 42, 'elementor_data' => '[]' ),
						'_meta'     => array( 'request_id' => 'mcp-912-replay-test' ),
					),
				)
			);
		};

		$first_request = $make_request();
		$this->assertTrue( $controller->verify_api_key( $first_request ), 'Test premise: the key authenticates.' );
		$first = $controller->handle_mcp( $first_request )->get_data();

		$second_request = $make_request();
		$this->assertTrue( $controller->verify_api_key( $second_request ) );
		$second = $controller->handle_mcp( $second_request )->get_data();

		$this->assertArrayNotHasKey( 'replayed', $first['result']['_meta'] ?? array() );
		$this->assertTrue( $second['result']['_meta']['replayed'] ?? false, 'The second identical request must be served from the idempotency store.' );

		// Both the fresh call and the replay carry exactly one hint entry —
		// never two, never zero.
		$this->assertCount( 2, $first['result']['content'] );
		$this->assertCount( 2, $second['result']['content'] );
		$this->assertSame( $first['result']['content'][0]['text'], $second['result']['content'][0]['text'] );
		$this->assertSame( $first['result']['content'][1]['text'], $second['result']['content'][1]['text'] );

		// The stored record itself (read directly from the transient) has
		// no hint entry at all — it is handle_tools_call()'s raw return
		// value, from before apply_next_step_hints() ever ran.
		$store_key = Mcpwp_REST_MCP::idempotency_key( $key['id'], 'mcp-912-replay-test' );
		$stored    = get_transient( $store_key );

		$this->assertIsArray( $stored );
		$this->assertSame( 'done', $stored['state'] );
		$this->assertCount( 1, $stored['response']['result']['content'], 'The stored record must carry only the tool\'s own content entry, never a hint entry.' );
	}
}
