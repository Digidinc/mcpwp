<?php
/**
 * #989 (from #804): no tool could say which template actually rendered on a
 * URL. A header template was published with include/general while Divi's
 * #main-header kept rendering, and wp_set_template_conditions reported only
 * the assignment. These tests pin Mcpwp_Effective_Template and the
 * effective_template block wp_get_rendered_html now returns.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// The controller under test is not loaded by the shared bootstrap.
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-content.php';

final class EffectiveTemplateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['_mcpwp_test_posts']             = array();
		$GLOBALS['_mcpwp_test_meta']              = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	private function document( string $body_class, string $body ): string {
		return '<!DOCTYPE html><html><head><title>t</title></head><body class="' . $body_class . '">' . $body . '</body></html>';
	}

	private function elementor_location( string $tag, string $location, int $id ): string {
		return '<' . $tag . ' data-elementor-type="' . $location . '" data-elementor-id="' . $id . '" class="elementor elementor-' . $id . ' elementor-location-' . $location . '"><div data-id="abc0001">x</div></' . $tag . '>';
	}

	public function test_reads_locations_page_template_and_theme_chrome(): void {
		$html = $this->document(
			'page-template page-template-elementor_header_footer page page-id-10',
			$this->elementor_location( 'header', 'header', 399280 )
			. '<main><div data-elementor-type="wp-page" data-elementor-id="10" class="elementor elementor-10">body</div></main>'
			. $this->elementor_location( 'footer', 'footer', 399281 )
		);

		$rendered = Mcpwp_Effective_Template::rendered_from_html( $html );

		$this->assertSame( 'rendered_html', $rendered['source'] );
		$this->assertSame( 'elementor_header_footer', $rendered['page_template'] );
		$this->assertSame( array( 399280 ), $rendered['locations']['header'] );
		$this->assertSame( array( 399281 ), $rendered['locations']['footer'] );
		$this->assertCount( 3, $rendered['elementor_documents'] );
		$this->assertSame( array(), $rendered['theme_chrome']['header'] );
		$this->assertSame( array(), $rendered['warnings'] );
	}

	/**
	 * The #804 case: an Elementor header rendered AND the theme's own one.
	 */
	public function test_theme_header_beside_an_elementor_header_is_flagged(): void {
		$html = $this->document(
			'page-template-default page-template page',
			'<header id="main-header"><nav>Divi menu</nav></header>' . $this->elementor_location( 'header', 'header', 399280 )
		);

		$rendered = Mcpwp_Effective_Template::rendered_from_html( $html );

		$this->assertSame( 'default', $rendered['page_template'] );
		$this->assertSame( array( '#main-header' ), $rendered['theme_chrome']['header'] );
		$this->assertCount( 1, $rendered['warnings'] );
		$this->assertStringContainsString( 'two headers', $rendered['warnings'][0] );
	}

	public function test_elementor_markup_reusing_theme_ids_is_not_theme_chrome(): void {
		$html = $this->document(
			'',
			'<header data-elementor-type="header" data-elementor-id="5" class="elementor elementor-location-header"><div class="site-header" id="masthead">x</div></header>'
		);

		$rendered = Mcpwp_Effective_Template::rendered_from_html( $html );

		$this->assertSame( array(), $rendered['theme_chrome']['header'] );
		$this->assertNull( $rendered['page_template'], 'No template class, no guess.' );
	}

	public function test_a_fragment_is_not_read_as_a_document(): void {
		$this->assertNull( Mcpwp_Effective_Template::rendered_from_html( '<div class="elementor">x</div>' ) );
	}

	public function test_theme_class_containing_template_is_not_a_page_template(): void {
		$rendered = Mcpwp_Effective_Template::rendered_from_html( $this->document( 'ast-header-template-2 home', '' ) );

		$this->assertNull( $rendered['page_template'] );
	}

	public function test_assigned_header_that_did_not_render_is_reported_with_what_rendered_instead(): void {
		$assigned = array(
			'page_template' => 'default',
			'theme_builder' => array(
				'evaluated' => true,
				'locations' => array( 'header' => array( 399280 ) ),
			),
		);
		$rendered = Mcpwp_Effective_Template::rendered_from_html(
			$this->document( 'page-template page-template-default', '<header id="main-header">Divi</header>' )
		);

		$warnings = Mcpwp_Effective_Template::compare( $assigned, $rendered );

		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( '399280', $warnings[0] );
		$this->assertStringContainsString( '#main-header', $warnings[0] );
	}

	public function test_page_template_mismatch_is_reported(): void {
		$warnings = Mcpwp_Effective_Template::compare(
			array( 'page_template' => 'elementor_canvas', 'theme_builder' => array( 'evaluated' => false ) ),
			Mcpwp_Effective_Template::rendered_from_html( $this->document( 'page-template page-template-default', '' ) )
		);

		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'elementor_canvas', $warnings[0] );
	}

	public function test_assigned_without_elementor_pro_says_why(): void {
		$post = new WP_Post( array( 'ID' => 10, 'post_type' => 'page' ) );
		update_post_meta( 10, '_wp_page_template', 'elementor_canvas' );

		$assigned = Mcpwp_Effective_Template::assigned_for_post( $post );

		$this->assertSame( 'elementor_canvas', $assigned['page_template'] );
		$this->assertNotNull( $assigned['page_template_label'] );
		$this->assertFalse( $assigned['theme_builder']['evaluated'] );
		$this->assertSame( 'elementor_pro_theme_builder_unavailable', $assigned['theme_builder']['reason'] );
	}

	// ── wp_get_rendered_html carries the report ─────────────────────────

	private function rendered_html( array $params, string $html ) {
		add_filter(
			'pre_http_request',
			static function () use ( $html ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => $html,
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$controller = new Mcpwp_REST_Site_Content();
		// 'content' too: a page id is gated by its own post type's category.
		$created    = $controller->create_scoped_api_key( 'test key', array( 'read' ), 'custom', array( 'site', 'content' ) );
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/rendered-html', $params, array( 'X-API-Key' => $created['key'] ) );
		$this->assertTrue( $controller->verify_api_key( $request ) );

		return $controller->get_rendered_html( $request )->get_data();
	}

	public function test_rendered_html_reports_templates_from_the_whole_document_even_with_a_selector(): void {
		$GLOBALS['_mcpwp_test_posts'][10] = new WP_Post( array( 'ID' => 10, 'post_type' => 'page', 'post_status' => 'publish' ) );

		$html = $this->document(
			'page-template page-template-default',
			'<header id="main-header">Divi</header>' . $this->elementor_location( 'header', 'header', 77 ) . '<footer id="colophon">f</footer>'
		);

		$data = $this->rendered_html( array( 'id' => 10, 'selector' => 'footer' ), $html );

		$this->assertTrue( $data['selector_found'] );
		$this->assertArrayHasKey( 'effective_template', $data );
		$this->assertSame( array( 77 ), $data['effective_template']['rendered']['locations']['header'] );
		$this->assertSame( 'default', $data['effective_template']['assigned']['page_template'] );
		$this->assertStringContainsString( 'two headers', implode( ' ', $data['effective_template']['warnings'] ) );
	}

	public function test_rendered_html_for_a_url_without_a_post_still_reports_what_rendered(): void {
		$data = $this->rendered_html(
			array( 'url' => 'https://example.com/category/blog/' ),
			$this->document( 'archive category', $this->elementor_location( 'div', 'archive', 88 ) )
		);

		$this->assertNull( $data['effective_template']['assigned'] );
		$this->assertSame( array( 88 ), $data['effective_template']['rendered']['locations']['archive'] );
	}
}
