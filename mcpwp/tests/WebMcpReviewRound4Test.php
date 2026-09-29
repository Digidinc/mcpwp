<?php
/**
 * #981 adversarial review round 4 — the approvals store can't be jammed.
 *
 *  BLOCK  closed records are compacted (no bodies); a capped requester
 *         holds at most its byte quota; an admin can always queue once
 *         open requests are decided; "purge closed approvals" (REST admin
 *         scope + Control Room). (#1042: the whole-store byte budget and
 *         its max_allowed_packet cap went away with the option — the one
 *         site-wide limit left is MAX_ITEMS open requests.)
 *  WARN-1 stale applying / rolling_back can be resolved by an admin.
 *  WARN-2 large-post proposals need ≥3 kept revisions; apply re-verifies
 *         the referenced revision first.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';

final class WebMcpReviewRound4Test extends TestCase {

	private const POST = 9850;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['_mcpwp_test_posts']       = array(
			self::POST => new WP_Post( array( 'ID' => self::POST, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'P', 'post_content' => 'original body' ) ),
		);
		unset( $GLOBALS['mcpwp_test_revisions_to_keep'], $GLOBALS['mcpwp_test_get_lock_results'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_revisions_to_keep'], $GLOBALS['mcpwp_test_get_lock_results'] );
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	private function store(): array {
		$items = mcpwp_test_approvals_all();
		return is_array( $items ) ? $items : array();
	}

	// ── BLOCK ──────────────────────────────────────────────────────────────

	public function test_closed_records_are_compacted_to_hashes_and_excerpts(): void {
		$created = Mcpwp_Approvals::create_post_content_request( self::POST, str_repeat( 'n', 50000 ), array( 'requested_by_key_id' => 'admin', 'requester_unrestricted' => true ) );
		$this->assertArrayHasKey( 'after_content', $this->store()[ $created['id'] ]['payload'] );

		Mcpwp_Approvals::reject_request( $created['id'] );
		$record = $this->store()[ $created['id'] ];
		$this->assertArrayNotHasKey( 'after_content', $record['payload'] );
		$this->assertArrayNotHasKey( 'before_content', $record['rollback'] );
		$this->assertNotEmpty( $record['rollback']['before_hash'] );
		$this->assertNotEmpty( $record['diff']['after_hash'] );
		$this->assertNotEmpty( $record['compacted'] );
		$this->assertLessThan( 4000, strlen( serialize( $record ) ), 'A closed record is tiny.' );
	}

	public function test_applied_records_keep_their_rollback_until_the_retention_passes(): void {
		$created = Mcpwp_Approvals::create_post_content_request( self::POST, 'new body', array( 'requested_by_key_id' => 'admin', 'requester_unrestricted' => true ) );
		Mcpwp_Approvals::approve_request( $created['id'] );
		Mcpwp_Approvals::apply_request( $created['id'] );
		$this->assertArrayHasKey( 'before_content', $this->store()[ $created['id'] ]['rollback'], 'Rollback still possible.' );

		add_filter(
			'mcpwp_approval_applied_retention',
			static function () {
				return -1;
			}
		);
		Mcpwp_Approvals::purge_closed(); // Any write compacts; purge also removes it.
		$this->assertArrayNotHasKey( $created['id'], $this->store() );
	}

	public function test_the_jam_is_cleared_by_rejecting_and_an_admin_can_always_queue(): void {
		// A queue at the open-request cap (MAX_ITEMS), filled by capped keys.
		$items = array();
		for ( $i = 0; $i < Mcpwp_Approvals::MAX_ITEMS; $i++ ) {
			$id           = sprintf( 'apr_jam%03d', $i );
			$items[ $id ] = array( 'id' => $id, 'status' => 'pending', 'action' => 'destructive_operation', 'requested_by_key_id' => 'editor-' . $i, 'created_at' => gmdate( 'c' ), 'updated_at' => gmdate( 'c' ) );
		}
		mcpwp_test_approvals_put( $items );

		// Another capped key is refused by count.
		$full = Mcpwp_Approvals::create_destructive_request( 'delete_page', array( 'route' => '/mcpwp/v1/pages/1', 'method' => 'DELETE', 'params' => array() ), array( 'type' => 'page', 'id' => 1 ), array( 'requested_by_key_id' => 'editor-new' ) );
		$this->assertInstanceOf( WP_Error::class, $full );
		$this->assertSame( 'approval_queue_full', $full->get_error_code() );
		$this->assertSame( 429, $full->get_error_data()['status'] );

		// The site's administrator never is (#1042 review round 1, B1).
		$this->assertIsArray( Mcpwp_Approvals::create_destructive_request( 'delete_page', array( 'route' => '/mcpwp/v1/pages/1', 'method' => 'DELETE', 'params' => array() ), array( 'type' => 'page', 'id' => 1 ), array( 'requested_by_key_id' => 'wp-user:1' ) ) );

		// Rejecting frees capped room again; closed history does not count.
		$capped = Mcpwp_Approvals::create_post_content_request( self::POST, 'x', array( 'requested_by_key_id' => 'editor-new' ) );
		$this->assertSame( 'approval_queue_full', $capped->get_error_code() );
		$this->assertIsArray( Mcpwp_Approvals::reject_request( 'apr_jam000' ) );
		$this->assertIsArray( Mcpwp_Approvals::reject_request( 'apr_jam001' ) );
		$ok = Mcpwp_Approvals::create_post_content_request( self::POST, 'x', array( 'requested_by_key_id' => 'editor-new' ) );
		$this->assertIsArray( $ok, is_wp_error( $ok ) ? $ok->get_error_code() : '' );
	}

	public function test_history_is_never_destroyed_to_make_room(): void {
		// Round 5 refused a new request (507) rather than evict an applied
		// one inside its rollback window. #1042: there is no store budget
		// left to run out of — both are kept.
		$applied = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$c = Mcpwp_Approvals::create_post_content_request( self::POST, str_repeat( 'q', 18000 ), array( 'requested_by_key_id' => 'admin', 'requester_unrestricted' => true ) );
			Mcpwp_Approvals::approve_request( $c['id'] );
			Mcpwp_Approvals::apply_request( $c['id'] );
			$applied[] = $c['id'];
			$GLOBALS['_mcpwp_test_posts'][ self::POST ]->post_content = 'original body';
		}
		$result = Mcpwp_Approvals::create_post_content_request( self::POST, str_repeat( 'r', 600000 ), array( 'requested_by_key_id' => 'admin', 'requester_unrestricted' => true ) );
		$this->assertIsArray( $result );
		foreach ( $applied as $id ) {
			$this->assertArrayHasKey( 'before_content', $this->store()[ $id ]['rollback'], 'An applied request inside its rollback window keeps its rollback.' );
		}
	}

	public function test_one_capped_requester_is_held_to_its_byte_quota(): void {
		$this->assertSame( Mcpwp_Approvals::REQUESTER_BYTE_QUOTA, Mcpwp_Approvals::requester_byte_quota() );
		add_filter(
			'mcpwp_approval_content_limits',
			static function ( $limits ) {
				$limits['requester_bytes'] = 20000;
				return $limits;
			}
		);
		$this->assertSame( 20000, Mcpwp_Approvals::requester_byte_quota() );

		$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, str_repeat( 'a', 8000 ), array( 'requested_by_key_id' => 'webmcp:user:5' ) ) );
		$over = Mcpwp_Approvals::create_post_content_request( self::POST, str_repeat( 'b', 12000 ), array( 'requested_by_key_id' => 'webmcp:user:5' ) );
		$this->assertSame( 'requester_store_quota', $over->get_error_code() );
		$this->assertSame( 429, $over->get_error_data()['status'] );

		// Someone else, and an unrestricted key, are unaffected.
		$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, str_repeat( 'c', 12000 ), array( 'requested_by_key_id' => 'webmcp:user:6' ) ) );
		$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, str_repeat( 'd', 30000 ), array( 'requested_by_key_id' => 'admin', 'requester_unrestricted' => true ) ) );
	}

	public function test_purge_closed_removes_only_decided_requests(): void {
		$a = Mcpwp_Approvals::create_post_content_request( self::POST, 'a', array( 'requested_by_key_id' => 'k1' ) );
		$b = Mcpwp_Approvals::create_post_content_request( self::POST, 'b', array( 'requested_by_key_id' => 'k1' ) );
		Mcpwp_Approvals::reject_request( $a['id'] );

		$this->assertSame( 1, Mcpwp_Approvals::purge_closed() );
		$this->assertArrayNotHasKey( $a['id'], $this->store() );
		$this->assertArrayHasKey( $b['id'], $this->store() );
	}

	public function test_purge_and_resolve_routes_need_admin_scope(): void {
		$harness = new class() {
			use Mcpwp_Api_Auth {
				get_required_scope_for_request as public;
			}
		};
		$this->assertSame( 'admin', $harness->get_required_scope_for_request( new WP_REST_Request( 'POST', '/mcpwp/v1/approvals/purge-closed' ) ) );
		$this->assertSame( 'admin', $harness->get_required_scope_for_request( new WP_REST_Request( 'POST', '/mcpwp/v1/approvals/apr_x/resolve' ) ) );
	}

	// ── WARN-1 ─────────────────────────────────────────────────────────────

	private function stuck( string $status ): string {
		$created = Mcpwp_Approvals::create_post_content_request( self::POST, 'new body', array( 'requested_by_key_id' => 'k1' ) );
		$items   = mcpwp_test_approvals_all();
		$items[ $created['id'] ]['status']          = $status;
		$items[ $created['id'] ][ $status . '_at' ] = gmdate( 'c', time() - 3600 );
		mcpwp_test_approvals_put( $items );
		return $created['id'];
	}

	public function test_a_stale_apply_marked_completed_becomes_applied_and_can_be_rolled_back(): void {
		$id = $this->stuck( 'applying' );
		$GLOBALS['_mcpwp_test_posts'][ self::POST ]->post_content = 'new body'; // It did land.

		$resolved = Mcpwp_Approvals::resolve_stale( $id, 'completed', array( 'actor' => 'wp-user:1' ) );
		$this->assertSame( 'applied', $resolved['status'] );
		$this->assertSame( 'completed', $resolved['resolved']['outcome'] );

		$this->assertSame( 'rolled_back', Mcpwp_Approvals::rollback_request( $id )['status'] );
		$this->assertSame( 'original body', $GLOBALS['_mcpwp_test_posts'][ self::POST ]->post_content );
	}

	public function test_a_stale_apply_marked_not_completed_goes_back_to_approved_with_a_warning(): void {
		$id       = $this->stuck( 'applying' );
		$resolved = Mcpwp_Approvals::resolve_stale( $id, 'not_completed' );
		$this->assertSame( 'approved', $resolved['status'] );
		$this->assertStringContainsString( 'Check the post content', $resolved['warning'] );
	}

	public function test_a_stale_rollback_can_be_resolved_both_ways(): void {
		$this->assertSame( 'rolled_back', Mcpwp_Approvals::resolve_stale( $this->stuck( 'rolling_back' ), 'completed' )['status'] );
		$this->assertSame( 'applied', Mcpwp_Approvals::resolve_stale( $this->stuck( 'rolling_back' ), 'not_completed' )['status'] );
	}

	public function test_resolve_refuses_a_fresh_claim_and_anything_not_in_flight(): void {
		$created = Mcpwp_Approvals::create_post_content_request( self::POST, 'x', array( 'requested_by_key_id' => 'k1' ) );
		$this->assertSame( 'approval_not_in_flight', Mcpwp_Approvals::resolve_stale( $created['id'], 'completed' )->get_error_code() );

		$items = mcpwp_test_approvals_all();
		$items[ $created['id'] ]['status']      = 'applying';
		$items[ $created['id'] ]['applying_at'] = gmdate( 'c' );
		mcpwp_test_approvals_put( $items );
		$this->assertSame( 'approval_in_progress', Mcpwp_Approvals::resolve_stale( $created['id'], 'completed' )->get_error_code() );
		$this->assertSame( 'invalid_outcome', Mcpwp_Approvals::resolve_stale( $this->stuck( 'applying' ), 'maybe' )->get_error_code() );
	}

	// ── WARN-2 ─────────────────────────────────────────────────────────────

	public function test_large_post_proposals_need_enough_kept_revisions(): void {
		$GLOBALS['_mcpwp_test_posts'][ self::POST ]->post_content = str_repeat( 'L', Mcpwp_Approvals::MAX_ROLLBACK_INLINE_BYTES + 1 );
		foreach ( array( 0, 1, 2 ) as $keep ) {
			$GLOBALS['mcpwp_test_revisions_to_keep'] = $keep;
			$this->assertSame( 'rollback_unavailable', Mcpwp_Approvals::create_post_content_request( self::POST, 'x', array( 'requested_by_key_id' => 'k1' ) )->get_error_code(), "keep=$keep" );
		}
		foreach ( array( 3, -1 ) as $keep ) {
			$GLOBALS['mcpwp_test_revisions_to_keep'] = $keep;
			$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, 'x', array( 'requested_by_key_id' => 'k' . $keep ) ), "keep=$keep" );
		}
	}

	public function test_apply_refuses_when_the_rollback_revision_is_gone(): void {
		$big = str_repeat( 'M', Mcpwp_Approvals::MAX_ROLLBACK_INLINE_BYTES + 1 );
		$GLOBALS['_mcpwp_test_posts'][ self::POST ]->post_content = $big;
		$created = Mcpwp_Approvals::create_post_content_request( self::POST, 'small', array( 'requested_by_key_id' => 'k1' ) );
		Mcpwp_Approvals::approve_request( $created['id'] );

		unset( $GLOBALS['_mcpwp_test_posts'][ mcpwp_test_approvals_all()[ $created['id'] ]['rollback']['before_revision_id'] ] );
		$result = Mcpwp_Approvals::apply_request( $created['id'] );
		$this->assertSame( 'rollback_source_missing', $result->get_error_code() );
		$this->assertSame( $big, $GLOBALS['_mcpwp_test_posts'][ self::POST ]->post_content, 'Not applied.' );
		$this->assertSame( 'approved', mcpwp_test_approvals_all()[ $created['id'] ]['status'] );
	}
}
