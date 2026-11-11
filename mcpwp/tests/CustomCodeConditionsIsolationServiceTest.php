<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-custom-code-conditions.php';

final class CustomCodeConditionsIsolationServiceTest extends TestCase {
	private $error_log;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['mcpwp_test_user_caps'] = array( 1 => array( 'manage_options' => true ) );
		$GLOBALS['wpdb']->last_error = '';
		foreach ( array( 'request_skipped' => array(), 'cache_bypass' => false, 'raw_read_depth' => 0 ) as $property_name => $value ) {
			$property = new ReflectionProperty( Mcpwp_Custom_Code_Conditions::class, $property_name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
		$this->error_log = ini_get( 'error_log' );
		ini_set( 'error_log', '/dev/null' );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->error_log );
	}

	private function snippet( $id, $conditions ) {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post( array( 'ID' => $id, 'post_type' => 'elementor_snippet', 'post_status' => 'publish' ) );
		update_post_meta( $id, '_elementor_conditions', $conditions );
	}

	public function test_converter_is_all_or_nothing_for_maps_and_strings(): void {
		$this->assertSame( array( 'include/general', 'exclude/singular/page/5' ), Mcpwp_Custom_Code_Conditions::convert( array( 'include/general', array( 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '5' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, Mcpwp_Custom_Code_Conditions::convert( array( 'include/general', array( 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '5 ' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, Mcpwp_Custom_Code_Conditions::convert( array( 'include/singular/page/5/extra' ) ) );
	}

	public function test_cache_filter_removes_one_bad_snippet_without_shortening_or_touching_others(): void {
		$this->snippet( 15, array( 'include/general', array( 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '5 ' ) ) );
		$this->snippet( 16, array( 'include/general' ) );
		$cache = Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array( 'elementor_head' => array( 15 => array( 'include/general' ), 16 => array( 'include/general' ), 99 => array( 'include/general' ) ) ) );
		$this->assertArrayNotHasKey( 15, $cache['elementor_head'] );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][16] );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][99] );
	}

	public function test_regenerate_query_excludes_bad_and_unpersisted_legacy_entries(): void {
		$this->snippet( 15, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '5' ) ) );
		$this->snippet( 16, array( 'include/general' ) );
		$args = Mcpwp_Custom_Code_Conditions::filter_regenerate_query( array( 'post__not_in' => array( 99 ) ) );
		$this->assertSame( array( 99 ), $args['post__not_in'], 'A cold report preserves callers\' exclusions and avoids a full scan; Pro-only metadata isolation handles late records.' );
		$this->assertNotContains( 16, $args['post__not_in'] );
		$this->assertContains( 99, $args['post__not_in'] );
	}

	public function test_raw_reader_and_quarantine_report_without_mutating_the_shared_cache(): void {
		$this->snippet( 15, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '5' ) ) );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'elementor_head' => array( 15 => array( 'include/general' ), 99 => array( 'include/general' ) ) ) );
		$this->assertSame( array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '5' ) ), Mcpwp_Custom_Code_Conditions::raw_conditions( 15 ) );
		Mcpwp_Custom_Code_Conditions::quarantine( 15, Mcpwp_Custom_Code_Conditions::raw_conditions( 15 ) );
		$this->assertArrayHasKey( 15, get_option( 'elementor_pro_theme_builder_conditions' )['elementor_head'] );
		$this->assertSame( array( 'include/general' ), get_option( 'elementor_pro_theme_builder_conditions' )['elementor_head'][99] );
		$this->assertSame( array( 15 ), Mcpwp_Custom_Code_Conditions::annotate_response( array() )['skipped_custom_code_snippet_ids'] );
	}

	public function test_quarantine_logs_one_exact_id_per_fingerprint_and_does_not_rewrite_registry(): void {
		$this->snippet( 15, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '5' ) ) );
		$log = tempnam( sys_get_temp_dir(), 'mcpwp-conditions-' );
		ini_set( 'error_log', $log );
		try {
			$raw = Mcpwp_Custom_Code_Conditions::raw_conditions( 15 );
			Mcpwp_Custom_Code_Conditions::quarantine( 15, $raw );
			$first = get_option( Mcpwp_Custom_Code_Conditions::QUARANTINE_OPTION );
			Mcpwp_Custom_Code_Conditions::quarantine( 15, $raw );
			$this->assertSame( $first, get_option( Mcpwp_Custom_Code_Conditions::QUARANTINE_OPTION ) );
			$this->assertSame( 1, substr_count( file_get_contents( $log ), 'snippet 15' ) );
		} finally {
			unlink( $log );
		}
	}

	public function test_admin_notice_and_site_health_name_only_current_unsafe_ids(): void {
		$this->snippet( 15, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '5' ) ) );
		Mcpwp_Custom_Code_Conditions::quarantine( 15, Mcpwp_Custom_Code_Conditions::raw_conditions( 15 ) );
		ob_start();
		Mcpwp_Custom_Code_Conditions::render_admin_notice();
		$notice = ob_get_clean();
		$this->assertStringContainsString( '15', $notice );
		$health = Mcpwp_Custom_Code_Conditions::site_health_test();
		$this->assertSame( 'recommended', $health['status'] );
		$this->assertStringContainsString( '15', $health['description'] );
		update_post_meta( 15, '_elementor_conditions', array( 'include/general' ) );
		Mcpwp_Custom_Code_Conditions::clear_quarantine( 15 );
		$this->assertSame( 'good', Mcpwp_Custom_Code_Conditions::site_health_test()['status'] );
	}

	public function test_query_guard_repairs_convertible_raw_meta_before_rebuild_and_preserves_raw_on_cache_read(): void {
		$this->snippet( 16, array( array( 'type' => 'include', 'name' => 'general' ) ) );
		$raw = Mcpwp_Custom_Code_Conditions::raw_conditions( 16 );
		$cache = Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array( 'elementor_head' => array( 16 => $raw ) ) );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][16] );
		$this->assertSame( $raw, Mcpwp_Custom_Code_Conditions::raw_conditions( 16 ) );
		$guarded = Mcpwp_Custom_Code_Conditions::filter_regenerate_query( array() );
		$this->assertArrayNotHasKey( 'post__not_in', $guarded );
		$this->assertSame( $raw, Mcpwp_Custom_Code_Conditions::raw_conditions( 16 ) );
	}

	public function test_admin_refresh_records_late_unsafe_ids_once_while_cache_reads_stay_write_free(): void {
		$this->snippet( 15, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '5' ) ) );
		Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array( 'elementor_head' => array( 15 => array( 'include/general' ) ) ) );
		$this->assertFalse( get_option( Mcpwp_Custom_Code_Conditions::QUARANTINE_OPTION, false ) );
		$log = tempnam( sys_get_temp_dir(), 'mcpwp-conditions-admin-' );
		ini_set( 'error_log', $log );
		try {
			Mcpwp_Custom_Code_Conditions::refresh_admin_report();
			$first = get_option( Mcpwp_Custom_Code_Conditions::QUARANTINE_OPTION );
			Mcpwp_Custom_Code_Conditions::refresh_admin_report();
			$this->assertSame( $first, get_option( Mcpwp_Custom_Code_Conditions::QUARANTINE_OPTION ) );
			$this->assertSame( 1, substr_count( file_get_contents( $log ), 'snippet 15' ) );
			ob_start();
			Mcpwp_Custom_Code_Conditions::render_admin_notice();
			$this->assertStringContainsString( '15', ob_get_clean() );
		} finally {
			unlink( $log );
		}
	}
}
