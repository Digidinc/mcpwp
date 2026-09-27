<?php
/**
 * Coverage for #907 — the `core` MCP tool profile plus the two meta tools
 * (`mcpwp_find_tools`, `mcpwp_call`) that make it viable for a client capped
 * to a small tools/list (Cursor's 40-tool limit, a fixed token budget).
 *
 * Covers:
 *   - profile resolution precedence: `?profile=` query arg > site setting
 *     (`mcpwp_settings.mcp_tool_profile`) > `full` default, with an
 *     unrecognised value at any level falling through rather than erroring.
 *   - the `core` list is exactly the CORE_TOOL_PROFILE_TOOLS/META_TOOL_NAMES
 *     intersection with what `full` actually advertises — never more.
 *   - `full` is unchanged except for the two meta tools appended at the end.
 *   - mcpwp_find_tools only ever returns tools the caller could call right
 *     now — a role-restricted key never sees a category it lacks.
 *   - mcpwp_call dispatches through the exact same gate as a direct
 *     tools/call: a key refused on the inner tool directly is refused the
 *     same way through mcpwp_call, analytics fires with the INNER tool
 *     name, and nothing dispatches either way.
 *   - mcpwp_call refuses to wrap itself or mcpwp_find_tools (no recursion).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class McpToolProfileTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_actions']           = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_actions']           = array();
	}

	/**
	 * @param string      $method       'tools/list' or 'tools/call'.
	 * @param array       $params       JSON-RPC params.
	 * @param array       $headers      Request headers (e.g. X-API-Key).
	 * @param string|null $query_profile ?profile= query arg, or null to omit.
	 */
	private function request( string $method, array $params, array $headers = array(), ?string $query_profile = null ): WP_REST_Request {
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			$headers,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => $method,
				'params'  => $params,
			)
		);
		if ( null !== $query_profile ) {
			$request->set_query_params( array( 'profile' => $query_profile ) );
		}
		return $request;
	}

	/** tools/list with no API key context — unrestricted (admin fallback). */
	private function list_tools( ?string $query_profile = null ): array {
		$controller = new Mcpwp_REST_MCP();
		$data       = $controller->handle_mcp( $this->request( 'tools/list', array(), array(), $query_profile ) )->get_data();
		return array_column( $data['result']['tools'], 'name' );
	}

	// ── Profile resolution precedence ───────────────────────────────────────

	public function test_default_profile_is_full(): void {
		$names = $this->list_tools();
		// A tool nowhere near the core list must still be advertised.
		$this->assertContains( 'wp_delete_all_drafts', $names, 'Default profile must be full.' );
	}

	public function test_site_setting_selects_core_profile(): void {
		update_option( 'mcpwp_settings', array( 'mcp_tool_profile' => 'core' ) );

		$names = $this->list_tools();

		$this->assertNotContains( 'wp_delete_all_drafts', $names, 'Site setting "core" must trim tools/list.' );
		$this->assertContains( 'wp_onboard', $names );
		$this->assertContains( 'mcpwp_find_tools', $names );
		$this->assertContains( 'mcpwp_call', $names );
	}

	public function test_query_arg_overrides_site_setting_to_core(): void {
		update_option( 'mcpwp_settings', array( 'mcp_tool_profile' => 'full' ) );

		$names = $this->list_tools( 'core' );

		$this->assertNotContains( 'wp_delete_all_drafts', $names, '?profile=core must win over a "full" site setting.' );
	}

	public function test_query_arg_overrides_site_setting_to_full(): void {
		update_option( 'mcpwp_settings', array( 'mcp_tool_profile' => 'core' ) );

		$names = $this->list_tools( 'full' );

		$this->assertContains( 'wp_delete_all_drafts', $names, '?profile=full must win over a "core" site setting.' );
	}

	public function test_unrecognised_query_arg_falls_back_to_site_setting(): void {
		update_option( 'mcpwp_settings', array( 'mcp_tool_profile' => 'core' ) );

		$names = $this->list_tools( 'bogus' );

		$this->assertNotContains( 'wp_delete_all_drafts', $names, 'An unrecognised ?profile= must fall through to the site setting, not error.' );
	}

	public function test_unrecognised_site_setting_falls_back_to_full(): void {
		update_option( 'mcpwp_settings', array( 'mcp_tool_profile' => 'nonsense' ) );

		$names = $this->list_tools();

		$this->assertContains( 'wp_delete_all_drafts', $names, 'An unrecognised stored value must fall back to full, never error the handshake.' );
	}

	// ── `core` list contents ────────────────────────────────────────────────

	public function test_core_profile_is_exactly_the_advertised_intersection_plus_meta_tools(): void {
		$full_names = $this->list_tools( 'full' );
		$core_names = $this->list_tools( 'core' );

		$expected = array_values(
			array_unique(
				array_merge(
					array_intersect( Mcpwp_REST_MCP::CORE_TOOL_PROFILE_TOOLS, $full_names ),
					Mcpwp_REST_MCP::META_TOOL_NAMES
				)
			)
		);

		sort( $expected );
		$actual = $core_names;
		sort( $actual );

		$this->assertSame( $expected, $actual, 'core profile must be exactly the CORE_TOOL_PROFILE_TOOLS/full intersection plus the two meta tools — nothing else.' );
	}

	public function test_core_profile_excludes_known_non_core_tools(): void {
		$core_names = $this->list_tools( 'core' );

		foreach ( array( 'wp_delete_all_drafts', 'wp_get_content_graph', 'wp_bulk_create_pages', 'wp_analytics' ) as $excluded ) {
			$this->assertNotContains( $excluded, $core_names, "\"$excluded\" must not be in the core profile." );
		}
	}

	// ── `full` profile is unchanged except the 2 appended meta tools ───────

	public function test_full_profile_only_gains_the_two_meta_tools_appended_at_the_end(): void {
		$full_names = $this->list_tools( 'full' );

		$this->assertSame(
			array( 'mcpwp_find_tools', 'mcpwp_call' ),
			array_slice( $full_names, -2 ),
			'The two meta tools must be appended at the end, never interleaved with existing tools.'
		);

		$without_meta = array_slice( $full_names, 0, -2 );
		$this->assertNotContains( 'mcpwp_find_tools', $without_meta );
		$this->assertNotContains( 'mcpwp_call', $without_meta );
		$this->assertSame( count( array_unique( $full_names ) ), count( $full_names ), 'No duplicate tool names.' );
	}

	// ── mcpwp_find_tools: same visibility tools/list itself applies ────────

	public function test_find_tools_hides_a_category_the_key_does_not_have(): void {
		$controller = new Mcpwp_REST_MCP();
		// 'meta' so the key can call mcpwp_find_tools at all; deliberately no
		// 'content', so wp_list_pages/wp_create_page/etc. must never appear.
		$restricted = $controller->create_scoped_api_key( 'no-content', array( 'read', 'write' ), 'custom', array( 'seo', 'meta' ) );

		$request = $this->request(
			'tools/call',
			array(
				'name'      => 'mcpwp_find_tools',
				'arguments' => array( 'query' => 'page', 'limit' => 20 ),
			),
			array( 'X-API-Key' => $restricted['key'] )
		);

		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->assertTrue( $controller->verify_api_key( $request ) );
		$data = $controller->handle_mcp( $request )->get_data();

		$this->assertArrayHasKey( 'result', $data );
		$this->assertFalse( $data['result']['isError'] ?? true, 'mcpwp_find_tools itself must be callable by a key holding the "meta" category.' );

		$matches = json_decode( $data['result']['content'][0]['text'], true )['matches'];
		foreach ( $matches as $match ) {
			$this->assertNotSame( 'content', $match['category'], 'A "content" tool must not leak into search results for a key without that category.' );
			$this->assertNotContains( $match['name'], array( 'wp_list_pages', 'wp_create_page', 'wp_get_page_by_slug' ) );
		}
	}

	public function test_find_tools_shows_content_tools_to_an_unrestricted_key(): void {
		// Contrast case: proves the query itself would match content tools —
		// the previous test's empty-for-content result is the gate working,
		// not an unrelated scoring miss.
		$data = $this->call_tool_unauthenticated( 'mcpwp_find_tools', array( 'query' => 'page', 'limit' => 20 ) );

		$matches = json_decode( $data['result']['content'][0]['text'], true )['matches'];
		$names   = array_column( $matches, 'name' );

		$this->assertContains( 'wp_list_pages', $names, 'An unrestricted key must see content tools that match the query.' );
	}

	public function test_find_tools_never_returns_itself_or_mcpwp_call(): void {
		$data    = $this->call_tool_unauthenticated( 'mcpwp_find_tools', array( 'query' => 'tool call find', 'limit' => 20 ) );
		$matches = json_decode( $data['result']['content'][0]['text'], true )['matches'];
		$names   = array_column( $matches, 'name' );

		$this->assertNotContains( 'mcpwp_find_tools', $names );
		$this->assertNotContains( 'mcpwp_call', $names );
	}

	public function test_find_tools_requires_a_query(): void {
		$data = $this->call_tool_unauthenticated( 'mcpwp_find_tools', array() );

		$this->assertTrue( $data['result']['isError'] );
		$this->assertSame( 'invalid_arguments', $data['result']['structuredContent']['error']['code'] );
	}

	// ── mcpwp_call: same gate as a direct call, INNER tool name, no dispatch ─

	public function test_mcpwp_call_refuses_exactly_like_a_direct_call_and_never_dispatches(): void {
		$controller = new Mcpwp_REST_MCP();
		// 'content' + 'meta', deliberately no 'elementor'.
		$restricted = $controller->create_scoped_api_key( 'no-elementor', array( 'read', 'write' ), 'custom', array( 'content', 'meta' ) );

		$fired = array();
		add_action(
			'mcpwp_tool_called',
			static function ( $tool, $category, $duration_ms, $error_code ) use ( &$fired ) {
				$fired[] = array( 'tool' => $tool, 'category' => $category, 'error_code' => $error_code );
			},
			10,
			4
		);

		// Direct call.
		$direct_request = $this->request(
			'tools/call',
			array(
				'name'      => 'wp_set_elementor',
				'arguments' => array( 'id' => 6, 'elementor_data' => '[]' ),
			),
			array( 'X-API-Key' => $restricted['key'] )
		);
		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->assertTrue( $controller->verify_api_key( $direct_request ) );
		$direct = $controller->handle_mcp( $direct_request )->get_data();

		$this->assertTrue( $direct['result']['isError'] );
		$this->assertSame( 'scope_denied', $direct['result']['structuredContent']['error']['code'] );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatched_routes'], 'A refused direct call must never dispatch.' );

		// Same call, wrapped in mcpwp_call.
		$controller2 = new Mcpwp_REST_MCP();
		$wrapped_request = $this->request(
			'tools/call',
			array(
				'name'      => 'mcpwp_call',
				'arguments' => array(
					'name'      => 'wp_set_elementor',
					'arguments' => array( 'id' => 6, 'elementor_data' => '[]' ),
				),
			),
			array( 'X-API-Key' => $restricted['key'] )
		);
		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->assertTrue( $controller2->verify_api_key( $wrapped_request ) );
		$wrapped = $controller2->handle_mcp( $wrapped_request )->get_data();

		$this->assertTrue( $wrapped['result']['isError'], 'mcpwp_call must refuse exactly like the direct call.' );
		$this->assertSame( 'scope_denied', $wrapped['result']['structuredContent']['error']['code'] );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatched_routes'], 'A refused mcpwp_call must never dispatch the inner tool either.' );

		// Analytics fired with the INNER tool name both times, never "mcpwp_call".
		$tool_names = array_column( $fired, 'tool' );
		$this->assertNotContains( 'mcpwp_call', $tool_names, 'mcpwp_tool_called must never fire for the wrapper name.' );
		$this->assertSame(
			array( 'wp_set_elementor', 'wp_set_elementor' ),
			$tool_names,
			'Both the direct call and the mcpwp_call-wrapped call must report analytics under the inner tool name.'
		);
	}

	public function test_mcpwp_call_succeeds_when_the_key_is_allowed(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );

		$request = $this->request(
			'tools/call',
			array(
				'name'      => 'mcpwp_call',
				'arguments' => array(
					'name'      => 'wp_list_pages',
					'arguments' => array(),
				),
			),
			array( 'X-API-Key' => $key['key'] )
		);
		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->assertTrue( $controller->verify_api_key( $request ) );
		$data = $controller->handle_mcp( $request )->get_data();

		$this->assertArrayHasKey( 'result', $data );
		$this->assertFalse( $data['result']['isError'] ?? true, 'An allowed mcpwp_call must dispatch and succeed.' );
		$this->assertContains( '/mcpwp/v1/pages', $GLOBALS['mcpwp_test_dispatched_routes'], 'mcpwp_call must dispatch through the real route, not a side door.' );
	}

	public function test_mcpwp_call_requires_a_name_argument(): void {
		$data = $this->call_tool_unauthenticated( 'mcpwp_call', array( 'arguments' => array() ) );

		$this->assertTrue( $data['result']['isError'] );
		$this->assertSame( 'invalid_arguments', $data['result']['structuredContent']['error']['code'] );
	}

	// ── Recursion refusal ───────────────────────────────────────────────────

	public function test_mcpwp_call_refuses_to_wrap_itself(): void {
		$data = $this->call_tool_unauthenticated(
			'mcpwp_call',
			array(
				'name'      => 'mcpwp_call',
				'arguments' => array( 'name' => 'wp_site_info' ),
			)
		);

		$this->assertTrue( $data['result']['isError'] );
		$this->assertSame( 'recursion_denied', $data['result']['structuredContent']['error']['code'] );
	}

	public function test_mcpwp_call_refuses_to_wrap_find_tools(): void {
		$data = $this->call_tool_unauthenticated(
			'mcpwp_call',
			array(
				'name'      => 'mcpwp_find_tools',
				'arguments' => array( 'query' => 'page' ),
			)
		);

		$this->assertTrue( $data['result']['isError'] );
		$this->assertSame( 'recursion_denied', $data['result']['structuredContent']['error']['code'] );
	}

	// ── Adversarial review round 1: WARN-1 — "meta" gate on mcpwp_call ─────

	public function test_mcpwp_call_refused_when_meta_category_disabled(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'meta' ) );

		$data = $this->call_tool_unauthenticated(
			'mcpwp_call',
			array(
				'name'      => 'wp_site_info',
				'arguments' => array(),
			)
		);

		$this->assertTrue( $data['result']['isError'], 'Disabling "meta" must turn off mcpwp_call itself, not just mcpwp_find_tools/tools-list.' );
		$this->assertSame( 'category_disabled', $data['result']['structuredContent']['error']['code'] );
	}

	public function test_mcpwp_call_refused_when_key_role_lacks_meta_category(): void {
		$controller = new Mcpwp_REST_MCP();
		// Has 'content' — the INNER tool's own category — but not 'meta'.
		$key = $controller->create_scoped_api_key( 'no-meta', array( 'read', 'write' ), 'custom', array( 'content' ) );

		$request = $this->request(
			'tools/call',
			array(
				'name'      => 'mcpwp_call',
				'arguments' => array(
					'name'      => 'wp_list_pages',
					'arguments' => array(),
				),
			),
			array( 'X-API-Key' => $key['key'] )
		);
		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->assertTrue( $controller->verify_api_key( $request ) );
		$data = $controller->handle_mcp( $request )->get_data();

		$this->assertTrue( $data['result']['isError'], 'A key without "meta" must not be able to use mcpwp_call, even to reach a tool its OTHER categories already permit.' );
		$this->assertSame( 'scope_denied', $data['result']['structuredContent']['error']['code'] );
		$this->assertStringContainsString( 'meta', $data['result']['structuredContent']['error']['message'] );
	}

	// ── Adversarial review round 1: WARN-2 — unbounded find_tools query ────

	public function test_find_tools_query_is_capped_fast_and_notes_truncation(): void {
		$huge  = str_repeat( 'a', 400000 ) . ' ' . implode( ' ', array_fill( 0, 50, 'wordterm' ) );
		$start = microtime( true );
		$data  = $this->call_tool_unauthenticated( 'mcpwp_find_tools', array( 'query' => $huge, 'limit' => 5 ) );
		$this->assertLessThan( 2.0, microtime( true ) - $start, 'A 400KB query must not blow up scoring time — it must be capped, not scored in full.' );

		$this->assertFalse( $data['result']['isError'] );
		$payload = json_decode( $data['result']['content'][0]['text'], true );
		$this->assertArrayHasKey( 'query_truncated', $payload, 'A query over the char/term cap must say so.' );
		$this->assertSame( Mcpwp_REST_MCP::FIND_TOOLS_QUERY_MAX_CHARS, $payload['query_truncated']['chars_kept'] );
		$this->assertSame( Mcpwp_REST_MCP::FIND_TOOLS_QUERY_MAX_TERMS, $payload['query_truncated']['terms_kept'] );
	}

	public function test_find_tools_short_query_is_not_marked_truncated(): void {
		$data    = $this->call_tool_unauthenticated( 'mcpwp_find_tools', array( 'query' => 'elementor widget', 'limit' => 5 ) );
		$payload = json_decode( $data['result']['content'][0]['text'], true );
		$this->assertArrayNotHasKey( 'query_truncated', $payload );
	}

	// ── Adversarial review round 1: NIT-2 — stray outer argument keys ───────

	public function test_mcpwp_call_refuses_stray_top_level_argument_keys(): void {
		$data = $this->call_tool_unauthenticated(
			'mcpwp_call',
			array(
				'name'      => 'wp_list_pages',
				'per_page'  => 1,
			)
		);

		$this->assertTrue( $data['result']['isError'], 'A key flattened onto mcpwp_call\'s own arguments (instead of nested under "arguments") must be refused, not silently dropped.' );
		$this->assertSame( 'invalid_params', $data['result']['structuredContent']['error']['code'] );
		$this->assertStringContainsString( 'per_page', $data['result']['structuredContent']['error']['message'] );
	}

	/** tools/call with no API key context (unrestricted admin fallback). */
	private function call_tool_unauthenticated( string $name, array $arguments ): array {
		$controller = new Mcpwp_REST_MCP();
		$request    = $this->request( 'tools/call', array( 'name' => $name, 'arguments' => $arguments ) );
		return $controller->handle_mcp( $request )->get_data();
	}
}
