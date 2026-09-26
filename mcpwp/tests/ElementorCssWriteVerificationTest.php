<?php
/**
 * Regression coverage for #901: wp_set_elementor reported
 * success/css_regenerated/render_verified all `true` while
 * uploads/elementor/css was not writable, so the page's CSS file was never
 * written and the page rendered unstyled.
 *
 * Root cause: the writer trait's closures around
 * \Elementor\Core\Files\CSS\Post::create( $page_id )->update() hardcode
 * `return true;` after calling update(). Elementor's own CSS_File::update()
 * swallows a filesystem write failure (unwritable directory, full disk,
 * permissions) with no exception and no WP_Error, so attempt_elementor_call()
 * saw "no exception" and reported ok:true regardless of whether anything
 * actually landed on disk. css_regenerated:true then fed straight into
 * decorate_render_status(), so render_verified:true described a page whose
 * CSS file did not exist.
 *
 * Also covers the adversarial review (2026-09-26) against the first fix,
 * which found:
 *
 * BLOCK — a naive "file must exist and be non-empty" check produces FALSE
 * failures on healthy pages: Elementor's own Base::update() deliberately
 * deletes a post's CSS file and records `_elementor_css.status = 'empty'`
 * whenever there is nothing to print (a cleared page, a widget with no
 * styled/global-default controls, an Elementor 4 atomic-only page). The fix
 * reads that per-page verdict — 'file' | 'inline' | 'empty' — instead of
 * inferring intent from a byte count.
 *
 * WARN — a non-empty file on disk is not proof it was written by THIS call:
 * on a directory that goes unwritable after a page already had CSS, the
 * write silently no-ops and the OLD file survives, passing a byte-count-only
 * check. The fix compares the file's mtime against a timestamp captured
 * immediately before the regeneration call.
 *
 * verify_css_file_was_written() closes both gaps: css_regenerated is only
 * downgraded when the per-page status is 'file' (or absent, with the
 * site-wide print method not 'internal') AND the file is missing, empty, or
 * older than the regeneration call that supposedly (re)wrote it.
 *
 * Each test runs in a separate process because the fixture declares
 * \Elementor\Plugin and \Elementor\Core\Files\CSS\Post — see
 * ElementorSaveThrowableTest for why (#757).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorCssWriteVerificationTest extends TestCase {

	private const PAGE_ID = 5151;

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts']  = array();
		$GLOBALS['_mcpwp_test_meta']   = array();
		$GLOBALS['mcpwp_test_options'] = array();

		$this->clean_css_fixture_dir();
	}

	protected function tearDown(): void {
		$this->clean_css_fixture_dir();
	}

	/**
	 * The fixture writes REAL files to the real (temp-dir) uploads path
	 * wp_upload_dir() resolves to in tests, and every test in this file
	 * shares the same PAGE_ID. Unlike $GLOBALS, that file survives across
	 * @runInSeparateProcess test processes — each is a fresh PHP process but
	 * the same disk — so a file one test wrote would otherwise leak into
	 * whichever test runs next.
	 */
	private function clean_css_fixture_dir(): void {
		$path = sys_get_temp_dir() . '/mcpwp-test-uploads/elementor/css/post-' . self::PAGE_ID . '.css';
		if ( file_exists( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * @param string $scenario See the fixture's file docblock for the full
	 *                         list ('healthy', 'unwritable_first',
	 *                         'unwritable_after_existing', 'empty', 'inline',
	 *                         'no_meta').
	 */
	private function install_elementor( string $scenario ): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		$GLOBALS['mcpwp_test_css_scenario']     = $scenario;
		$GLOBALS['mcpwp_test_css_save_calls']   = 0;
		$GLOBALS['mcpwp_test_css_update_calls'] = 0;

		require_once __DIR__ . '/fixtures/elementor-css-write-doubles.php';

		\Elementor\Plugin::$instance = new \Elementor\Plugin();
	}

	/**
	 * A $wpdb double that reflects what the meta stub actually holds — same
	 * pattern as ElementorDocumentSaveDeclinedTest, needed because the
	 * bootstrap double otherwise always reports a failed persist.
	 */
	private function install_reflecting_wpdb(): void {
		$GLOBALS['wpdb'] = new class() extends Mcpwp_Test_WPDB {
			public function get_var( $query = '', $x = 0, $y = 0 ) {
				if ( false !== strpos( (string) $query, '_elementor_data' ) ) {
					return $GLOBALS['_mcpwp_test_meta'][ ElementorCssWriteVerificationTest::page_id() ]['_elementor_data'] ?? null;
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
			'post_title'  => 'CSS-write fixture',
			'post_status' => 'publish',
		);
	}

	/**
	 * Seeds an existing, non-empty CSS file on disk with an mtime well
	 * before "now" — the pre-condition for the WARN scenario: a page that
	 * already had CSS before the uploads directory went unwritable.
	 *
	 * @param int $age_seconds How far in the past to backdate the file's mtime.
	 */
	private function seed_existing_css_file( int $age_seconds = 3600 ): void {
		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . '/elementor/css';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		$path = $dir . '/post-' . self::PAGE_ID . '.css';
		file_put_contents( $path, '.mcpwp-test{color:blue}/* pre-existing */' );
		touch( $path, time() - $age_seconds );
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

	/**
	 * Rig proof, in miniature, case (a): an unwritable uploads/elementor/css
	 * on a page's FIRST write must turn a would-be success into an honest
	 * failure. Before the fix this asserted true; after the fix it asserts
	 * false with an actionable hint.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_unwritable_dir_on_first_write_fails_render_verification_with_a_hint(): void {
		$this->install_elementor( 'unwritable_first' );
		$this->install_reflecting_wpdb();
		$this->seed_page();

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->minimal_section() ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 1, $GLOBALS['mcpwp_test_css_update_calls'] );

		$this->assertFalse(
			$result['css_regenerated'],
			'A CSS file that was never written to disk, with Elementor\'s own meta reporting status "file", must not be reported as css_regenerated:true.'
		);
		$this->assertTrue( $result['debug']['css_file_not_written'] ?? false );
		$this->assertArrayNotHasKey( 'css_file_stale', $result['debug'] );
		$this->assertSame( 0, $result['debug']['css_file_size'] ?? null );

		$this->assertFalse( $result['render_verified'] );
		$joined = implode( ' ', $result['render_errors'] ?? array() );
		$this->assertStringContainsString( 'uploads/elementor/css', $joined );
		$this->assertStringContainsString( 'writable', $joined );

		// The data itself must still have landed — a CSS failure is a
		// rendering problem, not a data-loss one.
		$raw    = $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] ?? null;
		$stored = is_string( $raw ) ? json_decode( stripslashes( $raw ), true ) : null;
		$this->assertIsArray( $stored );
		$this->assertSame( 'sec00001', $stored[0]['id'] );
	}

	/**
	 * Rig proof case (b) / RC review WARN: an uploads directory that goes
	 * unwritable AFTER a page already had CSS must not have the OLD file
	 * read as proof of a fresh write. Before this fix (the naive
	 * exists-and-non-empty check) this passed; after it, the stale file is
	 * caught by its mtime and reported as a failure with its own hint.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_unwritable_dir_after_existing_css_is_caught_by_staleness(): void {
		$this->install_elementor( 'unwritable_after_existing' );
		$this->install_reflecting_wpdb();
		$this->seed_page();
		$this->seed_existing_css_file( 3600 );

		$before_size = filesize( sys_get_temp_dir() . '/mcpwp-test-uploads/elementor/css/post-' . self::PAGE_ID . '.css' );
		$this->assertGreaterThan( 0, $before_size, 'The pre-existing file must actually be there for this test to mean anything.' );

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->minimal_section() ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );

		// The stale file is untouched — non-empty, but that is exactly the
		// trap: it must not be read as evidence of a fresh write.
		$this->assertGreaterThan( 0, $result['debug']['css_file_size'] ?? 0 );

		$this->assertFalse(
			$result['css_regenerated'],
			'A CSS file that predates this regeneration call must not be reported as css_regenerated:true just because it happens to be non-empty.'
		);
		$this->assertTrue( $result['debug']['css_file_not_written'] ?? false );
		$this->assertTrue( $result['debug']['css_file_stale'] ?? false );

		$this->assertFalse( $result['render_verified'] );
		$joined = implode( ' ', $result['render_errors'] ?? array() );
		$this->assertStringContainsString( 'not updated', $joined );
		$this->assertStringContainsString( 'writable', $joined );
	}

	/**
	 * Rig proof case (c) / RC review BLOCK: an Elementor 4 atomic-only page
	 * — modelled here directly as Elementor's own verdict, `_elementor_css`
	 * status 'empty' (the legacy Post CSS renderer has only legacy-control
	 * rules to emit for an atomic-only tree, so it deletes any file and
	 * records 'empty', exactly as it would for a genuinely empty page) —
	 * must NOT be flagged. No file on disk here is correct, not a failure.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_atomic_only_page_with_empty_status_passes(): void {
		$this->install_elementor( 'empty' );
		$this->install_reflecting_wpdb();
		$this->seed_page();

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->minimal_section() ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 1, $GLOBALS['mcpwp_test_css_update_calls'] );

		$this->assertTrue(
			$result['css_regenerated'],
			'Elementor\'s own "empty" verdict (nothing to print — an atomic-only tree, a cleared page, an unstyled widget) must not be reported as a regeneration failure.'
		);
		$this->assertArrayNotHasKey( 'css_file_not_written', $result['debug'] );
		$this->assertSame( 0, $result['debug']['css_file_size'] ?? null );
		$this->assertTrue( $result['render_verified'] );
	}

	/**
	 * Rig proof case (d): an explicitly cleared page is the same 'empty'
	 * verdict as the atomic-only case above — covered by the same code path,
	 * asserted separately because it is the scenario named in the review's
	 * re-run matrix.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cleared_page_with_empty_status_passes(): void {
		$this->install_elementor( 'empty' );
		$this->install_reflecting_wpdb();
		$this->seed_page();

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => array() ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $result['css_regenerated'] );
		$this->assertArrayNotHasKey( 'css_file_not_written', $result['debug'] );
		$this->assertTrue( $result['render_verified'] );
	}

	/**
	 * The 'inline' verdict — read directly from the per-page meta, not
	 * inferred from the site-wide option — must pass exactly like 'empty'.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_inline_status_passes_without_a_css_file(): void {
		$this->install_elementor( 'inline' );
		$this->install_reflecting_wpdb();
		$this->seed_page();

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->minimal_section() ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $result['css_regenerated'] );
		$this->assertArrayNotHasKey( 'css_file_not_written', $result['debug'] );
		$this->assertTrue( $result['render_verified'] );
	}

	/**
	 * Fallback path: no per-page meta status at all (older Elementor, or a
	 * config that never wrote it) but the site-wide print method is
	 * 'internal'. Must still pass — this is the pre-BLOCK-fix behaviour,
	 * kept as the fallback when there is no per-page verdict to read.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_internal_print_method_is_verified_without_a_css_file(): void {
		$this->install_elementor( 'no_meta' );
		$this->install_reflecting_wpdb();
		$this->seed_page();

		$GLOBALS['mcpwp_test_options']['elementor_css_print_method'] = 'internal';

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->minimal_section() ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$this->assertTrue(
			$result['css_regenerated'],
			'The \'internal\' print method never writes a per-post CSS file by design; a missing file must not be reported as a regeneration failure.'
		);
		$this->assertArrayNotHasKey( 'css_file_not_written', $result['debug'] );
		$this->assertTrue( $result['render_verified'] );
	}

	/**
	 * Rig proof case (e): a normal, writable-directory save must be
	 * unaffected by any of the above — this fix must not turn a genuine
	 * success into a false failure.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_written_css_file_passes_render_verification(): void {
		$this->install_elementor( 'healthy' );
		$this->install_reflecting_wpdb();
		$this->seed_page();

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->minimal_section() ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 1, $GLOBALS['mcpwp_test_css_update_calls'] );

		$this->assertTrue( $result['css_regenerated'] );
		$this->assertArrayNotHasKey( 'css_file_not_written', $result['debug'] );
		$this->assertGreaterThan( 0, $result['debug']['css_file_size'] ?? 0 );
		$this->assertTrue( $result['render_verified'] );
	}

	/**
	 * save_elements_to_page() — the shared helper behind the surgical
	 * operations (add/remove/replace/patch/edit/strip) — carries its own copy
	 * of the CSS-regeneration call and needs its own coverage for both the
	 * failure and the BLOCK exemption, exactly as ElementorDocumentSaveDeclinedTest
	 * gives the surgical path its own coverage for the #861 decline case.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_surgical_add_section_fails_render_verification_on_an_unwritten_css_file(): void {
		$this->install_elementor( 'unwritable_first' );
		$this->install_reflecting_wpdb();
		$this->seed_page();

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

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertFalse( $result['render_verified'] );

		$joined = implode( ' ', $result['render_errors'] ?? array() );
		$this->assertStringContainsString( 'uploads/elementor/css', $joined );
	}

	/**
	 * save_elements_to_page()'s own BLOCK exemption: an atomic-only /
	 * cleared-page 'empty' verdict must pass through the surgical path too.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_surgical_add_section_passes_on_an_empty_status(): void {
		$this->install_elementor( 'empty' );
		$this->install_reflecting_wpdb();
		$this->seed_page();

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

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $result['render_verified'] );
		$this->assertArrayNotHasKey( 'css_file_not_written', $result['debug'] ?? array() );
	}
}
