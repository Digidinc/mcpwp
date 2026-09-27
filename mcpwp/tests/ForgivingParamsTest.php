<?php
/**
 * Coverage for #911 — forgiving MCP tool arguments: an unknown top-level
 * argument key that is an unambiguous near-miss of a real param is silently
 * remapped (and noted, for the caller to learn); everything else unresolved
 * is refused before dispatch, naming the unknown key, its closest valid
 * key(s), and the tool's required parameters.
 *
 * Two layers, matching this file's siblings:
 *  - Mcpwp_REST_MCP::remap_near_miss_params() / validate_tool_arguments()
 *    exercised directly via reflection (ParamAliasResolutionTest.php's
 *    style) for the edge cases a real tool schema does not naturally
 *    produce (ties, a synthetic additionalProperties:true schema);
 *  - real tools dispatched through handle_mcp() (ParamAliasesDispatchTest.php's
 *    style) for the cases production schemas do produce: wp_delete_page's
 *    `id`/`force` pair is this repo's only built-in tool that pairs an
 *    unaliased id-group member with a privileged param on the same schema.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ForgivingParamsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']             = array();
		$GLOBALS['mcpwp_test_transients']          = array();
		$GLOBALS['mcpwp_test_current_user']        = 2;
		$GLOBALS['mcpwp_test_filters']             = array();
		$GLOBALS['mcpwp_test_dispatched_routes']   = array();
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();
	}

	// -------------------------------------------------------------------
	// Unit layer: remap_near_miss_params() via reflection.
	// -------------------------------------------------------------------

	private function remap( array $known_params, array $arguments ): array {
		$controller = new Mcpwp_REST_MCP();
		$method     = new ReflectionMethod( Mcpwp_REST_MCP::class, 'remap_near_miss_params' );
		$method->setAccessible( true );
		return $method->invoke( $controller, $known_params, $arguments );
	}

	public function test_exact_match_known_params_are_never_touched(): void {
		$out = $this->remap( array( 'id', 'force' ), array( 'id' => 5, 'force' => true ) );

		$this->assertSame( array( 'id' => 5, 'force' => true ), $out['arguments'] );
		$this->assertSame( array(), $out['fixes'] );
		$this->assertSame( array(), $out['refused'] );
	}

	public function test_camel_case_key_maps_to_its_unique_snake_case_param(): void {
		$out = $this->remap( array( 'post_id', 'prompt' ), array( 'postId' => 42, 'prompt' => 'hi' ) );

		// assertEquals (not assertSame): mapping a key does not promise to
		// preserve original key order, only key/value identity — same rule
		// ParamAliasResolutionTest.php documents for resolve_param_aliases().
		$this->assertEquals( array( 'post_id' => 42, 'prompt' => 'hi' ), $out['arguments'] );
		$this->assertSame(
			array( array( 'from' => 'postId', 'to' => 'post_id', 'reason' => 'near_miss' ) ),
			$out['fixes']
		);
		$this->assertSame( array(), $out['refused'] );
	}

	public function test_synonym_group_maps_when_schema_has_exactly_one_member(): void {
		// #911's own example: id/page_id/post_id. Only post_id is real here.
		$out = $this->remap( array( 'post_id', 'prompt' ), array( 'page_id' => 9, 'prompt' => 'hi' ) );

		$this->assertEquals( array( 'post_id' => 9, 'prompt' => 'hi' ), $out['arguments'] );
		$this->assertSame(
			array( array( 'from' => 'page_id', 'to' => 'post_id', 'reason' => 'near_miss' ) ),
			$out['fixes']
		);
	}

	public function test_tie_between_two_synonym_group_members_is_never_mapped(): void {
		// A tool whose schema carries BOTH page_id and post_id: "id" cannot
		// be resolved to either without guessing, so it is refused, not mapped.
		$out = $this->remap( array( 'page_id', 'post_id' ), array( 'id' => 3 ) );

		$this->assertSame( array( 'id' => 3 ), $out['arguments'], 'Unresolved — the original key must survive untouched.' );
		$this->assertSame( array(), $out['fixes'] );
		$this->assertArrayHasKey( 'id', $out['refused'] );
		$this->assertSame( 'ambiguous', $out['refused']['id']['reason'] );
	}

	public function test_privileged_candidate_is_refused_not_mapped(): void {
		$out = $this->remap( array( 'id', 'force' ), array( 'Force' => true ) );

		$this->assertSame( array( 'Force' => true ), $out['arguments'], 'A privileged near-miss target must never be auto-mapped.' );
		$this->assertSame( array(), $out['fixes'] );
		$this->assertArrayHasKey( 'Force', $out['refused'] );
		$this->assertSame( 'force', $out['refused']['Force']['candidate'] );
		$this->assertSame( 'privileged', $out['refused']['Force']['reason'] );
	}

	public function test_a_privileged_param_named_exactly_is_untouched_and_never_flagged(): void {
		// The gate is on the MAPPING target, not on a key that is already
		// the real, exact name — a caller sending 'force' on purpose is not
		// second-guessed.
		$out = $this->remap( array( 'id', 'force' ), array( 'id' => 1, 'force' => true ) );

		$this->assertSame( array( 'id' => 1, 'force' => true ), $out['arguments'] );
		$this->assertSame( array(), $out['refused'] );
	}

	public function test_caller_supplying_both_the_near_miss_and_the_real_key_drops_the_near_miss(): void {
		$out = $this->remap( array( 'id' ), array( 'id' => 5, 'page_id' => 5 ) );

		$this->assertSame( array( 'id' => 5 ), $out['arguments'], 'The real key wins; the redundant near-miss is dropped, not left behind.' );
		$this->assertSame(
			array( array( 'from' => 'page_id', 'to' => 'id', 'reason' => 'duplicate_dropped' ) ),
			$out['fixes']
		);
		$this->assertSame( array(), $out['refused'], 'A dropped duplicate is not a refusal — the call still succeeds on "id".' );
	}

	public function test_a_key_with_zero_candidates_is_left_for_the_fuzzy_matcher(): void {
		$out = $this->remap( array( 'title' ), array( 'totally_unrelated_key' => 'x' ) );

		$this->assertSame( array( 'totally_unrelated_key' => 'x' ), $out['arguments'] );
		$this->assertSame( array(), $out['fixes'] );
		$this->assertSame( array(), $out['refused'], 'Zero candidates is genuinely unknown, not a refused near-miss — validate_tool_arguments() falls back to its fuzzy did-you-mean for this case.' );
	}

	public function test_content_html_maps_to_content_only_via_the_explicit_synonym_table(): void {
		// content_html and content do not normalize to the same string —
		// this only works because of the explicit PARAM_SYNONYM_GROUPS entry.
		$out = $this->remap( array( 'content' ), array( 'content_html' => '<p>hi</p>' ) );

		$this->assertSame( array( 'content' => '<p>hi</p>' ), $out['arguments'] );
		$this->assertSame( 'near_miss', $out['fixes'][0]['reason'] );
	}

	// -------------------------------------------------------------------
	// Unit layer: validate_tool_arguments() via reflection — required-param
	// listing and the additionalProperties:true escape hatch, neither of
	// which any real tool exercises today.
	// -------------------------------------------------------------------

	private function validate( string $tool_name, array $arguments ): array {
		$controller = new Mcpwp_REST_MCP();
		$method     = new ReflectionMethod( Mcpwp_REST_MCP::class, 'validate_tool_arguments' );
		$method->setAccessible( true );
		return $method->invoke( $controller, $tool_name, $arguments );
	}

	public function test_missing_required_parameters_are_all_listed_not_just_the_first(): void {
		// wp_set_featured_image requires both id and media_id.
		$out = $this->validate( 'wp_set_featured_image', array() );

		$this->assertNotNull( $out['error'] );
		$this->assertStringContainsString( 'id', $out['error'] );
		$this->assertStringContainsString( 'media_id', $out['error'] );
	}

	public function test_a_tool_schema_declaring_additional_properties_true_is_left_alone(): void {
		$controller = new Mcpwp_REST_MCP();
		$cache_prop = new ReflectionProperty( Mcpwp_REST_MCP::class, 'tools_cache' );
		$cache_prop->setAccessible( true );
		$cache_prop->setValue(
			$controller,
			array(
				array(
					'name'        => 'free_form_test_tool',
					'inputSchema' => array(
						'type'                 => 'object',
						'properties'           => array( 'id' => array( 'type' => 'number' ) ),
						'additionalProperties' => true,
					),
				),
			)
		);

		$method = new ReflectionMethod( Mcpwp_REST_MCP::class, 'validate_tool_arguments' );
		$method->setAccessible( true );
		$out = $method->invoke( $controller, 'free_form_test_tool', array( 'id' => 1, 'whatever_extra_key' => 'kept as-is' ) );

		$this->assertNull( $out['error'] );
		$this->assertSame( array(), $out['fixes'] );
		$this->assertSame( array( 'id' => 1, 'whatever_extra_key' => 'kept as-is' ), $out['arguments'] );
	}

	// -------------------------------------------------------------------
	// End-to-end layer: real tools dispatched through handle_mcp().
	// wp_delete_page: id (required) + force (privileged) — no param_aliases
	// declared, so #831's per-tool alias table never intercepts these keys
	// before they reach the #911 remap.
	// -------------------------------------------------------------------

	private function call( string $tool, array $arguments ): array {
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
					'name'      => $tool,
					'arguments' => $arguments,
				),
			)
		);
		return $controller->handle_mcp( $request )->get_data();
	}

	public function test_wp_delete_page_maps_page_id_near_miss_to_id_and_dispatches(): void {
		$data = $this->call( 'wp_delete_page', array( 'page_id' => 77 ) );

		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertFalse( $data['result']['isError'], isset( $data['result']['structuredContent']['error'] ) ? $data['result']['structuredContent']['error']['message'] : '' );

		$this->assertNotEmpty( $GLOBALS['mcpwp_test_dispatched_requests'], 'The near-miss must have resolved to "id" and reached dispatch.' );
		$req = end( $GLOBALS['mcpwp_test_dispatched_requests'] );
		$this->assertSame( '/mcpwp/v1/pages/77', $req->get_route() );
		$this->assertNull( $req->get_param( 'page_id' ) );

		$this->assertSame(
			array( array( 'from' => 'page_id', 'to' => 'id', 'reason' => 'near_miss' ) ),
			$data['result']['_meta']['param_fixes'],
			'The agent must be told what was fixed, so it learns the real name.'
		);
	}

	public function test_wp_delete_page_exact_match_is_unaffected_and_carries_no_param_fixes_note(): void {
		$data = $this->call( 'wp_delete_page', array( 'id' => 5 ) );

		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertFalse( $data['result']['isError'] );
		$this->assertArrayNotHasKey( 'param_fixes', $data['result']['_meta'] ?? array() );
	}

	public function test_wp_delete_page_privileged_near_miss_force_is_refused_before_dispatch(): void {
		// "Force" is an unambiguous case-only near-miss of the real "force"
		// param — but force is destructive/privileged, so it must be refused,
		// not guessed.
		$data = $this->call( 'wp_delete_page', array( 'id' => 5, 'Force' => true ) );

		$this->assertArrayNotHasKey( 'error', $data, 'A resolved tool with a bad call is an isError result, not a protocol error.' );
		$this->assertTrue( $data['result']['isError'] );

		$message = $data['result']['structuredContent']['error']['message'];
		$this->assertStringContainsString( 'Force', $message );
		$this->assertStringContainsString( 'force', $message );
		$this->assertStringContainsString( 'privileged', $message );
		// Required params still surface, per #911's "or refuse with the fix".
		$this->assertStringContainsString( 'id', $message );

		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_requests'], 'A refused call must never reach dispatch.' );
	}

	public function test_wp_delete_page_caller_supplying_both_page_id_and_id_drops_the_alias(): void {
		$data = $this->call( 'wp_delete_page', array( 'id' => 5, 'page_id' => 5 ) );

		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertFalse( $data['result']['isError'] );

		$req = end( $GLOBALS['mcpwp_test_dispatched_requests'] );
		$this->assertSame( '/mcpwp/v1/pages/5', $req->get_route() );

		$this->assertSame(
			array( array( 'from' => 'page_id', 'to' => 'id', 'reason' => 'duplicate_dropped' ) ),
			$data['result']['_meta']['param_fixes']
		);
	}

	public function test_wp_delete_page_a_truly_unknown_key_is_refused_with_did_you_mean_and_required_list(): void {
		$data = $this->call( 'wp_delete_page', array( 'id' => 5, 'pge_id' => 5 ) );

		$this->assertTrue( $data['result']['isError'] );
		$message = $data['result']['structuredContent']['error']['message'];
		$this->assertStringContainsString( 'pge_id', $message );
		$this->assertStringContainsString( 'Required:', $message );
		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_requests'] );
	}
}
