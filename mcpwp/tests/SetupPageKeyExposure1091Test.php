<?php
/**
 * #1091 / #1093 — Setup page: never embed the primary admin key in a
 * copyable Claude Desktop connector URL, and describe Elementor HTML the
 * way #1179 actually behaves for a ChatGPT connector key.
 *
 * Review P2s on PR #1203: no ChatGPT-URL fallback for Claude Desktop;
 * refuse admin-role (not only admin-scope) keys in the URL; local OAuth
 * offers the key-less MCP URL; onboarding checklist uses last_activity_time
 * after it is defined.
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

	/**
	 * @param array       $scoped_keys
	 * @param string|null $new_key
	 * @param array|null  $new_scoped_key
	 * @param string      $chatgpt_new_url
	 * @param bool        $local_oauth_enabled
	 */
	private function render( array $scoped_keys, $new_key = null, $new_scoped_key = null, string $chatgpt_new_url = '', bool $local_oauth_enabled = false ): string {
		require_once dirname( __DIR__ ) . '/tests/fixtures/setup-display-stubs-1098.php';
		$GLOBALS['mcpwp_test_options']['date_format'] = 'Y-m-d';
		$GLOBALS['mcpwp_test_options']['time_format'] = 'H:i';
		ob_start();
		try {
			mcpwp_1098_render_setup_partial( $scoped_keys, $new_key, $new_scoped_key, $chatgpt_new_url, $local_oauth_enabled );
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

	/**
	 * P2-2: role admin with only read/write scopes must still be refused —
	 * never put an admin-role key in a URL.
	 */
	public function test_an_admin_role_key_without_admin_scope_does_not_fill_the_claude_desktop_url(): void {
		$html = $this->render(
			array(),
			null,
			array(
				'id'     => 'a2',
				'key'    => self::SCOPED_KEY,
				'scopes' => array( 'read', 'write' ),
				'role'   => 'admin',
			)
		);

		$url = $this->claude_desktop_url_block( $html );
		$this->assertStringContainsString( 'api_key=YOUR_API_KEY', $url );
		$this->assertStringNotContainsString( self::SCOPED_KEY, $url );
	}

	/**
	 * P2-1: a just-minted ChatGPT connector URL must not become the Claude
	 * Desktop connector URL (two clients must not share one credential).
	 */
	public function test_a_just_minted_chatgpt_connector_url_is_not_offered_for_claude_desktop(): void {
		$connector = 'https://example.com/wp-json/mcpwp/v1/mcp?api_key=' . self::SCOPED_KEY;
		$html      = $this->render( array(), self::ADMIN_KEY, null, $connector );

		$url = $this->claude_desktop_url_block( $html );
		$this->assertStringContainsString( 'api_key=YOUR_API_KEY', $url );
		$this->assertNotSame( $connector, $url );
		$this->assertStringNotContainsString( self::SCOPED_KEY, $url );
		$this->assertStringNotContainsString( self::ADMIN_KEY, $url );
		// ChatGPT still gets its own block with the connector URL.
		$this->assertStringContainsString( 'id="mcpwp-chatgpt-url"', $html );
		$this->assertStringContainsString( $connector, $html );
	}

	public function test_chatgpt_connector_wording_matches_elementor_sanitization(): void {
		$html = $this->render( array() );

		$this->assertStringNotContainsString( 'including scripts', $html );
		$this->assertStringContainsString( 'sanitized the same as for any key', $html );
		$this->assertStringContainsString( 'Only an admin-scoped key can store raw Kit CSS', $html );
	}

	/**
	 * P2-3: Connect intro must not claim every client URL is pre-filled;
	 * Claude Desktop tab offers a create-key step and, when local OAuth is
	 * on, the key-less MCP URL.
	 */
	public function test_claude_desktop_copy_and_local_oauth_url(): void {
		$html = $this->render( array(), null, null, '', true );

		$this->assertStringNotContainsString( 'Your API key has been pre-filled below.', $html );
		$this->assertStringContainsString( 'Header-based clients', $html );
		$this->assertStringContainsString( 'Create a Claude Desktop key', $html );
		$this->assertStringContainsString( 'name="mcpwp_create_scoped_key"', $html );
		$this->assertStringContainsString( 'value="Claude Desktop"', $html );
		$this->assertStringContainsString( 'id="mcpwp-claude-desktop-oauth-url"', $html );
		if ( ! preg_match( '#id="mcpwp-claude-desktop-oauth-url">(.*?)</pre>#s', $html, $m ) ) {
			$this->fail( 'Claude Desktop OAuth URL block missing.' );
		}
		$oauth_url = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		$this->assertStringNotContainsString( 'api_key=', $oauth_url );
		$this->assertStringContainsString( '/wp-json/mcpwp/v1/mcp', $oauth_url );
		$this->assertStringNotContainsString( 'OAuth sign-in below when enabled', $html );
	}

	/**
	 * P2-4: checklist connection step is Done only after last_activity_time
	 * is computed (moved below get_recent_activity_rows).
	 */
	public function test_onboarding_connection_step_uses_last_activity_time(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = array(
			array( 'id' => 'k1', 'hash' => 'h', 'revoked_at' => null ),
		);
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) {
			if ( 'get_results' === $method && false !== strpos( (string) $query, 'mcpwp_activity_log' ) ) {
				return array(
					array(
						'id'          => 1,
						'action'      => 'tools/call',
						'endpoint'    => '/mcpwp/v1/mcp',
						'method'      => 'POST',
						'status_code' => 200,
						'created_at'  => '2026-11-15 12:00:00',
					),
				);
			}
			return MCPWP_TEST_WPDB_PASS;
		};

		$html = $this->render(
			array(
				array(
					'id'              => 'k1',
					'label'           => 'Key',
					'scopes'          => array( 'read', 'write' ),
					'role'            => 'editor',
					'tool_categories' => array(),
					'deny_all'        => false,
					'created_at'      => '2026-01-01 00:00:00',
					'last_used_at'    => null,
					'revoked_at'      => null,
					'migrated_from'   => '',
				),
			)
		);

		$this->assertMatchesRegularExpression(
			'#Make your first connection</strong>.*?</div>\s*</div>\s*<div class="mcpwp-onboarding-step">#s',
			$html
		);
		// The connection step status immediately before that body is Done.
		$this->assertMatchesRegularExpression(
			'#mcpwp-onboarding-step__status is-done">\s*Done\s*</span>\s*<div class="mcpwp-onboarding-step__body">\s*<strong>Make your first connection</strong>#s',
			$html
		);
	}

	public function test_onboarding_connection_step_stays_open_without_activity(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = array(
			array( 'id' => 'k1', 'hash' => 'h', 'revoked_at' => null ),
		);
		$html = $this->render(
			array(
				array(
					'id'              => 'k1',
					'label'           => 'Key',
					'scopes'          => array( 'read', 'write' ),
					'role'            => 'editor',
					'tool_categories' => array(),
					'deny_all'        => false,
					'created_at'      => '2026-01-01 00:00:00',
					'last_used_at'    => null,
					'revoked_at'      => null,
					'migrated_from'   => '',
				),
			)
		);

		$this->assertMatchesRegularExpression(
			'#mcpwp-onboarding-step__status is-open">\s*To do\s*</span>\s*<div class="mcpwp-onboarding-step__body">\s*<strong>Make your first connection</strong>#s',
			$html
		);
	}
}
