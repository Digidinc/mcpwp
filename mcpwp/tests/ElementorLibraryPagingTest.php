<?php
/**
 * Coverage for #635 — library listings that could not be paged and a total
 * that restated the caller's own page size.
 *
 * Verifying a 13-item template library meant listing it, then making 13
 * separate get calls, decoding each one's Elementor JSON, and counting widgets
 * by hand. An N+1 that grows with the library, on responses that had no upper
 * bound.
 *
 * Two things were already wrong before the feature was missing, and both
 * looked like they worked:
 *
 *   - The REST layer accepted `per_page` and `page` and passed them straight
 *     into get_posts(), which reads `posts_per_page` and `paged`. The
 *     parameters were advertised, accepted, and inert — every request returned
 *     the same first 50 rows.
 *
 *   - `total` was `count( $templates )`: the number of rows on the page just
 *     returned, not the number that exist. With 60 templates at per_page 25 it
 *     answered 25, so a client paginating on it stopped after page one
 *     believing it had seen the whole library.
 *
 * Both are the same failure this release keeps finding: a response that cannot
 * be distinguished from a correct one.
 *
 * Confirmed against real WordPress on the rig with a 13-item library before
 * these tests were written:
 *
 *     page 1: returned=5  total=12  pages=3
 *     page 2: returned=5  total=12  pages=3
 *     page 3: returned=2  total=12  pages=3
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorLibraryPagingTest extends TestCase {

	/** @var array */
	private $posts_before = array();

	/** @var array */
	private $meta_before = array();

	protected function setUp(): void {
		$this->posts_before = $GLOBALS['_mcpwp_test_posts'] ?? array();
		$this->meta_before  = $GLOBALS['_mcpwp_test_meta'] ?? array();

		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta']  = array();

		// Mcpwp_Elementor_Pro::is_elementor_active() reads did_action(
		// 'elementor/loaded' ). Without it every listing short-circuits and
		// the assertions below would pass over an empty envelope, so the
		// premise is established rather than assumed.
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;
		$this->assertTrue( (bool) did_action( 'elementor/loaded' ) );
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_posts'] = $this->posts_before;
		$GLOBALS['_mcpwp_test_meta']  = $this->meta_before;

		// Not left set for whatever runs next: a global that makes Elementor
		// look loaded changes the behaviour of every Elementor-sensitive test
		// after it (#757's family).
		unset( $GLOBALS['mcpwp_test_did_action']['elementor/loaded'] );
	}

	/**
	 * Seed a library of templates.
	 *
	 * @param int $count How many.
	 */
	private function seed_library( int $count ): void {
		for ( $i = 1; $i <= $count; $i++ ) {
			$id = 9400 + $i;

			$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post(
				array(
					'ID'          => $id,
					'post_type'   => 'elementor_library',
					'post_title'  => sprintf( 'Library item %02d', $i ),
					'post_name'   => 'library-item-' . $i,
					'post_status' => 'publish',
				)
			);

			update_post_meta( $id, '_elementor_template_type', 'section' );
			update_post_meta( $id, '_elementor_data', wp_json_encode( array( $this->section( $i ) ) ) );
		}
	}

	/**
	 * One section carrying $widgets heading widgets.
	 *
	 * @param int $widgets Widget count.
	 * @return array
	 */
	private function section( int $widgets ): array {
		$children = array();

		for ( $w = 1; $w <= $widgets; $w++ ) {
			$children[] = array(
				'id'         => sprintf( 'wid%05d', $w ),
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => 'Heading ' . $w ),
			);
		}

		return array(
			'id'       => 'sec00001',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => 'col00001',
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => $children,
				),
			),
		);
	}

	private function pro(): Mcpwp_Elementor_Pro {
		return new Mcpwp_Elementor_Pro();
	}

	// ---------------------------------------------------------------
	// Pagination that actually paginates.
	// ---------------------------------------------------------------

	public function test_per_page_and_page_are_honoured(): void {
		$this->seed_library( 12 );

		$first = $this->pro()->get_templates_page( array( 'per_page' => 5, 'page' => 1 ) );
		$last  = $this->pro()->get_templates_page( array( 'per_page' => 5, 'page' => 3 ) );

		// Before this, per_page/page reached get_posts() under names it does
		// not read, so both of these returned the same first 50 rows.
		$this->assertCount( 5, $first['items'] );
		$this->assertCount( 2, $last['items'] );
		$this->assertNotEquals( $first['items'][0]['id'], $last['items'][0]['id'] );
	}

	public function test_total_is_the_library_size_not_the_page_size(): void {
		$this->seed_library( 12 );

		$page = $this->pro()->get_templates_page( array( 'per_page' => 5, 'page' => 1 ) );

		// The whole defect in one assertion: total used to equal the number of
		// items returned, so a client paginating on it stopped at page one.
		$this->assertSame( 12, $page['total'] );
		$this->assertNotSame( count( $page['items'] ), $page['total'] );
		$this->assertSame( 3, $page['pages'] );
	}

	public function test_the_wp_query_spellings_still_work_for_internal_callers(): void {
		// The admin library screens pass posts_per_page, not per_page.
		$this->seed_library( 12 );

		$page = $this->pro()->get_templates_page( array( 'posts_per_page' => 4, 'paged' => 2 ) );

		$this->assertCount( 4, $page['items'] );
		$this->assertSame( 12, $page['total'] );
	}

	public function test_per_page_is_clamped_so_a_listing_cannot_grow_without_bound(): void {
		$this->seed_library( 12 );

		$page = $this->pro()->get_templates_page( array( 'per_page' => 5000 ) );

		$this->assertSame( 100, $page['per_page'] );
	}

	public function test_minus_one_still_means_everything(): void {
		// Internal callers rely on it, and clamping it to 100 would silently
		// truncate the admin library inventory.
		$this->seed_library( 12 );

		$page = $this->pro()->get_templates_page( array( 'per_page' => -1 ) );

		$this->assertSame( -1, $page['per_page'] );
		$this->assertCount( 12, $page['items'] );
	}

	public function test_a_page_beyond_the_end_returns_nothing_but_still_reports_the_total(): void {
		$this->seed_library( 12 );

		$page = $this->pro()->get_templates_page( array( 'per_page' => 5, 'page' => 99 ) );

		$this->assertSame( array(), $page['items'] );
		$this->assertSame( 12, $page['total'], 'An empty page is not an empty library, and the response has to keep those apart.' );
	}

	// ---------------------------------------------------------------
	// Summaries: the N+1 this issue is about.
	// ---------------------------------------------------------------

	public function test_summaries_are_off_unless_asked_for(): void {
		$this->seed_library( 2 );

		$page = $this->pro()->get_templates_page( array() );

		$this->assertArrayNotHasKey( 'summary', $page['items'][0] );
	}

	public function test_a_summary_answers_what_a_get_call_was_needed_for(): void {
		$this->seed_library( 3 );

		$page    = $this->pro()->get_templates_page( array( 'include_summary' => true ) );
		$summary = $page['items'][0]['summary'];

		$this->assertTrue( $summary['has_data'] );
		$this->assertSame( 1, $summary['section_count'] );
		$this->assertSame( 1, $summary['widget_count'] );
		$this->assertSame( array( 'sec00001' ), $summary['top_level_ids'] );
		$this->assertGreaterThan( 0, $summary['data_bytes'] );
	}

	public function test_widgets_are_counted_through_the_whole_tree(): void {
		$this->seed_library( 1 );
		update_post_meta( 9401, '_elementor_data', wp_json_encode( array( $this->section( 7 ) ) ) );

		$page = $this->pro()->get_templates_page( array( 'include_summary' => true ) );

		// Nested two levels down, which is where a shallow count would miss
		// them and report a library of empty sections.
		$this->assertSame( 7, $page['items'][0]['summary']['widget_count'] );
	}

	public function test_a_template_with_no_data_says_so_rather_than_reporting_zeroes(): void {
		$this->seed_library( 1 );
		delete_post_meta( 9401, '_elementor_data' );

		$summary = $this->pro()->get_templates_page( array( 'include_summary' => true ) )['items'][0]['summary'];

		$this->assertFalse( $summary['has_data'] );
	}

	public function test_unreadable_data_is_not_reported_as_an_empty_template(): void {
		$this->seed_library( 1 );
		update_post_meta( 9401, '_elementor_data', '{not json' );

		$summary = $this->pro()->get_templates_page( array( 'include_summary' => true ) )['items'][0]['summary'];

		// "0 sections" and "could not read the sections" are different facts,
		// and a caller picking a template acts on them differently.
		$this->assertTrue( $summary['has_data'] );
		$this->assertTrue( $summary['unreadable'] );
		$this->assertGreaterThan( 0, $summary['data_bytes'] );
	}

	// ---------------------------------------------------------------
	// The old shape is untouched.
	// ---------------------------------------------------------------

	public function test_get_templates_still_returns_a_plain_list(): void {
		$this->seed_library( 4 );

		$list = $this->pro()->get_templates( array( 'per_page' => 2 ) );

		// The admin library screens and every existing caller were written
		// against a list. Changing a return shape under callers is exactly
		// what broke wp_assign_template earlier in this release, so the
		// envelope is a separate method rather than a replacement.
		$this->assertIsArray( $list );
		$this->assertCount( 2, $list );
		$this->assertArrayHasKey( 'id', $list[0] );
		$this->assertArrayNotHasKey( 'items', $list );
	}
}
