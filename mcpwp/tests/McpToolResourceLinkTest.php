<?php
/**
 * #913 — a create/update-page-or-post tool result may carry a `resource_link`
 * content item pointing at the freshly written mcpwp://page/{id}, so an agent
 * does not need a second tool call just to get a resource_link back. Gated to
 * the protocol version that defines `resource_link` (2025-06-18+, the same
 * boundary protocol_version_has_structured_output() already tracks for
 * structuredContent) — the text block is unaffected either way.
 *
 * Same request pattern as McpProtocolVersionTest: no API key is presented, so
 * the key record stays null (unrestricted), and the stubbed rest_do_request()
 * answers every internal dispatch with the scripted
 * mcpwp_test_rest_response_override — exactly what a real wp_create_page
 * dispatch to Mcpwp_Pages::create_page() would hand back.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class McpToolResourceLinkTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		$GLOBALS['_mcpwp_test_posts']            = array();
		$GLOBALS['_mcpwp_test_meta']             = array();
		Mcpwp_REST_MCP::reset_verdict_memo();

		$GLOBALS['mcpwp_test_rest_response_override'] = array(
			'id'          => 5501,
			'title'       => 'Spring Sale Landing',
			'status'      => 'draft',
			'url'         => 'https://example.test/spring-sale/',
			'template'    => 'default',
			'has_elementor' => false,
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_rest_response_override'] );
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	private function call_create_page( $version ) {
		$headers = null === $version ? array() : array( 'MCP-Protocol-Version' => $version );
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			$headers,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'wp_create_page',
					'arguments' => array( 'title' => 'Spring Sale Landing' ),
				),
			)
		);
		return ( new Mcpwp_REST_MCP() )->handle_mcp( $request )->get_data();
	}

	public function test_resource_link_is_present_under_2025_06_18(): void {
		$data = $this->call_create_page( '2025-06-18' );

		$this->assertArrayHasKey( 'result', $data );
		$content = $data['result']['content'];

		$links = array_values( array_filter( $content, static fn( $item ) => 'resource_link' === $item['type'] ) );
		$this->assertCount( 1, $links, 'Exactly one resource_link content item is expected.' );
		$this->assertSame( 'mcpwp://page/5501', $links[0]['uri'] );
		$this->assertSame( 'Spring Sale Landing', $links[0]['name'] );
		$this->assertSame( 'application/json', $links[0]['mimeType'] );

		// The text block must still be present — resource_link is additive.
		$text_blocks = array_values( array_filter( $content, static fn( $item ) => 'text' === $item['type'] ) );
		$this->assertCount( 1, $text_blocks );
	}

	public function test_resource_link_is_absent_before_2025_06_18(): void {
		$data = $this->call_create_page( '2025-03-26' );

		$this->assertArrayHasKey( 'result', $data );
		$content = $data['result']['content'];

		foreach ( $content as $item ) {
			$this->assertNotSame( 'resource_link', $item['type'], 'A pre-2025-06-18 client must not see a resource_link content type.' );
		}
	}

	public function test_resource_link_is_absent_for_a_tool_it_does_not_apply_to(): void {
		$data = $this->call_create_page( '2025-06-18' );
		$this->assertArrayHasKey( 'result', $data );

		// Re-run with a tool outside the small allowlist (build_tool_resource_link()) —
		// wp_site_info's result carries no 'id' this feature should ever link to.
		$headers = array( 'MCP-Protocol-Version' => '2025-06-18' );
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			$headers,
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'wp_site_info', 'arguments' => array() ),
			)
		);
		$data = ( new Mcpwp_REST_MCP() )->handle_mcp( $request )->get_data();

		$this->assertArrayHasKey( 'result', $data );
		foreach ( $data['result']['content'] as $item ) {
			$this->assertNotSame( 'resource_link', $item['type'] );
		}
	}
}
