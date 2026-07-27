<?php

use PHPUnit\Framework\TestCase;

class SeoVisibilityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
	}

	private function rows() {
		return array(
			array(
				'keyword'            => 'wordpress mcp',
				'position'           => 75,
				'ranking_url'        => 'https://docs.mcpwp.net/get-started/',
				'search_volume'      => 720,
				'keyword_difficulty' => 27,
			),
			array(
				'keyword'            => 'mcp pricing',
				'position'           => 91,
				'ranking_url'        => 'https://mcpwp.net/pricing/',
				'search_volume'      => 30,
				'keyword_difficulty' => 9,
			),
		);
	}

	public function test_snapshot_round_trips() {
		Mcpwp_SEO_Visibility::store_snapshot( $this->rows(), 'mcpwp.net' );

		$latest = Mcpwp_SEO_Visibility::get_latest();

		$this->assertSame( 'mcpwp.net', $latest['target'] );
		$this->assertCount( 2, $latest['rows'] );
		$this->assertSame( 'wordpress mcp', $latest['rows'][0]['keyword'] );
		$this->assertSame( 75, $latest['rows'][0]['position'] );
	}

	public function test_store_never_carries_click_or_impression_columns() {
		Mcpwp_SEO_Visibility::store_snapshot( $this->rows(), 'mcpwp.net' );
		$latest = Mcpwp_SEO_Visibility::get_latest();

		foreach ( $latest['rows'] as $row ) {
			// DataForSEO cannot supply these. A zeroed column would render as
			// "no traffic", which is a different and false claim.
			$this->assertArrayNotHasKey( 'clicks', $row );
			$this->assertArrayNotHasKey( 'impressions', $row );
			$this->assertArrayNotHasKey( 'ctr', $row );
		}
	}

	public function test_no_snapshot_is_reported_as_a_reason_not_an_empty_list() {
		$latest = Mcpwp_SEO_Visibility::get_latest();

		$this->assertSame( array(), $latest['rows'] );
		$this->assertSame( 'never_fetched', $latest['reason'] );
	}

	public function test_a_real_empty_snapshot_differs_from_never_fetched() {
		Mcpwp_SEO_Visibility::store_snapshot( array(), 'newsite.example' );
		$latest = Mcpwp_SEO_Visibility::get_latest();

		$this->assertSame( array(), $latest['rows'] );
		// The site genuinely ranks for nothing — that is an answer, not a gap.
		$this->assertSame( 'no_rankings', $latest['reason'] );
	}

	public function test_rows_are_sorted_by_position() {
		Mcpwp_SEO_Visibility::store_snapshot( array_reverse( $this->rows() ), 'mcpwp.net' );
		$latest = Mcpwp_SEO_Visibility::get_latest();

		$this->assertSame( 75, $latest['rows'][0]['position'] );
		$this->assertSame( 91, $latest['rows'][1]['position'] );
	}
}
