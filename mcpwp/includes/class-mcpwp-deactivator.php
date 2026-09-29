<?php
/**
 * Plugin Deactivator
 *
 * @package MCPWP
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
		wp_clear_scheduled_hook( 'mcpwp_figma_sync_design_tokens' );
		// Approvals retention (#1042); the table itself is kept.
		wp_clear_scheduled_hook( 'mcpwp_approvals_retention' );

		// Site graph schedules (#943 S1 review, W7): worker, backfill,
		// signals prune, and the table-creation retry. The queue/backfill/
		// reconcile TABLES and OPTIONS are left alone here on purpose —
		// deactivation preserves data, same as every other MCPWP table;
		// only uninstall.php drops them.
		if ( class_exists( 'Mcpwp_Graph_Builder' ) ) {
			wp_clear_scheduled_hook( Mcpwp_Graph_Builder::WORKER_CRON_HOOK );
			wp_clear_scheduled_hook( Mcpwp_Graph_Builder::BACKFILL_CRON_HOOK );
			wp_clear_scheduled_hook( Mcpwp_Graph_Builder::READY_RETRY_CRON_HOOK );
		}
		if ( class_exists( 'Mcpwp_Graph_Signals' ) ) {
			wp_clear_scheduled_hook( Mcpwp_Graph_Signals::PRUNE_CRON_HOOK );
		}

		// Flush rewrite rules
		flush_rewrite_rules();
	}
}
