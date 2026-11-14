<?php
/**
 * #989: a write's render_verified is a DOM assertion. After a clean save
 * pipeline the writer renders the page and checks the HTML against the
 * stored tree; a placeholder, an element that rendered nothing, or a failed
 * render makes render_verified false with the reason in render_errors.
 *
 * Each test runs in a separate process because the fixture declares
 * \Elementor\Plugin (#757).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class RenderVerifiedDomCheckTest extends TestCase {

	private const PAGE_ID = 6262;

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts']    = array();
		$GLOBALS['_mcpwp_test_meta']     = array();
		$GLOBALS['mcpwp_test_options']   = array();
		$GLOBALS['mcpwp_test_dom_seen']  = array();
		$GLOBALS['mcpwp_test_dom_html']  = '';
		$GLOBALS['mcpwp_test_dom_throw'] = false;
	}

	protected function tearDown(): void {
		$path = sys_get_temp_dir() . '/mcpwp-test-uploads/elementor/css/post-' . self::PAGE_ID . '.css';
		if ( file_exists( $path ) ) {
			unlink( $path );
		}
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	public static function page_id(): int {
		return self::PAGE_ID;
	}

	private function install( array $post = array() ): Mcpwp_Elementor_Basic {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		require_once __DIR__ . '/fixtures/elementor-dom-check-doubles.php';

		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$GLOBALS['wpdb'] = new class() extends Mcpwp_Test_WPDB {
			public function get_var( $query = '', $x = 0, $y = 0 ) {
				if ( false !== strpos( (string) $query, '_elementor_data' ) ) {
					return $GLOBALS['_mcpwp_test_meta'][ RenderVerifiedDomCheckTest::page_id() ]['_elementor_data'] ?? null;
				}
				return null;
			}
		};

		$GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ] = new WP_Post(
			array_merge(
				array(
					'ID'          => self::PAGE_ID,
					'post_type'   => 'page',
					'post_title'  => 'DOM check fixture',
					'post_status' => 'publish',
				),
				$post
			)
		);

		return new Mcpwp_Elementor_Basic();
	}

	private function tree( array $heading_settings ): array {
		return array(
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
								'id'         => 'hed0001',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => $heading_settings,
							),
						),
					),
				),
			),
		);
	}

	private function rendered( string $heading_text, bool $with_heading = true ): string {
		return '<div class="elementor" data-elementor-type="wp-page" data-elementor-id="' . self::PAGE_ID . '">'
			. '<section data-id="sec0001"><div data-id="col0001">'
			. ( $with_heading ? '<div class="elementor-widget-heading" data-id="hed0001"><h2>' . $heading_text . '</h2></div>' : '' )
			. '</div></section></div>';
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_matching_render_is_verified(): void {
		$basic                          = $this->install();
		$GLOBALS['mcpwp_test_dom_html'] = $this->rendered( 'Welcome' );

		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->tree( array( 'title' => 'Welcome' ) ) ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $result['render_verified'], wp_json_encode( $result['render_errors'] ?? array() ) );
		$this->assertSame( 'passed', $result['dom_check']['status'] );
		$this->assertSame( 3, $result['dom_check']['elements_rendered'] );

		// Rendered once, as the front end renders the body: the page is the
		// current post, so dynamic tags resolve to it, and no CSS is inlined.
		$this->assertSame( array( array( self::PAGE_ID, false, self::PAGE_ID ) ), $GLOBALS['mcpwp_test_dom_seen'] );
		$this->assertArrayNotHasKey( 'post', $GLOBALS, 'The global post must be restored after the check.' );
	}

	/**
	 * The #804 acceptance criterion: render_verified is false when a
	 * placeholder string is present.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_placeholder_on_the_rendered_page_fails_render_verified(): void {
		$basic                          = $this->install();
		$GLOBALS['mcpwp_test_dom_html'] = $this->rendered( 'Add Your Heading Text Here' );

		// Wrong control key: the heading's title is never set.
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->tree( array( 'text' => 'Welcome' ) ) ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $result['success'], 'The data was written; the caller must not be told to retry the write.' );
		$this->assertFalse( $result['render_verified'] );
		$this->assertSame( 'failed', $result['dom_check']['status'] );
		$this->assertStringContainsString( 'Add Your Heading Text Here', implode( ' ', $result['render_errors'] ) );
		$this->assertStringContainsString( 'does not match', $result['message'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_element_missing_from_the_render_fails_render_verified(): void {
		$basic                          = $this->install();
		$GLOBALS['mcpwp_test_dom_html'] = $this->rendered( '', false );

		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->tree( array( 'title' => 'Welcome' ) ) ) ) );

		$this->assertFalse( $result['render_verified'] );
		$this->assertSame( array( 'hed0001' ), $result['dom_check']['missing_element_ids'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_render_that_throws_fails_render_verified_without_failing_the_write(): void {
		$basic                           = $this->install();
		$GLOBALS['mcpwp_test_dom_throw'] = true;

		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->tree( array( 'title' => 'Welcome' ) ) ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['render_verified'] );
		$this->assertSame( 'render_failed', $result['dom_check']['reason'] );
		$this->assertStringContainsString( 'render exploded', implode( ' ', $result['render_errors'] ) );
		$this->assertArrayNotHasKey( 'post', $GLOBALS, 'The global post must be restored even when the render throws.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_check_can_be_turned_off_and_says_so(): void {
		$basic                          = $this->install();
		$GLOBALS['mcpwp_test_dom_html'] = $this->rendered( 'Add Your Heading Text Here' );
		add_filter(
			'mcpwp_verify_rendered_dom',
			static function () {
				return false;
			}
		);

		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->tree( array( 'text' => 'Welcome' ) ) ) ) );

		$this->assertTrue( $result['render_verified'] );
		$this->assertSame( 'skipped', $result['dom_check']['status'] );
		$this->assertSame( 'disabled', $result['dom_check']['reason'] );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dom_seen'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_password_protected_page_is_skipped_not_failed(): void {
		$basic = $this->install( array( 'post_password' => 'secret' ) );

		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->tree( array( 'title' => 'Welcome' ) ) ) ) );

		$this->assertTrue( $result['render_verified'] );
		$this->assertSame( 'password_protected', $result['dom_check']['reason'] );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dom_seen'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_theme_builder_template_is_skipped(): void {
		$basic = $this->install( array( 'post_type' => 'elementor_library' ) );
		update_post_meta( self::PAGE_ID, '_elementor_template_type', 'header' );

		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->tree( array( 'title' => 'Welcome' ) ) ) ) );

		$this->assertSame( 'template_document', $result['dom_check']['reason'] );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dom_seen'] );
	}

	/**
	 * Surgical writes report through the same decorate_render_status().
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_edit_widget_is_checked_too(): void {
		$basic = $this->install();
		update_post_meta( self::PAGE_ID, '_elementor_data', wp_json_encode( $this->tree( array( 'title' => 'Old' ) ) ) );
		update_post_meta( self::PAGE_ID, '_elementor_edit_mode', 'builder' );
		$GLOBALS['mcpwp_test_dom_html'] = $this->rendered( 'Old' );

		$result = $basic->edit_widget( self::PAGE_ID, 'hed0001', array( 'title' => 'New' ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertFalse( $result['render_verified'], 'The render still shows the old title.' );
		$this->assertSame( 'hed0001', $result['dom_check']['text_mismatches'][0]['element_id'] );
	}
}
