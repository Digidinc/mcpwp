<?php
/**
 * Coverage for #802 — writes are not idempotent, so a retry after a lost
 * response duplicates work.
 *
 * From the report: a 429 landed mid-migration; on resume, five video
 * testimonials were created twice and two records lost their terms, because
 * the client could not tell "the write never happened" from "the write
 * happened and the response was lost", and re-sent.
 *
 * `params._meta.request_id` on tools/call makes the second case safe: the
 * first completed result for (api key, request_id) is stored, and a re-send
 * with the same id returns it without dispatching again, marked
 * `_meta.replayed`. Rules pinned here:
 *
 *   - Only completed results are pinned. A call that errored is not — the
 *     retry must be allowed to do the work.
 *   - The same id with different arguments is refused, not replayed: that is
 *     a client bug and returning a stale result for it would hide it.
 *   - Ids are scoped to the API key. Two keys never see each other's results.
 *   - Read tools are not pinned; nothing to protect and results go stale.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class IdempotentToolCallTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
	}

	private function request( string $tool, array $arguments, $request_id = null, $rpc_id = 7 ): WP_REST_Request {
		$params = array(
			'name'      => $tool,
			'arguments' => $arguments,
		);

		if ( null !== $request_id ) {
			$params['_meta'] = array( 'request_id' => $request_id );
		}

		return new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => $rpc_id,
				'method'  => 'tools/call',
				'params'  => $params,
			)
		);
	}

	private function call( Mcpwp_REST_MCP $controller, string $tool, array $arguments, $request_id = null ): array {
		return $controller->handle_mcp( $this->request( $tool, $arguments, $request_id ) )->get_data();
	}

	private function dispatches(): int {
		return count( $GLOBALS['mcpwp_test_dispatched_routes'] );
	}

	// ---------------------------------------------------------------
	// Replay.
	// ---------------------------------------------------------------

	public function test_a_resend_with_the_same_request_id_does_not_dispatch_again(): void {
		$controller = new Mcpwp_REST_MCP();

		$first = $this->call( $controller, 'wp_create_page', array( 'title' => 'Testimonial 3' ), 'job-42:page:3' );
		$this->assertArrayHasKey( 'result', $first );
		$this->assertSame( 1, $this->dispatches() );

		$second = $this->call( $controller, 'wp_create_page', array( 'title' => 'Testimonial 3' ), 'job-42:page:3' );

		$this->assertSame( 1, $this->dispatches(), 'The whole point: the write ran once.' );
		$this->assertSame( $first['result']['content'], $second['result']['content'] );
		$this->assertTrue( $second['result']['_meta']['replayed'] );
		$this->assertArrayNotHasKey( 'replayed', $first['result']['_meta'] ?? array() );
	}

	public function test_a_different_request_id_dispatches_again(): void {
		$controller = new Mcpwp_REST_MCP();

		$this->call( $controller, 'wp_create_page', array( 'title' => 'A' ), 'job-42:page:1' );
		$this->call( $controller, 'wp_create_page', array( 'title' => 'B' ), 'job-42:page:2' );

		$this->assertSame( 2, $this->dispatches() );
	}

	public function test_no_request_id_means_no_pinning(): void {
		$controller = new Mcpwp_REST_MCP();

		$this->call( $controller, 'wp_create_page', array( 'title' => 'A' ) );
		$this->call( $controller, 'wp_create_page', array( 'title' => 'A' ) );

		$this->assertSame( 2, $this->dispatches(), 'Opt-in. Existing clients keep their exact behaviour.' );
	}

	public function test_the_replay_survives_a_new_controller_instance(): void {
		// A retry arrives on a fresh PHP request. The record has to live in
		// storage, not on the object.
		$this->call( new Mcpwp_REST_MCP(), 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$second = $this->call( new Mcpwp_REST_MCP(), 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		$this->assertSame( 1, $this->dispatches() );
		$this->assertTrue( $second['result']['_meta']['replayed'] );
	}

	// ---------------------------------------------------------------
	// What is refused rather than replayed.
	// ---------------------------------------------------------------

	public function test_the_same_id_with_different_arguments_is_refused(): void {
		$controller = new Mcpwp_REST_MCP();

		$this->call( $controller, 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$second = $this->call( $controller, 'wp_create_page', array( 'title' => 'B' ), 'r1' );

		$this->assertArrayHasKey( 'error', $second );
		$this->assertSame( -32602, $second['error']['code'] );
		$this->assertStringContainsString( 'request_id', $second['error']['message'] );
		$this->assertSame( 1, $this->dispatches(), 'Neither replayed nor re-run: a reused id with new arguments is a client bug and is said so.' );
	}

	public function test_a_call_still_in_flight_is_reported_not_duplicated(): void {
		$controller = new Mcpwp_REST_MCP();

		// The first attempt is mid-execution on another PHP worker.
		set_transient(
			Mcpwp_REST_MCP::idempotency_key( 'anon', 'r1' ),
			array( 'state' => 'in_progress', 'at' => time() ),
			60
		);

		$data = $this->call( $controller, 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32009, $data['error']['code'] );
		$this->assertSame( 0, $this->dispatches() );
	}

	public function test_a_failed_call_is_not_pinned_so_the_retry_can_do_the_work(): void {
		$controller = new Mcpwp_REST_MCP();

		update_option( 'mcpwp_disabled_tool_categories', array( 'content' ) );
		$failed = $this->call( $controller, 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$this->assertArrayHasKey( 'error', $failed );

		delete_option( 'mcpwp_disabled_tool_categories' );
		$retry = $this->call( $controller, 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		$this->assertArrayHasKey( 'result', $retry );
		$this->assertSame( 1, $this->dispatches() );
		$this->assertArrayNotHasKey( 'replayed', $retry['result']['_meta'] ?? array() );
	}

	public function test_an_unusable_request_id_is_refused_up_front(): void {
		$controller = new Mcpwp_REST_MCP();

		foreach ( array( str_repeat( 'a', 129 ), 'has space', "nul\0byte", '' ) as $bad ) {
			$data = $this->call( $controller, 'wp_create_page', array( 'title' => 'A' ), $bad );
			$this->assertArrayHasKey( 'error', $data, var_export( $bad, true ) );
			$this->assertSame( -32602, $data['error']['code'] );
		}

		$this->assertSame( 0, $this->dispatches() );
	}

	// ---------------------------------------------------------------
	// Scope.
	// ---------------------------------------------------------------

	public function test_ids_are_scoped_to_the_api_key(): void {
		$a = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'lane a', array( 'admin' ) );
		$b = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'lane b', array( 'admin' ) );

		foreach ( array( $a, $b ) as $key ) {
			$controller = new Mcpwp_REST_MCP();
			$request    = $this->request( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
			$request->set_header( 'X-API-Key', $key['key'] );
			$this->assertTrue( $controller->verify_api_key( $request ) );
			$controller->handle_mcp( $request );
		}

		$this->assertSame( 2, $this->dispatches(), 'Lane B must never receive lane A\'s result.' );
	}

	public function test_read_tools_are_not_pinned(): void {
		$controller = new Mcpwp_REST_MCP();

		$this->call( $controller, 'wp_site_info', array(), 'r1' );
		$this->call( $controller, 'wp_site_info', array(), 'r1' );

		$this->assertSame( 2, $this->dispatches(), 'Nothing to protect on a read, and a pinned read goes stale.' );
	}
}
