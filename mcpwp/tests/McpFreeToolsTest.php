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
	public function test_tool_schemas_describe_each_supported_parameter(): void {
		$expected = array(
			'wp_site_info' => array(),
			'wp_list_posts' => array( 'posts_per_page', 'paged', 'post_status', 'orderby', 'order' ),
			'wp_get_post' => array( 'id' ),
			'wp_create_post' => array( 'title', 'content', 'status', 'excerpt', 'slug', 'categories', 'tags', 'featured_image' ),
			'wp_update_post' => array( 'id', 'title', 'content', 'status', 'excerpt', 'slug', 'categories', 'tags', 'featured_image' ),
			'wp_list_pages' => array( 'posts_per_page', 'paged', 'post_status', 'orderby', 'order' ),
			'wp_get_page' => array( 'id' ),
			'wp_create_page' => array( 'title', 'content', 'status', 'slug', 'parent', 'menu_order', 'template', 'featured_image' ),
			'wp_update_page' => array( 'id', 'title', 'content', 'status', 'slug', 'parent', 'menu_order', 'template', 'featured_image' ),
			'wp_list_drafts' => array( 'type', 'posts_per_page', 'paged', 'orderby', 'order' ),
			'wp_delete_draft' => array( 'id' ),
			'wp_list_media' => array( 'posts_per_page', 'paged', 'mime_type', 'search', 'orderby', 'order' ),
			'wp_get_media' => array( 'id' ),
			'wp_upload_media_b64' => array( 'data', 'filename', 'mime_type', 'title', 'alt', 'caption' ),
			'wp_list_categories' => array( 'per_page', 'page', 'search', 'hide_empty', 'orderby', 'order' ),
			'wp_list_tags' => array( 'per_page', 'page', 'search', 'hide_empty', 'orderby', 'order' ),
			'wp_create_term' => array( 'taxonomy', 'name', 'slug', 'description' ),
		);
		foreach ( ( new Mcpwp_MCP_Free_Tools() )->get_tools() as $tool ) {
			$this->assertSame( $expected[ $tool['name'] ], array_keys( (array) $tool['inputSchema']['properties'] ), $tool['name'] );
		}
	}
}
