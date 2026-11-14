<?php
/**
 * #1098 P2 — the Setup page, RENDERED: the key-display branch
 * (mcpwp-setup-display.php: legacy option OR an active scoped record) and
 * the note on a migrated 2.8 key in Active Keys.
 *
 * Renders the real partial in a separate process; the admin-only helpers it
 * calls that the shared bootstrap lacks are stubbed there, so they cannot
 * leak into other tests.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SetupDisplayKeyState1098Test extends TestCase {

	const NOTE = 'Migrated from an older version — has full admin access. Create a new key with only what it needs, then revoke this one.';

	private function render( array $scoped_keys, $new_key = null ): string {
		require_once dirname( __DIR__ ) . '/tests/fixtures/setup-display-stubs-1098.php';
		$GLOBALS['mcpwp_test_options']['date_format'] = 'Y-m-d';
		$GLOBALS['mcpwp_test_options']['time_format'] = 'H:i';
		ob_start();
		try {
			mcpwp_1098_render_setup_partial( $scoped_keys, $new_key );
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	private function record( array $over = array() ): array {
		return array_merge( array( 'id' => 'k1', 'label' => 'Key', 'scopes' => array( 'read', 'write', 'admin' ), 'role' => 'admin', 'tool_categories' => array(), 'deny_all' => false, 'created_at' => '2026-01-01 00:00:00', 'last_used_at' => null, 'revoked_at' => null, 'migrated_from' => '' ), $over );
	}

	public function test_a_site_with_only_an_active_scoped_key_shows_a_hidden_key_not_a_blank(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = array( array( 'id' => 'k1', 'hash' => 'h', 'revoked_at' => null ) );
		$html = $this->render( array( $this->record() ) );
		$this->assertStringContainsString( '(Hidden)', $html, 'The has-key branch renders.' );
	}

	public function test_a_site_with_no_key_at_all_shows_no_hidden_key(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = array( array( 'id' => 'k1', 'hash' => 'h', 'revoked_at' => '2026-01-01 00:00:00' ) );
		$html = $this->render( array( $this->record( array( 'revoked_at' => '2026-01-01 00:00:00' ) ) ) );
		$this->assertStringNotContainsString( '(Hidden)', $html, 'Only revoked records: no key to hide.' );
	}

	public function test_a_migrated_admin_key_carries_the_least_privilege_note(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = array( array( 'id' => 'k1', 'hash' => 'h', 'revoked_at' => null ) );
		$html = $this->render( array(
			$this->record( array( 'id' => 'm1', 'label' => 'Migrated 2.8 key', 'migrated_from' => 'spai' ) ),
			$this->record( array( 'id' => 'n1', 'label' => 'Ordinary key' ) ),
		) );
		$this->assertSame( 1, substr_count( $html, esc_html( self::NOTE ) ), 'Exactly one note: on the migrated key only.' );
		$this->assertMatchesRegularExpression( '#Migrated 2\.8 key</strong>\s*<p class="description mcpwp-migrated-key-note">#', $html );
	}

	public function test_a_revoked_or_non_admin_migrated_key_has_no_note(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = array( array( 'id' => 'k1', 'hash' => 'h', 'revoked_at' => null ) );
		$html = $this->render( array(
			$this->record( array( 'id' => 'm1', 'migrated_from' => 'spai', 'revoked_at' => '2026-01-01 00:00:00' ) ),
			$this->record( array( 'id' => 'm2', 'migrated_from' => 'spai', 'role' => 'editor', 'scopes' => array( 'read', 'write' ) ) ),
		) );
		$this->assertStringNotContainsString( esc_html( self::NOTE ), $html );
	}
}
