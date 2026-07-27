<?php
/**
 * Regression tests — final whole-branch review, IMPORTANT 1, and the fix-pass
 * regression it introduced.
 *
 * History:
 *  1. Mcpwp_Keyword_Research::enrich() originally had zero production callers
 *     — keyword_research() called only research(), so a Pro customer with
 *     valid DataForSEO credentials configured received byte-identical output
 *     to an unconfigured site.
 *  2. The fix that wired enrich() in did so unconditionally — every call to
 *     this FREE-TIER, keyless tool now spent DataForSEO credit for any site
 *     with credentials configured, contradicting its own advertised contract
 *     ("keyless — no API key required … does not require a paid provider")
 *     and unlike its sibling /seo/opportunities, which only ever spends when
 *     there is no stored visibility.
 *
 * This file now proves both: enrichment happens (routed through
 * Mcpwp_SEO_Cache, never a second, uncached call) ONLY when the caller
 * explicitly passes enrich=true, and stays fully keyless/costless otherwise.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// Not pulled in by the main bootstrap — no earlier test needed this combination.
require_once dirname( __DIR__, 2 ) . '/includes/api/class-mcpwp-rest-api.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/class-mcpwp-rest-seo-audit.php';
require_once dirname( __DIR__, 2 ) . '/includes/core/class-mcpwp-keyword-research.php';
require_once dirname( __DIR__, 2 ) . '/includes/core/class-mcpwp-seo-cache.php';
require_once dirname( __DIR__, 2 ) . '/includes/core/class-mcpwp-provider-dataforseo.php';

/**
 * Duck-typed stand-in for Mcpwp_Provider_Dataforseo — only keyword_suggestions()
 * is called from this path, so no live API or credential plumbing is required.
 */
class Mcpwp_Test_Stub_Dataforseo_Keyword_Provider {

	/** @var array|WP_Error Result to return from keyword_suggestions(). */
	public $result = array();

	/** @var int Number of times keyword_suggestions() was invoked. */
	public $calls = 0;

	/** @var array Args passed on the most recent call. */
	public $last_args = array();

	public function keyword_suggestions( $seed, $args = array() ) {
		$this->calls++;
		$this->last_args = $args;
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

	private function make_request( $seed = 'wordpress mcp', $enrich = null, $hl = null, $gl = null ) {
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/keyword-research' );
		$request->set_param( 'seed', $seed );
		if ( null !== $enrich ) {
			$request->set_param( 'enrich', $enrich );
		}
		if ( null !== $hl ) {
			$request->set_param( 'hl', $hl );
		}
		if ( null !== $gl ) {
			$request->set_param( 'gl', $gl );
		}
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

	// ─────────────────────────────────────────────────────────────────────
	// enrich defaults to false — the free-tier keyless contract.
	// ─────────────────────────────────────────────────────────────────────

	public function test_enrich_omitted_never_calls_provider_even_when_configured() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Keyword_Provider();
		$provider->result = array( $this->metrics_row() );

		$controller                = new Mcpwp_REST_SEO_Audit_Testable();
		$controller->stub_provider = $provider;

		$response = $controller->keyword_research( $this->make_request() );
		$data     = $response->get_data();

		$this->assertSame( 0, $provider->calls, 'A configured provider must never be spent by default.' );
		// Response is byte-identical to the pre-enrichment (pre-branch) shape:
		// note stays, no metrics/enrichment_reason keys were ever added.
		$this->assertArrayHasKey( 'note', $data );
		$this->assertArrayNotHasKey( 'metrics', $data );
		$this->assertArrayNotHasKey( 'enrichment_reason', $data );
	}

	public function test_enrich_false_explicitly_also_skips_the_provider() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Keyword_Provider();
		$provider->result = array( $this->metrics_row() );

		$controller                = new Mcpwp_REST_SEO_Audit_Testable();
		$controller->stub_provider = $provider;

		$response = $controller->keyword_research( $this->make_request( 'wordpress mcp', false ) );

		$this->assertSame( 0, $provider->calls );
		$this->assertArrayHasKey( 'note', $response->get_data() );
	}

