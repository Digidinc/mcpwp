<?php
/**
 * #981 — WebMCP in wp-admin.
 *
 * The browser's built-in agent calls MCPWP tools through
 * POST /mcpwp/v1/webmcp/call with the signed-in user's cookie + wp_rest
 * nonce. Pinned here:
 *
 *  - the endpoint's permission order (off 404, machine credential 403,
 *    cross-origin 403, no cookie 401, bad/missing nonce 403, subscriber 403);
 *  - the allowlist and per-tool capability, draft-only creation, and that a
 *    call is dispatched through the /mcp tools/call chokepoint under the
 *    WebMCP principal (and only while the endpoint holds the lift);
 *  - the auth chokepoint under the lift: the real user (not mcpwp_bot), no
 *    admin scope (so publishing is refused even if a status slipped by),
 *    rate limiting, allowlist enforced again, action log source=webmcp;
 *  - wp_propose_content_change: an approval, no write, WebMCP-only;
 *  - settings validation, the origin-trial <meta> only in wp-admin and only
 *    when set, and single-sourced tool definitions for the browser.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-settings.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-settings.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

if ( ! defined( 'MCPWP_PLUGIN_URL' ) ) {
	define( 'MCPWP_PLUGIN_URL', 'https://example.com/wp-content/plugins/mcpwp/' );
}

/** Auth-trait harness: a controller whose permission callback is verify_api_key(). */
class Mcpwp_WebMcp_Auth_Harness extends Mcpwp_REST_API {
	public function register_routes() {}

	public function record() {
		return $this->get_current_api_key_record();
	}
}

final class WebMcpTest extends TestCase {

	private const EDITOR     = 5;
	private const SUBSCRIBER = 6;
	private const AUTHOR     = 7;
	private const POST_ID    = 9811;

