<?php

use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for Mcpwp_Seo_Flags::compute() — no WordPress storage
 * involved, fixture rows/content-index passed in directly. See
 * RestSeoFlagsTest.php for the REST-level round trip and
 * McpPromptsSeoLoopTest.php for the seo-loop prompt.
 */
final class SeoFlagsTest extends TestCase {

	/**
	 * One row helper. Position/ctr are per-row; aggregation across rows with
	 * the same query+url happens inside compute().
	 */
	private function row( $query, $url, $date, $clicks, $impressions, $position = 0.0, $ctr = null, $import_id = 'spi_a' ) {
		return array(
			'query'       => $query,
			'url'         => $url,
			'date'        => $date,
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'position'    => $position,
			'ctr'         => null === $ctr ? ( $impressions > 0 ? $clicks / $impressions : 0 ) : $ctr,
			'import_id'   => $import_id,
			'provider'    => 'manual',
		);
	}

	// ------------------------------------------------------------------
	// no-data path
	// ------------------------------------------------------------------

	public function test_no_rows_returns_success_with_empty_flags_and_next_step() {
		$result = Mcpwp_Seo_Flags::compute( array() );

		foreach ( array( 'almost_there', 'no_clicks', 'decaying', 'untargeted', 'wrong_intent', 'ai_mode' ) as $flag ) {
			$this->assertArrayHasKey( $flag, $result['flags'] );
			$this->assertSame( array(), $result['flags'][ $flag ], "Flag {$flag} should be empty with no rows." );
		}
		$this->assertArrayHasKey( 'next_step', $result );
		$this->assertNotEmpty( $result['next_step'] );
		$this->assertStringContainsString( 'wp_import_search_performance', $result['next_step'] );
	}

	// ------------------------------------------------------------------
	// almost_there — position band + impression floor + brand exclusion
	// ------------------------------------------------------------------

	public function test_almost_there_position_band_edges() {
		$rows = array(
			$this->row( 'just above band', 'https://example.com/a', '2026-09-20', 1, 100, 2.9 ),  // excluded: < 3
			$this->row( 'low edge in band', 'https://example.com/b', '2026-09-20', 1, 100, 3.0 ), // included
			$this->row( 'high edge in band', 'https://example.com/c', '2026-09-20', 1, 100, 20.0 ), // included
			$this->row( 'just below band', 'https://example.com/d', '2026-09-20', 1, 100, 20.1 ), // excluded: > 20
		);

		$result  = Mcpwp_Seo_Flags::compute( $rows );
		$queries = array_column( $result['flags']['almost_there'], 'query' );

		$this->assertNotContains( 'just above band', $queries );
		$this->assertContains( 'low edge in band', $queries );
		$this->assertContains( 'high edge in band', $queries );
		$this->assertNotContains( 'just below band', $queries );
	}

	public function test_almost_there_impression_floor_edge() {
		$rows = array(
			$this->row( 'below floor', 'https://example.com/a', '2026-09-20', 1, 49, 10.0 ),
			$this->row( 'at floor', 'https://example.com/b', '2026-09-20', 1, 50, 10.0 ),
		);

		$result  = Mcpwp_Seo_Flags::compute( $rows );
		$queries = array_column( $result['flags']['almost_there'], 'query' );

		$this->assertNotContains( 'below floor', $queries );
		$this->assertContains( 'at floor', $queries );
	}

	public function test_almost_there_excludes_brand_queries() {
		$rows = array(
			$this->row( 'acme widgets pricing', 'https://acme.example.com/pricing', '2026-09-20', 1, 200, 8.0 ),
			$this->row( 'best widget supplier', 'https://acme.example.com/widgets', '2026-09-20', 1, 200, 8.0 ),
		);

		$result  = Mcpwp_Seo_Flags::compute( $rows, array(), array(), array( 'brand_terms' => array( 'acme' ) ) );
		$queries = array_column( $result['flags']['almost_there'], 'query' );

		$this->assertNotContains( 'acme widgets pricing', $queries, 'Brand-containing query must be excluded.' );
		$this->assertContains( 'best widget supplier', $queries );
	}

