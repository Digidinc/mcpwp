<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-deactivator.php';

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return rtrim( dirname( $file ), '/\\' ) . '/';
	}
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}
if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $callback ) {
		$GLOBALS['mcpwp_test_activation_hook'] = array( $file, $callback );
	}
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $callback ) {
		$GLOBALS['mcpwp_test_deactivation_hook'] = array( $file, $callback );
	}
}

// The bootstrap defines MCPWP_VERSION for unit isolation, so suppress only the
// expected duplicate-constant notice while loading the real lifecycle callback.
@require_once dirname( __DIR__ ) . '/mcpwp.php';

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
		$GLOBALS['mcpwp_test_removed_roles_by_blog'] = array();
		$GLOBALS['mcpwp_test_removed_caps_by_blog'] = array();
		$GLOBALS['mcpwp_test_scheduled_hooks'] = array();
		$GLOBALS['mcpwp_test_option_rows'] = array();
		$GLOBALS['mcpwp_test_blog_roles'] = null;
		$GLOBALS['mcpwp_test_blog_options'] = null;
		$GLOBALS['mcpwp_test_blog_option_rows'] = null;
		$GLOBALS['mcpwp_test_blog_scheduled_hooks'] = null;
		$GLOBALS['mcpwp_test_blog_stack'] = array();
		$GLOBALS['mcpwp_test_current_blog_id'] = 0;
		$GLOBALS['mcpwp_test_tables'] = array();
		$GLOBALS['mcpwp_test_table_rows'] = array();
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['mcpwp_test_get_sites_args'] = array();
		$GLOBALS['mcpwp_test_site_ids'] = array();
		$GLOBALS['mcpwp_test_switched_blogs'] = array();
		$GLOBALS['mcpwp_test_restored_blogs'] = 0;
		$GLOBALS['mcpwp_test_is_multisite'] = false;
		$GLOBALS['wpdb']->queries = array();
		$GLOBALS['wpdb']->prepared = array();
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

	public function test_activity_schema_places_each_index_on_its_own_dbdelta_line(): void {
		Mcpwp_Activator::activate();

		$schema      = $GLOBALS['mcpwp_test_dbdelta'][0];
		$lines       = preg_split( '/\R/', $schema );
		$index_lines = array_values(
			array_filter(
				array_map( 'trim', $lines ),
				static function ( $line ) {
					return 1 === preg_match( '/^(?:PRIMARY\s+KEY|KEY)\s+/i', $line );
				}
			)
		);

		$this->assertCount( 3, $index_lines, 'dbDelta parses schema indexes one physical line at a time.' );
		$this->assertMatchesRegularExpression( '/^PRIMARY\s+KEY\s+\(id\),?$/i', $index_lines[0] );
		$this->assertSame( 'KEY created_at (created_at),', $index_lines[1] );
		$this->assertSame( 'KEY action (action)', $index_lines[2] );
	}

	public function test_deactivation_preserves_all_state_except_six_approved_hooks(): void {
		$approved_hooks = array(
			'mcpwp_cleanup_logs',
			'mcpwp_check_alerts',
			'mcpwp_verify_screenshot',
			'mcpwp_compute_signals',
			'mcpwp_action_log_daily_prune',
			'mcpwp_webhook_retry',
		);
		$GLOBALS['mcpwp_test_options'] = array(
			'mcpwp_settings' => array( 'enable_logging' => true ),
			'mcpwp_api_keys' => array( array( 'id' => 'keep-key' ) ),
			'unrelated_option' => 'keep-option',
		);
		$GLOBALS['mcpwp_test_roles'] = array(
			'administrator' => new Mcpwp_Test_Role( array( 'manage_options' => true, 'mcpwp_manage_settings' => true ) ),
			'mcpwp_api_agent' => new Mcpwp_Test_Role( array( 'read' => true ) ),
		);
		$GLOBALS['mcpwp_test_scheduled_hooks'] = array_fill_keys( array_merge( $approved_hooks, array( 'unrelated_schedule' ) ), true );
		$GLOBALS['_mcpwp_test_posts'] = array( 41 => (object) array( 'ID' => 41, 'post_title' => 'Keep content' ) );
		$GLOBALS['mcpwp_test_tables'] = array( 'wp_mcpwp_activity_log' => true );
		$GLOBALS['mcpwp_test_table_rows'] = array( 'wp_mcpwp_activity_log' => array( array( 'action' => 'keep-row' ) ) );
		$options_before = $GLOBALS['mcpwp_test_options'];
		$roles_before = serialize( $GLOBALS['mcpwp_test_roles'] );
		$posts_before = $GLOBALS['_mcpwp_test_posts'];
		$tables_before = $GLOBALS['mcpwp_test_tables'];
		$rows_before = $GLOBALS['mcpwp_test_table_rows'];
		Mcpwp_Deactivator::deactivate();

		$this->assertSame( $approved_hooks, $GLOBALS['mcpwp_test_cleared_hooks'] );
		$this->assertSame( array( 'unrelated_schedule' => true ), $GLOBALS['mcpwp_test_scheduled_hooks'] );
		$this->assertSame( $options_before, $GLOBALS['mcpwp_test_options'] );
		$this->assertSame( $roles_before, serialize( $GLOBALS['mcpwp_test_roles'] ) );
		$this->assertSame( $posts_before, $GLOBALS['_mcpwp_test_posts'] );
		$this->assertSame( $tables_before, $GLOBALS['mcpwp_test_tables'] );
		$this->assertSame( $rows_before, $GLOBALS['mcpwp_test_table_rows'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
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

	public function test_network_activation_provisions_more_than_one_hundred_sites_without_default_limit(): void {
		$site_ids = range( 1, 150 );
		$GLOBALS['mcpwp_test_site_ids'] = $site_ids;
		$GLOBALS['mcpwp_test_blog_roles'] = array();
		$GLOBALS['mcpwp_test_blog_options'] = array();
		foreach ( $site_ids as $site_id ) {
			$GLOBALS['mcpwp_test_blog_roles'][ $site_id ] = array( 'administrator' => new Mcpwp_Test_Role() );
		}

		mcpwp_activate( true );

		$this->assertSame( array( 'fields' => 'ids', 'number' => 0 ), $GLOBALS['mcpwp_test_get_sites_args'][0] );
		$this->assertSame( $site_ids, $GLOBALS['mcpwp_test_switched_blogs'] );
		$this->assertSame( 150, $GLOBALS['mcpwp_test_restored_blogs'] );
		$this->assertCount( 150, $GLOBALS['mcpwp_test_dbdelta'] );
		foreach ( $site_ids as $site_id ) {
			$this->assertArrayHasKey( 'mcpwp_api_agent', $GLOBALS['mcpwp_test_blog_roles'][ $site_id ], 'site ' . $site_id );
			$this->assertTrue( $GLOBALS['mcpwp_test_blog_roles'][ $site_id ]['administrator']->capabilities['mcpwp_manage_settings'], 'site ' . $site_id );
			$this->assertArrayHasKey( 'mcpwp_settings', $GLOBALS['mcpwp_test_blog_options'][ $site_id ], 'site ' . $site_id );
			$this->assertArrayHasKey( 'mcpwp_rate_limit_settings', $GLOBALS['mcpwp_test_blog_options'][ $site_id ], 'site ' . $site_id );
			$this->assertArrayHasKey( 'mcpwp_db_version', $GLOBALS['mcpwp_test_blog_options'][ $site_id ], 'site ' . $site_id );
		}
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

	public function test_uninstall_removes_only_owned_rate_limit_transient_namespace_rows(): void {
		$hash = md5( 'key:example_minute' );
		$GLOBALS['mcpwp_test_option_rows'] = array(
			'_transient_mcpwp_rate_' . $hash => 'plugin-counter',
			'_transient_timeout_mcpwp_rate_' . $hash => 'plugin-timeout',
			'_transient_mcpwp_rate_not-a-md5' => 'owned-legacy-shape',
			'_transient_mcpwp_ratex_' . $hash => 'similar-prefix-unrelated',
			'_transient_unrelated' => 'unrelated',
		);

		$this->run_uninstall();

		$this->assertSame(
			array(
				'_transient_mcpwp_ratex_' . $hash => 'similar-prefix-unrelated',
				'_transient_unrelated' => 'unrelated',
			),
			$GLOBALS['mcpwp_test_option_rows']
		);
		$this->assertCount( 1, $GLOBALS['wpdb']->prepared );
		$this->assertSame(
			array( '\\_transient\\_mcpwp\\_rate\\_%', '\\_transient\\_timeout\\_mcpwp\\_rate\\_%' ),
			$GLOBALS['wpdb']->prepared[0]['args']
		);
		$this->assertStringContainsString( 'DELETE FROM wp_options', $GLOBALS['wpdb']->prepared[0]['query'] );
		$this->assertSame( 2, substr_count( $GLOBALS['wpdb']->prepared[0]['query'], 'LIKE %s' ) );
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
		$GLOBALS['mcpwp_test_blog_roles'] = array();
		$GLOBALS['mcpwp_test_blog_options'] = array();
		$GLOBALS['mcpwp_test_blog_option_rows'] = array();
		$GLOBALS['mcpwp_test_blog_scheduled_hooks'] = array();
		foreach ( $site_ids as $site_id ) {
			$GLOBALS['mcpwp_test_blog_roles'][ $site_id ] = array(
				'administrator' => new Mcpwp_Test_Role( array( 'manage_options' => true, 'mcpwp_manage_settings' => true ) ),
				'mcpwp_api_agent' => new Mcpwp_Test_Role( array( 'read' => true ) ),
				'editor' => new Mcpwp_Test_Role( array( 'edit_posts' => true ) ),
			);
			$GLOBALS['mcpwp_test_blog_options'][ $site_id ] = array( 'mcpwp_settings' => array(), 'unrelated_option' => 'site-' . $site_id );
			$site_hash = md5( 'site-' . $site_id );
			$GLOBALS['mcpwp_test_blog_option_rows'][ $site_id ] = array(
				'_transient_mcpwp_rate_' . $site_hash => 'plugin-counter',
				'_transient_timeout_mcpwp_rate_' . $site_hash => 'plugin-timeout',
				'_transient_unrelated' => 'site-' . $site_id,
			);
			$GLOBALS['mcpwp_test_blog_scheduled_hooks'][ $site_id ] = array( 'mcpwp_cleanup_logs' => true, 'unrelated_schedule' => true );
		}

		$this->run_uninstall();

		$this->assertSame( array( 'fields' => 'ids', 'number' => 0 ), $GLOBALS['mcpwp_test_get_sites_args'][0] );
		$this->assertSame( $site_ids, $GLOBALS['mcpwp_test_switched_blogs'] );
		$this->assertSame( 150, $GLOBALS['mcpwp_test_restored_blogs'] );
		$this->assertCount( 150, $GLOBALS['mcpwp_test_removed_roles'] );
		$this->assertCount( 150, $GLOBALS['mcpwp_test_removed_caps'] );
		foreach ( $site_ids as $site_id ) {
			$roles = $GLOBALS['mcpwp_test_blog_roles'][ $site_id ];
			$this->assertArrayNotHasKey( 'mcpwp_api_agent', $roles, 'site ' . $site_id );
			$this->assertArrayNotHasKey( 'mcpwp_manage_settings', $roles['administrator']->capabilities, 'site ' . $site_id );
			$this->assertTrue( $roles['administrator']->capabilities['manage_options'], 'site ' . $site_id );
			$this->assertTrue( $roles['editor']->capabilities['edit_posts'], 'site ' . $site_id );
			$this->assertSame( array( 'mcpwp_api_agent' ), $GLOBALS['mcpwp_test_removed_roles_by_blog'][ $site_id ], 'site ' . $site_id );
			$this->assertSame( array( 'mcpwp_manage_settings' ), $GLOBALS['mcpwp_test_removed_caps_by_blog'][ $site_id ], 'site ' . $site_id );
			$this->assertSame( array( 'unrelated_option' => 'site-' . $site_id ), $GLOBALS['mcpwp_test_blog_options'][ $site_id ], 'site ' . $site_id );
			$this->assertSame( array( '_transient_unrelated' => 'site-' . $site_id ), $GLOBALS['mcpwp_test_blog_option_rows'][ $site_id ], 'site ' . $site_id );
			$this->assertSame( array( 'unrelated_schedule' => true ), $GLOBALS['mcpwp_test_blog_scheduled_hooks'][ $site_id ], 'site ' . $site_id );
		}
	}

	public function test_lifecycle_loops_do_not_reuse_wordpress_global_blog_id(): void {
		foreach ( array( 'mcpwp.php', 'uninstall.php', 'includes/class-mcpwp-deactivator.php' ) as $relative ) {
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
