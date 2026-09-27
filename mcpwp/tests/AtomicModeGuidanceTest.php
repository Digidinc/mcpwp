<?php
/**
 * Regression coverage for the atomic-mode agent guidance fix.
 *
 * Verified on a fresh WordPress 7.0.2 + Elementor 4.3.2 install (both the
 * Container and Atomic Elements experiments default-active): legacy
 * container + classic widget data written via wp_set_elementor renders
 * correctly, but genuine Atomic Elements (e-div-block > e-heading, ...)
 * report `success: true` yet `render_verified: false` — Elementor's own
 * render pipeline drops them after save ("Elementor dropped elements while
 * rendering (1 of 2 survived)"). Before this fix, every agent-facing
 * guidance surface (wp_get_guide('elementor'), the MCP server instructions,
 * wp_onboard) steered agents toward building atomic elements on an
 * atomic-mode site — exactly the path that does not render today.
 *
 * This also pinned a second bug in the guide's own example: the e-heading
 * `title` prop was documented as `{"$$type": "string", ...}`, but
 * wp_get_widget_schema('e-heading') on Elementor 4.3.2 reports its kind as
 * `escaped-html`.
 *
 * Every case here is checked both ways: atomic mode gets the new guidance,
 * and section/container mode is unaffected (no atomic-only text leaks in
 * when atomic is not the active mode).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-guides.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-media.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-design-references.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-info.php';

final class AtomicModeGuidanceTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_active_experiments'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_active_experiments'] );
	}

	/**
	 * wp_onboard()/wp_introspect() read site info through Mcpwp_Core, which
	 * calls into functions the shared bootstrap does not stub. Declared here,
	 * inside separate-process tests only, same approach as
	 * ResponseProjectionTest::stubThemeLookup().
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

	private function activateAtomicMode(): void {
		require_once __DIR__ . '/fixtures/elementor-experiments-doubles.php';
		define( 'ELEMENTOR_VERSION', '4.3.2' );
		\Elementor\Plugin::$instance = new \Elementor\Plugin();
		// Matches the real rig: Atomic Elements does not go active alone —
		// Elementor turns the Container experiment on with it.
		$GLOBALS['mcpwp_test_active_experiments'] = array( 'container', 'e_atomic_elements' );
	}

	private function activateContainerModeOnly(): void {
		require_once __DIR__ . '/fixtures/elementor-experiments-doubles.php';
		define( 'ELEMENTOR_VERSION', '4.3.2' );
		\Elementor\Plugin::$instance               = new \Elementor\Plugin();
		$GLOBALS['mcpwp_test_active_experiments']  = array( 'container' );
	}

	// ---------------------------------------------------------------
	// wp_get_guide('elementor')
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_guide_recommends_containers_in_atomic_mode(): void {
		$this->activateAtomicMode();

		$guide = Mcpwp_Guides::guide_elementor();

		$this->assertSame( 'atomic', $guide['layout_mode'] );

		$layout_section = $this->findSection( $guide, 'Layout Modes' );
		$this->assertStringContainsString( 'container + classic widgets', $layout_section['content'] );
		$this->assertStringContainsString( 'render_verified', $layout_section['content'] );

		$atomic_section = $this->findSection( $guide, 'Atomic Elements (Editor V4)' );
		$this->assertStringContainsString( '**Recommendation:**', $atomic_section['content'] );
		$this->assertStringContainsString( 'container + classic widgets', $atomic_section['content'] );
		$this->assertStringContainsString( 'render_verified', $atomic_section['content'] );

		// Atomic support itself must not be removed — still documented in full.
		$this->assertStringContainsString( 'e-div-block', $atomic_section['content'] );
		$this->assertStringContainsString( 'e-heading', $atomic_section['content'] );
	}

	/**
	 * The bug the rig caught: the example wrapped `title` as
	 * {"$$type": "string", ...}, but e-heading's real schema kind for title
	 * is "escaped-html".
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_guide_example_uses_escaped_html_for_heading_title(): void {
		$this->activateAtomicMode();

		$guide          = Mcpwp_Guides::guide_elementor();
		$atomic_section = $this->findSection( $guide, 'Atomic Elements (Editor V4)' );

		$this->assertStringContainsString(
			'"title": {"$$type": "escaped-html", "value": "Hello Atomic World"}',
			$atomic_section['content']
		);
		$this->assertStringNotContainsString(
			'"title": {"$$type": "string", "value": "Hello Atomic World"}',
			$atomic_section['content']
		);
	}

	/**
	 * Container-only (non-atomic) sites must not see the atomic-only
	 * recommendation text — it only applies once atomic mode is reported.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_guide_layout_section_unaffected_in_container_mode(): void {
		$this->activateContainerModeOnly();

		$guide = Mcpwp_Guides::guide_elementor();

		$this->assertSame( 'container', $guide['layout_mode'] );

		$layout_section = $this->findSection( $guide, 'Layout Modes' );
		$this->assertStringNotContainsString( '**On this site:**', $layout_section['content'] );
	}

	// ---------------------------------------------------------------
	// MCP server instructions (initialize response)
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_server_instructions_recommend_containers_in_atomic_mode(): void {
		$this->activateAtomicMode();

		$instructions = $this->buildServerInstructions();

		$this->assertStringContainsString( 'container > container(s) > widget(s)', $instructions );
		$this->assertStringContainsString( 'render_verified', $instructions );
		// Must not tell the agent to build the section hierarchy on an
		// atomic-mode site — that branch is for classic "section" mode only.
		$this->assertStringNotContainsString( '`section > column(s) > widget(s)`', $instructions );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_server_instructions_container_mode_unaffected(): void {
		$this->activateContainerModeOnly();

		$instructions = $this->buildServerInstructions();

		$this->assertStringContainsString( 'container > container(s) > widget(s)', $instructions );
		$this->assertStringNotContainsString( 'render_verified', $instructions );
	}

	private function buildServerInstructions(): string {
		$method = new ReflectionMethod( Mcpwp_REST_MCP::class, 'build_server_instructions' );
		$method->setAccessible( true );

		return (string) $method->invoke( new Mcpwp_REST_MCP() );
	}

	// ---------------------------------------------------------------
	// wp_onboard
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_onboard_elementor_integration_carries_a_container_note_in_atomic_mode(): void {
		$this->activateAtomicMode();
		$this->stubThemeLookup();

		$controller = new Mcpwp_REST_Site_Info();
		// #905: the default response is now the slim shape, where active
		// integrations (with their guidance notes intact) live under
		// `connected` rather than `active_integrations` (full-briefing-only).
		$data = $controller->get_onboard( new WP_REST_Request( 'GET', '/mcpwp/v1/onboard' ) )->get_data();

		$this->assertArrayHasKey( 'elementor', $data['connected'] );
		$this->assertSame( 'atomic', $data['connected']['elementor']['layout_mode'] );
		$this->assertArrayHasKey( 'note', $data['connected']['elementor'] );
		$this->assertStringContainsString( 'container', $data['connected']['elementor']['note'] );
		$this->assertStringContainsString( 'render_verified', $data['connected']['elementor']['note'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_onboard_elementor_integration_has_no_note_in_container_mode(): void {
		$this->activateContainerModeOnly();
		$this->stubThemeLookup();

		$controller = new Mcpwp_REST_Site_Info();
		$data       = $controller->get_onboard( new WP_REST_Request( 'GET', '/mcpwp/v1/onboard' ) )->get_data();

		$this->assertSame( 'container', $data['connected']['elementor']['layout_mode'] );
		$this->assertArrayNotHasKey( 'note', $data['connected']['elementor'] );
	}

	// ---------------------------------------------------------------
	// Writer guard: container data must still be accepted on an atomic-mode
	// site (atomic is additive — Container is active alongside it).
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_container_elements_are_accepted_when_atomic_mode_is_active(): void {
		$this->activateAtomicMode();

		$page_id                      = 9310;
		$GLOBALS['_mcpwp_test_meta']  = array();
		$GLOBALS['_mcpwp_test_posts'] = array(
			$page_id => new WP_Post(
				array(
					'ID'          => $page_id,
					'post_type'   => 'page',
					'post_title'  => 'Atomic-mode container probe',
					'post_name'   => 'atomic-mode-container-probe',
					'post_status' => 'draft',
				)
			),
		);

		$container_element = array(
			'id'       => 'ctr00002',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => array(),
		);

		$writer = ( new ReflectionClass( Mcpwp_Elementor_Basic::class ) )->newInstanceWithoutConstructor();
		$result = $writer->set_elementor_data(
			$page_id,
			array( 'elementor_data' => wp_json_encode( array( $container_element ) ) )
		);

		if ( $result instanceof WP_Error ) {
			$this->assertNotSame(
				'layout_mode_mismatch',
				$result->get_error_code(),
				'Container data must not be rejected as a layout-mode mismatch on an atomic-mode site — Container is active alongside Atomic Elements, not replaced by it.'
			);
			return;
		}

		$this->assertNotSame( 'layout_mode_mismatch', is_array( $result ) ? ( $result['code'] ?? '' ) : '' );
	}

	// ---------------------------------------------------------------
	// helpers
	// ---------------------------------------------------------------

	private function findSection( array $guide, string $heading ): array {
		foreach ( $guide['sections'] as $section ) {
			if ( $section['heading'] === $heading ) {
				return $section;
			}
		}

		$this->fail( sprintf( 'Guide section "%s" not found.', $heading ) );
	}
}
