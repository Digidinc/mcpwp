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
}
