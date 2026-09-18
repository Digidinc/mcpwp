<?php
/**
 * Elementor doubles for wp_get_elementor_widgets / wp_get_widget_schema
 * projection tests (#830).
 *
 * Deliberately NOT named *Test.php: phpunit.xml collects the tests directory
 * with the default Test.php suffix, and \Elementor\Plugin must never be
 * declared in the main single-process run — doing so changes the behaviour of
 * every Elementor-sensitive test that follows (see #757).
 *
 * Loaded with require_once from inside a @runInSeparateProcess test only.
 *
 * 'heading' is a real key in Mcpwp_Elementor_Widgets::get_free_widgets(), so
 * registering a fake widget under that name picks up real reference data
 * (description/category/example/common_mistakes) the same way the live
 * Elementor registry would. The second fake, 'mcpwp-fixture-widget', has no
 * reference entry — has_reference:false, no category — so category filtering
 * has something to exclude.
 *
 * @package MCPWP
 */

namespace Elementor {

	class Mcpwp_Schema_Fake_Widget {

		private $type;
		private $controls;

		public function __construct( $type, array $controls ) {
			$this->type     = $type;
			$this->controls = $controls;
		}

		public function get_title() {
			return ucwords( str_replace( '-', ' ', $this->type ) );
		}

		public function get_icon() {
			return 'eicon-' . $this->type;
		}

		public function get_controls() {
			return $this->controls;
		}
	}

	class Mcpwp_Schema_Fake_Widgets_Manager {

		public function get_widget_types() {
			return array(
				'heading'              => new Mcpwp_Schema_Fake_Widget(
					'heading',
					array(
						'title'       => array( 'type' => 'text', 'label' => 'Title', 'tab' => 'content' ),
						'title_color' => array( 'type' => 'color', 'label' => 'Title Color', 'tab' => 'style' ),
						'_internal'   => array( 'type' => 'hidden', 'label' => 'Internal', 'tab' => 'content', 'is_internal' => true ),
					)
				),
				'mcpwp-fixture-widget' => new Mcpwp_Schema_Fake_Widget(
					'mcpwp-fixture-widget',
					array(
						'label' => array( 'type' => 'text', 'label' => 'Label', 'tab' => 'content' ),
					)
				),
			);
		}
	}

	class Plugin {

		public static $instance;

		public $widgets_manager;

		public function __construct() {
			$this->widgets_manager = new Mcpwp_Schema_Fake_Widgets_Manager();
		}
	}
}