	// ------------------------------------------------------------------
	// no_clicks — impression + CTR edges
	// ------------------------------------------------------------------

	public function test_no_clicks_impression_edge_is_exclusive() {
		$rows = array(
			// impressions == min_impressions (500): excluded, rule is strictly greater than.
			$this->row( 'at impression floor', 'https://example.com/a', '2026-09-20', 1, 500, 15.0, 0.001 ),
			// impressions just above: included (CTR well under ceiling).
			$this->row( 'above impression floor', 'https://example.com/b', '2026-09-20', 1, 501, 15.0, 0.001 ),
		);

		$result  = Mcpwp_Seo_Flags::compute( $rows, array(), array(), array( 'min_impressions' => 500 ) );
		$queries = array_column( $result['flags']['no_clicks'], 'query' );

		$this->assertNotContains( 'at impression floor', $queries );
		$this->assertContains( 'above impression floor', $queries );
	}

	public function test_no_clicks_ctr_ceiling_edge() {
		$rows = array(
			// ctr == 0.005 exactly: excluded, rule is strictly less than.
			$this->row( 'ctr at ceiling', 'https://example.com/a', '2026-09-20', 5, 1000, 15.0, 0.005 ),
			// ctr just under: included.
			$this->row( 'ctr under ceiling', 'https://example.com/b', '2026-09-20', 4, 1000, 15.0, 0.004 ),
		);

		$result  = Mcpwp_Seo_Flags::compute( $rows, array(), array(), array( 'min_impressions' => 500 ) );
		$queries = array_column( $result['flags']['no_clicks'], 'query' );

		$this->assertNotContains( 'ctr at ceiling', $queries );
		$this->assertContains( 'ctr under ceiling', $queries );
	}

	// ------------------------------------------------------------------
	// decaying — date-window comparison and import-boundary fallback
	// ------------------------------------------------------------------

	public function test_decaying_flags_a_real_drop_across_date_windows() {
		$rows = array(
			// Previous 7 days (2026-09-08..2026-09-14): 20 clicks total, base >= 10.
			$this->row( 'decaying query', 'https://example.com/a', '2026-09-10', 20, 400, 6.0 ),
			// Last 7 days (2026-09-15..2026-09-21): 5 clicks — 75% drop.
			$this->row( 'decaying query', 'https://example.com/a', '2026-09-18', 5, 400, 6.0 ),
		);

		$result  = Mcpwp_Seo_Flags::compute( $rows );
		$queries = array_column( $result['flags']['decaying'], 'query' );

		$this->assertContains( 'decaying query', $queries );
	}

	public function test_decaying_ignores_drop_under_threshold() {
		$rows = array(
			$this->row( 'stable query', 'https://example.com/a', '2026-09-10', 15, 400, 6.0 ),
			// Drop of 20% — below the 30% threshold.
			$this->row( 'stable query', 'https://example.com/a', '2026-09-18', 12, 400, 6.0 ),
		);

		$result  = Mcpwp_Seo_Flags::compute( $rows );
		$queries = array_column( $result['flags']['decaying'], 'query' );

		$this->assertNotContains( 'stable query', $queries );
	}

	public function test_decaying_ignores_drop_below_min_base_clicks() {
		$rows = array(
			// Base of 9 clicks (< 10) — noise floor, must not fire even on a huge relative drop.
			$this->row( 'too small to matter', 'https://example.com/a', '2026-09-10', 9, 100, 6.0 ),
			$this->row( 'too small to matter', 'https://example.com/a', '2026-09-18', 1, 100, 6.0 ),
		);

		$result  = Mcpwp_Seo_Flags::compute( $rows );
		$queries = array_column( $result['flags']['decaying'], 'query' );

		$this->assertNotContains( 'too small to matter', $queries );
	}

	public function test_decaying_min_base_clicks_edge_at_ten_is_included() {
		$rows = array(
			// Base of exactly 10 clicks, dropping to 6 — a 40% drop, over threshold.
			$this->row( 'edge base clicks', 'https://example.com/a', '2026-09-10', 10, 100, 6.0 ),
			$this->row( 'edge base clicks', 'https://example.com/a', '2026-09-18', 6, 100, 6.0 ),
		);

		$result  = Mcpwp_Seo_Flags::compute( $rows );
		$queries = array_column( $result['flags']['decaying'], 'query' );

		$this->assertContains( 'edge base clicks', $queries );
	}

