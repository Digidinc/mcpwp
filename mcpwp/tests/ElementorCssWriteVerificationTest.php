<?php
/**
 * Regression coverage for #901: wp_set_elementor reported
 * success/css_regenerated/render_verified all `true` while
 * uploads/elementor/css was not writable, so the page's CSS file was never
 * written and the page rendered unstyled.
 *
 * Root cause: the writer trait's closures around
 * \Elementor\Core\Files\CSS\Post::create( $page_id )->update() hardcode
 * `return true;` after calling update() — see the plain `return true;` after
 * `$css_file->update();` in both set_elementor_data() and
 * save_elements_to_page(). Elementor's own CSS_File::update() swallows a
 * filesystem write failure (unwritable directory, full disk, permissions)
 * with no exception and no WP_Error, so attempt_elementor_call() saw
 * "no exception" and reported ok:true regardless of whether anything actually
 * landed on disk. css_regenerated:true then fed straight into
 * decorate_render_status(), so render_verified:true described a page whose
 * CSS file did not exist.
 *
 * verify_css_file_was_written() closes the gap: css_regenerated is only true
 * when the post's CSS file exists on disk and is non-empty, or Elementor is
 * configured to print CSS inline ('internal' — no file is ever written by
 * design, so none is expected).
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
		$GLOBALS['_mcpwp_test_posts']   = array();
		$GLOBALS['_mcpwp_test_meta']    = array();
		$GLOBALS['mcpwp_test_options']  = array();

		$this->clean_css_fixture_dir();
	}

	protected function tearDown(): void {
		$this->clean_css_fixture_dir();
	}

	/**
	 * The write-succeeds fixture writes a REAL file to the real (temp-dir)
	 * uploads path wp_upload_dir() resolves to in tests, and every test in
	 * this file shares the same PAGE_ID. Unlike $GLOBALS, that file survives
	 * across @runInSeparateProcess test processes — each is a fresh PHP
	 * process but the same disk — so a file one test wrote would otherwise
	 * leak into whichever test runs next and silently satisfy the "file
	 * exists and is non-empty" check for a fixture that itself never wrote
	 * anything this run.
	 */
	private function clean_css_fixture_dir(): void {
		$path = sys_get_temp_dir() . '/mcpwp-test-uploads/elementor/css/post-' . self::PAGE_ID . '.css';
		if ( file_exists( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * @param bool $write_succeeds Whether the CSS\Post double actually writes
	 *                             a file to disk (healthy uploads dir) or
	 *                             silently no-ops (the unwritable-directory
	 *                             shape #901 reported).
	 */
	private function install_elementor( bool $write_succeeds ): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		$GLOBALS['mcpwp_test_css_write_succeeds'] = $write_succeeds;
		$GLOBALS['mcpwp_test_css_save_calls']     = 0;
		$GLOBALS['mcpwp_test_css_update_calls']   = 0;

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
	 * Rig proof, in miniature: an unwritable uploads/elementor/css must turn
	 * a would-be success into an honest failure. Before the fix this asserted
	 * true; after the fix it asserts false with an actionable hint.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unwritten_css_file_fails_render_verification_with_a_hint(): void {
		$this->install_elementor( false );
		$this->install_reflecting_wpdb();
		$this->seed_page();

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( $this->minimal_section() ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );

		// The CSS regeneration call itself must actually have run for this
		// test to mean anything — the fixture, not a skipped branch, produced
		// the empty file.
		$this->assertSame( 1, $GLOBALS['mcpwp_test_css_update_calls'] );

		$this->assertFalse(
			$result['css_regenerated'],
			'A CSS file that was never written to disk must not be reported as css_regenerated:true, even though Elementor\'s own update() call threw nothing.'
		);
		$this->assertTrue(
			$result['debug']['css_file_not_written'] ?? false,
			'The specific failure must be named, distinctly from a thrown exception or WP_Error.'
		);
		$this->assertSame( 0, $result['debug']['css_file_size'] ?? null );

		$this->assertFalse(
			$result['render_verified'],
			'A page whose CSS file does not exist has not actually rendered styled — render_verified must say so.'
		);
		$this->assertNotEmpty( $result['render_errors'] ?? array() );

		$joined = implode( ' ', $result['render_errors'] );
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
	 * Control: a healthy, writable uploads directory must be unaffected —
	 * this fix must not turn a genuine success into a false failure.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_written_css_file_passes_render_verification(): void {
		$this->install_elementor( true );
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
	 * Elementor's 'internal' CSS print method never writes a per-post file by
	 * design — inline <style> tags are the delivery mechanism. An
	 * unwritable/empty file on disk in that configuration is expected, not a
	 * failure, and must not be reported as one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_internal_print_method_is_verified_without_a_css_file(): void {
		$this->install_elementor( false );
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
	 * save_elements_to_page() — the shared helper behind the surgical
	 * operations (add/remove/replace/patch/edit/strip) — carries its own copy
	 * of the CSS-regeneration call and needs its own coverage, exactly as
	 * ElementorDocumentSaveDeclinedTest gives the surgical path its own
	 * coverage for the #861 decline case.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_surgical_add_section_fails_render_verification_on_an_unwritten_css_file(): void {
		$this->install_elementor( false );
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
}
