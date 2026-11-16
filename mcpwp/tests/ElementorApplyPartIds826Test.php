<?php
/**
 * Regression coverage for #826: applying the same reusable part twice must
 * insert two sections with distinct element ids and report id_map.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-elementor-pro.php';

final class Mcpwp_Apply_Part_826_Pro_Double extends Mcpwp_Elementor_Pro {

	/** @var array<int,array> */
	private $parts;

	public function __construct( array $parts, Mcpwp_Elementor_Basic $basic ) {
		$this->parts = $parts;

		$property = new ReflectionProperty( Mcpwp_Elementor_Pro::class, 'basic_handler' );
		$property->setAccessible( true );
		$property->setValue( $this, $basic );
	}

	public function get_part( $part_id ) {
		return $this->parts[ $part_id ] ?? new WP_Error( 'not_found', 'Part not found.' );
	}
}

final class ElementorApplyPartIds826Test extends TestCase {

	private const PAGE_ID = 826001;
	private const PART_ID = 826002;

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts']                  = array();
		$GLOBALS['_mcpwp_test_meta']                   = array();
		$GLOBALS['mcpwp_test_filters']                 = array();
		$GLOBALS['mcpwp_test_save_calls']              = 0;
		$GLOBALS['mcpwp_test_corrupt_page_id']         = self::PAGE_ID;
		$GLOBALS['mcpwp_test_normalize_default_fields'] = true;
		$GLOBALS['mcpwp_test_rendered_root_ids']       = array();

