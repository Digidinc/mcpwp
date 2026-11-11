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
		unset( $GLOBALS['mcpwp_test_wpdb_read_fails'] );
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
		$cache = get_option( 'elementor_pro_theme_builder_conditions', array() );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][682] ?? null );
		$this->assertArrayNotHasKey( 683, $cache['elementor_head'] ?? array() );
		$this->assertContains( 683, Mcpwp_Custom_Code_Conditions::skipped_ids() );
	}

	public function test_existing_metadata_completion_does_not_skip_the_new_cache_backfill(): void {
		update_option( 'mcpwp_custom_code_conditions_repaired', 1 );
		$this->seed( 682 );
		$this->assertTrue( Mcpwp_Activator::repair_custom_code_conditions() );
		$this->assertSame( array( 'include/general' ), get_option( 'elementor_pro_theme_builder_conditions', array() )['elementor_head'][682] ?? null );
	}

	public function test_admin_refresh_backfills_late_missing_and_stale_entries(): void {
		$this->seed( 682 );
		Mcpwp_Custom_Code_Conditions::refresh_admin_report();
		$this->seed( 686, 'publish', array( 'include/singular/page/5' ), 'elementor_body_end' );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'elementor_head' => array( 686 => array( 'include/general' ) ) ) );
		Mcpwp_Custom_Code_Conditions::invalidate_report_on_meta( 1, 686, '_elementor_conditions' );
		Mcpwp_Custom_Code_Conditions::refresh_admin_report();
		$cache = get_option( 'elementor_pro_theme_builder_conditions', array() );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][682] ?? null );
		$this->assertSame( array( 'include/singular/page/5' ), $cache['elementor_body_end'][686] ?? null );
		$this->assertArrayNotHasKey( 686, $cache['elementor_head'] ?? array() );
	}

	public function test_drafts_and_trash_are_not_added_and_reruns_are_idempotent(): void {
		$this->seed( 682 );$this->seed( 684, 'draft' );$this->seed( 685, 'trash' );
		Mcpwp_Activator::repair_custom_code_conditions();
		$cache = get_option( 'elementor_pro_theme_builder_conditions', array() );
		$this->assertArrayHasKey( 682, $cache['elementor_head'] ?? array() );
		$this->assertArrayNotHasKey( 684, $cache['elementor_head'] ?? array() );
		$this->assertArrayNotHasKey( 685, $cache['elementor_head'] ?? array() );
		Mcpwp_Activator::repair_custom_code_conditions();Mcpwp_Custom_Code_Conditions::refresh_admin_report();
		$this->assertSame( $cache, get_option( 'elementor_pro_theme_builder_conditions' ) );
	}

	public function test_one_sync_failure_is_reported_and_does_not_stop_other_published_entries(): void {
		$this->seed( 682 );$this->seed( 686 );
		$reads=0;
		$GLOBALS['mcpwp_test_wpdb_read_fails'] = static function ( $name ) use ( &$reads ) {
			return 'elementor_pro_theme_builder_conditions' === $name && ++$reads <= 3;
		};
		$log=tempnam(sys_get_temp_dir(),'cache-backfill-');ini_set('error_log',$log);
		try {
			$this->assertTrue( Mcpwp_Activator::repair_custom_code_conditions( 30.0 ) );
			$cache=get_option('elementor_pro_theme_builder_conditions',array());
			$this->assertSame(array('include/general'),$cache['elementor_head'][686]??null);
			$this->assertContains(682,get_option('mcpwp_custom_code_cache_repair_failed_ids',array()));
			$this->assertStringContainsString('682',file_get_contents($log));
			ob_start();Mcpwp_Custom_Code_Conditions::render_admin_notice();$notice=ob_get_clean();
			$this->assertStringContainsString('682',$notice);
			$this->assertStringContainsString('682',Mcpwp_Custom_Code_Conditions::site_health_test()['description']);
			$this->assertContains(682,Mcpwp_Custom_Code_Conditions::annotate_response(array(),false)['custom_code_cache_repair_failed_ids']);
			Mcpwp_Custom_Code_Conditions::refresh_admin_report();
			$this->assertSame(array('include/general'),get_option('elementor_pro_theme_builder_conditions')['elementor_head'][682]??null);
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
			$this->assertCount( Mcpwp_Activator::CUSTOM_CODE_REPAIR_BATCH_SIZE + 1, get_option( 'elementor_pro_theme_builder_conditions' )['elementor_head'] );
			$completed_scans = $scans;
			Mcpwp_Custom_Code_Conditions::refresh_admin_report();
			$this->assertSame( $completed_scans, $scans, 'A completed, unchanged admin request remains scan-free.' );
		} finally { unset( $GLOBALS['mcpwp_test_wpdb_intercept'] ); }
	}

}
