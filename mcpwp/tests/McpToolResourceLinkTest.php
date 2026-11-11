<?php
/**
 * #913 — a create/update-page-or-post tool result may carry a `resource_link`
 * content item pointing at the freshly written mcpwp://page/{id}, so an agent
 * does not need a second tool call just to get a resource_link back. Gated to
 * the protocol version that defines `resource_link` (2025-06-18+, the same
 * boundary protocol_version_has_structured_output() already tracks for
 * structuredContent) — the text block is unaffected either way.
 *
 * Same request pattern as McpProtocolVersionTest: no API key is presented for
 * the plain (non-idempotent) tests, so the key record stays null
 * (unrestricted), and the stubbed rest_do_request() answers every internal
 * dispatch with the scripted mcpwp_test_rest_response_override — exactly what
 * a real wp_create_page dispatch to Mcpwp_Pages::create_page() would hand
 * back. The idempotency/replay tests (#1015 adversarial review round 1, W2)
 * authenticate with a real scoped key instead, following
 * IdempotentToolCallTest's own request pattern, since replay requires a real
 * principal to pin the stored result to.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/helpers/trait-mcpwp-request-fixture-state.php';

final class McpToolResourceLinkTest extends TestCase {

	use Mcpwp_Test_Request_Fixture_State;

	protected function setUp(): void {
		$this->capture_request_fixture_state();
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_filters']           = array();
		// #1114: the old-version test checks every content item. Always
		// include the Elementor next-step hint instead of inheriting whether
		// an earlier test defined the process-wide ELEMENTOR_VERSION.
		add_filter( 'mcpwp_site_capabilities', static function ( $caps ) {
			$caps['elementor'] = true;
			return $caps;
		} );
		Mcpwp_Integration::clear_cache();
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

		// build_tool_resource_link() now verifies the REAL resulting
		// post_type via get_post() before linking (#1015 review round 1,
		// W3) — the stubbed rest_do_request() never actually runs
		// Mcpwp_Pages::create_post(), so the fixture backing that check is
		// seeded here, independent of the scripted response above.
		$GLOBALS['_mcpwp_test_posts'][5501] = new WP_Post(
			array(
				'ID'        => 5501,
				'post_type' => 'page',
			)
		);
	}

	protected function tearDown(): void {
		$this->restore_request_fixture_state();
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
		return $this->fixture_request( static function () use ( $request ) {
			return ( new Mcpwp_REST_MCP() )->handle_mcp( $request )->get_data();
		} );
	}

	private function resource_links( array $content ): array {
		return array_values( array_filter( $content, static fn( $item ) => 'resource_link' === $item['type'] ) );
	}

	public function test_resource_link_is_present_under_2025_06_18(): void {
		$data = $this->call_create_page( '2025-06-18' );

		$this->assertArrayHasKey( 'result', $data );
		$content = $data['result']['content'];

		$links = $this->resource_links( $content );
		$this->assertCount( 1, $links, 'Exactly one resource_link content item is expected.' );
		$this->assertSame( 'mcpwp://page/5501', $links[0]['uri'] );
		$this->assertSame( 'Spring Sale Landing', $links[0]['name'] );
		$this->assertSame( 'application/json', $links[0]['mimeType'] );

		// The original text block must still be present and first —
		// resource_link (and any next-step hint text, #912) is additive,
		// never a replacement.
		$this->assertSame( 'text', $content[0]['type'] );
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
		$data = $this->fixture_request( static function () use ( $request ) {
			return ( new Mcpwp_REST_MCP() )->handle_mcp( $request )->get_data();
		} );

		$this->assertArrayHasKey( 'result', $data );
		foreach ( $data['result']['content'] as $item ) {
			$this->assertNotSame( 'resource_link', $item['type'] );
		}
	}

	/**
	 * #1015 adversarial review round 1, W3: wp_create_post accepts a
	 * caller-controlled post_type — wp_block, elementor_library, or any
	 * other writable type — and the formatted response carries no post_type
	 * field to check inline. mcpwp://page/{id} resolves only page/post, so
	 * linking anything else is a dangling resource_link that 404s the
	 * moment a client reads it. build_tool_resource_link() must verify the
	 * REAL resulting type via get_post() before linking.
	 */
	public function test_resource_link_is_absent_for_a_non_page_non_post_result(): void {
		$GLOBALS['_mcpwp_test_posts'][9001] = new WP_Post(
			array(
				'ID'        => 9001,
				'post_type' => 'wp_block',
			)
		);
		$GLOBALS['mcpwp_test_rest_response_override'] = array(
			'id'     => 9001,
			'title'  => 'Reusable Block',
			'status' => 'publish',
		);

		$headers = array( 'MCP-Protocol-Version' => '2025-06-18' );
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			$headers,
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'wp_create_post',
					'arguments' => array( 'title' => 'Reusable Block', 'post_type' => 'wp_block' ),
				),
			)
		);
		$data = $this->fixture_request( static function () use ( $request ) {
			return ( new Mcpwp_REST_MCP() )->handle_mcp( $request )->get_data();
		} );

		$this->assertArrayHasKey( 'result', $data );
		$this->assertEmpty( $this->resource_links( $data['result']['content'] ), 'wp_block is not page/post — no resource_link should be emitted.' );
	}

	public function test_resource_link_is_present_for_a_real_post_result(): void {
		$GLOBALS['_mcpwp_test_posts'][9002] = new WP_Post(
			array(
				'ID'        => 9002,
				'post_type' => 'post',
			)
		);
		$GLOBALS['mcpwp_test_rest_response_override'] = array(
			'id'     => 9002,
			'title'  => 'A Real Post',
			'status' => 'draft',
		);

		$headers = array( 'MCP-Protocol-Version' => '2025-06-18' );
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			$headers,
			array(
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'wp_create_post',
					'arguments' => array( 'title' => 'A Real Post' ),
				),
			)
		);
		$data  = $this->fixture_request( static function () use ( $request ) {
			return ( new Mcpwp_REST_MCP() )->handle_mcp( $request )->get_data();
		} );
		$links = $this->resource_links( $data['result']['content'] );

		$this->assertCount( 1, $links );
		$this->assertSame( 'mcpwp://page/9002', $links[0]['uri'] );
	}

	// ── Idempotent replay across a protocol-version change (W2) ────────────

	/**
	 * #1015 adversarial review round 1, W2: resource_link was added at the
	 * insertion site gated on protocol_version_has_structured_output(), but
	 * an idempotent REPLAY reaches its content through
	 * conform_tool_call_result() same as a fresh dispatch — and that gate
	 * lived only at insertion, never re-checked on replay. A tools/call
	 * stored under 2025-06-18 and later replayed to a client that negotiated
	 * 2025-03-26 (or no header, which the spec treats as 2025-03-26) would
	 * still carry the resource_link from the original dispatch. Fixed by
	 * moving the enforcement into apply_output_schema() — the one place
	 * every tools/call answer passes through, fresh or replayed alike — so
	 * it is checked against THIS request's negotiated version every time,
	 * not the one negotiated when the result was first produced.
	 */
	public function test_resource_link_does_not_survive_replay_to_an_older_protocol_version(): void {
		$controller = new Mcpwp_REST_MCP();
		$created    = $this->fixture_api_key( $controller, 'k', array( 'admin' ) );

		$fresh_request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(
				'X-API-Key'            => $created['key'],
				'MCP-Protocol-Version' => '2025-06-18',
			),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'wp_create_page',
					'arguments' => array( 'title' => 'Spring Sale Landing' ),
					'_meta'     => array( 'request_id' => 'resource-link-replay-1' ),
				),
			)
		);
		$this->assertTrue( $this->fixture_request( static function () use ( $controller, $fresh_request ) {
			return $controller->verify_api_key( $fresh_request );
		} ) );
		$fresh = $this->fixture_request( static function () use ( $controller, $fresh_request ) {
			return $controller->handle_mcp( $fresh_request )->get_data();
		} );

		$this->assertArrayHasKey( 'result', $fresh );
		$this->assertNotEmpty( $this->resource_links( $fresh['result']['content'] ), 'Test premise: the fresh dispatch under 2025-06-18 carries a resource_link.' );
		$this->assertSame( 1, count( $GLOBALS['mcpwp_test_dispatched_routes'] ), 'Test premise: the fresh call dispatched once.' );

		// Same principal, same request_id, same arguments — a replay, now
		// negotiating an OLDER protocol version.
		$controller2  = new Mcpwp_REST_MCP();
		$replay_request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(
				'X-API-Key'            => $created['key'],
				'MCP-Protocol-Version' => '2025-03-26',
			),
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'wp_create_page',
					'arguments' => array( 'title' => 'Spring Sale Landing' ),
					'_meta'     => array( 'request_id' => 'resource-link-replay-1' ),
				),
			)
		);
		$this->assertTrue( $this->fixture_request( static function () use ( $controller2, $replay_request ) {
			return $controller2->verify_api_key( $replay_request );
		} ) );
		$replayed = $this->fixture_request( static function () use ( $controller2, $replay_request ) {
			return $controller2->handle_mcp( $replay_request )->get_data();
		} );

		$this->assertArrayHasKey( 'result', $replayed );
		$this->assertTrue( $replayed['result']['_meta']['replayed'], 'Test premise: this is a replay, not a second dispatch.' );
		$this->assertSame( 1, count( $GLOBALS['mcpwp_test_dispatched_routes'] ), 'A replay must not dispatch again.' );
		$this->assertEmpty(
			$this->resource_links( $replayed['result']['content'] ),
			'A result stored under 2025-06-18 must not carry resource_link when replayed to a client that negotiated 2025-03-26.'
		);
	}
}