	public function test_decaying_falls_back_to_import_boundaries_when_all_rows_share_one_date() {
		// A manual CSV export that stamps every row with the same report date —
		// no per-day granularity, so decaying must compare import boundaries.
		$rows = array(
			$this->row( 'single-date query', 'https://example.com/a', '2026-09-20', 20, 400, 6.0, null, 'spi_previous' ),
			$this->row( 'single-date query', 'https://example.com/a', '2026-09-20', 5, 400, 6.0, null, 'spi_latest' ),
		);
		$imports = array(
			array( 'id' => 'spi_latest', 'imported_at' => '2026-09-20T00:00:00+00:00' ),
			array( 'id' => 'spi_previous', 'imported_at' => '2026-09-13T00:00:00+00:00' ),
		);

		$result  = Mcpwp_Seo_Flags::compute( $rows, array(), $imports );
		$queries = array_column( $result['flags']['decaying'], 'query' );

		$this->assertContains( 'single-date query', $queries );
	}

	public function test_decaying_import_fallback_needs_at_least_two_imports() {
		$rows = array(
			$this->row( 'only one import', 'https://example.com/a', '2026-09-20', 20, 400, 6.0, null, 'spi_only' ),
		);
		$imports = array(
			array( 'id' => 'spi_only', 'imported_at' => '2026-09-20T00:00:00+00:00' ),
		);

		$result = Mcpwp_Seo_Flags::compute( $rows, array(), $imports );

		$this->assertSame( array(), $result['flags']['decaying'] );
	}

	// ------------------------------------------------------------------
	// untargeted — content index token matching, focus keyword suppression
	// ------------------------------------------------------------------

	public function test_untargeted_flags_query_with_no_covering_content() {
		$rows          = array( $this->row( 'totally uncovered widget topic', 'https://example.com/a', '2026-09-20', 0, 20, 0 ) );
		$content_index = array(
			array(
				'id'            => 1,
				'url'           => 'https://example.com/unrelated',
				'post_type'     => 'page',
				'title'         => 'Contact Us',
				'slug'          => 'contact',
				'focus_keyword' => '',
				'has_table'     => false,
				'word_count'    => 100,
				'tokens'        => array( 'contact' ),
			),
		);

		$result  = Mcpwp_Seo_Flags::compute( $rows, $content_index );
		$queries = array_column( $result['flags']['untargeted'], 'query' );

		$this->assertContains( 'totally uncovered widget topic', $queries );
	}

	public function test_untargeted_suppressed_by_focus_keyword_match() {
		$rows          = array( $this->row( 'wordpress mcp server', 'https://example.com/get-started', '2026-09-20', 0, 20, 0 ) );
		$content_index = array(
			array(
				'id'            => 5,
				'url'           => 'https://example.com/get-started',
				'post_type'     => 'page',
				'title'         => 'Get Started',
				'slug'          => 'get-started',
				'focus_keyword' => 'wordpress mcp server',
				'has_table'     => false,
				'word_count'    => 500,
				'tokens'        => array( 'get', 'started', 'wordpress', 'mcp', 'server' ),
			),
		);

		$result  = Mcpwp_Seo_Flags::compute( $rows, $content_index );
		$queries = array_column( $result['flags']['untargeted'], 'query' );

		$this->assertNotContains( 'wordpress mcp server', $queries, 'A focus-keyword match must suppress the untargeted flag.' );
	}

	// ------------------------------------------------------------------
	// wrong_intent — rule-based mismatch detection
	// ------------------------------------------------------------------

