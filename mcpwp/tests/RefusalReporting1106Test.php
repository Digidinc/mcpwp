<?php
/**
 * How a refusal is REPORTED (not whether it happens): the wording a key whose
 * role can never hold admin scope gets, an in-protocol refusal for /mcp
 * tools/call and mcpwp_call (and per call inside a JSON-RPC batch), tools/list
 * and friends listing only what the principal can call (#1106), and one
 * invalid-key hint that names no cause.
 *
 * Every test that checks a refusal also pins the decision: the call is still
 * refused, never run.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class RefusalReporting1106Test extends TestCase {

	const EDITOR = 7;

	/** @var array */
	private $saved_users;

	/** @var array */
	private $saved_caps;

	protected function setUp(): void {
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		$this->saved_caps  = $GLOBALS['mcpwp_test_user_caps'] ?? array();

		$GLOBALS['mcpwp_test_options']             = array();
		$GLOBALS['mcpwp_test_transients']          = array();
		$GLOBALS['mcpwp_test_current_user']        = 0;
		$GLOBALS['mcpwp_test_filters']             = array();
		$GLOBALS['mcpwp_test_dispatched_routes']   = array();
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();
		$GLOBALS['mcpwp_test_users'][]             = (object) array( 'ID' => self::EDITOR, 'roles' => array( 'editor' ), 'user_login' => 'editor7' );
		$GLOBALS['mcpwp_test_user_caps']           = array(
			self::EDITOR => array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true, 'publish_posts' => true, 'read_private_posts' => true ),
		);
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );
		Mcpwp_REST_MCP::reset_verdict_memo();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_users']             = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps']         = $this->saved_caps;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		$GLOBALS['mcpwp_test_current_user']      = 0;
	}

	private function mint( string $role, array $scopes, array $categories = array() ): string {
		$created = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'k-' . $role, $scopes, $role, $categories );
		$this->assertArrayHasKey( 'key', $created );
		return $created['key'];
	}

	private function rest( string $method, string $route, string $key ): WP_REST_Request {
		return new WP_REST_Request( $method, $route, array(), array( 'X-API-Key' => $key ), array() );
	}

	/** One /mcp tools/call message. */
	private function call_msg( $id, string $tool, array $args = array() ): array {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => array( 'name' => $tool, 'arguments' => $args ) );
	}

	private function wrapped_msg( $id, string $tool, array $args = array() ): array {
		return $this->call_msg( $id, 'mcpwp_call', array( 'name' => $tool, 'arguments' => $args ) );
	}

	/** Authenticate then answer, on one controller, as WordPress would. */
	private function mcp( string $key, $body, array $params = array() ): array {
		Mcpwp_REST_MCP::reset_verdict_memo();
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', $params, array( 'X-API-Key' => $key ), $body );
		$verdict    = $controller->verify_api_key( $request );
		if ( is_wp_error( $verdict ) ) {
			return array( 'verdict' => $verdict, 'status' => null, 'data' => null );
		}
		$response = $controller->handle_mcp( $request );
		return array( 'verdict' => $verdict, 'status' => $response->get_status(), 'data' => $response->get_data() );
	}

	// ------------------------------------------------------------------
	// (A) wording for a role that can never hold admin scope
	// ------------------------------------------------------------------

	public static function non_admin_roles(): array {
		return array(
			'editor'   => array( 'editor', array() ),
			'author'   => array( 'author', array() ),
			'designer' => array( 'designer', array() ),
			'custom'   => array( 'custom', array( 'content', 'media' ) ),
		);
	}

	/**
	 * @dataProvider non_admin_roles
	 */
	public function test_a_role_that_cannot_hold_admin_is_told_what_is_true( string $role, array $categories ): void {
		$key     = $this->mint( $role, array( 'read', 'write' ), $categories );
		$verdict = ( new Mcpwp_REST_MCP() )->verify_api_key( $this->rest( 'POST', '/mcpwp/v1/site-context', $key ) );

		// The decision: still refused, same code, same status.
		$this->assertInstanceOf( WP_Error::class, $verdict );
		$this->assertSame( 'insufficient_scope', $verdict->get_error_code() );
		$data = $verdict->get_error_data();
		$this->assertSame( 403, $data['status'] );
		$this->assertSame( 'admin', $data['required_scope'] );

		// The wording: an Admin-role key from Setup, not "request a key with the scope".
		$this->assertStringContainsString( 'Admin-role key with the admin scope', $verdict->get_error_message() );
		$this->assertStringContainsString( 'role: ' . $role, $verdict->get_error_message() );
		$this->assertStringContainsString( 'MCPWP -> Setup', $data['hint'] );
		$this->assertStringNotContainsString( 'wp_create_api_key', $data['hint'], 'A key that can never hold admin must not be pointed at a self-service fix.' );
		$this->assertStringNotContainsString( 'Request a key with the required scope', $data['hint'] );
	}

	public function test_the_category_the_key_lacks_is_named_too(): void {
		$key     = $this->mint( 'author', array( 'read', 'write' ) );
		$verdict = ( new Mcpwp_REST_MCP() )->verify_api_key( $this->rest( 'GET', '/mcpwp/v1/woocommerce/orders', $key ) );

		$this->assertInstanceOf( WP_Error::class, $verdict );
		$this->assertSame( 'insufficient_scope', $verdict->get_error_code() );
		$this->assertStringContainsString( 'woocommerce', $verdict->get_error_message() );
		$this->assertStringContainsString( 'content, media, taxonomy', $verdict->get_error_message() );
		$this->assertSame( 'woocommerce', $verdict->get_error_data()['category'] );
		$this->assertStringContainsString( 'Admin-role key', $verdict->get_error_message() );
	}

	public function test_an_admin_role_key_keeps_the_scope_hint(): void {
		$key     = $this->mint( 'admin', array( 'read', 'write' ) );
		$verdict = ( new Mcpwp_REST_MCP() )->verify_api_key( $this->rest( 'POST', '/mcpwp/v1/site-context', $key ) );

		$this->assertInstanceOf( WP_Error::class, $verdict );
		$this->assertSame( 'insufficient_scope', $verdict->get_error_code() );
		$this->assertSame( 'API key lacks required scope: admin', $verdict->get_error_message() );
		$this->assertStringContainsString( 'wp_create_api_key', $verdict->get_error_data()['hint'] );
		$this->assertStringNotContainsString( 'Admin-role key', $verdict->get_error_data()['hint'] );
	}

	public function test_a_read_only_non_admin_key_that_needs_write_keeps_the_satisfiable_hint(): void {
		$key     = $this->mint( 'author', array( 'read' ) );
		$verdict = ( new Mcpwp_REST_MCP() )->verify_api_key( $this->rest( 'POST', '/mcpwp/v1/pages', $key ) );

		$this->assertInstanceOf( WP_Error::class, $verdict );
		$this->assertSame( 'insufficient_scope', $verdict->get_error_code() );
		$this->assertStringContainsString( 'needs "write"', $verdict->get_error_data()['hint'] );
	}

	// ------------------------------------------------------------------
	// (B) in-protocol refusal on /mcp
	// ------------------------------------------------------------------

	public function test_tools_call_scope_refusal_is_an_in_protocol_result(): void {
		$key = $this->mint( 'author', array( 'read', 'write' ) );
		$out = $this->mcp( $key, $this->call_msg( 5, 'wp_set_site_context' ) );

		$this->assertTrue( $out['verdict'], 'The envelope admits the message; the call is judged on its own.' );
		$this->assertSame( 200, $out['status'] );
		$this->assertSame( 5, $out['data']['id'] );
		$result = $out['data']['result'];
		$this->assertTrue( $result['isError'] );
		$error = $result['structuredContent']['error'];
		$this->assertSame( 'insufficient_scope', $error['code'] );
		$this->assertSame( 403, $error['status'] );
		$this->assertSame( 'admin', $error['required_scope'] );
		$this->assertStringContainsString( 'Admin-role key with the admin scope', $error['message'] );
		$this->assertStringContainsString( 'MCPWP -> Setup', $error['hint'] );
		$this->assertStringContainsString( 'Fix: ', $result['content'][0]['text'] );
		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_routes'], 'The refused call never dispatched.' );
	}

	public function test_an_admin_role_key_gets_the_ordinary_hint_in_protocol(): void {
		$key   = $this->mint( 'admin', array( 'read', 'write' ) );
		$out   = $this->mcp( $key, $this->call_msg( 1, 'wp_set_site_context' ) );
		$error = $out['data']['result']['structuredContent']['error'];

		$this->assertSame( 'insufficient_scope', $error['code'] );
		$this->assertStringContainsString( 'wp_create_api_key', $error['hint'] );
	}

	public function test_mcpwp_call_scope_refusal_is_an_in_protocol_result(): void {
		$key = $this->mint( 'author', array( 'read', 'write' ) );
		$out = $this->mcp( $key, $this->wrapped_msg( 6, 'wp_set_site_context' ) );

		$this->assertTrue( $out['verdict'] );
		$this->assertSame( 200, $out['status'] );
		$this->assertTrue( $out['data']['result']['isError'] );
		$this->assertSame( 'insufficient_scope', $out['data']['result']['structuredContent']['error']['code'] );
		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_routes'] );
	}

	public function test_a_batch_runs_the_allowed_calls_and_reports_the_refused_one(): void {
		$key = $this->mint( 'admin', array( 'read', 'write' ) );
		$out = $this->mcp(
			$key,
			array(
				$this->call_msg( 1, 'wp_site_info' ),
				$this->call_msg( 2, 'wp_set_site_context' ),
				$this->wrapped_msg( 3, 'wp_list_pages' ),
				$this->wrapped_msg( 4, 'wp_delete_all_drafts' ),
			)
		);

		$this->assertTrue( $out['verdict'], 'One refused call must not reject the whole batch.' );
		$this->assertSame( 200, $out['status'] );
		$by_id = array();
		foreach ( $out['data'] as $response ) {
			$by_id[ $response['id'] ] = $response;
		}
		$this->assertCount( 4, $by_id );
		$this->assertEmpty( $by_id[1]['result']['isError'] ?? false, 'wp_site_info is allowed and ran.' );
		$this->assertTrue( $by_id[2]['result']['isError'] );
		$this->assertSame( 'insufficient_scope', $by_id[2]['result']['structuredContent']['error']['code'] );
		$this->assertEmpty( $by_id[3]['result']['isError'] ?? false, 'A wrapped allowed call ran.' );
		$this->assertTrue( $by_id[4]['result']['isError'] );
		$this->assertSame( 'insufficient_scope', $by_id[4]['result']['structuredContent']['error']['code'] );

		$routes = $GLOBALS['mcpwp_test_dispatched_routes'];
		$this->assertContains( '/mcpwp/v1/site-info', $routes );
		$this->assertContains( '/mcpwp/v1/pages', $routes );
		$this->assertNotContains( '/mcpwp/v1/site-context', $routes );
		$this->assertNotContains( '/mcpwp/v1/drafts/delete-all', $routes );
	}

	public function test_a_shortfall_outside_tools_call_still_refuses_the_whole_envelope(): void {
		// A read key; the unknown method needs more than read. Nothing about
		// that is per call, so it stays one refusal of the whole body.
		$key = $this->mint( 'admin', array( 'read' ) );
		$out = $this->mcp(
			$key,
			array(
				$this->call_msg( 1, 'wp_site_info' ),
				array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'example/unknown', 'params' => array() ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out['verdict'] );
		$this->assertSame( 'insufficient_scope', $out['verdict']->get_error_code() );
		$this->assertSame( 403, $out['verdict']->get_error_data()['status'] );
	}

	public function test_a_read_key_wrapped_and_direct_write_calls_are_refused_and_never_dispatch(): void {
		$key = $this->mint( 'admin', array( 'read' ) );
		foreach ( array( $this->call_msg( 1, 'wp_create_page', array( 'title' => 'x' ) ), $this->wrapped_msg( 2, 'wp_create_page', array( 'title' => 'x' ) ) ) as $message ) {
			$out = $this->mcp( $key, $message );
			$this->assertTrue( $out['data']['result']['isError'] );
			$this->assertSame( 'insufficient_scope', $out['data']['result']['structuredContent']['error']['code'] );
		}
		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_routes'] );
	}

	public function test_a_stored_idempotent_result_is_not_served_to_a_key_that_may_not_call(): void {
		$admin = $this->mint( 'admin', array( 'read', 'write', 'admin' ) );
		$write = $this->mint( 'admin', array( 'read', 'write' ) );
		$msg   = $this->call_msg( 1, 'wp_set_site_context', array( 'context' => 'refusal test' ) );
		$msg['params']['_meta'] = array( 'request_id' => 'req-refusal-1106' );

		$first = $this->mcp( $admin, $msg );
		$this->assertEmpty( $first['data']['result']['isError'] ?? false, 'Precondition: the admin key ran it.' );

		$second = $this->mcp( $write, $msg );
		$this->assertTrue( $second['data']['result']['isError'] );
		$this->assertSame( 'insufficient_scope', $second['data']['result']['structuredContent']['error']['code'] );
	}

	public function test_a_rest_route_keeps_its_http_403(): void {
		$key     = $this->mint( 'author', array( 'read', 'write' ) );
		$verdict = ( new Mcpwp_REST_MCP() )->verify_api_key( $this->rest( 'POST', '/mcpwp/v1/site-context', $key ) );

		$this->assertInstanceOf( WP_Error::class, $verdict );
		$this->assertSame( 403, $verdict->get_error_data()['status'] );
	}

	// ------------------------------------------------------------------
	// (C) listing and the call path agree (#1106)
	// ------------------------------------------------------------------

	private function listed( string $key, array $params = array(), string $method = 'tools/list' ): array {
		$out   = $this->mcp( $key, array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => array() ), $params + array( 'profile' => 'full' ) );
		$names = array();
		foreach ( $out['data']['result']['tools'] ?? array() as $tool ) {
			$names[] = $tool['name'];
		}
		return $names;
	}

	public function test_tools_list_omits_tools_the_key_scope_cannot_call(): void {
		$write = $this->listed( $this->mint( 'admin', array( 'read', 'write' ) ) );
		$admin = $this->listed( $this->mint( 'admin', array( 'read', 'write', 'admin' ) ) );
		$read  = $this->listed( $this->mint( 'admin', array( 'read' ) ) );

		$this->assertContains( 'wp_set_site_context', $admin );
		$this->assertNotContains( 'wp_set_site_context', $write );
		$this->assertNotContains( 'wp_delete_all_drafts', $write );
		$this->assertContains( 'wp_create_page', $write );
		$this->assertNotContains( 'wp_create_page', $read, 'A read key is not offered a write tool.' );
		$this->assertContains( 'wp_site_info', $read );
		$this->assertContains( 'mcpwp_call', $read, 'The meta tools are gateways and stay listed.' );
		$this->assertContains( 'mcpwp_find_tools', $read );
	}

	/**
	 * The listing is the call path's own answer: a tool is listed exactly
	 * when tools/call does not refuse it for scope.
	 */
	public function test_every_listed_tool_passes_and_every_hidden_tool_is_refused_for_scope(): void {
		$key    = $this->mint( 'admin', array( 'read', 'write' ) );
		$full   = $this->listed( $this->mint( 'admin', array( 'read', 'write', 'admin' ) ) );
		$listed = array_flip( $this->listed( $key ) );

		$this->assertGreaterThan( 100, count( $full ) );
		$wrong = array();
		foreach ( $full as $name ) {
			if ( in_array( $name, array( 'mcpwp_call', 'mcpwp_find_tools' ), true ) ) {
				continue;
			}
			$GLOBALS['mcpwp_test_transients'] = array(); // The rate window is not what is under test.
			$out     = $this->mcp( $key, $this->call_msg( 1, $name ) );
			$refused = isset( $out['data']['result']['structuredContent']['error']['code'] ) && 'insufficient_scope' === $out['data']['result']['structuredContent']['error']['code'];
			if ( isset( $listed[ $name ] ) === $refused ) {
				$wrong[] = $name . ( $refused ? ' (listed but refused)' : ' (hidden but allowed)' );
			}
		}
		$this->assertSame( array(), $wrong );
	}

	public function test_find_tools_does_not_return_what_the_key_cannot_call(): void {
		$query = array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'mcpwp_find_tools', 'arguments' => array( 'query' => 'site context' ) ) );
		$names = function ( array $out ): array {
			$text = $out['data']['result']['content'][0]['text'] ?? '';
			return array_map( static function ( $t ) { return $t['name']; }, (array) ( json_decode( $text, true )['matches'] ?? array() ) );
		};

		$write = $names( $this->mcp( $this->mint( 'admin', array( 'read', 'write' ) ), $query ) );
		$admin = $names( $this->mcp( $this->mint( 'admin', array( 'read', 'write', 'admin' ) ), $query ) );

		$this->assertContains( 'wp_set_site_context', $admin, 'Precondition: the search finds it for a key that may call it.' );
		$this->assertNotContains( 'wp_set_site_context', $write );
	}

	public function test_a_user_run_token_is_not_offered_tools_its_user_lacks_the_capability_for(): void {
		$harness = new Mcpwp_REST_MCP();
		$token   = $harness->issue_oauth_access_token( array( 'read', 'write' ), 3600, array( 'user_id' => self::EDITOR ) )['access_token'];

		Mcpwp_REST_MCP::reset_verdict_memo();
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array( 'profile' => 'full' ), array( 'Authorization' => 'Bearer ' . $token ), array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => array() ) );
		$this->assertTrue( $controller->verify_api_key( $request ) );
		$tools = array();
		foreach ( $controller->handle_mcp( $request )->get_data()['result']['tools'] as $tool ) {
			$tools[] = $tool['name'];
		}

		$this->assertContains( 'wp_list_pages', $tools );
		$this->assertNotContains( 'wp_set_site_context', $tools, 'Admin scope: an OAuth token never holds it.' );

		// Every route-mapped tool listed to the editor has a capability the editor holds.
		$map     = array_merge( ( new Mcpwp_MCP_Free_Tools() )->get_tool_map(), ( new Mcpwp_MCP_Pro_Tools() )->get_tool_map() );
		$listed  = 0;
		foreach ( $tools as $name ) {
			if ( empty( $map[ $name ]['route'] ) || isset( $map[ $name ]['callback'] ) ) {
				continue;
			}
			$route = (string) preg_replace( '/\{[A-Za-z0-9_]+\}/', '1', str_replace( '{type}', 'post', $map[ $name ]['route'] ) );
			$cap   = Mcpwp_Route_Capability_Map::required_capability( $map[ $name ]['method'], $route );
			foreach ( '' === $cap ? array() : explode( '+', $cap ) as $one ) {
				$this->assertTrue( current_user_can( $one ), "$name is listed but needs $one" );
			}
			++$listed;
		}
		$this->assertGreaterThan( 20, $listed );

		// Same scope (read + write), no category restriction on either: what the
		// editor is not offered, beyond the key's list, is hidden by capability.
		$key_tools = $this->listed( $this->mint( 'admin', array( 'read', 'write' ) ) );
		$hidden    = array_diff( $key_tools, $tools );
		$this->assertGreaterThan( 5, count( $hidden ), 'Tools the editor lacks the capability for are not listed: ' . implode( ', ', $hidden ) );
	}

	// ------------------------------------------------------------------
	// (D) one hint for every invalid key
	// ------------------------------------------------------------------

	private function invalid_key_error( string $key ): WP_Error {
		Mcpwp_REST_MCP::reset_verdict_memo();
		$verdict = ( new Mcpwp_REST_MCP() )->verify_api_key( $this->rest( 'GET', '/mcpwp/v1/site-info', $key ) );
		$this->assertInstanceOf( WP_Error::class, $verdict );
		$this->assertSame( 'invalid_api_key', $verdict->get_error_code() );
		$this->assertSame( 401, $verdict->get_error_data()['status'] );
		return $verdict;
	}

	public function test_every_invalid_key_case_carries_the_same_generic_hint(): void {
		$controller = new Mcpwp_REST_MCP();

		// A retired first-activation key: the option holds a hash, scoped keys exist.
		$legacy = 'mcpwp_' . bin2hex( random_bytes( 12 ) );
		update_option( 'mcpwp_api_key', wp_hash_password( $legacy ) );
		$live    = $controller->create_scoped_api_key( 'live', array( 'read' ), 'admin' );
		$revoked = $controller->create_scoped_api_key( 'gone', array( 'read' ), 'admin' );
		$controller->revoke_scoped_api_key( $revoked['id'] );

		$hints = array();
		foreach ( array( 'never-valid' => 'mcpwp_' . str_repeat( 'a', 48 ), 'retired legacy' => $legacy, 'revoked' => $revoked['key'] ) as $label => $key ) {
			$error = $this->invalid_key_error( $key );
			$hints[ $label ] = $error->get_error_data()['hint'];
			$this->assertSame( 'Invalid API key.', $error->get_error_message(), $label );
		}

		$this->assertSame( 1, count( array_unique( $hints ) ), 'The hint must be identical for every case: ' . wp_json_encode( $hints ) );
		$hint = reset( $hints );
		$this->assertSame( Mcpwp_REST_MCP::invalid_api_key_hint(), $hint );
		$this->assertStringContainsString( 'mistyped', $hint );
		$this->assertStringContainsString( 'revoked', $hint );
		$this->assertStringContainsString( 'retired first-activation key', $hint );
		$this->assertStringContainsString( 'MCPWP -> Setup', $hint );
		$this->assertStringNotContainsString( 'typos or whitespace', $hint );

		// The decision is unchanged: the live key still authenticates, the retired one does not.
		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->assertTrue( ( new Mcpwp_REST_MCP() )->verify_api_key( $this->rest( 'GET', '/mcpwp/v1/site-info', $live['key'] ) ) );
	}

	public function test_the_late_hint_table_says_the_same_thing(): void {
		$hint = Mcpwp_Error_Hints::get_hint_for_code( 'invalid_api_key' );
		$this->assertSame( Mcpwp_REST_MCP::invalid_api_key_hint(), $hint['hint'] );
	}
}
