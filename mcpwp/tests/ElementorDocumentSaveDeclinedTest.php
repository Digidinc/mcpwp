<?php
/**
 * Regression coverage for #861: an Elementor write on a PUBLISHED page
 * reported `document_save: true` (and `save_method:
 * document_save_with_meta_overwrite`) while Elementor's own
 * Document::save() returned bool `false`.
 *
 * Root cause, verified on a real WP 7.0.2 + Elementor 4.2.4 rig with the
 * mcpwp_api_agent role as it existed before #861b (edit_pages +
 * edit_others_pages, deliberately NOT edit_published_pages):
 *
 * #861b granted edit_published_posts/edit_published_pages to the shipped
 * role (owner decision: agent writes to published pages are intended), so
 * this exact scenario no longer occurs for that role. The decline path
 * covered below stays as defense-in-depth — a custom/legacy role, or a site
 * mid-migration before Mcpwp_Activator::grant_agent_published_page_caps()
 * has run, can still hit it — exercised here via a double that forces
 * Document::save() to decline regardless of the real capability set.
 *
 *   current_user_can( 'edit_post', $published_page_id ) === false
 *   \Elementor\Core\Base\Document::is_editable_by_current_user() === false
 *   $document->save( $data ) === false   // strict bool, no exception, no WP_Error
 *   wp_get_post_revisions( $published_page_id ) === []  // pipeline never ran
 *
 * attempt_elementor_call() only recognised a thrown Throwable and a returned
 * WP_Error as failure (see ElementorSaveThrowableTest for that coverage). A
 * bare `false` fell through both checks, came back `ok => true`, and every
 * call site read "attempt ok, not a WP_Error" as "Document::save() worked" —
 * so it force-overwrote _elementor_data via raw meta (which always succeeds,
 * update_post_meta() does not check capabilities) and reported
 * `document_save: true` / `save_method: document_save_with_meta_overwrite`,
 * a receipt for a save pipeline (revisions, `after_save` hooks, Pro features)
 * that never ran.
 *
 * These tests assert the fixed contract: a declined save is recorded
 * (`<label>_declined`), `document_save` is explicitly `false` (never merely
 * absent), `save_method` falls back to `meta_direct` (never
 * `document_save_with_meta_overwrite`), `render_verified` is `false` with an
 * actionable `render_errors` entry — and the write still lands, because the
 * raw-meta fallback is what actually persisted the data both before and
 * after this fix. Truthfulness is the fix here, not availability.
 *
 * Each test runs in a separate process because the fixture declares
 * \Elementor\Plugin and ELEMENTOR_VERSION — see ElementorSaveThrowableTest
 * for why (#757).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorDocumentSaveDeclinedTest extends TestCase {

	private const PAGE_ID = 4343;

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta']  = array();
	}

	/**
	 * Install the declining Elementor doubles for one test.
	 *
	 * @param string $decline_on 'all'     decline every save() call — the
	 *                                     real #861 shape (same user, same
	 *                                     published page, every call in the
	 *                                     request fails identically);
	 *                           'rebuild' let the first save succeed and
	 *                                     decline only the HTML-cache
	 *                                     rebuild save;
	 *                           'never'   both saves succeed (control).
	 */
	private function install_elementor( string $decline_on ): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		$GLOBALS['mcpwp_test_save_calls']  = 0;
		$GLOBALS['mcpwp_test_decline_on']  = $decline_on;

		require_once __DIR__ . '/fixtures/elementor-declined-save-doubles.php';

		\Elementor\Plugin::$instance = new \Elementor\Plugin();
	}

	/**
	 * A $wpdb double that reflects what the meta stub actually holds — see
	 * ElementorSaveThrowableTest for why this matters: the bootstrap double
	 * otherwise always reports a failed persist, which would hide the exact
	 * question these tests ask.
	 */
	private function install_reflecting_wpdb(): void {
		$GLOBALS['wpdb'] = new class() extends Mcpwp_Test_WPDB {
			public function get_var( $query = '', $x = 0, $y = 0 ) {
				if ( false !== strpos( (string) $query, '_elementor_data' ) ) {
					return $GLOBALS['_mcpwp_test_meta'][ ElementorDocumentSaveDeclinedTest::page_id() ]['_elementor_data'] ?? null;
				}
				return null;
			}
		};
	}

	public static function page_id(): int {
		return self::PAGE_ID;
	}

	/**
	 * A PUBLISHED post — the exact condition #861 requires. The throwable
	 * fixture's page is a draft; this one must be publish, or the fix would
	 * pass on a fixture that could never reproduce the bug.
	 */
	private function seed_published_page(): void {
		$GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ] = (object) array(
			'ID'          => self::PAGE_ID,
			'post_type'   => 'page',
			'post_title'  => 'Declined-save fixture',
			'post_status' => 'publish',
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
	 * The #861 scenario itself: full-set save on a published page,
	 * Document::save() declines. The write must still land via the raw-meta
	 * fallback — declining is not the same failure mode as throwing or
	 * erroring, but it must degrade the same way.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_full_set_save_survives_a_declined_document_save(): void {
		$this->install_elementor( 'all' );
		$this->install_reflecting_wpdb();
		$this->seed_published_page();

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->minimal_section() ) ) );

		$this->assertNotInstanceOf(
			WP_Error::class,
			$result,
			'A declined Document::save() must degrade to the raw-meta fallback, not fail the request.'
		);

		$stored = $this->stored_elements();
		$this->assertIsArray( $stored, 'The payload must still reach _elementor_data via the fallback.' );
		$this->assertSame( 'sec00001', $stored[0]['id'] );
		$this->assertSame(
			'Canary',
			$stored[0]['elements'][0]['elements'][0]['settings']['title'],
			'The whole tree must persist, not just the top-level shape.'
		);
	}

	/**
	 * The truthfulness bug itself: a declined save must be reported as
	 * `document_save: false` with a `document_save_declined` reason, and
	 * `save_method` must NOT claim the Document API pipeline ran.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_declined_save_is_reported_as_false_not_success(): void {
		$this->install_elementor( 'all' );
		$this->install_reflecting_wpdb();
		$this->seed_published_page();

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->minimal_section() ) ) );

		$this->assertIsArray( $result );

		$this->assertArrayHasKey(
			'document_save',
			$result['debug'],
			'document_save must be explicitly reported, not omitted, on a declined save.'
		);
		$this->assertFalse(
			$result['debug']['document_save'],
			'A declined Document::save() must never be reported as document_save:true.'
		);
		$this->assertTrue(
			$result['debug']['document_save_declined'] ?? false,
			'The decline itself must be named, distinctly from an exception or a WP_Error.'
		);
		$this->assertSame(
			'meta_direct',
			$result['save_method'],
			'A declined Document::save() ran no pipeline; save_method must say so, not document_save_with_meta_overwrite.'
		);

		$flat = wp_json_encode( $result );
		$this->assertStringNotContainsString(
			'"document_save":true',
			$flat,
			'The exact defect from #861: a declined save must not read as a successful one anywhere in the response.'
		);
	}

	/**
	 * document_save:false must propagate into the render-verification layer:
	 * a page whose only "save" was our own meta overwrite has not been
	 * through Elementor's render pipeline, and render_verified must say so.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_declined_save_fails_render_verification_with_an_actionable_reason(): void {
		$this->install_elementor( 'all' );
		$this->install_reflecting_wpdb();
		$this->seed_published_page();

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->minimal_section() ) ) );

		$this->assertFalse(
			$result['render_verified'],
			'A page whose save pipeline never ran must not be reported as render_verified:true.'
		);
		$this->assertNotEmpty( $result['render_errors'] ?? array() );

		$joined = implode( ' ', $result['render_errors'] );
		$this->assertStringContainsString(
			'declined',
			$joined,
			'The render error must say Elementor declined the save, not just that verification failed.'
		);
	}

	/**
	 * The HTML-cache rebuild save runs a second Document::save() call after
	 * the data is already persisted. A decline there must be reported too —
	 * html_cache_rebuilt must not read true when Elementor declined it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_declined_html_cache_rebuild_is_reported_and_data_survives(): void {
		$this->install_elementor( 'rebuild' );
		$this->install_reflecting_wpdb();
		$this->seed_published_page();

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->minimal_section() ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );

		// The first save succeeded, so this path IS the genuine
		// document_save_with_meta_overwrite success — only the rebuild
		// declined.
		$this->assertTrue( $result['debug']['document_save'] ?? false );

		$stored = $this->stored_elements();
		$this->assertIsArray( $stored, 'Data persisted by the first save must survive a rebuild that declines.' );
		$this->assertSame( 'sec00001', $stored[0]['id'] );

		$this->assertTrue(
			$result['debug']['html_cache_rebuild_declined'] ?? false,
			'The rebuild decline must be named.'
		);
		$this->assertFalse(
			$result['debug']['html_cache_rebuilt'] ?? true,
			'A declined rebuild must not be reported as html_cache_rebuilt:true.'
		);
	}

	/**
	 * save_elements_to_page() is the shared helper behind six surgical
	 * operations (add/remove/replace/patch/edit/strip) and carries its own
	 * copy of the same call — it needs its own coverage, exactly as
	 * ElementorSaveThrowableTest gives the throwable case its own coverage.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_surgical_add_section_reports_a_declined_save_and_still_persists(): void {
		$this->install_elementor( 'all' );
		$this->install_reflecting_wpdb();
		$this->seed_published_page();

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

		$this->assertFalse(
			$result['debug']['document_save'] ?? true,
			'The surgical path must report document_save:false on a decline, exactly like the full-set path.'
		);
		$this->assertSame( 'meta_direct', $result['debug']['save_method'] ?? null );
		$this->assertFalse( $result['render_verified'] );
	}

	/**
	 * Control: when Elementor does NOT decline, behaviour must be unchanged —
	 * this fix must not turn a genuine success into a false failure.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_successful_save_is_unaffected_by_the_decline_check(): void {
		$this->install_elementor( 'never' );
		$this->install_reflecting_wpdb();
		$this->seed_published_page();

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->minimal_section() ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $result['debug']['document_save'] ?? false );
		$this->assertArrayNotHasKey( 'document_save_declined', $result['debug'] );
		$this->assertSame( 'document_save_with_meta_overwrite', $result['save_method'] );
		$this->assertTrue( $result['render_verified'] );
	}
}
