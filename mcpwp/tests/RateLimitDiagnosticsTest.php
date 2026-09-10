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

		$created      = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'bulk job', array( 'admin' ) );
		$this->key    = $created['key'];
		$this->key_id = $created['id'];

		Mcpwp_Rate_Limiter::get_instance()->update_settings(
			array(
				'enabled'             => true,
				'requests_per_minute' => 100,
				'requests_per_hour'   => 1000,
				'burst_limit'         => 50,
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
		$this->assertSame( 'minute', $meta['window'] );
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
}
