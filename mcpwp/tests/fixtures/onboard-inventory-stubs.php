<?php
/**
 * Minimal WordPress function stubs for
 * WebMcpReviewRound2Test::test_onboard_inventory_counts_only_published_for_webmcp.
 *
 * Declared conditionally in a real file, not with the eval language construct: the repo's
 * validation rejects that construct anywhere under mcpwp/, test code included.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() {
		return 'UTC';
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return 'en_US';
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( $p ) {
		return false;
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme() {
		return new class() {
			public function get( $h ) {
				return 'Stub';
			}
		};
	}
}

if ( ! function_exists( 'wp_count_attachments' ) ) {
	function wp_count_attachments() {
		return (object) array( 'image/jpeg' => 0 );
	}
}

if ( ! function_exists( 'wp_count_terms' ) ) {
	function wp_count_terms( $t ) {
		return 0;
	}
}
