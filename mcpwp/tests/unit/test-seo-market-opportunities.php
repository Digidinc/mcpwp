<?php
/**
 * Regression tests — final whole-branch review, IMPORTANT 2 and IMPORTANT 3.
 *
 * get_opportunities() ('/seo/opportunities') previously collapsed two very
 * different situations into one answer:
 *
 *   - a seed was supplied but DataForSEO isn't configured -> read the same as
 *     "no seed was given" (reason 'no_rankings_needs_seed')
 *   - a seed was supplied, DataForSEO answered with zero rows -> also read
 *     the same as "no seed was given"
 *
 * It was also a GET route that could spend real DataForSEO credit once per
 * distinct caller-supplied seed with no ceiling.
 *
 * Test approach mirrors test-seo-market-debounce.php: subclass
 * Mcpwp_REST_SEO_Market to inject a stub provider and call get_opportunities()
 * directly, bypassing the HTTP/auth stack.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/api/class-mcpwp-rest-api.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/class-mcpwp-rest-seo-market.php';
require_once dirname( __DIR__, 2 ) . '/includes/core/class-mcpwp-provider-dataforseo.php';
require_once dirname( __DIR__, 2 ) . '/includes/core/class-mcpwp-seo-visibility.php';
require_once dirname( __DIR__, 2 ) . '/includes/core/class-mcpwp-seo-opportunities.php';
require_once dirname( __DIR__, 2 ) . '/includes/core/class-mcpwp-seo-cache.php';

/**
 * Duck-typed stand-in for Mcpwp_Provider_Dataforseo's keyword_suggestions().
 */
class Mcpwp_Test_Stub_Dataforseo_Suggestions_Provider {

	/** @var array|WP_Error Result to return. */
	public $result = array();

	/** @var int Number of times keyword_suggestions() was invoked. */
	public $calls = 0;

	public function keyword_suggestions( $seed, $args = array() ) {
		$this->calls++;
		return $this->result;
	}
}

/**
 * Testable subclass: injects a stub provider instead of building a real one.
 */
class Mcpwp_REST_SEO_Market_Opportunities_Testable extends Mcpwp_REST_SEO_Market {

	/** @var object|null Provider to return from get_dataforseo_provider(). */
	public $stub_provider = null;

	protected function get_dataforseo_provider() {
		return $this->stub_provider;
	}
}

class SeoMarketOpportunitiesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
	}

	private function make_request( $seed = null ) {
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/seo/opportunities' );
		if ( null !== $seed ) {
			$request->set_param( 'seed', $seed );
		}
		return $request;
	}

	// ─────────────────────────────────────────────────────────────────────
	// IMPORTANT 2 — credentials absent must not collapse into "no seed given".
	// ─────────────────────────────────────────────────────────────────────

	public function test_seed_given_without_credentials_returns_not_configured_error() {
		$controller                = new Mcpwp_REST_SEO_Market_Opportunities_Testable();
		$controller->stub_provider = null;

		$response = $controller->get_opportunities( $this->make_request( 'wordpress mcp' ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'not_configured', $response->get_error_code() );
	}

	public function test_no_seed_and_no_visibility_still_reads_no_inputs() {
		// Sanity check: the fix above must not touch the pre-existing,
		// already-correct "nobody asked for anything" path.
		$controller                = new Mcpwp_REST_SEO_Market_Opportunities_Testable();
		$controller->stub_provider = null;

		$response = $controller->get_opportunities( $this->make_request() );
		$data     = $response->get_data();

		$this->assertSame( 'no_inputs', $data['reason'] );
	}

	/**
	 * IMPORTANT 2 — a genuinely empty answer for a real seed lookup must not
	 * read the same as "no seed given".
	 */
	public function test_seed_with_zero_suggestions_gets_its_own_reason() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Suggestions_Provider();
		$provider->result = array();

		$controller                = new Mcpwp_REST_SEO_Market_Opportunities_Testable();
		$controller->stub_provider = $provider;

		$response = $controller->get_opportunities( $this->make_request( 'zxqv' ) );
		$data     = $response->get_data();

		$this->assertSame( Mcpwp_SEO_Opportunities::REASON_SEED_NO_SUGGESTIONS, $data['reason'] );
		$this->assertNotSame( 'no_rankings_needs_seed', $data['reason'] );
	}

	public function test_seed_with_real_suggestions_still_ranks_them() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Suggestions_Provider();
		$provider->result = array(
			array( 'keyword' => 'wordpress mcp server', 'search_volume' => 210, 'keyword_difficulty' => 8, 'cpc' => 3.1, 'competition' => 0.2, 'competition_level' => 'LOW' ),
		);

		$controller                = new Mcpwp_REST_SEO_Market_Opportunities_Testable();
		$controller->stub_provider = $provider;

		$response = $controller->get_opportunities( $this->make_request( 'wordpress mcp' ) );
		$data     = $response->get_data();

		$this->assertSame( 'greenfield', $data['mode'] );
		$this->assertNotEmpty( $data['opportunities'] );
		$this->assertSame( 'wordpress mcp server', $data['opportunities'][0]['keyword'] );
	}

	public function test_provider_error_is_returned_as_error_not_empty_result() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Suggestions_Provider();
		$provider->result = new WP_Error( 'insufficient_credits', 'no credit' );

		$controller                = new Mcpwp_REST_SEO_Market_Opportunities_Testable();
		$controller->stub_provider = $provider;

		$response = $controller->get_opportunities( $this->make_request( 'wordpress mcp' ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'insufficient_credits', $response->get_error_code() );
	}

	// ─────────────────────────────────────────────────────────────────────
	// IMPORTANT 3 — distinct-seed lookups are capped, and a cache hit never
	// spends the budget.
	// ─────────────────────────────────────────────────────────────────────

	public function test_repeated_seed_is_served_from_cache_and_does_not_spend_budget() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Suggestions_Provider();
		$provider->result = array(
			array( 'keyword' => 'wordpress mcp server', 'search_volume' => 210, 'keyword_difficulty' => 8 ),
		);

		$controller                = new Mcpwp_REST_SEO_Market_Opportunities_Testable();
		$controller->stub_provider = $provider;

		for ( $i = 0; $i < 5; $i++ ) {
			$controller->get_opportunities( $this->make_request( 'wordpress mcp' ) );
		}

		$this->assertSame( 1, $provider->calls, 'Repeating the same seed must be served from Mcpwp_SEO_Cache.' );
	}

	public function test_distinct_seeds_beyond_the_budget_return_budget_exhausted_not_empty() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Suggestions_Provider();
		$provider->result = array(
			array( 'keyword' => 'x', 'search_volume' => 10, 'keyword_difficulty' => 5 ),
		);

		$controller                = new Mcpwp_REST_SEO_Market_Opportunities_Testable();
		$controller->stub_provider = $provider;

		$last_response = null;
		for ( $i = 0; $i < Mcpwp_REST_SEO_Market::LOOKUP_BUDGET_MAX + 1; $i++ ) {
			$last_response = $controller->get_opportunities( $this->make_request( 'seed-' . $i ) );
		}

		$this->assertSame( Mcpwp_REST_SEO_Market::LOOKUP_BUDGET_MAX, $provider->calls );

		$data = $last_response->get_data();
		$this->assertSame( Mcpwp_REST_SEO_Market::REASON_LOOKUP_BUDGET_EXHAUSTED, $data['reason'] );
		$this->assertArrayHasKey( 'retry_after', $data );
		$this->assertGreaterThan( 0, $data['retry_after'] );
		// Exhaustion is a distinct answer, never a bare empty-looking success
		// that reads the same as "nothing to do".
		$this->assertSame( array(), $data['opportunities'] );
	}

	public function test_budget_exhaustion_does_not_block_a_seed_already_cached() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Suggestions_Provider();
		$provider->result = array(
			array( 'keyword' => 'x', 'search_volume' => 10, 'keyword_difficulty' => 5 ),
		);

		$controller                = new Mcpwp_REST_SEO_Market_Opportunities_Testable();
		$controller->stub_provider = $provider;

		// Warm the cache for one seed first.
		$controller->get_opportunities( $this->make_request( 'warm-seed' ) );

		// Exhaust the budget with distinct new seeds.
		for ( $i = 0; $i < Mcpwp_REST_SEO_Market::LOOKUP_BUDGET_MAX; $i++ ) {
			$controller->get_opportunities( $this->make_request( 'seed-' . $i ) );
		}

		// The already-cached seed must still work — it costs nothing.
		$response = $controller->get_opportunities( $this->make_request( 'warm-seed' ) );
		$data     = $response->get_data();

		$this->assertNotSame( Mcpwp_REST_SEO_Market::REASON_LOOKUP_BUDGET_EXHAUSTED, $data['reason'] );
	}
}
