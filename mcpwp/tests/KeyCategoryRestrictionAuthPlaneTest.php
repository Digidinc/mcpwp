<?php
/**
 * Regression tests — #874.
 *
 * A scoped API key's ROLE (admin/designer/author/editor/custom) is sold as
 * restricting which tool categories the key may touch — e.g. an "author" key
 * (categories: content, media, taxonomy) should never be able to write
 * Elementor data, even though its scope tier (read/write) is otherwise
 * identical to a "designer" key's.
 *
 * Before this fix, that restriction (resolve_key_tool_categories() /
 * key_allows_category()) was enforced ONLY inside
 * Mcpwp_REST_MCP::handle_tools_call() — i.e. only for a caller going through
 * the /mcp JSON-RPC surface. Every other entry point to the SAME REST API
 * (a direct call to any /mcpwp/v1/* route, or one nested inside a /batch
 * envelope) skipped it entirely: an "author" key could POST Elementor data to
 * /mcpwp/v1/elementor/{id} directly, or via /batch, even though the identical
 * operation through wp_set_elementor over /mcp was correctly refused. Scope
 * (get_required_scope_for_request()) was satisfied in every case — scope and
 * per-key category restriction are orthogonal, and only one of the two was
 * ever checked outside of /mcp.
 *
 * check_key_category_restriction_for_route() closes this: it is called from
 * every authentication path in trait-mcpwp-api-auth.php, so a key's role now
 * holds regardless of which entry point a caller uses.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

/** Harness exposing the auth trait. All methods used here are already public. */
class Mcpwp_Key_Category_Auth_Harness {
	use Mcpwp_Api_Auth;
}

final class KeyCategoryRestrictionAuthPlaneTest extends TestCase {

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
	}

	private function harness(): Mcpwp_Key_Category_Auth_Harness {
		return new Mcpwp_Key_Category_Auth_Harness();
	}

	private function request_with_key( string $key, string $method, string $route ): WP_REST_Request {
		return new WP_REST_Request( $method, $route, array(), array( 'X-API-Key' => $key ) );
	}

	private function assert_forbidden_category( $result, string $message ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $message );
		$this->assertSame( 'forbidden_category', $result->get_error_code(), $message );
		$this->assertSame( 403, $result->get_error_data()['status'], $message );
	}

	// ── The live bug: direct REST call bypassing an author key's role ────────

	public function test_author_key_is_blocked_from_elementor_route_directly(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );

		$result = $auth->verify_api_key( $this->request_with_key( $created['key'], 'POST', '/mcpwp/v1/elementor/6' ) );

		$this->assert_forbidden_category( $result, 'An author-role key must not reach an Elementor route via direct REST call' );
	}

	public function test_author_key_is_blocked_from_elementor_route_via_batch_sub_request(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );

		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		$result                                 = $auth->verify_api_key( $this->request_with_key( $created['key'], 'POST', '/mcpwp/v1/elementor/6' ) );
		Mcpwp_REST_Batch::$is_batch_sub_request = false;

		$this->assert_forbidden_category( $result, 'Wrapping the same call in a /batch envelope must not launder an author key past its role' );
	}

	// ── The /batch envelope itself must not be gated by its OWN nominal ──────
	// category. get_category_for_route() classifies '/batch' as 'content' so the
	// SITE-WIDE disabled-category toggle can block it outright, but a per-key
	// role restriction must judge each operation INSIDE the envelope by its own
	// real route (already proven above), not by the wrapper's label — otherwise
	// a designer-role key could never call /batch at all, even to batch nothing
	// but Elementor writes its role fully permits.

	public function test_designer_key_can_call_batch_envelope_directly(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'designer', array( 'read', 'write' ), 'designer' );

		// The outer /batch request itself must not be rejected on '/batch' =>
		// 'content', which is not one of designer's categories.
		$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $created['key'], 'POST', '/mcpwp/v1/batch' ) ) );
	}

	public function test_author_key_can_still_write_its_own_categories(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );

		$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $created['key'], 'POST', '/mcpwp/v1/pages' ) ), 'content is an author category' );
		$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $created['key'], 'POST', '/mcpwp/v1/media' ) ), 'media is an author category' );
	}

	// ── Designer role: elementor is IN its categories ────────────────────────

	public function test_designer_key_can_write_elementor_directly(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'designer', array( 'read', 'write' ), 'designer' );

		$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $created['key'], 'POST', '/mcpwp/v1/elementor/6' ) ) );
	}

	public function test_designer_key_is_blocked_from_content_graph_route(): void {
		// Designer's categories are elementor/elementor-build/elementor-templates/
		// elementor-theme/elementor-info/gutenberg/media/site — NOT 'content'.
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'designer', array( 'read', 'write' ), 'designer' );

		$result = $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', '/mcpwp/v1/pages' ) );

		$this->assert_forbidden_category( $result, 'A designer key has no "content" category' );
	}

	// ── Admin role and legacy/unrestricted keys stay unrestricted ───────────

	public function test_admin_role_key_is_unrestricted(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );

		$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $created['key'], 'POST', '/mcpwp/v1/elementor/6' ) ) );
		$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', '/mcpwp/v1/pages' ) ) );
	}

	public function test_legacy_single_key_is_unrestricted(): void {
		$auth = $this->harness();
		$key  = 'mcpwp_legacytestkey0000000000000000000000';
		update_option( 'mcpwp_api_key', wp_hash_password( $key ) );

		$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $key, 'POST', '/mcpwp/v1/elementor/6' ) ) );
	}

	// ── Custom role with an explicit, narrow category list ──────────────────

	public function test_custom_role_key_restricted_to_its_chosen_categories(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'custom-key', array( 'read', 'write' ), 'custom', array( 'seo' ) );

		$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', '/mcpwp/v1/seo/audit' ) ) );

		$result = $auth->verify_api_key( $this->request_with_key( $created['key'], 'POST', '/mcpwp/v1/elementor/6' ) );
		$this->assert_forbidden_category( $result, 'A custom-role key must be confined to the categories it was granted' );
	}

	// ── Uncategorised routes stay reachable regardless of role ──────────────

	public function test_uncategorised_route_is_unaffected_by_role_restriction(): void {
		// /site-info carries no route category (get_category_for_route() returns
		// ''), so it must remain callable by a narrowly-scoped key exactly as
		// before this fix — the per-key gate fails OPEN on an unclassified route,
		// mirroring check_disabled_category_for_route()'s own convention.
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );

		$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', '/mcpwp/v1/site-info' ) ) );
	}

	// ── The error is a 403 with actionable data, not a bare boolean false ───

	public function test_forbidden_category_error_names_the_role_and_allowed_categories(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );

		$result = $auth->verify_api_key( $this->request_with_key( $created['key'], 'POST', '/mcpwp/v1/elementor/6' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertSame( 'elementor', $data['category'] );
		$this->assertStringContainsString( 'author', $result->get_error_message() );
		$this->assertStringContainsString( 'content', $result->get_error_message() );
	}
}
