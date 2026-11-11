<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-custom-code-conditions.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';

final class CustomCodeConditionsReportCacheTest extends TestCase {
	private $previous_error_log;

	protected function setUp(): void {
		$this->previous_error_log = ini_get( 'error_log' );
		$GLOBALS['mcpwp_test_options']       = array();
		$GLOBALS['_mcpwp_test_posts']        = array();
		$GLOBALS['_mcpwp_test_meta']         = array();
		$GLOBALS['mcpwp_test_current_user']  = 1;
		$GLOBALS['mcpwp_test_user_caps']     = array( 1 => array( 'manage_options' => true ) );
		$GLOBALS['mcpwp_test_wpdb_intercept'] = null;
		ini_set( 'error_log', '/dev/null' );
		Mcpwp_Custom_Code_Conditions::register_hooks();
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->previous_error_log );
		$GLOBALS['mcpwp_test_wpdb_intercept'] = null;
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
		$GLOBALS['mcpwp_test_option_store_after_hook'] = null;
	}

	private function seed( $id, $conditions ) {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post( array( 'ID' => $id, 'post_type' => 'elementor_snippet', 'post_status' => 'publish' ) );
		update_post_meta( $id, '_elementor_conditions', $conditions );
	}

	public function test_admin_refresh_caches_full_ids_and_rescans_only_after_a_condition_change(): void {
		for ( $id = 1; $id <= 1500; $id++ ) {
			$this->seed( $id, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => (string) $id ) ) );
		}
		$this->seed( 1501, array( 'include/general' ) );
		$this->assertInstanceOf( WP_Error::class, Mcpwp_Custom_Code_Conditions::convert( Mcpwp_Custom_Code_Conditions::raw_conditions( 1500 ) ) );
		$queries = 0;
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) use ( &$queries ) {
			if ( 'get_col' === $method && false !== strpos( $query, "post_type = 'elementor_snippet'" ) ) {
				$queries++;
			}
			return MCPWP_TEST_WPDB_PASS;
		};

		Mcpwp_Custom_Code_Conditions::refresh_admin_report();
		$first_queries = $queries;
		$this->assertGreaterThan( 1, $first_queries );
		$this->assertSame( array_values( range( 1, 1500 ) ), Mcpwp_Custom_Code_Conditions::report_ids() );
		$this->assertCount( 1500, get_option( Mcpwp_Custom_Code_Conditions::QUARANTINE_OPTION ) );

		Mcpwp_Custom_Code_Conditions::refresh_admin_report();
		$this->assertSame( $first_queries, $queries, 'An unchanged admin request reuses the cached report.' );

		update_post_meta( 1, '_elementor_conditions', array( 'include/general' ) );
		Mcpwp_Custom_Code_Conditions::refresh_admin_report();
		$this->assertGreaterThan( $first_queries, $queries );
		$this->assertSame( array_values( range( 2, 1500 ) ), Mcpwp_Custom_Code_Conditions::report_ids() );
	}

	public function test_notice_and_health_show_a_count_and_only_the_first_twenty_ids(): void {
		for ( $id = 1; $id <= 25; $id++ ) {
			$this->seed( $id, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => (string) $id ) ) );
		}
		$this->seed( 26, array( 'include/general' ) );
		Mcpwp_Custom_Code_Conditions::refresh_admin_report();
		ob_start();
		Mcpwp_Custom_Code_Conditions::render_admin_notice();
		$notice = ob_get_clean();
		$this->assertStringContainsString( '25 snippet', $notice );
		$this->assertStringContainsString( '20', $notice );
		$this->assertStringNotContainsString( '25.', $notice );
		$health = Mcpwp_Custom_Code_Conditions::site_health_test();
		$this->assertStringContainsString( '25 snippet', $health['description'] );
		$this->assertStringNotContainsString( '25.', $health['description'] );
	}

	public function test_report_annotation_uses_the_cached_full_list_without_a_runtime_scan(): void {
		$this->seed( 15, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '15' ) ) );
		$this->seed( 16, array( 'include/general' ) );
		Mcpwp_Custom_Code_Conditions::refresh_admin_report();
		$report = get_option( Mcpwp_Custom_Code_Conditions::REPORT_OPTION );
		$report['ids'] = array( 15 );
		update_option( Mcpwp_Custom_Code_Conditions::REPORT_OPTION, $report );
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) {
			if ( 'get_col' === $method && false !== strpos( $query, "post_type = 'elementor_snippet'" ) ) {
				throw new RuntimeException( 'response unexpectedly rescanned snippets' );
			}
			return MCPWP_TEST_WPDB_PASS;
		};
		$this->assertSame( array( 15 ), Mcpwp_Custom_Code_Conditions::annotate_response( array() )['skipped_custom_code_snippet_ids'] );
	}

	public function test_compact_and_legacy_quarantine_storage_preserve_sparse_ids(): void {
		foreach ( array( 15, 1000, 2000 ) as $id ) {
			$this->seed( $id, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => (string) $id ) ) );
		}
		update_option( Mcpwp_Custom_Code_Conditions::QUARANTINE_OPTION, array( 15, 1000, 2000 ) );
		Mcpwp_Custom_Code_Conditions::refresh_admin_report();
		$this->assertSame( array( 15, 1000, 2000 ), Mcpwp_Custom_Code_Conditions::report_ids() );

		update_option( Mcpwp_Custom_Code_Conditions::QUARANTINE_OPTION, array( 15 => 'old-fingerprint', 1000 => 'old-fingerprint', 2000 => 'old-fingerprint' ) );
		Mcpwp_Custom_Code_Conditions::invalidate_report_on_meta( 1, 15, '_elementor_conditions' );
		Mcpwp_Custom_Code_Conditions::refresh_admin_report();
		$this->assertSame( array( 15, 1000, 2000 ), Mcpwp_Custom_Code_Conditions::report_ids() );
		$this->assertSame( array( 15, 1000, 2000 ), get_option( Mcpwp_Custom_Code_Conditions::QUARANTINE_OPTION ), 'Upgrade refresh compacts an unchanged legacy registry too.' );
	}

	public function test_quarantine_option_and_cache_writes_hold_the_shared_mutex(): void {
		$this->seed( 15, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '15' ) ) );
		$observed = array();
		$GLOBALS['mcpwp_test_option_store_after_hook'] = static function ( $name ) use ( &$observed ) {
			if ( in_array( $name, array( Mcpwp_Custom_Code_Conditions::QUARANTINE_OPTION, 'elementor_pro_theme_builder_conditions' ), true ) ) {
				$observed[] = isset( $GLOBALS['mcpwp_test_wpdb_options']['mcpwp_custom_code_cache_write_lock'] );
			}
		};
		$this->assertTrue( Mcpwp_Custom_Code_Conditions::quarantine( 15, Mcpwp_Custom_Code_Conditions::raw_conditions( 15 ) ) );
		$this->assertNotEmpty( $observed );
		$this->assertNotContains( false, $observed );
	}

	public function test_quarantine_waits_for_an_existing_cache_writer(): void {
		$this->seed( 15, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '15' ) ) );
		$lock = 'mcpwp_custom_code_cache_write_lock';
		$GLOBALS['mcpwp_test_wpdb_options'][ $lock ] = (string) ( time() + 90 );
		$attempts = 0;
		$hook = null;
		$hook = static function ( $name ) use ( &$hook, &$attempts, $lock ) {
			if ( $lock === $name && ++$attempts === 2 ) {
				unset( $GLOBALS['mcpwp_test_wpdb_options'][ $lock ] );
			}
			if ( $attempts < 2 ) {
				$GLOBALS['mcpwp_test_wpdb_before_insert'] = $hook;
			}
		};
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = $hook;
		$this->assertTrue( Mcpwp_Custom_Code_Conditions::quarantine( 15, Mcpwp_Custom_Code_Conditions::raw_conditions( 15 ) ) );
		$this->assertSame( 2, $attempts );
		$this->assertArrayNotHasKey( $lock, $GLOBALS['mcpwp_test_wpdb_options'] );
	}
}
