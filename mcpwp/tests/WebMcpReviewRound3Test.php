<?php
/**
 * #981 adversarial review round 3 — the approvals audit trail.
 *
 *  BLOCK-1 apply/rollback are a claim state machine: approved → applying →
 *          applied (rolling_back → rolled_back). A parallel apply sees the
 *          claim and runs nothing; a payload failure returns the record to
 *          approved; a finalise that cannot get the lock never reports
 *          "nothing changed" — 202 approval_record_unconfirmed, record left
 *          in flight and flagged needs_attention. record_apply_result()
 *          failures propagate.
 *  BLOCK-2 a failed update_option() is a failure, not a success; a body
 *          over 256 KB is referenced by revision, not copied; the whole
 *          store has a byte budget for new requests.
 *  NIT     meta keys gated case-insensitively against registered keys.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-content-guardrails.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

final class WebMcpReviewRound3Test extends TestCase {

	private const POST = 9840;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']          = array();
		$GLOBALS['mcpwp_test_transients']       = array();
		$GLOBALS['mcpwp_test_filters']          = array();
		$GLOBALS['mcpwp_test_named_lock_calls'] = array();
		$GLOBALS['mcpwp_test_current_user']     = 1;
		$GLOBALS['mcpwp_test_user_caps']        = array();
		$GLOBALS['_mcpwp_test_posts']           = array(
			self::POST => new WP_Post( array( 'ID' => self::POST, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'P', 'post_content' => 'original body' ) ),
		);
		unset( $GLOBALS['mcpwp_test_get_lock_result'], $GLOBALS['mcpwp_test_get_lock_results'], $GLOBALS['mcpwp_test_on_lock_granted'], $GLOBALS['mcpwp_test_update_option_fail'], $GLOBALS['mcpwp_test_revisions_disabled'], $GLOBALS['_mcpwp_test_registered_meta'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_get_lock_result'], $GLOBALS['mcpwp_test_get_lock_results'], $GLOBALS['mcpwp_test_on_lock_granted'], $GLOBALS['mcpwp_test_update_option_fail'], $GLOBALS['mcpwp_test_revisions_disabled'], $GLOBALS['_mcpwp_test_registered_meta'] );
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		Mcpwp_WebMCP::end_dispatch( 0 );
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
	}

	private function approved_request( string $content = 'new body' ): string {
		$created = Mcpwp_Approvals::create_post_content_request( self::POST, $content, array( 'requested_by_key_id' => 'k1' ) );
		$this->assertIsArray( $created );
		$this->assertIsArray( Mcpwp_Approvals::approve_request( $created['id'] ) );
		return $created['id'];
	}

	private function stored( string $id ): array {
		return get_option( Mcpwp_Approvals::OPTION_NAME )[ $id ];
	}

	private function body(): string {
		return $GLOBALS['_mcpwp_test_posts'][ self::POST ]->post_content;
	}

	// ── BLOCK-1 ────────────────────────────────────────────────────────────

	public function test_apply_goes_approved_applying_applied_and_rollback_through_rolling_back(): void {
		$id = $this->approved_request();

		$applied = Mcpwp_Approvals::apply_request( $id );
		$this->assertSame( 'applied', $applied['status'] );
		$this->assertSame( 'new body', $this->body() );
		$this->assertNotEmpty( $this->stored( $id )['applying_at'], 'The claim was recorded before the payload ran.' );

		$rolled = Mcpwp_Approvals::rollback_request( $id );
		$this->assertSame( 'rolled_back', $rolled['status'] );
		$this->assertSame( 'original body', $this->body() );
	}

	public function test_a_parallel_apply_sees_the_claim_and_runs_nothing(): void {
		$id = $this->approved_request();

		// Another request has claimed it (status applying) and is mid-payload.
		$items                  = get_option( Mcpwp_Approvals::OPTION_NAME );
		$items[ $id ]['status'] = 'applying';
		$items[ $id ]['applying_at'] = gmdate( 'c' );
		update_option( Mcpwp_Approvals::OPTION_NAME, $items, false );

		$second = Mcpwp_Approvals::apply_request( $id );
		$this->assertSame( 'approval_in_progress', $second->get_error_code() );
		$this->assertSame( 409, $second->get_error_data()['status'] );
		$this->assertSame( 'original body', $this->body(), 'The payload did not run a second time.' );
	}

	public function test_the_claim_is_taken_under_the_lock_against_a_fresh_read(): void {
		$id = $this->approved_request();

		// A parallel apply claims it just before ours gets the lock.
		$GLOBALS['mcpwp_test_on_lock_granted'] = static function () use ( $id ) {
			$items                  = get_option( Mcpwp_Approvals::OPTION_NAME );
			$items[ $id ]['status'] = 'applying';
			update_option( Mcpwp_Approvals::OPTION_NAME, $items, false );
		};
		$this->assertSame( 'approval_in_progress', Mcpwp_Approvals::apply_request( $id )->get_error_code() );
		$this->assertSame( 'original body', $this->body() );
	}

	public function test_a_payload_failure_returns_the_record_to_approved(): void {
		$id = $this->approved_request();
		$GLOBALS['_mcpwp_test_posts'][ self::POST ]->post_content = 'edited meanwhile';

		$result = Mcpwp_Approvals::apply_request( $id );
		$this->assertSame( 'approval_content_conflict', $result->get_error_code() );

		$stored = $this->stored( $id );
		$this->assertSame( 'approved', $stored['status'], 'Nothing ran, so it can be retried.' );
		$this->assertSame( 'approval_content_conflict', $stored['last_error']['code'] );
	}

	public function test_a_finalise_lock_timeout_never_claims_nothing_changed(): void {
		$id = $this->approved_request();

		// Claim gets the lock; every finalise attempt times out.
		$GLOBALS['mcpwp_test_get_lock_results'] = array( '1', '0', '0', '0', '0' );
		$result = Mcpwp_Approvals::apply_request( $id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'approval_record_unconfirmed', $result->get_error_code() );
		$this->assertSame( 202, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'WAS applied', $result->get_error_message() );
		$this->assertStringNotContainsString( 'Nothing was changed', $result->get_error_message() );
		$this->assertSame( 'new body', $this->body(), 'The change did happen.' );

		unset( $GLOBALS['mcpwp_test_get_lock_results'] );
		$this->assertSame( 'applying', $this->stored( $id )['status'], 'Left in flight for a person.' );

		// Not re-applied by a retry; surfaced once stale.
		$this->assertSame( 'approval_in_progress', Mcpwp_Approvals::apply_request( $id )->get_error_code() );
		add_filter(
			'mcpwp_approval_claim_stale_after',
			static function () {
				return -1;
			}
		);
		$this->assertSame( 'stale_applying', Mcpwp_Approvals::get_request( $id )['needs_attention'] );
	}

	public function test_a_finalise_retry_recovers_from_a_brief_lock_timeout(): void {
		$id = $this->approved_request();
		$GLOBALS['mcpwp_test_get_lock_results'] = array( '1', '0', '1' );
		$this->assertSame( 'applied', Mcpwp_Approvals::apply_request( $id )['status'] );
	}

	public function test_record_apply_result_propagates_a_store_failure(): void {
		$method = new ReflectionMethod( Mcpwp_Content_Guardrails::class, 'record_apply_result' );
		$method->setAccessible( true );

		$id = $this->approved_request();
		$GLOBALS['mcpwp_test_get_lock_result'] = '0';
		$result = $method->invoke( null, $id, array( 1, 2 ), array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'approvals_busy', $result->get_error_code() );

		unset( $GLOBALS['mcpwp_test_get_lock_result'] );
		$this->assertTrue( $method->invoke( null, $id, array( 1, 2 ), array() ) );
		$this->assertSame( array( 1, 2 ), $this->stored( $id )['metadata']['applied_ids'] );
	}

	// ── BLOCK-2 ────────────────────────────────────────────────────────────

	public function test_a_failed_update_option_is_reported_as_a_failure(): void {
		$GLOBALS['mcpwp_test_update_option_fail'][ Mcpwp_Approvals::OPTION_NAME ] = true;
		$result = Mcpwp_Approvals::create_post_content_request( self::POST, 'x', array( 'requested_by_key_id' => 'k1' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'approvals_store_failed', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );

		unset( $GLOBALS['mcpwp_test_update_option_fail'] );
		$id = $this->approved_request();
		$GLOBALS['mcpwp_test_update_option_fail'][ Mcpwp_Approvals::OPTION_NAME ] = true;
		$this->assertSame( 'approvals_store_failed', Mcpwp_Approvals::reject_request( $id )->get_error_code() );
		$this->assertSame( 'approved', $this->stored( $id )['status'] );
	}

	public function test_a_large_target_is_referenced_by_revision_not_copied(): void {
		$big = str_repeat( 'B', Mcpwp_Approvals::MAX_ROLLBACK_INLINE_BYTES + 1 );
		$GLOBALS['_mcpwp_test_posts'][ self::POST ]->post_content = $big;

		$created = Mcpwp_Approvals::create_post_content_request( self::POST, 'small replacement', array( 'requested_by_key_id' => 'k1' ) );
		$this->assertIsArray( $created );
		$stored = $this->stored( $created['id'] );
		$this->assertArrayNotHasKey( 'before_content', $stored['rollback'] );
		$this->assertGreaterThan( 0, $stored['rollback']['before_revision_id'] );
		$this->assertSame( hash( 'sha256', $big ), $stored['rollback']['before_hash'] );
		$this->assertLessThanOrEqual( 1000, strlen( $stored['rollback']['before_excerpt'] ) );
		$this->assertLessThan( 20000, strlen( serialize( get_option( Mcpwp_Approvals::OPTION_NAME ) ) ), 'The store does not carry the big body.' );
		$this->assertArrayNotHasKey( 'before_excerpt', $created['rollback'] ?? array(), 'Not in the public record.' );

		// Apply, then roll back from the revision.
		Mcpwp_Approvals::approve_request( $created['id'] );
		Mcpwp_Approvals::apply_request( $created['id'] );
		$this->assertSame( 'small replacement', $this->body() );
		$this->assertSame( 'rolled_back', Mcpwp_Approvals::rollback_request( $created['id'] )['status'] );
		$this->assertSame( $big, $this->body() );
	}

	public function test_rollback_refuses_cleanly_when_the_revision_is_gone(): void {
		$big = str_repeat( 'C', Mcpwp_Approvals::MAX_ROLLBACK_INLINE_BYTES + 10 );
		$GLOBALS['_mcpwp_test_posts'][ self::POST ]->post_content = $big;
		$created = Mcpwp_Approvals::create_post_content_request( self::POST, 'replacement', array( 'requested_by_key_id' => 'k1' ) );
		Mcpwp_Approvals::approve_request( $created['id'] );
		Mcpwp_Approvals::apply_request( $created['id'] );

		unset( $GLOBALS['_mcpwp_test_posts'][ $this->stored( $created['id'] )['rollback']['before_revision_id'] ] );
		$result = Mcpwp_Approvals::rollback_request( $created['id'] );
		$this->assertSame( 'rollback_source_missing', $result->get_error_code() );
		$this->assertSame( 'replacement', $this->body() );
		$this->assertSame( 'applied', $this->stored( $created['id'] )['status'], 'Back to applied; nothing ran.' );
	}

	public function test_a_large_target_without_revisions_is_refused_at_creation(): void {
		$GLOBALS['mcpwp_test_revisions_disabled'] = true;
		$GLOBALS['_mcpwp_test_posts'][ self::POST ]->post_content = str_repeat( 'D', Mcpwp_Approvals::MAX_ROLLBACK_INLINE_BYTES + 1 );
		$result = Mcpwp_Approvals::create_post_content_request( self::POST, 'x', array( 'requested_by_key_id' => 'k1' ) );
		$this->assertSame( 'rollback_unavailable', $result->get_error_code() );
		$this->assertFalse( get_option( Mcpwp_Approvals::OPTION_NAME, false ) );
	}

	public function test_the_store_has_a_byte_budget_for_new_requests_only(): void {
		$id = $this->approved_request();
		add_filter(
			'mcpwp_approval_store_max_bytes',
			static function () {
				return 2000;
			}
		);

		foreach ( array( array( 'requested_by_key_id' => 'k1' ), array( 'requested_by_key_id' => 'admin', 'requester_unrestricted' => true ), array( 'requested_by_key_id' => 'wp-user:1' ) ) as $args ) {
			$result = Mcpwp_Approvals::create_post_content_request( self::POST, str_repeat( 'x', 3000 ), $args );
			// A capped requester now hits its own 1/20 byte quota first (round 4).
			$this->assertContains( $result->get_error_code(), empty( $args['requester_unrestricted'] ) && 0 !== strpos( $args['requested_by_key_id'], 'wp-user:' ) ? array( 'approvals_store_full', 'requester_store_quota' ) : array( 'approvals_store_full' ), wp_json_encode( $args ) );
			$this->assertContains( $result->get_error_data()['status'], array( 507, 429 ) );
		}

		// Existing requests can still be worked down.
		$this->assertSame( 'rejected', Mcpwp_Approvals::reject_request( $id )['status'] );
	}

	// ── NIT ────────────────────────────────────────────────────────────────

	public function test_meta_gate_is_case_insensitive_against_registered_keys(): void {
		$GLOBALS['_mcpwp_test_registered_meta'] = array( 'featured_flag' => array() );
		add_filter(
			'auth_post_meta_featured_flag_for_post',
			static function () {
				return false;
			},
			10,
			6
		);

		foreach ( array( 'Featured_Flag', 'FEATURED_FLAG', 'featured_flag' ) as $spelling ) {
			$this->assertFalse( Mcpwp_WebMCP::meta_key_allowed( $spelling, 'post' ), $spelling );
		}
		$this->assertTrue( Mcpwp_WebMCP::meta_key_allowed( 'Mood', 'post' ) );
	}
}
