<?php
/**
 * Regression coverage for #830: several of the biggest read tools
 * (wp_onboard, wp_introspect, wp_get_elementor, wp_get_elementor_widgets,
 * wp_get_widget_schema) returned 40-150KB with no way to ask for less —
 * every KB is context an agent pays for and must reason over, and every
 * non-Claude MCP client (no Programmatic Tool Calling) eats the whole
 * payload regardless.
 *
 * One convention, applied wherever it fits the tool's shape:
 *   - a top-level `fields`/`sections` allowlist narrows the SHAPE of a
 *     response (which keys come back);
 *   - a natural selector (`tool`, `element_id`, `search`, `category`, `tab`)
 *     narrows the POPULATION (which items/subtree come back) — `fields`
 *     alone can't shrink a list or a tree, only trim each item's keys.
 *
 * What every test here pins down twice:
 *   1. passing the projection parameter returns the requested subset;
 *   2. omitting it returns the exact legacy payload, byte-for-byte — this
 *      is opt-in narrowing, never a silent default change.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-media.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-design-references.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-info.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';

final class ResponseProjectionTest extends TestCase {

	private const PAGE_ID = 9130;

	/** @var array */
	private $meta_before = array();

	/** @var array */
	private $posts_before = array();

	protected function setUp(): void {
		$this->meta_before  = $GLOBALS['_mcpwp_test_meta'] ?? array();
		$this->posts_before = $GLOBALS['_mcpwp_test_posts'] ?? array();
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_meta']  = $this->meta_before;
		$GLOBALS['_mcpwp_test_posts'] = $this->posts_before;
	}

	/**
	 * get_onboard()/get_introspect() read site info through Mcpwp_Core. The
	 * shared bootstrap has no wp_get_theme(), wp_timezone_string() or
	 * get_locale(); declared here, inside separate-process tests only, so
	 * they cannot leak into the shared run (same approach as
	 * OnboardingFigmaTierTest::stubThemeLookup()).
	 */
	private function stubThemeLookup(): void {
		if ( ! function_exists( 'wp_timezone_string' ) ) {
			function wp_timezone_string() {
				return 'UTC';
			}
		}
		if ( ! function_exists( 'get_locale' ) ) {
			function get_locale() {
				return 'en_US';
			}
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			function is_plugin_active( $plugin ) {
				return false;
			}
		}
		if ( ! function_exists( 'wp_get_theme' ) ) {
			function wp_get_theme() {
				return new class() {
					public function get( $header ) {
						return 'Stub Theme';
					}
				};
			}
		}
		if ( ! function_exists( 'wp_count_attachments' ) ) {
			function wp_count_attachments() {
				return (object) array( 'image/jpeg' => 0 );
			}
		}
		if ( ! function_exists( 'wp_count_terms' ) ) {
			function wp_count_terms( $taxonomy ) {
				return 0;
			}
		}
	}

	// ---------------------------------------------------------------
	// wp_onboard — `sections` allowlist.
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_onboard_sections_returns_only_the_requested_keys(): void {
		$this->stubThemeLookup();
		$controller = new Mcpwp_REST_Site_Info();

		$scoped = $controller->get_onboard( new WP_REST_Request( 'GET', '/mcpwp/v1/onboard', array( 'sections' => array( 'site_identity', 'quick_start' ) ) ) );
		$data   = $scoped->get_data();

		$this->assertSame( array( 'site_identity', 'quick_start' ), array_keys( $data ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_onboard_with_no_sections_param_is_byte_for_byte_the_legacy_payload(): void {
		$this->stubThemeLookup();
		$controller = new Mcpwp_REST_Site_Info();

		$a = $controller->get_onboard( new WP_REST_Request( 'GET', '/mcpwp/v1/onboard' ) )->get_data();
		$b = $controller->get_onboard( new WP_REST_Request( 'GET', '/mcpwp/v1/onboard', array( 'sections' => array() ) ) )->get_data();

		$expected_keys = array(
			'site_identity',
			'content_inventory',
			'active_integrations',
			'available_tools',
			'site_context',
			'recommended_actions',
			'quick_reference',
			'suggested_first_tools',
			'quick_start',
			'pro_active',
		);

		$this->assertSame( $expected_keys, array_keys( $a ), 'Omitting sections must return every section, in the existing order.' );
		$this->assertSame(
			wp_json_encode( $a ),
			wp_json_encode( $b ),
			'An empty sections array is not a narrowing request — it must answer exactly like no sections param at all.'
		);
	}

	// ---------------------------------------------------------------
	// wp_introspect — `tool` selector.
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_introspect_tool_param_returns_that_tools_full_schema(): void {
		$this->stubThemeLookup();
		$controller = new Mcpwp_REST_Site_Info();

		$resp = $controller->get_introspect( new WP_REST_Request( 'GET', '/mcpwp/v1/introspect', array( 'tool' => 'wp_onboard' ) ) );
		$data = $resp->get_data();

		$this->assertArrayHasKey( 'tool', $data );
		$this->assertSame( 'wp_onboard', $data['tool']['name'] );
		$this->assertArrayHasKey( 'inputSchema', $data['tool'], 'The whole point of #830\'s ?tool= ask was the schema, not just the name.' );
		$this->assertArrayNotHasKey( 'tools', $data, 'Asking for one tool must not also ship the full/compact tool list.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_introspect_unknown_tool_is_a_404_not_a_silent_empty_result(): void {
		$this->stubThemeLookup();
		$controller = new Mcpwp_REST_Site_Info();

		$resp = $controller->get_introspect( new WP_REST_Request( 'GET', '/mcpwp/v1/introspect', array( 'tool' => 'wp_does_not_exist' ) ) );

		$this->assertInstanceOf( WP_Error::class, $resp );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_introspect_with_no_tool_param_is_byte_for_byte_the_legacy_compact_payload(): void {
		$this->stubThemeLookup();
		$controller = new Mcpwp_REST_Site_Info();

		$a = $controller->get_introspect( new WP_REST_Request( 'GET', '/mcpwp/v1/introspect' ) )->get_data();
		$b = $controller->get_introspect( new WP_REST_Request( 'GET', '/mcpwp/v1/introspect', array( 'tool' => '' ) ) )->get_data();

		$this->assertArrayHasKey( 'tools', $a );
		$this->assertArrayHasKey( 'name', $a['tools'][0], 'Compact mode is unchanged: name + description, no schema.' );
		$this->assertArrayNotHasKey( 'inputSchema', $a['tools'][0] );
		$this->assertSame( wp_json_encode( $a ), wp_json_encode( $b ) );
	}

	// ---------------------------------------------------------------
	// wp_get_elementor — `element_id` scope.
	// ---------------------------------------------------------------

	private function seed_elementor_page(): void {
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PAGE_ID => new WP_Post(
				array(
					'ID'          => self::PAGE_ID,
					'post_type'   => 'page',
					'post_title'  => 'Projection probe',
					'post_name'   => 'projection-probe',
					'post_status' => 'publish',
				)
			),
		);
		$GLOBALS['_mcpwp_test_meta'] = array(
			self::PAGE_ID => array(
				'_elementor_data' => wp_json_encode(
					array(
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
											'settings'   => array( 'title' => 'Target' ),
										),
									),
								),
							),
						),
						array(
							'id'       => 'sec0002',
							'elType'   => 'section',
							'settings' => array(),
							'elements' => array(),
						),
					)
				),
			),
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_elementor_element_id_returns_only_that_elements_subtree(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		$this->seed_elementor_page();

		$controller = new Mcpwp_REST_Elementor();
		$resp       = $controller->get_elementor_data( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/' . self::PAGE_ID, array( 'id' => self::PAGE_ID, 'element_id' => 'wid0001' ) ) );
		$data       = $resp->get_data();

		$this->assertSame( 'wid0001', $data['elementor_data']['id'] );
		$this->assertSame( 'heading', $data['elementor_data']['widgetType'] );
		$this->assertSame( 'wid0001', $data['element_id'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_elementor_unknown_element_id_is_a_404(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		$this->seed_elementor_page();

		$controller = new Mcpwp_REST_Elementor();
		$resp       = $controller->get_elementor_data( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/' . self::PAGE_ID, array( 'id' => self::PAGE_ID, 'element_id' => 'nope0001' ) ) );

		$this->assertInstanceOf( WP_Error::class, $resp );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_elementor_with_no_element_id_is_byte_for_byte_the_full_page(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		$this->seed_elementor_page();

		$controller = new Mcpwp_REST_Elementor();
		$a = $controller->get_elementor_data( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/' . self::PAGE_ID, array( 'id' => self::PAGE_ID ) ) )->get_data();
		$b = $controller->get_elementor_data( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/' . self::PAGE_ID, array( 'id' => self::PAGE_ID, 'element_id' => '' ) ) )->get_data();

		$this->assertCount( 2, $a['elementor_data'], 'Both top-level sections must still be present.' );
		$this->assertArrayNotHasKey( 'element_id', $a );
		$this->assertSame( wp_json_encode( $a ), wp_json_encode( $b ) );
	}

	// ---------------------------------------------------------------
	// wp_get_elementor_widgets — `search` / `category` / `fields`.
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_elementor_widgets_search_and_category_narrow_the_list(): void {
		$this->assertFalse( class_exists( '\Elementor\Plugin', false ) );
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$controller = new Mcpwp_REST_Elementor();

		$by_search = $controller->get_widget_schema( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/widgets', array( 'search' => 'headline' ) ) )->get_data();
		$names     = array_column( $by_search['widgets'], 'name' );
		$this->assertSame( array( 'heading' ), $names, 'heading\'s real reference description contains "headline" — the fixture widget does not match.' );

		$by_category = $controller->get_widget_schema( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/widgets', array( 'category' => 'basic' ) ) )->get_data();
		$cat_names   = array_column( $by_category['widgets'], 'name' );
		$this->assertContains( 'heading', $cat_names );
		$this->assertNotContains( 'mcpwp-fixture-widget', $cat_names, 'The fixture widget has no reference entry, so no category to match.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_elementor_widgets_fields_narrows_each_item(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$controller = new Mcpwp_REST_Elementor();
		$data       = $controller->get_widget_schema( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/widgets', array( 'fields' => array( 'name', 'category' ) ) ) )->get_data();

		foreach ( $data['widgets'] as $entry ) {
			$this->assertSame( array_intersect( array( 'name', 'category' ), array_keys( $entry ) ), array_keys( $entry ) );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_elementor_widgets_with_no_projection_params_is_byte_for_byte_the_legacy_list(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$controller = new Mcpwp_REST_Elementor();
		$a = $controller->get_widget_schema( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/widgets' ) )->get_data();
		$b = $controller->get_widget_schema( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/widgets', array( 'search' => '', 'category' => '', 'fields' => array() ) ) )->get_data();

		$this->assertSame( 2, $a['count'] );
		$this->assertSame( wp_json_encode( $a ), wp_json_encode( $b ) );
	}

	// ---------------------------------------------------------------
	// wp_get_widget_schema — `tab` / `fields`.
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_widget_schema_tab_narrows_controls_to_one_tab(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$controller = new Mcpwp_REST_Elementor();
		$data       = $controller->get_widget_schema( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/widgets/heading', array( 'type' => 'heading', 'tab' => 'style' ) ) )->get_data();

		$this->assertSame( array( 'style' ), array_keys( $data['tabs'] ) );
		$this->assertSame( 'title_color', $data['tabs']['style'][0]['name'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_widget_schema_fields_narrows_the_result(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$controller = new Mcpwp_REST_Elementor();
		$data       = $controller->get_widget_schema( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/widgets/heading', array( 'type' => 'heading', 'fields' => array( 'description', 'example', 'common_mistakes' ) ) ) )->get_data();

		$this->assertSame( array( 'description', 'example', 'common_mistakes' ), array_keys( $data ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_widget_schema_with_no_projection_params_is_byte_for_byte_the_legacy_result(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$controller = new Mcpwp_REST_Elementor();
		$a = $controller->get_widget_schema( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/widgets/heading', array( 'type' => 'heading' ) ) )->get_data();
		$b = $controller->get_widget_schema( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/widgets/heading', array( 'type' => 'heading', 'tab' => '', 'fields' => array() ) ) )->get_data();

		$this->assertSame( array( 'content', 'style' ), array_keys( $a['tabs'] ), 'Unfiltered result carries every tab.' );
		$this->assertSame( wp_json_encode( $a ), wp_json_encode( $b ) );
	}
}
