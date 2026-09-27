<?php
/**
 * P0 regression — adversarial review of #907 round 1, BLOCK-1.
 *
 * Mcpwp_Api_Auth::get_required_scope_for_mcp_request() infers the SCOPE TIER
 * (read/write/admin) a /mcp request needs from the raw JSON-RPC payload,
 * BEFORE Mcpwp_REST_MCP::handle_tools_call() ever runs — it is the gate that
 * produces a genuine 403 for an under-scoped key, independent of and prior to
 * every category/role check inside handle_tools_call() itself.
 *
 * Before this fix it read `params.name`/`params.arguments` directly, so a
 * message naming the new `mcpwp_call` meta tool (#907) was classified from a
 * table that has never heard of "mcpwp_call" — defaulting to 'read' —
 * regardless of how privileged the tool actually named in
 * `params.arguments.name` was. Confirmed live over real HTTP: a read-scoped
 * key wrapping `wp_list_mcp_events` (admin-only) and a write-scoped key
 * wrapping `wp_delete_all_drafts`/`wp_batch` (admin-only) both got a 200 with
 * real data, where the direct call correctly got a 403.
 *
 * The fix: Mcpwp_Api_Auth::effective_mcp_tool_call() is the ONE place that
 * unwraps mcpwp_call for scope/rate-weight/diagnostic classification, called
 * by get_required_scope_for_mcp_request() (here), and by
 * Mcpwp_REST_MCP::is_diagnostic_operation()/::rate_limit_method() (own
 * coverage in McpToolProfileTest.php's rate/diagnostic-adjacent cases). This
 * file proves the SCOPE gate specifically: wrapped and direct requests must
 * be classified identically, for every tool this server knows, and a
 * wrapped call that should be refused gets refused with the SAME 403
 * envelope (WP_Error, status 403, `insufficient_scope`) a direct call gets —
 * never let through to reach handle_tools_call() at all.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/**
 * Harness exposing the trait's scope resolver directly, so a message's
 * required scope can be computed without a full authenticated round trip —
 * used by the enumeration/parity test, which needs to check every known
 * tool name cheaply.
 */
class Mcpwp_Mcp_Scope_Bypass_Harness {
	use Mcpwp_Api_Auth;

	/**
	 * @param array $body One JSON-RPC message, or a batch array of them.
	 */
	public function scope_for( array $body ): string {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array(), $body );
		return $this->get_required_scope_for_request( $request );
	}
}