		$GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ] = (object) array(
			'ID'          => self::PAGE_ID,
			'post_type'   => 'page',
			'post_title'  => 'Apply part ids canary',
			'post_status' => 'draft',
		);
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = '[]';
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_normalize_default_fields'] = false;
		$GLOBALS['mcpwp_test_rendered_root_ids']        = array();
	}

	private function install_elementor(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		require_once __DIR__ . '/fixtures/elementor-kses-doubles.php';

		\Elementor\Plugin::$instance = new \Elementor\Plugin();
	}

	private function part_tree( string $root_id ): array {
		return array(
			'elementor_data' => array(
				array(
					'id'       => $root_id,
					'elType'   => 'section',
					'settings' => array(
						'custom_css' => '#elementor-element-' . $root_id . ' { padding: 10px; }',
					),
					'elements' => array(
						array(
							'id'       => 'col0001',
							'elType'   => 'column',
							'settings' => array( '_column_size' => 100 ),
							'elements' => array(
								array(
									'id'         => 'wid0001',
									'elType'     => 'widget',
									'widgetType' => 'heading',
									'settings'   => array( 'title' => 'Hero' ),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_second_apply_of_the_same_part_increases_sections_and_returns_id_map(): void {
		$this->install_elementor();

		$pro   = new Mcpwp_Apply_Part_826_Pro_Double(
			array( self::PART_ID => $this->part_tree( 'sec0001' ) ),
			new Mcpwp_Elementor_Basic()
		);
		$first = $pro->insert_part_into_page( self::PART_ID, self::PAGE_ID, 'end' );
		$second = $pro->insert_part_into_page( self::PART_ID, self::PAGE_ID, 'end' );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertGreaterThan( $first['sections_before'], $first['sections_after'] );
		$this->assertGreaterThan( $second['sections_before'], $second['sections_after'] );
		$this->assertSame( 2, $second['sections_after'], 'Two applies must leave two top-level sections.' );

		$this->assertArrayHasKey( 'id_map', $first );
		$this->assertArrayHasKey( 'id_map', $second );
		$this->assertNotSame(
			(array) $first['id_map'],
			(array) $second['id_map'],
			'Each apply gets its own id map.'
		);

		$stored = json_decode( $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'], true );
		$this->assertCount( 2, $stored );
		$this->assertNotSame( $stored[0]['id'], $stored[1]['id'] );

		$this->assertArrayHasKey( 'css_scoped_to_ids', $first );
		$this->assertContains( 'sec0001', $first['css_scoped_to_ids'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_allow_duplicate_false_refuses_a_second_apply(): void {
		$this->install_elementor();

		$pro = new Mcpwp_Apply_Part_826_Pro_Double(
			array( self::PART_ID => $this->part_tree( 'dup0001' ) ),
			new Mcpwp_Elementor_Basic()
		);

		$pro->insert_part_into_page( self::PART_ID, self::PAGE_ID, 'end' );
		$again = $pro->insert_part_into_page(
			self::PART_ID,
			self::PAGE_ID,
			'end',
			array( 'allow_duplicate' => false )
		);

		$this->assertIsArray( $again );
		$this->assertSame( 'already_present', $again['status'] );

		$stored = json_decode( $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'], true );
		$this->assertCount( 1, $stored );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_first_apply_on_an_empty_page_keeps_original_part_ids(): void {
		$this->install_elementor();

		$pro    = new Mcpwp_Apply_Part_826_Pro_Double(
			array( self::PART_ID => $this->part_tree( 'sec0001' ) ),
			new Mcpwp_Elementor_Basic()
		);
		$first  = $pro->insert_part_into_page( self::PART_ID, self::PAGE_ID, 'end' );
		$stored = json_decode( $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'], true );

		$this->assertSame( array(), (array) $first['id_map'], 'No collisions means an empty id_map.' );
		$this->assertSame( 'sec0001', $stored[0]['id'] );
		$this->assertSame( 'col0001', $stored[0]['elements'][0]['id'] );
	}

	public function test_remap_tree_for_insert_assigns_fresh_ids_not_on_the_page(): void {
		$element = array(
			'id'       => 'sec0001',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => 'col0001',
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => array(
						array(
							'id'         => 'wid0001',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array( 'title' => 'x' ),
						),
					),
				),
			),
		);

		$remap = Mcpwp_Elementor_Element_Ids::remap_tree_for_insert(
			$element,
			'826|1|1',
			array( 'sec0001' => true, 'col0001' => true )
		);

		$this->assertNotSame( 'sec0001', $remap['elements']['id'] );
		$this->assertArrayHasKey( 'sec0001', (array) $remap['id_map'] );
	}

	public function test_remap_preserves_all_digit_id_map_keys(): void {
		$element = array(
			'id'       => '1234567',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(),
		);

		$remap = Mcpwp_Elementor_Element_Ids::remap_tree_for_insert(
			$element,
			'826|1|1',
			array( '1234567' => true )
		);

		$map = (array) $remap['id_map'];
		$this->assertArrayHasKey( '1234567', $map );
		$this->assertNotSame( '1234567', $remap['elements']['id'] );
	}

	public function test_remap_rewrites_custom_css_selectors_after_collision(): void {
		$remap = Mcpwp_Elementor_Element_Ids::remap_tree_for_insert(
			array(
				'id'       => 'sec0001',
				'elType'   => 'section',
				'settings' => array(
					'custom_css' => '.elementor-element-sec0001 { margin: 0; } [data-id="sec0001"] { color: red; }',
				),
				'elements' => array(),
			),
			'826|css|1',
			array( 'sec0001' => true )
		);

		$new_root = $remap['elements']['id'];
		$css      = $remap['elements']['settings']['custom_css'];
		$this->assertStringContainsString( '.elementor-element-' . $new_root, $css );
		$this->assertStringContainsString( '[data-id="' . $new_root . '"]', $css );
		$this->assertStringNotContainsString( 'sec0001', $css );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_allow_duplicate_false_ignores_stale_history_without_tree_marker(): void {
		$this->install_elementor();

		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_mcpwp_applied_part_ids'] = array( self::PART_ID );
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data']       = '[]';

		$pro   = new Mcpwp_Apply_Part_826_Pro_Double(
			array( self::PART_ID => $this->part_tree( 'fre0001' ) ),
			new Mcpwp_Elementor_Basic()
		);
		$first = $pro->insert_part_into_page(
			self::PART_ID,
			self::PAGE_ID,
			'end',
			array( 'allow_duplicate' => false )
		);

		$this->assertIsArray( $first );
		$this->assertTrue( $first['inserted'] );
	}

	public function test_duplicate_ids_in_part_remap_only_the_second_occurrence(): void {
		$roots = array(
			array(
				'id'       => 'dup0001',
				'elType'   => 'section',
				'settings' => array(
					'custom_css' => '.elementor-element-dup0001 { margin: 1px; }',
				),
				'elements' => array(),
			),
			array(
				'id'       => 'dup0001',
				'elType'   => 'section',
				'settings' => array(
					'custom_css' => '.elementor-element-dup0001 { margin: 2px; }',
				),
				'elements' => array(),
			),
		);

		$remap = Mcpwp_Elementor_Element_Ids::remap_part_roots_for_insert( $roots, '826|dup|1', array() );

		$this->assertSame( 'dup0001', $remap['roots'][0]['id'] );
		$this->assertNotSame( 'dup0001', $remap['roots'][1]['id'] );
		$this->assertStringContainsString( 'dup0001', $remap['roots'][0]['settings']['custom_css'] );
		$this->assertStringNotContainsString( 'dup0001', $remap['roots'][1]['settings']['custom_css'] );
	}

	public function test_heading_text_mentions_are_listed_not_rewritten(): void {
		$remap = Mcpwp_Elementor_Element_Ids::remap_tree_for_insert(
			array(
				'id'       => 'sec0001',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'col0001',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(
							array(
								'id'         => 'wid0001',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array( 'title' => 'see #sec0001 here' ),
							),
						),
					),
				),
			),
			'826|text|1',
			array( 'sec0001' => true, 'col0001' => true, 'wid0001' => true )
		);

		$title = $remap['elements']['elements'][0]['elements'][0]['settings']['title'];
		$this->assertSame( 'see #sec0001 here', $title );

		$mentioned = wp_list_pluck( $remap['possible_references'], 'id' );
		$this->assertContains( 'sec0001', $mentioned );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_allow_duplicate_false_refuses_unstamped_copy_by_root_ids(): void {
		$this->install_elementor();

		$pro = new Mcpwp_Apply_Part_826_Pro_Double(
			array( self::PART_ID => $this->part_tree( 'leg0001' ) ),
			new Mcpwp_Elementor_Basic()
		);

		$stored = array(
			array(
				'id'       => 'leg0001',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'col0001',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(
							array(
								'id'         => 'wid0001',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array( 'title' => 'Legacy copy' ),
							),
						),
					),
				),
			),
		);
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = wp_json_encode( $stored );

		$again = $pro->insert_part_into_page(
			self::PART_ID,
			self::PAGE_ID,
			'end',
			array( 'allow_duplicate' => false )
		);

		$this->assertIsArray( $again );
		$this->assertSame( 'already_present', $again['status'] );
		$this->assertCount( 1, json_decode( $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'], true ) );
	}
}