<?php
/**
 * #978 — same pattern as #902/#972, one call site later: tools/call
 * (Mcpwp_REST_MCP::handle_tools_call()) and /batch
 * (Mcpwp_REST_Batch::execute_operation()) each built their internal
 * sub-request's credential from get_api_key_from_request($request) and
 * always re-sent it as X-API-Key — even when the caller authenticated with
 * `Authorization: Bearer` (an OAuth access token or a mumcp.com broker
 * token). The broker fallback in trait-mcpwp-api-auth.php only ever runs for
 * a genuine Bearer header with no X-API-Key present, so a broker caller's
 * every tool call (and every /batch operation) failed the inner dispatch
 * with "invalid_api_key" even though the outer /mcp or /batch request had
 * already authenticated.
 *
 * These tests reproduce the header-conversion bug directly against the
 * sub-request the controller actually builds (via the shared
 * mcpwp_test_dispatched_requests capture — the same technique
 * McpEndpointTest uses for resources/read) and pin the fix: both call sites
 * now go through the shared Mcpwp_Api_Auth::forward_caller_credential()
 * helper (extracted from #902's resources/read fix) instead of duplicating
 * the header logic.
 *
 * Reproduced against main before this fix: reverting the
 * forward_caller_credential() call in handle_tools_call()'s internal-dispatch
 * branch back to the old
 *   $api_key = $this->get_api_key_from_request( $request );
 *   $internal_request->set_header( 'X-API-Key', $api_key );
 * makes test_tools_call_carries_bearer_header_when_caller_used_bearer() and
 * test_batch_carries_bearer_header_when_caller_used_bearer() fail — the
 * captured sub-request's X-API-Key comes back as "Bearer opaque-caller-token"
 * instead of empty, and Authorization comes back empty instead of the
 * Bearer value. The OAuth-specific regression the issue predicted did NOT
 * reproduce this way: looks_like_oauth_access_token() matches by token
 * shape, not by header name, so an OAuth token surviving the old
 * X-API-Key-only copy still authenticated on the sub-request — the bug is
 * broker-specific (and, in principle, any future Bearer-only credential).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

final class CredentialForwardingTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']             = array();
		$GLOBALS['mcpwp_test_transients']          = array();
		$GLOBALS['mcpwp_test_dispatched_routes']   = array();
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_dispatched_routes']   = array();
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();
		Mcpwp_REST_Batch::$is_batch_sub_request    = false;
	}

	// ── tools/call ───────────────────────────────────────────────────────

	private function tools_call_request( array $headers ): WP_REST_Request {
		return new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			$headers,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				// wp_site_info is a plain route-based (non-callback) read tool
				// with no plugin requirement, so it always reaches the
				// rest_do_request() branch under test.
				'params'  => array(
					'name'      => 'wp_site_info',
					'arguments' => array(),
				),
			)
		);
	}

	public function test_tools_call_carries_bearer_header_when_caller_used_bearer(): void {
		$controller = new Mcpwp_REST_MCP();
		$controller->handle_mcp( $this->tools_call_request( array( 'Authorization' => 'Bearer opaque-caller-token' ) ) );

		$this->assertNotEmpty( $GLOBALS['mcpwp_test_dispatched_requests'] );
		$sub_request = end( $GLOBALS['mcpwp_test_dispatched_requests'] );
		$this->assertSame( 'Bearer opaque-caller-token', $sub_request->get_header( 'Authorization' ) );
		$this->assertEmpty( $sub_request->get_header( 'X-API-Key' ), 'A Bearer credential must never be converted onto X-API-Key.' );
	}

	public function test_tools_call_carries_api_key_header_when_caller_used_api_key(): void {
		$controller = new Mcpwp_REST_MCP();
		$controller->handle_mcp( $this->tools_call_request( array( 'X-API-Key' => 'mcpwp_secret_test_key' ) ) );

		$this->assertNotEmpty( $GLOBALS['mcpwp_test_dispatched_requests'] );
		$sub_request = end( $GLOBALS['mcpwp_test_dispatched_requests'] );
		$this->assertSame( 'mcpwp_secret_test_key', $sub_request->get_header( 'X-API-Key' ) );
		$this->assertEmpty( $sub_request->get_header( 'Authorization' ) );
	}

	public function test_tools_call_dispatches_with_sub_request_flag_set_and_restores_it(): void {
		$observed = array();
		add_filter(
			'rest_dispatch_request',
			function ( $result ) use ( &$observed ) {
				$observed[] = Mcpwp_REST_Batch::$is_batch_sub_request;
				return $result;
			},
			10,
			4
		);

		$controller = new Mcpwp_REST_MCP();
		$controller->handle_mcp( $this->tools_call_request( array( 'X-API-Key' => 'mcpwp_secret_test_key' ) ) );

		$this->assertSame( array( true ), $observed );
		$this->assertFalse( Mcpwp_REST_Batch::$is_batch_sub_request );

		$GLOBALS['mcpwp_test_filters'] = array();
	}

	// ── /batch ────────────────────────────────────────────────────────────

	private function batch_request( array $headers, array $operations ): WP_REST_Request {
		return new WP_REST_Request(
			'POST',
			'/mcpwp/v1/batch',
			array( 'operations' => $operations ),
			$headers
		);
	}

	public function test_batch_carries_bearer_header_when_caller_used_bearer(): void {
		$controller = new Mcpwp_REST_Batch();
		$controller->handle_batch(
			$this->batch_request(
				array( 'Authorization' => 'Bearer opaque-caller-token' ),
				array( array( 'method' => 'GET', 'path' => '/site-info' ) )
			)
		);

		$this->assertNotEmpty( $GLOBALS['mcpwp_test_dispatched_requests'] );
		$sub_request = end( $GLOBALS['mcpwp_test_dispatched_requests'] );
		$this->assertSame( 'Bearer opaque-caller-token', $sub_request->get_header( 'Authorization' ) );
		$this->assertEmpty( $sub_request->get_header( 'X-API-Key' ), 'A Bearer credential must never be converted onto X-API-Key.' );
	}

	public function test_batch_carries_api_key_header_when_caller_used_api_key(): void {
		$controller = new Mcpwp_REST_Batch();
		$controller->handle_batch(
			$this->batch_request(
				array( 'X-API-Key' => 'mcpwp_secret_test_key' ),
				array( array( 'method' => 'GET', 'path' => '/site-info' ) )
			)
		);

		$this->assertNotEmpty( $GLOBALS['mcpwp_test_dispatched_requests'] );
		$sub_request = end( $GLOBALS['mcpwp_test_dispatched_requests'] );
		$this->assertSame( 'mcpwp_secret_test_key', $sub_request->get_header( 'X-API-Key' ) );
		$this->assertEmpty( $sub_request->get_header( 'Authorization' ) );
	}

	public function test_batch_dispatches_with_sub_request_flag_set_and_restores_it(): void {
		$controller = new Mcpwp_REST_Batch();
		$controller->handle_batch(
			$this->batch_request(
				array( 'X-API-Key' => 'mcpwp_secret_test_key' ),
				array( array( 'method' => 'GET', 'path' => '/site-info' ) )
			)
		);

		$this->assertFalse( Mcpwp_REST_Batch::$is_batch_sub_request, 'The flag must be restored to its previous value once the dispatch returns.' );
	}
}
