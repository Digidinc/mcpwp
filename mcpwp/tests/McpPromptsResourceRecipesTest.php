<?php
/**
 * #913 — three new MCP prompt recipes ported from the agent playbooks /
 * mcpwp:operate loop (brand-first, verify-before-done): build-landing-page,
 * fix-page-from-screenshot, publish-post-safely. Plus a registry-wide guard:
 * a prompt that tells an agent to call a tool that does not exist is worse
 * than no prompt at all (mirrors McpPromptsSeoLoopTest's existing check for
 * seo-loop, generalized to every prompt prompts/list advertises).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class McpPromptsResourceRecipesTest extends TestCase {

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

	public function test_all_three_recipes_are_listed(): void {
		$data  = $this->dispatch( 'prompts/list' );
		$names = array_column( $data['result']['prompts'], 'name' );

		$this->assertContains( 'build-landing-page', $names );
		$this->assertContains( 'fix-page-from-screenshot', $names );
		$this->assertContains( 'publish-post-safely', $names );
	}

	public function test_build_landing_page_requires_title_and_goal(): void {
		$data = $this->dispatch( 'prompts/get', array( 'name' => 'build-landing-page' ) );
		$this->assertArrayHasKey( 'error', $data, 'Missing required arguments must be refused.' );

		$data = $this->dispatch(
			'prompts/get',
			array(
				'name'      => 'build-landing-page',
				'arguments' => array(
					'title' => 'Spring Sale',
					'goal'  => 'capture leads for the spring sale',
				),
			)
		);
		$this->assertArrayHasKey( 'result', $data );
		$text = $data['result']['messages'][0]['content']['text'];
		$this->assertStringContainsString( 'Spring Sale', $text );
		$this->assertStringContainsString( 'mcpwp://brand', $text );
		$this->assertStringContainsString( 'wp_build_page', $text );
		$this->assertStringContainsString( 'wp_get_rendered_html', $text );
	}

	public function test_fix_page_from_screenshot_requires_page_id(): void {
		$data = $this->dispatch( 'prompts/get', array( 'name' => 'fix-page-from-screenshot' ) );
		$this->assertArrayHasKey( 'error', $data );

		$data = $this->dispatch(
			'prompts/get',
			array(
				'name'      => 'fix-page-from-screenshot',
				'arguments' => array( 'page_id' => 42 ),
			)
		);
		$this->assertArrayHasKey( 'result', $data );
		$text = $data['result']['messages'][0]['content']['text'];
		$this->assertStringContainsString( '42', $text );
		$this->assertStringContainsString( 'mcpwp://brand', $text );
		$this->assertStringContainsString( 'wp_screenshot_url', $text );
		$this->assertStringContainsString( 'wp_get_elementor', $text );
		$this->assertStringContainsString( 'wp_list_approvals', $text );
	}

	public function test_publish_post_safely_requires_post_id_and_never_flips_status_directly(): void {
		$data = $this->dispatch( 'prompts/get', array( 'name' => 'publish-post-safely' ) );
		$this->assertArrayHasKey( 'error', $data );

		$data = $this->dispatch(
			'prompts/get',
			array(
				'name'      => 'publish-post-safely',
				'arguments' => array( 'post_id' => 7 ),
			)
		);
		$this->assertArrayHasKey( 'result', $data );
		$text = $data['result']['messages'][0]['content']['text'];
		$this->assertStringContainsString( 'mcpwp://brand', $text );
		$this->assertStringContainsString( 'wp_validate_seo_readiness', $text );
		$this->assertStringContainsString( 'wp_list_approvals', $text );
		$this->assertStringContainsString( 'Do not call wp_update_post/wp_update_page with status=publish directly', $text );
	}

	/**
	 * Every wp_-prefixed tool name mentioned in ANY prompt's text must be a
	 * real, registered tool — generalizes McpPromptsSeoLoopTest's seo-loop-only
	 * check to the whole prompts/list surface, so a future prompt cannot
	 * silently reference a renamed or removed tool.
	 */
	public function test_every_tool_mentioned_in_every_prompt_is_registered(): void {
		$registered = $this->all_tool_names();
		$this->assertNotEmpty( $registered );

		$list_data = $this->dispatch( 'prompts/list' );

		foreach ( $list_data['result']['prompts'] as $prompt ) {
			$name = $prompt['name'];

			$arguments = array();
			foreach ( $prompt['arguments'] as $arg ) {
				if ( ! empty( $arg['required'] ) ) {
					// A stub value per type is enough — these prompts only
					// sanitize/interpolate arguments into text, they never
					// resolve them against real data.
					$arguments[ $arg['name'] ] = 'id' === $arg['name'] || false !== strpos( $arg['name'], '_id' ) ? 1 : 'test-value';
				}
			}

			$get_data = $this->dispatch( 'prompts/get', array( 'name' => $name, 'arguments' => $arguments ) );
			$this->assertArrayHasKey( 'result', $get_data, "prompts/get for \"{$name}\" must succeed with stubbed required arguments." );

			$text = $get_data['result']['messages'][0]['content']['text'];
			preg_match_all( '/\bwp_[a-z0-9_]+\b/', $text, $matches );

			foreach ( array_unique( $matches[0] ) as $mentioned_tool ) {
				$this->assertContains(
					$mentioned_tool,
					$registered,
					"Prompt \"{$name}\" mentions \"{$mentioned_tool}\", which is not a registered MCP tool."
				);
			}
		}
	}
}
