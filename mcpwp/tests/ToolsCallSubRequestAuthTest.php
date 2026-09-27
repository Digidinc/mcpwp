<?php
/**
 * #978 — end-to-end proof, through tools/call itself, that the shared
 * forward_caller_credential() fix (and the unmetered auth-only sub-request
 * path #902 added to verify_api_key_auth_only()) works for all three
 * credential types: a plain API key, an OAuth access token, and a mumcp.com
 * broker bearer token.
 *
 * The shared PHPUnit rest_do_request() double does not run a real
 * permission_callback on the sub-request it captures, so this wires the
 * rest_dispatch_request filter to run the REAL check_permission() (exactly
 * what WordPress's own REST dispatch does before a route handler) for one
 * target route — the same technique WpBatchToolTest.php uses to route a real
 * controller through the double.
 *
 * Each test also authenticates the OUTER /mcp request itself first (calling
 * check_permission() directly, exactly as WordPress's permission_callback
 * would before invoking the handle_mcp() callback) so the "one rate-limit
 * slot per call" assertion reflects the real request lifecycle: one metered
 * charge from the outer permission_callback, zero more from the inner
 * sub-request tools/call fans out to.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-broker-auth.php';

final class ToolsCallSubRequestAuthTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']             = array();
		$GLOBALS['mcpwp_test_transients']          = array();
		$GLOBALS['mcpwp_test_current_user']        = 0;
		$GLOBALS['mcpwp_test_is_admin']            = false;
		$GLOBALS['mcpwp_test_filters']             = array();
		$GLOBALS['mcpwp_test_dispatched_routes']   = array();
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();

		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );

		$ref      = new ReflectionClass( Mcpwp_Encryption::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters']              = array();
		$GLOBALS['mcpwp_test_dispatched_routes']    = array();
		$GLOBALS['mcpwp_test_dispatched_requests']  = array();
		Mcpwp_REST_Batch::$is_batch_sub_request     = false;

		if ( class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			$instance = ( new ReflectionClass( Mcpwp_Rate_Limiter::class ) )->getProperty( 'instance' );
			$instance->setAccessible( true );
			$instance->setValue( null );
		}
	}

	/** Burst-window counter check_limit() writes on a successful, metered call. */
	private function burst_count( string $identifier ): int {
		$name = 'mcpwp_rate_' . md5( $identifier . '_burst' );
		$slot = $GLOBALS['mcpwp_test_transients'][ $name ] ?? null;
		$data = is_array( $slot ) && array_key_exists( 'value', $slot ) ? $slot['value'] : $slot;

		return is_array( $data ) && isset( $data['count'] ) ? (int) $data['count'] : 0;
	}

	/**
	 * Route a matching sub-request through a REAL controller's
	 * check_permission() — same technique WpBatchToolTest.php uses to route
	 * /batch through the real Mcpwp_REST_Batch, applied here to whichever
	 * single route a test targets.
	 */
	private function wire_real_permission_check( string $route_pattern ): void {
		add_filter(
			'rest_dispatch_request',
			function ( $result, $request, $route ) use ( $route_pattern ) {
				if ( null !== $result || ! preg_match( $route_pattern, (string) $route ) ) {
					return $result;
				}

				$probe = new Mcpwp_REST_MCP();
				$auth  = $probe->check_permission( $request );

				if ( is_wp_error( $auth ) ) {
					$data   = $auth->get_error_data();
					$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
					return new WP_REST_Response(
						array(
							'code'    => $auth->get_error_code(),
							'message' => $auth->get_error_message(),
							'data'    => $data,
						),
						$status
					);
				}

				return new WP_REST_Response( array( 'ok' => true ), 200 );
			},
			10,
			4
		);
	}

	private function tools_call_request( string $tool, array $headers ): WP_REST_Request {
		return new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			$headers,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => $tool,
					'arguments' => array(),
				),
			)
		);
	}

	/** The real permission_callback WordPress runs before handle_mcp() — metered. */
	private function authenticate_outer_request( WP_REST_Request $request ) {
		return ( new Mcpwp_REST_MCP() )->check_permission( $request );
	}

	// ── Success + exactly one rate-limit slot, per credential type ─────────

	public function test_tools_call_with_api_key_succeeds_and_meters_once(): void {
		$controller = new Mcpwp_REST_MCP();
		$created    = $controller->create_scoped_api_key( 'k', array( 'read', 'write' ), 'admin' );

		$this->wire_real_permission_check( '#^/mcpwp/v1/pages$#i' );

		$request = $this->tools_call_request( 'wp_list_pages', array( 'X-API-Key' => $created['key'] ) );
		$this->assertTrue( $this->authenticate_outer_request( $request ) );

		$data = $controller->handle_mcp( $request )->get_data();

		$this->assertArrayHasKey( 'result', $data );
		$this->assertEmpty( $data['result']['isError'] ?? false, 'wp_list_pages must succeed for an admin-role key.' );
		$this->assertSame( 1, $this->burst_count( 'key:' . $created['id'] ), 'One tools/call must cost exactly one rate-limit slot.' );
	}

	public function test_tools_call_with_oauth_access_token_succeeds_and_meters_once(): void {
		$controller = new Mcpwp_REST_MCP();
		$token      = $controller->issue_oauth_access_token( array( 'read', 'write' ), 3600, array( 'user_id' => 7 ) )['access_token'];

		$this->wire_real_permission_check( '#^/mcpwp/v1/pages$#i' );

		$request = $this->tools_call_request( 'wp_list_pages', array( 'Authorization' => 'Bearer ' . $token ) );
		$this->assertTrue( $this->authenticate_outer_request( $request ) );

		$data = $controller->handle_mcp( $request )->get_data();

		$this->assertArrayHasKey( 'result', $data );
		$this->assertEmpty( $data['result']['isError'] ?? false, 'wp_list_pages must succeed for an OAuth write token.' );
		$this->assertSame( 1, $this->burst_count( 'oauth:' . substr( hash( 'sha256', $token ), 0, 16 ) ) );
	}

	public function test_tools_call_with_broker_bearer_token_succeeds_and_meters_once(): void {
		$encrypted = Mcpwp_Encryption::get_instance()->encrypt( 'super-secret-cred' );
		update_option(
			'mcpwp_broker_enrollment',
			array(
				'issuer'                 => 'https://mumcp.com',
				'site_id'                => 'site-123',
				'audience'               => 'https://example.test',
				'introspection_endpoint' => 'https://mumcp.com/oauth/introspect',
				'site_credential'        => $encrypted,
			)
		);
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'active'  => true,
							'scope'   => array( 'write' ),
							'sub'     => 'user-42',
							'site_id' => 'site-123',
							'aud'     => 'https://example.test',
							'exp'     => time() + 120,
						)
					),
				);
			},
			10,
			3
		);

		$controller = new Mcpwp_REST_MCP();
		$token      = 'opaque-broker-token';

		$this->wire_real_permission_check( '#^/mcpwp/v1/pages$#i' );

		$request = $this->tools_call_request( 'wp_list_pages', array( 'Authorization' => 'Bearer ' . $token ) );
		$this->assertTrue( $this->authenticate_outer_request( $request ) );

		$data = $controller->handle_mcp( $request )->get_data();

		$this->assertArrayHasKey( 'result', $data );
		$this->assertEmpty( $data['result']['isError'] ?? false, 'wp_list_pages must succeed for a write-scoped broker token — this is the exact call #978 reported as failing.' );
		$this->assertSame( 1, $this->burst_count( 'broker:' . substr( hash( 'sha256', $token ), 0, 16 ) ) );

		// #978 review NOTE: the DoS pre-throttle on the outbound introspection
		// call must not be charged twice (outer + this inner dispatch) for one
		// client call. get_client_ip() has no $_SERVER['REMOTE_ADDR'] in this
		// CLI test process, so its documented fallback identifier is 'unknown'.
		$this->assertSame(
			1,
			$this->burst_count( 'broker-attempt:unknown' ),
			'The broker-attempt DoS throttle must be charged once per client call, not once per internal dispatch.'
		);
	}

	// ── Scope enforcement still applies to the call as a whole ─────────────
	//
	// Category-restriction enforcement on the unmetered sub-request fast path
	// itself (verify_api_key_auth_only()) — the specific mechanism this fix
	// and #902 share — is proven directly against the auth trait in
	// ResourcesReadSubRequestAuthTest::test_category_restricted_key_is_still_denied_as_sub_request().
	// This test proves the tools/call integration does not accidentally widen
	// a read-only key into a write tool because of the credential-forwarding
	// change: wp_create_page requires 'write' scope (the write-verb prefix
	// rule in get_required_scope_for_tool_name()), no plugin dependency, and
	// no admin_tools listing.

	public function test_tools_call_still_denies_a_write_tool_for_a_read_only_key(): void {
		$controller = new Mcpwp_REST_MCP();
		$created    = $controller->create_scoped_api_key( 'reader-key', array( 'read' ), 'admin' );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $created['key'] ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'wp_create_page',
					'arguments' => array( 'title' => 'Should be refused' ),
				),
			)
		);

		$auth = $this->authenticate_outer_request( $request );

		$this->assertInstanceOf( WP_Error::class, $auth, 'A read-only key must not be able to call a write tool through tools/call.' );
		$this->assertSame( 'insufficient_scope', $auth->get_error_code() );
	}
}
