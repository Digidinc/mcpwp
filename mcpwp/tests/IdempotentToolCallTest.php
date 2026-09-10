<?php
/**
 * Coverage for #802 — writes are not idempotent, so a retry after a lost
 * response duplicates work.
 *
 * From the report: a 429 landed mid-migration; on resume, five video
 * testimonials were created twice because the client could not tell "the
 * write never happened" from "the write happened and the response was lost",
 * and re-sent.
 *
 * `params._meta.request_id` on tools/call makes the second case safe: the
 * first completed result for (principal, request_id) is stored, and a re-send
 * with the same id returns it without dispatching again, marked
 * `_meta.replayed`.
 *
 * The adversarial review of the first cut found the pinning decision was made
 * from the SCOPE table (a privilege regex), which silently left 51 mutating
 * tools unpinned — every wc_* write, wp_clone_page, wp_patch_elementor — and
 * pinned 17 admin-scoped reads for a day. It is now made from the tool map's
 * own HTTP method. The other rules pinned here:
 *
 *   - Only completed results are pinned. A failed call, and a 200 that
 *     carries its own failure list, are not — the retry must be allowed to
 *     do the work.
 *   - The same id with different arguments is refused, not replayed.
 *   - Two concurrent identical retries: exactly one runs (atomic claim).
 *   - A claim left behind by a fatal expires; it does not lock the id for a day.
 *   - Ids are scoped to the principal. No principal, no pinning — and the
 *     response says so rather than letting the client assume protection.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class IdempotentToolCallTest extends TestCase {

	/** @var array */
	private $key;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		$GLOBALS['mcpwp_test_filters']           = array();
		Mcpwp_REST_MCP::reset_verdict_memo();

		$this->key = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'lane a', array( 'admin' ) );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']                = array();
		$GLOBALS['mcpwp_test_transients']             = array();
		$GLOBALS['mcpwp_test_dispatched_routes']      = array();
		$GLOBALS['mcpwp_test_transient_write_fails']  = false;
	}

	private function request( string $tool, array $arguments, $request_id = null, $rpc_id = 7, $key = null ): WP_REST_Request {
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
			array( 'X-API-Key' => $key ?: $this->key['key'] ),
			array(
				'jsonrpc' => '2.0',
				'id'      => $rpc_id,
				'method'  => 'tools/call',
				'params'  => $params,
			)
		);
	}

	/**
	 * Authenticate (so a principal exists) and dispatch, like the REST layer does.
	 */
	private function call( string $tool, array $arguments, $request_id = null, $rpc_id = 7, $key = null ): array {
		$controller = new Mcpwp_REST_MCP();
		$request    = $this->request( $tool, $arguments, $request_id, $rpc_id, $key );
		$verdict    = $controller->verify_api_key( $request );
		$this->assertTrue( $verdict, 'Test premise: the key authenticates.' );

		return $controller->handle_mcp( $request )->get_data();
	}

	private function dispatches(): int {
		return count( $GLOBALS['mcpwp_test_dispatched_routes'] );
	}

	private function store_key( string $request_id ): string {
		return Mcpwp_REST_MCP::idempotency_key( $this->key['id'], $request_id );
	}

	// ---------------------------------------------------------------
	// Replay.
	// ---------------------------------------------------------------

	public function test_a_resend_with_the_same_request_id_does_not_dispatch_again(): void {
		$first = $this->call( 'wp_create_page', array( 'title' => 'Testimonial 3' ), 'job-42:page:3', 1 );
		$this->assertArrayHasKey( 'result', $first );
		$this->assertSame( 'stored', $first['result']['_meta']['idempotency'] );
		$this->assertSame( 1, $this->dispatches() );

		$second = $this->call( 'wp_create_page', array( 'title' => 'Testimonial 3' ), 'job-42:page:3', 2 );

		$this->assertSame( 1, $this->dispatches(), 'The whole point: the write ran once.' );
		$this->assertSame( $first['result']['content'], $second['result']['content'] );
		$this->assertTrue( $second['result']['_meta']['replayed'] );
		$this->assertSame( 2, $second['id'], 'The replay answers THIS request, not the one it remembers.' );
		$this->assertArrayNotHasKey( 'replayed', $first['result']['_meta'] );
	}

	public function test_every_tool_with_side_effects_is_pinned_not_only_the_ones_matching_a_name_regex(): void {
		// The first cut decided from the scope regex /^wp_(create|update|…)/,
		// which none of these match. All of them write.
		foreach ( array( 'wp_clone_page', 'wp_patch_elementor', 'wp_apply_elementor_part', 'wp_add_section', 'wp_remember' ) as $tool ) {
			$GLOBALS['mcpwp_test_dispatched_routes'] = array();
			Mcpwp_REST_MCP::reset_verdict_memo();

			$this->call( $tool, array( 'id' => 5, 'page_id' => 5, 'key' => 'k', 'value' => 'v', 'template_id' => 1, 'elements' => array(), 'operations' => array() ), 'r-' . $tool );
			$this->call( $tool, array( 'id' => 5, 'page_id' => 5, 'key' => 'k', 'value' => 'v', 'template_id' => 1, 'elements' => array(), 'operations' => array() ), 'r-' . $tool );

			$this->assertLessThanOrEqual( 1, $this->dispatches(), $tool . ' has side effects and must be pinned.' );
		}
	}

	public function test_a_different_request_id_dispatches_again(): void {
		$this->call( 'wp_create_page', array( 'title' => 'A' ), 'job-42:page:1' );
		$this->call( 'wp_create_page', array( 'title' => 'B' ), 'job-42:page:2' );

		$this->assertSame( 2, $this->dispatches() );
	}

	public function test_no_request_id_means_no_pinning_and_no_note(): void {
		$a = $this->call( 'wp_create_page', array( 'title' => 'A' ) );
		$this->call( 'wp_create_page', array( 'title' => 'A' ) );

		$this->assertSame( 2, $this->dispatches(), 'Opt-in. Existing clients keep their exact behaviour.' );
		$this->assertArrayNotHasKey( 'idempotency', $a['result']['_meta'] ?? array() );
	}

	public function test_argument_key_order_does_not_change_the_identity_of_a_call(): void {
		$this->call( 'wp_create_page', array( 'title' => 'A', 'status' => 'draft' ), 'r1' );
		$second = $this->call( 'wp_create_page', array( 'status' => 'draft', 'title' => 'A' ), 'r1' );

		// A retry re-serialised by another SDK must not be accused of changing
		// its arguments.
		$this->assertArrayHasKey( 'result', $second );
		$this->assertTrue( $second['result']['_meta']['replayed'] );
	}

	public function test_the_record_is_kept_for_a_day(): void {
		$this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		$this->assertSame( DAY_IN_SECONDS, $GLOBALS['mcpwp_test_transients'][ $this->store_key( 'r1' ) ]['ttl'] );
	}

	// ---------------------------------------------------------------
	// What is not pinned, and says so.
	// ---------------------------------------------------------------

	public function test_read_tools_are_not_pinned_even_when_admin_scoped(): void {
		// wp_list_api_keys is admin-scoped and a GET. Pinning it would serve
		// a revoked key as healthy for a day.
		foreach ( array( 'wp_site_info', 'wp_list_api_keys' ) as $tool ) {
			$GLOBALS['mcpwp_test_dispatched_routes'] = array();
			Mcpwp_REST_MCP::reset_verdict_memo();

			$first = $this->call( $tool, array(), 'r-' . $tool );
			$this->call( $tool, array(), 'r-' . $tool );

			$this->assertSame( 2, $this->dispatches(), $tool );
			$this->assertSame( 'not_applicable', $first['result']['_meta']['idempotency'] );
		}
	}

	public function test_a_failed_call_is_not_pinned_so_the_retry_can_do_the_work(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'content' ) );
		$failed = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$this->assertArrayHasKey( 'error', $failed );

		delete_option( 'mcpwp_disabled_tool_categories' );
		Mcpwp_REST_MCP::reset_verdict_memo();
		$retry = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		$this->assertArrayHasKey( 'result', $retry );
		$this->assertSame( 1, $this->dispatches() );
		$this->assertArrayNotHasKey( 'replayed', $retry['result']['_meta'] );
	}

	public function test_a_partial_success_is_not_pinned(): void {
		// A bulk create that answered 200 with errors[] is not done. Pinning
		// it turns the reporter's duplicate into a silent drop of the failed
		// items on every retry.
		$GLOBALS['mcpwp_test_rest_response_override'] = array(
			'created' => array( 1, 2, 3 ),
			'errors'  => array( array( 'index' => 3, 'error' => 'title required' ) ),
		);

		$first = $this->call( 'wp_bulk_create_pages', array( 'pages' => array() ), 'r1' );
		unset( $GLOBALS['mcpwp_test_rest_response_override'] );

		$this->assertSame( 'not_stored', $first['result']['_meta']['idempotency'] );

		$this->call( 'wp_bulk_create_pages', array( 'pages' => array() ), 'r1' );
		$this->assertSame( 2, $this->dispatches(), 'The retry runs.' );
	}

	public function test_a_result_too_large_to_pin_says_so(): void {
		$GLOBALS['mcpwp_test_rest_response_override'] = array( 'blob' => str_repeat( 'x', Mcpwp_REST_MCP::IDEMPOTENCY_MAX_BYTES + 1 ) );

		$first = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		unset( $GLOBALS['mcpwp_test_rest_response_override'] );

		$this->assertSame( 'not_stored', $first['result']['_meta']['idempotency'] );
		$this->assertFalse( get_transient( $this->store_key( 'r1' ) ) );
		$this->assertFalse( get_option( $this->store_key( 'r1' ) . '_lease' ), 'No claim left behind.' );
	}

	public function test_a_storage_write_failure_is_reported_not_hidden(): void {
		$store_key                                    = $this->store_key( 'r1' );
		$GLOBALS['mcpwp_test_transient_write_fails'] = function ( $name ) use ( $store_key ) {
			return $name === $store_key;
		};

		$first = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		$this->assertArrayHasKey( 'result', $first );
		$this->assertSame( 'not_stored', $first['result']['_meta']['idempotency'], 'A client that believes it is protected and is not will retry into a duplicate.' );
	}

	public function test_no_principal_means_no_pinning_and_the_response_says_so(): void {
		// The admin chat path authenticates without a key record. There is no
		// principal to scope the id to, and 'anon' would be a namespace shared
		// with every id-less legacy key.
		$controller = new Mcpwp_REST_MCP();
		$request    = $this->request( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$data       = $controller->handle_mcp( $request )->get_data();

		$this->assertSame( 'not_stored', $data['result']['_meta']['idempotency'] );
		$this->assertFalse( get_transient( Mcpwp_REST_MCP::idempotency_key( 'anon', 'r1' ) ) );
	}

	// ---------------------------------------------------------------
	// Refusals.
	// ---------------------------------------------------------------

	public function test_the_same_id_with_different_arguments_is_refused(): void {
		$this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$second = $this->call( 'wp_create_page', array( 'title' => 'B' ), 'r1' );

		$this->assertArrayHasKey( 'error', $second );
		$this->assertSame( -32602, $second['error']['code'] );
		$this->assertStringContainsString( 'request_id', $second['error']['message'] );
		$this->assertSame( 1, $this->dispatches() );
	}

	public function test_an_unusable_request_id_is_refused_up_front(): void {
		foreach ( array( str_repeat( 'a', 129 ), 'has space', "nul\0byte", '' ) as $bad ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
			$data = $this->call( 'wp_create_page', array( 'title' => 'A' ), $bad );
			$this->assertArrayHasKey( 'error', $data, var_export( $bad, true ) );
			$this->assertSame( -32602, $data['error']['code'] );
		}

		$this->assertSame( 0, $this->dispatches() );
	}

	// ---------------------------------------------------------------
	// Concurrency and abandoned claims.
	// ---------------------------------------------------------------

	public function test_two_concurrent_identical_retries_run_exactly_once(): void {
		// The first attempt is mid-execution on another worker: it holds the
		// claim, nothing is stored yet. add_option() is the atomic primitive.
		add_option( $this->store_key( 'r1' ) . '_lease', array( 'at' => time() ), '', 'no' );

		$data = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32009, $data['error']['code'] );
		$this->assertGreaterThan( 0, $data['error']['data']['retry_after'] );
		$this->assertSame( 0, $this->dispatches() );
	}

	public function test_a_claim_abandoned_by_a_fatal_expires_instead_of_locking_the_id_for_a_day(): void {
		add_option(
			$this->store_key( 'r1' ) . '_lease',
			array( 'at' => time() - Mcpwp_REST_MCP::IDEMPOTENCY_LEASE_SECONDS - 1 ),
			'',
			'no'
		);

		$data = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		$this->assertArrayHasKey( 'result', $data, 'The retry is allowed to do the work.' );
		$this->assertSame( 1, $this->dispatches() );
	}

	public function test_the_claim_is_released_after_the_call_whatever_happened(): void {
		$this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$this->assertFalse( get_option( $this->store_key( 'r1' ) . '_lease' ) );

		update_option( 'mcpwp_disabled_tool_categories', array( 'content' ) );
		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->call( 'wp_create_page', array( 'title' => 'B' ), 'r2' );
		$this->assertFalse( get_option( $this->store_key( 'r2' ) . '_lease' ) );
	}

	// ---------------------------------------------------------------
	// Scope and gates.
	// ---------------------------------------------------------------

	public function test_ids_are_scoped_to_the_principal(): void {
		$b = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'lane b', array( 'admin' ) );

		$this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1', 7, $b['key'] );

		$this->assertSame( 2, $this->dispatches(), 'Lane B must never receive lane A\'s result.' );
	}

	public function test_a_replay_re_checks_the_gates_the_handler_would_have_applied(): void {
		$this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		// The category is disabled after the first call. The old result must
		// not keep being served around the switch.
		update_option( 'mcpwp_disabled_tool_categories', array( 'content' ) );
		Mcpwp_REST_MCP::reset_verdict_memo();
		$data = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32003, $data['error']['code'] );
	}

	public function test_a_replay_is_visible_in_the_activity_log(): void {
		$seen = array();
		add_action(
			'mcpwp_tool_called',
			function ( $tool ) use ( &$seen ) {
				$seen[] = $tool;
			},
			10,
			1
		);

		$this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		$this->assertSame( array( 'wp_create_page', 'wp_create_page' ), $seen, 'N re-sends are N API calls in the audit trail, even though the site changed once.' );
	}
}
