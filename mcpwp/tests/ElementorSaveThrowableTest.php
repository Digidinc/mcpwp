<?php
/**
 * Regression coverage: an Elementor Document::save() that THROWS must not
 * abort the MCPWP save path.
 *
 * Reported from staging (2026-09-05): MCPWP 3.10.3, WP 7.1, PHP 8.4.25,
 * Elementor 4.2.4 + Pro 4.2.3. wp_set_elementor dry-run accepted a minimal
 * native payload with zero warnings; the real save returned HTTP 500 and
 * readback stayed empty. Elementor's own browser "Save Draft" persisted fine
 * on the same post, so WordPress and Elementor persistence were healthy — only
 * the MCPWP write path was broken.
 *
 * That difference is the diagnosis. Dry-run never enters Elementor; the real
 * save does. Every Document::save() call in the writer trait handled a
 * RETURNED WP_Error and nothing else, so a Throwable raised inside Elementor's
 * own save — a TypeError against a changed 4.x signature, an Error from a
 * removed class — propagated out of the request. The raw-meta fallback that
 * exists precisely for "Document::save() did not work" sits AFTER that call,
 * so it never ran and nothing was written.
 *
 * The real one is now known. Reproduced 2026-09-05 on PHP 8.4.25 with real
 * Elementor 4.2.4: its content-sanitizer module hooks
 * elementor/document/save/data and so runs inside Document::save(), and its
 * is_target_widget() reads $element['widgetType'] unguarded
 * (modules/content-sanitizer/module.php:45) across every element — sections,
 * columns and containers have none. The sanitizer early-returns for anyone
 * with manage_options, so a browser admin never hits it and an API-key
 * request always does. Unpatched, the throw escaped at
 * trait-mcpwp-elementor-writer.php:384; patched, the save fell back to
 * meta_direct and the data persisted.
 *
 * These tests still do not pin that specific Throwable, because the upstream
 * detail may change between Elementor releases. They assert the property that
 * must hold for any of them: a throwing
 * Document::save() degrades to the verified raw-meta path instead of losing
 * the write, and the degradation is reported rather than swallowed.
 *
 * Each test runs in a separate process because the fixture declares
 * \Elementor\Plugin and ELEMENTOR_VERSION. A constant cannot be undefined once
 * set and this suite runs in one process, so declaring them inline would
 * poison every later test (see #757).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorSaveThrowableTest extends TestCase {

	private const PAGE_ID = 4242;

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta']  = array();
		$GLOBALS['mcpwp_test_corrupt_rebuild'] = false;
		$GLOBALS['mcpwp_test_mutate_rebuild'] = false;
		$GLOBALS['mcpwp_test_corrupt_page_id'] = self::PAGE_ID;
	}

	/**
	 * Install the Elementor doubles for one test.
	 *
	 * @param string $throw_on 'first'   throw from the preferred save;
	 *                         'rebuild' let the first save succeed and throw
	 *                                   from the HTML-cache rebuild save.
	 */
	private function install_elementor( string $throw_on ): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		$GLOBALS['mcpwp_test_save_calls'] = 0;
		$GLOBALS['mcpwp_test_throw_on']   = $throw_on;

		require_once __DIR__ . '/fixtures/elementor-throwable-doubles.php';

		\Elementor\Plugin::$instance = new \Elementor\Plugin();
	}

	/**
	 * A $wpdb double that reflects what the meta stub actually holds.
	 *
	 * The bootstrap double returns null from get_var() unconditionally, which
	 * would make the writer's raw-DB verification report a failed persist even
	 * on a healthy save — and then these tests could not tell "the fallback
	 * wrote the data" from "nothing was written", which is the whole question.
	 */
	private function install_reflecting_wpdb(): void {
		$GLOBALS['wpdb'] = new class() extends Mcpwp_Test_WPDB {
			public function get_var( $query = '', $x = 0, $y = 0 ) {
				if ( false !== strpos( (string) $query, '_elementor_data' ) ) {
					return $GLOBALS['_mcpwp_test_meta'][ ElementorSaveThrowableTest::page_id() ]['_elementor_data'] ?? null;
				}
				return null;
			}
		};
	}

	public static function page_id(): int {
		return self::PAGE_ID;
	}

	private function seed_page(): void {
		$GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ] = (object) array(
			'ID'          => self::PAGE_ID,
			'post_type'   => 'page',
			'post_title'  => 'Throwable fixture',
			'post_status' => 'draft',
		);
	}

	private function minimal_section(): array {
		return array(
			array(
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
								'settings'   => array( 'title' => 'Canary' ),
							),
						),
					),
				),
			),
		);
	}

	private function stored_elements(): ?array {
		$raw = $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] ?? null;
		if ( null === $raw ) {
			return null;
		}
		return json_decode( stripslashes( (string) $raw ), true );
	}

	/**
	 * The reported staging failure: full-set save, Document::save() throws.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_full_set_save_survives_a_throwable_from_document_save(): void {
		$this->install_elementor( 'first' );
		$this->install_reflecting_wpdb();
		$this->seed_page();

		$basic = new Mcpwp_Elementor_Basic();

		// Before the fix this call does not return at all — the TypeError
		// propagates and WordPress turns it into the HTTP 500 seen on staging.
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->minimal_section() ) ) );

		$this->assertNotInstanceOf(
			WP_Error::class,
			$result,
			'A throwing Document::save() must degrade to the raw-meta fallback, not fail the request.'
		);

		$stored = $this->stored_elements();
		$this->assertIsArray( $stored, 'The payload must still reach _elementor_data via the fallback.' );
		$this->assertCount( 1, $stored );
		$this->assertSame( 'sec00001', $stored[0]['id'] );
		$this->assertSame(
			'Canary',
			$stored[0]['elements'][0]['elements'][0]['settings']['title'],
			'The whole tree must persist, not just the top-level shape.'
		);
	}

	/**
	 * A caught Throwable must be REPORTED. An Elementor API that cannot be
	 * called and an Elementor API that worked must never produce the same
	 * response — that is the defect this whole class of bug comes from.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_caught_throwable_is_reported_not_silently_swallowed(): void {
		$this->install_elementor( 'first' );
		$this->install_reflecting_wpdb();
		$this->seed_page();

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->minimal_section() ) ) );

		$this->assertIsArray( $result );
		$flat = wp_json_encode( $result );

		$this->assertStringContainsString(
			'document_save_exception',
			$flat,
			'The response must record that the preferred save path threw.'
		);
		$this->assertStringContainsString(
			'TypeError',
			$flat,
			'The recorded failure must name the throwable class, or the report is not actionable.'
		);
		$this->assertStringNotContainsString(
			'"document_save":true',
			$flat,
			'A save that threw must not be reported as a successful Document::save().'
		);
	}

	/**
	 * The HTML-cache rebuild save runs AFTER the data is already persisted.
	 * A throw there must not 500 the request or lose the verified bytes — it
	 * only means the rendering cache was not rebuilt.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_throwable_in_html_cache_rebuild_keeps_already_persisted_data(): void {
		$this->install_elementor( 'rebuild' );
		$this->install_reflecting_wpdb();
		$this->seed_page();

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->minimal_section() ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$stored = $this->stored_elements();
		$this->assertIsArray( $stored, 'Data persisted before the rebuild must survive a rebuild that throws.' );
		$this->assertSame( 'sec00001', $stored[0]['id'] );

		$flat = wp_json_encode( $result );
		$this->assertStringContainsString(
			'html_cache_rebuild_exception',
			$flat,
			'A rebuild that threw must be reported, not reported as rebuilt.'
		);
	}

	/**
	 * save_elements_to_page() is the shared helper behind six surgical
	 * operations (add/remove/replace/patch/edit/strip). It carries its own
	 * copy of the same unguarded pattern, so it needs its own coverage.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_surgical_add_section_survives_a_throwable_from_document_save(): void {
		$this->install_elementor( 'first' );
		$this->install_reflecting_wpdb();
		$this->seed_page();

		// Start from an existing single-section page.
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] =
			wp_json_encode( $this->minimal_section() );

		$basic = new Mcpwp_Elementor_Basic();

		$result = $basic->add_section(
			self::PAGE_ID,
			array(
				'element' => array(
					'id'       => 'sec00002',
					'elType'   => 'section',
					'settings' => array(),
					'elements' => array(
						array(
							'id'       => 'col00002',
							'elType'   => 'column',
							'settings' => array( '_column_size' => 100 ),
							'elements' => array(),
						),
					),
				),
			)
		);

		$this->assertNotInstanceOf(
			WP_Error::class,
			$result,
			'The surgical path shares the defect and must share the fix.'
		);

		$stored = $this->stored_elements();
		$this->assertIsArray( $stored );
		$this->assertCount( 2, $stored, 'The appended section must persist through the fallback.' );
		$this->assertSame( 'sec00002', $stored[1]['id'] );
	}

	/**
	 * A successful surgical rebuild can still truncate nested elements.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_surgical_rebuild_restores_a_silently_truncated_tree_and_fails_render_verification(): void {
		$this->install_elementor( 'never' );
		$this->install_reflecting_wpdb();
		$this->seed_page();
		$GLOBALS['mcpwp_test_corrupt_rebuild'] = true;

		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = wp_json_encode( $this->minimal_section() );

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->add_section(
			self::PAGE_ID,
			array(
				'element' => array(
					'id'       => 'sec00002',
					'elType'   => 'section',
					'settings' => array(),
					'elements' => array(),
				),
			)
		);

		$this->assertTrue( $result['debug']['html_rebuild_corrupted_data'] ?? false, 'The recursive guard must detect the nested-element loss.' );
		$this->assertFalse( $result['render_verified'], 'The restored tree has not been rendered.' );
		$stored = $this->stored_elements();
		$this->assertSame( 'wid00001', $stored[0]['elements'][0]['elements'][0]['id'], 'The exact submitted tree must be restored.' );
	}

	/**
	 * Recursive count equality must not hide same-size tree mutations.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_surgical_rebuild_restores_a_same_count_id_mutation(): void {
		$this->install_elementor( 'never' );
		$this->install_reflecting_wpdb();
		$this->seed_page();
		$GLOBALS['mcpwp_test_mutate_rebuild'] = true;

		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = wp_json_encode( $this->minimal_section() );

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->add_section(
			self::PAGE_ID,
			array(
				'element' => array(
					'id'       => 'sec00002',
					'elType'   => 'section',
					'settings' => array(),
					'elements' => array(),
				),
			)
		);

		$this->assertTrue( $result['debug']['html_rebuild_corrupted_data'] ?? false, 'An ID mutation with the same recursive count must fail integrity verification.' );
		$this->assertFalse( $result['render_verified'] );
		$this->assertSame(
			array(
				'path'                 => '$[0].elements[0].elements[0].id',
				'key'                  => 'id',
				'difference'           => 'value_mismatch',
				'expected_type'        => 'string',
				'actual_type'          => 'string',
				'expected_value_class' => 'non_empty_string',
				'actual_value_class'   => 'non_empty_string',
			),
			$result['debug']['html_rebuild_first_mismatch'] ?? null,
			'The diagnostic must locate and classify the first mismatch without including either value.'
		);
		$this->assertStringNotContainsString( 'wid00001', wp_json_encode( $result['debug']['html_rebuild_first_mismatch'] ?? array() ) );
		$this->assertStringNotContainsString( 'changed1', wp_json_encode( $result['debug']['html_rebuild_first_mismatch'] ?? array() ) );
		$stored = $this->stored_elements();
		$this->assertSame( 'wid00001', $stored[0]['elements'][0]['elements'][0]['id'] );
	}
}
