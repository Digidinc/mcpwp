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

	private const PUB_PAGE_ID    = 501;
	private const DRAFT_PAGE_ID  = 502;
	private const PUB_POST_ID    = 601;
	private const DRAFT_POST_ID  = 602;
	// Deliberately absent from $GLOBALS['_mcpwp_test_posts'] — the
	// "genuinely missing id" side of the #1026 parity tests below.
	private const MISSING_PAGE_ID = 599;
	private const MISSING_POST_ID = 699;

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
		$created    = $controller->create_scoped_api_key( 'test key', $scopes, 'custom', array( 'content' ) );
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/pages/' . $id, array( 'id' => $id ), array( 'X-API-Key' => $created['key'] ) );
		$this->verdict = $controller->verify_api_key( $request );
		return array( $controller, $request );
	}

	private function authed_pages_slug( string $slug, array $scopes ): array {
		$controller = new Mcpwp_REST_Pages();
		$created    = $controller->create_scoped_api_key( 'test key', $scopes, 'custom', array( 'content' ) );
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/pages/by-slug/' . $slug, array( 'slug' => $slug ), array( 'X-API-Key' => $created['key'] ) );
		$this->verdict = $controller->verify_api_key( $request );
		return array( $controller, $request );
	}

	private function authed_posts( int $id, array $scopes ): array {
		$controller = new Mcpwp_REST_Posts();
		$created    = $controller->create_scoped_api_key( 'test key', $scopes, 'custom', array( 'content' ) );
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/posts/' . $id, array( 'id' => $id ), array( 'X-API-Key' => $created['key'] ) );
		$this->verdict = $controller->verify_api_key( $request );
		return array( $controller, $request );
	}

	/** @var true|WP_Error Permission verdict of the last authenticated request. */
	private $verdict = true;

	/**
	 * What a real request gets: the permission verdict when it refuses
	 * (#1030 round-5 — the declared-post-ref guard answers a hidden or
	 * missing id itself, before any handler), otherwise the handler's answer.
	 */
	private function dispatch( $controller, string $method, $request ) {
		return true === $this->verdict ? $controller->$method( $request ) : $this->verdict;
	}

	private function assert404( $resp ): void {
		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'not_found', $resp->get_error_code() );
		$this->assertSame( 404, $resp->get_error_data()['status'] );
	}

	/**
	 * Digits in an error's 'hint' text are the ONLY thing allowed to differ
	 * between a guard_non_public_post()-blocked 404 and a genuinely-missing
	 * 404 for a DIFFERENT id — both hints embed "their" id. Normalising them
	 * out lets the two be compared byte-for-byte otherwise (#1026).
	 *
	 * @param string $hint Raw hint text.
	 * @return string Hint text with every digit run replaced by '#'.
	 */
	private function normalize_id_in_hint( string $hint ): string {
		return preg_replace( '/\d+/', '#', $hint );
	}

	/**
	 * #1026: guard_non_public_post()'s 404 for a non-public post must be
	 * byte-identical (id-normalised) to the SAME door's own 404 for a
	 * genuinely missing post — same code, same message, same data KEYS,
	 * same status, same hint template. Before the fix, the guard's hint
	 * carried the generic, unsubstituted "(ID: {id})" template while the
	 * door's own missing-post branch carried a real, id-substituted hint —
	 * two different hints for what is supposed to be one indistinguishable
	 * 404.
	 *
	 * @param WP_Error $missing_resp 404 for a genuinely missing/wrong-type id.
	 * @param WP_Error $guarded_resp 404 for a non-public id, gate-blocked.
	 */
	private function assertSameNotFoundShape( $missing_resp, $guarded_resp ): void {
		$this->assertInstanceOf( WP_Error::class, $missing_resp, 'Test premise: the missing-id case must itself be a WP_Error.' );
		$this->assertInstanceOf( WP_Error::class, $guarded_resp, 'Test premise: the guarded case must itself be a WP_Error.' );

		$this->assertSame( $missing_resp->get_error_code(), $guarded_resp->get_error_code(), 'Error code must match.' );
		$this->assertSame( $missing_resp->get_error_message(), $guarded_resp->get_error_message(), 'Error message must match.' );

		$missing_data = $missing_resp->get_error_data();
		$guarded_data = $guarded_resp->get_error_data();

		$this->assertSame( array_keys( $missing_data ), array_keys( $guarded_data ), 'Error data must carry the same keys (e.g. both have a hint, or neither does).' );
		$this->assertSame( $missing_data['status'], $guarded_data['status'], 'HTTP status must match.' );

		$missing_hint = isset( $missing_data['hint'] ) ? $this->normalize_id_in_hint( $missing_data['hint'] ) : null;
		$guarded_hint = isset( $guarded_data['hint'] ) ? $this->normalize_id_in_hint( $guarded_data['hint'] ) : null;
		$this->assertSame( $missing_hint, $guarded_hint, 'Hint (id-normalised) must match — the templates must be identical even though the embedded id is not.' );
	}

	// ── GET /pages/{id} ──────────────────────────────────────────────────

	public function test_page_draft_read_key_blocked_as_404(): void {
		[ $c, $r ] = $this->authed_pages( self::DRAFT_PAGE_ID, array( 'read' ) );
		$this->assert404( $this->dispatch( $c, 'get_page', $r ) );
	}

	public function test_page_draft_write_key_returns_body(): void {
		[ $c, $r ] = $this->authed_pages( self::DRAFT_PAGE_ID, array( 'write' ) );
		$resp = $this->dispatch( $c, 'get_page', $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'draft', $resp->get_data()['status'] );
	}

	public function test_page_published_read_key_returns_body(): void {
		[ $c, $r ] = $this->authed_pages( self::PUB_PAGE_ID, array( 'read' ) );
		$resp = $this->dispatch( $c, 'get_page', $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'publish', $resp->get_data()['status'] );
	}

	/**
	 * #1026: a read-only key's 404 for the draft page must be byte-identical
	 * (id-normalised) to the 404 for a page id that does not exist at all.
	 * Since #1030 round-5 both are the declared-post-ref guard's canonical
	 * error, dispatched the way a real request is.
	 */
	public function test_page_draft_blocked_404_matches_missing_page_404(): void {
		[ $missing_c, $missing_r ] = $this->authed_pages( self::MISSING_PAGE_ID, array( 'read' ) );
		$missing_resp              = $this->dispatch( $missing_c, 'get_page', $missing_r );

		[ $draft_c, $draft_r ] = $this->authed_pages( self::DRAFT_PAGE_ID, array( 'read' ) );
		$draft_resp            = $this->dispatch( $draft_c, 'get_page', $draft_r );

		$this->assertSameNotFoundShape( $missing_resp, $draft_resp );
	}

	// ── GET /pages/by-slug/{slug} ────────────────────────────────────────
	// (get_page_by_path is stubbed to publish-only in bootstrap, so the draft
	//  slug resolves to null → 404 regardless; the write-key path proves the
	//  gate does not over-block a resolvable published page.)

	public function test_page_by_slug_published_read_key_returns_body(): void {
		[ $c, $r ] = $this->authed_pages_slug( 'public-page', array( 'read' ) );
		$resp = $this->dispatch( $c, 'get_page_by_slug', $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'publish', $resp->get_data()['status'] );
	}

	// ── GET /posts/{id} ──────────────────────────────────────────────────

	public function test_post_draft_read_key_blocked_as_404(): void {
		[ $c, $r ] = $this->authed_posts( self::DRAFT_POST_ID, array( 'read' ) );
		$this->assert404( $this->dispatch( $c, 'get_post', $r ) );
	}

	public function test_post_draft_write_key_returns_body(): void {
		[ $c, $r ] = $this->authed_posts( self::DRAFT_POST_ID, array( 'write' ) );
		$resp = $this->dispatch( $c, 'get_post', $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'draft', $resp->get_data()['status'] );
	}

	public function test_post_published_read_key_returns_body(): void {
		[ $c, $r ] = $this->authed_posts( self::PUB_POST_ID, array( 'read' ) );
		$resp = $this->dispatch( $c, 'get_post', $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'publish', $resp->get_data()['status'] );
	}

	/**
	 * #1026: a read-only key's 404 for the draft post must be byte-identical
	 * (id-normalised) to the same door's 404 for a post id that does not
	 * exist at all. Before the fix, the guard's hint was the generic,
	 * unsubstituted "(ID: {id})" template while Mcpwp_Posts::get_post()'s own
	 * missing-post branch carried a real, id-substituted hint — this is the
	 * exact case #1026 reports.
	 */
	public function test_post_draft_blocked_404_matches_missing_post_404(): void {
		[ $missing_c, $missing_r ] = $this->authed_posts( self::MISSING_POST_ID, array( 'read' ) );
		$missing_resp              = $this->dispatch( $missing_c, 'get_post', $missing_r );

		[ $draft_c, $draft_r ] = $this->authed_posts( self::DRAFT_POST_ID, array( 'read' ) );
		$draft_resp            = $this->dispatch( $draft_c, 'get_post', $draft_r );

		$this->assertSameNotFoundShape( $missing_resp, $draft_resp );
	}
}
