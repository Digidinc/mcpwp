<?php
/**
 * Minimal `\Elementor\Plugin` double — just enough for a test to get past
 * Mcpwp_Elementor_Css_Trait::regenerate_css()'s `class_exists('\Elementor\Plugin')`
 * guard. Only used by tests exercising a code path that is expected to
 * return BEFORE actually touching $instance (e.g. the #1024 category gate),
 * so no further behavior is stubbed here.
 */

namespace Elementor;

if ( ! class_exists( __NAMESPACE__ . '\\Plugin' ) ) {
	class Plugin {
		public static $instance;
	}
}
