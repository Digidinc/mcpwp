<?php
/**
 * Coverage for #1073: plugin follow-ups from the agent-ready docs work.
 *
 *   1. wp_introspect reports the real `meta` category for mcpwp_find_tools and
 *      mcpwp_call (never an empty one). `meta` stays reserved: no role, no
 *      grantable category list and no key-setup choice offers it.
 *   2. wp_create_api_key's role text and category enum are generated from the
 *      auth trait's own role => category map, so they cannot drift from it.
 *   3. Guides, the guide topic list and the MCP initialize instructions link
 *      to docs.mcpwp.net and its llms.txt.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class Docs1073FollowupsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
		set_transient(
			'mcpwp_capabilities_cache',
			array( 'gutenberg' => true, 'elementor' => true, 'elementor_layout_mode' => 'container', 'yoast' => true ),
			3600
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_filters'] = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
	}

	private function create_api_key_tool(): array {
		foreach ( ( new Mcpwp_MCP_Free_Tools() )->get_tools() as $tool ) {
			if ( 'wp_create_api_key' === $tool['name'] ) {
				return $tool;
			}
		}
		$this->fail( 'wp_create_api_key is not registered.' );
	}

	private function initialize_text( ?string $key ): string {
		$controller = new Mcpwp_REST_MCP();
		$headers    = array( 'MCP-Protocol-Version' => '2025-11-25' );
		if ( null !== $key ) {
			$headers['X-API-Key'] = $key;
		}
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			$headers,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array( 'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 't', 'version' => '1' ) ),
			)
		);
		if ( null !== $key ) {
			$this->assertTrue( true === $controller->verify_api_key( $request ), 'Test premise: the key authenticates.' );
		}
		$data = json_decode( wp_json_encode( $controller->handle_mcp( $request )->get_data() ), true );
		return $data['result']['instructions'];
	}

	// ── Item 1 ──────────────────────────────────────────────────────────

	public function test_introspection_reports_the_meta_category_for_both_meta_tools(): void {
		$controller = new Mcpwp_REST_MCP();
		$method     = new ReflectionMethod( $controller, 'get_registry_for_tool' );
		$method->setAccessible( true );

		foreach ( array( 'mcpwp_find_tools', 'mcpwp_call' ) as $name ) {
			$annotations = $method->invoke( $controller, $name )->get_tool_annotations( $name );
			$this->assertSame( 'meta', $annotations['category'], "$name must introspect with a real category." );
		}
	}

	public function test_meta_stays_reserved_and_is_not_grantable(): void {
		$this->assertSame( array( 'meta' ), Mcpwp_REST_API::reserved_tool_category_slugs() );
		$this->assertArrayNotHasKey( 'meta', Mcpwp_REST_API::get_all_tool_category_labels() );
		foreach ( Mcpwp_REST_API::get_role_definitions() as $slug => $definition ) {
			$this->assertNotContains( 'meta', $definition['categories'], "Role $slug must not hold meta." );
		}
		$tool = $this->create_api_key_tool();
		$this->assertNotContains( 'meta', $tool['inputSchema']['properties']['tool_categories']['items']['enum'] );
		$this->assertStringNotContainsString( 'meta', $tool['inputSchema']['properties']['tool_categories']['description'] );
	}

	// ── Item 2 ──────────────────────────────────────────────────────────

	public function test_create_api_key_role_enum_is_the_role_map(): void {
		$props = $this->create_api_key_tool()['inputSchema']['properties'];
		$this->assertSame( array_keys( Mcpwp_REST_API::get_role_definitions() ), $props['role']['enum'] );
	}

	public function test_create_api_key_role_text_states_every_role_category(): void {
		$text = $this->create_api_key_tool()['inputSchema']['properties']['role']['description'];
		foreach ( Mcpwp_REST_API::get_role_definitions() as $slug => $definition ) {
			$this->assertStringContainsString( $slug, $text );
			foreach ( $definition['categories'] as $category ) {
				// Delimited, so "elementor" cannot be satisfied by "elementor-build".
				$this->assertMatchesRegularExpression( '/[(, ]' . preg_quote( $category, '/' ) . '[,)]/', $text, "Role $slug: category $category missing from the role text." );
			}
		}
		$this->assertMatchesRegularExpression( '/designer \([^)]*elementor-theme[^)]*\)/', $text );
		$this->assertMatchesRegularExpression( '/editor \([^)]*elementor-info[^)]*\)/', $text );
	}

	public function test_create_api_key_category_enum_is_the_grantable_category_list(): void {
		$props = $this->create_api_key_tool()['inputSchema']['properties']['tool_categories'];
		$slugs = array_keys( Mcpwp_REST_API::get_all_tool_category_labels() );
		$this->assertSame( $slugs, $props['items']['enum'] );
		foreach ( $slugs as $slug ) {
			$this->assertStringContainsString( $slug, $props['description'] );
		}
	}

	public function test_every_enum_category_is_accepted_by_key_creation(): void {
		$controller = new Mcpwp_REST_MCP();
		foreach ( $this->create_api_key_tool()['inputSchema']['properties']['tool_categories']['items']['enum'] as $slug ) {
			$created = $controller->create_scoped_api_key( 'k ' . $slug, array( 'read' ), 'custom', array( $slug ) );
			$this->assertArrayNotHasKey( 'error', $created, "The enum offers $slug but creation refuses it." );
		}
	}

	// ── Item 3 ──────────────────────────────────────────────────────────

	public function test_guide_topics_and_guides_link_to_their_docs_page(): void {
		$topics = array_column( Mcpwp_Guides::get_topics(), null, 'topic' );
		$this->assertNotEmpty( $topics );
		foreach ( $topics as $slug => $topic ) {
			if ( isset( Mcpwp_Guides::DOCS_PAGES[ $slug ] ) ) {
				$this->assertSame( 'https://docs.mcpwp.net' . Mcpwp_Guides::DOCS_PAGES[ $slug ], $topic['docs_url'] );
			} else {
				$this->assertArrayNotHasKey( 'docs_url', $topic, "$slug has no docs page, so it carries no link." );
			}
		}
		$this->assertSame( 'https://docs.mcpwp.net/get-started/first-connection.md', $topics['onboarding']['docs_url'] );

		$guide = Mcpwp_Guides::get_guide( 'onboarding' );
		$this->assertSame( 'https://docs.mcpwp.net/get-started/first-connection.md', $guide['docs_url'] );
		$this->assertSame( 'onboarding', $guide['topic'] );
	}

	public function test_guide_tool_description_mentions_the_docs_link(): void {
		foreach ( ( new Mcpwp_MCP_Free_Tools() )->get_tools() as $tool ) {
			if ( 'wp_get_guide' === $tool['name'] ) {
				$this->assertStringContainsString( 'docs_url', $tool['description'] );
				return;
			}
		}
		$this->fail( 'wp_get_guide is not registered.' );
	}

	public function test_initialize_instructions_link_to_the_docs_and_llms_txt(): void {
		$admin = $this->initialize_text( null );
		$this->assertStringContainsString( 'https://docs.mcpwp.net/llms.txt', $admin );

		// A restricted key keeps the section: it names no tool.
		$key        = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'seo-only', array( 'read' ), 'custom', array( 'seo' ) )['key'];
		$restricted = $this->initialize_text( $key );
		$this->assertStringContainsString( 'https://docs.mcpwp.net/llms.txt', $restricted );
		$this->assertStringContainsString( '## Documentation', $restricted );
	}
}
