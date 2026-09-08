<?php
/**
 * Coverage for #693 — the render tools answered for a page nobody could reach.
 *
 * Reported: `wp_get_rendered_html` returned an Elementor form and a 200 from
 * the internal render, while the same page's public URL returned 404 and a
 * "Coming Soon" page. CTA and form QA passed while real visitors saw no form.
 *
 * The plugin already knew. `get_rendered_html()` renders in-process
 * *specifically because* host-level gates intercept a loopback fetch — the
 * branch that chooses that path says so in a comment naming HostGator's
 * staging lock. Working around a gate and not mentioning it hands the caller a
 * confident answer to a question they did not ask.
 *
 * Three outcomes are kept apart, and the third is the reason this returns a
 * report rather than a boolean:
 *
 *   gated = true   the public route answers with something a visitor cannot use
 *   gated = false  the public route answers 200 with the page itself
 *   gated = null   the check did not happen or could not complete
 *
 * "The public route is fine" and "nobody looked" must never be the same
 * answer. On the rig, where the container cannot resolve its own external
 * permalink, the real check returns exactly that third outcome — which is the
 * correct answer and not a false all-clear.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class PublicRouteParityTest extends TestCase {

	private const PUBLISHED = 9500;
	private const DRAFT     = 9501;

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PUBLISHED => new WP_Post(
				array(
					'ID'          => self::PUBLISHED,
					'post_type'   => 'page',
					'post_title'  => 'Pricing',
					'post_name'   => 'pricing',
					'post_status' => 'publish',
				)
			),
			self::DRAFT     => new WP_Post(
				array(
					'ID'          => self::DRAFT,
					'post_type'   => 'page',
					'post_title'  => 'Unfinished',
					'post_name'   => 'unfinished',
					'post_status' => 'draft',
				)
			),
		);

		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters']    = array();
	}

	protected function tearDown(): void {
		// Cleared on the way OUT as well: a stubbed pre_http_request left
		// installed would answer every outbound fetch in whatever class runs
		// next (#757's family).
		$GLOBALS['_mcpwp_test_posts']     = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters']    = array();
	}

	/**
	 * Stub the outbound fetch.
	 *
	 * @param mixed $response What wp_remote_get should return.
	 */
	private function public_route_returns( $response ): void {
		add_filter(
			'pre_http_request',
			static function () use ( $response ) {
				return $response;
			}
		);
	}

	/**
	 * @param int    $code  HTTP status.
	 * @param string $title Document title.
	 * @return array
	 */
	private function html_response( int $code, string $title ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => '<html><head><title>' . $title . '</title></head><body>x</body></html>',
		);
	}

	// ---------------------------------------------------------------
	// The reported case.
	// ---------------------------------------------------------------

	public function test_a_coming_soon_gate_is_reported_even_though_it_answers_200(): void {
		$this->public_route_returns( $this->html_response( 200, 'Digital Marketing Experts — Coming Soon' ) );

		$report = Mcpwp_Public_Route::check( self::PUBLISHED, true );

		// A 200 alone is not evidence a visitor sees the page. This is the
		// exact shape the report describes.
		$this->assertTrue( $report['gated'] );
		$this->assertSame( 'gate_page', $report['reason'] );
		$this->assertStringContainsString( 'a visitor sees the gate', $report['warning'] );
	}

	public function test_a_non_200_public_route_is_reported(): void {
		$this->public_route_returns( $this->html_response( 404, 'Not Found' ) );

		$report = Mcpwp_Public_Route::check( self::PUBLISHED, true );

		$this->assertTrue( $report['gated'] );
		$this->assertSame( 'http_status', $report['reason'] );
		$this->assertSame( 404, $report['status'] );
	}

	/**
	 * @dataProvider gate_titles
	 * @param string $title A title that identifies a gate.
	 */
	public function test_the_common_gate_pages_are_recognised( string $title ): void {
		$this->public_route_returns( $this->html_response( 200, $title ) );

		$this->assertTrue( Mcpwp_Public_Route::check( self::PUBLISHED, true )['gated'], $title );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function gate_titles(): array {
		return array(
			'hosting coming-soon' => array( 'Acme — Coming Soon' ),
			'construction'        => array( 'Site Under Construction' ),
			'maintenance plugin'  => array( 'Maintenance Mode' ),
			'wp core upgrade'     => array( 'Briefly unavailable for scheduled maintenance.' ),
			'launch page'         => array( 'Launching Soon!' ),
		);
	}

	// ---------------------------------------------------------------
	// Not crying wolf.
	// ---------------------------------------------------------------

	public function test_a_reachable_page_is_reported_as_public(): void {
		$this->public_route_returns( $this->html_response( 200, 'Pricing – Acme' ) );

		$report = Mcpwp_Public_Route::check( self::PUBLISHED, true );

		$this->assertFalse( $report['gated'] );
		$this->assertSame( 'public', $report['reason'] );
		$this->assertArrayNotHasKey( 'warning', $report );
	}

	public function test_a_draft_is_not_reported_as_gated(): void {
		$report = Mcpwp_Public_Route::check( self::DRAFT, true );

		// A draft's public URL is supposed to 404. Calling that a gate would
		// cry wolf on the ordinary case of building before publishing, and a
		// warning that fires constantly is one nobody reads.
		$this->assertFalse( $report['checked'] );
		$this->assertNull( $report['gated'] );
		$this->assertSame( 'not_published', $report['reason'] );
	}

	public function test_a_missing_post_is_not_reported_as_gated(): void {
		$report = Mcpwp_Public_Route::check( 999999, true );

		$this->assertNull( $report['gated'] );
		$this->assertSame( 'no_such_post', $report['reason'] );
	}

	// ---------------------------------------------------------------
	// The third outcome, which is the point.
	// ---------------------------------------------------------------

	public function test_an_unreachable_check_is_not_an_all_clear(): void {
		$this->public_route_returns( new WP_Error( 'http_request_failed', 'cURL error 7: connection refused' ) );

		$report = Mcpwp_Public_Route::check( self::PUBLISHED, true );

		// The whole reason this returns a report rather than a boolean. A
		// false here would say "a visitor can see this page" on the strength
		// of a request that never completed.
		$this->assertFalse( $report['checked'] );
		$this->assertNull( $report['gated'] );
		$this->assertNotFalse( $report['gated'], 'gated must be null, never false, when nothing was observed.' );
		$this->assertStringContainsString( 'unknown', $report['note'] );
	}

	// ---------------------------------------------------------------
	// Cost.
	// ---------------------------------------------------------------

	public function test_the_result_is_cached_so_a_build_loop_does_not_refetch(): void {
		$this->public_route_returns( $this->html_response( 200, 'Pricing – Acme' ) );

		$first = Mcpwp_Public_Route::check( self::PUBLISHED, true );
		$this->assertFalse( $first['cached'] );

		$second = Mcpwp_Public_Route::check( self::PUBLISHED );

		// The render tools are called repeatedly while building; an outbound
		// request per render would make this feature cost more than it saves.
		$this->assertTrue( $second['cached'] );
		$this->assertSame( $first['gated'], $second['gated'] );
	}

	public function test_forgetting_clears_the_cache(): void {
		$this->public_route_returns( $this->html_response( 200, 'Pricing – Acme' ) );
		Mcpwp_Public_Route::check( self::PUBLISHED, true );

		Mcpwp_Public_Route::forget( self::PUBLISHED );

		$this->public_route_returns( $this->html_response( 200, 'Acme — Coming Soon' ) );

		// A page that gets gated after it was checked must be reportable
		// again, or the cache turns a stale all-clear into a permanent one.
		$this->assertTrue( Mcpwp_Public_Route::check( self::PUBLISHED, true )['gated'] );
	}

	// ---------------------------------------------------------------
	// Title extraction.
	// ---------------------------------------------------------------

	public function test_a_title_with_entities_and_attributes_is_read(): void {
		$this->assertSame(
			'Acme — Coming Soon',
			Mcpwp_Public_Route::extract_title( '<html><head><title data-x="1">Acme &mdash; Coming Soon</title></head></html>' )
		);
	}

	public function test_a_body_with_no_title_yields_an_empty_string_not_a_false_match(): void {
		$this->assertSame( '', Mcpwp_Public_Route::extract_title( '<html><body>no title here</body></html>' ) );
		$this->assertNull( Mcpwp_Public_Route::matched_gate_marker( '' ) );
	}
}
