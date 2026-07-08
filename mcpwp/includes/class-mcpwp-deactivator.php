<?php
/**
 * Plugin Deactivator
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fired during plugin deactivation.
 */
class Mcpwp_Deactivator {

	/**
	 * Deactivate the plugin.
	 *
	 * Clean up transients, flush rewrite rules.
	 * Note: API key and settings are preserved for reactivation.
	 * Full cleanup happens in uninstall.php.
	 */
	public static function deactivate() {
		// Clear any transients
		delete_transient( 'mcpwp_capabilities_cache' );
		wp_clear_scheduled_hook( 'mcpwp_cleanup_logs' );
		wp_clear_scheduled_hook( 'mcpwp_compute_signals' );

		// Flush rewrite rules
		flush_rewrite_rules();
	}
}
