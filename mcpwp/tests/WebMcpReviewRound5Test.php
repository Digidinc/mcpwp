<?php
/**
 * #981 adversarial review round 5 — removal never destroys live history.
 *
 *  - #1042: there is no store budget to evict for. The daily retention
 *    removes only closed records decided more than 90 days ago;
 *    pending/approved/in-flight never are, applied-within-rollback-window
 *    records keep their bodies.
 *  - Every removal (retention or purge) first writes an action-log audit row;
 *    no row, no removal.
 *  - Rollback Ready lists only what can still be rolled back.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';

final class WebMcpReviewRound5Test extends TestCase {

	private const POST_A = 9860;
	private const POST_B = 9861;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_wpdb_rows']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['_mcpwp_test_posts']       = array(
			self::POST_A => new WP_Post( array( 'ID' => self::POST_A, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'A', 'post_content' => 'A original' ) ),
			self::POST_B => new WP_Post( array( 'ID' => self::POST_B, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'B', 'post_content' => 'B original' ) ),
		);
		unset( $GLOBALS['mcpwp_test_wpdb_insert_fail'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_wpdb_insert_fail'] );
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	private function store(): array {
		$items = mcpwp_test_approvals_all();
		return is_array( $items ) ? $items : array();
	}

	private function apply( int $post, string $content ): string {
		$c = Mcpwp_Approvals::create_post_content_request( $post, $content, array( 'requested_by_key_id' => 'admin', 'requester_unrestricted' => true ) );
		Mcpwp_Approvals::approve_request( $c['id'], '', array( 'actor' => 'admin-2' ) );
		$this->assertSame( 'applied', Mcpwp_Approvals::apply_request( $c['id'] )['status'] );
		return $c['id'];
	}

	/** Age a record's decision past retention. */
	private function age( string $id, int $days ): void {
		$items                             = mcpwp_test_approvals_all();
		$items[ $id ]['updated_at']        = gmdate( 'c', time() - $days * DAY_IN_SECONDS );
		if ( ! empty( $items[ $id ]['applied_at'] ) ) {
			$items[ $id ]['applied_at'] = $items[ $id ]['updated_at'];
		}
		mcpwp_test_approvals_put( $items );
	}

	private function audit_rows(): array {
		$rows = array();
		foreach ( $GLOBALS['mcpwp_test_wpdb_rows'] as $table => $table_rows ) {
			foreach ( (array) $table_rows as $row ) {
				if ( is_array( $row ) && isset( $row['tool_name'] ) && 'approval_record_removed' === $row['tool_name'] ) {
					$rows[] = $row;
				}
			}
		}
		return $rows;
	}

	public function test_reviewer_scenario_recently_applied_approvals_survive_new_proposals(): void {
		$a = $this->apply( self::POST_A, str_repeat( 'a', 30000 ) );
		$b = $this->apply( self::POST_B, str_repeat( 'b', 30000 ) );

		add_filter(
			'mcpwp_approval_content_limits',
			static function ( $limits ) {
				$limits['requester_bytes'] = 1000000;
				return $limits;
			}
		);
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST_A, str_repeat( 'c', 200000 ), array( 'requested_by_key_id' => 'webmcp:user:' . ( 3 + $i ) ) ) );
		}
		Mcpwp_Approvals::run_retention();

		$this->assertArrayHasKey( $a, $this->store() );
		$this->assertArrayHasKey( $b, $this->store() );
		$this->assertSame( array(), $this->audit_rows() );

		// Rollback still works for both.
		$this->assertSame( 'rolled_back', Mcpwp_Approvals::rollback_request( $b, array( 'actor' => 'admin-3' ) )['status'] );
		$this->assertSame( 'admin-3', $this->store()[ $b ]['rolled_back_by'] );
		$this->assertSame( 'admin-2', $this->store()[ $b ]['approved_by'] );
		$this->assertArrayHasKey( 'applied_by', $this->store()[ $b ] );
		$this->assertSame( 'B original', $GLOBALS['_mcpwp_test_posts'][ self::POST_B ]->post_content );
	}

	public function test_only_closed_records_past_retention_are_removed_and_each_is_audited(): void {
		$old     = Mcpwp_Approvals::create_post_content_request( self::POST_A, str_repeat( 'x', 20000 ), array( 'requested_by_key_id' => 'k-old' ) );
		$fresh   = Mcpwp_Approvals::create_post_content_request( self::POST_B, str_repeat( 'y', 20000 ), array( 'requested_by_key_id' => 'k-fresh' ) );
		$pending = Mcpwp_Approvals::create_post_content_request( self::POST_A, str_repeat( 'z', 20000 ), array( 'requested_by_key_id' => 'k-pend' ) );
		Mcpwp_Approvals::reject_request( $old['id'], '', array( 'actor' => 'reviewer-1' ) );
		Mcpwp_Approvals::reject_request( $fresh['id'], '', array( 'actor' => 'reviewer-1' ) );
		$this->age( $old['id'], 91 );
		$this->age( $fresh['id'], 10 );

		$this->assertSame( 1, Mcpwp_Approvals::run_retention() );

		$this->assertArrayNotHasKey( $old['id'], $this->store() );
		$this->assertArrayHasKey( $fresh['id'], $this->store(), 'A recent rejection stays for its retention.' );
		$this->assertArrayHasKey( $pending['id'], $this->store() );

		$rows = $this->audit_rows();
		$this->assertCount( 1, $rows );
		$summary = json_decode( $rows[0]['arguments'], true );
		$this->assertSame( $old['id'], $summary['approval_id'] );
		$this->assertSame( 'post_content_update', $summary['action'] );
		$this->assertSame( 'post', $summary['resource_type'] );
		$this->assertSame( (string) self::POST_A, $summary['resource_id'] );
		$this->assertSame( 'k-old', $summary['requester'] );
		$this->assertSame( 'reviewer-1', $summary['approvers']['rejected_by'] );
		$this->assertSame( 'rejected', $summary['final_status'] );
		$this->assertNotEmpty( $summary['created_at'] );
		$this->assertNotEmpty( $summary['decided_at'] );
		$this->assertSame( hash( 'sha256', str_repeat( 'x', 20000 ) ), $summary['content_hash'] );
		$this->assertSame( 'retention', $summary['removal_reason'] );
		$this->assertSame( 'approval', $rows[0]['resource_type'] );
		$this->assertSame( $old['id'], $rows[0]['resource_id'] );
	}

	public function test_purge_audits_every_removal_and_keeps_applied_within_retention(): void {
		$applied   = $this->apply( self::POST_A, 'applied body' );
		$rejected  = Mcpwp_Approvals::create_post_content_request( self::POST_B, 'r', array( 'requested_by_key_id' => 'k1' ) );
		Mcpwp_Approvals::reject_request( $rejected['id'] );
		$destroyed = Mcpwp_Approvals::create_destructive_request( 'delete_page', array( 'route' => '/mcpwp/v1/pages/9', 'method' => 'DELETE', 'params' => array() ), array( 'type' => 'page', 'id' => 9 ), array( 'requested_by_key_id' => 'wp-user:1' ) );
		Mcpwp_Approvals::reject_request( $destroyed['id'] );

		$this->assertSame( 2, Mcpwp_Approvals::purge_closed() );
		$this->assertArrayHasKey( $applied, $this->store(), 'Still inside its rollback window.' );

		$ids = array_map(
			static function ( $row ) {
				return json_decode( $row['arguments'], true )['approval_id'];
			},
			$this->audit_rows()
		);
		sort( $ids );
		$expected = array( $rejected['id'], $destroyed['id'] );
		sort( $expected );
		$this->assertSame( $expected, $ids );
		$destructive_summary = json_decode( $this->audit_rows()[1]['arguments'], true );
		$this->assertSame( 'purged', $destructive_summary['removal_reason'] );
	}

	public function test_no_audit_row_means_no_removal(): void {
		$rejected = Mcpwp_Approvals::create_post_content_request( self::POST_B, 'r', array( 'requested_by_key_id' => 'k1' ) );
		Mcpwp_Approvals::reject_request( $rejected['id'] );

		global $wpdb;
		$GLOBALS['mcpwp_test_wpdb_insert_fail'][ $wpdb->prefix . 'mcpwp_action_log' ] = true;
		$this->assertSame( 0, Mcpwp_Approvals::purge_closed() );
		$this->assertArrayHasKey( $rejected['id'], $this->store() );
	}

	public function test_rollback_ready_lists_only_what_can_still_be_rolled_back(): void {
		$id = $this->apply( self::POST_A, 'applied body' );
		$this->assertTrue( Mcpwp_Approvals::get_request( $id )['rollback_available'] );

		// Past its rollback window: compacted by the daily retention run,
		// inline copy gone.
		$this->age( $id, 10 );
		Mcpwp_Approvals::run_retention();
		$record = Mcpwp_Approvals::get_request( $id );
		$this->assertNotEmpty( $this->store()[ $id ]['compacted'] );
		$this->assertFalse( $record['rollback_available'] );

		// The Control Room list uses the same flag.
		$ready = array_filter(
			Mcpwp_Approvals::list_requests( '', 100, true ),
			static function ( $approval ) {
				return isset( $approval['status'] ) && in_array( $approval['status'], array( 'applied', 'apply_failed' ), true ) && ! empty( $approval['rollback_available'] );
			}
		);
		$this->assertSame( array(), array_values( $ready ) );
	}
}
