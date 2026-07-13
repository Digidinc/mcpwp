<?php
use PHPUnit\Framework\TestCase;

final class McpEndpointTest extends TestCase {
	protected function setUp(): void { $GLOBALS['mcpwp_test_options'] = array(); }
	private function call( $method, $params = array() ) {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array(), array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ) );
		return ( new Mcpwp_REST_MCP() )->handle_mcp( $request )->get_data();
	}
	public function test_initialize_advertises_tools_and_resources(): void {
		$data = $this->call( 'initialize' );
		$this->assertArrayHasKey( 'tools', $data['result']['capabilities'] );
		$this->assertArrayHasKey( 'resources', $data['result']['capabilities'] );
	}
	public function test_tools_list_is_exact_approved_inventory(): void {
		$data = $this->call( 'tools/list' );
		$names = array_column( $data['result']['tools'], 'name' );
		$this->assertSame( array( 'wp_site_info', 'wp_list_posts', 'wp_get_post', 'wp_create_post', 'wp_update_post', 'wp_list_pages', 'wp_get_page', 'wp_create_page', 'wp_update_page', 'wp_list_drafts', 'wp_delete_draft', 'wp_list_media', 'wp_get_media', 'wp_upload_media', 'wp_upload_media_b64', 'wp_list_categories', 'wp_list_tags', 'wp_create_term' ), $names );
	}
	public function test_delete_draft_is_the_only_destructive_tool(): void {
		$data = $this->call( 'tools/list' ); $destructive = array();
		foreach ( $data['result']['tools'] as $tool ) { if ( $tool['annotations']['destructiveHint'] ) { $destructive[] = $tool['name']; } }
		$this->assertSame( array( 'wp_delete_draft' ), $destructive );
	}
	public function test_resources_are_local_and_curated(): void {
		$data = $this->call( 'resources/list' );
		$this->assertSame( array( 'Site Info', 'Posts' ), array_column( $data['result']['resources'], 'name' ) );
	}
}
