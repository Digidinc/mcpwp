<?php
/**
 * Regression guard — #874.
 *
 * MCPWP computes the required authorization scope for a request TWICE, from two
 * independent tables that must agree:
 *
 *   - get_required_scope_for_request()  — keyed by REST route + method. This is
 *     the table that actually runs when a route-mapped MCP tool dispatches via
 *     rest_do_request() (see Mcpwp_REST_MCP::handle_tools_call()).
 *   - get_required_scope_for_tool_name() — keyed by MCP tool name. This is the
 *     table that gates the OUTER /mcp request before dispatch, and — for the
 *     small set of 'callback'-dispatched tools that skip rest_do_request()
 *     entirely — the ONLY table that ever runs.
 *
 * A tool-name-table entry that classifies a tool BELOW what its real route
 * requires is a bug: latent today for a route-mapped tool (the route table
 * still catches it on re-dispatch), but live the moment that tool is ever
 * dispatched as a 'callback' instead, and a source of drift regardless. This
 * test iterates every route-mapped tool in the free and pro registries and
 * fails if the tool-name table ever again disagrees with the route it points
 * to. See trait-mcpwp-api-auth.php get_required_scope_for_request() and
 * get_required_scope_for_tool_name() for the full write-up (#874).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/** Harness exposing the two protected scope resolvers under test. */
class Mcpwp_Scope_Parity_Auth_Harness {
	use Mcpwp_Api_Auth;

	public function route_scope( string $method, string $route ): string {
		return $this->get_required_scope_for_request( new WP_REST_Request( $method, $route ) );
	}

	public function tool_scope( string $tool_name ): string {
		return $this->get_required_scope_for_tool_name( $tool_name );
	}
}

final class ToolNameRouteScopeParityTest extends TestCase {

	private const RANK = array(
		'read'  => 1,
		'write' => 2,
		'admin' => 3,
	);

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	/**
	 * Every route-mapped tool name from both first-party registries, resolved to
	 * its dispatch (method, route). Tools registered with a 'callback' instead of
	 * a 'route' (none exist in the free/pro registries today — see the class
	 * docblock) are intentionally excluded: they have no REST route to compare
	 * against, and get_required_scope_for_tool_name() is their only gate.
	 *
	 * @return array<string, array{0:string, 1:string}> tool_name => [method, route]
	 */
	private function route_mapped_tools(): array {
		$maps = array_merge(
			( new Mcpwp_MCP_Free_Tools() )->get_tool_map(),
			( new Mcpwp_MCP_Pro_Tools() )->get_tool_map()
		);

		$out = array();
		foreach ( $maps as $name => $mapping ) {
			if ( isset( $mapping['callback'] ) ) {
				continue;
			}
			if ( ! isset( $mapping['route'], $mapping['method'] ) ) {
				continue;
			}
			$route = isset( $mapping['rest_path'] ) ? $mapping['rest_path'] : '/mcpwp/v1' . $mapping['route'];
			// Any {placeholder} is a path segment for route-matching purposes —
			// the exact value never affects which prefix/pattern matches.
			$route = preg_replace( '/\{[^}]+\}/', '1', $route );
			$out[ $name ] = array( $mapping['method'], $route );
		}

		return $out;
	}

	public function test_no_first_party_tool_uses_callback_dispatch(): void {
		// Documents and locks the precondition the rest of this file's docblock
		// relies on: today, EVERY first-party tool is route-mapped, so
		// get_required_scope_for_tool_name() is never anyone's sole gate except
		// for third-party tools registered via Mcpwp_Custom_Tool_Registry. If this
		// ever changes, get_required_scope_for_tool_name() for the newly-callback
		// tool becomes a LIVE gate, not a latent one, and deserves fresh scrutiny.
		$maps = array_merge(
			( new Mcpwp_MCP_Free_Tools() )->get_tool_map(),
			( new Mcpwp_MCP_Pro_Tools() )->get_tool_map()
		);

		$callback_tools = array();
		foreach ( $maps as $name => $mapping ) {
			if ( isset( $mapping['callback'] ) ) {
				$callback_tools[] = $name;
			}
		}

		$this->assertSame(
			array(),
			$callback_tools,
			'A first-party tool now dispatches via callback (skips the rest_do_request() re-check) — ' .
			'audit its required scope directly against get_required_scope_for_tool_name() instead of relying on the route table.'
		);
	}

	public function test_tool_name_scope_never_under_classifies_its_real_route(): void {
		$auth  = new Mcpwp_Scope_Parity_Auth_Harness();
		$tools = $this->route_mapped_tools();
		$this->assertGreaterThan( 250, count( $tools ), 'Sanity: the registries should yield hundreds of route-mapped tools.' );

		$failures = array();
		foreach ( $tools as $name => list( $method, $route ) ) {
			$route_scope = $auth->route_scope( $method, $route );
			$tool_scope  = $auth->tool_scope( $name );

			if ( self::RANK[ $tool_scope ] < self::RANK[ $route_scope ] ) {
				$failures[] = sprintf( '%s (%s %s): route requires "%s" but tool-name table only requires "%s"', $name, $method, $route, $route_scope, $tool_scope );
			}
		}

		$this->assertSame( array(), $failures, "Tool-name scope table disagrees with the route it dispatches to:\n" . implode( "\n", $failures ) );
	}
}
