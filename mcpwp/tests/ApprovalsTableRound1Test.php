<?php
/**
 * #1042 adversarial review, round 1 — one test per finding.
 *
 *  B1  exempt principals are never refused by the open-request cap.
 *  W1  the byte quota measures unescaped JSON (Persian / CJK not 2-3x).
 *  W2  one unencodable legacy record never blocks the migration.
 *  W3  a legacy option recreated after the migration is imported.
 *  W4  approval counts are exact, not a count of one listed page.
 *  NIT a..e  accurate messages; retention dates; one audit row per
 *            removal; a damaged row is not-found to a restricted caller;
 *            a real cap_exempt column.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-site-state.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-approvals.php';

final class ApprovalsTableRound1Test extends TestCase {

	private const POST = 10430;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']          = array();
		$GLOBALS['mcpwp_test_options_autoload'] = array();
		$GLOBALS['mcpwp_test_wpdb_options']     = array();
		$GLOBALS['mcpwp_test_transients']       = array();
		$GLOBALS['mcpwp_test_filters']          = array();
		$GLOBALS['mcpwp_test_actions']          = array();
		$GLOBALS['mcpwp_test_wpdb_rows']        = array();
		$GLOBALS['mcpwp_test_user_caps']        = array( 1 => array( 'manage_options' => true ) ); // wp-user:1 is an administrator.
		$GLOBALS['mcpwp_test_current_user']     = 1;
		$GLOBALS['_mcpwp_test_posts']           = array(
			self::POST => new WP_Post( array( 'ID' => self::POST, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'P', 'post_content' => 'original body' ) ),
		);
		unset( $GLOBALS['mcpwp_test_action_log_read_fails'] );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_approvals_fail']          = null;
		$GLOBALS['mcpwp_test_approvals_before_update'] = null;
		$GLOBALS['mcpwp_test_options']                 = array();
		$GLOBALS['mcpwp_test_filters']                 = array();
		$GLOBALS['mcpwp_test_current_user']            = 0;
		unset( $GLOBALS['mcpwp_test_action_log_read_fails'] );
	}

	private function audit_ids(): array {
		$ids = array();
		foreach ( $GLOBALS['mcpwp_test_wpdb_rows'] as $table_rows ) {
			foreach ( (array) $table_rows as $row ) {
				if ( is_array( $row ) && 'approval_record_removed' === ( $row['tool_name'] ?? '' ) ) {
					$ids[] = (string) $row['resource_id'];
				}
			}
		}
		return $ids;
	}

	private function open_queue_of_capped_keys(): void {
		$items = array();
		$now   = gmdate( 'c' );
		for ( $i = 0; $i < 100; $i++ ) {
			$id           = sprintf( 'apr_cc%03d', $i );
			$items[ $id ] = array( 'id' => $id, 'status' => 'pending', 'action' => 'post_content_update', 'requested_by_key_id' => 'restricted-' . ( $i % 5 ), 'metadata' => array(), 'created_at' => $now, 'updated_at' => $now );
			$id           = sprintf( 'apr_cd%03d', $i );
			$items[ $id ] = array( 'id' => $id, 'status' => 'pending', 'action' => 'destructive_operation', 'requested_by_key_id' => 'restricted-d' . $i, 'created_at' => $now, 'updated_at' => $now );
		}
		mcpwp_test_approvals_put( $items );
	}

	// ── B1 ─────────────────────────────────────────────────────────────────

	public function test_capped_keys_filling_every_open_slot_never_lock_out_an_administrator(): void {
		$this->open_queue_of_capped_keys();

		$capped = Mcpwp_Approvals::create_destructive_request( 'delete_page', array( 'route' => '/mcpwp/v1/pages/1', 'method' => 'DELETE', 'params' => array() ), array( 'type' => 'page', 'id' => 1 ), array( 'requested_by_key_id' => 'restricted-x' ) );
		$this->assertSame( 'approval_queue_full', $capped->get_error_code() );

		$this->assertIsArray( Mcpwp_Approvals::create_destructive_request( 'delete_page', array( 'route' => '/mcpwp/v1/pages/1', 'method' => 'DELETE', 'params' => array() ), array( 'type' => 'page', 'id' => 1 ), array( 'requested_by_key_id' => 'wp-user:1' ) ) );
		$this->assertIsArray( Mcpwp_Approvals::create_destructive_request( 'delete_page', array( 'route' => '/mcpwp/v1/pages/2', 'method' => 'DELETE', 'params' => array() ), array( 'type' => 'page', 'id' => 2 ), array( 'requested_by_key_id' => 'admin-key', 'key_record' => mcpwp_test_admin_key() ) ) );
		$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, 'admin edit', array( 'requested_by_key_id' => 'admin-key', 'key_record' => mcpwp_test_admin_key() ) ) );
		$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, 'wp-admin edit', array( 'requested_by_key_id' => 'wp-user:1' ) ) );
		$this->assertTrue( Mcpwp_Approvals::insert_request( array( 'id' => 'apr_stg_admin', 'status' => 'pending', 'action' => 'staging_push', 'requested_by_key_id' => 'admin-key', 'created_at' => gmdate( 'c' ), 'updated_at' => gmdate( 'c' ) ), array( 'key_record' => mcpwp_test_admin_key() ) ) );

		// The gate derives the verdict from the one decision function:
		// admin scope, not role or categories (round 2).
		$this->assertTrue( Mcpwp_Destructive_Gate::key_is_unrestricted( mcpwp_test_admin_key( 'k1' ) ) );
		$this->assertFalse( Mcpwp_Destructive_Gate::key_is_unrestricted( array( 'id' => 'k2', 'role' => 'admin', 'scopes' => array( 'read', 'write' ) ) ) );
		$this->assertFalse( Mcpwp_Destructive_Gate::key_is_unrestricted( null ) );
	}

	// ── W1 ─────────────────────────────────────────────────────────────────

	public function test_persian_and_cjk_proposals_are_measured_by_their_real_size(): void {
		$persian = str_repeat( 'سلام دنیا ', 13650 ); // ~240 KB.
		$cjk     = str_repeat( '漢字テスト', 16000 );  // ~240 KB.
		$this->assertLessThan( Mcpwp_Approvals::MAX_PROPOSED_CONTENT_BYTES, strlen( $persian ) );
		$this->assertGreaterThan( 235000, strlen( $cjk ) );

		$a = Mcpwp_Approvals::create_post_content_request( self::POST, $persian, array( 'requested_by_key_id' => 'webmcp:user:5' ) );
		$this->assertIsArray( $a, is_wp_error( $a ) ? $a->get_error_code() : '' );
		$b = Mcpwp_Approvals::create_post_content_request( self::POST, $cjk, array( 'requested_by_key_id' => 'webmcp:user:6' ) );
		$this->assertIsArray( $b, is_wp_error( $b ) ? $b->get_error_code() : '' );

		// Stored unescaped on utf8mb4, and the text round-trips.
		$row = $GLOBALS['mcpwp_test_approvals_rows'][ $a['id'] ];
		$this->assertStringContainsString( 'سلام', $row['record'] );
		$this->assertLessThan( strlen( $persian ) + 20000, (int) $row['quota_bytes'] );
		$this->assertSame( $persian, mcpwp_test_approvals_all()[ $a['id'] ]['payload']['after_content'] );

		// On a non-utf8mb4 connection it is stored escaped, but the quota
		// still measures the unescaped size.
		global $wpdb;
		$wpdb->charset = 'utf8';
		try {
			$c = Mcpwp_Approvals::create_post_content_request( self::POST, $persian, array( 'requested_by_key_id' => 'webmcp:user:7' ) );
			$this->assertIsArray( $c );
			$escaped = $GLOBALS['mcpwp_test_approvals_rows'][ $c['id'] ];
			$this->assertStringNotContainsString( 'سلام', $escaped['record'] );
			$this->assertSame( $row['quota_bytes'], $escaped['quota_bytes'] );
		} finally {
			$wpdb->charset = 'utf8mb4';
		}
	}

	// ── W2 ─────────────────────────────────────────────────────────────────

	public function test_an_unencodable_legacy_record_is_skipped_not_fatal(): void {
		$deep = array( 'leaf' => 1 );
		for ( $i = 0; $i < 600; $i++ ) {
			$deep = array( 'n' => $deep );
		}
		$now   = gmdate( 'c' );
		$items = array(
			'apr_ok1'  => array( 'id' => 'apr_ok1', 'status' => 'pending', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k1', 'created_at' => $now, 'updated_at' => $now ),
			'apr_deep' => array( 'id' => 'apr_deep', 'status' => 'pending', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k2', 'payload' => $deep, 'created_at' => $now, 'updated_at' => $now ),
			'apr_bad'  => 'not a record',
			'apr_ok2'  => array( 'id' => 'apr_ok2', 'status' => 'rejected', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k3', 'created_at' => $now, 'updated_at' => $now ),
		);
		update_option( Mcpwp_Approvals::OPTION_NAME, $items, false );

		// The first read migrates; it neither fails nor 500s.
		$this->assertSame( 'pending', Mcpwp_Approvals::get_request( 'apr_ok1' )['status'] );
		$this->assertSame( 1, get_option( Mcpwp_Approvals::MIGRATED_FLAG ) );
		$this->assertSame( array( 'apr_ok1', 'apr_ok2' ), array_keys( mcpwp_test_approvals_all() ) );
		$this->assertSame( array( 'apr_deep', 'apr_bad' ), get_option( Mcpwp_Approvals::SKIPPED_OPTION ) );
		$this->assertFalse( $GLOBALS['mcpwp_test_options_autoload'][ Mcpwp_Approvals::SKIPPED_OPTION ] );
		$this->assertSame( $items, get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION ), 'The skipped records stay in the backup.' );
		$this->assertSame( Mcpwp_Approvals::not_found_error()->get_error_code(), Mcpwp_Approvals::get_request( 'apr_deep' )->get_error_code() );
	}

	public function test_a_failed_copy_answers_503_never_500(): void {
		update_option( Mcpwp_Approvals::OPTION_NAME, array( 'apr_x' => array( 'id' => 'apr_x', 'status' => 'pending', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k1', 'created_at' => gmdate( 'c' ), 'updated_at' => gmdate( 'c' ) ) ), false );
		$GLOBALS['mcpwp_test_approvals_fail'] = static function ( $kind ) {
			return 'insert' === $kind;
		};
		foreach ( array(
			Mcpwp_Approvals::get_request( 'apr_x' ),
			Mcpwp_Approvals::list_requests(),
			Mcpwp_Approvals::create_destructive_request( 'delete_page', array( 'route' => '/mcpwp/v1/pages/1', 'method' => 'DELETE', 'params' => array() ), array( 'type' => 'page', 'id' => 1 ), array( 'requested_by_key_id' => 'wp-user:1' ) ),
		) as $answer ) {
			$this->assertInstanceOf( WP_Error::class, $answer );
			$this->assertContains( $answer->get_error_code(), array( 'approvals_busy', 'approvals_store_failed' ) );
			$this->assertSame( 503, $answer->get_error_data()['status'] );
		}
		$this->assertFalse( get_option( Mcpwp_Approvals::MIGRATED_FLAG, false ) );
	}

	// ── W3 ─────────────────────────────────────────────────────────────────

	public function test_a_legacy_option_recreated_after_the_migration_is_imported(): void {
		$now = gmdate( 'c' );
		update_option( Mcpwp_Approvals::OPTION_NAME, array( 'apr_first' => array( 'id' => 'apr_first', 'status' => 'pending', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k1', 'created_at' => $now, 'updated_at' => $now ) ), false );
		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );
		$this->assertSame( 1, get_option( Mcpwp_Approvals::MIGRATED_FLAG ) );

		// An old-code worker (or a stint downgraded to 3.14.8) writes the
		// option again: a new id, plus a stale copy of one already migrated.
		update_option(
			Mcpwp_Approvals::OPTION_NAME,
			array(
				'apr_first' => array( 'id' => 'apr_first', 'status' => 'rejected', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k1', 'created_at' => $now, 'updated_at' => $now ),
				'apr_late'  => array( 'id' => 'apr_late', 'status' => 'pending', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k2', 'created_at' => $now, 'updated_at' => $now ),
			),
			false
		);

		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );
		$this->assertSame( 'pending', Mcpwp_Approvals::get_request( 'apr_late' )['status'], 'The late record reached the table.' );
		$this->assertSame( 'pending', Mcpwp_Approvals::get_request( 'apr_first' )['status'], 'An id already in the table wins.' );
		$this->assertFalse( get_option( Mcpwp_Approvals::OPTION_NAME, false ), 'The option is gone again.' );
		$backup = get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION );
		$this->assertArrayHasKey( 'apr_late', $backup );
		$this->assertSame( 'pending', $backup['apr_first']['status'], 'An id already in the backup wins.' );
	}

	// ── W4 ─────────────────────────────────────────────────────────────────

	public function test_counts_are_exact_past_one_page(): void {
		$items = array();
		for ( $i = 0; $i < 255; $i++ ) {
			$id           = sprintf( 'apr_n%03d', $i );
			$items[ $id ] = array( 'id' => $id, 'status' => 'pending', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k', 'visibility_categories' => array( 'content' ), 'created_at' => gmdate( 'c' ), 'updated_at' => gmdate( 'c' ) );
		}
		mcpwp_test_approvals_put( $items );

		$summary = new ReflectionMethod( Mcpwp_Site_State::class, 'get_approval_summary' );
		$summary->setAccessible( true );
		$restricted = static function ( $category ) {
			return 'woocommerce' !== $category;
		};

		$this->assertSame( 255, $summary->invoke( null, true, null )['counts']['pending'] );
		$this->assertSame( 255, $summary->invoke( null, true, $restricted )['counts']['pending'] );
		$this->assertSame( 255, Mcpwp_Approvals::count_by_status()['counts']['pending'] );
	}

	public function test_a_restricted_count_is_exact_over_many_closed_rows(): void {
		// Round 2 dropped the 5000-row scan cap: counts read light columns
		// only, so they are exact for every caller.
		$items = array();
		for ( $i = 0; $i < 5010; $i++ ) {
			$id           = sprintf( 'apr_c%05d', $i );
			$items[ $id ] = array( 'id' => $id, 'status' => 'rejected', 'action' => 'destructive_operation', 'visibility_categories' => array( 'content' ), 'created_at' => '2026-09-01T00:00:00+00:00', 'updated_at' => '2026-09-01T00:00:00+00:00' );
		}
		mcpwp_test_approvals_put( $items );

		$counted = Mcpwp_Approvals::count_by_status(
			static function () {
				return true;
			}
		);
		$this->assertArrayNotHasKey( 'truncated', $counted );
		$this->assertSame( 5010, $counted['counts']['rejected'] );
		$this->assertSame( 5010, Mcpwp_Approvals::count_by_status()['counts']['rejected'], 'Unrestricted: exact.' );
	}

	// ── NIT a ──────────────────────────────────────────────────────────────

	public function test_each_failure_says_what_actually_failed(): void {
		$created = Mcpwp_Approvals::create_post_content_request( self::POST, 'new body', array( 'requested_by_key_id' => 'k1' ) );

		$GLOBALS['mcpwp_test_approvals_fail'] = static function ( $kind ) {
			return 'select' === $kind;
		};
		$read = Mcpwp_Approvals::get_request( $created['id'] );
		$this->assertSame( 'approvals_store_failed', $read->get_error_code() );
		$this->assertStringNotContainsString( 'could not be saved', $read->get_error_message() );
		$this->assertStringContainsString( 'could not be read', $read->get_error_message() );
		$GLOBALS['mcpwp_test_approvals_fail'] = null;

		Mcpwp_Approvals::approve_request( $created['id'], '', array( 'actor' => 'reviewer' ) );
		$seen = 0;
		$GLOBALS['mcpwp_test_approvals_fail'] = static function ( $kind ) use ( &$seen ) {
			return 'update' === $kind && ++$seen > 1; // The claim lands; every finalise write fails.
		};
		$unconfirmed = Mcpwp_Approvals::apply_request( $created['id'], array( 'actor' => 'reviewer' ) );
		$this->assertSame( 'approval_record_unconfirmed', $unconfirmed->get_error_code() );
		$this->assertStringNotContainsString( 'busy', $unconfirmed->get_error_message() );
		$this->assertStringContainsString( 'could not be saved', $unconfirmed->get_error_message() );
	}

	// ── NIT b ──────────────────────────────────────────────────────────────

	public function test_retention_dates_a_record_by_created_at_and_never_deletes_an_undated_one(): void {
		$old = gmdate( 'c', time() - 200 * DAY_IN_SECONDS );
		mcpwp_test_approvals_put(
			array(
				'apr_old_created' => array( 'id' => 'apr_old_created', 'status' => 'rejected', 'action' => 'destructive_operation', 'created_at' => $old ),
				'apr_new_created' => array( 'id' => 'apr_new_created', 'status' => 'rejected', 'action' => 'destructive_operation', 'created_at' => gmdate( 'c' ) ),
				'apr_undated'     => array( 'id' => 'apr_undated', 'status' => 'rejected', 'action' => 'destructive_operation' ),
			)
		);
		$this->assertSame( 1, Mcpwp_Approvals::run_retention() );
		$left = mcpwp_test_approvals_all();
		$this->assertArrayNotHasKey( 'apr_old_created', $left );
		$this->assertArrayHasKey( 'apr_new_created', $left, 'No updated_at: dated by a recent created_at.' );
		$this->assertArrayHasKey( 'apr_undated', $left, 'No date at all: never deleted.' );
	}

	// ── NIT c ──────────────────────────────────────────────────────────────

	public function test_a_retried_removal_writes_one_audit_row(): void {
		$old = gmdate( 'c', time() - 100 * DAY_IN_SECONDS );
		mcpwp_test_approvals_put(
			array(
				'apr_retry' => array( 'id' => 'apr_retry', 'status' => 'rejected', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k', 'created_at' => $old, 'updated_at' => $old ),
			)
		);
		// An earlier run wrote the audit row, then lost its version-guarded
		// DELETE to a concurrent write.
		$this->assertTrue( Mcpwp_Action_Log::record_approval_removal( array( 'approval_id' => 'apr_retry', 'removal_reason' => 'retention' ) ) );

		$this->assertSame( 1, Mcpwp_Approvals::run_retention() );
		$this->assertArrayNotHasKey( 'apr_retry', mcpwp_test_approvals_all() );
		$this->assertSame( array( 'apr_retry' ), $this->audit_ids(), 'Exactly one audit row.' );

		// "Could not check the log" is never "not logged yet": nothing removed.
		mcpwp_test_approvals_put(
			array(
				'apr_unsure' => array( 'id' => 'apr_unsure', 'status' => 'rejected', 'action' => 'destructive_operation', 'created_at' => $old, 'updated_at' => $old ),
			)
		);
		$GLOBALS['mcpwp_test_action_log_read_fails'] = true;
		$this->assertSame( 0, Mcpwp_Approvals::run_retention() );
		$this->assertArrayHasKey( 'apr_unsure', mcpwp_test_approvals_all() );
	}

	// ── NIT d ──────────────────────────────────────────────────────────────

	public function test_a_damaged_row_is_not_found_to_a_restricted_caller(): void {
		mcpwp_test_approvals_put(
			array(
				'apr_dmg' => array( 'id' => 'apr_dmg', 'status' => 'pending', 'action' => 'destructive_operation', 'visibility_categories' => array( 'woocommerce' ), 'created_at' => gmdate( 'c' ), 'updated_at' => gmdate( 'c' ) ),
			)
		);
		$GLOBALS['mcpwp_test_approvals_rows']['apr_dmg']['record'] = '{"id":"apr_dmg","status":"pend';

		$resolve = new ReflectionMethod( Mcpwp_REST_Approvals::class, 'resolve_visible_approval' );
		$resolve->setAccessible( true );

		$restricted = new class() extends Mcpwp_REST_Approvals {
			public function caller_may_read_category( $category ) {
				return 'woocommerce' !== $category;
			}
			protected function requester_is_unrestricted() {
				return false;
			}
		};
		$this->assertEquals( $resolve->invoke( $restricted, 'apr_nosuch' ), $resolve->invoke( $restricted, 'apr_dmg' ) );

		$unrestricted = new class() extends Mcpwp_REST_Approvals {
			public function caller_may_read_category( $category ) {
				return true;
			}
			protected function requester_is_unrestricted() {
				return true;
			}
		};
		$damaged = $resolve->invoke( $unrestricted, 'apr_dmg' );
		$this->assertSame( 'approvals_store_failed', $damaged->get_error_code() );
		$this->assertSame( 503, $damaged->get_error_data()['status'] );
	}

	// ── NIT e ──────────────────────────────────────────────────────────────

	public function test_cap_exemption_is_a_real_column_filled_everywhere(): void {
		$exempt = Mcpwp_Approvals::create_post_content_request( self::POST, 'a', array( 'requested_by_key_id' => 'admin-key', 'key_record' => mcpwp_test_admin_key() ) );
		$capped = Mcpwp_Approvals::create_post_content_request( self::POST, 'b', array( 'requested_by_key_id' => 'webmcp:user:5' ) );
		$wpuser = Mcpwp_Approvals::create_post_content_request( self::POST, 'c', array( 'requested_by_key_id' => 'wp-user:1' ) );
		$this->assertSame( 1, (int) $GLOBALS['mcpwp_test_approvals_rows'][ $exempt['id'] ]['cap_exempt'] );
		$this->assertSame( 0, (int) $GLOBALS['mcpwp_test_approvals_rows'][ $capped['id'] ]['cap_exempt'] );
		$this->assertSame( 1, (int) $GLOBALS['mcpwp_test_approvals_rows'][ $wpuser['id'] ]['cap_exempt'] );
		$this->assertFalse( (bool) preg_grep( '/NOT LIKE/', $GLOBALS['mcpwp_test_approvals_statements'] ), 'No LIKE over the record.' );

		// A table created by an earlier schema gets the column backfilled.
		foreach ( $GLOBALS['mcpwp_test_approvals_rows'] as $id => $row ) {
			$GLOBALS['mcpwp_test_approvals_rows'][ $id ]['cap_exempt']  = 0;
			$GLOBALS['mcpwp_test_approvals_rows'][ $id ]['quota_bytes'] = 0;
		}
		update_option( Mcpwp_Approvals::SCHEMA_OPTION, 1 );
		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );
		$this->assertSame( 1, (int) $GLOBALS['mcpwp_test_approvals_rows'][ $exempt['id'] ]['cap_exempt'] );
		$this->assertGreaterThan( 0, (int) $GLOBALS['mcpwp_test_approvals_rows'][ $capped['id'] ]['quota_bytes'] );
		$this->assertSame( Mcpwp_Approvals::SCHEMA_VERSION, (int) get_option( Mcpwp_Approvals::SCHEMA_OPTION ) );
	}
}
