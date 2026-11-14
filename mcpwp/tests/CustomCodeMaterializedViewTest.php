<?php

use PHPUnit\Framework\TestCase;

final class CustomCodeMaterializedViewTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
		Mcpwp_Custom_Code_Conditions::register_hooks();
		$GLOBALS['_mcpwp_test_posts'][15] = new WP_Post( array( 'ID'=>15, 'post_type'=>'elementor_snippet', 'post_status'=>'publish' ) );
		update_post_meta( 15, '_elementor_conditions', array( 'include/general' ) );
		update_post_meta( 15, '_elementor_location', 'elementor_head' );
		update_option( 'elementor_pro_theme_builder_conditions', array() );
		Mcpwp_Custom_Code_Cache_Entry::sync( 15, 'elementor_head', array( 'include/general' ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_wpdb_intercept'], $GLOBALS['mcpwp_test_wpdb_delete_fails'] );
		unset( $GLOBALS['mcpwp_test_update_option_fail'][Mcpwp_Custom_Code_Cache_View::GENERATION_OPTION] );
	}

	public function test_write_flush_materializes_but_a_pure_read_does_not_write(): void {
		$this->assertFalse( get_option( Mcpwp_Custom_Code_Cache_View::OPTION, false ) );
		Mcpwp_Custom_Code_Conditions::refresh_pending_view();
		$this->assertIsArray( get_option( Mcpwp_Custom_Code_Cache_View::OPTION ) );
		$before = $GLOBALS['mcpwp_test_options'];
		$cache = Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array() );
		$this->assertSame( array('include/general'), $cache['elementor_head'][15] );
		$this->assertSame( $before, $GLOBALS['mcpwp_test_options'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_direct_sql_exclusion_cannot_print_from_the_materialized_view(): void {
		require_once __DIR__ . '/fixtures/custom-code-print-document.php';
		Mcpwp_Custom_Code_Conditions::refresh_pending_view();
		$this->assertTrue( Mcpwp_Custom_Code_Cache_View::read( array() )['hit'] );
		// Direct database changes bypass all metadata hooks and object caches.
		$GLOBALS['_mcpwp_test_meta'][15]['_elementor_conditions'] = array( 'exclude/general' );
		wp_cache_delete( 15, 'post_meta' );
		Mcpwp_Custom_Code_Conditions::invalidate_cache_view( 15 );
		Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array() );
		$this->assertSame( array(''), \ElementorPro\Modules\CustomCode\Document::print_content( 15 ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_failed_view_invalidation_returns_a_partial_create_receipt(): void {
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
		Mcpwp_Custom_Code_Conditions::refresh_pending_view();
		$GLOBALS['mcpwp_test_update_option_fail'][Mcpwp_Custom_Code_Cache_View::GENERATION_OPTION] = true;
		$GLOBALS['mcpwp_test_wpdb_delete_fails'] = static function ( $table, $where ) {
			return ( $where['option_name'] ?? '' ) === Mcpwp_Custom_Code_Cache_View::OPTION;
		};
		$result = ( new Mcpwp_REST_Elementor_Pro( new stdClass() ) )->create_custom_code( new WP_REST_Request( 'POST', '/', array(
			'title' => 'View invalidation receipt', 'status' => 'publish', 'code' => '<meta name="view-receipt" content="ok">',
		) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $result->get_error_data()['partial_write'] );
		$this->assertGreaterThan( 0, $result->get_error_data()['resource_id'] );
		$this->assertSame( 'publish', get_post_status( $result->get_error_data()['resource_id'] ) );
	}

	public function test_view_options_are_closed_on_the_generic_option_api(): void {
		foreach ( array( Mcpwp_Custom_Code_Cache_View::OPTION, Mcpwp_Custom_Code_Cache_View::GENERATION_OPTION ) as $key ) {
			foreach ( array('read','write') as $mode ) {
				$this->assertFalse( Mcpwp_Option_Access::decide( $key, $mode )['allowed'] );
			}
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_removed_live_location_cannot_print_from_a_cached_bucket(): void {
		require_once __DIR__ . '/fixtures/custom-code-print-document.php';
		Mcpwp_Custom_Code_Conditions::refresh_pending_view();
		unset( $GLOBALS['_mcpwp_test_meta'][15]['_elementor_location'] );
		wp_cache_delete( 15, 'post_meta' );
		Mcpwp_Custom_Code_Conditions::invalidate_cache_view( 15 );
		Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array() );
		$this->assertSame( array(''), \ElementorPro\Modules\CustomCode\Document::print_content( 15 ) );
	}

	public function test_regular_source_reads_are_not_masked_by_a_cached_view(): void {
		Mcpwp_Custom_Code_Conditions::refresh_pending_view();
		$GLOBALS['_mcpwp_test_meta'][15]['_elementor_conditions'] = array( 'exclude/general' );
		wp_cache_delete( 15, 'post_meta' );
		Mcpwp_Custom_Code_Conditions::invalidate_cache_view( 15 );
		Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array() );
		$this->assertNull( Mcpwp_Custom_Code_Conditions::filter_changed_view_source( null, 15, '_elementor_code', true, 'post' ) );
	}

	public function test_direct_sql_corrupt_entry_is_reported_even_with_a_materialized_view(): void {
		Mcpwp_Custom_Code_Conditions::refresh_pending_view();
		$GLOBALS['mcpwp_test_wpdb_options'][Mcpwp_Custom_Code_Cache_Entry::row_name(15)] = 'broken';
		Mcpwp_Custom_Code_Conditions::invalidate_cache_view();
		Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array() );
		$health = Mcpwp_Custom_Code_Conditions::site_health_test();
		$this->assertStringContainsString( 'repair failed', $health['description'] );
	}
}
