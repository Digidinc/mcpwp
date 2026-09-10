<?php
/**
 * A minimal \Elementor\Plugin whose frontend renders nothing, so
 * get_rendered_content() takes its fallback path and reads the stored tree.
 *
 * Deliberately NOT named *Test.php, and loaded with require_once from inside a
 * @runInSeparateProcess test only: declaring \Elementor\Plugin in the shared
 * run changes every Elementor-sensitive test after it (#757).
 *
 * @package MCPWP
 */

namespace Elementor {

	class Mcpwp_Fake_Blank_Frontend {

		public function get_builder_content( $post_id, $with_css = false ) {
			return '';
		}
	}

	class Plugin {

		/** @var Plugin|null */
		public static $instance;

		/** @var Mcpwp_Fake_Blank_Frontend */
		public $frontend;

		public function __construct() {
			$this->frontend = new Mcpwp_Fake_Blank_Frontend();
		}
	}
}
