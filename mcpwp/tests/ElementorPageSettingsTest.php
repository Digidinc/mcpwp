<?php
/**
 * Coverage for #638 — full-page templates carried the layout and lost the
 * document.
 *
 * `_elementor_page_settings` holds the document half of an Elementor page:
 * which page template it uses, whether the title is hidden, the body
 * background, page padding, the page's own custom CSS. Get responses exposed
 * it; no create or update path accepted it. So an 18-section archetype stored
 * from a real page read back with `page_settings: ""` — structure intact,
 * document gone — and applying it produced the right sections inside the wrong
 * frame.
 *
 * The one page-settings write that did exist took a five-key allowlist and
 * reported `page_settings_updated` listing the keys the CALLER SENT rather
 * than the keys it accepted, so a dropped key was indistinguishable from an
 * applied one. Both halves of that are fixed here: the allowlist admits
 * Elementor's control families rather than five hand-picked names, and what is
 * refused is reported by name.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorPageSettingsTest extends TestCase {

	private const PAGE_ID     = 9300;
	private const TEMPLATE_ID = 9301;

	/** @var array */
	private $meta_before = array();

	protected function setUp(): void {
		$this->meta_before           = $GLOBALS['_mcpwp_test_meta'] ?? array();
		$GLOBALS['_mcpwp_test_meta'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_meta'] = $this->meta_before;
	}

	/**
	 * A document with the settings the issue actually names.
	 *
	 * @return array
	 */
	private function document(): array {
		return array(
			'template'              => 'elementor_canvas',
			'hide_title'            => 'yes',
			'background_background' => 'classic',
			'background_color'      => '#101820',
			'padding'               => array(
				'unit'     => 'px',
				'top'      => '80',
				'right'    => '0',
				'bottom'   => '80',
				'left'     => '0',
				'isLinked' => false,
			),
			'custom_css'            => "selector .hero {\n  letter-spacing: -0.02em;\n}",
		);
	}

	// ---------------------------------------------------------------
	// Reading: absent is not empty.
	// ---------------------------------------------------------------

	public function test_a_post_with_no_settings_reads_as_null(): void {
		$this->assertNull( Mcpwp_Elementor_Page_Settings::read( self::PAGE_ID ) );
	}

	public function test_unreadable_settings_read_as_null_not_as_empty(): void {
		update_post_meta( self::PAGE_ID, '_elementor_page_settings', '{not json' );

		// Returning array() here would let a caller conclude the document has
		// no settings when in fact this could not read the ones it has — and
		// then overwrite them.
		$this->assertNull( Mcpwp_Elementor_Page_Settings::read( self::PAGE_ID ) );
	}

	public function test_settings_stored_as_a_json_string_are_read(): void {
		update_post_meta( self::PAGE_ID, '_elementor_page_settings', wp_json_encode( $this->document() ) );

		$this->assertSame( 'elementor_canvas', Mcpwp_Elementor_Page_Settings::read( self::PAGE_ID )['template'] );
	}

	// ---------------------------------------------------------------
	// The round trip, without type loss.
	// ---------------------------------------------------------------

	public function test_a_document_round_trips_with_its_types_intact(): void {
		$document   = $this->document();
		$normalized = Mcpwp_Elementor_Page_Settings::normalize( $document );

		$this->assertSame( array(), $normalized['skipped'] );
		$this->assertTrue( Mcpwp_Elementor_Page_Settings::write( self::PAGE_ID, $normalized['settings'] ) );

		$back = Mcpwp_Elementor_Page_Settings::read( self::PAGE_ID );

		$this->assertSame( 'elementor_canvas', $back['template'] );
		$this->assertSame( 'yes', $back['hide_title'] );
		$this->assertSame( '#101820', $back['background_color'] );

		// A dimensions control is a nested array with a boolean in it. Running
		// the map through sanitize_text_field would flatten this to a string
		// and change the document — the acceptance criteria rule out type loss
		// specifically.
		$this->assertIsArray( $back['padding'] );
		$this->assertSame( '80', $back['padding']['top'] );
		$this->assertFalse( $back['padding']['isLinked'] );
	}

	public function test_page_custom_css_survives_its_newlines(): void {
		$normalized = Mcpwp_Elementor_Page_Settings::normalize( $this->document() );
		Mcpwp_Elementor_Page_Settings::write( self::PAGE_ID, $normalized['settings'] );

		$css = Mcpwp_Elementor_Page_Settings::read( self::PAGE_ID )['custom_css'];

		// sanitize_text_field() strips newlines, which would turn a working
		// stylesheet into one line of broken CSS — the setting this feature
		// exists to carry would be the one it destroyed.
		$this->assertStringContainsString( "\n", $css );
		$this->assertStringContainsString( 'letter-spacing', $css );
	}

	public function test_html_cannot_be_smuggled_through_custom_css(): void {
		$normalized = Mcpwp_Elementor_Page_Settings::normalize(
			array( 'custom_css' => "selector{color:red}<script>alert(1)</script>" )
		);

		$this->assertStringNotContainsString( '<script', $normalized['settings']['custom_css'] );
		$this->assertStringContainsString( 'color:red', $normalized['settings']['custom_css'] );
	}

	// ---------------------------------------------------------------
	// What is refused is reported.
	// ---------------------------------------------------------------

	public function test_unrecognised_keys_are_reported_rather_than_dropped(): void {
		$normalized = Mcpwp_Elementor_Page_Settings::normalize(
			array(
				'template'      => 'elementor_canvas',
				'_invalid_key'  => 'nope',
				'post_content'  => 'definitely not a page setting',
			)
		);

		$this->assertSame( array( 'template' ), array_keys( $normalized['settings'] ) );
		$this->assertContains( '_invalid_key', $normalized['skipped'] );
		$this->assertContains( 'post_content', $normalized['skipped'] );
	}

	public function test_the_control_families_a_real_document_uses_are_admitted(): void {
		// The previous allowlist was five hand-picked names, which is how most
		// of a real document got dropped. Elementor generates these families,
		// so they are admitted as families.
		foreach ( array( 'background_overlay_color', 'background_image', 'padding_tablet', 'margin_mobile', 'content_width_tablet' ) as $key ) {
			$this->assertTrue(
				Mcpwp_Elementor_Page_Settings::is_allowed_key( $key ),
				$key . ' is a real Elementor page setting and must round-trip.'
			);
		}
	}

	// ---------------------------------------------------------------
	// Apply modes.
	// ---------------------------------------------------------------

	public function test_preserve_is_the_default_and_never_restyles_an_existing_page(): void {
		Mcpwp_Elementor_Page_Settings::write(
			self::PAGE_ID,
			array( 'template' => 'elementor_header_footer', 'hide_title' => '' )
		);

		$report = Mcpwp_Elementor_Page_Settings::apply( self::PAGE_ID, $this->document(), 'preserve' );
		$after  = Mcpwp_Elementor_Page_Settings::read( self::PAGE_ID );

		// The page's own frame wins. Applying a template must not silently
		// change a page's canvas or title visibility.
		$this->assertSame( 'elementor_header_footer', $after['template'] );
		$this->assertSame( '', $after['hide_title'] );
		$this->assertContains( 'template', $report['preserved'] );

		// But settings the page did not have are gained.
		$this->assertSame( '#101820', $after['background_color'] );
		$this->assertContains( 'background_color', $report['applied'] );
	}

	public function test_an_unknown_mode_falls_back_to_preserve_rather_than_replacing(): void {
		Mcpwp_Elementor_Page_Settings::write( self::PAGE_ID, array( 'template' => 'elementor_header_footer' ) );

		$report = Mcpwp_Elementor_Page_Settings::apply( self::PAGE_ID, $this->document(), 'obliterate' );

		$this->assertSame( 'preserve', $report['page_settings_mode'] );
		$this->assertSame( 'elementor_header_footer', Mcpwp_Elementor_Page_Settings::read( self::PAGE_ID )['template'] );
	}

	public function test_replace_takes_the_template_document_wholesale(): void {
		Mcpwp_Elementor_Page_Settings::write(
			self::PAGE_ID,
			array( 'template' => 'elementor_header_footer', 'background_color' => '#FFFFFF' )
		);

		$report = Mcpwp_Elementor_Page_Settings::apply( self::PAGE_ID, $this->document(), 'replace' );
		$after  = Mcpwp_Elementor_Page_Settings::read( self::PAGE_ID );

		$this->assertSame( 'elementor_canvas', $after['template'] );
		$this->assertSame( '#101820', $after['background_color'] );
		$this->assertSame( array(), $report['preserved'] );
	}

	public function test_merge_lets_the_template_win_per_key_and_keeps_the_rest(): void {
		Mcpwp_Elementor_Page_Settings::write(
			self::PAGE_ID,
			array( 'template' => 'elementor_header_footer', 'content_width' => '1140' )
		);

		$report = Mcpwp_Elementor_Page_Settings::apply( self::PAGE_ID, $this->document(), 'merge' );
		$after  = Mcpwp_Elementor_Page_Settings::read( self::PAGE_ID );

		$this->assertSame( 'elementor_canvas', $after['template'], 'The template wins on a key it sets.' );
		$this->assertSame( '1140', $after['content_width'], 'The page keeps a key the template never mentions.' );
		$this->assertContains( 'content_width', $report['preserved'] );
	}

	public function test_a_template_with_no_settings_says_so_rather_than_reporting_an_empty_apply(): void {
		Mcpwp_Elementor_Page_Settings::write( self::PAGE_ID, array( 'template' => 'elementor_canvas' ) );

		$report = Mcpwp_Elementor_Page_Settings::apply( self::PAGE_ID, null, 'replace' );

        // An apply that carried nothing and an apply that carried something
        // and changed nothing must not read the same.
		$this->assertArrayHasKey( 'page_settings_source', $report );
		$this->assertSame( array(), $report['applied'] );
		$this->assertContains( 'template', $report['preserved'] );

		// And it must not have wiped the page under 'replace'.
		$this->assertSame( 'elementor_canvas', Mcpwp_Elementor_Page_Settings::read( self::PAGE_ID )['template'] );
	}

	public function test_the_report_names_what_was_refused_during_an_apply(): void {
		$report = Mcpwp_Elementor_Page_Settings::apply(
			self::PAGE_ID,
			array( 'template' => 'elementor_canvas', 'post_content' => 'nope' ),
			'replace'
		);

		$this->assertContains( 'template', $report['applied'] );
		$this->assertContains( 'post_content', $report['skipped'] );
	}

	public function test_a_failed_write_is_reported_rather_than_assumed(): void {
		$GLOBALS['mcpwp_test_meta_write_fails'] = array( '_elementor_page_settings' );

		$report = Mcpwp_Elementor_Page_Settings::apply( self::PAGE_ID, $this->document(), 'replace' );

		unset( $GLOBALS['mcpwp_test_meta_write_fails'] );

		$this->assertFalse( $report['written'] );
		$this->assertArrayHasKey( 'warning', $report );
	}

	public function test_writing_an_empty_document_clears_rather_than_storing_nothing(): void {
		Mcpwp_Elementor_Page_Settings::write( self::PAGE_ID, $this->document() );

		$this->assertTrue( Mcpwp_Elementor_Page_Settings::write( self::PAGE_ID, array() ) );
		$this->assertNull( Mcpwp_Elementor_Page_Settings::read( self::PAGE_ID ) );
	}
}
