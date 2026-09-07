<?php
/**
 * Elementor doubles for the live widget-registry tests.
 *
 * Deliberately NOT named *Test.php: phpunit.xml collects the tests directory
 * with the default Test.php suffix, and \Elementor\Plugin must never be
 * declared in the main single-process run — doing so changes the behaviour of
 * every Elementor-sensitive test that follows (see #757).
 *
 * Loaded with require_once from inside a @runInSeparateProcess test only.
 *
 * The widget list comes from a global rather than being baked in, so one
 * process can model "Pro installed", another "Pro absent", and a third the
 * mid-boot registry that reports zero types.
 *
 * @package MCPWP
 */

namespace Elementor {

	class Mcpwp_Fake_Widget {

		private $type;

		public function __construct( $type ) {
			$this->type = $type;
		}

		public function get_title() {
			return ucwords( str_replace( array( 'theme-', '-' ), array( '', ' ' ), $this->type ) );
		}

		public function get_categories() {
			return array( 0 === strpos( $this->type, 'theme-' ) ? 'theme-elements' : 'basic' );
		}

		public function get_controls() {
			return array(
				'title'       => array( 'type' => 'text' ),
				'header_size' => array( 'type' => 'select' ),
				'align'       => array( 'type' => 'choose' ),
			);
		}
	}

	class Mcpwp_Fake_Widgets_Manager {

		public function get_widget_types() {
			if ( ! empty( $GLOBALS['mcpwp_test_widget_registry_throws'] ) ) {
				throw new \RuntimeException( 'registry unavailable' );
			}

			$types = $GLOBALS['mcpwp_test_widget_types'] ?? array();
			$out   = array();

			foreach ( $types as $type ) {
				$out[ $type ] = new Mcpwp_Fake_Widget( $type );
			}

			return $out;
		}
	}

	class Plugin {

		public static $instance;

		public $widgets_manager;

		public function __construct() {
			$this->widgets_manager = new Mcpwp_Fake_Widgets_Manager();
		}
	}
}
