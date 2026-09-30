<?php
/**
 * #1042 adversarial review, round 2 — one test per finding.
 *
 *  BLOCK  who is exempt (one function); a 64 MB open-bytes ceiling for
 *         everyone; listing and counting never load full records they do
 *         not return.
 *  WARN 1 the late import never resurrects an id it imported before; the
 *         2.8.x bridge stops re-feeding the legacy option.
 *  WARN 2 the migration also holds 3.14.8's own store lock.
 *  NITs   damaged rows as stubs for unrestricted callers, full pages for
 *         restricted ones; the late import runs lazily on approvals paths;
 *         late-imported identity-less requests are dropped.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-site-state.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-migrate.php';

final class ApprovalsTableRound2Test extends TestCase {

	private const POST = 10440;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']          = array();
		$GLOBALS['mcpwp_test_options_autoload'] = array();
		$GLOBALS['mcpwp_test_wpdb_options']     = array();
		$GLOBALS['mcpwp_test_filters']          = array();
		$GLOBALS['mcpwp_test_wpdb_rows']        = array();
		$GLOBALS['mcpwp_test_named_lock_calls'] = array();
		$GLOBALS['mcpwp_test_named_lock_names'] = array();
		$GLOBALS['mcpwp_test_user_caps']        = array(
			1 => array( 'manage_options' => true ),
			3 => array( 'edit_posts' => true ),
		);
		$GLOBALS['mcpwp_test_current_user']     = 1;
		$GLOBALS['_mcpwp_test_posts']           = array(
			self::POST => new WP_Post( array( 'ID' => self::POST, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'P', 'post_content' => 'original body' ) ),
		);
		unset( $GLOBALS['mcpwp_test_get_lock_result'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_get_lock_result'] );
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	private function statements_since( int $from ): array {
		return array_slice( $GLOBALS['mcpwp_test_approvals_statements'], $from );
	}

	private function big_rows( int $n, int $bytes, string $status = 'pending' ): array {
		$items = array();
		$body  = str_repeat( 'x', $bytes );
		for ( $i = 0; $i < $n; $i++ ) {
			$id           = sprintf( 'apr_big%03d', $i );
			$when         = gmdate( 'c', time() - ( $n - $i ) );
			$items[ $id ] = array( 'id' => $id, 'status' => $status, 'action' => 'post_content_update', 'requested_by_key_id' => 'admin-key', 'visibility_categories' => array( 'content' ), 'resource' => array( 'type' => 'post', 'id' => self::POST ), 'payload' => array( 'after_content' => $body ), 'metadata' => array( 'cap_exempt' => true ), 'created_at' => $when, 'updated_at' => $when );
		}
		return $items;
	}

	// ── BLOCK 1: who is exempt ─────────────────────────────────────────────

	public function test_only_admin_scope_keys_and_wp_admin_administrators_are_exempt(): void {
		$exempt = static function ( $requester, $args = array() ) {
			return Mcpwp_Approvals::requester_is_exempt_from_caps( $requester, $args );
		};
		$this->assertTrue( $exempt( 'wp-user:1' ) );
		$this->assertFalse( $exempt( 'wp-user:3' ), 'A wp-admin user without manage_options is capped.' );
		$this->assertTrue( $exempt( '', array( 'wp_user_id' => 1 ) ), 'The trusted admin-AJAX administrator.' );
		$this->assertTrue( $exempt( 'k-admin', array( 'key_record' => mcpwp_test_admin_key( 'k-admin' ) ) ) );
		$this->assertFalse( $exempt( 'k-write', array( 'key_record' => array( 'id' => 'k-write', 'role' => 'admin', 'scopes' => array( 'read', 'write' ) ) ) ), 'A write-scope admin-role key is an agent.' );
		$this->assertFalse( $exempt( 'oauth-user:9', array( 'key_record' => array( 'id' => 'oauth-user:9', 'type' => 'oauth', 'scopes' => array( 'read', 'write', 'admin' ) ) ) ) );
		$this->assertFalse( $exempt( 'broker:1', array( 'key_record' => array( 'id' => 'broker:1', 'type' => 'broker', 'scopes' => array( 'admin' ) ) ) ) );
		$this->assertFalse( $exempt( 'webmcp:user:1', array( 'wp_user_id' => 1 ) ) );
	}

	public function test_a_write_scope_admin_role_key_and_an_oauth_contributor_are_capped(): void {
		$writer = array( 'id' => 'k-write', 'role' => 'admin', 'scopes' => array( 'read', 'write' ) );
		$oauth  = array( 'id' => 'oauth-user:7', 'type' => 'oauth', 'scopes' => array( 'read', 'write' ) );
		foreach ( array( $writer, $oauth ) as $key ) {
			for ( $i = 0; $i < Mcpwp_Approvals::MAX_PENDING_CONTENT_PER_REQUESTER; $i++ ) {
				$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, "{$key['id']}-$i", array( 'requested_by_key_id' => $key['id'], 'key_record' => $key ) ) );
			}
			$over = Mcpwp_Approvals::create_post_content_request( self::POST, 'one more', array( 'requested_by_key_id' => $key['id'], 'key_record' => $key ) );
			$this->assertSame( 'too_many_pending', $over->get_error_code(), $key['id'] );
		}
		$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, 'admin', array( 'requested_by_key_id' => 'k-admin', 'key_record' => mcpwp_test_admin_key( 'k-admin' ) ) ) );
	}

	// ── BLOCK 2: open-bytes ceiling ────────────────────────────────────────

	public function test_the_open_bytes_ceiling_binds_everyone_but_never_a_status_change(): void {
		add_filter(
			'mcpwp_approvals_open_bytes_max',
			static function () {
				return 150000;
			}
		);
		$first = Mcpwp_Approvals::create_post_content_request( self::POST, str_repeat( 'a', 70000 ), array( 'requested_by_key_id' => 'k-admin', 'key_record' => mcpwp_test_admin_key( 'k-admin' ) ) );
		$this->assertIsArray( $first );
		$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, str_repeat( 'b', 70000 ), array( 'requested_by_key_id' => 'wp-user:1' ) ) );

		foreach ( array( array( 'requested_by_key_id' => 'k-admin', 'key_record' => mcpwp_test_admin_key( 'k-admin' ) ), array( 'requested_by_key_id' => 'wp-user:1' ) ) as $args ) {
			$full = Mcpwp_Approvals::create_post_content_request( self::POST, str_repeat( 'c', 70000 ), $args );
			$this->assertSame( 'approvals_store_full', $full->get_error_code() );
			$this->assertSame( 507, $full->get_error_data()['status'] );
		}

		// Status changes are never refused; deciding one frees room.
		$this->assertSame( 'rejected', Mcpwp_Approvals::reject_request( $first['id'] )['status'] );
		$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, str_repeat( 'c', 70000 ), array( 'requested_by_key_id' => 'wp-user:1' ) ) );
		$this->assertSame( 67108864, Mcpwp_Approvals::OPEN_BYTES_MAX );
	}

	// ── BLOCK 3: light listing and counting ────────────────────────────────

	public function test_listing_and_counting_64_one_mb_rows_stay_small(): void {
		mcpwp_test_approvals_put( $this->big_rows( 64, 1048576 ) );
		$restricted = static function ( $category ) {
			return 'woocommerce' !== $category;
		};

		$from = count( $GLOBALS['mcpwp_test_approvals_statements'] );
		memory_reset_peak_usage();
		$base = memory_get_usage();

		$page = Mcpwp_Approvals::list_requests( '', 1, true, 'date', $restricted );
		$this->assertCount( 1, $page );
		$this->assertSame( 'apr_big063', $page[0]['id'] );

		$summary = new ReflectionMethod( Mcpwp_Site_State::class, 'get_approval_summary' );
		$summary->setAccessible( true );
		$this->assertSame( 64, $summary->invoke( null, true, $restricted )['counts']['pending'] );
		$this->assertSame( 64, Mcpwp_Approvals::count_by_status()['counts']['pending'] );

		$this->assertLessThan( 12 * 1048576, memory_get_peak_usage() - $base, 'Never more than the page\'s own records in memory.' );

		$full = array_values( preg_grep( '/^SELECT [^F]*\brecord\b/', $this->statements_since( $from ) ) );
		foreach ( $full as $sql ) {
			$this->assertMatchesRegularExpression( "/^SELECT id, record FROM \\S+ WHERE id IN \\('apr_big0\\d\\d'(, 'apr_big0\\d\\d'){0,4}\\)$/", $sql, 'Only a page\'s own ids (at most 5 here) are loaded in full.' );
		}
		$this->assertSame( "SELECT id, record FROM wp_mcpwp_approvals WHERE id IN ('apr_big063')", $full[0], 'limit=1 loads exactly one record.' );
	}

	public function test_a_page_stops_loading_past_eight_mb(): void {
		mcpwp_test_approvals_put( $this->big_rows( 6, 3 * 1048576 ) );
		$page = Mcpwp_Approvals::list_requests( '', 5, true );
		$this->assertGreaterThanOrEqual( 1, count( $page ) );
		$this->assertLessThanOrEqual( 3, count( $page ), '3 MB records: the page stops once it passes 8 MB.' );
	}

	// ── NIT a: damaged rows ────────────────────────────────────────────────

	public function test_damaged_rows_are_stubs_for_unrestricted_callers_and_never_end_a_restricted_page(): void {
		$items = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$id           = sprintf( 'apr_d%02d', $i );
			$when         = gmdate( 'c', time() - ( 10 - $i ) );
			$items[ $id ] = array( 'id' => $id, 'status' => 'pending', 'action' => 'destructive_operation', 'visibility_categories' => array( 'content' ), 'created_at' => $when, 'updated_at' => $when );
		}
		mcpwp_test_approvals_put( $items );
		$GLOBALS['mcpwp_test_approvals_rows']['apr_d05']['record'] = '{"id":"apr_d05","sta'; // Newest row, damaged.

		$admin = Mcpwp_Approvals::list_requests( '', 3, true );
		$this->assertSame( array( 'id' => 'apr_d05', 'status' => 'pending', 'error' => 'corrupt_record' ), $admin[0] );
		$this->assertCount( 3, $admin );

		$restricted = Mcpwp_Approvals::list_requests(
			'',
			5,
			true,
			'date',
			static function () {
				return true;
			},
			false
		);
		$this->assertCount( 5, $restricted, 'The damaged row is hidden, and the page is still full.' );
		$this->assertNotContains( 'apr_d05', array_column( $restricted, 'id' ) );

		$this->assertSame( 6, Mcpwp_Approvals::count_by_status()['counts']['pending'], 'Counted under its status column.' );
	}

	// ── WARN 1: tombstones, and the bridge ─────────────────────────────────

	public function test_an_imported_id_deleted_from_the_table_is_never_reinserted(): void {
		$now = gmdate( 'c' );
		update_option( Mcpwp_Approvals::OPTION_NAME, array( 'apr_gone' => array( 'id' => 'apr_gone', 'status' => 'approved', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k1', 'created_at' => $now, 'updated_at' => $now ) ), false );
		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );
		unset( $GLOBALS['mcpwp_test_approvals_rows']['apr_gone'] ); // Purged / deleted since.

		// The old option comes back with the stale copy and a genuinely new id.
		update_option(
			Mcpwp_Approvals::OPTION_NAME,
			array(
				'apr_gone' => array( 'id' => 'apr_gone', 'status' => 'approved', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k1', 'created_at' => $now, 'updated_at' => $now ),
				'apr_new'  => array( 'id' => 'apr_new', 'status' => 'pending', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k2', 'created_at' => $now, 'updated_at' => $now ),
			),
			false
		);
		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );

		$this->assertSame( array( 'apr_new' ), array_keys( mcpwp_test_approvals_all() ), 'Only the new id entered the table.' );
		$this->assertSame( Mcpwp_Approvals::not_found_error()->get_error_code(), Mcpwp_Approvals::get_request( 'apr_gone' )->get_error_code() );
	}

	public function test_the_spai_bridge_does_not_refeed_the_legacy_queue_after_migration(): void {
		update_option( 'mcpwp_approvals_table_migrated', 1 );
		update_option( 'spai_settings', array( 'enable_logging' => true ) );
		update_option( 'spai_approval_requests', array( 'apr_spai' => array( 'id' => 'apr_spai', 'status' => 'approved', 'requested_by_key_id' => 'k' ) ) );

		Mcpwp_Migrate::run();

		$this->assertFalse( get_option( Mcpwp_Approvals::OPTION_NAME, false ) );
	}

	// ── WARN 2: the old store lock ─────────────────────────────────────────

	public function test_the_migration_holds_the_old_store_lock_around_the_copy(): void {
		update_option( Mcpwp_Approvals::OPTION_NAME, array( 'apr_x' => array( 'id' => 'apr_x', 'status' => 'pending', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k1', 'created_at' => gmdate( 'c' ), 'updated_at' => gmdate( 'c' ) ) ), false );

		// An old-code worker holds 3.14.8's lock: nothing is read or moved.
		$GLOBALS['mcpwp_test_get_lock_result'] = '0';
		$busy = Mcpwp_Activator::migrate_approvals_to_table();
		$this->assertSame( 'approvals_busy', $busy->get_error_code() );
		$this->assertIsArray( get_option( Mcpwp_Approvals::OPTION_NAME ) );
		$this->assertFalse( get_option( Mcpwp_Approvals::MIGRATED_FLAG, false ) );
		$this->assertSame( 'mcpwp_approvals_wp_', $GLOBALS['mcpwp_test_named_lock_names'][0] );

		unset( $GLOBALS['mcpwp_test_get_lock_result'] );
		$GLOBALS['mcpwp_test_named_lock_calls'] = array();
		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );
		$this->assertSame( array( 'get', 'release' ), $GLOBALS['mcpwp_test_named_lock_calls'], 'Taken and released around the copy.' );
	}

	// ── NIT b + c: lazy late import, identity check ────────────────────────

	public function test_the_late_import_runs_lazily_and_drops_identity_less_requests(): void {
		mcpwp_test_approvals_put( array() );
		$now = gmdate( 'c' );
		update_option(
			Mcpwp_Approvals::OPTION_NAME,
			array(
				'apr_anon' => array( 'id' => 'apr_anon', 'status' => 'pending', 'action' => 'destructive_operation', 'requested_by_key_id' => '', 'created_at' => $now, 'updated_at' => $now ),
				'apr_named' => array( 'id' => 'apr_named', 'status' => 'pending', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k9', 'created_at' => $now, 'updated_at' => $now ),
			),
			false
		);

		// No hook ran; the next approvals read imports it.
		$this->assertSame( 'pending', Mcpwp_Approvals::get_request( 'apr_named' )['status'] );
		$this->assertSame( 'approval_not_found', Mcpwp_Approvals::get_request( 'apr_anon' )->get_error_code(), 'Identity-less pending: dropped.' );
		$this->assertFalse( get_option( Mcpwp_Approvals::OPTION_NAME, false ) );
		$this->assertSame( 'mcpwp_approvals_late_import', Mcpwp_Approvals::LATE_IMPORT_CRON_HOOK );
	}
}
