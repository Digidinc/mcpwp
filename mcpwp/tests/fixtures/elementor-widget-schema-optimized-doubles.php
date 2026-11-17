<?php
/**
 * Elementor doubles for wp_get_widget_schema optimized-controls tests (#1236).
 *
 * Loaded only from @runInSeparateProcess tests — never from the default suite
 * bootstrap (see elementor-widget-schema-doubles.php).
 *
 * @package MCPWP
 */

namespace Elementor\Core\Frontend {

	if ( ! class_exists( __NAMESPACE__ . '\\Performance', false ) ) {
		class Performance {

			private static $use_style_controls = false;

			public static function set_use_style_controls( bool $is_use ): void {
				static::$use_style_controls = $is_use;
			}

			public static function is_use_style_controls(): bool {
				return static::$use_style_controls;
			}

			public static function should_optimize_controls() {
				return true;
			}
		}
	}
}

namespace Elementor {

	class Mcpwp_Schema_Optimized_Fake_Widget {

		private $type;
		private $content_controls;
		private $style_controls;
		private $stack_tabs;

		public function __construct( $type, array $content_controls, array $style_controls, array $stack_tabs ) {
			$this->type             = $type;
			$this->content_controls = $content_controls;
			$this->style_controls   = $style_controls;
			$this->stack_tabs       = $stack_tabs;
		}

		public function get_title() {
			return 'Optimized Fixture';
		}

		public function get_icon() {
			return 'eicon-image';
		}

		public function get_controls() {
			$controls = $this->content_controls;
			if ( \Elementor\Core\Frontend\Performance::is_use_style_controls() ) {
				$controls = array_merge( $controls, $this->style_controls );
			}
			return $controls;
		}

		public function get_stack() {
			return array(
				'controls'       => $this->content_controls,
				'style_controls' => $this->style_controls,
				'tabs'           => $this->stack_tabs,
			);
		}

		public function get_unique_name() {
			return $this->type;
		}
	}

	class Mcpwp_Schema_Optimized_Fake_Widgets_Manager {

		public function get_widget_types() {
			$html_like_controls = array(
				'html' => array(
					'type'  => 'textarea',
					'label' => 'HTML',
					'tab'   => 'content',
				),
				'advanced_margin' => array(
					'type'  => 'dimensions',
					'label' => 'advanced_margin',
					'tab'   => 'content',
				),
			);

			return array(
				'mcpwp-optimized-widget' => new Mcpwp_Schema_Optimized_Fake_Widget(
					'mcpwp-optimized-widget',
					array(
						'image_size' => array(
							'type'  => 'select',
							'label' => 'image_size',
							'tab'   => 'content',
						),
					),
					array(
						'width' => array(
							'type'       => 'slider',
							'label'      => 'width',
							'tab'        => 'content',
							'selectors'  => array( '{{WRAPPER}} img' => 'width: {{SIZE}}' ),
						),
					),
					array(
						'content' => array( 'label' => 'Content' ),
						'style'   => array( 'label' => 'Style' ),
					)
				),
				'mcpwp-html-like-widget' => new Mcpwp_Schema_Optimized_Fake_Widget(
					'mcpwp-html-like-widget',
					$html_like_controls,
					array(),
					array(
						'content'  => array( 'label' => 'Content' ),
						'style'    => array( 'label' => 'Style' ),
						'advanced' => array( 'label' => 'Advanced' ),
					)
				),
			);
		}
	}

	class Plugin {

		public static $instance;

		public $widgets_manager;

		public function __construct() {
			$this->widgets_manager = new Mcpwp_Schema_Optimized_Fake_Widgets_Manager();
		}
	}
}