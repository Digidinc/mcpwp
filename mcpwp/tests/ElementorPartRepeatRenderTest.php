<?php
/**
 * Regression coverage for #769: a second reusable-part insert was reported as
 * corrupt after Elementor added default `isInner: false` fields while saving.
 *
 * Elementor 4.2.4's Element_Base::get_raw_data() always emits `isInner`, using
 * false as the default. Widget_Base::get_raw_data() then removes that field for
 * widgets. A saved library part may therefore omit `isInner` on sections and
 * columns even though it is semantically identical to false. The exact tree
 * comparator added in #768 treated that normal form as a mutation, restored
 * raw meta after the successful render, and returned render_verified=false.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-elementor-pro.php';

final class Mcpwp_Repeat_Insert_Pro_Double extends Mcpwp_Elementor_Pro {

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

final class ElementorPartRepeatRenderTest extends TestCase {

	private const PAGE_ID = 399810;

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts']                  = array();
		$GLOBALS['_mcpwp_test_meta']                   = array();
		$GLOBALS['mcpwp_test_filters']                 = array();
		$GLOBALS['mcpwp_test_allowed_html']            = array( 'strong' => array() );
		$GLOBALS['mcpwp_test_save_calls']              = 0;
		$GLOBALS['mcpwp_test_corrupt_page_id']         = self::PAGE_ID;
		$GLOBALS['mcpwp_test_normalize_default_fields'] = true;
		$GLOBALS['mcpwp_test_rendered_root_ids']       = array();

		$GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ] = (object) array(
			'ID'          => self::PAGE_ID,
			'post_type'   => 'page',
			'post_title'  => 'Repeat insert canary',
			'post_status' => 'draft',
		);
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = '[]';
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_normalize_default_fields'] = false;
		$GLOBALS['mcpwp_test_rendered_root_ids']       = array();
	}

	private function install_elementor(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		require_once __DIR__ . '/fixtures/elementor-kses-doubles.php';

		\Elementor\Plugin::$instance = new \Elementor\Plugin();
	}

	private function install_reflecting_wpdb(): void {
		$GLOBALS['wpdb'] = new class() extends Mcpwp_Test_WPDB {
			public function get_var( $query = '', $x = 0, $y = 0 ) {
				if ( false !== strpos( (string) $query, '_elementor_data' ) ) {
					return $GLOBALS['_mcpwp_test_meta'][ ElementorPartRepeatRenderTest::page_id() ]['_elementor_data'] ?? null;
				}

				return null;
			}
		};
	}

	public static function page_id(): int {
		return self::PAGE_ID;
	}

	/**
	 * `isInner` has no special meaning inside widget settings. A settings value
	 * can coincidentally use Elementor-like key names and must still be compared
	 * exactly.
	 */
	public function test_is_inner_default_is_not_normalized_inside_arbitrary_settings_maps(): void {
		$expected = array(
			'setting_payload' => array(
				'id'       => 'not-an-element',
				'elType'   => 'custom-setting-value',
				'settings' => 'scalar',
				'elements' => 'scalar',
			),
		);
		$actual = $expected;
		$actual['setting_payload']['isInner'] = false;

		$method = new ReflectionMethod( Mcpwp_Elementor_Basic::class, 'elementor_values_match' );
		$method->setAccessible( true );

		$this->assertFalse(
			$method->invoke( new Mcpwp_Elementor_Basic(), $expected, $actual ),
			'Only nodes reached through the root element list or an element children list may normalize `isInner`.'
		);
	}

	private function part( string $root_id, bool $include_default_fields, bool $omit_widget_elements = false ): array {
		$column_id = substr( md5( $root_id . ':column' ), 0, 8 );
		$widget_id = substr( md5( $root_id . ':widget' ), 0, 8 );
		$section = array(
			'id'       => $root_id,
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => $column_id,
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => array(
						array(
							'id'         => $widget_id,
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array( 'title' => $root_id ),
							'elements'   => array(),
						),
					),
				),
			),
		);

		if ( $include_default_fields ) {
			$section['isInner']                = false;
			$section['elements'][0]['isInner'] = false;
		}
		if ( $omit_widget_elements ) {
			unset( $section['elements'][0]['elements'][0]['elements'] );
		}

		return array( 'elementor_data' => array( $section ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_second_part_insert_accepts_elementor_default_field_normalization(): void {
		$this->install_elementor();
		$this->install_reflecting_wpdb();

		$pro = new Mcpwp_Repeat_Insert_Pro_Double(
			array(
				399477 => $this->part( '41c3ee18', true ),
				399478 => $this->part( 'hnext0sec', false ),
			),
			new Mcpwp_Elementor_Basic()
		);

		$first  = $pro->insert_part_into_page( 399477, self::PAGE_ID, 'end' );
		$second = $pro->insert_part_into_page( 399478, self::PAGE_ID, 'end' );

		$this->assertTrue( $first['last_operation']['render_verified'], 'The already-normalized first part establishes a clean rendered page.' );
		$this->assertTrue( $second['last_operation']['debug']['html_cache_rebuilt'], 'Elementor completed the second render call.' );
		$this->assertFalse( $second['last_operation']['debug']['html_rebuild_corrupted_data'] ?? false, 'Adding default false fields must not be classified as data corruption.' );
		$this->assertTrue( $second['last_operation']['render_verified'], 'A successful normalized second insert is a verified render.' );
		$this->assertSame( array( '41c3ee18', 'hnext0sec' ), $GLOBALS['mcpwp_test_rendered_root_ids'], 'The rebuilt HTML tree contains both inserted roots in order.' );

		$stored = json_decode( $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'], true );
		$this->assertCount( 2, $stored );
		$this->assertSame( false, $stored[1]['isInner'], 'The harmless Elementor normal form remains stored instead of being rolled back.' );
		$this->assertSame( false, $stored[1]['elements'][0]['isInner'] );
	}

	/**
	 * Exact live shape from staging canary 399825 (#769): the saved part omitted
	 * `elements` on a heading widget and Elementor 4.2.4 materialized an empty
	 * list during the second save. The recursive element count stayed 44 -> 44,
	 * but the strict comparator restored meta and reported render_verified=false.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_second_part_insert_accepts_empty_elements_materialized_on_a_leaf_widget(): void {
		$this->install_elementor();
		$this->install_reflecting_wpdb();

		$pro = new Mcpwp_Repeat_Insert_Pro_Double(
			array(
				399477 => $this->part( '41c3ee18', true ),
				399478 => $this->part( 'hnext0sec', false, true ),
			),
			new Mcpwp_Elementor_Basic()
		);

		$first  = $pro->insert_part_into_page( 399477, self::PAGE_ID, 'end' );
		$second = $pro->insert_part_into_page( 399478, self::PAGE_ID, 'end' );

		$this->assertTrue( $first['last_operation']['render_verified'] );
		$this->assertTrue( $second['last_operation']['debug']['html_cache_rebuilt'] );
		$this->assertFalse( $second['last_operation']['debug']['html_rebuild_corrupted_data'] ?? false );
		$this->assertTrue( $second['last_operation']['render_verified'] );
		$this->assertSame( array( '41c3ee18', 'hnext0sec' ), $GLOBALS['mcpwp_test_rendered_root_ids'] );

		$stored = json_decode( $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'], true );
		$this->assertSame( array(), $stored[1]['elements'][0]['elements'][0]['elements'] );
	}

	/**
	 * @dataProvider unsafe_empty_elements_normalization_provider
	 */
	public function test_empty_elements_is_not_normalized_outside_a_strict_leaf_widget( array $expected, array $actual ): void {
		$method = new ReflectionMethod( Mcpwp_Elementor_Basic::class, 'elementor_values_match' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( new Mcpwp_Elementor_Basic(), array( $expected ), array( $actual ) ) );
	}

	public function unsafe_empty_elements_normalization_provider(): array {
		return array(
			'container is not a leaf' => array(
				array( 'id' => 'root0001', 'elType' => 'section', 'settings' => array() ),
				array( 'id' => 'root0001', 'elType' => 'section', 'settings' => array(), 'elements' => array() ),
			),
			'widget without widgetType is malformed' => array(
				array( 'id' => 'leaf0001', 'elType' => 'widget', 'settings' => array() ),
				array( 'id' => 'leaf0001', 'elType' => 'widget', 'settings' => array(), 'elements' => array() ),
			),
			'non-empty children are meaningful' => array(
				array( 'id' => 'leaf0001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array() ),
				array(
					'id'         => 'leaf0001',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => array(),
					'elements'   => array( array( 'id' => 'child001' ) ),
				),
			),
			'content still differs' => array(
				array( 'id' => 'leaf0001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Expected' ) ),
				array( 'id' => 'leaf0001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Changed' ), 'elements' => array() ),
			),
			'identity still differs' => array(
				array( 'id' => 'leaf0001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array() ),
				array( 'id' => 'leaf0002', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array(), 'elements' => array() ),
			),
		);
	}

	public function test_empty_elements_is_not_normalized_inside_arbitrary_settings_maps(): void {
		$expected = array(
			array(
				'id'         => 'leaf0001',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array(
					'payload' => array(
						'id'         => 'fake0001',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => array(),
					),
				),
				'elements' => array(),
			),
		);
		$actual = $expected;
		$actual[0]['settings']['payload']['elements'] = array();

		$method = new ReflectionMethod( Mcpwp_Elementor_Basic::class, 'elementor_values_match' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( new Mcpwp_Elementor_Basic(), $expected, $actual ) );
	}
}
