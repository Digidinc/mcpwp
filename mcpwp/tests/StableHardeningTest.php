<?php

use PHPUnit\Framework\TestCase;

/**
 * Stable-release hardening regressions (#496, #559).
 *
 * #496 — calling a Pro tool on a Free install must return an upgrade-path
 *        error (pro_required), not a generic "unknown tool".
 * #559 — /option must not let a plain string overwrite an option that
 *        currently holds an array/object: a stringified
 *        elementor_pro_theme_builder_conditions silently removes every
 *        theme-builder location site-wide. Valid JSON decodes; anything
 *        else is rejected unless force_type_change is passed.
 */
final class StableHardeningTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 2;
	}

	// ── #496: Pro tool on Free install ─────────────────────────────────

	private function call_tool( string $tool_name ): array {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 7,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => $tool_name,
					'arguments' => array(),
				),
			)
		);

		return $controller->handle_mcp( $request )->get_data();
	}

	public function test_pro_tool_on_free_install_returns_upgrade_error(): void {
		// No license configured in setUp() → is_pro_active() is false.
		$data = $this->call_tool( 'wp_build_page' );

		$this->assertArrayHasKey( 'error', $data, 'Pro tool on Free must error.' );
		$this->assertSame( -32004, $data['error']['code'], 'Pro-required uses a dedicated JSON-RPC code, not unknown-tool -32602.' );
		$this->assertStringContainsString( 'MCPWP Pro', $data['error']['message'] );
		$this->assertSame( 'pro', $data['error']['data']['plan_required'] );
		$this->assertStringContainsString( 'mcpwp.net/pricing', $data['error']['data']['upgrade_url'] );
	}

	public function test_unknown_tool_still_returns_unknown_not_upsell(): void {
		$data = $this->call_tool( 'wp_definitely_not_a_tool' );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32602, $data['error']['code'] );
		$this->assertStringContainsString( 'Unknown tool', $data['error']['message'] );
	}

	// ── #559: option type-preserving guard ─────────────────────────────

	private function update_option_via_api( string $key, $value, array $extra = array() ) {
		$controller = new Mcpwp_REST_Site_Settings();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/option' );
		$request->set_param( 'key', $key );
		$request->set_param( 'value', $value );
		foreach ( $extra as $k => $v ) {
			$request->set_param( $k, $v );
		}

		return $controller->update_option_handler( $request );
	}

	public function test_string_overwrite_of_array_option_is_rejected(): void {
		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( '315' => array( 'include/general' ) ) ) );

		$response = $this->update_option_via_api( 'elementor_pro_theme_builder_conditions', 'not json at all' );

		$this->assertInstanceOf( WP_Error::class, $response, 'A type-mismatched write must be rejected.' );
		$this->assertSame( 'option_type_mismatch', $response->get_error_code() );
		$this->assertIsArray(
			get_option( 'elementor_pro_theme_builder_conditions' ),
			'The stored option must remain an array after the rejected write.'
		);
	}

	public function test_json_string_value_is_decoded_into_array_option(): void {
		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( '315' => array( 'include/general' ) ) ) );

		$json     = '{"header":{"315":["include/general"]},"footer":{"323":["include/general"]}}';
		$response = $this->update_option_via_api( 'elementor_pro_theme_builder_conditions', $json );

		$this->assertSame( 200, $response->get_status() );
		$stored = get_option( 'elementor_pro_theme_builder_conditions' );
		$this->assertIsArray( $stored, 'Valid JSON must be decoded before storage, never stored as a string.' );
		$this->assertSame( array( 'include/general' ), $stored['footer']['323'] );
	}

	public function test_force_type_change_allows_explicit_string_overwrite(): void {
		update_option( 'theme_mods_probe_option', array( 'a' => 1 ) );

		$response = $this->update_option_via_api(
			'theme_mods_probe_option',
		update_option( 'elementor_some_array_option', array( 'a' => 1 ) );

			'elementor_some_array_option',
			'plain string on purpose',
			array( 'force_type_change' => true )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'plain string on purpose', get_option( 'theme_mods_probe_option' ) );
		$this->assertSame( 'plain string on purpose', get_option( 'elementor_some_array_option' ) );
	}

	public function test_plain_string_option_updates_unchanged(): void {
		update_option( 'blogname', 'Old Name' );

		$response = $this->update_option_via_api( 'blogname', 'New Name' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'New Name', get_option( 'blogname' ) );
	}
}
