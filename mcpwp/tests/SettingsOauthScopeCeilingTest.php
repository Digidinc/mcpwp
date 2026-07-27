<?php
/**
 * The client_credentials scope ceiling must survive a settings save.
 *
 * sanitize_settings() rebuilds its output from an empty array and returns only
 * keys it names explicitly, so a key that is not handled there is silently
 * dropped on every save — the administrator would pick "Full admin", press Save,
 * and be shown "Read and write" with no error. These tests lock the default, the
 * allow-list, and the fail-safe direction of the fallback.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-settings.php';

final class SettingsOauthScopeCeilingTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
	}

	private function settings(): Mcpwp_Settings {
		return new Mcpwp_Settings();
	}

	public function test_default_ceiling_is_write(): void {
		$this->assertSame( 'write', $this->settings()->get_defaults()['oauth_client_credentials_max_scope'] );
	}

	public function test_sanitize_preserves_an_explicit_admin_choice(): void {
		$out = $this->settings()->sanitize_settings( array( 'oauth_client_credentials_max_scope' => 'admin' ) );

		$this->assertArrayHasKey( 'oauth_client_credentials_max_scope', $out, 'The key must survive sanitize_settings()' );
		$this->assertSame( 'admin', $out['oauth_client_credentials_max_scope'] );
	}

	public function test_sanitize_preserves_read(): void {
		$out = $this->settings()->sanitize_settings( array( 'oauth_client_credentials_max_scope' => 'read' ) );

		$this->assertSame( 'read', $out['oauth_client_credentials_max_scope'] );
	}

	public function test_sanitize_rejects_an_unknown_value(): void {
		$out = $this->settings()->sanitize_settings( array( 'oauth_client_credentials_max_scope' => 'superadmin' ) );

		$this->assertSame( 'write', $out['oauth_client_credentials_max_scope'], 'Unknown values must fall back down, never up' );
	}

	public function test_sanitize_of_absent_key_falls_back_to_write(): void {
		$out = $this->settings()->sanitize_settings( array() );

		$this->assertSame( 'write', $out['oauth_client_credentials_max_scope'] );
	}

	public function test_get_settings_exposes_the_default_when_option_is_empty(): void {
		$this->assertSame( 'write', $this->settings()->get_settings()['oauth_client_credentials_max_scope'] );
	}
}
