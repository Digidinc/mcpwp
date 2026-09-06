<?php
/**
 * Regression coverage for #737: the css_id → _element_id rename ran only where
 * the validator ran, and four write paths never reach it.
 *
 * `_element_id` is the ID control Elementor actually renders from —
 * Element_Base's shared render path is the only thing that sets the wrapper id,
 * and it reads `_element_id`. `css_id` is not a control on any element type, so
 * it stores and round-trips perfectly while the rendered element carries no id
 * and hash links have no target (#678).
 *
 * The fix for that lived inside validate_and_fix_elements(), reached from
 * set_elementor_data() and save_elements_to_page() only. These four wrote
 * _elementor_data directly:
 *
 *   - approval rollback   (Mcpwp_Action_Log::apply_snapshot)
 *   - find/replace        (Mcpwp_REST_Elementor::find_replace)
 *   - page clone          (Mcpwp_REST_Pages::clone_page)
 *   - theme builder       (Mcpwp_Theme_Builder, Pro)
 *
 * Rollback is the one that mattered. A snapshot taken before the fix holds
 * `css_id`; restoring it put the broken key back and dropped `_element_id`, so
 * the fix silently un-applied itself and the rollback reported success.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';

final class ElementorElementKeysTest extends TestCase {

	private const PAGE_ID = 8100;

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta']  = array();
	}

	/**
	 * A section carrying the wrong key, with a nested widget carrying it too —
	 * the reported case was a top-level section, which the widget-keyed rename
	 * pass could not reach.
	 */
	private function tree_with_css_id(): array {
		return array(
			array(
				'id'       => 'a26captr',
				'elType'   => 'section',
				'settings' => array( 'css_id' => 'request-your-snapshot' ),
				'elements' => array(
					array(
						'id'       => '7b550aa4',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(
							array(
								'id'         => 'w0000001',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array( 'title' => 'Snapshot', 'css_id' => 'nested-anchor' ),
								'elements'   => array(),
							),
						),
					),
				),
			),
		);
	}

	// ---------------------------------------------------------------------
	// The rule itself.
	// ---------------------------------------------------------------------

	public function test_the_wrong_key_is_renamed_throughout_the_tree(): void {
		$renamed = 0;
		$out     = Mcpwp_Elementor_Element_Keys::normalize_tree( $this->tree_with_css_id(), $renamed );

		$this->assertSame( 2, $renamed, 'Both the section and the nested widget carry it.' );
		$this->assertSame( 'request-your-snapshot', $out[0]['settings']['_element_id'] );
		$this->assertArrayNotHasKey( 'css_id', $out[0]['settings'] );
		$this->assertSame( 'nested-anchor', $out[0]['elements'][0]['elements'][0]['settings']['_element_id'] );
		$this->assertArrayNotHasKey( 'css_id', $out[0]['elements'][0]['elements'][0]['settings'] );
	}

	public function test_an_existing_correct_key_is_never_overwritten(): void {
		$result = Mcpwp_Elementor_Element_Keys::rename_settings(
			array( 'css_id' => 'stray', '_element_id' => 'the-one-that-works' )
		);

		$this->assertSame( 'the-one-that-works', $result['settings']['_element_id'], 'A working id must survive a stray css_id.' );
		$this->assertArrayNotHasKey( 'css_id', $result['settings'], 'The key Elementor ignores is still dropped.' );
		$this->assertSame( array(), $result['renamed'], 'Nothing was renamed — the correct key was already there.' );
	}

	public function test_css_classes_is_deliberately_left_alone(): void {
		// css_classes really IS the control on section/column/container. That
		// asymmetry is what makes css_id a natural mistake, and renaming it too
		// would break every element currently styled correctly.
		$result = Mcpwp_Elementor_Element_Keys::rename_settings( array( 'css_classes' => 'hero dark' ) );

		$this->assertSame( array( 'css_classes' => 'hero dark' ), $result['settings'] );
	}

	public function test_unparseable_data_is_returned_byte_for_byte(): void {
		$renamed = 0;
		$junk    = '{not json at all';

		$this->assertSame( $junk, Mcpwp_Elementor_Element_Keys::normalize_json( $junk, $renamed ) );
		$this->assertSame( 0, $renamed, '"could not look" and "nothing to fix" must not read the same.' );
	}

	public function test_clean_data_is_returned_byte_for_byte(): void {
		$renamed = 0;
		$json    = '[{"id":"a1","elType":"section","settings":{"_element_id":"ok"},"elements":[]}]';

		$this->assertSame(
			$json,
			Mcpwp_Elementor_Element_Keys::normalize_json( $json, $renamed ),
			'Nothing to change means the caller\'s exact bytes are stored — a normaliser must not reformat data as a side effect.'
		);
		$this->assertSame( 0, $renamed );
	}

	// ---------------------------------------------------------------------
	// The four paths that never reach the validator.
	// ---------------------------------------------------------------------

	public function test_rolling_back_a_pre_fix_snapshot_does_not_restore_the_broken_key(): void {
		$snapshot = wp_json_encode( $this->tree_with_css_id() );

		$method = new ReflectionMethod( Mcpwp_Action_Log::class, 'apply_snapshot' );
		$method->setAccessible( true );

		$result = $method->invoke(
			null,
			'elementor',
			array( 'post_id' => self::PAGE_ID, 'elementor_data' => $snapshot )
		);

		$this->assertTrue( $result );

		$restored = json_decode( get_post_meta( self::PAGE_ID, '_elementor_data', true ), true );

		$this->assertSame( 'request-your-snapshot', $restored[0]['settings']['_element_id'] );
		$this->assertArrayNotHasKey(
			'css_id',
			$restored[0]['settings'],
			'A rollback that silently un-applies a shipped fix, and reports success, is the worst shape this can take.'
		);
	}

	public function test_find_replace_on_a_pre_fix_page_does_not_write_the_broken_key_back(): void {
		$GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ] = new WP_Post(
			array(
				'ID'          => self::PAGE_ID,
				'post_type'   => 'page',
				'post_title'  => 'Snapshot page',
				'post_status' => 'publish',
			)
		);
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ] = array(
			'_elementor_data' => wp_json_encode( $this->tree_with_css_id() ),
		);

		$controller = new Mcpwp_REST_Elementor();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/' . self::PAGE_ID . '/find-replace',
			array( 'id' => self::PAGE_ID, 'search' => 'Snapshot', 'replace' => 'Report' )
		);

		$response = $controller->find_replace( $request );
		$this->assertNotInstanceOf( WP_Error::class, $response );

		$saved = json_decode( get_post_meta( self::PAGE_ID, '_elementor_data', true ), true );

		$this->assertSame( 'request-your-snapshot', $saved[0]['settings']['_element_id'] );
		$this->assertArrayNotHasKey( 'css_id', $saved[0]['settings'] );
		$this->assertSame(
			'Report',
			$saved[0]['elements'][0]['elements'][0]['settings']['title'],
			'The replacement itself must still happen — normalising is not allowed to swallow the edit.'
		);
	}

	public function test_cloning_a_pre_fix_page_does_not_carry_the_broken_key(): void {
		$GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ] = new WP_Post(
			array(
				'ID'           => self::PAGE_ID,
				'post_type'    => 'page',
				'post_title'   => 'Snapshot page',
				'post_name'    => 'snapshot-page',
				'post_status'  => 'publish',
				'post_content' => '',
				'post_excerpt' => '',
			)
		);
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ] = array(
			'_elementor_data'      => wp_json_encode( $this->tree_with_css_id() ),
			'_elementor_edit_mode' => 'builder',
		);

		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/pages/' . self::PAGE_ID . '/clone', array( 'id' => self::PAGE_ID ) );
		$response   = $controller->clone_page( $request );

		$this->assertNotInstanceOf( WP_Error::class, $response );

		$data     = $response->get_data();
		$clone_id = (int) ( $data['id'] ?? $data['page']['id'] ?? 0 );
		$this->assertGreaterThan( 0, $clone_id, 'The clone must exist before its data can be asserted on.' );

		$cloned = json_decode( get_post_meta( $clone_id, '_elementor_data', true ), true );

		$this->assertSame( 'request-your-snapshot', $cloned[0]['settings']['_element_id'] );
		$this->assertArrayNotHasKey( 'css_id', $cloned[0]['settings'] );
	}
}
