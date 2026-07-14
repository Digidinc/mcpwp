<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-deactivator.php';

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', true );
}

final class PluginLifecycleTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']       = array();
		$GLOBALS['mcpwp_test_roles']         = array( 'administrator' => new Mcpwp_Test_Role() );
		$GLOBALS['mcpwp_test_dbdelta']       = array();
		$GLOBALS['mcpwp_test_cleared_hooks'] = array();
		$GLOBALS['mcpwp_test_removed_roles'] = array();
		$GLOBALS['mcpwp_test_removed_caps'] = array();
		$GLOBALS['mcpwp_test_scheduled_hooks'] = array();
		$GLOBALS['mcpwp_test_get_sites_args'] = array();
		$GLOBALS['mcpwp_test_site_ids'] = array();
		$GLOBALS['mcpwp_test_switched_blogs'] = array();
		$GLOBALS['mcpwp_test_restored_blogs'] = 0;
		$GLOBALS['mcpwp_test_is_multisite'] = false;
		$GLOBALS['wpdb']->queries = array();
		$GLOBALS['mcpwp_test_users'] = array(
			(object) array( 'ID' => 2, 'roles' => array( 'administrator' ) ),
			(object) array( 'ID' => 7, 'roles' => array( 'subscriber' ) ),
		);
		$GLOBALS['mcpwp_test_user_caps'] = array(
			2 => array( 'manage_options' => true, 'mcpwp_manage_settings' => true ),
			7 => array( 'manage_options' => false, 'mcpwp_manage_settings' => true ),
		);
	}

	public function test_version_mismatch_activation_seeds_owner_only_for_an_administrator(): void {
		$GLOBALS['mcpwp_test_current_user'] = 7;
		Mcpwp_Activator::activate();
		$this->assertFalse( get_option( 'mcpwp_legacy_migration_owner_id', false ), 'A subscriber must never become migration owner.' );

		$GLOBALS['mcpwp_test_current_user'] = 2;
		Mcpwp_Activator::activate();
		$this->assertSame( 2, get_option( 'mcpwp_legacy_migration_owner_id' ) );
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
		$this->assertSame( array( 'fields' => 'ids', 'number' => 0 ), $GLOBALS['mcpwp_test_get_sites_args'][0] );
		$GLOBALS['mcpwp_test_is_multisite'] = false;
	}

	public function test_network_deactivation_visits_more_than_one_hundred_sites(): void {
		$site_ids = range( 1, 150 );
		$GLOBALS['mcpwp_test_is_multisite'] = true;
		$GLOBALS['mcpwp_test_site_ids'] = $site_ids;

		Mcpwp_Deactivator::deactivate( true );

		$this->assertSame( array( 'fields' => 'ids', 'number' => 0 ), $GLOBALS['mcpwp_test_get_sites_args'][0] );
		$this->assertSame( $site_ids, $GLOBALS['mcpwp_test_switched_blogs'] );
		$this->assertSame( 150, $GLOBALS['mcpwp_test_restored_blogs'] );
		$this->assertCount( 900, $GLOBALS['mcpwp_test_cleared_hooks'] );
	}

	public function test_uninstall_removes_owned_role_and_capability_but_preserves_unrelated_state(): void {
		$GLOBALS['mcpwp_test_roles'] = array(
			'administrator' => new Mcpwp_Test_Role( array( 'manage_options' => true, 'mcpwp_manage_settings' => true ) ),
			'mcpwp_api_agent' => new Mcpwp_Test_Role( array( 'read' => true ) ),
			'editor' => new Mcpwp_Test_Role( array( 'edit_posts' => true ) ),
		);
		$GLOBALS['mcpwp_test_options'] = array(
			'mcpwp_settings' => array( 'enable_logging' => true ),
			'unrelated_option' => 'keep-me',
		);
		$GLOBALS['mcpwp_test_scheduled_hooks'] = array(
			'mcpwp_cleanup_logs' => true,
			'unrelated_schedule' => true,
		);

		$this->run_uninstall();

		$this->assertArrayNotHasKey( 'mcpwp_api_agent', $GLOBALS['mcpwp_test_roles'] );
		$this->assertArrayNotHasKey( 'mcpwp_manage_settings', $GLOBALS['mcpwp_test_roles']['administrator']->capabilities );
		$this->assertTrue( $GLOBALS['mcpwp_test_roles']['administrator']->capabilities['manage_options'] );
		$this->assertTrue( $GLOBALS['mcpwp_test_roles']['editor']->capabilities['edit_posts'] );
		$this->assertSame( 'keep-me', $GLOBALS['mcpwp_test_options']['unrelated_option'] );
		$this->assertArrayNotHasKey( 'mcpwp_cleanup_logs', $GLOBALS['mcpwp_test_scheduled_hooks'] );
		$this->assertTrue( $GLOBALS['mcpwp_test_scheduled_hooks']['unrelated_schedule'] );
		$this->assertNotEmpty( $GLOBALS['wpdb']->queries );
		$this->assertStringNotContainsString( 'unrelated', implode( "\n", $GLOBALS['wpdb']->queries ) );
		$this->assertSame( array( 'mcpwp_api_agent' ), $GLOBALS['mcpwp_test_removed_roles'] );
		$this->assertSame( array( 'mcpwp_manage_settings' ), $GLOBALS['mcpwp_test_removed_caps'] );
	}

	public function test_uninstall_is_idempotent(): void {
		$GLOBALS['mcpwp_test_roles'] = array(
			'administrator' => new Mcpwp_Test_Role( array( 'manage_options' => true, 'mcpwp_manage_settings' => true ) ),
			'mcpwp_api_agent' => new Mcpwp_Test_Role( array( 'read' => true ) ),
		);
		$GLOBALS['mcpwp_test_options'] = array( 'unrelated_option' => 'keep-me' );

		$this->run_uninstall();
		$this->run_uninstall();

		$this->assertSame( 'keep-me', $GLOBALS['mcpwp_test_options']['unrelated_option'] );
		$this->assertTrue( $GLOBALS['mcpwp_test_roles']['administrator']->capabilities['manage_options'] );
		$this->assertArrayNotHasKey( 'mcpwp_manage_settings', $GLOBALS['mcpwp_test_roles']['administrator']->capabilities );
		$this->assertSame( array( 'mcpwp_api_agent', 'mcpwp_api_agent' ), $GLOBALS['mcpwp_test_removed_roles'] );
		$this->assertSame( array( 'mcpwp_manage_settings', 'mcpwp_manage_settings' ), $GLOBALS['mcpwp_test_removed_caps'] );
	}

	public function test_network_uninstall_visits_more_than_one_hundred_sites_without_default_limit(): void {
		$site_ids = range( 1, 150 );
		$GLOBALS['mcpwp_test_is_multisite'] = true;
		$GLOBALS['mcpwp_test_site_ids'] = $site_ids;
		$GLOBALS['mcpwp_test_roles']['mcpwp_api_agent'] = new Mcpwp_Test_Role( array( 'read' => true ) );

		$this->run_uninstall();

		$this->assertSame( array( 'fields' => 'ids', 'number' => 0 ), $GLOBALS['mcpwp_test_get_sites_args'][0] );
		$this->assertSame( $site_ids, $GLOBALS['mcpwp_test_switched_blogs'] );
		$this->assertSame( 150, $GLOBALS['mcpwp_test_restored_blogs'] );
		$this->assertCount( 150, $GLOBALS['mcpwp_test_removed_roles'] );
		$this->assertCount( 150, $GLOBALS['mcpwp_test_removed_caps'] );
	}

	public function test_lifecycle_loops_do_not_reuse_wordpress_global_blog_id(): void {
		foreach ( array( 'uninstall.php', 'includes/class-mcpwp-deactivator.php' ) as $relative ) {
			$source = (string) file_get_contents( dirname( __DIR__ ) . '/' . $relative );
			$this->assertStringNotContainsString( 'as $blog_id', $source, $relative );
			$this->assertStringContainsString( 'as $mcpwp_site_id', $source, $relative );
		}
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

	private function run_uninstall(): void {
		include dirname( __DIR__ ) . '/uninstall.php';
	}
}
