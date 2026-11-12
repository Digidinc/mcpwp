<?php
/**
 * #1053: the age-gated, fail-closed shrink of the
 * `mcpwp_approval_requests_pre_3149` recovery backup.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';

final class ApprovalsBackupCleanupTest extends TestCase {

	private const DAY = 86400;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']                = array();
		$GLOBALS['mcpwp_test_wpdb_options']           = array();
		$GLOBALS['mcpwp_test_filters']                = array();
		$GLOBALS['mcpwp_test_approvals_fail']         = null;
		$GLOBALS['mcpwp_test_approvals_table_exists'] = true;
		$GLOBALS['mcpwp_test_approvals_rows']         = array();
		$GLOBALS['mcpwp_test_transients']             = array();
		$GLOBALS['mcpwp_test_delete_option_fail']     = array();
		$GLOBALS['mcpwp_test_update_option_fail']     = array();
		$GLOBALS['mcpwp_test_wpdb_intercept']         = null;
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_wpdb_options']       = array();
		$GLOBALS['mcpwp_test_filters']            = array();
		$GLOBALS['mcpwp_test_approvals_fail']     = null;
		$GLOBALS['mcpwp_test_delete_option_fail'] = array();
		$GLOBALS['mcpwp_test_update_option_fail'] = array();
		$GLOBALS['mcpwp_test_wpdb_intercept']     = null;
	}

	private function backup(): array {
		$now = gmdate( 'c' );
		return array(
			'apr_bk_1' => array( 'id' => 'apr_bk_1', 'status' => 'rejected', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k1', 'resource' => array( 'type' => 'page', 'id' => 3 ), 'created_at' => $now, 'updated_at' => $now ),
			'apr_bk_2' => array( 'id' => 'apr_bk_2', 'status' => 'rejected', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k2', 'resource' => array( 'type' => 'page', 'id' => 4 ), 'created_at' => $now, 'updated_at' => $now ),
		);
	}

	/** A site that went through the table migration for real. */
	private function migrated_site(): void {
		update_option( Mcpwp_Approvals::OPTION_NAME, $this->backup(), false );
		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );
		$this->assertNotEmpty( get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION ) );
		$this->assertNotEmpty( $GLOBALS['mcpwp_test_approvals_rows'] );
	}

	private function age_flag( int $seconds ): void {
		update_option( Mcpwp_Approvals::MIGRATED_FLAG, time() - $seconds );
	}

	private function old_enough(): void {
		$this->age_flag( 91 * self::DAY );
	}

	private function status(): string {
		return (string) get_transient( Mcpwp_Activator::APPROVALS_BACKUP_CLEANUP_STATUS );
	}

	private function backup_option() {
		return get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, false );
	}

	// ── The age gate ────────────────────────────────────────────────────────

	public function test_a_new_migration_stamps_the_time_in_the_flag(): void {
		$before = time();
		$this->migrated_site();
		$flag = (int) get_option( Mcpwp_Approvals::MIGRATED_FLAG );
		$this->assertGreaterThanOrEqual( $before, $flag );
		$this->assertLessThanOrEqual( time(), $flag );
	}

	public function test_a_late_import_does_not_move_the_flag(): void {
		$this->migrated_site();
		$this->age_flag( 10 * self::DAY );
		$stamp = get_option( Mcpwp_Approvals::MIGRATED_FLAG );
		update_option( Mcpwp_Approvals::OPTION_NAME, array(), false );
		Mcpwp_Activator::migrate_approvals_to_table();
		$this->assertSame( $stamp, get_option( Mcpwp_Approvals::MIGRATED_FLAG ) );
	}

	public function test_the_tick_that_migrates_a_site_does_not_touch_its_backup(): void {
		// A site updating from 3.14.8: the option holds the queue, nothing migrated yet.
		update_option( Mcpwp_Approvals::OPTION_NAME, $this->backup(), false );
		$GLOBALS['mcpwp_test_approvals_table_exists'] = true;
		$this->assertTrue( Mcpwp_Activator::run_approvals_late_import_tick() );
		$this->assertSame( 'too_recent', $this->status() );
		$this->assertSame( $this->backup(), $this->backup_option(), 'The full recovery copy survives the tick that made it.' );
	}

	public function test_a_bare_one_flag_is_stamped_with_now_and_refused_the_first_time(): void {
		$this->migrated_site();
		update_option( Mcpwp_Approvals::MIGRATED_FLAG, 1 );
		$this->assertSame( 'too_recent', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertEqualsWithDelta( time(), (int) get_option( Mcpwp_Approvals::MIGRATED_FLAG ), 5 );
		$this->assertSame( 'too_recent', Mcpwp_Activator::cleanup_approvals_backup(), 'And the next pass is still inside the window.' );
		$this->assertSame( $this->backup(), array_intersect_key( $this->backup_option(), $this->backup() ) );
	}

	public function test_one_second_inside_the_window_is_refused_and_one_second_past_it_shrinks(): void {
		$this->migrated_site();
		$this->age_flag( 90 * self::DAY - 2 );
		$this->assertSame( 'too_recent', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertIsArray( $this->backup_option()['apr_bk_1'] );
		$this->age_flag( 90 * self::DAY + 1 );
		$this->assertSame( 'shrunk', Mcpwp_Activator::cleanup_approvals_backup() );
	}

	public function test_does_nothing_before_the_migration_flag_is_set(): void {
		update_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, $this->backup(), false );
		$this->assertSame( 'not_migrated', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertSame( $this->backup(), $this->backup_option() );
	}

	public function test_the_tick_skips_the_shrink_when_the_migration_failed(): void {
		$this->migrated_site();
		$this->old_enough();
		// The legacy option is back, so the tick migrates again — and fails.
		update_option( Mcpwp_Approvals::OPTION_NAME, array( 'apr_late' => array( 'id' => 'apr_late', 'status' => 'rejected', 'requested_by_key_id' => 'k', 'resource' => array( 'type' => 'page', 'id' => 3 ) ) ), false );
		$GLOBALS['mcpwp_test_approvals_fail'] = true;
		$this->assertInstanceOf( WP_Error::class, Mcpwp_Activator::run_approvals_late_import_tick() );
		$this->assertSame( '', $this->status(), 'cleanup_approvals_backup() was not even called.' );
		$this->assertIsArray( $this->backup_option()['apr_bk_1'] );
	}

	// ── The shrink ──────────────────────────────────────────────────────────

	public function test_shrinks_to_an_id_tombstone_map_and_keeps_the_table(): void {
		$this->migrated_site();
		$rows = $GLOBALS['mcpwp_test_approvals_rows'];
		$this->old_enough();
		$this->assertSame( 'shrunk', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertSame( array( 'apr_bk_1' => true, 'apr_bk_2' => true ), $this->backup_option() );
		$this->assertSame( $rows, $GLOBALS['mcpwp_test_approvals_rows'] );
		$this->assertSame( 'shrunk', $this->status() );
	}

	public function test_a_shrunk_backup_is_left_alone_afterwards(): void {
		$this->migrated_site();
		$this->old_enough();
		Mcpwp_Activator::cleanup_approvals_backup();
		$this->assertSame( 'nothing_to_clean', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertSame( array( 'apr_bk_1' => true, 'apr_bk_2' => true ), $this->backup_option() );
	}

	public function test_the_tombstone_map_still_stops_a_late_import_resurrecting_a_decided_id(): void {
		$this->migrated_site();
		$this->old_enough();
		Mcpwp_Activator::cleanup_approvals_backup();
		unset( $GLOBALS['mcpwp_test_approvals_rows']['apr_bk_1'] ); // Since deleted.
		update_option( Mcpwp_Approvals::OPTION_NAME, array( 'apr_bk_1' => $this->backup()['apr_bk_1'] ), false );
		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );
		$this->assertArrayNotHasKey( 'apr_bk_1', $GLOBALS['mcpwp_test_approvals_rows'] );
		$this->assertFalse( get_option( Mcpwp_Approvals::OPTION_NAME, false ) );
	}

	public function test_a_late_import_recreating_records_is_shrunk_again_on_the_next_tick(): void {
		$this->migrated_site();
		$this->old_enough();
		Mcpwp_Activator::cleanup_approvals_backup();
		$new = array( 'apr_new' => array( 'id' => 'apr_new', 'status' => 'rejected', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k9', 'resource' => array( 'type' => 'page', 'id' => 3 ), 'created_at' => gmdate( 'c' ), 'updated_at' => gmdate( 'c' ) ) );
		update_option( Mcpwp_Approvals::OPTION_NAME, $new, false );
		$this->assertTrue( Mcpwp_Activator::run_approvals_late_import_tick() );
		$this->assertSame( array( 'apr_bk_1' => true, 'apr_bk_2' => true, 'apr_new' => true ), $this->backup_option() );
		$this->assertArrayHasKey( 'apr_new', $GLOBALS['mcpwp_test_approvals_rows'] );
	}

	public function test_an_empty_array_backup_is_deleted(): void {
		update_option( Mcpwp_Approvals::MIGRATED_FLAG, time() - 91 * self::DAY );
		update_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, array(), false );
		$this->assertSame( 'deleted', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertFalse( $this->backup_option() );
		$this->assertSame( 'nothing_to_clean', Mcpwp_Activator::cleanup_approvals_backup() );
	}

	public function test_a_delete_that_does_not_take_is_reported(): void {
		update_option( Mcpwp_Approvals::MIGRATED_FLAG, time() - 91 * self::DAY );
		update_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, array(), false );
		$GLOBALS['mcpwp_test_delete_option_fail'][ Mcpwp_Approvals::LEGACY_BACKUP_OPTION ] = true;
		$this->assertSame( 'delete_failed', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertSame( 'delete_failed', $this->status() );
	}

	public function test_a_shrink_that_does_not_take_is_reported_and_the_records_stay(): void {
		$this->migrated_site();
		$this->old_enough();
		$GLOBALS['mcpwp_test_update_option_fail'][ Mcpwp_Approvals::LEGACY_BACKUP_OPTION ] = true;
		$this->assertSame( 'shrink_failed', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertIsArray( $this->backup_option()['apr_bk_1'] );
	}

	// ── Shape of the backup ─────────────────────────────────────────────────

	public function test_a_valid_non_array_backup_is_unrecognised_not_deleted(): void {
		$this->old_enough();
		foreach ( array( '{"a":1}', 'text', 7, true ) as $value ) {
			update_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, $value, false );
			$this->assertSame( 'backup_unrecognised', Mcpwp_Activator::cleanup_approvals_backup(), var_export( $value, true ) );
			$this->assertSame( $value, $this->backup_option() );
		}
	}

	public function test_a_non_array_record_inside_the_backup_is_unrecognised(): void {
		$this->migrated_site();
		$this->old_enough();
		$backup             = $this->backup_option();
		$backup['apr_bad'] = 'garbage';
		update_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, $backup, false );
		$this->assertSame( 'backup_unrecognised', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertSame( $backup, $this->backup_option() );
	}

	public function test_a_backup_row_get_option_cannot_read_is_reported_not_called_clean(): void {
		$this->old_enough();
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $sql ) {
			return false !== strpos( $sql, 'FROM' ) && false !== strpos( $sql, 'option_name' ) && false !== strpos( $sql, Mcpwp_Approvals::LEGACY_BACKUP_OPTION ) ? '1' : MCPWP_TEST_WPDB_PASS;
		};
		$this->assertFalse( $this->backup_option() );
		$this->assertSame( 'backup_unreadable', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertSame( 'backup_unreadable', $this->status() );
		$GLOBALS['mcpwp_test_wpdb_intercept'] = null;
		$this->assertSame( 'nothing_to_clean', Mcpwp_Activator::cleanup_approvals_backup(), 'No row at all is simply clean.' );
	}

	// ── Table state: per-id presence ────────────────────────────────────────

	public function test_refuses_when_the_table_is_missing(): void {
		$this->migrated_site();
		$this->old_enough();
		$GLOBALS['mcpwp_test_approvals_table_exists'] = false;
		$this->assertSame( 'table_unreadable', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertIsArray( $this->backup_option()['apr_bk_1'] );
	}

	public function test_refuses_when_the_count_query_fails(): void {
		$this->migrated_site();
		$this->old_enough();
		$GLOBALS['mcpwp_test_approvals_fail'] = true;
		$this->assertSame( 'table_unreadable', Mcpwp_Activator::cleanup_approvals_backup() );
	}

	public function test_refuses_when_the_table_is_empty(): void {
		$this->migrated_site();
		$this->old_enough();
		$GLOBALS['mcpwp_test_approvals_rows'] = array();
		$this->assertSame( 'rows_missing', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertIsArray( $this->backup_option()['apr_bk_1'] );
	}

	public function test_a_dropped_and_recreated_table_with_one_new_approval_is_refused(): void {
		$this->migrated_site();
		$this->old_enough();
		$GLOBALS['mcpwp_test_approvals_rows'] = array( 'apr_brand_new' => $GLOBALS['mcpwp_test_approvals_rows']['apr_bk_1'] );
		$GLOBALS['mcpwp_test_approvals_rows']['apr_brand_new']['id'] = 'apr_brand_new';
		$this->assertSame( 'rows_missing', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertIsArray( $this->backup_option()['apr_bk_1'] );
	}

	public function test_one_missing_record_among_present_ones_is_refused(): void {
		$this->migrated_site();
		$this->old_enough();
		unset( $GLOBALS['mcpwp_test_approvals_rows']['apr_bk_2'] );
		$this->assertSame( 'rows_missing', Mcpwp_Activator::cleanup_approvals_backup() );
	}

	public function test_old_closed_and_identity_less_records_may_be_legitimately_gone(): void {
		$old    = gmdate( 'c', time() - 200 * self::DAY );
		$backup = array(
			'apr_keep'   => array( 'id' => 'apr_keep', 'status' => 'rejected', 'requested_by_key_id' => 'k', 'created_at' => gmdate( 'c' ), 'updated_at' => gmdate( 'c' ) ),
			'apr_old'    => array( 'id' => 'apr_old', 'status' => 'rejected', 'requested_by_key_id' => 'k', 'created_at' => $old, 'updated_at' => $old ),
			'apr_noid'   => array( 'id' => 'apr_noid', 'status' => 'pending', 'created_at' => gmdate( 'c' ), 'updated_at' => gmdate( 'c' ) ),
			'apr_oldpnd' => array( 'id' => 'apr_oldpnd', 'status' => 'pending', 'requested_by_key_id' => 'k', 'created_at' => $old, 'updated_at' => $old ),
		);
		update_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, $backup, false );
		$this->old_enough();
		$GLOBALS['mcpwp_test_approvals_rows'] = array( 'apr_keep' => array( 'id' => 'apr_keep' ) );
		$this->assertSame( 'shrunk', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertSame( array_fill_keys( array_keys( $backup ), true ), $this->backup_option() );
	}

	public function test_a_recent_closed_record_or_a_young_pending_one_missing_is_refused(): void {
		foreach ( array( 'rejected' => 100, 'pending' => 100 ) as $status => $age_days ) {
			$when = gmdate( 'c', time() - $age_days * self::DAY );
			$GLOBALS['mcpwp_test_transients'] = array();
			update_option(
				Mcpwp_Approvals::LEGACY_BACKUP_OPTION,
				array(
					'apr_x' => array( 'id' => 'apr_x', 'status' => $status, 'requested_by_key_id' => 'k', 'created_at' => $when, 'updated_at' => $when ),
					'apr_y' => array( 'id' => 'apr_y', 'status' => 'rejected', 'requested_by_key_id' => 'k', 'created_at' => $when, 'updated_at' => $when ),
				),
				false
			);
			$this->old_enough();
			// 100 days is past retention for a closed record but not for a pending one (90 + 30 TTL).
			$GLOBALS['mcpwp_test_approvals_rows'] = array( 'apr_y' => array( 'id' => 'apr_y' ) );
			$expected = 'rejected' === $status ? 'shrunk' : 'rows_missing';
			$this->assertSame( $expected, Mcpwp_Activator::cleanup_approvals_backup(), $status );
		}
	}

	public function test_nothing_is_gone_when_retention_is_switched_off(): void {
		$old = gmdate( 'c', time() - 400 * self::DAY );
		update_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, array( 'apr_old' => array( 'id' => 'apr_old', 'status' => 'rejected', 'requested_by_key_id' => 'k', 'created_at' => $old, 'updated_at' => $old ) ), false );
		$this->old_enough();
		add_filter( 'mcpwp_approvals_closed_retention_days', static function () {
			return 0;
		} );
		$this->assertSame( 'rows_missing', Mcpwp_Activator::cleanup_approvals_backup() );
	}

	// ── Other fail-closed gates ─────────────────────────────────────────────

	public function test_refuses_when_a_record_was_skipped_and_only_lives_there(): void {
		$this->migrated_site();
		$this->old_enough();
		update_option( Mcpwp_Approvals::SKIPPED_OPTION, array( 'apr_bk_2' ), false );
		$this->assertSame( 'skipped_records', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertIsArray( $this->backup_option()['apr_bk_2'] );
	}

	public function test_refuses_while_the_legacy_option_has_reappeared(): void {
		$this->migrated_site();
		$this->old_enough();
		update_option( Mcpwp_Approvals::OPTION_NAME, $this->backup(), false );
		$this->assertSame( 'late_import_pending', Mcpwp_Activator::cleanup_approvals_backup() );
	}

	public function test_refuses_while_the_migration_lock_is_held(): void {
		$this->migrated_site();
		$this->old_enough();
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Activator::APPROVALS_TABLE_LOCK_OPTION ] = (string) ( time() + 60 );
		$this->assertSame( 'busy', Mcpwp_Activator::cleanup_approvals_backup() );
	}

	public function test_it_runs_only_from_the_hourly_cron(): void {
		$src = file_get_contents( dirname( __DIR__ ) . '/mcpwp.php' );
		$this->assertMatchesRegularExpression( '/LATE_IMPORT_CRON_HOOK,\s*static function \(\) \{[^}]*run_approvals_late_import_tick/s', $src );
		$this->assertStringNotContainsString( 'cleanup_approvals_backup', $src );
	}
}
