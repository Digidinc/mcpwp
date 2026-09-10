<?php
/**
 * Elementor doubles carrying real control definitions, for #824.
 *
 * Deliberately NOT named *Test.php and loaded with require_once from inside a
 * @runInSeparateProcess test only — declaring \Elementor\Plugin in the shared
 * run changes every Elementor-sensitive test after it (#757).
 *
 * Shapes are taken from Elementor 4.2.4 as observed in a REST request on a
 * real install (2026-09-10):
 *   - Widget_Base::get_controls() returns the widget's own controls merged
 *     with the common (Advanced tab) controls; get_stack( false ) returns only
 *     the widget's own.
 *   - Group controls are expanded into one control per field
 *     (`typography_font_family`), responsive controls into one per active
 *     device (`align_tablet`).
 *   - A repeater control carries `fields` keyed by field name, `_id` included.
 *   - Elementor free registers Pro widget names (`form`, `posts`, ...) as
 *     Modules\Promotions\Widgets\Pro_Widget_Promotion, which has no controls.
 *   - Core\Frontend\Performance::should_optimize_controls() is true outside
 *     REST, admin and preview requests, and then style controls are left out
 *     of get_controls() entirely.
 *
 * Globals:
 *   mcpwp_test_widget_controls   type => array( name => control ) — own controls.
 *   mcpwp_test_common_controls   array( name => control ).
 *   mcpwp_test_promotion_widgets list of types registered as placeholders.
 *   mcpwp_test_widget_registry_throws  bool.
 *   mcpwp_test_optimize_controls bool.
 *
 * @package MCPWP
 */

namespace Elementor\Core\Frontend {

	class Performance {

		public static function should_optimize_controls() {
			return ! empty( $GLOBALS['mcpwp_test_optimize_controls'] );
		}
	}
}

namespace Elementor\Modules\Promotions\Widgets {

	class Pro_Widget_Promotion {

		private $type;

		public function __construct( $type ) {
			$this->type = $type;
		}

		public function get_name() {
			return $this->type;
		}

		public function get_title() {
			return ucwords( str_replace( '-', ' ', $this->type ) );
		}

		public function get_categories() {
			return array( 'general', 'pro-elements' );
		}

		public function get_controls() {
			return $GLOBALS['mcpwp_test_common_controls'] ?? array();
		}

		public function get_stack( $with_common_controls = true ) {
			return array( 'controls' => $with_common_controls ? $this->get_controls() : array() );
		}
	}
}

namespace Elementor {

	class Mcpwp_Fake_Control_Widget {

		private $type;

		public function __construct( $type ) {
			$this->type = $type;
		}

		public function get_name() {
			return $this->type;
		}

		public function get_title() {
			return ucwords( str_replace( '-', ' ', $this->type ) );
		}

		public function get_categories() {
			return array( 'basic' );
		}

		private function own() {
			$own = $GLOBALS['mcpwp_test_widget_controls'][ $this->type ] ?? array();

			foreach ( $own as $name => $control ) {
				$own[ $name ]['name'] = $name;
			}

			return $own;
		}

		public function get_controls() {
			return array_merge( $this->own(), $GLOBALS['mcpwp_test_common_controls'] ?? array() );
		}

		public function get_stack( $with_common_controls = true ) {
			return array( 'controls' => $with_common_controls ? $this->get_controls() : $this->own() );
		}
	}

	class Mcpwp_Fake_Control_Widgets_Manager {

		public function get_widget_types( $type = null ) {
			if ( ! empty( $GLOBALS['mcpwp_test_widget_registry_throws'] ) ) {
				throw new \RuntimeException( 'registry unavailable' );
			}

			$out = array();

			foreach ( array_keys( $GLOBALS['mcpwp_test_widget_controls'] ?? array() ) as $name ) {
				$out[ $name ] = new Mcpwp_Fake_Control_Widget( $name );
			}

			foreach ( $GLOBALS['mcpwp_test_promotion_widgets'] ?? array() as $name ) {
				$out[ $name ] = new Modules\Promotions\Widgets\Pro_Widget_Promotion( $name );
			}

			if ( null !== $type ) {
				return $out[ $type ] ?? null;
			}

			return $out;
		}
	}

	class Plugin {

		public static $instance;

		public $widgets_manager;

		public function __construct() {
			$this->widgets_manager = new Mcpwp_Fake_Control_Widgets_Manager();
		}
	}
}
