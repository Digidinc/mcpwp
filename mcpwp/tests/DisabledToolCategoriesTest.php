<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the admin global "disabled tool categories" gate.
 *
 * Ensures that a category disabled via the option `mcpwp_disabled_tool_categories`
 * is rejected on `tools/call` (not just hidden from `tools/list`), and that the
 * gate is cleared once the option is removed.
 */
final class DisabledToolCategoriesTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 2;
	}

	private function call_tool( string $tool_name ): array {
		$controller = new Mcpwp_REST_MCP();
		$body = array(
			'jsonrpc' => '2.0',
			'id'      => 42,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => $tool_name,
				'arguments' => (object) array(),
			),
		);
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			null,
			wp_json_encode( $body )
		);

		return $controller->handle_mcp( $request )->get_data();
	}

	public function test_tool_in_disabled_category_is_rejected_on_call(): void {
		// `wp_site_info` is in the "site" category (read-only, no plugin requirement).
		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );

		$data = $this->call_tool( 'wp_site_info' );

		$this->assertArrayHasKey( 'error', $data, 'A disabled-category tool must return a JSON-RPC error.' );
		$this->assertArrayNotHasKey( 'result', $data, 'A disabled-category tool must not execute.' );
		$this->assertSame( -32003, $data['error']['code'] );
		$this->assertStringContainsString( 'disabled by the site administrator', $data['error']['message'] );
	}

	public function test_tool_in_non_disabled_category_passes_the_gate(): void {
		// Disable "content" but call a "site" tool — the gate must not block it.
		update_option( 'mcpwp_disabled_tool_categories', array( 'content' ) );

		$data = $this->call_tool( 'wp_site_info' );

		// The disabled-categories gate must not have fired for a non-disabled category.
		if ( isset( $data['error'] ) ) {
			$this->assertStringNotContainsString( 'disabled by the site administrator', $data['error']['message'] );
		} else {
			$this->assertArrayHasKey( 'result', $data, 'A non-disabled tool should execute successfully.' );
		}
	}

	public function test_disabled_category_tool_is_callable_again_after_clearing_option(): void {
		// Disable, confirm blocked, then clear and confirm it is no longer blocked.
		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );
		$blocked = $this->call_tool( 'wp_site_info' );
		$this->assertSame( -32003, $blocked['error']['code'] );

		delete_option( 'mcpwp_disabled_tool_categories' );
		$data = $this->call_tool( 'wp_site_info' );

		$this->assertArrayHasKey( 'result', $data, 'Tool should be callable again after the option is cleared.' );
		$this->assertArrayNotHasKey( 'error', $data );
	}

	public function test_empty_disabled_categories_option_does_not_block(): void {
		update_option( 'mcpwp_disabled_tool_categories', array() );

		$data = $this->call_tool( 'wp_site_info' );

		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayNotHasKey( 'error', $data );
	}
}