	public function test_enrich_true_without_provider_keeps_the_free_tier_note() {
		$controller                = new Mcpwp_REST_SEO_Audit_Testable();
		$controller->stub_provider = null;

		$response = $controller->keyword_research( $this->make_request( 'wordpress mcp', true ) );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'note', $data );
		$this->assertSame( array(), $data['metrics'] );
		$this->assertSame( 'not_configured', $data['enrichment_reason'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// enrich=true with a configured provider — the regression this guards
	// against: a Pro customer who configured DataForSEO must see numbers when
	// they explicitly ask, not the apology telling them to configure the
	// provider they already configured.
	// ─────────────────────────────────────────────────────────────────────

	public function test_enrich_true_with_configured_provider_replaces_the_note_with_metrics() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Keyword_Provider();
		$provider->result = array( $this->metrics_row() );

		$controller                = new Mcpwp_REST_SEO_Audit_Testable();
		$controller->stub_provider = $provider;

		$response = $controller->keyword_research( $this->make_request( 'wordpress mcp', true ) );
		$data     = $response->get_data();

		$this->assertArrayNotHasKey( 'note', $data );
		$this->assertNotEmpty( $data['metrics'] );
		$this->assertSame( 210, $data['metrics'][0]['search_volume'] );
		$this->assertNull( $data['enrichment_reason'] );
		$this->assertSame( 1, $provider->calls );
	}

	public function test_enrich_true_provider_error_is_surfaced_not_swallowed() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Keyword_Provider();
		$provider->result = new WP_Error( 'insufficient_credits', 'no credit' );

		$controller                = new Mcpwp_REST_SEO_Audit_Testable();
		$controller->stub_provider = $provider;

		$response = $controller->keyword_research( $this->make_request( 'wordpress mcp', true ) );
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

		$controller->keyword_research( $this->make_request( 'wordpress mcp', true ) );
		$controller->keyword_research( $this->make_request( 'wordpress mcp', true ) );

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

		$controller->keyword_research( $this->make_request( 'wordpress mcp', true ) );
		$controller->keyword_research( $this->make_request( 'wordpress hosting', true ) );

		$this->assertSame( 2, $provider->calls );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Shared lookup budget (hoisted to Mcpwp_REST_API) — exhaustion and
	// the WP_Error refund guard.
	// ─────────────────────────────────────────────────────────────────────

	public function test_budget_exhausted_returns_distinct_reason_with_no_provider_call() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Keyword_Provider();
		$provider->result = array( $this->metrics_row() );

		$controller                = new Mcpwp_REST_SEO_Audit_Testable();
		$controller->stub_provider = $provider;

		$last_response = null;
		for ( $i = 0; $i < Mcpwp_REST_SEO_Audit::LOOKUP_BUDGET_MAX + 1; $i++ ) {
			$last_response = $controller->keyword_research( $this->make_request( 'seed-' . $i, true ) );
		}

		$this->assertSame( Mcpwp_REST_SEO_Audit::LOOKUP_BUDGET_MAX, $provider->calls );

		$data = $last_response->get_data();
		$this->assertSame( Mcpwp_REST_SEO_Audit::REASON_LOOKUP_BUDGET_EXHAUSTED, $data['enrichment_reason'] );
		$this->assertSame( array(), $data['metrics'] );
		$this->assertArrayHasKey( 'retry_after', $data );
		$this->assertGreaterThan( 0, $data['retry_after'] );
		$this->assertArrayNotHasKey( 'note', $data );
	}

	/**
	 * Minor — a permanently-broken credential must not exhaust the shared
	 * daily budget. Mcpwp_SEO_Cache::TTL_FAIL is only 5 minutes, so retrying
	 * the same failing seed on a natural cadence would otherwise burn a
	 * budget slot every 5 minutes.
	 */
	public function test_wp_error_fetch_does_not_consume_budget() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Keyword_Provider();
		$provider->result = new WP_Error( 'auth_rejected', 'bad credentials' );

