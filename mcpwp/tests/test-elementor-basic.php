<?php
/**
 * Tests for Mcpwp_Elementor_Basic.
 *
 * Runs entirely in separate processes. This is the only file that defines
 * ELEMENTOR_VERSION in what would otherwise be the shared run, and a constant
 * cannot be undefined again — so once it ran, every later test in the process
 * saw an "Elementor is installed" world. That silently changed at least three
 * unrelated files: RestElementorDraftLeakTest's absence codes, Mcpwp_Signals'
 * compute_broken_elementor() (which stops short-circuiting and issues a real
 * query), and RestExhaustiveDraftLeakTest's signal set. The suite passed in
 * declaration order and failed under --order-by=random (#757).
 *
 * Every other file that needs the constant already confines it to a
 * separate-process test, so isolating this one keeps ELEMENTOR_VERSION out of
 * the shared process entirely rather than making its absence a matter of luck.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 *
 * @package SitePilotAI\Tests
 */

class Test_Elementor_Basic extends PHPUnit\Framework\TestCase {

	/** @var Mcpwp_Elementor_Basic */
	private $elementor;

	protected function setUp(): void {
		$this->elementor = new Mcpwp_Elementor_Basic();

		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta']  = array();
	}

	// ── validate_post ──────────────────────────────────────────

	public function test_validate_post_returns_error_for_missing_post() {
		$result = $this->elementor->validate_post( 999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_validate_post_returns_error_for_unsupported_type() {
		$post = (object) array( 'ID' => 10, 'post_type' => 'attachment', 'post_title' => 'Image' );
		$GLOBALS['_mcpwp_test_posts'][10] = $post;

		$result = $this->elementor->validate_post( 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	/**
	 * @dataProvider allowed_post_types_provider
	 */
	public function test_validate_post_accepts_allowed_types( $post_type ) {
		$post = (object) array( 'ID' => 1, 'post_type' => $post_type, 'post_title' => 'Test' );
		$GLOBALS['_mcpwp_test_posts'][1] = $post;

		$result = $this->elementor->validate_post( 1 );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( $post_type, $result->post_type );
	}

	public static function allowed_post_types_provider() {
		return array(
			'page'              => array( 'page' ),
			'post'              => array( 'post' ),
			'elementor_library' => array( 'elementor_library' ),
		);
	}

	// ── get_elementor_data ─────────────────────────────────────

	/**
	 * The only test in this file that needs ELEMENTOR_VERSION to be UNDEFINED,
	 * while every other test here defines it. In a shared process that made it
	 * pass only while it happened to be ordered first. The class-level process
	 * isolation is what lets it state its precondition instead of inheriting
	 * one, so the assertion below is the guard, not a comment.
	 */
	public function test_get_elementor_data_returns_error_when_not_active() {
		$this->assertFalse(
			defined( 'ELEMENTOR_VERSION' ),
			'This test is about the absent-Elementor path; if the constant is set the process is not clean.'
		);

		$result = $this->elementor->get_elementor_data( 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'elementor_not_active', $result->get_error_code() );
	}

	public function test_get_elementor_data_returns_error_for_invalid_post() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.20.0' );
		}

		$result = $this->elementor->get_elementor_data( 999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_get_elementor_data_accepts_elementor_library() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.20.0' );
		}

		$post = (object) array( 'ID' => 127, 'post_type' => 'elementor_library', 'post_title' => 'Header' );
		$GLOBALS['_mcpwp_test_posts'][127] = $post;
		$GLOBALS['_mcpwp_test_meta'][127]  = array(
			'_elementor_data'          => '[{"id":"abc"}]',
			'_elementor_edit_mode'     => 'builder',
			'_elementor_template_type' => 'header',
		);

		$result = $this->elementor->get_elementor_data( 127 );

		$this->assertIsArray( $result );
		$this->assertSame( 127, $result['page_id'] );
		$this->assertSame( 'Header', $result['title'] );
		$this->assertTrue( $result['has_elementor'] );
		$this->assertSame( 'header', $result['template_type'] );
	}

	// ── set_elementor_data ─────────────────────────────────────

	public function test_set_elementor_data_rejects_invalid_json() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.20.0' );
		}

		$post = (object) array( 'ID' => 5, 'post_type' => 'page', 'post_title' => 'Test Page' );
		$GLOBALS['_mcpwp_test_posts'][5] = $post;

		$result = $this->elementor->set_elementor_data( 5, array(
			'elementor_data' => '{invalid json',
		) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_json', $result->get_error_code() );
	}

	public function test_set_elementor_data_rejects_empty_data() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.20.0' );
		}

