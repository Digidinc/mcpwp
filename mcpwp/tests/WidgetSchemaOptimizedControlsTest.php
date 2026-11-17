<?php
/**
 * Elementor optimized control stacks vs wp_get_widget_schema (#1236).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/**
 * Widget schema optimized-controls detection tests.
 */
final class WidgetSchemaOptimizedControlsTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_widget_schema_flags_optimized_controls_and_merges_style_stack(): void {
		$this->assertFalse( class_exists( '\Elementor\Plugin', false ) );
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.4.1' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-optimized-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$elementor = new Mcpwp_Elementor_Basic();
		$data      = $elementor->get_widget_schema(
			'mcpwp-optimized-widget',
			array()
		);

		$this->assertIsArray( $data );
		$this->assertTrue( $data['controls_optimized'] );
		$this->assertNotEmpty( $data['warning'] );
		$this->assertArrayHasKey( 'content', $data['tabs'] );
		$names = array_column( $data['tabs']['content'], 'name' );
		$this->assertContains( 'image_size', $names );
		$this->assertContains( 'width', $names, 'set_use_style_controls(true) should merge style_controls into the schema read.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_widget_schema_tab_filter_warns_when_tabs_unreliable(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.4.1' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-optimized-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$elementor = new Mcpwp_Elementor_Basic();
		$data      = $elementor->get_widget_schema(
			'mcpwp-optimized-widget',
			array( 'tab' => 'style' )
		);

		$this->assertTrue( $data['controls_optimized'] );
		$this->assertArrayHasKey( 'tab_filter_warning', $data );
		$this->assertSame( array(), $data['tabs'], 'Style tab filter must not silently succeed with an empty list.' );
	}

	/**
	 * fields=[tabs] must not strip controls_optimized metadata (#1236 P1-1).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_widget_schema_fields_tabs_keeps_optimized_flags_with_tab_filter(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.4.1' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-optimized-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$elementor = new Mcpwp_Elementor_Basic();
		$data      = $elementor->get_widget_schema(
			'mcpwp-optimized-widget',
			array(
				'tab'    => 'style',
				'fields' => array( 'tabs' ),
			)
		);

		$this->assertArrayHasKey( 'tabs', $data );
		$this->assertTrue( $data['controls_optimized'] );
		$this->assertNotEmpty( $data['warning'] );
		$this->assertArrayHasKey( 'tab_filter_warning', $data );
		$this->assertSame( array(), $data['tabs'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_widget_schema_fields_tabs_keeps_optimized_flags_without_tab_filter(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.4.1' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-optimized-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$elementor = new Mcpwp_Elementor_Basic();
		$data      = $elementor->get_widget_schema(
			'mcpwp-optimized-widget',
			array( 'fields' => array( 'tabs' ) )
		);

		$this->assertArrayHasKey( 'tabs', $data );
		$this->assertTrue( $data['controls_optimized'] );
		$this->assertNotEmpty( $data['warning'] );
		$this->assertArrayNotHasKey( 'tab_filter_warning', $data );
		$this->assertArrayHasKey( 'content', $data['tabs'] );
		$this->assertGreaterThanOrEqual( 2, count( $data['tabs']['content'] ) );
	}

	/**
	 * Widgets with empty style_controls still flag when Performance says optimized (#1236 P1-2).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_widget_schema_detects_optimized_when_style_controls_empty(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.4.1' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-optimized-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$elementor = new Mcpwp_Elementor_Basic();
		$data      = $elementor->get_widget_schema( 'mcpwp-html-like-widget', array() );

		$this->assertTrue( $data['controls_optimized'] );
		$this->assertNotEmpty( $data['warning'] );
		$this->assertArrayHasKey( 'content', $data['tabs'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_widget_schema_html_like_tab_advanced_warns_under_optimization(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.4.1' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-optimized-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$elementor = new Mcpwp_Elementor_Basic();
		$data      = $elementor->get_widget_schema(
			'mcpwp-html-like-widget',
			array( 'tab' => 'advanced' )
		);

		$this->assertTrue( $data['controls_optimized'] );
		$this->assertArrayHasKey( 'tab_filter_warning', $data );
		$this->assertSame( array(), $data['tabs'] );
	}

	/**
	 * set_use_style_controls must restore after each schema read (#1236 P2-1).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_widget_schema_restores_use_style_controls_flag(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.4.1' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-optimized-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->get_widget_schema( 'mcpwp-optimized-widget', array() );

		$this->assertFalse(
			\Elementor\Core\Frontend\Performance::is_use_style_controls(),
			'Performance::set_use_style_controls(true) must not leak past the schema read.'
		);
	}

	/**
	 * Optimized warning must not promise a clean re-read on poisoned requests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_widget_schema_optimized_warning_omits_clean_request_hint(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.4.1' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-optimized-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$elementor = new Mcpwp_Elementor_Basic();
		$data      = $elementor->get_widget_schema( 'mcpwp-optimized-widget', array() );

		$this->assertStringNotContainsString( 'clean request', strtolower( $data['warning'] ) );
	}

	/**
	 * Clean stacks (no style_controls split) stay unchanged — no warning flag.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_widget_schema_clean_controls_have_no_optimized_warning(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.4.1' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$elementor = new Mcpwp_Elementor_Basic();
		$data      = $elementor->get_widget_schema( 'heading', array() );

		$this->assertArrayNotHasKey( 'controls_optimized', $data );
		$this->assertArrayNotHasKey( 'warning', $data );
		$this->assertSame( array( 'content', 'style' ), array_keys( $data['tabs'] ) );
		$this->assertFalse( \Elementor\Core\Frontend\Performance::should_optimize_controls() );
	}
}