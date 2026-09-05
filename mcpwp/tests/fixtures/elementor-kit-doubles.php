<?php
/**
 * Elementor kit doubles for the Kit CSS round-trip tests.
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

	class Mcpwp_Fake_Kit {

		private $id;

		public function __construct( $id ) {
			$this->id = $id;
		}

		public function get_id() {
			return $this->id;
		}
	}

	class Mcpwp_Fake_Kits_Manager {

		public function get_active_kit() {
			return new Mcpwp_Fake_Kit( $GLOBALS['mcpwp_test_kit_id'] ?? 4 );
		}
	}

	class Mcpwp_Fake_Files_Manager {

		public function clear_cache() {}
	}

	class Plugin {

		public static $instance;

		public $kits_manager;
		public $files_manager;

		public function __construct() {
			$this->kits_manager  = new Mcpwp_Fake_Kits_Manager();
			$this->files_manager = new Mcpwp_Fake_Files_Manager();
		}
	}
}
