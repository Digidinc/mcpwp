<?php
/**
 * Elementor doubles for #989: a save pipeline that always succeeds, CSS that
 * is always written, and a frontend whose rendered HTML each test scripts.
 *
 * $GLOBALS['mcpwp_test_dom_html']   HTML get_builder_content() returns.
 * $GLOBALS['mcpwp_test_dom_throw']  When set, get_builder_content() throws.
 * $GLOBALS['mcpwp_test_dom_seen']   Records [post_id, with_css, current post id]
 *                                   for every render call.
 *
 * Deliberately NOT named *Test.php, and loaded with require_once from inside a
 * @runInSeparateProcess test only: \Elementor\Plugin can only be declared once
 * per process (#757).
 *
 * @package MCPWP
 */

namespace Elementor {

	class Mcpwp_Dom_Fake_Document {

		public function save( $data ) {
			return true;
		}
	}

	class Mcpwp_Dom_Fake_Documents {

		public function get( $id, $from_cache = true ) {
			return new Mcpwp_Dom_Fake_Document();
		}
	}

	class Mcpwp_Dom_Fake_Files_Manager {

		public function clear_cache() {}
	}

	class Mcpwp_Dom_Fake_Frontend {

		public function get_builder_content( $post_id, $with_css = false ) {
			$current = isset( $GLOBALS['post'] ) && is_object( $GLOBALS['post'] ) ? (int) $GLOBALS['post']->ID : 0;

			$GLOBALS['mcpwp_test_dom_seen'][] = array( (int) $post_id, (bool) $with_css, $current );

			if ( ! empty( $GLOBALS['mcpwp_test_dom_throw'] ) ) {
				throw new \RuntimeException( 'render exploded (test double)' );
			}

			return (string) ( $GLOBALS['mcpwp_test_dom_html'] ?? '' );
		}
	}

	class Plugin {

		public static $instance;

		public $documents;
		public $files_manager;
		public $frontend;

		public function __construct() {
			$this->documents     = new Mcpwp_Dom_Fake_Documents();
			$this->files_manager = new Mcpwp_Dom_Fake_Files_Manager();
			$this->frontend      = new Mcpwp_Dom_Fake_Frontend();
		}
	}
}

namespace Elementor\Core\Files\CSS {

	class Post {

		private $post_id;

		private function __construct( $post_id ) {
			$this->post_id = $post_id;
		}

		public static function create( $post_id ) {
			return new self( $post_id );
		}

		public function update() {
			$upload_dir = \wp_upload_dir();
			$dir        = $upload_dir['basedir'] . '/elementor/css';
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0777, true );
			}
			file_put_contents( $dir . '/post-' . $this->post_id . '.css', '.mcpwp-dom-test{color:red}' );
			\update_post_meta( $this->post_id, '_elementor_css', array( 'status' => 'file' ) );
		}
	}
}
