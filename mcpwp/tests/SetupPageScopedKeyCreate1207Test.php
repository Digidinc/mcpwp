<?php
/**
 * #1207 — Scoped key create on Setup: no plaintext persistence, early POST
 * handling, Claude Desktop tab on same response.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_settings_error' ) ) {
	function add_settings_error( $setting, $code, $message, $type = 'error' ) { // phpcs:ignore
		$GLOBALS['mcpwp_test_settings_errors'][] = compact( 'setting', 'code', 'message', 'type' );
	}
}

if ( ! function_exists( 'get_settings_errors' ) ) {
	function get_settings_errors() {
		return $GLOBALS['mcpwp_test_settings_errors'] ?? array();
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
		$valid = ! empty( $GLOBALS['mcpwp_test_valid_admin_referer'][ $action ] );
		if ( ! $valid ) {
			throw new RuntimeException( 'Invalid nonce: ' . $action );
		}
		return true;
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $location, $status = 302 ) {
		$GLOBALS['_mcpwp_test_redirected_to'] = $location;
		throw new Mcpwp_Test_Redirect_Signal( (string) $location );
	}
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SetupPageScopedKeyCreate1207Test extends TestCase {

	private const FORM_UID = 'testformuid1207';

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']               = array();
		$GLOBALS['mcpwp_test_transients']              = array();
		$GLOBALS['mcpwp_test_current_user']            = 1;
		$GLOBALS['mcpwp_test_valid_admin_referer']     = array( 'mcpwp_manage_scoped_keys' => true );
		$GLOBALS['mcpwp_test_settings_errors']       = array();
		$GLOBALS['_mcpwp_test_redirected_to']          = null;
		$_POST                                         = array();
		$_GET                                          = array( 'page' => 'mcpwp' );
		unset( $GLOBALS['mcpwp_test_wpdb_col_charset'] );

		$mcpwp_dir = dirname( __DIR__ );
		require_once $mcpwp_dir . '/includes/core/class-mcpwp-key-state.php';
		foreach ( array( 'setup', 'control-room', 'chat', 'library', 'settings', 'activity', 'mupot' ) as $trait ) {
			require_once $mcpwp_dir . '/includes/admin/traits/trait-mcpwp-admin-' . $trait . '.php';
		}
		require_once $mcpwp_dir . '/includes/admin/class-mcpwp-admin.php';

		Mcpwp_Admin::reset_setup_page_request_state();
	}

	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		Mcpwp_Admin::reset_setup_page_request_state();
	}

	private function claude_desktop_post( string $form_uid = self::FORM_UID ): array {
		return array(
			'mcpwp_create_scoped_key'     => '1',
			'mcpwp_scoped_keys_nonce'     => 'valid',
			'mcpwp_scoped_key_label'      => 'Claude Desktop',
			'mcpwp_scoped_key_role'       => 'editor',
			'mcpwp_scoped_key_scopes'     => array( 'read', 'write' ),
			'mcpwp_setup_return_tab'      => 'claude-desktop',
			'mcpwp_scoped_form_uid'       => $form_uid,
		);
	}

	private function assert_plaintext_key_not_persisted( string $key ): void {
		foreach ( (array) ( $GLOBALS['mcpwp_test_transients'] ?? array() ) as $name => $value ) {
			if ( is_string( $value ) && false !== strpos( $value, $key ) ) {
				$this->fail( 'Plaintext key found in transient ' . $name );
			}
			if ( is_array( $value ) && ( ( $value['key'] ?? '' ) === $key ) ) {
				$this->fail( 'Plaintext key found in transient array ' . $name );
			}
		}
		foreach ( (array) ( $GLOBALS['mcpwp_test_options'] ?? array() ) as $name => $value ) {
			$encoded = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			if ( is_string( $encoded ) && false !== strpos( $encoded, $key ) ) {
				$this->fail( 'Plaintext key found in option ' . $name );
			}
		}
		$this->assertFalse( get_transient( 'mcpwp_new_scoped_key' ) );
	}

	private function rendered_html( ?array $new_scoped_key ): string {
		require_once dirname( __DIR__ ) . '/tests/fixtures/setup-display-stubs-1098.php';
		$GLOBALS['mcpwp_test_options']['date_format'] = 'Y-m-d';
		$GLOBALS['mcpwp_test_options']['time_format'] = 'H:i';
		ob_start();
		try {
			mcpwp_1098_render_setup_partial( array(), null, $new_scoped_key );
		} finally {
			return (string) ob_get_clean();
		}
	}

	public function test_claude_desktop_key_create_shows_tab_once_without_storing_plaintext(): void {
		$_POST = $this->claude_desktop_post();
		$admin = new Mcpwp_Admin();
		$admin->handle_setup_page_load();

		$ref  = new ReflectionClass( Mcpwp_Admin::class );
		$prop = $ref->getProperty( 'setup_new_scoped_key' );
		$prop->setAccessible( true );
		$new_scoped_key = $prop->getValue();

		$this->assertIsArray( $new_scoped_key );
		$this->assertNotEmpty( $new_scoped_key['key'] );
		$this->assertSame( 'claude-desktop', Mcpwp_Admin::get_setup_connect_tab_override() );

		$key = (string) $new_scoped_key['key'];
		$this->assert_plaintext_key_not_persisted( $key );

		$html = $this->rendered_html( $new_scoped_key );
		$this->assertMatchesRegularExpression(
			'#data-tab="claude-desktop"[^>]*nav-tab-active|nav-tab-active[^>]*data-tab="claude-desktop"#',
			$html
		);
		$this->assertStringNotContainsString(
			'id="mcpwp-tab-claude-desktop" style="display:none;"',
			$html
		);
		$this->assertStringContainsString( 'api_key=' . $key, html_entity_decode( $this->claude_desktop_url_block( $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	public function test_another_admin_load_does_not_see_a_key_minted_by_someone_else(): void {
		$_POST = $this->claude_desktop_post();
		( new Mcpwp_Admin() )->handle_setup_page_load();

		$GLOBALS['mcpwp_test_current_user'] = 2;
		Mcpwp_Admin::reset_setup_page_request_state();
		$_POST = array();

		$html = $this->rendered_html( null );
		$this->assertStringNotContainsString( 'mcpwp_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $html );
		$this->assertStringContainsString( 'api_key=YOUR_API_KEY', $this->claude_desktop_url_block( $html ) );
	}

	public function test_a_resubmitted_form_uid_does_not_mint_a_second_key(): void {
		$_POST = $this->claude_desktop_post();
		( new Mcpwp_Admin() )->handle_setup_page_load();

		$before = ( new Mcpwp_Admin() )->list_scoped_api_keys( false );
		$this->assertCount( 1, $before );

		$_POST = $this->claude_desktop_post();
		try {
			( new Mcpwp_Admin() )->handle_setup_page_load();
			$this->fail( 'Expected redirect on duplicate form submit.' );
		} catch ( Mcpwp_Test_Redirect_Signal $e ) {
			$this->assertStringContainsString( 'page=mcpwp', $e->getMessage() );
		}

		$after = ( new Mcpwp_Admin() )->list_scoped_api_keys( false );
		$this->assertCount( 1, $after );
	}

	private function claude_desktop_url_block( string $html ): string {
		if ( ! preg_match( '#id="mcpwp-claude-desktop-url">(.*?)</pre>#s', $html, $m ) ) {
			$this->fail( 'Claude Desktop URL block missing from Setup page.' );
		}
		return trim( $m[1] );
	}

	public function test_new_primary_key_transient_is_per_user_and_short_lived(): void {
		$this->assertSame( 60, Mcpwp_Admin::NEW_PRIMARY_KEY_TRANSIENT_TTL );
		$this->assertSame( 'mcpwp_new_api_key_7', Mcpwp_Admin::new_primary_key_transient_name( 7 ) );

		set_transient( 'mcpwp_new_api_key', 'legacy-key', 300 );
		$GLOBALS['mcpwp_test_current_user'] = 3;
		$this->assertSame( 'legacy-key', Mcpwp_Admin::pull_new_primary_api_key_transient() );
		$this->assertFalse( get_transient( 'mcpwp_new_api_key' ) );
		$this->assertSame( 'legacy-key', get_transient( Mcpwp_Admin::new_primary_key_transient_name( 3 ) ) );
	}
}

final class Mcpwp_Test_Redirect_Signal extends RuntimeException {
}