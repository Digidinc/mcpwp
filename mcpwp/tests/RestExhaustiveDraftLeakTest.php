<?php
/**
 * Third fix pass of PR #683 — exhaustive draft/private enumeration + approval
 * source-leak closure. Where the earlier passes closed single-post reads, this
 * suite proves the LIST / enumeration surfaces and the approval/diff surfaces
 * never expose non-public titles, URLs, statuses, or bodies to a read-only key:
 *
 *   Primitive A (caller-influenced non-public list inclusion):
 *     GET  /seo/audit?include_drafts=true   Mcpwp_REST_SEO_Audit::audit_seo_site   (BLOCK 1 + BLOCK 3 base builder)
 *     GET  /content?status=any              Mcpwp_REST_Content::list_content
 *     GET  /pages / GET /posts              sanitize_query_args() shared clamp
 *     GET  /seo/woocommerce?status=any      Mcpwp_REST_SEO_Audit::get_woocommerce_seo_report
 *     GET  /seo/issues                      stored-run replay clamp
 *
 *   Primitive B (approval/diff built from a source post):
 *     POST /content-graph/apply-link        Mcpwp_REST_Content_Graph::apply_internal_link (BLOCK 2)
 *     PUT  /blocks/{id} + patch section     Mcpwp_REST_Blocks::set_blocks / patch_block_section
 *     GET  /approvals + /approvals/{id}     public_record redaction (defense in depth)
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-posts.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-drafts.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content-graph.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-seo-audit.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-blocks.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-seo-audit-store.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-seo-autofix.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-site-state.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-signals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-core.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-media.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-design-references.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-info.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-signals.php';

final class RestExhaustiveDraftLeakTest extends TestCase {

	private const PUB_ID    = 901;
	private const DRAFT_ID  = 902;
	private const PUB_PAGE  = 903;
	private const DRAFT_PAGE = 904;
	private const TARGET_ID = 905; // published internal-link target

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['_mcpwp_test_meta']              = array();

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PUB_ID    => new WP_Post(
				array(
					'ID'           => self::PUB_ID,
					'post_type'    => 'post',
					'post_title'   => 'Public post',
					'post_name'    => 'public-post',
					'post_status'  => 'publish',
					'post_content' => 'Public post body with plenty of words to survive any scoring path.',
				)
			),
			self::DRAFT_ID  => new WP_Post(
				array(
					'ID'           => self::DRAFT_ID,
					'post_type'    => 'post',
					'post_title'   => 'SECRET draft title',
					'post_name'    => 'secret-draft',
					'post_status'  => 'draft',
					'post_content' => 'SECRET draft body with plenty of words to survive any scoring path so the positive branch runs.',
				)
			),
			self::PUB_PAGE  => new WP_Post(
				array(
					'ID'           => self::PUB_PAGE,
					'post_type'    => 'page',
					'post_title'   => 'Public page',
					'post_name'    => 'public-page',
					'post_status'  => 'publish',
					'post_content' => 'Public page body.',
				)
			),
			self::DRAFT_PAGE => new WP_Post(
				array(
					'ID'           => self::DRAFT_PAGE,
					'post_type'    => 'page',
					'post_title'   => 'SECRET draft page',
					'post_name'    => 'secret-draft-page',
					'post_status'  => 'draft',
					'post_content' => 'SECRET draft page body.',
				)
			),
			self::TARGET_ID => new WP_Post(
				array(
					'ID'           => self::TARGET_ID,
					'post_type'    => 'post',
					'post_title'   => 'Link target',
					'post_name'    => 'link-target',
					'post_status'  => 'publish',
					'post_content' => 'Target body.',
				)
			),
		);
	}

	/**
	 * Authenticate a GET/POST request against a scoped key on the controller.
	 *
	 * @param object $c      Controller.
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @param array  $params Params.
	 * @param array  $scopes Key scopes.
	 * @return WP_REST_Request Authenticated request.
	 */
	private function authed( $c, string $method, string $route, array $params, array $scopes ): WP_REST_Request {
		// This suite is about draft/private enumeration leaks, not per-key
		// category restriction. Several routes here gate on BOTH their own
		// route category AND the resolved post's type category
		// (guard_post_type_category(), #1024) — reconstructing that exact
		// pair per route/controller is exactly the fixture noise role=admin
		// avoids. Before #1041, role='custom' with no categories resolved to
		// unrestricted by the very bug this suite's sibling test closes;
		// admin is the correct, DOCUMENTED way to get that same property now.
		$created = $c->create_scoped_api_key( 'test key', $scopes, 'admin' );
		$request = new WP_REST_Request( $method, $route, $params, array( 'X-API-Key' => $created['key'] ) );
		$this->assertTrue( $c->verify_api_key( $request ), 'verify_api_key should succeed' );
		return $request;
	}

	private function assertNoSecret( $data ): void {
		$this->assertStringNotContainsStringIgnoringCase( 'SECRET', wp_json_encode( $data ) );
	}

	/**
	 * Assert a read-scoped key is denied at the auth/scope layer for a route —
	 * the primary protection for write/admin-scoped endpoints, which a read-only
	 * key can never reach the handler of. The in-handler source guards on those
	 * endpoints are belt-and-suspenders behind this gate.
	 *
	 * @param object $c      Controller.
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @param string $scope  Expected required scope.
	 */
	private function assertReadKeyScopeDenied( $c, string $method, string $route, string $scope ): void {
		// This asserts scope denial, not category — verify_api_key() checks
		// scope before category, so role is irrelevant here; admin keeps this
		// test independent of any route/post-type category pairing.
		$created = $c->create_scoped_api_key( 'test key', array( 'read' ), 'admin' );
		$request = new WP_REST_Request( $method, $route, array(), array( 'X-API-Key' => $created['key'] ) );
		$result  = $c->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result, 'read key must be denied' );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( $scope, $result->get_error_data()['required_scope'] );
	}

	// ── BLOCK 1 + BLOCK 3: GET /seo/audit?include_drafts=true ───────────────

	public function test_seo_audit_read_key_include_drafts_excludes_all_draft_data(): void {
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/seo/audit', array( 'post_types' => 'post', 'include_drafts' => true, 'limit' => 50 ), array( 'read' ) );
		$resp = $c->audit_seo_site( $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$data = $resp->get_data();
		// The direct urls[] enumeration (BLOCK 1) AND the inherited base
		// build_content_graph_data graph (BLOCK 3) must both be clamped.
		$statuses = array_column( $data['urls'], 'status' );
		$this->assertNotContains( 'draft', $statuses, 'read key must not receive draft URLs in the audit' );
		$this->assertNotContains( 'private', $statuses );
		$this->assertNoSecret( $data );
	}

	public function test_seo_audit_write_key_include_drafts_includes_draft_data(): void {
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/seo/audit', array( 'post_types' => 'post', 'include_drafts' => true, 'limit' => 50 ), array( 'write' ) );
		$resp = $c->audit_seo_site( $r );
		$statuses = array_column( $resp->get_data()['urls'], 'status' );
		$this->assertContains( 'draft', $statuses, 'write key with include_drafts must see draft URLs' );
	}

	// ── BLOCK 2: POST /content-graph/apply-link (write-scoped route) ─────────
	// A read-only key is denied at the scope layer before reaching the handler,
	// so the in-handler non-public source guard is defense-in-depth. The live
	// read-key exposure of BLOCK 2 is the PERSISTED approval body read back via
	// GET /approvals — closed by the redaction tests further down.

	public function test_apply_link_read_key_scope_denied(): void {
		$this->assertReadKeyScopeDenied( new Mcpwp_REST_Content_Graph(), 'POST', '/mcpwp/v1/content-graph/apply-link', 'write' );
	}

	// ── Primitive A: GET /content is admin-scoped ───────────────────────────
	// /content (generic CPT list) requires admin scope for every method, so a
	// read-only key can never enumerate it. The status clamp in the handler is
	// belt-and-suspenders behind this gate.

	public function test_content_list_read_key_scope_denied(): void {
		$this->assertReadKeyScopeDenied( new Mcpwp_REST_Content(), 'GET', '/mcpwp/v1/content', 'admin' );
	}

	// ── Primitive A: GET /pages and GET /posts (sanitize_query_args clamp) ───

	public function test_pages_list_read_key_default_excludes_drafts(): void {
		$c = new Mcpwp_REST_Pages();
		// No status param: the pages model defaults to 'any', so the clamp must
		// override the model default to publish for a read key.
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/pages', array(), array( 'read' ) );
		$resp = $c->list_pages( $r );
		$statuses = array_column( $resp->get_data()['pages'], 'status' );
		$this->assertNotContains( 'draft', $statuses );
		$this->assertNoSecret( $resp->get_data() );
	}

	public function test_pages_list_read_key_explicit_draft_status_excluded(): void {
		$c = new Mcpwp_REST_Pages();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/pages', array( 'status' => 'draft' ), array( 'read' ) );
		$resp = $c->list_pages( $r );
		// The clamp forces status='publish', so the read key sees only published
		// pages (no draft) — never the requested drafts.
		$statuses = array_column( $resp->get_data()['pages'], 'status' );
		$this->assertNotContains( 'draft', $statuses, 'read key asking for drafts gets none' );
		$this->assertNoSecret( $resp->get_data() );
	}

	public function test_pages_list_write_key_draft_status_returns_drafts(): void {
		$c = new Mcpwp_REST_Pages();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/pages', array( 'status' => 'draft' ), array( 'write' ) );
		$resp = $c->list_pages( $r );
		$statuses = array_column( $resp->get_data()['pages'], 'status' );
		$this->assertContains( 'draft', $statuses );
	}

	public function test_posts_list_read_key_draft_status_excluded(): void {
		$c = new Mcpwp_REST_Posts();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/posts', array( 'status' => 'draft' ), array( 'read' ) );
		$resp = $c->list_posts( $r );
		$statuses = array_column( $resp->get_data()['posts'], 'status' );
		$this->assertNotContains( 'draft', $statuses );
		$this->assertNoSecret( $resp->get_data() );
	}

	public function test_posts_list_write_key_draft_status_returns_drafts(): void {
		$c = new Mcpwp_REST_Posts();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/posts', array( 'status' => 'draft' ), array( 'write' ) );
		$resp = $c->list_posts( $r );
		$statuses = array_column( $resp->get_data()['posts'], 'status' );
		$this->assertContains( 'draft', $statuses );
	}

	// ── Primitive B: PUT /blocks/{id} + patch section (write-scoped route) ──
	// Read-only key denied at the scope layer; the in-handler non-public source
	// guard (which stops set_blocks/patch_block_section from reading a draft body
	// into an approval diff) is defense-in-depth behind this gate.

	public function test_set_blocks_read_key_scope_denied(): void {
		$this->assertReadKeyScopeDenied( new Mcpwp_REST_Blocks(), 'POST', '/mcpwp/v1/blocks/' . self::DRAFT_ID, 'write' );
	}

	public function test_patch_section_read_key_scope_denied(): void {
		$this->assertReadKeyScopeDenied( new Mcpwp_REST_Blocks(), 'POST', '/mcpwp/v1/blocks/' . self::DRAFT_ID . '/patch-section', 'write' );
	}

	// ── Primitive B (LIVE read-key vector): GET /approvals redaction ─────────

	private function seed_draft_approval(): void {
		update_option(
			Mcpwp_Approvals::OPTION_NAME,
			array(
				'apr_test1' => array(
					'id'       => 'apr_test1',
					'status'   => 'pending',
					'action'   => 'post_content_update',
					'title'    => 'Update content for #902',
					'resource' => array(
						'type'   => 'post',
						'id'     => self::DRAFT_ID,
						'title'  => 'SECRET draft title',
						'status' => 'draft',
						'url'    => 'https://example.com/?p=902',
					),
					'diff'     => array(
						'type'           => 'content',
						'before_excerpt' => 'SECRET draft body excerpt',
						'after_excerpt'  => 'SECRET proposed body excerpt',
					),
					'created_at' => gmdate( 'c' ),
				),
			),
			false
		);
	}

	public function test_approvals_list_read_key_redacts_draft_source(): void {
		$this->seed_draft_approval();
		$c = new Mcpwp_REST_Approvals();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/approvals', array(), array( 'read' ) );
		$resp = $c->list_approvals( $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertNoSecret( $resp->get_data() );
		$record = $resp->get_data()['approvals'][0];
		$this->assertSame( '', $record['resource']['title'] );
		$this->assertSame( 'restricted', $record['resource']['status'] );
		$this->assertArrayNotHasKey( 'before_excerpt', $record['diff'] );
		$this->assertTrue( $record['redacted'] );
	}

	public function test_approvals_get_read_key_redacts_draft_source(): void {
		$this->seed_draft_approval();
		$c = new Mcpwp_REST_Approvals();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/approvals/apr_test1', array( 'id' => 'apr_test1' ), array( 'read' ) );
		$resp = $c->get_approval( $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertNoSecret( $resp->get_data() );
	}

	public function test_approvals_list_write_key_sees_full_source(): void {
		$this->seed_draft_approval();
		$c = new Mcpwp_REST_Approvals();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/approvals', array(), array( 'write' ) );
		$resp = $c->list_approvals( $r );
		$record = $resp->get_data()['approvals'][0];
		$this->assertSame( 'SECRET draft title', $record['resource']['title'], 'write key sees the real source title' );
		$this->assertArrayHasKey( 'before_excerpt', $record['diff'] );
	}

	// ── Primitive A: GET /seo/woocommerce?status=any ────────────────────────

	private function seed_products(): void {
		$GLOBALS['_mcpwp_test_posts'][950] = (object) array(
			'ID'           => 950,
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'post_title'   => 'Public product',
			'post_content' => '<p>Public product copy.</p>',
			'post_excerpt' => '',
			'modified_ts'  => time(),
		);
		$GLOBALS['_mcpwp_test_posts'][951] = (object) array(
			'ID'           => 951,
			'post_type'    => 'product',
			'post_status'  => 'draft',
			'post_title'   => 'SECRET product',
			'post_content' => '<p>SECRET draft product copy.</p>',
			'post_excerpt' => '',
			'modified_ts'  => time(),
		);
	}

	public function test_woocommerce_report_read_key_status_any_excludes_draft_products(): void {
		$this->seed_products();
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/seo/woocommerce', array( 'status' => 'any', 'limit' => 10 ), array( 'read' ) );
		$resp = $c->get_woocommerce_seo_report( $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertNoSecret( $resp->get_data() );
		$this->assertSame( 1, $resp->get_data()['summary']['products_inspected'] );
	}

	public function test_woocommerce_report_write_key_status_any_includes_draft_products(): void {
		$this->seed_products();
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/seo/woocommerce', array( 'status' => 'any', 'limit' => 10 ), array( 'write' ) );
		$resp = $c->get_woocommerce_seo_report( $r );
		$this->assertSame( 2, $resp->get_data()['summary']['products_inspected'] );
	}

	// ── Primitive A: GET /seo/issues stored-run replay clamp ────────────────

	private function seed_stored_issues(): void {
		update_option(
			'mcpwp_seo_issues',
			array(
				'pub_issue'   => array(
					'id'       => 'pub_issue',
					'status'   => 'open',
					'post_id'  => self::PUB_ID,
					'title'    => 'Public post',
					'url'      => 'https://example.com/?p=901',
					'severity' => 'warning',
					'category' => 'readiness',
					'code'     => 'x',
					'priority_score' => 10,
					'last_seen_at'   => gmdate( 'c' ),
				),
				'draft_issue' => array(
					'id'       => 'draft_issue',
					'status'   => 'open',
					'post_id'  => self::DRAFT_ID,
					'title'    => 'SECRET draft title',
					'url'      => 'https://example.com/?p=902',
					'severity' => 'error',
					'category' => 'readiness',
					'code'     => 'y',
					'priority_score' => 20,
					'last_seen_at'   => gmdate( 'c' ),
				),
			),
			false
		);
	}

	public function test_seo_issues_read_key_excludes_draft_issues(): void {
		$this->seed_stored_issues();
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/seo/issues', array(), array( 'read' ) );
		$resp = $c->get_seo_issues( $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertNoSecret( $resp->get_data() );
		$ids = array_column( $resp->get_data()['issues'], 'id' );
		$this->assertNotContains( 'draft_issue', $ids );
		$this->assertContains( 'pub_issue', $ids );
	}

	public function test_seo_issues_write_key_includes_draft_issues(): void {
		$this->seed_stored_issues();
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/seo/issues', array(), array( 'write' ) );
		$resp = $c->get_seo_issues( $r );
		$ids = array_column( $resp->get_data()['issues'], 'id' );
		$this->assertContains( 'draft_issue', $ids );
	}

	// ── FIX PASS 4: the three UNGATED read surfaces the model chokepoints now
	// protect. These routes had ZERO coverage, which is why the leak survived
	// three passes that gated siblings. Each proves READ key → no non-public
	// source; WRITE key → full data (regression guard). ─────────────────────

	// GET /site-state → Mcpwp_Site_State::get_approval_summary (list_requests)
	//                 + get_seo_summary (list_issues).

	public function test_site_state_read_key_redacts_draft_approval_and_seo(): void {
		$this->seed_draft_approval();
		$this->seed_stored_issues();
		$c = new Mcpwp_REST_Site_Info();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/site-state', array(), array( 'read' ) );
		$resp = $c->get_site_state( $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$data = $resp->get_data();
		$this->assertNoSecret( $data['approvals'] );
		$this->assertNoSecret( $data['seo'] );
		// The published SEO issue still surfaces; only the draft is dropped.
		$issue_ids = array_column( $data['seo']['top_issues'], 'id' );
		$this->assertNotContains( 'draft_issue', $issue_ids );
		$this->assertContains( 'pub_issue', $issue_ids );
	}

	public function test_site_state_write_key_sees_draft_approval_and_seo(): void {
		$this->seed_draft_approval();
		$this->seed_stored_issues();
		$c = new Mcpwp_REST_Site_Info();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/site-state', array(), array( 'write' ) );
		$data = $c->get_site_state( $r )->get_data();
		$issue_ids = array_column( $data['seo']['top_issues'], 'id' );
		$this->assertContains( 'draft_issue', $issue_ids, 'write key must see the draft SEO issue' );
		$this->assertSame(
			'SECRET draft title',
			$data['approvals']['pending_items'][0]['resource']['title'],
			'write key must see the real draft approval source title'
		);
	}

	// GET /signals → Mcpwp_Signals::get_signals read-time redaction (the shared
	// cache is populated full via compute_seo_issues; read redacts per caller).

	public function test_signals_read_key_redacts_seo_issue_title(): void {
		$this->seed_stored_issues();
		$c = new Mcpwp_REST_Signals();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/signals', array(), array( 'read' ) );
		$data = $c->get_signals( $r )->get_data();
		$this->assertNoSecret( $data['signals'] );
		$seo = array_values(
			array_filter( $data['signals'], static fn( $s ) => ( $s['type'] ?? '' ) === 'seo_issue' )
		);
		$this->assertNotEmpty( $seo, 'seo_issue signal should be present but redacted' );
		$this->assertSame( '', $seo[0]['entity_title'] );
	}

	public function test_signals_write_key_sees_seo_issue_title(): void {
		$this->seed_stored_issues();
		$c = new Mcpwp_REST_Signals();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/signals', array(), array( 'write' ) );
		$data = $c->get_signals( $r )->get_data();
		$seo = array_values(
			array_filter( $data['signals'], static fn( $s ) => ( $s['type'] ?? '' ) === 'seo_issue' )
		);
		$this->assertNotEmpty( $seo );
		$this->assertSame( 'SECRET draft title', $seo[0]['entity_title'] );
	}

	// POST /signals/refresh → Mcpwp_REST_Signals::refresh_signals. This handler
	// had ZERO coverage. It called Mcpwp_Signals::compute() (which returns the
	// freshly-computed signals RAW, unredacted) and returned that value directly
	// — bypassing the get_signals() read chokepoint that GET /signals passes
	// through. Two layers protect the draft seo_issue title now:
	//
	//   (1) PRIMARY: /signals/refresh is a POST, so the scope gate requires
	//       'write' scope — a read-only key is denied at auth (403) and never
	//       reaches the handler at all.
	//   (2) DEFENSE-IN-DEPTH: the handler itself now reads the refreshed cache
	//       back through get_signals() gated on requester_can_see_non_public(),
	//       so an unprivileged caller (no write scope) is redacted at the SAME
	//       single chokepoint every other signal read passes. Reverting to
	//       `return compute()` re-opens the leak and fails the direct-invocation
	//       negative below — the TDD bite.

	public function test_refresh_signals_read_key_scope_denied(): void {
		$this->assertReadKeyScopeDenied( new Mcpwp_REST_Signals(), 'POST', '/mcpwp/v1/signals/refresh', 'write' );
	}

	public function test_refresh_signals_unprivileged_caller_redacts_seo_issue_title(): void {
		$this->seed_stored_issues();
		$c = new Mcpwp_REST_Signals();
		// Invoke the handler directly with NO privileged context (no key record
		// set → requester_can_see_non_public() === false), exercising the
		// in-handler redaction chokepoint independently of the route scope gate.
		$r    = new WP_REST_Request( 'POST', '/mcpwp/v1/signals/refresh', array( 'types' => 'seo_issue' ) );
		$resp = $c->refresh_signals( $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$data = $resp->get_data();
		// The freshly-computed seo_issue signal references a DRAFT post — the
		// draft title/detail must not appear anywhere in the response.
		$this->assertNoSecret( $data );
		$seo = array_values(
			array_filter( $data['signals'], static fn( $s ) => ( $s['type'] ?? '' ) === 'seo_issue' )
		);
		$this->assertNotEmpty( $seo, 'seo_issue signal should be present but redacted' );
		$this->assertSame( '', $seo[0]['entity_title'], 'unprivileged caller must not receive the draft entity title' );
		$this->assertTrue( ! empty( $seo[0]['redacted'] ), 'the draft-derived signal is flagged redacted' );
	}

	public function test_refresh_signals_write_key_sees_seo_issue_title(): void {
		$this->seed_stored_issues();
		$c = new Mcpwp_REST_Signals();
		$r = $this->authed( $c, 'POST', '/mcpwp/v1/signals/refresh', array( 'types' => 'seo_issue' ), array( 'write' ) );
		$resp = $c->refresh_signals( $r );
		$this->assertSame( 200, $resp->get_status() );
		$data = $resp->get_data();
		$seo = array_values(
			array_filter( $data['signals'], static fn( $s ) => ( $s['type'] ?? '' ) === 'seo_issue' )
		);
		$this->assertNotEmpty( $seo );
		$this->assertSame( 'SECRET draft title', $seo[0]['entity_title'], 'write key sees the real draft source title' );
	}

	// GET /seo/autofix-plan → Mcpwp_SEO_Autofix::get_plan (list_issues); the
	// plan's actions[] embed each issue title/url.

	public function test_autofix_plan_read_key_redacts_draft_issue(): void {
		$this->seed_stored_issues();
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/seo/autofix-plan', array( 'limit' => 10 ), array( 'read' ) );
		$data = $c->get_seo_autofix_plan( $r )->get_data();
		$this->assertNoSecret( $data );
		$titles = array_column( $data['actions'], 'title' );
		$this->assertNotContains( 'SECRET draft title', $titles );
	}

	public function test_autofix_plan_write_key_sees_draft_issue(): void {
		$this->seed_stored_issues();
		$c = new Mcpwp_REST_SEO_Audit();
		$r = $this->authed( $c, 'GET', '/mcpwp/v1/seo/autofix-plan', array( 'limit' => 10 ), array( 'write' ) );
		$data = $c->get_seo_autofix_plan( $r )->get_data();
		$titles = array_column( $data['actions'], 'title' );
		$this->assertContains( 'SECRET draft title', $titles );
	}
}
