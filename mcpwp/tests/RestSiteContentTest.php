<?php
/**
 * Regression tests — private/draft content exposure via /search and /fetch.
 *
 * A read-only key must never be able to enumerate non-public titles/slugs/URLs
 * through /search, nor retrieve an unpublished post body through /fetch (by id
 * or by url). Write/admin keys keep full access; published content is never
 * withheld from read keys.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// The controller under test is not loaded by the shared bootstrap.
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-content.php';

// Minimal WP_Post double: the controller gates on `instanceof WP_Post`, and the
// post stubs in bootstrap.php return whatever is seeded into
// $GLOBALS['_mcpwp_test_posts']. Declaring the properties keeps PHP 8.2+ from
// warning about dynamic properties.
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID                = 0;
		public $post_type         = 'post';
		public $post_title        = '';
		public $post_name         = '';
		public $post_status       = 'publish';
		public $post_content      = '';
		public $post_excerpt      = '';
		public $post_date         = '';
		public $post_date_gmt     = '';
		public $post_modified     = '';
		public $post_modified_gmt = '';
		public $post_parent       = 0;
		public $menu_order        = 0;
		public $post_author       = 0;

		public function __construct( array $data = array() ) {
			foreach ( $data as $key => $value ) {
				$this->{$key} = $value;
			}
		}
	}
}

final class RestSiteContentTest extends TestCase {

	/** Search term present in both the published and draft fixtures. */
	private const TERM = 'zebra';

	private const PUBLISHED_ID = 101;
	private const DRAFT_ID     = 202;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['_mcpwp_test_meta']              = array();

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PUBLISHED_ID => new WP_Post(
				array(
					'ID'           => self::PUBLISHED_ID,
					'post_type'    => 'page',
					'post_title'   => 'Public zebra page',
					'post_name'    => 'public-page',
					'post_status'  => 'publish',
					'post_content' => 'A public zebra body.',
					'post_excerpt' => 'Public excerpt.',
				)
			),
			self::DRAFT_ID     => new WP_Post(
				array(
					'ID'           => self::DRAFT_ID,
					'post_type'    => 'page',
					'post_title'   => 'Secret zebra draft',
					'post_name'    => 'secret-draft',
					'post_status'  => 'draft',
					'post_content' => 'A secret zebra body.',
					'post_excerpt' => 'Secret excerpt.',
				)
			),
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Mint a scoped key on the controller, authenticate a GET request with it
	 * (populating current_api_key_record), then return both for handler calls.
	 *
	 * @param string $route  REST route.
	 * @param array  $params Query params.
	 * @param array  $scopes Scopes for the minted key.
	 * @return array{0:Mcpwp_REST_Site_Content,1:WP_REST_Request}
	 */
	private function authed( string $route, array $params, array $scopes ): array {
		$controller = new Mcpwp_REST_Site_Content();
		$created    = $controller->create_scoped_api_key( 'test key', $scopes, 'custom' );

		$request = new WP_REST_Request(
			'GET',
			$route,
			$params,
			array( 'X-API-Key' => $created['key'] )
		);

		$auth = $controller->verify_api_key( $request );
		$this->assertTrue( $auth, 'verify_api_key should succeed for a valid key' );

		return array( $controller, $request );
	}

	private function search( array $scopes, array $params ) {
		[ $controller, $request ] = $this->authed( '/mcpwp/v1/search', $params, $scopes );
		return $controller->search_content( $request );
	}

	private function fetch( array $scopes, array $params ) {
		[ $controller, $request ] = $this->authed( '/mcpwp/v1/fetch', $params, $scopes );
		return $controller->fetch_content( $request );
	}

	// ── Case 1: read key, status=draft → clamped to publish + note ───────

	public function test_search_draft_status_read_key_clamps_to_publish(): void {
		$resp = $this->search( array( 'read' ), array( 'query' => self::TERM, 'status' => 'draft' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );

		$data = $resp->get_data();
		$this->assertSame( 'publish', $data['status'] );
		$this->assertTrue( $data['status_downgraded'] );
		foreach ( $data['items'] as $item ) {
			$this->assertSame( 'publish', $item['status'] );
		}
	}

	// ── Case 2: write key, status=draft → drafts honored, no downgrade ───

	public function test_search_draft_status_write_key_returns_drafts(): void {
		$resp = $this->search( array( 'write' ), array( 'query' => self::TERM, 'status' => 'draft' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );

		$data = $resp->get_data();
		$this->assertArrayNotHasKey( 'status_downgraded', $data );
		$this->assertContains( 'draft', array_column( $data['items'], 'status' ) );
	}

	// ── Case 3: read key, status=any → clamped (the "any" leak vector) ───

	public function test_search_any_status_read_key_clamps_to_publish(): void {
		$resp = $this->search( array( 'read' ), array( 'query' => self::TERM, 'status' => 'any' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );

		$data = $resp->get_data();
		$this->assertSame( 'publish', $data['status'] );
		$this->assertTrue( $data['status_downgraded'] );
		foreach ( $data['items'] as $item ) {
			$this->assertSame( 'publish', $item['status'] );
		}
	}

	// ── Case 4: read key, status=publish → no false downgrade ────────────

	public function test_search_publish_status_read_key_not_downgraded(): void {
		$resp = $this->search( array( 'read' ), array( 'query' => self::TERM, 'status' => 'publish' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );

		$data = $resp->get_data();
		$this->assertArrayNotHasKey( 'status_downgraded', $data );
		foreach ( $data['items'] as $item ) {
			$this->assertSame( 'publish', $item['status'] );
		}
	}

	// ── Case 4b: read key, array-valued status → clamps safely, no warning ─

	public function test_search_array_status_read_key_clamps_to_publish(): void {
		$resp = $this->search( array( 'read' ), array( 'query' => self::TERM, 'status' => array( 'draft' ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );

		$data = $resp->get_data();
		$this->assertSame( 'publish', $data['status'] );
		foreach ( $data['items'] as $item ) {
			$this->assertSame( 'publish', $item['status'] );
		}
	}

	// ── Case 5: read key, fetch draft by id → 404 not_found ──────────────

	public function test_fetch_draft_by_id_read_key_returns_404(): void {
		$resp = $this->fetch( array( 'read' ), array( 'id' => self::DRAFT_ID ) );

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'not_found', $resp->get_error_code() );
		$this->assertSame( 404, $resp->get_error_data()['status'] );
	}

	// ── Case 6: write key, fetch draft by id → 200 with draft ────────────

	public function test_fetch_draft_by_id_write_key_returns_draft(): void {
		$resp = $this->fetch( array( 'write' ), array( 'id' => self::DRAFT_ID ) );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'draft', $resp->get_data()['status'] );
	}

	// ── Case 7: read key, fetch draft by url → 404 not_found ─────────────

	public function test_fetch_draft_by_url_read_key_returns_404(): void {
		$resp = $this->fetch( array( 'read' ), array( 'url' => 'https://example.com/secret-draft' ) );

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'not_found', $resp->get_error_code() );
		$this->assertSame( 404, $resp->get_error_data()['status'] );
	}

	// ── Case 7b: read key, fetch draft by ?page_id= query → 404 ──────────
	// Exercises the url_to_postid() resolver path (plain permalink) rather than
	// the slug resolver, proving fetch_content's own guard catches it.

	public function test_fetch_draft_by_page_id_query_read_key_returns_404(): void {
		$resp = $this->fetch( array( 'read' ), array( 'url' => 'https://example.com/?page_id=' . self::DRAFT_ID ) );

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'not_found', $resp->get_error_code() );
		$this->assertSame( 404, $resp->get_error_data()['status'] );
	}

	// ── Case 8: write key, fetch draft by url → 200 with draft ───────────

	public function test_fetch_draft_by_url_write_key_returns_draft(): void {
		$resp = $this->fetch( array( 'write' ), array( 'url' => 'https://example.com/secret-draft' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'draft', $resp->get_data()['status'] );
	}

	// ── Case 9: read key, fetch published by id → 200 (never regresses) ──

	public function test_fetch_published_by_id_read_key_returns_200(): void {
		$resp = $this->fetch( array( 'read' ), array( 'id' => self::PUBLISHED_ID ) );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'publish', $resp->get_data()['status'] );
	}

	// ── Render endpoints: a read key must not reach an unpublished body ──

	private function rendered_copy( array $scopes, array $params ) {
		[ $controller, $request ] = $this->authed( '/mcpwp/v1/rendered-copy', $params, $scopes );
		return $controller->get_rendered_copy( $request );
	}

	private function rendered_html( array $scopes, array $params ) {
		[ $controller, $request ] = $this->authed( '/mcpwp/v1/rendered-html', $params, $scopes );
		return $controller->get_rendered_html( $request );
	}

	public function test_rendered_copy_draft_read_key_blocked_as_404(): void {
		$resp = $this->rendered_copy( array( 'read' ), array( 'id' => self::DRAFT_ID ) );

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'not_found', $resp->get_error_code() );
		$this->assertSame( 404, $resp->get_error_data()['status'] );
	}

	public function test_rendered_html_draft_read_key_blocked_as_404(): void {
		$resp = $this->rendered_html( array( 'read' ), array( 'id' => self::DRAFT_ID ) );

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'not_found', $resp->get_error_code() );
		$this->assertSame( 404, $resp->get_error_data()['status'] );
	}

	// ── post-meta: non-public meta gated to write scope ──────────────────

	private function post_meta( array $scopes, array $params ) {
		[ $controller, $request ] = $this->authed( '/mcpwp/v1/post-meta', $params, $scopes );
		return $controller->get_post_meta_handler( $request );
	}

	public function test_post_meta_draft_read_key_blocked_as_404(): void {
		$GLOBALS['_mcpwp_test_meta'][ self::DRAFT_ID ]['price'] = '999';

		$resp = $this->post_meta( array( 'read' ), array( 'id' => self::DRAFT_ID, 'key' => 'price' ) );

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'not_found', $resp->get_error_code() );
		$this->assertSame( 404, $resp->get_error_data()['status'] );
	}

	public function test_post_meta_draft_write_key_returns_value(): void {
		$GLOBALS['_mcpwp_test_meta'][ self::DRAFT_ID ]['price'] = '999';

		$resp = $this->post_meta( array( 'write' ), array( 'id' => self::DRAFT_ID, 'key' => 'price' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( '999', $resp->get_data()['value'] );
	}

	public function test_post_meta_published_read_key_returns_value(): void {
		$GLOBALS['_mcpwp_test_meta'][ self::PUBLISHED_ID ]['color'] = 'blue';

		$resp = $this->post_meta( array( 'read' ), array( 'id' => self::PUBLISHED_ID, 'key' => 'color' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'blue', $resp->get_data()['value'] );
	}
}
