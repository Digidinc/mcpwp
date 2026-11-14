<?php
/**
 * Elementor Pro Theme Builder doubles for #989, modelling the parts of the
 * real Conditions_Manager (4.1.1) that Mcpwp_Effective_Template uses:
 * get_documents_for_location() answers from the CURRENT global post and
 * caches per location until clear_location_cache().
 *
 * $GLOBALS['mcpwp_test_tb_assignments'][ post_id ][ location ] = array( template ids )
 * $GLOBALS['mcpwp_test_tb_throw']    When set, get_documents_for_location() throws.
 * $GLOBALS['mcpwp_test_tb_clears']   Count of clear_location_cache() calls.
 *
 * Deliberately NOT named *Test.php; loaded from a @runInSeparateProcess test
 * only, since the ElementorPro classes can be declared once per process.
 *
 * @package MCPWP
 */

namespace ElementorPro\Modules\ThemeBuilder {

	class Mcpwp_Tb_Fake_Conditions_Manager {

		private $location_cache = array();

		public function get_documents_for_location( $location ) {
			if ( isset( $this->location_cache[ $location ] ) ) {
				return $this->location_cache[ $location ];
			}

			if ( ! empty( $GLOBALS['mcpwp_test_tb_throw'] ) ) {
				throw new \RuntimeException( 'conditions exploded (test double)' );
			}

			$current = isset( $GLOBALS['post'] ) && is_object( $GLOBALS['post'] ) ? (int) $GLOBALS['post']->ID : 0;
			$ids     = $GLOBALS['mcpwp_test_tb_assignments'][ $current ][ $location ] ?? array();

			$documents = array();
			foreach ( $ids as $id ) {
				$documents[ $id ] = new \stdClass();
			}

			$this->location_cache[ $location ] = $documents;

			return $documents;
		}

		public function clear_location_cache() {
			$GLOBALS['mcpwp_test_tb_clears'] = ( $GLOBALS['mcpwp_test_tb_clears'] ?? 0 ) + 1;
			$this->location_cache            = array();
		}
	}

	class Mcpwp_Tb_Fake_Locations_Manager {

		public function get_locations() {
			return array(
				'header' => array( 'label' => 'Header' ),
				'footer' => array( 'label' => 'Footer' ),
				'single' => array( 'label' => 'Single' ),
			);
		}
	}

	class Module {

		private static $instance;

		private $conditions;
		private $locations;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		public function __construct() {
			$this->conditions = new Mcpwp_Tb_Fake_Conditions_Manager();
			$this->locations  = new Mcpwp_Tb_Fake_Locations_Manager();
		}

		public function get_conditions_manager() {
			return $this->conditions;
		}

		public function get_locations_manager() {
			return $this->locations;
		}
	}
}