		$controller                = new Mcpwp_REST_SEO_Audit_Testable();
		$controller->stub_provider = $provider;

		// Simulate the failing seed being retried many times more than the
		// budget ceiling would allow if each miss were charged.
		for ( $i = 0; $i < Mcpwp_REST_SEO_Audit::LOOKUP_BUDGET_MAX + 5; $i++ ) {
			// A fresh cache-miss each time: TTL_FAIL still caches the error for
			// 5 minutes in real use, but here each call uses a distinct seed to
			// force a fresh miss without waiting on the clock — the point under
			// test is whether a *miss that fails* spends the budget, not the
			// cache TTL itself (covered separately by test-seo-cache.php).
			$response = $controller->keyword_research( $this->make_request( 'broken-seed-' . $i, true ) );
			$data     = $response->get_data();
			$this->assertSame( 'auth_rejected', $data['enrichment_reason'] );
		}

		$this->assertSame(
			Mcpwp_REST_SEO_Audit::LOOKUP_BUDGET_MAX + 5,
			$provider->calls,
			'Every failing miss must still reach the provider — a WP_Error must not be served from a stale exhausted-budget state.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Minor — locale must reach the provider and the cache key, or the
	// caller must be told plainly the locale is not supported. Never pair a
	// real request with silently-wrong market data.
	// ─────────────────────────────────────────────────────────────────────

	public function test_default_locale_reaches_provider_as_en_us() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Keyword_Provider();
		$provider->result = array( $this->metrics_row() );

		$controller                = new Mcpwp_REST_SEO_Audit_Testable();
		$controller->stub_provider = $provider;

		$controller->keyword_research( $this->make_request( 'wordpress mcp', true ) );

		$this->assertSame( 'en', $provider->last_args['language_code'] );
		$this->assertSame( 2840, $provider->last_args['location_code'] );
	}

	public function test_non_default_language_reaches_the_provider_and_the_cache_key() {
		$provider         = new Mcpwp_Test_Stub_Dataforseo_Keyword_Provider();
		$provider->result = array( $this->metrics_row() );

		$controller                = new Mcpwp_REST_SEO_Audit_Testable();
		$controller->stub_provider = $provider;

		// hl varies, gl stays at the mappable default ('us') so the request
		// must reach the provider rather than fail on an unmapped location.
		$controller->keyword_research( $this->make_request( 'wordpress mcp', true, 'fr', 'us' ) );

		$this->assertSame( 1, $provider->calls );
		$this->assertSame( 'fr', $provider->last_args['language_code'] );

		// A different hl for the same seed must not collide with the 'en'
		// cache entry — i.e. it must reach the provider again, not be served
		// (wrongly) from the English cache entry.
		$controller->keyword_research( $this->make_request( 'wordpress mcp', true, 'en', 'us' ) );
		$this->assertSame( 2, $provider->calls );
	}

	public function test_unmapped_country_yields_locale_not_supported_without_calling_the_provider() {
		$provider = new Mcpwp_Test_Stub_Dataforseo_Keyword_Provider();

		$controller                = new Mcpwp_REST_SEO_Audit_Testable();
		$controller->stub_provider = $provider;

		// French keywords (hl=fr) paired with a French location (gl=fr) — the
		// bug this guards against: gl='fr' must never silently fall back to
		// US market data under a reason that claims clean success.
		$response = $controller->keyword_research( $this->make_request( 'wordpress mcp', true, 'fr', 'fr' ) );
		$data     = $response->get_data();

		$this->assertSame( 0, $provider->calls, 'An unmapped locale must never reach the provider with the wrong location.' );
		$this->assertSame( array(), $data['metrics'] );
		$this->assertSame( 'locale_not_supported', $data['enrichment_reason'] );
		$this->assertArrayNotHasKey( 'note', $data );
	}
}
