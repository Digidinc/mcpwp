<?php
/**
 * Regression tests for two SEO readiness validator-correctness bugs (P1,
 * fix/3.6.4-seo-readiness-and-ux):
 *
 *   #680 GET /seo/readiness/{id} — robots.txt "sitewide block" false-positive.
 *        get_robots_txt() output was matched with a raw `stripos($robots_txt,
 *        'Disallow: /')`, which matches ANY line starting "Disallow: /" —
 *        "Disallow: /wp-admin/", WooCommerce's "Disallow: /*?add-to-cart=",
 *        etc. — flagging virtually every real WordPress/WooCommerce site as
 *        "search-invisible" with an error-severity, fail-status finding.
 *
 *   #681 GET /seo/readiness/{id} — false failures on template-rendered pages.
 *        Every body-derived check (word count/thin_content, headings/
 *        missing_h1/heading_order_skip, schema hint) read ONLY raw
 *        post_content. A server-rendered template page (content from a PHP
 *        page template or Elementor, not post_content — e.g. the Motion
 *        editorial homepage) has empty/near-empty post_content, so these
 *        checks reported false missing_h1/thin_content/schema_hint_missing
 *        failures even though the live page has a full H1 and body copy.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-seo-audit.php';

final class SeoReadinessValidatorFixesTest extends TestCase {

	private const PUB_ID          = 801;
	private const TEMPLATE_ID     = 802;
	private const EMPTY_ID        = 803;
	private const DRAFT_TMPL_ID   = 804;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['_mcpwp_test_meta']              = array();
		$GLOBALS['mcpwp_test_filters']            = array();

		// Public site by default so get_robots_txt()'s baseline ("Disallow:")
		// doesn't itself trip the sitewide-block check when a test doesn't
		// override robots.txt via the filter.
		$GLOBALS['mcpwp_test_options']['blog_public'] = 1;

		$long_body = str_repeat(
			'Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua ut enim ad minim veniam quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur excepteur sint occaecat cupidatat non proident sunt in culpa qui officia deserunt mollit anim id est laborum. ',
			2
		);

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PUB_ID        => new WP_Post(
				array(
					'ID'           => self::PUB_ID,
					'post_type'    => 'post',
					'post_title'   => 'A normal published post',
					'post_name'    => 'normal-post',
					'post_status'  => 'publish',
					'post_content' => '<h1>A normal published post</h1><p>' . $long_body . '</p>',
				)
			),
			// Empty post_content + a custom page template — mirrors the Motion
			// editorial homepage: real content is rendered by the template, not
			// stored in post_content.
			self::TEMPLATE_ID   => new WP_Post(
				array(
					'ID'           => self::TEMPLATE_ID,
					'post_type'    => 'page',
					'post_title'   => 'Motion Home',
					'post_name'    => 'motion-home',
					'post_status'  => 'publish',
					'post_content' => '',
				)
			),
			// Empty post_content + a custom page template, but nothing renders
			// through it either (genuinely empty page) — must still fail.
			self::EMPTY_ID      => new WP_Post(
				array(
					'ID'           => self::EMPTY_ID,
					'post_type'    => 'page',
					'post_title'   => 'Truly Empty Page',
					'post_name'    => 'truly-empty',
					'post_status'  => 'publish',
					'post_content' => '',
				)
			),
			// Draft + custom page template — write-scope caller only.
			self::DRAFT_TMPL_ID => new WP_Post(
				array(
					'ID'           => self::DRAFT_TMPL_ID,
					'post_type'    => 'page',
					'post_title'   => 'Draft Template Page',
					'post_name'    => 'draft-template',
					'post_status'  => 'draft',
					'post_content' => '',
				)
			),
		);

		$GLOBALS['_mcpwp_test_meta'][ self::TEMPLATE_ID ]['_wp_page_template']   = 'templates/home.php';
		$GLOBALS['_mcpwp_test_meta'][ self::EMPTY_ID ]['_wp_page_template']      = 'templates/home.php';
		$GLOBALS['_mcpwp_test_meta'][ self::DRAFT_TMPL_ID ]['_wp_page_template'] = 'templates/home.php';
	}

	/**
	 * Build a controller with a scoped key and an authenticated GET request.
	 *
	 * @param string $route  REST route.
	 * @param array  $params Query params.
	 * @param array  $scopes Scopes for the minted key.
	 * @return array{0:Mcpwp_REST_SEO_Audit,1:WP_REST_Request}
	 */
	private function authed( string $route, array $params, array $scopes ): array {
		$controller = new Mcpwp_REST_SEO_Audit();
		$created    = $controller->create_scoped_api_key( 'test key', $scopes, 'custom' );

		$request = new WP_REST_Request( 'GET', $route, $params, array( 'X-API-Key' => $created['key'] ) );
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

	private function readiness( int $post_id, array $scopes = array( 'read' ) ) {
		[ $controller, $request ] = $this->authed( '/mcpwp/v1/seo/readiness/' . $post_id, array( 'id' => $post_id ), $scopes );
		return $this->dispatch( $controller, 'validate_seo_readiness', $request );
	}

	private function issueCodes( WP_REST_Response $resp ): array {
		return array_column( $resp->get_data()['issues'], 'code' );
	}

	// ── #680 — robots.txt sitewide-block false positive ────────────────────

	public function test_woocommerce_style_robots_txt_does_not_flag_sitewide_block(): void {
		add_filter(
			'robots_txt',
			static function () {
				return "User-agent: *\nDisallow: /wp-admin/\nDisallow: /*?add-to-cart=\nDisallow: /*?s=\n";
			},
			10,
			2
		);

		$resp = $this->readiness( self::PUB_ID );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertNotContains( 'robots_disallow_root', $this->issueCodes( $resp ), 'per-path Disallow lines must not be read as a sitewide block' );
	}

	public function test_true_sitewide_disallow_root_is_flagged(): void {
		add_filter(
			'robots_txt',
			static function () {
				return "User-agent: *\nDisallow: /\n";
			},
			10,
			2
		);

		$resp = $this->readiness( self::PUB_ID );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$codes = $this->issueCodes( $resp );
		$this->assertContains( 'robots_disallow_root', $codes, 'an exact "Disallow: /" for the wildcard group is a real sitewide block' );

		$issue = current( array_filter( $resp->get_data()['issues'], static function ( $i ) {
			return 'robots_disallow_root' === $i['code'];
		} ) );
		$this->assertSame( 'error', $issue['severity'] );
	}

	public function test_disallow_root_trailing_whitespace_variant_is_flagged(): void {
		// Real-world robots.txt generators sometimes trail a space/CR — the
		// value must still be trimmed to an exact "/" match.
		add_filter(
			'robots_txt',
			static function () {
				return "User-agent: *\nDisallow: /   \n";
			},
			10,
			2
		);

		$resp = $this->readiness( self::PUB_ID );
		$this->assertContains( 'robots_disallow_root', $this->issueCodes( $resp ) );
	}

	public function test_ua_scoped_block_does_not_leak_into_wildcard_group(): void {
		// A block scoped to a specific bad bot must not cause the wildcard
		// ("*", i.e. Googlebot/every other crawler) group to be treated as
		// blocked.
		add_filter(
			'robots_txt',
			static function () {
				return "User-agent: BadBot\nDisallow: /\n\nUser-agent: *\nDisallow: /wp-admin/\n";
			},
			10,
			2
		);

		$resp = $this->readiness( self::PUB_ID );
		$this->assertNotContains( 'robots_disallow_root', $this->issueCodes( $resp ) );
	}

	// ── #681 — false failures on template-rendered pages ────────────────────

	public function test_template_page_with_rendered_h1_and_body_is_not_flagged_thin_or_missing_h1(): void {
		$rendered_body = str_repeat( 'word ', 400 );
		add_filter(
			'the_content',
			static function () use ( $rendered_body ) {
				return '<h1>Motion — Editorial Homepage</h1><p>' . $rendered_body . '</p>';
			}
		);

		$resp = $this->readiness( self::TEMPLATE_ID );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$codes = $this->issueCodes( $resp );

		$this->assertNotContains( 'missing_h1', $codes, 'rendered output has an H1 — must not be reported missing' );
		$this->assertNotContains( 'thin_content', $codes, 'rendered output has 400 words — must not be reported thin' );

		$data = $resp->get_data();
		$this->assertSame( 'rendered_html', $data['body_validation_basis'] );
		$this->assertGreaterThan( 80, $data['checks']['word_count'] );
		$this->assertSame( 1, $data['checks']['h1_count'] );
	}

	public function test_genuinely_empty_template_page_still_reports_failures(): void {
		// _wp_page_template is set, but nothing renders through `the_content`
		// (no filter registered) — the page really is empty, so the false-
		// positive fix must not suppress a genuine failure.
		$resp = $this->readiness( self::EMPTY_ID );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$codes = $this->issueCodes( $resp );

		$this->assertContains( 'missing_h1', $codes );
		$this->assertContains( 'thin_content', $codes );

		$data = $resp->get_data();
		$this->assertSame( 'post_content', $data['body_validation_basis'] );
	}

	public function test_normal_post_content_page_is_unaffected(): void {
		// No custom template, real post_content, published — behavior must be
		// identical to before the fix.
		$resp = $this->readiness( self::PUB_ID );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$codes = $this->issueCodes( $resp );

		$this->assertNotContains( 'missing_h1', $codes );
		$this->assertNotContains( 'thin_content', $codes );

		$data = $resp->get_data();
		$this->assertSame( 'post_content', $data['body_validation_basis'] );
	}

	public function test_draft_template_page_write_scope_uses_rendered_basis(): void {
		$rendered_body = str_repeat( 'word ', 400 );
		add_filter(
			'the_content',
			static function () use ( $rendered_body ) {
				return '<h1>Draft Template Home</h1><p>' . $rendered_body . '</p>';
			}
		);

		$resp = $this->readiness( self::DRAFT_TMPL_ID, array( 'write' ) );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$codes = $this->issueCodes( $resp );

		$this->assertNotContains( 'missing_h1', $codes );
		$this->assertNotContains( 'thin_content', $codes );
		$this->assertSame( 'rendered_html', $resp->get_data()['body_validation_basis'] );
	}

	public function test_draft_template_page_read_scope_is_blocked_as_404(): void {
		// #683 security model: a read-only key must never trigger a render of
		// non-public content — guard_non_public_post() denies it before any
		// rendering path runs.
		$resp = $this->readiness( self::DRAFT_TMPL_ID, array( 'read' ) );
		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'not_found', $resp->get_error_code() );
	}

	// ── #717 — no_internal_outbound_links false negative ───────────────────
	//
	// The flag was decided from the content graph's resolved outbound_count: a
	// link only counted once it resolved to a node in the graph sample. Two
	// real conditions defeat that while the page plainly carries the link:
	//
	//   1. The href is same-host but not resolvable to a post on THIS install
	//      — the case reported on DME page 5774, where the copy links to
	//      https://digitalmarketingexperts.ca/privacy-policy/ while the staging
	//      install serves that page at /staging/5707/privacy-policy/.
	//   2. The target is a real post outside the graph's 100-post window.
	//
	// Both rendered as "No internal outbound links found" — "we could not
	// resolve it" reported as "there isn't one".

	public function test_same_host_link_that_resolves_to_no_post_still_counts(): void {
		// Verbatim shape of the DME text-editor widget copy that was flagged.
		$GLOBALS['_mcpwp_test_posts'][ self::PUB_ID ]->post_content =
			'<h1>Snapshot</h1><p>See our <a href="' . home_url( '/privacy-policy/' ) . '">Privacy Policy</a>.</p>';

		$resp = $this->readiness( self::PUB_ID );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertNotContains(
			'no_internal_outbound_links',
			$this->issueCodes( $resp ),
			'a same-host link is an internal link even when it resolves to no post in this install'
		);
	}

	public function test_page_with_no_links_at_all_is_still_flagged(): void {
		// The guard against over-correcting: the check must still fire.
		$GLOBALS['_mcpwp_test_posts'][ self::PUB_ID ]->post_content = '<h1>Snapshot</h1><p>No links here at all.</p>';

		$resp = $this->readiness( self::PUB_ID );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertContains( 'no_internal_outbound_links', $this->issueCodes( $resp ) );
	}

	public function test_external_and_fragment_only_links_do_not_count_as_internal(): void {
		// A page whose only links leave the site or jump within it has no
		// internal outbound links. Fragments matter here: DME page 5774's
		// buttons link to "#request-your-snapshot", and counting those would
		// have masked the bug rather than fixed it.
		$GLOBALS['_mcpwp_test_posts'][ self::PUB_ID ]->post_content =
			// NOT example.com — the test bootstrap's home_url() is
			// https://example.com, so a link there is same-host and would make
			// this guard pass for the wrong reason.
			'<h1>Snapshot</h1><p><a href="https://another-site.test/other/">Out</a>'
			. ' <a href="#request-your-snapshot">Jump</a>'
			. ' <a href="mailto:hi@another-site.test">Mail</a></p>';

		$resp = $this->readiness( self::PUB_ID );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertContains( 'no_internal_outbound_links', $this->issueCodes( $resp ) );
	}

	public function test_unresolvable_external_host_is_not_counted_as_internal(): void {
		// Adversarial review, 2026-07-27. The first version of this fix asked
		// wp_http_validate_url() whether an href was absolute and resolved
		// against home_url() when it said no. Real WP returns false whenever
		// gethostbyname() fails on the host, so an unreachable external link
		// became home_url('https://x.test/y') = 'https://example.com/https://x.test/y'
		// — our own host — and counted as internal. On a server without
		// outbound DNS that silenced this check for every external link.
		//
		// The original guard test used exactly such a host and passed only
		// because the stub did no DNS.
		$GLOBALS['_mcpwp_test_posts'][ self::PUB_ID ]->post_content =
			'<h1>Snapshot</h1><p><a href="https://never-resolves.invalid/page/">Out</a></p>';

		$resp = $this->readiness( self::PUB_ID );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertContains( 'no_internal_outbound_links', $this->issueCodes( $resp ) );
	}

	public function test_protocol_relative_external_link_is_not_counted_as_internal(): void {
		// '//evil.example/page' has no scheme, so a naive "starts with http"
		// test calls it relative and home_url() makes it ours.
		$GLOBALS['_mcpwp_test_posts'][ self::PUB_ID ]->post_content =
			'<h1>Snapshot</h1><p><a href="//another-site.invalid/page/">Out</a></p>';

		$resp = $this->readiness( self::PUB_ID );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertContains( 'no_internal_outbound_links', $this->issueCodes( $resp ) );
	}

	public function test_site_relative_path_counts_as_internal(): void {
		// The other direction: a bare path is internal and must still count.
		$GLOBALS['_mcpwp_test_posts'][ self::PUB_ID ]->post_content =
			'<h1>Snapshot</h1><p><a href="/privacy-policy/">Privacy</a></p>';

		$resp = $this->readiness( self::PUB_ID );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertNotContains( 'no_internal_outbound_links', $this->issueCodes( $resp ) );
	}

	public function test_internal_link_inside_elementor_widget_settings_counts(): void {
		// post_content is empty on an Elementor page — the link lives only in
		// _elementor_data, which is where the DME link actually was.
		$GLOBALS['_mcpwp_test_posts'][ self::PUB_ID ]->post_content = '<h1>Snapshot</h1><p>' . str_repeat( 'word ', 200 ) . '</p>';
		$GLOBALS['_mcpwp_test_meta'][ self::PUB_ID ]['_elementor_data'] = wp_json_encode(
			array(
				array(
					'id'       => 'sec1',
					'elType'   => 'section',
					'elements' => array(
						array(
							'id'       => 'col1',
							'elType'   => 'column',
							'elements' => array(
								array(
									'id'         => 'w1',
									'elType'     => 'widget',
									'widgetType' => 'text-editor',
									'settings'   => array(
										'editor' => '<p>See our <a href="' . home_url( '/privacy-policy/' ) . '">Privacy Policy</a>.</p>',
									),
								),
							),
						),
					),
				),
			)
		);

		$resp = $this->readiness( self::PUB_ID );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertNotContains( 'no_internal_outbound_links', $this->issueCodes( $resp ) );
	}
}
