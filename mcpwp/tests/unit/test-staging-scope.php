<?php
/**
 * Regression tests — Staging scope gate.
 *
 * P0 security fix: all four staging tools and the /staging REST route must
 * require 'admin' scope.  A 'read' or 'write'-scoped key must be blocked at
 * the auth layer before the handler is ever reached.
 *
 * Test approach: use the Mcpwp_Api_Auth trait directly (same harness pattern
 * as ApiAuthTest) to call get_required_scope_for_request() and
 * get_required_scope_for_tool_name() indirectly via verify_api_key().
 * This keeps the tests honest: they exercise the real auth code path, not
 * just the look-up table.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/**
 * Harness: exposes the auth trait's protected scope methods for direct assertion.
 * Using public wrappers avoids reflection and keeps tests readable.
 */
class Mcpwp_Staging_Scope_Auth_Harness {
	use Mcpwp_Api_Auth;

	/** Expose get_required_scope_for_request() for direct assertion in tests. */
	public function required_scope_for_request( WP_REST_Request $request ): string {
		return $this->get_required_scope_for_request( $request );
	}

	/** Expose get_required_scope_for_tool_name() for direct assertion in tests. */
	public function required_scope_for_tool( string $tool_name ): string {
		return $this->get_required_scope_for_tool_name( $tool_name );
	}
}

final class StagingScopeTest extends TestCase {

	private Mcpwp_Staging_Scope_Auth_Harness $auth;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;

		$this->auth = new Mcpwp_Staging_Scope_Auth_Harness();
	}

	// ─────────────────────────────────────────────────────────────────────
	// 1. REST route scope mapping
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * POST /mcpwp/v1/staging/push with a read-scoped key must yield 403
	 * insufficient_scope — never reach the handler.
	 */
	public function test_read_scoped_key_on_staging_push_route_gets_403(): void {
		$created = $this->auth->create_scoped_api_key( 'read only', array( 'read' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/staging/push',
			array(),
			array( 'X-API-Key' => $created['key'] )
		);

		$result = $this->auth->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * POST /mcpwp/v1/staging/push with a write-scoped key (but not admin)
	 * must also yield 403 — write is not enough for staging.
	 */
	public function test_write_scoped_key_on_staging_push_route_gets_403(): void {
		$created = $this->auth->create_scoped_api_key( 'writer', array( 'write' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/staging/push',
			array(),
			array( 'X-API-Key' => $created['key'] )
		);

		$result = $this->auth->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * An admin-scoped key passes the auth layer for /staging/push (passes scope
	 * check — the test confirms no WP_Error with code insufficient_scope is returned).
	 *
	 * Note: verify_api_key() will ultimately return true once the API user context
	 * is set, which relies on get_users() returning a user with the mcpwp_api_agent
	 * role.  The bootstrap seeds $GLOBALS['mcpwp_test_users'] with one such user so
	 * the user-context step also passes.
	 */
	public function test_admin_scoped_key_on_staging_push_route_passes_scope_check(): void {
		$created = $this->auth->create_scoped_api_key( 'admin key', array( 'admin' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/staging/push',
			array(),
			array( 'X-API-Key' => $created['key'] )
		);

		$result = $this->auth->verify_api_key( $request );

		// Must not be blocked by scope — either true (full success) or a different error
		// (e.g. set_api_user_context failure in a stripped environment).
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame(
				'insufficient_scope',
				$result->get_error_code(),
				'Admin-scoped key must not be blocked by insufficient_scope on /staging/push.'
			);
		} else {
			$this->assertTrue( $result );
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// 2. Scope look-up: /staging/* prefix always maps to 'admin'
	// ─────────────────────────────────────────────────────────────────────

	public function test_staging_route_requires_admin_scope_for_post(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/staging/push' );
		$this->assertSame( 'admin', $this->auth->required_scope_for_request( $request ) );
	}

	public function test_staging_route_requires_admin_scope_for_get(): void {
		// Even GET /staging/* must require admin (env info is sensitive).
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/staging/info' );
		$this->assertSame( 'admin', $this->auth->required_scope_for_request( $request ) );
	}

	// ─────────────────────────────────────────────────────────────────────
	// 3. MCP tool scope mapping — all four staging tools → 'admin'
	// ─────────────────────────────────────────────────────────────────────

	public function test_wp_get_staging_info_requires_admin_scope(): void {
		$this->assertSame( 'admin', $this->auth->required_scope_for_tool( 'wp_get_staging_info' ) );
	}

	public function test_wp_push_to_staging_requires_admin_scope(): void {
		$this->assertSame( 'admin', $this->auth->required_scope_for_tool( 'wp_push_to_staging' ) );
	}

	public function test_wp_push_to_live_requires_admin_scope(): void {
		$this->assertSame( 'admin', $this->auth->required_scope_for_tool( 'wp_push_to_live' ) );
	}

	public function test_wp_get_staging_status_requires_admin_scope(): void {
		$this->assertSame( 'admin', $this->auth->required_scope_for_tool( 'wp_get_staging_status' ) );
	}

	// ─────────────────────────────────────────────────────────────────────
	// 4. MCP /mcp endpoint: write-scoped key calling wp_push_to_live via
	//    JSON-RPC tools/call gets insufficient_scope, not a staging error.
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * A write-scoped key calling wp_push_to_live via the MCP endpoint must
	 * be rejected at the auth layer (insufficient_scope) — the staging module
	 * should never be invoked at all.
	 */
	public function test_write_scoped_key_calling_wp_push_to_live_via_mcp_gets_scope_error(): void {
		$created = $this->auth->create_scoped_api_key( 'writer', array( 'write' ) );

		// Build a minimal tools/call JSON-RPC payload for wp_push_to_live.
		$mcp_request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $created['key'] ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'wp_push_to_live',
					'arguments' => array(),
				),
			)
		);

		$result = $this->auth->verify_api_key( $mcp_request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );

		// The error must name the staging tool's required scope.
		$this->assertSame( 'admin', $result->get_error_data()['required_scope'] );
	}

	/**
	 * A read-scoped key calling wp_push_to_live via the MCP endpoint must
	 * also be rejected with insufficient_scope.
	 */
	public function test_read_scoped_key_calling_wp_push_to_live_via_mcp_gets_scope_error(): void {
		$created = $this->auth->create_scoped_api_key( 'reader', array( 'read' ) );

		$mcp_request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $created['key'] ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'wp_push_to_live',
					'arguments' => array(),
				),
			)
		);

		$result = $this->auth->verify_api_key( $mcp_request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}
}
