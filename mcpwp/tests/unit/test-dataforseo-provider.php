<?php

use PHPUnit\Framework\TestCase;

/**
 * Replays captured live responses. CI must never call the real API.
 */
class FixtureDataforseoProvider extends Mcpwp_Provider_Dataforseo {
	public $fixture;
	public $last_payload;

	protected function post( $path, $payload ) {
		$this->last_payload = $payload;
		return $this->fixture;
	}
}

class DataforseoProviderTest extends TestCase {

	private function fixture( $name ) {
		$path = dirname( __DIR__ ) . '/fixtures/dataforseo/' . $name . '.json';
		return json_decode( file_get_contents( $path ), true );
	}

	private function provider( $fixture_name ) {
		$p          = new FixtureDataforseoProvider( 'user@example.com', 'secret' );
		$p->fixture = $this->fixture( $fixture_name );
		return $p;
	}

	public function test_keyword_suggestions_returns_market_metrics() {
		$result = $this->provider( 'keyword_suggestions_live' )->keyword_suggestions( 'wordpress mcp' );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );

		$first = $result[0];
		$this->assertSame( 'wordpress mcp', $first['keyword'] );
		$this->assertSame( 720, $first['search_volume'] );
		$this->assertSame( 27, $first['keyword_difficulty'] );
		$this->assertEqualsWithDelta( 14.45, $first['cpc'], 0.01 );
		$this->assertSame( 'LOW', $first['competition_level'] );
	}

	public function test_ranked_keywords_returns_positions_and_urls() {
		$result = $this->provider( 'ranked_keywords_live' )->ranked_keywords( 'mcpwp.net' );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );

		$keywords = array_column( $result, 'keyword' );
		$this->assertContains( 'wordpress mcp', $keywords );

		foreach ( $result as $row ) {
			$this->assertArrayHasKey( 'position', $row );
			$this->assertArrayHasKey( 'ranking_url', $row );
			// Clicks and impressions are Search Console data. This provider
			// cannot supply them, so it must not invent them.
			$this->assertArrayNotHasKey( 'clicks', $row );
			$this->assertArrayNotHasKey( 'impressions', $row );
		}
	}

	/**
	 * @dataProvider status_reason_cases
	 */
	public function test_each_failure_status_has_its_own_reason( $status, $expected ) {
		$this->assertSame( $expected, Mcpwp_Provider_Dataforseo::reason_for_status( $status ) );
	}

	public function status_reason_cases() {
		return array(
			'auth rejected'      => array( 40100, 'auth_rejected' ),
			'payment required'   => array( 40200, 'insufficient_credits' ),
			'client error'       => array( 40400, 'request_rejected' ),
			'server error'       => array( 50000, 'provider_unavailable' ),
		);
	}

	public function test_reasons_are_all_distinct() {
		$reasons = array();
		foreach ( array( 40100, 40200, 40400, 50000 ) as $code ) {
			$reasons[] = Mcpwp_Provider_Dataforseo::reason_for_status( $code );
		}
		$this->assertSame( $reasons, array_unique( $reasons ), 'Each failure must be distinguishable.' );
	}

	public function test_non_ok_status_returns_error_not_empty_array() {
		$p          = new FixtureDataforseoProvider( 'user@example.com', 'secret' );
		$p->fixture = array( 'status_code' => 40100, 'status_message' => 'Unauthorized.' );

		$result = $p->keyword_suggestions( 'anything' );

		// An unusable credential must never look like "no keywords found".
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'auth_rejected', $result->get_error_code() );
	}

	public function test_request_caps_keywords_sent_for_enrichment() {
		$p          = new FixtureDataforseoProvider( 'user@example.com', 'secret' );
		$p->fixture = $this->fixture( 'keyword_suggestions_live' );

		$p->keyword_suggestions( 'wordpress mcp', array( 'limit' => 9999 ) );

		$this->assertLessThanOrEqual(
			Mcpwp_Provider_Dataforseo::MAX_ENRICHMENT_KEYWORDS,
			$p->last_payload[0]['limit'],
			'An unbounded limit must be clamped so an agent loop cannot spend without bound.'
		);
	}
}
