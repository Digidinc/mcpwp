<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the proactive signals engine (issue #499).
 *
 * Covers the three production failure modes found dogfooding mcpwp.net:
 * 1. An empty feed was indistinguishable from "never computed" — get_meta()
 *    and the REST lazy-compute-on-first-read close that gap.
 * 2. Synchronous compute had no time budget — partial runs must skip types
 *    (preserving their stored signals) rather than 502 the origin.
 * 3. compute_pending_updates() called wp_update_plugins() (remote requests
 *    to wordpress.org) inside web requests — now cron-only.
 */
final class SignalsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']               = array();
		$GLOBALS['mcpwp_test_transients']            = array();
		$GLOBALS['_mcpwp_test_posts']                = array();
		$GLOBALS['mcpwp_test_doing_cron']            = false;
		$GLOBALS['mcpwp_test_plugin_updates']        = array();
		$GLOBALS['mcpwp_test_update_plugins_called'] = false;
	}

	/**
	 * One test here flips the harness into "this is a cron run", and setUp alone
	 * does not undo it — nothing clears it after this class's last test. The next
	 * class then computed signals down a cron-only path and its seeded seo_issue
	 * never appeared, failing RestExhaustiveDraftLeakTest's redaction tests. That
	 * is #757 in its third form: a leaked MODE switch rather than a leaked
	 * constant, and like the others it is invisible in declaration order.
	 */
	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_doing_cron']            = false;
		$GLOBALS['mcpwp_test_plugin_updates']        = array();
		$GLOBALS['mcpwp_test_update_plugins_called'] = false;
	}

	private function seed_page_drafts( int $count ): void {
		for ( $i = 1; $i <= $count; $i++ ) {
			$GLOBALS['_mcpwp_test_posts'][ 100 + $i ] = (object) array(
				'ID'          => 100 + $i,
				'post_title'  => 'Draft ' . $i,
				'post_type'   => 'page',
				'post_status' => 'draft',
			);
		}
	}

	// ── Meta / never-computed detection ────────────────────────────────

	public function test_meta_reports_never_computed_before_first_run(): void {
		$meta = Mcpwp_Signals::get_meta();
		$this->assertSame( '', $meta['last_computed'] );
		$this->assertFalse( $meta['partial'] );
	}

	public function test_compute_stores_signals_and_meta(): void {
		$this->seed_page_drafts( 12 ); // Above the default threshold of 10.

		$signals = Mcpwp_Signals::compute();

		$types = array_column( $signals, 'type' );
		$this->assertContains( 'draft_accumulation', $types );

		$meta = Mcpwp_Signals::get_meta();
		$this->assertNotSame( '', $meta['last_computed'], 'last_computed must be set after a run.' );
		$this->assertFalse( $meta['partial'] );
		$this->assertSame( array(), $meta['skipped_types'] );
		$this->assertSame( Mcpwp_Signals::SIGNAL_TYPES, $meta['computed_types'] );

		// Stored feed serves the computed signals.
		$stored = Mcpwp_Signals::get_signals();
		$this->assertContains( 'draft_accumulation', array_column( $stored, 'type' ) );
	}

	// ── Time budget / partial runs ──────────────────────────────────────

	public function test_exhausted_budget_skips_types_but_always_computes_one(): void {
		$signals = Mcpwp_Signals::compute( array(), 0.000001 );

		$meta = Mcpwp_Signals::get_meta();
		$this->assertTrue( $meta['partial'] );
		$this->assertCount( 1, $meta['computed_types'], 'At least (and here exactly) one type must compute.' );
		$this->assertCount(
			count( Mcpwp_Signals::SIGNAL_TYPES ) - 1,
			$meta['skipped_types'],
			'Remaining types must be reported as skipped.'
		);
		$this->assertIsArray( $signals );
	}

	public function test_partial_run_preserves_stored_signals_of_skipped_types(): void {
		// Pre-store a signal of a type that the budgeted run will skip.
		update_option(
			Mcpwp_Signals::OPTION_KEY,
			array(
				array(
					'type'        => 'pending_update',
					'severity'    => 'medium',
					'entity_id'   => 0,
					'detected_at' => '2026-06-01T00:00:00+00:00',
				),
			)
		);

		// Tiny budget: only the first type (stale_content) computes.
		Mcpwp_Signals::compute( array(), 0.000001 );

		$meta = Mcpwp_Signals::get_meta();
		$this->assertContains( 'pending_update', $meta['skipped_types'] );

		$stored = Mcpwp_Signals::get_signals();
		$this->assertContains(
			'pending_update',
			array_column( $stored, 'type' ),
			'Signals of budget-skipped types must survive a partial run.'
		);
	}

	// ── No remote requests outside cron ─────────────────────────────────

	public function test_pending_updates_does_not_hit_network_in_web_context(): void {
		$GLOBALS['mcpwp_test_plugin_updates'] = array(
			'foo/foo.php' => (object) array(
				'Name'    => 'Foo',
				'Version' => '1.0',
				'update'  => (object) array( 'new_version' => '2.0' ),
			),
		);

		$signals = Mcpwp_Signals::compute( array( 'pending_update' ) );

		$this->assertFalse(
			$GLOBALS['mcpwp_test_update_plugins_called'],
			'wp_update_plugins() must never run outside cron.'
		);
		// The cached transient data still produces the signal.
		$this->assertSame( 'pending_update', $signals[0]['type'] ?? null );
	}

	public function test_pending_updates_refreshes_cache_in_cron_context(): void {
		$GLOBALS['mcpwp_test_doing_cron'] = true;

		Mcpwp_Signals::compute( array( 'pending_update' ) );

		$this->assertTrue(
			$GLOBALS['mcpwp_test_update_plugins_called'],
			'Cron runs should refresh the update cache.'
		);
	}

	// ── REST: lazy compute on first read + staleness metadata ───────────

	private function rest_get( array $params = array() ): array {
		$controller = new Mcpwp_REST_Signals();
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/signals', $params );
		return $controller->get_signals( $request )->get_data();
	}

	public function test_rest_first_read_lazily_computes(): void {
		$this->seed_page_drafts( 12 );

		$data = $this->rest_get();

		$this->assertNotNull( $data['last_computed'], 'First read must trigger a compute.' );
		$this->assertContains( 'draft_accumulation', array_column( $data['signals'], 'type' ) );
	}

	public function test_rest_subsequent_read_serves_stored_feed_without_recompute(): void {
		$this->rest_get(); // First read computes.
		$first_meta = Mcpwp_Signals::get_meta();

		// Make the second read detectable: a recompute would change the feed.
		$this->seed_page_drafts( 12 );
		$data = $this->rest_get();

		$this->assertSame(
			$first_meta['last_computed'],
			$data['last_computed'],
			'Reads after the first must serve the stored feed.'
		);
		$this->assertNotContains( 'draft_accumulation', array_column( $data['signals'], 'type' ) );
	}

	public function test_rest_refresh_param_recomputes(): void {
		$this->rest_get(); // Establish a computed (empty) feed.
		$this->seed_page_drafts( 12 );

		$data = $this->rest_get( array( 'refresh' => true ) );

		$this->assertContains( 'draft_accumulation', array_column( $data['signals'], 'type' ) );
	}
}
