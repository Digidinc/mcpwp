<?php
/**
 * #1053: the one-time cleanup of the `mcpwp_approval_requests_pre_3149`
 * recovery backup. Fails closed: it deletes only when the table release has
 * demonstrably moved every record, and says why when it refuses.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';

final class ApprovalsBackupCleanupTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']                = array();
		$GLOBALS['mcpwp_test_wpdb_options']           = array();
		$GLOBALS['mcpwp_test_filters']                = array();
		$GLOBALS['mcpwp_test_approvals_fail']         = null;
		$GLOBALS['mcpwp_test_approvals_table_exists'] = true;
		$GLOBALS['mcpwp_test_approvals_rows']         = array();
		$GLOBALS['mcpwp_test_transients']             = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']        = array();
		$GLOBALS['mcpwp_test_wpdb_options']   = array();
		$GLOBALS['mcpwp_test_approvals_fail'] = null;
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

	public function test_deletes_the_backup_after_a_real_migration(): void {
		$this->migrated_site();
		$this->assertSame( 'deleted', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertFalse( get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, false ) );
		$this->assertNotEmpty( $GLOBALS['mcpwp_test_approvals_rows'], 'The table is untouched.' );
	}

	public function test_is_idempotent(): void {
		$this->migrated_site();
		Mcpwp_Activator::cleanup_approvals_backup();
		$this->assertSame( 'nothing_to_clean', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertSame( 'nothing_to_clean', Mcpwp_Activator::cleanup_approvals_backup() );
	}

	public function test_does_nothing_before_the_migration_flag_is_set(): void {
		update_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, $this->backup(), false );
		$GLOBALS['mcpwp_test_approvals_rows'] = array( 'x' => array( 'id' => 'x' ) );
		$this->assertSame( 'not_migrated', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertNotFalse( get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, false ) );
	}

	public function test_keeps_the_backup_when_the_table_is_missing(): void {
		$this->migrated_site();
		$GLOBALS['mcpwp_test_approvals_table_exists'] = false;
		$this->assertSame( 'table_unreadable', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertNotFalse( get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, false ) );
	}

	public function test_keeps_the_backup_when_the_table_is_empty(): void {
		$this->migrated_site();
		$GLOBALS['mcpwp_test_approvals_rows'] = array();
		$this->assertSame( 'table_empty', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertNotEmpty( get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION ) );
	}

	public function test_keeps_the_backup_when_the_count_query_fails(): void {
		$this->migrated_site();
		$GLOBALS['mcpwp_test_approvals_fail'] = true;
		$this->assertSame( 'table_unreadable', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertNotEmpty( get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION ) );
	}

	public function test_keeps_the_backup_when_a_record_was_skipped_and_only_lives_there(): void {
		$this->migrated_site();
		update_option( Mcpwp_Approvals::SKIPPED_OPTION, array( 'apr_bk_2' ), false );
		$this->assertSame( 'skipped_records', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertNotEmpty( get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION ) );
	}

	public function test_keeps_the_backup_while_the_legacy_option_has_reappeared(): void {
		$this->migrated_site();
		update_option( Mcpwp_Approvals::OPTION_NAME, $this->backup(), false );
		$this->assertSame( 'late_import_pending', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertNotEmpty( get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION ) );
	}

	public function test_an_empty_backup_is_removed_even_with_an_empty_table(): void {
		update_option( Mcpwp_Approvals::MIGRATED_FLAG, 1 );
		update_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, array(), false );
		$this->assertSame( 'deleted', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertFalse( get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, false ) );
	}

	public function test_a_refusal_is_recorded_with_its_reason(): void {
		$this->migrated_site();
		$GLOBALS['mcpwp_test_approvals_rows'] = array();
		Mcpwp_Activator::cleanup_approvals_backup();
		$this->assertSame( 'table_empty', get_transient( Mcpwp_Activator::APPROVALS_BACKUP_CLEANUP_STATUS ) );
	}

	public function test_refuses_while_the_migration_lock_is_held(): void {
		$this->migrated_site();
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Activator::APPROVALS_TABLE_LOCK_OPTION ] = (string) ( time() + 60 );
		$this->assertSame( 'busy', Mcpwp_Activator::cleanup_approvals_backup() );
		$this->assertNotEmpty( get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION ) );
	}

	public function test_it_runs_from_the_hourly_cron_and_not_from_a_public_request_hook(): void {
		$src = file_get_contents( dirname( __DIR__ ) . '/mcpwp.php' );
		$this->assertMatchesRegularExpression( '/LATE_IMPORT_CRON_HOOK,\s*static function \(\) \{[^}]*cleanup_approvals_backup/s', $src );
		$this->assertDoesNotMatchRegularExpression( "/'wp_loaded',\s*static function \(\) \{[^}]*cleanup_approvals_backup/s", $src );
	}
}
