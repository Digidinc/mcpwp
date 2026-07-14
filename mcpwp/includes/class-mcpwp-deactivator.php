<?php
/**
 * Plugin deactivation routines.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Clear plugin-owned schedules when the plugin is deactivated.
 */
class Mcpwp_Deactivator {
	/**
	 * Clear schedules for one site or every site in a network.
	 *
	 * @param bool $network_wide Whether WordPress requested network deactivation.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( $network_wide && is_multisite() && function_exists( 'get_sites' ) ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $mcpwp_site_id ) {
				switch_to_blog( $mcpwp_site_id );
				self::clear_site_hooks();
				restore_current_blog();
			}
			return;
		}
		self::clear_site_hooks();
	}

	/**
	 * Clear plugin-owned schedules for the current site.
	 *
	 * @return void
	 */
	private static function clear_site_hooks() {
		// Current directory-edition metadata-retention cleanup.
		wp_clear_scheduled_hook( 'mcpwp_cleanup_logs' );
		// Legacy-only compatibility cleanup; these hooks have no active scheduler or implementation in this edition.
		wp_clear_scheduled_hook( 'mcpwp_check_alerts' );
		wp_clear_scheduled_hook( 'mcpwp_verify_screenshot' );
		wp_clear_scheduled_hook( 'mcpwp_compute_signals' );
		wp_clear_scheduled_hook( 'mcpwp_action_log_daily_prune' );
		wp_clear_scheduled_hook( 'mcpwp_webhook_retry' );
	}
}
