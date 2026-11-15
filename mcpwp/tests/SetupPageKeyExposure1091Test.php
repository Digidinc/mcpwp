<?php
/**
 * #1091 / #1093 — Setup page: never embed the primary admin key in a
 * copyable Claude Desktop connector URL, and describe Elementor HTML the
 * way #1179 actually behaves for a ChatGPT connector key.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SetupPageKeyExposure1091Test extends TestCase {

	private const ADMIN_KEY = 'mcpwp_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const SCOPED_KEY = 'mcpwp_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	private function render( array $scoped_keys, $new_key = null, $new_scoped_key = null, string $chatgpt_new_url = '' ): string {
		require_once dirname( __DIR__ ) . '/tests/fixtures/setup-display-stubs-1098.php';
		$GLOBALS['mcpwp_test_options']['date_format'] = 'Y-m-d';
		$GLOBALS['mcpwp_test_options']['time_format'] = 'H:i';
		ob_start();
		try {
			mcpwp_1098_render_setup_partial( $scoped_keys, $new_key, $new_scoped_key, $chatgpt_new_url );
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	private function claude_desktop_url_block( string $html ): string {
		if ( ! preg_match( '#id="mcpwp-claude-desktop-url">(.*?)</pre>#s', $html, $m ) ) {
			$this->fail( 'Claude Desktop URL block missing from Setup page.' );
		}
		return html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
	}

	public function test_first_activation_admin_key_is_not_embedded_in_claude_desktop_url(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = array(
			array( 'id' => 'primary', 'hash' => 'h', 'revoked_at' => null ),
		);
		$html = $this->render(
			array(
				array(
					'id'              => 'primary',
					'label'           => 'Primary API Key',
					'scopes'          => array( 'read', 'write', 'admin' ),
					'role'            => 'admin',
					'tool_categories' => array(),
					'deny_all'        => false,
					'created_at'      => '2026-01-01 00:00:00',
					'last_used_at'    => null,
					'revoked_at'      => null,
					'migrated_from'   => '',
				),
			),
			self::ADMIN_KEY
		);

		$url = $this->claude_desktop_url_block( $html );
		$this->assertStringContainsString( 'api_key=YOUR_API_KEY', $url );
		$this->assertStringNotContainsString( self::ADMIN_KEY, $url );
		// The admin key remains available in the documented primary-key display.
		$this->assertStringContainsString( 'id="mcpwp-api-key"', $html );
		$this->assertStringContainsString( self::ADMIN_KEY, $html );
		$this->assertStringContainsString( 'never the primary admin key', $html );
	}

	public function test_a_non_admin_scoped_key_may_fill_the_claude_desktop_url(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = array(
			array( 'id' => 's1', 'hash' => 'h', 'revoked_at' => null ),
		);
		$html = $this->render(
			array(
				array(
					'id'              => 's1',
					'label'           => 'Claude Desktop',
					'scopes'          => array( 'read', 'write' ),
					'role'            => 'editor',
					'tool_categories' => array(),
					'deny_all'        => false,
					'created_at'      => '2026-01-01 00:00:00',
					'last_used_at'    => null,
					'revoked_at'      => null,
					'migrated_from'   => '',
				),
			),
			null,
			array(
				'id'     => 's1',
				'key'    => self::SCOPED_KEY,
				'scopes' => array( 'read', 'write' ),
				'role'   => 'editor',
			)
		);

		$url = $this->claude_desktop_url_block( $html );
		$this->assertStringContainsString( 'api_key=' . self::SCOPED_KEY, $url );
		$this->assertStringNotContainsString( 'YOUR_API_KEY', $url );
	}

	public function test_an_admin_scoped_key_does_not_fill_the_claude_desktop_url(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = array(
			array( 'id' => 'a1', 'hash' => 'h', 'revoked_at' => null ),
		);
		$html = $this->render(
			array(),
			null,
			array(
				'id'     => 'a1',
				'key'    => self::SCOPED_KEY,
				'scopes' => array( 'read', 'write', 'admin' ),
				'role'   => 'admin',
			)
		);

		$url = $this->claude_desktop_url_block( $html );
		$this->assertStringContainsString( 'api_key=YOUR_API_KEY', $url );
		$this->assertStringNotContainsString( self::SCOPED_KEY, $url );
	}

	public function test_a_just_minted_chatgpt_connector_url_is_offered_for_claude_desktop(): void {
		$connector = 'https://example.com/wp-json/mcpwp/v1/mcp?api_key=' . self::SCOPED_KEY;
		$html      = $this->render( array(), self::ADMIN_KEY, null, $connector );

		$url = $this->claude_desktop_url_block( $html );
		$this->assertSame( $connector, $url );
		$this->assertStringNotContainsString( self::ADMIN_KEY, $url );
	}

	public function test_chatgpt_connector_wording_matches_elementor_sanitization(): void {
		$html = $this->render( array() );

		$this->assertStringNotContainsString( 'including scripts', $html );
		$this->assertStringContainsString( 'sanitized the same as for any key', $html );
		$this->assertStringContainsString( 'Only an admin-scoped key can store raw Kit CSS', $html );
	}
}
