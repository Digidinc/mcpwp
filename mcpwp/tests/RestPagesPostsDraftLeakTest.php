<?php
/**
 * Regression tests — draft/pending/private body leak via the single-GET page
 * and post read surfaces: GET /pages/{id}, GET /pages/by-slug/{slug}, and
 * GET /posts/{id}. The core get_page()/get_post() format the post regardless
 * of status, so a read-only key could otherwise read an unpublished body.
 * Closes the sibling-endpoint bypass of the /rendered-* gate (PR #683).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-posts.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-drafts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';

final class RestPagesPostsDraftLeakTest extends TestCase {

	private const PUB_PAGE_ID   = 501;
	private const DRAFT_PAGE_ID = 502;
	private const PUB_POST_ID   = 601;
	private const DRAFT_POST_ID = 602;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['_mcpwp_test_meta']              = array();

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PUB_PAGE_ID   => new WP_Post(
				array(
					'ID'           => self::PUB_PAGE_ID,
					'post_type'    => 'page',
					'post_title'   => 'Public page',
					'post_name'    => 'public-page',
					'post_status'  => 'publish',
					'post_content' => 'Public page body.',
				)
			),
			self::DRAFT_PAGE_ID => new WP_Post(
				array(
					'ID'           => self::DRAFT_PAGE_ID,
					'post_type'    => 'page',
					'post_title'   => 'Secret page',
					'post_name'    => 'secret-page',
					'post_status'  => 'draft',
					'post_content' => 'SECRET page body.',
				)
			),
			self::PUB_POST_ID   => new WP_Post(
				array(
					'ID'           => self::PUB_POST_ID,
					'post_type'    => 'post',
					'post_title'   => 'Public post',
					'post_name'    => 'public-post',
					'post_status'  => 'publish',
					'post_content' => 'Public post body.',
				)
			),
			self::DRAFT_POST_ID => new WP_Post(
				array(
					'ID'           => self::DRAFT_POST_ID,
					'post_type'    => 'post',
					'post_title'   => 'Secret post',
					'post_name'    => 'secret-post',
					'post_status'  => 'draft',
					'post_content' => 'SECRET post body.',
				)
			),
		);
	}

	private function authed_pages( int $id, array $scopes ): array {
		$controller = new Mcpwp_REST_Pages();
		$created    = $controller->create_scoped_api_key( 'test key', $scopes, 'custom' );
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/pages/' . $id, array( 'id' => $id ), array( 'X-API-Key' => $created['key'] ) );
		$this->assertTrue( $controller->verify_api_key( $request ), 'verify_api_key should succeed' );
		return array( $controller, $request );
	}

	private function authed_pages_slug( string $slug, array $scopes ): array {
		$controller = new Mcpwp_REST_Pages();
		$created    = $controller->create_scoped_api_key( 'test key', $scopes, 'custom' );
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/pages/by-slug/' . $slug, array( 'slug' => $slug ), array( 'X-API-Key' => $created['key'] ) );
		$this->assertTrue( $controller->verify_api_key( $request ), 'verify_api_key should succeed' );
		return array( $controller, $request );
	}

	private function authed_posts( int $id, array $scopes ): array {
		$controller = new Mcpwp_REST_Posts();
		$created    = $controller->create_scoped_api_key( 'test key', $scopes, 'custom' );
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/posts/' . $id, array( 'id' => $id ), array( 'X-API-Key' => $created['key'] ) );
		$this->assertTrue( $controller->verify_api_key( $request ), 'verify_api_key should succeed' );
		return array( $controller, $request );
	}

	private function assert404( $resp ): void {
		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'not_found', $resp->get_error_code() );
		$this->assertSame( 404, $resp->get_error_data()['status'] );
	}

	// ── GET /pages/{id} ──────────────────────────────────────────────────

	public function test_page_draft_read_key_blocked_as_404(): void {
		[ $c, $r ] = $this->authed_pages( self::DRAFT_PAGE_ID, array( 'read' ) );
		$this->assert404( $c->get_page( $r ) );
	}

	public function test_page_draft_write_key_returns_body(): void {
		[ $c, $r ] = $this->authed_pages( self::DRAFT_PAGE_ID, array( 'write' ) );
		$resp = $c->get_page( $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'draft', $resp->get_data()['status'] );
	}

	public function test_page_published_read_key_returns_body(): void {
		[ $c, $r ] = $this->authed_pages( self::PUB_PAGE_ID, array( 'read' ) );
		$resp = $c->get_page( $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'publish', $resp->get_data()['status'] );
	}

	// ── GET /pages/by-slug/{slug} ────────────────────────────────────────
	// (get_page_by_path is stubbed to publish-only in bootstrap, so the draft
	//  slug resolves to null → 404 regardless; the write-key path proves the
	//  gate does not over-block a resolvable published page.)

	public function test_page_by_slug_published_read_key_returns_body(): void {
		[ $c, $r ] = $this->authed_pages_slug( 'public-page', array( 'read' ) );
		$resp = $c->get_page_by_slug( $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'publish', $resp->get_data()['status'] );
	}

	// ── GET /posts/{id} ──────────────────────────────────────────────────

	public function test_post_draft_read_key_blocked_as_404(): void {
		[ $c, $r ] = $this->authed_posts( self::DRAFT_POST_ID, array( 'read' ) );
		$this->assert404( $c->get_post( $r ) );
	}

	public function test_post_draft_write_key_returns_body(): void {
		[ $c, $r ] = $this->authed_posts( self::DRAFT_POST_ID, array( 'write' ) );
		$resp = $c->get_post( $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'draft', $resp->get_data()['status'] );
	}

	public function test_post_published_read_key_returns_body(): void {
		[ $c, $r ] = $this->authed_posts( self::PUB_POST_ID, array( 'read' ) );
		$resp = $c->get_post( $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'publish', $resp->get_data()['status'] );
	}
}
