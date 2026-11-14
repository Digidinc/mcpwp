<?php
/**
 * #989: render_verified must assert the rendered DOM, not only that the save
 * pipeline did not throw. These tests pin Mcpwp_Render_Inspector, which reads
 * the HTML Elementor produced and compares it with the saved tree.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class RenderInspectorTest extends TestCase {

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	private function heading( string $id, array $settings ): array {
		return array(
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => $settings,
		);
	}

	private function section( array $widgets, array $settings = array() ): array {
		return array(
			array(
				'id'       => 'sec0001',
				'elType'   => 'section',
				'settings' => $settings,
				'elements' => array(
					array(
						'id'       => 'col0001',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => $widgets,
					),
				),
			),
		);
	}

	/**
	 * Elementor's wrapper markup for a section/column with the given widget
	 * HTML inside.
	 */
	private function render( string $widgets_html ): string {
		return '<div class="elementor elementor-10" data-elementor-type="wp-page" data-elementor-id="10">'
			. '<section class="elementor-section" data-id="sec0001" data-element_type="section">'
			. '<div class="elementor-column" data-id="col0001" data-element_type="column">'
			. $widgets_html
			. '</div></section></div>';
	}

	private function heading_html( string $id, string $text ): string {
		return '<div class="elementor-widget elementor-widget-heading" data-id="' . $id . '" data-element_type="widget"><h2 class="elementor-heading-title">' . $text . '</h2></div>';
	}

	public function test_a_render_matching_the_saved_tree_passes(): void {
		$tree   = $this->section( array( $this->heading( 'hed0001', array( 'title' => 'Welcome home' ) ) ) );
		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $this->render( $this->heading_html( 'hed0001', 'Welcome home' ) ), $tree );

		$this->assertSame( 'passed', $report['status'], wp_json_encode( $report ) );
		$this->assertSame( 3, $report['elements_expected'] );
		$this->assertSame( 3, $report['elements_rendered'] );
		$this->assertSame( array(), $report['failures'] );
	}

	/**
	 * The #804 case: a heading saved under the wrong key renders Elementor's
	 * default text. The pipeline succeeds; the DOM shows a placeholder.
	 */
	public function test_default_heading_placeholder_fails(): void {
		$tree   = $this->section( array( $this->heading( 'hed0001', array( 'text' => 'Welcome home' ) ) ) );
		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $this->render( $this->heading_html( 'hed0001', 'Add Your Heading Text Here' ) ), $tree );

		$this->assertSame( 'failed', $report['status'] );
		$this->assertSame( 'elementor_default_heading', $report['placeholders'][0]['type'] );
		$this->assertStringContainsString( 'Add Your Heading Text Here', implode( ' ', $report['failures'] ) );
	}

	public function test_placeholder_the_caller_saved_is_not_reported(): void {
		$tree   = $this->section( array( $this->heading( 'hed0001', array( 'title' => 'Add Your Heading Text Here' ) ) ) );
		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $this->render( $this->heading_html( 'hed0001', 'Add Your Heading Text Here' ) ), $tree );

		$this->assertSame( 'passed', $report['status'], wp_json_encode( $report ) );
		$this->assertSame( array(), $report['placeholders'] );
	}

	public function test_placeholder_image_fails(): void {
		$tree = $this->section(
			array(
				array(
					'id'         => 'img0001',
					'elType'     => 'widget',
					'widgetType' => 'image',
					'settings'   => array( 'image' => array( 'id' => 5 ) ),
				),
			)
		);
		$html = $this->render( '<div class="elementor-widget" data-id="img0001"><img src="https://example.com/wp-content/plugins/elementor/assets/images/placeholder.png" alt=""></div>' );

		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $html, $tree );

		$this->assertSame( 'failed', $report['status'] );
		$this->assertSame( 'elementor_placeholder_image', $report['placeholders'][0]['type'] );
	}

	public function test_default_button_label_fails_but_a_saved_one_does_not(): void {
		$button = static function ( array $settings ): array {
			return array(
				'id'         => 'btn0001',
				'elType'     => 'widget',
				'widgetType' => 'button',
				'settings'   => $settings,
			);
		};
		$html = $this->render( '<div class="elementor-widget" data-id="btn0001"><a class="elementor-button elementor-button-link" href="/contact"><span class="elementor-button-content-wrapper"><span class="elementor-button-text">Click here</span></span></a></div>' );

		$defaulted = Mcpwp_Render_Inspector::inspect_elementor_output( $html, $this->section( array( $button( array( 'label' => 'Contact' ) ) ) ) );
		$this->assertSame( 'failed', $defaulted['status'] );
		$this->assertSame( 'elementor_default_button_text', $defaulted['placeholders'][0]['type'] );

		$asked = Mcpwp_Render_Inspector::inspect_elementor_output( $html, $this->section( array( $button( array( 'text' => 'Click here' ) ) ) ) );
		$this->assertSame( 'passed', $asked['status'], wp_json_encode( $asked ) );
	}

	public function test_element_that_rendered_nothing_fails_and_is_named(): void {
		$tree = $this->section(
			array(
				$this->heading( 'hed0001', array( 'title' => 'Shown' ) ),
				$this->heading( 'hed0002', array( 'title' => '' ) ),
			)
		);
		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $this->render( $this->heading_html( 'hed0001', 'Shown' ) ), $tree );

		$this->assertSame( 'failed', $report['status'] );
		$this->assertSame( 1, $report['missing_element_count'] );
		$this->assertSame( array( 'hed0002' ), $report['missing_element_ids'] );
		$this->assertStringContainsString( 'hed0002', implode( ' ', $report['failures'] ) );
	}

	public function test_missing_ids_are_capped_but_counted_exactly(): void {
		$widgets = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$widgets[] = $this->heading( sprintf( 'h%06d', $i ), array( 'title' => 'x' ) );
		}
		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $this->render( '' ), $this->section( $widgets ) );

		$this->assertSame( 30, $report['missing_element_count'] );
		$this->assertCount( Mcpwp_Render_Inspector::MAX_REPORTED_IDS, $report['missing_element_ids'] );
	}

	public function test_empty_render_of_a_non_empty_tree_fails(): void {
		$report = Mcpwp_Render_Inspector::inspect_elementor_output( '', $this->section( array( $this->heading( 'hed0001', array( 'title' => 'x' ) ) ) ) );

		$this->assertSame( 'failed', $report['status'] );
		$this->assertSame( 3, $report['missing_element_count'] );
		$this->assertStringContainsString( 'rendered no HTML', $report['failures'][0] );
	}

	public function test_empty_render_of_an_empty_tree_passes(): void {
		$report = Mcpwp_Render_Inspector::inspect_elementor_output( '', array() );

		$this->assertSame( 'passed', $report['status'] );
		$this->assertSame( 0, $report['elements_expected'] );
	}

	public function test_unrendered_divi_shortcode_fails(): void {
		$tree = $this->section(
			array(
				array(
					'id'         => 'txt0001',
					'elType'     => 'widget',
					'widgetType' => 'text-editor',
					'settings'   => array( 'editor' => '<p>Hello</p>' ),
				),
			)
		);
		$html   = $this->render( '<div class="elementor-widget" data-id="txt0001"><p>[et_pb_section][et_pb_text]Hello[/et_pb_text][/et_pb_section]</p></div>' );
		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $html, $tree );

		$this->assertSame( 'failed', $report['status'] );
		$this->assertSame( array( 'divi_shortcode' ), $report['shortcode_leaks'] );
	}

	public function test_heading_whose_text_did_not_render_fails(): void {
		$tree   = $this->section( array( $this->heading( 'hed0001', array( 'title' => 'Book a demo' ) ) ) );
		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $this->render( $this->heading_html( 'hed0001', 'Something else' ) ), $tree );

		$this->assertSame( 'failed', $report['status'] );
		$this->assertSame( 'hed0001', $report['text_mismatches'][0]['element_id'] );
		$this->assertSame( 'title', $report['text_mismatches'][0]['setting'] );
	}

	/**
	 * Rendering changes typography (wptexturize), entities and case is CSS;
	 * none of that is a mismatch.
	 */
	public function test_text_comparison_ignores_typography_entities_and_markup(): void {
		$tree   = $this->section( array( $this->heading( 'hed0001', array( 'title' => 'It\'s "<strong>ready</strong>" - now &amp; here' ) ) ) );
		$html   = $this->render( $this->heading_html( 'hed0001', 'It&#8217;s &#8220;<strong>ready</strong>&#8221; &#8211; now &amp; here' ) );
		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $html, $tree );

		$this->assertSame( 'passed', $report['status'], wp_json_encode( $report ) );
	}

	public function test_dynamic_title_is_not_compared(): void {
		$tree = $this->section(
			array(
				$this->heading(
					'hed0001',
					array(
						'title'       => 'Stored fallback',
						'__dynamic__' => array( 'title' => '[elementor-tag id="x" name="post-title"]' ),
					)
				),
			)
		);
		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $this->render( $this->heading_html( 'hed0001', 'The Real Post Title' ) ), $tree );

		$this->assertSame( 'passed', $report['status'], wp_json_encode( $report ) );
	}

	public function test_display_conditions_subtree_is_not_expected(): void {
		$tree = array(
			array(
				'id'       => 'con0001',
				'elType'   => 'container',
				'settings' => array( 'e_display_conditions' => array( array( 'logged_in' ) ) ),
				'elements' => array( $this->heading( 'hed0009', array( 'title' => 'Members only' ) ) ),
			),
		);
		$report = Mcpwp_Render_Inspector::inspect_elementor_output( '<div class="elementor"></div>', $tree );

		$this->assertSame( 'passed', $report['status'], wp_json_encode( $report ) );
		$this->assertSame( 0, $report['elements_expected'] );
		$this->assertSame( 2, $report['elements_not_checked'] );
	}

	public function test_hash_link_button_is_a_warning_not_a_failure(): void {
		$tree = $this->section(
			array(
				array(
					'id'         => 'btn0001',
					'elType'     => 'widget',
					'widgetType' => 'button',
					'settings'   => array( 'text' => 'Go' ),
				),
			)
		);
		$html   = $this->render( '<div class="elementor-widget" data-id="btn0001"><a class="elementor-button" href="#"><span class="elementor-button-text">Go</span></a></div>' );
		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $html, $tree );

		$this->assertSame( 'passed', $report['status'] );
		$this->assertCount( 1, $report['warnings'] );
		$this->assertStringContainsString( 'btn0001', $report['warnings'][0] );
	}

	public function test_nested_template_ids_are_not_required(): void {
		$tree = $this->section( array( $this->heading( 'hed0001', array( 'title' => 'Hi' ) ) ) );
		$html = $this->render( $this->heading_html( 'hed0001', 'Hi' ) . '<div data-elementor-id="77" data-elementor-type="section"><div data-id="zzz9999">x</div></div>' );

		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $html, $tree );

		$this->assertSame( 'passed', $report['status'] );
		$this->assertSame( 3, $report['elements_expected'] );
	}

	// ── Adversarial review round 1 (#989) ───────────────────────────────

	/**
	 * A Template, Global or Loop item embedded in the page is another
	 * document: its placeholders and shortcodes are not this page's defects.
	 */
	public function test_placeholders_and_shortcodes_inside_a_nested_document_do_not_fail_the_page(): void {
		$tree   = $this->section(
			array(
				$this->heading( 'hed0001', array( 'title' => 'Hi' ) ),
				array(
					'id'         => 'tpl0001',
					'elType'     => 'widget',
					'widgetType' => 'template',
					'settings'   => array( 'template_id' => 77 ),
				),
			)
		);
		$nested = '<div class="elementor-widget" data-id="tpl0001"><div data-elementor-type="section" data-elementor-id="77">'
			. '<h2>Add Your Heading Text Here</h2><img src="/wp-content/plugins/elementor/assets/images/placeholder.png">'
			. '<span class="elementor-button-text">Click here</span><p>[et_pb_text]</p></div></div>';

		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $this->render( $this->heading_html( 'hed0001', 'Hi' ) . $nested ), $tree );

		$this->assertSame( 'passed', $report['status'], wp_json_encode( $report ) );
	}

	public function test_the_same_placeholders_on_the_page_itself_still_fail(): void {
		$tree = $this->section( array( $this->heading( 'hed0001', array( 'title' => 'Hi' ) ) ) );
		$own  = '<img src="/wp-content/plugins/elementor/assets/images/placeholder.png"><span class="elementor-button-text">Click here</span>';

		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $this->render( $this->heading_html( 'hed0001', 'Hi' ) . $own ), $tree );

		$this->assertSame( array( 'elementor_placeholder_image', 'elementor_default_button_text' ), array_column( $report['placeholders'], 'type' ) );
	}

	public function test_shortcode_text_inside_code_is_content_not_a_leak(): void {
		$tree = $this->section(
			array(
				array(
					'id'         => 'txt0001',
					'elType'     => 'widget',
					'widgetType' => 'text-editor',
					'settings'   => array( 'editor' => 'x' ),
				),
			)
		);
		$html = $this->render( '<div data-id="txt0001"><p>Use <code>[et_pb_section]</code> or:</p><pre>[elementor-template id="5"]</pre><script>var s="Add Your Heading Text Here";</script></div>' );

		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $html, $tree );

		$this->assertSame( 'passed', $report['status'], wp_json_encode( $report ) );
	}

	public function test_widgets_that_may_render_nothing_are_not_missing(): void {
		$widgets = array();
		foreach ( array( 'shortcode', 'html', 'loop-grid', 'nav-menu', 'wp-widget-recent-posts', 'theme-post-title' ) as $i => $type ) {
			$widgets[] = array(
				'id'         => sprintf( 'emp%04d', $i ),
				'elType'     => 'widget',
				'widgetType' => $type,
				'settings'   => array(),
			);
		}

		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $this->render( '' ), $this->section( $widgets ) );

		$this->assertSame( 'passed', $report['status'], wp_json_encode( $report ) );
		$this->assertSame( 6, $report['elements_empty_allowed'] );
		$this->assertSame( 0, $report['missing_element_count'] );
	}

	public function test_element_cache_placeholders_skip_the_check(): void {
		$html   = $this->render( '[elementor-element k="abc" data="eyJ9"]' );
		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $html, $this->section( array( $this->heading( 'hed0001', array( 'title' => 'x' ) ) ) ) );

		$this->assertSame( 'skipped', $report['status'] );
		$this->assertSame( 'element_cache_placeholder', $report['reason'] );
	}

	public function test_html_over_the_byte_limit_is_skipped_not_parsed(): void {
		add_filter(
			'mcpwp_dom_check_max_bytes',
			static function () {
				return 100;
			}
		);

		$report = Mcpwp_Render_Inspector::inspect_elementor_output( $this->render( str_repeat( 'x', 200 ) ), array() );

		$this->assertSame( 'skipped', $report['status'] );
		$this->assertSame( 'html_too_large', $report['reason'] );
	}
}
