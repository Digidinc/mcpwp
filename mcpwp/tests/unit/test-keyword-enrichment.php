<?php

use PHPUnit\Framework\TestCase;

class StubEnrichmentProvider {
	public $result;

	public function keyword_suggestions( $seed, $args = array() ) {
		return $this->result;
	}
}

class KeywordEnrichmentTest extends TestCase {

	private function grouped() {
		return array(
			'seed'      => 'wordpress mcp',
			'keywords'  => array( 'wordpress mcp server' ),
			'questions' => array( 'what is wordpress mcp' ),
			'total'     => 2,
		);
	}

	public function test_enrichment_adds_market_metrics() {
		$provider         = new StubEnrichmentProvider();
		$provider->result = array(
			array(
				'keyword'            => 'wordpress mcp',
				'search_volume'      => 720,
				'cpc'                => 14.45,
				'competition'        => 0.12,
				'competition_level'  => 'LOW',
				'keyword_difficulty' => 27,
			),
		);

		$result = Mcpwp_Keyword_Research::enrich( $this->grouped(), $provider );

		$this->assertSame( 720, $result['metrics'][0]['search_volume'] );
		$this->assertSame( 27, $result['metrics'][0]['keyword_difficulty'] );
		$this->assertNull( $result['enrichment_reason'] );
	}

	public function test_no_provider_degrades_to_autocomplete_without_erroring() {
		$result = Mcpwp_Keyword_Research::enrich( $this->grouped(), null );

		// The free tier must keep working exactly as before.
		$this->assertSame( array( 'wordpress mcp server' ), $result['keywords'] );
		$this->assertSame( array(), $result['metrics'] );
		$this->assertSame( 'not_configured', $result['enrichment_reason'] );
	}

	public function test_provider_error_is_reported_not_swallowed() {
		$provider         = new StubEnrichmentProvider();
		$provider->result = new WP_Error( 'insufficient_credits', 'no credit' );

		$result = Mcpwp_Keyword_Research::enrich( $this->grouped(), $provider );

		// Out of credit must not look like "this keyword has no volume".
		$this->assertSame( array(), $result['metrics'] );
		$this->assertSame( 'insufficient_credits', $result['enrichment_reason'] );
	}

	public function test_apology_note_is_gone_when_enriched() {
		$provider         = new StubEnrichmentProvider();
		$provider->result = array(
			array(
				'keyword'            => 'wordpress mcp',
				'search_volume'      => 720,
				'cpc'                => 14.45,
				'competition'        => 0.12,
				'competition_level'  => 'LOW',
				'keyword_difficulty' => 27,
			),
		);

		$result = Mcpwp_Keyword_Research::enrich( $this->grouped(), $provider );

		$this->assertArrayNotHasKey( 'note', $result );
	}

	/**
	 * IMPORTANT 1 (final whole-branch review) — a caller that already fetched
	 * (and cached) the lookup must be able to hand the result to enrich()
	 * without triggering a second, uncached provider call.
	 */
	public function test_prefetched_metrics_are_used_without_calling_the_provider() {
		$provider = new StubEnrichmentProvider();
		// Deliberately left unset/null — if enrich() ignored the pre-fetched
		// value and called the provider anyway, this would be the return.

		$metrics = array(
			array(
				'keyword'            => 'wordpress mcp',
				'search_volume'      => 720,
				'cpc'                => 14.45,
				'competition'        => 0.12,
				'competition_level'  => 'LOW',
				'keyword_difficulty' => 27,
			),
		);

		$result = Mcpwp_Keyword_Research::enrich( $this->grouped(), $provider, $metrics );

		$this->assertSame( 720, $result['metrics'][0]['search_volume'] );
		$this->assertNull( $result['enrichment_reason'] );
	}

	public function test_prefetched_error_is_reported_without_calling_the_provider() {
		$provider = new StubEnrichmentProvider();
		$error    = new WP_Error( 'insufficient_credits', 'no credit' );

		$result = Mcpwp_Keyword_Research::enrich( $this->grouped(), $provider, $error );

		$this->assertSame( array(), $result['metrics'] );
		$this->assertSame( 'insufficient_credits', $result['enrichment_reason'] );
	}

	public function test_omitting_metrics_preserves_the_direct_provider_call() {
		// Backward compatibility: existing two-argument call sites must behave
		// exactly as before.
		$provider         = new StubEnrichmentProvider();
		$provider->result = array(
			array(
				'keyword'            => 'wordpress mcp',
				'search_volume'      => 720,
				'cpc'                => 14.45,
				'competition'        => 0.12,
				'competition_level'  => 'LOW',
				'keyword_difficulty' => 27,
			),
		);

		$result = Mcpwp_Keyword_Research::enrich( $this->grouped(), $provider );

		$this->assertSame( 720, $result['metrics'][0]['search_volume'] );
	}
}