	public function test_wrong_intent_flags_comparison_query_on_thin_page() {
		$rows          = array( $this->row( 'mcpwp vs competitor alternative', 'https://example.com/blog/thin-post', '2026-09-20', 1, 20, 5.0 ) );
		$content_index = array(
			array(
				'id'         => 2,
				'url'        => 'https://example.com/blog/thin-post',
				'post_type'  => 'post',
				'title'      => 'Thin Post',
				'slug'       => 'thin-post',
				'has_table'  => false,
				'word_count' => 80,
				'tokens'     => array( 'thin', 'post' ),
			),
		);

		$result = Mcpwp_Seo_Flags::compute( $rows, $content_index );
		$queries = array_column( $result['flags']['wrong_intent'], 'query' );

		$this->assertContains( 'mcpwp vs competitor alternative', $queries );
		$this->assertSame( 'heuristic', $result['flags']['wrong_intent'][0]['confidence'] );
	}

	public function test_wrong_intent_does_not_flag_a_matching_page() {
		$rows          = array( $this->row( 'mcpwp vs competitor alternative', 'https://example.com/comparison', '2026-09-20', 1, 20, 5.0 ) );
		$content_index = array(
			array(
				'id'         => 3,
				'url'        => 'https://example.com/comparison',
				'post_type'  => 'page',
				'title'      => 'MCPWP vs Competitor',
				'slug'       => 'comparison',
				'has_table'  => true,
				'word_count' => 1200,
				'tokens'     => array( 'mcpwp', 'competitor' ),
			),
		);

		$result  = Mcpwp_Seo_Flags::compute( $rows, $content_index );
		$queries = array_column( $result['flags']['wrong_intent'], 'query' );

		$this->assertNotContains( 'mcpwp vs competitor alternative', $queries );
	}

	public function test_wrong_intent_ignores_queries_with_no_clear_intent_signal() {
		$rows          = array( $this->row( 'random keyword phrase', 'https://example.com/anything', '2026-09-20', 1, 20, 5.0 ) );
		$content_index = array(
			array(
				'id'         => 4,
				'url'        => 'https://example.com/anything',
				'post_type'  => 'post',
				'title'      => 'Anything',
				'slug'       => 'anything',
				'has_table'  => false,
				'word_count' => 10,
				'tokens'     => array( 'anything' ),
			),
		);

		$result  = Mcpwp_Seo_Flags::compute( $rows, $content_index );
		$queries = array_column( $result['flags']['wrong_intent'], 'query' );

		$this->assertNotContains( 'random keyword phrase', $queries );
	}

	// ------------------------------------------------------------------
	// ai_mode — word count edges
	// ------------------------------------------------------------------

	public function test_ai_mode_word_count_edge() {
		$rows = array(
			$this->row( 'six word query right here now', 'https://example.com/a', '2026-09-20', 1, 20, 5.0 ),
			$this->row( 'this is a seven word long query', 'https://example.com/b', '2026-09-20', 1, 20, 5.0 ),
		);

		$result  = Mcpwp_Seo_Flags::compute( $rows );
		$queries = array_column( $result['flags']['ai_mode'], 'query' );

		$this->assertNotContains( 'six word query right here now', $queries );
		$this->assertContains( 'this is a seven word long query', $queries );
	}

	// ------------------------------------------------------------------
	// Shared item shape
	// ------------------------------------------------------------------

	public function test_every_item_carries_evidence_suggested_action_and_real_tools() {
		$rows = array(
			$this->row( 'low edge in band', 'https://example.com/b', '2026-09-20', 1, 100, 3.0 ),
		);

		$result = Mcpwp_Seo_Flags::compute( $rows );
		$item   = $result['flags']['almost_there'][0];

		$this->assertArrayHasKey( 'evidence', $item );
		$this->assertNotEmpty( $item['evidence'] );
		$this->assertArrayHasKey( 'suggested_action', $item );
		$this->assertStringContainsString( 'https://example.com/b', $item['suggested_action'] );
		$this->assertArrayHasKey( 'tools', $item );
		$this->assertIsArray( $item['tools'] );
		$this->assertNotEmpty( $item['tools'] );
	}

	public function test_summary_counts_match_flag_sizes() {
		$rows = array(
			$this->row( 'low edge in band', 'https://example.com/b', '2026-09-20', 1, 100, 3.0 ),
		);

		$result = Mcpwp_Seo_Flags::compute( $rows );

		foreach ( $result['flags'] as $flag => $items ) {
			$this->assertSame( count( $items ), $result['summary'][ $flag ] );
		}
	}
}
