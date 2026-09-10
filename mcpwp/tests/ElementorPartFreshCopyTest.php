<?php
/**
 * Regression coverage for #826: wp_apply_elementor_part copied a part's
 * element ids verbatim.
 *
 * Reported on staging15 (MCPWP 3.10.5 - 3.10.8): applying the same part to a
 * page twice answered success while the second copy collided with the first,
 * and every page that used the part carried the same ids, so id-scoped CSS
 * targeted every copy.
 *
 * On main the save's de-duplication renamed whichever copy came second in the
 * tree — which, for an insert at `start`, was the element ALREADY ON THE PAGE —
 * and the response named the part's original id for an element saved under a
 * different one.
 *
 * What this pins:
 *   - every apply inserts a fresh copy: every element id in it is a new
 *     7-character id that no element on the page already has;
 *   - the page's existing elements are never renamed;
 *   - the response reports the ids the copy was saved with and an old -> new
 *     id map, and references inside the part follow the new ids;
 *   - settings that still name an old part id (custom CSS) are reported;
 *   - a multi-section part is inserted in one write: a failure leaves the page
 *     untouched.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-elementor-pro.php';

final class Mcpwp_Fresh_Copy_Pro_Double extends Mcpwp_Elementor_Pro {

	/** @var array<int,array> */
	private $parts;

	public function __construct( array $parts, $basic ) {
		$this->parts = $parts;

		$property = new ReflectionProperty( Mcpwp_Elementor_Pro::class, 'basic_handler' );
		$property->setAccessible( true );
		$property->setValue( $this, $basic );
	}

	public function get_part( $part_id ) {
		return $this->parts[ $part_id ] ?? new WP_Error( 'not_found', 'Part not found.' );
	}
}

final class ElementorPartFreshCopyTest extends TestCase {

	private const PAGE_ID = 8260;

	private const PART_ID = 8261;

	private const PART_IDS = array( 'prtsec1', 'prtcol1', 'prthd01', 'prtlg01', 'prttf01' );

	/** @var array */
	private $meta_before = array();

	/** @var array */
	private $posts_before = array();

	protected function setUp(): void {
		$this->meta_before  = $GLOBALS['_mcpwp_test_meta'] ?? array();
		$this->posts_before = $GLOBALS['_mcpwp_test_posts'] ?? array();

		$GLOBALS['_mcpwp_test_meta']  = array();
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PAGE_ID => new WP_Post(
				array(
					'ID'          => self::PAGE_ID,
					'post_type'   => 'page',
					'post_title'  => 'Part fresh copy probe',
					'post_name'   => 'part-fresh-copy-probe',
					'post_status' => 'draft',
				)
			),
		);

