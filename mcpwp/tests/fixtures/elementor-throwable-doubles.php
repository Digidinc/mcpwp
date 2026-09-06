<?php
/**
 * Elementor doubles whose Document::save() can be made to throw.
 *
 * Deliberately NOT named *Test.php: phpunit.xml collects the tests directory
 * with the default Test.php suffix, and these classes must never be declared
 * in the main single-process run. Declaring \Elementor\Plugin there would
 * change the behaviour of every Elementor-sensitive test that follows it
 * (see #757 — the suite is already order-dependent on exactly this).
 *
 * Loaded with require_once from inside a @runInSeparateProcess test only.
 *
 * @package MCPWP
 */

namespace Elementor {

	/**
	 * Stands in for an Elementor document. Throws from save() on the call
	 * index the test selects.
	 */
	class Mcpwp_Fake_Document {

		public function save( $data ) {
			$GLOBALS['mcpwp_test_save_calls'] = ( $GLOBALS['mcpwp_test_save_calls'] ?? 0 ) + 1;

			$is_first = 1 === $GLOBALS['mcpwp_test_save_calls'];
			$throw_on = $GLOBALS['mcpwp_test_throw_on'] ?? 'first';

			if ( ( 'first' === $throw_on && $is_first ) || ( 'rebuild' === $throw_on && ! $is_first ) ) {
				// Shape of a real API-context failure against a changed 4.x
				// signature. The specific Throwable is not the contract under
				// test — surviving any of them is.
				throw new \TypeError( 'Elementor\Core\Base\Document::save(): Argument #1 must be of type array, string given' );
			}

			if ( ! empty( $GLOBALS['mcpwp_test_corrupt_rebuild'] ) && ! $is_first ) {
				$corrupted = $data['elements'];
				$corrupted[0]['elements'][0]['elements'] = array();
				\update_post_meta( $GLOBALS['mcpwp_test_corrupt_page_id'], '_elementor_data', \wp_json_encode( $corrupted ) );
			}

			if ( ! empty( $GLOBALS['mcpwp_test_mutate_rebuild'] ) && ! $is_first ) {
				$mutated = $data['elements'];
				$mutated[0]['elements'][0]['elements'][0]['id'] = 'changed1';
				\update_post_meta( $GLOBALS['mcpwp_test_corrupt_page_id'], '_elementor_data', \wp_json_encode( $mutated ) );
			}

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
