<?php
/**
 * Regression test for wp_get_rendered_copy on unpublished drafts (GH #675).
 *
 * The copy reader used to resolve unpublished posts to a preview URL and fetch
 * it over an outbound loopback, then hard-fail with `fetch_error` when the fetch
 * did not return HTTP 200 — which it never does for a draft without a logged-in
 * session. An authenticated agent should be able to audit draft copy without
 * publishing, so unpublished content is now rendered in-process.
 *
 * @package SitePilotAI\Tests
 */

// The controller under test is not loaded by the shared bootstrap; require it
// directly so this test is independent of file execution order.
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-content.php';

class RenderedCopyDraftTest extends PHPUnit\Framework\TestCase {

	private const DRAFT_ID = 5777;

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta']  = array();

		// The in-process draft render is gated behind write scope (a read-only
		// key must never reach an unpublished body — see the trait's
		// requester_can_see_non_public() and RestSiteContentTest). Reset the
		// option/user globals the key-minting + auth path reads so call_copy()
		// can mint and verify a write-scoped key cleanly, matching the
		// "authenticated agent" the #675 feature is written for.
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_is_admin']     = false;
	}

	private function seed_draft( string $content ): void {
		$GLOBALS['_mcpwp_test_posts'][ self::DRAFT_ID ] = new WP_Post( array(
			'ID'           => self::DRAFT_ID,
			'post_type'    => 'page',
			'post_title'   => 'Book',
			'post_name'    => 'book',
			'post_status'  => 'draft',
			'post_content' => $content,
		) );
	}

	private function call_copy() {
		$controller = new Mcpwp_REST_Site_Content();

		// Mint + authenticate a write-scoped key so the request carries the
		// privilege the draft render requires. verify_api_key() populates the
		// controller's current_api_key_record, which requester_can_see_non_public()
		// reads to allow unpublished content through.
		// get_rendered_copy() gates on BOTH the route's own category ('site')
		// AND the resolved post's type category ('content', via
		// guard_post_type_category() — page/post has no exemption here unlike
		// /elementor or /blocks). role=admin (documented unrestricted, see
		// resolve_key_tool_categories()) sidesteps reconstructing that pair;
		// this test is about the in-process draft render, not category gating.
		$created = $controller->create_scoped_api_key( 'test key', array( 'write' ), 'admin' );
		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/rendered-copy',
			array( 'id' => self::DRAFT_ID ),
			array( 'X-API-Key' => $created['key'] )
		);
		$this->assertTrue( $controller->verify_api_key( $request ), 'verify_api_key should succeed for a valid write-scoped key.' );

		return $controller->get_rendered_copy( $request );
	}

	public function test_draft_copy_renders_in_process_instead_of_fetch_error() {
		$this->seed_draft( '<h1>Book Title</h1><p>Draft body copy that agents must audit.</p>' );

		$resp = $this->call_copy();

		$this->assertSame( 200, $resp->get_status(), 'A draft must not return a fetch_error.' );
		$data = $resp->get_data();
		$this->assertSame( 'internal_content', $data['render_method'] );
		$this->assertStringContainsString( 'Draft body copy that agents must audit.', $data['all_text'] );
		$this->assertGreaterThan( 0, $data['section_count'] );
	}

	public function test_missing_post_still_404s() {
		$controller = new Mcpwp_REST_Site_Content();
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/rendered-copy', array( 'id' => 999999 ) );

		$resp = $controller->get_rendered_copy( $request );

		// error_response returns a WP_Error or a response carrying a 404 status.
		$status = $resp instanceof WP_Error ? $resp->get_error_data()['status'] : $resp->get_status();
		$this->assertSame( 404, $status );
	}
}
