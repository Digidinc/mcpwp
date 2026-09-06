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
	 * Invoke the private comparator while retaining its by-reference diagnostic.
	 *
	 * @param mixed $expected Expected value.
	 * @param mixed $actual   Actual value.
	 * @return array{0:bool,1:array|null} Match verdict and first mismatch.
	 */
	private function compare_with_mismatch( $expected, $actual ): array {
		$method = new ReflectionMethod( Mcpwp_Elementor_Basic::class, 'elementor_values_match' );
		$method->setAccessible( true );
		$mismatch = null;
		$args     = array( $expected, $actual, 'elements', &$mismatch );
		$matches  = $method->invokeArgs( new Mcpwp_Elementor_Basic(), $args );

		return array( $matches, $mismatch );
	}

	/**
	 * @dataProvider private_diagnostic_key_provider
	 */
	public function test_diagnostic_redacts_credential_and_customer_shaped_keys( string $private_key ): void {
		$expected = array( $private_key => 'before-secret-content' );
		$actual   = array( $private_key => 'after-secret-content' );

		list( $matches, $mismatch ) = $this->compare_with_mismatch( $expected, $actual );
		$encoded = wp_json_encode( $mismatch );

		$this->assertFalse( $matches );
		$this->assertSame( '[redacted-key]', $mismatch['key'] );
		$this->assertSame( '$.[redacted-key]', $mismatch['path'] );
		$this->assertStringNotContainsString( $private_key, $encoded );
		$this->assertStringNotContainsString( 'before-secret-content', $encoded );
		$this->assertStringNotContainsString( 'after-secret-content', $encoded );
	}

	public function private_diagnostic_key_provider(): array {
		return array(
			'customer identifier' => array( 'customer_acme_private_api_token' ),
			'credential shape'    => array( 'sk_live_51NXmZAbCDEFGHIJKLMNOPQRSTUVWXYZ123456' ),
		);
	}

	/**
	 * A settings map is customer-controlled, even when one of its keys happens
	 * to have the same spelling as an Elementor structural field.
	 *
	 * @dataProvider settings_diagnostic_key_provider
	 */
	public function test_diagnostic_redacts_all_settings_map_keys( string $private_key ): void {
		$expected = array(
			array(
				'id'         => 'root0001',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( $private_key => 'before-secret-content' ),
				'elements'   => array(),
			),
		);
		$actual = $expected;
		$actual[0]['settings'][ $private_key ] = 'after-secret-content';

		list( $matches, $mismatch ) = $this->compare_with_mismatch( $expected, $actual );
		$encoded = wp_json_encode( $mismatch );

		$this->assertFalse( $matches );
		$this->assertSame( '[redacted-key]', $mismatch['key'] );
		$this->assertSame( '$[0].settings.[redacted-key]', $mismatch['path'] );
		$this->assertStringNotContainsString( $private_key, $encoded );
		$this->assertStringNotContainsString( 'before-secret-content', $encoded );
		$this->assertStringNotContainsString( 'after-secret-content', $encoded );
	}

	public function settings_diagnostic_key_provider(): array {
		return array(
			'framework id collision'         => array( 'id' ),
			'framework widget type collision' => array( 'widgetType' ),
			'customer identifier'             => array( 'customer_acme_private_api_token' ),
			'credential shape'                => array( 'sk_live_51NXmZAbCDEFGHIJKLMNOPQRSTUVWXYZ123456' ),
		);
	}

	public function test_diagnostic_preserves_numeric_settings_list_indices_without_values(): void {
		$expected = array(
			array(
				'id'       => 'root0001',
				'elType'   => 'widget',
				'settings' => array( 'before-secret-content' ),
				'elements' => array(),
			),
		);
		$actual = $expected;
		$actual[0]['settings'][0] = 'after-secret-content';

		list( $matches, $mismatch ) = $this->compare_with_mismatch( $expected, $actual );
		$encoded = wp_json_encode( $mismatch );

		$this->assertFalse( $matches );
		$this->assertSame( '0', $mismatch['key'] );
		$this->assertSame( '$[0].settings[0]', $mismatch['path'] );
		$this->assertStringNotContainsString( 'before-secret-content', $encoded );
		$this->assertStringNotContainsString( 'after-secret-content', $encoded );
	}

	public function test_diagnostic_does_not_emit_object_class_names_from_settings_values(): void {
		$expected = array(
			array(
				'id'       => 'root0001',
				'elType'   => 'widget',
				'settings' => array( 'id' => new stdClass() ),
				'elements' => array(),
			),
		);
		$actual = $expected;
		$actual[0]['settings']['id'] = new stdClass();

		list( $matches, $mismatch ) = $this->compare_with_mismatch( $expected, $actual );
		$encoded = wp_json_encode( $mismatch );

		$this->assertFalse( $matches );
		$this->assertSame( '[redacted-key]', $mismatch['key'] );
		$this->assertSame( '$[0].settings.[redacted-key]', $mismatch['path'] );
		$this->assertSame( 'object', $mismatch['expected_value_class'] );
		$this->assertSame( 'object', $mismatch['actual_value_class'] );
		$this->assertStringNotContainsString( 'stdClass', $encoded );
	}

	public function test_array_size_mismatch_reports_only_safe_lengths(): void {
		$expected = array(
			array(
				'id'       => 'root0001',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(),
			),
		);

		list( $matches, $mismatch ) = $this->compare_with_mismatch( $expected, array() );

		$this->assertFalse( $matches );
		$this->assertSame( 'array_size_mismatch', $mismatch['difference'] );
		$this->assertSame( 1, $mismatch['expected_length'] );
		$this->assertSame( 0, $mismatch['actual_length'] );
		$this->assertSame( 'list', $mismatch['expected_value_class'] );
		$this->assertSame( 'list', $mismatch['actual_value_class'] );
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

	private function part( string $root_id, bool $include_default_fields ): array {
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
}