		$this->store( array( $this->page_section() ) );
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_meta']  = $this->meta_before;
		$GLOBALS['_mcpwp_test_posts'] = $this->posts_before;
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	private function page_section(): array {
		return array(
			'id'       => 'pgsec01',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => 'pgcol01',
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => array(
						array(
							'id'         => 'pghd001',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array( 'title' => 'Existing' ),
							'elements'   => array(),
						),
					),
				),
			),
		);
	}

	private function part_section(): array {
		return array(
			'id'       => 'prtsec1',
			'elType'   => 'section',
			'settings' => array( 'custom_css' => 'selector .x{color:red} .elementor-element-prthd01{margin:0}' ),
			'elements' => array(
				array(
					'id'       => 'prtcol1',
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => array(
						array(
							'id'         => 'prthd01',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array( 'title' => 'Stat' ),
							'elements'   => array(),
						),
						array(
							'id'         => 'prtlg01',
							'elType'     => 'widget',
							'widgetType' => 'loop-grid',
							'settings'   => array( 'posts_per_page' => 3 ),
							'elements'   => array(),
						),
						array(
							'id'         => 'prttf01',
							'elType'     => 'widget',
							'widgetType' => 'taxonomy-filter',
							'settings'   => array( 'selected_element' => 'prtlg01' ),
							'elements'   => array(),
						),
					),
				),
			),
		);
	}

	private function pro( array $roots = array() ): Mcpwp_Fresh_Copy_Pro_Double {
		$basic = ( new ReflectionClass( Mcpwp_Elementor_Basic::class ) )->newInstanceWithoutConstructor();

		return new Mcpwp_Fresh_Copy_Pro_Double(
			array( self::PART_ID => array( 'elementor_data' => empty( $roots ) ? array( $this->part_section() ) : $roots ) ),
			$basic
		);
	}

	private function store( array $tree ): void {
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = wp_json_encode( $tree );
	}

	private function stored_raw(): string {
		return (string) $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'];
	}

	private function stored(): array {
		$tree = json_decode( stripslashes( $this->stored_raw() ), true );
		$this->assertIsArray( $tree );

		return $tree;
	}

	/**
	 * Every id in a tree, depth first.
	 *
	 * @return string[]
	 */
	private function all_ids( array $elements ): array {
		$ids = array();
		foreach ( $elements as $el ) {
			$ids[] = (string) $el['id'];
			if ( ! empty( $el['elements'] ) ) {
				$ids = array_merge( $ids, $this->all_ids( $el['elements'] ) );
			}
		}

		return $ids;
	}

	// ---------------------------------------------------------------------
	// Tests.
	// ---------------------------------------------------------------------

	public function test_applying_the_same_part_twice_inserts_two_copies_with_fresh_distinct_ids(): void {
		$pro = $this->pro();

		$first  = $pro->insert_part_into_page( self::PART_ID, self::PAGE_ID, 'end' );
		$second = $pro->insert_part_into_page( self::PART_ID, self::PAGE_ID, 'end' );

		$this->assertNotInstanceOf( WP_Error::class, $first );
		$this->assertNotInstanceOf( WP_Error::class, $second );

		$tree = $this->stored();
		$this->assertCount( 3, $tree, 'The page section and two copies of the part.' );
		$this->assertSame( array( 'pgsec01', 'pgcol01', 'pghd001' ), $this->all_ids( array( $tree[0] ) ), 'The page\'s own elements keep their ids.' );

		$all = $this->all_ids( $tree );
		$this->assertSame( $all, array_values( array_unique( $all ) ), 'No two elements on the page share an id.' );

		foreach ( array( $tree[1], $tree[2] ) as $copy ) {
			foreach ( $this->all_ids( array( $copy ) ) as $id ) {
				$this->assertMatchesRegularExpression( '/^[a-z0-9]{7}$/', $id );
				$this->assertNotContains( $id, self::PART_IDS, 'A copy never carries the part\'s stored ids.' );
			}
		}

		$this->assertSame( array( $tree[1]['id'] ), $first['inserted_ids'], 'The response names the id each root was saved with.' );
		$this->assertSame( array( $tree[2]['id'] ), $second['inserted_ids'] );
		$this->assertSame( 2, $second['sections_before'] );
		$this->assertSame( 3, $second['sections_after'] );
	}

	public function test_the_response_maps_every_part_id_to_the_id_it_was_saved_with(): void {
		$result = $this->pro()->insert_part_into_page( self::PART_ID, self::PAGE_ID, 'end' );

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$map = (array) $result['id_map'];
		$this->assertSame( self::PART_IDS, array_keys( $map ) );
		$this->assertSame( array_values( $map ), $this->all_ids( array( $this->stored()[1] ) ), 'Old -> new, in tree order, as stored.' );
	}

	public function test_references_inside_the_part_follow_the_new_ids(): void {
		$result = $this->pro()->insert_part_into_page( self::PART_ID, self::PAGE_ID, 'end' );

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$map    = (array) $result['id_map'];
		$filter = $this->stored()[1]['elements'][0]['elements'][2];
		$this->assertSame( $map['prtlg01'], $filter['settings']['selected_element'], 'The Taxonomy Filter in the copy targets the copy\'s Loop Grid, not the part\'s.' );
	}

	public function test_css_that_still_names_an_old_part_id_is_reported(): void {
		$result = $this->pro()->insert_part_into_page( self::PART_ID, self::PAGE_ID, 'end' );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertNotEmpty( $result['possible_references'] );

		$found = false;
		foreach ( $result['warnings'] ?? array() as $warning ) {
			if ( false !== strpos( $warning, 'prthd01' ) && false !== strpos( $warning, 'custom_css' ) ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'A custom_css rule scoped to an old part id no longer matches the copy; the caller must be told.' );
	}

	public function test_inserting_before_an_earlier_copy_never_renames_the_element_already_on_the_page(): void {
		$this->store( array( $this->part_section() ) );

		$result = $this->pro()->insert_part_into_page( self::PART_ID, self::PAGE_ID, 'start' );

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$tree = $this->stored();
		$this->assertCount( 2, $tree );
		$this->assertSame( self::PART_IDS, $this->all_ids( array( $tree[1] ) ), 'The copy already on the page is referenced by CSS and other widgets by these ids.' );
		$this->assertSame( array( $tree[0]['id'] ), $result['inserted_ids'] );
	}

	public function test_a_multi_section_part_is_one_write_and_a_bad_section_leaves_the_page_untouched(): void {
		$before = $this->stored_raw();

		$result = $this->pro( array( $this->part_section(), array( 'id' => 'bad0001', 'settings' => array() ) ) )
			->insert_part_into_page( self::PART_ID, self::PAGE_ID, 'end' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $before, $this->stored_raw(), 'The first section must not be saved when the second cannot be.' );
	}
}
