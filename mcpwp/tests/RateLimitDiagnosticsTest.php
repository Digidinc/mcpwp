<?php
/**
 * Coverage for #802 — a bulk job that hit the rate limit could not find out
 * when to resume, because the calls that answer that question were counted
 * against the budget they were asking about.
 *
 * Reported from a 116-page migration: the hourly limit landed mid-run,
 * `wp_rate_limit_status` itself returned `rate_limit_exceeded`, `initialize`
 * 429'd, and the client had to sleep blind. The 429 body carried "Try again in
 * N seconds" as prose but the success envelopes carried no budget, so a client
 * could not pace itself before the wall.
 *
 * Three properties are pinned here:
 *
 *   - Diagnostics (initialize, wp_rate_limit_status, wp_site_info, GET
 *     /rate-limit) are metered in their own bucket, so an exhausted write
 *     budget can never hide the budget. They are still bounded — an
 *     authenticated caller cannot spam them without limit.
 *
 *   - Every MCP result carries `_meta.rate_limit` with `remaining` and
 *     `reset_in`, and `initialize` reports the plugin version, WP version and a
 *     concurrency hint, so a client can pace itself and detect a plugin swap
 *     mid-session.
 *
 *   - A notifications-only batch is 204, not an empty 200. An empty 200 is the
 *     response shape the reporter's client had to special-case as "server
 *     returned nothing".
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class RateLimitDiagnosticsTest extends TestCase {

	/** @var string */
	private $key = '';

	/** @var string */
	private $key_id = '';

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_filters']      = array();
		Mcpwp_REST_MCP::reset_verdict_memo();

		$created      = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'bulk job', array( 'admin' ) );
		$this->key    = $created['key'];
		$this->key_id = $created['id'];

		Mcpwp_Rate_Limiter::get_instance()->update_settings(
			array(
				'enabled'             => true,
				'requests_per_minute' => 100,
				'requests_per_hour'   => 1000,
				'burst_limit'         => 100,
				'whitelist'           => array(),
			)
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
	}

	/**
	 * The bucket the auth layer meters this key in.
	 */
	private function write_bucket(): string {
		return 'key:' . sanitize_key( $this->key_id );
	}

	/**
	 * Put a bucket at its hourly ceiling with the window still open.
	 */
	private function exhaust( string $identifier ): void {
		set_transient(
			'mcpwp_rate_' . md5( $identifier . '_hour' ),
			array( 'count' => 1000, 'reset' => time() + 1800 ),
			1800
		);
	}

	private function mcp_request( array $body ): WP_REST_Request {
		return new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $this->key ),
			$body
		);
	}

	private function rpc( string $method, array $params = array(), $id = 1 ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'method'  => $method,
			'params'  => $params,
		);
	}

	// ---------------------------------------------------------------
	// Diagnostics survive an exhausted write budget.
	// ---------------------------------------------------------------

	public function test_initialize_answers_while_the_write_budget_is_exhausted(): void {
		$this->exhaust( $this->write_bucket() );

		$controller = new Mcpwp_REST_MCP();
		$result     = $controller->verify_api_key( $this->mcp_request( $this->rpc( 'initialize' ) ) );

		$this->assertTrue( $result, 'initialize is how a client learns when to resume; it cannot be behind the wall it is asking about.' );
	}

	public function test_rate_limit_status_and_site_info_answer_while_exhausted(): void {
		$this->exhaust( $this->write_bucket() );

		$controller = new Mcpwp_REST_MCP();

		foreach ( array( 'wp_rate_limit_status', 'wp_site_info' ) as $tool ) {
			$request = $this->mcp_request( $this->rpc( 'tools/call', array( 'name' => $tool ) ) );
			$this->assertTrue( $controller->verify_api_key( $request ), $tool . ' must be reachable while rate-limited.' );
		}
	}

	public function test_an_ordinary_tool_call_is_still_refused_while_exhausted(): void {
		$this->exhaust( $this->write_bucket() );

		$controller = new Mcpwp_REST_MCP();
		$request    = $this->mcp_request( $this->rpc( 'tools/call', array( 'name' => 'wp_create_page' ) ) );
		$result     = $controller->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	public function test_a_batch_that_smuggles_a_write_beside_initialize_is_metered_as_a_write(): void {
		$this->exhaust( $this->write_bucket() );

		$controller = new Mcpwp_REST_MCP();
		$request    = $this->mcp_request(
			array(
				$this->rpc( 'initialize', array(), 1 ),
				$this->rpc( 'tools/call', array( 'name' => 'wp_delete_page' ), 2 ),
			)
		);

		$result = $controller->verify_api_key( $request );

		// Only an all-diagnostic body gets the diagnostic bucket. One write in
		// the batch and the whole request is a write — otherwise the exemption
		// is a bypass.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
	}

	public function test_diagnostics_are_bounded_in_their_own_bucket(): void {
		// Exhaust the diagnostic bucket, not the write bucket.
		$this->exhaust( $this->write_bucket() . ':diag' );

		$controller = new Mcpwp_REST_MCP();
		$result     = $controller->verify_api_key( $this->mcp_request( $this->rpc( 'initialize' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Exempt from the write budget is not exempt from every budget.' );
	}

	public function test_rest_rate_limit_status_route_answers_while_exhausted(): void {
		$this->exhaust( $this->write_bucket() );

		$controller = new Mcpwp_REST_Site_Updates();
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/rate-limit', array(), array( 'X-API-Key' => $this->key ) );

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	// ---------------------------------------------------------------
	// Budget is in band, not only in the 429 prose.
	// ---------------------------------------------------------------

	public function test_initialize_reports_versions_and_budget(): void {
		$controller = new Mcpwp_REST_MCP();
		$controller->verify_api_key( $this->mcp_request( $this->rpc( 'initialize' ) ) );

		$data = $controller->handle_mcp( $this->mcp_request( $this->rpc( 'initialize' ) ) )->get_data();
		$info = $data['result']['serverInfo'];

		$this->assertSame( MCPWP_VERSION, $info['plugin_version'], 'A plugin swapped under a running job must be detectable from initialize.' );
		$this->assertSame( get_bloginfo( 'version' ), $info['wp_version'] );
		$this->assertArrayHasKey( 'site_url', $info );
		$this->assertIsInt( $info['concurrency_hint'] );
		$this->assertGreaterThanOrEqual( 1, $info['concurrency_hint'] );

		$this->assertArrayHasKey( 'rate_limit', $info );
		$this->assertSame( 100, $info['rate_limit']['requests_per_minute'] );
		$this->assertSame( 1000, $info['rate_limit']['requests_per_hour'] );
	}

	public function test_every_result_carries_the_remaining_budget(): void {
		$controller = new Mcpwp_REST_MCP();
		$controller->verify_api_key( $this->mcp_request( $this->rpc( 'tools/list' ) ) );

		$data = $controller->handle_mcp( $this->mcp_request( $this->rpc( 'tools/list' ) ) )->get_data();

		$this->assertArrayHasKey( '_meta', $data['result'] );
		$meta = $data['result']['_meta']['rate_limit'];

		$this->assertSame( 100, $meta['limit'] );
		$this->assertSame( 99, $meta['remaining'], 'One request consumed, so a client can pace itself before the wall.' );
		$this->assertArrayHasKey( 'reset_in', $meta );
		$this->assertGreaterThanOrEqual( 0, $meta['reset_in'] );
		$this->assertContains( $meta['window'], array( 'burst', 'minute', 'hour' ), 'The tightest window is reported; with equal room, any of them is honest.' );
	}

	public function test_every_mcp_response_names_the_plugin_version_in_a_header(): void {
		$controller = new Mcpwp_REST_MCP();
		$response   = $controller->handle_mcp( $this->mcp_request( $this->rpc( 'tools/list' ) ) );

		$this->assertSame( MCPWP_VERSION, $response->get_headers()['X-MCPWP-Version'] );
	}

	public function test_a_429_carries_retry_after_on_the_wire(): void {
		$this->exhaust( $this->write_bucket() );

		$controller = new Mcpwp_REST_MCP();
		$request    = $this->mcp_request( $this->rpc( 'tools/call', array( 'name' => 'wp_create_page' ) ) );
		$error      = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $error );

		// permission_callback errors never pass through success_response(), so
		// the header has to reach the wire via the rest_post_dispatch hook.
		$response = new WP_REST_Response( array( 'code' => $error->get_error_code() ), 429 );
		$loader   = ( new ReflectionClass( Mcpwp_Loader::class ) )->newInstanceWithoutConstructor();
		$response = $loader->add_mcpwp_rate_limit_headers( $response, null, $request );
		$headers  = $response->get_headers();

		$this->assertArrayHasKey( 'Retry-After', $headers );
		$this->assertGreaterThan( 0, (int) $headers['Retry-After'] );
		$this->assertSame( 0, (int) $headers['X-RateLimit-Remaining'] );

		// The 429 path builds its headers from the error data, where reset is
		// an epoch; the 200 path reports seconds-until-reset. Same header, so
		// same unit — seen live: 1789006291 on a 429 beside 60 on a 200.
		$this->assertLessThanOrEqual( 3600, (int) $headers['X-RateLimit-Reset'] );
		$this->assertGreaterThan( 0, (int) $headers['X-RateLimit-Reset'] );
	}

	// ---------------------------------------------------------------
	// No empty 200.
	// ---------------------------------------------------------------

	public function test_a_notifications_only_batch_is_204_not_an_empty_200(): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = $this->mcp_request(
			array(
				array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ),
				array( 'jsonrpc' => '2.0', 'method' => 'notifications/cancelled' ),
			)
		);

		$response = $controller->handle_mcp( $request );

		$this->assertSame( 204, $response->get_status(), 'An empty 200 is indistinguishable from a server that returned nothing.' );
	}

	// ---------------------------------------------------------------
	// Findings from the adversarial review of PR #833.
	// ---------------------------------------------------------------

	public function test_one_request_object_is_charged_once_however_many_times_core_asks(): void {
		// rest_send_allow_header re-runs every handler's permission_callback
		// on the matched route. /mcp has three, so a request was charged
		// three slots — 1000/hour was really ~333. Measured on the rig
		// before this: three tools/list calls left the bucket at count=9.
		$controller = new Mcpwp_REST_MCP();
		$request    = $this->mcp_request( $this->rpc( 'tools/list' ) );

		$this->assertTrue( $controller->verify_api_key( $request ) );
		$this->assertTrue( $controller->verify_api_key( $request ) );
		$this->assertTrue( $controller->verify_api_key( $request ) );

		$this->assertSame( 99, Mcpwp_Rate_Limiter::get_instance()->get_budget()['remaining'] );

		// A different request object is a different request.
		$controller->verify_api_key( $this->mcp_request( $this->rpc( 'tools/list' ) ) );
		$this->assertSame( 98, Mcpwp_Rate_Limiter::get_instance()->get_budget()['remaining'] );
	}

	public function test_the_diagnostic_bucket_is_small_and_absolute_not_a_doubled_copy(): void {
		// A separate bucket at read limits would have doubled every key's
		// ceiling (60 + 120 per minute, 1000 + 1000 per hour). Diagnostics
		// get a fixed small allowance instead.
		$controller = new Mcpwp_REST_MCP();
		$controller->verify_api_key( $this->mcp_request( $this->rpc( 'initialize' ) ) );

		$diag = Mcpwp_Rate_Limiter::get_instance()->get_usage( $this->write_bucket() . ':diag' );
		$this->assertSame( 1, $diag['minute']['used'] );

		set_transient(
			'mcpwp_rate_' . md5( $this->write_bucket() . ':diag_minute' ),
			array( 'count' => 30, 'reset' => time() + 50 ),
			50
		);
		$refused = $controller->verify_api_key( $this->mcp_request( $this->rpc( 'initialize' ) ) );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 30, $refused->get_error_data()['limit'], 'Fixed allowance, not 2× the key\'s own limit.' );
		$this->assertSame( 'diagnostic', $refused->get_error_data()['bucket'] );
	}

	public function test_a_diagnostic_reports_the_write_budget_the_client_is_about_to_spend(): void {
		$controller = new Mcpwp_REST_MCP();

		// Spend three write slots, then ask.
		foreach ( array( 1, 2, 3 ) as $i ) {
			$controller->verify_api_key( $this->mcp_request( $this->rpc( 'tools/list', array(), $i ) ) );
		}
		$request = $this->mcp_request( $this->rpc( 'initialize' ) );
		$controller->verify_api_key( $request );
		$data = $controller->handle_mcp( $request )->get_data();

		$reported = $data['result']['serverInfo']['rate_limit'];
		$this->assertSame( 'write', $reported['bucket'] );
		$this->assertSame( 97, $reported['remaining'], 'The write bucket, not the diagnostic slot just spent.' );
		$this->assertLessThanOrEqual( $reported['requests_per_minute'], $reported['remaining'] );
		$this->assertSame( 'write', $data['result']['_meta']['rate_limit']['bucket'] );

		// And the headers agree with the body.
		$this->assertSame( 97, Mcpwp_Rate_Limiter::get_instance()->get_headers()['X-RateLimit-Remaining'] );
	}

	public function test_oauth_bearer_sessions_get_the_same_carve_out(): void {
		// The claude.ai / ChatGPT connector path authenticates with an OAuth
		// access token and never reaches the API-key branch. It has to be
		// metered by the same rule or the fix misses the main consumer.
		update_option(
			'mcpwp_settings',
			array(
				'oauth_enabled'            => true,
				'oauth_client_id'          => 'site_pilot_ai',
				'oauth_client_secret_hash' => wp_hash_password( 'secret' ),
				'oauth_token_ttl'          => 3600,
			)
		);
		$controller = new Mcpwp_REST_MCP();
		$token      = $controller->issue_oauth_access_token( array( 'admin' ), 3600 )['access_token'];
		$bucket     = 'oauth:' . substr( hash( 'sha256', $token ), 0, 16 );
		$this->exhaust( $bucket );

		$init = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'Authorization' => 'Bearer ' . $token ), $this->rpc( 'initialize' ) );
		$this->assertTrue( $controller->verify_api_key( $init ), 'initialize must answer for an exhausted OAuth session too.' );

		Mcpwp_REST_MCP::reset_verdict_memo();
		$write = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'Authorization' => 'Bearer ' . $token ), $this->rpc( 'tools/call', array( 'name' => 'wp_create_page' ) ) );
		$this->assertInstanceOf( WP_Error::class, $controller->verify_api_key( $write ) );
	}

	public function test_a_whitelisted_key_is_whitelisted_for_its_diagnostics_too(): void {
		Mcpwp_Rate_Limiter::get_instance()->update_settings( array( 'whitelist' => array( $this->write_bucket() ) ) );
		$this->exhaust( $this->write_bucket() . ':diag' );

		$this->assertTrue( ( new Mcpwp_REST_MCP() )->verify_api_key( $this->mcp_request( $this->rpc( 'initialize' ) ) ) );
	}

	public function test_a_write_to_the_rate_limit_route_is_not_a_diagnostic(): void {
		$this->exhaust( $this->write_bucket() );

		$controller = new Mcpwp_REST_Site_Updates();
		$request    = new WP_REST_Request( 'PUT', '/mcpwp/v1/rate-limit', array( 'enabled' => false ), array( 'X-API-Key' => $this->key ) );

		// PUT /rate-limit rewrites the site's limits and whitelist. Reaching
		// it from the exhaustion-immune bucket would be a total bypass.
		$this->assertInstanceOf( WP_Error::class, $controller->verify_api_key( $request ) );
	}

	public function test_every_diagnostic_tool_is_a_read_in_the_tool_map(): void {
		$map = ( new ReflectionMethod( Mcpwp_REST_MCP::class, 'get_all_tool_map' ) );
		$map->setAccessible( true );
		$tool_map = $map->invoke( new Mcpwp_REST_MCP() );

		foreach ( Mcpwp_REST_MCP::diagnostic_tools() as $tool ) {
			$this->assertArrayHasKey( $tool, $tool_map );
			$this->assertSame( 'GET', strtoupper( $tool_map[ $tool ]['method'] ), $tool . ' is metered as a diagnostic and must not be able to write.' );
		}

		$this->assertSame( array( 'initialize', 'ping' ), Mcpwp_REST_MCP::diagnostic_methods() );
	}

	public function test_a_write_first_batch_is_metered_as_a_write(): void {
		$this->exhaust( $this->write_bucket() );

		$request = $this->mcp_request(
			array(
				$this->rpc( 'tools/call', array( 'name' => 'wp_delete_page', 'arguments' => array( 'id' => 42 ) ), 1 ),
				$this->rpc( 'initialize', array(), 2 ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, ( new Mcpwp_REST_MCP() )->verify_api_key( $request ), 'Order must not matter.' );
	}

	public function test_a_read_method_that_is_not_listed_is_still_a_write_bucket_call(): void {
		$this->exhaust( $this->write_bucket() );

		foreach ( array( 'tools/list', 'resources/list', 'prompts/list' ) as $method ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
			$this->assertInstanceOf( WP_Error::class, ( new Mcpwp_REST_MCP() )->verify_api_key( $this->mcp_request( $this->rpc( $method ) ) ), $method );
		}
	}

	public function test_non_admin_keys_do_not_learn_the_site_posture(): void {
		$reader = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'reader', array( 'read' ), 'author' );

		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $reader['key'] ), $this->rpc( 'initialize' ) );
		$controller->verify_api_key( $request );
		$info = $controller->handle_mcp( $request )->get_data()['result']['serverInfo'];

		$this->assertArrayNotHasKey( 'wp_version', $info );
		$this->assertArrayNotHasKey( 'site_url', $info );
		$this->assertArrayNotHasKey( 'enabled', $info['rate_limit'], 'Whether the throttle is on is exactly what a scraper wants to know.' );
		$this->assertArrayNotHasKey( 'requests_per_hour', $info['rate_limit'] );
		$this->assertArrayHasKey( 'remaining', $info['rate_limit'], 'Its own budget it may have.' );
	}

	public function test_options_carries_no_version_header(): void {
		$response = ( new Mcpwp_REST_MCP() )->handle_options( new WP_REST_Request( 'OPTIONS', '/mcpwp/v1/mcp' ) );

		$this->assertArrayNotHasKey( 'X-MCPWP-Version', $response->get_headers(), 'OPTIONS is open; an exact version there is a scanner oracle.' );
	}

	public function test_a_429_body_carries_seconds_until_reset_beside_the_epoch(): void {
		$this->exhaust( $this->write_bucket() );

		$error = ( new Mcpwp_REST_MCP() )->verify_api_key( $this->mcp_request( $this->rpc( 'tools/list' ) ) );
		$data  = $error->get_error_data();

		$this->assertArrayHasKey( 'reset_in', $data );
		$this->assertLessThanOrEqual( 3600, $data['reset_in'] );
		$this->assertSame( 'write', $data['bucket'] );
	}

	public function test_a_429_names_its_bucket_in_the_header_too(): void {
		$this->exhaust( $this->write_bucket() );

		$controller = new Mcpwp_REST_MCP();
		$request    = $this->mcp_request( $this->rpc( 'tools/list' ) );
		$error      = $controller->verify_api_key( $request );

		$response = new WP_REST_Response( array( 'code' => 'rate_limit_exceeded', 'data' => $error->get_error_data() ), 429 );
		$loader   = ( new ReflectionClass( Mcpwp_Loader::class ) )->newInstanceWithoutConstructor();
		$headers  = $loader->add_mcpwp_rate_limit_headers( $response, null, $request )->get_headers();

		$this->assertSame( 'write', $headers['X-RateLimit-Bucket'] );
	}

	public function test_a_key_throttled_below_the_diagnostic_allowance_keeps_its_own_ceiling(): void {
		$tight = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'tight', array( 'admin' ) );
		$keys  = get_option( 'mcpwp_api_keys', array() );
		foreach ( $keys as &$record ) {
			if ( $record['id'] === $tight['id'] ) {
				$record['rate_limits'] = array( 'per_minute' => 5, 'per_hour' => 50, 'burst' => 5 );
			}
		}
		unset( $record );
		update_option( 'mcpwp_api_keys', $keys );

		set_transient( 'mcpwp_rate_' . md5( 'key:' . sanitize_key( $tight['id'] ) . ':diag_hour' ), array( 'count' => 50, 'reset' => time() + 1800 ), 1800 );

		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $tight['key'] ), $this->rpc( 'initialize' ) );
		$refused    = $controller->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $refused, 'The allowance is a floor for exhausted keys, not a lift above a deliberate throttle.' );
		$this->assertSame( 50, $refused->get_error_data()['limit'] );
	}

	public function test_the_site_ceiling_also_bounds_the_diagnostic_allowance(): void {
		Mcpwp_Rate_Limiter::get_instance()->update_settings( array( 'burst_limit' => 5, 'requests_per_minute' => 5, 'requests_per_hour' => 50 ) );
		set_transient( 'mcpwp_rate_' . md5( $this->write_bucket() . ':diag_hour' ), array( 'count' => 50, 'reset' => time() + 1800 ), 1800 );

		$refused = ( new Mcpwp_REST_MCP() )->verify_api_key( $this->mcp_request( $this->rpc( 'initialize' ) ) );

		$this->assertInstanceOf( WP_Error::class, $refused, 'A site throttled to 50/hour does not hand diagnostics 300/hour.' );
		$this->assertSame( 50, $refused->get_error_data()['limit'] );
	}

	public function test_headers_name_the_window_they_describe(): void {
		$controller = new Mcpwp_REST_MCP();
		$controller->verify_api_key( $this->mcp_request( $this->rpc( 'tools/list' ) ) );

		$this->assertArrayHasKey( 'X-RateLimit-Window', Mcpwp_Rate_Limiter::get_instance()->get_headers() );
	}

	public function test_a_non_string_method_is_a_jsonrpc_error_not_a_fatal(): void {
		$data = ( new Mcpwp_REST_MCP() )->handle_mcp( $this->mcp_request( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => array( 'x' ) ) ) )->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32601, $data['error']['code'] );
	}

	// ---------------------------------------------------------------
	// Round 4: the memo carries its side effects; the harness guards.
	// ---------------------------------------------------------------

	public function test_a_memo_hit_on_another_instance_carries_the_principal_and_the_metered_bucket(): void {
		// Round-4 P2-3: a hit returned the cached boolean only, so a second
		// controller instance saw no key record — no tool-category
		// restriction, no principal scope, no nested charging.
		$request = $this->mcp_request( $this->rpc( 'tools/list' ) );
		$a = new Mcpwp_REST_MCP();
		$this->assertTrue( $a->verify_api_key( $request ) );
		$this->assertNotNull( $a->get_current_api_key_record() );

		$b = new Mcpwp_REST_MCP();
		$this->assertTrue( $b->verify_api_key( $request ) );
		$this->assertSame( $a->get_current_api_key_record()['id'], $b->get_current_api_key_record()['id'] );

		$metered = new ReflectionProperty( Mcpwp_REST_API::class, 'mcpwp_metered' );
		$metered->setAccessible( true );
		$this->assertSame( $metered->getValue( $a ), $metered->getValue( $b ) );
		$this->assertIsArray( $metered->getValue( $b ) );
	}

	public function test_a_refused_request_leaves_no_metered_bucket_behind(): void {
		// Round-4 M7: in a persistent process a later nested charge landed
		// on the previous request's bucket.
		$controller = new Mcpwp_REST_MCP();
		$this->assertTrue( $controller->verify_api_key( $this->mcp_request( $this->rpc( 'tools/list' ) ) ) );
		$metered = new ReflectionProperty( Mcpwp_REST_API::class, 'mcpwp_metered' );
		$metered->setAccessible( true );
		$this->assertIsArray( $metered->getValue( $controller ) );

		$anonymous = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array(), $this->rpc( 'tools/list' ) );
		$this->assertInstanceOf( WP_Error::class, $controller->verify_api_key( $anonymous ) );
		$this->assertNull( $metered->getValue( $controller ), 'The refused request cleared it.' );

		$charge = new ReflectionMethod( Mcpwp_REST_API::class, 'charge_additional_slots' );
		$charge->setAccessible( true );
		$before = Mcpwp_Rate_Limiter::get_instance()->get_budget();
		$this->assertTrue( $charge->invoke( $controller, 5 ) );
		$this->assertSame( $before, Mcpwp_Rate_Limiter::get_instance()->get_budget(), 'Nothing was charged to the earlier bucket.' );
	}

	public function test_the_memo_is_bounded_and_evicts_the_oldest_without_renumbering(): void {
		// Round-4 M8/M16: array_shift() renumbers object-id keys; a hit must
		// be for the SAME request object, never a reused id.
		$verdicts = new ReflectionProperty( Mcpwp_REST_API::class, 'mcpwp_verdicts' );
		$verdicts->setAccessible( true );
		Mcpwp_REST_MCP::reset_verdict_memo();

		$controller = new Mcpwp_REST_MCP();
		$held       = array();
		for ( $i = 0; $i < 70; $i++ ) {
			$held[] = $this->mcp_request( $this->rpc( 'tools/list', array(), $i ) );
			$controller->verify_api_key( end( $held ) );
		}
		$memo = $verdicts->getValue();
		$this->assertCount( 64, $memo );
		foreach ( $memo as $id => $entry ) {
			$this->assertSame( spl_object_id( $entry[0] ), $id, 'Keys are the object ids, not renumbered indexes.' );
		}
		$this->assertArrayNotHasKey( spl_object_id( $held[0] ), $memo, 'The oldest was evicted.' );
		$this->assertArrayHasKey( spl_object_id( $held[69] ), $memo );

		// Two distinct request objects are two verdicts (two slots), one object is one.
		$GLOBALS['mcpwp_test_transients'] = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
		$r1 = $this->mcp_request( $this->rpc( 'tools/list' ) );
		$r2 = $this->mcp_request( $this->rpc( 'tools/list' ) );
		$controller->verify_api_key( $r1 );
		$before = Mcpwp_Rate_Limiter::get_instance()->get_budget()['remaining'];
		$controller->verify_api_key( $r1 );
		$this->assertSame( $before, Mcpwp_Rate_Limiter::get_instance()->get_budget()['remaining'], 'The repeat on the same object was free.' );
		$controller->verify_api_key( $r2 );
		$this->assertSame( $before - 1, Mcpwp_Rate_Limiter::get_instance()->get_budget()['remaining'], 'A different object is a different verdict.' );
	}

	public function test_a_429_names_its_window_in_the_header_too(): void {
		// Round-4 M10.
		$this->exhaust( $this->write_bucket() );
		$controller = new Mcpwp_REST_MCP();
		$request    = $this->mcp_request( $this->rpc( 'tools/list' ) );
		$error      = $controller->verify_api_key( $request );

		$response = new WP_REST_Response( array( 'code' => 'rate_limit_exceeded', 'data' => $error->get_error_data() ), 429 );
		$loader   = ( new ReflectionClass( Mcpwp_Loader::class ) )->newInstanceWithoutConstructor();
		$headers  = $loader->add_mcpwp_rate_limit_headers( $response, null, $request )->get_headers();

		$this->assertSame( 'hour', $headers['X-RateLimit-Window'] );
	}
}
