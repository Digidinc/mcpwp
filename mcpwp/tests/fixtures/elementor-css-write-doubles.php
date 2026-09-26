<?php
/**
 * Elementor doubles for #901: Document::save() always succeeds (the write
 * path itself is not under test here — ElementorDocumentSaveDeclinedTest and
 * ElementorSaveThrowableTest already cover that), but
 * \Elementor\Core\Files\CSS\Post::update() is scenario-driven, mirroring the
 * real defect AND the RC review (2026-09-26) findings against the first fix:
 *
 * BLOCK — Elementor's own Base::update() -> update_file() is
 * `if ($this->content) write(); else delete();`, and it ALWAYS records its
 * verdict in the `_elementor_css` post meta `status` key regardless of
 * whether the underlying filesystem write actually landed:
 *   - 'file'   — content was non-empty, Elementor intended to write a file.
 *                An unwritable uploads/elementor/css swallows the write with
 *                no exception, no WP_Error, so this status can be set with
 *                NO file (or an empty one) on disk — that mismatch is the
 *                real failure signature.
 *   - 'empty'  — nothing to print (a cleared page, a widget with no
 *                styled/global-default controls, an Elementor 4 atomic-only
 *                page whose legacy Post CSS has only legacy-control rules to
 *                emit) — Elementor deliberately deletes any existing file.
 *                No file on disk here is CORRECT, not a failure.
 *   - 'inline' — the site prints CSS inline; no file by design.
 *
 * WARN — a file that exists and is non-empty is not proof THIS call wrote
 * it: on a directory that went unwritable after a page already had CSS, the
 * write silently no-ops and the OLD file survives untouched.
 * 'unwritable_after_existing' models exactly that: an existing file is left
 * alone (not rewritten, not deleted) while the meta status still reports
 * 'file'.
 *
 * $GLOBALS['mcpwp_test_css_scenario'] selects the behaviour:
 *   'healthy'                  — writes a real, non-empty file; status 'file'.
 *   'unwritable_first'         — no file (dir unwritable, nothing existed
 *                                 before); status 'file' regardless (mirrors
 *                                 Elementor recording its intent even though
 *                                 the write silently failed).
 *   'unwritable_after_existing'— does not touch whatever file already
 *                                 exists on disk; status 'file'.
 *   'empty'                    — deletes any existing file; status 'empty'.
 *   'inline'                   — deletes any existing file; status 'inline'.
 *   'no_meta'                  — deletes the `_elementor_css` meta entirely,
 *                                 modelling an Elementor version/config that
 *                                 never wrote per-page status.
 *
 * Deliberately NOT named *Test.php — same reason as the sibling *-doubles.php
 * fixtures: phpunit.xml's default Test.php suffix would pick this up in the
 * main single-process run, and \Elementor\Plugin / \Elementor\Core\Files\...
 * can only be declared once per process (#757). Loaded with require_once
 * from inside a @runInSeparateProcess test only.
 *
 * @package MCPWP
 */

namespace Elementor {

	class Mcpwp_Fake_Document {

		public function save( $data ) {
			$GLOBALS['mcpwp_test_css_save_calls'] = ( $GLOBALS['mcpwp_test_css_save_calls'] ?? 0 ) + 1;

			return true;
		}
	}

	class Mcpwp_Fake_Documents {

		public function get( $id, $from_cache = true ) {
			return new Mcpwp_Fake_Document();
		}
	}

	class Mcpwp_Fake_Files_Manager {

		public function clear_cache() {}
	}

	/**
	 * Minimal stand-in for \Elementor\Plugin as the writer trait uses it:
	 * Plugin::$instance->documents and Plugin::$instance->files_manager.
	 */
	class Plugin {

		public static $instance;

		public $documents;
		public $files_manager;

		public function __construct() {
			$this->documents     = new Mcpwp_Fake_Documents();
			$this->files_manager = new Mcpwp_Fake_Files_Manager();
		}
	}
}

namespace Elementor\Core\Files\CSS {

	/**
	 * Stands in for \Elementor\Core\Files\CSS\Post. See the file docblock for
	 * what each $GLOBALS['mcpwp_test_css_scenario'] value models.
	 */
	class Post {

		private $post_id;

		private function __construct( $post_id ) {
			$this->post_id = $post_id;
		}

		public static function create( $post_id ) {
			return new self( $post_id );
		}

		public function update() {
			$GLOBALS['mcpwp_test_css_update_calls'] = ( $GLOBALS['mcpwp_test_css_update_calls'] ?? 0 ) + 1;

			$scenario   = $GLOBALS['mcpwp_test_css_scenario'] ?? 'healthy';
			$upload_dir = \wp_upload_dir();
			$dir        = $upload_dir['basedir'] . '/elementor/css';
			$path       = $dir . '/post-' . $this->post_id . '.css';

			switch ( $scenario ) {
				case 'healthy':
					if ( ! is_dir( $dir ) ) {
						mkdir( $dir, 0777, true );
					}
					file_put_contents( $path, '.mcpwp-test{color:red}' );
					\update_post_meta( $this->post_id, '_elementor_css', array( 'status' => 'file' ) );
					break;

				case 'unwritable_first':
				case 'unwritable_after_existing':
					// The write itself is swallowed either way -- the only
					// difference between these two scenarios is what the
					// TEST seeds on disk beforehand. Elementor still records
					// that it INTENDED to write a file.
					\update_post_meta( $this->post_id, '_elementor_css', array( 'status' => 'file' ) );
					break;

				case 'empty':
					if ( file_exists( $path ) ) {
						unlink( $path );
					}
					\update_post_meta( $this->post_id, '_elementor_css', array( 'status' => 'empty' ) );
					break;

				case 'inline':
					if ( file_exists( $path ) ) {
						unlink( $path );
					}
					\update_post_meta( $this->post_id, '_elementor_css', array( 'status' => 'inline' ) );
					break;

				case 'no_meta':
					\delete_post_meta( $this->post_id, '_elementor_css' );
					break;
			}
		}
	}
}
