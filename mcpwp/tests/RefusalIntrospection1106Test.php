<?php
/**
 * #1106: /introspect and /onboard list only the tools the caller's key can
 * call — the same filter tools/list and mcpwp_find_tools use. Separate
 * process: get_introspection_data() needs WordPress helpers the shared
 * bootstrap does not declare, and declaring them must not leak.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class RefusalIntrospection1106Test extends TestCase {

	protected function setUp(): void {
		if ( defined( 'MCPWP_PRO' ) || defined( 'MCPWP_WPORG_BUILD' ) || defined( 'MCPWP_ENCRYPTION_KEY' ) ) {
			$this->markTestSkipped( 'A build-level constant is defined in this process.' );
		}
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters']    = array();
		if ( ! function_exists( 'wp_timezone_string' ) ) {
			function wp_timezone_string() {
				return 'UTC';
			}
		}
		if ( ! function_exists( 'get_locale' ) ) {
			function get_locale() {
				return 'en_US';
			}
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			function is_plugin_active( $plugin ) {
				return false;
			}
		}
		if ( ! function_exists( 'wp_get_theme' ) ) {
			function wp_get_theme() {
				return new class() {
					public function get( $header ) {
						return 'Stub Theme';
					}
				};
			}
		}
	}

	private function names( array $record ): array {
		$data = ( new Mcpwp_REST_MCP() )->get_introspection_data_for_caller( $record );
		$out  = array();
		foreach ( $data['tools'] as $tool ) {
			$out[] = $tool['name'];
		}
		return $out;
	}

	public function test_introspection_lists_only_what_the_key_can_call(): void {
		$write = $this->names( array( 'id' => 'a', 'role' => 'admin', 'scopes' => array( 'read', 'write' ) ) );
		$admin = $this->names( array( 'id' => 'b', 'role' => 'admin', 'scopes' => array( 'read', 'write', 'admin' ) ) );
		$read  = $this->names( array( 'id' => 'c', 'role' => 'admin', 'scopes' => array( 'read' ) ) );

		$this->assertContains( 'wp_set_site_context', $admin );
		$this->assertNotContains( 'wp_set_site_context', $write );
		$this->assertContains( 'wp_create_page', $write );
		$this->assertNotContains( 'wp_create_page', $read );
		$this->assertContains( 'wp_site_info', $read );
	}
}
