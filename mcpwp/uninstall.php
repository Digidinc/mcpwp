<?php
/** Multisite-aware plugin-owned data cleanup. @package mumcp */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

// Delete plugin-owned current and compatibility state.

$mcpwp_uninstall_site = static function () {
	global $wpdb;
	delete_option( 'mcpwp_api_key' );
	delete_option( 'mcpwp_api_keys' );
	delete_option( 'mcpwp_legacy_migration_owner_id' );
	delete_option( 'mcpwp_settings' );
	delete_option( 'mcpwp_rate_limit_settings' );
	delete_option( 'mcpwp_disabled_tool_categories' );
	delete_option( 'mcpwp_db_version' );
	delete_option( 'mcpwp_migrated_from_spai' );
	$mcpwp_rate_transient_like = $wpdb->esc_like( '_transient_mcpwp_rate_' ) . '%';
	$mcpwp_rate_timeout_like   = $wpdb->esc_like( '_transient_timeout_mcpwp_rate_' ) . '%';
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$mcpwp_rate_transient_like,
			$mcpwp_rate_timeout_like
		)
	);
	// Legacy-only option cleanup; these names have no active implementation, network call, UI, or commercial behavior in this edition.
	delete_option( 'mcpwp_integrations' );
	delete_option( 'mcpwp_license' );
	delete_option( 'mcpwp_update_info' );
	delete_option( 'mcpwp_version_url' );
	delete_option( 'mcpwp_analytics_consent' );
	delete_option( 'mcpwp_feedback_history' );
	delete_option( 'mcpwp_oauth_clients' );
	delete_option( 'mcpwp_oauth_codes' );
	delete_option( 'mcpwp_webhooks' );
	delete_option( 'mcpwp_approvals' );
	delete_option( 'mcpwp_site_blueprints' );
	delete_option( 'mcpwp_design_references' );
	// Current directory-edition metadata-retention cleanup.
	wp_clear_scheduled_hook( 'mcpwp_cleanup_logs' );
	// Legacy-only scheduled-hook cleanup; these names have no active scheduler or implementation in this edition.
	wp_clear_scheduled_hook( 'mcpwp_check_alerts' );
	wp_clear_scheduled_hook( 'mcpwp_verify_screenshot' );
	wp_clear_scheduled_hook( 'mcpwp_compute_signals' );
	wp_clear_scheduled_hook( 'mcpwp_action_log_daily_prune' );
	wp_clear_scheduled_hook( 'mcpwp_webhook_retry' );
	// Current directory-edition local activity table.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcpwp_activity_log" );
	// Legacy-only table cleanup; these names have no active implementation in this edition.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcpwp_webhooks" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcpwp_webhook_logs" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcpwp_feedback" );

	// Remove only the role and administrator capability owned by this plugin.
	remove_role( 'mcpwp_api_agent' );
	$mcpwp_administrator_role = get_role( 'administrator' );
	if ( $mcpwp_administrator_role ) {
		$mcpwp_administrator_role->remove_cap( 'mcpwp_manage_settings' );
	}
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $mcpwp_site_id ) {
		switch_to_blog( $mcpwp_site_id );
		$mcpwp_uninstall_site();
		restore_current_blog();
	}
} else {
	$mcpwp_uninstall_site();
}
