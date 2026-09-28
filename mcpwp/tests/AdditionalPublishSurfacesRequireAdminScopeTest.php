<?php

use PHPUnit\Framework\TestCase;

/**
 * RC 3.12.1 adversarial review, Requirement 1 systematic sweep.
 *
 * BLOCK 1 (LearnPress) and BLOCK 2 (WooCommerce archetype apply) were found
 * by hand-enumerating routes, which is exactly the failure mode the review
 * called out: "the fix enumerated routes by hand... it missed sibling
 * surfaces with the identical defect." Grepping every wp_insert_post()/
 * wp_update_post() call site in includes/ for a caller-controlled
 * 'post_status' (not just re-reading the route table) turned up five MORE
 * surfaces with that same defect, none named in the original two BLOCK
 * findings:
 *
 *   - Events (Mcpwp_Events::update_event()) — a sibling CPT to LearnPress
 *     with the identical shape.
 *   - wp_build_page (Mcpwp_Page_Builder::build() via /elementor/build-page)
 *   - wp_create_landing_page (/elementor/landing-page)
 *   - wp_clone_elementor_page (/elementor/clone — the Elementor-specific
 *     sibling of /pages/{id}/clone, which was already gated)
 *   - create_elementor_page (/elementor/page — reachable directly over REST
 *     even though no MCP tool maps to it; the review's own threat model is
 *     direct REST, not only /mcp)
 *
 * See get_required_scope_for_request()'s $publish_gated_routes for the full
 * per-route audit, including the routes checked and found to be either
 * already covered (translations ride the /posts and /pages prefixes;
 * Elementor Custom Code and site-blueprints deploy are already
 * blanket-admin) or not caller-controlled at all (archetype/template/part
 * "apply" only ever mutates an EXISTING page's Elementor JSON, never its
 * status).
 *
 * @package MCPWP
 */

require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-events.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-elementor-pro.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';

final class AdditionalPublishSurfacesRequireAdminScopeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		// The ids these requests name must exist: since #1030 round-5 the
		// declared-post-ref guard answers a missing id itself, before scope.
		$GLOBALS['_mcpwp_test_posts'] = array(
			12 => new WP_Post( array( 'ID' => 12, 'post_type' => 'page', 'post_status' => 'publish' ) ),
		);
		if ( method_exists( 'Mcpwp_REST_API', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_API::reset_verdict_memo();
		}
	}

	private function write_key( $controller ): string {
		// This suite is about SCOPE (write vs admin), not category — it
		// exercises routes across several categories (webhooks, elementor,
		// elementor-templates). role=admin keeps that orthogonal: scopes are
		// still capped to exactly what is requested (get_required_scope_for_request()
		// is what each test actually asserts against), and category never
		// enters into it.
		return $controller->create_scoped_api_key( 'write key', array( 'write' ), 'admin' )['key'];
	}

	private function admin_key( $controller ): string {
		return $controller->create_scoped_api_key( 'admin key', array( 'admin' ), 'admin' )['key'];
	}

	// ── Events (sibling to LearnPress) ──────────────────────────────────────

	public function test_write_key_cannot_update_an_event_to_published(): void {
		$controller = new Mcpwp_REST_Events( null );
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/events/4',
			array(
				'id'     => 4,
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_still_update_an_events_title_only(): void {
		$controller = new Mcpwp_REST_Events( null );
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/events/4',
			array(
				'id'    => 4,
				'title' => 'New title',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	public function test_write_key_can_create_a_draft_event(): void {
		$controller = new Mcpwp_REST_Events( null );
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/events',
			array( 'title' => 'Launch party' ), // create_event() defaults to draft
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	public function test_admin_key_can_update_an_event_to_published(): void {
		$controller = new Mcpwp_REST_Events( null );
		$key        = $this->admin_key( $controller );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/events/4',
			array(
				'id'     => 4,
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	// ── wp_build_page ────────────────────────────────────────────────────────

	public function test_write_key_cannot_build_a_published_page(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( null );
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/build-page',
			array(
				'title'    => 'Landing',
				'sections' => array( array( 'type' => 'hero' ) ),
				'status'   => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_build_a_draft_page_with_no_status_param(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( null );
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/build-page',
			array(
				'title'    => 'Landing',
				'sections' => array( array( 'type' => 'hero' ) ),
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue(
			$controller->verify_api_key( $request ),
			'build_page() defaults status to draft — a write key must still be able to do that.'
		);
	}

	// ── wp_create_landing_page ───────────────────────────────────────────────

	public function test_write_key_cannot_create_a_published_landing_page(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( null );
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/landing-page',
			array(
				'title'  => 'Landing',
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_create_a_draft_landing_page(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( null );
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/landing-page',
			array( 'title' => 'Landing' ),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	// ── wp_clone_elementor_page ──────────────────────────────────────────────

	public function test_write_key_cannot_clone_an_elementor_page_to_published(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( null );
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/clone',
			array(
				'source_id' => 12,
				'status'    => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'/elementor/clone is the Elementor-specific sibling of /pages/{id}/clone (already gated) — it must be gated too.'
		);
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_clone_an_elementor_page_to_draft(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( null );
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/clone',
			array( 'source_id' => 12 ),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	// ── create_elementor_page (/elementor/page, no MCP tool maps to it) ────

	public function test_write_key_cannot_create_a_published_page_via_elementor_page_route(): void {
		$controller = new Mcpwp_REST_Elementor();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/page',
			array(
				'title'  => 'Hello',
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'/elementor/page is reachable directly over REST even though no MCP tool maps to it — the gate must not depend on the /mcp path.'
		);
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_create_a_draft_page_via_elementor_page_route(): void {
		$controller = new Mcpwp_REST_Elementor();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/page',
			array( 'title' => 'Hello' ),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	// ── Confirm the audited "safe" surfaces really do stay at write scope ──

	public function test_write_key_can_still_apply_an_elementor_archetype_to_an_existing_page(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( null );
		$key        = $this->write_key( $controller );

		// apply_archetype()/apply_template()/apply_part() only ever mutate an
		// EXISTING page's Elementor JSON — they never touch post_status, so
		// they must stay at write scope regardless of payload shape.
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/archetypes/3/apply',
			array( 'page_id' => 12 ),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}
}
