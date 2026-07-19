<?php
/**
 * Tool-name stability guard (GH #673).
 *
 * The documented Elementor CSS-regeneration tool must be discoverable under its
 * stable name `wp_regenerate_elementor_css`, and no tool may leak a hash-suffixed
 * name (e.g. wp_regenerate_ba7fe74659ee) into the caller-facing API. This locks
 * the invariant so no future registration path can silently hash a tool name;
 * onboarding tells agents to call the documented names, so they must exist.
 *
 * @package SitePilotAI\Tests
 */

class ToolNameStabilityTest extends PHPUnit\Framework\TestCase {

	/** @return string[] Every registered free + pro tool name. */
	private function all_tool_names(): array {
		$names = array();
		foreach ( array( new Mcpwp_MCP_Free_Tools(), new Mcpwp_MCP_Pro_Tools() ) as $registry ) {
			foreach ( $registry->get_tools() as $tool ) {
				if ( isset( $tool['name'] ) ) {
					$names[] = $tool['name'];
				}
			}
		}
		return $names;
	}

	public function test_css_regeneration_tool_uses_documented_name() {
		$this->assertContains(
			'wp_regenerate_elementor_css',
			$this->all_tool_names(),
			'Onboarding documents wp_regenerate_elementor_css; it must be registered under that exact name.'
		);
	}

	public function test_no_tool_name_leaks_a_hash_suffix() {
		foreach ( $this->all_tool_names() as $name ) {
			$this->assertDoesNotMatchRegularExpression(
				'/_[0-9a-f]{8,}$/',
				$name,
				sprintf( 'Tool name "%s" looks hash-suffixed; caller-facing names must be stable (GH #673).', $name )
			);
		}
	}

	public function test_tool_names_are_unique() {
		$names = $this->all_tool_names();
		$this->assertSame( array_values( array_unique( $names ) ), $names, 'Duplicate tool names would force collision handling.' );
	}
}
