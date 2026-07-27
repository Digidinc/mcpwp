<?php
/**
 * Regression tests — /seo/visibility/refresh debounce.
 *
 * refresh_visibility() is the most expensive paid call in the SEO market-data
 * feature: every call spends the customer's DataForSEO credit. The generic
 * rate limiter is a per-key throttle, not a cost guard, so a five-minute
 * debounce sits in front of the provider call itself.
 *
 * Deliberately NOT a cache: a debounced response must carry the existing
 * snapshot tagged 'refresh_debounced' rather than a freshly-stamped
 * captured_at, so the caller can always tell "we did not refetch" from
 * "we refetched and nothing changed."
 *
 * Test approach: subclass Mcpwp_REST_SEO_Market to inject a stub provider
 * (get_dataforseo_provider() is protected for exactly this reason) and call
 * the public refresh_visibility() method directly, bypassing the HTTP/auth
 * stack — same pattern as StagingSelfApprovalTest.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// Not pulled in by the main bootstrap — no earlier test needed it.
require_once dirname( __DIR__, 2 ) . '/includes/api/class-mcpwp-rest-seo-market.php';

/**
 * Duck-typed stand-in for Mcpwp_Provider_Dataforseo. get_dataforseo_provider()
 * carries no return-type hint, so any object exposing ranked_keywords() works
 * — no live API, no credential setup, no encryption plumbing required.
 */
class Mcpwp_Test_Stub_Dataforseo_Provider {

	/** @var array Rows to return on a successful call. */
	public $rows = array();

	/** @var WP_Error|null Set to make ranked_keywords() fail instead. */
	public $error = null;

	/** @var int Number of times ranked_keywords() was invoked. */
	public $calls = 0;

	public function ranked_keywords( $target, $args = array() ) {
		$this->calls++;

		if ( null !== $this->error ) {
			return $this->error;
		}

		return $this->rows;
	}
}

/**
 * Testable subclass: injects a stub provider instead of building a real one
 * from stored (encrypted) integration config.
 */
class Mcpwp_REST_SEO_Market_Testable extends Mcpwp_REST_SEO_Market {

	/** @var object|null Provider to return from get_dataforseo_provider(). */
	public $stub_provider = null;

	protected function get_dataforseo_provider() {
		return $this->stub_provider;
	}
}

class SeoMarketDebounceTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
	}

	private function make_request() {
		return new WP_REST_Request( 'POST', '/mcpwp/v1/seo/visibility/refresh' );
	}

	private function stub_provider_with_rows() {
		$provider       = new Mcpwp_Test_Stub_Dataforseo_Provider();
		$provider->rows = array(
			array( 'keyword' => 'wordpress mcp', 'position' => 12, 'search_volume' => 720, 'keyword_difficulty' => 27, 'ranking_url' => 'https://example.com/' ),
		);
		return $provider;
	}

	// ─────────────────────────────────────────────────────────────────────
	// 1. A second call inside the debounce window does not invoke the
	//    provider, and is distinguishable from a real re-fetch.
	// ─────────────────────────────────────────────────────────────────────

	public function test_second_call_within_debounce_window_does_not_call_provider() {
		$provider = $this->stub_provider_with_rows();

		$controller                = new Mcpwp_REST_SEO_Market_Testable();
		$controller->stub_provider = $provider;

		$first = $controller->refresh_visibility( $this->make_request() );
		$this->assertInstanceOf( WP_REST_Response::class, $first );
		$this->assertSame( 1, $provider->calls );

		$second = $controller->refresh_visibility( $this->make_request() );

		// The debounce guard must fire before the provider is ever touched.
		$this->assertSame(
			1,
			$provider->calls,
			'A second refresh inside the debounce window must not spend DataForSEO credit.'
		);

		$this->assertInstanceOf( WP_REST_Response::class, $second );
		$data = $second->get_data();

		$this->assertSame( 'refresh_debounced', $data['reason'] );
		$this->assertArrayHasKey( 'retry_after', $data );
		$this->assertGreaterThan( 0, $data['retry_after'] );
		$this->assertLessThanOrEqual( Mcpwp_REST_SEO_Market::REFRESH_DEBOUNCE, $data['retry_after'] );

		// The debounced response must still carry the real, existing snapshot
		// (from the first fetch) — never an empty stand-in.
		$this->assertNotEmpty( $data['rows'] );
		$this->assertSame( 'wordpress mcp', $data['rows'][0]['keyword'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// 2. A provider WP_Error must NOT set the lock — a following call is
	//    allowed to retry immediately. A transient outage must never cost a
	//    customer five minutes of blocked retries.
	// ─────────────────────────────────────────────────────────────────────

	public function test_provider_error_does_not_set_lock_and_allows_retry() {
		$provider        = new Mcpwp_Test_Stub_Dataforseo_Provider();
		$provider->error = new WP_Error( 'provider_unavailable', 'DataForSEO could not be reached.' );

		$controller                = new Mcpwp_REST_SEO_Market_Testable();
		$controller->stub_provider = $provider;

		$first = $controller->refresh_visibility( $this->make_request() );
		$this->assertInstanceOf( WP_Error::class, $first );
		$this->assertSame( 1, $provider->calls );

		$this->assertFalse(
			get_transient( Mcpwp_REST_SEO_Market::REFRESH_LOCK ),
			'A failed provider call must not set the debounce lock.'
		);

		$second = $controller->refresh_visibility( $this->make_request() );

		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame(
			2,
			$provider->calls,
			'A retry after a provider error must not be blocked by a debounce lock that was never set.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// 3. A successful fetch DOES set the lock, for the full debounce window.
	// ─────────────────────────────────────────────────────────────────────

	public function test_successful_fetch_sets_the_lock() {
		$provider = $this->stub_provider_with_rows();

		$controller                = new Mcpwp_REST_SEO_Market_Testable();
		$controller->stub_provider = $provider;

		$controller->refresh_visibility( $this->make_request() );

		$entry = $GLOBALS['mcpwp_test_transients'][ Mcpwp_REST_SEO_Market::REFRESH_LOCK ];

		$this->assertSame( Mcpwp_REST_SEO_Market::REFRESH_DEBOUNCE, $entry['ttl'] );
	}
}
