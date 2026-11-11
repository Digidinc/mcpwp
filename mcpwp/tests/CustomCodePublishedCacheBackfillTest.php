<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';

final class CustomCodePublishedCacheBackfillTest extends TestCase {
	private $error_log;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['mcpwp_test_user_caps'] = array( 1 => array( 'manage_options' => true ) );
		$this->error_log = ini_get( 'error_log' );
		ini_set( 'error_log', '/dev/null' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_wpdb_read_fails'], $GLOBALS['mcpwp_test_wpdb_intercept'] );
		ini_set( 'error_log', $this->error_log );
	}

	private function seed( $id, $status = 'publish', $conditions = null, $location = 'elementor_head' ) {
		$GLOBALS['_mcpwp_test_posts'][$id] = new WP_Post( array( 'ID' => $id, 'post_type' => 'elementor_snippet', 'post_status' => $status, 'post_title' => 'Snippet '.$id ) );
		update_post_meta( $id, '_elementor_conditions', $conditions ?? array( array( 'type' => 'include', 'name' => 'general' ) ) );
		update_post_meta( $id, '_elementor_location', $location );
	}

	public function test_upgrade_backfills_a_published_legacy_snippet_with_an_unsafe_neighbor(): void {
		$this->seed( 682 );
		$this->seed( 683, 'publish', array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '5' ) ) );
		$this->assertTrue( Mcpwp_Activator::repair_custom_code_conditions() );
		$this->assertSame( array( 'include/general' ), get_post_meta( 682, '_elementor_conditions', true ) );
		$this->assertSame( array( 'include/general' ), Mcpwp_Custom_Code_Cache_Entry::entry( 682 )['conditions'] ?? null );
		$this->assertNull( Mcpwp_Custom_Code_Cache_Entry::entry( 683 ) );
		$this->assertContains( 683, Mcpwp_Custom_Code_Conditions::skipped_ids() );
	}

	public function test_existing_metadata_completion_does_not_skip_the_new_cache_backfill(): void {
		update_option( 'mcpwp_custom_code_conditions_repaired', 1 );
		$this->seed( 682 );
		$this->assertTrue( Mcpwp_Activator::repair_custom_code_conditions() );
		$this->assertSame( array( 'include/general' ), Mcpwp_Custom_Code_Cache_Entry::entry( 682 )['conditions'] ?? null );
	}

	public function test_admin_refresh_backfills_late_missing_and_stale_entries(): void {
		$this->seed( 682 );
		Mcpwp_Custom_Code_Conditions::refresh_admin_report();
		$this->seed( 686, 'publish', array( 'include/singular/page/5' ), 'elementor_body_end' );
		Mcpwp_Custom_Code_Cache_Entry::sync( 686, 'elementor_head', array( 'include/general' ) );
		Mcpwp_Custom_Code_Conditions::invalidate_report_on_meta( 1, 686, '_elementor_conditions' );
		Mcpwp_Custom_Code_Conditions::refresh_admin_report();
		$this->assertSame( 'elementor_head', Mcpwp_Custom_Code_Cache_Entry::entry( 682 )['location'] ?? null );
		$this->assertSame( 'elementor_body_end', Mcpwp_Custom_Code_Cache_Entry::entry( 686 )['location'] ?? null );
	}

	public function test_drafts_and_trash_are_not_added_and_reruns_are_idempotent(): void {
		$this->seed( 682 );$this->seed( 684, 'draft' );$this->seed( 685, 'trash' );
		Mcpwp_Activator::repair_custom_code_conditions();
		$this->assertNotNull( Mcpwp_Custom_Code_Cache_Entry::entry( 682 ) );
		$this->assertNull( Mcpwp_Custom_Code_Cache_Entry::entry( 684 ) );
		$this->assertNull( Mcpwp_Custom_Code_Cache_Entry::entry( 685 ) );
		Mcpwp_Activator::repair_custom_code_conditions();Mcpwp_Custom_Code_Conditions::refresh_admin_report();
		$this->assertSame( 'elementor_head', Mcpwp_Custom_Code_Cache_Entry::entry( 682 )['location'] );
	}

	public function test_one_sync_failure_is_reported_and_does_not_stop_other_published_entries(): void {
		$this->seed( 682 );$this->seed( 686 );
		$reads=0;
		$GLOBALS['mcpwp_test_wpdb_read_fails'] = static function ( $name ) use ( &$reads ) {
			return Mcpwp_Custom_Code_Cache_Entry::row_name( 682 ) === $name && ++$reads <= 3;
		};
		$log=tempnam(sys_get_temp_dir(),'cache-backfill-');ini_set('error_log',$log);
		try {
			$this->assertTrue( Mcpwp_Activator::repair_custom_code_conditions( 30.0 ) );
			$this->assertSame(array('include/general'),Mcpwp_Custom_Code_Cache_Entry::entry(686)['conditions']??null);
			$this->assertContains(682,get_option('mcpwp_custom_code_cache_repair_failed_ids',array()));
			$this->assertStringContainsString('682',file_get_contents($log));
			ob_start();Mcpwp_Custom_Code_Conditions::render_admin_notice();$notice=ob_get_clean();
			$this->assertStringContainsString('682',$notice);
			$this->assertStringContainsString('682',Mcpwp_Custom_Code_Conditions::site_health_test()['description']);
			$this->assertContains(682,Mcpwp_Custom_Code_Conditions::annotate_response(array(),false)['custom_code_cache_repair_failed_ids']);
			$state = get_option( Mcpwp_Activator::CUSTOM_CODE_CACHE_REPAIR_FAILURE_STATE_OPTION, array() );
			$state[682]['next_retry'] = time() - 1;
			update_option( Mcpwp_Activator::CUSTOM_CODE_CACHE_REPAIR_FAILURE_STATE_OPTION, $state );
			Mcpwp_Custom_Code_Conditions::refresh_admin_report();
			$this->assertSame(array('include/general'),Mcpwp_Custom_Code_Cache_Entry::entry(682)['conditions']??null);
			$this->assertSame(array(),get_option('mcpwp_custom_code_cache_repair_failed_ids',array()));
		} finally { unlink($log); }
	}
	public function test_admin_refresh_resumes_backfill_even_with_a_fresh_diagnostic_report(): void {
		for ( $id = 1; $id <= Mcpwp_Activator::CUSTOM_CODE_REPAIR_BATCH_SIZE + 1; ++$id ) {
			$this->seed( $id );
		}
		update_option( Mcpwp_Custom_Code_Conditions::REPORT_OPTION, array(
			'marker' => (string) get_option( Mcpwp_Custom_Code_Conditions::REPORT_MARKER, '' ),
			'ids' => array(), 'expires_at' => time() + 600,
		) );
		Mcpwp_Activator::repair_custom_code_conditions( 0.0 );
		$this->assertSame( Mcpwp_Activator::CUSTOM_CODE_REPAIR_BATCH_SIZE, get_option( Mcpwp_Activator::CUSTOM_CODE_CONDITIONS_REPAIR_CURSOR_OPTION ) );
		$this->assertFalse( get_option( Mcpwp_Activator::CUSTOM_CODE_CACHE_BACKFILL_DONE_OPTION, false ) );
		$scans = 0;
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) use ( &$scans ) {
			if ( 'get_col' === $method && false !== strpos( $query, 'elementor_snippet' ) ) { ++$scans; }
			return MCPWP_TEST_WPDB_PASS;
		};
		try {
			Mcpwp_Custom_Code_Conditions::refresh_admin_report();
			$this->assertSame( 1, get_option( Mcpwp_Activator::CUSTOM_CODE_CACHE_BACKFILL_DONE_OPTION ) );
			$this->assertNotNull( Mcpwp_Custom_Code_Cache_Entry::entry( Mcpwp_Activator::CUSTOM_CODE_REPAIR_BATCH_SIZE + 1 ) );
			$completed_scans = $scans;
			Mcpwp_Custom_Code_Conditions::refresh_admin_report();
			$this->assertSame( $completed_scans, $scans, 'A completed, unchanged admin request remains scan-free.' );
		} finally { unset( $GLOBALS['mcpwp_test_wpdb_intercept'] ); }
	}

	public function test_parked_failure_does_not_gate_the_next_full_rescan(): void {
		Mcpwp_Custom_Code_Conditions::register_hooks();
		$this->seed( 9 );
		$this->seed( 11 );
		$attempts = 0;
		$GLOBALS['mcpwp_test_wpdb_read_fails'] = static function ( $name ) use ( &$attempts ) {
			if ( Mcpwp_Custom_Code_Cache_Entry::row_name( 9 ) === $name ) {
				++$attempts;
				return true;
			}
			return false;
		};

		for ( $pass = 0; $pass < Mcpwp_Activator::CUSTOM_CODE_CACHE_REPAIR_MAX_ATTEMPTS; ++$pass ) {
			$state = get_option( Mcpwp_Activator::CUSTOM_CODE_CACHE_REPAIR_FAILURE_STATE_OPTION, array() );
			if ( isset( $state[9] ) ) {
				$state[9]['next_retry'] = time() - 1;
				update_option( Mcpwp_Activator::CUSTOM_CODE_CACHE_REPAIR_FAILURE_STATE_OPTION, $state );
			}
			Mcpwp_Activator::repair_custom_code_conditions( 30.0, true );
		}
		$state = get_option( Mcpwp_Activator::CUSTOM_CODE_CACHE_REPAIR_FAILURE_STATE_OPTION, array() );
		$this->assertTrue( $state[9]['parked'] ?? false );
		$this->assertSame( Mcpwp_Activator::CUSTOM_CODE_CACHE_REPAIR_MAX_ATTEMPTS, $state[9]['attempts'] ?? 0 );

		Mcpwp_Custom_Code_Cache_Entry::remove( 11 );
		$backfill = get_option( Mcpwp_Activator::CUSTOM_CODE_CACHE_BACKFILL_STATE_OPTION, array() );
		$backfill['completed_at'] = time() - Mcpwp_Custom_Code_Conditions::REPORT_TTL - 1;
		update_option( Mcpwp_Activator::CUSTOM_CODE_CACHE_BACKFILL_STATE_OPTION, $backfill );
		$this->assertTrue( Mcpwp_Activator::custom_code_cache_full_rescan_due() );

		Mcpwp_Custom_Code_Conditions::refresh_admin_report();
		$this->assertSame( array( 'include/general' ), Mcpwp_Custom_Code_Cache_Entry::entry( 11 )['conditions'] ?? null );
		$state = get_option( Mcpwp_Activator::CUSTOM_CODE_CACHE_REPAIR_FAILURE_STATE_OPTION, array() );
		$this->assertSame( Mcpwp_Activator::CUSTOM_CODE_CACHE_REPAIR_MAX_ATTEMPTS, $state[9]['attempts'] ?? 0, 'A peer rescan must respect the parked document backoff.' );
		ob_start();
		Mcpwp_Custom_Code_Conditions::render_admin_notice();
		$notice = ob_get_clean();
		$this->assertStringContainsString( 'repair failed', $notice );
		$this->assertStringContainsString( '9', $notice );
		$this->assertStringContainsString( 'repair failed', Mcpwp_Custom_Code_Conditions::site_health_test()['description'] );
		update_post_meta( 9, '_elementor_conditions', array( 'exclude/general' ) );
		$this->assertArrayNotHasKey( 9, get_option( Mcpwp_Activator::CUSTOM_CODE_CACHE_REPAIR_FAILURE_STATE_OPTION, array() ) );
	}

	public function test_oversized_legacy_conditions_are_quarantined_without_caching_or_blocking_a_peer(): void {
		$large = array_fill( 0, 150000, 'include/general' );
		$this->seed( 700, 'publish', $large );
		$this->seed( 701 );
		$this->assertTrue( Mcpwp_Activator::repair_custom_code_conditions( 30.0 ) );
		$this->assertNull( Mcpwp_Custom_Code_Cache_Entry::entry( 700 ) );
		$this->assertContains( 700, Mcpwp_Custom_Code_Conditions::skipped_ids() );
		$this->assertSame( 'conditions_too_large', Mcpwp_Custom_Code_Conditions::quarantine_reason( 700 ) );
		$this->assertSame( array( 'include/general' ), Mcpwp_Custom_Code_Cache_Entry::entry( 701 )['conditions'] ?? null );
	}

}
