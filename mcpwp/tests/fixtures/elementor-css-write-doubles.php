<?php
/**
 * Elementor doubles for #901: Document::save() always succeeds (the write
 * path itself is not under test here — ElementorDocumentSaveDeclinedTest and
 * ElementorSaveThrowableTest already cover that), but
 * \Elementor\Core\Files\CSS\Post::update() is controllable, mirroring the
 * real defect: Elementor's own CSS_File::update() swallows a filesystem
 * write failure (unwritable uploads/elementor/css, full disk, permissions)
 * with no exception and no WP_Error. The writer trait's closure around it
 * hardcodes `return true;` regardless, so css_regenerated read true and,
 * downstream, so did render_verified — for a page whose CSS file was never
 * actually written and therefore rendered unstyled.
 *
 * $GLOBALS['mcpwp_test_css_write_succeeds'] selects the behaviour:
 *   true  — writes a real, non-empty file to the real (temp-dir) uploads
 *           path wp_upload_dir() resolves to in tests, mirroring a healthy
 *           writable uploads directory.
 *   false — does nothing, mirroring Elementor's own swallowed failure on an
 *           unwritable directory: update() runs, returns normally, no
 *           exception, and no file lands on disk.
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
	 * Stands in for \Elementor\Core\Files\CSS\Post. The real class writes
	 * uploads/elementor/css/post-{id}.css; this double writes the same path
	 * (under the test's temp-dir wp_upload_dir()) only when told to succeed,
	 * and otherwise silently no-ops — exactly the shape of an unwritable
	 * directory that Elementor's own filesystem write failure swallows.
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

			if ( empty( $GLOBALS['mcpwp_test_css_write_succeeds'] ) ) {
				// The unwritable-directory shape: Elementor's own write
				// failed silently. No file, no exception, no WP_Error.
				return;
			}

			$upload_dir = \wp_upload_dir();
			$dir        = $upload_dir['basedir'] . '/elementor/css';
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0777, true );
			}
			file_put_contents( $dir . '/post-' . $this->post_id . '.css', '.mcpwp-test{color:red}' );
		}
	}
}
