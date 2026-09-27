<?php
/**
 * Adversarial review round 3 on #1007, BLOCK-1.
 *
 * The round-2 owner decision made the BASE mcpwp_site_context text public
 * (get_discovery_allowlist()'s read-only 'wp_get_site_context' entry). It
 * said nothing about the scope='product'/archetype_class PAGE-TYPE OVERRIDE
 * get_site_context() -> build_effective_site_context() -> find_context_override()
 * can splice into that text: a WooCommerce product archetype brief
 * (find_product_context_override(), backed by the 'mcpwp_wc_product_archetypes'
 * option — the same data wc_get_product_archetype/wc_list_product_archetypes
 * gate behind the 'woocommerce' category) or an Elementor page archetype
 * brief (find_page_context_override(), backed by
 * Mcpwp_Elementor_Pro::get_archetypes() — the same data
 * wp_get_elementor_archetype/wp_list_elementor_archetypes gate behind
 * 'elementor-templates'). Neither brief is published in /llms.txt.
 *
 * Live evidence from the review: an author read-key with 'site' AND
 * 'woocommerce' AND 'elementor-templates' all disabled still got the full
 * product/page brief plus its archetype title and id back from
 * GET /site-context?scope=product&archetype_class=apparel, from
 * tools/call wp_get_site_context with the same arguments, and from
 * resources/read mcpwp://site-context?scope=product&archetype_class=apparel
 * — while a direct call to the archetype's OWN route (GET
 * /woocommerce/archetypes) correctly 403'd.
 *
 * Fix: find_context_override() — the ONE function every door funnels
 * through (get_site_context() is the single REST handler behind direct
 * REST, tools/call wp_get_site_context, and resources/read
 * mcpwp://site-context; mcpwp://brand dispatches to the same handler for
 * its site-context half) — now checks caller_may_read_category() for the
 * archetype's own category before ever resolving an override, and returns
 * array() (the same shape as "no archetype matched") when denied. A denied
 * caller sees the base context only, inheritance.matched stays false, and
 * there is no 'source' key — nothing reveals that an archetype exists.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-media.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-design-references.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-info.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-mcp.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

final class SiteContextArchetypeCategoryGateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_is_admin']     = false;
		$GLOBALS['mcpwp_test_filters']      = array();

		update_option( 'mcpwp_site_context', 'Base brand voice: warm and direct.' );
		update_option(
			'mcpwp_wc_product_archetypes',
			array(
				array(
					'id'               => 42,
					'name'             => 'Apparel Archetype',
					'archetype_class'  => 'apparel',
					'archetype_style'  => '',
					'brief'            => 'PRIVATE: internal margin targets and supplier terms for apparel.',
				),
			)
		);
	}

	protected function tearDown(): void {
		if ( class_exists( 'Mcpwp_REST_Batch' ) ) {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
		}
		update_option( 'mcpwp_disabled_tool_categories', array() );
	}

	private function controller(): Mcpwp_REST_Site_Info {
		return new Mcpwp_REST_Site_Info();
	}

	/** Exposes a private method via reflection. */
	private function call_private( $object, string $method, array $args ) {
		$ref = new ReflectionMethod( get_class( $object ), $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $object, $args );
	}

	/**
	 * The shared PHPUnit rest_do_request() double answers every internal
	 * sub-request (tools/call's route dispatch, resources/read's) with a
	 * canned {"ok":true} unless a test wires the rest_dispatch_request
	 * filter to run something real — same technique
	 * ToolsCallSubRequestAuthTest::wire_real_permission_check() uses, but
	 * that one only proves auth passed (still answers a canned 200). This
	 * variant actually invokes the real Mcpwp_REST_Site_Info::get_site_context()
	 * handler on a permission pass, so a door-2/door-3 test can assert on
	 * REAL response content, not just "no error".
	 */
	private function wire_real_site_context_dispatch(): void {
		add_filter(
			'rest_dispatch_request',
			function ( $result, $request, $route ) {
				if ( null !== $result || '/mcpwp/v1/site-context' !== (string) $route ) {
					return $result;
				}

				$controller = new Mcpwp_REST_Site_Info();
				$auth       = $controller->check_permission( $request );

				if ( is_wp_error( $auth ) ) {
					$data   = $auth->get_error_data();
					$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
					return new WP_REST_Response(
						array(
							'code'    => $auth->get_error_code(),
							'message' => $auth->get_error_message(),
							'data'    => $data,
						),
						$status
					);
				}

				return $controller->get_site_context( $request );
			},
			10,
			4
		);
	}

	private function request_with_key( string $key, array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/site-context', array(), array( 'X-API-Key' => $key ) );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		return $request;
	}

	// ── caller_may_read_category(): the shared gate, both categories ────────

	public function test_caller_may_read_category_fails_closed_for_a_restricted_role(): void {
		$controller = $this->controller();
		$created    = $controller->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );
		$this->assertTrue( $controller->verify_api_key( $this->request_with_key( $created['key'] ) ) );

		$this->assertFalse( $this->call_private( $controller, 'caller_may_read_category', array( 'woocommerce' ) ) );
		$this->assertFalse( $this->call_private( $controller, 'caller_may_read_category', array( 'elementor-templates' ) ) );
	}

	public function test_caller_may_read_category_allows_a_role_that_carries_it(): void {
		$controller = $this->controller();
		$created    = $controller->create_scoped_api_key( 'custom-key', array( 'read', 'write' ), 'custom', array( 'woocommerce', 'elementor-templates' ) );
		$this->assertTrue( $controller->verify_api_key( $this->request_with_key( $created['key'] ) ) );

		$this->assertTrue( $this->call_private( $controller, 'caller_may_read_category', array( 'woocommerce' ) ) );
		$this->assertTrue( $this->call_private( $controller, 'caller_may_read_category', array( 'elementor-templates' ) ) );
	}

	public function test_caller_may_read_category_fails_closed_when_disabled_site_wide_even_for_admin(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'woocommerce', 'elementor-templates' ) );

		$controller = $this->controller();
		$created    = $controller->create_scoped_api_key( 'admin key', array( 'read', 'write', 'admin' ), 'admin' );
		$this->assertTrue( $controller->verify_api_key( $this->request_with_key( $created['key'] ) ) );

		$this->assertFalse( $this->call_private( $controller, 'caller_may_read_category', array( 'woocommerce' ) ) );
		$this->assertFalse( $this->call_private( $controller, 'caller_may_read_category', array( 'elementor-templates' ) ) );
	}

	public function test_caller_may_read_category_is_unrestricted_for_admin_role_and_no_key_context(): void {
		$controller = $this->controller();
		$created    = $controller->create_scoped_api_key( 'admin key', array( 'read', 'write', 'admin' ), 'admin' );
		$this->assertTrue( $controller->verify_api_key( $this->request_with_key( $created['key'] ) ) );
		$this->assertTrue( $this->call_private( $controller, 'caller_may_read_category', array( 'woocommerce' ) ) );

		// No key record at all (trusted admin-AJAX / internal context) fails open,
		// matching every other category gate's convention in this plugin.
		$fresh = $this->controller();
		$this->assertNull( $fresh->get_current_api_key_record() );
		$this->assertTrue( $this->call_private( $fresh, 'caller_may_read_category', array( 'woocommerce' ) ) );
	}

	// ── find_context_override(): product scope (WooCommerce archetype) ─────

	public function test_product_override_is_denied_for_an_author_key_without_woocommerce(): void {
		$controller = $this->controller();
		$created    = $controller->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );
		$this->assertTrue( $controller->verify_api_key( $this->request_with_key( $created['key'] ) ) );

		$override = $this->call_private( $controller, 'find_context_override', array( 'product', 'apparel', '' ) );

		$this->assertSame( array(), $override, 'A key without woocommerce must see nothing — not even that an archetype exists.' );
	}

	public function test_product_override_is_denied_when_woocommerce_is_disabled_site_wide_even_for_admin(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'woocommerce' ) );

		$controller = $this->controller();
		$created    = $controller->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );
		$this->assertTrue( $controller->verify_api_key( $this->request_with_key( $created['key'] ) ) );

		$override = $this->call_private( $controller, 'find_context_override', array( 'product', 'apparel', '' ) );

		$this->assertSame( array(), $override );
	}

	public function test_product_override_is_allowed_for_a_custom_key_with_woocommerce(): void {
		$controller = $this->controller();
		$created    = $controller->create_scoped_api_key( 'wc-key', array( 'read', 'write' ), 'custom', array( 'woocommerce' ) );
		$this->assertTrue( $controller->verify_api_key( $this->request_with_key( $created['key'] ) ) );

		$override = $this->call_private( $controller, 'find_context_override', array( 'product', 'apparel', '' ) );

		$this->assertSame( 'product_archetype', $override['type'] ?? null );
		$this->assertSame( 42, $override['id'] ?? null );
		$this->assertSame( 'Apparel Archetype', $override['title'] ?? null );
		$this->assertStringContainsString( 'PRIVATE', $override['brief'] ?? '' );
	}

	public function test_product_override_is_allowed_for_an_admin_key(): void {
		$controller = $this->controller();
		$created    = $controller->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );
		$this->assertTrue( $controller->verify_api_key( $this->request_with_key( $created['key'] ) ) );

		$override = $this->call_private( $controller, 'find_context_override', array( 'product', 'apparel', '' ) );

		$this->assertSame( 42, $override['id'] ?? null );
	}

	// ── find_context_override(): page scope (Elementor archetype) ──────────
	//
	// find_page_context_override() needs a real WP_Query over elementor_library
	// posts to prove a MATCH, which this suite's double does not stand up —
	// the security-critical direction is proven directly instead: the gate
	// must return array() BEFORE ever reaching Mcpwp_Elementor_Pro, for both
	// the per-key role restriction and the site-wide toggle. The positive
	// (allowed + real archetype match) direction is verified over real HTTP
	// against a live Elementor Pro archetype in the rig check.

	public function test_page_override_is_denied_for_an_author_key_without_elementor_templates(): void {
		$controller = $this->controller();
		$created    = $controller->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );
		$this->assertTrue( $controller->verify_api_key( $this->request_with_key( $created['key'] ) ) );

		$override = $this->call_private( $controller, 'find_context_override', array( 'landing_page', 'saas', '' ) );

		$this->assertSame( array(), $override );
	}

	public function test_page_override_is_denied_when_elementor_templates_is_disabled_site_wide_even_for_admin(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'elementor-templates' ) );

		$controller = $this->controller();
		$created    = $controller->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );
		$this->assertTrue( $controller->verify_api_key( $this->request_with_key( $created['key'] ) ) );

		$override = $this->call_private( $controller, 'find_context_override', array( 'landing_page', 'saas', '' ) );

		$this->assertSame( array(), $override );
	}

	// ── The full response shape: no leak through build_effective_site_context() ──

	public function test_get_site_context_response_never_leaks_the_denied_product_archetype(): void {
		$controller = $this->controller();
		$created    = $controller->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );
		$request    = $this->request_with_key( $created['key'], array( 'scope' => 'product', 'archetype_class' => 'apparel' ) );
		$this->assertTrue( $controller->verify_api_key( $request ) );

		$data = $controller->get_site_context( $request )->get_data();

		$this->assertSame( 'Base brand voice: warm and direct.', $data['effective_context'] );
		$this->assertFalse( $data['inheritance']['matched'] );
		$this->assertArrayNotHasKey( 'source', $data['inheritance'] );
		$this->assertStringNotContainsString( 'PRIVATE', $data['effective_context'] );
		$this->assertStringNotContainsString( 'Apparel Archetype', $data['effective_context'] );
	}

	public function test_get_site_context_response_includes_the_allowed_product_archetype(): void {
		$controller = $this->controller();
		$created    = $controller->create_scoped_api_key( 'wc-key', array( 'read', 'write' ), 'custom', array( 'woocommerce' ) );
		$request    = $this->request_with_key( $created['key'], array( 'scope' => 'product', 'archetype_class' => 'apparel' ) );
		$this->assertTrue( $controller->verify_api_key( $request ) );

		$data = $controller->get_site_context( $request )->get_data();

		$this->assertTrue( $data['inheritance']['matched'] );
		$this->assertSame( 42, $data['inheritance']['source']['id'] );
		$this->assertStringContainsString( 'PRIVATE', $data['effective_context'] );
	}

	// ── Door 2: tools/call wp_get_site_context ───────────────────────────────

	public function test_tools_call_wp_get_site_context_never_leaks_the_denied_product_archetype(): void {
		$this->wire_real_site_context_dispatch();

		$controller = new Mcpwp_REST_MCP();
		$created    = $controller->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $created['key'] ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'wp_get_site_context',
					'arguments' => array( 'scope' => 'product', 'archetype_class' => 'apparel' ),
				),
			)
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
		$data = $controller->handle_mcp( $request )->get_data();

		$this->assertFalse( $data['result']['isError'] ?? true );
		$text = $data['result']['content'][0]['text'] ?? wp_json_encode( $data['result'] );
		$this->assertStringNotContainsString( 'PRIVATE', $text );
		$this->assertStringNotContainsString( 'Apparel Archetype', $text );
	}

	// ── Door 3: resources/read mcpwp://site-context?scope=... ───────────────

	public function test_resources_read_site_context_never_leaks_the_denied_product_archetype(): void {
		$this->wire_real_site_context_dispatch();

		$controller = new Mcpwp_REST_MCP();
		$created    = $controller->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $created['key'] ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'resources/read',
				'params'  => array( 'uri' => 'mcpwp://site-context?scope=product&archetype_class=apparel' ),
			)
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
		$data = $controller->handle_mcp( $request )->get_data();
		$text = $data['result']['contents'][0]['text'] ?? '';

		$this->assertStringNotContainsString( 'PRIVATE', $text );
		$this->assertStringNotContainsString( 'Apparel Archetype', $text );
	}

	public function test_resources_read_site_context_includes_the_allowed_product_archetype(): void {
		$this->wire_real_site_context_dispatch();

		$controller = new Mcpwp_REST_MCP();
		$created    = $controller->create_scoped_api_key( 'wc-key', array( 'read', 'write' ), 'custom', array( 'woocommerce' ) );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $created['key'] ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'resources/read',
				'params'  => array( 'uri' => 'mcpwp://site-context?scope=product&archetype_class=apparel' ),
			)
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
		$data = $controller->handle_mcp( $request )->get_data();
		$text = $data['result']['contents'][0]['text'] ?? '';

		$this->assertStringContainsString( 'PRIVATE', $text );
	}
}
