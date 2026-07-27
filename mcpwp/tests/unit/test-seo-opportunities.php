<?php

use PHPUnit\Framework\TestCase;

class SeoOpportunitiesTest extends TestCase {

	/**
	 * Real mcpwp.net data: six keywords, every one at position 64-91.
	 * A striking-distance-only ranker returns nothing for this site.
	 */
	private function young_site_rows() {
		return array(
			array( 'keyword' => 'claude desktop config json', 'position' => 64, 'search_volume' => 70,  'keyword_difficulty' => 14, 'ranking_url' => 'https://docs.mcpwp.net/connect/claude-desktop/' ),
			array( 'keyword' => 'claude desktop mcp',         'position' => 72, 'search_volume' => 590, 'keyword_difficulty' => 14, 'ranking_url' => 'https://docs.mcpwp.net/connect/claude-desktop/' ),
			array( 'keyword' => 'wordpress mcp',              'position' => 75, 'search_volume' => 720, 'keyword_difficulty' => 27, 'ranking_url' => 'https://docs.mcpwp.net/get-started/' ),
			array( 'keyword' => 'wordpress mcp server',       'position' => 79, 'search_volume' => 210, 'keyword_difficulty' => 8,  'ranking_url' => 'https://docs.mcpwp.net/get-started/' ),
			array( 'keyword' => 'mcp unlimited',              'position' => 90, 'search_volume' => 110, 'keyword_difficulty' => 8,  'ranking_url' => 'https://mcpwp.net/pricing/' ),
			array( 'keyword' => 'mcp pricing',                'position' => 91, 'search_volume' => 30,  'keyword_difficulty' => 9,  'ranking_url' => 'https://mcpwp.net/pricing/' ),
		);
	}

	public function test_young_site_still_gets_opportunities() {
		$result = Mcpwp_SEO_Opportunities::rank( $this->young_site_rows(), array(), array() );

		$this->assertNotEmpty(
			$result['opportunities'],
			'A site ranking only at positions 64-91 must still receive a ranked list.'
		);
		$this->assertSame( 'emerging', $result['mode'] );
	}

	public function test_young_site_ranks_high_volume_low_difficulty_first() {
		$result = Mcpwp_SEO_Opportunities::rank( $this->young_site_rows(), array(), array() );

		$top = $result['opportunities'][0];

		// wordpress mcp server: volume 210, difficulty 8 -> ratio 26.25
		// claude desktop mcp:   volume 590, difficulty 14 -> ratio 42.14
		// wordpress mcp:        volume 720, difficulty 27 -> ratio 26.67
		$this->assertSame( 'claude desktop mcp', $top['keyword'] );
	}

	public function test_every_opportunity_carries_a_reason() {
		$result = Mcpwp_SEO_Opportunities::rank( $this->young_site_rows(), array(), array() );

		foreach ( $result['opportunities'] as $opp ) {
			$this->assertNotEmpty( $opp['reason'] );
		}
	}

	public function test_mature_site_uses_striking_distance_as_a_multiplier() {
		$rows = array(
			// Page 2, modest volume — should beat a deep-ranked bigger term.
			array( 'keyword' => 'page two term', 'position' => 14, 'search_volume' => 200, 'keyword_difficulty' => 20, 'ranking_url' => 'https://example.com/a' ),
			array( 'keyword' => 'deep term',     'position' => 88, 'search_volume' => 300, 'keyword_difficulty' => 20, 'ranking_url' => 'https://example.com/b' ),
		);

		$result = Mcpwp_SEO_Opportunities::rank( $rows, array(), array() );

		$this->assertSame( 'mature', $result['mode'] );
		$this->assertSame( 'page two term', $result['opportunities'][0]['keyword'] );
	}

	public function test_site_with_no_rankings_falls_back_to_keyword_research() {
		$metrics = array(
			array( 'keyword' => 'new topic', 'search_volume' => 400, 'keyword_difficulty' => 10, 'cpc' => 1.0, 'competition' => 0.1, 'competition_level' => 'LOW' ),
		);

		$result = Mcpwp_SEO_Opportunities::rank( array(), $metrics, array() );

		$this->assertSame( 'greenfield', $result['mode'] );
		$this->assertSame( 'new topic', $result['opportunities'][0]['keyword'] );
	}

	public function test_nothing_to_do_is_distinct_from_nothing_computed() {
		$empty = Mcpwp_SEO_Opportunities::rank( array(), array(), array() );

		$this->assertSame( array(), $empty['opportunities'] );
		$this->assertSame( 'no_inputs', $empty['reason'] );
	}

	public function test_zero_difficulty_does_not_divide_by_zero() {
		$rows = array(
			array( 'keyword' => 'unguarded', 'position' => 50, 'search_volume' => 100, 'keyword_difficulty' => 0, 'ranking_url' => 'https://example.com/x' ),
		);

		$result = Mcpwp_SEO_Opportunities::rank( $rows, array(), array() );

		$this->assertNotEmpty( $result['opportunities'] );
		$this->assertIsFloat( $result['opportunities'][0]['score'] );
	}

