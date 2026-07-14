<?php
/**
 * Internationalization
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define internationalization functionality.
 *
 * Translations are loaded automatically by WordPress.org since WP 4.6.
 */
class Mcpwp_i18n {

	/**
	 * Load the plugin text domain.
	 *
	 * No-op since WordPress 4.6+ loads translations automatically
	 * for plugins hosted on WordPress.org.
	 */
	public function load_plugin_textdomain() {
		// Intentionally left empty — WordPress handles this automatically.
	}
}
