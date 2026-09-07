<?php
/**
 * Regression coverage for #785 defect 4 and #784 — data written, nothing
 * rendered, reported as success.
 *
 * #785: create a page with no Elementor document, then wp_apply_elementor_part.
 * The response was clean — applied:true, document_save:true, meta_verified:true,
 * css_regenerated:true, render_verified:true — and the frontend rendered the
 * inserted content as BARE HTML DIRECTLY IN BODY: an h3 whose parentElement was
 * BODY, no .elementor-section wrappers, no classes, no styling.
 *
 * #784: eleven inserts into a fresh draft. Readback intact at 11 roots / 223
 * elements / 138 widgets, every call reporting render_verified:true and
 * html_cache_rebuilt:true, and wp_preview_elementor(format="html") returning
 * EMPTY with "the page may need to be saved in the Elementor editor first".
 * One manual Save Draft in the native editor fixed it.
 *
 * Same cause. Elementor's frontend renders _elementor_data only when the
 * document's is_built_with_elementor() is true, which reads the
 * `_elementor_edit_mode` meta. Document::save() does NOT set that — read it:
 * it saves settings, elements, template type and version, and never touches the
 * flag. Only Elementor's editor sets it (editor.php calls
 * set_is_built_with_elementor). set_elementor_data() and
 * create_elementor_page() set it themselves; save_elements_to_page(), which
 * every surgical operation routes through — add/remove/replace/patch/edit, and
 * therefore wp_apply_elementor_part — did not.
 *
 * Measured on the rig before the fix, on a page created and written entirely
 * through this API:
 *
 *   edit_mode after:         ''
 *   is_built_with_elementor: false
 *   render_verified:         true
 *   frontend html bytes:     0
 *
 * And after:
 *
 *   edit_mode after:         'builder'
 *   is_built_with_elementor: true
 *   frontend html bytes:     1290
 *
 * The report's cross-cutting complaint is the second half of this file:
 * render_verified answered true for a page rendering nothing, because it only
 * ever meant "no Elementor call threw". A verification that cannot fail on a
 * page producing zero bytes is not a verification.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorBuilderFlagTest extends TestCase {

	private const PAGE_ID = 9100;

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_meta'] = array();
		unset( $GLOBALS['mcpwp_test_meta_write_fails'] );

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PAGE_ID => new WP_Post(
				array(
					'ID'          => self::PAGE_ID,
					'post_type'   => 'page',
					'post_title'  => 'Builder flag probe',
					'post_name'   => 'builder-flag-probe',
					'post_status' => 'draft',
				)
			),
		);
	}

	protected function tearDown(): void {
		// Not only setUp: the failure switch below would otherwise follow this
		// class out and break whatever runs next (#757's family).
		$GLOBALS['_mcpwp_test_meta']  = array();
		$GLOBALS['_mcpwp_test_posts'] = array();
		unset( $GLOBALS['mcpwp_test_meta_write_fails'] );
	}

	private function writer(): Mcpwp_Elementor_Basic {
		return ( new ReflectionClass( Mcpwp_Elementor_Basic::class ) )->newInstanceWithoutConstructor();
	}

	private function invoke( string $method, array $args ) {
		$reflection = new ReflectionMethod( Mcpwp_Elementor_Basic::class, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $this->writer(), $args );
	}

	// ---------------------------------------------------------------
	// Setting the flag.
	// ---------------------------------------------------------------

	public function test_the_flag_is_written_when_elementor_is_not_loaded(): void {
		$this->assertSame( '', get_post_meta( self::PAGE_ID, '_elementor_edit_mode', true ) );

		$this->assertTrue( $this->invoke( 'mark_built_with_elementor', array( self::PAGE_ID ) ) );

		$this->assertSame(
			'builder',
			get_post_meta( self::PAGE_ID, '_elementor_edit_mode', true ),
			'Without Elementor loaded this must still fall back to the raw meta write, exactly as the rest of the writer does.'
		);
	}

	public function test_marking_an_already_flagged_post_still_reports_true(): void {
		update_post_meta( self::PAGE_ID, '_elementor_edit_mode', 'builder' );

		// update_post_meta() returns false when the value is unchanged, which
		// is indistinguishable from a failed write if you trust its return.
		// The flag is read back instead.
		$this->assertTrue( $this->invoke( 'mark_built_with_elementor', array( self::PAGE_ID ) ) );
	}

	public function test_a_meta_write_that_does_nothing_is_reported_as_failure(): void {
		$GLOBALS['mcpwp_test_meta_write_fails'] = array( '_elementor_edit_mode' );

		$this->assertFalse(
			$this->invoke( 'mark_built_with_elementor', array( self::PAGE_ID ) ),
			'A host whose meta write silently no-ops leaves the page unrenderable; claiming otherwise is the bug being fixed, one layer down.'
		);
	}

	// ---------------------------------------------------------------
	// Reporting it.
	// ---------------------------------------------------------------

	public function test_render_verified_is_false_when_the_page_will_not_render(): void {
		$result = $this->invoke(
			'decorate_render_status',
			array(
				array( 'success' => true ),
				array(
					'document_save'        => true,
					'html_cache_rebuilt'   => true,
					'built_with_elementor' => false,
				),
			)
		);

		// Every "did the call throw" signal is green here — that is precisely
		// the reported response — and the page renders nothing.
		$this->assertFalse( $result['render_verified'] );
		$this->assertFalse( $result['renders_via_elementor'] );
		$this->assertStringContainsString( 'builder_flag', implode( "\n", $result['render_errors'] ) );
		$this->assertStringContainsString( 'unstyled HTML', implode( "\n", $result['render_errors'] ) );
	}

	public function test_render_verified_survives_a_page_that_does_render(): void {
		$result = $this->invoke(
			'decorate_render_status',
			array(
				array( 'success' => true ),
				array(
					'document_save'        => true,
					'built_with_elementor' => true,
				),
			)
		);

		$this->assertTrue( $result['render_verified'] );
		$this->assertTrue( $result['renders_via_elementor'] );
		$this->assertArrayNotHasKey( 'render_errors', $result );
	}

	public function test_a_write_that_never_reached_the_flag_claims_nothing_about_it(): void {
		// Clearing a page writes an empty element list and deliberately does
		// not flag it. Reporting renders_via_elementor:false there would be
		// wrong in the other direction — nothing was attempted, so nothing is
		// claimed.
		$result = $this->invoke(
			'decorate_render_status',
			array(
				array( 'success' => true ),
				array( 'document_save' => true ),
			)
		);

		$this->assertTrue( $result['render_verified'] );
		$this->assertArrayNotHasKey( 'renders_via_elementor', $result );
	}

	public function test_an_existing_render_error_is_kept_alongside_the_flag_error(): void {
		$result = $this->invoke(
			'decorate_render_status',
			array(
				array( 'success' => true ),
				array(
					'document_save'           => true,
					'built_with_elementor'    => false,
					'css_regeneration_error'  => 'could not write file',
				),
			)
		);

		$errors = implode( "\n", $result['render_errors'] );

		$this->assertStringContainsString( 'css_regeneration', $errors );
		$this->assertStringContainsString( 'builder_flag', $errors );
	}

	public function test_the_warning_text_reaches_the_caller(): void {
		$result = $this->invoke(
			'decorate_render_status',
			array(
				array( 'success' => true ),
				array(
					'document_save'        => true,
					'built_with_elementor' => false,
				),
			)
		);

		// The reported failure was invisible precisely because nothing at the
		// top level of the response said anything was wrong.
		$this->assertNotEmpty( $result['warnings'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	// ---------------------------------------------------------------
	// The save path. Without this the two halves above could both be
	// correct while nothing ever calls them — which is the state the
	// plugin was actually in.
	// ---------------------------------------------------------------

	/**
	 * @return array One valid top-level section.
	 */
	private function section(): array {
		return array(
			'id'       => 'sec00001',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => 'col00001',
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => array(
						array(
							'id'         => 'wid00001',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array( 'title' => 'Inserted' ),
						),
					),
				),
			),
		);
	}

	public function test_saving_elements_flags_the_page(): void {
		$debug = $this->invoke( 'save_elements_to_page', array( self::PAGE_ID, array( $this->section() ) ) );

		$this->assertIsArray( $debug );
		$this->assertTrue(
			$debug['built_with_elementor'],
			'This is the path wp_apply_elementor_part takes. It wrote the data and left the page unrenderable.'
		);
		$this->assertSame( 'builder', get_post_meta( self::PAGE_ID, '_elementor_edit_mode', true ) );
	}

	public function test_clearing_a_page_does_not_claim_it_for_elementor(): void {
		$debug = $this->invoke( 'save_elements_to_page', array( self::PAGE_ID, array() ) );

		$this->assertIsArray( $debug );
		$this->assertArrayNotHasKey(
			'built_with_elementor',
			$debug,
			'Writing an empty element list is how a page gets cleared; flagging it would hand an empty Elementor document control of a page that renders fine without one.'
		);
		$this->assertSame( '', get_post_meta( self::PAGE_ID, '_elementor_edit_mode', true ) );
	}

	public function test_add_section_reports_the_render_verdict_it_earned(): void {
		$result = $this->writer()->add_section(
			self::PAGE_ID,
			array(
				'element'  => $this->section(),
				'position' => 'end',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $result['renders_via_elementor'] );

		// render_verified stays false in this process for a separate,
		// pre-existing reason: Elementor is not loaded here, so no render call
		// completed and collect_render_errors() says so. Asserting it true
		// would be asserting something this environment cannot show. What this
		// test owns is that the builder flag is not among the complaints.
		$this->assertStringNotContainsString(
			'builder_flag',
			implode( "\n", $result['render_errors'] ?? array() )
		);
	}

	public function test_add_section_reports_false_when_the_flag_cannot_be_written(): void {
		$GLOBALS['mcpwp_test_meta_write_fails'] = array( '_elementor_edit_mode' );

		$result = $this->writer()->add_section(
			self::PAGE_ID,
			array(
				'element'  => $this->section(),
				'position' => 'end',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );

		// The data really is saved, so success stays true and the caller must
		// not retry a completed write. render_verified is the field that says
		// the result is not usable.
		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['renders_via_elementor'] );
		$this->assertFalse( $result['render_verified'] );
	}
}
