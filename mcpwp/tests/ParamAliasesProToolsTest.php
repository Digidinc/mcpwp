<?php
/**
 * Static tool_map / inputSchema coverage for #831's pro-tier renames.
 *
 * These tools are Pro-license-gated at dispatch time (see
 * ParamAliasesDispatchTest.php for the free-tier tools exercised end to end
 * through handle_mcp()) — asserting directly on Mcpwp_MCP_Pro_Tools::get_tools()
 * / get_tool_map() is the same style McpProToolsTest.php already uses for
 * this registry's other param_remap aliases (wp_set_seo, wp_bulk_seo), and
 * needs no license/tier setup.
 *
 * Also proves the #831 design decision stated in
 * Mcpwp_REST_MCP::resolve_param_aliases()'s docblock: a deprecated alias is
 * NOT advertised in inputSchema.properties — only param_aliases on the
 * tool_map entry — unlike the existing #634 pattern (wp_set_seo,
 * wp_list_elementor_archetypes) which lists both names as permanently
 * equal siblings. #831's aliases are retiring an old name, not offering two
 * equally current ones, so they stay out of the schema a client (or Tool
 * Search Tool) reads to decide what to send.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return false;
	}
}

final class ParamAliasesProToolsTest extends TestCase {
	private Mcpwp_MCP_Pro_Tools $registry;

	protected function setUp(): void {
		$this->registry = new Mcpwp_MCP_Pro_Tools();
	}

	private function schemaProperties( string $tool_name ): array {
		foreach ( $this->registry->get_tools() as $tool ) {
			if ( $tool['name'] === $tool_name ) {
				$props = $tool['inputSchema']['properties'];
				return is_array( $props ) ? $props : array();
			}
		}
		$this->fail( "Tool not found: {$tool_name}" );
	}

	public function test_wp_apply_elementor_template_renamed_template_id_to_id(): void {
		$map = $this->registry->get_tool_map();

		$this->assertSame( '/elementor/templates/{id}/apply', $map['wp_apply_elementor_template']['route'] );
		$this->assertSame( 'id', $map['wp_apply_elementor_template']['param_aliases']['template_id'] );

		$props = $this->schemaProperties( 'wp_apply_elementor_template' );
		$this->assertArrayHasKey( 'id', $props );
		$this->assertArrayNotHasKey( 'template_id', $props, 'template_id is a deprecated alias — not advertised in the schema.' );
	}

	public function test_wp_delete_elementor_template_accepts_template_id_alias(): void {
		$map = $this->registry->get_tool_map();

		$this->assertSame( 'id', $map['wp_delete_elementor_template']['param_aliases']['template_id'] );
		$this->assertArrayNotHasKey( 'template_id', $this->schemaProperties( 'wp_delete_elementor_template' ) );
	}

	public function test_wp_create_elementor_part_from_section_renames_page_id_and_aliases_section_id(): void {
		$map = $this->registry->get_tool_map();

		$this->assertSame( 'page_id', $map['wp_create_elementor_part_from_section']['param_remap']['id'] );
		$this->assertSame( 'id', $map['wp_create_elementor_part_from_section']['param_aliases']['page_id'] );
		$this->assertSame( 'element_id', $map['wp_create_elementor_part_from_section']['param_aliases']['section_id'] );

		$props = $this->schemaProperties( 'wp_create_elementor_part_from_section' );
		$this->assertArrayHasKey( 'id', $props );
		$this->assertArrayHasKey( 'element_id', $props );
		$this->assertArrayNotHasKey( 'page_id', $props );
		$this->assertArrayNotHasKey( 'section_id', $props );
	}

	public function test_wp_save_section_as_template_renamed_page_id_to_id(): void {
		$map = $this->registry->get_tool_map();

		$this->assertSame( 'page_id', $map['wp_save_section_as_template']['param_remap']['id'] );
		$this->assertSame( 'id', $map['wp_save_section_as_template']['param_aliases']['page_id'] );
		$this->assertArrayHasKey( 'id', $this->schemaProperties( 'wp_save_section_as_template' ) );
	}

	public function test_wp_add_widget_accepts_widget_type_alias_for_type(): void {
		$map = $this->registry->get_tool_map();

		$this->assertSame( 'type', $map['wp_add_widget']['param_aliases']['widget_type'] );
		// id/type were already correct — no rename, so both remain the
		// tool's only advertised properties.
		$props = $this->schemaProperties( 'wp_add_widget' );
		$this->assertArrayHasKey( 'id', $props );
		$this->assertArrayHasKey( 'type', $props );
	}

	public function test_wp_set_elementor_globals_aliases_flat_colors_to_system_colors(): void {
		$map = $this->registry->get_tool_map();

		$this->assertSame( 'system_colors', $map['wp_set_elementor_globals']['param_aliases']['colors'] );
		$this->assertArrayNotHasKey( 'colors', $this->schemaProperties( 'wp_set_elementor_globals' ), 'colors is a lossy heuristic alias, deliberately not advertised.' );
	}

	public function test_elementor_data_base64_added_to_the_template_archetype_and_part_create_update_tools(): void {
		foreach ( array( 'wp_create_elementor_template', 'wp_update_elementor_template', 'wp_create_elementor_archetype', 'wp_create_elementor_part' ) as $tool_name ) {
			$props = $this->schemaProperties( $tool_name );
			$this->assertArrayHasKey( 'elementor_data_base64', $props, "{$tool_name} should accept elementor_data_base64 like wp_set_elementor does." );
			$this->assertArrayHasKey( 'elementor_data', $props );
		}
	}

	public function test_wp_create_elementor_part_no_longer_hard_requires_elementor_data(): void {
		$tools = $this->registry->get_tools();
		foreach ( $tools as $tool ) {
			if ( 'wp_create_elementor_part' === $tool['name'] ) {
				$required = $tool['inputSchema']['required'] ?? array();
				$this->assertNotContains( 'elementor_data', $required, 'elementor_data must not be schema-required now that elementor_data_base64 is an alternative — the REST layer accepts either.' );
				return;
			}
		}
		$this->fail( 'wp_create_elementor_part not found' );
	}
}
