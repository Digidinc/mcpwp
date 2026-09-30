<?php
/**
 * #1042 — the approvals store is its own table.
 *
 * One row per approval (`record` holds the whole record, JSON-encoded,
 * exactly as the old option held it), written by optimistic per-row
 * updates; creates count and insert under a short named lock; the old
 * option is migrated once and renamed, never read again; closed records
 * are kept 90 days and removed by a daily cron that audits first.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-approvals.php';

final class ApprovalsTableTest extends TestCase {

	private const POST = 10420;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']          = array();
		$GLOBALS['mcpwp_test_options_autoload'] = array();
		$GLOBALS['mcpwp_test_wpdb_options']     = array();
		$GLOBALS['mcpwp_test_transients']       = array();
		$GLOBALS['mcpwp_test_filters']          = array();
		$GLOBALS['mcpwp_test_actions']          = array();
		$GLOBALS['mcpwp_test_wpdb_rows']        = array();
		$GLOBALS['mcpwp_test_named_lock_calls'] = array();
		$GLOBALS['mcpwp_test_user_caps']        = array( 1 => array( 'manage_options' => true ) ); // wp-user:1 is an administrator.
		$GLOBALS['mcpwp_test_current_user']     = 1;
		$GLOBALS['_mcpwp_test_posts']           = array(
			self::POST => new WP_Post( array( 'ID' => self::POST, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'P', 'post_content' => 'original body' ) ),
		);
		unset( $GLOBALS['mcpwp_test_wpdb_insert_fail'], $GLOBALS['mcpwp_test_on_lock_granted'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_wpdb_insert_fail'], $GLOBALS['mcpwp_test_on_lock_granted'] );
		$GLOBALS['mcpwp_test_approvals_fail']          = null;
		$GLOBALS['mcpwp_test_approvals_before_update'] = null;
		$GLOBALS['mcpwp_test_options']                 = array();
		$GLOBALS['mcpwp_test_filters']                 = array();
		$GLOBALS['mcpwp_test_actions']                 = array();
		$GLOBALS['mcpwp_test_current_user']            = 0;
	}

	private function row( string $id ): array {
		return $GLOBALS['mcpwp_test_approvals_rows'][ $id ];
	}

	private function audit_rows(): array {
		$rows = array();
		foreach ( $GLOBALS['mcpwp_test_wpdb_rows'] as $table_rows ) {
			foreach ( (array) $table_rows as $row ) {
				if ( is_array( $row ) && isset( $row['tool_name'] ) && 'approval_record_removed' === $row['tool_name'] ) {
					$rows[] = $row;
				}
			}
		}
		return $rows;
	}

	private function legacy_items(): array {
		$revision = new WP_Post( array( 'ID' => 10421, 'post_type' => 'revision', 'post_parent' => self::POST, 'post_status' => 'inherit', 'post_content' => str_repeat( 'L', 140000 ) ) );
		$GLOBALS['_mcpwp_test_posts'][10421] = $revision;
		$now = gmdate( 'c' );

		return array(
			'apr_mig_pending'  => array( 'id' => 'apr_mig_pending', 'status' => 'pending', 'action' => 'post_content_update', 'requested_by_key_id' => 'k1', 'resource' => array( 'type' => 'post', 'id' => self::POST ), 'payload' => array( 'after_content' => 'x' ), 'rollback' => array( 'before_content' => 'original body', 'before_hash' => hash( 'sha256', 'original body' ) ), 'metadata' => array(), 'created_at' => $now, 'updated_at' => $now, 'approved_at' => null, 'triage' => array( 'priority' => 'high', 'change_kind' => 'content_edit', 'confidence' => 0.5, 'source' => 'jev', 'at' => $now ) ),
			'apr_mig_large'    => array( 'id' => 'apr_mig_large', 'status' => 'applied', 'action' => 'post_content_update', 'requested_by_key_id' => 'webmcp:user:5', 'resource' => array( 'type' => 'post', 'id' => self::POST ), 'payload' => array( 'after_content' => str_repeat( 'N', 140000 ) ), 'rollback' => array( 'before_revision_id' => 10421, 'before_hash' => hash( 'sha256', str_repeat( 'L', 140000 ) ), 'before_length' => 140000, 'before_excerpt' => str_repeat( 'L', 1000 ) ), 'diff' => array( 'after_hash' => hash( 'sha256', str_repeat( 'N', 140000 ) ) ), 'created_at' => $now, 'updated_at' => $now, 'applied_at' => $now, 'applied_by' => 'wp-user:1' ),
			'apr_mig_rejected' => array( 'id' => 'apr_mig_rejected', 'status' => 'rejected', 'action' => 'destructive_operation', 'operation' => 'delete_page', 'requested_by_key_id' => 'k2', 'resource' => array( 'type' => 'page', 'id' => 3, 'title' => 'Ünïcödé "quoted" \\ back\'slash' ), 'payload' => array( 'route' => '/mcpwp/v1/pages/3', 'method' => 'DELETE', 'params' => array() ), 'created_at' => $now, 'updated_at' => $now, 'rejected_at' => $now, 'compacted' => $now ),
			'apr_mig_staging'  => array( 'id' => 'apr_mig_staging', 'status' => 'approved', 'action' => 'staging_push', 'requested_by_key_id' => 'k3', 'resource' => array( 'type' => 'staging' ), 'visibility_categories' => array( 'staging' ), 'payload' => array( 'source' => 's', 'destination' => 'd', 'include_db' => true, 'include_files' => false ), 'created_at' => $now, 'updated_at' => $now ),
		);
	}

	// ── 1. Migration ────────────────────────────────────────────────────────

	public function test_migration_copies_every_record_verbatim_sets_the_flag_and_renames_the_option(): void {
		$items = $this->legacy_items();
		update_option( Mcpwp_Approvals::OPTION_NAME, $items, false );

		// A crashed earlier run had already copied one record.
		$GLOBALS['mcpwp_test_approvals_table_exists'] = true;
		Mcpwp_Approvals::import_legacy_items( array( 'apr_mig_staging' => $items['apr_mig_staging'] ) );

		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );

		$migrated = mcpwp_test_approvals_all();
		$expected = $items;
		ksort( $migrated );
		ksort( $expected );
		// Round 3: a record that stored no visibility_categories gets the
		// derived ones persisted into it; every other field is verbatim.
		// Round 4: only when the derivation is confident — the destructive
		// record targets page #3, which does not exist, so it stays pending
		// (nothing written into it; the column is fail-closed).
		foreach ( $expected as $id => $record ) {
			if ( empty( $record['visibility_categories'] ) && 'apr_mig_rejected' !== $id ) {
				$this->assertNotEmpty( $migrated[ $id ]['visibility_categories'], $id );
				$expected[ $id ]['visibility_categories'] = $migrated[ $id ]['visibility_categories'];
			}
			// The store's own bookkeeping marker is not part of the record's data.
			unset( $migrated[ $id ]['metadata']['categories_source'] );
			if ( ! isset( $record['metadata'] ) && array() === ( $migrated[ $id ]['metadata'] ?? null ) ) {
				unset( $migrated[ $id ]['metadata'] );
			}
			$this->assertEquals( $expected[ $id ], $migrated[ $id ], $id );
		}
		$this->assertSame( 1, (int) $this->row( 'apr_mig_rejected' )['categories_pending'] );
		$this->assertSame( '["__unresolvable__"]', $this->row( 'apr_mig_rejected' )['visibility_categories'] );
		$this->assertSame( array_keys( $expected ), array_keys( $migrated ) );
		$this->assertSame( array( 'apr_mig_staging', 'apr_mig_pending', 'apr_mig_large', 'apr_mig_rejected' ), array_keys( mcpwp_test_approvals_all() ), 'The rest in the option\'s order, after the one a crashed run had copied.' );
		$this->assertCount( 4, $GLOBALS['mcpwp_test_approvals_rows'] );
		$this->assertSame( 1, get_option( Mcpwp_Approvals::MIGRATED_FLAG ) );
		$this->assertFalse( get_option( Mcpwp_Approvals::OPTION_NAME, false ), 'The old option is gone under its old name.' );
		$this->assertSame( $items, get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION ), 'Kept, renamed, for recovery by hand.' );
		$this->assertFalse( $GLOBALS['mcpwp_test_options_autoload'][ Mcpwp_Approvals::LEGACY_BACKUP_OPTION ], 'The backup does not autoload.' );

		// Column copies agree with each record.
		$this->assertSame( 'applied', $this->row( 'apr_mig_large' )['status'] );
		$this->assertSame( 'webmcp:user:5', $this->row( 'apr_mig_large' )['requested_by_key_id'] );
		$this->assertSame( 2, $this->row( 'apr_mig_pending' )['triage_rank'] );

		// The large record, migrated with its revision reference, still rolls back.
		$this->assertTrue( Mcpwp_Approvals::get_request( 'apr_mig_large' )['rollback_available'] );
		$GLOBALS['_mcpwp_test_posts'][ self::POST ]->post_content = str_repeat( 'N', 140000 );
		$this->assertSame( 'rolled_back', Mcpwp_Approvals::rollback_request( 'apr_mig_large', array( 'actor' => 'wp-user:1' ) )['status'] );
		$this->assertSame( str_repeat( 'L', 140000 ), $GLOBALS['_mcpwp_test_posts'][ self::POST ]->post_content );

		// Re-running is a no-op.
		$before = $GLOBALS['mcpwp_test_approvals_rows'];
		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );
		delete_option( Mcpwp_Approvals::MIGRATED_FLAG );
		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );
		$this->assertSame( $before, $GLOBALS['mcpwp_test_approvals_rows'] );
	}

	public function test_the_store_answers_busy_while_another_request_is_migrating(): void {
		update_option( Mcpwp_Approvals::OPTION_NAME, $this->legacy_items(), false );
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Activator::APPROVALS_TABLE_LOCK_OPTION ] = (string) ( time() + 60 );

		foreach ( array(
			Mcpwp_Approvals::get_request( 'apr_mig_pending' ),
			Mcpwp_Approvals::list_requests(),
			Mcpwp_Approvals::approve_request( 'apr_mig_pending' ),
			Mcpwp_Approvals::create_post_content_request( self::POST, 'x', array( 'requested_by_key_id' => 'k9' ) ),
		) as $answer ) {
			$this->assertInstanceOf( WP_Error::class, $answer );
			$this->assertSame( 'approvals_busy', $answer->get_error_code() );
			$this->assertSame( array( 'status' => 503, 'retry_after' => 2 ), $answer->get_error_data() );
		}
		$this->assertFalse( get_option( Mcpwp_Approvals::MIGRATED_FLAG, false ) );
		$this->assertIsArray( get_option( Mcpwp_Approvals::OPTION_NAME ), 'Nothing moved while the other request holds the lock.' );
	}

	public function test_migration_takes_the_spai_queue_itself_when_the_bridge_has_not(): void {
		// 2.8.x: the queue is still under spai_approval_requests, and the
		// spai_ → mcpwp_ bridge never finished (it stopped on an unrelated
		// table, so mcpwp_migrated_from_spai is unset). Round 3: the
		// migration no longer waits on the whole bridge.
		$items = $this->legacy_items();
		update_option( 'spai_approval_requests', $items, false );

		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );
		$this->assertSame( 1, get_option( Mcpwp_Approvals::MIGRATED_FLAG ) );
		$this->assertCount( 4, mcpwp_test_approvals_all() );
		$this->assertSame( 'pending', Mcpwp_Approvals::get_request( 'apr_mig_pending' )['status'] );
		$this->assertIsArray( Mcpwp_Approvals::list_requests() );
		$this->assertSame( $items, get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION ) );
	}

	// ── 2. Concurrent apply ─────────────────────────────────────────────────

	public function test_two_concurrent_applies_leave_exactly_one_winner(): void {
		$created = Mcpwp_Approvals::create_post_content_request( self::POST, 'new body', array( 'requested_by_key_id' => 'k1' ) );
		$id      = $created['id'];
		Mcpwp_Approvals::approve_request( $id, '', array( 'actor' => 'reviewer' ) );

		$writes = 0;
		add_action(
			'post_updated',
			static function () use ( &$writes ) {
				$writes++;
			}
		);

		// The other apply claims and finishes between our read of the row
		// and our conditional write of the claim.
		$other = null;
		$GLOBALS['mcpwp_test_approvals_before_update'] = static function () use ( $id, &$other ) {
			$other = Mcpwp_Approvals::apply_request( $id, array( 'actor' => 'reviewer-2' ) );
		};
		$ours = Mcpwp_Approvals::apply_request( $id, array( 'actor' => 'reviewer-1' ) );

		$this->assertSame( 'applied', $other['status'] );
		$this->assertInstanceOf( WP_Error::class, $ours );
		$this->assertSame( 'approval_not_ready', $ours->get_error_code() );
		$this->assertSame( 409, $ours->get_error_data()['status'] );
		$this->assertSame( 1, $writes, 'The payload ran exactly once.' );
		$this->assertSame( 'reviewer-2', mcpwp_test_approvals_all()[ $id ]['applied_by'] );
	}

	// ── 3. Concurrent creates ───────────────────────────────────────────────

	public function test_ten_interleaved_creates_store_ten_rows(): void {
		// Every other create commits while the first holds the create lock
		// between its grant and its insert.
		mcpwp_test_approvals_put( array() ); // Migrated already: the one-time copy takes its own locks.
		$ids = array();
		$GLOBALS['mcpwp_test_on_lock_granted'] = static function () use ( &$ids ) {
			for ( $i = 1; $i < 10; $i++ ) {
				$ids[] = Mcpwp_Approvals::create_post_content_request( self::POST, "v$i", array( 'requested_by_key_id' => "k$i" ) )['id'];
			}
		};
		$ids[] = Mcpwp_Approvals::create_post_content_request( self::POST, 'v0', array( 'requested_by_key_id' => 'k0' ) )['id'];

		$this->assertCount( 10, array_unique( $ids ) );
		$this->assertSame( 10, count( mcpwp_test_approvals_all() ) );
		foreach ( $ids as $id ) {
			$this->assertArrayHasKey( $id, mcpwp_test_approvals_all() );
		}
		$this->assertSame( 10, count( array_keys( $GLOBALS['mcpwp_test_named_lock_calls'], 'get', true ) ) );
		$this->assertSame( 10, count( array_keys( $GLOBALS['mcpwp_test_named_lock_calls'], 'release', true ) ) );
	}

	// ── 4. Quotas ───────────────────────────────────────────────────────────

	public function test_the_twenty_first_pending_proposal_and_the_site_total_are_refused(): void {
		for ( $i = 0; $i < Mcpwp_Approvals::MAX_PENDING_CONTENT_PER_REQUESTER; $i++ ) {
			$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, "m$i", array( 'requested_by_key_id' => 'webmcp:user:7' ) ) );
		}
		$refused = Mcpwp_Approvals::create_post_content_request( self::POST, 'm21', array( 'requested_by_key_id' => 'webmcp:user:7' ) );
		$this->assertSame( 'too_many_pending', $refused->get_error_code() );
		$this->assertSame( 429, $refused->get_error_data()['status'] );

		// Exempt requesters never count toward the site total.
		$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, 'admin', array( 'requested_by_key_id' => 'admin-key', 'key_record' => mcpwp_test_admin_key() ) ) );
		$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, 'wp', array( 'requested_by_key_id' => 'wp-user:1' ) ) );

		// Fill the site total (100) from capped requesters.
		for ( $r = 0; $r < 4; $r++ ) {
			for ( $i = 0; $i < Mcpwp_Approvals::MAX_PENDING_CONTENT_PER_REQUESTER; $i++ ) {
				$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, "t$r-$i", array( 'requested_by_key_id' => "restricted-$r" ) ) );
			}
		}
		$full = Mcpwp_Approvals::create_post_content_request( self::POST, 'one more', array( 'requested_by_key_id' => 'restricted-new' ) );
		$this->assertSame( 'approval_queue_full', $full->get_error_code() );
		$this->assertSame( 429, $full->get_error_data()['status'] );
		$this->assertSame( 102, count( mcpwp_test_approvals_all() ) );
	}

	// ── 5. Expiry ───────────────────────────────────────────────────────────

	public function test_a_31_day_old_pending_request_expires_and_cannot_be_approved(): void {
		$old = gmdate( 'c', time() - 31 * DAY_IN_SECONDS );
		mcpwp_test_approvals_put(
			array(
				'apr_old' => array( 'id' => 'apr_old', 'status' => 'pending', 'action' => 'post_content_update', 'requested_by_key_id' => 'k1', 'resource' => array( 'type' => 'post', 'id' => self::POST ), 'created_at' => $old, 'updated_at' => $old ),
			)
		);

		$listed = Mcpwp_Approvals::list_requests( '', 10, true );
		$this->assertSame( 'expired', $listed[0]['status'] );
		$this->assertSame( 'expired', $this->row( 'apr_old' )['status'], 'The sweep persisted it, column and record together.' );
		$this->assertSame( 'expired', mcpwp_test_approvals_all()['apr_old']['status'] );
		$this->assertSame( array(), Mcpwp_Approvals::list_requests( 'pending', 10, true ) );

		$refused = Mcpwp_Approvals::approve_request( 'apr_old' );
		$this->assertSame( 'approval_closed', $refused->get_error_code() );
	}

	// ── 6. Visibility paging ────────────────────────────────────────────────

	/**
	 * @return array{0: array, 1: string[]} [records, visible ids]
	 */
	private function mixed_visibility( int $total, int $visible ): array {
		$items   = array();
		$shown   = array();
		for ( $i = 0; $i < $total; $i++ ) {
			$id   = sprintf( 'apr_vis%03d', $i );
			// Newest first in the list: the visible ones are the OLDEST, so
			// they sit behind every hidden record.
			$when = gmdate( 'c', time() - ( $total - $i ) * 60 );
			$show = $i < $visible;
			$items[ $id ] = array( 'id' => $id, 'status' => 'pending', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k', 'visibility_categories' => array( $show ? 'content' : 'woocommerce' ), 'created_at' => $when, 'updated_at' => $when );
			if ( $show ) {
				$shown[] = $id;
			}
		}
		return array( $items, $shown );
	}

	public function test_a_restricted_key_gets_full_pages_of_visible_records(): void {
		$allowed = static function ( $category ) {
			return 'woocommerce' !== $category;
		};

		list( $items, $shown ) = $this->mixed_visibility( 50, 5 );
		mcpwp_test_approvals_put( $items );
		$page = Mcpwp_Approvals::list_requests( '', 5, true, 'date', $allowed );
		$this->assertSame( array_reverse( $shown ), array_column( $page, 'id' ) );

		// Past one fetch batch: 5 visible behind 245 hidden.
		list( $items, $shown ) = $this->mixed_visibility( 250, 5 );
		mcpwp_test_approvals_put( $items );
		$page = Mcpwp_Approvals::list_requests( '', 5, true, 'date', $allowed );
		$this->assertSame( array_reverse( $shown ), array_column( $page, 'id' ) );
		$this->assertCount( 5, $page );
	}

	// ── 7. Not-found parity ─────────────────────────────────────────────────

	public function test_a_hidden_id_answers_exactly_like_a_missing_id(): void {
		list( $items ) = $this->mixed_visibility( 2, 1 );
		mcpwp_test_approvals_put( $items );

		$controller = new class() extends Mcpwp_REST_Approvals {
			public function caller_may_read_category( $category ) {
				return 'woocommerce' !== $category;
			}
		};
		$resolve = new ReflectionMethod( Mcpwp_REST_Approvals::class, 'resolve_visible_approval' );
		$resolve->setAccessible( true );

		$hidden  = $resolve->invoke( $controller, 'apr_vis001' );
		$missing = $resolve->invoke( $controller, 'apr_nosuch' );
		$this->assertInstanceOf( WP_Error::class, $hidden );
		$this->assertEquals( $missing, $hidden );
		$this->assertSame( serialize( $missing ), serialize( $hidden ) );
		$this->assertEquals( Mcpwp_Approvals::not_found_error(), Mcpwp_Approvals::get_request( 'apr_nosuch' ) );
		$this->assertIsArray( $resolve->invoke( $controller, 'apr_vis000' ) );
	}

	// ── 8. Retention ────────────────────────────────────────────────────────

	public function test_retention_deletes_old_closed_rows_audits_first_and_never_touches_open_rows(): void {
		$old   = gmdate( 'c', time() - 91 * DAY_IN_SECONDS );
		$young = gmdate( 'c', time() - 89 * DAY_IN_SECONDS );
		$items = array();
		foreach ( array( 'rejected', 'expired', 'rolled_back', 'applied', 'pending', 'approved', 'applying', 'apply_failed' ) as $status ) {
			foreach ( array( 'old' => $old, 'young' => $young ) as $age => $when ) {
				$id           = "apr_{$status}_{$age}";
				$items[ $id ] = array( 'id' => $id, 'status' => $status, 'action' => 'destructive_operation', 'requested_by_key_id' => 'k', 'resource' => array( 'type' => 'page', 'id' => 1 ), 'payload' => array( 'route' => '/mcpwp/v1/pages/1' ), 'created_at' => $when, 'updated_at' => $when, 'compacted' => $when );
			}
		}
		mcpwp_test_approvals_put( $items );

		$this->assertSame( 4, Mcpwp_Approvals::run_retention() );

		$left = mcpwp_test_approvals_all();
		foreach ( array( 'rejected', 'expired', 'rolled_back', 'applied' ) as $status ) {
			$this->assertArrayNotHasKey( "apr_{$status}_old", $left );
			$this->assertArrayHasKey( "apr_{$status}_young", $left );
		}
		foreach ( array( 'pending', 'approved', 'applying', 'apply_failed' ) as $status ) {
			$this->assertArrayHasKey( "apr_{$status}_old", $left, 'An open request is never removed, however old.' );
			$this->assertArrayHasKey( "apr_{$status}_young", $left );
		}

		$audited = array_map(
			static function ( $row ) {
				return json_decode( $row['arguments'], true )['approval_id'];
			},
			$this->audit_rows()
		);
		sort( $audited );
		$this->assertSame( array( 'apr_applied_old', 'apr_expired_old', 'apr_rejected_old', 'apr_rolled_back_old' ), $audited );
	}

	public function test_retention_keeps_a_row_whose_audit_row_cannot_be_written(): void {
		$old = gmdate( 'c', time() - 120 * DAY_IN_SECONDS );
		mcpwp_test_approvals_put(
			array(
				'apr_keep' => array( 'id' => 'apr_keep', 'status' => 'rejected', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k', 'created_at' => $old, 'updated_at' => $old ),
			)
		);
		global $wpdb;
		$GLOBALS['mcpwp_test_wpdb_insert_fail'][ $wpdb->prefix . 'mcpwp_action_log' ] = true;

		$this->assertSame( 0, Mcpwp_Approvals::run_retention() );
		$this->assertArrayHasKey( 'apr_keep', mcpwp_test_approvals_all(), 'No audit row, no removal.' );
		$this->assertSame( array(), preg_grep( '/^DELETE FROM/', $GLOBALS['mcpwp_test_approvals_statements'] ), 'The delete never ran.' );

		// Retention is filterable.
		unset( $GLOBALS['mcpwp_test_wpdb_insert_fail'] );
		add_filter(
			'mcpwp_approvals_closed_retention_days',
			static function () {
				return 200;
			}
		);
		$this->assertSame( 0, Mcpwp_Approvals::run_retention() );
		$this->assertArrayHasKey( 'apr_keep', mcpwp_test_approvals_all() );
	}

	// ── 9. Write failure ────────────────────────────────────────────────────

	public function test_a_failed_update_is_approvals_store_failed_and_changes_nothing(): void {
		$created = Mcpwp_Approvals::create_post_content_request( self::POST, 'new body', array( 'requested_by_key_id' => 'k1' ) );
		$id      = $created['id'];
		$before  = $this->row( $id );

		$GLOBALS['mcpwp_test_approvals_fail'] = static function ( $kind ) {
			return 'update' === $kind;
		};
		foreach ( array(
			Mcpwp_Approvals::approve_request( $id ),
			Mcpwp_Approvals::reject_request( $id ),
			Mcpwp_Approvals::update_request(
				$id,
				static function ( array $record ) {
					$record['note'] = 'changed';
					return $record;
				}
			),
		) as $result ) {
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'approvals_store_failed', $result->get_error_code() );
			$this->assertSame( 503, $result->get_error_data()['status'] );
		}
		$this->assertFalse( Mcpwp_Approvals::set_triage( $id, array( 'priority' => 'low', 'change_kind' => 'other' ) ) );

		$this->assertSame( $before, $this->row( $id ), 'Record and version untouched.' );
	}

	public function test_a_failed_read_is_never_reported_as_not_found(): void {
		$created = Mcpwp_Approvals::create_post_content_request( self::POST, 'new body', array( 'requested_by_key_id' => 'k1' ) );
		$GLOBALS['mcpwp_test_approvals_fail'] = static function ( $kind ) {
			return 'select' === $kind || 'count' === $kind;
		};

		$this->assertSame( 'approvals_store_failed', Mcpwp_Approvals::get_request( $created['id'] )->get_error_code() );
		$this->assertSame( 'approvals_store_failed', Mcpwp_Approvals::get_request( 'apr_nosuch' )->get_error_code() );
		$this->assertSame( 'approvals_store_failed', Mcpwp_Approvals::list_requests()->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, Mcpwp_Approvals::requester_of( $created['id'] ) );
		$this->assertSame( 'approvals_store_failed', Mcpwp_Approvals::create_post_content_request( self::POST, 'y', array( 'requested_by_key_id' => 'k2' ) )->get_error_code() );
	}
}
