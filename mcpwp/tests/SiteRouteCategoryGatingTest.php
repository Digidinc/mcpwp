<?php
/**
 * Regression tests — #1007.
 *
 * Before this fix, every route dispatched by a 'site'-category MCP tool
 * (/menus, /options, /memory, /site-blueprints, /widgets, /sidebars,
 * /custom-css, ... — 64 routes total, see RouteCategoryToolParityTest's
 * former SITE_EXEMPT list) resolved to '' in get_category_for_route(), so
 * neither the per-key role restriction (check_key_category_restriction_for_route(),
 * #874) nor the site-wide category toggle (check_disabled_category_for_route())
 * ever applied to them over direct REST, /batch, or resources/read — only a
 * call through /mcp tools/call was gated, via the independent tool-name-keyed
 * check in Mcpwp_REST_MCP::handle_tools_call(). An "author" key (categories:
 * content, media, taxonomy — no 'site') could read/write site settings,
 * memory, and menus directly, something its role explicitly does not permit
 * through /mcp.
 *
 * Owner decision (2026-09-27, closing #1007): map all of them to 'site' in
 * get_route_category_map(), and carve out a small, explicit, permanently
 * ungated discovery allowlist — Mcpwp_Api_Auth::get_discovery_allowlist_routes()
 * (/site-info, /onboard, /introspect) — so every key, of any role, and even
 * while the site administrator has disabled the 'site' category outright,
 * can still make first contact with a site. This is a breaking change for
 * existing Author and Editor keys, which do not carry the 'site' category
 * (see Mcpwp_Api_Auth::get_role_definitions()) and therefore lose direct and
 * /batch access to every route in this list — they keep the discovery
 * allowlist.
 *
 * A confound worth naming up front: /menus and /options are ALSO
 * blanket-admin-SCOPE routes (get_required_scope_for_request()'s
 * $admin_routes table requires 'admin' scope for every method, including
 * GET) — and create_scoped_api_key() caps a non-admin-role key's scope
 * ceiling to array('read','write') no matter what scopes are requested
 * (see its "Cap scopes to the role's maximum ceiling" comment). So no
 * author/designer/custom-role key can EVER reach /menus or /options,
 * regardless of category — the scope tier alone already fully protects
 * them from any non-admin-role key. The CATEGORY gate #1007 adds is
 * real for those two routes only against the SITE-WIDE toggle (an
 * admin-role key, unrestricted by role, was still not blocked when an
 * admin disabled 'site' — see test_disabling_site_category_blocks_admin_from_newly_gated_routes()).
 * /memory, /site-blueprints, /widgets, /sidebars and /custom-css (GET) carry
 * no such scope restriction, so they are this file's proof that the
 * per-key ROLE restriction itself now reaches this whole route group.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

/**
 * Harness exposing the auth trait. Everything used here is already public
 * except get_category_for_route() and get_discovery_allowlist_routes(),
 * widened to public via trait conflict resolution (same technique
 * RouteCategoryToolParityTest's harness uses with its own wrapper method).
 */
class Mcpwp_Site_Route_Gating_Harness {
	use Mcpwp_Api_Auth {
		get_category_for_route as public;
		get_discovery_allowlist_routes as public;
	}
}

final class SiteRouteCategoryGatingTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_is_admin']     = false;
		$GLOBALS['mcpwp_test_filters']      = array();
	}

	protected function tearDown(): void {
		if ( class_exists( 'Mcpwp_REST_Batch' ) ) {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
		}
		update_option( 'mcpwp_disabled_tool_categories', array() );
	}

	private function harness(): Mcpwp_Site_Route_Gating_Harness {
		return new Mcpwp_Site_Route_Gating_Harness();
	}

	private function request_with_key( string $key, string $method, string $route ): WP_REST_Request {
		return new WP_REST_Request( $method, $route, array(), array( 'X-API-Key' => $key ) );
	}

	private function assert_forbidden_category( $result, string $message ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $message );
		$this->assertSame( 'forbidden_category', $result->get_error_code(), $message );
		$this->assertSame( 403, $result->get_error_data()['status'], $message );
	}

	/** Routes #1007 newly gates to 'site', with no other scope confound (GET is read-scope). */
	private const CATEGORY_ONLY_ROUTES = array(
		'/mcpwp/v1/memory',
		'/mcpwp/v1/site-blueprints',
		'/mcpwp/v1/widgets/1',
		'/mcpwp/v1/sidebars',
		'/mcpwp/v1/custom-css',
	);

	/** The routes #1007 newly gates to 'site', a representative sample. */
	public static function newly_gated_routes(): array {
		return array(
			'menus'           => array( '/mcpwp/v1/menus' ),
			'options'         => array( '/mcpwp/v1/options' ),
			'memory'          => array( '/mcpwp/v1/memory' ),
			'site-blueprints' => array( '/mcpwp/v1/site-blueprints' ),
			'widgets'         => array( '/mcpwp/v1/widgets/1' ),
			'sidebars'        => array( '/mcpwp/v1/sidebars' ),
			'custom-css'      => array( '/mcpwp/v1/custom-css' ),
		);
	}

	// ── The route classifier itself ─────────────────────────────────────────

	/**
	 * @dataProvider newly_gated_routes
	 */
	public function test_newly_gated_route_resolves_to_site_category( string $route ): void {
		$auth = $this->harness();
		$this->assertSame( 'site', $auth->get_category_for_route( $route ), $route );
	}

	public function test_discovery_allowlist_routes_stay_ungated_at_the_classifier(): void {
		$auth = $this->harness();
		foreach ( $auth->get_discovery_allowlist_routes() as $route ) {
			$this->assertSame( '', $auth->get_category_for_route( '/mcpwp/v1' . $route ), $route );
		}
	}

	public function test_discovery_allowlist_is_exactly_the_three_pinned_routes(): void {
		$this->assertSame(
			array( '/site-info', '/onboard', '/introspect' ),
			$this->harness()->get_discovery_allowlist_routes()
		);
	}

	public function test_mcp_endpoint_itself_stays_uncategorised(): void {
		// The MCP handshake (initialize, tools/list, ping, notifications/*)
		// never dispatches through a route at all — process_single_request()
		// answers those methods directly — so this classifier never sees them.
		// tools/call is gated separately, per tool, inside handle_tools_call().
		$auth = $this->harness();
		$this->assertSame( '', $auth->get_category_for_route( '/mcpwp/v1/mcp' ) );
	}

	// ── Per-key role restriction (#874 chokepoint) now covers these routes ──

	public function test_author_key_is_blocked_from_category_only_routes_directly(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );

		foreach ( self::CATEGORY_ONLY_ROUTES as $route ) {
			$result = $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', $route ) );
			$this->assert_forbidden_category( $result, "author key on {$route}" );
		}
	}

	public function test_author_key_is_blocked_from_memory_via_batch_sub_request(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );

		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		$result                                 = $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', '/mcpwp/v1/memory' ) );
		Mcpwp_REST_Batch::$is_batch_sub_request = false;

		$this->assert_forbidden_category( $result, 'Wrapping the same call in a /batch envelope must not launder an author key past its role' );
	}

	/**
	 * /menus and /options structurally cannot be reached by any non-admin-role
	 * key at all — the scope-tier gate (get_required_scope_for_request()) bites
	 * first, and create_scoped_api_key() will not issue a non-admin role the
	 * 'admin' scope needed to get past it. Still refused, just not for the
	 * category reason — documented so a future change to either gate does not
	 * silently make this pass for the wrong one.
	 */
	public function test_author_key_is_refused_on_menus_and_options_by_scope_before_category(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'author', array( 'read', 'write', 'admin' ), 'author' );

		$this->assertSame( array( 'read', 'write' ), $created['scopes'], 'Ceiling: a non-admin role never gets admin scope, even on request.' );

		foreach ( array( '/mcpwp/v1/menus', '/mcpwp/v1/options' ) as $route ) {
			$result = $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', $route ) );
			$this->assertInstanceOf( WP_Error::class, $result, $route );
			$this->assertSame( 'insufficient_scope', $result->get_error_code(), $route );
		}
	}

	public function test_designer_key_is_allowed_on_category_only_routes(): void {
		// Designer's categories include 'site' (get_role_definitions()).
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'designer', array( 'read', 'write' ), 'designer' );

		foreach ( self::CATEGORY_ONLY_ROUTES as $route ) {
			$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', $route ) ), $route );
		}
	}

	public function test_admin_key_is_allowed_on_every_newly_gated_route(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );

		foreach ( array( '/mcpwp/v1/menus', '/mcpwp/v1/options', '/mcpwp/v1/memory' ) as $route ) {
			$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', $route ) ), $route );
		}
	}

	public function test_custom_role_key_with_site_category_is_allowed(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'custom-key', array( 'read', 'write' ), 'custom', array( 'site' ) );

		$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', '/mcpwp/v1/memory' ) ) );

		$result = $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', '/mcpwp/v1/pages' ) );
		$this->assert_forbidden_category( $result, 'A custom-role key without "content" must not reach /pages' );
	}

	// ── Discovery allowlist bypasses the per-key role gate ──────────────────

	public function test_author_key_keeps_the_discovery_allowlist(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );

		foreach ( array( '/mcpwp/v1/site-info', '/mcpwp/v1/onboard', '/mcpwp/v1/introspect' ) as $route ) {
			$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', $route ) ), $route );
		}
	}

	// ── Site-wide toggle interaction (#1007 task 4) ─────────────────────────

	public function test_disabling_site_category_blocks_admin_from_newly_gated_routes(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );

		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );

		foreach ( array( '/mcpwp/v1/menus', '/mcpwp/v1/options', '/mcpwp/v1/memory' ) as $route ) {
			$result = $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', $route ) );
			$this->assertInstanceOf( WP_Error::class, $result, $route );
			$this->assertSame( 'forbidden_category', $result->get_error_code(), $route );
		}
	}

	public function test_disabling_site_category_does_not_lock_out_the_discovery_allowlist(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );

		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );

		foreach ( array( '/mcpwp/v1/site-info', '/mcpwp/v1/onboard', '/mcpwp/v1/introspect' ) as $route ) {
			$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', $route ) ), $route );
		}
	}

	// ── tools/call: independent, pre-existing, tool-name-keyed gate ─────────
	//
	// Untouched by this fix (it never resolves a route at all — see
	// tool_category_for()/resolve_key_tool_categories() in
	// Mcpwp_REST_MCP::handle_tools_call()), kept here as a regression pin so
	// the two gates (route-keyed here, tool-name-keyed there) are proven to
	// agree for a representative 'site' tool. wp_list_memories, not
	// wp_list_menus: the latter maps to /menus, which forces 'admin' scope
	// (see test_author_key_is_refused_on_menus_and_options_by_scope_before_category())
	// and would fail there before ever reaching the category check this
	// test targets.

	public function test_author_key_is_refused_wp_list_memories_via_tools_call(): void {
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-mcp.php';

		$controller = new Mcpwp_REST_MCP();
		$created    = $controller->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $created['key'] ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'wp_list_memories',
					'arguments' => array(),
				),
			)
		);

		$verdict = $controller->verify_api_key( $request );
		$this->assertTrue( $verdict, 'Test premise: the key authenticates (scope is satisfied).' );

		$data = $controller->handle_mcp( $request )->get_data();
		$this->assertTrue( $data['result']['isError'] ?? false );
		$this->assertSame( 'scope_denied', $data['result']['structuredContent']['error']['code'] );
	}

	// ── BLOCK-1 (adversarial review round 1): untooled routes ───────────────
	//
	// /settings, /favicon, /theme/*, /themes/*, /action-log and /users
	// dispatch no MCP tool at all, so RouteCategoryToolParityTest never saw
	// them — AllRegisteredRoutesCategoryTest closes that blind spot
	// generically; these are the specific, named regression pins for the
	// six routes the live review evidence named. GET only, to keep each
	// assertion isolated to the CATEGORY gate: /settings, /users (blanket-
	// admin SCOPE for every method) and /theme, /themes, /action-log
	// (admin scope on WRITE only) all stay at the READ scope default on GET.

	public function test_author_key_is_blocked_from_untooled_site_routes_directly(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );

		foreach ( array( '/mcpwp/v1/favicon', '/mcpwp/v1/theme/custom-css', '/mcpwp/v1/themes/detect', '/mcpwp/v1/action-log' ) as $route ) {
			$result = $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', $route ) );
			$this->assert_forbidden_category( $result, "author key on {$route}" );
		}
	}

	public function test_designer_key_is_allowed_on_untooled_site_routes(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'designer', array( 'read', 'write' ), 'designer' );

		foreach ( array( '/mcpwp/v1/favicon', '/mcpwp/v1/theme/custom-css', '/mcpwp/v1/themes/detect' ) as $route ) {
			$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', $route ) ), $route );
		}
	}

	public function test_admin_key_is_allowed_on_untooled_admin_scope_routes(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );

		foreach ( array( '/mcpwp/v1/settings', '/mcpwp/v1/users', '/mcpwp/v1/action-log' ) as $route ) {
			$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', $route ) ), $route );
		}
	}

	public function test_disabling_site_category_blocks_admin_from_untooled_routes(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );

		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );

		foreach ( array( '/mcpwp/v1/settings', '/mcpwp/v1/favicon', '/mcpwp/v1/theme/custom-css', '/mcpwp/v1/themes/detect' ) as $route ) {
			$result = $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', $route ) );
			$this->assertInstanceOf( WP_Error::class, $result, $route );
			$this->assertSame( 'forbidden_category', $result->get_error_code(), $route );
		}
	}

	public function test_disabling_admin_category_blocks_admin_from_users_and_action_log(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'admin' ) );

		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );

		foreach ( array( '/mcpwp/v1/users', '/mcpwp/v1/action-log' ) as $route ) {
			$result = $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', $route ) );
			$this->assertInstanceOf( WP_Error::class, $result, $route );
			$this->assertSame( 'forbidden_category', $result->get_error_code(), $route );
		}
	}

	public function test_theme_builder_routes_are_not_shadowed_by_the_new_theme_prefix(): void {
		// '/theme' is a literal string prefix of '/theme-builder' — first-match
		// ordering in get_route_category_map() must keep /theme-builder's own
		// 'elementor-theme' category (theme-builder's entry comes first).
		$auth = $this->harness();
		$this->assertSame( 'elementor-theme', $auth->get_category_for_route( '/mcpwp/v1/theme-builder/status' ) );
		$this->assertSame( 'site', $auth->get_category_for_route( '/mcpwp/v1/theme/mods' ) );
		$this->assertSame( 'site', $auth->get_category_for_route( '/mcpwp/v1/themes/astra/colors' ) );
	}

	// ── Review round 2 owner decision: /site-context reading is public ─────
	//
	// GET /site-context (read) is a discovery-allowlist READ-ONLY entry:
	// every key, any role, even with 'site' disabled site-wide. PUT/POST
	// /site-context (write, wp_set_site_context — a different tool sharing
	// the same route) stays gated to 'site' exactly as before. The pure
	// route classifier (get_category_for_route(), method-unaware) still
	// resolves 'site' for either method — the read/write split lives in
	// is_discovery_read_bypass(), consulted by both real gates.

	public function test_get_site_context_is_public_regardless_of_role(): void {
		$auth = $this->harness();

		foreach ( array( 'author', 'designer' ) as $role ) {
			$created = $auth->create_scoped_api_key( $role . ' key', array( 'read', 'write' ), $role );
			$this->assertTrue(
				$auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', '/mcpwp/v1/site-context' ) ),
				$role
			);
		}
	}

	public function test_get_site_context_is_public_even_when_site_category_is_disabled(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );

		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );

		$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', '/mcpwp/v1/site-context' ) ) );
	}

	public function test_put_site_context_stays_gated_to_site_category_for_an_author_key(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );

		$result = $auth->verify_api_key( $this->request_with_key( $created['key'], 'PUT', '/mcpwp/v1/site-context' ) );
		$this->assert_forbidden_category( $result, 'PUT /site-context (the write tool) must stay gated even though GET is public.' );
	}

	public function test_put_site_context_is_refused_when_site_category_is_disabled_even_for_admin(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );

		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );

		$result = $auth->verify_api_key( $this->request_with_key( $created['key'], 'PUT', '/mcpwp/v1/site-context' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden_category', $result->get_error_code() );
	}

	public function test_get_site_context_via_batch_stays_public_for_an_author_key(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );

		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		$result                                 = $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', '/mcpwp/v1/site-context' ) );
		Mcpwp_REST_Batch::$is_batch_sub_request = false;

		$this->assertTrue( $result, 'Wrapping the public GET in a /batch envelope must not accidentally gate it.' );
	}

	// ── NIT-1: tool_currently_permitted() must agree with the handler ───────
	//
	// tool_currently_permitted() backs two callers besides handle_tools_call()
	// itself: the idempotent-replay gate (~1324, only ever reached for a
	// PINNED tool — i.e. a write; none of the discovery tools are pinned,
	// since they are all reads) and #1012's next-step-hint filter (~2174,
	// which can name ANY tool, including a discovery one, as a suggested
	// next call). Before this fix, this function disagreed with the
	// handler it exists to mirror for exactly the three (now four) tool
	// names the handler itself exempts — a hint naming wp_site_info for an
	// author key would have been silently dropped as "not currently
	// permitted" even though calling it directly succeeds. Tested directly
	// via reflection (the method is private) rather than by trying to force
	// a specific hint to appear, which depends on unrelated site state.

	public function test_tool_currently_permitted_exempts_discovery_tools_for_a_restricted_key(): void {
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-mcp.php';

		$controller = new Mcpwp_REST_MCP();
		$created    = $controller->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );
		$this->assertTrue( $controller->verify_api_key( $this->request_with_key( $created['key'], 'GET', '/mcpwp/v1/site-info' ) ) );
		$key_record = $controller->get_current_api_key_record();
		$this->assertIsArray( $key_record, 'Test premise: the key resolves to a record.' );

		$method = new ReflectionMethod( Mcpwp_REST_MCP::class, 'tool_currently_permitted' );
		$method->setAccessible( true );

		foreach ( array( 'wp_site_info', 'wp_onboard', 'wp_introspect', 'wp_get_site_context' ) as $tool_name ) {
			$this->assertTrue( $method->invoke( $controller, $tool_name, $key_record ), $tool_name );
		}
		// Proof this is a narrow exemption, not a blanket unrestriction: a
		// genuine 'site' tool the author role does not carry still fails.
		$this->assertFalse( $method->invoke( $controller, 'wp_list_menus', $key_record ) );
	}

	public function test_tool_currently_permitted_exempts_discovery_tools_when_site_category_is_disabled(): void {
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-mcp.php';
		update_option( 'mcpwp_disabled_tool_categories', array( 'site' ) );

		$controller = new Mcpwp_REST_MCP();
		$created    = $controller->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );
		$this->assertTrue( $controller->verify_api_key( $this->request_with_key( $created['key'], 'GET', '/mcpwp/v1/site-info' ) ) );
		$key_record = $controller->get_current_api_key_record();

		$method = new ReflectionMethod( Mcpwp_REST_MCP::class, 'tool_currently_permitted' );
		$method->setAccessible( true );

		foreach ( array( 'wp_site_info', 'wp_get_site_context' ) as $tool_name ) {
			$this->assertTrue( $method->invoke( $controller, $tool_name, $key_record ), $tool_name );
		}
		$this->assertFalse( $method->invoke( $controller, 'wp_list_menus', $key_record ), 'A disabled category must still block a genuine site tool.' );
	}
}
