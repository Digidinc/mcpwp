<?php
/**
 * A minimal \Elementor\Plugin whose Experiments_Manager answers
 * is_feature_active() from test-controlled state rather than the real
 * feature registry.
 *
 * This is the double that #883 needed and did not have: every other
 * \Elementor\Plugin double in this suite (elementor-render-doubles.php,
 * elementor-declined-save-doubles.php, ...) has no `experiments` property at
 * all, so `Mcpwp_Elementor_Experiments::is_active()` falls back to the raw
 * `elementor_experiment-*` option in every existing test. That fallback is
 * exactly the code path that mis-reported a fresh Elementor 4.x install
 * (Container default-active, no option row) as classic "section" mode. This
 * double lets a test assert the OTHER path: the manager says a feature is
 * active while wp_options says nothing about it at all.
 *
 * Deliberately NOT named *Test.php, and loaded with require_once from inside
 * a @runInSeparateProcess test only: declaring \Elementor\Plugin in the
 * shared run changes every Elementor-sensitive test after it (#757).
 *
 * @package MCPWP
 */

namespace Elementor {

	/**
	 * Answers is_feature_active() from $GLOBALS['mcpwp_test_active_experiments']
	 * — a plain list of active feature slugs — regardless of any wp_options row.
	 * This is the property real Elementor has and every prior double lacked: a
	 * feature can be "active" with nothing in the options table to say so,
	 * because its registered default is active.
	 */
	class Mcpwp_Fake_Experiments_Manager {

		public function is_feature_active( $feature_name ) {
			$active = isset( $GLOBALS['mcpwp_test_active_experiments'] ) && is_array( $GLOBALS['mcpwp_test_active_experiments'] )
				? $GLOBALS['mcpwp_test_active_experiments']
				: array();

			return in_array( $feature_name, $active, true );
		}
	}

	class Plugin {

		/** @var Plugin|null */
		public static $instance;

		/** @var Mcpwp_Fake_Experiments_Manager */
		public $experiments;

		/**
		 * Deliberately null: this double exists to test experiment detection,
		 * not the Document::save() path. class_exists( '\Elementor\Plugin' )
		 * being true (needed for $experiments to be reachable at all) makes
		 * the writer try `Plugin::$instance->documents` — null here sends it
		 * down the same meta-fallback path every other \Elementor\Plugin-less
		 * test in this suite already takes.
		 *
		 * @var mixed
		 */
		public $documents;

		public function __construct() {
			$this->experiments = new Mcpwp_Fake_Experiments_Manager();
			$this->documents   = null;
		}
	}
}
