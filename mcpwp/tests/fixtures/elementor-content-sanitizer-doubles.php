<?php
/**
 * Elementor doubles for the content-sanitizer suspension tests (#769).
 *
 * Deliberately NOT named *Test.php: phpunit.xml collects the tests directory
 * with the default Test.php suffix, and \Elementor\Plugin must never be
 * declared in the main single-process run — doing so changes the behaviour of
 * every Elementor-sensitive test that follows (see #757).
 *
 * Loaded with require_once from inside a @runInSeparateProcess test only.
 *
 * @package MCPWP
 */

namespace Elementor {

	/**
	 * Stands in for \Elementor\Modules\ContentSanitizer\Module. Only the piece
	 * that matters is modelled: a sanitize_content() callback hooked on
	 * elementor/document/save/data that rewrites heading titles.
	 */
	class Mcpwp_Fake_Content_Sanitizer {

		public function sanitize_content( $data, $document = null ) {
			$GLOBALS['mcpwp_test_sanitizer_ran'] = ( $GLOBALS['mcpwp_test_sanitizer_ran'] ?? 0 ) + 1;

			return $data;
		}
	}

	class Mcpwp_Fake_Modules_Manager {

		public $sanitizer;

		public function __construct() {
			$this->sanitizer = new Mcpwp_Fake_Content_Sanitizer();
		}

		public function get_modules( $name ) {
			if ( 'content-sanitizer' === $name ) {
				return $this->sanitizer;
			}

			return null;
		}
	}

	class Plugin {

		public static $instance;

		public $modules_manager;

		public function __construct() {
			$this->modules_manager = new Mcpwp_Fake_Modules_Manager();
		}
	}
}
