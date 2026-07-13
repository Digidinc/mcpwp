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
			array( 'mcpwp_settings', 'mcpwp_rate_limit_settings', 'mcpwp_db_version' ),
			array_keys( $GLOBALS['mcpwp_test_options'] )
		);
		$this->assertCount( 1, $GLOBALS['mcpwp_test_dbdelta'] );
		$this->assertStringContainsString( 'mcpwp_activity_log', $GLOBALS['mcpwp_test_dbdelta'][0] );
	}

	public function test_deactivation_only_clears_scheduled_hooks(): void {
		Mcpwp_Deactivator::deactivate();

		$this->assertContains( 'mcpwp_cleanup_logs', $GLOBALS['mcpwp_test_cleared_hooks'] );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_options'] );
	}
}
