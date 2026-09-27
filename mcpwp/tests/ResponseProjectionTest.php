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
 * wp_onboard is the one exception, and it is deliberate (#905): the tool
 * catalog dump this file's own comment above calls out (`available_tools`
 * alone is ~95% of the payload) made first contact cost ~18k tokens before
 * an agent wrote anything. #830's opt-in `sections` allowlist fixed that for
 * callers who knew to ask; #905 flips the wp_onboard *default* to the slim
 * shape instead, so every caller gets it without asking. `sections` (and the
 * new `detail=full` escape hatch) still reach the exact legacy payload,
 * byte-for-byte — nothing is unreachable, just no longer the default.
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

		// A non-empty `sections` allowlist implies the full legacy briefing
		// (#905) — it still selects from that shape, not the slim default.
		$scoped = $controller->get_onboard( new WP_REST_Request( 'GET', '/mcpwp/v1/onboard', array( 'sections' => array( 'site_identity', 'quick_start' ) ) ) );
		$data   = $scoped->get_data();

		$this->assertSame( array( 'site_identity', 'quick_start' ), array_keys( $data ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_onboard_with_no_params_is_the_slim_default(): void {
		// #905: wp_onboard is the one tool in this file where omitting the
		// projection parameter is *not* the legacy payload — the slim shape
		// is the default, so every caller gets ≤3k tokens without asking.
		$this->stubThemeLookup();
		$controller = new Mcpwp_REST_Site_Info();

		$a = $controller->get_onboard( new WP_REST_Request( 'GET', '/mcpwp/v1/onboard' ) )->get_data();
		$b = $controller->get_onboard( new WP_REST_Request( 'GET', '/mcpwp/v1/onboard', array( 'sections' => array() ) ) )->get_data();

		$expected_keys = array(
			'site_identity',
			'builder_mode',
			'connected',
			'content_summary',
			'site_context_configured',
			'next_actions',
			'more',
			'pro_active',
			'detail',
		);

		$this->assertSame( $expected_keys, array_keys( $a ), 'Default (no params) must be the slim shape, in this order.' );
		$this->assertSame( 'slim', $a['detail'] );
		$this->assertArrayNotHasKey( 'available_tools', $a, 'The tool catalog dump must not appear in the slim default.' );
		$this->assertSame(
			wp_json_encode( $a ),
			wp_json_encode( $b ),
			'An empty sections array is not a narrowing request — it must answer exactly like no params at all (slim).'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_onboard_detail_full_is_byte_for_byte_the_legacy_payload(): void {
		$this->stubThemeLookup();
		$controller = new Mcpwp_REST_Site_Info();

		$a = $controller->get_onboard( new WP_REST_Request( 'GET', '/mcpwp/v1/onboard', array( 'detail' => 'full' ) ) )->get_data();
		// A non-empty `sections` allowlist covering every legacy key also
		// implies the full briefing and must match it exactly.
		$b = $controller->get_onboard(
			new WP_REST_Request(
				'GET',
				'/mcpwp/v1/onboard',
				array(
					'sections' => array(
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
					),
				)
			)
		)->get_data();

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
			'guardrails_status',
		);

		$this->assertSame( $expected_keys, array_keys( $a ), 'detail=full must return every legacy section, in the existing order.' );
		$this->assertSame(
			wp_json_encode( $a ),
			wp_json_encode( $b ),
			'detail=full and an explicit all-sections allowlist must answer identically.'
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
	 * RC review small finding: `fields`' arg schema declares type=array but
	 * this route has no `sanitize_callback`, so WP never coerces a caller's
	 * raw value — a comma-separated string (the ordinary way to write an
	 * array-typed value in a query string) reached get_widget_schema() as a
	 * literal string and, before this, failed the `is_array($fields)` check
	 * silently, over-returning every field instead of projecting. This pins
	 * the recovered behaviour: a CSV string narrows exactly like the
	 * equivalent array does.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_widget_schema_fields_accepts_comma_separated_string(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$controller = new Mcpwp_REST_Elementor();
		$from_csv   = $controller->get_widget_schema( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/widgets/heading', array( 'type' => 'heading', 'fields' => ' description, example ,common_mistakes' ) ) )->get_data();
		$from_array = $controller->get_widget_schema( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/widgets/heading', array( 'type' => 'heading', 'fields' => array( 'description', 'example', 'common_mistakes' ) ) ) )->get_data();

		$this->assertSame( array( 'description', 'example', 'common_mistakes' ), array_keys( $from_csv ) );
		$this->assertSame( wp_json_encode( $from_array ), wp_json_encode( $from_csv ) );
	}

	/**
	 * An empty string carries no field names — it must behave exactly like
	 * omitting `fields` altogether (the legacy, unprojected result), not
	 * like an error or an empty allowlist that returns nothing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_widget_schema_fields_empty_string_is_same_as_absent(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		require_once __DIR__ . '/fixtures/elementor-widget-schema-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$controller = new Mcpwp_REST_Elementor();
		$a = $controller->get_widget_schema( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/widgets/heading', array( 'type' => 'heading' ) ) )->get_data();
		$b = $controller->get_widget_schema( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/widgets/heading', array( 'type' => 'heading', 'fields' => '' ) ) )->get_data();

		$this->assertSame( wp_json_encode( $a ), wp_json_encode( $b ) );
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
