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
		$GLOBALS['mcpwp_test_wpdb_options']      = array();
		$GLOBALS['mcpwp_test_wpdb_insert_fails'] = false;
		Mcpwp_REST_MCP::reset_verdict_memo();

		$this->key = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'lane a', array( 'admin' ) );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']                = array();
		$GLOBALS['mcpwp_test_transients']             = array();
		$GLOBALS['mcpwp_test_dispatched_routes']      = array();
		$GLOBALS['mcpwp_test_transient_write_fails']  = false;
		// setUp resets this too, but only for this class. Several tests switch
		// inserts off and one of them may run last, which leaves every later
		// class unable to insert an option row — including the encryption key,
		// which is created with a plain $wpdb->insert (#564). Under
		// --order-by=random that surfaced as broker/mupot encryption failures
		// far from here (#757 pattern: a leaked sabotage switch).
		$GLOBALS['mcpwp_test_wpdb_insert_fails']      = false;
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
		$this->assertArrayNotHasKey( 'error', $failed );
		$this->assertTrue( $failed['result']['isError'] );

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

		// wp_create_page resolved to a real tool; the conflict is a refusal to
		// run it, not a malformed request — isError result, not protocol error.
		$this->assertArrayNotHasKey( 'error', $second );
		$this->assertTrue( $second['result']['isError'] );
		$this->assertSame( 'idempotency_conflict', $second['result']['structuredContent']['error']['code'] );
		$this->assertStringContainsString( 'request_id', $second['result']['structuredContent']['error']['message'] );
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

	public function test_the_claim_primitive_is_the_unique_index_not_add_option(): void {
		// add_option() is a cached read-then-write over INSERT ... ON
		// DUPLICATE KEY UPDATE: two concurrent add_option() calls BOTH return
		// true. The first cut used it and shipped a race that the harness
		// double had encoded as correct. A plain INSERT is arbitrated by the
		// UNIQUE index: exactly one of two wins.
		$this->assertSame( Mcpwp_Idempotency_Claim::ACQUIRED, Mcpwp_Idempotency_Claim::acquire( 'mcpwp_idem_x_lease', time(), 'h' ) );
		$this->assertSame( Mcpwp_Idempotency_Claim::HELD, Mcpwp_Idempotency_Claim::acquire( 'mcpwp_idem_x_lease', time(), 'h' ) );

		Mcpwp_Idempotency_Claim::release( 'mcpwp_idem_x_lease' );
		$this->assertSame( Mcpwp_Idempotency_Claim::ACQUIRED, Mcpwp_Idempotency_Claim::acquire( 'mcpwp_idem_x_lease', time(), 'h' ) );
	}

	public function test_a_stale_claim_is_taken_over_by_compare_and_swap_so_only_one_retry_wins(): void {
		Mcpwp_Idempotency_Claim::acquire( 'mcpwp_idem_y_lease', 1000, 'h' );
		$seen = Mcpwp_Idempotency_Claim::read( 'mcpwp_idem_y_lease' );

		$this->assertTrue( Mcpwp_Idempotency_Claim::take_over( 'mcpwp_idem_y_lease', $seen['raw'], 2000, 'h' ) );
		$this->assertFalse( Mcpwp_Idempotency_Claim::take_over( 'mcpwp_idem_y_lease', $seen['raw'], 2001, 'h' ), 'The second racer saw the same stale value and must lose.' );
	}

	public function test_a_localized_duplicate_message_is_still_a_held_claim(): void {
		// MySQL in French says "Duplicata du champ"; SQLite says "UNIQUE
		// constraint failed". The row is the authority, not the wording.
		$GLOBALS['mcpwp_test_wpdb_duplicate_message'] = "Duplicata du champ 'x' pour la clef 'option_name'";
		Mcpwp_Idempotency_Claim::acquire( 'mcpwp_idem_z_lease', time(), 'h' );
		$this->assertSame( Mcpwp_Idempotency_Claim::HELD, Mcpwp_Idempotency_Claim::acquire( 'mcpwp_idem_z_lease', time(), 'h' ) );

		$GLOBALS['mcpwp_test_wpdb_duplicate_message'] = 'some driver-specific wording with no keyword';
		$this->assertSame( Mcpwp_Idempotency_Claim::HELD, Mcpwp_Idempotency_Claim::acquire( 'mcpwp_idem_z_lease', time(), 'h' ), 'No keyword: the visible row decides.' );
		unset( $GLOBALS['mcpwp_test_wpdb_duplicate_message'] );
	}

	public function test_an_unavailable_claim_store_still_replays_a_completed_record(): void {
		// The holder released between our refused insert and our read, on a
		// driver whose wording we do not recognise: the done record must win
		// before anything runs unprotected.
		$this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$GLOBALS['mcpwp_test_wpdb_insert_fails'] = true;

		$second = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		$this->assertTrue( $second['result']['_meta']['replayed'] );
		$this->assertSame( 1, $this->dispatches() );
	}

	public function test_the_retry_path_never_prints_a_database_error(): void {
		global $wpdb;
		Mcpwp_Idempotency_Claim::acquire( 'mcpwp_idem_q_lease', time(), 'h' );
		$wpdb->suppressed = false;

		Mcpwp_Idempotency_Claim::acquire( 'mcpwp_idem_q_lease', time(), 'h' );

		$this->assertFalse( $wpdb->suppressed, 'Suppression is restored after the statement.' );
		$this->assertTrue( method_exists( $wpdb, 'suppress_errors' ) );
	}

	public function test_the_sweep_runs_a_portable_delete(): void {
		global $wpdb;
		$wpdb->queries = array();

		Mcpwp_Idempotency_Claim::sweep( 1789000000 );

		$this->assertCount( 1, $wpdb->queries );
		$this->assertStringContainsString( 'mcpwp', $wpdb->queries[0] );
		$this->assertStringContainsString( '_lease', $wpdb->queries[0] );
		$this->assertStringNotContainsString( 'SUBSTRING_INDEX', $wpdb->queries[0], 'MySQL-only functions are unavailable on the SQLite integration.' );
		$this->assertStringContainsString( "'1788913600|'", $wpdb->queries[0] );
	}

	public function test_an_unavailable_claim_store_runs_the_call_and_says_it_was_not_protected(): void {
		$GLOBALS['mcpwp_test_wpdb_insert_fails'] = true;

		$data = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		$this->assertArrayHasKey( 'result', $data );
		$this->assertSame( 'not_stored', $data['result']['_meta']['idempotency'] );
		$this->assertSame( 1, $this->dispatches() );
	}

	public function test_the_lease_outlives_the_done_record_write(): void {
		// Releasing the lease before the record exists is a duplicate window:
		// a retry in that gap sees no lease and no record. Observed through
		// the record write: it must happen while the lease is still held.
		$store_key                                    = $this->store_key( 'r1' );
		$order                                        = array();
		$GLOBALS['mcpwp_test_transient_write_fails'] = function ( $name ) use ( $store_key, &$order ) {
			if ( $name === $store_key ) {
				$order[] = isset( $GLOBALS['mcpwp_test_wpdb_options'][ $store_key . '_lease' ] ) ? 'lease-held' : 'lease-gone';
			}
			return false;
		};

		$this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		$this->assertSame( array( 'lease-held' ), $order );
		$this->assertArrayNotHasKey( $store_key . '_lease', $GLOBALS['mcpwp_test_wpdb_options'], 'And released afterwards.' );
	}

	public function test_two_concurrent_identical_retries_run_exactly_once(): void {
		// The first attempt is mid-execution on another worker: it holds the
		// claim row, nothing is stored yet.
		Mcpwp_Idempotency_Claim::acquire( $this->store_key( 'r1' ) . '_lease', time(), '' );

		$data = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertTrue( $data['result']['isError'] );
		$this->assertSame( 'idempotency_in_progress', $data['result']['structuredContent']['error']['code'] );
		$this->assertGreaterThan( 0, $data['result']['structuredContent']['error']['retry_after'] );
		$this->assertSame( 0, $this->dispatches() );
	}

	public function test_a_claim_abandoned_by_a_fatal_expires_instead_of_locking_the_id_for_a_day(): void {
		Mcpwp_Idempotency_Claim::acquire( $this->store_key( 'r1' ) . '_lease', time() - Mcpwp_REST_MCP::IDEMPOTENCY_LEASE_SECONDS - 1, '' );

		$data = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		$this->assertArrayHasKey( 'result', $data, 'The retry is allowed to do the work.' );
		$this->assertSame( 1, $this->dispatches() );
	}

	public function test_the_claim_is_released_after_the_call_whatever_happened(): void {
		$this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$this->assertNull( Mcpwp_Idempotency_Claim::read( $this->store_key( 'r1' ) . '_lease' ) );

		update_option( 'mcpwp_disabled_tool_categories', array( 'content' ) );
		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->call( 'wp_create_page', array( 'title' => 'B' ), 'r2' );
		$this->assertNull( Mcpwp_Idempotency_Claim::read( $this->store_key( 'r2' ) . '_lease' ) );
	}

	public function test_a_corrupt_done_record_is_an_error_not_a_second_write(): void {
		set_transient( $this->store_key( 'r1' ), array( 'state' => 'done', 'args_hash' => 'x' ), 60 );

		$data = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );

		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertTrue( $data['result']['isError'] );
		$this->assertSame( 'idempotency_corrupt', $data['result']['structuredContent']['error']['code'] );
		$this->assertSame( 0, $this->dispatches() );
	}

	public function test_a_tool_outside_the_built_in_map_says_it_was_not_pinned(): void {
		$data = $this->call( 'custom_hook_tool', array(), 'r1' );

		if ( isset( $data['result'] ) ) {
			$this->assertSame( 'not_applicable', $data['result']['_meta']['idempotency'] );
		} else {
			$this->assertArrayHasKey( 'error', $data );
		}
	}

	public function test_a_tool_that_cannot_prove_completion_opts_out_of_pinning(): void {
		$first = $this->call( 'wp_deploy_site_blueprint', array( 'id' => 1 ), 'r1' );
		$this->call( 'wp_deploy_site_blueprint', array( 'id' => 1 ), 'r1' );

		$this->assertSame( 2, $this->dispatches(), 'A deploy whose 200 absorbs failed steps must never be replayed as complete.' );
		$this->assertSame( 'not_applicable', $first['result']['_meta']['idempotency'] );
	}

	public function test_a_result_reporting_unreadable_items_is_partial(): void {
		// The site-wide find/replace reports pages it could not read under
		// 'unreadable', never 'errors'. Pinning that would replay "done" over
		// the pages it skipped. Modelled on a bulk tool that needs no plugin.
		$GLOBALS['mcpwp_test_rest_response_override'] = array( 'updated' => array(), 'errors' => array(), 'unreadable' => array( array( 'id' => 5 ) ) );

		$first = $this->call( 'wp_bulk_update_pages', array( 'pages' => array( array( 'id' => 5, 'title' => 'x' ) ) ), 'r1' );
		unset( $GLOBALS['mcpwp_test_rest_response_override'] );

		$this->assertArrayHasKey( 'result', $first, isset( $first['error'] ) ? wp_json_encode( $first['error'] ) : '' );
		$this->assertSame( 'not_stored', $first['result']['_meta']['idempotency'] );
	}

	public function test_skipped_not_found_and_a_failed_count_are_partial_too(): void {
		foreach ( array( array( 'skipped' => array( 3 ) ), array( 'not_found' => array( 9 ) ), array( 'failed' => 2, 'total' => 5 ) ) as $shape ) {
			$GLOBALS['mcpwp_test_transients']              = array();
			$GLOBALS['mcpwp_test_wpdb_options']            = array();
			$GLOBALS['mcpwp_test_rest_response_override']  = array_merge( array( 'updated' => array() ), $shape );
			Mcpwp_REST_MCP::reset_verdict_memo();

			$first = $this->call( 'wp_bulk_update_pages', array( 'pages' => array( array( 'id' => 5, 'title' => 'x' ) ) ), 'r-' . key( $shape ) );

			$this->assertSame( 'not_stored', $first['result']['_meta']['idempotency'], wp_json_encode( $shape ) );
		}
		unset( $GLOBALS['mcpwp_test_rest_response_override'] );
	}

	public function test_a_validation_tool_finding_errors_is_not_a_partial_failure(): void {
		$GLOBALS['mcpwp_test_rest_response_override'] = array( 'valid' => false, 'errors' => array( 'block 3: missing attribute' ) );

		$first = $this->call( 'wp_validate_blocks', array( 'blocks' => array() ), 'r1' );
		unset( $GLOBALS['mcpwp_test_rest_response_override'] );

		$this->assertSame( 'stored', $first['result']['_meta']['idempotency'], 'Finding errors is what a validator is for.' );
	}

	public function test_an_oauth_client_id_without_a_user_is_not_a_principal(): void {
		$this->assertSame( '', Mcpwp_REST_MCP::principal_scope( array( 'id' => 'oauth-client:site_pilot_ai' ) ) );
		$this->assertSame( 'oauth-user:7', Mcpwp_REST_MCP::principal_scope( array( 'id' => 'oauth-user:7' ) ) );
		$this->assertSame( 'broker:abc', Mcpwp_REST_MCP::principal_scope( array( 'id' => 'broker:abc' ) ) );
		$this->assertSame( '', Mcpwp_REST_MCP::principal_scope( array( 'id' => 'anon' ) ) );
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

		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertTrue( $data['result']['isError'] );
		$this->assertSame( 'category_disabled', $data['result']['structuredContent']['error']['code'] );
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

	// ---------------------------------------------------------------
	// Round 4: the guard's own gates, the sweep, the lease, the store.
	// ---------------------------------------------------------------

	public function test_a_tool_with_no_category_is_never_re_run_by_the_replay_gate(): void {
		// Round-4 P1: the replay gate invented 'site' for a tool absent from
		// the category map; the handler enforces '' — so a refused replay
		// was sent back into the handler and the write ran again, with no
		// idempotency note on the response.
		$first = $this->call( 'wp_update_menu_item_auto', array( 'item_id' => 5, 'title' => 'X' ), 'r1' );
		$this->assertSame( 1, $this->dispatches() );
		$this->assertSame( 'stored', $first['result']['_meta']['idempotency'] );

		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );
		Mcpwp_REST_MCP::reset_verdict_memo();
		$second = $this->call( 'wp_update_menu_item_auto', array( 'item_id' => 5, 'title' => 'X' ), 'r1' );

		$this->assertSame( 1, $this->dispatches(), 'The write did not run again.' );
		$this->assertTrue( $second['result']['isError'] );
		$this->assertSame( 'category_disabled', $second['result']['structuredContent']['error']['code'], 'Both _auto tools are in the category map now, so the kill switch binds them.' );

		// And the two gates read one resolver: a name in neither map has no
		// category on either side, so the replay gate cannot invent one.
		$permitted = new ReflectionMethod( Mcpwp_REST_MCP::class, 'tool_currently_permitted' );
		$permitted->setAccessible( true );
		$this->assertTrue( $permitted->invoke( new Mcpwp_REST_MCP(), 'wp_no_such_tool', null ), 'No category, no refusal — exactly what the handler does.' );
	}

	public function test_every_pinned_tool_has_a_category(): void {
		// The replay gate depends on the category map; a pinned tool missing
		// from it is the round-4 hole in a new coat.
		$controller = new Mcpwp_REST_MCP();
		$map        = new ReflectionMethod( $controller, 'get_all_tool_map' );
		$map->setAccessible( true );
		$cats = new ReflectionMethod( $controller, 'get_all_tool_categories' );
		$cats->setAccessible( true );
		$categories = $cats->invoke( $controller );

		// Every tool, GET included: the per-key category gate applies to
		// every method, not only to pinned ones.
		foreach ( $map->invoke( $controller ) as $tool => $entry ) {
			$this->assertArrayHasKey( $tool, $categories, $tool . ' must be in the category map.' );
		}
	}

	public function test_the_sweep_deletes_only_leases_older_than_the_cutoff(): void {
		// Round-4 M1: inverting the comparison deleted every live lease and
		// kept the stale ones, and the suite stayed green because nothing
		// looked at which rows survived.
		$now = time();
		$GLOBALS['mcpwp_test_wpdb_options'] = array(
			'mcpwp_idem_stale_lease' => ( $now - 2 * DAY_IN_SECONDS ) . '|' . str_repeat( 'a', 64 ),
			'mcpwp_idem_live_lease'  => ( $now - 30 ) . '|' . str_repeat( 'b', 64 ),
			'mcpwp_idem_edge_lease'  => ( $now - DAY_IN_SECONDS ) . '|' . str_repeat( 'c', 64 ),
			'mcpwp_idem_done_record' => 'not a lease',
			'other_option'           => '1',
		);

		Mcpwp_Idempotency_Claim::sweep( $now );

		$this->assertArrayNotHasKey( 'mcpwp_idem_stale_lease', $GLOBALS['mcpwp_test_wpdb_options'] );
		$this->assertArrayHasKey( 'mcpwp_idem_live_lease', $GLOBALS['mcpwp_test_wpdb_options'] );
		$this->assertArrayHasKey( 'mcpwp_idem_edge_lease', $GLOBALS['mcpwp_test_wpdb_options'], 'Exactly the cutoff is kept: option_value < cutoff|.' );
		$this->assertArrayHasKey( 'mcpwp_idem_done_record', $GLOBALS['mcpwp_test_wpdb_options'], 'Only _lease rows.' );
		$this->assertArrayHasKey( 'other_option', $GLOBALS['mcpwp_test_wpdb_options'] );

		global $wpdb;
		$this->assertStringContainsString( "LIKE 'mcpwp\\_idem\\_%\\_lease'", end( $wpdb->queries ), 'What production emits: LIKE metacharacters escaped.' );
	}

	public function test_an_unavailable_store_that_then_shows_a_done_record_replays_instead_of_re_running(): void {
		// Round-4 M5: the branch that answers a flapping store from the done
		// record was never executed by its own test.
		$first = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$this->assertSame( 1, $this->dispatches() );
		$record = $GLOBALS['mcpwp_test_transients'][ $this->store_key( 'r1' ) ]['value'];

		// The store cannot arbitrate the claim, and the first read of the
		// done record misses; the branch's own read hits.
		$GLOBALS['mcpwp_test_wpdb_insert_fails'] = true;
		$reads = 0;
		$key   = $this->store_key( 'r1' );
		$GLOBALS['mcpwp_test_transient_hook'] = function ( $name ) use ( &$reads, $key, $record ) {
			if ( $name !== $key ) {
				return null;
			}
			$reads++;
			return 1 === $reads ? false : $record;
		};
		Mcpwp_REST_MCP::reset_verdict_memo();
		$second = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		unset( $GLOBALS['mcpwp_test_transient_hook'] );
		$GLOBALS['mcpwp_test_wpdb_insert_fails'] = false;

		$this->assertSame( 1, $this->dispatches(), 'Replayed from the record, not run unprotected.' );
		$this->assertTrue( $second['result']['_meta']['replayed'] );
	}

	public function test_a_store_that_never_settles_runs_the_call_once_unprotected_and_says_so(): void {
		// Depth bound: a store answering "done" on one read and nothing on
		// the next must not recurse without end.
		$this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$record = $GLOBALS['mcpwp_test_transients'][ $this->store_key( 'r1' ) ]['value'];
		$GLOBALS['mcpwp_test_wpdb_insert_fails'] = true;
		$key   = $this->store_key( 'r1' );
		$reads = 0;
		$GLOBALS['mcpwp_test_transient_hook'] = function ( $name ) use ( &$reads, $key, $record ) {
			if ( $name !== $key ) {
				return null;
			}
			$reads++;
			return 0 === $reads % 2 ? $record : false; // flaps forever
		};
		Mcpwp_REST_MCP::reset_verdict_memo();
		$data = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		unset( $GLOBALS['mcpwp_test_transient_hook'] );
		$GLOBALS['mcpwp_test_wpdb_insert_fails'] = false;

		$this->assertSame( 2, $this->dispatches() );
		$this->assertSame( 'not_stored', $data['result']['_meta']['idempotency'] );
		$this->assertStringContainsString( 'inconsistent', $data['result']['_meta']['idempotency_reason'] );
	}

	public function test_the_lease_length_governs_takeover_and_a_future_lease_is_abandoned(): void {
		// Round-4 M20 and P2-10.
		$lease = Mcpwp_REST_MCP::idempotency_lease_name( $this->store_key( 'r1' ) );
		$hash  = hash( 'sha256', 'wp_create_page|' . wp_json_encode( array( 'title' => 'A' ) ) );

		// Held 5 s ago: in progress under the default 120 s.
		$GLOBALS['mcpwp_test_wpdb_options'][ $lease ] = ( time() - 5 ) . '|' . $hash;
		$data = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$this->assertTrue( $data['result']['isError'] );
		$this->assertSame( 'idempotency_in_progress', $data['result']['structuredContent']['error']['code'] );
		$this->assertSame( 0, $this->dispatches() );

		// Lower the lease to 3 s: the same 5 s old lease is abandoned and taken over.
		$GLOBALS['mcpwp_test_filters'] = array();
		add_filter( 'mcpwp_idempotency_lease_seconds', function () { return 3; } );
		Mcpwp_REST_MCP::reset_verdict_memo();
		$data = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertSame( 1, $this->dispatches() );
		$GLOBALS['mcpwp_test_filters'] = array();

		// A lease stamped in the future (clock corrected backwards) is abandoned, not held forever.
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_wpdb_options'][ $lease ] = ( time() + 3600 ) . '|' . $hash;
		Mcpwp_REST_MCP::reset_verdict_memo();
		$data = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$this->assertArrayHasKey( 'result', $data, 'Not still-in-progress for the next hour.' );
		$this->assertSame( 2, $this->dispatches() );
		$this->assertSame( 1, Mcpwp_REST_MCP::lease_seconds() >= 1 ? 1 : 0 );
	}

	public function test_a_replay_is_reported_with_its_own_code_and_counted_as_a_success(): void {
		// Round-4 M18 and P2-4.
		$seen = array();
		$GLOBALS['mcpwp_test_actions'] = array();
		add_action( 'mcpwp_tool_called', function ( $tool, $category, $ms, $code ) use ( &$seen ) { $seen[] = $code; }, 10, 4 );

		$this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$GLOBALS['mcpwp_test_actions'] = array();

		$this->assertSame( array( '', 'replayed' ), $seen );

		$captured = array();
		$GLOBALS['mcpwp_test_filters'] = array();
		add_filter( 'mcpwp_analytics_capture', function ( $event ) use ( &$captured ) { $captured[] = $event; return $event; } );
		if ( ! class_exists( 'Mcpwp_Analytics' ) ) {
			require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-analytics.php';
		}
		$this->assertTrue( ( new ReflectionMethod( Mcpwp_Analytics::class, 'on_tool_called' ) )->isStatic() );
		$props = $this->analytics_props( 'replayed' );
		$this->assertTrue( $props['success'], 'A safe replay is the happy path, not an error.' );
		$this->assertFalse( $this->analytics_props( 'execution_error' )['success'] );
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	/**
	 * The properties Mcpwp_Analytics::on_tool_called() would capture.
	 */
	private function analytics_props( string $code ): array {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/core/class-mcpwp-analytics.php' );
		// success is defined by one expression in on_tool_called(); evaluate it here.
		$this->assertMatchesRegularExpression( "/'success'\\s*=>\\s*in_array\\( \\(string\\) \\\$error_code, array\\( '', 'replayed' \\), true \\)/", $src );
		return array( 'success' => in_array( $code, array( '', 'replayed' ), true ) );
	}

	public function test_a_partial_failure_note_warns_that_a_retry_re_runs_what_succeeded(): void {
		// Round-4 P2-7: the old hint recommended the duplicating action.
		$GLOBALS['mcpwp_test_rest_response_override'] = array( 'success' => true, 'succeeded' => 1, 'failed' => 1, 'results' => array() );
		$data = $this->call( 'wp_batch_update', array( 'operations' => array( array( 'method' => 'GET', 'path' => '/posts/1' ), array( 'method' => 'GET', 'path' => '/posts/2' ) ) ), 'r1' );
		unset( $GLOBALS['mcpwp_test_rest_response_override'] );

		$this->assertSame( 'not_stored', $data['result']['_meta']['idempotency'] );
		$this->assertStringContainsString( 'NOT protected', $data['result']['_meta']['idempotency_reason'] );
		$this->assertStringContainsString( 'own request_id', $data['result']['_meta']['idempotency_reason'] );
	}

	// ---------------------------------------------------------------
	// Round 5: every gate resolves a category the same way.
	// ---------------------------------------------------------------

	public function test_an_integration_tool_with_no_category_is_gated_the_same_way_on_every_path(): void {
		// Round-5 P1: the per-key branch still defaulted to 'site', so a
		// replay the gate refused went back into the handler and the write
		// ran again — through a third-party integration that supplied a tool
		// map without categories.
		$integration = new class() extends Mcpwp_Integration {
			public function get_info() {
				return array( 'id' => 'zz', 'name' => 'ZZ', 'version' => '1.0' );
			}
			public function get_tool_map() {
				return array( 'zz_write_thing' => array( 'route' => '/mcpwp/v1/pages', 'method' => 'POST' ) );
			}
			public function get_tools() {
				return array( array( 'name' => 'zz_write_thing', 'description' => 'writes a thing', 'inputSchema' => array( 'type' => 'object', 'properties' => array( 'title' => array( 'type' => 'string' ) ) ) ) );
			}
		};
		$GLOBALS['mcpwp_test_filters'] = array();
		add_filter( 'mcpwp_integrations', function () use ( $integration ) { return array( $integration ); } );
		Mcpwp_Integration::clear_cache();

		// A category-restricted key: an uncategorised tool is denied — on
		// the first call and on the retry, and nothing dispatches.
		$restricted = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'restricted', array( 'admin' ), 'custom', array( 'site' ) );
		$first  = $this->call( 'zz_write_thing', array( 'title' => 'x' ), 'r1', 7, $restricted['key'] );
		Mcpwp_REST_MCP::reset_verdict_memo();
		$second = $this->call( 'zz_write_thing', array( 'title' => 'x' ), 'r1', 7, $restricted['key'] );
		$this->assertTrue( $first['result']['isError'] );
		$this->assertSame( 'scope_denied', $first['result']['structuredContent']['error']['code'] );
		$this->assertTrue( $second['result']['isError'] );
		$this->assertSame( 'scope_denied', $second['result']['structuredContent']['error']['code'] );
		$this->assertSame( 0, $this->dispatches(), 'Denied on every path; never re-run.' );

		// An unrestricted key: pinned and replayed like any built-in tool.
		$GLOBALS['mcpwp_test_transients'] = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
		$one = $this->call( 'zz_write_thing', array( 'title' => 'x' ), 'r2' );
		Mcpwp_REST_MCP::reset_verdict_memo();
		$two = $this->call( 'zz_write_thing', array( 'title' => 'x' ), 'r2' );
		$this->assertSame( 'stored', $one['result']['_meta']['idempotency'] );
		$this->assertTrue( $two['result']['_meta']['replayed'] );
		$this->assertSame( 1, $this->dispatches() );

		// The registry resolves the same way: no invented category — and the
		// listing agrees with the gate: a restricted key is not shown a tool
		// it cannot call (round-6 P2-1).
		$this->assertSame( '', $integration->get_tool_category( 'zz_write_thing' ) );
		$controller = new Mcpwp_REST_MCP();
		$listing    = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $restricted['key'] ), array( 'jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/list' ) );
		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->assertTrue( $controller->verify_api_key( $listing ) );
		$names = array_column( $controller->handle_mcp( $listing )->get_data()['result']['tools'], 'name' );
		$this->assertNotContains( 'zz_write_thing', $names );
		$GLOBALS['mcpwp_test_filters'] = array();
		Mcpwp_Integration::clear_cache();
	}

	public function test_the_in_progress_hint_names_the_lease_in_force_and_the_lease_is_clamped(): void {
		$lease = Mcpwp_REST_MCP::idempotency_lease_name( $this->store_key( 'r1' ) );
		$hash  = hash( 'sha256', 'wp_create_page|' . wp_json_encode( array( 'title' => 'A' ) ) );
		$GLOBALS['mcpwp_test_wpdb_options'][ $lease ] = ( time() - 2 ) . '|' . $hash;

		$GLOBALS['mcpwp_test_filters'] = array();
		add_filter( 'mcpwp_idempotency_lease_seconds', function () { return 10; } );
		$data = $this->call( 'wp_create_page', array( 'title' => 'A' ), 'r1' );
		$this->assertTrue( $data['result']['isError'] );
		$this->assertSame( 'idempotency_in_progress', $data['result']['structuredContent']['error']['code'] );
		$this->assertStringContainsString( '10 seconds', $data['result']['structuredContent']['error']['hint'] );
		$this->assertStringContainsString( '10 seconds', $data['result']['content'][0]['text'] );
		$this->assertLessThanOrEqual( 8, $data['result']['structuredContent']['error']['retry_after'] );

		$GLOBALS['mcpwp_test_filters'] = array();
		add_filter( 'mcpwp_idempotency_lease_seconds', function () { return PHP_INT_MAX; } );
		$this->assertSame( Mcpwp_REST_MCP::IDEMPOTENCY_TTL, Mcpwp_REST_MCP::lease_seconds(), 'Never longer than the record itself lives.' );
		$GLOBALS['mcpwp_test_filters'] = array();
	}
}
