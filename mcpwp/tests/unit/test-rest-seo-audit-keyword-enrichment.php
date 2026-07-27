<?php
/**
 * Regression tests — final whole-branch review, IMPORTANT 1.
 *
 * Mcpwp_Keyword_Research::enrich() had zero production callers: the live
 * keyword_research() REST path called only research(), so a Pro customer with
 * valid DataForSEO credentials configured received byte-identical output to
 * an unconfigured site — including the note telling them to configure the
 * provider they had already configured. This wires enrich() into the REST
 * path and proves the paid lookup goes through Mcpwp_SEO_Cache, never a
 * second, uncached call (enrich() itself calls the provider directly when
 * given no pre-fetched metrics).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// Not pulled in by the main bootstrap — no earlier test needed this combination.
require_once dirname( __DIR__, 2 ) . '/includes/api/class-mcpwp-rest-api.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/class-mcpwp-rest-seo-audit.php';
require_once dirname( __DIR__, 2 ) . '/includes/core/class-mcpwp-keyword-research.php';
require_once dirname( __DIR__, 2 ) . '/includes/core/class-mcpwp-seo-cache.php';

/**
 * Duck-typed stand-in for Mcpwp_Provider_Dataforseo — only keyword_suggestions()
 * is called from this path, so no live API or credential plumbing is required.
 */
class Mcpwp_Test_Stub_Dataforseo_Keyword_Provider {

	/** @var array|WP_Error Result to return from keyword_suggestions(). */
	public $result = array();

	/** @var int Number of times keyword_suggestions() was invoked. */
	public $calls = 0;

	public function keyword_suggestions( $seed, $args = array() ) {
		$this->calls++;
		return $this->result;
	}
}

/**
 * Testable subclass: injects a stub provider instead of building a real one
 * from stored (encrypted) integration config — same pattern as
 * Mcpwp_REST_SEO_Market_Testable in test-seo-market-debounce.php.
 */
class Mcpwp_REST_SEO_Audit_Testable extends Mcpwp_REST_SEO_Audit {

	/** @var object|null Provider to return from get_dataforseo_provider(). */
	public $stub_provider = null;

	protected function get_dataforseo_provider() {
		return $this->stub_provider;
	}
}

class RestSeoAuditKeywordEnrichmentTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
	}

	private function make_request( $seed = 'wordpress mcp' ) {
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/seo/keyword-research' );
		$request->set_param( 'seed', $seed );
		return $request;
	}

	private function metrics_row() {
		return array(
			'keyword'            => 'wordpress mcp server',
			'search_volume'      => 210,
			'cpc'                => 3.1,
			'competition'        => 0.2,
			'competition_level'  => 'LOW',
			'keyword_difficulty' => 8,
		);
	}

	public function test_no_provider_keeps_the_free_tier_note() {
		$controller                = new Mcpwp_REST_SEO_Audit_Testable();
		$controller->stub_provider = null;

		$response = $controller->keyword_research( $this->make_request() );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'note', $data );
		$this->assertSame( array(), $data['metrics'] );
		$this->assertSame( 'not_configured', $data['enrichment_reason'] );
	}

	/**
	 * The regression this guards against: a Pro customer who configured
	 * DataForSEO must see numbers, not the apology telling them to configure
	 * the provider they already configured.
	 */
	public function test_configured_provider_replaces_the_note_with_metrics() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Keyword_Provider();
		$provider->result = array( $this->metrics_row() );

		$controller                = new Mcpwp_REST_SEO_Audit_Testable();
		$controller->stub_provider = $provider;

		$response = $controller->keyword_research( $this->make_request() );
		$data     = $response->get_data();

		$this->assertArrayNotHasKey( 'note', $data );
		$this->assertNotEmpty( $data['metrics'] );
		$this->assertSame( 210, $data['metrics'][0]['search_volume'] );
		$this->assertNull( $data['enrichment_reason'] );
	}

	public function test_provider_error_is_surfaced_not_swallowed() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Keyword_Provider();
		$provider->result = new WP_Error( 'insufficient_credits', 'no credit' );

		$controller                = new Mcpwp_REST_SEO_Audit_Testable();
		$controller->stub_provider = $provider;

		$response = $controller->keyword_research( $this->make_request() );
		$data     = $response->get_data();

		$this->assertSame( array(), $data['metrics'] );
		$this->assertSame( 'insufficient_credits', $data['enrichment_reason'] );
	}

	/**
	 * The regression this guards against: enrich() calling the provider
	 * directly (as it did before this fix wired a cache path in front of it)
	 * would make every request to this endpoint a fresh paid call, even for
	 * the exact same seed within the cache TTL.
	 */
	public function test_second_call_for_same_seed_does_not_call_provider_again() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Keyword_Provider();
		$provider->result = array( $this->metrics_row() );

		$controller                = new Mcpwp_REST_SEO_Audit_Testable();
		$controller->stub_provider = $provider;

		$controller->keyword_research( $this->make_request( 'wordpress mcp' ) );
		$controller->keyword_research( $this->make_request( 'wordpress mcp' ) );

		$this->assertSame(
			1,
			$provider->calls,
			'A repeated request for the same seed must be served from Mcpwp_SEO_Cache, not a second paid call.'
		);
	}

	public function test_different_seeds_do_call_provider_separately() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Keyword_Provider();
		$provider->result = array( $this->metrics_row() );

		$controller                = new Mcpwp_REST_SEO_Audit_Testable();
		$controller->stub_provider = $provider;

		$controller->keyword_research( $this->make_request( 'wordpress mcp' ) );
		$controller->keyword_research( $this->make_request( 'wordpress hosting' ) );

		$this->assertSame( 2, $provider->calls );
	}
}
