<?php
use PHPUnit\Framework\TestCase;

final class McpFreeToolsTest extends TestCase {
	public function test_definitions_and_map_have_exact_same_inventory(): void {
		$registry = new Mcpwp_MCP_Free_Tools();
		$this->assertSame( array_keys( $registry->get_tool_map() ), array_column( $registry->get_tools(), 'name' ) );
	}
	public function test_tool_map_has_only_local_rest_methods(): void {
		foreach ( ( new Mcpwp_MCP_Free_Tools() )->get_tool_map() as $mapping ) {
			$this->assertContains( $mapping['method'], array( 'GET', 'POST', 'PATCH', 'DELETE' ) );
			$this->assertStringStartsWith( '/', $mapping['route'] );
		}
	}
	public function test_all_tools_have_protocol_schema_and_annotations(): void {
		foreach ( ( new Mcpwp_MCP_Free_Tools() )->get_tools() as $tool ) {
			$this->assertArrayHasKey( 'inputSchema', $tool );
			$this->assertArrayHasKey( 'annotations', $tool );
			$this->assertFalse( $tool['annotations']['openWorldHint'] );
		}
	}
}
