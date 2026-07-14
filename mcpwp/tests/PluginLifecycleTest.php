<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-deactivator.php';

final class PluginLifecycleTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']       = array();
		$GLOBALS['mcpwp_test_roles']         = array( 'administrator' => new Mcpwp_Test_Role() );
		$GLOBALS['mcpwp_test_dbdelta']       = array();
		$GLOBALS['mcpwp_test_cleared_hooks'] = array();
	}

	public function test_clean_activation_creates_only_retained_local_state(): void {
		Mcpwp_Activator::activate();

		$this->assertArrayHasKey( 'mcpwp_api_agent', $GLOBALS['mcpwp_test_roles'] );
		$this->assertTrue( $GLOBALS['mcpwp_test_roles']['administrator']->capabilities['mcpwp_manage_settings'] );
		$this->assertSame(
			array( 'mcpwp_legacy_migration_owner_id', 'mcpwp_settings', 'mcpwp_rate_limit_settings', 'mcpwp_db_version' ),
			array_keys( $GLOBALS['mcpwp_test_options'] )
		);
		$this->assertCount( 1, $GLOBALS['mcpwp_test_dbdelta'] );
		$schema = $GLOBALS['mcpwp_test_dbdelta'][0];
		$this->assertStringContainsString( 'mcpwp_activity_log', $schema );
		foreach ( array( 'action', 'status_code', 'duration_ms', 'api_key_id', 'created_at' ) as $column ) {
			$this->assertStringContainsString( $column, $schema );
		}
		foreach ( array( 'request_data', 'response_data', 'ip_address', 'user_agent', 'endpoint', 'method' ) as $column ) {
			$this->assertStringNotContainsString( $column, $schema );
		}
	}

	public function test_deactivation_only_clears_scheduled_hooks(): void {
		Mcpwp_Deactivator::deactivate();

		$this->assertContains( 'mcpwp_cleanup_logs', $GLOBALS['mcpwp_test_cleared_hooks'] );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_options'] );
	}

	public function test_network_deactivation_clears_hooks_for_every_site(): void {
		$GLOBALS['mcpwp_test_is_multisite'] = true;
		$GLOBALS['mcpwp_test_site_ids'] = array( 11, 22 );
		$GLOBALS['mcpwp_test_switched_blogs'] = array();
		$GLOBALS['mcpwp_test_restored_blogs'] = 0;

		Mcpwp_Deactivator::deactivate( true );

		$this->assertSame( array( 11, 22 ), $GLOBALS['mcpwp_test_switched_blogs'] );
		$this->assertSame( 2, $GLOBALS['mcpwp_test_restored_blogs'] );
		$this->assertSame( 12, count( $GLOBALS['mcpwp_test_cleared_hooks'] ) );
		$GLOBALS['mcpwp_test_is_multisite'] = false;
	}

	public function test_activity_admin_reads_only_the_minimal_schema(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/admin/class-mcpwp-activity-log.php' );
		foreach ( array( 'duration_ms', 'api_key_id', 'status_code', 'created_at' ) as $column ) {
			$this->assertStringContainsString( $column, $source );
		}
		foreach ( array( 'request_data', 'response_data', 'ip_address', 'user_agent', 'endpoint', 'method' ) as $column ) {
			$this->assertStringNotContainsString( $column, $source );
		}
	}
}