	private const EDITOR_CAPS = array(
		'edit_posts'        => true,
		'edit_pages'        => true,
		'edit_others_posts' => true,
		'edit_post'         => true,
		'unfiltered_html'   => false,
	);

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_filters']            = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_user_caps']          = array(
			self::EDITOR     => self::EDITOR_CAPS,
			self::SUBSCRIBER => array( 'read' => true ),
			self::AUTHOR     => array( 'edit_posts' => true, 'edit_pages' => false, 'edit_others_posts' => false ),
		);
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['mcpwp_test_dispatched_routes']  = array();
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();
		$GLOBALS['mcpwp_test_enqueued_scripts']   = array();
		$GLOBALS['mcpwp_test_inline_scripts']     = array();
		$GLOBALS['mcpwp_test_wpdb_rows']          = array();
		$GLOBALS['_mcpwp_test_posts']             = array(
			self::POST_ID => new WP_Post(
				array(
					'ID'           => self::POST_ID,
					'post_type'    => 'post',
					'post_title'   => 'About us',
					'post_status'  => 'publish',
					'post_content' => 'Original body.',
				)
			),
		);
		unset( $GLOBALS['wp_rest_auth_cookie'], $GLOBALS['mcpwp_test_rest_response_override'] );
		$_GET = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
		Mcpwp_WebMCP::end_dispatch( 0 );
		Mcpwp_Integration::clear_cache();
		// What Mcpwp_Loader::register_builtin_integrations() does in production.
		add_filter(
			'mcpwp_integrations',
			static function ( $integrations ) {
				$integrations[] = new Mcpwp_WebMCP_Integration();
				return $integrations;
			}
		);
		$this->reset_rate_limiter();
	}

	protected function tearDown(): void {
		Mcpwp_WebMCP::end_dispatch( 0 );
		Mcpwp_Integration::clear_cache();
		unset( $GLOBALS['wp_rest_auth_cookie'], $GLOBALS['mcpwp_test_rest_response_override'] );
		$GLOBALS['mcpwp_test_options']   = array();
		$GLOBALS['mcpwp_test_filters']   = array();
		$GLOBALS['mcpwp_test_user_caps'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_is_admin']  = false;
		$_GET = array();
		$this->reset_rate_limiter();
	}

	private function reset_rate_limiter(): void {
		$ref  = new ReflectionClass( Mcpwp_Rate_Limiter::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	private function enable( array $extra = array() ): void {
		update_option( 'mcpwp_settings', array_merge( array( 'webmcp_admin_enabled' => true ), $extra ) );
	}

	/** A request as a signed-in browser tab on this site would send it. */
	private function browser_request( array $body, array $headers = array() ): WP_REST_Request {
		$headers = array_merge(
			array(
				'Origin'     => 'https://example.com',
				'X-WP-Nonce' => 'test-nonce',
			),
			$headers
		);
		return new WP_REST_Request( 'POST', '/mcpwp/v1/webmcp/call', $body, $headers, $body );
	}

	private function sign_in( int $user_id ): void {
		$GLOBALS['wp_rest_auth_cookie']     = true;
		$GLOBALS['mcpwp_test_current_user'] = $user_id;
	}

	private function error_status( $result ): int {
		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data();
		return (int) $data['status'];
	}

	private function mcp_body( string $tool, array $args = array() ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => array( 'name' => $tool, 'arguments' => $args ),
		);
	}

	// ── Permission callback ───────────────────────────────────────────────

	public function test_switched_off_the_route_answers_404(): void {
		$this->sign_in( self::EDITOR );
		$result = ( new Mcpwp_REST_WebMCP() )->check_webmcp_permission( $this->browser_request( array( 'name' => 'wp_site_info' ) ) );
		$this->assertSame( 404, $this->error_status( $result ) );
		$this->assertSame( 'rest_no_route', $result->get_error_code() );
		$this->assertFalse( Mcpwp_WebMCP::is_enabled(), 'Default is off.' );
	}

	public function test_signed_in_editor_passes(): void {
		$this->enable();
		$this->sign_in( self::EDITOR );
		$this->assertTrue( ( new Mcpwp_REST_WebMCP() )->check_webmcp_permission( $this->browser_request( array( 'name' => 'wp_site_info' ) ) ) );
	}

	public function test_no_login_cookie_is_401(): void {
		$this->enable();
		$GLOBALS['mcpwp_test_current_user'] = self::EDITOR; // e.g. an Application Password login.
		$result = ( new Mcpwp_REST_WebMCP() )->check_webmcp_permission( $this->browser_request( array( 'name' => 'wp_site_info' ) ) );
		$this->assertSame( 401, $this->error_status( $result ) );

		// Core leaves the failure REASON (a non-empty string) in the global
		// when the cookie is missing or bad — found on the rig, where a
		// truthiness check read 'malformed' as "signed in".
		foreach ( array( 'malformed', 'expired', 'bad_hash', 'bad_username', 1, '1' ) as $reason ) {
			$GLOBALS['wp_rest_auth_cookie'] = $reason;
			$result = ( new Mcpwp_REST_WebMCP() )->check_webmcp_permission( $this->browser_request( array( 'name' => 'wp_site_info' ) ) );
			$this->assertSame( 401, $this->error_status( $result ), (string) $reason );
		}
	}

	public function test_missing_or_bad_nonce_is_403(): void {
		$this->enable();
		$this->sign_in( self::EDITOR );
		$controller = new Mcpwp_REST_WebMCP();

		$missing = $this->browser_request( array( 'name' => 'wp_site_info' ), array( 'X-WP-Nonce' => '' ) );
		$this->assertSame( 403, $this->error_status( $controller->check_webmcp_permission( $missing ) ) );

		$bad = $this->browser_request( array( 'name' => 'wp_site_info' ), array( 'X-WP-Nonce' => 'forged' ) );
		$result = $controller->check_webmcp_permission( $bad );
		$this->assertSame( 403, $this->error_status( $result ) );
		$this->assertSame( 'rest_cookie_invalid_nonce', $result->get_error_code() );
	}

	public function test_an_api_key_or_bearer_token_is_refused_even_with_a_valid_session(): void {
		$this->enable();
		$this->sign_in( self::EDITOR );
		$controller = new Mcpwp_REST_WebMCP();

		foreach ( array(
			array( 'X-API-Key' => 'mcpwp_abc' ),
			array( 'Authorization' => 'Bearer mcpwp_at_xyz' ),
			array( 'authorization' => 'bearer something' ),
		) as $headers ) {
			$result = $controller->check_webmcp_permission( $this->browser_request( array( 'name' => 'wp_site_info' ), $headers ) );
			$this->assertSame( 403, $this->error_status( $result ), wp_json_encode( $headers ) );
			$this->assertSame( 'webmcp_credential_refused', $result->get_error_code() );
		}

		$_GET['api_key'] = 'mcpwp_abc';
		$result          = $controller->check_webmcp_permission( $this->browser_request( array( 'name' => 'wp_site_info' ) ) );
		$this->assertSame( 'webmcp_credential_refused', $result->get_error_code() );
		$_GET = array();

		$result = $controller->check_webmcp_permission( $this->browser_request( array( 'name' => 'wp_site_info', 'api_key' => 'mcpwp_abc' ) ) );
		$this->assertSame( 'webmcp_credential_refused', $result->get_error_code() );
	}

	public function test_http_basic_from_a_password_protected_staging_site_is_not_a_refusal(): void {
		$this->enable();
		$this->sign_in( self::EDITOR );
		$result = ( new Mcpwp_REST_WebMCP() )->check_webmcp_permission(
			$this->browser_request( array( 'name' => 'wp_site_info' ), array( 'Authorization' => 'Basic c3RhZ2luZzpzdGFnaW5n' ) )
		);
		$this->assertTrue( $result );
	}

	public function test_cross_origin_is_403(): void {
		$this->enable();
		$this->sign_in( self::EDITOR );
		$controller = new Mcpwp_REST_WebMCP();

		foreach ( array(
			array( 'Origin' => 'https://evil.example' ),
			array( 'Origin' => 'https://example.com.evil.example' ),
			array( 'Origin' => 'http://example.com' ),       // Scheme differs.
			array( 'Origin' => 'https://example.com:8443' ), // Port differs.
			array( 'Origin' => 'null' ),
			array( 'Origin' => '', 'Referer' => 'https://evil.example/wp-admin/' ),
			array( 'Origin' => '', 'Referer' => '' ),           // No proof of origin at all.
		) as $headers ) {
			$result = $controller->check_webmcp_permission( $this->browser_request( array( 'name' => 'wp_site_info' ), $headers ) );
			$this->assertSame( 403, $this->error_status( $result ), wp_json_encode( $headers ) );
			$this->assertSame( 'webmcp_cross_origin', $result->get_error_code() );
		}

		// Same-origin Referer when a proxy stripped Origin; explicit default port.
		$this->assertTrue( $controller->check_webmcp_permission( $this->browser_request( array( 'name' => 'wp_site_info' ), array( 'Origin' => '', 'Referer' => 'https://example.com/wp-admin/edit.php' ) ) ) );
		$this->assertTrue( $controller->check_webmcp_permission( $this->browser_request( array( 'name' => 'wp_site_info' ), array( 'Origin' => 'https://EXAMPLE.com:443' ) ) ) );
	}

	public function test_subscriber_and_author_are_403(): void {
		$this->enable();
		$controller = new Mcpwp_REST_WebMCP();

		foreach ( array( self::SUBSCRIBER, self::AUTHOR ) as $user ) {
			$this->sign_in( $user );
			$result = $controller->check_webmcp_permission( $this->browser_request( array( 'name' => 'wp_site_info' ) ) );
			$this->assertSame( 403, $this->error_status( $result ), "user $user" );
			$this->assertSame( 'webmcp_forbidden', $result->get_error_code() );
		}
	}

	// ── Handler: allowlist, capability, drafts, dispatch ──────────────────

	public function test_non_allowlisted_and_destructive_tools_are_refused_without_dispatch(): void {
		$this->enable();
		$this->sign_in( self::EDITOR );
		$controller = new Mcpwp_REST_WebMCP();

		foreach ( array( 'wp_delete_page', 'wp_delete_all_drafts', 'wp_update_option', 'wp_create_api_key', 'wp_update_page', 'wp_bulk_create_posts', 'mcpwp_call', 'wp_batch', 'wp_apply_approval' ) as $tool ) {
			$result = $controller->handle_call( $this->browser_request( array( 'name' => $tool, 'arguments' => array() ) ) );
			$this->assertSame( 403, $this->error_status( $result ), $tool );
			$this->assertSame( 'webmcp_tool_not_allowed', $result->get_error_code(), $tool );
		}
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatched_routes'], 'Nothing was dispatched.' );
	}

	public function test_allowlist_holds_no_destructive_or_admin_tool(): void {
		$forbidden = '/^(wp|wc)_(delete|update|bulk|set|apply|revoke|trigger|flush|batch|approve|reject|rollback|push)/';
		foreach ( array_keys( Mcpwp_WebMCP::TOOL_CAPABILITIES ) as $tool ) {
			$this->assertDoesNotMatchRegularExpression( $forbidden, $tool );
		}
		foreach ( Mcpwp_WebMCP::TOOL_CAPABILITIES as $cap ) {
			$this->assertNotSame( 'manage_options', $cap, 'Nothing site-wide-administrative is on the list.' );
		}
	}

	public function test_per_tool_capability_is_enforced(): void {
		$this->enable();
		$GLOBALS['mcpwp_test_user_caps'][8] = array( 'edit_posts' => true, 'edit_others_posts' => true, 'edit_pages' => false );
		$this->sign_in( 8 );
		$controller = new Mcpwp_REST_WebMCP();

		$result = $controller->handle_call( $this->browser_request( array( 'name' => 'wp_create_page', 'arguments' => array( 'title' => 'x' ) ) ) );
		$this->assertSame( 403, $this->error_status( $result ) );
		$this->assertSame( 'webmcp_tool_forbidden', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatched_routes'] );
	}

	public function test_publish_is_refused_under_every_spelling(): void {
		$this->enable();
		$this->sign_in( self::EDITOR );
		$controller = new Mcpwp_REST_WebMCP();

		foreach ( array(
			array( 'status' => 'publish' ),
			array( 'status' => ' Publish ' ),
			array( 'status' => 'private' ),
			array( 'status' => 'future' ),
			array( 'status' => 'pending' ),
			array( 'post_status' => 'publish' ),
			array( 'Post-Status' => 'publish' ),
			array( 'STATUS' => 'publish' ),
			array( 'status' => array( 'publish' ) ),
		) as $status_args ) {
			foreach ( array( 'wp_create_post', 'wp_create_page' ) as $tool ) {
				$result = $controller->handle_call( $this->browser_request( array( 'name' => $tool, 'arguments' => array_merge( array( 'title' => 'x' ), $status_args ) ) ) );
				$this->assertSame( 'webmcp_draft_only', $result->get_error_code(), $tool . ' ' . wp_json_encode( $status_args ) );
				$this->assertSame( 403, $this->error_status( $result ) );
			}
		}
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatched_routes'], 'Nothing was dispatched.' );
	}

	public function test_draft_create_is_forced_to_draft_and_dispatched_through_mcp_under_the_lift(): void {
		$this->enable();
		$this->sign_in( self::EDITOR );

		$seen = array();
		add_filter(
			'rest_dispatch_request',
			static function ( $pre, $request ) use ( &$seen ) {
				$seen[] = array(
					'route'   => $request->get_route(),
					'body'    => $request->get_json_params(),
					'lift'    => Mcpwp_WebMCP::dispatch_user_id(),
				);
				return new WP_REST_Response(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'result'  => array(
							'content' => array( array( 'type' => 'text', 'text' => '{"id":42,"status":"draft"}' ) ),
							'isError' => false,
						),
					),
					200
				);
			},
			10,
			2
		);

		foreach ( array( array( 'title' => 'Hello' ), array( 'title' => 'Hello', 'status' => 'draft' ), array( 'title' => 'Hello', 'post_status' => 'Draft' ) ) as $args ) {
			$seen     = array();
			$response = ( new Mcpwp_REST_WebMCP() )->handle_call( $this->browser_request( array( 'name' => 'wp_create_post', 'arguments' => $args ) ) );

			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( false, $response->get_data()['isError'] );
			$this->assertSame( '{"id":42,"status":"draft"}', $response->get_data()['content'][0]['text'] );

			$this->assertCount( 1, $seen );
			$this->assertSame( '/mcpwp/v1/mcp', $seen[0]['route'] );
			$this->assertSame( 'tools/call', $seen[0]['body']['method'] );
			$this->assertSame( 'wp_create_post', $seen[0]['body']['params']['name'] );
			$this->assertSame( 'draft', $seen[0]['body']['params']['arguments']['status'] );
			$this->assertArrayNotHasKey( 'post_status', $seen[0]['body']['params']['arguments'] );
			$this->assertSame( self::EDITOR, $seen[0]['lift'], 'The dispatch ran under the WebMCP principal lift.' );
		}

		$this->assertSame( 0, Mcpwp_WebMCP::dispatch_user_id(), 'The lift is released after the dispatch.' );
	}

	public function test_a_refusal_from_the_chokepoint_passes_through_with_its_status(): void {
		$this->enable();
		$this->sign_in( self::EDITOR );
		add_filter(
			'rest_dispatch_request',
			static function () {
				return new WP_Error( 'rate_limited', 'Rate limit exceeded.', array( 'status' => 429, 'retry_after' => 30 ) );
			},
			10,
			2
		);

		$result = ( new Mcpwp_REST_WebMCP() )->handle_call( $this->browser_request( array( 'name' => 'wp_list_pages', 'arguments' => array() ) ) );
		$this->assertSame( 429, $this->error_status( $result ) );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame( 30, $result->get_error_data()['retry_after'] );
		$this->assertSame( 0, Mcpwp_WebMCP::dispatch_user_id() );
	}

	public function test_a_jsonrpc_error_becomes_an_is_error_result(): void {
		$this->enable();
		$this->sign_in( self::EDITOR );
		add_filter(
			'rest_dispatch_request',
			static function () {
				return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => 1, 'error' => array( 'code' => -32602, 'message' => 'Unknown tool: wp_get_seo', 'data' => array( 'hint' => 'Pro only.' ) ) ), 200 );
			},
			10,
			2
		);

		$response = ( new Mcpwp_REST_WebMCP() )->handle_call( $this->browser_request( array( 'name' => 'wp_get_seo', 'arguments' => array( 'id' => 1 ) ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['isError'] );
		$this->assertStringContainsString( 'Unknown tool', $response->get_data()['content'][0]['text'] );
	}

	// ── The auth chokepoint under the lift ────────────────────────────────

	private function verify_under_lift( array $body, int $user = self::EDITOR, $harness = null ) {
		$harness = $harness ? $harness : new Mcpwp_WebMcp_Auth_Harness();
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', $body, array(), $body );
		$prev    = Mcpwp_WebMCP::begin_dispatch( $user );
		try {
			return array( $harness->verify_api_key( $request ), $harness );
		} finally {
			Mcpwp_WebMCP::end_dispatch( $prev );
		}
	}

	public function test_the_lift_authenticates_as_the_real_user_with_the_webmcp_record(): void {
		$this->sign_in( self::EDITOR );
		list( $verdict, $harness ) = $this->verify_under_lift( $this->mcp_body( 'wp_list_pages' ) );

		$this->assertTrue( $verdict );
		$record = $harness->record();
		$this->assertSame( 'webmcp:user:5', $record['id'] );
		$this->assertSame( 'webmcp', $record['source'] );
		$this->assertSame( self::EDITOR, $record['user_id'] );
		$this->assertSame( array( 'read', 'write' ), $record['scopes'], 'Never admin scope.' );
		$this->assertSame( self::EDITOR, get_current_user_id(), 'Not switched to the mcpwp_bot service account.' );
	}

	public function test_under_the_lift_only_allowlisted_tools_calls_authenticate(): void {
		$this->sign_in( self::EDITOR );
		foreach ( array(
			array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ),
			$this->mcp_body( 'wp_update_option', array( 'key' => 'blogname', 'value' => 'x' ) ),
			$this->mcp_body( 'mcpwp_call', array( 'name' => 'wp_list_pages' ) ),
			array( $this->mcp_body( 'wp_list_pages' ), $this->mcp_body( 'wp_delete_page', array( 'id' => 1 ) ) ),
		) as $body ) {
			list( $verdict ) = $this->verify_under_lift( $body );
			$this->assertInstanceOf( WP_Error::class, $verdict, wp_json_encode( $body ) );
			$this->assertSame( 'webmcp_tool_not_allowed', $verdict->get_error_code() );
		}
	}

	public function test_under_the_lift_a_top_level_request_to_another_route_is_refused(): void {
		$this->sign_in( self::EDITOR );
		$harness = new Mcpwp_WebMcp_Auth_Harness();
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x' ) );
		$prev    = Mcpwp_WebMCP::begin_dispatch( self::EDITOR );
		try {
			$verdict = $harness->verify_api_key( $request );
		} finally {
			Mcpwp_WebMCP::end_dispatch( $prev );
		}
		$this->assertSame( 'webmcp_tool_not_allowed', $verdict->get_error_code() );
	}

	public function test_a_lift_for_another_user_is_refused(): void {
		$this->sign_in( self::SUBSCRIBER );
		list( $verdict ) = $this->verify_under_lift( $this->mcp_body( 'wp_list_pages' ), self::EDITOR );
		$this->assertSame( 'webmcp_principal_mismatch', $verdict->get_error_code() );

		// And a lift naming a user who does not qualify.
		list( $verdict ) = $this->verify_under_lift( $this->mcp_body( 'wp_list_pages' ), self::SUBSCRIBER );
		$this->assertSame( 'webmcp_principal_mismatch', $verdict->get_error_code() );
	}

	public function test_publishing_is_refused_at_the_auth_layer_even_if_a_status_slipped_past(): void {
		$this->sign_in( self::EDITOR );
		$harness = new Mcpwp_WebMcp_Auth_Harness();
		$prev    = Mcpwp_WebMCP::begin_dispatch( self::EDITOR );
		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		try {
			$publish = $harness->verify_api_key( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'status' => 'publish' ) ) );
			$draft   = ( new Mcpwp_WebMcp_Auth_Harness() )->verify_api_key( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'status' => 'draft' ) ) );
		} finally {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
			Mcpwp_WebMCP::end_dispatch( $prev );
		}
		$this->assertInstanceOf( WP_Error::class, $publish );
		$this->assertSame( 'insufficient_scope', $publish->get_error_code() );
		$this->assertSame( 403, $publish->get_error_data()['status'] );
		$this->assertTrue( $draft );
	}

	public function test_rate_limit_applies_per_user_bucket(): void {
		Mcpwp_Rate_Limiter::get_instance()->update_settings(
			array(
				'enabled'             => true,
				'requests_per_minute' => 2,
				'requests_per_hour'   => 100,
				'burst_limit'         => 2,
				'whitelist'           => array(),
			)
		);
		$this->sign_in( self::EDITOR );

		list( $first ) = $this->verify_under_lift( $this->mcp_body( 'wp_list_pages' ) );
		list( $second ) = $this->verify_under_lift( $this->mcp_body( 'wp_list_pages' ) );
		list( $third ) = $this->verify_under_lift( $this->mcp_body( 'wp_list_pages' ) );

		$this->assertTrue( $first );
		$this->assertTrue( $second );
		$this->assertInstanceOf( WP_Error::class, $third );
		$this->assertSame( 429, $third->get_error_data()['status'] );
		$this->assertGreaterThan( 0, Mcpwp_Rate_Limiter::get_instance()->get_usage( 'webmcp:user:' . self::EDITOR )['burst']['count'] ?? 1 );
	}

	public function test_without_the_lift_a_cookie_session_gets_nothing_from_mcp(): void {
		$this->sign_in( self::EDITOR );
		$verdict = ( new Mcpwp_WebMcp_Auth_Harness() )->verify_api_key( new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array(), $this->mcp_body( 'wp_list_pages' ) ) );
		$this->assertSame( 'missing_api_key', $verdict->get_error_code() );
	}

	public function test_action_log_records_source_webmcp_and_the_user(): void {
		$this->enable();
		$this->sign_in( self::EDITOR );
		$GLOBALS['mcpwp_test_rest_response_override'] = array( 'id' => 42, 'status' => 'draft', 'title' => 'Hello' );

		$mcp     = new Mcpwp_REST_MCP();
		$body    = $this->mcp_body( 'wp_create_post', array( 'title' => 'Hello', 'status' => 'draft' ) );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', $body, array(), $body );
		$prev    = Mcpwp_WebMCP::begin_dispatch( self::EDITOR );
		try {
			$this->assertTrue( $mcp->verify_api_key( $request ) );
			$mcp->handle_mcp( $request );
		} finally {
			Mcpwp_WebMCP::end_dispatch( $prev );
		}

		$all = array();
		foreach ( $GLOBALS['mcpwp_test_wpdb_rows'] ?? array() as $table_rows ) {
			$all = array_merge( $all, (array) $table_rows );
		}
		$rows = array_values(
			array_filter(
				$all,
				static function ( $row ) {
					return is_array( $row ) && isset( $row['tool_name'] ) && 'wp_create_post' === $row['tool_name'];
				}
			)
		);
		$this->assertNotEmpty( $rows, 'The write landed in the action log.' );
		$this->assertSame( 'webmcp:user:5', $rows[0]['api_key_id'] );
	}

	public function test_principal_categories_cover_the_allowlist(): void {
		$categories = array_merge(
			( new Mcpwp_MCP_Free_Tools() )->get_tool_categories(),
			( new Mcpwp_MCP_Pro_Tools() )->get_tool_categories(),
			( new Mcpwp_WebMCP_Integration() )->get_tool_categories()
		);
		foreach ( array_keys( Mcpwp_WebMCP::TOOL_CAPABILITIES ) as $tool ) {
			$this->assertArrayHasKey( $tool, $categories, "$tool must be a real tool." );
			$this->assertContains( $categories[ $tool ], Mcpwp_WebMCP::PRINCIPAL_CATEGORIES, "$tool's category must be one the WebMCP principal is granted." );
		}
	}

	// ── wp_propose_content_change ─────────────────────────────────────────

	public function test_propose_creates_an_approval_and_changes_nothing(): void {
		$this->sign_in( self::EDITOR );
		$prev = Mcpwp_WebMCP::begin_dispatch( self::EDITOR );
		try {
			$result = Mcpwp_WebMCP::propose_content_change(
				array(
					'post_id' => self::POST_ID,
					'content' => 'New body <script>alert(1)</script>',
					'note'    => 'Tighter intro.',
				)
			);
		} finally {
			Mcpwp_WebMCP::end_dispatch( $prev );
		}

		$this->assertIsArray( $result );
		$this->assertSame( 'approval_required', $result['status'] );
		$this->assertSame( 'pending', $result['approval']['status'] );
		$this->assertSame( 'post_content_update', $result['approval']['action'] );

		$stored = mcpwp_test_approvals_all()[ $result['approval']['id'] ];
		$this->assertSame( 'webmcp:user:5', $stored['requested_by_key_id'] );
		$this->assertSame( 'wp_propose_content_change', $stored['tool'] );
		$this->assertStringNotContainsString( '<script>', $stored['payload']['after_content'], 'No unfiltered HTML for a user without unfiltered_html.' );
		$this->assertSame( 'Original body.', $GLOBALS['_mcpwp_test_posts'][ self::POST_ID ]->post_content, 'The post is untouched.' );
	}

	public function test_propose_refuses_outside_a_webmcp_dispatch_and_for_posts_the_user_cannot_edit(): void {
		$this->sign_in( self::EDITOR );
		$result = Mcpwp_WebMCP::propose_content_change( array( 'post_id' => self::POST_ID, 'content' => 'x' ) );
		$this->assertSame( 'webmcp_only', $result->get_error_code() );

		$GLOBALS['mcpwp_test_user_caps'][ self::EDITOR ]['edit_post'] = false;
		$prev = Mcpwp_WebMCP::begin_dispatch( self::EDITOR );
		try {
			$denied  = Mcpwp_WebMCP::propose_content_change( array( 'post_id' => self::POST_ID, 'content' => 'x' ) );
			$missing = Mcpwp_WebMCP::propose_content_change( array( 'post_id' => 999999, 'content' => 'x' ) );
		} finally {
			Mcpwp_WebMCP::end_dispatch( $prev );
		}
		$this->assertSame( 'not_found', $denied->get_error_code() );
		$this->assertSame( $missing->get_error_code(), $denied->get_error_code(), 'Same answer as a missing post.' );
		$this->assertSame( array(), mcpwp_test_approvals_all() );
	}

	public function test_the_propose_tool_exists_only_for_webmcp(): void {
		$integration = new Mcpwp_WebMCP_Integration();
		$this->assertSame( array(), $integration->get_tools() );
		$this->assertSame( array(), $integration->get_tool_map() );
		$this->assertSame( array( 'wp_propose_content_change' => 'content' ), $integration->get_tool_categories(), 'Name reserved regardless.' );

		$prev = Mcpwp_WebMCP::begin_dispatch( self::EDITOR );
		try {
			$this->assertSame( 'wp_propose_content_change', $integration->get_tools()[0]['name'] );
			$this->assertTrue( is_callable( $integration->get_tool_map()['wp_propose_content_change']['callback'] ) );
			$annotations = $integration->get_tools()[0]['annotations'];
			$this->assertFalse( $annotations['destructiveHint'] );
		} finally {
			Mcpwp_WebMCP::end_dispatch( $prev );
		}
	}

	// ── Settings ──────────────────────────────────────────────────────────

	public function test_origin_trial_token_validation(): void {
		$valid = 'A' . str_repeat( 'bC9+/', 60 ) . 'x==';
		$this->assertSame( $valid, Mcpwp_WebMCP::sanitize_origin_trial_token( "  $valid\n" ) );
		$this->assertSame( '', Mcpwp_WebMCP::sanitize_origin_trial_token( '' ) );
		$this->assertSame( '', Mcpwp_WebMCP::sanitize_origin_trial_token( null ) );
		foreach ( array( '"><script>', 'abc def', 'abc"', array( 'x' ), str_repeat( 'A', 5000 ), 'abc===' ) as $bad ) {
			$this->assertNull( Mcpwp_WebMCP::sanitize_origin_trial_token( $bad ), wp_json_encode( $bad ) );
		}
	}

	public function test_admin_form_sanitizer_keeps_the_stored_token_on_a_bad_value(): void {
		$settings = new Mcpwp_Settings();
		update_option( 'mcpwp_settings', array( 'webmcp_origin_trial_token' => 'AAAA' ) );

		$bad = $settings->sanitize_settings( array( 'webmcp_origin_trial_token' => '"><script>alert(1)</script>' ) );
		$this->assertSame( 'AAAA', $bad['webmcp_origin_trial_token'] );

		$good = $settings->sanitize_settings( array( 'webmcp_origin_trial_token' => ' BBBB== ' ) );
		$this->assertSame( 'BBBB==', $good['webmcp_origin_trial_token'] );

		$absent = $settings->sanitize_settings( array() );
		$this->assertSame( 'AAAA', $absent['webmcp_origin_trial_token'] );
		$this->assertFalse( $absent['webmcp_admin_enabled'], 'Off by default.' );
	}

	public function test_rest_plugin_settings_validates_both_keys(): void {
		$controller = new Mcpwp_REST_Site_Settings();

		$bad = $controller->update_plugin_settings( new WP_REST_Request( 'POST', '/mcpwp/v1/plugin-settings', array(), array(), array( 'webmcp_origin_trial_token' => 'not a token!' ) ) );
		$this->assertInstanceOf( WP_Error::class, $bad );
		$this->assertSame( 400, $bad->get_error_data()['status'] );

		$ok = $controller->update_plugin_settings( new WP_REST_Request( 'POST', '/mcpwp/v1/plugin-settings', array(), array(), array( 'webmcp_admin_enabled' => true, 'webmcp_origin_trial_token' => 'QUJD' ) ) );
		$this->assertNotInstanceOf( WP_Error::class, $ok );
		$this->assertTrue( Mcpwp_WebMCP::is_enabled() );
		$this->assertSame( 'QUJD', Mcpwp_WebMCP::get_origin_trial_token() );
	}

	// ── wp-admin wiring ───────────────────────────────────────────────────

	private function meta_output(): string {
		ob_start();
		Mcpwp_WebMCP::print_origin_trial_meta();
		return (string) ob_get_clean();
	}

	public function test_origin_trial_meta_only_in_admin_only_when_enabled_and_set(): void {
		$GLOBALS['mcpwp_test_current_user'] = self::EDITOR;

		$GLOBALS['mcpwp_test_is_admin'] = true;
		$this->enable( array( 'webmcp_origin_trial_token' => 'QUJDRA==' ) );
		$this->assertSame( '<meta http-equiv="origin-trial" content="QUJDRA==" />' . "\n", $this->meta_output() );

		$GLOBALS['mcpwp_test_is_admin'] = false; // Front end.
		$this->assertSame( '', $this->meta_output() );

		$GLOBALS['mcpwp_test_is_admin'] = true;
		$this->enable( array( 'webmcp_origin_trial_token' => '' ) );
		$this->assertSame( '', $this->meta_output(), 'No token, no tag.' );

		update_option( 'mcpwp_settings', array( 'webmcp_admin_enabled' => false, 'webmcp_origin_trial_token' => 'QUJDRA==' ) );
		$this->assertSame( '', $this->meta_output(), 'Switched off, no tag.' );

		// A malformed value that reached the option by another path is never printed.
		$this->enable( array( 'webmcp_origin_trial_token' => '"><script>x</script>' ) );
		$this->assertSame( '', $this->meta_output() );

		$GLOBALS['mcpwp_test_current_user'] = self::SUBSCRIBER;
		$this->enable( array( 'webmcp_origin_trial_token' => 'QUJDRA==' ) );
		$this->assertSame( '', $this->meta_output(), 'Not for a user who does not get WebMCP.' );
	}

	public function test_nothing_is_enqueued_when_off_or_for_a_subscriber(): void {
		$GLOBALS['mcpwp_test_is_admin']     = true;
		$GLOBALS['mcpwp_test_current_user'] = self::EDITOR;
		Mcpwp_WebMCP::enqueue();
		$this->assertSame( array(), $GLOBALS['mcpwp_test_enqueued_scripts'] );

		$this->enable();
		$GLOBALS['mcpwp_test_current_user'] = self::SUBSCRIBER;
		Mcpwp_WebMCP::enqueue();
		$this->assertSame( array(), $GLOBALS['mcpwp_test_enqueued_scripts'] );
	}

	public function test_enqueue_localizes_single_sourced_definitions_for_an_editor(): void {
		$GLOBALS['mcpwp_test_is_admin']     = true;
		$GLOBALS['mcpwp_test_current_user'] = self::EDITOR;
		$this->enable();

		Mcpwp_WebMCP::enqueue();

		$this->assertArrayHasKey( 'mcpwp-webmcp', $GLOBALS['mcpwp_test_enqueued_scripts'] );
		$this->assertStringEndsWith( 'admin/js/mcpwp-webmcp.js', $GLOBALS['mcpwp_test_enqueued_scripts']['mcpwp-webmcp'] );
		$inline = $GLOBALS['mcpwp_test_inline_scripts'][0];
		$this->assertSame( 'before', $inline['position'] );
		$this->assertStringStartsWith( 'window.mcpwpWebMcp = ', $inline['data'] );

		$config = json_decode( substr( rtrim( $inline['data'], ';' ), strlen( 'window.mcpwpWebMcp = ' ) ), true );
		$this->assertSame( 'https://example.com/wp-json/mcpwp/v1/webmcp/call', $config['endpoint'] );
		$this->assertSame( 'test-nonce', $config['nonce'] );

		$by_name = array_column( $config['tools'], null, 'name' );
		foreach ( array( 'wp_onboard', 'wp_site_info', 'wp_list_pages', 'wp_list_posts', 'wp_get_page_by_slug', 'wp_search', 'wp_seo_flags', 'wp_get_rendered_html', 'wp_create_post', 'wp_create_page', 'wp_propose_content_change' ) as $name ) {
			$this->assertArrayHasKey( $name, $by_name, $name );
		}
		foreach ( array_keys( $by_name ) as $name ) {
			$this->assertTrue( Mcpwp_WebMCP::is_allowlisted( $name ), "$name is not allowlisted but was registered." );
		}

		// Same schema the /mcp registry serves (single source), minus status on draft tools.
		$registry = array_column( ( new Mcpwp_MCP_Free_Tools() )->get_tools(), null, 'name' );
		$this->assertSame( $registry['wp_list_pages']['description'], $by_name['wp_list_pages']['description'] );
		$this->assertSame( json_decode( wp_json_encode( $registry['wp_list_pages']['inputSchema'] ), true ), $by_name['wp_list_pages']['inputSchema'] );
		$this->assertArrayNotHasKey( 'status', $by_name['wp_create_post']['inputSchema']['properties'] );
		$this->assertStringContainsString( 'DRAFT', $by_name['wp_create_post']['description'] );

		$this->assertSame( array( 'readOnlyHint' => true, 'untrustedContentHint' => true, 'consequentialHint' => false ), $by_name['wp_list_pages']['annotations'] );
		$this->assertFalse( $by_name['wp_create_post']['annotations']['readOnlyHint'] );
		foreach ( $config['tools'] as $tool ) {
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_.-]{1,128}$/', $tool['name'], 'WebMCP tool-name rule.' );
			$this->assertNotSame( '', $tool['description'] );
		}

		$this->assertSame( 0, Mcpwp_WebMCP::dispatch_user_id() );
		$this->assertFalse( Mcpwp_WebMCP::exposes_webmcp_only_tools(), 'The describing flag is released.' );
	}
}