final class McpCallScopeBypassTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_filters']      = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters']    = array();
	}

	private function direct_message( string $tool, array $args = array() ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => array( 'name' => $tool, 'arguments' => $args ),
		);
	}

	private function wrapped_message( string $tool, array $args = array() ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => 'mcpwp_call',
				'arguments' => array( 'name' => $tool, 'arguments' => $args ),
			),
		);
	}

	// ── The live bugs, reproduced at the scope-classification level ────────

	public function test_wrapped_admin_tool_requires_admin_scope_same_as_direct(): void {
		$auth = new Mcpwp_Mcp_Scope_Bypass_Harness();

		foreach ( array( 'wp_list_mcp_events', 'wp_delete_all_drafts', 'wp_batch' ) as $tool ) {
			$direct  = $auth->scope_for( $this->direct_message( $tool ) );
			$wrapped = $auth->scope_for( $this->wrapped_message( $tool ) );
			$this->assertSame( 'admin', $direct, "Precondition: \"$tool\" must require admin scope directly." );
			$this->assertSame( $direct, $wrapped, "mcpwp_call-wrapped \"$tool\" must require the SAME scope as calling it directly." );
		}
	}

	public function test_custom_filter_admin_tool_requires_admin_scope_wrapped(): void {
		add_filter(
			'mcpwp_required_scope_for_tool',
			static function ( $scope, $tool_name ) {
				return 'zzz_custom_admin_tool' === $tool_name ? 'admin' : $scope;
			},
			10,
			2
		);

		$auth    = new Mcpwp_Mcp_Scope_Bypass_Harness();
		$direct  = $auth->scope_for( $this->direct_message( 'zzz_custom_admin_tool' ) );
		$wrapped = $auth->scope_for( $this->wrapped_message( 'zzz_custom_admin_tool' ) );

		$this->assertSame( 'admin', $direct );
		$this->assertSame( 'admin', $wrapped, 'A registered tool raising its own scope via the mcpwp_required_scope_for_tool filter must still be honoured when reached through mcpwp_call.' );
	}

	public function test_wide_find_replace_is_admin_wrapped_same_as_direct(): void {
		if ( ! class_exists( 'Mcpwp_REST_Elementor' ) ) {
			$this->markTestSkipped( 'Mcpwp_REST_Elementor not loaded by the bare bootstrap.' );
		}

		// No id/ids (named target) + scope=site (or post_type) = wide, per
		// Mcpwp_REST_Elementor::find_replace_is_wide().
		$wide_args = array( 'scope' => 'site', 'search' => 'x', 'replace' => 'y' );

		$auth    = new Mcpwp_Mcp_Scope_Bypass_Harness();
		$direct  = $auth->scope_for( $this->direct_message( 'wp_bulk_find_replace', $wide_args ) );
		$wrapped = $auth->scope_for( $this->wrapped_message( 'wp_bulk_find_replace', $wide_args ) );

		$this->assertSame( 'admin', $direct, 'Precondition: a wide find/replace must be admin scope directly.' );
		$this->assertSame( $direct, $wrapped, 'A wide find/replace wrapped in mcpwp_call must be classified from the EFFECTIVE (inner) arguments, not the outer wrapper\'s.' );
	}

	public function test_batch_array_with_wrapped_admin_call_requires_admin_scope(): void {
		$auth  = new Mcpwp_Mcp_Scope_Bypass_Harness();
		$batch = array(
			$this->wrapped_message( 'wp_list_pages' ),
			$this->wrapped_message( 'wp_list_mcp_events' ),
		);

		$this->assertSame( 'admin', $auth->scope_for( $batch ), 'A JSON-RPC batch carrying one wrapped admin-only call must raise the WHOLE batch to admin scope.' );
	}

	/**
	 * Enumerates every tool this server knows (free + pro registries) and
	 * asserts the scope classification never disagrees between a direct
	 * tools/call and the same tool reached through mcpwp_call — the general
	 * form of the two specific live bugs above, so no OTHER tool can quietly
	 * be under-classified through the wrapper either.
	 */
	public function test_wrapped_scope_matches_direct_for_every_known_tool(): void {
		$auth  = new Mcpwp_Mcp_Scope_Bypass_Harness();
		$tools = array_keys(
			array_merge(
				( new Mcpwp_MCP_Free_Tools() )->get_tool_map(),
				( new Mcpwp_MCP_Pro_Tools() )->get_tool_map()
			)
		);

		$this->assertGreaterThan( 250, count( $tools ), 'Sanity: the registries should yield hundreds of tools.' );

		$mismatches = array();
		foreach ( $tools as $tool ) {
			$direct  = $auth->scope_for( $this->direct_message( $tool ) );
			$wrapped = $auth->scope_for( $this->wrapped_message( $tool ) );
			if ( $direct !== $wrapped ) {
				$mismatches[] = "$tool: direct=$direct wrapped=$wrapped";
			}
		}

		$this->assertSame( array(), $mismatches, "Required scope must never differ between a direct call and the same tool wrapped in mcpwp_call:\n" . implode( "\n", $mismatches ) );
	}

	// ── End-to-end: the SAME 403 envelope as a direct call (NIT-4) ─────────

	public function test_read_key_wrapped_admin_tool_refused_with_same_envelope_as_direct(): void {
		$controller = new Mcpwp_REST_MCP();
		$read_key   = $controller->create_scoped_api_key( 'reader', array( 'read' ), 'admin' );

		$direct_request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $read_key['key'] ), $this->direct_message( 'wp_list_mcp_events' ) );
		Mcpwp_REST_MCP::reset_verdict_memo();
		$direct_verdict = $controller->verify_api_key( $direct_request );

		$controller2     = new Mcpwp_REST_MCP();
		$wrapped_request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $read_key['key'] ), $this->wrapped_message( 'wp_list_mcp_events' ) );
		Mcpwp_REST_MCP::reset_verdict_memo();
		$wrapped_verdict = $controller2->verify_api_key( $wrapped_request );

		$this->assertInstanceOf( WP_Error::class, $direct_verdict, 'Precondition: a read key must be refused calling an admin tool directly.' );
		$this->assertInstanceOf( WP_Error::class, $wrapped_verdict, 'BLOCK-1: a read key wrapping an admin tool in mcpwp_call must be refused at authentication, exactly like a direct call — never reach dispatch.' );
		$this->assertSame( 'insufficient_scope', $direct_verdict->get_error_code() );
		$this->assertSame( $direct_verdict->get_error_code(), $wrapped_verdict->get_error_code() );
		$this->assertSame( $direct_verdict->get_error_data()['status'], $wrapped_verdict->get_error_data()['status'], 'NIT-4: same HTTP status as the direct refusal.' );
		$this->assertSame( 403, $wrapped_verdict->get_error_data()['status'] );
		$this->assertSame( $direct_verdict->get_error_data()['required_scope'], $wrapped_verdict->get_error_data()['required_scope'] );
	}

	public function test_write_key_wrapped_admin_tool_refused_with_same_envelope_as_direct(): void {
		$controller = new Mcpwp_REST_MCP();
		$write_key  = $controller->create_scoped_api_key( 'writer', array( 'read', 'write' ), 'admin' );

		foreach ( array( 'wp_delete_all_drafts', 'wp_batch' ) as $tool ) {
			$direct_request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $write_key['key'] ), $this->direct_message( $tool ) );
			Mcpwp_REST_MCP::reset_verdict_memo();
			$direct_verdict = $controller->verify_api_key( $direct_request );

			$controller2     = new Mcpwp_REST_MCP();
			$wrapped_request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $write_key['key'] ), $this->wrapped_message( $tool ) );
			Mcpwp_REST_MCP::reset_verdict_memo();
			$wrapped_verdict = $controller2->verify_api_key( $wrapped_request );

			$this->assertInstanceOf( WP_Error::class, $direct_verdict, "Precondition: a write key must be refused calling \"$tool\" directly." );
			$this->assertInstanceOf( WP_Error::class, $wrapped_verdict, "A write key wrapping \"$tool\" in mcpwp_call must be refused at authentication too — this was the live 200-with-real-deletion bug." );
			$this->assertSame( 403, $wrapped_verdict->get_error_data()['status'] );
		}
	}

	// ── NIT-1: the same shared helper fixes rate-limit/diagnostic pricing ──

	public function test_rate_limit_method_prices_a_wrapped_get_tool_as_get(): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array(), $this->wrapped_message( 'wp_site_info' ) );

		$method = new ReflectionMethod( Mcpwp_REST_MCP::class, 'rate_limit_method' );
		$method->setAccessible( true );

		$this->assertSame( 'GET', $method->invoke( $controller, $request ), 'A GET-mapped tool wrapped in mcpwp_call must price at the lighter GET ceiling, not the default write bucket.' );
	}

	public function test_is_diagnostic_operation_recognizes_a_wrapped_diagnostic_tool(): void {
		$controller = new Mcpwp_REST_MCP();
		$operation  = $this->wrapped_message( 'wp_site_info' );

		$method = new ReflectionMethod( Mcpwp_REST_MCP::class, 'is_diagnostic_operation' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $controller, $operation ), 'wp_site_info wrapped in mcpwp_call must keep its diagnostic-rate-limit exemption.' );
	}

	public function test_admin_key_wrapped_admin_tool_still_succeeds_past_the_scope_gate(): void {
		// The fix must not over-block: an admin key wrapping an admin tool
		// still passes the scope gate (dispatch itself is covered elsewhere).
		$controller = new Mcpwp_REST_MCP();
		$admin_key  = $controller->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );

		$wrapped_request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $admin_key['key'] ), $this->wrapped_message( 'wp_list_mcp_events' ) );
		Mcpwp_REST_MCP::reset_verdict_memo();
		$verdict = $controller->verify_api_key( $wrapped_request );

		$this->assertTrue( $verdict, 'An admin key wrapping an admin tool must still pass the scope gate.' );
	}
}
