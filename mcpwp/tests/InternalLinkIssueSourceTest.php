<?php
/**
 * Regression coverage for #728: wp_validate_internal_links could not be acted on.
 *
 * Reported as a stale cache. It is not one — the validator reads post_content and
 * `_elementor_data` live on every call, and caches nothing. What it did not do was
 * say WHICH of those two copies a reported link came from.
 *
 * That is what made it look like a cache. An operator repaired the Elementor data
 * with a bulk find/replace, verified with wp_get_elementor that the old string was
 * gone, re-ran the check, and got the same issues back — because the remaining copy
 * was in post_content, and nothing in the report could tell them so. A link that is
 * genuinely still there and a link the repair missed read identically.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content-graph.php';

final class InternalLinkIssueSourceTest extends TestCase {

	private const SOURCE_ID = 8200;
	private const TARGET_ID = 8201;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['_mcpwp_test_meta']        = array();
		$GLOBALS['_mcpwp_test_posts']       = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	/**
	 * A page whose Elementor data is already repaired while post_content still
	 * carries the old link — exactly the state the reporter was in and could not
	 * see.
	 */
	private function seed_split_sources(): void {
		$GLOBALS['_mcpwp_test_posts'][ self::TARGET_ID ] = new WP_Post(
			array(
				'ID'          => self::TARGET_ID,
				'post_type'   => 'page',
				'post_title'  => 'Chapter 7',
				'post_name'   => 'chapter-7',
				'post_status' => 'publish',
			)
		);

		$GLOBALS['_mcpwp_test_posts'][ self::SOURCE_ID ] = new WP_Post(
			array(
				'ID'           => self::SOURCE_ID,
				'post_type'    => 'page',
				'post_title'   => 'Companion',
				'post_name'    => 'companion',
				'post_status'  => 'publish',
				// Repaired in _elementor_data, still stale here.
				'post_content' => '<p><a href="chapter-7.html">click here</a></p>',
			)
		);
	}

	private function validate(): array {
		$controller = new Mcpwp_REST_Content_Graph();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/content-graph/validate-links',
			array( 'post_types' => 'page', 'limit' => 100 )
		);

		$response = $controller->validate_internal_links( $request );
		$this->assertNotInstanceOf( WP_Error::class, $response );

		return $response->get_data();
	}

	public function test_every_issue_names_the_stored_copy_it_came_from(): void {
		$this->seed_split_sources();

		$data   = $this->validate();
		$issues = $data['issues'] ?? $data['data']['issues'] ?? array();

		$this->assertNotEmpty( $issues, 'A stale relative target must still be reported.' );

		foreach ( $issues as $issue ) {
			$this->assertArrayHasKey(
				'source',
				$issue,
				'Without this the report cannot be acted on: a repair to one copy leaves the other reporting the same link.'
			);
			$this->assertContains( $issue['source'], array( 'post_content', 'elementor' ) );
		}
	}

	public function test_a_post_content_only_link_is_attributed_to_post_content(): void {
		$this->seed_split_sources();

		$data   = $this->validate();
		$issues = $data['issues'] ?? $data['data']['issues'] ?? array();

		$stale = array_values(
			array_filter(
				$issues,
				static fn( $issue ) => false !== strpos( (string) $issue['url'], 'chapter-7.html' )
			)
		);

		$this->assertNotEmpty( $stale, 'The stale target itself must appear in the report.' );
		$this->assertSame(
			'post_content',
			$stale[0]['source'],
			'It lives only in post_content — saying so is the difference between an actionable report and a bug report about caching.'
		);
	}

	public function test_the_summary_counts_issues_per_stored_copy(): void {
		$this->seed_split_sources();

		$data    = $this->validate();
		$summary = $data['summary'] ?? $data['data']['summary'] ?? array();

		$this->assertArrayHasKey( 'by_source', $summary );
		$this->assertArrayHasKey( 'post_content', $summary['by_source'] );
		$this->assertArrayHasKey( 'elementor', $summary['by_source'] );
		$this->assertGreaterThan( 0, $summary['by_source']['post_content'] );
		$this->assertSame(
			(int) $summary['issue_count'],
			array_sum( $summary['by_source'] ),
			'Every issue is attributed to exactly one copy — an unattributed issue would be the same blind spot again.'
		);
	}
}
