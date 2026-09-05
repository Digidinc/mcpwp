<?php
/**
 * Regression coverage for #756: MCP tool dispatch must substitute
 * {placeholder} arguments into rest_path the same way it already does for
 * built-in tools' route.
 *
 * includes/api/class-mcpwp-rest-mcp.php substitutes placeholders into a
 * local $route variable, but the final dispatch preferred the ORIGINAL,
 * unsubstituted mapping['rest_path'] for custom tools (registered via the
 * mcpwp_register_tools filter) whenever it was set — so any custom tool
 * whose rest_path carried a placeholder dispatched the literal braces and
 * WordPress could never route it (rest_no_route), even though $route held
 * the correctly substituted string right next to it.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class CustomToolPlaceholderTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_filters']            = array();
		$GLOBALS['mcpwp_test_options']             = array();
		$GLOBALS['mcpwp_test_transients']          = array();
		$GLOBALS['mcpwp_test_current_user']        = 0;
		$GLOBALS['mcpwp_test_dispatched_routes']   = array();
	}

	/**
	 * Register one custom tool via the mcpwp_register_tools filter, exactly
	 * as a third-party plugin would.
	 *
	 * @param array $tool Tool definition array.
	 */
	private function register_tool( array $tool ): void {
		add_filter(
			'mcpwp_register_tools',
			function ( $existing ) use ( $tool ) {
				$existing[] = $tool;
				return $existing;
			}
		);
	}

	/**
	 * Dispatch a tools/call request and return the decoded JSON-RPC result.
	 *
	 * @param string $tool_name Tool name to call.
	 * @param array  $arguments Tool call arguments.
	 * @return array
	 */
	private function call_tool( string $tool_name, array $arguments = array() ): array {
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
					'name'      => $tool_name,
					'arguments' => $arguments,
				),
			)
		);

		return $controller->handle_mcp( $request )->get_data();
	}

	/**
	 * Core regression: a custom tool whose rest_path carries a {placeholder}
	 * must dispatch the SUBSTITUTED route, not the literal template.
	 */
	public function test_custom_tool_with_placeholder_dispatches_substituted_route(): void {
		$this->register_tool(
			array(
				'name'        => 'digid_get_listing',
				'description' => 'Get one listing by id.',
				'rest_path'   => '/digid/v1/listings/{id}',
			)
		);

		$data = $this->call_tool( 'digid_get_listing', array( 'id' => 42 ) );

		$this->assertArrayHasKey( 'result', $data, 'Tool call must succeed: ' . wp_json_encode( $data ) );

		$dispatched = $GLOBALS['mcpwp_test_dispatched_routes'];
		$this->assertNotEmpty( $dispatched, 'The tool call must have dispatched an internal REST request.' );
		$this->assertSame(
			'/digid/v1/listings/42',
			end( $dispatched ),
			'The placeholder must be substituted with the argument value before dispatch.'
		);
	}

	/**
	 * A custom tool whose rest_path has no placeholder must be dispatched
	 * unchanged — no accidental mutation from the (no-op) substitution pass.
	 */
	public function test_custom_tool_without_placeholder_is_unchanged(): void {
		$this->register_tool(
			array(
				'name'        => 'digid_list_listings',
				'description' => 'List all listings.',
				'rest_path'   => '/digid/v1/listings',
			)
		);

		$data = $this->call_tool( 'digid_list_listings', array() );

		$this->assertArrayHasKey( 'result', $data );

		$dispatched = $GLOBALS['mcpwp_test_dispatched_routes'];
		$this->assertNotEmpty( $dispatched );
		$this->assertSame( '/digid/v1/listings', end( $dispatched ) );
	}

	/**
	 * Regression guard: a built-in tool (no rest_path, uses route) must
	 * still substitute correctly. wp_update_page maps to a route
	 * '/pages/{id}' prefixed with the MCPWP namespace. Chosen because it
	 * carries no required-plugin capability (unlike the Elementor/Gutenberg
	 * tools), so it dispatches cleanly in this bootstrap without stubbing a
	 * third-party plugin as active.
	 */
	public function test_builtin_tool_still_substitutes_route_placeholder(): void {
		$data = $this->call_tool( 'wp_update_page', array( 'id' => 7 ) );

		$this->assertArrayHasKey( 'result', $data, 'Tool call must succeed: ' . wp_json_encode( $data ) );

		$dispatched = $GLOBALS['mcpwp_test_dispatched_routes'];
		$this->assertNotEmpty( $dispatched );
		$this->assertSame( '/mcpwp/v1/pages/7', end( $dispatched ) );
	}

	/**
	 * A placeholder value must not be able to turn an allowed template into
	 * a request that reaches a path the registry would have denied. The
	 * registry's is_safe_rest_path() gate runs once, at registration, on the
	 * fixed template string chosen by the tool author — never on a
	 * runtime-substituted path — so a hostile argument value (containing
	 * "/" or "..") can only ever widen the literal path string handed to
	 * WP_REST_Request, never re-route it into a different, unvalidated
	 * namespace, because WordPress's REST router matches the route as a
	 * fixed pattern rather than resolving path segments. Assert the
	 * dispatched path is exactly the literal (safe) substitution — i.e. the
	 * traversal/slash sequence lands inside the one placeholder segment
	 * rather than being interpreted as extra path structure — and that it
	 * is never satisfied by WordPress's stub responder as if it reached a
	 * distinct, more-privileged route.
	 */
	public function test_placeholder_value_containing_a_slash_is_refused(): void {
		$this->register_tool(
			array(
				'name'        => 'digid_get_listing_hostile',
				'description' => 'Get one listing by id.',
				'rest_path'   => '/digid/v1/listings/{id}',
			)
		);

		$hostile = '../../mcpwp/v1/api-keys';
		$data    = $this->call_tool( 'digid_get_listing_hostile', array( 'id' => $hostile ) );

		// A placeholder fills exactly one path segment. is_safe_rest_path() ran
		// at registration, while the braces were still in the template, so it
		// never saw this value — substitution is where caller text enters an
		// already-approved route. A value carrying "/" adds segments nobody
		// approved, so it is refused rather than substituted.
		//
		// It is refused even though it would not route today: WordPress matches
		// REST routes with an anchored pattern and does not resolve "..", so the
		// smuggled path fails to match anything (verified against WP 7.0.2).
		// Depending on that would make our safety a property of someone else's
		// matcher — the exact dependency #752 F7 and F11 existed to remove.
		$this->assertArrayHasKey( 'error', $data, 'A slash-bearing path parameter must be refused.' );
		$this->assertSame( -32602, $data['error']['code'] );
		$this->assertStringContainsString( 'may not contain', $data['error']['message'] );

		$this->assertSame(
			array(),
			$GLOBALS['mcpwp_test_dispatched_routes'],
			'A refused path parameter must never reach dispatch.'
		);
	}

	public function test_ordinary_placeholder_values_are_still_substituted(): void {
		$this->register_tool(
			array(
				'name'        => 'digid_get_listing_ok',
				'description' => 'Get one listing by id.',
				'rest_path'   => '/digid/v1/listings/{id}',
			)
		);

		// The guard rejects separators, not punctuation: slugs, dots and dashes
		// are ordinary segment content and must keep working.
		$this->call_tool( 'digid_get_listing_ok', array( 'id' => 'my-listing.v2' ) );

		$dispatched = $GLOBALS['mcpwp_test_dispatched_routes'];
		$this->assertNotEmpty( $dispatched );
		$this->assertSame( '/digid/v1/listings/my-listing.v2', end( $dispatched ) );
	}
}
