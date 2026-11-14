<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';

final class CustomCodeInvalidEntryRepairTest extends TestCase {
	private $error_log;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['mcpwp_test_user_caps'] = array( 1 => array( 'manage_options' => true ) );
		$GLOBALS['wpdb']->last_error = '';
		$this->error_log = ini_get( 'error_log' );
		ini_set( 'error_log', '/dev/null' );
		Mcpwp_Custom_Code_Conditions::register_hooks();
		$GLOBALS['_mcpwp_test_posts'][7] = new WP_Post( array( 'ID' => 7, 'post_type' => 'elementor_snippet', 'post_status' => 'publish' ) );
		update_post_meta( 7, '_elementor_conditions', array( 'include/general' ) );
		update_post_meta( 7, '_elementor_location', 'elementor_head' );
		$GLOBALS['mcpwp_test_wpdb_options'][Mcpwp_Custom_Code_Cache_Entry::row_name( 7 )] = 'broken row';
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->error_log );
	}

	public function test_invalid_entry_is_reported_without_mutation_then_repaired_on_admin_maintenance(): void {
		$before = $GLOBALS['mcpwp_test_options'];
		$cache = Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array() );
		$this->assertArrayNotHasKey( 7, $cache['elementor_head'] ?? array() );
		$health = Mcpwp_Custom_Code_Conditions::site_health_test();
		$this->assertSame( 'recommended', $health['status'] );
		$this->assertStringContainsString( 'repair failed', $health['description'] );
		$this->assertStringContainsString( '7', $health['description'] );
		$after = $GLOBALS['mcpwp_test_options'];
		unset( $after[ Mcpwp_Custom_Code_Cache_View::OPTION ] );
		$this->assertSame( $before, $after, 'A cold read may store the derived view, but must not persist repair/report receipts.' );
		$this->assertSame( 'broken row', $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::row_name( 7 ) ], 'Reading must not repair the source entry.' );
		Mcpwp_Custom_Code_Conditions::refresh_admin_report();
		$this->assertSame( array( 'include/general' ), Mcpwp_Custom_Code_Cache_Entry::entry( 7 )['conditions'] ?? null );
		$this->assertSame( array(), get_option( Mcpwp_Activator::CUSTOM_CODE_CACHE_REPAIR_FAILED_OPTION, array() ) );
		$this->assertSame( array(), Mcpwp_Custom_Code_Conditions::report_ids() );
	}
}
