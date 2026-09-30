<?php
/**
 * Adversarial review on #1007 — WARN-1, WARN-2, and the resources/list NIT.
 * (BLOCK-1's untooled-route gap is covered separately by
 * AllRegisteredRoutesCategoryTest; the discovery-allowlist route change to
 * DisabledToolCategoriesTest / ToolExecutionIsErrorTest covers the tools/call
 * exemption's "must still refuse a genuine site tool" half.)
 *
 * WARN-1, round 1 fix (redaction) then SUPERSEDED by an explicit round-2
 * owner decision: site context is public brand copy, already published
 * unauthenticated at /llms.txt (Mcpwp_AI_Presence::generate_llms_txt() reads
 * the same mcpwp_site_context option) — so /introspect and
 * /onboard?detail=full now include it for EVERY key, any role, even with
 * 'site' disabled site-wide. Mcpwp_Api_Auth::caller_may_read_site_context()
 * (the round-1 redaction gate) was removed entirely. Reading site context
 * through any surface — GET /site-context, wp_get_site_context,
 * mcpwp://site-context, mcpwp://brand — is on the discovery allowlist as a
 * READ-ONLY entry (get_discovery_allowlist_read_only_routes() /
 * is_discovery_read_bypass()): GET bypasses the category gate, but
 * wp_set_site_context / POST|PUT /site-context (the site owner's own edit,
 * a DIFFERENT tool sharing the same route) stays gated to 'site'.
 *
 * WARN-2: handle_tools_call()/get_all_tools()/filter_tools_by_key_role()
 * gated wp_site_info/wp_onboard/wp_introspect (and now wp_get_site_context)
 * exactly like any other 'site' tool — an author key's tools/list omitted
 * them and a tools/call answered scope_denied (or category_disabled with
 * 'site' disabled), even though the identical call was already reachable
 * directly over REST. Fixed by exempting the shared discovery-allowlist
 * tool names (get_discovery_allowlist_tool_names()) from both category
 * gates in both methods, plus filter_actions_by_caller_categories() so
 * onboard's own suggestions never point a restricted key at a tool it
 * cannot call (NIT-2, round 2: this now means wp_get_guide is filtered but
 * wp_get_site_context is NOT — it is universally callable).
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

	// ── WARN-1 (round 2 reversal): site context is public, always included ──

	/**
	 * @runInSeparateProcess
	 */
	public function test_introspect_includes_site_context_for_an_author_key(): void {
		$this->stubThemeLookup();
		$controller = $this->site_info_controller();
		$request    = $this->authenticate( $controller, '/mcpwp/v1/introspect', 'author' );

		// #1064: the site context text is in detail=full; the default
		// summary only says whether one is configured.
		$request->set_param( 'detail', 'full' );
		$data = $controller->get_introspect( $request )->get_data();

		$this->assertSame( 'Brand voice: warm and direct. Primary color: #1a2b3c.', $data['site_context'] ?? null );
		$this->assertArrayNotHasKey( 'site_context_note', $data );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_introspect_includes_site_context_for_a_key_with_site_category(): void {
		$this->stubThemeLookup();
		$controller = $this->site_info_controller();
		$request    = $this->authenticate( $controller, '/mcpwp/v1/introspect', 'designer' );

		// #1064: the site context text is in detail=full; the default
		// summary only says whether one is configured.
		$request->set_param( 'detail', 'full' );
		$data = $controller->get_introspect( $request )->get_data();

		$this->assertSame( 'Brand voice: warm and direct. Primary color: #1a2b3c.', $data['site_context'] ?? null );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_introspect_includes_site_context_even_when_site_category_is_disabled(): void {
		$this->stubThemeLookup();
		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );

		$controller = $this->site_info_controller();
		$request    = $this->authenticate( $controller, '/mcpwp/v1/introspect', 'admin', array( 'read', 'write', 'admin' ) );

		// #1064: the site context text is in detail=full; the default
		// summary only says whether one is configured.
		$request->set_param( 'detail', 'full' );
		$data = $controller->get_introspect( $request )->get_data();

		$this->assertSame( 'Brand voice: warm and direct. Primary color: #1a2b3c.', $data['site_context'] ?? null );
	}

	// ── WARN-1 (round 2 reversal): /onboard?detail=full ─────────────────────

	/**
	 * @runInSeparateProcess
	 */
	public function test_onboard_full_includes_site_context_for_an_author_key(): void {
		$this->stubThemeLookup();
		$controller = $this->site_info_controller();
		$request    = $this->authenticate( $controller, '/mcpwp/v1/onboard', 'author' );
		$request->set_param( 'detail', 'full' );

		$data = $controller->get_onboard( $request )->get_data();

		$this->assertSame( 'Brand voice: warm and direct. Primary color: #1a2b3c.', $data['site_context']['context'] ?? null );
		$this->assertArrayNotHasKey( 'note', $data['site_context'] );
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

	// ── WARN-2 / NIT-2: onboard's own suggestions ────────────────────────────

	/**
	 * wp_get_site_context is universally callable (round 2), so onboard
	 * keeps pointing every key at it; wp_get_guide is NOT on the discovery
	 * allowlist and is still genuinely gated to 'site', so it must not be
	 * suggested to a key that cannot call it.
	 *
	 * @runInSeparateProcess
	 */
	public function test_onboard_full_suggestions_filter_wp_get_guide_but_keep_site_context_for_an_author_key(): void {
		$this->stubThemeLookup();
		$controller = $this->site_info_controller();
		$request    = $this->authenticate( $controller, '/mcpwp/v1/onboard', 'author' );
		$request->set_param( 'detail', 'full' );

		$data = $controller->get_onboard( $request )->get_data();

		foreach ( array( 'quick_reference', 'suggested_first_tools', 'recommended_actions' ) as $section ) {
			$names = array_column( $data[ $section ], 'tool' );
			$this->assertNotContains( 'wp_get_guide', $names, "{$section} must not suggest wp_get_guide to an author key." );
			$this->assertNotContains( 'wp_set_site_context', $names, "{$section} must not suggest the WRITE tool to an author key." );
		}

		// Discovery tools (including wp_get_site_context, round 2) stay
		// suggested regardless of role — quick_reference names both explicitly.
		$quick_ref_names = array_column( $data['quick_reference'], 'tool' );
		$this->assertContains( 'wp_site_info', $quick_ref_names );
		$this->assertContains( 'wp_get_site_context', $quick_ref_names );
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

	// ── NIT-2: slim onboard's 'more' pointers ────────────────────────────────

	/**
	 * @runInSeparateProcess
	 */
	public function test_onboard_slim_more_filters_guide_but_keeps_site_context_pointer_for_an_author_key(): void {
		$this->stubThemeLookup();
		$controller = $this->site_info_controller();
		$request    = $this->authenticate( $controller, '/mcpwp/v1/onboard', 'author' );

		$data = $controller->get_onboard( $request )->get_data();

		$this->assertSame( 'slim', $data['detail'] );
		$this->assertArrayNotHasKey( 'guide', $data['more'], 'wp_get_guide is still gated to "site" — must not be pointed at.' );
		$this->assertArrayHasKey( 'site_context', $data['more'], 'wp_get_site_context is universally callable (round 2) — the pointer stays.' );
		$this->assertArrayHasKey( 'tools', $data['more'] );
		$this->assertArrayHasKey( 'full_briefing', $data['more'] );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_onboard_slim_more_keeps_guide_pointer_for_a_designer_key(): void {
		$this->stubThemeLookup();
		$controller = $this->site_info_controller();
		$request    = $this->authenticate( $controller, '/mcpwp/v1/onboard', 'designer' );

		$data = $controller->get_onboard( $request )->get_data();

		$this->assertArrayHasKey( 'guide', $data['more'] );
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

		// The role filter is under test, not the `core` default (#1064),
		// which leaves wp_introspect out for every key.
		$request->set_query_params( array( 'profile' => 'full' ) );

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

	public function test_resources_list_omits_design_references_but_keeps_site_context_for_an_author_key(): void {
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

		// 'site' category, still genuinely gated — author key does not carry
		// it, resources/read would refuse with -32003.
		$this->assertFalse( $this->uris_contain_route( $uris, '/mcpwp/v1/design-references' ) );
		// Discovery / content / round-2 public site-context — still advertised.
		$this->assertTrue( $this->uris_contain_route( $uris, '/mcpwp/v1/site-info' ) );
		$this->assertTrue( $this->uris_contain_route( $uris, '/mcpwp/v1/pages' ) );
		$this->assertTrue( $this->uris_contain_route( $uris, '/mcpwp/v1/site-context' ), 'site-context is public (round 2) — must stay advertised even to an author key.' );
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
