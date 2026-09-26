<?php

use PHPUnit\Framework\TestCase;

/**
 * The "seo-loop" MCP prompt: listed in prompts/list, and prompts/get returns
 * a text message that walks the read -> judge -> fix -> kill -> link ->
 * measure -> remember loop using real, registered tool names.
 */
final class McpPromptsSeoLoopTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
	}

	private function dispatch( $method, $params = array(), $id = 1 ) {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'method'  => $method,
				'params'  => $params,
			)
		);
		return $controller->handle_mcp( $request )->get_data();
	}

	public function test_seo_loop_is_listed() {
		$data  = $this->dispatch( 'prompts/list' );
		$names = array_column( $data['result']['prompts'], 'name' );

		$this->assertContains( 'seo-loop', $names );
	}

	public function test_seo_loop_get_returns_text_referencing_real_tools() {
		$data = $this->dispatch( 'prompts/get', array( 'name' => 'seo-loop' ) );

		$this->assertArrayHasKey( 'result', $data );
		$messages = $data['result']['messages'];
		$this->assertNotEmpty( $messages );

		$text = $messages[0]['content']['text'];
		$this->assertIsString( $text );
		$this->assertNotEmpty( $text );

		// Every referenced tool must be a real, registered tool name — a
		// prompt that tells an agent to call a tool that doesn't exist is
		// worse than no prompt at all.
		$referenced = array(
			'wp_seo_flags',
			'wp_import_search_performance',
			'wp_create_page',
			'wp_update_page',
			'wp_create_post',
			'wp_update_post',
			'wp_list_approvals',
			'wp_apply_approval',
			'wp_suggest_internal_links',
			'wp_apply_internal_link',
			'wp_remember',
		);

		$free_tools    = new Mcpwp_MCP_Free_Tools();
		$registered    = array_column( $free_tools->get_tools(), 'name' );

		foreach ( $referenced as $tool ) {
			$this->assertStringContainsString( $tool, $text, "Prompt text must mention {$tool}." );
			$this->assertContains( $tool, $registered, "{$tool} must be a real registered MCP tool." );
		}
	}

	public function test_seo_loop_has_no_required_arguments() {
		$data = $this->dispatch( 'prompts/list' );
		foreach ( $data['result']['prompts'] as $prompt ) {
			if ( 'seo-loop' === $prompt['name'] ) {
				$this->assertSame( array(), $prompt['arguments'] );
				return;
			}
		}
		$this->fail( 'seo-loop prompt not found in prompts/list.' );
	}
}
