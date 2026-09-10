<?php
/**
 * Minimal stand-in for Elementor Pro's theme builder module class, for tests
 * that only need the class to exist. Loaded with require_once; kept in its own
 * file because a namespaced class cannot be declared inline in a test (and the
 * CI security scan rejects eval()).
 *
 * @package MCPWP
 */

namespace ElementorPro\Modules\ThemeBuilder;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCPWP_TESTS' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Module', false ) ) {
	class Module {}
}