		$post = (object) array( 'ID' => 5, 'post_type' => 'page', 'post_title' => 'Test Page' );
		$GLOBALS['_mcpwp_test_posts'][5] = $post;

		$result = $this->elementor->set_elementor_data( 5, array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_data', $result->get_error_code() );
	}

	// ── #678 — section css_id never reaches the front end ──────────────────
	//
	// Elementor reads the wrapper id from `_element_id` in Element_Base's
	// shared render path (element-base.php: `if ( ! empty( $settings
	// ['_element_id'] ) ) { add_render_attribute( '_wrapper', 'id', ... ) }` ),
	// so `_element_id` is the ID control for sections, columns, containers and
	// widgets alike. `css_id` is not an Elementor control on any of them.
	//
	// Setting it therefore persisted and round-tripped perfectly through
	// wp_set_elementor / wp_get_elementor while the rendered section carried no
	// id at all — reported on DME sections a26captr and 7b550aa4, where the
	// existing hash links had no target.
	//
	// Note the asymmetry that made this easy to get wrong: the CLASSES control
	// really is `css_classes` on section/column/container (and `_css_classes`
	// on widgets), so `css_classes` works and `css_id` looks like it should.

	private function setElementorData( array $elements ): array {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.20.0' );
		}

		$GLOBALS['_mcpwp_test_posts'][5] = (object) array( 'ID' => 5, 'post_type' => 'page', 'post_title' => 'Test Page' );

		$this->elementor->set_elementor_data( 5, array( 'elementor_data' => wp_json_encode( $elements ) ) );

		return json_decode( $GLOBALS['_mcpwp_test_meta'][5]['_elementor_data'], true );
	}

	public function test_section_css_id_is_renamed_to_element_id() {
		$saved = $this->setElementorData(
			array(
				array(
					'id'       => 'a26captr',
					'elType'   => 'section',
					'settings' => array( 'css_id' => 'request-your-snapshot' ),
					'elements' => array(),
				),
			)
		);

		$this->assertSame( 'request-your-snapshot', $saved[0]['settings']['_element_id'], 'the ID must land on the key Elementor renders from' );
		$this->assertArrayNotHasKey( 'css_id', $saved[0]['settings'], 'the inert key must not be left behind' );
	}

	public function test_css_id_is_renamed_on_columns_containers_and_widgets_too() {
		$saved = $this->setElementorData(
			array(
				array(
					'id'       => 'sec1',
					'elType'   => 'section',
					'settings' => array(),
					'elements' => array(
						array(
							'id'       => 'col1',
							'elType'   => 'column',
							'settings' => array( '_column_size' => 100, 'css_id' => 'col-anchor' ),
							'elements' => array(
								array(
									'id'         => 'w1',
									'elType'     => 'widget',
									'widgetType' => 'heading',
									'settings'   => array( 'title' => 'Hi', 'css_id' => 'widget-anchor' ),
									'elements'   => array(),
								),
							),
						),
					),
				),
			)
		);

		$column = $saved[0]['elements'][0];
		$widget = $column['elements'][0];

		$this->assertSame( 'col-anchor', $column['settings']['_element_id'] );
		$this->assertSame( 'widget-anchor', $widget['settings']['_element_id'] );
	}

	public function test_existing_element_id_is_not_overwritten_by_css_id() {
		// Never clobber a value that already works — same rule the widget
		// control_renames pass follows.
		$saved = $this->setElementorData(
			array(
				array(
					'id'       => 'sec1',
					'elType'   => 'section',
					'settings' => array( 'css_id' => 'stale', '_element_id' => 'real-anchor' ),
					'elements' => array(),
				),
			)
		);

		$this->assertSame( 'real-anchor', $saved[0]['settings']['_element_id'] );
	}

	public function test_css_classes_is_left_alone_on_a_section() {
		// The guard against over-correcting by symmetry: css_classes IS the
		// real control on section/column/container. Renaming it would break
		// every section that currently styles correctly.
		$saved = $this->setElementorData(
			array(
				array(
					'id'       => 'sec1',
					'elType'   => 'section',
					'settings' => array( 'css_classes' => 'dme-official-system' ),
					'elements' => array(),
				),
			)
		);

		$this->assertSame( 'dme-official-system', $saved[0]['settings']['css_classes'] );
		$this->assertArrayNotHasKey( '_css_classes', $saved[0]['settings'] );
	}
}
