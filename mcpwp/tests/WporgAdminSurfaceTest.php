<?php
/**
 * WordPress.org minimal administration and disclosure contract.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'MCPWP_PLUGIN_DIR' ) ) {
	define( 'MCPWP_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-settings.php';
require_once dirname( __DIR__ ) . '/includes/admin/traits/trait-mcpwp-admin-setup.php';

final class Mcpwp_Admin_Setup_Test_Harness {
	use Mcpwp_Api_Auth;
	use Mcpwp_Admin_Setup_Trait;
}

final class WporgAdminSurfaceTest extends TestCase {

	/** @var string */
	private static $plugin_dir;

	/** @var string */
	private static $repository_dir;

	public static function setUpBeforeClass(): void {
		self::$plugin_dir     = dirname( __DIR__ );
		self::$repository_dir = dirname( self::$plugin_dir );
	}

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']       = array();
		$GLOBALS['mcpwp_test_current_user']  = 7;
		$GLOBALS['mcpwp_test_users']         = array( (object) array( 'ID' => 7, 'roles' => array( 'subscriber' ) ) );
		$GLOBALS['mcpwp_test_user_caps']     = array( 7 => array( 'mcpwp_manage_settings' => true, 'manage_options' => false ) );
		$GLOBALS['mcpwp_test_valid_nonces']  = array(
			'mcpwp_create_key'  => 'valid-create',
			'mcpwp_revoke_key'  => 'valid-revoke',
			'mcpwp_save_plugin_settings' => 'valid-plugin-settings',
			'mcpwp_save_rate_settings'   => 'valid-rate-settings',
		);
	}

	public function test_exact_four_admin_submenus_use_the_custom_capability(): void {
		$source = $this->read( 'includes/admin/class-mcpwp-admin.php' );
		$tools  = $this->read( 'includes/admin/class-mcpwp-tools-admin.php' );
		preg_match_all( '/add_submenu_page\s*\((.*?)\);/s', $source, $matches );

		$this->assertCount( 4, $matches[1], 'The directory edition must register exactly four submenus.' );
		foreach ( $matches[1] as $registration ) {
			$this->assertStringContainsString( "'mcpwp_manage_settings'", $registration );
		}

		$this->assertStringContainsString( "const PAGE_SLUG = 'mcpwp';", $source );
		$this->assertStringContainsString( "const PAGE_SLUG = 'mcpwp-tools';", $tools );
		$this->assertStringContainsString( "const SETTINGS_PAGE_SLUG = 'mcpwp-settings';", $source );
		$this->assertStringContainsString( "const ACTIVITY_LOG_PAGE_SLUG = 'mcpwp-activity-log';", $source );
	}

	public function test_every_admin_renderer_uses_only_the_custom_capability(): void {
		$source = $this->admin_php_source();
		$this->assertStringNotContainsString( "current_user_can( 'activate_plugins'", $source );
		$this->assertStringNotContainsString( "current_user_can( 'manage_options'", $source );
		$this->assertGreaterThanOrEqual( 4, substr_count( $source, "current_user_can( 'mcpwp_manage_settings'" ) );
	}

	public function test_dead_ajax_and_tool_category_mutation_surface_is_absent(): void {
		$source = $this->admin_php_source() . "\n" . $this->read( 'includes/class-mcpwp-loader.php' );
		foreach ( array(
			'mcpwp_test_connection',
			'ajax_test_connection',
			'mcpwp_toggle_tool_category',
			'ajax_toggle_category',
			'mcpwp_tools_nonce',
			'mcpwp_disabled_tool_categories',
		) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $source, 'Dead admin mutation remains: ' . $forbidden );
		}
	}

	public function test_setup_exposes_nonempty_scopes_categories_and_revocation(): void {
		$source = $this->read( 'includes/admin/traits/trait-mcpwp-admin-setup.php' ) . "\n" . $this->read( 'admin/partials/mcpwp-setup-display.php' );
		$this->assertStringNotContainsString( "array( 'read', 'write', 'admin' )", $source, 'Key creation must not silently grant all scopes.' );
		foreach ( array( 'scope', 'categor', 'revoke', 'user_id' ) as $required ) {
			$this->assertStringContainsStringIgnoringCase( $required, $source, 'Setup must expose ' . $required . '.' );
		}
		$this->assertMatchesRegularExpression( '/check_admin_referer\s*\([^)]*revoke|wp_verify_nonce\s*\([^)]*revoke/i', $source );
	}

	public function test_create_key_binds_current_user_and_selected_scope_and_categories(): void {
		$admin = new Mcpwp_Admin_Setup_Test_Harness();
		$this->assertTrue( method_exists( $admin, 'handle_setup_action' ), 'Setup needs a directly testable fail-closed mutation handler.' );
		$result = $admin->handle_setup_action( array(
			'mcpwp_create_key' => '1',
			'_wpnonce'         => 'valid-create',
			'label'            => 'Editorial key',
			'scopes'           => array( 'read', 'write' ),
			'tool_categories'  => array( 'content', 'media' ),
		) );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 7, $result['created']['user_id'] );
		$this->assertSame( array( 'read', 'write' ), $result['created']['scopes'] );
		$this->assertSame( array( 'content', 'media' ), $result['created']['tool_categories'] );
		$this->assertCount( 1, get_option( 'mcpwp_api_keys', array() ) );
	}

	public function test_create_key_rejects_invalid_nonce_unauthorized_user_and_empty_authority(): void {
		$admin = new Mcpwp_Admin_Setup_Test_Harness();
		$this->assertTrue( method_exists( $admin, 'handle_setup_action' ) );
		$valid = array(
			'mcpwp_create_key' => '1',
			'_wpnonce'         => 'wrong',
			'label'            => 'No authority',
			'scopes'           => array( 'read' ),
			'tool_categories'  => array( 'content' ),
		);
		$this->assertSame( 'invalid_admin_nonce', $admin->handle_setup_action( $valid )->get_error_code() );
		$this->assertSame( array(), get_option( 'mcpwp_api_keys', array() ) );

		$valid['_wpnonce'] = 'valid-create';
		$GLOBALS['mcpwp_test_user_caps'][7]['mcpwp_manage_settings'] = false;
		$this->assertSame( 'forbidden', $admin->handle_setup_action( $valid )->get_error_code() );
		$this->assertSame( array(), get_option( 'mcpwp_api_keys', array() ) );

		$GLOBALS['mcpwp_test_user_caps'][7]['mcpwp_manage_settings'] = true;
		$valid['scopes'] = array();
		$this->assertSame( 'invalid_api_key_record', $admin->handle_setup_action( $valid )->get_error_code() );
		$valid['scopes'] = array( 'read' );
		$valid['tool_categories'] = array();
		$this->assertSame( 'invalid_api_key_record', $admin->handle_setup_action( $valid )->get_error_code() );
		$this->assertSame( array(), get_option( 'mcpwp_api_keys', array() ) );
	}

	public function test_create_key_requires_a_bounded_nonempty_label(): void {
		$admin = new Mcpwp_Admin_Setup_Test_Harness();
		$post = array(
			'mcpwp_create_key' => '1', '_wpnonce' => 'valid-create',
			'scopes' => array( 'read' ), 'tool_categories' => array( 'content' ),
			'label' => ' <script></script> ',
		);
		$result = $admin->handle_setup_action( $post );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_api_key_label', $result->get_error_code() );
		$post['label'] = str_repeat( 'a', 101 );
		$result = $admin->handle_setup_action( $post );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_api_key_label', $result->get_error_code() );
		$this->assertSame( array(), get_option( 'mcpwp_api_keys', array() ) );
	}

	public function test_revoke_key_requires_its_own_nonce_and_capability(): void {
		$admin = new Mcpwp_Admin_Setup_Test_Harness();
		$this->assertTrue( method_exists( $admin, 'handle_setup_action' ) );
		$created = $admin->handle_setup_action( array(
			'mcpwp_create_key' => '1', '_wpnonce' => 'valid-create', 'label' => 'Temporary',
			'scopes' => array( 'read' ), 'tool_categories' => array( 'site' ),
		) );
		$this->assertFalse( is_wp_error( $created ) );
		$key_id = $created['created']['id'];

		$bad = $admin->handle_setup_action( array( 'mcpwp_revoke_key' => '1', '_wpnonce' => 'valid-create', 'key_id' => $key_id ) );
		$this->assertSame( 'invalid_admin_nonce', $bad->get_error_code() );
		$this->assertNull( get_option( 'mcpwp_api_keys' )[0]['revoked_at'] );

		$GLOBALS['mcpwp_test_user_caps'][7]['mcpwp_manage_settings'] = false;
		$forbidden = $admin->handle_setup_action( array( 'mcpwp_revoke_key' => '1', '_wpnonce' => 'valid-revoke', 'key_id' => $key_id ) );
		$this->assertSame( 'forbidden', $forbidden->get_error_code() );
		$GLOBALS['mcpwp_test_user_caps'][7]['mcpwp_manage_settings'] = true;

		$revoked = $admin->handle_setup_action( array( 'mcpwp_revoke_key' => '1', '_wpnonce' => 'valid-revoke', 'key_id' => $key_id ) );
		$this->assertSame( $key_id, $revoked['revoked'] );
		$this->assertNotEmpty( get_option( 'mcpwp_api_keys' )[0]['revoked_at'] );
	}

	public function test_settings_partial_save_is_lossless_and_checkbox_absence_is_false(): void {
		$settings = new Mcpwp_Settings();
		$this->assertTrue( method_exists( $settings, 'handle_admin_save' ), 'Settings need a custom-capability save handler.' );
		update_option( 'mcpwp_settings', array( 'enable_logging' => true, 'log_retention_days' => 30 ) );
		$rates = array( 'enabled' => true, 'requests_per_minute' => 60, 'requests_per_hour' => 1000, 'burst_limit' => 10, 'whitelist' => array( 'key:keep' ) );
		update_option( 'mcpwp_rate_limit_settings', $rates );

		$result = $settings->handle_admin_save( array(
			'mcpwp_save_settings' => 'plugin',
			'_wpnonce' => 'valid-plugin-settings',
			'mcpwp_settings' => array( 'log_retention_days' => '45' ),
		) );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( array( 'enable_logging' => false, 'log_retention_days' => 45 ), get_option( 'mcpwp_settings' ) );
		$this->assertSame( $rates, get_option( 'mcpwp_rate_limit_settings' ), 'Saving logging must not erase rate settings.' );
	}

	public function test_rate_settings_round_trip_bounds_relationship_and_xss(): void {
		$settings = new Mcpwp_Settings();
		$this->assertTrue( method_exists( $settings, 'handle_admin_save' ) );
		$input = array(
			'mcpwp_save_settings' => 'rate',
			'_wpnonce' => 'valid-rate-settings',
			'mcpwp_rate_limit_settings' => array(
				'enabled' => '1', 'requests_per_minute' => '20', 'requests_per_hour' => '400', 'burst_limit' => '5',
				'whitelist' => " key:one\n<script>alert(1)</script>key:two ",
			),
		);
		$result = $settings->handle_admin_save( $input );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame(
			array( 'enabled' => true, 'requests_per_minute' => 20, 'requests_per_hour' => 400, 'burst_limit' => 5, 'whitelist' => array( 'key:one', 'alert(1)key:two' ) ),
			get_option( 'mcpwp_rate_limit_settings' )
		);

		$before = get_option( 'mcpwp_rate_limit_settings' );
		$input['mcpwp_rate_limit_settings']['burst_limit'] = '21';
		$this->assertSame( 'invalid_rate_settings', $settings->handle_admin_save( $input )->get_error_code() );
		$this->assertSame( $before, get_option( 'mcpwp_rate_limit_settings' ) );
	}

	public function test_rate_whitelist_rejects_empty_long_or_excess_entries(): void {
		$settings = new Mcpwp_Settings();
		$base = array(
			'mcpwp_save_settings' => 'rate', '_wpnonce' => 'valid-rate-settings',
			'mcpwp_rate_limit_settings' => array(
				'enabled' => '1', 'requests_per_minute' => '20', 'requests_per_hour' => '400', 'burst_limit' => '5',
			),
		);
		foreach ( array(
			array( 'key:valid', '<script></script>' ),
			array( str_repeat( 'a', 101 ) ),
			array_map( function ( $index ) { return 'key:' . $index; }, range( 1, 101 ) ),
		) as $invalid ) {
			$base['mcpwp_rate_limit_settings']['whitelist'] = $invalid;
			$result = $settings->handle_admin_save( $base );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'invalid_rate_whitelist', $result->get_error_code() );
			$this->assertSame( array(), get_option( 'mcpwp_rate_limit_settings', array() ) );
		}
	}

	public function test_custom_handlers_are_the_only_admin_settings_mutation_path(): void {
		$settings = $this->read( 'includes/admin/class-mcpwp-settings.php' );
		$loader   = $this->read( 'includes/class-mcpwp-loader.php' );
		$this->assertStringNotContainsString( 'register_setting(', $settings );
		$this->assertStringNotContainsString( "'admin_init', \$settings, 'register_settings'", $loader );
		$this->assertStringNotContainsString( 'action="options.php"', $this->read( 'admin/partials/mcpwp-settings-display.php' ) );
	}

	public function test_settings_save_rejects_invalid_nonce_and_custom_capability_failure(): void {
		$settings = new Mcpwp_Settings();
		$this->assertTrue( method_exists( $settings, 'handle_admin_save' ) );
		$before = array( 'enable_logging' => true, 'log_retention_days' => 30 );
		update_option( 'mcpwp_settings', $before );
		$post = array( 'mcpwp_save_settings' => 'plugin', '_wpnonce' => 'wrong', 'mcpwp_settings' => array( 'log_retention_days' => 99 ) );
		$this->assertSame( 'invalid_admin_nonce', $settings->handle_admin_save( $post )->get_error_code() );
		$this->assertSame( $before, get_option( 'mcpwp_settings' ) );

		$post['_wpnonce'] = 'valid-plugin-settings';
		$GLOBALS['mcpwp_test_user_caps'][7]['mcpwp_manage_settings'] = false;
		$this->assertSame( 'forbidden', $settings->handle_admin_save( $post )->get_error_code() );
		$this->assertSame( $before, get_option( 'mcpwp_settings' ) );
	}

	public function test_settings_form_round_trips_every_retained_field(): void {
		$partial = $this->read( 'admin/partials/mcpwp-settings-display.php' );
		foreach ( array( 'enable_logging', 'log_retention_days', 'enabled', 'requests_per_minute', 'requests_per_hour', 'burst_limit', 'whitelist' ) as $field ) {
			$this->assertStringContainsString( '[' . $field . ']', $partial, 'Settings form omits ' . $field . '.' );
		}
	}

	public function test_activity_queries_only_the_retained_local_metadata_schema(): void {
		$source = $this->read( 'includes/admin/class-mcpwp-activity-log.php' ) . "\n" . $this->read( 'includes/admin/traits/trait-mcpwp-admin-activity.php' );
		foreach ( array( 'request_data', 'response_data', 'ip_address', 'user_agent', 'endpoint', 'method' ) as $column ) {
			$this->assertStringNotContainsString( $column, $source, 'Activity admin references removed column ' . $column . '.' );
		}
	}

	public function test_admin_presentation_is_local_enqueued_and_has_no_inline_behavior(): void {
		$source = $this->admin_php_source();
		$this->assertStringContainsString( "wp_enqueue_style( 'mcpwp-admin'", $source );
		$this->assertStringContainsString( "wp_enqueue_script( 'mcpwp-admin'", $source );
		$this->assertDoesNotMatchRegularExpression( '/<(?:script|style)\b|\son[a-z]+\s*=/i', $source );
		$this->assertDoesNotMatchRegularExpression( '#(?:src|href)\s*=\s*[\'\"]https?://#i', $source );
	}

	public function test_every_runtime_translation_uses_the_public_domain_and_exists_in_pot(): void {
		$runtime = $this->runtime_php_source();
		preg_match_all( '/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(\s*([\'\"])(.*?)\1\s*,\s*([\'\"])(.*?)\3\s*\)/s', $runtime, $matches, PREG_SET_ORDER );
		$this->assertNotEmpty( $matches, 'No translatable runtime strings were found.' );

		$pot = $this->read( 'languages/mumega-mcp.pot' );
		foreach ( $matches as $match ) {
			$this->assertSame( 'mumega-mcp', $match[4], 'Wrong text domain for: ' . $match[2] );
			$this->assertStringContainsString( 'msgid "' . addcslashes( $match[2], "\\\"\n\r\t" ) . '"', $pot, 'POT omits: ' . $match[2] );
		}
	}

	public function test_plugin_header_omits_overbroad_plugin_uri(): void {
		$header = $this->read( 'mcpwp.php' );
		$this->assertDoesNotMatchRegularExpression( '/^\s*\*\s*Plugin URI:\s*\S+/mi', $header );
	}

	public function test_readme_discloses_authority_data_flow_and_negative_capabilities(): void {
		$readme = strtolower( $this->read( 'readme.txt' ) );
		$this->assertMatchesRegularExpression( '/^tested up to:\s*7\.0\s*$/mi', $readme, 'The WordPress 7.0 claim is backed by a 7.0.1 smoke matrix.' );
		foreach ( array(
			'no plugin-initiated outbound',
			'no telemetry',
			'mumega',
			'mcp client',
			'provider',
			'scoped',
			'wordpress user',
			'retention',
			'draft by default',
			'publish',
			'private',
			'delete',
			'php',
			'javascript',
			'css',
			'plugin',
			'theme',
			'install',
			'update',
			'self-update',
		) as $required ) {
			$this->assertStringContainsString( $required, $readme, 'Readme omits required disclosure: ' . $required );
		}
		$this->assertStringNotContainsString( 'does not send site data to a third-party service', $readme );
	}

	public function test_anonymous_readme_link_checker_is_present_and_executable(): void {
		$checker = self::$plugin_dir . '/scripts/check-readme-links.sh';
		$this->assertFileExists( $checker );
		$this->assertTrue( is_executable( $checker ), 'Readme link checker must be executable.' );
	}

	public function test_submission_notes_describe_only_the_retained_directory_artifact(): void {
		$submission = strtolower( (string) file_get_contents( self::$repository_dir . '/SUBMISSION.md' ) );
		foreach ( array( '17 tool', '19 route', 'no outbound', 'no telemetry', 'no self-update', 'explicit', 'existing wordpress user', 'task 5' ) as $required ) {
			$this->assertStringContainsString( $required, $submission, 'Submission notes omit: ' . $required );
		}
		foreach ( array( 'posthog', 'feedback relay', 'provider apis', 'mcpwp_wporg_build', 'oauth controller', 'freemius', 'service account user' ) as $stale ) {
			$this->assertStringNotContainsString( $stale, $submission, 'Submission notes retain stale claim: ' . $stale );
		}
	}

	private function read( $relative ): string {
		$contents = file_get_contents( self::$plugin_dir . '/' . $relative );
		$this->assertNotFalse( $contents, 'Could not read ' . $relative );
		return (string) $contents;
	}

	private function admin_php_source(): string {
		$source = '';
		foreach ( array( 'includes/admin', 'admin/partials' ) as $directory ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::$plugin_dir . '/' . $directory, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
					$source .= "\n" . (string) file_get_contents( $file->getPathname() );
				}
			}
		}
		return $source;
	}

	private function runtime_php_source(): string {
		$source = $this->read( 'mcpwp.php' );
		foreach ( array( 'includes', 'admin/partials' ) as $directory ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::$plugin_dir . '/' . $directory, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
					$source .= "\n" . (string) file_get_contents( $file->getPathname() );
				}
			}
		}
		return $source;
	}
}
