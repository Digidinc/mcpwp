<?php
/**
 * Regression coverage for #875 review finding W3:
 * Mcpwp_Activator::repair_custom_code_locations() must be batched and
 * checkpointed, not an unbounded single-pass loop on the plugins_loaded
 * request path.
 *
 * The original version of this migration (#871) did
 * get_posts(['posts_per_page' => -1]) and looped every elementor_snippet in
 * one pass, writing its completion flag only after the whole loop finished.
 * A site with enough snippets to exceed max_execution_time would time out
 * mid-loop, never set the flag, and re-scan from the start on every
 * subsequent request forever.
 *
 * These tests simulate that interruption deterministically (passing an
 * explicit time budget) rather than waiting on a real timeout, and verify:
 *   - partial progress survives an interrupted run (durable cursor option,
 *     completion flag still unset, already-repaired rows stay repaired);
 *   - a resumed run picks up where it left off and eventually completes;
 *   - the migration is idempotent (a second call after completion, and a
 *     re-run over already-repaired rows, both no-op);
 *   - it converges on a large snippet count within a bounded number of
 *     request-scoped calls.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';

final class CustomCodeLocationRepairMigrationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['_mcpwp_test_posts']             = array();
		$GLOBALS['_mcpwp_test_meta']              = array();
		$GLOBALS['mcpwp_test_wpdb_options']       = array();
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
	}

	/**
	 * Populate $count elementor_snippet posts (IDs 1..$count) written under
	 * the #871 bug: literal 'head' location, post_content holding the code,
	 * no _elementor_code / _elementor_priority meta at all.
	 */
	private function seed_snippets( int $count ): void {
		for ( $i = 1; $i <= $count; $i++ ) {
			$GLOBALS['_mcpwp_test_posts'][ $i ] = (object) array(
				'ID'           => $i,
				'post_type'    => 'elementor_snippet',
				'post_title'   => 'Snippet ' . $i,
				'post_status'  => 'publish',
				'post_content' => '<script>snippet' . $i . '()</script>',
			);
			update_post_meta( $i, '_elementor_location', 'head' );
		}
	}

	private function is_fully_repaired( int $post_id ): bool {
		return 'elementor_head' === get_post_meta( $post_id, '_elementor_location', true )
			&& '' !== (string) get_post_meta( $post_id, '_elementor_code', true )
			&& '' !== (string) get_post_meta( $post_id, '_elementor_priority', true );
	}

	/**
	 * A single call with a normal (non-exhausted) budget over a snippet count
	 * under one batch must finish everything and mark completion.
	 */
	public function test_small_snippet_count_completes_in_one_call(): void {
		$this->seed_snippets( 5 );

		Mcpwp_Activator::repair_custom_code_locations();

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertTrue( $this->is_fully_repaired( $i ), "post $i should be fully repaired" );
		}
		$this->assertSame( 1, get_option( 'mcpwp_custom_code_locations_repaired' ) );
		$this->assertFalse( get_option( Mcpwp_Activator::CUSTOM_CODE_REPAIR_CURSOR_OPTION, false ) );
	}

	/**
	 * Correctness of the actual repair rules, preserved across the
	 * batching rewrite: an internal value is left alone, an unrecognized
	 * value is left alone, and a value already carrying code/priority is not
	 * clobbered.
	 */
	public function test_repair_rules_are_unchanged_by_batching(): void {
		$GLOBALS['_mcpwp_test_posts'][100] = (object) array(
			'ID' => 100, 'post_type' => 'elementor_snippet', 'post_status' => 'publish',
			'post_content' => '<script>a()</script>',
		);
		update_post_meta( 100, '_elementor_location', 'body_start' );

		$GLOBALS['_mcpwp_test_posts'][101] = (object) array(
			'ID' => 101, 'post_type' => 'elementor_snippet', 'post_status' => 'publish',
			'post_content' => '<script>b()</script>',
		);
		update_post_meta( 101, '_elementor_location', 'elementor_head' ); // already internal

		$GLOBALS['_mcpwp_test_posts'][102] = (object) array(
			'ID' => 102, 'post_type' => 'elementor_snippet', 'post_status' => 'publish',
			'post_content' => '<script>c()</script>',
		);
		update_post_meta( 102, '_elementor_location', 'sidebar' ); // unrecognized
		update_post_meta( 102, '_elementor_code', '<script>already-there()</script>' );
		update_post_meta( 102, '_elementor_priority', 5 );

		Mcpwp_Activator::repair_custom_code_locations();

		$this->assertSame( 'elementor_body_start', get_post_meta( 100, '_elementor_location', true ) );
		$this->assertSame( '<script>a()</script>', get_post_meta( 100, '_elementor_code', true ) );
		$this->assertSame( 1, get_post_meta( 100, '_elementor_priority', true ) );

		$this->assertSame( 'elementor_head', get_post_meta( 101, '_elementor_location', true ) );

		// Unrecognized location untouched; pre-existing code/priority untouched.
		$this->assertSame( 'sidebar', get_post_meta( 102, '_elementor_location', true ) );
		$this->assertSame( '<script>already-there()</script>', get_post_meta( 102, '_elementor_code', true ) );
		$this->assertSame( 5, get_post_meta( 102, '_elementor_priority', true ) );
	}

	/**
	 * Core W3 fix: a run that exhausts its time budget mid-scan must leave a
	 * durable cursor, must NOT set the completion flag, and must NOT lose
	 * the work it already did. Uses 450 snippets (> 2 batches of the
	 * production BATCH_SIZE=200) so a single call cannot possibly finish,
	 * and time_budget=0.0 to force the interruption deterministically
	 * instead of depending on a real timeout.
	 */
	public function test_interrupted_run_checkpoints_and_does_not_lose_progress(): void {
		$this->seed_snippets( 450 );

		Mcpwp_Activator::repair_custom_code_locations( 0.0 );

		// Exactly one batch worth of forward progress — the guarantee that a
		// call always completes at least one batch regardless of budget.
		$this->assertSame( Mcpwp_Activator::CUSTOM_CODE_REPAIR_BATCH_SIZE, get_option( Mcpwp_Activator::CUSTOM_CODE_REPAIR_CURSOR_OPTION ) );
		$this->assertFalse( get_option( 'mcpwp_custom_code_locations_repaired', false ), 'must not be marked complete after only one batch' );

		for ( $i = 1; $i <= 200; $i++ ) {
			$this->assertTrue( $this->is_fully_repaired( $i ), "post $i should already be repaired" );
		}
		for ( $i = 201; $i <= 450; $i++ ) {
			$this->assertSame( 'head', get_post_meta( $i, '_elementor_location', true ), "post $i must not be touched yet" );
			$this->assertSame( '', get_post_meta( $i, '_elementor_code', true ) );
		}
	}

	/**
	 * A resumed run (same call, no state reset) must continue from the
	 * checkpoint rather than rescanning from the start, and must eventually
	 * reach completion.
	 */
	public function test_resumed_run_continues_from_checkpoint_and_completes(): void {
		$this->seed_snippets( 450 );

		Mcpwp_Activator::repair_custom_code_locations( 0.0 ); // batch 1: 1-200
		$this->assertSame( 200, get_option( Mcpwp_Activator::CUSTOM_CODE_REPAIR_CURSOR_OPTION ) );

		Mcpwp_Activator::repair_custom_code_locations( 0.0 ); // batch 2: 201-400
		$this->assertSame( 400, get_option( Mcpwp_Activator::CUSTOM_CODE_REPAIR_CURSOR_OPTION ) );
		$this->assertFalse( get_option( 'mcpwp_custom_code_locations_repaired', false ) );
		for ( $i = 201; $i <= 400; $i++ ) {
			$this->assertTrue( $this->is_fully_repaired( $i ), "post $i should be repaired after the second batch" );
		}
		for ( $i = 401; $i <= 450; $i++ ) {
			$this->assertSame( 'head', get_post_meta( $i, '_elementor_location', true ), "post $i must not be touched yet" );
		}

		// Final partial batch (50 rows) — a generous budget lets the do-while
		// loop's own "was that the last page" check end the run.
		Mcpwp_Activator::repair_custom_code_locations( 30.0 ); // batch 3: 401-450

		$this->assertSame( 1, get_option( 'mcpwp_custom_code_locations_repaired' ) );
		$this->assertFalse( get_option( Mcpwp_Activator::CUSTOM_CODE_REPAIR_CURSOR_OPTION, false ), 'cursor must be cleared on completion' );
		for ( $i = 1; $i <= 450; $i++ ) {
			$this->assertTrue( $this->is_fully_repaired( $i ), "post $i should be repaired after resuming to completion" );
		}
	}

	/**
	 * Idempotency at the top level: once complete, further calls are a
	 * pure no-op (the flag short-circuits before touching anything).
	 */
	public function test_completed_migration_is_a_no_op_on_further_calls(): void {
		$this->seed_snippets( 10 );
		Mcpwp_Activator::repair_custom_code_locations();
		$this->assertSame( 1, get_option( 'mcpwp_custom_code_locations_repaired' ) );

		// Mutate a repaired post back to an unrelated state to prove a
		// second call does not touch it at all (not merely that it produces
		// the same result).
		update_post_meta( 1, '_elementor_priority', 9 );

		Mcpwp_Activator::repair_custom_code_locations();

		$this->assertSame( 9, get_post_meta( 1, '_elementor_priority', true ), 'a post-completion call must not re-touch any row' );
	}

	/**
	 * Idempotency mid-scan: re-running over rows already visited in an
	 * earlier batch (because the caller re-entered before the flag was set)
	 * must not double-apply or corrupt anything, since a fresh keyset query
	 * always starts from the cursor, never from a row already passed.
	 * Also exercises the map's own idempotency directly.
	 */
	public function test_reapplying_repair_to_an_already_repaired_row_is_a_no_op(): void {
		$this->seed_snippets( 1 );
		update_post_meta( 1, '_elementor_location', 'elementor_head' );
		update_post_meta( 1, '_elementor_code', '<script>final()</script>' );
		update_post_meta( 1, '_elementor_priority', 3 );

		Mcpwp_Activator::repair_custom_code_locations();

		$this->assertSame( 'elementor_head', get_post_meta( 1, '_elementor_location', true ) );
		$this->assertSame( '<script>final()</script>', get_post_meta( 1, '_elementor_code', true ) );
		$this->assertSame( 3, get_post_meta( 1, '_elementor_priority', true ) );
	}

	/**
	 * Convergence at scale. 5,000 snippets — 25x the production batch size —
	 * chosen to make the test exercise real multi-batch behaviour (not just
	 * "two batches") while staying fast as a pure in-memory PHPUnit run (no
	 * real DB I/O in this suite). Each call simulates one full,
	 * budget-exhausted web request; the loop below simulates the sequence of
	 * requests plugins_loaded would run across real page loads until the
	 * one-time flag is finally set, with a hard cap far above what should be
	 * needed so a regression that stops converging fails loudly instead of
	 * hanging the test suite.
	 */
	public function test_large_snippet_count_converges_over_repeated_requests(): void {
		$total = 5000;
		$this->seed_snippets( $total );

		$batch_size = Mcpwp_Activator::CUSTOM_CODE_REPAIR_BATCH_SIZE;
		// One call per full batch, plus one extra call when $total is an exact
		// multiple of the batch size — that last full batch looks identical
		// to a not-yet-final page (count === BATCH_SIZE), so the scan needs
		// one more call to see an empty result before it can mark completion.
		$expected_calls = (int) ceil( $total / $batch_size ) + ( 0 === $total % $batch_size ? 1 : 0 );
		$calls          = 0;
		$max_calls      = $expected_calls + 5; // headroom, not an excuse to loop forever.

		while ( ! get_option( 'mcpwp_custom_code_locations_repaired' ) ) {
			$this->assertLessThanOrEqual( $max_calls, $calls, 'migration did not converge within the expected number of batched requests' );
			Mcpwp_Activator::repair_custom_code_locations( 0.0 );
			$calls++;
		}

		$this->assertSame( $expected_calls, $calls, 'should take the expected number of request-scoped calls to converge' );
		$this->assertGreaterThan( 1, $calls, 'a single call must not have processed all 5000 rows at once — batching must actually be happening' );

		$this->assertTrue( $this->is_fully_repaired( 1 ), 'first row' );
		$this->assertTrue( $this->is_fully_repaired( (int) ( $total / 2 ) ), 'middle row' );
		$this->assertTrue( $this->is_fully_repaired( $total ), 'last row' );
	}

	/**
	 * A site with no Elementor Pro / no snippets at all must complete
	 * immediately rather than looping.
	 */
	public function test_no_snippets_completes_immediately(): void {
		Mcpwp_Activator::repair_custom_code_locations();

		$this->assertSame( 1, get_option( 'mcpwp_custom_code_locations_repaired' ) );
		$this->assertFalse( get_option( Mcpwp_Activator::CUSTOM_CODE_REPAIR_CURSOR_OPTION, false ) );
	}

	// ── RC review finding #2: the read-cursor -> batch -> write-cursor cycle ─
	// must be locked so two overlapping plugins_loaded calls cannot both read
	// the same stale cursor and have whichever writes last move it backward.

	/**
	 * A run that finds the lock already held (another process's in-flight
	 * call) must simply return and make zero progress — not wait, not run
	 * unprotected, and not touch the cursor or the completion flag.
	 */
	public function test_second_run_is_refused_while_lock_is_held(): void {
		$this->seed_snippets( 5 );

		// Simulate another process's run already in flight, well inside its TTL.
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Activator::CUSTOM_CODE_REPAIR_LOCK_OPTION ] = (string) ( time() + Mcpwp_Activator::CUSTOM_CODE_REPAIR_LOCK_TTL );

		Mcpwp_Activator::repair_custom_code_locations();

		$this->assertFalse( get_option( 'mcpwp_custom_code_locations_repaired', false ) );
		$this->assertFalse( get_option( Mcpwp_Activator::CUSTOM_CODE_REPAIR_CURSOR_OPTION, false ) );
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertSame( 'head', get_post_meta( $i, '_elementor_location', true ), "post $i must be untouched while the lock is held" );
		}
	}

	/**
	 * Once the other process's lock is gone (released, or its TTL lapsed),
	 * a later call must proceed and converge normally — the lock never
	 * blocks the migration forever.
	 */
	public function test_run_proceeds_normally_once_the_other_lock_is_gone(): void {
		$this->seed_snippets( 5 );

		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Activator::CUSTOM_CODE_REPAIR_LOCK_OPTION ] = (string) ( time() + Mcpwp_Activator::CUSTOM_CODE_REPAIR_LOCK_TTL );
		Mcpwp_Activator::repair_custom_code_locations();
		$this->assertFalse( get_option( 'mcpwp_custom_code_locations_repaired', false ), 'still blocked while the other lock stands' );

		// The other process finished and released its lock.
		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Activator::CUSTOM_CODE_REPAIR_LOCK_OPTION ] );

		Mcpwp_Activator::repair_custom_code_locations();

		$this->assertSame( 1, get_option( 'mcpwp_custom_code_locations_repaired' ) );
		$this->assertArrayNotHasKey( Mcpwp_Activator::CUSTOM_CODE_REPAIR_LOCK_OPTION, $GLOBALS['mcpwp_test_wpdb_options'], 'this run must release its own lock' );
	}

	/**
	 * A lock left behind by a holder that died mid-run (crash, worker
	 * recycle) must not block the migration forever: once its TTL has
	 * passed, the next call steals it via compare-and-swap and proceeds.
	 */
	public function test_expired_lock_from_a_dead_holder_is_taken_over(): void {
		$this->seed_snippets( 5 );

		// A lock whose expiry is already in the past — a holder that never
		// came back to release it.
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Activator::CUSTOM_CODE_REPAIR_LOCK_OPTION ] = (string) ( time() - 5 );

		Mcpwp_Activator::repair_custom_code_locations();

		$this->assertSame( 1, get_option( 'mcpwp_custom_code_locations_repaired' ), 'a stale lock must not block the migration forever' );
		$this->assertArrayNotHasKey( Mcpwp_Activator::CUSTOM_CODE_REPAIR_LOCK_OPTION, $GLOBALS['mcpwp_test_wpdb_options'] );
	}

	/**
	 * The actual race: two calls that both start at (as close as a
	 * single-threaded test can get to) the same instant must serialize
	 * through the lock, so the cursor only ever advances — it is never
	 * possible for the second call to read the first's stale starting
	 * cursor and overwrite forward progress with its own smaller value.
	 *
	 * Simulated via the same one-shot before-insert hook the codebase
	 * already uses to land a concurrent writer between a caller's read and
	 * its insert (#564) — here landing an entire second, fully-overlapping
	 * repair_custom_code_locations() call at the exact point the first is
	 * claiming the lock row.
	 */
	public function test_interleaved_calls_advance_the_cursor_serially_never_backward(): void {
		$this->seed_snippets( 450 ); // 3 batches of the production 200-row size.

		$GLOBALS['mcpwp_test_wpdb_before_insert'] = function ( $name ) {
			if ( Mcpwp_Activator::CUSTOM_CODE_REPAIR_LOCK_OPTION !== $name ) {
				return;
			}
			// A second, fully overlapping plugins_loaded call — e.g. two
			// admin tabs, or an admin page load racing a REST request.
			Mcpwp_Activator::repair_custom_code_locations( 0.0 );
		};

		Mcpwp_Activator::repair_custom_code_locations( 0.0 );

		// Without the lock, both calls would have read cursor=0 and each
		// checkpointed their own single batch's end position — whichever
		// wrote last would win, and 200 (one batch) is exactly what "the
		// cursor moved backward" looks like here. Serialized through the
		// lock, the second call's read happens only after the first's
		// full run (including release) completes, so it continues from
		// 200, not from 0: two batches, 400.
		$this->assertSame( 400, get_option( Mcpwp_Activator::CUSTOM_CODE_REPAIR_CURSOR_OPTION ) );
		$this->assertArrayNotHasKey( Mcpwp_Activator::CUSTOM_CODE_REPAIR_LOCK_OPTION, $GLOBALS['mcpwp_test_wpdb_options'], 'the lock must be released after both calls finish' );

		for ( $i = 1; $i <= 400; $i++ ) {
			$this->assertTrue( $this->is_fully_repaired( $i ), "post $i should be repaired by one of the two interleaved calls" );
		}
		for ( $i = 401; $i <= 450; $i++ ) {
			$this->assertSame( 'head', get_post_meta( $i, '_elementor_location', true ), "post $i must not be touched yet" );
		}
	}

	// ── RC review finding #3: a cursor corrupted UPWARD must not read as ─────
	// genuine completion — "no rows above cursor" and "actually finished" are
	// proven apart by checking content, not the cursor's own value.

	public function test_cursor_corrupted_upward_does_not_falsely_complete(): void {
		$this->seed_snippets( 5 ); // real max ID is 5.

		// Something outside this migration set the cursor far above any real
		// post ID — e.g. a bad manual edit, or a bug in an unrelated migration
		// sharing option-naming conventions.
		update_option( Mcpwp_Activator::CUSTOM_CODE_REPAIR_CURSOR_OPTION, 999999, false );

		Mcpwp_Activator::repair_custom_code_locations();

		$this->assertFalse(
			get_option( 'mcpwp_custom_code_locations_repaired', false ),
			'an inflated cursor must not be trusted as proof the migration already ran'
		);
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertSame( 'head', get_post_meta( $i, '_elementor_location', true ), "post $i was never actually repaired despite the corrupted cursor" );
		}

		// Self-healed: the cursor is reset to something trustworthy rather
		// than left at the value that lied, so the migration can actually
		// make progress on a later call.
		$this->assertNotEquals( 999999, get_option( Mcpwp_Activator::CUSTOM_CODE_REPAIR_CURSOR_OPTION ) );

		// A follow-up call now converges normally.
		Mcpwp_Activator::repair_custom_code_locations();

		$this->assertSame( 1, get_option( 'mcpwp_custom_code_locations_repaired' ) );
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertTrue( $this->is_fully_repaired( $i ), "post $i should be repaired once the cursor is trustworthy again" );
		}
	}

	/**
	 * A cursor sitting exactly at the true max ID, with every row genuinely
	 * repaired already, must still be recognised as complete — the fix for
	 * finding #3 must not turn a real completion into a false negative.
	 */
	public function test_genuinely_complete_migration_is_still_recognised_as_complete(): void {
		$this->seed_snippets( 5 );
		Mcpwp_Activator::repair_custom_code_locations();
		$this->assertSame( 1, get_option( 'mcpwp_custom_code_locations_repaired' ) );

		// Simulate the cursor having drifted to exactly the true max ID
		// (the ordinary, non-corrupted end state) with the flag somehow
		// unset, to isolate has_unrepaired_custom_code_snippets() from the
		// top-level short-circuit and prove it answers "complete" on truly
		// clean content.
		delete_option( 'mcpwp_custom_code_locations_repaired' );
		update_option( Mcpwp_Activator::CUSTOM_CODE_REPAIR_CURSOR_OPTION, 5, false );

		Mcpwp_Activator::repair_custom_code_locations();

		$this->assertSame( 1, get_option( 'mcpwp_custom_code_locations_repaired' ) );
	}
}