	/**
	 * Regression guard for the "unavailable source == empty result" defect.
	 *
	 * A site that connected DataForSEO, fetched, and genuinely ranks for
	 * nothing must not be told the same thing as a site that never connected.
	 * Mcpwp_SEO_Visibility::get_latest() distinguishes 'no_rankings' (fetched,
	 * ranks for nothing) from 'never_fetched' (nobody ever fetched); rank()
	 * must fan that distinction back out through its own reason.
	 */
	public function test_fetched_but_no_rankings_yields_greenfield_needs_seed() {
		$result = Mcpwp_SEO_Opportunities::rank( array(), array(), array(), 'no_rankings' );

		$this->assertSame( array(), $result['opportunities'] );
		$this->assertSame( 'greenfield', $result['mode'] );
		$this->assertSame( 'no_rankings_needs_seed', $result['reason'] );
	}

	public function test_never_fetched_yields_no_inputs() {
		$result = Mcpwp_SEO_Opportunities::rank( array(), array(), array(), 'never_fetched' );

		$this->assertSame( 'no_inputs', $result['reason'] );
	}

	public function test_no_rankings_and_never_fetched_reasons_differ() {
		$fetched_empty = Mcpwp_SEO_Opportunities::rank( array(), array(), array(), 'no_rankings' );
		$never_fetched = Mcpwp_SEO_Opportunities::rank( array(), array(), array(), 'never_fetched' );

		// The whole point of this fix: these must never collapse to the same
		// value again. Assert the inequality directly so a future change that
		// re-merges them fails loudly, not silently.
		$this->assertNotSame(
			$never_fetched['reason'],
			$fetched_empty['reason'],
			'An unavailable source and an empty-but-fetched result must never produce the same reason.'
		);
	}

	public function test_omitting_visibility_reason_preserves_backward_compatible_no_inputs() {
		// Existing three-argument call sites must keep behaving exactly as
		// before: no fourth argument at all, not even null.
		$result = Mcpwp_SEO_Opportunities::rank( array(), array(), array() );

		$this->assertSame( 'no_inputs', $result['reason'] );
		$this->assertSame( 'unknown', $result['mode'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Carried Minor #1 — deterministic tie-break.
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * 100/10 and 200/20 both score 10 — PHP's usort() is only stable from 8.0,
	 * and the plugin floor is 7.4, so without an explicit tie-break two
	 * identical requests could return a different top-N ordering.
	 */
	public function test_tied_scores_are_ordered_deterministically() {
		$rows = array(
			array( 'keyword' => 'zeta term',  'position' => 50, 'search_volume' => 200, 'keyword_difficulty' => 20, 'ranking_url' => 'https://example.com/z' ),
			array( 'keyword' => 'alpha term', 'position' => 50, 'search_volume' => 100, 'keyword_difficulty' => 10, 'ranking_url' => 'https://example.com/a' ),
		);

		$first  = Mcpwp_SEO_Opportunities::rank( $rows, array(), array() );
		$second = Mcpwp_SEO_Opportunities::rank( $rows, array(), array() );

		$this->assertSame( 10.0, $first['opportunities'][0]['score'] );
		$this->assertSame( 10.0, $first['opportunities'][1]['score'] );

		// Deterministic: same tie-break every time, and it must be alphabetical
		// (strcmp on keyword), not insertion order.
		$this->assertSame( 'alpha term', $first['opportunities'][0]['keyword'] );
		$this->assertSame( 'zeta term', $first['opportunities'][1]['keyword'] );
		$this->assertSame(
			array_column( $first['opportunities'], 'keyword' ),
			array_column( $second['opportunities'], 'keyword' ),
			'Two identical requests must return the same ordering for tied scores.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Carried Minor #2 — striking distance is 11-20, not 1-20.
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * A site ranking #2 for its own brand name has already won that keyword.
	 * It must not flip the whole site into 'mature' mode, and that row must
	 * not receive the striking-distance boost meant for near-miss terms.
	 */
	public function test_position_within_top_ten_gets_no_boost_and_no_mature_flip() {
		$rows = array(
			array( 'keyword' => 'brand name', 'position' => 2, 'search_volume' => 500, 'keyword_difficulty' => 20, 'ranking_url' => 'https://example.com/brand' ),
		);

		$result = Mcpwp_SEO_Opportunities::rank( $rows, array(), array() );

		$this->assertSame( 'emerging', $result['mode'], 'Position 1-10 must not flip the site into mature mode.' );

		$expected_score = (float) ( 500 / 20 ); // No STRIKING_DISTANCE_BOOST applied.
		$this->assertSame( $expected_score, $result['opportunities'][0]['score'] );
	}

	public function test_position_eleven_still_gets_the_striking_distance_boost() {
		$rows = array(
			array( 'keyword' => 'edge case term', 'position' => 11, 'search_volume' => 500, 'keyword_difficulty' => 20, 'ranking_url' => 'https://example.com/edge' ),
		);

		$result = Mcpwp_SEO_Opportunities::rank( $rows, array(), array() );

		$this->assertSame( 'mature', $result['mode'] );

		$expected_score = ( 500 / 20 ) * Mcpwp_SEO_Opportunities::STRIKING_DISTANCE_BOOST;
		$this->assertSame( $expected_score, $result['opportunities'][0]['score'] );
	}
}
