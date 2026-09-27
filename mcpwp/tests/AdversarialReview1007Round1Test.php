<?php
/**
 * Adversarial review round 1 on #1007 — WARN-1, WARN-2, and the resources/list
 * NIT. (BLOCK-1's untooled-route gap is covered separately by
 * AllRegisteredRoutesCategoryTest; the discovery-allowlist route change to
 * DisabledToolCategoriesTest / ToolExecutionIsErrorTest covers the tools/call
 * exemption's "must still refuse a genuine site tool" half.)
 *
 * WARN-1: /introspect and /onboard?detail=full are on the discovery
 * allowlist (reachable by every key, any role, even with 'site' disabled),
 * but before this fix they both embedded the FULL mcpwp_site_context text
 * inline — the exact data /site-context and mcpwp://site-context already
 * refuse to a key without 'site'. Fixed by
 * Mcpwp_Api_Auth::caller_may_read_site_context(), consulted at both
 * embedding sites.
 *
 * WARN-2: handle_tools_call()/get_all_tools()/filter_tools_by_key_role()
 * gated wp_site_info/wp_onboard/wp_introspect exactly like any other 'site'
 * tool — an author key's tools/list omitted them and a tools/call answered
 * scope_denied (or category_disabled with 'site' disabled), even though the
 * identical call was already reachable directly over REST. Fixed by
 * exempting the shared discovery-allowlist tool names
 * (get_discovery_allowlist_tool_names()) from both category gates in both
 * methods, plus filter_actions_by_caller_categories() so onboard's own
 * suggestions never point a restricted key at a tool it cannot call.
 *
 * NIT: resources/list advertised every resource regardless of the caller's
 * categories; resources/read (the real gate) refused some of them anyway.
 * Fixed by resource_definition_is_readable_by_caller().
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-media.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-design-references.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-info.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

final class AdversarialReview1007Round1Test extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_is_admin']     = false;
		$GLOBALS['mcpwp_test_filters']      = array();

		update_option( 'mcpwp_site_context', 'Brand voice: warm and direct. Primary color: #1a2b3c.' );
	}

	protected function tearDown(): void {
		if ( class_exists( 'Mcpwp_REST_Batch' ) ) {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
		}
		update_option( 'mcpwp_disabled_tool_categories', array() );
	}

	private function site_info_controller(): Mcpwp_REST_Site_Info {
		return new Mcpwp_REST_Site_Info();
	}

	/**
	 * get_onboard()/get_introspect() read site info through Mcpwp_Core. The
	 * shared bootstrap has no wp_get_theme(), wp_timezone_string(),
	 * get_locale(), is_plugin_active() or wp_count_attachments(); declared
	 * here, inside @runInSeparateProcess tests only, so they cannot leak
	 * into the shared run (same approach as
	 * ResponseProjectionTest::stubThemeLookup()).
	 */
	private function stubThemeLookup(): void {
		if ( ! function_exists( 'wp_timezone_string' ) ) {
			function wp_timezone_string() {
				return 'UTC';
			}
		}
		if ( ! function_exists( 'get_locale' ) ) {
			function get_locale() {
				return 'en_US';
			}
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			function is_plugin_active( $plugin ) {
				return false;
			}
		}
		if ( ! function_exists( 'wp_get_theme' ) ) {
			function wp_get_theme() {
				return new class() {
					public function get( $header ) {
						return 'Stub Theme';
					}
				};
			}
		}
		if ( ! function_exists( 'wp_count_attachments' ) ) {
			function wp_count_attachments() {
				return (object) array( 'image/jpeg' => 0 );
			}
		}
		if ( ! function_exists( 'wp_count_terms' ) ) {
			function wp_count_terms( $taxonomy ) {
				return 0;
			}
		}
	}

	/** Authenticates $controller as the given role, populating current_api_key_record. */
	private function authenticate( Mcpwp_REST_Site_Info $controller, string $route, string $role, array $scopes = array( 'read', 'write' ) ): WP_REST_Request {
		$created = $controller->create_scoped_api_key( $role . ' key', $scopes, $role );
		$request = new WP_REST_Request( 'GET', $route, array(), array( 'X-API-Key' => $created['key'] ) );
		$verdict = $controller->verify_api_key( $request );
		$this->assertTrue( is_bool( $verdict ) && $verdict, "Test premise: {$role} key authenticates on {$route}." );
		return $request;
	}

	// ── WARN-1: /introspect ─────────────────────────────────────────────────

	/**
	 * @runInSeparateProcess
	 */
	public function test_introspect_omits_site_context_for_a_key_without_site_category(): void {
		$this->stubThemeLookup();
		$controller = $this->site_info_controller();
		$request    = $this->authenticate( $controller, '/mcpwp/v1/introspect', 'author' );

		$data = $controller->get_introspect( $request )->get_data();

		$this->assertArrayNotHasKey( 'site_context', $data );
		$this->assertArrayHasKey( 'site_context_note', $data );
		$this->assertStringContainsString( 'site', $data['site_context_note'] );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_introspect_includes_site_context_for_a_key_with_site_category(): void {
		$this->stubThemeLookup();
		$controller = $this->site_info_controller();
		$request    = $this->authenticate( $controller, '/mcpwp/v1/introspect', 'designer' );

		$data = $controller->get_introspect( $request )->get_data();

		$this->assertSame( 'Brand voice: warm and direct. Primary color: #1a2b3c.', $data['site_context'] ?? null );
		$this->assertArrayNotHasKey( 'site_context_note', $data );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_introspect_omits_site_context_when_site_category_is_disabled_even_for_admin(): void {
		$this->stubThemeLookup();
		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );

		$controller = $this->site_info_controller();
		$request    = $this->authenticate( $controller, '/mcpwp/v1/introspect', 'admin', array( 'read', 'write', 'admin' ) );

		$data = $controller->get_introspect( $request )->get_data();

		$this->assertArrayNotHasKey( 'site_context', $data );
		$this->assertArrayHasKey( 'site_context_note', $data );
	}

	// ── WARN-1: /onboard?detail=full ────────────────────────────────────────

	/**
	 * @runInSeparateProcess
	 */
	public function test_onboard_full_omits_site_context_for_a_key_without_site_category(): void {
		$this->stubThemeLookup();
		$controller = $this->site_info_controller();
		$request    = $this->authenticate( $controller, '/mcpwp/v1/onboard', 'author' );
		$request->set_param( 'detail', 'full' );

		$data = $controller->get_onboard( $request )->get_data();

		$this->assertTrue( $data['site_context']['configured'] );
		$this->assertArrayNotHasKey( 'context', $data['site_context'] );
		$this->assertArrayHasKey( 'note', $data['site_context'] );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_onboard_full_includes_site_context_for_a_key_with_site_category(): void {
		$this->stubThemeLookup();
		$controller = $this->site_info_controller();
		$request    = $this->authenticate( $controller, '/mcpwp/v1/onboard', 'designer' );
		$request->set_param( 'detail', 'full' );

		$data = $controller->get_onboard( $request )->get_data();

		$this->assertSame( 'Brand voice: warm and direct. Primary color: #1a2b3c.', $data['site_context']['context'] ?? null );
	}

	// ── WARN-2: onboard's own suggestions must not point at an uncallable tool ──

	/**
	 * @runInSeparateProcess
	 */
	public function test_onboard_full_suggestions_exclude_site_category_tools_for_an_author_key(): void {
		$this->stubThemeLookup();
		$controller = $this->site_info_controller();
		$request    = $this->authenticate( $controller, '/mcpwp/v1/onboard', 'author' );
		$request->set_param( 'detail', 'full' );

		$data = $controller->get_onboard( $request )->get_data();

		foreach ( array( 'quick_reference', 'suggested_first_tools', 'recommended_actions' ) as $section ) {
			$names = array_column( $data[ $section ], 'tool' );
			$this->assertNotContains( 'wp_get_site_context', $names, "{$section} must not suggest a 'site' tool to an author key." );
			$this->assertNotContains( 'wp_set_site_context', $names, "{$section} must not suggest a 'site' tool to an author key." );
		}

		// Discovery tools stay suggested regardless of role — quick_reference
		// names wp_site_info explicitly.
		$this->assertContains( 'wp_site_info', array_column( $data['quick_reference'], 'tool' ) );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_onboard_full_suggestions_keep_site_category_tools_for_a_designer_key(): void {
		$this->stubThemeLookup();
		$controller = $this->site_info_controller();
		$request    = $this->authenticate( $controller, '/mcpwp/v1/onboard', 'designer' );
		$request->set_param( 'detail', 'full' );

		$data  = $controller->get_onboard( $request )->get_data();
		$names = array_column( $data['quick_reference'], 'tool' );

		$this->assertContains( 'wp_get_site_context', $names );
	}

	// ── WARN-2: tools/list and tools/call for the discovery tools themselves ──

	public function test_tools_list_still_advertises_discovery_tools_to_an_author_key(): void {
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-mcp.php';

		$controller = new Mcpwp_REST_MCP();
		$created    = $controller->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $created['key'] ),
			array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
		$data  = $controller->handle_mcp( $request )->get_data();
		$names = array_column( $data['result']['tools'], 'name' );

		$this->assertContains( 'wp_site_info', $names );
		$this->assertContains( 'wp_onboard', $names );
		$this->assertContains( 'wp_introspect', $names );
		// A genuine 'site' tool the author role does NOT carry stays hidden —
		// proof this is a narrow exemption, not a blanket unrestriction.
		$this->assertNotContains( 'wp_list_menus', $names );
	}

	public function test_tools_list_still_advertises_discovery_tools_when_site_category_is_disabled(): void {
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-mcp.php';
		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );

		$controller = new Mcpwp_REST_MCP();
		$created    = $controller->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $created['key'] ),
			array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
		$data  = $controller->handle_mcp( $request )->get_data();
		$names = array_column( $data['result']['tools'], 'name' );

		$this->assertContains( 'wp_site_info', $names );
		$this->assertNotContains( 'wp_list_menus', $names, 'A disabled category must still hide a genuine site tool.' );
	}

	// ── NIT: resources/list must agree with resources/read ──────────────────

	/** True when some URI in $uris carries $route (mcp_resource_uri() builds real rest_url() links, not mcpwp:// shorthand). */
	private function uris_contain_route( array $uris, string $route ): bool {
		foreach ( $uris as $uri ) {
			if ( false !== strpos( $uri, $route ) ) {
				return true;
			}
		}
		return false;
	}

	public function test_resources_list_omits_resources_an_author_key_cannot_read(): void {
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-mcp.php';

		$controller = new Mcpwp_REST_MCP();
		$created    = $controller->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $created['key'] ),
			array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list' )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
		$data = $controller->handle_mcp( $request )->get_data();
		$uris = array_column( $data['result']['resources'], 'uri' );

		// 'site' category — author key does not carry it, resources/read
		// would refuse both with -32003.
		$this->assertFalse( $this->uris_contain_route( $uris, '/mcpwp/v1/site-context' ) );
		$this->assertFalse( $this->uris_contain_route( $uris, '/mcpwp/v1/design-references' ) );
		// Discovery / content — still advertised.
		$this->assertTrue( $this->uris_contain_route( $uris, '/mcpwp/v1/site-info' ) );
		$this->assertTrue( $this->uris_contain_route( $uris, '/mcpwp/v1/pages' ) );
	}

	public function test_resources_list_shows_every_resource_to_an_admin_key(): void {
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-mcp.php';

		$controller = new Mcpwp_REST_MCP();
		$created    = $controller->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $created['key'] ),
			array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list' )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
		$data = $controller->handle_mcp( $request )->get_data();
		$uris = array_column( $data['result']['resources'], 'uri' );

		$this->assertTrue( $this->uris_contain_route( $uris, '/mcpwp/v1/site-context' ) );
		$this->assertTrue( $this->uris_contain_route( $uris, '/mcpwp/v1/design-references' ) );
	}
}
