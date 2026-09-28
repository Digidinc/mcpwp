<?php
/**
 * Regression tests — draft/pending/private leak via the SEO/content read
 * surfaces that read $post->post_content (or enumerate draft nodes) and return
 * draft-derived data with HTTP 200:
 *
 *   GET  /seo/structured-data/{id}   Mcpwp_REST_SEO_Audit::validate_structured_data
 *   GET  /seo/media/{id}             Mcpwp_REST_SEO_Audit::audit_media_seo
 *   GET  /seo/readiness/{id}         Mcpwp_REST_SEO_Audit::validate_seo_readiness
 *   GET  /seo/content-quality/{id}   Mcpwp_REST_Content_Quality::audit_content_quality
 *   GET  /content-graph              Mcpwp_REST_Content_Graph::get_content_graph
 *   GET  /drafts                     Mcpwp_REST_Posts::list_drafts
 *
 * Second fix pass of PR #683: closes the sibling-endpoint bypass class the first
 * pass left open on these read-scoped handlers.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-posts.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-drafts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content-quality.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-seo-audit.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content-graph.php';

final class RestSeoContentDraftLeakTest extends TestCase {

	private const PUB_ID     = 701;
	private const DRAFT_ID   = 702;
	private const PENDING_ID = 703;
	private const PRIVATE_ID = 704;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['_mcpwp_test_meta']              = array();

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PUB_ID     => new WP_Post(
				array(
					'ID'           => self::PUB_ID,
					'post_type'    => 'post',
					'post_title'   => 'Public post',
					'post_name'    => 'public-post',
					'post_status'  => 'publish',
					'post_content' => 'Public post body with enough words to be scored by the content quality and readiness audits so the positive path runs to completion without tripping thin-content guards or any early return that would mask the real behaviour under test here today.',
				)
			),
			self::DRAFT_ID   => new WP_Post(
				array(
					'ID'           => self::DRAFT_ID,
					'post_type'    => 'post',
					'post_title'   => 'Secret draft',
					'post_name'    => 'secret-draft',
					'post_status'  => 'draft',
					'post_content' => 'SECRET draft body with enough words to be scored by the content quality and readiness audits so the write-key positive path runs to completion without tripping thin-content guards or any early return that would mask the behaviour under test right now.',
				)
			),
			self::PENDING_ID => new WP_Post(
				array(
					'ID'           => self::PENDING_ID,
					'post_type'    => 'post',
					'post_title'   => 'Pending post',
					'post_name'    => 'pending-post',
					'post_status'  => 'pending',
					'post_content' => 'PENDING body content.',
				)
			),
			self::PRIVATE_ID => new WP_Post(
				array(
					'ID'           => self::PRIVATE_ID,
					'post_type'    => 'post',
					'post_title'   => 'Private post',
					'post_name'    => 'private-post',
					'post_status'  => 'private',
					'post_content' => 'PRIVATE body content.',
				)
			),
		);
	}

	/**
	 * Build a controller with a scoped key and an authenticated GET request.
	 *
	 * @param object $controller Controller instance.
	 * @param string $route      REST route.
	 * @param array  $params     Request params.
	 * @param array  $scopes     Key scopes.
	 * @return WP_REST_Request Authenticated request.
	 */
	private function authed( $controller, string $route, array $params, array $scopes ): WP_REST_Request {
		$created = $controller->create_scoped_api_key( 'test key', $scopes, 'custom' );
		$request = new WP_REST_Request( 'GET', $route, $params, array( 'X-API-Key' => $created['key'] ) );
		$this->verdict = $controller->verify_api_key( $request );
		return $request;
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

	private function assertNoSecretLeak( WP_REST_Response $resp ): void {
		$this->assertStringNotContainsStringIgnoringCase( 'SECRET', wp_json_encode( $resp->get_data() ) );
	}

	// ── GET /seo/structured-data/{id} ───────────────────────────────────────

	public function test_structured_data_draft_read_key_blocked_as_404(): void {
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, '/mcpwp/v1/seo/structured-data/' . self::DRAFT_ID, array( 'id' => self::DRAFT_ID ), array( 'read' ) );
		$this->assert404( $this->dispatch( $c, 'validate_structured_data', $r ) );
	}

	public function test_structured_data_draft_write_key_returns_data(): void {
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, '/mcpwp/v1/seo/structured-data/' . self::DRAFT_ID, array( 'id' => self::DRAFT_ID ), array( 'write' ) );
		$resp = $this->dispatch( $c, 'validate_structured_data', $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
	}

	public function test_structured_data_published_read_key_returns_data(): void {
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, '/mcpwp/v1/seo/structured-data/' . self::PUB_ID, array( 'id' => self::PUB_ID ), array( 'read' ) );
		$resp = $this->dispatch( $c, 'validate_structured_data', $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
	}

	// ── GET /seo/media/{id} ─────────────────────────────────────────────────

	public function test_media_draft_read_key_blocked_as_404(): void {
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, '/mcpwp/v1/seo/media/' . self::DRAFT_ID, array( 'id' => self::DRAFT_ID ), array( 'read' ) );
		$this->assert404( $this->dispatch( $c, 'audit_media_seo', $r ) );
	}

	public function test_media_draft_write_key_returns_data(): void {
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, '/mcpwp/v1/seo/media/' . self::DRAFT_ID, array( 'id' => self::DRAFT_ID ), array( 'write' ) );
		$resp = $this->dispatch( $c, 'audit_media_seo', $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
	}

	public function test_media_published_read_key_returns_data(): void {
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, '/mcpwp/v1/seo/media/' . self::PUB_ID, array( 'id' => self::PUB_ID ), array( 'read' ) );
		$resp = $this->dispatch( $c, 'audit_media_seo', $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
	}

	// ── GET /seo/readiness/{id} ─────────────────────────────────────────────

	public function test_readiness_draft_read_key_blocked_as_404(): void {
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, '/mcpwp/v1/seo/readiness/' . self::DRAFT_ID, array( 'id' => self::DRAFT_ID ), array( 'read' ) );
		$resp = $this->dispatch( $c, 'validate_seo_readiness', $r );
		$this->assert404( $resp );
	}

	public function test_readiness_draft_write_key_returns_signals(): void {
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, '/mcpwp/v1/seo/readiness/' . self::DRAFT_ID, array( 'id' => self::DRAFT_ID ), array( 'write' ) );
		$resp = $this->dispatch( $c, 'validate_seo_readiness', $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		// A write key is entitled to the draft's signals — no secret assertion here.
	}

	public function test_readiness_published_read_key_returns_signals(): void {
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, '/mcpwp/v1/seo/readiness/' . self::PUB_ID, array( 'id' => self::PUB_ID ), array( 'read' ) );
		$resp = $this->dispatch( $c, 'validate_seo_readiness', $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
	}

	// Pending + private generalization — proves is_public_status() gates beyond draft.

	public function test_readiness_pending_read_key_blocked_as_404(): void {
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, '/mcpwp/v1/seo/readiness/' . self::PENDING_ID, array( 'id' => self::PENDING_ID ), array( 'read' ) );
		$this->assert404( $this->dispatch( $c, 'validate_seo_readiness', $r ) );
	}

	public function test_readiness_private_read_key_blocked_as_404(): void {
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, '/mcpwp/v1/seo/readiness/' . self::PRIVATE_ID, array( 'id' => self::PRIVATE_ID ), array( 'read' ) );
		$this->assert404( $this->dispatch( $c, 'validate_seo_readiness', $r ) );
	}

	// ── GET /seo/content-quality/{id} ───────────────────────────────────────

	public function test_content_quality_draft_read_key_blocked_as_404(): void {
		$c = new Mcpwp_REST_Content_Quality();
		$r = $this->authed( $c, '/mcpwp/v1/seo/content-quality/' . self::DRAFT_ID, array( 'id' => self::DRAFT_ID ), array( 'read' ) );
		$this->assert404( $this->dispatch( $c, 'audit_content_quality', $r ) );
	}

	public function test_content_quality_draft_write_key_returns_data(): void {
		$c = new Mcpwp_REST_Content_Quality();
		$r = $this->authed( $c, '/mcpwp/v1/seo/content-quality/' . self::DRAFT_ID, array( 'id' => self::DRAFT_ID ), array( 'write' ) );
		$resp = $this->dispatch( $c, 'audit_content_quality', $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		// A write key is entitled to the draft's content signals — no secret assertion here.
	}

	public function test_content_quality_published_read_key_returns_data(): void {
		$c = new Mcpwp_REST_Content_Quality();
		$r = $this->authed( $c, '/mcpwp/v1/seo/content-quality/' . self::PUB_ID, array( 'id' => self::PUB_ID ), array( 'read' ) );
		$resp = $this->dispatch( $c, 'audit_content_quality', $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
	}

	// ── GET /content-graph (include_drafts clamp) ───────────────────────────

	private function graph_statuses( WP_REST_Response $resp ): array {
		$data = $resp->get_data();
		$statuses = array();
		foreach ( $data['nodes'] as $node ) {
			$statuses[ $node['status'] ] = true;
		}
		return array_keys( $statuses );
	}

	public function test_content_graph_read_key_include_drafts_yields_zero_draft_nodes(): void {
		$c = new Mcpwp_REST_Content_Graph();
		$r = $this->authed(
			$c,
			'/mcpwp/v1/content-graph',
			array( 'post_types' => 'post', 'limit' => 100, 'include_drafts' => true ),
			array( 'read' )
		);
		$resp = $c->get_content_graph( $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$statuses = $this->graph_statuses( $resp );
		$this->assertNotContains( 'draft', $statuses, 'read key must never receive draft nodes' );
		$this->assertNotContains( 'private', $statuses, 'read key must never receive private nodes' );
		$this->assertNoSecretLeak( $resp );
		// The clamp is also reflected in the reported status set.
		$this->assertSame( array( 'publish' ), $resp->get_data()['summary']['statuses'] );
	}

	public function test_content_graph_write_key_include_drafts_yields_draft_nodes(): void {
		$c = new Mcpwp_REST_Content_Graph();
		$r = $this->authed(
			$c,
			'/mcpwp/v1/content-graph',
			array( 'post_types' => 'post', 'limit' => 100, 'include_drafts' => true ),
			array( 'write' )
		);
		$resp = $c->get_content_graph( $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$statuses = $this->graph_statuses( $resp );
		$this->assertContains( 'draft', $statuses, 'write key with include_drafts=true must receive draft nodes' );
	}

	// ── GET /drafts (whole-endpoint gate) ───────────────────────────────────

	public function test_drafts_read_key_returns_empty_downgraded(): void {
		$c = new Mcpwp_REST_Posts();
		$r = $this->authed( $c, '/mcpwp/v1/drafts', array(), array( 'read' ) );
		$resp = $c->list_drafts( $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$data = $resp->get_data();
		$this->assertSame( array(), $data['drafts'], 'read key must not enumerate any drafts' );
		$this->assertSame( 0, $data['total'] );
		$this->assertTrue( $data['status_downgraded'] );
		$this->assertNoSecretLeak( $resp );
	}

	public function test_drafts_write_key_enumerates_drafts(): void {
		$c = new Mcpwp_REST_Posts();
		$r = $this->authed( $c, '/mcpwp/v1/drafts', array(), array( 'write' ) );
		$resp = $c->list_drafts( $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertGreaterThan( 0, $resp->get_data()['total'], 'write key must see drafts' );
	}
}
